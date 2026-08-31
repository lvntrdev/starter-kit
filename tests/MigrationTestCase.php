<?php

namespace Lvntr\StarterKit\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lvntr\StarterKit\StarterKitServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

/**
 * Driver-real TestCase: runs the kit's migration chain against the connection
 * the ENVIRONMENT configures, not the SQLite in-memory default.
 *
 * ## Why this exists next to DatabaseTestCase instead of inside it
 *
 * `DatabaseTestCase` deliberately never runs a migration: it builds its tables
 * inline with the Schema builder, because `migrate:fresh` under Testbench also
 * picks up testbench-core's own fixture migrations (duplicate `dedupe_receipts`)
 * and dies. That workaround keeps the suite fast and green — at the cost of the
 * migration files themselves never being executed by any test. Everything that
 * only shows up when a real driver parses the DDL (index key-length limits,
 * whether `uuid` is a native type or `char(36)`, whether a FK's referenced
 * column type actually matches) is therefore invisible to `composer test`.
 *
 * This case closes that hole. It keeps the `TESTBENCH_WITHOUT_DEFAULT_MIGRATIONS`
 * contract (set in phpunit.xml) so the fixture collision stays out, and instead
 * of inline tables it runs the real `migrate` command over two real paths.
 *
 * ## Self-skipping — the guard is the driver, not an opt-in flag
 *
 * The whole case skips itself unless the resolved driver is `mysql` or
 * `mariadb`. Two reasons, and the second is the important one:
 *
 *   1. The default `composer test` run is SQLite, and SQLite is exactly the
 *      engine whose leniency this case exists to bypass — running it there
 *      would prove nothing.
 *   2. This case WRITES to a real database and drops what it created. A flag
 *      ("run me if you set MIGRATION_TESTS=1") could be switched on next to a
 *      developer's real MySQL by accident. Keying off the driver plus the
 *      empty-database precondition below means the case refuses to touch any
 *      schema that already has tables in it.
 *
 * ## Empty-database precondition
 *
 * Before migrating, the target database must contain ZERO tables. If it does
 * not, the test skips with the database name in the message rather than
 * migrating on top of — or later dropping — data it did not create. Teardown
 * then drops exactly the tables that appeared between the precondition check
 * and the end of the test; nothing else is ever dropped.
 *
 * ## Why Testbench's `loadMigrationsFrom()` helper is not used
 *
 * `Orchestra\Testbench\Concerns\InteractsWithMigrations::loadMigrationsFrom()`
 * is not a path registration — it immediately runs `migrate --path=<path>` and
 * queues its own `migrate:rollback` for teardown. Called once per path it would
 * produce one BATCH per path, and `migrate:rollback` rolls back a batch, so the
 * rollback assertion (the batch unwinds in reverse dependency order) would be
 * testing an arrangement no consumer ever has. A consumer runs a single
 * `php artisan migrate` that sorts every path's files together into one batch,
 * so that is what {@see runMigrationChain()} does, with an explicit `--path`
 * pair. See {@see migrationPaths()} for why there are two.
 */
abstract class MigrationTestCase extends Orchestra
{
    /**
     * The connection name this case configures from the DB_* environment.
     *
     * Named distinctly from `mysql`/`testing` so a stray `DB::connection()`
     * elsewhere cannot silently land on it.
     */
    public const CONNECTION = 'migration_probe';

    /**
     * Drivers whose DDL this case is written against.
     *
     * @var list<string>
     */
    protected const SUPPORTED_DRIVERS = ['mysql', 'mariadb'];

    /**
     * Tables present in the target database before this test migrated.
     *
     * Always `[]` while the precondition holds — kept as state so teardown
     * drops the difference rather than everything it finds.
     *
     * @var list<string>
     */
    private array $tablesBeforeMigrate = [];

    private bool $hasMigrated = false;

    /**
     * The driver name the environment asked for.
     *
     * Read through `env()` rather than `$_ENV`/`getenv()` directly because
     * phpunit.xml pins `DB_CONNECTION=sqlite` for the SQLite suite and PHPUnit
     * only applies an `<env>` entry when the variable is NOT already set in the
     * process environment. A CI step that exports `DB_CONNECTION=mariadb`
     * therefore wins, and a plain local run still resolves to `sqlite`.
     */
    protected static function resolveDriver(): string
    {
        return strtolower((string) env('DB_CONNECTION', 'sqlite'));
    }

    protected function setUp(): void
    {
        $driver = static::resolveDriver();

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            $this->markTestSkipped(sprintf(
                'Package migration chain runs on %s only; DB_CONNECTION resolved to "%s".',
                implode('/', self::SUPPORTED_DRIVERS),
                $driver,
            ));
        }

        parent::setUp();

