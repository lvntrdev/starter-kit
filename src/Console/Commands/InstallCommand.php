<?php

namespace Lvntr\StarterKit\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Lvntr\StarterKit\StarterKitServiceProvider;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class InstallCommand extends Command
{
    protected $signature = 'sk:install
        {--force : Overwrite existing files}';

    protected $description = 'Install the Lvntr Starter Kit scaffolding';

    private Filesystem $files;

    /** @var list<string> */
    private array $published = [];

    /** @var list<string> */
    private array $skipped = [];

    /**
     * Default Laravel files that conflict with Starter Kit stubs.
     *
     * @var list<string>
     */
    private array $conflictingFiles = [
        'vite.config.js',
        'vite.config.mjs',
        'resources/js/app.js',
        'resources/js/bootstrap.js',
        'resources/views/welcome.blade.php',
        'package-lock.json',
    ];

    /**
     * Paths that must always be overwritten, even without --force.
     * These contain critical schema/config changes that differ from Laravel defaults.
     *
     * @var list<string>
     */
    private array $forceOverwritePaths = [
        'database/migrations/',
        'database/seeders/',
        'package.json',
        'vite.config.ts',
        'tsconfig.json',
        'resources/js/app.ts',
        'resources/js/ssr.ts',
        'resources/css/app.css',
        'bootstrap/',
    ];

