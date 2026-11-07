<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\HandleImpersonation;
use App\Http\Middleware\ResolveBodyContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// XAMPP runs PHP inside one threaded Apache process, shared by every app it
// serves. Loading .env with putenv() lets one app's DB_DATABASE reach another.
// Same fix as edutrustshsm; it also means this app no longer depends on a cached
// config to stay on its own database.
\Illuminate\Support\Env::disablePutenv();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        /*
         * Three surfaces, deliberately in separate route files (brief §1):
         *
         *   web.php  — /admin (the EdutrustPay operator) and /body (the body's
         *              own people). Session auth, custom RBAC.
         *   api.php  — /api/v1. Machines only. No session, no CSRF; authenticated
         *              by API key id + HMAC signature over the raw body.
         *
         * edutrustshsm keeps everything in one 669-line web.php, but it has a
         * single auth model. Ours differ enough that one file would mislead.
         */
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => CheckRole::class,
            'permission' => CheckPermission::class,
        ]);

        // Prepended, so the body-context memo is cleared before anything can
        // read it. See ResolveBodyContext for why that is not optional.
        $middleware->web(prepend: [ResolveBodyContext::class]);

        // Appended, so it runs after the session is available and after auth —
        // it has no opinion about anonymous requests, and it must be able to
        // read the impersonation the session holds.
        $middleware->web(append: [HandleImpersonation::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
