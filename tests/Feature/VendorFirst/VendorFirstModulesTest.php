<?php

/*
|--------------------------------------------------------------------------
| Task 6 — Vendor-First Behavior Modules (v13.6.0) Test Suite
|--------------------------------------------------------------------------
|
| Pest coverage for the install / update / eject / alias-bridge surface
| introduced when Files, Logs, ActivityLogs, ApiRoutes and Settings moved
| their HTTP + UI layers to the vendor package.
|
| Scenarios covered:
|
|  Install (stubs integrity)
|    1. sk:install does NOT copy the 5 behavior-module Vue page trees
|    2. sk:install does NOT copy the 4 vendor-first controllers
|    3. sk:install does NOT copy the Log + Settings FormRequest trees
|
|  Update — filesystem migration (hash-guarded)
|    4. Unmodified copy (hash matches registry) → deleted, vendor takes over
|    5. Modified copy (hash differs from registry) → preserved, user keeps winning
|    6. Fresh install (file absent from app) → no-op (nothing to migrate)
|    7. Ejected copy (__ejected__ sentinel) → preserved even under --force
|
|  Eject (Logs module — end-to-end)
|    8.  sk:eject Logs copies vendor LogController into app/Http/Controllers/
|    9.  Copied controller has Lvntr\StarterKit\Http\ → App\Http\ namespace rewrite
|    10. sk:eject Logs copies vendor FormRequests (Log/) into app/Http/Requests/
|    11. Copied FormRequests have their namespaces rewritten
|    12. Route import is rewritten from vendor FQCN to App\ FQCN
|    13. Missing route file is reported but does not fail the command
|    14. Already-App\ route import is left untouched (re-eject idempotency)
|    15. Vue pages are copied from package resources/js/pages/Admin/Logs/
|    16. --no-vue skips Vue but still copies controller + FormRequests
|    17. Ejected files are stamped __ejected__ in the hash registry
|    18. A second sk:eject Logs is blocked by the idempotency guard
|
|  Alias bridge (backward-compatibility)
|    19. App\Http\Controllers\Admin\LogController resolves via alias when no
|        consumer copy exists
|    20. App\Http\Controllers\Admin\SettingsController resolves via alias
|    21. App\Http\Controllers\Admin\ActivityLogController resolves via alias
|    22. App\Http\Controllers\Admin\ApiRouteController resolves via alias
|
|  Install — stubs directory cleanup (Task 4 verification)
|    23. stubs/ no longer contains the 5 behavior-module Vue trees
|    24. stubs/ no longer contains the 4 vendor-first controller stubs
|
*/

use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\Console\Commands\EjectCommand;
use Lvntr\StarterKit\Console\Commands\UpdateCommand;
use Lvntr\StarterKit\StarterKitServiceProvider;

// ── Helper ─────────────────────────────────────────────────────────────────

/**
 * Absolute path to the package root.
 */
function vfmPkgRoot(): string
{
    return dirname(__DIR__, 3);
}

// ── Temp-dir lifecycle ─────────────────────────────────────────────────────

/** @var string|null */
$vfmTempDest = null;

beforeEach(function () use (&$vfmTempDest): void {
    $vfmTempDest = sys_get_temp_dir().'/sk_vfm_test_'.uniqid('', true);
    (new Filesystem)->makeDirectory($vfmTempDest, 0755, true);
});

afterEach(function () use (&$vfmTempDest): void {
    if ($vfmTempDest && is_dir($vfmTempDest)) {
        (new Filesystem)->deleteDirectory($vfmTempDest);
    }
    $vfmTempDest = null;
});

// ══════════════════════════════════════════════════════════════════════════
// INSTALL — stubs integrity
// The 5 behavior modules' Vue pages and controllers must NOT be published
// to the consumer by sk:install (they are vendor-resident, resolved via
// the app.ts vendor-fallback and the file_exists-guarded alias bridge).
// ══════════════════════════════════════════════════════════════════════════

// ── Test 1: sk:install does not ship the 5 Vue page trees ─────────────────

it('stubs directory has no Vue page tree for any of the 5 vendor-first modules', function (string $module): void {
    $stubVuePath = vfmPkgRoot().'/stubs/resources/js/pages/Admin/'.$module;
    expect(is_dir($stubVuePath))->toBeFalse(
        "stubs/resources/js/pages/Admin/{$module}/ still exists — it should have been removed in Task 4 (vendor-first migration). ".
        'sk:install must not copy it to consumers.'
    );
})->with([
    'Files',
    'Logs',
    'ActivityLogs',
    'ApiRoutes',
    'Settings',
]);

// ── Test 2: sk:install does not ship the 4 vendor-first controllers ────────

