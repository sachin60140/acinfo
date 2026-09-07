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
