<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\AdminSessionMiddleware;
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
            'userAuth' => UserAuthMiddleware::class,

            // The customer portal. Its own gate on its own session key — see
            // the note in CustomerAuthMiddleware for why it is not userAuth
            // with a party id put into it.
            'customerAuth' => CustomerAuthMiddleware::class,

            // The office's password check on its own, for the sign-in page
            // and the sign-in, which are not office pages (routes/web.php).
            'adminSession' => AdminSessionMiddleware::class,
        ]);

        /*
         * The office's gate, on every office page.
         *
         * AdminMiddleware lets in only a signed-in office account.
         * AdminSessionMiddleware (Laravel's auth.session, and one thing more)
         * checks that the password the session, or the Remember me cookie, was
         * signed in with is still the account's password, and signs that
         * browser out if it is not. It is what makes changing the password
         * reach every other browser: without it one left open on the old
         * password kept going for as long as somebody kept clicking.
         *
         * Laravel runs the password check first, whatever the order here: its
         * own list of what runs before what puts it ahead of AdminMiddleware.
         * That changes nothing. It waves a guest through untouched, for
         * AdminMiddleware to send to the sign-in page as before.
         *
         * A group rather than an alias, so that every route group saying
         * 'admin' gets both, and an office page added later cannot be given
         * the one without the other.
         */
        $middleware->group('admin', [
            AdminMiddleware::class,
            AdminSessionMiddleware::class,
        ]);

        // Where the password check sends a browser it has signed out: the
        // office sign-in page. Without this Laravel looks for a page named
        // 'login', which there is not, and the signed-out browser gets an
        // error page instead. Nothing else here uses Laravel's own sign-in
        // checks; the two portals keep their own (userAuth and customerAuth
        // above).
        $middleware->redirectGuestsTo('/admin');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