        $this->guardTargetDatabaseIsEmpty();
        $this->runMigrationChain();
    }

    protected function tearDown(): void
    {
        $this->dropTablesCreatedByThisTest();

        parent::tearDown();
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            StarterKitServiceProvider::class,
            // The chain alters Spatie's roles table (add_color_to_roles_table)
            // and writes to the activity log table; both resolve their table
            // names from the packages' config, which only exists once the
            // providers have merged it. media-library is registered for parity
            // with DatabaseTestCase so the kit boots the same way it does there.
            PermissionServiceProvider::class,
            ActivitylogServiceProvider::class,
            MediaLibraryServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', self::CONNECTION);
        $app['config']->set('database.connections.'.self::CONNECTION, [
            'driver' => static::resolveDriver(),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'starter_kit_ci'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ]);

        // The chain's last two activity-log migrations resolve their target
        // through activitylog config; pin it to the probe connection so a
        // stray default cannot send the ALTER somewhere else.
        $app['config']->set('activitylog.database_connection', self::CONNECTION);
    }

    /**
     * The migration paths a real `php artisan migrate` would cover, in the
     * order a consumer app has them.
     *
     * BOTH paths are required — the package chain cannot stand alone:
     *   - `file_folders.created_by` and
     *     `file_manager_share_revocations.revoked_by_user_id` are FKs onto
     *     `users.id`, and `users` is app-owned (published by `sk:install` from
     *     stubs/, never vendor-resident).
     *   - `add_color_to_roles_table` ALTERs Spatie's `roles` table, published
     *     from the same stub directory.
     *
     * SQLite would swallow both gaps (it does not validate a FK target at
     * CREATE time); MySQL/MariaDB will not, which is the point of this case.
     * Filenames sort globally across the two paths, so the app-owned tables
     * land before the package migrations that depend on them without any
     * ordering help here.
     *
     * @return list<string>
     */
    protected function migrationPaths(): array
    {
        return [
            (string) realpath(__DIR__.'/../stubs/database/migrations'),
            (string) realpath(__DIR__.'/../database/migrations'),
        ];
    }

    /**
     * Table names in the CONFIGURED database only.
     *
     * The schema argument is mandatory here, not cosmetic: `getTableListing()`
     * with a null schema compiles to `information_schema.tables` filtered only
     * against MySQL's system schemas, so it returns every table on the SERVER —
     * a local dev machine answered with 518 tables from unrelated databases.
     * Both the emptiness precondition and the teardown diff are computed from
     * this list, so an unscoped read would have made the precondition fire
     * permanently (CI included) and the teardown diff meaningless.
     *
     * @return list<string>
     */
    private function targetDatabaseTables(): array
    {
        return Schema::getTableListing(DB::connection()->getDatabaseName(), false);
    }

    /**
     * Refuse to run against a database that already has tables in it.
     */
    private function guardTargetDatabaseIsEmpty(): void
    {
        $existing = $this->targetDatabaseTables();

        if ($existing !== []) {
            $this->markTestSkipped(sprintf(
                'Target database "%s" is not empty (%d table(s): %s). '
                .'This case only runs against a disposable, empty schema — it drops what it creates.',
                DB::connection()->getDatabaseName(),
                count($existing),
                implode(', ', array_slice($existing, 0, 5)),
            ));
        }

        $this->tablesBeforeMigrate = $existing;
    }

    /**
     * Run the real chain as one batch, exactly as `php artisan migrate` would.
     */
    protected function runMigrationChain(): void
    {
        $this->hasMigrated = true;

        $this->artisan('migrate', [
            '--path' => $this->migrationPaths(),
            '--realpath' => true,
            '--force' => true,
        ])->assertExitCode(0)->run();
    }

    /**
     * Roll the whole batch back, same paths, same command a consumer runs.
     */
    protected function rollbackMigrationChain(): void
    {
        $this->artisan('migrate:rollback', [
            '--path' => $this->migrationPaths(),
            '--realpath' => true,
            '--force' => true,
        ])->assertExitCode(0)->run();
    }

    /**
     * Drop exactly the tables that appeared during this test.
     *
     * Not `migrate:fresh`, not `db:wipe`, not `Schema::dropAllTables()`: the
     * set is computed as (tables now) minus (tables before the migration), so
     * anything this case did not create is left alone even if the precondition
     * were ever bypassed.
     *
     * This still runs after a test that rolled the batch back: most cases here
     * do NOT roll back (they assert against the migrated schema), and a case
     * that does simply leaves an empty difference. It is the safety net for a
     * chain that fails halfway, not a substitute for `down()`.
     */
    private function dropTablesCreatedByThisTest(): void
    {
        if (! $this->hasMigrated || $this->app === null) {
            return;
        }

        $this->hasMigrated = false;

        $leftovers = array_values(array_diff(
            $this->targetDatabaseTables(),
            $this->tablesBeforeMigrate,
        ));

        if ($leftovers === []) {
            return;
        }

        Schema::disableForeignKeyConstraints();

        foreach ($leftovers as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
}
