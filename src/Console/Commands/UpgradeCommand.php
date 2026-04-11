<?php

namespace Lvntr\StarterKit\Console\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;

class UpgradeCommand extends Command
{
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
            $this->step('Synchronising stubs', function () {
                $this->call('sk:update', ['--no-interaction' => true]);
            });
        }

        // 5. Clear cached bootstrap artefacts so new service bindings pick up.
        $this->step('Clearing framework caches', function () {
            $this->callSilently('config:clear');
            $this->callSilently('route:clear');
            $this->callSilently('view:clear');
            $this->callSilently('cache:clear');

            foreach (['packages.php', 'services.php'] as $file) {
                $path = base_path('bootstrap/cache/'.$file);
                if ($this->files->exists($path)) {
                    $this->files->delete($path);
                }
            }
        });

        // 6. Regenerate composer autoload so any new classes resolve.
        $this->step('Regenerating autoload', function () {
            $process = new Process(['composer', 'dump-autoload', '-q'], base_path(), null, null, 120);
            $process->run();
        });


        // 7. Run any new migrations shipped with the v13 package line.
        if ($this->confirmStep('Run database migrations?')) {
            $this->step('Running migrations', function () {
                $this->call('migrate', ['--force' => true]);
            });
        }

        // 8. Re-seed permissions in case the package added new abilities.
        if ($this->confirmStep('Re-seed roles and permissions from config?')) {
            $this->step('Seeding permissions', function () {
                $this->call('sk:seed-permissions');
            });
        }

        // 9. Rebuild frontend assets.
        if (! $this->option('skip-build') && $this->confirmStep('Reinstall npm dependencies and rebuild assets?')) {
            $this->step('Installing npm dependencies', function () {
                $process = new Process(['npm', 'install'], base_path(), null, null, 600);
                $process->setTty(Process::isTtySupported());
                $process->run();
            });

            $this->step('Building frontend assets', function () {
                $process = new Process(['npm', 'run', 'build'], base_path(), null, null, 600);
                $process->setTty(Process::isTtySupported());
                $process->run();
            });
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
        $this->line('    1. Edit <fg=cyan>composer.json</>: set <fg=cyan>"laravel/framework": "^13.0"</> and <fg=cyan>"php": "^8.3"</>.');
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
        $this->line('    <fg=cyan>composer require lvntr/laravel-starter-kit:^13.0</>');
        $this->newLine();

        return false;
    }

    /**
     * Abort if PHP is older than the minimum required by Laravel 13.
     */
    private function assertPhpVersion(): bool
    {
        if (PHP_VERSION_ID >= 80300) {
            $this->components->twoColumnDetail('PHP version', '<fg=green>'.PHP_VERSION.'</>');

            return true;
        }

        $this->components->error('PHP 8.3 or newer is required for Laravel 13.');
        $this->line('  Current version: <fg=yellow>'.PHP_VERSION.'</>');
        $this->newLine();

        return false;
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Run a labelled step with before/after output.
     */
    private function step(string $label, callable $callback): void
    {
        $this->line("  <fg=gray>→</> {$label}...");
        $callback();
        $this->components->twoColumnDetail($label, '<fg=green>DONE</>');
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
