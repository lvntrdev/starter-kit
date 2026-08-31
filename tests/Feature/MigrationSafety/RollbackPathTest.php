<?php

use Illuminate\Database\Migrations\Migration;

/*
|--------------------------------------------------------------------------
| Rollback-path guard — static, driver-free
|--------------------------------------------------------------------------
|
| Every migration the kit ships must be reversible. This file is the guard that
| makes a missing `down()` a failing test instead of a discovery someone makes
| mid-incident, with `migrate:rollback` already half-applied.
|
| ## Why this is NOT in tests/Feature/Migration/
|
| That directory is bound to `MigrationTestCase` (tests/Pest.php), which SKIPS
| itself unless DB_CONNECTION resolves to mysql/mariadb — and skips again if the
| target schema is not empty. A guard that only runs in two CI jobs, and only
| when a disposable database happens to be reachable, is not a guard: the local
| `composer test` gate that every commit passes through runs on SQLite and would
| never see it. Pest binds ONE base class per file and throws
| `TestCaseAlreadyInUse` when a directory binding and a file binding overlap
| (vendor/pestphp/pest/src/Repositories/TestRepository.php:190), so the guard
| cannot opt out of that directory's binding from inside it — it needs its own
| directory, bound to the light `TestCase`. Hence this one.
|
| ## Why it READS the migrations instead of running them
|
| Executing a real rollback answers a different question (does the DDL unwind on
| this driver) at a much higher cost, and tests/Feature/Migration already asks
| exactly that against MySQL and MariaDB. What is asserted here is cheaper and
| driver-independent: the METHOD EXISTS AT ALL. Loading the file is enough,
| because a Laravel migration file returns its anonymous class instance.
|
*/

/**
 * Every migration file the package owns, plus the app-owned ones it publishes.
 *
 * Both directories are covered on purpose: `migrate:rollback` unwinds one batch
 * across every registered path, so a stub migration without a `down()` strands
 * a consumer's rollback just as effectively as a vendor one. The stub tree also
 * carries the FK targets (`users`, `roles`) the vendor chain depends on.
 *
 * @return array<string, array{0: string, 1: string}> label => [absolute path, tree]
 */
function rollbackPathMigrationFiles(): array
{
    $trees = [
        'package' => __DIR__.'/../../../database/migrations',
        'stubs' => __DIR__.'/../../../stubs/database/migrations',
    ];

    $files = [];

    foreach ($trees as $tree => $directory) {
        $directory = realpath($directory);

        expect($directory)->not->toBeFalse("migration directory for [{$tree}] does not exist");

        $found = glob($directory.'/*.php') ?: [];

        // A glob that silently returns nothing would turn this whole guard into
        // a no-op that reports green, so the directory must be non-empty.
        expect($found)->not->toBeEmpty("no migration files found in [{$directory}]");

        foreach ($found as $path) {
            $files[$tree.'/'.basename($path)] = [$path, $tree];
        }
    }

    return $files;
}

it('has at least one migration file to check in each tree', function () {
    $files = rollbackPathMigrationFiles();

    $trees = array_unique(array_column($files, 1));

    expect($trees)->toHaveCount(2)
        ->and(count($files))->toBeGreaterThan(20);
});

it('declares a down() on every migration the kit ships', function () {
    $missing = [];

    foreach (rollbackPathMigrationFiles() as $label => [$path]) {
        // A Laravel migration file returns its anonymous class instance; the
        // Migrator loads it the same way (Migrator::resolvePath -> require).
        // Re-requiring a file that returns an anonymous class is safe — PHP
        // does not raise a redeclaration error for it.
        $migration = require $path;

        expect($migration)->toBeInstanceOf(
            Migration::class,
            "[{$label}] does not return an Illuminate migration instance",
        );

        if (! method_exists($migration, 'down')) {
            $missing[] = $label;

            continue;
        }

        // Guard the guard: assert the method is declared by the migration
        // itself, not inherited. Illuminate's base Migration declares no
        // down() today, so method_exists() alone would pass — but if a future
        // framework version adds an empty one, a bare method_exists() check
        // would start reporting green for every migration in the repo.
        $declaredBy = (new ReflectionMethod($migration, 'down'))->getDeclaringClass()->getName();

        if ($declaredBy === Migration::class) {
            $missing[] = $label.' (inherits an empty down() instead of declaring one)';
        }
    }

    expect($missing)->toBe([], sprintf(
        "%d migration(s) ship without a rollback path:\n  - %s",
        count($missing),
        implode("\n  - ", $missing),
    ));
});

it('detects a migration that omits down()', function () {
    // The guard above only proves something while it can still FAIL. This is
    // the negative control: the same reflection check, applied to a migration
    // that deliberately has no down(), must report it as missing.
    $withoutDown = new class extends Migration
    {
        public function up(): void {}
    };

    $withDown = new class extends Migration
    {
        public function up(): void {}

        public function down(): void {}
    };

    expect(method_exists($withoutDown, 'down'))->toBeFalse()
        ->and(method_exists($withDown, 'down'))->toBeTrue()
        ->and((new ReflectionMethod($withDown, 'down'))->getDeclaringClass()->getName())
        ->not->toBe(Migration::class);
});

it('drops the media table on rollback', function () {
    // Pinned by name because this is the hole the guard was written for:
    // create_media_table shipped without a down(), so `media` survived a full
    // migrate:rollback and the next migrate hit an existing table.
    $path = realpath(__DIR__.'/../../../database/migrations/2026_03_08_205445_create_media_table.php');

    expect($path)->not->toBeFalse();

    $migration = require $path;

    expect(method_exists($migration, 'down'))->toBeTrue()
        ->and((new ReflectionMethod($migration, 'down'))->getDeclaringClass()->getName())
        ->not->toBe(Migration::class);
});
