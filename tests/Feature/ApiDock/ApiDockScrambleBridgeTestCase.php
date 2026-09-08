<?php

namespace Lvntr\StarterKit\Tests\Feature\ApiDock;

use Dedoc\Scramble\ScrambleServiceProvider;
use Illuminate\Foundation\Application;
use LvntR\ApiDock\ApiDockServiceProvider;
use Lvntr\StarterKit\StarterKitServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots the three providers in the order Composer's package discovery produces
 * them (installed.json is name-sorted: dedoc/scramble, lvntr/api-dock,
 * lvntr/laravel-starter-kit).
 *
 * The order is the point: Scramble booting FIRST is the case where a
 * boot()-phase `ignoreDefaultRoutes()` would be too late and `docs/api` would
 * still be registered.
 */
class ApiDockScrambleBridgeTestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ScrambleServiceProvider::class,
            ApiDockServiceProvider::class,
            StarterKitServiceProvider::class,
        ];
    }
}
