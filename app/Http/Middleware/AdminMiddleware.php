<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect(url('/admin'));
        }

        // Authorisation failure must not destroy a valid session: redirect only.
        if (Auth::user()->user_type != 1) {
            return redirect(url('/admin'))->with('error', 'You do not have access to the admin area.');
        }

        return $next($request);
    }
}
