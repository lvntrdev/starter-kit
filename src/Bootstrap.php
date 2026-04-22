<?php

namespace Lvntr\StarterKit;

use App\Exceptions\ApiExceptionHandler;
use App\Http\Middleware\AssignTraceId;
use App\Http\Middleware\CheckResourcePermission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ValidateTurnstile;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/**
 * Runtime bootstrap wiring for the starter kit.
 *
 * Consumers call these helpers from `bootstrap/app.php` so the Laravel
 * default file stays intact except for two delegating lines. Class names
 * are written as fully qualified strings — the referenced classes come
 * from published stubs that exist in the consumer application, not in
 * this package.
 */
class Bootstrap
{
    /**
     * Register starter kit middleware, aliases, and auth redirect targets.
     */
    public static function middleware(Middleware $middleware): void
    {
        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            SecurityHeaders::class,
        ]);

        // AssignTraceId runs first on the API group so every downstream
        // handler (success path via ApiResponse, error path via
        // ApiExceptionHandler) can read a single shared trace id from the
        // request attributes.
        $middleware->api(prepend: [
            AssignTraceId::class,
        ]);

        $middleware->alias([
            'auth' => Authenticate::class,
            'guest' => RedirectIfAuthenticated::class,
            'check.permission' => CheckResourcePermission::class,
            'turnstile' => ValidateTurnstile::class,
        ]);

        $middleware->redirectTo(guests: '/login', users: '/dashboard');
    }

    /**
     * Register the API exception handler for JSON responses.
     */
    public static function exceptions(Exceptions $exceptions): void
    {
        ApiExceptionHandler::register($exceptions);
    }
}
