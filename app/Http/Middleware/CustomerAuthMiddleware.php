<?php

namespace App\Http\Middleware;

use App\Models\PartyModel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate on the customer portal.
 *
 * Deliberately not UserAuthMiddleware, and deliberately not its session key.
 *
 * That one gates the client portal on 'userid', and every page behind it looks
 * its data up in the client tables by that number. A party id put there would
 * be read as a client id and resolve — to a different person's ledger, because
 * client #5 and customer #5 are two unrelated rows. Two identities sharing one
 * key is how a customer ends up looking at somebody else's money, so they do
 * not share one: this reads 'customer_id' and nothing else does.
 */
class CustomerAuthMiddleware
{
    /**
     * The session key this portal signs in under.
     *
     * A constant because the controller writes it, the middleware reads it, and
     * every query behind the gate scopes on it. Three spellings of the same
     * string is one typo away from an unscoped page.
     */
    public const SESSION_KEY = 'customer_id';

    /**
     * Where the session keeps a fingerprint of what it was opened with: the
     * password, and the customer's record as the office last saved it.
     */
    public const PASSWORD_KEY = 'customer_password';

    /**
     * What a session signed in as this customer carries.
     *
     * Built here and only here — at sign-in, and again when the customer
     * changes their own password — so the gate below and whatever writes a
     * session can never disagree about what one looks like.
     *
     * @return array<string, mixed>
     */
    public static function signedInAs(PartyModel $party): array
    {
        return [
            self::SESSION_KEY => $party->id,
            self::PASSWORD_KEY => self::fingerprint($party),
        ];
    }

    /**
     * What stands in the session for the login it was opened with.
     *
     * The password, taken from the stored hash, never from anything typed.
     * Bcrypt salts every hash afresh, so a new password — even the same one set
     * again — makes a new hash and so a new fingerprint. The hash itself is not
     * what is kept: there is no reason for a second copy of it to sit in the
     * sessions table.
     *
     * And updated_at, for the one change that leaves nothing else behind. A
     * customer switched off and on again is, by the time anybody looks, the
     * customer they were — active, the same password — and a phone that opened
     * no page while they were off would find nothing to tell the two apart.
     * Each switch is a save from the office, and every save from the office
     * moves updated_at; a sign-in and the customer's own password change are
     * written without moving it (see CustomerPortalController). So any change
     * the office makes to the record signs the customer out as well — a new
     * mobile number or a corrected name as much as a switch off and on. That
     * is the price of noticing the switch without a column of its own, and it
     * is paid with a sign-in.
     *
     * Read from the attributes, not through the model's date cast: a model
     * with timestamps = false — the customer's own change saves it that way —
     * stops casting updated_at at all and hands back the bare string. Made a
     * Unix time either way, so a model just saved and the same row read back
     * give the same figure whatever form each happens to hold it in.
     */
    private static function fingerprint(PartyModel $party): string
    {
        $saved = Carbon::make($party->getAttributes()['updated_at'] ?? null)?->getTimestamp();

        return hash('sha256', $party->password.'|'.$saved);
    }

    /**
     * Handle an incoming request.
     *
     * Having the key is not enough. The party behind it is read again on every
     * page, and the session is ended when that party has gone, is no longer a
     * customer, has been switched off, or no longer matches the fingerprint
     * this session was opened with: a different password, or a save from the
     * office since. The last is what catches a customer switched off and on
     * again while the phone holding the session opened nothing.
     *
     * Ended, not just turned away, because a refusal leaves the session
     * standing, to be let back in the moment whatever refused it is put back.
     * And a customer who changes their password because somebody else knows
     * it, or asks the office to set a new one or to switch them off, has no
     * other way to turn that somebody's phone out: there is no list of
     * sessions to end, and one kept in use never expires.
     *
     * Here, for every route behind this gate, rather than in each screen. The
     * screens did ask whether the customer was still active — all but the one
     * that did not, and that one served the approval scans.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! session()->has(self::SESSION_KEY)) {
            $request->session()->flash('error', 'Please sign in to view your account.');

            return redirect()->route('customer.login');
        }

        $party = PartyModel::query()
            ->whereKey(session(self::SESSION_KEY))
            ->first(['id', 'party_type', 'is_active', 'password', 'updated_at']);

        $current = $party !== null
            && $party->party_type === 'customer'
            && (int) $party->is_active === 1
            && hash_equals(self::fingerprint($party), (string) session(self::PASSWORD_KEY));

        if (! $current) {
            // Ended the way signing out ends it, and with the words a visitor
            // who never signed in gets: whoever holds the phone is not told why.
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->flash('error', 'Please sign in to view your account.');

            return redirect()->route('customer.login');
        }

        return $next($request);
    }
}
