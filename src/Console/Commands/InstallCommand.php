<?php

namespace Lvntr\StarterKit\Console\Commands;

use App\Models\User;
use Composer\Autoload\ClassLoader;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Lvntr\StarterKit\Console\Commands\Concerns\MirrorsAiSkills;
use Lvntr\StarterKit\StarterKitServiceProvider;
use Lvntr\StarterKit\Support\Encryption\DataEncrypterFactory;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class InstallCommand extends Command
{
    use MirrorsAiSkills;

    protected $signature = 'sk:install
        {--force : Overwrite existing files}
        {--without-ai-skill : Skip .claude/skills/ and .codex/skills/ AI skill files}
        {--without-eject : Keep User/Role runtime in vendor (skip default domain eject)}
        {--resume : Resume a previously interrupted install, skipping already-completed steps}';

    protected $description = 'Install the Lvntr Starter Kit application skeleton (v13.5.0+: package runtime runs from vendor; User + Role are ejected into app/Domain on first install)';

    /**
     * Domains ejected into app/Domain automatically on the FIRST install so the
     * consumer owns the code they edit most. Each name MUST match a key of
     * EjectCommand::DOMAIN_MANIFEST verbatim. User↔Role have no cross-domain
     * references (only their own namespace + the never-ejected Domain\Shared and
     * app-owned classes), so ejecting both leaves no stale vendor link behind.
     *
     * @var list<string>
     */
    private const DEFAULT_EJECT_DOMAINS = ['User', 'Role'];

    private Filesystem $files;

    /** @var list<string> */
    private array $published = [];

    /** @var list<string> */
    private array $skipped = [];

    /**
     * Default domains successfully ejected during this install run. Drives the
     * end-of-install ownership summary; empty when the eject step was skipped
     * (--without-eject or re-install) or every eject failed.
     *
     * @var list<string>
     */
    private array $ejectedDomains = [];

    /**
     * Labels of the `step()` calls that have finished successfully. Loaded from
     * the on-disk progress checkpoint at the start of handle() and appended to
     * as each step completes, so an interrupted install can be resumed with
     * `--resume` without redoing finished work.
     *
     * @var list<string>
     */
    private array $completedSteps = [];

    /**
     * Cross-run metadata persisted alongside the completed-step list (currently
     * the original first-install decision, so a resumed run keeps ejecting the
     * default domains it started with instead of re-deciding mid-way).
     *
     * @var array<string, mixed>
     */
    private array $progressMeta = [];

    /**
     * The step currently executing — surfaced in the failure message so the
     * operator knows exactly which step to fix before `sk:install --resume`.
     */
    private ?string $currentStep = null;

    /**
     * Whether a progress checkpoint file already existed when this run started.
     * True means this is a resumed/re-run of a half-finished install, which must
     * NOT take the pristine first-install force-overwrite path.
     */
    private bool $progressExisted = false;

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
     * v13.6.0: the kit's `sk-*` UI translations are no longer shipped under
     * stubs/lang — they are vendor-resident (resources/lang/{en,tr}/sk-*.php) and
     * resolved namespace-less at runtime, so publishDirectory never copies them.
     * `lang/` is still preservable because stubs/lang/{en,tr}/validation.php (the
     * consumer-owned framework-default override stub) DOES still ship and must be
     * kept when the consumer has customized it on re-install.
     *
     * @var list<string>
     */
    private array $preservablePaths = [
        'lang/',
    ];

    /**
     * The full .gitignore ignore set, grouped by category.
     *
     * On install these are MERGED into the consumer's existing .gitignore:
     * missing lines are appended under their category header, and lines the
     * project already has (Laravel defaults or user-added) are left untouched.
     *
     * @var array<string, list<string>>
     */
    private array $gitignoreGroups = [
        'OS / Editör' => [
            '.DS_Store',
            'Thumbs.db',
            '.phpactor.json',
            '/.codex',
            '/.cursor/',
            '/.idea',
            '/.nova',
            '/.vscode',
            '/.zed',
        ],
        'Env / Secret' => [
            '.env',
            '.env.*',
            '!.env.example',
            '/auth.json',
            '/storage/*.key',
        ],
        'Bağımlılıklar' => [
            '/node_modules',
            '/vendor',
        ],
        'Log / Cache' => [
            '*.log',
            '.phpunit.result.cache',
            '/.phpunit.cache',
            '/storage/pail',
        ],
        'Laravel build / public' => [
            '/public/build',
            '/public/hot',
            '/public/storage',
            '/public/fonts-manifest.dev.json',
            '_ide_helper.php',
            'Homestead.json',
            'Homestead.yaml',
        ],
        'Starter Kit runtime state (üretilen hash manifesti, ~2MB)' => [
            '/storage/starter-kit/',
        ],
        'Claude Code yerel ayarları' => [
            '.claude/settings.local.json',
        ],
        'Laravel Wayfinder üretimi (actions / routes / helpers)' => [
            '/resources/js/wayfinder/',
            '/resources/js/actions/',
            '/resources/js/routes/',
        ],
        'Vite SSR build çıktısı (Inertia)' => [
            '/bootstrap/ssr',
        ],
        'unplugin otomatik üretilen tip tanımları' => [
            '/auto-imports.d.ts',
            '/components.d.ts',
        ],
        'Derlenmiş JSON çeviriler (kök seviye)' => [
            '/lang/*.json',
        ],
        'Üretilen tema manifesti (sk-theme-build resolver çıktısı)' => [
            '/resources/css/theme/_active.css',
            '/resources/css/theme/.sk-active-theme',
        ],
    ];

    public function handle(): int
    {
        $this->files = new Filesystem;

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>Lvntr Starter Kit Installer (v13.6.x)</>');
        $this->newLine();
        $this->line('  <fg=gray>Package runtime runs from vendor/lvntr/laravel-starter-kit.</>');
        $this->line('  <fg=gray>This command copies the application skeleton (auth, layout,</>');
        $this->line('  <fg=gray>user/role/setting domains, config) to your app directory, and on the</>');
        $this->line('  <fg=gray>first install ejects the User + Role runtime into app/Domain so you own it.</>');
        $this->newLine();
        $this->components->info('Installing Lvntr Starter Kit...');
        $this->newLine();

        // Preflight: surface a too-old / missing Node toolchain up front so the
        // operator is not blindsided at the npm step. Never hard-fails — the
        // frontend step degrades gracefully on its own.
        $this->preflight();

        // Load any checkpoint left by a previous interrupted run BEFORE deciding
        // whether this is a pristine first install.
        $this->progressExisted = $this->files->exists($this->progressFilePath());
        $this->loadProgress();

        if ($this->option('resume') && ! $this->progressExisted) {
            $this->components->warn('No interrupted install found — running a full install.');
            $this->newLine();
        }

        // 1. Publish stubs first so the kit's .env.example, package.json, and
        // scaffolding are in place before any .env / database wiring runs.
        // On a pristine first install (no hash registry AND no partial progress)
        // force-copy so preservable paths like lang/ are populated from stubs.
        // A resumed/re-run install ($progressExisted) must NOT force — the prior
        // attempt already published files the operator may have edited.
        $isFirstInstall = $this->computeFirstInstall(
            $this->progressExisted,
            $this->progressMeta,
            $this->isFirstInstall(),
        );
        $this->progressMeta['first_install'] = $isFirstInstall;
        $this->persistProgress();

        try {
            $this->step('Publishing application scaffolding', function () use ($isFirstInstall) {
                $stubsPath = StarterKitServiceProvider::stubsPath();
                $this->publishDirectory(
                    $stubsPath,
                    base_path(),
                    $this->shouldForceOverwrite($isFirstInstall, $this->progressExisted, (bool) $this->option('force')),
                );
            });

            // 1b. Merge package.json (stub wins for shared deps, user extras preserved)
            $this->step('Merging package.json', function () {
                $this->mergePackageJson();
            });

            // 1c. Seed .env from the freshly published .env.example so consumers
            // get every kit key without copying by hand, then generate APP_KEY
            // when it is blank. Runs before the database step so DB_* values are
            // written into an already-seeded .env.
            $this->step('Ensuring .env file', function () use ($isFirstInstall) {
                $this->ensureEnvFile($isFirstInstall);

                // The dedicated data key is generated HERE, not at the end of
                // the install: step 8 runs the seeders, and _03_SettingSeeder
                // encrypts mail/storage secrets through SettingService. A key
                // created after that leaves those rows written under APP_KEY
                // while .env advertises a dedicated key — the app then looks
                // protected, and the first `key:generate` on a server move
                // destroys exactly the values this feature exists to keep.
                // Guarded and first-install-only, so a re-run is a no-op.
                $this->ensureDataEncryptionKey(base_path('.env'), $isFirstInstall);
            });

            // 2. Database configuration (writes DB_* into the now-seeded .env)
            $this->configureDatabaseStep();

            // 3. Remove conflicting default Laravel files
            $this->step('Removing conflicting default files', function () {
                foreach ($this->conflictingFiles as $file) {
                    $path = base_path($file);
                    if ($this->files->exists($path)) {
                        $this->files->delete($path);
                    }
                }
            });

            // 3b. Ensure .gitignore entries — merge the kit's ignore set into the
            // project's existing .gitignore without dropping any current lines.
            $this->step('Ensuring .gitignore entries', function () {
                $this->ensureGitignore();
            });

            // 4. Publish config
            $this->step('Publishing configuration', function () {
                // Core starter-kit config
                $this->callSilently('vendor:publish', [
                    '--tag' => 'starter-kit-config',
                    '--force' => $this->option('force'),
                ]);

                // FileManager config — vendor runtime reads its defaults from here.
                // Published separately so users can override file-manager.php settings
                // (allowed mime types, max size, model bindings) without touching vendor code.
                $this->callSilently('vendor:publish', [
                    '--tag' => 'starter-kit-file-manager-config',
                    '--force' => $this->option('force'),
                ]);
            });

            // 4b. Inject required config keys into config/app.php
            $this->step('Configuring application settings', function () {
                $this->injectAppConfig();
            });

            // 4b-2. Pin MySQL/MariaDB sessions to UTC in config/database.php
            $this->step('Configuring database timezone', function () {
                $this->injectDatabaseTimezoneConfig();
            });

            // 4c. Inject DigitalOcean Spaces disk into config/filesystems.php
            $this->step('Configuring filesystem disks', function () {
                $this->injectFilesystemsConfig();
            });

            // 4c-2. Inject Turnstile keys into config/services.php so the kit's
            // CAPTCHA code (middleware, Fortify action, validation rule) can read
            // services.turnstile.* from the TURNSTILE_* env vars.
            $this->step('Configuring third-party services', function () {
                $this->injectServicesConfig();
            });

            // 4d. Wire starter kit bootstrap hooks into bootstrap/app.php
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

            // 4h. Default domain eject (User + Role) on first install only.
            //
            // Ordering rationale:
            //  - AFTER stub publish + provider injection (4f) so DomainServiceProvider
            //    already exists on disk — it is the target the eject injects App-FQCN
            //    Event::listen bindings into.
            //  - BEFORE the autoload regeneration (step 6) so the install's own single
            //    `composer dump-autoload` also picks up the freshly ejected
            //    app/Domain/{User,Role} classes — that is why each eject is invoked with
            //    --skip-autoload (the per-domain dump would be redundant work).
            //  - --no-vue because the Users/Roles Vue pages were already copied by the
            //    stub publish step; eject only needs to relocate the vendor runtime.
            if ($this->shouldEjectDefaultDomains($isFirstInstall)) {
                $this->step('Ejecting default domains (User, Role) to app/Domain', function () {
                    $this->ejectDefaultDomains();
                });
            }

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

            // 7-9. Database-dependent steps (migrations, seeders, permissions).
            // A soft reachability probe replaces a hard crash when the DB is down:
            // the operator can create/fix the connection and `sk:install --resume`
            // to pick these up without redoing the earlier filesystem steps.
            if ($this->databaseReachable()) {
                // 7. Run migrations
                if ($this->confirmStep('Run database migrations?')) {
                    $this->runMigrations();
                }

                // 8. Run seeders
                if ($this->confirmStep('Run database seeders?')) {
                    $this->runSeeders();
                }

                // 9. Seed permissions (config-driven, vendor runtime reads from permission-resources.php)
                if ($this->confirmStep('Seed permissions from config/permission-resources.php?')) {
                    $this->step('Seeding permissions', function () {
                        $this->callSilently('sk:seed-permissions', ['--fresh' => true]);
                    });
                }
            } else {
                $this->newLine();
                $this->components->warn('Database is not reachable — skipping migrations, seeders, and permission seeding.');
                $this->line('  <fg=gray>Create/fix the database connection, then run: php artisan sk:install --resume</>');
                $this->newLine();
            }

            // 10. Passport keys + personal access client
            if ($this->confirmStep('Generate Passport encryption keys?')) {
                $this->step('Generating Passport keys', function () {
                    $this->callSilently('passport:keys', ['--force' => true]);
                });
                $this->step('Creating Passport personal access client', function () {
                    $this->callSilently('passport:client', ['--personal' => true, '--name' => config('app.name').' Personal Access Client', '--provider' => 'users', '--no-interaction' => true]);
                });

                // ROOT CAUSE FIX: passport:client is invoked with --no-interaction,
                // whose configurePrompts() flips the GLOBAL static
                // Laravel\Prompts\Prompt::$interactive to false. Laravel's
                // restorePrompts() only restores the output stream, NOT that flag, so
                // it leaks into every later step — the "Create admin user?" /
                // "Install npm?" confirms would silently auto-accept their default
                // (never asking the operator) and the required admin-detail prompts
                // would throw NonInteractiveValidationException. Re-assert this
                // command's own prompt interactivity before any further prompting.
                $this->configurePrompts($this->input);
            }

            // 11. Create admin user
            if ($this->confirmStep('Create default admin user?')) {
                $this->createAdminUser();
            }

            // 12. Install npm dependencies
            if ($this->confirmStep('Install npm dependencies and build assets?')) {
                $this->installFrontend();
            }

            // 12b. Finalize the encryption keys as the last setup action. Both
            // helpers are guarded — they generate only when the .env value is blank,
            // so an existing key (e.g. on re-install) is preserved and
            // already-encrypted data / sessions stay decryptable. The order matters:
            // key:generate rewrites .env, so the dedicated-key helper must read the
            // file AFTER it, never from a body captured before.
            //
            // The dedicated key normally already exists by now — it is generated
            // back at step 1c, before the seeders write anything encrypted. This
            // call is the safety net for the path where step 1c could not write
            // it (unsupported cipher at that moment, an APP_KEY guard abort); it
            // is a no-op whenever the key is already there.
            $this->step('Finalizing encryption keys', function () use ($isFirstInstall) {
                $this->ensureAppKey(base_path('.env'));
                $this->ensureDataEncryptionKey(base_path('.env'), $isFirstInstall);
            });

            // 13. Save stub hashes for update tracking
            $this->saveStubHashes();

            // Best-effort: the .codex mirror is a regenerable artifact — a
            // failure here must not mark an otherwise completed install failed.
            try {
                $this->mirrorAiSkills(skipped: (bool) $this->option('without-ai-skill'));
            } catch (\Throwable $e) {
                $this->components->warn('AI skills could not be mirrored to .codex/skills: '.$e->getMessage());
            }

        } catch (\Throwable $e) {
            // Progress is checkpointed after every completed step, so it is
            // already on disk here. Surface a concise, actionable message
            // instead of a raw stack trace and let the operator resume.
            $this->renderStepFailure($e);

            return self::FAILURE;
        }

        // Install completed end-to-end — drop the checkpoint so the next run is
        // treated as a clean re-install, not a resume.
        $this->clearProgress();

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

        if ($this->ejectedDomains !== []) {
            $this->newLine();
            $this->components->twoColumnDetail(
                '<fg=green>Ejected domains</>',
                implode(', ', $this->ejectedDomains).' → app/Domain/ (consumer-owned)',
            );
            $this->line('  <fg=gray>The kit no longer ships runtime updates for these domains to you.</>');
            $this->line('  <fg=gray>To revert: delete the directories, remove the injected Event::listen lines</>');
            $this->line('  <fg=gray>from app/Providers/DomainServiceProvider.php, then run `composer dump-autoload`.</>');
            $this->line('  <fg=gray>To keep them in vendor next time: `php artisan sk:install --without-eject`.</>');
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
        if ($this->stepAlreadyCompleted($label, (bool) $this->option('resume'), $this->completedSteps)) {
            $this->components->twoColumnDetail($label, '<fg=gray>SKIPPED (resume)</>');

            return;
        }

        $this->currentStep = $label;
        $this->line("  <fg=gray>→</> {$label}...");
        $callback();
        $this->components->twoColumnDetail($label, '<fg=green>DONE</>');

        $this->markStepComplete($label);
        $this->currentStep = null;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PREFLIGHT + CHECKPOINT / RESUME
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Warn-only environment checks run before any file is touched. Never
     * hard-fails: a too-old / missing Node toolchain only affects the optional
     * npm step, which degrades on its own, so blocking the whole install here
     * would be worse than proceeding.
     */
    private function preflight(): void
    {
        $node = $this->detectNodeMajorVersion();

        if ($node === null) {
            $this->components->warn('Node.js was not found on PATH — the npm install/build step will be skipped. Install Node 20.19+ (Vite 7 engine floor) to build assets.');
            $this->newLine();

            return;
        }

        // Vite 7 needs Node ^20.19 || >=22.12; a bare major floor of 20 is the
        // coarse preflight gate (warn-only). The precise floor lives in the
        // NodeVersionCheck doctor check.
        if ($node < 20) {
            $this->components->warn("Node.js v{$node} detected, but the kit's frontend toolchain (Vite 7) needs Node 20.19+. The npm install/build step will be skipped — upgrade Node, then run: npm install && npm run build");
            $this->newLine();
        }
    }

    /**
     * Detect the major version of the `node` binary, or null when Node is not
     * installed / not on PATH. Pure execution wrapper around the pure parser so
     * the parsing logic stays unit-testable.
     */
    private function detectNodeMajorVersion(): ?int
    {
        try {
            $process = new Process(['node', '-v'], base_path(), null, null, 10);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            return $this->parseNodeMajorVersion($process->getOutput());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse `node -v` output (e.g. "v18.17.0\n") into its major version number,
     * or null when the string is not a recognizable version. Pure — unit tested.
     */
    private function parseNodeMajorVersion(string $raw): ?int
    {
        if (preg_match('/v?(\d+)\./', trim($raw), $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Whether the configured database connection currently accepts a PDO
     * handle. Used to skip (rather than crash on) the migration/seeder/permission
     * steps when the DB is unreachable.
     */
    private function databaseReachable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Absolute path to the resume checkpoint file.
     */
    private function progressFilePath(): string
    {
        return storage_path('starter-kit/install-progress.json');
    }

    /**
     * Load the completed-step list + metadata from a prior interrupted run.
     * Absent / malformed file leaves the in-memory state empty (clean start).
     */
    private function loadProgress(): void
    {
        $path = $this->progressFilePath();

        if (! $this->files->exists($path)) {
            return;
        }

        $data = json_decode($this->files->get($path), true);

        if (! is_array($data)) {
            return;
        }

        $this->completedSteps = array_values(array_filter(
            $data['completed'] ?? [],
            'is_string',
        ));
        $this->progressMeta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
    }

    /**
     * Record a step as completed and flush the checkpoint to disk so an
     * interruption on the NEXT step still leaves this one marked done.
     */
    private function markStepComplete(string $label): void
    {
        if (! in_array($label, $this->completedSteps, true)) {
            $this->completedSteps[] = $label;
        }

        $this->persistProgress();
    }

    /**
     * Write the current checkpoint (completed steps + metadata) to disk,
     * creating the storage/starter-kit directory on demand.
     */
    private function persistProgress(): void
    {
        $dir = dirname($this->progressFilePath());

        if (! $this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        $this->files->put($this->progressFilePath(), json_encode([
            'completed' => $this->completedSteps,
            'meta' => $this->progressMeta,
            'updated_at' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Remove the checkpoint after a fully successful install so the next run is
     * a clean re-install rather than a resume.
     */
    private function clearProgress(): void
    {
        $path = $this->progressFilePath();

        if ($this->files->exists($path)) {
            $this->files->delete($path);
        }
    }

    /**
     * Pure decision: has this step already finished in a prior run we are now
     * resuming? Kept side-effect free so it is unit testable in isolation.
     *
     * @param  list<string>  $completed
     */
    private function stepAlreadyCompleted(string $label, bool $resuming, array $completed): bool
    {
        return $resuming && in_array($label, $completed, true);
    }

    /**
     * Pure decision: is this a first install? A resumed/re-run install (progress
     * checkpoint present) inherits the ORIGINAL decision from the checkpoint so
     * it keeps ejecting the default domains it started with; a clean run falls
     * back to the absence of the hash registry.
     *
     * @param  array<string, mixed>  $meta
     */
    private function computeFirstInstall(bool $progressExisted, array $meta, bool $noHashRegistry): bool
    {
        if ($progressExisted) {
            return (bool) ($meta['first_install'] ?? false);
        }

        return $noHashRegistry;
    }

    /**
     * Pure decision: should the stub publish force-overwrite existing files?
     * Only on an explicit --force OR a PRISTINE first install (no checkpoint).
     * A resumed/re-run install ($progressExisted) never force-overwrites, so a
     * half-finished first install cannot clobber files the operator edited
     * between attempts.
     */
    private function shouldForceOverwrite(bool $isFirstInstall, bool $progressExisted, bool $forceOption): bool
    {
        return $forceOption || ($isFirstInstall && ! $progressExisted);
    }

    /**
     * Render a concise, actionable failure message (no raw stack trace) telling
     * the operator which step failed and how to resume.
     */
    private function renderStepFailure(\Throwable $e): void
    {
        $step = $this->currentStep ?? 'setup';

        $this->newLine();
        $this->components->error("Step failed: {$step}");
        $this->line('  <fg=red>'.$e->getMessage().'</>');
        $this->newLine();
        $this->line('  <fg=yellow>Fix the issue above, then resume the install with:</>');
        $this->line('  <fg=cyan>php artisan sk:install --resume</>');
        $this->line('  <fg=gray>Completed steps are checkpointed and will be skipped on resume.</>');
        $this->newLine();
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
     * Ensure the consumer's .env exists and carries every key the kit ships in
     * .env.example.
     *
     * On a fresh install the kit's .env.example becomes the base — any values
     * the user already set (APP_URL, APP_KEY, DB_*) are re-applied on top so
     * nothing critical is lost. On a re-install only keys missing from the
     * existing .env are appended — existing lines and values are never touched.
     * Finally APP_KEY is generated when blank so the app boots.
     */
    private function ensureEnvFile(bool $isFirstInstall = false): void
    {
        $envPath = base_path('.env');
        $examplePath = base_path('.env.example');

        // Nothing to seed from. The publish step is expected to have placed the
        // kit's .env.example, so reaching here means a malformed install.
        if (! $this->files->exists($examplePath)) {
            return;
        }

        if (! $this->files->exists($envPath) || $isFirstInstall) {
            $this->files->copy($examplePath, $envPath);
        } else {
            $this->mergeMissingEnvKeys($envPath, $examplePath);
        }

        $this->ensureAppKey($envPath);
        $this->ensureCachePrefix($envPath);
    }

    /**
     * Give this install a unique CACHE_PREFIX so two kit apps that share one
     * Redis never collide on cache keys.
     *
     * The kit ships an identical default APP_NAME and a commented CACHE_PREFIX.
     * Laravel derives the redis cache prefix from APP_NAME when CACHE_PREFIX is
     * blank, so two installs left at the defaults produce the SAME prefix and
     * stomp each other's cached `settings`, definitions, sessions, etc. We seed
     * a per-install prefix (only when the user has not set one) to break that.
     */
    private function ensureCachePrefix(string $envPath): void
    {
        $content = $this->files->get($envPath);

        // An uncommented, non-empty CACHE_PREFIX is already set — respect it.
        if (preg_match('/^CACHE_PREFIX=.+$/m', $content)) {
            return;
        }

        $slug = Str::slug(basename(base_path()), '_') ?: 'app';
        $prefix = 'sk_'.$slug.'_'.Str::lower(Str::random(6)).'_cache';

        // Replace the commented placeholder from .env.example if present,
        // otherwise an empty key, otherwise append.
        if (preg_match('/^#\s*CACHE_PREFIX=.*$/m', $content)) {
            $content = preg_replace('/^#\s*CACHE_PREFIX=.*$/m', "CACHE_PREFIX={$prefix}", $content, 1);
        } elseif (preg_match('/^CACHE_PREFIX=.*$/m', $content)) {
            $content = preg_replace('/^CACHE_PREFIX=.*$/m', "CACHE_PREFIX={$prefix}", $content, 1);
        } else {
            $content = rtrim($content, "\n")."\nCACHE_PREFIX={$prefix}\n";
        }

        $this->files->put($envPath, $content);
    }

    /**
     * Append keys present in .env.example but absent from .env, preserving the
     * user's existing lines and values. Comment/blank lines are ignored when
     * detecting keys; the missing lines are copied verbatim so their inline
     * comments and defaults survive.
     */
    private function mergeMissingEnvKeys(string $envPath, string $examplePath): void
    {
        $merged = $this->buildMergedEnvContent(
            $this->files->get($envPath),
            $this->files->get($examplePath),
        );

        if ($merged === null) {
            return;
        }

        $this->files->put($envPath, $merged);
    }

    /**
     * Keys the kit seeds ONLY into a brand-new .env, never into an existing one.
     *
     * The merge below exists to hand an installed app the new settings a kit
     * release added, which is right for a knob whose value is a preference. It
     * is wrong for a knob that decides whether a request is authorized: an app
     * that has been running for two years would silently acquire a stricter
     * gate from a command it ran for an unrelated reason, which is exactly the
     * "an upgrade must not change who gets a 403" guarantee the kit sells.
     *
     * A key listed here therefore reaches a fresh install (which copies
     * .env.example wholesale) and no one else. An existing app opts in by
     * writing the line itself — see docs/UPGRADE.md.
     *
     * The two DATA_ENCRYPTION_* keys are here for the same reason in a harsher
     * currency. Merging a BLANK DATA_ENCRYPTION_KEY line would be inert, but
     * merging the pair hands an installed app a placeholder that reads as
     * "adoption is configured" while nothing has been rekeyed, and it puts an
     * empty DATA_ENCRYPTION_PREVIOUS_KEYS next to it — the exact line whose
     * clearing is the one irreversible operator action in this feature. An
     * existing app adopts a dedicated key through `php artisan encryption:key`,
     * which preserves the current key in the previous-key list first; it never
     * arrives as a side effect of re-running the installer. See
     * docs/encryption.md and docs/server-migration-runbook.md.
     *
     * @var list<string>
     */
    private const FIRST_INSTALL_ONLY_ENV_KEYS = [
        'STARTER_KIT_ALLOW_UNRESOLVED_ROUTES',
        DataEncrypterFactory::PRIMARY_ENV_KEY,
        DataEncrypterFactory::PREVIOUS_ENV_KEY,
    ];

    /**
     * Compute the merged .env body, appending any example key absent from the
     * current .env under a kit header. Returns null when nothing is missing so
     * the caller can skip the write (making the operation idempotent).
     *
     * Keys in self::FIRST_INSTALL_ONLY_ENV_KEYS are skipped: this method runs
     * only on the re-install path (ensureEnvFile copies the example outright on
     * a first install), so skipping here is precisely "new installs get it,
     * existing ones do not".
     *
     * Pure string-in / string-out — no filesystem access — so it can be unit
     * tested in isolation.
     */
    private function buildMergedEnvContent(string $envContent, string $exampleContent): ?string
    {
        $existing = $this->parseEnvKeys($envContent);
        $exampleLines = preg_split('/\r\n|\r|\n/', $exampleContent) ?: [];

        $missing = [];
        foreach ($exampleLines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
                continue;
            }

            $key = trim(explode('=', $trimmed, 2)[0]);

            if (in_array($key, self::FIRST_INSTALL_ONLY_ENV_KEYS, strict: true)) {
                continue;
            }

            if ($key !== '' && ! array_key_exists($key, $existing)) {
                $missing[] = $line;
            }
        }

        if ($missing === []) {
            return null;
        }

        return rtrim($envContent, "\n")
            ."\n\n# ---- Lvntr Starter Kit ----\n"
            .implode("\n", $missing)."\n";
    }

    /**
     * Parse an .env body into a set of declared keys, ignoring comment and
     * blank lines.
     *
     * @return array<string, true>
     */
    private function parseEnvKeys(string $content): array
    {
        $keys = [];

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
                continue;
            }

            $keys[trim(explode('=', $trimmed, 2)[0])] = true;
        }

        return $keys;
    }

    /**
     * Generate APP_KEY via artisan when the .env value is blank, so a freshly
     * seeded environment boots without a manual `key:generate`.
     */
    private function ensureAppKey(string $envPath): void
    {
        $content = $this->files->get($envPath);

        // A non-empty APP_KEY is already set — leave it untouched.
        if (preg_match('/^APP_KEY=.+$/m', $content)) {
            return;
        }

        $this->callSilently('key:generate', ['--force' => true]);
    }

    /**
     * Seed a dedicated DATA_ENCRYPTION_KEY, on a FIRST install only.
     *
     * WHY THIS IS NOT JUST A BLANK CHECK. The blank check alone is not the
     * safety property here — $isFirstInstall is. On a re-install of a populated
     * app the key is blank too (FIRST_INSTALL_ONLY_ENV_KEYS keeps the merge
     * from ever writing the line), and generating one there would silently move
     * the WRITE key off APP_KEY on a live install. That does not lose data —
     * DataEncrypterFactory keeps APP_KEY last in the read chain — but it splits
     * the ciphertext across two keys behind the operator's back, with no
     * `encryption:rekey` run and nothing in DATA_ENCRYPTION_PREVIOUS_KEYS to
     * describe the split. Adoption on an existing app is an explicit
     * `php artisan encryption:key`, which preserves the current key first.
     *
     * On a first install the reasoning inverts: ensureEnvFile() has just copied
     * .env.example wholesale, so there is no prior key and no encrypted row,
     * and a key generated here is the one every value will ever have been
     * written with. Nothing is added to DATA_ENCRYPTION_PREVIOUS_KEYS on
     * purpose — a fresh install has no retired key, and copying APP_KEY in
     * there would duplicate that secret into a second env var and keep it a
     * valid data key forever, including after APP_KEY is rotated.
     *
     * APP_KEY is never touched: the rewrite is compared against the original
     * before the write, and a mismatch aborts with nothing on disk. The key is
     * written to .env and never printed, logged or echoed.
     */
    private function ensureDataEncryptionKey(string $envPath, bool $isFirstInstall): void
    {
        if (! $isFirstInstall || ! $this->files->exists($envPath)) {
            return;
        }

        $content = $this->files->get($envPath);

        // A non-blank value already in the file wins — a re-run or a resumed
        // first install that already generated a key must keep it, or every row
        // written in between becomes unreadable. The LAST assignment is read,
        // not the first: Laravel's dotenv repository only protects variables
        // defined OUTSIDE the file, so a later duplicate line inside .env is
        // what the running app ends up with.
        $existing = preg_match_all(
            $this->envAssignmentPattern(DataEncrypterFactory::PRIMARY_ENV_KEY),
            $content,
            $matches,
        ) > 0 ? trim((string) end($matches[1])) : '';

        if ($existing !== '') {
            return;
        }

        $cipher = (new DataEncrypterFactory)->cipher();

        // Encrypter::generateKey() falls back to 32 bytes for a cipher it does
        // not know, which writes a key every later read rejects. Probe with NUL
        // bytes (no real material on this path) and skip rather than write a
        // key that would break the app; the factory then stays on the APP_KEY
        // fallback, which is the pre-feature behaviour.
        if (! Encrypter::supported(str_repeat("\0", 16), $cipher)
            && ! Encrypter::supported(str_repeat("\0", 32), $cipher)) {
            $this->components->warn(
                'Cipher ['.$cipher.'] is not supported, so no '.DataEncrypterFactory::PRIMARY_ENV_KEY
                .' was generated. The app falls back to '.DataEncrypterFactory::APP_ENV_KEY
                .'; fix the cipher and run `php artisan encryption:key`.'
            );

            return;
        }

        $value = 'base64:'.base64_encode(Encrypter::generateKey($cipher));

        $line = DataEncrypterFactory::PRIMARY_ENV_KEY.'='.$value;

        $updated = $this->replaceOrAppendEnvLine($content, DataEncrypterFactory::PRIMARY_ENV_KEY, $line);

        // Fail closed on the candidate body, before it reaches disk. APP_KEY is
        // what keeps every pre-adoption row readable; a rewrite that moved it
        // would be silent, irreversible data loss.
        if ($this->appKeyLines($content) !== $this->appKeyLines($updated)) {
            $this->components->warn(
                'Skipped writing '.DataEncrypterFactory::PRIMARY_ENV_KEY.': the rewrite would have '
                .'modified an '.DataEncrypterFactory::APP_ENV_KEY.' line. Nothing was written.'
            );

            return;
        }

        $this->files->put($envPath, $updated);

        // The install process has ALREADY booted with this key absent, so the
        // seeders that run later in this same run would otherwise encrypt every
        // sensitive setting under APP_KEY while the file on disk advertises a
        // dedicated key. Push the new value into the live config and drop the
        // memoised encrypter so the rest of THIS run writes with it.
        config(['starter-kit.encryption.key' => $value]);

        StarterKitServiceProvider::flushDataEncrypter();
    }

    /**
     * Replace every uncommented assignment of $key with $line, filling a
     * commented placeholder if that is all there is, appending otherwise.
     *
     * Every occurrence is rewritten, not just the first: phpdotenv lets a later
     * duplicate win, so leaving a stale line behind would hand the app a key
     * this method did not choose. preg_replace_callback keeps key material from
     * ever being read as a backreference.
     */
    private function replaceOrAppendEnvLine(string $content, string $key, string $line): string
    {
        $assignment = $this->envAssignmentPattern($key);

        if (preg_match($assignment, $content) === 1) {
            return (string) preg_replace_callback($assignment, static fn (): string => $line, $content);
        }

        $commented = '%^[ \t]*#[ \t]*(?:export[ \t]+)?'.preg_quote($key, '%').'[ \t]*=.*$%m';

        if (preg_match($commented, $content) === 1) {
            return (string) preg_replace_callback($commented, static fn (): string => $line, $content, 1);
        }

        return rtrim($content, "\n")."\n".$line."\n";
    }

    /**
     * Pattern matching an uncommented assignment of $key, capturing its value.
     */
    private function envAssignmentPattern(string $key): string
    {
        return '%^[ \t]*(?:export[ \t]+)?'.preg_quote($key, '%').'[ \t]*=(.*)$%m';
    }

    /**
     * Every APP_KEY assignment line, commented ones included, verbatim.
     *
     * Scoped to assignments so prose naming the key cannot trip the guard.
     *
     * @return list<string>
     */
    private function appKeyLines(string $content): array
    {
        preg_match_all(
            '%^[ \t]*#?[ \t]*(?:export[ \t]+)?'.preg_quote(DataEncrypterFactory::APP_ENV_KEY, '%').'[ \t]*=.*$%m',
            $content,
            $matches,
        );

        return $matches[0];
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

            // Dependencies, build output, generated type files, and OS junk are
            // regenerated locally and must never reach a consumer app. Guarded here
            // (independent of git / Composer export-ignore) so that path or working-copy
            // installs are protected too.
            if ($this->isIgnoredStubFile($normalizedPath)) {
                continue;
            }

            foreach ($this->getSkipPaths() as $skipPath) {
                if (str_starts_with($normalizedPath, $skipPath)) {
                    continue 2; // skip this file
                }
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

            // Re-install guard: if hash registry exists and has a record for this file
            // but the target is missing, the user intentionally deleted it — don't restore.
            if (! $force && ! $this->files->exists($targetPath)) {
                $hashFile = config('starter-kit.published_hashes', storage_path('starter-kit/hashes.json'));
                if ($this->files->exists($hashFile)) {
                    $hashes = json_decode($this->files->get($hashFile), true) ?: [];
                    if (isset($hashes[$normalizedPath])) {
                        $this->skipped[] = $relativePath;

                        continue;
                    }
                }
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

    // ══════════════════════════════════════════════════════════════════════
    // DEFAULT DOMAIN EJECT (User + Role on first install)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Whether the default domains (User + Role) should be ejected into
     * app/Domain on this run. True only on the first install when the opt-out
     * --without-eject flag is absent. Re-installs ($isFirstInstall === false)
     * and explicit opt-out both return false so the consumer's already-owned
     * app/Domain is never touched.
     *
     * Pure decision logic — no filesystem or eject side effects — so it is unit
     * testable in isolation (mirrors the env/gitignore merge helpers).
     */
    private function shouldEjectDefaultDomains(bool $isFirstInstall): bool
    {
        return $isFirstInstall && ! $this->option('without-eject');
    }

    /**
     * Eject each default domain into app/Domain by delegating to sk:eject.
     *
     * Each call passes:
     *   - --no-vue: the domain's Vue pages were already copied by the stub
     *     publish step, so eject only relocates the vendor backend runtime.
     *   - --skip-autoload: the installer runs ONE composer dump-autoload (step 6)
     *     after this, covering all ejected copies — a per-domain dump would be
     *     wasted work.
     *   - --force: REQUIRED here, and NOT a clobber risk. The stub publish step
     *     (step 1) runs BEFORE this one and ships
     *     stubs/app/Domain/{User,Role}/BulkActions/*, so by the time eject runs
     *     the app/Domain/{Name} directory already exists. sk:eject's
     *     directory-level idempotency guard (EjectCommand::handle():
     *     `! $force && isDirectory(app/Domain/{Name})`) would otherwise fire and
     *     make the eject a SILENT no-op — it warns, but callSilently swallows the
     *     warning, so nothing is copied yet the install would still report the
     *     domain as consumer-owned. --force tells eject to proceed. This is safe
     *     because the vendor source (src/Domain/{Name}) ships only
     *     Actions/DTOs/Events/Listeners/Queries and NO BulkActions: a forced eject
     *     can never overwrite the stub-published BulkActions files — the two file
     *     sets do not overlap.
     *
     * Why the old "never forward --force" assumption was wrong: it treated
     * app/Domain as purely the consumer's own code, but missed that the stub
     * publish step itself creates app/Domain/{Name} (BulkActions only) on a fresh
     * install — so the un-forced eject always no-op'd. The new contract is:
     * pre-existing-runtime gate + --force + post-eject evidence check (below).
     *
     * Pre-existing-runtime gate (the genuine consumer-code protection): BEFORE
     * ejecting, if app/Domain/{Name}/Actions already exists, real runtime — a
     * prior eject or hand-authored code — is present, so the domain is skipped
     * entirely (no --force over it, not added to the owned list). The
     * stub-published BulkActions-only directory has no Actions/ subdir, so a
     * genuinely fresh install passes the gate; real runtime does not.
     *
     * A non-SUCCESS eject only warns (does not abort the install). Because the
     * guard can no longer no-op the eject, SUCCESS is ALSO re-checked against the
     * filesystem: the domain is recorded as ejected only when its runtime
     * (app/Domain/{Name}/Actions) actually materialized — a skipped/no-op eject
     * must never be reported as "ejected". After a verified eject the App-FQCN
     * Event::listen injection is checked against DomainServiceProvider; a silent
     * injection failure (callSilently swallows the eject's own warning) would
     * break the audit log, so it is surfaced here.
     */
    private function ejectDefaultDomains(): void
    {
        foreach (self::DEFAULT_EJECT_DOMAINS as $domain) {
            // Pre-existing-runtime gate: a populated app/Domain/{Name}/Actions
            // means real runtime (a prior eject or hand-written code) is already
            // there — never --force over it; treat the domain as already owned and
            // move on. The stub-published BulkActions-only directory has no
            // Actions/ subdir, so a genuinely fresh install passes this gate.
            if ($this->domainHasRuntime($domain)) {
                $this->components->warn(
                    "app/Domain/{$domain} already contains runtime code — eject skipped to avoid overwriting it. "
                    .'On a fresh install this is unexpected; otherwise the domain is already owned by your app.'
                );

                continue;
            }

            $exitCode = $this->callSilently('sk:eject', [
                'domain' => $domain,
                '--no-vue' => true,
                '--skip-autoload' => true,
                // See the --force rationale in the method docblock: required so the
                // stub-published BulkActions-only directory does not trip eject's
                // idempotency guard; cannot clobber (vendor ships no BulkActions).
                '--force' => true,
            ]);

            if ($exitCode !== self::SUCCESS) {
                $this->components->warn(
                    "Eject for {$domain} returned a non-success status — it may not be fully owned by app/Domain. "
                    ."Re-run `php artisan sk:eject {$domain} --no-vue --force` after install to retry."
                );

                continue;
            }

            // Runtime-evidence check: SUCCESS alone is not proof the runtime was
            // copied. Record ownership only when app/Domain/{Name}/Actions truly
            // exists now — never let a no-op masquerade as a completed eject.
            if (! $this->domainHasRuntime($domain)) {
                $this->components->warn(
                    "Eject for {$domain} reported success but app/Domain/{$domain}/Actions was not created — "
                    ."the domain still runs from vendor. Re-run `php artisan sk:eject {$domain} --no-vue --force` to retry."
                );

                continue;
            }

            $this->ejectedDomains[] = $domain;

            $this->verifyEventBindingsInjected($domain);
        }
    }

    /**
     * Whether app/Domain/{Name} already holds real ejected/hand-authored runtime,
     * detected by the presence of its Actions/ subdirectory.
     *
     * Actions/ is the discriminator on purpose: every ejectable domain's vendor
     * source ships an Actions/ folder, whereas the stub publish step seeds only
     * app/Domain/{Name}/BulkActions/* (no Actions/). So this returns false for a
     * freshly stub-published BulkActions-only directory (the eject should proceed)
     * and true once genuine runtime has landed — which is exactly what both the
     * pre-eject gate and the post-eject evidence check need.
     *
     * Pure filesystem probe (no eject side effects) so it is unit testable in
     * isolation, mirroring shouldEjectDefaultDomains().
     */
    private function domainHasRuntime(string $domain): bool
    {
        return $this->files->isDirectory(base_path("app/Domain/{$domain}/Actions"));
    }

    /**
     * Lightweight audit-chain check: confirm the App-FQCN Event::listen bindings
     * the eject was supposed to inject are actually present in
     * app/Providers/DomainServiceProvider.php. callSilently() swallows sk:eject's
     * own warning if injection failed, so without this check a broken audit log
     * would pass install silently. A missing binding only warns (does not abort).
     */
    private function verifyEventBindingsInjected(string $domain): void
    {
        $providerPath = base_path('app/Providers/DomainServiceProvider.php');

        if (! $this->files->exists($providerPath)) {
            $this->components->warn(
                "DomainServiceProvider not found after ejecting {$domain} — audit-log event bindings could not be verified."
            );

            return;
        }

        $code = $this->files->get($providerPath);

        // Marker FQCN per domain; presence implies the eject's boot()-injection ran.
        $marker = match ($domain) {
            'User' => 'App\\Domain\\User\\Events\\UserCreated',
            'Role' => 'App\\Domain\\Role\\Events\\RoleCreated',
            default => null,
        };

        if ($marker === null) {
            return;
        }

        if (! str_contains($code, $marker)) {
            $this->components->warn(
                "Event bindings for {$domain} were not found in DomainServiceProvider — the audit log may not fire for "
                ."{$domain} actions. Add the App\\Domain\\{$domain} Event::listen lines manually, or re-run "
                ."`php artisan sk:eject {$domain} --no-vue --force`."
            );
        }
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
     * Paths within the stubs directory to skip during publish.
     * Returned as relative paths under stubsPath() with forward slashes.
     *
     * @return list<string>
     */
    private function getSkipPaths(): array
    {
        $skip = [];

        if ($this->option('without-ai-skill')) {
            $skip[] = '.claude/skills/';
        }

        return $skip;
    }

    /**
     * Build/generated/dependency/OS-junk files that must never be copied into a
     * consumer app nor recorded in the hash registry.
     *
     * These are reproduced locally (npm install, vite build, wayfinder/unplugin
     * codegen) and are never the kit's source of truth. Matching is done by
     * directory prefix, exact generated-file name, and `.DS_Store` basename at any
     * depth. `resources/js/env.d.ts` is hand-written and intentionally NOT matched.
     */
    private function isIgnoredStubFile(string $normalizedPath): bool
    {
        $ignoredPrefixes = [
            'node_modules/',
            'public/build/',
            'bootstrap/ssr/',
            'resources/js/routes/',
            'vendor/',
        ];

        foreach ($ignoredPrefixes as $prefix) {
            if (str_starts_with($normalizedPath, $prefix)) {
                return true;
            }
        }

        // Generated type declarations (unplugin auto-import / components resolver)
        // and the theme resolver's generated manifest (sk-theme-build.mjs output).
        // package-lock.json: the stub-side npm lockfile must never land in a consumer
        // app — installFrontend() regenerates it via `npm install`, and copying the
        // kit's lock would pin the consumer to the kit's exact dependency graph and
        // break `npm ci` against their own package.json.
        $ignoredExact = [
            'auto-imports.d.ts',
            'components.d.ts',
            'resources/css/theme/_active.css',
            'package-lock.json',
        ];

        if (in_array($normalizedPath, $ignoredExact, true)) {
            return true;
        }

        return basename($normalizedPath) === '.DS_Store';
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
    // GITIGNORE
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Ensure the project's .gitignore contains the kit's full ignore set.
     *
     * Reads the existing file (an empty string when absent), merges in any
     * missing entries, and writes back only when something changed.
     */
    private function ensureGitignore(): void
    {
        $path = base_path('.gitignore');

        $existing = $this->files->exists($path) ? $this->files->get($path) : '';

        $merged = $this->buildGitignoreContent($existing);

        if ($merged === $existing) {
            return;
        }

        $this->files->put($path, $merged);
    }

    /**
     * Merge the desired ignore groups into existing .gitignore content.
     *
     * Only entries not already present (compared line-by-line, trimmed) are
     * appended, grouped under their category header. Existing lines — Laravel
     * defaults and any user-added entries — are preserved verbatim. Returns the
     * input unchanged when nothing is missing, which makes the operation
     * idempotent.
     */
    private function buildGitignoreContent(string $existing): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $existing) ?: [];
        $present = array_map('trim', $lines);

        $blocks = [];

        foreach ($this->gitignoreGroups as $label => $entries) {
            $missing = array_values(array_filter(
                $entries,
                static fn (string $entry): bool => ! in_array($entry, $present, true),
            ));

            if ($missing === []) {
                continue;
            }

            $blocks[] = "# ---- {$label} ----\n".implode("\n", $missing);
        }

        if ($blocks === []) {
            return $existing;
        }

        $appendix = implode("\n\n", $blocks)."\n";

        if (trim($existing) === '') {
            return $appendix;
        }

        return rtrim($existing, "\n")."\n\n".$appendix;
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

            // Never offer a destructive full reset in production-like environments.
            // Mirrors site:install's guard so a mis-set APP_ENV cannot wipe live
            // data through the installer.
            $allowFresh = ! $this->isProductionLikeEnvironment();

            if (! $allowFresh) {
                $this->line('  <fg=gray>Detected a production-like environment — the destructive "fresh" option is disabled.</>');
            }

            $options = ['migrate' => 'Run pending migrations only (keep existing data)'];

            if ($allowFresh) {
                $options = [
                    'fresh' => 'Drop all tables and run fresh migrations (data will be lost)',
                ] + $options;
            }

            $options['skip'] = 'Skip migrations';

            $action = select(
                label: 'How would you like to proceed?',
                options: $options,
                default: 'migrate',
            );

            if ($action === 'skip') {
                $this->components->info('Migrations skipped.');

                return;
            }

            // `$allowFresh` re-checked as belt-and-suspenders: in production the
            // option is never presented, so select() cannot return it here.
            if ($action === 'fresh' && $allowFresh) {
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

    /**
     * Whether the current environment looks like production, where a destructive
     * full reset (migrate:fresh) must never be offered.
     *
     * Matches 'prod'/'production' as a case-insensitive substring so 'prod',
     * 'production', 'prod-eu', 'my-prod' all trip the guard — mirrors the
     * stubbed site:install command's blocklist philosophy.
     */
    private function isProductionLikeEnvironment(): bool
    {
        $environment = strtolower((string) app()->environment());

        foreach (['prod', 'production'] as $keyword) {
            if (str_contains($environment, $keyword)) {
                return true;
            }
        }

        return false;
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
     * Whether this session may prompt the operator for input.
     *
     * Mirrors the exact condition Laravel uses to enable laravel/prompts
     * (Illuminate\Console\Concerns\ConfiguresPrompts): an interactive input AND
     * a real TTY on STDIN (or a unit-test run). A genuinely TTY-less session
     * (--no-interaction, real CI, piped stdin) must fall back to defaults rather
     * than fire a required prompt that would throw NonInteractiveValidationException.
     */
    private function canPrompt(): bool
    {
        if ($this->option('no-interaction')) {
            return false;
        }

        return ($this->input->isInteractive() && defined('STDIN') && stream_isatty(STDIN))
            || $this->getLaravel()->runningUnitTests();
    }

    /**
     * Create the admin user.
     *
     * When the session can actually prompt (interactive TTY), every field
     * (first/last name, email, password) is required and prompted with NO
     * pre-filled default — the password is masked and confirmed by re-entry.
     * When it cannot prompt (--no-interaction, or any TTY-less session such as
     * CI / Herd / piped stdin / site:install automation), there is no human to
     * prompt, so a fixed email + a freshly generated random password are used
     * so the flow still produces a working admin instead of crashing on a
     * required prompt. The generated password is printed once at the end.
     */
    private function createAdminUser(): void
    {
        $firstName = 'Admin';
        $lastName = 'User';
        $email = 'admin@lvntr.dev';
        // A fresh random password every run — a fixed fallback would be a known,
        // guessable credential in every non-interactive (CI/automation) install.
        $password = Str::password(16);
        $usedDefaults = true;

        if ($this->canPrompt()) {
            $usedDefaults = false;

            $firstName = text('Admin first name:', required: true);
            $lastName = text('Admin last name:', required: true);

            $email = text(
                label: 'Admin email:',
                required: true,
                validate: fn (string $value): ?string => filter_var($value, FILTER_VALIDATE_EMAIL)
                    ? null
                    : 'Enter a valid email address.',
            );

            $password = password(
                label: 'Admin password:',
                required: true,
                validate: fn (string $value): ?string => strlen($value) >= 8
                    ? null
                    : 'Password must be at least 8 characters.',
            );

            // Re-entry only validates against $password; the value itself is discarded.
            password(
                label: 'Confirm admin password:',
                required: true,
                validate: fn (string $value): ?string => $value === $password
                    ? null
                    : 'Passwords do not match.',
            );
        }

        // Use DB::table directly because the User model loaded in memory
        // is the default Laravel model, not the published stub model.
        $this->step("Creating admin user ({$email})", function () use ($firstName, $lastName, $email, $password) {
            $id = (string) Str::uuid();

            DB::table('users')->insert([
                'id' => $id,
                'first_name' => $firstName,
                'last_name' => $lastName,
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

        // Never echo a password the operator typed into their terminal. Only the
        // freshly generated --no-interaction fallback password is surfaced, because
        // automation has no other way to learn the generated credentials.
        $this->components->twoColumnDetail(
            '<fg=green>Admin Password</>',
            $usedDefaults ? $password : '<fg=gray>(girdiğiniz şifre)</>',
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // FRONTEND
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Install frontend dependencies and build.
     */
    private function installFrontend(): void
    {
        // Guard the npm work behind a Node preflight so an old / missing Node
        // toolchain produces a clear skip + follow-up instructions instead of a
        // cryptic process failure mid-install.
        $node = $this->detectNodeMajorVersion();

        if ($node === null) {
            $this->components->warn('Node.js was not found on PATH — skipping npm install/build. Install Node 20.19+ (Vite 7 engine floor), then run: npm install && npm run build');

            return;
        }

        if ($node < 20) {
            $this->components->warn("Node.js v{$node} is too old (need 20.19+, Vite 7 engine floor) — skipping npm install/build. Upgrade Node, then run: npm install && npm run build");

            return;
        }

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
                $this->envCallNode('APP_DISPLAY_TIMEZONE', 'UTC'),
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
            'app.display_timezone' => env('APP_DISPLAY_TIMEZONE', 'UTC'),
            'app.available_languages' => ['en' => 'English', 'tr' => 'Türkçe'],
            'app.languages' => ['en' => 'English'],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // DATABASE CONFIG INJECTION
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Pin existing MySQL and MariaDB connection definitions to UTC.
     */
    private function injectDatabaseTimezoneConfig(): void
    {
        $configPath = config_path('database.php');

        if (! $this->files->exists($configPath)) {
            $this->components->warn('Could not find config/database.php — automatic database timezone edit skipped.');

            return;
        }

        // sk:install is also the documented recovery path on an existing project, so it can meet
        // a populated database whose session is not UTC. Pinning it there shifts every stored
        // application-written TIMESTAMP in the UI, which is sk:upgrade's consent-gated territory.
        if ($this->databaseHoldsOffsetTimestamps()) {
            $this->components->warn('This database already holds data on a non-UTC session — database timezone pin skipped.');
            $this->line('    Pinning it here would shift how existing timestamps render.');
            $this->line('    Run <fg=cyan>php artisan sk:upgrade</> and follow the one-time conversion guide in <fg=cyan>docs/timezone.md</>.');

            return;
        }

        $results = $this->rewriteDatabaseTimezoneConfig($configPath);

        if ($results === null) {
            $this->components->warn("Could not locate the connections array in config/database.php — add 'timezone' => '+00:00' to the mysql/mariadb connections manually.");

            return;
        }

        foreach ($results as $connection => $result) {
            if ($result === 'changed') {
                config()->set("database.connections.{$connection}.timezone", '+00:00');
                DB::purge($connection);
            }

            $message = match ($result) {
                'changed' => "{$connection}: timezone pinned to +00:00.",
                'existing' => "{$connection}: existing timezone left unchanged.",
                'unreadable' => "{$connection}: connections array is not a literal; add 'timezone' => '+00:00' manually.",
                default => "{$connection}: connection not found; skipped.",
            };

            $this->line('    <fg=gray>config/database.php: '.$message.'</>');
        }
    }

    /**
     * Report whether the default connection already holds rows written through a non-UTC session.
     *
     * An unreachable or unreadable database answers false: a fresh install must not be blocked by
     * a database that is not up yet, and there is no stored data to protect in that case.
     */
    private function databaseHoldsOffsetTimestamps(): bool
    {
        $defaultConnection = (string) config('database.default');
        $driver = strtolower((string) config("database.connections.{$defaultConnection}.driver"));

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return false;
        }

        try {
            $connection = DB::connection($defaultConnection);
            $timezone = $connection->selectOne('SELECT @@session.time_zone AS time_zone');
            $sessionTimezone = (string) (is_array($timezone)
                ? ($timezone['time_zone'] ?? 'unknown')
                : ($timezone->time_zone ?? 'unknown'));

            if (in_array($sessionTimezone, ['+00:00', 'UTC'], true)) {
                return false;
            }

            return $connection->getSchemaBuilder()->hasTable('users')
                && $connection->table('users')->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Add the UTC timezone literal to supported database connection arrays.
     *
     * @return array{mysql: 'changed'|'existing'|'missing'|'unreadable', mariadb: 'changed'|'existing'|'missing'|'unreadable'}|null
     */
    private function rewriteDatabaseTimezoneConfig(string $configPath): ?array
    {
        $results = ['mysql' => 'missing', 'mariadb' => 'missing'];
        $inspected = false;

        $this->modifyPhpFileAst($configPath, function (array $stmts) use (&$results, &$inspected): bool {
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

                if ($this->configArrayHasKey($connectionItem->value, 'timezone')) {
                    $results[$connection] = 'existing';

                    continue;
                }

                $connectionItem->value->items[] = new Node\ArrayItem(
                    new Node\Scalar\String_('+00:00'),
                    new Node\Scalar\String_('timezone'),
                );
                $results[$connection] = 'changed';
                $changed = true;
            }

            return $changed;
        });

        return $inspected ? $results : null;
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
    // SERVICES CONFIG INJECTION
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Inject a `turnstile` block into config/services.php if not already present.
     *
     * The kit reads `services.turnstile.*` (enabled / site_key / secret_key /
     * verify_url) from the CAPTCHA middleware, the Fortify ValidateTurnstile
     * action, and the TurnstileRule. Laravel's stock services.php has no such
     * block, so without this the TURNSTILE_* env vars are silently ignored.
     */
    private function injectServicesConfig(): void
    {
        $configPath = config_path('services.php');

        if (! $this->files->exists($configPath)) {
            return;
        }

        $this->modifyPhpFileAst($configPath, function (array $stmts): bool {
            $root = $this->findConfigRootArray($stmts);

            if ($root === null) {
                return false;
            }

            // Idempotent — skip if the 'turnstile' block is already present.
            if ($this->configArrayHasKey($root, 'turnstile')) {
                return false;
            }

            $root->items[] = new Node\ArrayItem(
                new Node\Expr\Array_([
                    new Node\ArrayItem(
                        new Node\Expr\FuncCall(new Node\Name('env'), [
                            new Node\Arg(new Node\Scalar\String_('TURNSTILE_ENABLED')),
                            new Node\Arg(new Node\Expr\ConstFetch(new Node\Name('false'))),
                        ]),
                        new Node\Scalar\String_('enabled'),
                    ),
                    new Node\ArrayItem($this->envCallNode('TURNSTILE_SITE_KEY'), new Node\Scalar\String_('site_key')),
                    new Node\ArrayItem($this->envCallNode('TURNSTILE_SECRET_KEY'), new Node\Scalar\String_('secret_key')),
                    new Node\ArrayItem(
                        $this->envCallNode('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
                        new Node\Scalar\String_('verify_url'),
                    ),
                ]),
                new Node\Scalar\String_('turnstile'),
            );

            return true;
        });

        // Also set in runtime config so it's available immediately.
        config([
            'services.turnstile' => [
                'enabled' => (bool) env('TURNSTILE_ENABLED', false),
                'site_key' => env('TURNSTILE_SITE_KEY'),
                'secret_key' => env('TURNSTILE_SECRET_KEY'),
                'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
            ],
        ]);
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

        // Dosya yoksa minimal stub oluştur — composer dump-autoload'dan ÖNCE
        // çalıştığı için eksik dosya her artisan çağrısını kırar.
        $helpersPath = base_path('app/Helpers/custom.php');
        if (! $this->files->exists($helpersPath)) {
            $this->files->makeDirectory(dirname($helpersPath), 0755, true, true);
            $this->files->put($helpersPath, "<?php\n");
        }
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

        $injected = $this->modifyPhpFileAst($path, function (array $stmts): bool {
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

        // A false return here (the idempotency guard above already ruled out the
        // "already wired" case) means the AST edit could not find the expected
        // application-builder chain. Don't fail silently — tell the operator the
        // exact lines to add so bootstrap wiring is never quietly missing.
        if (! $injected) {
            $this->warnBootstrapManualWiring();
        }
    }

    /**
     * Emit the manual bootstrap/app.php wiring instructions when the automatic
     * AST injection could not be applied.
     */
    private function warnBootstrapManualWiring(): void
    {
        $this->newLine();
        $this->components->warn('Could not automatically wire bootstrap/app.php — add these manually:');
        $this->line("  <fg=cyan>->withRouting(api: __DIR__.'/../routes/api.php', /* keep existing args */)</>");
        $this->line('  <fg=cyan>->withMiddleware(function ($middleware) { \\Lvntr\\StarterKit\\Bootstrap::middleware($middleware); })</>');
        $this->line('  <fg=cyan>->withExceptions(function ($exceptions) { \\Lvntr\\StarterKit\\Bootstrap::exceptions($exceptions); })</>');
        $this->newLine();
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
            // The shipped stub lists these providers by their short name via
            // `use` imports (e.g. `DomainServiceProvider::class`). Resolving the
            // use-alias map lets us compare against the canonical FQCN so we do
            // not append a fully-qualified duplicate of an already-listed
            // provider — a duplicate would boot the provider twice and register
            // its event listeners twice.
            $useMap = $this->collectUseAliases($stmts);

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
            $existing = $this->collectProviderClassNames($array, $useMap);
            $changed = false;

            foreach ($providers as $fqcn) {
                if (in_array($fqcn, $existing, true)) {
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
     * Collect all class names currently listed in a providers array, resolved to
     * their canonical fully-qualified form so a `Foo::class` written against a
     * `use App\Providers\Foo;` import compares equal to `App\Providers\Foo`.
     *
     * @param  array<string, string>  $useMap  alias => FQCN from the file's `use` statements
     * @return list<string>
     */
    private function collectProviderClassNames(Node\Expr\Array_ $array, array $useMap = []): array
    {
        $names = [];

        foreach ($array->items as $item) {
            if (! $item instanceof Node\ArrayItem) {
                continue;
            }

            if ($item->value instanceof Node\Expr\ClassConstFetch
                && $item->value->class instanceof Node\Name
            ) {
                $names[] = $this->resolveClassName($item->value->class, $useMap);
            }
        }

        return $names;
    }

    /**
     * Build an alias => FQCN map from the file's top-level `use` statements
     * (class imports only) so short class references can be resolved.
     *
     * @param  array<Stmt>  $stmts
     * @return array<string, string>
     */
    private function collectUseAliases(array $stmts): array
    {
        $map = [];

        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Stmt\Use_ || $stmt->type !== Stmt\Use_::TYPE_NORMAL) {
                continue;
            }

            foreach ($stmt->uses as $use) {
                $fqcn = $use->name->toString();
                $alias = $use->alias?->toString() ?? $use->name->getLast();
                $map[$alias] = $fqcn;
            }
        }

        return $map;
    }

    /**
     * Resolve a class-reference name to its canonical FQCN (no leading slash).
     *
     * Fully-qualified names are returned as-is; an unqualified name whose first
     * segment matches a `use` alias is expanded against that import; otherwise
     * the name is returned verbatim.
     *
     * @param  array<string, string>  $useMap
     */
    private function resolveClassName(Node\Name $name, array $useMap): string
    {
        if ($name instanceof Node\Name\FullyQualified) {
            return $name->toString();
        }

        $first = $name->getFirst();

        if (isset($useMap[$first])) {
            $rest = array_slice($name->getParts(), 1);

            return $rest === [] ? $useMap[$first] : $useMap[$first].'\\'.implode('\\', $rest);
        }

        return $name->toString();
    }

    // ══════════════════════════════════════════════════════════════════════
    // HASH REGISTRY
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Save hashes of published stub files for update tracking.
     *
     * v13.5.0+: Only app-owned skeleton stubs are tracked. Vendor-runtime files
     * (Domain/FileManager, Domain/Shared, Traits, Helpers/sk-helpers.php,
     * Http/Responses/ApiResponse.php, Http/Middleware/CheckResourcePermission.php,
     * Exceptions/ApiException.php) are NOT in the stubs directory and therefore
     * never appear in this registry. They run from vendor and need no tracking.
     */
    private function saveStubHashes(): void
    {
        $hashFile = config('starter-kit.published_hashes', storage_path('starter-kit/hashes.json'));
        $hashes = [];

        $stubsPath = StarterKitServiceProvider::stubsPath();
        $skipPaths = $this->getSkipPaths();

        foreach ($this->files->allFiles($stubsPath, true) as $file) {
            $relativePath = $file->getRelativePathname();
            $normalizedPath = str_replace('\\', '/', $relativePath);
            $targetPath = base_path($relativePath);

            // Build/generated/junk files are never published and never tracked —
            // skip entirely so the hash registry does not fill with node_modules etc.
            if ($this->isIgnoredStubFile($normalizedPath)) {
                continue;
            }

            // Mark explicitly-skipped paths (e.g. --without-ai-skill) so sk:update
            // knows not to re-add them. Mirrors the '__deleted__' sentinel pattern.
            $skipped = false;
            foreach ($skipPaths as $skipPath) {
                if (str_starts_with($normalizedPath, $skipPath)) {
                    $skipped = true;
                    break;
                }
            }

            if ($skipped) {
                $hashes[$relativePath] = '__skipped__';

                continue;
            }

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
     * Render an absolute path relative to the app base for user-facing messages.
     */
    private function relativePath(string $path): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

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
        } catch (Error $e) {
            $this->components->warn('Could not parse '.$this->relativePath($path).' — automatic edit skipped. ('.$e->getMessage().')');

            return false;
        }

        if ($oldStmts === null) {
            $this->components->warn('Could not parse '.$this->relativePath($path).' — automatic edit skipped.');

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
