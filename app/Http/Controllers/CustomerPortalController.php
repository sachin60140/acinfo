<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CustomerAuthMiddleware;
use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Support\Screen;
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

        $balance = PartyLedgerModel::currentBalance($customer->id);

        return view('customer.dashboard', [
            'customerName' => $customer->name,
            'customerMobile' => $customer->mobile,
            'balance' => $balance,
        ] + $this->balanceWording($balance));
    }

    /**
     * A balance a customer can read without knowing what Dr means.
     *
     * The office convention is debits less credits, and the sign carries the
     * meaning: positive is money this customer owes, negative is money held for
     * them. Both are shown here without a minus sign, because a balance with
     * one is a balance somebody has to stop and decode — the words say which
     * way it falls instead.
     *
     * @return array{balanceText: string, balanceLabel: string, balanceTone: string}
     */
    private function balanceWording(float $balance): array
    {
        $settled = abs($balance) < 0.005;

        return [
            'balanceText' => number_format(abs($balance), 2, '.', ','),
            'balanceLabel' => match (true) {
                $settled => 'Account settled',
                $balance > 0 => 'You owe',
                default => 'In your credit',
            },
            // Owing is not an error and credit is not a success, so these are
            // the ledger's own two colours rather than red and green.
            'balanceTone' => $settled ? 'settled' : ($balance > 0 ? 'dr' : 'cr'),
        ];
    }

    /**
     * The customer's own account statement.
     *
     * The same query the office statement runs, for the party in the session and
     * for no other. What differs is what comes out of it: the office rows carry
     * a link into the file editor, which is a screen this reader must never
     * reach, so the reference here is text.
     */
    public function statement(Request $req)
    {
        $req->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        $customer = $this->customer();

        // Never $req->query('id') — the party is the session's, always.
        $data = PartyLedgerModel::statement($customer->id, $req->query('from'), $req->query('to'));

        $from = $req->query('from');
        $to = $req->query('to');

        $fromText = $from ? date('d-m-Y', strtotime($from)) : 'Beginning';
        $toText = $to ? date('d-m-Y', strtotime($to)) : 'Till date';
        $periodText = $from || $to ? $fromText.' to '.$toText : 'All transactions';

        /*
         * Accumulated here in the order the query returned, carried forward from
         * the opening balance. That is the only order in which a running balance
         * means anything, which is why the grid is handed sortable => false.
         */
        $running = (float) $data['opening'];
        $rows = [];

        foreach ($data['getRecords'] as $entry) {
            $running += $entry->signedAmount();
            $isDebit = $entry->entry_type === 'debit';

            $rows[] = [
                'id' => $entry->id,
                'txn_date' => date('d-m-Y', strtotime($entry->txn_date)),
                'particular' => $entry->particular,
                'payment_mode' => $entry->payment_mode,
                'ref_no' => $entry->ref_no,
                // The side an entry does not fall on stays null, so it exports
                // as a blank cell rather than as 0.00.
                'debit' => $isDebit ? (float) $entry->amount : null,
                'credit' => $isDebit ? null : (float) $entry->amount,
                'balance' => round($running, 2),
            ];
        }

        $props = [
            // Also the export filename and the heading on the PDF and printout.
            'title' => $customer->name.' Statement '.$periodText,
            'columns' => [
                ['key' => 'txn_date', 'label' => 'Date'],
                ['key' => 'particular', 'label' => 'Particulars', 'width' => '16rem'],
                ['key' => 'payment_mode', 'label' => 'Mode'],
                // Plain text. The office statement links this to the file
                // editor; that screen carries what the vendor was paid.
                ['key' => 'ref_no', 'label' => 'Ref No.'],
                ['key' => 'debit', 'label' => 'Debit', 'type' => 'money', 'class' => 'ui-money--dr'],
                ['key' => 'credit', 'label' => 'Credit', 'type' => 'money', 'class' => 'ui-money--cr'],
                ['key' => 'balance', 'label' => 'Balance', 'type' => 'balance', 'class' => 'ui-money--strong'],
            ],
            'rows' => $rows,
            'perPage' => 50,
            /*
             * Never sortable: the balance is a running total, so re-ordering the
             * rows detaches every figure from the row it belongs to and the
             * statement is quietly wrong.
             */
            'sortable' => false,
            'totals' => ['debit' => 'sum', 'credit' => 'sum'],
            'emptyText' => ($from || $to)
                ? 'Nothing in this period. Try a wider range, or All.'
                : 'Nothing on your account yet.',
        ];

        $today = now();

        // The Indian financial year starts in April, so "this year" on a
        // statement means April to March.
        $fyStart = $today->month >= 4
            ? $today->copy()->startOfYear()->addMonths(3)
            : $today->copy()->subYear()->startOfYear()->addMonths(3);

        $closing = (float) $data['closing'];

        return Screen::make('customer.statement', 'vue-customer-statement', $props, [
            'customerName' => $customer->name,
            'from' => $from,
            'to' => $to,
            'fromText' => $fromText,
            'toText' => $toText,
            'periodText' => $periodText,
            'entryCount' => count($rows),
            'opening' => (float) $data['opening'],
            'debits' => (float) $data['debits'],
            'credits' => (float) $data['credits'],
            'closing' => $closing,
            'base' => route('customer.statement'),
            'maxDate' => $today->toDateString(),
            'monthStart' => $today->copy()->startOfMonth()->toDateString(),
            'monthEnd' => $today->copy()->endOfMonth()->toDateString(),
            'fyStart' => $fyStart->toDateString(),
            'fyEnd' => $fyStart->copy()->addYear()->subDay()->toDateString(),
        ] + $this->balanceWording($closing))->toResponse($req);
    }
}
