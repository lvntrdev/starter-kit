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
use Lvntr\StarterKit\Console\Commands\Concerns\ChecksStepResults;
use Lvntr\StarterKit\Console\Commands\Concerns\ComparesPublishedStubs;
use Lvntr\StarterKit\Console\Commands\Concerns\MirrorsAiSkills;
use Lvntr\StarterKit\Console\Commands\Concerns\RefusesPackageSourceTree;
use Lvntr\StarterKit\Console\Commands\Concerns\WritesFilesAtomically;
use Lvntr\StarterKit\Console\Support\RecipeRegistry;
use Lvntr\StarterKit\StarterKitServiceProvider;
use Lvntr\StarterKit\Support\DocsLink;
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
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class InstallCommand extends Command
{
    use ChecksStepResults;
    use ComparesPublishedStubs;
    use MirrorsAiSkills;
    use RefusesPackageSourceTree;
    use WritesFilesAtomically;

    protected $signature = 'sk:install
        {--force : Overwrite existing files, and bypass the already-installed safety stop}
        {--adopt : Rebuild storage/starter-kit/hashes.json from the shipped stubs for an app that is already installed — copies no file, runs no migration, touches no .env}
        {--dry-run : Print what would be written and exit without writing anything}
        {--without-ai-skill : Skip .claude/skills/ and .codex/skills/ AI skill files}
        {--without-eject : Keep User/Role runtime in vendor (skip default domain eject)}
        {--modules=* : Optional packages (telescope, pulse, sentry) — left empty, prompted interactively in a TTY, skipped when non-interactive}
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

    /**
     * Composer package name, matched against the consumer's composer.lock as one
     * half of an already-installed marker. See detectComposerLockMarkers().
     */
    private const PACKAGE_NAME = 'lvntr/laravel-starter-kit';

    /**
     * Label of the optional-module step. A constant because it is read twice —
     * once to ask the checkpoint whether the step already finished (so a
     * `--resume` does not re-prompt for a selection it already installed), and
     * once by step() itself. Two literals that must match are a bug waiting.
     */
    private const RECIPE_STEP_LABEL = 'Installing optional modules';

    /**
     * Files that ONLY this kit publishes. A stock Laravel application — the
     * shape `laravel new` and `composer create-project` produce — has none of
     * them, so finding one means sk:install has already run here.
     *
     * Deliberately narrow: never app/Models/User.php, never a framework config
     * file. A marker that a fresh project could legitimately own would block
     * the install it exists to protect.
     *
     * @var list<string>
     */
    private const EXISTING_APP_FILE_MARKERS = [
        'app/Providers/DomainServiceProvider.php',
        'config/permission-resources.php',
    ];

    /**
     * Directories only this kit publishes. Same rule as the file markers, plus
     * one more: the directory must actually CONTAIN something. An empty
     * leftover directory carries no work worth protecting and would be a pure
     * false positive.
     *
     * @var list<string>
     */
    private const EXISTING_APP_DIRECTORY_MARKERS = [
        'app/Http/Controllers/Admin',
        // Lowercase `pages` — that is what the stub tree ships. The uppercase
        // spelling this used to carry never matched on a case-sensitive
        // filesystem (macOS masked it), so the marker was dead in production.
        'resources/js/pages/Admin',
    ];

    /**
     * Tables the kit's own migrations create. Their presence in a reachable
     * schema is evidence the kit has been installed and migrated here, which
     * survives a wiped storage/ directory and a fresh clone alike.
     *
     * `permissions` is Spatie's rather than the kit's own, so it can in
     * principle belong to a non-kit application that is installing the kit for
     * the first time. It stays in the list because the stop is recoverable and
     * names the exact table it tripped on — an operator reads one line and
     * decides. Silently ignoring it would trade a recoverable stop for an
     * unrecoverable overwrite.
     *
     * @var list<string>
     */
    private const KIT_SCHEMA_TABLES = [
        'settings',
        // `file_folders`, not `file_manager_folders` — the latter is not a table
        // this kit has ever created, so it could never match. See
        // database/migrations/2026_04_13_100100_create_file_folders_table.php.
        'file_folders',
        'permissions',
    ];

    private Filesystem $files;

    /**
     * --dry-run. Every write this command performs is behind a guard on this
     * flag: the publish loop copies nothing, the checkpoint is not persisted,
     * and the run returns before .env / migrations / seeders / npm are reached.
     * A dry run that mutated anything would be worse than no dry run at all.
     */
    private bool $dryRun = false;

    /** @var list<string> */
    private array $published = [];

    /** @var list<string> */
    private array $skipped = [];

    /**
     * Files whose on-disk copy differs from what the kit recorded shipping, so
     * the publish loop left the consumer's version alone. Reported as its own
     * group at the end of the run: an operator who is not told which files were
     * withheld has no way to notice that their re-install did nothing for them.
     *
     * @var list<string>
     */
    private array $preserved = [];

    /**
     * Default domains successfully ejected during this install run. Drives the
     * end-of-install ownership summary; empty when the eject step was skipped
     * (--without-eject or re-install) or every eject failed.
     *
     * @var list<string>
     */
    private array $ejectedDomains = [];

    /**
     * Recipe keys (see RecipeRegistry) whose composer package AND every
     * post-install command succeeded during this run. Drives the closing
     * summary; a recipe whose package installed but whose post-install command
     * failed deliberately stays out, so the summary never claims a module is
     * ready when it is half-wired — the best-effort failure list reports it.
     *
     * @var list<string>
     */
    private array $installedRecipes = [];

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
     * Labels of best-effort steps that failed. These never fail the command (a
     * machine without Node must still be able to install), but they are listed
     * again in the closing summary so the operator knows what to run by hand.
     *
     * @var list<string>
     */
    private array $bestEffortFailures = [];

    /**
     * Set when a step the install cannot substitute for was skipped rather than
     * run — today only the database block (migrations, seeders, permissions)
     * when the connection is unreachable.
     *
     * It is NOT a best-effort failure: those leave a working application minus
     * an optional convenience, whereas this one leaves an app with no schema, no
     * permissions and no admin user. So it withholds the hash registry, keeps
     * the resume checkpoint, and makes the command exit non-zero — a consumer CI
     * that went green here deployed a shell of an application.
     */
    private bool $installIncomplete = false;

    /**
     * Conflicting default files found on a NON-first install and deliberately
     * left on disk. Reported in the closing summary so the operator knows the
     * kit noticed them and chose not to act.
     *
     * @var list<string>
     */
    private array $conflictingFilesKept = [];

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

    /**
     * The filesystem helper is bound here rather than only in handle() so the
     * detection helpers below are callable — and therefore unit-testable — on a
     * freshly constructed command, the way the existing InstallCommand unit
     * tests instantiate it.
     */
    public function __construct()
    {
        parent::__construct();

        $this->files = new Filesystem;
    }

    public function handle(): int
    {
        if (! $this->option('dry-run') && $this->isPackageSourceTree()) {
            return $this->renderPackageSourceTreeStop();
        }

        $this->files = new Filesystem;
        $this->dryRun = (bool) $this->option('dry-run');

        // A mistyped --modules value is caught HERE, before the first byte is
        // written. Left to RecipeRegistry::get(), it would throw from inside the
        // module step — after the whole scaffold was published — and take the
        // run down over a typo the operator could have fixed in a second.
        if (($unknown = $this->unknownRecipeKeys()) !== []) {
            $this->components->error('Unknown --modules value(s): '.implode(', ', $unknown));
            $this->line('  <fg=gray>Available modules: '.implode(', ', array_keys(RecipeRegistry::all())).'</>');
            $this->newLine();

            return self::FAILURE;
        }

        // --adopt is a registry-repair command wearing the installer's name. It
        // shares nothing with the install path except the stub-hash semantics,
        // so it short-circuits ahead of the banner, the preflight and the
        // checkpoint — none of which apply to it.
        if ($this->option('adopt')) {
            return $this->adoptExistingInstall();
        }

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>Lvntr Starter Kit Installer (v13.7.x)</>');
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

        // A checkpoint file alone is NOT "resuming" — stepAlreadyCompleted()
        // (the actual step-skip logic) already requires --resume too, so a
        // checkpoint left by an install that stalled out (e.g. an unreachable
        // database, see installIncomplete below) and was never explicitly
        // resumed would otherwise silently disable both guards below forever,
        // on every ordinary re-run, while still republishing every file from
        // scratch. Both concerns have to agree on what "resuming" means.
        $resuming = $this->progressExisted && (bool) $this->option('resume');

        // Fail-closed. The hash registry was the ONLY thing separating a first
        // install from a re-run, and it lives under the git-ignored storage/
        // tree: a stateless deploy, a wiped storage/, or a fresh clone loses it.
        // The command then classified a fully installed application as a brand
        // new project — force-publishing over every path and taking the
        // first-install-only branches. So when the registry is missing we ask
        // the application itself whether it has been installed, and stop before
        // the first byte is written if the answer is yes.
        //
        // A resume never reaches the check: the markers it would find are this
        // command's OWN half-finished publish, and stopping there would strand
        // the operator in the middle of an install with no way forward.
        $noHashRegistry = $this->isFirstInstall();
        $existingAppMarkers = $noHashRegistry && ! $resuming
            ? $this->detectExistingApp()
            : [];

        if ($existingAppMarkers !== []) {
            if (! $this->option('force')) {
                $this->renderExistingAppStop($existingAppMarkers);

                return self::FAILURE;
            }

            $this->renderForcedOverExistingApp($existingAppMarkers);
        }

        // The hash registry existing (! $noHashRegistry) means this app really
        // was installed before and this is not a resumed run — a genuine
        // re-run, which republishes files. That is a legitimate repair path
        // (a registry-repair is what --adopt is for; a full re-run is for
        // pulling in first-install-only publishing this app never got), but an
        // operator who fat-fingers `sk:install` on a working app should not
        // have it silently start rewriting files. Ask first, unless they
        // already opted in via --force. A --dry-run writes nothing at all
        // (it stops before the first byte, below), so there is nothing to
        // confirm — asking, or refusing under --no-interaction, would block a
        // read-only preview for no reason.
        if (! $noHashRegistry && ! $resuming) {
            if ($this->option('force')) {
                $this->components->warn('--force: skipping the already-installed confirmation.');
                $this->newLine();
            } elseif (! $this->dryRun && ! $this->confirmReinstall()) {
                return self::FAILURE;
            }
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
            $noHashRegistry,
            $existingAppMarkers !== [],
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

            // --dry-run stops here, before the first byte is written. Everything
            // past this point (.env, database config, migrations, seeders, npm,
            // the hash registry) mutates the application, so a dry run that kept
            // going would not be dry.
            if ($this->dryRun) {
                return $this->renderDryRunPlan();
            }

            // 1b. Merge package.json (stub wins for shared deps, user extras preserved)
            $this->step('Merging package.json', function () {
                $this->mergePackageJson();
            });

            // 1c. Seed .env from the freshly published .env.example so consumers
            // get every kit key without copying by hand, then generate APP_KEY
            // when it is blank. Runs before the database step so DB_* values are
            // written into an already-seeded .env.
            $this->step('Ensuring .env file', function () use ($isFirstInstall) {
                if (! $this->ensureEnvFile($isFirstInstall)) {
                    return false;
                }

                // The dedicated data key is generated HERE, not at the end of
                // the install: step 8 runs the seeders, and _03_SettingSeeder
                // encrypts mail/storage secrets through SettingService. A key
                // created after that leaves those rows written under APP_KEY
                // while .env advertises a dedicated key — the app then looks
                // protected, and the first `key:generate` on a server move
                // destroys exactly the values this feature exists to keep.
                // Guarded and first-install-only, so a re-run is a no-op.
                $this->ensureDataEncryptionKey(base_path('.env'), $isFirstInstall);

                return true;
            });

            // 2. Database configuration (writes DB_* into the now-seeded .env)
            $this->configureDatabaseStep();

            // 3. Remove conflicting default Laravel files — FIRST INSTALL ONLY.
            //
            // On a fresh project these are stock-Laravel leftovers the kit
            // replaces, and deleting them costs nothing. On a re-install or an
            // update they are live project artifacts: package-lock.json pins the
            // consumer's whole dependency tree, vite.config.* carries whatever
            // build config they added, and resources/js/app.js may be their own
            // entry point. Deleting those unconditionally destroyed work the
            // installer had no mandate over, so a non-first run reports them and
            // leaves the decision with the operator.
            $this->step('Removing conflicting default files', function () use ($isFirstInstall) {
                $this->removeConflictingDefaults($isFirstInstall);
            });

            // 3b. Ensure .gitignore entries — merge the kit's ignore set into the
            // project's existing .gitignore without dropping any current lines.
            $this->step('Ensuring .gitignore entries', function () {
                $this->ensureGitignore();
            });

            // 4. Publish config
            $this->step('Publishing configuration', function () {
                // Core starter-kit config
                if (! $this->runArtisan('vendor:publish', [
                    '--tag' => 'starter-kit-config',
                    '--force' => $this->option('force'),
                ])) {
                    return false;
                }

                // FileManager config — vendor runtime reads its defaults from here.
                // Published separately so users can override file-manager.php settings
                // (allowed mime types, max size, model bindings) without touching vendor code.
                return $this->runArtisan('vendor:publish', [
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

            // 5b. Optional observability modules (Telescope / Pulse / Sentry).
            //
            // Ordering is load-bearing: the `composer require` has to happen
            // BEFORE the autoload dump (step 6) and the migration step (step 7),
            // so a recipe package's own migrations are on disk and discovered by
            // the migrate run this install performs — not left for the operator.
            //
            // Best-effort by design: composer needs the network and the module is
            // optional, so a failure warns and the install carries on (same
            // contract as the autoload dump right below).
            //
            // --dry-run never reaches here — it returns at the publish step
            // above; renderDryRunPlan() reports the selection instead of running
            // it. And on a --resume that already completed this step, the
            // selection is not re-asked at all: prompting for a choice that will
            // then be skipped is pure noise.
            $recipes = $this->stepAlreadyCompleted(self::RECIPE_STEP_LABEL, (bool) $this->option('resume'), $this->completedSteps)
                ? []
                : $this->selectedRecipes();

            if ($recipes !== []) {
                $this->step(self::RECIPE_STEP_LABEL, fn (): bool => $this->installRecipes($recipes), mandatory: false);
            }

            // 6. Regenerate autoload so published classes are available for migrations/seeders
            //
            // Best-effort on purpose: composer is not guaranteed to be on PATH of
            // the machine running the install (deploy images routinely drop it),
            // and today's installs survive that. If the dump really was needed,
            // the seeder step right after fails loudly on the missing class.
            $this->step('Regenerating autoload', function () {
                $composer = $this->findComposerBinary();
                $succeeded = $this->runProcessStep([...$composer, 'dump-autoload', '-q'], timeout: 120);

                // Reload the in-process autoloader so newly published classes (e.g. App\Enums\RoleEnum)
                // are discoverable during the seeder step that runs in the same PHP process.
                $this->refreshAutoloader();

                if (! $succeeded) {
                    $this->stepFailureDetail .= PHP_EOL.'  Run `composer dump-autoload` by hand before the seeders.';

                    return false;
                }

                return true;
            }, mandatory: false);

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
                        return $this->runArtisan('sk:seed-permissions', ['--fresh' => true]);
                    });
                }
            } else {
                // Skipping these leaves an application with no schema. The run
                // continues (the remaining filesystem work is still worth
                // doing), but it is now an INCOMPLETE install: no hash registry,
                // no checkpoint clear, and a non-zero exit code at the end.
                $this->installIncomplete = true;

                $this->newLine();
                $this->components->warn('Database is not reachable — skipping migrations, seeders, and permission seeding.');
                $this->line('  <fg=gray>Create/fix the database connection, then run: php artisan sk:install --resume</>');
                $this->newLine();
            }

            // 10. Passport keys + personal access client
            if ($this->confirmStep('Generate Passport encryption keys?')) {
                $this->step('Generating Passport keys', function () {
                    return $this->runArtisan('passport:keys', ['--force' => true]);
                });
                $this->step('Creating Passport personal access client', function () {
                    return $this->runArtisan('passport:client', ['--personal' => true, '--name' => config('app.name').' Personal Access Client', '--provider' => 'users', '--no-interaction' => true]);
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
                $this->installFrontend($isFirstInstall);
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
                if (! $this->ensureAppKey(base_path('.env'))) {
                    return false;
                }

                $this->ensureDataEncryptionKey(base_path('.env'), $isFirstInstall);

                return true;
            });

            // 13. Save stub hashes for update tracking.
            //
            // Withheld on an incomplete install. The registry is the marker that
            // says "this application is installed" — writing it over a run that
            // never migrated would make the NEXT sk:install read a half-installed
            // app as a finished one and skip the guard that protects it.
            if (! $this->installIncomplete) {
                $this->saveStubHashes();
            }

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

        return $this->renderInstallSummary();
    }

    /**
     * Close the run: settle the checkpoint, print the summary, and return the
     * exit code the operator's shell (and CI) will see.
     *
     * Kept out of handle() because it is the whole fail-closed verdict in one
     * place — checkpoint retention, registry suppression and exit code have to
     * agree, and they are only assertable together.
     */
    private function renderInstallSummary(): int
    {
        // Install completed end-to-end — drop the checkpoint so the next run is
        // treated as a clean re-install, not a resume.
        //
        // An INCOMPLETE run keeps its checkpoint on purpose: the filesystem
        // steps that did finish stay recorded, so the `--resume` this command is
        // about to recommend genuinely resumes instead of republishing over
        // files the operator may have edited in the meantime.
        if (! $this->installIncomplete) {
            $this->clearProgress();
        }

        // Summary
        $this->newLine();
        if ($this->installIncomplete) {
            $this->components->error('Lvntr Starter Kit install is INCOMPLETE — the database steps did not run.');
        } else {
            $this->components->info('Lvntr Starter Kit installed successfully!');
        }
        $this->newLine();

        if (! empty($this->published)) {
            $this->components->twoColumnDetail('<fg=green>Published</>', count($this->published).' files');
        }
        if (! empty($this->skipped)) {
            $this->components->twoColumnDetail('<fg=yellow>Skipped</>', count($this->skipped).' files (already exist, use --force to overwrite)');
        }

        $this->printPreservedFiles();

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

        if ($this->installedRecipes !== []) {
            $this->newLine();
            $this->components->twoColumnDetail(
                '<fg=green>Optional modules</>',
                implode(', ', $this->installedRecipes),
            );
            $this->line('  <fg=gray>Review each package\'s own config/env before using it in production.</>');
        }

        // Non-fatal failures did not stop the install, so they are repeated here
        // — the closing screen is the only place the operator still reads.
        if ($this->bestEffortFailures !== []) {
            $this->newLine();
            $this->components->warn('These optional steps failed and were skipped:');
            foreach ($this->bestEffortFailures as $failed) {
                $this->line("  <fg=yellow>- {$failed}</>");
            }
        }

        $this->printConflictingFilesKept();

        $this->newLine();
        $this->components->warn('Run the following commands to ensure all components work correctly:');
        $this->line('  <fg=cyan>npm install && npm run build</>');
        $this->newLine();

        // Last thing on screen, and the exit code to match: whatever scrolled
        // past, the operator and their CI must both read this run as unfinished.
        if ($this->installIncomplete) {
            $this->renderIncompleteInstall();

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Delete the stock-Laravel files the kit stubs replace — on a FIRST install
     * only; on any other run record them for the closing report instead.
     *
     * The distinction is not cosmetic. package-lock.json pins the consumer's
     * entire dependency tree, vite.config.* carries whatever build config they
     * added, and resources/js/app.js may be their own entry point. Deleting
     * those on a re-install or an update destroyed work the installer has no
     * mandate over, and it happened silently.
     */
    private function removeConflictingDefaults(bool $isFirstInstall): void
    {
        $present = array_values(array_filter(
            $this->conflictingFiles,
            fn (string $file): bool => $this->files->exists(base_path($file)),
        ));

        if (! $isFirstInstall) {
            $this->conflictingFilesKept = $present;

            return;
        }

        foreach ($present as $file) {
            $this->files->delete(base_path($file));
        }
    }

    /**
     * Closing report for a run whose filesystem work finished but whose database
     * work never ran.
     *
     * The exit code is the load-bearing half of this: a consumer pipeline that
     * saw 0 here shipped an application with no schema, no permissions and no
     * admin user. The screen text exists so the operator reading the terminal
     * reaches the same conclusion the pipeline does.
     */
    private function renderIncompleteInstall(): void
    {
        $this->line('  <fg=yellow>Still missing:</>');
        $this->line('  <fg=gray>  migrations, seeders and permission seeding — the database was unreachable.</>');
        $this->line('  <fg=gray>  The update tracking registry was NOT written, and the resume checkpoint was</>');
        $this->line('  <fg=gray>  kept, so the steps that did finish will not be redone.</>');
        $this->newLine();
        $this->line('  <fg=yellow>Create/fix the database connection, then run:</>');
        $this->line('  <fg=cyan>php artisan sk:install --resume</>');
        $this->newLine();
        $this->line('  <fg=red;options=bold>Install INCOMPLETE — exiting non-zero.</>');
        $this->newLine();
    }

    /**
     * Report the stock-Laravel files a re-install found and deliberately did not
     * delete. Silence here would read as "there was nothing to do", which is the
     * opposite of the truth: the operator is the one who has to decide whether
     * their package-lock.json / vite.config still belongs next to the kit's.
     */
    private function printConflictingFilesKept(): void
    {
        if ($this->conflictingFilesKept === []) {
            return;
        }

        $this->newLine();
        $this->components->twoColumnDetail(
            '<fg=yellow>Kept</>',
            count($this->conflictingFilesKept).' default Laravel file(s) that conflict with the kit stubs',
        );

        foreach ($this->conflictingFilesKept as $path) {
            $this->line("  <fg=yellow>~</> {$path}");
        }

        $this->newLine();
        $this->line('  <fg=gray>Only a FIRST install deletes these — on a re-run they may be yours.</>');
        $this->line('  <fg=gray>Delete by hand if they are stock Laravel leftovers you no longer need.</>');
    }

    // ══════════════════════════════════════════════════════════════════════
    // STEP RUNNER
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Run a step with simple before/after output (no spinner).
     *
     * The callback's return value is the step's verdict: `false` means the step
     * failed. Anything else (including the `null` of a callback that only throws
     * on error) is success — that is what keeps every legacy step working.
     *
     * A failed MANDATORY step aborts the run: it throws, which the handle()
     * catch turns into `renderStepFailure()` + `self::FAILURE`. Because it never
     * reaches markStepComplete(), the checkpoint keeps the step pending and the
     * hash registry / clearProgress() at the end of handle() never run — a
     * failed install stays resumable instead of being recorded as done.
     *
     * A failed BEST-EFFORT step (frontend work) warns, is remembered for the
     * closing summary, and lets the install continue with its exit code intact.
     *
     * @return bool Whether the step succeeded.
     */
    private function step(string $label, callable $callback, bool $mandatory = true): bool
    {
        if ($this->stepAlreadyCompleted($label, (bool) $this->option('resume'), $this->completedSteps)) {
            $this->components->twoColumnDetail($label, '<fg=gray>SKIPPED (resume)</>');

            return true;
        }

        $this->currentStep = $label;
        $this->stepFailureDetail = null;
        $this->line("  <fg=gray>→</> {$label}...");

        $result = $callback();

        if ($result === false) {
            $detail = $this->stepFailureDetail ?? 'The step reported a failure.';
            $this->stepFailureDetail = null;

            if ($mandatory) {
                $this->components->twoColumnDetail($label, '<fg=red>FAILED</>');

                // Caught by handle()'s try/catch: renders the failed step + the
                // resume command and returns self::FAILURE.
                throw new \RuntimeException($detail);
            }

            $this->components->twoColumnDetail($label, '<fg=yellow>FAILED (non-fatal)</>');
            $this->line('  <fg=yellow>'.$detail.'</>');
            $this->bestEffortFailures[] = $label;
            $this->currentStep = null;

            return false;
        }

        $this->components->twoColumnDetail($label, '<fg=green>DONE</>');

        $this->markStepComplete($label);
        $this->currentStep = null;

        return true;
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
     *
     * Atomic for the same reason the registry is: this file is written between
     * steps precisely so an interruption is survivable, and the interruption
     * most likely to truncate it is one that lands DURING the write. A
     * half-written checkpoint parses as `null`, loadProgress() reads it as a
     * clean start, and the resume then redoes steps that already ran.
     */
    private function persistProgress(): void
    {
        if ($this->dryRun) {
            return;
        }

        $this->atomicPut($this->progressFilePath(), json_encode([
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
        if ($this->dryRun) {
            return;
        }

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
     * it keeps ejecting the default domains it started with.
     *
     * On a clean run the absence of the hash registry is no longer enough on its
     * own: the registry is git-ignored and routinely lost, so it is now the
     * absence of the registry AND the absence of any evidence that the kit has
     * already been installed here. That pairing is what keeps a --force run over
     * a detected installation out of the first-install-only branches — the eject,
     * the FIRST_INSTALL_ONLY env seeding and the dedicated-key generation all
     * hang off this flag, and every one of them is wrong on an app that already
     * has data.
     *
     * @param  array<string, mixed>  $meta
     */
    private function computeFirstInstall(
        bool $progressExisted,
        array $meta,
        bool $noHashRegistry,
        bool $existingAppDetected = false,
    ): bool {
        if ($progressExisted) {
            return (bool) ($meta['first_install'] ?? false);
        }

        return $noHashRegistry && ! $existingAppDetected;
    }

    // ══════════════════════════════════════════════════════════════════════
    // EXISTING-APPLICATION DETECTION (fail-closed)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Observable evidence that the kit has ALREADY been installed into this
     * application, independent of the git-ignored hash registry.
     *
     * Each returned string is a complete, operator-readable sentence naming the
     * exact path or table it tripped on, because the whole value of the stop is
     * that it can be judged in one read: either the operator recognises the file
     * as their own installed app, or they see a coincidence and re-run with
     * --force.
     *
     * Any single marker is sufficient. They are cheap and ordered cheapest
     * first, but all of them are collected rather than short-circuited — a stop
     * that names one file when four exist reads like a false positive.
     *
     * @return list<string>
     */
    private function detectExistingApp(): array
    {
        return array_merge(
            $this->detectPublishedTargetMarkers(base_path()),
            $this->detectComposerLockMarkers(base_path()),
            $this->detectKitSchemaMarkers(),
        );
    }

    /**
     * Published files/directories that only this kit creates. Takes the base
     * path as an argument rather than calling base_path() so it is testable
     * against a temporary directory.
     *
     * @return list<string>
     */
    private function detectPublishedTargetMarkers(string $basePath): array
    {
        $base = rtrim(str_replace('\\', '/', $basePath), '/');
        $markers = [];

        foreach (self::EXISTING_APP_FILE_MARKERS as $relative) {
            if ($this->files->isFile($base.'/'.$relative)) {
                $markers[] = $relative.' — published by sk:install; a stock Laravel app has no such file';
            }
        }

        foreach (self::EXISTING_APP_DIRECTORY_MARKERS as $relative) {
            $path = $base.'/'.$relative;

            if (! $this->files->isDirectory($path)) {
                continue;
            }

            $count = $this->countFilesUnder($path);

            if ($count === 0) {
                continue;
            }

            $markers[] = $count === null
                ? $relative.'/ — published by sk:install; present but not readable for inspection'
                : sprintf(
                    '%s/ — published by sk:install; %d file(s) present, a stock Laravel app has no such directory',
                    $relative,
                    $count,
                );
        }

        return $markers;
    }

    /**
     * Number of files under $path, or null when the directory exists but cannot
     * be read.
     *
     * Null is deliberately not folded into 0. A directory the installer cannot
     * inspect is not evidence of absence, and an unhandled iterator exception
     * here would surface as a stack trace ahead of the install's own error
     * handling. Callers treat null as "present" — the fail-closed reading.
     */
    private function countFilesUnder(string $path): ?int
    {
        try {
            return count($this->files->allFiles($path, true));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The kit's own DDD tree under app/Domain/ alongside a composer.lock entry
     * for the package.
     *
     * The composer.lock entry alone proves nothing — a FIRST install is by
     * definition run right after `composer require`, so the entry is always
     * there. app/Domain/ is what makes the pair specific: a stock Laravel
     * application has no such directory, and this kit is what puts one there
     * (published stubs plus the User/Role eject). Requiring both keeps the
     * marker off a project that merely required the package and has not
     * installed it yet.
     *
     * @return list<string>
     */
    private function detectComposerLockMarkers(string $basePath): array
    {
        $base = rtrim(str_replace('\\', '/', $basePath), '/');
        $lockPath = $base.'/composer.lock';
        $domainPath = $base.'/app/Domain';

        if (! $this->files->isFile($lockPath) || ! $this->files->isDirectory($domainPath)) {
            return [];
        }

        $domainFiles = $this->countFilesUnder($domainPath);

        if ($domainFiles === 0) {
            return [];
        }

        try {
            $lock = $this->files->get($lockPath);
        } catch (\Throwable) {
            // An unreadable composer.lock leaves this marker unprovable. The
            // published-target and schema markers are unaffected, so the guard
            // still has evidence to work from.
            return [];
        }

        if (! str_contains($lock, '"'.self::PACKAGE_NAME.'"')) {
            return [];
        }

        return [sprintf(
            'app/Domain/ — %s of kit domain code, with composer.lock listing %s',
            $domainFiles === null ? 'present but not readable for inspection' : $domainFiles.' file(s)',
            self::PACKAGE_NAME,
        )];
    }

    /**
     * Kit tables in a reachable schema.
     *
     * An UNREACHABLE database is never an error and never a marker: the install
     * is frequently run before DB_* is configured at all, and turning that into
     * a hard stop would break the ordinary first install this guard exists to
     * protect. Every driver failure — no credentials, no server, no driver
     * extension, a database that does not exist yet — lands in the same catch
     * and yields "no evidence".
     *
     * @return list<string>
     */
    private function detectKitSchemaMarkers(): array
    {
        try {
            $connection = DB::connection();
            $connection->getPdo();
        } catch (\Throwable) {
            return [];
        }

        $markers = [];

        foreach (self::KIT_SCHEMA_TABLES as $table) {
            try {
                if (Schema::hasTable($table)) {
                    $markers[] = sprintf(
                        'database table `%s` on connection `%s` — created by the kit\'s migrations',
                        $table,
                        $connection->getName(),
                    );
                }
            } catch (\Throwable) {
                // The connection opened but the schema is not introspectable
                // (permissions, a dropped database mid-check). Report what was
                // found so far rather than inventing evidence either way.
                return $markers;
            }
        }

        return $markers;
    }

    /**
     * Refuse the install and tell the operator exactly what was found and which
     * command they actually want. Nothing has been written at this point.
     *
     * @param  list<string>  $markers
     */
    private function renderExistingAppStop(array $markers): void
    {
        $this->newLine();
        $this->components->error('This application already looks installed — nothing was written.');
        $this->newLine();

        $this->line('  <fg=yellow>Evidence found:</>');
        foreach ($markers as $marker) {
            $this->line('    <fg=red>•</> <fg=white>'.$marker.'</>');
        }

        $this->newLine();
        $this->line('  <fg=gray>The tracking registry is missing:</>');
        $this->line('  <fg=gray>  '.$this->hashRegistryPath().'</>');
        $this->line('  <fg=gray>That file is how sk:install tells a first install from a re-run, and it is</>');
        $this->line('  <fg=gray>git-ignored — a stateless deploy or a cleared storage/ directory loses it.</>');
        $this->line('  <fg=gray>Continuing would publish over the paths above and take the first-install-only</>');
        $this->line('  <fg=gray>branches (default-domain eject, first-install .env seeding) on an app that</>');
        $this->line('  <fg=gray>already has data.</>');

        $this->newLine();
        $this->line('  <fg=yellow>What you probably want:</>');
        $this->components->twoColumnDetail(
            '<fg=cyan>php artisan sk:update</>',
            '<fg=gray>update an app that is already installed</>',
        );
        $this->components->twoColumnDetail(
            '<fg=cyan>php artisan sk:install --adopt</>',
            '<fg=gray>rebuild the registry only — copies no file, runs no migration, touches no .env</>',
        );
        $this->components->twoColumnDetail(
            '<fg=cyan>php artisan sk:install --adopt --dry-run</>',
            '<fg=gray>show what --adopt would record, write nothing</>',
        );

        $this->newLine();
        $this->line('  <fg=yellow>If this really is a fresh project and the evidence is a coincidence:</>');
        $this->line('  <fg=gray>remove or rename the exact path(s)/table(s) named above, then re-run</>');
        $this->line('  <fg=gray>sk:install normally — that keeps the full first-install behaviour.</>');
        $this->line('  <fg=gray>--force also proceeds, but read it as "overwrite the paths listed above", and</>');
        $this->line('  <fg=gray>note that a forced run is NOT treated as a first install.</>');
        $this->newLine();
    }

    /**
     * --force over a detected installation. The operator asked for it, so the
     * run continues — but the paths that are about to be overwritten are named
     * first, and loudly.
     *
     * @param  list<string>  $markers
     */
    private function renderForcedOverExistingApp(array $markers): void
    {
        $this->newLine();
        $this->components->warn('--force: publishing over an application that already looks installed.');
        $this->newLine();

        $this->line('  <fg=yellow>These will be overwritten:</>');
        foreach ($markers as $marker) {
            $this->line('    <fg=red>•</> <fg=white>'.$marker.'</>');
        }

        $this->newLine();
        $this->line('  <fg=gray>Every published path except lang/ is replaced with the shipped stub.</>');
        $this->line('  <fg=gray>Your .env is still never overwritten, and because the kit was detected here</>');
        $this->line('  <fg=gray>this run is NOT treated as a first install: no default-domain eject and no</>');
        $this->line('  <fg=gray>first-install-only .env seeding.</>');
        $this->newLine();
    }

    /**
     * The hash registry says this app was already installed. Ask before
     * republishing over it — fail-closed under --no-interaction, since a
     * silent "yes" here is exactly the accidental-run risk this guards.
     */
    private function confirmReinstall(): bool
    {
        $this->newLine();
        $this->components->warn('This application is already installed — sk:install will republish files here.');
        $this->newLine();
        $this->line('  <fg=gray>Hash registry found at:</>');
        $this->line('  <fg=gray>  '.$this->hashRegistryPath().'</>');
        $this->line('  <fg=gray>Routine updates should use `php artisan sk:update` instead — it is the</>');
        $this->line('  <fg=gray>hash-tracked path that refreshes stubs without clobbering your edits.</>');
        $this->newLine();

        if ($this->option('no-interaction')) {
            $this->components->error('Refusing to re-install without confirmation. Pass --force to proceed non-interactively.');

            return false;
        }

        return confirm('Continue and republish over this installed application?', default: false);
    }

    /**
     * Report the files the publish loop refused to overwrite, as one group.
     *
     * The count alone is useless here — the operator needs the paths, because
     * the whole point of preserving a file is that they now have to decide
     * whether the version they kept is missing something the release shipped.
     * One line of instruction, not a paragraph: diff against the stub, or take
     * the packaged version knowingly — for everything (--force) or for the one
     * area they care about (sk:publish --tag).
     *
     * Two populations land here and read the same way to the operator: a file
     * they edited after the kit shipped it, and a file of their own at a path
     * the kit has only now started shipping to. In both cases the copy on disk
     * differs from the packaged version and the packaged version was not
     * written, which is why the wording talks about the difference rather than
     * about who made it.
     */
    private function printPreservedFiles(bool $dryRun = false): void
    {
        if ($this->preserved === []) {
            return;
        }

        $verb = $dryRun ? 'Would preserve' : 'Preserved';

        $this->newLine();
        $this->components->twoColumnDetail(
            "<fg=yellow>{$verb}</>",
            count($this->preserved).' files that differ from the packaged version (it was NOT written)',
        );

        foreach ($this->preserved as $path) {
            $this->line("  <fg=yellow>~</> {$path}");
        }

        $this->newLine();
        $this->line('  <fg=gray>Diff each against the same relative path under vendor/lvntr/laravel-starter-kit/stubs/</>');
        $this->line('  <fg=gray>and merge by hand, or take the packaged version and DISCARD what is on disk with</>');
        $this->line('  <fg=gray>`php artisan sk:install --force` (all of them) or</>');
        // {area} rather than <area>: the console formatter reads angle brackets
        // as style tags, and a placeholder is not worth the ambiguity.
        $this->line('  <fg=gray>`php artisan sk:publish --tag={area} --force` (one area only).</>');
    }

    /**
     * Report what a --dry-run install WOULD have published, having written
     * nothing.
     */
    private function renderDryRunPlan(): int
    {
        $this->newLine();
        $this->components->info('Dry run — nothing was written.');
        $this->newLine();

        $this->components->twoColumnDetail('<fg=green>Would publish</>', count($this->published).' files');
        $this->components->twoColumnDetail('<fg=yellow>Would skip</>', count($this->skipped).' files (preserved or intentionally deleted)');

        $this->printPreservedFiles(dryRun: true);

        // Only what --modules= named: a dry run must not open an interactive
        // prompt, so the selection is never asked for here.
        if (($recipes = $this->recipeKeysFromOption()) !== []) {
            $this->newLine();
            $this->components->twoColumnDetail(
                '<fg=green>Would install optional modules</>',
                implode(', ', $recipes),
            );
        }

        $this->newLine();
        $this->line('  <fg=gray>No .env change, no database configuration, no migration, no seeder,</>');
        $this->line('  <fg=gray>no npm install, and no hash-registry write were performed.</>');
        $this->line('  <fg=gray>Re-run without --dry-run to install.</>');
        $this->newLine();

        return self::SUCCESS;
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

        // Single closing line: whatever scrolled past, the last thing on screen
        // names the failed step and the exact command that continues the run.
        $this->line("  <fg=red;options=bold>Install failed at step \"{$step}\" — resume with `php artisan sk:install --resume`.</>");
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
     * Rewrites one line per key and leaves every other byte of the file alone;
     * the whole body is only ever handed to the atomic writer, never truncated
     * in place. The seed branch is reachable only when .env does not exist —
     * this method must not be able to replace an existing file either.
     *
     * @param  array<string, string>  $values
     */
    private function updateEnvFile(array $values): void
    {
        $envPath = base_path('.env');

        if (! $this->files->exists($envPath)) {
            $examplePath = base_path('.env.example');
            $this->putEnvAtomically(
                $envPath,
                $this->files->exists($examplePath) ? $this->files->get($examplePath) : '',
            );
        }

        $content = $this->files->get($envPath);

        foreach ($values as $key => $value) {
            // Wrap value in quotes if it contains spaces or is empty
            $escapedValue = $value;
            if ($value === '' || str_contains($value, ' ') || str_contains($value, '#')) {
                $escapedValue = "\"{$value}\"";
            }

            if (preg_match("/^{$key}=.*/m", $content)) {
                // Replaced through a callback, never as a replacement STRING: a
                // password containing $1 or \1 would otherwise be read as a
                // backreference and silently written to disk mangled (or empty),
                // locking the operator out of their own database. Same reasoning
                // as replaceOrAppendEnvLine() below.
                $content = preg_replace_callback(
                    "/^{$key}=.*/m",
                    static fn (): string => "{$key}={$escapedValue}",
                    $content,
                ) ?? $content;
            } else {
                // Add new key
                $content .= "\n{$key}={$escapedValue}";
            }
        }

        $this->putEnvAtomically($envPath, $content);
    }

    /**
     * Ensure the consumer's .env exists and carries every key the kit ships in
     * .env.example.
     *
     * AN EXISTING .env IS NEVER OVERWRITTEN — not on a re-install, and not on a
     * first install either. It used to be: a first install copied .env.example
     * over whatever was there, which on the ordinary `composer create-project`
     * shape (a Laravel app that already has a .env) destroyed the operator's
     * DB_PASSWORD and, far worse, their APP_KEY — and APP_KEY is what every
     * already-encrypted row and every live session is readable through. There
     * is no undo for that and no copy of the value anywhere else, so the copy
     * branch is now reachable only when the file genuinely does not exist.
     *
     * The first-install intent survives without the overwrite: $isFirstInstall
     * is handed to the merge, which seeds the FIRST_INSTALL_ONLY_ENV_KEYS a
     * brand-new project is supposed to start with — but only where the key is
     * absent. No existing key's value is ever rewritten on either path.
     *
     * Finally APP_KEY is generated when blank so the app boots.
     *
     * @return bool False only when APP_KEY generation itself failed — an app
     *              without an APP_KEY cannot boot, let alone encrypt anything,
     *              so the caller treats it as a failed step.
     */
    private function ensureEnvFile(bool $isFirstInstall = false): bool
    {
        $envPath = base_path('.env');
        $examplePath = base_path('.env.example');

        // Nothing to seed from. The publish step is expected to have placed the
        // kit's .env.example, so reaching here means a malformed install.
        if (! $this->files->exists($examplePath)) {
            return true;
        }

        if ($this->files->exists($envPath)) {
            $this->mergeMissingEnvKeys($envPath, $examplePath, $isFirstInstall);
        } else {
            // Seeded atomically as well: a half-written .env left behind by an
            // interrupted run would count as "present" on the next attempt and
            // never be re-seeded, which is the same dead end from the other
            // direction.
            $this->putEnvAtomically($envPath, $this->files->get($examplePath));
        }

        if (! $this->ensureAppKey($envPath)) {
            return false;
        }

        $this->ensureCachePrefix($envPath);

        return true;
    }

    /**
     * Write $content to $path through a temp file in the SAME directory, then
     * rename() it over the target.
     *
     * Every .env writer in this command goes through here. A plain put() opens
     * the real file with O_TRUNC: an install interrupted between the truncate
     * and the write (Ctrl-C, OOM kill, a full disk) leaves a truncated or empty
     * .env, and the credentials that were in it are gone. rename() within one
     * filesystem is atomic, so the file on disk is only ever the old body or
     * the complete new one.
     *
     * The mode of the existing file is carried over; a file being created gets
     * 0600, and the temp file is chmod-ed before the body is written so the
     * credentials are never briefly readable under a permissive umask.
     *
     * A SYMLINKED .env is followed, not replaced. Zero-downtime deployers
     * (Envoyer, Deployer, Capistrano) symlink the release's .env at one shared
     * file; rename() would swap out the LINK, leaving the shared credentials
     * orphaned and this release reading a private copy that the next deploy
     * never sees. realpath() resolves to the file the operator actually
     * maintains and the swap happens there — same directory, so still one
     * filesystem and still atomic.
     */
    private function putEnvAtomically(string $path, string $content): void
    {
        // false for a path that does not exist yet (and for a dangling link,
        // where there is no target to write through anyway).
        $target = realpath($path) ?: $path;

        $temp = dirname($target).'/.env.sk-tmp-'.bin2hex(random_bytes(8));

        $targetExists = $this->files->exists($target);

        $perms = $targetExists ? @fileperms($target) : false;
        $mode = $perms === false ? 0600 : ($perms & 0777);

        // Ownership has to be carried too, not just the mode. A `sudo php
        // artisan sk:install` over an app whose .env is www-data:www-data would
        // otherwise rename a root-owned file into place and the web user could
        // no longer read it — the in-place put() this writer replaced preserved
        // the owner implicitly, because it never created a new inode.
        $owner = $targetExists ? @fileowner($target) : false;
        $group = $targetExists ? @filegroup($target) : false;

        try {
            // 'x' fails rather than clobbering, so a colliding name can never
            // cost us a file we did not create.
            $handle = @fopen($temp, 'x');

            if ($handle === false) {
                throw new \RuntimeException('Could not create a temporary .env file at ['.$temp.'].');
            }

            try {
                @chmod($temp, $mode);

                // Best-effort: only root can hand a file to another user, and a
                // non-root run already owns the file it is replacing.
                if ($group !== false) {
                    @chgrp($temp, $group);
                }

                if ($owner !== false) {
                    @chown($temp, $owner);
                }

                if (fwrite($handle, $content) !== strlen($content)) {
                    throw new \RuntimeException('Could not write the temporary .env file at ['.$temp.'].');
                }

                // Flush userland + kernel buffers before the rename, so a crash
                // right after it cannot leave a correctly named but empty file.
                fflush($handle);
                @fsync($handle);
            } finally {
                fclose($handle);
            }

            if (! @rename($temp, $target)) {
                throw new \RuntimeException('Could not move the temporary .env file into place at ['.$target.'].');
            }
        } finally {
            // Only reachable when the rename did not happen; a successful
            // rename leaves nothing behind.
            if ($this->files->exists($temp)) {
                $this->files->delete($temp);
            }
        }
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

        $this->putEnvAtomically($envPath, $content);
    }

    /**
     * Append keys present in .env.example but absent from .env, preserving the
     * user's existing lines and values. Comment/blank lines are ignored when
     * detecting keys; the missing lines are copied verbatim so their inline
     * comments and defaults survive.
     *
     * $isFirstInstall lifts the FIRST_INSTALL_ONLY_ENV_KEYS skip — see that
     * constant. It only ever adds an absent key; nothing existing is rewritten
     * on either path.
     */
    private function mergeMissingEnvKeys(string $envPath, string $examplePath, bool $isFirstInstall = false): void
    {
        $current = $this->files->get($envPath);

        $merged = $this->buildMergedEnvContent(
            $current,
            $this->files->get($examplePath),
            $isFirstInstall,
        );

        if ($merged === null) {
            return;
        }

        $this->putEnvAtomically($envPath, $merged);

        // Key NAMES only. The added lines come from .env.example, so their
        // values are the shipped defaults rather than anything sensitive, but
        // printing a name is the whole point here and printing a value never is.
        $added = array_keys(array_diff_key(
            $this->parseEnvKeys($merged),
            $this->parseEnvKeys($current),
        ));

        if ($added !== []) {
            $this->components->info(sprintf(
                'Added %d missing key(s) to .env (existing values untouched): %s',
                count($added),
                implode(', ', $added),
            ));
        }
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
     * A key listed here therefore reaches a fresh install and no one else. It
     * used to get there because a first install copied .env.example over the
     * file; now that an existing .env is never overwritten, the same keys are
     * seeded by the merge under the $isFirstInstall flag, and only where the
     * key is absent — an operator who already set one keeps their value. An
     * existing app opts in by writing the line itself — see docs/UPGRADE.md.
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
     * STARTER_KIT_CSP_NONCE is the same shape one layer down the stack: it
     * swaps script-src `'unsafe-inline'` for a per-request nonce, and a browser
     * stops honouring `'unsafe-inline'` the instant a nonce appears. A fresh
     * project is safe because the `app.blade.php` published beside this .env
     * carries `nonce="{{ Vite::cspNonce() }}"` on the kit's one inline script.
     * An app that published its Blade before that attribute existed would lose
     * the theme script with no error anywhere, so the key must never arrive by
     * merge — it opts in after adding the attribute.
     *
     * @var list<string>
     */
    private const FIRST_INSTALL_ONLY_ENV_KEYS = [
        'STARTER_KIT_ALLOW_UNRESOLVED_ROUTES',
        'STARTER_KIT_CSP_NONCE',
        DataEncrypterFactory::PRIMARY_ENV_KEY,
        DataEncrypterFactory::PREVIOUS_ENV_KEY,
    ];

    /**
     * Compute the merged .env body, appending any example key absent from the
     * current .env under a kit header. Returns null when nothing is missing so
     * the caller can skip the write (making the operation idempotent).
     *
     * Keys in self::FIRST_INSTALL_ONLY_ENV_KEYS are skipped unless
     * $isFirstInstall says this is a brand-new project. That flag is the whole
     * mechanism behind "new installs get it, existing ones do not", now that an
     * existing .env is never overwritten and every install reaches this merge.
     * Even on a first install the key is only ADDED when absent, so an operator
     * who pre-set one keeps their value.
     *
     * Pure string-in / string-out — no filesystem access — so it can be unit
     * tested in isolation.
     */
    private function buildMergedEnvContent(string $envContent, string $exampleContent, bool $isFirstInstall = false): ?string
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

            if (! $isFirstInstall && in_array($key, self::FIRST_INSTALL_ONLY_ENV_KEYS, strict: true)) {
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
     *
     * @return bool Whether an APP_KEY is now present (true when one already was).
     */
    private function ensureAppKey(string $envPath): bool
    {
        $content = $this->files->get($envPath);

        // A non-empty APP_KEY is already set — leave it untouched.
        if (preg_match('/^APP_KEY=.+$/m', $content)) {
            return true;
        }

        return $this->runArtisan('key:generate', ['--force' => true]);
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
     * On a first install the reasoning inverts: there is no prior key and no
     * encrypted row (ensureEnvFile() has just seeded the kit's keys, and the
     * blank check below still holds if a value was somehow already there), and
     * a key generated here is the one every value will ever have been written
     * with. Nothing is added to DATA_ENCRYPTION_PREVIOUS_KEYS on
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

        $this->putEnvAtomically($envPath, $updated);

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
     *
     * Not a plain copy loop. Everything outside $preservablePaths used to be
     * overwritten unconditionally, which made a second `sk:install` a silent
     * data-loss event: a consumer who edited a published controller got the
     * stub back with nothing in the summary saying their work was gone. The
     * hash registry already records what the kit SHIPPED for each path, so the
     * same three-way comparison `sk:update` uses ({@see ComparesPublishedStubs})
     * now guards this loop too — a file that differs from what we shipped is
     * the consumer's, and it is preserved and reported instead of clobbered.
     * On a re-install that extends to a path the registry has NO record of:
     * once the registry is authoritative, "never shipped here" is as good a
     * proof of consumer ownership as "shipped, then edited".
     * `--force` still takes the package version, as documented.
     */
    private function publishDirectory(string $source, string $destination, bool $force): void
    {
        if (! $this->files->isDirectory($source)) {
            return;
        }

        if (! $this->dryRun && ! $this->files->isDirectory($destination)) {
            $this->files->makeDirectory($destination, 0755, true);
        }

        // Read once. The old re-install guard re-read and re-decoded the whole
        // registry inside the loop, once per stub file.
        $registry = $this->loadHashRegistry();

        // Is the registry authoritative for this run? Only if the kit has
        // published into this application before — read from the same file, via
        // the same accessor, that decides first-install everywhere else, so the
        // two can never disagree.
        //
        // This is the fact the decision was missing. With an authoritative
        // registry, "no record for this path" stops being a shrug and becomes
        // evidence: the kit knows what it shipped here, so a path it has NO
        // record of is a path it has never shipped here — and the file sitting
        // at that path is therefore the consumer's own, not a stale copy of
        // ours. A newer package version that starts shipping into an occupied
        // path used to overwrite it silently.
        //
        // On a first install there is no registry to be authoritative with:
        // every path reads as untracked, and preserving them would leave a
        // fresh Laravel skeleton half-scaffolded while reporting success. That
        // direction keeps today's behaviour byte for byte, with the
        // detectExistingApp() stop above as its guard.
        $registryIsAuthoritative = ! $this->isFirstInstall();

        // A registry file that exists but decodes to nothing (truncated by a
        // killed run, corrupted, hand-edited) is authoritative and empty: every
        // single path then reads UNTRACKED and is preserved, so the run
        // publishes nothing and still reports success. Preserving is the right
        // direction — the alternative is overwriting consumer files on the
        // strength of a file we could not read — but silence is not: the
        // operator has to be told why nothing was published and how to get the
        // registry back.
        if ($registryIsAuthoritative && $registry === []) {
            $this->components->warn(
                'The published-file registry exists but is empty or unreadable, so every existing '
                .'target is being preserved and this run will publish almost nothing. Rebuild it with '
                .'`php artisan sk:install --adopt`, or force a republish with `php artisan sk:install --force` '
                .'(--force OVERWRITES files you have edited).'
            );
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

            if (! $this->dryRun && ! $this->files->isDirectory($targetDir)) {
                $this->files->makeDirectory($targetDir, 0755, true);
            }

            $targetExists = $this->files->exists($targetPath);

            if (! $force && $this->isPreservable($relativePath) && $targetExists) {
                $this->skipped[] = $relativePath;

                continue;
            }

            // Re-install guard: if hash registry exists and has a record for this file
            // but the target is missing, the user intentionally deleted it — don't restore.
            if (! $force && ! $targetExists && $this->registryRecordFor($registry, $normalizedPath) !== null) {
                $this->skipped[] = $relativePath;

                continue;
            }

            // Hash-aware overwrite guard. Only the outcomes that mean "do not
            // write" stop the copy:
            //   - OPTED_OUT   the consumer excluded this path at install time
            //   - UP_TO_DATE  we have shipped nothing new, so the difference on
            //                 disk is theirs
            //   - MODIFIED    a new version exists AND they edited their copy
            //   - UNTRACKED   only when the registry is authoritative (below)
            // IDENTICAL and WRITE fall through (an identical copy is a harmless
            // no-op write that keeps the published count honest).
            // md5_file() returns false for an unreadable path (and for a
            // directory sitting where a file should be). Falling back to null
            // keeps the pre-guard behaviour — publish — instead of raising a
            // TypeError on a tree we cannot read anyway.
            if ($targetExists) {
                $decision = $this->decidePublishedStub(
                    md5_file($file->getPathname()) ?: '',
                    md5_file($targetPath) ?: null,
                    $this->registryRecordFor($registry, $normalizedPath),
                    $force,
                );

                if ($decision === self::STUB_OPTED_OUT) {
                    $this->skipped[] = $relativePath;

                    continue;
                }

                // No record, a file on disk that differs, and a registry that
                // knows what this application was given: the kit has never
                // shipped this path here, so the file is the consumer's and it
                // is preserved and reported rather than overwritten.
                //
                // Both boundaries stay exactly where they were. `--force`
                // never reaches here — decidePublishedStub() answers WRITE for
                // it first, the same escape hatch MODIFIED has always had. And
                // without an authoritative registry (first install, or a resume
                // of one) UNTRACKED still falls through and publishes the
                // scaffold, because there every path is untracked.
                if ($decision === self::STUB_UNTRACKED && $registryIsAuthoritative) {
                    $this->preserved[] = $normalizedPath;

                    continue;
                }

                if ($decision === self::STUB_UP_TO_DATE || $decision === self::STUB_MODIFIED) {
                    $this->preserved[] = $normalizedPath;

                    continue;
                }
            }

            // --dry-run records the decision and copies nothing; the plan the
            // operator reads is therefore the exact set this loop would write.
            if (! $this->dryRun) {
                $this->files->copy($file->getPathname(), $targetPath);
            }

            $this->published[] = $relativePath;
        }
    }

    /**
     * Detect if this is the first install by checking for the hash registry file.
     * The registry is written at the end of install, so its absence means no prior install.
     */
    private function isFirstInstall(): bool
    {
        return ! $this->files->exists($this->hashRegistryPath());
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

    // ══════════════════════════════════════════════════════════════════════
    // OPTIONAL MODULES (observability recipes)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The recipe keys this run should install: whatever `--modules=` named, or
     * an interactive selection when the flag was omitted.
     *
     * @return list<string>
     */
    private function selectedRecipes(): array
    {
        $fromOption = $this->recipeKeysFromOption();

        return $fromOption !== [] ? $fromOption : $this->promptForRecipes();
    }

    /**
     * Normalized `--modules=` values. Accepts both repeated flags
     * (`--modules=telescope --modules=pulse`) and the comma-separated spelling
     * (`--modules=telescope,pulse`) the docs advertise, because an array option
     * hands the latter over as ONE untouched "telescope,pulse" string.
     *
     * Pure — no registry lookup, so an unknown key survives to be reported by
     * unknownRecipeKeys() rather than throwing here.
     *
     * @return list<string>
     */
    private function recipeKeysFromOption(): array
    {
        $keys = [];

        /** @var list<string> $values */
        $values = (array) $this->option('modules');

        foreach ($values as $value) {
            foreach (explode(',', $value) as $key) {
                $key = strtolower(trim($key));

                if ($key !== '') {
                    $keys[] = $key;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * `--modules=` values that name no known recipe. Pure decision, unit
     * testable in isolation; the caller fails the run on a non-empty result.
     *
     * @return list<string>
     */
    private function unknownRecipeKeys(): array
    {
        return array_values(array_diff(
            $this->recipeKeysFromOption(),
            array_keys(RecipeRegistry::all()),
        ));
    }

    /**
     * Ask which optional modules to install. Selecting none is a valid answer
     * (`required: false`), so the prompt never traps an operator who only wanted
     * the base kit.
     *
     * Returns [] without prompting when there is nobody to ask: a
     * non-interactive input (Symfony flips that for --no-interaction / -n before
     * the command runs) or no TTY on stdin (CI). The TTY test mirrors Laravel's
     * own configurePrompts() — relying on laravel/prompts to fall back to the
     * default would make an unattended install depend on library internals, and
     * the version pinned here has no Prompt::fake().
     *
     * @return list<string>
     */
    private function promptForRecipes(): array
    {
        if (! $this->input->isInteractive() || ! defined('STDIN') || ! stream_isatty(STDIN)) {
            return [];
        }

        $selected = multiselect(
            label: 'Install optional monitoring/debugging modules?',
            options: RecipeRegistry::labels(),
            required: false,
            hint: 'Select none to skip — any of these can be added later by hand.',
        );

        // multiselect() hands back the option KEYS, typed loosely as
        // array<int|string, int|string>; the recipe keys they carry are strings.
        return array_values(array_map(strval(...), $selected));
    }

    /**
     * `composer require` each selected recipe, then run its post-install
     * commands in order. Every recipe is attempted even when an earlier one
     * failed: they are independent, and stopping at the first failure would
     * silently drop modules the operator explicitly asked for.
     *
     * @param  list<string>  $keys
     * @return bool Whether every recipe installed cleanly.
     */
    private function installRecipes(array $keys): bool
    {
        $composer = $this->findComposerBinary();
        $failures = [];

        foreach ($keys as $key) {
            $recipe = RecipeRegistry::get($key);

            if (! $this->runProcessStep($this->recipeRequireCommand($composer, $recipe), timeout: 180)) {
                $failures[] = $key.' — '.($this->stepFailureDetail ?? '`composer require` failed.');

                continue;
            }

            foreach ($recipe['post_install'] as $artisanCommand) {
                if ($this->runProcessStep($this->recipeArtisanCommand($artisanCommand), timeout: 120)) {
                    continue;
                }

                $failures[] = sprintf(
                    '%s — the package was installed, but `php artisan %s` failed; run it by hand. %s',
                    $key,
                    $artisanCommand,
                    $this->stepFailureDetail ?? '',
                );

                // The package is on disk but not wired, so it must not be
                // reported as installed. Its remaining post-install commands
                // would only fail on the same cause.
                continue 2;
            }

            $this->installedRecipes[] = $key;
        }

        if ($failures !== []) {
            $this->stepFailureDetail = implode(PHP_EOL.'  ', $failures);

            return false;
        }

        $this->stepFailureDetail = null;

        return true;
    }

    /**
     * The composer invocation for one recipe. Pure — built here rather than
     * inline so it can be asserted without a network call.
     *
     * @param  list<string>  $composer
     * @param  array{composer: string, dev: bool, label: string, post_install: list<string>}  $recipe
     * @return list<string>
     */
    private function recipeRequireCommand(array $composer, array $recipe): array
    {
        return array_values(array_filter([
            ...$composer,
            'require',
            $recipe['dev'] ? '--dev' : null,
            $recipe['composer'],
            '--no-interaction',
        ], fn (?string $part): bool => $part !== null));
    }

    /**
     * A recipe's post-install Artisan command, as a CHILD php process.
     *
     * Not Artisan::call(): the package was installed seconds ago by the composer
     * run above, so its service provider was never registered in THIS
     * already-booted process and `telescope:install` does not exist here —
     * an in-process call would always die with "command not found". A fresh
     * process boots after composer's package discovery and finds it. Same shape
     * as the wayfinder:generate step further down.
     *
     * --no-interaction is appended so an unattended install can never stall on a
     * third-party package's prompt. Splitting on whitespace is safe because the
     * strings come from RecipeRegistry's own constant, never from user input.
     *
     * @return list<string>
     */
    private function recipeArtisanCommand(string $command): array
    {
        $tokens = preg_split('/\s+/', trim($command)) ?: [];

        return [PHP_BINARY, base_path('artisan'), ...$tokens, '--no-interaction'];
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
     * Run migrations, with a narrowly guarded escape hatch for a database that
     * already holds tables.
     *
     * The destructive branch (migrate:fresh) exists for one situation: an
     * operator, at a real terminal, pointing an install at a database left
     * half-populated by an earlier aborted attempt. Every other shape —
     * automation, a deployed environment, a connection whose tables demonstrably
     * hold rows — takes the additive `migrate` path, and the reason the
     * destructive option is missing is printed rather than left to guesswork.
     *
     * Note what the gate does NOT check: whether this is a first install.
     * `destructiveMigrationBlockReason()` never asks, so an interactive
     * re-install onto an all-empty schema is offered the option too. That is
     * deliberate — the row probe is the guarantee that matters, and it blocks
     * the moment any table holds a row.
     */
    private function runMigrations(): void
    {
        $appTables = $this->existingApplicationTables();

        if ($appTables !== []) {
            $this->newLine();
            $this->components->warn('The database already contains tables.');

            $action = $this->chooseMigrationStrategy($appTables);

            if ($action === 'skip') {
                $this->components->info('Migrations skipped.');

                return;
            }

            if ($action === 'fresh') {
                $this->step('Running migrate:fresh', function () {
                    return $this->runArtisan('migrate:fresh', ['--force' => true]);
                });

                return;
            }
        }

        $this->step('Running migrations', function () {
            return $this->runArtisan('migrate', ['--force' => true]);
        });
    }

    /**
     * Decide how to proceed against a database that already holds tables.
     *
     * Returns 'fresh' only once the destructive option was both offered AND
     * confirmed by a typed answer; every other route resolves to the additive
     * 'migrate' or to 'skip'.
     *
     * @param  list<string>  $appTables
     * @return 'migrate'|'fresh'|'skip'
     */
    private function chooseMigrationStrategy(array $appTables): string
    {
        // A session with no human on the other end is never offered a
        // destructive branch and never has one selected for it. Deciding that
        // here, rather than leaning on select()'s non-interactive default, keeps
        // the guarantee independent of laravel/prompts internals: the Windows
        // and unit-test paths route through a Symfony fallback that resolves
        // defaults through a different code path than $interactive === false.
        if (! $this->canPrompt()) {
            $this->line('  <fg=gray>Non-interactive session — running pending migrations only; existing data is kept.</>');

            return 'migrate';
        }

        $blockReason = $this->destructiveMigrationBlockReason($appTables);

        if ($blockReason !== null) {
            $this->line('  <fg=gray>The destructive "fresh" option is withheld: '.$blockReason.'.</>');
        }

        $action = select(
            label: 'How would you like to proceed?',
            options: $this->migrationStrategyOptions($blockReason === null),
            default: 'migrate',
        );

        if ($action === 'skip') {
            return 'skip';
        }

        if ($action !== 'fresh') {
            return 'migrate';
        }

        // A refused destructive reset falls back to `migrate`, NOT to `skip`.
        // The two are not interchangeable: `skip` runs no migrations at all and
        // then lets the install walk on into seeders and permission seeding
        // against a schema that was never built, which is the half-install this
        // command was hardened to stop reporting as success. `migrate` is the
        // additive path the operator would have got by pressing Enter, and it
        // is what the changelog and UPGRADE guide promise for this case.
        //
        // $blockReason is re-checked rather than trusted: select() cannot return
        // an option that was never offered, but an irreversible drop must not
        // rest on that being true.
        if ($blockReason !== null) {
            return 'migrate';
        }

        if (! $this->confirmDestructiveReset()) {
            $this->line('  <fg=gray>Confirmation did not match — nothing was dropped, running pending migrations instead.</>');

            return 'migrate';
        }

        return 'fresh';
    }

    /**
     * The migration choices offered to the operator.
     *
     * `migrate` is always first AND always the default: the highlighted row of a
     * select is one Enter away, so the entry that destroys data must never
     * occupy it — whatever else is on the list.
     *
     * @return array<string, string>
     */
    private function migrationStrategyOptions(bool $allowFresh): array
    {
        $options = ['migrate' => 'Run pending migrations only (keep existing data)'];

        if ($allowFresh) {
            $options['fresh'] = 'Drop all tables and run fresh migrations (ALL DATA WILL BE LOST)';
        }

        $options['skip'] = 'Skip migrations';

        return $options;
    }

    /**
     * Table names already present on the default connection, excluding the
     * migrations ledger.
     *
     * A connection that cannot be reached or read answers `[]`: a first install
     * against a database that is not up yet must not be pushed down the
     * "existing tables" branch — `migrate` reports that failure with a far
     * better message than this probe could.
     *
     * The connection prefix is STRIPPED here. `Schema::getTables()` reports the
     * real names the database carries, prefix included, while every consumer of
     * this list speaks the query-builder's language: `DB::table()` re-applies
     * the prefix itself, so passing a prefixed name back to it asks for
     * `pfx_pfx_users` and throws. On a prefixed connection that turned the
     * fail-closed row probe into a permanent block with a false reason, and hid
     * the `migrations` ledger from the filter below.
     *
     * @return list<string>
     */
    private function existingApplicationTables(): array
    {
        try {
            $tables = Schema::getTables();
        } catch (\Throwable) {
            return [];
        }

        $prefix = (string) DB::getTablePrefix();
        $names = [];

        foreach ($tables as $table) {
            $name = is_array($table) ? (string) ($table['name'] ?? '') : (string) $table;

            if ($prefix !== '' && str_starts_with($name, $prefix)) {
                $name = substr($name, strlen($prefix));
            }

            // The ledger is written by migrate itself. Its rows are not data an
            // operator can lose, so it neither proves "existing tables" here nor
            // counts as live data in the row probe below — without this the
            // destructive option would be withheld from every database that has
            // ever been migrated, which is the whole case it exists for.
            if ($name === '' || $name === 'migrations') {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * Why the destructive "fresh" option must not be offered, or null when it may be.
     *
     * The environment NAME is the weakest of these signals — an operator who
     * never set APP_ENV is not thereby running a throwaway database — so it is
     * no longer the only one. A session that cannot prompt, a non-debug (i.e.
     * deployed) application, and a connection whose tables demonstrably hold
     * rows each withhold the option on their own.
     *
     * The row probe fails CLOSED: a table that cannot be read — permission-limited,
     * a view the credentials cannot select from, dropped mid-probe — counts as
     * holding live data, never as empty.
     *
     * Every branch below blocks equally; their order only decides which cause is
     * printed when several hold at once, and "your APP_ENV says production" is
     * more actionable than "there is no TTY".
     *
     * @param  list<string>  $appTables
     */
    private function destructiveMigrationBlockReason(array $appTables): ?string
    {
        if ($this->isProductionLikeEnvironment()) {
            return sprintf('APP_ENV is "%s"', (string) app()->environment());
        }

        if (! (bool) config('app.debug')) {
            return 'APP_DEBUG is off, so this is treated as a deployed environment';
        }

        if (! $this->canPrompt()) {
            return 'this session cannot prompt for confirmation (--no-interaction, CI, or no TTY)';
        }

        foreach ($appTables as $table) {
            try {
                $populated = DB::table($table)->exists();
            } catch (\Throwable) {
                return sprintf(
                    'the existing table "%s" could not be read, so it is treated as holding live data',
                    $table,
                );
            }

            if ($populated) {
                return sprintf('the existing table "%s" already holds rows', $table);
            }
        }

        return null;
    }

    /**
     * Take an explicitly TYPED confirmation before dropping every table.
     *
     * A yes/no confirm is one keystroke from the wrong answer and reads exactly
     * like the half-dozen other confirms this install asks. Dropping every table
     * is not that kind of decision, so the operator has to reproduce a string
     * that names what is about to be destroyed.
     */
    private function confirmDestructiveReset(): bool
    {
        // No human, no confirmation — and therefore no reset. Reached only
        // defensively; chooseMigrationStrategy() already returned 'migrate'.
        if (! $this->canPrompt()) {
            return false;
        }

        $database = $this->currentDatabaseName();

        $this->newLine();
        $this->components->warn('migrate:fresh DROPS EVERY TABLE on this connection. There is no undo.');
        $this->line(sprintf(
            '  <fg=gray>Connection: %s · Database: %s</>',
            (string) config('database.default'),
            $database !== '' ? $database : 'unknown',
        ));

        $typed = (string) text(
            label: $database !== ''
                ? sprintf('Type the database name (%s) or the word "fresh" to confirm', $database)
                : 'Type the word "fresh" to confirm',
            placeholder: $database !== '' ? $database : 'fresh',
        );

        return $this->destructiveResetConfirmationMatches($typed);
    }

    /**
     * Whether a typed confirmation authorises the reset.
     *
     * Surrounding whitespace is forgiven (a pasted database name carries it);
     * nothing else is. Every reflex answer — an empty line, 'y', 'yes' — is
     * rejected, and so is the empty string a non-interactive prompt fallback
     * would hand back.
     */
    private function destructiveResetConfirmationMatches(string $typed): bool
    {
        $typed = trim($typed);

        if ($typed === '') {
            return false;
        }

        if (strtolower($typed) === 'fresh') {
            return true;
        }

        $database = $this->currentDatabaseName();

        return $database !== '' && $typed === $database;
    }

    /**
     * The database name behind the default connection.
     *
     * Answers '' when the connection carries no plain-string database name (a
     * DSN-configured or unset connection), in which case the literal word
     * 'fresh' is the only confirmation the operator can type.
     */
    private function currentDatabaseName(): string
    {
        $connection = (string) config('database.default');
        $database = config("database.connections.{$connection}.database");

        return is_string($database) ? trim($database) : '';
    }

    /**
     * Whether the current environment looks like production.
     *
     * One signal among several for the destructive migration branch — see
     * destructiveMigrationBlockReason(), which also weighs APP_DEBUG and whether
     * the connection actually holds rows.
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
                return $this->runArtisan('db:seed', [
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
     *
     * The `runningUnitTests()` arm reads like a test-only escape hatch and is
     * not one: it is copied from ConfiguresPrompts because this method has to
     * answer the same question that framework does — "will a prompt() call
     * actually run here?" — and under `php artisan test` the framework answers
     * yes. Dropping the arm would make canPrompt() disagree with the machinery
     * it is predicting, so a test could not exercise the interactive branch at
     * all. It does not weaken the destructive-migration gate either: prompts in
     * a test run resolve to their DEFAULT, and chooseMigrationStrategy()'s
     * default is 'migrate', never the fresh/destructive option — that one also
     * has to clear destructiveMigrationBlockReason() (APP_ENV, APP_DEBUG, the
     * row probe) and then a typed confirmation string. Behaviour unchanged.
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
     *
     * EVERY step here is best-effort by design: the kit must stay installable on
     * a machine with no Node toolchain (CI images, PHP-only deploy hosts). A
     * failure warns, prints the command to run by hand, and leaves the install's
     * exit code alone — promoting these to mandatory would start failing
     * installs that work today.
     *
     * `$isFirstInstall` decides the fate of `package-lock.json` — see the
     * lockfile branch below. It must agree with the flag `removeConflictingDefaults()`
     * was given, or the install reports a file as kept and then deletes it.
     */
    private function installFrontend(bool $isFirstInstall): void
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

        $nodeModules = base_path('node_modules');
        $lockFile = base_path('package-lock.json');

        // 1. npm install
        //
        // BOTH pre-steps — clearing node_modules and deciding the lockfile —
        // live INSIDE the step, deliberately. On a `--resume` run this step is
        // already checkpointed and gets skipped, so anything sitting in front of
        // it would run against a tree the skipped step is supposed to own: the
        // lockfile the FIRST run's `npm install` had just written would be
        // deleted with nothing left to regenerate it, and the node_modules that
        // same run installed would be wiped while the install that fills it is
        // skipped — leaving the build step to fail on missing dependencies.
        if (! $this->step('Installing npm dependencies', function () use ($nodeModules, $lockFile, $isFirstInstall) {
            $this->clearNodeModules($nodeModules);
            $this->prepareLockFile($lockFile, $isFirstInstall);

            return $this->runProcessStep(['npm', 'install'], timeout: 300);
        }, mandatory: false)) {
            $this->renderFrontendGuidance('npm install && npm run build');

            return;
        }

        // 2. Clear config/route cache so wayfinder sees fresh routes
        $this->runProcess(['php', 'artisan', 'config:clear'], 'Clearing config cache');
        $this->runProcess(['php', 'artisan', 'route:clear'], 'Clearing route cache');

        // 3. Generate Wayfinder route/action TypeScript files (required for build)
        if (! $this->step('Generating Wayfinder types', function () {
            return $this->runProcessStep(['php', 'artisan', 'wayfinder:generate'], timeout: 60);
        }, mandatory: false)) {
            $this->components->warn('Wayfinder types could not be generated. Build will fail.');
            $this->renderFrontendGuidance('php artisan wayfinder:generate && npm run build');

            return;
        }

        // 4. Build frontend
        if (! $this->step('Building frontend assets', function () {
            return $this->runProcessStep(['npm', 'run', 'build'], timeout: 300);
        }, mandatory: false)) {
            $this->renderFrontendGuidance('npm run build');
        }
    }

    /**
     * Clear a stale `node_modules` right before `npm install` refills it.
     *
     * The tree is regenerated from the lockfile and holds no work of the
     * operator's, so removing it is always safe — but only when the install
     * that refills it actually runs. Called from inside the npm-install step so
     * a resumed run that skips that step leaves the tree the first run
     * installed alone.
     */
    private function clearNodeModules(string $nodeModules): void
    {
        if (! $this->files->isDirectory($nodeModules)) {
            return;
        }

        $this->line('  <fg=gray>Removing the old node_modules tree…</>');

        $this->files->deleteDirectory($nodeModules);
    }

    /**
     * Decide what happens to `package-lock.json` right before `npm install`.
     *
     * The lockfile is the app's pinned dependency graph, not a build artefact:
     * deleting it lets `npm install` resolve versions the app was never tested
     * against. On a FIRST install there is nothing of the operator's to lose,
     * and a stale lock left over from an unrelated package.json only gets in
     * the way. On a re-install it is kept — which is also what
     * removeConflictingDefaults() has already reported to the operator, so the
     * two must not disagree.
     *
     * Called from inside the npm-install step so a resumed run that skips that
     * step does not touch the lockfile either.
     */
    private function prepareLockFile(string $lockFile, bool $isFirstInstall): void
    {
        if (! $this->files->exists($lockFile)) {
            return;
        }

        if ($isFirstInstall) {
            $this->files->delete($lockFile);

            return;
        }

        $this->components->info('Keeping the existing package-lock.json — npm install will resolve against your pinned versions. Delete it by hand if you want a clean resolution.');
    }

    /**
     * The "run it by hand" guidance shipped with every non-fatal frontend
     * failure — the install continues, so the operator needs the exact command.
     */
    private function renderFrontendGuidance(string $command): void
    {
        $this->line('  <fg=yellow>Frontend assets were not built. Fix the issue above, then run:</>');
        $this->line("  <fg=cyan>{$command}</>");
        $this->newLine();
    }

    /**
     * Run a process silently, only for cache/clear type operations.
     *
     * Best-effort: a cache clear that fails is not worth stopping an install
     * over, but it is no longer swallowed — the next step (wayfinder) would
     * otherwise fail with a stale-route error and no hint of the cause.
     */
    private function runProcess(array $command, string $label): void
    {
        if (! $this->runProcessStep($command, timeout: 30)) {
            $this->components->warn($label.' failed: '.$this->stepFailureDetail);
            $this->stepFailureDetail = null;
        }
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
            $this->line('    Run <fg=cyan>php artisan sk:upgrade</> and follow the one-time conversion guide in <fg=cyan>'.DocsLink::to('timezone.md').'</>.');

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
        $this->writeHashRegistry($this->buildStubHashRegistry()['hashes']);
    }

    /**
     * Configured location of the published-file hash registry. Single accessor
     * so the install path, the re-install guard and --adopt can never drift onto
     * different files.
     */
    private function hashRegistryPath(): string
    {
        return config('starter-kit.published_hashes', storage_path('starter-kit/hashes.json'));
    }

    /**
     * Read the registry once. A malformed or absent file reads as "no records",
     * which makes every path untracked — the publish loop then behaves exactly
     * as it did before hash tracking existed rather than failing the install.
     *
     * @return array<string, mixed>
     */
    private function loadHashRegistry(): array
    {
        $path = $this->hashRegistryPath();

        if (! $this->files->exists($path)) {
            return [];
        }

        $data = json_decode($this->files->get($path), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Look a path up in the registry, tolerating the separator form it was
     * written with.
     *
     * The registry is keyed by SplFileInfo::getRelativePathname(), which uses
     * DIRECTORY_SEPARATOR — identical to the normalised form on POSIX, but
     * backslashed on Windows, where a registry written by an older run would
     * then read as "no record" for every single file and the overwrite guard
     * would never fire. Checking both forms costs one array lookup and removes
     * the whole class of silent mis-keying.
     *
     * @param  array<string, mixed>  $registry
     */
    private function registryRecordFor(array $registry, string $normalizedPath): ?string
    {
        $record = $registry[$normalizedPath] ?? null;

        if ($record === null && DIRECTORY_SEPARATOR !== '/') {
            $record = $registry[str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath)] ?? null;
        }

        return is_string($record) ? $record : null;
    }

    /**
     * Build the registry contents from the shipped stubs against what is
     * currently on disk, plus the counts that describe the result.
     *
     * This is the ONE definition of the registry's semantics — a stub whose
     * target exists is recorded under the hash of the STUB (what we shipped, so
     * sk:update can later tell a consumer edit from an untouched file), a stub
     * excluded by a flag gets the '__skipped__' sentinel, and a stub whose
     * target is absent is left out entirely so sk:update treats it as new.
     * Both saveStubHashes() and --adopt go through here, which is what makes
     * "--adopt writes the same registry a successful install would" true by
     * construction rather than by a second implementation agreeing.
     *
     * @return array{hashes: array<string, string>, adopted: int, missing: int, skipped: int}
     */
    private function buildStubHashRegistry(): array
    {
        $hashes = [];
        $adopted = 0;
        $missing = 0;
        $skippedCount = 0;

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
                $skippedCount++;

                continue;
            }

            if ($this->files->exists($targetPath)) {
                // Store STUB hash — this is what we shipped, used to detect user modifications
                $hashes[$relativePath] = md5_file($file->getPathname());
                $adopted++;

                continue;
            }

            $missing++;
        }

        $hashes['_format'] = 'v2';

        return [
            'hashes' => $hashes,
            'adopted' => $adopted,
            'missing' => $missing,
            'skipped' => $skippedCount,
        ];
    }

    /**
     * Persist the registry, creating storage/starter-kit on demand.
     *
     * Atomic (temp file in the same directory, flushed, then renamed) because a
     * truncated registry is the most expensive failure this command can leave
     * behind: sk:update reads it to tell consumer-owned files from removable
     * ones, so half a registry reads as "these paths were never published" and
     * the next update decides accordingly. A crash mid-write must leave the old
     * registry or the new one, never something in between. A write that cannot
     * complete throws, so it fails the install instead of vanishing.
     *
     * @param  array<string, string>  $hashes
     */
    private function writeHashRegistry(array $hashes): void
    {
        $this->atomicPut(
            $this->hashRegistryPath(),
            json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Copy an existing registry aside before it is replaced, returning the
     * backup path (null when there was nothing to back up).
     *
     * The registry is the only record of which files the kit published and what
     * it shipped for each. Replacing it with a rebuild is a judgement call about
     * files the operator may have edited, so the previous state stays on disk —
     * a rebuild that guessed wrong is then one `mv` away from being undone.
     * The counter defends the same-second re-run: silently overwriting the first
     * backup would destroy exactly the copy the operator would restore from.
     */
    private function backupHashRegistry(string $path): ?string
    {
        if (! $this->files->exists($path)) {
            return null;
        }

        $stamp = date('Ymd-His');
        $backup = $path.'.bak-'.$stamp;

        $suffix = 1;
        while ($this->files->exists($backup)) {
            $backup = $path.'.bak-'.$stamp.'-'.$suffix;
            $suffix++;
        }

        $this->files->copy($path, $backup);

        return $backup;
    }

    // ══════════════════════════════════════════════════════════════════════
    // ADOPT (--adopt): registry repair, no publish
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Rebuild the hash registry for an application the kit is already installed
     * into, WITHOUT copying a single file.
     *
     * This is the recovery path out of the fail-closed stop. The registry is
     * git-ignored, so losing it is routine — a stateless deploy, a fresh clone,
     * a cleared storage/ tree — and before this command the only ways forward
     * were to hand-write the file or to let sk:install publish over a live
     * application. It writes exactly one path (the registry, after backing up
     * whatever was there): no stub is copied, no migration runs, no .env is
     * read or written, no seeder and no npm step is reached.
     */
    private function adoptExistingInstall(): int
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>Lvntr Starter Kit — adopt an existing installation</>');
        $this->newLine();
        $this->line('  <fg=gray>Rebuilds the published-file registry from the shipped stubs for every</>');
        $this->line('  <fg=gray>target that already exists on disk.</>');
        $this->line('  <fg=gray>No file is copied, no migration runs, no .env is touched.</>');
        $this->newLine();

        // The zero-count guard below cannot carry this decision on its own. The
        // kit ships `app/Models/User.php` and `app/Providers/AppServiceProvider.php`,
        // and a stock Laravel application already has both, so a never-installed
        // app adopts two files and sails past a count check. The evidence that
        // this app was really installed is the same marker set the fail-closed
        // stop reads, so --adopt reads it too.
        $markers = $this->detectExistingApp();

        if ($markers === []) {
            $this->components->error('Nothing to adopt — this application does not look like it has the kit installed.');
            $this->newLine();
            $this->line('  <fg=gray>--adopt rebuilds the registry for an app that WAS installed and lost it.</>');
            $this->line('  <fg=gray>Writing one here would mark this app as installed and permanently skip the</>');
            $this->line('  <fg=gray>first-install steps it has never had.</>');
            $this->components->twoColumnDetail(
                '<fg=cyan>php artisan sk:install</>',
                '<fg=gray>this application is not installed yet</>',
            );
            $this->newLine();

            return self::FAILURE;
        }

        $this->line('  <fg=yellow>Evidence this application was installed:</>');
        foreach ($markers as $marker) {
            $this->line('    <fg=green>•</> <fg=white>'.$marker.'</>');
        }
        $this->newLine();

        $registry = $this->buildStubHashRegistry();
        $path = $this->hashRegistryPath();

        $this->components->twoColumnDetail(
            '<fg=green>Adopted</>',
            $registry['adopted'].' published file(s) found on disk',
        );
        $this->components->twoColumnDetail(
            '<fg=yellow>Missing</>',
            $registry['missing'].' stub target(s) absent (sk:update will treat them as new)',
        );
        $this->components->twoColumnDetail(
            '<fg=gray>Skipped</>',
            $registry['skipped'].' stub path(s) excluded by flags',
        );

        // Adopting nothing is not a harmless no-op: a registry listing zero
        // published files still EXISTS, and its existence is what makes
        // isFirstInstall() false. Writing one onto an app that was never
        // installed would permanently deny it the first-install path (env
        // seeding, User/Role eject) with no error to explain why.
        if ($registry['adopted'] === 0) {
            $this->newLine();
            $this->components->error('Nothing to adopt — no published kit file was found in this application.');
            $this->newLine();
            $this->line('  <fg=gray>Writing an empty registry would mark this app as installed and permanently</>');
            $this->line('  <fg=gray>skip the first-install steps it has never had.</>');
            $this->components->twoColumnDetail(
                '<fg=cyan>php artisan sk:install</>',
                '<fg=gray>this application is not installed yet</>',
            );
            $this->newLine();

            return self::FAILURE;
        }

        if ($this->dryRun) {
            $this->newLine();
            $this->components->info('Dry run — nothing was written.');
            $this->newLine();
            $this->line('  <fg=gray>Would write:  '.$path.'</>');

            if ($this->files->exists($path)) {
                $this->line('  <fg=gray>Would back up the current registry alongside it first.</>');
            }

            $this->newLine();

            return self::SUCCESS;
        }

        $backup = $this->backupHashRegistry($path);

        $this->writeHashRegistry($registry['hashes']);

        $this->newLine();

        if ($backup !== null) {
            $this->components->twoColumnDetail('<fg=gray>Backed up</>', $backup);
        }

        $this->components->info('Registry rebuilt: '.$path);
        $this->newLine();
        $this->line('  <fg=gray>Nothing else on disk was touched.</>');
        $this->line('  <fg=gray>From here use <fg=cyan>php artisan sk:update</> to take new kit versions.</>');
        $this->newLine();

        return self::SUCCESS;
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
