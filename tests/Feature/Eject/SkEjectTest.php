<?php

/*
|--------------------------------------------------------------------------
| sk:eject — EjectCommand Feature Tests
|--------------------------------------------------------------------------
|
| All tests use --destination to write into a temp directory so the real
| consumer app/  is never touched.  The temp tree is torn down after each
| test via afterEach().
|
| Covered scenarios:
|
|  1. --dry-run  lists plan but writes nothing
|  2. Backend ns-rewrite: eject namespace → App\, Shared reference preserved
|  3. DomainServiceProvider: 3 bindings injected for User (event domain)
|  4. Event binding idempotency: second eject does not add duplicate lines
|  5. --no-vue  does not touch Vue directory
|  6. Event/vue-less domain (Session) leaves provider and Vue untouched
|  7. Alias skip: after eject, backwardCompatAliasPlan() omits the User alias
|  8. Unknown domain returns failure + shows valid list
|  9. Existing app/Domain without --force exits early (idempotency guard)
| 10. Manifest backend paths all resolve to real directories (integrity check)
| 11. Vue guard: existing customized page preserved without --force (no data loss)
| 12. Vue guard: existing page overwritten when --force is passed
| 13. Autoload failure → non-zero exit code (CI-safe); files still ejected
| 14. --skip-autoload skips the dump and still returns SUCCESS (even broken phar)
| 15. --force over a stub-published BulkActions-only dir: runtime copied,
|     pre-existing BulkActions file left byte-for-byte intact (install path)
| 16. Ejected controller own-domain rewrite: Logs controller binds to
|     App\Domain\Logs\ (not vendor) — no dead-code app/Domain after eject
| 17. Cross-domain reference stays vendor: ejecting ApiRoute leaves the
|     controller's Domain\Setting\SettingService pointing at vendor (scoped rewrite)
| 18. Skipped pre-existing file still stamped __ejected__ in the hash registry
|     (sk:update must not later delete an owned, ejected module copy)
| 19. Legacy v1 registry (no _format) is NOT upgraded to v2 on eject — sentinels
|     added but format left intact so sk:update's v1→v2 re-derivation still runs
| 20. A fresh (no prior) registry IS stamped _format=v2 on eject
|
| Ownership consent gate (DX tutarlılık, madde 26):
| 25. Declining the "you will own this domain" confirmation aborts before any
|     file is written, without --force / --no-interaction
| 26. Confirming the prompt proceeds with the eject as normal
|
*/

use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\Console\Commands\EjectCommand;
use Lvntr\StarterKit\StarterKitServiceProvider;

// ── Helpers ────────────────────────────────────────────────────────────────

/**
 * Absolute path to the package root (one level above src/).
 */
function pkgRoot(): string
{
    return dirname(__DIR__, 3);
}

/**
 * Create a minimal DomainServiceProvider.php at the given destination root.
 * Mirrors the shipped stub exactly so injection can locate boot().
 */
function makeDomainServiceProvider(string $destRoot): void
{
    $fs = new Filesystem;
    $dir = $destRoot.'/app/Providers';
    $fs->makeDirectory($dir, 0755, true, true);

    $fs->put($dir.'/DomainServiceProvider.php', <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class DomainServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // domain events go here
    }
}
PHP);
}

// ── Temp-dir lifecycle ─────────────────────────────────────────────────────

/** @var string|null */
$tempDest = null;

beforeEach(function () use (&$tempDest): void {
    $tempDest = sys_get_temp_dir().'/sk_eject_test_'.uniqid('', true);
    (new Filesystem)->makeDirectory($tempDest, 0755, true);
});

afterEach(function () use (&$tempDest): void {
    if ($tempDest && is_dir($tempDest)) {
        (new Filesystem)->deleteDirectory($tempDest);
    }
    $tempDest = null;
});

// ── Test 1: --dry-run lists plan but writes nothing ────────────────────────

