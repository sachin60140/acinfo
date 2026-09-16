<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\CustomerAuthMiddleware;
use App\Http\Middleware\UserAuthMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        /*
         * Which sidebar sections the reader has shut.
         *
         * Read by _sidebar.blade.php so the markup arrives in the right state
         * rather than being corrected by script afterwards, and written by the
         * browser when a heading is clicked — which is why it cannot be
         * encrypted: an encrypted cookie the browser wrote is one Laravel
         * discards as tampered with, silently, leaving the menu stuck open.
         *
         * It holds nothing but the group keys from that file. Nothing is
         * decided by it but what is rolled up.
         */
        $middleware->encryptCookies(except: ['nav_collapsed']);

        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'userAuth' => UserAuthMiddleware::class,

            // The customer portal. Its own gate on its own session key — see
            // the note in CustomerAuthMiddleware for why it is not userAuth
            // with a party id put into it.
            'customerAuth' => CustomerAuthMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
