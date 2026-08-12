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
        if (! empty(Auth::check())) {
            if (Auth::user()->user_type == 1) {
                return $next($request);
            } else {
                Auth::logout();

                return redirect(url('/admin'));
            }
        } else {
            Auth::logout();

            return redirect(url('/admin'));
        }
    }
}