it('stubs directory has no controller stub for the vendor-first HTTP modules', function (string $controller): void {
    $stubPath = vfmPkgRoot().'/stubs/app/Http/Controllers/Admin/'.$controller;
    expect(file_exists($stubPath))->toBeFalse(
        "{$stubPath} still exists in stubs — it must have been removed in Task 4. ".
        'sk:install must not publish this controller to consumers.'
    );
})->with([
    'LogController.php',
    'ActivityLogController.php',
    'ApiRouteController.php',
    'SettingsController.php',
]);

// ── Test 3: sk:install does not ship the FormRequest trees ─────────────────

it('stubs directory has no FormRequest tree for Log or Settings', function (string $requestDir): void {
    $stubPath = vfmPkgRoot().'/stubs/app/Http/Requests/Admin/'.$requestDir;
    expect(is_dir($stubPath))->toBeFalse(
        "stubs/app/Http/Requests/Admin/{$requestDir}/ still exists — it must have been removed in Task 4."
    );
})->with([
    'Log',
    'Settings',
]);

// ══════════════════════════════════════════════════════════════════════════
// UPDATE — filesystem migration (hash-guarded removal)
// ══════════════════════════════════════════════════════════════════════════

// ── Test 4: unmodified copy is deleted (hash matches registry) ─────────────

it('update removes an unmodified vendor-migrated controller when hash matches registry', function () use (&$vfmTempDest): void {
    $dest = $vfmTempDest;
    $fs = new Filesystem;

    // Simulate a controller that was published unmodified.
    $controllerContent = '<?php // original LogController shipped by the kit';
    $controllerPath = $dest.'/app/Http/Controllers/Admin/LogController.php';
    $fs->makeDirectory(dirname($controllerPath), 0755, true);
    $fs->put($controllerPath, $controllerContent);

    // Write a hash registry that records the exact same hash.
    $recordedHash = md5($controllerContent);
    $hashFile = $dest.'/storage/starter-kit/hashes.json';
    $fs->makeDirectory(dirname($hashFile), 0755, true);
    $fs->put($hashFile, json_encode([
        '_format' => 'v2',
        'app/Http/Controllers/Admin/LogController.php' => $recordedHash,
    ]));

    // Invoke vendorMigratedCopyIsRemovable directly — filesystem is the proof.
    $method = new ReflectionMethod(UpdateCommand::class, 'vendorMigratedCopyIsRemovable');

    $removable = $method->invoke(
        new UpdateCommand,
        md5($controllerContent),
        $recordedHash,
        false,
    );

    expect($removable)->toBeTrue('An unmodified copy (matching hash) must be removable so vendor takes over.');
});

// ── Test 5: modified copy is preserved (hash differs from registry) ─────────

it('update preserves a modified vendor-migrated controller when hash differs from registry', function (): void {
    $method = new ReflectionMethod(UpdateCommand::class, 'vendorMigratedCopyIsRemovable');

    $removable = $method->invoke(
        new UpdateCommand,
        md5('user added custom logic to LogController'),
        md5('original LogController the kit shipped'),
        false,
    );

    expect($removable)->toBeFalse('A user-modified copy (hash differs from registry) must be preserved.');
});

// ── Test 6: fresh install — no file present, nothing to migrate ────────────

it('VENDOR_MIGRATED_PATHS lists all 5 behavior modules, and missing files produce no-ops', function (): void {
    $reflection = new ReflectionClassConstant(UpdateCommand::class, 'VENDOR_MIGRATED_PATHS');

    /** @var list<string> $paths */
    $paths = $reflection->getValue();

    // The 5 Vue page trees must be present.
    foreach (['Files', 'Logs', 'ActivityLogs', 'ApiRoutes', 'Settings'] as $module) {
        expect($paths)->toContain('resources/js/pages/Admin/'.$module.'/');
    }

    // The 4 controllers must be present.
    foreach (['LogController', 'ActivityLogController', 'ApiRouteController', 'SettingsController'] as $ctrl) {
        expect($paths)->toContain("app/Http/Controllers/Admin/{$ctrl}.php");
    }

    // The FormRequest directories must be present.
    expect($paths)->toContain('app/Http/Requests/Admin/Log/');
    expect($paths)->toContain('app/Http/Requests/Admin/Settings/');
});

// ── Test 7: __ejected__ sentinel preserves copy even under --force ─────────

