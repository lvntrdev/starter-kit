<?php

namespace Lvntr\StarterKit\Console\Commands;

use App\Models\User;
use App\Support\MediaPathGenerator;
use Composer\Autoload\ClassLoader;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Lvntr\StarterKit\StarterKitServiceProvider;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
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
     * Paths that may be skipped if they already exist (user-customizable on re-install).
     * Everything NOT in this list will always be overwritten, even without --force.
     *
     * @var list<string>
     */
    private array $preservablePaths = [
        'lang/',
    ];

    public function handle(): int
    {
        $this->files = new Filesystem;

        $this->newLine();
        $this->components->info('Installing Lvntr Starter Kit...');
        $this->newLine();

        // 1. Database configuration
        $this->configureDatabaseStep();

        // 2. Publish stubs — on first install (no hash registry), force-copy
        // so preservable paths like lang/ are populated from stubs.
        $isFirstInstall = $this->isFirstInstall();

        $this->step('Publishing application scaffolding', function () use ($isFirstInstall) {
            $stubsPath = StarterKitServiceProvider::stubsPath();
            $this->publishDirectory(
                $stubsPath,
                base_path(),
                $this->option('force') || $isFirstInstall,
            );
        });

        // 2b. Merge package.json (stub wins for shared deps, user extras preserved)
        $this->step('Merging package.json', function () {
            $this->mergePackageJson();
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

        // 4c. Inject DigitalOcean Spaces disk into config/filesystems.php
        $this->step('Configuring filesystem disks', function () {
            $this->injectFilesystemsConfig();
        });

        // 4d. Configure media library path generator
        $this->step('Configuring media library', function () {
            $this->injectMediaLibraryConfig();
        });

        // 4e. Wire starter kit bootstrap hooks into bootstrap/app.php
        $this->step('Configuring bootstrap/app.php', function () {
            $this->injectBootstrapApp();
        });

        // 4f. Register starter kit service providers in bootstrap/providers.php
        $this->step('Registering service providers', function () {
            $this->injectBootstrapProviders();
        });

        // 4g. Register custom helpers autoload entry in composer.json
        $this->step('Registering custom helpers autoload', function () {
            $this->injectHelpersAutoload();
        });

        // 5. Create hash registry directory
        $dir = storage_path('starter-kit');
        if (! $this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        // 6. Regenerate autoload so published classes are available for migrations/seeders
        $this->step('Regenerating autoload', function () {
            $composer = $this->findComposerBinary();
            $process = new Process([...$composer, 'dump-autoload', '-q'], base_path(), null, null, 120);
            $process->run();

            // Reload the in-process autoloader so newly published classes (e.g. App\Enums\RoleEnum)
            // are discoverable during the seeder step that runs in the same PHP process.
            $this->refreshAutoloader();
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
            ],
            default: 'mysql',
        );

        $envValues = ['DB_CONNECTION' => $driver];

        $host = text(label: 'Database host', default: '127.0.0.1', required: true);
        $port = text(label: 'Database port', default: '3306', required: true);
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
            $normalizedPath = str_replace('\\', '/', $relativePath);

            // package.json is merged separately to preserve user-added dependencies.
            if ($normalizedPath === 'package.json') {
                continue;
            }

            $targetPath = $destination.DIRECTORY_SEPARATOR.$relativePath;
            $targetDir = dirname($targetPath);

            if (! $this->files->isDirectory($targetDir)) {
                $this->files->makeDirectory($targetDir, 0755, true);
            }

            if (! $force && $this->isPreservable($relativePath) && $this->files->exists($targetPath)) {
                $this->skipped[] = $relativePath;

                continue;
            }

            $this->files->copy($file->getPathname(), $targetPath);
            $this->published[] = $relativePath;
        }
    }

    /**
     * Detect if this is the first install by checking for the hash registry file.
     * The registry is written at the end of install, so its absence means no prior install.
     */
    private function isFirstInstall(): bool
    {
        $hashFile = config('starter-kit.published_hashes', storage_path('starter-kit/hashes.json'));

        return ! $this->files->exists($hashFile);
    }

    /**
     * Merge the stub package.json into the application's package.json.
     *
     * Strategy: stub version wins for shared dependency versions. User-added
     * dependencies (and any extra root-level keys) are preserved.
     */
    private function mergePackageJson(): void
    {
        $stubPath = StarterKitServiceProvider::stubsPath('package.json');
        $targetPath = base_path('package.json');

        if (! $this->files->exists($stubPath)) {
            return;
        }

        if (! $this->files->exists($targetPath)) {
            $this->files->copy($stubPath, $targetPath);

            return;
        }

        /** @var array<string, mixed>|null $stub */
        $stub = json_decode($this->files->get($stubPath), true);
        /** @var array<string, mixed>|null $current */
        $current = json_decode($this->files->get($targetPath), true);

        if (! is_array($stub) || ! is_array($current)) {
            // Malformed JSON — fall back to stub to guarantee a working build.
            $this->files->copy($stubPath, $targetPath);

            return;
        }

        // Stub keys win at the root level; user-added extra keys are preserved.
        $merged = array_merge($current, $stub);

        // For dependency sections, union the two maps so user extras survive
        // while stub versions override any shared dependency versions.
        foreach (['dependencies', 'devDependencies'] as $section) {
            $stubSection = $stub[$section] ?? [];
            $currentSection = $current[$section] ?? [];

            if (! is_array($stubSection) || ! is_array($currentSection)) {
                continue;
            }

            $mergedSection = array_merge($currentSection, $stubSection);
            ksort($mergedSection);
            $merged[$section] = $mergedSection;
        }

        $this->files->put(
            $targetPath,
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );
    }

    /**
     * Locate the composer executable. Falls back to `php composer.phar` or `composer`
     * when a direct binary cannot be resolved from PATH.
     *
     * @return list<string>
     */
    private function findComposerBinary(): array
    {
        if ($this->files->exists(base_path('composer.phar'))) {
            return [PHP_BINARY, base_path('composer.phar')];
        }

        return ['composer'];
    }

    /**
     * Re-register Composer's autoloader in the current process so that classes
     * published during this install (e.g. app/Enums/RoleEnum.php) can be resolved
     * by the seeders that run later in the same PHP request.
     */
    private function refreshAutoloader(): void
    {
        $autoloadPath = base_path('vendor/autoload.php');

        if (! $this->files->exists($autoloadPath)) {
            return;
        }

        // Clear any opcache entries for the regenerated composer autoload files.
        if (function_exists('opcache_invalidate')) {
            foreach (['autoload_classmap.php', 'autoload_psr4.php', 'autoload_static.php', 'autoload_real.php'] as $file) {
                $path = base_path('vendor/composer/'.$file);
                if ($this->files->exists($path)) {
                    @opcache_invalidate($path, true);
                }
            }
        }

        // Re-include the freshly generated classmap/psr4 maps into the active ClassLoader
        // instance so newly published files become discoverable immediately.
        $loaders = ClassLoader::getRegisteredLoaders();
        foreach ($loaders as $vendorDir => $loader) {
            $classMap = $vendorDir.'/composer/autoload_classmap.php';
            if (file_exists($classMap)) {
                $map = require $classMap;
                if (is_array($map)) {
                    $loader->addClassMap($map);
                }
            }
        }
    }

    /**
     * Check if a path is user-customizable and should be preserved on re-install.
     * Only these paths are skipped when the file already exists (without --force).
     * Everything else is always overwritten to ensure a working installation.
     */
    private function isPreservable(string $relativePath): bool
    {
        $normalized = str_replace('\\', '/', $relativePath);

        foreach ($this->preservablePaths as $path) {
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
        $email = 'admin@lvntr.dev';
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

        $this->modifyPhpFileAst($configPath, function (array $stmts): bool {
            $array = $this->findConfigRootArray($stmts);

            if ($array === null) {
                return false;
            }

            // Idempotent — skip if already injected.
            if ($this->configArrayHasKey($array, 'available_languages')) {
                return false;
            }

            $array->items[] = new Node\ArrayItem(
                $this->envCallNode('APP_TIMEZONE', 'UTC'),
                new Node\Scalar\String_('display_timezone'),
            );

            $array->items[] = new Node\ArrayItem(
                new Node\Expr\Array_([
                    new Node\ArrayItem(new Node\Scalar\String_('English'), new Node\Scalar\String_('en')),
                    new Node\ArrayItem(new Node\Scalar\String_('Türkçe'), new Node\Scalar\String_('tr')),
                ]),
                new Node\Scalar\String_('available_languages'),
            );

            $array->items[] = new Node\ArrayItem(
                new Node\Expr\Array_([
                    new Node\ArrayItem(new Node\Scalar\String_('English'), new Node\Scalar\String_('en')),
                ]),
                new Node\Scalar\String_('languages'),
            );

            return true;
        });

        // Also set in runtime config so seeders can use it immediately.
        config([
            'app.display_timezone' => 'UTC',
            'app.available_languages' => ['en' => 'English', 'tr' => 'Türkçe'],
            'app.languages' => ['en' => 'English'],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // FILESYSTEMS CONFIG INJECTION
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Inject DigitalOcean Spaces disk into config/filesystems.php if not already present.
     */
    private function injectFilesystemsConfig(): void
    {
        $configPath = config_path('filesystems.php');

        if (! $this->files->exists($configPath)) {
            return;
        }

        $this->modifyPhpFileAst($configPath, function (array $stmts): bool {
            $root = $this->findConfigRootArray($stmts);

            if ($root === null) {
                return false;
            }

            $disksItem = $this->findArrayItem($root, 'disks');

            if ($disksItem === null || ! $disksItem->value instanceof Node\Expr\Array_) {
                return false;
            }

            // Idempotent — skip if the 'do' disk is already present.
            if ($this->configArrayHasKey($disksItem->value, 'do')) {
                return false;
            }

            $disksItem->value->items[] = new Node\ArrayItem(
                new Node\Expr\Array_([
                    new Node\ArrayItem(new Node\Scalar\String_('s3'), new Node\Scalar\String_('driver')),
                    new Node\ArrayItem($this->envCallNode('DO_SPACES_KEY'), new Node\Scalar\String_('key')),
                    new Node\ArrayItem($this->envCallNode('DO_SPACES_SECRET'), new Node\Scalar\String_('secret')),
                    new Node\ArrayItem($this->envCallNode('DO_SPACES_REGION'), new Node\Scalar\String_('region')),
                    new Node\ArrayItem($this->envCallNode('DO_SPACES_BUCKET'), new Node\Scalar\String_('bucket')),
                    new Node\ArrayItem($this->envCallNode('DO_SPACES_ENDPOINT'), new Node\Scalar\String_('endpoint')),
                    new Node\ArrayItem($this->envCallNode('DO_SPACES_URL'), new Node\Scalar\String_('url')),
                    new Node\ArrayItem(new Node\Scalar\String_('private'), new Node\Scalar\String_('visibility')),
                    new Node\ArrayItem(new Node\Expr\ConstFetch(new Node\Name('false')), new Node\Scalar\String_('throw')),
                    new Node\ArrayItem(new Node\Expr\ConstFetch(new Node\Name('false')), new Node\Scalar\String_('report')),
                ]),
                new Node\Scalar\String_('do'),
            );

            return true;
        });

        // Also set in runtime config so it's available immediately.
        config([
            'filesystems.disks.do' => [
                'driver' => 's3',
                'key' => env('DO_SPACES_KEY'),
                'secret' => env('DO_SPACES_SECRET'),
                'region' => env('DO_SPACES_REGION'),
                'bucket' => env('DO_SPACES_BUCKET'),
                'endpoint' => env('DO_SPACES_ENDPOINT'),
                'url' => env('DO_SPACES_URL'),
                'visibility' => 'private',
                'throw' => false,
                'report' => false,
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // MEDIA LIBRARY CONFIG INJECTION
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Set the custom path generator in config/media-library.php if not already configured.
     */
    private function injectMediaLibraryConfig(): void
    {
        $configPath = config_path('media-library.php');

        // Publish the config if it doesn't exist yet.
        if (! $this->files->exists($configPath)) {
            $vendorConfig = base_path('vendor/spatie/laravel-medialibrary/config/media-library.php');
            if ($this->files->exists($vendorConfig)) {
                $this->files->copy($vendorConfig, $configPath);
            } else {
                return;
            }
        }

        $this->modifyPhpFileAst($configPath, function (array $stmts): bool {
            // Idempotent — skip if MediaPathGenerator is already referenced anywhere
            // in the file (as a class constant, qualified name, or use alias).
            $finder = new NodeFinder;
            $existing = $finder->find($stmts, static function (Node $node): bool {
                if ($node instanceof Node\Name) {
                    $name = $node->toString();

                    return str_ends_with($name, 'MediaPathGenerator');
                }

                return false;
            });

            if (! empty($existing)) {
                return false;
            }

            $root = $this->findConfigRootArray($stmts);

            if ($root === null) {
                return false;
            }

            $pathGenerator = $this->findArrayItem($root, 'path_generator');

            if ($pathGenerator === null) {
                return false;
            }

            $pathGenerator->value = new Node\Expr\ClassConstFetch(
                new Node\Name\FullyQualified('App\\Support\\MediaPathGenerator'),
                'class',
            );

            return true;
        });

        config(['media-library.path_generator' => MediaPathGenerator::class]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // BOOTSTRAP INJECTION (format-preserving)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Wire the starter kit into bootstrap/app.php without overwriting the
     * Laravel defaults. Adds:
     *   - `api: __DIR__ . '/../routes/api.php'` to `withRouting()`
     *   - `\Lvntr\StarterKit\Bootstrap::middleware($middleware);` call inside
     *     the `withMiddleware()` closure
     *   - `\Lvntr\StarterKit\Bootstrap::exceptions($exceptions);` call inside
     *     the `withExceptions()` closure
     */
    /**
     * Add `app/Helpers/custom.php` to composer.json `autoload.files` so users
     * can register their own global helpers. Idempotent — skips if already
     * present, also rewrites the legacy `app/helpers.php` entry.
     */
    private function injectHelpersAutoload(): void
    {
        $path = base_path('composer.json');

        if (! $this->files->exists($path)) {
            return;
        }

        $data = json_decode($this->files->get($path), true);

        if (! is_array($data)) {
            return;
        }

        $files = $data['autoload']['files'] ?? [];
        $hasLegacy = in_array('app/helpers.php', $files, true);
        $hasCustom = in_array('app/Helpers/custom.php', $files, true);

        if (! $hasLegacy && $hasCustom) {
            return;
        }

        $files = array_values(array_filter($files, fn ($entry) => $entry !== 'app/helpers.php'));

        if (! $hasCustom) {
            $files[] = 'app/Helpers/custom.php';
        }

        $data['autoload']['files'] = array_values(array_unique($files));

        $this->files->put(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );
    }

    private function injectBootstrapApp(): void
    {
        $path = base_path('bootstrap/app.php');

        if (! $this->files->exists($path)) {
            return;
        }

        // Idempotent — the helper reference is the strongest marker.
        if (str_contains($this->files->get($path), 'Lvntr\\StarterKit\\Bootstrap')) {
            return;
        }

        $this->modifyPhpFileAst($path, function (array $stmts): bool {
            $return = null;
            foreach ($stmts as $stmt) {
                if ($stmt instanceof Stmt\Return_) {
                    $return = $stmt;
                    break;
                }
            }

            if ($return === null || ! $return->expr instanceof Node\Expr\MethodCall) {
                return false;
            }

            $changed = false;

            $this->walkMethodChain($return->expr, function (Node\Expr\MethodCall $call) use (&$changed): void {
                if (! $call->name instanceof Node\Identifier) {
                    return;
                }

                match ($call->name->name) {
                    'withRouting' => $this->addApiRouteArg($call, $changed),
                    'withMiddleware' => $this->addBootstrapCall($call, 'middleware', '$middleware', $changed),
                    'withExceptions' => $this->addBootstrapCall($call, 'exceptions', '$exceptions', $changed),
                    default => null,
                };
            });

            return $changed;
        });
    }

    /**
     * Register starter kit providers in bootstrap/providers.php without
     * dropping the user's existing entries.
     */
    private function injectBootstrapProviders(): void
    {
        $path = base_path('bootstrap/providers.php');

        if (! $this->files->exists($path)) {
            return;
        }

        $providers = [
            'App\\Providers\\DomainServiceProvider',
            'App\\Providers\\FortifyServiceProvider',
            'App\\Providers\\SettingsServiceProvider',
        ];

        $this->modifyPhpFileAst($path, function (array $stmts) use ($providers): bool {
            $return = null;
            foreach ($stmts as $stmt) {
                if ($stmt instanceof Stmt\Return_) {
                    $return = $stmt;
                    break;
                }
            }

            if ($return === null || ! $return->expr instanceof Node\Expr\Array_) {
                return false;
            }

            $array = $return->expr;
            $existing = $this->collectProviderClassNames($array);
            $changed = false;

            foreach ($providers as $fqcn) {
                if (in_array($fqcn, $existing, true) || in_array('\\'.$fqcn, $existing, true)) {
                    continue;
                }

                $array->items[] = new Node\ArrayItem(
                    new Node\Expr\ClassConstFetch(new Node\Name\FullyQualified($fqcn), 'class'),
                );
                $changed = true;
            }

            return $changed;
        });
    }

    /**
     * Walk a left-associative method chain (`foo()->bar()->baz()`) from the
     * outermost call inward, invoking the callback on each MethodCall node.
     */
    private function walkMethodChain(Node\Expr\MethodCall $call, callable $callback): void
    {
        $callback($call);

        if ($call->var instanceof Node\Expr\MethodCall) {
            $this->walkMethodChain($call->var, $callback);
        }
    }

    /**
     * Add `api: __DIR__ . '/../routes/api.php'` to a `withRouting()` call if
     * no `api` named argument exists yet.
     */
    private function addApiRouteArg(Node\Expr\MethodCall $call, bool &$changed): void
    {
        foreach ($call->args as $arg) {
            if ($arg instanceof Node\Arg && $arg->name instanceof Node\Identifier && $arg->name->name === 'api') {
                return;
            }
        }

        $apiValue = new Node\Expr\BinaryOp\Concat(
            new Node\Scalar\MagicConst\Dir,
            new Node\Scalar\String_('/../routes/api.php'),
        );

        $newArg = new Node\Arg($apiValue, name: new Node\Identifier('api'));

        // Keep ordering stable: append after the existing `web:` arg when present,
        // otherwise insert at the front of the argument list.
        $insertAt = count($call->args);
        foreach ($call->args as $index => $arg) {
            if ($arg instanceof Node\Arg && $arg->name instanceof Node\Identifier && $arg->name->name === 'web') {
                $insertAt = $index + 1;
                break;
            }
        }

        array_splice($call->args, $insertAt, 0, [$newArg]);
        $changed = true;
    }

    /**
     * Append `\Lvntr\StarterKit\Bootstrap::{$method}(${$paramName})` as the
     * first statement of the closure passed to `withMiddleware()` / `withExceptions()`.
     */
    private function addBootstrapCall(Node\Expr\MethodCall $call, string $method, string $paramName, bool &$changed): void
    {
        $closure = $call->args[0] ?? null;

        if (! $closure instanceof Node\Arg || ! $closure->value instanceof Node\Expr\Closure) {
            return;
        }

        $paramIdent = ltrim($paramName, '$');

        $bootstrapCall = new Stmt\Expression(
            new Node\Expr\StaticCall(
                new Node\Name\FullyQualified('Lvntr\\StarterKit\\Bootstrap'),
                $method,
                [new Node\Arg(new Node\Expr\Variable($paramIdent))],
            ),
        );

        // Prepend to preserve any user-added statements below it.
        array_unshift($closure->value->stmts, $bootstrapCall);
        $changed = true;
    }

    /**
     * Collect all class names currently listed in a providers array so we can
     * skip providers that are already registered (with or without a leading slash).
     *
     * @return list<string>
     */
    private function collectProviderClassNames(Node\Expr\Array_ $array): array
    {
        $names = [];

        foreach ($array->items as $item) {
            if (! $item instanceof Node\ArrayItem) {
                continue;
            }

            if ($item->value instanceof Node\Expr\ClassConstFetch
                && $item->value->class instanceof Node\Name
            ) {
                $names[] = $item->value->class->toString();
            }
        }

        return $names;
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
                // Store STUB hash — this is what we shipped, used to detect user modifications
                $hashes[$relativePath] = md5_file($file->getPathname());
            }
        }

        $hashes['_format'] = 'v2';

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

    // ══════════════════════════════════════════════════════════════════════
    // AST HELPERS (format-preserving config editing)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Parse a PHP file, invoke the mutator with the clone-traversed statement
     * list, and write the file back with format-preserving pretty printing
     * only when the mutator reports a change.
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
        } catch (Error) {
            return false;
        }

        if ($oldStmts === null) {
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
        $newCode = $printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);

        $this->files->put($path, $newCode);

        return true;
    }

    /**
     * Locate the top-level `return [...]` array used by Laravel config files.
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
     * Check if an Array_ node already contains the given string key.
     */
    private function configArrayHasKey(Node\Expr\Array_ $array, string $key): bool
    {
        return $this->findArrayItem($array, $key) !== null;
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
     * Build an `env('KEY')` or `env('KEY', 'default')` call expression.
     */
    private function envCallNode(string $key, ?string $default = null): Node\Expr\FuncCall
    {
        $args = [new Node\Arg(new Node\Scalar\String_($key))];

        if ($default !== null) {
            $args[] = new Node\Arg(new Node\Scalar\String_($default));
        }

        return new Node\Expr\FuncCall(new Node\Name('env'), $args);
    }
}