it('--dry-run shows plan and writes no files', function () use (&$tempDest): void {
    $dest = $tempDest;

    $this->artisan('sk:eject', [
        'domain' => 'Session',
        '--dry-run' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    // Nothing written into destination
    expect(is_dir($dest.'/app/Domain/Session'))->toBeFalse();
});

// ── Test 2: namespace rewrite — domain ns → App\, Shared preserved ─────────

it('ejects backend with correct namespace rewrite; Shared reference preserved', function () use (&$tempDest): void {
    $dest = $tempDest;

    $this->artisan('sk:eject', [
        'domain' => 'User',
        '--no-vue' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $actionPath = $dest.'/app/Domain/User/Actions/CreateUserAction.php';
    expect(file_exists($actionPath))->toBeTrue();

    $contents = file_get_contents($actionPath);

    // Namespace declaration rewritten to App\
    expect($contents)->toContain('namespace App\\Domain\\User\\Actions;');

    // use of the same domain class also rewritten
    expect($contents)->toContain('use App\\Domain\\User\\DTOs\\UserDTO;');
    expect($contents)->toContain('use App\\Domain\\User\\Events\\UserCreated;');

    // Shared base import left untouched (Lvntr FQCN must remain)
    expect($contents)->toContain('use Lvntr\\StarterKit\\Domain\\Shared\\Actions\\BaseAction;');

    // Lvntr\StarterKit\Domain\User\ must NOT appear anywhere anymore
    expect($contents)->not->toContain('Lvntr\\StarterKit\\Domain\\User\\');
});

// ── Test 3: DomainServiceProvider gets 3 Event::listen bindings ───────────

it('injects 3 App-FQCN event bindings into DomainServiceProvider for User', function () use (&$tempDest): void {
    $dest = $tempDest;
    makeDomainServiceProvider($dest);

    $this->artisan('sk:eject', [
        'domain' => 'User',
        '--no-vue' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $providerPath = $dest.'/app/Providers/DomainServiceProvider.php';
    $code = file_get_contents($providerPath);

    // All three User event bindings must be injected
    expect($code)->toContain('App\\Domain\\User\\Events\\UserCreated::class');
    expect($code)->toContain('App\\Domain\\User\\Listeners\\LogUserCreated::class');

    expect($code)->toContain('App\\Domain\\User\\Events\\UserUpdated::class');
    expect($code)->toContain('App\\Domain\\User\\Listeners\\LogUserUpdated::class');

    expect($code)->toContain('App\\Domain\\User\\Events\\UserDeleted::class');
    expect($code)->toContain('App\\Domain\\User\\Listeners\\LogUserDeleted::class');

    // Count exactly 3 Event::listen calls added (not more)
    expect(substr_count($code, 'Event::listen('))->toBe(3);
});

// ── Test 4: Event binding idempotency ─────────────────────────────────────

it('second eject does not duplicate event bindings in DomainServiceProvider', function () use (&$tempDest): void {
    $dest = $tempDest;
    makeDomainServiceProvider($dest);

    $args = [
        'domain' => 'User',
        '--force' => true,
        '--no-vue' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ];

    $this->artisan('sk:eject', $args)->assertSuccessful();
    $this->artisan('sk:eject', $args)->assertSuccessful();

    $code = file_get_contents($dest.'/app/Providers/DomainServiceProvider.php');

    // Must still be exactly 3 — not 6
    expect(substr_count($code, 'Event::listen('))->toBe(3);
});

// ── Test 5: --no-vue leaves Vue directory untouched ───────────────────────

it('--no-vue does not write any Vue files', function () use (&$tempDest): void {
    $dest = $tempDest;

    $this->artisan('sk:eject', [
        'domain' => 'User',
        '--no-vue' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    // resources/js/pages/Admin/Users must NOT exist
    expect(is_dir($dest.'/resources/js/pages/Admin/Users'))->toBeFalse();
    expect(file_exists($dest.'/resources/js/types/user.ts'))->toBeFalse();
});

// ── Test 6: Event/vue-less domain (Session) leaves provider & Vue alone ───

it('ejecting Session (no events, no vue) leaves DomainServiceProvider unchanged', function () use (&$tempDest): void {
    $dest = $tempDest;
    makeDomainServiceProvider($dest);

    $originalProvider = file_get_contents($dest.'/app/Providers/DomainServiceProvider.php');

    $this->artisan('sk:eject', [
        'domain' => 'Session',
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    // Provider file unchanged (no Event::listen added)
    $updatedProvider = file_get_contents($dest.'/app/Providers/DomainServiceProvider.php');
    expect($updatedProvider)->toBe($originalProvider);

    // No Vue directory created
    expect(is_dir($dest.'/resources'))->toBeFalse();
});

// ── Test 7: alias skip — backwardCompatAliasPlan() omits User after eject ─

it('backwardCompatAliasPlan() skips User aliases when app/Domain/User exists', function () use (&$tempDest): void {
    $dest = $tempDest;

    // Simulate the eject: create the sentinel file that the alias guard checks.
    // The guard is: file_exists($basePath . '/app/' . $relativePath . '.php')
    // For 'App\Domain\User\Actions\CreateUserAction' the relative path is
    // 'Domain/User/Actions/CreateUserAction', so the full check path is
    // $basePath/app/Domain/User/Actions/CreateUserAction.php.
    $fs = new Filesystem;
    $actionDir = $dest.'/app/Domain/User/Actions';
    $fs->makeDirectory($actionDir, 0755, true);
    $fs->put($actionDir.'/CreateUserAction.php', '<?php');

    $provider = new StarterKitServiceProvider(app());
    $reflect = new ReflectionMethod($provider, 'backwardCompatAliasPlan');

    /** @var array<class-string, class-string> $plan */
    $plan = $reflect->invoke($provider, $dest);

    // The User CreateUserAction alias must NOT be in the plan
    expect(array_key_exists('App\\Domain\\User\\Actions\\CreateUserAction', $plan))->toBeFalse();

    // A domain that was NOT ejected (e.g. Session) must still be aliased
    expect(array_key_exists('App\\Domain\\Session\\Actions\\PurgeOtherSessionsAction', $plan))->toBeTrue();
});

// ── Test 8: unknown domain returns failure ─────────────────────────────────

it('unknown domain returns FAILURE exit code and lists valid domains', function () use (&$tempDest): void {
    $this->artisan('sk:eject', [
        'domain' => 'NonExistentDomain',
        '--destination' => $tempDest,
        '--no-interaction' => true,
    ])->assertFailed();
});

// ── Test 9: idempotency guard — existing app/Domain without --force exits ──

it('exits early without --force when app/Domain/{name} already exists', function () use (&$tempDest): void {
    $dest = $tempDest;
    $fs = new Filesystem;

    // Pre-create the domain directory
    $fs->makeDirectory($dest.'/app/Domain/User', 0755, true);
    $sentinel = $dest.'/app/Domain/User/sentinel.php';
    $fs->put($sentinel, '<?php // existing file');

    $this->artisan('sk:eject', [
        'domain' => 'User',
        '--no-vue' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful(); // exits 0 (warn + exit, not FAILURE)

    // The sentinel must NOT have been overwritten
    expect(file_get_contents($sentinel))->toBe('<?php // existing file');

    // No additional files (CreateUserAction etc.) should have been copied
    expect(file_exists($dest.'/app/Domain/User/Actions/CreateUserAction.php'))->toBeFalse();
});

// ── Test 10: manifest backend paths resolve to real directories ────────────

it('every manifest backend path exists as a real directory in the package', function (): void {
    $manifestProp = new ReflectionClassConstant(EjectCommand::class, 'DOMAIN_MANIFEST');
    /** @var array<string, array{backend: string, vue: array<string, string>, events: array<string, string>}> $manifest */
    $manifest = $manifestProp->getValue();

    foreach ($manifest as $domain => $descriptor) {
        // Vue-only descriptors (Files) carry backend === '' on purpose — the backend
        // stays vendor and is never ejected. basePath('') would resolve to the package
        // root (a dir) and pass by accident, so skip them: there is no backend dir to
        // assert. The descriptors that DO declare a backend must resolve to a real dir.
        if ($descriptor['backend'] === '') {
            continue;
        }

        $path = StarterKitServiceProvider::basePath($descriptor['backend']);
        expect(is_dir($path))->toBeTrue(
            "DOMAIN_MANIFEST['$domain']['backend'] = '{$descriptor['backend']}' is not a real directory at: $path"
        );
    }
});

// ── Test 11: Vue guard — existing customized page preserved without --force ─

it('preserves an existing customized Vue page when --force is not passed', function () use (&$tempDest): void {
    $dest = $tempDest;
    $fs = new Filesystem;

    // Consumer customized Users/Index.vue (a page shipped earlier by sk:install).
    $vueDir = $dest.'/resources/js/pages/Admin/Users';
    $fs->makeDirectory($vueDir, 0755, true);
    $indexPath = $vueDir.'/Index.vue';
    $fs->put($indexPath, '<!-- CUSTOM USER INDEX -->');

    // First eject: app/Domain/User does not exist, so the idempotency guard does
    // not fire and the command proceeds into the Vue step.
    $this->artisan('sk:eject', [
        'domain' => 'User',
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    // The customized page must survive untouched — no silent data loss.
    expect(file_get_contents($indexPath))->toBe('<!-- CUSTOM USER INDEX -->');
});

// ── Test 12: Vue guard — --force overwrites the existing page ───────────────

it('overwrites an existing Vue page when --force is passed', function () use (&$tempDest): void {
    $dest = $tempDest;
    $fs = new Filesystem;

    $vueDir = $dest.'/resources/js/pages/Admin/Users';
    $fs->makeDirectory($vueDir, 0755, true);
    $indexPath = $vueDir.'/Index.vue';
    $fs->put($indexPath, '<!-- CUSTOM USER INDEX -->');

    $this->artisan('sk:eject', [
        'domain' => 'User',
        '--force' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    // With --force the kit stub replaces the custom content.
    expect(file_exists($indexPath))->toBeTrue();
    expect(file_get_contents($indexPath))->not->toBe('<!-- CUSTOM USER INDEX -->');
});

// ── Test 13: autoload failure returns a non-zero exit code (CI-safe) ────────

it('returns a non-zero exit code when composer dump-autoload fails', function () use (&$tempDest): void {
    $dest = $tempDest;
    $fs = new Filesystem;

    // A real composer.json so refreshAutoload() does not early-return, plus a
    // fake composer.phar that always fails — findComposerBinary() prefers the
    // phar, so `php composer.phar dump-autoload -q` runs and exits non-zero.
    $fs->put($dest.'/composer.json', '{}');
    $fs->put($dest.'/composer.phar', '<?php exit(23);');

    $this->artisan('sk:eject', [
        'domain' => 'User',
        '--no-vue' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertFailed();

    // The backend files were still copied — the failure is the autoload step,
    // not the eject itself — but the command signals failure so automation halts.
    expect(file_exists($dest.'/app/Domain/User/Actions/CreateUserAction.php'))->toBeTrue();
});

// ── Test 14: --skip-autoload skips the dump and still returns SUCCESS ─────────

it('--skip-autoload copies files and returns SUCCESS without running dump-autoload', function () use (&$tempDest): void {
    $dest = $tempDest;

    // No composer.json at destination — refreshAutoload() would short-circuit anyway,
    // but the test confirms --skip-autoload path is followed (no dump attempted).
    $this->artisan('sk:eject', [
        'domain' => 'User',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    // Backend files must be present.
    expect(file_exists($dest.'/app/Domain/User/Actions/CreateUserAction.php'))->toBeTrue();
});

it('--skip-autoload does not fail even when composer.phar is broken', function () use (&$tempDest): void {
    $dest = $tempDest;
    $fs = new Filesystem;

    // A real composer.json triggers refreshAutoload() when --skip-autoload is NOT set.
    // With --skip-autoload the dump is bypassed so the broken phar must not cause FAILURE.
    $fs->put($dest.'/composer.json', '{}');
    $fs->put($dest.'/composer.phar', '<?php exit(23);');

    $this->artisan('sk:eject', [
        'domain' => 'User',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful(); // SUCCESS — the broken phar is never invoked

    // Files were still ejected.
    expect(file_exists($dest.'/app/Domain/User/Actions/CreateUserAction.php'))->toBeTrue();
});

// ── Test 15: --force eject over a stub-published BulkActions-only dir ──────────
// Mirrors the install path: stub publish ships app/Domain/User/BulkActions/* and
// creates the domain dir BEFORE eject runs, which (without --force) would trip the
// directory-level idempotency guard and silently no-op. InstallCommand therefore
// passes --force; this is safe because vendor src/Domain/User ships no BulkActions,
// so the forced eject relocates the runtime WITHOUT touching the consumer's
// BulkActions file.

it('--force ejects runtime over a pre-existing BulkActions dir without clobbering it', function () use (&$tempDest): void {
    $dest = $tempDest;
    $fs = new Filesystem;

    // Simulate the stub publish: a BulkActions-only app/Domain/User from stubs/.
    // Its presence makes app/Domain/User exist, so an un-forced eject would warn
    // + exit early (Test 9). The sentinel content lets us prove it is untouched.
    $bulkDir = $dest.'/app/Domain/User/BulkActions';
    $fs->makeDirectory($bulkDir, 0755, true);
    $bulkFile = $bulkDir.'/BulkDeleteUserAction.php';
    $fs->put($bulkFile, '<?php // CONSUMER BULK DELETE — must survive eject');

    // Install-path invocation: --force --no-vue --skip-autoload --destination.
    $this->artisan('sk:eject', [
        'domain' => 'User',
        '--force' => true,
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    // 1. The vendor runtime (every src/Domain/User subdir) is now copied in.
    expect(is_dir($dest.'/app/Domain/User/Actions'))->toBeTrue();
    expect(file_exists($dest.'/app/Domain/User/Actions/CreateUserAction.php'))->toBeTrue();
    expect(is_dir($dest.'/app/Domain/User/DTOs'))->toBeTrue();
    expect(is_dir($dest.'/app/Domain/User/Events'))->toBeTrue();
    expect(is_dir($dest.'/app/Domain/User/Listeners'))->toBeTrue();
    expect(is_dir($dest.'/app/Domain/User/Queries'))->toBeTrue();

    // 2. The pre-existing BulkActions file is byte-for-byte unchanged — vendor
    //    ships no BulkActions, so there is nothing to overwrite it with.
    expect(file_get_contents($bulkFile))->toBe('<?php // CONSUMER BULK DELETE — must survive eject');
});

// ── Test 16: ejected controller wires to the ejected app/Domain, not vendor ───
// Regression guard: ejecting a vendor-first behavior module (Logs) that ALSO ejects
// its backend must rewrite the controller's own domain segment to App\, otherwise
// the App controller keeps importing the VENDOR action and app/Domain/Logs is dead
// code (and the injected App event binding never fires).

it('rewrites the ejected controller own-domain imports to App\\ (Logs)', function () use (&$tempDest): void {
    $dest = $tempDest;

    $this->artisan('sk:eject', [
        'domain' => 'Logs',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $controllerPath = $dest.'/app/Http/Controllers/Admin/LogController.php';
    expect(file_exists($controllerPath))->toBeTrue();

    $contents = file_get_contents($controllerPath);

    // Own HTTP namespace flipped to App\.
    expect($contents)->toContain('namespace App\\Http\\Controllers\\Admin;');
    // Own FormRequest imports flipped to App\.
    expect($contents)->toContain('use App\\Http\\Requests\\Admin\\Log\\DeleteLogFilesRequest;');
    // Own DOMAIN imports flipped to App\ — the controller now binds to app/Domain/Logs.
    expect($contents)->toContain('use App\\Domain\\Logs\\Actions\\DeleteLogFilesAction;');
    expect($contents)->toContain('use App\\Domain\\Logs\\Queries\\LogFileQuery;');

    // No vendor Domain\Logs reference may survive — that would be the dead-code bug.
    expect($contents)->not->toContain('Lvntr\\StarterKit\\Domain\\Logs\\');

    // The ejected FormRequest directory is likewise own-domain rewritten.
    expect(file_exists($dest.'/app/Http/Requests/Admin/Log/DeleteLogFilesRequest.php'))->toBeTrue();
});

// ── Test 17: a cross-domain reference stays vendor on eject (ApiRoute → Setting) ─
// The own-domain rewrite must be SCOPED: ejecting ApiRoute (Setting NOT ejected)
// flips Domain\ApiRoute\ to App\ but leaves the ApiRouteController's cross-domain
// Domain\Setting\SettingService pointing at vendor.

it('keeps a cross-domain reference vendor when ejecting ApiRoute (SettingService)', function () use (&$tempDest): void {
    $dest = $tempDest;

    $this->artisan('sk:eject', [
        'domain' => 'ApiRoute',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $controllerPath = $dest.'/app/Http/Controllers/Admin/ApiRouteController.php';
    expect(file_exists($controllerPath))->toBeTrue();

    $contents = file_get_contents($controllerPath);

    // The ejected domain's own imports flip to App\.
    expect($contents)->toContain('use App\\Domain\\ApiRoute\\Queries\\ApiRouteListQuery;');
    expect($contents)->not->toContain('Lvntr\\StarterKit\\Domain\\ApiRoute\\');

    // The cross-domain Setting service MUST remain vendor — Setting is not ejected here.
    expect($contents)->toContain('use Lvntr\\StarterKit\\Domain\\Setting\\SettingService;');
});

// ── Test 18: a skipped (pre-existing) controller is still stamped __ejected__ ──
// Eject is an ownership declaration: a file already present without --force is left
// untouched but must be stamped '__ejected__' in the hash registry, or a later
// sk:update would see an "unmodified vendor-migrated copy" and delete it.

it('stamps a skipped pre-existing controller as __ejected__ in the hash registry', function () use (&$tempDest): void {
    $dest = $tempDest;
    $fs = new Filesystem;

    // The consumer already has the (old, published) controller copy in place.
    $controllerDir = $dest.'/app/Http/Controllers/Admin';
    $fs->makeDirectory($controllerDir, 0755, true);
    $controllerPath = $controllerDir.'/LogController.php';
    $fs->put($controllerPath, '<?php // PRE-EXISTING LOG CONTROLLER');

    // Eject WITHOUT --force: app/Domain/Logs does not exist, so the command proceeds
    // into the HTTP step, where the existing controller is preserved (skipped).
    $this->artisan('sk:eject', [
        'domain' => 'Logs',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    // The pre-existing file is byte-for-byte intact — no silent overwrite.
    expect(file_get_contents($controllerPath))->toBe('<?php // PRE-EXISTING LOG CONTROLLER');

    // ...AND it is stamped '__ejected__' so sk:update keeps it (never removes it).
    $hashFile = $dest.'/storage/starter-kit/hashes.json';
    expect(file_exists($hashFile))->toBeTrue();

    /** @var array<string, string> $hashes */
    $hashes = json_decode(file_get_contents($hashFile), true);
    expect($hashes['app/Http/Controllers/Admin/LogController.php'] ?? null)->toBe('__ejected__');
});

// ── Test 19: eject must NOT upgrade a v1 (legacy) registry to _format=v2 ───────
// A consumer who installed before v13.5 (or never ran sk:update) still has a v1
// registry: entries are TARGET hashes with no '_format' key. Stamping it 'v2'
// would make UpdateCommand::loadHashRegistry() skip the v1→v2 re-derivation and
// read v1 target-hashes as v2 stub-hashes, corrupting later remove/preserve
// decisions. Eject must add the '__ejected__' sentinels but leave the format as-is.

it('does not upgrade a legacy (v1, no _format) registry to v2 on eject', function () use (&$tempDest): void {
    $dest = $tempDest;
    $fs = new Filesystem;

    // Pre-existing v1 registry: TARGET hashes, no '_format' key.
    $hashDir = $dest.'/storage/starter-kit';
    $fs->makeDirectory($hashDir, 0755, true);
    $hashFile = $hashDir.'/hashes.json';
    $fs->put($hashFile, json_encode([
        'app/Http/Controllers/Admin/LogController.php' => 'deadbeefdeadbeefdeadbeefdeadbeef',
        'config/settings.php' => 'cafef00dcafef00dcafef00dcafef00d',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Pre-existing controller so the HTTP step skips-and-stamps (Test 18 path).
    $controllerDir = $dest.'/app/Http/Controllers/Admin';
    $fs->makeDirectory($controllerDir, 0755, true);
    $fs->put($controllerDir.'/LogController.php', '<?php // PRE-EXISTING LOG CONTROLLER');

    $this->artisan('sk:eject', [
        'domain' => 'Logs',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    /** @var array<string, string> $hashes */
    $hashes = json_decode(file_get_contents($hashFile), true);

    // Format NOT upgraded — the v1 registry stays v1 so the next sk:update migrates it.
    expect(array_key_exists('_format', $hashes))->toBeFalse();

    // The '__ejected__' sentinel is still written (format-agnostic ownership stamp).
    expect($hashes['app/Http/Controllers/Admin/LogController.php'] ?? null)->toBe('__ejected__');

    // The original v1 target-hash entries are left intact (not clobbered).
    expect($hashes['config/settings.php'] ?? null)->toBe('cafef00dcafef00dcafef00dcafef00d');
});

// ── Test 20: a fresh (no prior) registry IS stamped _format=v2 ────────────────
// When there is no registry yet, eject creates one and it must be v2 (the current
// format) so sk:update reads the sentinels without an unnecessary migration pass.

it('writes _format=v2 when creating a fresh hash registry on eject', function () use (&$tempDest): void {
    $dest = $tempDest;

    // No storage/starter-kit/hashes.json exists beforehand.
    $this->artisan('sk:eject', [
        'domain' => 'Logs',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $hashFile = $dest.'/storage/starter-kit/hashes.json';
    expect(file_exists($hashFile))->toBeTrue();

    /** @var array<string, string> $hashes */
    $hashes = json_decode(file_get_contents($hashFile), true);

    expect($hashes['_format'] ?? null)->toBe('v2');
    expect($hashes['app/Http/Controllers/Admin/LogController.php'] ?? null)->toBe('__ejected__');
});

// ══════════════════════════════════════════════════════════════════════════
// Faz 2 (v13.6.0) — ApiClient + ContentLanguage eject round-trips
// ══════════════════════════════════════════════════════════════════════════

// ── Test 21: ApiClient eject re-homes controller + request + resource under App\
// The ApiClient domain owns both Client + personal-access-token flows, so the
// ApiClient AND ApiToken controllers/requests/resources eject together. The
// App\ namespace flip is what makes the consumer's copy WIN over the vendor alias.

it('sk:eject ApiClient re-homes the controller/request/resource trees under App\\', function () use (&$tempDest): void {
    $dest = $tempDest;

    $this->artisan('sk:eject', [
        'domain' => 'ApiClient',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    // Controllers copied + own HTTP namespace flipped to App\.
    foreach (['ApiClientController', 'ApiTokenController'] as $ctrl) {
        $path = $dest.'/app/Http/Controllers/Admin/'.$ctrl.'.php';
        expect(file_exists($path))->toBeTrue("{$ctrl} must be ejected into app/Http/Controllers/Admin/.");

        $contents = file_get_contents($path);
        expect($contents)->toContain('namespace App\\Http\\Controllers\\Admin;');
        expect($contents)->not->toContain('Lvntr\\StarterKit\\Http\\');
    }

    // Request + resource trees copied and namespace-rewritten.
    $storeReq = $dest.'/app/Http/Requests/Admin/ApiClient/StoreApiClientRequest.php';
    expect(file_exists($storeReq))->toBeTrue();
    expect(file_get_contents($storeReq))->toContain('namespace App\\Http\\Requests\\Admin\\ApiClient;');

    $resource = $dest.'/app/Http/Resources/Admin/ApiClient/ApiClientResource.php';
    expect(file_exists($resource))->toBeTrue();
    expect(file_get_contents($resource))->toContain('namespace App\\Http\\Resources\\Admin\\ApiClient;');

    // ApiClient backend (Passport secret/token Actions) also re-homed under App\.
    $action = $dest.'/app/Domain/ApiClient/Actions/CreateApiClientAction.php';
    expect(file_exists($action))->toBeTrue();
    expect(file_get_contents($action))->toContain('namespace App\\Domain\\ApiClient\\Actions;');
});

// ── Test 22: ApiClient eject stamps the re-homed files __ejected__ ──────────

it('sk:eject ApiClient stamps the ejected controller as __ejected__ so sk:update keeps it', function () use (&$tempDest): void {
    $dest = $tempDest;

    $this->artisan('sk:eject', [
        'domain' => 'ApiClient',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $hashes = json_decode(file_get_contents($dest.'/storage/starter-kit/hashes.json'), true);

    expect($hashes['app/Http/Controllers/Admin/ApiClientController.php'] ?? null)->toBe('__ejected__');
    expect($hashes['app/Http/Controllers/Admin/ApiTokenController.php'] ?? null)->toBe('__ejected__');
});

// ── Test 23: ContentLanguage eject — full Tier 3 round-trip, MODEL stays App\
// The controller/request/resource/domain flip to App\, BUT App\Models\
// ContentLanguage is NEVER relocated (it is app-owned and already App\). This is
// the critical "models stay app-owned" invariant for the eject path.

it('sk:eject ContentLanguage re-homes the full stack but leaves App\\Models\\ContentLanguage untouched', function () use (&$tempDest): void {
    $dest = $tempDest;

    $this->artisan('sk:eject', [
        'domain' => 'ContentLanguage',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    // Controller: own HTTP + own DOMAIN segments flipped to App\.
    $ctrlPath = $dest.'/app/Http/Controllers/Admin/ContentLanguageController.php';
    expect(file_exists($ctrlPath))->toBeTrue();
    $ctrl = file_get_contents($ctrlPath);

    expect($ctrl)->toContain('namespace App\\Http\\Controllers\\Admin;');
    expect($ctrl)->toContain('use App\\Domain\\ContentLanguage\\Actions\\CreateContentLanguageAction;');
    expect($ctrl)->toContain('use App\\Http\\Requests\\Admin\\ContentLanguage\\StoreContentLanguageRequest;');
    expect($ctrl)->toContain('use App\\Http\\Resources\\Admin\\ContentLanguage\\ContentLanguageResource;');

    // No vendor own-domain / own-http reference may survive (would be dead code).
    expect($ctrl)->not->toContain('Lvntr\\StarterKit\\Domain\\ContentLanguage\\');
    expect($ctrl)->not->toContain('Lvntr\\StarterKit\\Http\\');

    // INVARIANT: the MODEL stays App\Models\ContentLanguage — app-owned, NOT relocated.
    expect($ctrl)->toContain('use App\\Models\\ContentLanguage;');
    // The model file itself is never copied into the eject destination.
    expect(file_exists($dest.'/app/Models/ContentLanguage.php'))->toBeFalse(
        'sk:eject must NOT relocate App\\Models\\ContentLanguage — the model stays app-owned (publish).'
    );

    // Domain runtime re-homed under App\, and STILL references the model by App\ FQCN.
    $domainAction = $dest.'/app/Domain/ContentLanguage/Actions/CreateContentLanguageAction.php';
    expect(file_exists($domainAction))->toBeTrue();
    $domain = file_get_contents($domainAction);
    expect($domain)->toContain('namespace App\\Domain\\ContentLanguage\\Actions;');
    expect($domain)->toContain('use App\\Models\\ContentLanguage;');
    expect($domain)->not->toContain('Lvntr\\StarterKit\\Domain\\ContentLanguage\\Actions;');

    // Request + resource trees copied + rewritten.
    $req = $dest.'/app/Http/Requests/Admin/ContentLanguage/StoreContentLanguageRequest.php';
    expect(file_exists($req))->toBeTrue();
    expect(file_get_contents($req))->toContain('namespace App\\Http\\Requests\\Admin\\ContentLanguage;');

    $res = $dest.'/app/Http/Resources/Admin/ContentLanguage/ContentLanguageResource.php';
    expect(file_exists($res))->toBeTrue();
    expect(file_get_contents($res))->toContain('namespace App\\Http\\Resources\\Admin\\ContentLanguage;');
});

// ── Test 24: after ContentLanguage eject, the alias plan omits the re-homed
// controller AND still never aliases the model. The alias-skip override is the
// mechanism that makes the ejected App\ controller win over the vendor copy.

it('backwardCompatAliasPlan() omits ContentLanguageController after eject, and never aliases the model', function () use (&$tempDest): void {
    $dest = $tempDest;

    $this->artisan('sk:eject', [
        'domain' => 'ContentLanguage',
        '--no-vue' => true,
        '--skip-autoload' => true,
        '--destination' => $dest,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $provider = new StarterKitServiceProvider(app());
    $method = new ReflectionMethod($provider, 'backwardCompatAliasPlan');

    /** @var array<class-string, class-string> $plan */
    $plan = $method->invoke($provider, $dest);

    // The ejected (now App\-owned) controller must NOT be aliased — the file_exists
    // guard trips on the consumer copy, so the App\ class wins.
    expect(array_key_exists('App\\Http\\Controllers\\Admin\\ContentLanguageController', $plan))->toBeFalse();
    // The ejected domain Action is likewise no longer aliased.
    expect(array_key_exists('App\\Domain\\ContentLanguage\\Actions\\CreateContentLanguageAction', $plan))->toBeFalse();

    // The model was never aliased to begin with (app-owned invariant).
    expect(array_key_exists('App\\Models\\ContentLanguage', $plan))->toBeFalse();
});

// ══════════════════════════════════════════════════════════════════════════
// Ownership consent gate — sk:eject without --force asks before writing
// ══════════════════════════════════════════════════════════════════════════

// ── Test 25: declining the confirmation aborts before any file is written ──

it('aborts without writing files when the ownership confirmation is declined', function () use (&$tempDest): void {
    $dest = $tempDest;

    $this->artisan('sk:eject', [
        'domain' => 'Session',
        '--destination' => $dest,
    ])
        ->expectsConfirmation(
            'Ejecting Session makes it app-owned: you will maintain it yourself and no longer receive kit runtime updates for it. Continue?',
            'no',
        )
        ->assertSuccessful();

    expect(is_dir($dest.'/app/Domain/Session'))->toBeFalse();
});

// ── Test 26: confirming the prompt proceeds with the eject as normal ───────

it('proceeds with the eject when the ownership confirmation is accepted', function () use (&$tempDest): void {
    $dest = $tempDest;

    $this->artisan('sk:eject', [
        'domain' => 'Session',
        '--destination' => $dest,
    ])
        ->expectsConfirmation(
            'Ejecting Session makes it app-owned: you will maintain it yourself and no longer receive kit runtime updates for it. Continue?',
            'yes',
        )
        ->assertSuccessful();

    expect(is_dir($dest.'/app/Domain/Session'))->toBeTrue();
});
