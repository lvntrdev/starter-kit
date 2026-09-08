<?php

namespace Lvntr\StarterKit\Tests\Feature\ApiDock;

use App\Http\Middleware\CheckApiDocsAccess;
use Dedoc\Scramble\ScrambleServiceProvider;
use Illuminate\Foundation\Application;
use LvntR\ApiDock\ApiDockServiceProvider;
use Lvntr\StarterKit\Tests\PermissionMiddlewareTestCase;

/**
 * DB + Spatie-permission base case for the api-dock access-gate regression.
 *
 * Extends PermissionMiddlewareTestCase (users table + Spatie permission
 * tables + PermissionServiceProvider + StarterKitServiceProvider, whose
 * configureScramble() defines the `viewApiDocs` Gate under
 * runningInConsole() === true) and layers on the two providers that boot the
 * real api-dock panel route: ScrambleServiceProvider and
 * ApiDockServiceProvider. Booting both, rather than asserting against a
 * synthetic route, is deliberate — a test that passed because the provider
 * never booted would be worse than no test.
 *
 * The `api-dock.middleware` config is overridden to mirror
 * stubs/config/api-dock.php's one deliberate change from the package
 * default: the panel is gated behind the kit's own CheckApiDocsAccess
 * (`viewApiDocs` ability), not the package's separate `viewApiDock` gate
 * (which the kit leaves off).
 */
abstract class ApiDockAccessGateTestCase extends PermissionMiddlewareTestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            ScrambleServiceProvider::class,
            ApiDockServiceProvider::class,
        ]);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('api-dock.middleware', ['web', 'auth', CheckApiDocsAccess::class]);
    }
}
