<?php

namespace Lvntr\StarterKit\Console\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Lvntr\StarterKit\Console\Commands\Concerns\ChecksStepResults;
use Lvntr\StarterKit\Support\DocsLink;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

use function Laravel\Prompts\confirm;

class UpgradeCommand extends Command
{
    use ChecksStepResults;

    protected $signature = 'sk:upgrade
        {--force : Skip confirmation prompts}
        {--skip-build : Do not run npm install / npm run build}';

    protected $description = 'Upgrade a Laravel 12 project to use the Laravel 13 line of the Starter Kit';

    /**
     * Minimum Laravel version required for the v13 line of the Starter Kit.
     */
    private const REQUIRED_LARAVEL_MAJOR = 13;

    /**
     * Minimum Starter Kit package version required.
     */
    private const REQUIRED_PACKAGE_MAJOR = 13;

    private Filesystem $files;

    public function handle(): int
    {
        $this->files = new Filesystem;

        $this->newLine();
        $this->components->info('Lvntr Starter Kit — Laravel 13 upgrade');
        $this->newLine();

        // 1. Preflight: verify the host application is already on Laravel 13.
        if (! $this->assertLaravelVersion()) {
            return self::FAILURE;
        }

        // 2. Preflight: verify the installed package is on the v13 line.
        if (! $this->assertPackageVersion()) {
            return self::FAILURE;
        }

        // 3. Preflight: verify PHP runtime is compatible.
        if (! $this->assertPhpVersion()) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Preflight checks passed. Applying Starter Kit updates…');
        $this->newLine();

        // 4. Sync stubs via sk:update (hash-aware, preserves user edits).
        if ($this->confirmStep('Sync Starter Kit stubs (sk:update)?')) {
            if (! $this->step('Synchronising stubs', function () {
                return $this->runArtisan('sk:update', ['--no-interaction' => true], echo: true);
            })) {
                return $this->failUpgrade('Synchronising stubs');
            }
        }

        // 5. Repair the legacy display-timezone env binding in existing apps.
        $this->step('Pinning display timezone configuration', function () {
            if ($this->rewriteDisplayTimezoneConfig(config_path('app.php'))) {
                $this->line('    <fg=gray>config/app.php: display_timezone now reads APP_DISPLAY_TIMEZONE.</>');
            }
        });

        // 6. Pin MySQL/MariaDB sessions to UTC after checking existing data safety.
        $this->step('Pinning database connection timezone', function () {
            $this->upgradeDatabaseTimezoneConfig();
        });

        // 7. Clear cached bootstrap artefacts so new service bindings pick up.
        // Best-effort: a cache store that is momentarily unreachable (Redis down)
        // must not abort an upgrade whose file work already landed.
        $this->step('Clearing framework caches', function () {
            $cleared = true;

            foreach (['config:clear', 'route:clear', 'view:clear', 'cache:clear'] as $command) {
                $cleared = $this->runArtisan($command) && $cleared;
            }

            foreach (['packages.php', 'services.php'] as $file) {
                $path = base_path('bootstrap/cache/'.$file);
                if ($this->files->exists($path)) {
                    $this->files->delete($path);
                }
            }

            return $cleared;
        }, mandatory: false);

        // 8. Regenerate composer autoload so any new classes resolve.
        // Best-effort: composer is not guaranteed to be on the PATH of the machine
        // running the upgrade, and that has never blocked an upgrade before.
        $this->step('Regenerating autoload', function () {
            return $this->runProcessStep(['composer', 'dump-autoload', '-q'], timeout: 120);
        }, mandatory: false);

        // 9. Run any new migrations shipped with the v13 package line.
        if ($this->confirmStep('Run database migrations?')) {
            if (! $this->step('Running migrations', function () {
                return $this->runArtisan('migrate', ['--force' => true], echo: true);
            })) {
                return $this->failUpgrade('Running migrations');
            }
        }

        // 10. Re-seed permissions in case the package added new abilities.
        if ($this->confirmStep('Re-seed roles and permissions from config?')) {
            if (! $this->step('Seeding permissions', function () {
                return $this->runArtisan('sk:seed-permissions', echo: true);
            })) {
                return $this->failUpgrade('Seeding permissions');
            }
        }

        // 11. Rebuild frontend assets.
        if (! $this->option('skip-build') && $this->confirmStep('Reinstall npm dependencies and rebuild assets?')) {
            // Frontend work stays non-fatal on purpose: a machine without a Node
            // toolchain must still be able to complete the upgrade.
            $npmInstalled = $this->step('Installing npm dependencies', function () {
                return $this->runProcessStep(['npm', 'install'], timeout: 600, tty: true);
            }, mandatory: false);

            $built = $npmInstalled && $this->step('Building frontend assets', function () {
                return $this->runProcessStep(['npm', 'run', 'build'], timeout: 600, tty: true);
            }, mandatory: false);

            if (! $built) {
                $this->components->warn('Frontend assets were not rebuilt. Run `npm install && npm run build` by hand.');
            }
        }

        $this->newLine();
        $this->components->info('Starter Kit upgrade completed.');
        $this->components->bulletList([
            'Review modified files with <fg=cyan>git status</>.',
            'Run your test suite: <fg=cyan>php artisan test --compact</>.',
            'Smoke test the admin panel in the browser.',
        ]);
        $this->newLine();

        return self::SUCCESS;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PREFLIGHT CHECKS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Abort if the host application is not yet on Laravel 13.
     */
    private function assertLaravelVersion(): bool
    {
        $version = $this->laravel->version();
        $major = (int) explode('.', $version)[0];

        if ($major >= self::REQUIRED_LARAVEL_MAJOR) {
            $this->components->twoColumnDetail('Laravel version', "<fg=green>{$version}</>");

            return true;
        }

        $this->components->error('Laravel 13 or newer is required.');
        $this->line("  Current version: <fg=yellow>{$version}</>");
        $this->newLine();
        $this->line('  <fg=gray>Upgrade your project to Laravel 13 first, then re-run</> <fg=cyan>php artisan sk:upgrade</>.');
        $this->line('  <fg=gray>Official upgrade guide:</> <fg=cyan>https://laravel.com/docs/13.x/upgrade</>');
        $this->newLine();
        $this->line('  <fg=gray>Typical steps:</>');
        $this->line('    1. Edit <fg=cyan>composer.json</>: set <fg=cyan>"laravel/framework": "^13.0"</> and <fg=cyan>"php": "^8.4"</>.');
        $this->line('    2. Run <fg=cyan>composer update</>.');
        $this->line('    3. Run <fg=cyan>php artisan sk:upgrade</> to let this command finish the package-side work.');
        $this->newLine();

        return false;
    }

    /**
     * Abort if the installed Starter Kit package is not on the v13 line.
     */
    private function assertPackageVersion(): bool
    {
        if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled('lvntr/laravel-starter-kit')) {
            $this->components->error('lvntr/laravel-starter-kit package is not installed via Composer.');

            return false;
        }

        $installed = InstalledVersions::getPrettyVersion('lvntr/laravel-starter-kit') ?? 'unknown';
        $normalized = ltrim((string) $installed, 'v');
        $major = (int) (explode('.', $normalized)[0] ?? 0);

        // Dev installs (e.g. "dev-main") cannot be reliably version-checked,
        // so trust them when the Laravel version check has already passed.
        if ($major === 0 && str_contains($installed, 'dev')) {
            $this->components->twoColumnDetail('Starter Kit version', "<fg=yellow>{$installed} (dev)</>");

            return true;
        }

        if ($major >= self::REQUIRED_PACKAGE_MAJOR) {
            $this->components->twoColumnDetail('Starter Kit version', "<fg=green>{$installed}</>");

            return true;
        }

        $this->components->error('Starter Kit v13 or newer is required.');
        $this->line("  Current version: <fg=yellow>{$installed}</>");
        $this->newLine();
        $this->line('  <fg=gray>Bump the constraint and update the package:</>');
        $this->line('    <fg=cyan>composer require lvntr/laravel-starter-kit:^13.6</>');
        $this->newLine();

        return false;
    }