    public function handle(): int
    {
        $this->files = new Filesystem;

        $this->newLine();
        $this->components->info('Installing Lvntr Starter Kit...');
        $this->newLine();

        // 1. Database configuration
        $this->configureDatabaseStep();

        // 2. Publish stubs
        $this->step('Publishing application scaffolding', function () {
            $stubsPath = StarterKitServiceProvider::stubsPath();
            $this->publishDirectory($stubsPath, base_path(), $this->option('force'));
        });

        // 3. Remove conflicting default Laravel files
        $this->step('Removing conflicting default files', function () {
            foreach ($this->conflictingFiles as $file) {
                $path = base_path($file);
                if ($this->files->exists($path)) {
                    $this->files->delete($path);
                }
            }
        });

        // 4. Publish config
        $this->step('Publishing configuration', function () {
            $this->callSilently('vendor:publish', [
                '--tag' => 'starter-kit-config',
                '--force' => $this->option('force'),
            ]);
        });

        // 4b. Inject required config keys into config/app.php
        $this->step('Configuring application settings', function () {
            $this->injectAppConfig();
        });

        // 5. Create hash registry directory
        $dir = storage_path('starter-kit');
        if (! $this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        // 6. Regenerate autoload so published classes are available for migrations/seeders
        $this->step('Regenerating autoload', function () {
            $process = new Process(['composer', 'dump-autoload', '-q'], base_path(), null, null, 120);
            $process->run();
        });

        // 7. Run migrations
        if ($this->confirmStep('Run database migrations?')) {
            $this->runMigrations();
        }

        // 8. Run seeders
        if ($this->confirmStep('Run database seeders?')) {
            $this->runSeeders();
        }

        // 9. Passport keys
        if ($this->confirmStep('Generate Passport encryption keys?')) {
            $this->step('Generating Passport keys', function () {
                $this->callSilently('passport:keys', ['--force' => true]);
            });
        }

        // 10. Create admin user
        if ($this->confirmStep('Create default admin user?')) {
            $this->createAdminUser();
        }

        // 11. Install npm dependencies
        if ($this->confirmStep('Install npm dependencies and build assets?')) {
            $this->installFrontend();
        }

        // 12. Save stub hashes for update tracking
        $this->saveStubHashes();

        // Summary
        $this->newLine();
        $this->components->info('Lvntr Starter Kit installed successfully!');
        $this->newLine();

        if (! empty($this->published)) {
            $this->components->twoColumnDetail('<fg=green>Published</>', count($this->published).' files');
        }
        if (! empty($this->skipped)) {
            $this->components->twoColumnDetail('<fg=yellow>Skipped</>', count($this->skipped).' files (already exist, use --force to overwrite)');
        }

        $this->newLine();
        $this->components->warn('Run the following commands to ensure all components work correctly:');
        $this->line('  <fg=cyan>npm install && npm run build</>');
        $this->newLine();

        return self::SUCCESS;
    }

    // ══════════════════════════════════════════════════════════════════════
    // STEP RUNNER
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Run a step with simple before/after output (no spinner).
     */
    private function step(string $label, callable $callback): void
    {
        $this->line("  <fg=gray>→</> {$label}...");
        $callback();
        $this->components->twoColumnDetail($label, '<fg=green>DONE</>');
    }

    // ══════════════════════════════════════════════════════════════════════
    // DATABASE CONFIGURATION
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Configure database connection interactively.
     */
    private function configureDatabaseStep(): void
    {
        if ($this->option('no-interaction')) {
            return;
        }

        if (! confirm('Configure database connection?', default: true)) {
            return;
        }

        $this->newLine();

        $driver = select(
            label: 'Database driver',
            options: [
                'mysql' => 'MySQL',
                'mariadb' => 'MariaDB',
                'pgsql' => 'PostgreSQL',
            ],
            default: 'mysql',
        );

        $envValues = ['DB_CONNECTION' => $driver];

        $host = text(label: 'Database host', default: '127.0.0.1', required: true);
        $port = text(label: 'Database port', default: $driver === 'pgsql' ? '5432' : '3306', required: true);
        $database = text(label: 'Database name', default: 'starter_kit', required: true);
        $username = text(label: 'Database username', default: 'root', required: true);
        $password = text(label: 'Database password', default: '');

        $envValues['DB_HOST'] = $host;
        $envValues['DB_PORT'] = $port;
        $envValues['DB_DATABASE'] = $database;
        $envValues['DB_USERNAME'] = $username;
        $envValues['DB_PASSWORD'] = $password;

        // Write to .env
        $this->updateEnvFile($envValues);

        // Reload config so Laravel picks up the new values
        $this->laravel['config']->set('database.default', $driver);
        $this->laravel['config']->set("database.connections.{$driver}.host", $envValues['DB_HOST']);
        $this->laravel['config']->set("database.connections.{$driver}.port", $envValues['DB_PORT']);
        $this->laravel['config']->set("database.connections.{$driver}.database", $envValues['DB_DATABASE']);
        $this->laravel['config']->set("database.connections.{$driver}.username", $envValues['DB_USERNAME']);
        $this->laravel['config']->set("database.connections.{$driver}.password", $envValues['DB_PASSWORD']);

        // Purge old connection so new config is used
        DB::purge();

        // Test connection
        $this->testDatabaseConnection();

        $this->newLine();
        $this->components->info('Database configured successfully.');
    }

    /**
     * Update values in the .env file.
     *
     * @param  array<string, string>  $values
     */
    private function updateEnvFile(array $values): void
    {
        $envPath = base_path('.env');

        if (! $this->files->exists($envPath)) {
            $examplePath = base_path('.env.example');
            if ($this->files->exists($examplePath)) {
                $this->files->copy($examplePath, $envPath);
            } else {
                $this->files->put($envPath, '');
            }
        }

        $content = $this->files->get($envPath);

        foreach ($values as $key => $value) {
            // Wrap value in quotes if it contains spaces or is empty
            $escapedValue = $value;
            if ($value === '' || str_contains($value, ' ') || str_contains($value, '#')) {
                $escapedValue = "\"{$value}\"";
            }

            if (preg_match("/^{$key}=.*/m", $content)) {
                // Replace existing key
                $content = preg_replace("/^{$key}=.*/m", "{$key}={$escapedValue}", $content);
            } else {
                // Add new key
                $content .= "\n{$key}={$escapedValue}";
            }
        }

        $this->files->put($envPath, $content);
    }

    /**
     * Test the database connection.
     */
    private function testDatabaseConnection(): void
    {
        try {
            DB::connection()->getPdo();
            $this->components->twoColumnDetail('Connection test', '<fg=green>OK</>');
        } catch (\Exception $e) {
            $this->components->warn('Could not connect to database: '.$e->getMessage());
            $this->line('  <fg=gray>You may need to create the database manually before running migrations.</>');
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // PUBLISH STUBS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Recursively publish a directory.
     */
    private function publishDirectory(string $source, string $destination, bool $force): void
    {
        if (! $this->files->isDirectory($source)) {
            return;
        }

        if (! $this->files->isDirectory($destination)) {
            $this->files->makeDirectory($destination, 0755, true);
        }

        foreach ($this->files->allFiles($source, true) as $file) {
            $relativePath = $file->getRelativePathname();
            $targetPath = $destination.DIRECTORY_SEPARATOR.$relativePath;
            $targetDir = dirname($targetPath);

            if (! $this->files->isDirectory($targetDir)) {
                $this->files->makeDirectory($targetDir, 0755, true);
            }

            if (! $force && ! $this->isForceOverwritePath($relativePath) && $this->files->exists($targetPath)) {
                $this->skipped[] = $relativePath;

                continue;
            }

            $this->files->copy($file->getPathname(), $targetPath);
            $this->published[] = $relativePath;
        }
    }

    /**
     * Check if a path must always be overwritten (e.g. migrations with critical schema changes).
     */
    private function isForceOverwritePath(string $relativePath): bool
    {
        $normalized = str_replace('\\', '/', $relativePath);

        foreach ($this->forceOverwritePaths as $path) {
            if (str_starts_with($normalized, $path)) {
                return true;
            }
        }

        return false;
    }

    // ══════════════════════════════════════════════════════════════════════
    // MIGRATIONS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Run migrations with existing data check.
     */
    private function runMigrations(): void
    {
        // Check if database has existing tables
        $hasExistingTables = false;

        try {
            $tables = Schema::getTables();
            // Filter out the migrations table itself
            $appTables = array_filter($tables, fn ($table) => ($table['name'] ?? $table) !== 'migrations');
            $hasExistingTables = ! empty($appTables);
        } catch (\Exception) {
            // Connection failed or database doesn't exist — will be handled by migrate
        }

        if ($hasExistingTables) {
            $this->newLine();
            $this->components->warn('The database already contains tables.');

            $action = select(
                label: 'How would you like to proceed?',
                options: [
                    'fresh' => 'Drop all tables and run fresh migrations (data will be lost)',
                    'migrate' => 'Run pending migrations only (keep existing data)',
                    'skip' => 'Skip migrations',
                ],
                default: 'migrate',
            );

            if ($action === 'skip') {
                $this->components->info('Migrations skipped.');

                return;
            }

            if ($action === 'fresh') {
                if (! confirm('Are you sure? ALL existing data will be permanently deleted.', default: false)) {
                    $this->components->info('Migrations skipped.');

                    return;
                }

                $this->step('Running migrate:fresh', function () {
                    $this->callSilently('migrate:fresh', ['--force' => true]);
                });

                return;
            }
        }

        $this->step('Running migrations', function () {
            $this->callSilently('migrate', ['--force' => true]);
        });
    }

    // ══════════════════════════════════════════════════════════════════════
    // SEEDERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Discover and run seeders from the seeders directory.
     */
    private function runSeeders(): void
    {
        // Reload config files that were published during install
        // (Laravel booted before these files existed, so they're not in the config repository)
        $this->reloadPublishedConfigs();

        $seederPath = database_path('seeders');
        $files = glob($seederPath.'/_*.php');
        sort($files);

        foreach ($files as $file) {
            $className = pathinfo($file, PATHINFO_FILENAME);
            $displayName = preg_replace('/^_\d+_/', '', $className);
            $fqcn = 'Database\\Seeders\\'.$className;

            if (! class_exists($fqcn)) {
                require_once $file;
            }

            if (! class_exists($fqcn)) {
                $this->components->warn("Class [{$fqcn}] not found — skipping.");

                continue;
            }

            $this->step("Seeding: {$displayName}", function () use ($fqcn) {
                $this->callSilently('db:seed', [
                    '--class' => $fqcn,
                    '--force' => true,
                ]);
            });
        }
    }

    /**
     * Reload config files that were published during install.
     * Laravel was already booted before these files existed, so they need to be loaded manually.
     */
    private function reloadPublishedConfigs(): void
    {
        $configPath = config_path();

        foreach ($this->files->files($configPath) as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);

            if (config($key) === null) {
                config([$key => require $file]);
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // ADMIN USER
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Create the default admin user.
     */
    private function createAdminUser(): void
    {
        $email = 'admin@demo.com';
        $password = 'password';

        if (! $this->option('no-interaction')) {
            $email = text('Admin email:', default: $email, required: true);
            $password = text('Admin password:', default: $password, required: true);
        }

        // Use DB::table directly because the User model loaded in memory
        // is the default Laravel model, not the published stub model.
        $this->step("Creating admin user ({$email})", function () use ($email, $password) {
            $id = (string) Str::uuid();

            DB::table('users')->insert([
                'id' => $id,
                'first_name' => 'Admin',
                'last_name' => 'User',
                'email' => $email,
                'password' => Hash::make($password),
                'status' => 'active',
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Assign system_admin role if roles table exists
            if (Schema::hasTable('roles') && Schema::hasTable('model_has_roles')) {
                $role = DB::table('roles')->where('name', 'system_admin')->first();
                if ($role) {
                    DB::table('model_has_roles')->insert([
                        'role_id' => $role->id,
                        'model_type' => 'App\\Models\\User',
                        'model_id' => $id,
                    ]);
                }
            }
        });

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>Admin Email</>', $email);
        $this->components->twoColumnDetail('<fg=green>Admin Password</>', $password);
    }

    // ══════════════════════════════════════════════════════════════════════
    // FRONTEND
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Install frontend dependencies and build.
     */
    private function installFrontend(): void
    {
        // Remove old node_modules and lock file to ensure clean install with new package.json
        $nodeModules = base_path('node_modules');
        $lockFile = base_path('package-lock.json');

        if ($this->files->isDirectory($nodeModules)) {
            $this->step('Removing old node_modules', function () use ($nodeModules) {
                $this->files->deleteDirectory($nodeModules);
            });
        }

        if ($this->files->exists($lockFile)) {
            $this->files->delete($lockFile);
        }

        // 1. npm install
        $this->line('  <fg=gray>→</> Installing npm dependencies...');

        $npmInstall = new Process(['npm', 'install'], base_path(), null, null, 300);
        $npmInstall->run();

        if (! $npmInstall->isSuccessful()) {
            $this->components->twoColumnDetail('Installing npm dependencies', '<fg=red>FAILED</>');
            $this->line('  <fg=red>'.$npmInstall->getErrorOutput().'</>');

            return;
        }

        $this->components->twoColumnDetail('Installing npm dependencies', '<fg=green>DONE</>');

        // 2. Clear config/route cache so wayfinder sees fresh routes
        $this->runProcess(['php', 'artisan', 'config:clear'], 'Clearing config cache');
        $this->runProcess(['php', 'artisan', 'route:clear'], 'Clearing route cache');

        // 3. Generate Wayfinder route/action TypeScript files (required for build)
        $this->line('  <fg=gray>→</> Generating Wayfinder types...');

        $wayfinderProcess = new Process(['php', 'artisan', 'wayfinder:generate'], base_path(), null, null, 60);
        $wayfinderProcess->run();

        if (! $wayfinderProcess->isSuccessful()) {
            $this->components->twoColumnDetail('Generating Wayfinder types', '<fg=red>FAILED</>');
            $this->line('  <fg=red>'.$wayfinderProcess->getErrorOutput().'</>');
            $this->newLine();
            $this->components->warn('Wayfinder types could not be generated. Build will fail.');
            $this->line('  Fix the issue, then run:');
            $this->line('  <fg=cyan>php artisan wayfinder:generate && npm run build</>');

            return;
        }

        $this->components->twoColumnDetail('Generating Wayfinder types', '<fg=green>DONE</>');

        // 4. Build frontend
        $this->line('  <fg=gray>→</> Building frontend assets...');

        $npmBuild = new Process(['npm', 'run', 'build'], base_path(), null, null, 300);
        $npmBuild->run();

        if ($npmBuild->isSuccessful()) {
            $this->components->twoColumnDetail('Building frontend assets', '<fg=green>DONE</>');
        } else {
            $this->components->twoColumnDetail('Building frontend assets', '<fg=red>FAILED</>');
            $this->line('  <fg=red>'.$npmBuild->getErrorOutput().'</>');
        }
    }

    /**
     * Run a process silently, only for cache/clear type operations.
     */
    private function runProcess(array $command, string $label): void
    {
        $process = new Process($command, base_path(), null, null, 30);
        $process->run();
    }

    // ══════════════════════════════════════════════════════════════════════
    // APP CONFIG INJECTION
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Inject required config keys into config/app.php if not already present.
     */
    private function injectAppConfig(): void
    {
        $configPath = config_path('app.php');

        if (! $this->files->exists($configPath)) {
            return;
        }

        $content = $this->files->get($configPath);

        // Check if already injected
        if (str_contains($content, "'available_languages'")) {
            return;
        }

        $configBlock = <<<'PHP'

    /*
    |--------------------------------------------------------------------------
    | Display Timezone
    |--------------------------------------------------------------------------
    |
    | The timezone used for displaying dates/times in the UI.
    | Overridden from database settings at runtime by SettingsServiceProvider.
    |
    */

    'display_timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Available Languages
    |--------------------------------------------------------------------------
    |
    | All languages the application can support. The admin settings page
    | allows selecting which of these are active via checkboxes.
    |
    */

    'available_languages' => [
        'en' => 'English',
        'tr' => 'Türkçe',
    ],

    /*
    |--------------------------------------------------------------------------
    | Active Languages
    |--------------------------------------------------------------------------
    |
    | The currently active languages. Overridden from database settings
    | at runtime by SettingsServiceProvider. Defaults to all available.
    |
    */

    'languages' => [
        'en' => 'English',
    ],

PHP;

        // Insert before the final ];
        $content = preg_replace('/\n\];\s*$/', $configBlock."\n];\n", $content);

        $this->files->put($configPath, $content);

        // Also set in runtime config so seeders can use it immediately
        config([
            'app.display_timezone' => 'UTC',
            'app.available_languages' => ['en' => 'English', 'tr' => 'Türkçe'],
            'app.languages' => ['en' => 'English'],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // HASH REGISTRY
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Save hashes of published stub files for update tracking.
     */
    private function saveStubHashes(): void
    {
        $hashFile = config('starter-kit.published_hashes', storage_path('starter-kit/hashes.json'));
        $hashes = [];

        $stubsPath = StarterKitServiceProvider::stubsPath();

        foreach ($this->files->allFiles($stubsPath, true) as $file) {
            $relativePath = $file->getRelativePathname();
            $targetPath = base_path($relativePath);

            if ($this->files->exists($targetPath)) {
                $hashes[$relativePath] = md5_file($targetPath);
            }
        }

        $dir = dirname($hashFile);
        if (! $this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        $this->files->put($hashFile, json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Confirm a step, auto-accepting in no-interaction mode.
     */
    private function confirmStep(string $question): bool
    {
        if ($this->option('no-interaction')) {
            return true;
        }

        return confirm($question, default: true);
    }
}
