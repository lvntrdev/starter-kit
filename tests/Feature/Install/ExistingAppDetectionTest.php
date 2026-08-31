<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lvntr\StarterKit\Console\Commands\InstallCommand;
use Lvntr\StarterKit\StarterKitServiceProvider;

/**
 * Unit coverage for the fail-closed "this app already looks installed" guard.
 *
 * The guard exists because the hash registry — the only thing that used to
 * separate a first install from a re-run — is git-ignored and routinely lost, at
 * which point sk:install treated a live application as a brand-new project.
 *
 * Both directions are asserted here, because both are expensive:
 *   - a MISSED marker lets the installer publish over a live application;
 *   - a FALSE marker blocks a legitimate first install.
 * The false-positive cases below (a stock Laravel tree, an empty directory, a
 * composer.lock entry with no kit domain tree) are the ones that would break the
 * ordinary `composer require` → `sk:install` flow, so they are asserted first.
 *
 * The end-to-end scenarios (registry deletion, --adopt, mtime assertions) belong
 * to tests/Feature/Install/InstallerSafetyTest.php.
 */

/**
 * Invoke a private InstallCommand method by name. $this->files is bound in the
 * constructor, so the detection helpers are callable on a bare instance.
 */
function invokeDetection(string $method, array $args = []): mixed
{
    $command = new InstallCommand;
    $ref = new ReflectionMethod($command, $method);
    $ref->setAccessible(true);

    return $ref->invoke($command, ...$args);
}

/**
 * Read a private class constant off InstallCommand.
 *
 * The marker datasets below are driven off the constants rather than repeating
 * their values: a hardcoded copy asserts that the TEST's spelling works, which
 * is exactly how `resources/js/Pages/Admin` (uppercase P, matching nothing the
 * kit ships) survived in the constant while its test stayed green.
 *
 * @return list<string>
 */
function detectionMarkers(string $constant): array
{
    /** @var list<string> $value */
    $value = (new ReflectionClass(InstallCommand::class))->getConstant($constant);

    return $value;
}

/**
 * Materialise a throwaway application tree. A key ending in `/` creates an empty
 * directory; anything else creates a file with the given contents.
 *
 * @param  array<string, string>  $tree
 */
function makeDetectionTree(array $tree): string
{
    $dir = sys_get_temp_dir().'/sk-detect-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    foreach ($tree as $relative => $contents) {
        $path = $dir.'/'.ltrim($relative, '/');

        if (str_ends_with($relative, '/')) {
            $target = rtrim($path, '/');
            is_dir($target) || mkdir($target, 0700, true);

            continue;
        }

        $parent = dirname($path);
        is_dir($parent) || mkdir($parent, 0700, true);
        file_put_contents($path, $contents);
    }

    return $dir;
}