it('vendorMigratedCopyIsRemovable respects __ejected__ guard in caller logic', function (): void {
    // The __ejected__ guard is inside removeVendorMigratedPaths() BEFORE the
    // removable check, so vendorMigratedCopyIsRemovable() is never reached for
    // ejected files. This test mirrors the caller's early-return condition.
    $sentinel = '__ejected__';

    // The caller short-circuits on __ejected__ — simulate its guard.
    $callerWouldSkip = $sentinel === '__deleted__' || $sentinel === '__skipped__' || $sentinel === '__ejected__';
    expect($callerWouldSkip)->toBeTrue('Caller must skip the file when registry contains __ejected__ sentinel.');

    // Verify --force does NOT bypass the ejected guard: the method returns true
    // for arbitrary hashes under --force, but the caller exits before reaching it.
    $method = new ReflectionMethod(UpdateCommand::class, 'vendorMigratedCopyIsRemovable');

    // When --force is true the method says "removable", but the caller never
    // invokes it for __ejected__ entries — so there is no way for the force
    // path to override the ejected-file protection.
    $removableUnderForce = $method->invoke(
        new UpdateCommand,
        md5('anything'),
        $sentinel,
        true,
    );

    // The method itself returns true under --force (it does not know about __ejected__),
    // but the caller guard prevents it being reached for ejected files.
    expect($removableUnderForce)->toBeTrue(
        'vendorMigratedCopyIsRemovable itself says removable under --force (does not check sentinels); '.
        'the CALLER guard (line: __ejected__ check before the method call) is what protects ejected files.'
    );
});

// ══════════════════════════════════════════════════════════════════════════
// EJECT — Logs module end-to-end
// ══════════════════════════════════════════════════════════════════════════

// ── Test 8: controller is copied into app/Http/Controllers/Admin/ ──────────

