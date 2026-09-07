<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CustomerAuthMiddleware;
use App\Models\PartyModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;

/**
 * What a customer sees of their own account.
 *
 * The two questions that come by phone — where has my file reached, and what do
 * I owe — answered by the customer rather than by whoever picks up. Both answers
 * are already in this database; all that was missing was a way for a customer to
 * prove which rows are theirs.
 *
 * Everything behind the gate scopes on the session id and never on anything in
 * the request. That is the whole security model of this portal, and it is worth
 * stating plainly because there is nothing else holding one customer out of
 * another's files.
 *
 * What a customer is never shown is as much a part of this class as what they
 * are: which vendor holds their papers, what that vendor charges, and when the
 * file went out are the office's business. Every payload built here is an
 * explicit list of fields for that reason — never a model, never a select *.
 */
class CustomerPortalController extends Controller
{
    /** Where the signed-in party's id lives. Written here, read everywhere. */
    private const KEY = CustomerAuthMiddleware::SESSION_KEY;

    /**
     * A hash to check a password against when no party matched.
     *
     * Bcrypt is slow on purpose, so a login that skips it when the mobile is
     * unknown answers measurably faster than one that does not — and that
     * difference is a way to find out which numbers belong to your customers,
     * however carefully the message is worded. Checking against this costs the
     * same as checking against a real one.
     *
     * A real bcrypt hash of 32 random bytes nobody kept, so it matches nothing.
     */
    private const DUMMY_HASH = '$2y$12$YH9gt.eIuEVZszHMLXZ.PeVyWCozHV6j.2M6ikdhcBc2aZQqbzM4.';

    public function login()
    {
        if (session()->has(self::KEY)) {
            return redirect()->route('customer.dashboard');
        }

        return view('customer.login');
    }

    public function authenticate(Request $req): RedirectResponse
    {
        $req->validate([
            'mobile' => 'required|string|max:15',
            'password' => 'required|string|min:5|max:255',
        ]);

        $party = PartyModel::findForLogin((string) $req->post('mobile'));

        /*
         * One message for every way this can fail: a number that is not ours, a
         * vendor's number, a customer who has never been given a login, a
         * customer since deactivated, and a wrong password all say the same
         * thing. Telling them apart would be kinder to the one person who
         * mistyped and useful to everybody else.
         */
        $ok = Hash::check(
            (string) $req->post('password'),
            $party?->password ?: self::DUMMY_HASH
        );

        if (! $party || ! $ok) {
            return back()
                ->withInput($req->only('mobile'))
                ->with('error', 'Mobile number or password is incorrect.');
        }

        // A fresh session id, so a session fixed before sign-in is not the one
        // the customer ends up signed into.
        $req->session()->regenerate();

        session([
            self::KEY => $party->id,
            'customer_name' => $party->name,
        ]);

        /*
         * A visit is not an edit to the customer's record, so updated_at stays
         * where it is — the office screens read that column to mean "when did
         * somebody last change this", and every sign-in moving it would make it
         * mean nothing.
         *
         * timestamps = false, not saveQuietly(): that one suppresses model
         * events and touches the timestamps regardless, which is a distinction
         * worth writing down because the names do not suggest it.
         */
        $party->timestamps = false;
        $party->last_login_at = now();
        $party->save();

        return redirect()->route('customer.dashboard');
    }

    public function logout(Request $req): RedirectResponse
    {
        Session::flush();

        $req->session()->invalidate();
        $req->session()->regenerateToken();

        return redirect()->route('customer.login');
    }

    /**
     * The signed-in customer, or a 404.
     *
     * Every screen behind the gate starts here rather than reaching for the
     * session itself, so there is exactly one place that turns a session id
     * into a party — and exactly one place to be sure it is still a customer.
     * A party deactivated while someone is signed in stops being able to read
     * their own ledger at the next page, not at the next login.
     */
    private function customer(): PartyModel
    {
        $party = PartyModel::query()
            ->where('id', session(self::KEY))
            ->where('party_type', 'customer')
            ->where('is_active', 1)
            ->first();

        abort_if($party === null, 404);

        return $party;
    }

    public function dashboard(Request $req)
    {
        $customer = $this->customer();

        /*
         * Phase 1 shows who you are and nothing else. The balance, the files
         * and the statement arrive in the phases after this one; shipping the
         * gate on its own means the way in can be proven before there is
         * anything behind it to get wrong.
         */
        return view('customer.dashboard', [
            'customerName' => $customer->name,
            'customerMobile' => $customer->mobile,
        ]);
    }
}