    /**
     * Abort if PHP is older than the minimum required by the kit (composer.json
     * requires ^8.4).
     */
    private function assertPhpVersion(): bool
    {
        if (PHP_VERSION_ID >= 80400) {
            $this->components->twoColumnDetail('PHP version', '<fg=green>'.PHP_VERSION.'</>');

            return true;
        }

        $this->components->error('PHP 8.4 or newer is required.');
        $this->line('  Current version: <fg=yellow>'.PHP_VERSION.'</>');
        $this->newLine();

        return false;
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Rewrite only the legacy display_timezone env binding in config/app.php.
     */
    private function rewriteDisplayTimezoneConfig(string $configPath): bool
    {
        return $this->modifyPhpFileAst($configPath, function (array $stmts): bool {
            $array = $this->findConfigRootArray($stmts);

            if ($array === null) {
                return false;
            }

            foreach ($array->items as $item) {
                if (! $item instanceof Node\ArrayItem
                    || ! $item->key instanceof Node\Scalar\String_
                    || $item->key->value !== 'display_timezone'
                    || ! $item->value instanceof Node\Expr\FuncCall
                    || ! $item->value->name instanceof Node\Name
                    || strtolower($item->value->name->toString()) !== 'env'
                ) {
                    continue;
                }

                $envKey = $item->value->args[0]->value ?? null;

                if (! $envKey instanceof Node\Scalar\String_ || $envKey->value !== 'APP_TIMEZONE') {
                    return false;
                }

                $item->value->args[0]->value = new Node\Scalar\String_(
                    'APP_DISPLAY_TIMEZONE',
                    $envKey->getAttributes(),
                );

                return true;
            }

            return false;
        });
    }

    /**
     * Inspect, consent to, and apply the database connection timezone pin.
     */
    private function upgradeDatabaseTimezoneConfig(): void
    {
        $configPath = config_path('database.php');

        if (! $this->files->exists($configPath)) {
            $this->components->warn('Could not find config/database.php — automatic database timezone edit skipped.');

            return;
        }

        $assessment = $this->rewriteDatabaseTimezoneConfig($configPath, false);

        if ($assessment === null) {
            $this->components->warn("Could not locate the connections array in config/database.php — add 'timezone' => '+00:00' to the mysql/mariadb connections manually.");

            return;
        }

        $needsChange = in_array('changed', $assessment, true);
        $apply = $needsChange && $this->shouldApplyDatabaseTimezoneConfig($assessment);
        $results = $apply
            ? ($this->rewriteDatabaseTimezoneConfig($configPath) ?? $assessment)
            : $assessment;

        foreach ($results as $connection => $result) {
            if ($apply && $result === 'changed') {
                config()->set("database.connections.{$connection}.timezone", '+00:00');
                DB::purge($connection);
            }

            $message = match ($result) {
                'changed' => $apply
                    ? "{$connection}: timezone pinned to +00:00."
                    : "{$connection}: timezone pin skipped; apply it after following ".DocsLink::to('timezone.md').'.',
                'existing' => "{$connection}: existing timezone left unchanged.",
                'unreadable' => "{$connection}: connections array is not a literal; add 'timezone' => '+00:00' manually.",
                default => "{$connection}: connection not found; skipped.",
            };

            $this->line('    <fg=gray>config/database.php: '.$message.'</>');
        }

        if ($needsChange && ! $apply) {
            $this->line('    <fg=yellow>Apply later by following '.DocsLink::to('timezone.md').', then add \'timezone\' => \'+00:00\' to the mysql/mariadb connection arrays that need it.</>');
        }
    }

    /**
     * Decide whether it is safe to pin an existing installation without data conversion.
     *
     * Every connection this edit would actually change is inspected — not just the default one.
     * An app whose default is sqlite can still define a data-holding mysql connection, and that
     * connection is the one whose rows would shift.
     *
     * @param  array<string, string>  $assessment
     */
    private function shouldApplyDatabaseTimezoneConfig(array $assessment): bool
    {
        foreach ($assessment as $connection => $result) {
            if ($result !== 'changed') {
                continue;
            }

            $driver = strtolower((string) config("database.connections.{$connection}.driver"));

            if (! in_array($driver, ['mysql', 'mariadb'], true)) {
                continue;
            }

            if (! $this->confirmDatabaseTimezonePin($connection, $driver)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Inspect one connection and, when its rows would shift, ask before pinning it.
     */
    private function confirmDatabaseTimezonePin(string $connection, string $driver): bool
    {
        try {
            $link = DB::connection($connection);
            $timezone = $link->selectOne(
                'SELECT @@session.time_zone AS time_zone, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS offset_seconds',
            );
            $sessionTimezone = (string) (is_array($timezone)
                ? ($timezone['time_zone'] ?? 'unknown')
                : ($timezone->time_zone ?? 'unknown'));
            $rawOffsetSeconds = is_array($timezone)
                ? ($timezone['offset_seconds'] ?? null)
                : ($timezone->offset_seconds ?? null);
            $offsetSeconds = is_numeric($rawOffsetSeconds) ? (int) $rawOffsetSeconds : null;
            $hasData = $link->getSchemaBuilder()->hasTable('users')
                && $link->table('users')->exists();
        } catch (\Throwable) {
            $this->components->warn("Could not inspect the {$connection} session timezone and existing data — automatic database timezone edit skipped.");

            return false;
        }

        // The session counts as UTC only when it SAYS so. A `SYSTEM` session that happens to
        // resolve to a zero offset right now — a DST zone in its winter, or a host retimed after
        // the rows were written — still wrote offset rows earlier.
        if (in_array($sessionTimezone, ['+00:00', 'UTC'], true) || ! $hasData) {
            return true;
        }

        $this->components->warn("The {$connection} connection uses session timezone {$sessionTimezone} and the users table contains data.");

        if ($offsetSeconds === null || $offsetSeconds === 0) {
            $this->line('    The session currently resolves to a zero offset, but a non-UTC zone can have written');
            $this->line('    older rows at a different offset (daylight saving, or a host timezone change).');
        } else {
            $absoluteOffset = abs($offsetSeconds);
            $hours = intdiv($absoluteOffset, 3600);
            $minutes = intdiv($absoluteOffset % 3600, 60);
            $shift = trim(($hours > 0 ? "{$hours} hour".($hours === 1 ? '' : 's') : '').
                ($minutes > 0 ? " {$minutes} minute".($minutes === 1 ? '' : 's') : ''));
            $direction = $offsetSeconds > 0 ? 'earlier' : 'later';

            $this->line("    Existing application-written TIMESTAMP values will render {$shift} {$direction} after pinning the session to UTC.");
        }

        $this->line('    DEFAULT CURRENT_TIMESTAMP values move in the opposite direction and self-heal.');
        $this->line('    Follow the one-time conversion guide before or with this change: <fg=cyan>'.DocsLink::to('timezone.md').'</>.');

        // A non-TTY shell is just as unattended as an explicit --no-interaction: the prompt would
        // fall back to its "yes" default there, applying the pin without consent. Symfony's
        // isInteractive() only reflects the --no-interaction/-n flags, so stdin is checked
        // directly — a CI or deploy shell that never passed -n still counts as unattended.
        if (! $this->option('force')
            && ($this->option('no-interaction') || ! $this->input->isInteractive() || ! $this->hasInteractiveTerminal())
        ) {
            $this->components->warn('Non-interactive run detected — database timezone pin skipped for safety.');

            return false;
        }

        return $this->confirmStep('Pin the MySQL/MariaDB connection timezone to +00:00 now?');
    }

    /**
     * Report whether stdin is a real terminal a person could answer a prompt on.
     */
    private function hasInteractiveTerminal(): bool
    {
        if (! defined('STDIN')) {
            return false;
        }

        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDIN);
        }

        return function_exists('posix_isatty') && @posix_isatty(STDIN);
    }

    /**
     * Add the UTC timezone literal to supported database connection arrays.
     *
     * @return array{mysql: 'changed'|'existing'|'missing'|'unreadable', mariadb: 'changed'|'existing'|'missing'|'unreadable'}|null
     */
    private function rewriteDatabaseTimezoneConfig(string $configPath, bool $write = true): ?array
    {
        $results = ['mysql' => 'missing', 'mariadb' => 'missing'];
        $inspected = false;

        $this->modifyPhpFileAst($configPath, function (array $stmts) use (&$results, &$inspected, $write): bool {
            $root = $this->findConfigRootArray($stmts);

            if ($root === null) {
                return false;
            }

            $inspected = true;
            $connections = $this->findArrayItem($root, 'connections');

            if ($connections !== null && ! $connections->value instanceof Node\Expr\Array_) {
                // The key is there but is built dynamically (variable, spread, function call) —
                // report it as unreadable rather than as an absent connection.
                $results = array_fill_keys(array_keys($results), 'unreadable');

                return false;
            }

            if (! $connections?->value instanceof Node\Expr\Array_) {
                return false;
            }

            $changed = false;

            foreach (array_keys($results) as $connection) {
                $connectionItem = $this->findArrayItem($connections->value, $connection);

                if (! $connectionItem?->value instanceof Node\Expr\Array_) {
                    continue;
                }

                if ($this->findArrayItem($connectionItem->value, 'timezone') !== null) {
                    $results[$connection] = 'existing';

                    continue;
                }

                if ($write) {
                    $connectionItem->value->items[] = new Node\ArrayItem(
                        new Node\Scalar\String_('+00:00'),
                        new Node\Scalar\String_('timezone'),
                    );
                }

                $results[$connection] = 'changed';
                $changed = true;
            }

            return $write && $changed;
        });

        return $inspected ? $results : null;
    }

    /**
     * Parse and update a PHP file with format-preserving AST printing.
     *
     * @param  callable(array<Stmt>): bool  $mutator
     */
    private function modifyPhpFileAst(string $path, callable $mutator): bool
    {
        if (! $this->files->exists($path)) {
            return false;
        }

        $code = $this->files->get($path);
        $parser = (new ParserFactory)->createForHostVersion();

        try {
            $oldStmts = $parser->parse($code);
        } catch (Error $e) {
            $this->components->warn('Could not parse '.$this->relativePath($path).' — automatic timezone edit skipped. ('.$e->getMessage().')');

            return false;
        }

        if ($oldStmts === null) {
            $this->components->warn('Could not parse '.$this->relativePath($path).' — automatic timezone edit skipped.');

            return false;
        }

        $oldTokens = $parser->getTokens();
        $traverser = new NodeTraverser(new CloningVisitor);
        /** @var array<Stmt> $newStmts */
        $newStmts = $traverser->traverse($oldStmts);

        if (! $mutator($newStmts)) {
            return false;
        }

        $printer = new PrettyPrinter\Standard;
        $this->files->put(
            $path,
            $printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens),
        );

        return true;
    }

    /**
     * Locate the top-level return array used by Laravel config files.
     *
     * @param  array<Stmt>  $stmts
     */
    private function findConfigRootArray(array $stmts): ?Node\Expr\Array_
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Return_ && $stmt->expr instanceof Node\Expr\Array_) {
                return $stmt->expr;
            }
        }

        return null;
    }

    /**
     * Find an ArrayItem by its string key, or null when absent.
     */
    private function findArrayItem(Node\Expr\Array_ $array, string $key): ?Node\ArrayItem
    {
        foreach ($array->items as $item) {
            if ($item instanceof Node\ArrayItem
                && $item->key instanceof Node\Scalar\String_
                && $item->key->value === $key
            ) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Render an absolute path relative to the app base for user-facing messages.
     */
    private function relativePath(string $path): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * Run a labelled step with before/after output.
     *
     * The callback's return value is the verdict: `false` means the step failed.
     * Anything else (including the `null` of a callback that only throws on
     * error) counts as success.
     *
     * A MANDATORY failure is reported and returns false so handle() can stop and
     * exit non-zero — an upgrade whose migrations died must not report success.
     * A BEST-EFFORT failure (caches, autoload, frontend) only warns: those are
     * repeatable by hand and a machine without composer/Node must still be able
     * to finish the upgrade.
     *
     * @return bool Whether the step succeeded.
     */
    private function step(string $label, callable $callback, bool $mandatory = true): bool
    {
        $this->stepFailureDetail = null;
        $this->line("  <fg=gray>→</> {$label}...");

        $result = $callback();

        if ($result === false) {
            $detail = $this->stepFailureDetail ?? 'The step reported a failure.';
            $this->stepFailureDetail = null;

            $this->components->twoColumnDetail(
                $label,
                $mandatory ? '<fg=red>FAILED</>' : '<fg=yellow>FAILED (non-fatal)</>',
            );
            $this->line('  <fg='.($mandatory ? 'red' : 'yellow').'>'.$detail.'</>');

            return false;
        }

        $this->components->twoColumnDetail($label, '<fg=green>DONE</>');

        return true;
    }

    /**
     * Close a failed upgrade with a single line naming the step that failed and
     * the command that resumes it. sk:upgrade is idempotent (every step is
     * guarded or hash-aware), so re-running it after the fix is the resume path.
     */
    private function failUpgrade(string $label): int
    {
        $this->newLine();
        $this->line("  <fg=red;options=bold>Upgrade failed at step \"{$label}\" — fix the issue, then re-run `php artisan sk:upgrade`.</>");
        $this->newLine();

        return self::FAILURE;
    }

    /**
     * Ask the user to confirm a step unless --force or --no-interaction is set.
     */
    private function confirmStep(string $question): bool
    {
        if ($this->option('force') || $this->option('no-interaction')) {
            return true;
        }

        return confirm($question, default: true);
    }
}
