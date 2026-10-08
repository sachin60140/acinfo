<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Session\Middleware\AuthenticateSession;

/**
 * The office's password check: Laravel's own (auth.session), and one thing
 * more.
 *
 * Laravel's notes in the session the password a browser signed in with, and
 * signs the browser out once that is no longer the account's password. That is
 * what makes changing the password reach every other browser. But a session
 * with no password noted yet is taken on trust: the check notes whichever the
 * account has when that session next asks, and after a change that is the new
 * one.
 *
 * And Laravel's notes nothing on a request that began with nobody signed in,
 * which is what a sign-in through the form is. It looks at the start, finds a
 * guest, and steps aside for the whole request. So a browser that signed in
 * had no password noted until it opened its first office page; one that signed
 * in and went no further, closed straight after or kept warm on purpose on the
 * old password, came back after the change and was let in on the new one.
 *
 * This notes it on the way out of any request that signs a browser in. A
 * request that begins signed in is Laravel's check, unchanged.
 */
class AdminSessionMiddleware extends AuthenticateSession
{
    public function handle($request, Closure $next)
    {
        if (! $request->hasSession() || $request->user()) {
            return parent::handle($request, $next);
        }

        return tap($next($request), function () use ($request) {
            if (! is_null($this->guard()->user())) {
                $this->storePasswordHashInSession($request);
            }
        });
    }
}
