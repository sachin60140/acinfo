<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UserAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Gate on the key that actually identifies the client. The pages behind this
        // middleware look their data up by 'userid', so gating on anything else lets
        // a session through that those pages cannot resolve.
        if (! session()->has('userid')) {
            $request->session()->flash('error', 'User Access Not Permitted');

            return redirect('/user');
        }

        return $next($request);
    }
}