function removeDetectionTree(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

/**
 * The shape `laravel new` / `composer create-project` produces, plus the
 * composer.lock entry that `composer require lvntr/laravel-starter-kit` writes
 * immediately before the operator runs sk:install for the first time.
 *
 * @return array<string, string>
 */
function stockLaravelTree(): array
{
    return [
        'composer.lock' => '{"packages":[{"name":"lvntr/laravel-starter-kit","version":"v13.6.16"}]}',
        'app/Models/User.php' => '<?php',
        'app/Http/Controllers/Controller.php' => '<?php',
        'app/Providers/AppServiceProvider.php' => '<?php',
        'config/app.php' => '<?php return [];',
        'config/database.php' => '<?php return [];',
        'resources/js/app.js' => '// app',
        'resources/js/Pages/Welcome.vue' => '<template />',
        'database/migrations/0001_01_01_000000_create_users_table.php' => '<?php',
    ];
}

// ── False positives: a fresh project must never be blocked ───────────────────

it('finds no evidence in a stock Laravel application that just required the package', function (): void {
    // The single most important assertion in this file. Every marker must stay
    // off this tree, or `composer require` → `sk:install` stops working.
    $dir = makeDetectionTree(stockLaravelTree());

    expect(invokeDetection('detectPublishedTargetMarkers', [$dir]))->toBe([]);
    expect(invokeDetection('detectComposerLockMarkers', [$dir]))->toBe([]);

    removeDetectionTree($dir);
});

it('finds no evidence in an empty directory', function (): void {
    $dir = makeDetectionTree([]);

    expect(invokeDetection('detectPublishedTargetMarkers', [$dir]))->toBe([]);
    expect(invokeDetection('detectComposerLockMarkers', [$dir]))->toBe([]);

    removeDetectionTree($dir);
});

it('ignores an empty published directory', function (): void {
    // A leftover empty app/Http/Controllers/Admin/ holds no work worth
    // protecting, so it must not cost the operator an install.
    $dir = makeDetectionTree(array_fill_keys(
        array_map(fn (string $marker): string => $marker.'/', detectionMarkers('EXISTING_APP_DIRECTORY_MARKERS')),
        '',
    ));

    expect(invokeDetection('detectPublishedTargetMarkers', [$dir]))->toBe([]);

    removeDetectionTree($dir);
});

// ── True positives: every marker is independently sufficient ────────────────

it('names each kit-published file it finds', function (string $relative): void {
    $dir = makeDetectionTree(stockLaravelTree() + [$relative => '<?php']);

    $markers = invokeDetection('detectPublishedTargetMarkers', [$dir]);

    expect($markers)->toHaveCount(1);
    // The stop is only useful if the operator can judge it in one read, which
    // means the exact path that tripped it has to be in the message.
    expect($markers[0])->toContain($relative);

    removeDetectionTree($dir);
})->with(detectionMarkers('EXISTING_APP_FILE_MARKERS'));

it('names each non-empty kit-published directory it finds', function (string $relative): void {
    $dir = makeDetectionTree(stockLaravelTree() + [$relative.'/Kept.php' => '<?php']);

    $markers = invokeDetection('detectPublishedTargetMarkers', [$dir]);

    expect($markers)->toHaveCount(1);
    expect($markers[0])->toContain($relative.'/');

    removeDetectionTree($dir);
})->with(detectionMarkers('EXISTING_APP_DIRECTORY_MARKERS'));

// ── Marker liveness: a marker naming nothing the kit ships is dead ──────────

/**
 * Whether $relative exists under $base with EXACTLY this spelling.
 *
 * is_dir()/is_file() answer case-insensitively on macOS and APFS, which is what
 * hid the dead `resources/js/Pages/Admin` marker: it resolved on the maintainer's
 * machine and matched nothing on the Linux box the consumer installs on. Walking
 * the real directory entries and comparing them strictly is the only check that
 * behaves the same on both.
 */
function existsCaseSensitively(string $base, string $relative): bool
{
    $path = rtrim($base, '/');

    foreach (explode('/', trim($relative, '/')) as $segment) {
        $entries = @scandir($path);

        if ($entries === false || ! in_array($segment, $entries, true)) {
            return false;
        }

        $path .= '/'.$segment;
    }

    return true;
}

it('points every published-target marker at a path the kit actually ships', function (string $relative): void {
    // A marker whose spelling matches no shipped stub can never fire, so the
    // fail-closed guard silently loses one of its three evidence sources.
    expect(existsCaseSensitively(StarterKitServiceProvider::stubsPath(), $relative))
        ->toBeTrue("stubs/{$relative} does not exist — this marker can never match.");
})->with(array_merge(
    detectionMarkers('EXISTING_APP_FILE_MARKERS'),
    detectionMarkers('EXISTING_APP_DIRECTORY_MARKERS'),
));

it('points every schema marker at a table the kit actually creates', function (): void {
    // `permissions` is exempt: Spatie's migration creates it, not ours. Every
    // other entry has to name a table one of the package migrations creates —
    // `file_manager_folders` named none of them and was pure dead weight.
    $migrations = implode("\n", array_map(
        fn (string $file): string => (string) file_get_contents($file),
        glob(StarterKitServiceProvider::basePath('database/migrations').'/*.php') ?: [],
    ));

    foreach (detectionMarkers('KIT_SCHEMA_TABLES') as $table) {
        if ($table === 'permissions') {
            continue;
        }

        expect($migrations)->toContain("Schema::create('{$table}'");
    }
});

it('treats a directory it cannot inspect as present rather than empty', function (): void {
    // allFiles() throws for a path that is not an enumerable directory. Folding
    // that into 0 would let an uninspectable published directory read as absent.
    expect(invokeDetection('countFilesUnder', ['/sk-detect-nonexistent-'.bin2hex(random_bytes(4))]))->toBeNull();
});

it('collects every marker rather than stopping at the first', function (): void {
    // A stop that names one file when four exist reads like a false positive.
    $dir = makeDetectionTree(stockLaravelTree() + [
        'app/Providers/DomainServiceProvider.php' => '<?php',
        'config/permission-resources.php' => '<?php return [];',
        'app/Http/Controllers/Admin/UserController.php' => '<?php',
        'resources/js/pages/Admin/Dashboard.vue' => '<template />',
    ]);

    expect(invokeDetection('detectPublishedTargetMarkers', [$dir]))->toHaveCount(4);

    removeDetectionTree($dir);
});

// ── composer.lock marker: the pair, never the entry alone ───────────────────

it('does not treat a composer.lock entry alone as an installed application', function (): void {
    // A first install runs right after `composer require`, so the lock entry is
    // ALWAYS present at that moment. On its own it proves nothing.
    $dir = makeDetectionTree(stockLaravelTree());

    expect(invokeDetection('detectComposerLockMarkers', [$dir]))->toBe([]);

    removeDetectionTree($dir);
});

it('reports a kit domain tree alongside a composer.lock entry', function (): void {
    $dir = makeDetectionTree(stockLaravelTree() + [
        'app/Domain/User/Actions/CreateUserAction.php' => '<?php',
    ]);

    $markers = invokeDetection('detectComposerLockMarkers', [$dir]);

    expect($markers)->toHaveCount(1);
    expect($markers[0])
        ->toContain('app/Domain/')
        ->toContain('lvntr/laravel-starter-kit');

    removeDetectionTree($dir);
});

it('ignores a domain tree when composer.lock does not list the package', function (): void {
    // An unrelated DDD application that happens to use app/Domain/.
    $dir = makeDetectionTree([
        'composer.lock' => '{"packages":[{"name":"laravel/framework","version":"v13.0.0"}]}',
        'app/Domain/Billing/Invoice.php' => '<?php',
    ]);

    expect(invokeDetection('detectComposerLockMarkers', [$dir]))->toBe([]);

    removeDetectionTree($dir);
});

it('ignores an empty domain tree', function (): void {
    $dir = makeDetectionTree(stockLaravelTree() + ['app/Domain/' => '']);

    expect(invokeDetection('detectComposerLockMarkers', [$dir]))->toBe([]);

    removeDetectionTree($dir);
});

// ── Schema marker ───────────────────────────────────────────────────────────

it('treats an unreachable database as no evidence rather than an error', function (): void {
    // sk:install is routinely run before DB_* is configured at all. Turning that
    // into a stop would break the very first install this guard protects.
    config([
        'database.default' => 'sk_detect_unreachable',
        'database.connections.sk_detect_unreachable' => [
            'driver' => 'sqlite',
            'database' => '/sk-detect-nonexistent-'.bin2hex(random_bytes(4)).'/app.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
    ]);
    DB::purge('sk_detect_unreachable');

    expect(invokeDetection('detectKitSchemaMarkers'))->toBe([]);
});

it('finds no evidence on a reachable database with no kit tables', function (): void {
    expect(Schema::hasTable('settings'))->toBeFalse();

    expect(invokeDetection('detectKitSchemaMarkers'))->toBe([]);
});

it('names the kit table it found on a reachable database', function (): void {
    Schema::create('settings', function (Blueprint $table): void {
        $table->id();
    });

    $markers = invokeDetection('detectKitSchemaMarkers');

    expect($markers)->toHaveCount(1);
    expect($markers[0])->toContain('`settings`');

    Schema::drop('settings');
});

// ── computeFirstInstall: the markers override a missing registry ────────────

it('is not a first install when the registry is missing but the app is installed', function (): void {
    // The exact regression: registry lost (noHashRegistry = true) on a live app.
    // A "first install" verdict here re-ejects the default domains and takes the
    // first-install-only .env seeding on an app that already has data.
    expect(invokeDetection('computeFirstInstall', [false, [], true, true]))->toBeFalse();
});

it('is still a first install when the registry is missing and no evidence was found', function (): void {
    expect(invokeDetection('computeFirstInstall', [false, [], true, false]))->toBeTrue();
});

it('keeps inheriting the checkpoint decision on a resume regardless of evidence', function (): void {
    // A resume's markers are the command's OWN half-finished publish; the
    // checkpoint stays authoritative or an interrupted install can never finish.
    expect(invokeDetection('computeFirstInstall', [true, ['first_install' => true], true, true]))->toBeTrue();
    expect(invokeDetection('computeFirstInstall', [true, ['first_install' => false], true, false]))->toBeFalse();
});

// ── Registry backup ─────────────────────────────────────────────────────────

it('returns null when there is no registry to back up', function (): void {
    $dir = makeDetectionTree([]);

    expect(invokeDetection('backupHashRegistry', [$dir.'/hashes.json']))->toBeNull();

    removeDetectionTree($dir);
});

it('copies the current registry aside without altering it', function (): void {
    $dir = makeDetectionTree(['hashes.json' => '{"_format":"v2"}']);
    $path = $dir.'/hashes.json';

    $backup = invokeDetection('backupHashRegistry', [$path]);

    expect($backup)->toStartWith($path.'.bak-');
    expect(file_get_contents($backup))->toBe('{"_format":"v2"}');
    // The original is replaced by the caller, not by the backup step.
    expect(file_get_contents($path))->toBe('{"_format":"v2"}');

    removeDetectionTree($dir);
});

it('never overwrites an earlier backup taken in the same second', function (): void {
    // Two --adopt runs a second apart would otherwise destroy exactly the copy
    // the operator would restore from.
    $dir = makeDetectionTree(['hashes.json' => 'first']);
    $path = $dir.'/hashes.json';

    $one = invokeDetection('backupHashRegistry', [$path]);
    file_put_contents($path, 'second');
    $two = invokeDetection('backupHashRegistry', [$path]);

    expect($two)->not->toBe($one);
    expect(file_get_contents($one))->toBe('first');
    expect(file_get_contents($two))->toBe('second');

    removeDetectionTree($dir);
});
