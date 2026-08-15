<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
// Sanctum's own classes are referenced below only as ::class strings, which PHP
// resolves at compile time without autoloading. Nothing in this file may *call*
// into the package: it is not installed on the production host.
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    /*
     * Derived from APP_URL rather than from Sanctum::currentApplicationUrlWithPort().
     *
     * Config files are evaluated on every boot, so that call needed the Sanctum
     * class to exist before the framework had finished starting. The production
     * host has no laravel/sanctum in vendor/ and cannot get one — composer.lock
     * pins Laravel 13 while that server runs 11.9 — so the moment this file
     * arrived there, every request died with "Class Laravel\Sanctum\Sanctum not
     * found", artisan included.
     *
     * Reading the host out of APP_URL gives the same answer with no dependency
     * on the package, which is the shape Laravel itself moved to later.
     */
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        ($sanctumAppUrl = env('APP_URL')) ? ','.parse_url($sanctumAppUrl, PHP_URL_HOST) : ''
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. If this value is null, personal access tokens do
    | not expire. This won't tweak the lifetime of first-party sessions.
    |
    */

    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => PreventRequestForgery::class,
    ],

];