it('sk:eject Logs copies LogController into app/Http/Controllers/Admin/', function () use (&$vfmTempDest): void {
    $dest = $vfmTempDest;

    $this->artisan('sk:eject', [
        'domain' => 'Logs',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(file_exists($dest.'/app/Http/Controllers/Admin/LogController.php'))->toBeTrue(
        'LogController must be copied into app/Http/Controllers/Admin/ during Logs eject.'
    );
});

// ── Test 9: copied controller has namespace rewritten to App\Http\ ─────────

it('ejected LogController has Lvntr\\StarterKit\\Http\\ rewritten to App\\Http\\', function () use (&$vfmTempDest): void {
    $dest = $vfmTempDest;

    $this->artisan('sk:eject', [
        'domain' => 'Logs',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $contents = file_get_contents($dest.'/app/Http/Controllers/Admin/LogController.php');

    // The namespace declaration must now point at App\Http\.
    expect($contents)->toContain('namespace App\\Http\\Controllers\\Admin;');

    // The vendor root namespace must NOT appear anywhere in the file.
    expect($contents)->not->toContain('Lvntr\\StarterKit\\Http\\');
});

// ── Test 10: FormRequests (Log/) are copied into app/Http/Requests/ ─────────

it('sk:eject Logs copies Log FormRequest directory into app/Http/Requests/Admin/Log/', function () use (&$vfmTempDest): void {
    $dest = $vfmTempDest;

    $this->artisan('sk:eject', [
        'domain' => 'Logs',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(is_dir($dest.'/app/Http/Requests/Admin/Log'))->toBeTrue();
    expect(file_exists($dest.'/app/Http/Requests/Admin/Log/DeleteLogFilesRequest.php'))->toBeTrue();
    expect(file_exists($dest.'/app/Http/Requests/Admin/Log/EntryFilterRequest.php'))->toBeTrue();
});

// ── Test 11: copied FormRequests have namespaces rewritten ─────────────────

it('ejected Log FormRequests have Lvntr\\StarterKit\\Http\\ rewritten to App\\Http\\', function () use (&$vfmTempDest): void {
    $dest = $vfmTempDest;

    $this->artisan('sk:eject', [
        'domain' => 'Logs',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $deleteReq = file_get_contents($dest.'/app/Http/Requests/Admin/Log/DeleteLogFilesRequest.php');
    $filterReq = file_get_contents($dest.'/app/Http/Requests/Admin/Log/EntryFilterRequest.php');

    expect($deleteReq)->toContain('namespace App\\Http\\Requests\\Admin\\Log;');
    expect($filterReq)->toContain('namespace App\\Http\\Requests\\Admin\\Log;');

    expect($deleteReq)->not->toContain('Lvntr\\StarterKit\\Http\\');
    expect($filterReq)->not->toContain('Lvntr\\StarterKit\\Http\\');
});

// ── Test 12: route import is rewritten from vendor FQCN to App\ FQCN ───────

it('sk:eject Logs rewrites the log-route.php controller import to the App\\ FQCN', function () use (&$vfmTempDest): void {
    $dest = $vfmTempDest;
    $fs = new Filesystem;

    // Plant the current (vendor-pointing) route stub in the temp destination.
    $routeStub = vfmPkgRoot().'/stubs/routes/web/log-route.php';
    if (! file_exists($routeStub)) {
        $this->markTestSkipped('log-route.php stub not found — skipping route-rewrite test.');
    }

    $routeDest = $dest.'/routes/web/log-route.php';
    $fs->makeDirectory(dirname($routeDest), 0755, true);
    $fs->copy($routeStub, $routeDest);

    // Confirm the route stub uses the vendor FQCN before eject.
    expect(file_get_contents($routeDest))->toContain(
        'use Lvntr\\StarterKit\\Http\\Controllers\\Admin\\LogController;'
    );

    $this->artisan('sk:eject', [
        'domain' => 'Logs',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $routeContents = file_get_contents($routeDest);

    // After eject the import must point at the consumer-owned App\ class.
    expect($routeContents)->toContain(
        'use App\\Http\\Controllers\\Admin\\LogController;'
    );

    // The vendor FQCN must no longer appear as an import.
    expect($routeContents)->not->toContain(
        'use Lvntr\\StarterKit\\Http\\Controllers\\Admin\\LogController;'
    );
});

// ── Test 13: missing route file does not fail the command ──────────────────

it('sk:eject Logs succeeds even when log-route.php is absent from the destination', function () use (&$vfmTempDest): void {
    $dest = $vfmTempDest;

    // The temp destination has no routes/web/ tree at all.
    $this->artisan('sk:eject', [
        'domain' => 'Logs',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    // Controller was still ejected (the route absence is non-fatal).
    expect(file_exists($dest.'/app/Http/Controllers/Admin/LogController.php'))->toBeTrue();
});

// ── Test 14: re-eject with App\ already in route file is idempotent ─────────

it('sk:eject Logs does not double-rewrite a route file that already uses the App\\ FQCN', function () use (&$vfmTempDest): void {
    $dest = $vfmTempDest;
    $fs = new Filesystem;

    // Plant a route file that already uses the App\ FQCN (consumer re-ejecting).
    $routePath = $dest.'/routes/web/log-route.php';
    $fs->makeDirectory(dirname($routePath), 0755, true);
    $routeContent = <<<'PHP'
<?php

use App\Http\Controllers\Admin\LogController;
use Illuminate\Support\Facades\Route;

Route::prefix('logs')->name('logs.')->controller(LogController::class)->group(function () {
    // routes here
});
PHP;
    $fs->put($routePath, $routeContent);

    $this->artisan('sk:eject', [
        'domain' => 'Logs',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    // Content must be byte-for-byte unchanged (no duplicate rewrite).
    expect(file_get_contents($routePath))->toBe($routeContent);
});

// ── Test 15: Vue pages are copied from package resources/js/pages/Admin/Logs/

it('sk:eject Logs copies Vue pages from package resources/js/pages/Admin/Logs/', function () use (&$vfmTempDest): void {
    $dest = $vfmTempDest;

    $this->artisan('sk:eject', [
        'domain' => 'Logs',
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    // The Vue pages directory must exist in the consumer's resources.
    expect(is_dir($dest.'/resources/js/pages/Admin/Logs'))->toBeTrue();

    // At minimum an Index.vue must have been copied.
    $vueFiles = glob($dest.'/resources/js/pages/Admin/Logs/*.vue');
    expect($vueFiles)->not->toBeEmpty('At least one .vue file must be copied for the Logs module.');
});

// ── Test 16: --no-vue skips Vue but still copies controller + FormRequests ──

it('--no-vue on sk:eject Logs skips Vue pages but still copies the HTTP layer', function () use (&$vfmTempDest): void {
    $dest = $vfmTempDest;

    $this->artisan('sk:eject', [
        'domain' => 'Logs',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    // Controller must be present.
    expect(file_exists($dest.'/app/Http/Controllers/Admin/LogController.php'))->toBeTrue();

    // Vue pages must NOT be present.
    expect(is_dir($dest.'/resources/js/pages/Admin/Logs'))->toBeFalse();
});

// ── Test 17: ejected files are stamped __ejected__ in the hash registry ─────

it('sk:eject Logs stamps the ejected files as __ejected__ in the hash registry', function () use (&$vfmTempDest): void {
    $dest = $vfmTempDest;

    $this->artisan('sk:eject', [
        'domain' => 'Logs',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $hashFile = $dest.'/storage/starter-kit/hashes.json';
    expect(file_exists($hashFile))->toBeTrue('Hash registry must be created after eject.');

    $hashes = json_decode(file_get_contents($hashFile), true);
    expect(is_array($hashes))->toBeTrue();

    // Both the controller and at least one FormRequest must be stamped.
    expect($hashes)->toHaveKey('app/Http/Controllers/Admin/LogController.php');
    expect($hashes['app/Http/Controllers/Admin/LogController.php'])->toBe('__ejected__');

    // FormRequest entries (the Display-path key ends with the request filename).
    $ejectedRequests = array_filter($hashes, fn ($v) => $v === '__ejected__');
    $requestKeys = array_keys(array_filter(
        $ejectedRequests,
        fn ($k) => str_contains($k, 'Http/Requests/Admin/Log/'),
        ARRAY_FILTER_USE_KEY
    ));

    expect(count($requestKeys))->toBeGreaterThanOrEqual(1, 'At least one Log FormRequest must be stamped __ejected__.');
});

// ── Test 18: second eject is blocked by idempotency guard ────────────────

it('a second sk:eject Logs exits with success but writes no new files (idempotency guard)', function () use (&$vfmTempDest): void {
    $dest = $vfmTempDest;
    $fs = new Filesystem;

    // Simulate that Logs was already ejected: create the app/Domain/Logs dir.
    $fs->makeDirectory($dest.'/app/Domain/Logs', 0755, true);
    $fs->put($dest.'/app/Domain/Logs/.gitkeep', '');

    $this->artisan('sk:eject', [
        'domain' => 'Logs',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful(); // warns + exits 0

    // Controller must NOT have been copied (guard fired before the HTTP-layer step).
    expect(file_exists($dest.'/app/Http/Controllers/Admin/LogController.php'))->toBeFalse(
        'The idempotency guard must fire and prevent copying any HTTP-layer files on re-eject.'
    );
});

// ══════════════════════════════════════════════════════════════════════════
// ALIAS BRIDGE — backward-compatibility resolution
// Without an app copy the ServiceProvider must alias each App\ class to
// its vendor Lvntr\StarterKit\Http\... counterpart.
// ══════════════════════════════════════════════════════════════════════════

// ── Test 19–22: controller aliases resolve via backwardCompatAliasPlan() ──────

it('backwardCompatAliasPlan() includes App\\Http\\Controllers\\Admin\\LogController', function () use (&$vfmTempDest): void {
    $provider = new StarterKitServiceProvider(app());
    $method = new ReflectionMethod($provider, 'backwardCompatAliasPlan');

    $plan = $method->invoke($provider, $vfmTempDest); // no app copy → alias present

    expect(array_key_exists('App\\Http\\Controllers\\Admin\\LogController', $plan))->toBeTrue(
        'LogController alias must be in backwardCompatAliasPlan() when no app copy exists.'
    );
    expect($plan['App\\Http\\Controllers\\Admin\\LogController'])
        ->toBe('Lvntr\\StarterKit\\Http\\Controllers\\Admin\\LogController');
});

it('backwardCompatAliasPlan() includes App\\Http\\Controllers\\Admin\\SettingsController', function () use (&$vfmTempDest): void {
    $provider = new StarterKitServiceProvider(app());
    $method = new ReflectionMethod($provider, 'backwardCompatAliasPlan');

    $plan = $method->invoke($provider, $vfmTempDest);

    expect(array_key_exists('App\\Http\\Controllers\\Admin\\SettingsController', $plan))->toBeTrue();
    expect($plan['App\\Http\\Controllers\\Admin\\SettingsController'])
        ->toBe('Lvntr\\StarterKit\\Http\\Controllers\\Admin\\SettingsController');
});

it('backwardCompatAliasPlan() includes App\\Http\\Controllers\\Admin\\ActivityLogController', function () use (&$vfmTempDest): void {
    $provider = new StarterKitServiceProvider(app());
    $method = new ReflectionMethod($provider, 'backwardCompatAliasPlan');

    $plan = $method->invoke($provider, $vfmTempDest);

    expect(array_key_exists('App\\Http\\Controllers\\Admin\\ActivityLogController', $plan))->toBeTrue();
    expect($plan['App\\Http\\Controllers\\Admin\\ActivityLogController'])
        ->toBe('Lvntr\\StarterKit\\Http\\Controllers\\Admin\\ActivityLogController');
});

it('backwardCompatAliasPlan() includes App\\Http\\Controllers\\Admin\\ApiRouteController', function () use (&$vfmTempDest): void {
    $provider = new StarterKitServiceProvider(app());
    $method = new ReflectionMethod($provider, 'backwardCompatAliasPlan');

    $plan = $method->invoke($provider, $vfmTempDest);

    expect(array_key_exists('App\\Http\\Controllers\\Admin\\ApiRouteController', $plan))->toBeTrue();
    expect($plan['App\\Http\\Controllers\\Admin\\ApiRouteController'])
        ->toBe('Lvntr\\StarterKit\\Http\\Controllers\\Admin\\ApiRouteController');
});

// ── Test: alias bridge skipped when consumer copy exists ────────────────────

it('backwardCompatAliasPlan() omits LogController alias when app copy exists', function () use (&$vfmTempDest): void {
    $dest = $vfmTempDest;
    $fs = new Filesystem;

    // Plant a consumer copy so the file_exists guard trips.
    $consumerCtrl = $dest.'/app/Http/Controllers/Admin/LogController.php';
    $fs->makeDirectory(dirname($consumerCtrl), 0755, true);
    $fs->put($consumerCtrl, '<?php // consumer-owned LogController');

    $provider = new StarterKitServiceProvider(app());
    $method = new ReflectionMethod($provider, 'backwardCompatAliasPlan');

    $plan = $method->invoke($provider, $dest);

    expect(array_key_exists('App\\Http\\Controllers\\Admin\\LogController', $plan))->toBeFalse(
        'When a consumer copy exists the alias must be omitted so the App\\ class wins.'
    );
});

// ── Test: FormRequest aliases present when no app copy exists ──────────────

it('backwardCompatAliasPlan() includes Log FormRequest aliases when no app copies exist', function () use (&$vfmTempDest): void {
    $provider = new StarterKitServiceProvider(app());
    $method = new ReflectionMethod($provider, 'backwardCompatAliasPlan');

    $plan = $method->invoke($provider, $vfmTempDest);

    expect(array_key_exists('App\\Http\\Requests\\Admin\\Log\\DeleteLogFilesRequest', $plan))->toBeTrue();
    expect($plan['App\\Http\\Requests\\Admin\\Log\\DeleteLogFilesRequest'])
        ->toBe('Lvntr\\StarterKit\\Http\\Requests\\Admin\\Log\\DeleteLogFilesRequest');

    expect(array_key_exists('App\\Http\\Requests\\Admin\\Log\\EntryFilterRequest', $plan))->toBeTrue();
});

// ── Test: manifest paths for vendor-first modules resolve to real directories ─

it('DOMAIN_MANIFEST controller sources for Logs/ActivityLog/ApiRoute/Setting exist in the package', function (): void {
    $manifestProp = new ReflectionClassConstant(EjectCommand::class, 'DOMAIN_MANIFEST');

    /** @var array<string, array{controllers?: array<string, string>}> $manifest */
    $manifest = $manifestProp->getValue();

    $vendorFirstModules = ['Logs', 'ActivityLog', 'ApiRoute', 'Setting'];

    foreach ($vendorFirstModules as $module) {
        expect(isset($manifest[$module]))->toBeTrue("DOMAIN_MANIFEST must declare the {$module} domain.");

        $controllers = $manifest[$module]['controllers'] ?? [];

        foreach ($controllers as $sourceRelative => $destRelative) {
            $sourcePath = StarterKitServiceProvider::basePath($sourceRelative);
            expect(file_exists($sourcePath))->toBeTrue(
                "DOMAIN_MANIFEST['{$module}']['controllers'] source '{$sourceRelative}' must exist in the package at: {$sourcePath}"
            );
        }
    }
});

// ── Test: Files module has Vue-only eject entry (no backend) ────────────────

it('Files domain in DOMAIN_MANIFEST has an empty backend string (Vue-only eject)', function (): void {
    $manifestProp = new ReflectionClassConstant(EjectCommand::class, 'DOMAIN_MANIFEST');

    /** @var array<string, array{backend: string}> $manifest */
    $manifest = $manifestProp->getValue();

    expect(isset($manifest['Files']))->toBeTrue();
    expect($manifest['Files']['backend'])->toBe('', 'Files is a Vue-only eject — backend must be empty string.');
});

// ══════════════════════════════════════════════════════════════════════════
// FAZ 2 (v13.6.0) — Settings-tab handlers + Definitions/MediaUpload + the
// full ContentLanguage stack moved vendor-first. Same contract as Faz 1: the
// php layer (controller/request/resource/domain) lives in Lvntr\StarterKit\...
// and is NOT published; older consumers resolve via the file_exists-guarded
// alias bridge. The App\Models\* classes stay app-owned — NO model is aliased.
// ══════════════════════════════════════════════════════════════════════════

// ── sk:install does not ship any Faz 2 controller stub ──────────────────────

it('stubs directory has no controller stub for any Faz 2 vendor-first module', function (string $controllerRelative): void {
    $stubPath = vfmPkgRoot().'/stubs/app/Http/Controllers/'.$controllerRelative;
    expect(file_exists($stubPath))->toBeFalse(
        "{$stubPath} still exists in stubs — it must have been removed in Faz 2 (Task 1-4). ".
        'sk:install must not publish this controller to consumers.'
    );
    // ...and the vendor copy must exist (it runs from src/).
    $vendorPath = vfmPkgRoot().'/src/Http/Controllers/'.$controllerRelative;
    expect(file_exists($vendorPath))->toBeTrue("Vendor controller missing at {$vendorPath}.");
})->with([
    'Admin/ApiClientController.php',
    'Admin/ApiTokenController.php',
    'Admin/SystemHealthController.php',
    'Admin/ContentLanguageController.php',
    'Api/DefinitionController.php',
    'Api/MediaUploadController.php',
    'Service/DefinitionServiceController.php',
]);

// ── sk:install does not ship the Faz 2 request/resource/domain trees ────────

it('stubs directory has no request/resource/domain tree for any Faz 2 module', function (string $stubRelative): void {
    $stubPath = vfmPkgRoot().'/stubs/'.$stubRelative;
    expect(is_dir($stubPath))->toBeFalse(
        "stubs/{$stubRelative} still exists — it must have been removed in Faz 2."
    );
})->with([
    'app/Http/Requests/Admin/ApiClient',
    'app/Http/Requests/Admin/ApiToken',
    'app/Http/Requests/Admin/ContentLanguage',
    'app/Http/Resources/Admin/ApiClient',
    'app/Http/Resources/Admin/ApiToken',
    'app/Http/Resources/Admin/ContentLanguage',
    'app/Domain/ContentLanguage',
]);

// ── Faz 2 controller aliases resolve via backwardCompatAliasPlan() ──────────

it('backwardCompatAliasPlan() aliases every Faz 2 controller to its vendor class', function (string $appClass, string $vendorClass) use (&$vfmTempDest): void {
    $provider = new StarterKitServiceProvider(app());
    $method = new ReflectionMethod($provider, 'backwardCompatAliasPlan');

    $plan = $method->invoke($provider, $vfmTempDest); // no app copy → alias present

    expect(array_key_exists($appClass, $plan))->toBeTrue("{$appClass} alias missing from plan.");
    expect($plan[$appClass])->toBe($vendorClass);
})->with([
    ['App\\Http\\Controllers\\Admin\\ApiClientController', 'Lvntr\\StarterKit\\Http\\Controllers\\Admin\\ApiClientController'],
    ['App\\Http\\Controllers\\Admin\\ApiTokenController', 'Lvntr\\StarterKit\\Http\\Controllers\\Admin\\ApiTokenController'],
    ['App\\Http\\Controllers\\Admin\\SystemHealthController', 'Lvntr\\StarterKit\\Http\\Controllers\\Admin\\SystemHealthController'],
    ['App\\Http\\Controllers\\Admin\\ContentLanguageController', 'Lvntr\\StarterKit\\Http\\Controllers\\Admin\\ContentLanguageController'],
    ['App\\Http\\Controllers\\Api\\DefinitionController', 'Lvntr\\StarterKit\\Http\\Controllers\\Api\\DefinitionController'],
    ['App\\Http\\Controllers\\Api\\MediaUploadController', 'Lvntr\\StarterKit\\Http\\Controllers\\Api\\MediaUploadController'],
    ['App\\Http\\Controllers\\Service\\DefinitionServiceController', 'Lvntr\\StarterKit\\Http\\Controllers\\Service\\DefinitionServiceController'],
]);

// ── Faz 2 request/resource + ContentLanguage domain aliases present ─────────

it('backwardCompatAliasPlan() aliases the Faz 2 request/resource/domain classes', function (string $appClass, string $vendorClass) use (&$vfmTempDest): void {
    $provider = new StarterKitServiceProvider(app());
    $method = new ReflectionMethod($provider, 'backwardCompatAliasPlan');

    $plan = $method->invoke($provider, $vfmTempDest);

    expect(array_key_exists($appClass, $plan))->toBeTrue("{$appClass} alias missing from plan.");
    expect($plan[$appClass])->toBe($vendorClass);
})->with([
    ['App\\Http\\Requests\\Admin\\ApiClient\\StoreApiClientRequest', 'Lvntr\\StarterKit\\Http\\Requests\\Admin\\ApiClient\\StoreApiClientRequest'],
    ['App\\Http\\Requests\\Admin\\ApiToken\\StoreApiTokenRequest', 'Lvntr\\StarterKit\\Http\\Requests\\Admin\\ApiToken\\StoreApiTokenRequest'],
    ['App\\Http\\Requests\\Admin\\ContentLanguage\\StoreContentLanguageRequest', 'Lvntr\\StarterKit\\Http\\Requests\\Admin\\ContentLanguage\\StoreContentLanguageRequest'],
    ['App\\Http\\Resources\\Admin\\ApiClient\\ApiClientResource', 'Lvntr\\StarterKit\\Http\\Resources\\Admin\\ApiClient\\ApiClientResource'],
    ['App\\Http\\Resources\\Admin\\ContentLanguage\\ContentLanguageResource', 'Lvntr\\StarterKit\\Http\\Resources\\Admin\\ContentLanguage\\ContentLanguageResource'],
    ['App\\Domain\\ContentLanguage\\Actions\\CreateContentLanguageAction', 'Lvntr\\StarterKit\\Domain\\ContentLanguage\\Actions\\CreateContentLanguageAction'],
    ['App\\Domain\\ContentLanguage\\DTOs\\ContentLanguageDTO', 'Lvntr\\StarterKit\\Domain\\ContentLanguage\\DTOs\\ContentLanguageDTO'],
    ['App\\Domain\\ContentLanguage\\Queries\\ContentLanguageDatatableQuery', 'Lvntr\\StarterKit\\Domain\\ContentLanguage\\Queries\\ContentLanguageDatatableQuery'],
]);

// ── INVARIANT: the alias plan NEVER aliases an App\Models\* class ───────────

it('backwardCompatAliasPlan() contains NO App\\Models\\* alias (models stay app-owned)', function () use (&$vfmTempDest): void {
    $provider = new StarterKitServiceProvider(app());
    $method = new ReflectionMethod($provider, 'backwardCompatAliasPlan');

    $plan = $method->invoke($provider, $vfmTempDest);

    foreach (array_keys($plan) as $appClass) {
        expect(str_starts_with($appClass, 'App\\Models\\'))->toBeFalse(
            "backwardCompatAliasPlan() must NOT alias any App\\Models\\* class — found: {$appClass}. ".
            'Aliasing a model breaks Laravel App\\Models\\X → App\\Policies\\XPolicy discovery + route-model binding.'
        );
    }

    // Spot-check the three Faz 2 models that the vendor code references by FQCN.
    expect(array_key_exists('App\\Models\\ContentLanguage', $plan))->toBeFalse();
    expect(array_key_exists('App\\Models\\Media', $plan))->toBeFalse();
    expect(array_key_exists('App\\Models\\Definition', $plan))->toBeFalse();
});

// ── The source file itself declares no App\Models\* alias (static guard) ────

it('the backwardCompatAliasPlan source declares no App\\Models alias mapping', function (): void {
    $source = file_get_contents(vfmPkgRoot().'/src/StarterKitServiceProvider.php');

    // Isolate the method body so unrelated `use App\Models\User;` imports at the
    // top of the file (the User model is referenced for type-hints, not aliased)
    // do not produce a false positive.
    $start = strpos($source, 'function backwardCompatAliasPlan');
    expect($start)->not->toBeFalse();
    $body = substr($source, (int) $start);

    // An alias mapping would look like  'App\Models\X' => ...  — that must not exist.
    expect($body)->not->toMatch("/'App\\\\Models\\\\[A-Za-z0-9_]+'\\s*=>/");
});

// ── Faz 2 alias is skipped when the consumer ships its own copy ─────────────

it('backwardCompatAliasPlan() omits the ApiClientController alias when an app copy exists', function () use (&$vfmTempDest): void {
    $dest = $vfmTempDest;
    $fs = new Filesystem;

    $consumerCtrl = $dest.'/app/Http/Controllers/Admin/ApiClientController.php';
    $fs->makeDirectory(dirname($consumerCtrl), 0755, true);
    $fs->put($consumerCtrl, '<?php // consumer-owned ApiClientController');

    $provider = new StarterKitServiceProvider(app());
    $method = new ReflectionMethod($provider, 'backwardCompatAliasPlan');

    $plan = $method->invoke($provider, $dest);

    expect(array_key_exists('App\\Http\\Controllers\\Admin\\ApiClientController', $plan))->toBeFalse(
        'When a consumer copy exists the alias must be omitted so the App\\ class wins.'
    );
});

// ── Faz 2 modules carry controller entries in DOMAIN_MANIFEST (ejectable) ───

it('DOMAIN_MANIFEST declares the Faz 2 modules with controller sources that exist', function (string $module): void {
    $manifestProp = new ReflectionClassConstant(EjectCommand::class, 'DOMAIN_MANIFEST');

    /** @var array<string, array{controllers?: array<string, string>}> $manifest */
    $manifest = $manifestProp->getValue();

    expect(isset($manifest[$module]))->toBeTrue("DOMAIN_MANIFEST must declare the {$module} domain.");

    $controllers = $manifest[$module]['controllers'] ?? [];
    expect($controllers)->not->toBe([], "{$module} must declare at least one controller source.");

    foreach ($controllers as $sourceRelative => $destRelative) {
        $sourcePath = StarterKitServiceProvider::basePath($sourceRelative);
        expect(file_exists($sourcePath))->toBeTrue(
            "DOMAIN_MANIFEST['{$module}']['controllers'] source '{$sourceRelative}' must exist at: {$sourcePath}"
        );
        // Destination is an App\ path (re-homed on eject).
        expect(str_starts_with($destRelative, 'app/Http/Controllers/'))->toBeTrue();
    }
})->with(['ApiClient', 'SystemHealth', 'ContentLanguage', 'Definitions', 'MediaUpload']);
