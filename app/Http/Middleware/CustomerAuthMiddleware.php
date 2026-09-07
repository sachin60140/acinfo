<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! session()->has(self::SESSION_KEY)) {
            $request->session()->flash('error', 'Please sign in to view your account.');

            return redirect()->route('customer.login');
        }

        return $next($request);
    }
}
