<?php

namespace Lvntr\StarterKit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\Console\Commands\Concerns\RefusesPackageSourceTree;
use Lvntr\StarterKit\Console\Commands\Concerns\WritesFilesAtomically;
use Lvntr\StarterKit\StarterKitServiceProvider;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;

/**
 * Eject a kit domain into the consumer app — the inverse of vendor-first
 * relocation. Copies the domain's vendor runtime (Action/DTO/Query/Event/
 * Listener) into `app/Domain/{Name}`, rewrites ONLY that domain's root
 * namespace to `App\`, refreshes its Vue pages, and (for event domains)
 * injects App-FQCN `Event::listen` lines into DomainServiceProvider so the
 * audit log keeps firing.
 *
 * v13.6.0 — behavior modules (Logs, ActivityLog, ApiRoute, Setting, Files)
 * are now vendor-first end-to-end: their controllers + FormRequests live in
 * Lvntr\StarterKit\Http\... and their Vue pages under the package
 * resources/js/pages/Admin/.... Ejecting such a module therefore ALSO:
 *   - copies the vendor controller into app/Http/Controllers/Admin/, rewriting
 *     its own Http\ namespace Lvntr\StarterKit\Http\ → App\Http\ AND (because the
 *     same eject relocates the backend) this module's own domain segment
 *     Lvntr\StarterKit\Domain\{Domain}\ → App\Domain\{Domain}\, so the controller
 *     wires to the just-ejected app/Domain runtime — not vendor. A cross-domain
 *     reference (e.g. ApiRouteController's Domain\Setting\SettingService) and
 *     Domain\Shared\ are left vendor;
 *   - copies the vendor FormRequests into app/Http/Requests/Admin/..., with the
 *     same Http\ + own-domain rewrite;
 *   - rewrites the consumer's route file's controller import back to the App\
 *     FQCN (only when the exact vendor import line is found — a customized route
 *     file is left untouched with a warning);
 *   - copies the module's Vue pages from the PACKAGE resources/js/pages/Admin/...
 *     (no longer from stubs/, which no longer ship them).
 *
 * After ejection the file_exists() guard in
 * StarterKitServiceProvider::backwardCompatAliasPlan() automatically disables
 * the matching controller/request alias — the consumer copy wins. Ejected files
 * are stamped '__ejected__' in the hash registry so a later sk:update treats them
 * as consumer-owned and never removes them under VENDOR_MIGRATED_PATHS.
 *
 * Trade-off (warned at runtime + docs): once ejected, the consumer owns the
 * files and no longer receives kit security/bugfix updates for that domain —
 * the same known cost as overriding any published file.
 */
class EjectCommand extends Command
{
    use RefusesPackageSourceTree;
    use WritesFilesAtomically;

    protected $signature = 'sk:eject
        {domain : The kit domain to eject (User, Role, Setting, ActivityLog, ApiClient, SystemHealth, ContentLanguage, Definitions, MediaUpload, ApiRoute, Logs, Files, Session, Media)}
        {--force : Overwrite existing app/Domain files}
        {--dry-run : Show what would happen without writing anything}
        {--no-vue : Eject only the backend; leave Vue pages untouched}
        {--skip-autoload : Do not run composer dump-autoload (the CALLER must run it). Installer-internal use — sk:install batches its own single dump after ejecting User+Role.}
        {--destination= : Override destination base path (for testing or custom layouts)}';

    protected $description = 'Eject a kit domain into your app (own it: backend + Vue, alias disabled, no more kit updates for it)';

    /**
     * Static domain manifest. The kit's domain layout is too irregular to derive
     * by convention (domain `User` ↔ folder `Users` ↔ type `user.ts`; `Setting`
     * ↔ `SettingsController`; ApiClient/Token Vue lives inside Settings tabs), so
     * each ejectable domain is described explicitly. Mirrors
     * PublishCommand::PUBLISHABLE_TAGS in spirit: embedded in the command so
     * `config:cache` cannot strip it and a consumer cannot accidentally break it.
     *
     * Each descriptor:
     *   - backend:     vendor source dir (relative to package root) → app/Domain/{Name};
     *                  '' for a Vue-only descriptor (FileManager — backend stays vendor).
     *   - vue:         [sourceRelative => appDestRelative] map; [] when the domain ships
     *                  no dedicated Vue page folder (Session, Media, ApiClient). For the
     *                  v13.6.0 vendor-first modules the source is the PACKAGE copy
     *                  (resources/js/pages/Admin/...); for User/Role it is still stubs/.
     *   - events:      [EventShortName => ListenerShortName] for App-FQCN Event::listen
     *                  injection; [] for event-less domains.
     *   - controllers: [vendorSourceRelative => appDestRelative] map of single PHP files
     *                  (v13.6.0 vendor-first HTTP layer); copied with namespace rewrite
     *                  Lvntr\StarterKit\Http\ → App\Http\. [] for domains with no
     *                  vendor controller.
     *   - requests:    [vendorSourceDirRelative => appDestDirRelative] map of FormRequest
     *                  directories; copied recursively with the same namespace rewrite.
     *                  [] for domains with no vendor FormRequests.
     *   - resources:   [vendorSourceDirRelative => appDestDirRelative] map of API Resource
     *                  directories; copied recursively with the same namespace rewrite
     *                  (v13.6.0 Faz 2). Omitted for domains with no vendor Resource.
     *   - route:       [relativeRouteFile => vendorControllerFqcn] map; after eject the
     *                  consumer's route file has `use {vendorFqcn};` rewritten back to the
     *                  App\ FQCN — but ONLY when that exact import line is present (a
     *                  customized route file is left untouched with a warning). [] when
     *                  the module has no dedicated route file to rewrite.
     *
     * @var array<string, array{
     *   backend: string,
     *   vue: array<string, string>,
     *   events: array<string, string>,
     *   controllers?: array<string, string>,
     *   requests?: array<string, string>,
     *   resources?: array<string, string>,
     *   route?: array<string, string>,
     * }>
     */
    private const DOMAIN_MANIFEST = [
        'User' => [
            'backend' => 'src/Domain/User',
            'vue' => [
                'stubs/resources/js/pages/Admin/Users' => 'resources/js/pages/Admin/Users',
                'stubs/resources/js/types/user.ts' => 'resources/js/types/user.ts',
            ],
            'events' => [
                'UserCreated' => 'LogUserCreated',
                'UserUpdated' => 'LogUserUpdated',
                'UserDeleted' => 'LogUserDeleted',
            ],
        ],
        'Role' => [
            'backend' => 'src/Domain/Role',
            'vue' => [
                'stubs/resources/js/pages/Admin/Roles' => 'resources/js/pages/Admin/Roles',
            ],
            'events' => [
                'RoleCreated' => 'LogRoleCreated',
                'RoleUpdated' => 'LogRoleUpdated',
                'RoleDeleted' => 'LogRoleDeleted',
            ],
        ],
        'Setting' => [
            'backend' => 'src/Domain/Setting',
            // v13.6.0: Settings Vue + controller + FormRequests are vendor-first.
            'vue' => [
                'resources/js/pages/Admin/Settings' => 'resources/js/pages/Admin/Settings',
            ],
            'events' => [],
            'controllers' => [
                'src/Http/Controllers/Admin/SettingsController.php' => 'app/Http/Controllers/Admin/SettingsController.php',
            ],
            'requests' => [
                'src/Http/Requests/Admin/Settings' => 'app/Http/Requests/Admin/Settings',
            ],
            'route' => [
                'routes/web/settings-route.php' => 'Lvntr\StarterKit\Http\Controllers\Admin\SettingsController',
            ],
        ],
        'ActivityLog' => [
            'backend' => 'src/Domain/ActivityLog',
            'vue' => [
                'resources/js/pages/Admin/ActivityLogs' => 'resources/js/pages/Admin/ActivityLogs',
            ],
            'events' => [],
            'controllers' => [
                'src/Http/Controllers/Admin/ActivityLogController.php' => 'app/Http/Controllers/Admin/ActivityLogController.php',
            ],
            // ActivityLog has no dedicated FormRequest set.
            'requests' => [],
            'route' => [
                'routes/web/activity-log-route.php' => 'Lvntr\StarterKit\Http\Controllers\Admin\ActivityLogController',
            ],
        ],
        'ApiClient' => [
            'backend' => 'src/Domain/ApiClient',
            // ApiClient/ApiToken management Vue lives inside Settings tab components
            // (ApiClientsManageTab.vue, ApiTokensManageTab.vue), not a dedicated folder —
            // it travels with the Settings domain. No standalone ApiClient page set.
            'vue' => [],
            'events' => [],
            // v13.6.0 Faz 2 — the ApiClient + ApiToken HTTP layer is now vendor-first
            // and fully ejectable. The ApiClient domain owns both the Client and the
            // personal-access-token flows (the token actions live in
            // src/Domain/ApiClient), so the ApiToken controller/request/resource +
            // route eject together with it under this single domain entry.
            'controllers' => [
                'src/Http/Controllers/Admin/ApiClientController.php' => 'app/Http/Controllers/Admin/ApiClientController.php',
                'src/Http/Controllers/Admin/ApiTokenController.php' => 'app/Http/Controllers/Admin/ApiTokenController.php',
            ],
            'requests' => [
                'src/Http/Requests/Admin/ApiClient' => 'app/Http/Requests/Admin/ApiClient',
                'src/Http/Requests/Admin/ApiToken' => 'app/Http/Requests/Admin/ApiToken',
            ],
            'resources' => [
                'src/Http/Resources/Admin/ApiClient' => 'app/Http/Resources/Admin/ApiClient',
                'src/Http/Resources/Admin/ApiToken' => 'app/Http/Resources/Admin/ApiToken',
            ],
            'route' => [
                'routes/web/api-client-route.php' => 'Lvntr\StarterKit\Http\Controllers\Admin\ApiClientController',
                'routes/web/api-token-route.php' => 'Lvntr\StarterKit\Http\Controllers\Admin\ApiTokenController',
            ],
        ],
        'SystemHealth' => [
            // Controller-only module — no domain (the controller drives Artisan +
            // Gate directly), no FormRequest/Resource. Vue lives in the Settings
            // SystemHealthTab. v13.6.0 Faz 2.
            'backend' => '',
            'vue' => [],
            'events' => [],
            'controllers' => [
                'src/Http/Controllers/Admin/SystemHealthController.php' => 'app/Http/Controllers/Admin/SystemHealthController.php',
            ],
            'route' => [
                'routes/web/system-health-route.php' => 'Lvntr\StarterKit\Http\Controllers\Admin\SystemHealthController',
            ],
        ],
        'ContentLanguage' => [
            // v13.6.0 Faz 2 — full Tier 3 vendorize: domain + controller + request +
            // resource + route. The App\Models\ContentLanguage MODEL stays app-owned
            // (publish), so it is intentionally NOT relocated by this eject; the
            // ejected app/Domain/ContentLanguage runtime references it by App\ FQCN
            // (already App\ — never rewritten). Vue lives in the Settings
            // ContentLanguage tab.
            'backend' => 'src/Domain/ContentLanguage',
            'vue' => [],
            'events' => [],
            'controllers' => [
                'src/Http/Controllers/Admin/ContentLanguageController.php' => 'app/Http/Controllers/Admin/ContentLanguageController.php',
            ],
            'requests' => [
                'src/Http/Requests/Admin/ContentLanguage' => 'app/Http/Requests/Admin/ContentLanguage',
            ],
            'resources' => [
                'src/Http/Resources/Admin/ContentLanguage' => 'app/Http/Resources/Admin/ContentLanguage',
            ],
            'route' => [
                'routes/web/content-language-route.php' => 'Lvntr\StarterKit\Http\Controllers\Admin\ContentLanguageController',
            ],
        ],
        'Definitions' => [
            // v13.6.0 Faz 2 — controller-only (the Api + Service Definition
            // controllers wrap the vendor DefinitionService, which stays vendor).
            // No domain folder of its own (DefinitionService lives in Domain/Shared),
            // no FormRequest/Resource. Definitions has no dedicated Vue page.
            'backend' => '',
            'vue' => [],
            'events' => [],
            'controllers' => [
                'src/Http/Controllers/Api/DefinitionController.php' => 'app/Http/Controllers/Api/DefinitionController.php',
                'src/Http/Controllers/Service/DefinitionServiceController.php' => 'app/Http/Controllers/Service/DefinitionServiceController.php',
            ],
            'route' => [
                'routes/api/service-route.php' => 'Lvntr\StarterKit\Http\Controllers\Api\DefinitionController',
                'routes/web/service-route.php' => 'Lvntr\StarterKit\Http\Controllers\Service\DefinitionServiceController',
            ],
        ],
        'MediaUpload' => [
            // v13.6.0 Faz 2 — controller-only. The controller references
            // App\Models\Media by FQCN (model stays app-owned) and the existing
            // vendor Media domain (Clear/UploadMediaAction) backs it via its own
            // aliases. No FormRequest/Resource. The route lives in the shared
            // routes/web.php (media.destroy); the conservative exact-match import
            // rewrite flips only that one `use` line.
            'backend' => '',
            'vue' => [],
            'events' => [],
            'controllers' => [
                'src/Http/Controllers/Api/MediaUploadController.php' => 'app/Http/Controllers/Api/MediaUploadController.php',
            ],
            'route' => [
                'routes/web.php' => 'Lvntr\StarterKit\Http\Controllers\Api\MediaUploadController',
            ],
        ],
        'ApiRoute' => [
            'backend' => 'src/Domain/ApiRoute',
            'vue' => [
                'resources/js/pages/Admin/ApiRoutes' => 'resources/js/pages/Admin/ApiRoutes',
            ],
            'events' => [],
            'controllers' => [
                'src/Http/Controllers/Admin/ApiRouteController.php' => 'app/Http/Controllers/Admin/ApiRouteController.php',
            ],
            'requests' => [],
            // ApiRoute screens live under the developer-route.php group.
            'route' => [
                'routes/web/developer-route.php' => 'Lvntr\StarterKit\Http\Controllers\Admin\ApiRouteController',
            ],
        ],
        'Logs' => [
            'backend' => 'src/Domain/Logs',
            'vue' => [
                'resources/js/pages/Admin/Logs' => 'resources/js/pages/Admin/Logs',
            ],
            'events' => [
                'LogFilesDeleted' => 'LogActivityForLogFilesDeleted',
            ],
            'controllers' => [
                'src/Http/Controllers/Admin/LogController.php' => 'app/Http/Controllers/Admin/LogController.php',
            ],
            'requests' => [
                'src/Http/Requests/Admin/Log' => 'app/Http/Requests/Admin/Log',
            ],
            'route' => [
                'routes/web/log-route.php' => 'Lvntr\StarterKit\Http\Controllers\Admin\LogController',
            ],
        ],
        'Files' => [
            // FileManager backend keeps its own facade/route-registry infrastructure
            // and is NOT ejected (the FileManagerController + Requests already live in
            // vendor and stay there). This descriptor is Vue-only: it copies the
            // package's FileManager admin pages into the consumer so the UI can be
            // customized while the backend remains vendor-managed.
            'backend' => '',
            'vue' => [
                'resources/js/pages/Admin/Files' => 'resources/js/pages/Admin/Files',
            ],
            'events' => [],
        ],
        'Session' => [
            'backend' => 'src/Domain/Session',
            'vue' => [],
            'events' => [],
        ],
        'Media' => [
            'backend' => 'src/Domain/Media',
            'vue' => [],
            'events' => [],
        ],
    ];

    private Filesystem $files;

    public function handle(): int
    {
        if (! $this->option('dry-run') && $this->isPackageSourceTree($this->destinationOption())) {
            return $this->renderPackageSourceTreeStop();
        }

        $this->files = new Filesystem;

        $domain = (string) $this->argument('domain');

        if (! isset(self::DOMAIN_MANIFEST[$domain])) {
            $this->components->error("Unknown domain: {$domain}");
            $this->line('  Available domains: '.implode(', ', array_keys(self::DOMAIN_MANIFEST)));

            return self::FAILURE;
        }

        $descriptor = self::DOMAIN_MANIFEST[$domain];
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $noVue = (bool) $this->option('no-vue');
        $skipAutoload = (bool) $this->option('skip-autoload');

        $hasBackend = $descriptor['backend'] !== '';

        // Idempotency guard: an existing app/Domain/{Name} without --force means
        // the consumer already ejected (or hand-authored) it — do not clobber.
        // Vue-only descriptors (e.g. Files — backend stays vendor) have no
        // app/Domain dir to guard; the per-file Vue --force guard covers them.
        if ($hasBackend) {
            $appDomainDir = $this->resolveDestination('app/Domain/'.$domain);

            if (! $force && $this->files->isDirectory($appDomainDir)) {
                $this->components->warn("app/Domain/{$domain} already exists — pass --force to overwrite, or remove it first.");

                return self::SUCCESS;
            }
        }

        // Ownership consent gate: ejecting is a one-way trade-off (see class
        // docblock) — once ejected the domain stops receiving kit runtime
        // updates via composer update. Ask before doing any work, unless the
        // caller already expressed explicit intent (--force, e.g. sk:install's
        // internal default-domain eject) or cannot be prompted (--no-interaction,
        // e.g. CI/scripted eject) — --dry-run also skips it since nothing is
        // written.
        if (! $force && ! $dryRun && ! $this->option('no-interaction')) {
            $confirmed = confirm(
                label: "Ejecting {$domain} makes it app-owned: you will maintain it yourself and no longer receive kit runtime updates for it. Continue?",
                default: false,
            );

            if (! $confirmed) {
                $this->components->warn('Eject cancelled.');

                return self::SUCCESS;
            }
        }

        $this->newLine();
        $this->line("  <fg=cyan;options=bold>Ejecting domain: {$domain}</>");
        if ($dryRun) {
            $this->line('  <fg=yellow>DRY RUN — no files will be written.</>');
        }
        $this->newLine();

        // Relative app-paths of every file written by this eject — stamped
        // '__ejected__' in the hash registry at the end so a later sk:update
        // treats them as consumer-owned (never removed under VENDOR_MIGRATED_PATHS).
        $ejectedRelativePaths = [];

        $backendCount = 0;
        if ($hasBackend) {
            $backendCount = $this->ejectBackend($domain, $descriptor['backend'], $dryRun, $ejectedRelativePaths);
        }

        // v13.6.0 vendor-first HTTP layer: copy the controller + FormRequests into
        // app/, rewriting Lvntr\StarterKit\Http\ → App\Http\, then point the route
        // file's controller import back at the App\ FQCN.
        $httpResult = $this->ejectHttpLayer($domain, $descriptor, $force, $dryRun, $ejectedRelativePaths);

        $vueResult = ['copied' => 0, 'skipped' => []];
        if (! $noVue) {
            $vueResult = $this->ejectVue($domain, $descriptor['vue'], $force, $dryRun, $ejectedRelativePaths);
        }

        $bindings = $this->injectEventBindings($domain, $descriptor['events'], $dryRun);

        // Stamp the registry so sk:update keeps these consumer-owned (idempotent;
        // never on dry-run). Done before the autoload dump so even a failing dump
        // does not leave the registry out of sync with the files on disk.
        //
        // SKIPPED files are stamped too: an eject is an ownership DECLARATION, not
        // just a copy. A file already present at its install hash is skipped (no
        // --force) yet still becomes consumer-owned — without an '__ejected__' stamp
        // its registry entry stays the old install hash, so a later sk:update would
        // see "unmodified vendor-migrated copy" and DELETE it (UpdateCommand::
        // removeVendorMigratedPaths), partially reverting the owned module to vendor.
        // The '__ejected__' sentinel makes the file durable regardless of content.
        if (! $dryRun) {
            $this->stampEjectedHashes([
                ...$ejectedRelativePaths,
                ...$httpResult['skipped'],
                ...$vueResult['skipped'],
            ]);
        }

        // With --skip-autoload the caller (e.g. sk:install) owns the dump: it
        // ejects several domains in one pass and runs a single composer
        // dump-autoload afterwards instead of one per domain. We treat autoload
        // as OK so the FAILURE path stays reserved for a REAL dump failure of an
        // actual run — a skipped dump is not a failure.
        //
        // A Vue-only eject (no backend domain, no PHP controller/request copied)
        // adds no new App\ classes, so the autoload map is unchanged — skip the
        // dump entirely (it would be pure wasted work on a real consumer).
        $ejectedPhpClasses = $hasBackend || $httpResult['controllers'] > 0 || $httpResult['requests'] > 0 || $httpResult['resources'] > 0;
        $autoloadOk = true;
        if (! $dryRun && ! $skipAutoload && $ejectedPhpClasses) {
            $autoloadOk = $this->refreshAutoload();
        }

        $this->printSummary($domain, $backendCount, $httpResult, $vueResult, $bindings, $noVue, $dryRun, $autoloadOk, $skipAutoload);

        // A failed autoload regeneration means the ejected classes may not resolve
        // under optimized / classmap-authoritative autoloaders — the eject is not
        // functionally complete, so signal non-zero for CI/scripts even though the
        // files were already copied. (Never reached when --skip-autoload is set:
        // $autoloadOk stays true and the caller is responsible for the dump.)
        return $autoloadOk ? self::SUCCESS : self::FAILURE;
    }

    // ══════════════════════════════════════════════════════════════════════
    // BACKEND EJECT (copy + namespace rewrite)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Copy `src/Domain/{Name}/**` → `app/Domain/{Name}/**`, rewriting only this
     * domain's root namespace to `App\`. Returns the number of files copied.
     * Appends every written app-relative path to $ejectedRelativePaths so the
     * caller can stamp them '__ejected__' in the hash registry.
     *
     * @param  list<string>  $ejectedRelativePaths  (by ref) accumulates written app paths
     */
    private function ejectBackend(string $domain, string $sourceRelative, bool $dryRun, array &$ejectedRelativePaths): int
    {
        $source = StarterKitServiceProvider::basePath($sourceRelative);
        $destination = $this->resolveDestination('app/Domain/'.$domain);

        if (! $this->files->isDirectory($source)) {
            $this->components->error("Backend source not found: {$source}");

            return 0;
        }

        $count = 0;

        foreach ($this->files->allFiles($source, true) as $file) {
            $relative = $file->getRelativePathname();
            $targetPath = $destination.DIRECTORY_SEPARATOR.$relative;
            $ejectedRelativePaths[] = 'app/Domain/'.$domain.'/'.str_replace('\\', '/', $relative);

            if ($dryRun) {
                $this->line("  <fg=gray>backend</> app/Domain/{$domain}/".str_replace('\\', '/', $relative));
                $count++;

                continue;
            }

            $targetDir = dirname($targetPath);
            if (! $this->files->isDirectory($targetDir)) {
                $this->files->makeDirectory($targetDir, 0755, true);
            }

            $contents = $this->files->get($file->getPathname());

            if (str_ends_with($file->getFilename(), '.php')) {
                $contents = $this->rewriteDomainNamespace($contents, $domain);
            }

            $this->atomicPut($targetPath, $contents);
            $count++;
        }

        return $count;
    }

    // ══════════════════════════════════════════════════════════════════════
    // HTTP-LAYER EJECT (controller + FormRequests + route rewrite)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * v13.6.0 — eject a vendor-first module's HTTP layer into the consumer app:
     *   1. copy each manifest controller (single PHP file) into app/Http/..., with
     *      Lvntr\StarterKit\Http\ → App\Http\ rewrite;
     *   2. copy each manifest FormRequest directory recursively into app/Http/...,
     *      with the same rewrite;
     *   3. rewrite the consumer's route file's `use {vendorControllerFqcn};` back to
     *      the App\ FQCN (only when the exact line is found — otherwise warn + skip).
     *
     * The controller copy lands the App\ class that the file_exists-guarded alias in
     * StarterKitServiceProvider::backwardCompatAliasPlan() then yields to (the alias is
     * skipped once app/Http/Controllers/Admin/X.php exists), so the consumer copy wins.
     *
     * Single PHP files (not directories) follow the same per-file --force preserve
     * guard as the Vue eject — a customized controller/request is never silently
     * overwritten. Every written path is appended to $ejectedRelativePaths.
     *
     * @param  array{backend?: string, controllers?: array<string, string>, requests?: array<string, string>, resources?: array<string, string>, route?: array<string, string>}  $descriptor
     * @param  list<string>  $ejectedRelativePaths  (by ref)
     * @return array{controllers: int, requests: int, resources: int, skipped: list<string>, routeRewritten: list<string>, routeSkipped: list<string>}
     */
    private function ejectHttpLayer(string $domain, array $descriptor, bool $force, bool $dryRun, array &$ejectedRelativePaths): array
    {
        $controllers = $descriptor['controllers'] ?? [];
        $requests = $descriptor['requests'] ?? [];
        $resources = $descriptor['resources'] ?? [];
        $routes = $descriptor['route'] ?? [];

        // When this module also ejects its backend (descriptor.backend !== ''),
        // app/Domain/{Domain} now holds the App-namespaced runtime — so the copied
        // controller/request must point at THAT, not vendor. Without this, the App
        // controller keeps importing `Lvntr\StarterKit\Domain\{Domain}\...` and the
        // freshly-ejected app/Domain tree is dead code (and the injected App event
        // binding never fires, because the vendor action dispatches the vendor event).
        // Scoped to ONLY this domain's `Domain\{Domain}\` segment: a cross-domain
        // reference such as ApiRouteController's `Domain\Setting\SettingService`
        // stays vendor (Setting is not ejected here), and `Domain\Shared\` is never
        // rewritten. A Vue-only descriptor (Files, backend === '') has no backend to
        // wire and ships no controller, so the rewrite is correctly skipped.
        $rewriteDomain = ($descriptor['backend'] ?? '') !== '';

        $controllerCount = 0;
        $requestCount = 0;
        $resourceCount = 0;
        $skipped = [];
        $routeRewritten = [];
        $routeSkipped = [];

        // 1. Controllers — single PHP files, namespace-rewritten.
        foreach ($controllers as $sourceRelative => $destRelative) {
            $result = $this->copyHttpFile($sourceRelative, $destRelative, $domain, $rewriteDomain, $force, $dryRun, $ejectedRelativePaths);
            $controllerCount += $result['copied'];
            $skipped = [...$skipped, ...$result['skipped']];
        }

        // 2. FormRequest directories — recursive, namespace-rewritten.
        foreach ($requests as $sourceRelative => $destRelative) {
            $result = $this->copyHttpDirectory($sourceRelative, $destRelative, $domain, $rewriteDomain, $force, $dryRun, $ejectedRelativePaths);
            $requestCount += $result['copied'];
            $skipped = [...$skipped, ...$result['skipped']];
        }

        // 3. API Resource directories — recursive, namespace-rewritten. Identical
        // mechanics to FormRequests (Http\Resources\ flips to App\Http\Resources\
        // under the same rewriteHttpNamespace pass); split out only so the summary
        // can report resources distinctly. Added in v13.6.0 Faz 2 for the modules
        // that ship a vendor Resource (ApiClient, ApiToken, ContentLanguage).
        foreach ($resources as $sourceRelative => $destRelative) {
            $result = $this->copyHttpDirectory($sourceRelative, $destRelative, $domain, $rewriteDomain, $force, $dryRun, $ejectedRelativePaths);
            $resourceCount += $result['copied'];
            $skipped = [...$skipped, ...$result['skipped']];
        }

        // 4. Route file controller-import rewrite (vendor FQCN → App\ FQCN).
        foreach ($routes as $routeRelative => $vendorFqcn) {
            $rewritten = $this->rewriteRouteControllerImport($routeRelative, $vendorFqcn, $dryRun);
            if ($rewritten) {
                $routeRewritten[] = $routeRelative;
            } else {
                $routeSkipped[] = $routeRelative;
            }
        }

        return [
            'controllers' => $controllerCount,
            'requests' => $requestCount,
            'resources' => $resourceCount,
            'skipped' => $skipped,
            'routeRewritten' => $routeRewritten,
            'routeSkipped' => $routeSkipped,
        ];
    }

    /**
     * Copy a single vendor PHP file (controller) into the consumer app, rewriting
     * its HTTP namespaces Lvntr\StarterKit\Http\ → App\Http\ and — when this module
     * also ejects its backend ($rewriteDomain) — its own domain segment
     * Lvntr\StarterKit\Domain\{Domain}\ → App\Domain\{Domain}\, so the controller
     * wires to the just-ejected app/Domain runtime rather than vendor. An existing
     * destination is preserved unless --force (no silent overwrite of a customized
     * controller); a skipped path is still returned to the caller so it can be
     * stamped consumer-owned ('__ejected__'). Missing source is reported and skipped.
     *
     * @param  list<string>  $ejectedRelativePaths  (by ref)
     * @return array{copied: int, skipped: list<string>}
     */
    private function copyHttpFile(string $sourceRelative, string $destRelative, string $domain, bool $rewriteDomain, bool $force, bool $dryRun, array &$ejectedRelativePaths): array
    {
        $source = StarterKitServiceProvider::basePath($sourceRelative);
        $destination = $this->resolveDestination($destRelative);

        if (! $this->files->exists($source)) {
            $this->components->warn("Controller source not found, skipping: {$sourceRelative}");

            return ['copied' => 0, 'skipped' => []];
        }

        if (! $force && $this->files->exists($destination)) {
            if ($dryRun) {
                $this->line("  <fg=yellow>http skip</> {$destRelative} <fg=gray>(exists; --force to overwrite)</>");
            }

            return ['copied' => 0, 'skipped' => [$destRelative]];
        }

        $ejectedRelativePaths[] = $destRelative;

        if ($dryRun) {
            $this->line("  <fg=gray>http</> {$destRelative}");

            return ['copied' => 1, 'skipped' => []];
        }

        $dir = dirname($destination);
        if (! $this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        $contents = $this->rewriteHttpNamespace($this->files->get($source));
        if ($rewriteDomain) {
            $contents = $this->rewriteDomainNamespace($contents, $domain);
        }
        $this->atomicPut($destination, $contents);

        return ['copied' => 1, 'skipped' => []];
    }

    /**
     * Recursively copy a vendor FormRequest directory into the consumer app,
     * rewriting each PHP file's HTTP namespaces Lvntr\StarterKit\Http\ → App\Http\
     * and — when this module also ejects its backend ($rewriteDomain) — its own
     * domain segment Lvntr\StarterKit\Domain\{Domain}\ → App\Domain\{Domain}\ (a
     * Settings request's `Domain\Setting\SettingService` flips to App\, while its
     * cross-cutting `Domain\Shared\Services\DefinitionService` stays vendor). Per-file
     * --force preserve guard (mirrors copyVueDirectory); skipped paths are returned so
     * the caller can stamp them consumer-owned.
     *
     * @param  list<string>  $ejectedRelativePaths  (by ref)
     * @return array{copied: int, skipped: list<string>}
     */
    private function copyHttpDirectory(string $sourceRelative, string $destRelative, string $domain, bool $rewriteDomain, bool $force, bool $dryRun, array &$ejectedRelativePaths): array
    {
        $source = StarterKitServiceProvider::basePath($sourceRelative);

        if (! $this->files->isDirectory($source)) {
            $this->components->warn("Request source not found, skipping: {$sourceRelative}");

            return ['copied' => 0, 'skipped' => []];
        }

        $destinationBase = $this->resolveDestination($destRelative);
        $copied = 0;
        $skipped = [];

        foreach ($this->files->allFiles($source, true) as $file) {
            $relative = $file->getRelativePathname();
            $targetPath = $destinationBase.DIRECTORY_SEPARATOR.$relative;
            $displayPath = $destRelative.'/'.str_replace('\\', '/', $relative);

            if (! $force && $this->files->exists($targetPath)) {
                $skipped[] = $displayPath;
                if ($dryRun) {
                    $this->line('  <fg=yellow>http skip</> '.$displayPath.' <fg=gray>(exists; --force to overwrite)</>');
                }

                continue;
            }

            $ejectedRelativePaths[] = $displayPath;

            if ($dryRun) {
                $this->line('  <fg=gray>http</> '.$displayPath);
                $copied++;

                continue;
            }

            $targetDir = dirname($targetPath);
            if (! $this->files->isDirectory($targetDir)) {
                $this->files->makeDirectory($targetDir, 0755, true);
            }

            $contents = $this->files->get($file->getPathname());
            if (str_ends_with($file->getFilename(), '.php')) {
                $contents = $this->rewriteHttpNamespace($contents);
                if ($rewriteDomain) {
                    $contents = $this->rewriteDomainNamespace($contents, $domain);
                }
            }

            $this->atomicPut($targetPath, $contents);
            $copied++;
        }

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /**
     * Rewrite the vendor HTTP root namespace `Lvntr\StarterKit\Http\` → `App\Http\`.
     *
     * Deliberately scoped to the `Http\` segment so the controller/request's own
     * namespace AND its module FormRequest imports flip to App\, while every other
     * vendor reference is preserved verbatim by THIS pass:
     *   - `Lvntr\StarterKit\Domain\...` is untouched here. The ejected module's own
     *     `Domain\{Domain}\` segment is flipped separately by rewriteDomainNamespace()
     *     (called right after this when descriptor.backend !== '', since the same eject
     *     also relocates the backend), so the controller binds to app/Domain. A
     *     cross-domain reference (ApiRouteController's `Domain\Setting\SettingService`,
     *     when Setting is not ejected) and `Domain\Shared\` stay vendor;
     *   - `Lvntr\StarterKit\Http\Responses\ApiResponse` → becomes `App\Http\Responses\
     *     ApiResponse`, which StarterKitServiceProvider provides as an unconditional
     *     vendor alias, so it resolves correctly either way;
     *   - `Lvntr\StarterKit\Support\...` is left untouched (no `Http\` segment).
     *
     * The trailing backslash after `Http` is a hard boundary so a hypothetical
     * `Lvntr\StarterKit\HttpClient` (no such class today) could never be caught.
     */
    private function rewriteHttpNamespace(string $contents): string
    {
        return (string) preg_replace(
            '/\bLvntr\\\\StarterKit\\\\Http\\\\/',
            'App\\Http\\',
            $contents,
        );
    }

    /**
     * Rewrite a consumer route file's controller import from the vendor FQCN back
     * to the App\ FQCN so the ejected (App-namespaced) controller is used. The
     * rewrite is intentionally conservative: it fires ONLY when the exact
     * `use {vendorFqcn};` line is present. If the route file is missing or has been
     * customized (the expected import is absent — e.g. the user already rewrote it,
     * imports under an alias, or restructured the file), nothing is changed and a
     * warning is printed so the user can adjust it by hand. Returns true when an
     * actual rewrite happened (or would happen on dry-run).
     */
    private function rewriteRouteControllerImport(string $routeRelative, string $vendorFqcn, bool $dryRun): bool
    {
        $routePath = $this->resolveDestination($routeRelative);

        if (! $this->files->exists($routePath)) {
            $this->components->warn("Route file not found, skipping import rewrite: {$routeRelative}");

            return false;
        }

        $appFqcn = $this->vendorHttpFqcnToApp($vendorFqcn);

        $contents = $this->files->get($routePath);

        $vendorUse = 'use '.$vendorFqcn.';';
        $appUse = 'use '.$appFqcn.';';

        // Already pointing at the App\ FQCN (re-eject / hand-migrated) → nothing to do.
        if (str_contains($contents, $appUse)) {
            return true;
        }

        if (! str_contains($contents, $vendorUse)) {
            $this->components->warn(
                "Route file customized — expected import not found, left untouched: {$routeRelative}"
            );
            $this->line("  <fg=gray>Update its controller import to: {$appUse}</>");

            return false;
        }

        if ($dryRun) {
            $this->line("  <fg=gray>route</> {$routeRelative} <fg=gray>(import → {$appFqcn})</>");

            return true;
        }

        $this->files->put($routePath, str_replace($vendorUse, $appUse, $contents));

        return true;
    }

    /**
     * Map a vendor HTTP FQCN (`Lvntr\StarterKit\Http\...`) to its App\ counterpart
     * (`App\Http\...`). Mirrors rewriteHttpNamespace() at the FQCN level.
     */
    private function vendorHttpFqcnToApp(string $vendorFqcn): string
    {
        return (string) preg_replace(
            '/^Lvntr\\\\StarterKit\\\\Http\\\\/',
            'App\\Http\\',
            $vendorFqcn,
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // HASH REGISTRY STAMP (mark ejected files consumer-owned)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Stamp every ejected app-relative path with the '__ejected__' sentinel in the
     * starter-kit hash registry. sk:update's removeVendorMigratedPaths() treats
     * '__ejected__' like '__deleted__'/'__skipped__' — it never removes the file —
     * so a freshly ejected module survives subsequent updates as consumer-owned.
     *
     * No-op when there is nothing to stamp or when no real Composer project is at
     * the destination (isolated --destination test trees have no storage path the
     * consumer config points at; we still write next to the resolved hash file).
     *
     * @param  list<string>  $relativePaths
     */
    private function stampEjectedHashes(array $relativePaths): void
    {
        if ($relativePaths === []) {
            return;
        }

        $hashFile = $this->ejectHashRegistryFile();

        $hashes = [];
        $registryExisted = false;
        if ($this->files->exists($hashFile)) {
            $decoded = json_decode($this->files->get($hashFile), true);
            if (is_array($decoded)) {
                $hashes = $decoded;
                $registryExisted = true;
            }
        }

        foreach (array_unique($relativePaths) as $relativePath) {
            $hashes[$relativePath] = '__ejected__';
        }

        // Stamp '_format' = 'v2' ONLY when we are creating a fresh registry, or the
        // existing one already declares v2. The '__ejected__' sentinels above are
        // format-agnostic — sk:update checks them BEFORE any hash comparison
        // (processVendorMigratedLayer), so an ejected module survives regardless.
        //
        // The danger is a pre-existing v1 (or format-less) registry: those entries
        // are TARGET hashes, not stub hashes. Stamping it 'v2' would make
        // UpdateCommand::loadHashRegistry() skip the v1→v2 re-derivation
        // (migrateHashRegistry) and read v1 target-hashes as if they were v2
        // stub-hashes — corrupting every tracked file's removable/preserve decision
        // on the next update. So we leave a legacy registry's format untouched and
        // let the next sk:update migrate it correctly.
        if (! $registryExisted || ($hashes['_format'] ?? null) === 'v2') {
            $hashes['_format'] = 'v2';
        }

        $dir = dirname($hashFile);
        if (! $this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        $this->atomicPut($hashFile, json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Resolve the hash registry path. With no --destination this is the consumer's
     * configured registry (storage/starter-kit/hashes.json by default — the exact
     * path sk:install/sk:update read). With --destination (isolated test tree) it is
     * rooted under that override so an eject test never touches the real registry.
     */
    private function ejectHashRegistryFile(): string
    {
        $override = $this->option('destination');

        if (is_string($override) && $override !== '') {
            return rtrim($override, DIRECTORY_SEPARATOR).'/storage/starter-kit/hashes.json';
        }

        return config('starter-kit.published_hashes', storage_path('starter-kit/hashes.json'));
    }

    /**
     * Rewrite ONLY the ejected domain's root namespace segment
     * `Lvntr\StarterKit\Domain\{Name}\` → `App\Domain\{Name}\`.
     *
     * Deliberately narrow: the trailing backslash in the pattern is a hard
     * boundary, so `Domain\Shared\` (the SAFE_UPDATE base, never ejected),
     * other un-ejected domains, `Http\Responses\ApiResponse`, and any
     * third-party `Lvntr\StarterKit\*` reference are left exactly as written.
     * `preg_quote` keeps the domain name literal so it can never act as a
     * regex metacharacter.
     */
    private function rewriteDomainNamespace(string $contents, string $domain): string
    {
        $quoted = preg_quote($domain, '/');

        $pattern = '/\bLvntr\\\\StarterKit\\\\Domain\\\\'.$quoted.'\\\\/';

        return (string) preg_replace($pattern, 'App\\Domain\\'.$domain.'\\', $contents);
    }

    // ══════════════════════════════════════════════════════════════════════
    // VUE EJECT (refresh pages from stubs)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Copy the manifest's source→app Vue mappings. An existing destination file is
     * preserved unless --force is passed (symmetric with the backend's
     * directory-level guard in handle()) — eject must never silently overwrite a
     * consumer's customized page. Missing files are always written.
     *
     * v13.6.0: for vendor-first modules the source is the PACKAGE copy
     * (resources/js/pages/Admin/...); for User/Role it is still stubs/. Both resolve
     * relative to the package root via StarterKitServiceProvider::basePath(). Every
     * written app path is appended to $ejectedRelativePaths.
     *
     * @param  array<string, string>  $vueMap
     * @param  list<string>  $ejectedRelativePaths  (by ref)
     * @return array{copied: int, skipped: list<string>}
     */
    private function ejectVue(string $domain, array $vueMap, bool $force, bool $dryRun, array &$ejectedRelativePaths): array
    {
        if ($vueMap === []) {
            return ['copied' => 0, 'skipped' => []];
        }

        $copied = 0;
        $skipped = [];

        foreach ($vueMap as $sourceRelative => $destRelative) {
            $source = StarterKitServiceProvider::basePath($sourceRelative);
            $destination = $this->resolveDestination($destRelative);

            if (! $this->files->exists($source)) {
                $this->components->warn("Vue source not found, skipping: {$sourceRelative}");

                continue;
            }

            if ($this->files->isDirectory($source)) {
                $result = $this->copyVueDirectory($source, $destination, $force, $dryRun, $destRelative, $ejectedRelativePaths);
                $copied += $result['copied'];
                $skipped = [...$skipped, ...$result['skipped']];

                continue;
            }

            // Single-file mapping (e.g. types/user.ts): preserve unless --force.
            if (! $force && $this->files->exists($destination)) {
                $skipped[] = $destRelative;
                if ($dryRun) {
                    $this->line("  <fg=yellow>vue skip</> {$destRelative} <fg=gray>(exists; --force to overwrite)</>");
                }

                continue;
            }

            $ejectedRelativePaths[] = $destRelative;

            if ($dryRun) {
                $this->line("  <fg=gray>vue</> {$destRelative}");
                $copied++;

                continue;
            }

            $dir = dirname($destination);
            if (! $this->files->isDirectory($dir)) {
                $this->files->makeDirectory($dir, 0755, true);
            }

            $this->files->copy($source, $destination);
            $copied++;
        }

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /**
     * Recursively copy a Vue directory. Each existing destination file is
     * preserved unless --force is passed; missing files are always written.
     * Returns the copied count and the display paths that were skipped. Every
     * written app path is appended to $ejectedRelativePaths.
     *
     * @param  list<string>  $ejectedRelativePaths  (by ref)
     * @return array{copied: int, skipped: list<string>}
     */
    private function copyVueDirectory(string $source, string $destination, bool $force, bool $dryRun, string $destRelative, array &$ejectedRelativePaths): array
    {
        $copied = 0;
        $skipped = [];

        foreach ($this->files->allFiles($source, true) as $file) {
            $relative = $file->getRelativePathname();
            $targetPath = $destination.DIRECTORY_SEPARATOR.$relative;
            $displayPath = $destRelative.'/'.str_replace('\\', '/', $relative);

            if (! $force && $this->files->exists($targetPath)) {
                $skipped[] = $displayPath;
                if ($dryRun) {
                    $this->line('  <fg=yellow>vue skip</> '.$displayPath.' <fg=gray>(exists; --force to overwrite)</>');
                }

                continue;
            }

            $ejectedRelativePaths[] = $displayPath;

            if ($dryRun) {
                $this->line('  <fg=gray>vue</> '.$displayPath);
                $copied++;

                continue;
            }

            $targetDir = dirname($targetPath);
            if (! $this->files->isDirectory($targetDir)) {
                $this->files->makeDirectory($targetDir, 0755, true);
            }

            $this->files->copy($file->getPathname(), $targetPath);
            $copied++;
        }

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    // ══════════════════════════════════════════════════════════════════════
    // EVENT BINDING INJECTION (DomainServiceProvider)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Inject App-FQCN `Event::listen(\App\Domain\{Name}\Events\{E}::class,
     * \App\Domain\{Name}\Listeners\{L}::class);` statements into the boot()
     * method of app/Providers/DomainServiceProvider.php.
     *
     * Why this is required: after ejection the consumer's App action dispatches
     * the App event, but the vendor binding in registerEventListeners() is keyed
     * by the VENDOR FQCN — it never matches the App-namespaced dispatch, so the
     * audit listener would silently stop. Re-binding with App FQCNs here keeps it
     * firing. No double-fire: the vendor binding becomes dormant (the vendor
     * event is never dispatched once the App action owns the flow).
     *
     * Idempotent: a binding whose App event+listener FQCNs are already present in
     * the file is not added again.
     *
     * @param  array<string, string>  $events  EventShortName => ListenerShortName
     * @return list<string> the bindings that were (or would be, on dry-run) added
     */
    private function injectEventBindings(string $domain, array $events, bool $dryRun): array
    {
        if ($events === []) {
            return [];
        }

        $providerPath = $this->resolveDestination('app/Providers/DomainServiceProvider.php');

        if (! $this->files->exists($providerPath)) {
            $this->components->warn('DomainServiceProvider not found — event bindings skipped: app/Providers/DomainServiceProvider.php');

            return [];
        }

        $existingCode = $this->files->get($providerPath);

        $toAdd = [];
        foreach ($events as $event => $listener) {
            $eventFqcn = 'App\\Domain\\'.$domain.'\\Events\\'.$event;
            $listenerFqcn = 'App\\Domain\\'.$domain.'\\Listeners\\'.$listener;

            // Idempotency: skip when both FQCNs already appear in the same file.
            // Matching on the bare FQCN strings (with or without a leading
            // backslash) is robust against pretty-printer spacing differences.
            if ($this->bindingAlreadyPresent($existingCode, $eventFqcn, $listenerFqcn)) {
                continue;
            }

            $toAdd[] = [$eventFqcn, $listenerFqcn];
        }

        if ($toAdd === []) {
            return [];
        }

        $added = [];
        foreach ($toAdd as [$eventFqcn, $listenerFqcn]) {
            $added[] = "\\{$eventFqcn}::class → \\{$listenerFqcn}::class";
        }

        if ($dryRun) {
            return $added;
        }

        $updated = $this->insertBindingsIntoBoot($existingCode, $toAdd);

        if ($updated === null) {
            $this->components->warn('Could not locate boot() in DomainServiceProvider — event bindings skipped. Add them manually:');
            foreach ($added as $line) {
                $this->line('  <fg=gray>→</> Event::listen('.$line.');');
            }

            return [];
        }

        $this->atomicPut($providerPath, $updated);

        return $added;
    }

    /**
     * Insert fully-qualified `Event::listen(...)` lines as the FIRST statements of
     * the provider's `boot()` body, immediately after its opening brace.
     *
     * A targeted string insertion is used (not AST pretty-printing) because the
     * shipped stub's boot() body is comment-only: PhpParser's
     * `printFormatPreserving` silently drops statements appended to a body it
     * cannot anchor to an existing token, whereas anchoring on the literal opening
     * brace is deterministic and leaves the rest of the file byte-for-byte intact.
     * Indentation is inferred from the brace's own line. Returns null when the
     * `boot()` signature cannot be found.
     *
     * @param  list<array{0: string, 1: string}>  $toAdd  [eventFqcn, listenerFqcn] pairs
     */
    private function insertBindingsIntoBoot(string $code, array $toAdd): ?string
    {
        // Match `function boot(...)` ... up to and including its opening brace,
        // tolerant of a return type and arbitrary whitespace/newlines before `{`.
        if (! preg_match('/function\s+boot\s*\([^)]*\)\s*(?::\s*[^\{]+)?\{/', $code, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $bracePos = $m[0][1] + strlen($m[0][0]); // position just after the `{`

        // Indentation of the method body = the method line's indent + one level.
        $lineStart = strrpos(substr($code, 0, $m[0][1]), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $methodIndent = '';
        for ($i = $lineStart; $i < strlen($code) && ($code[$i] === ' ' || $code[$i] === "\t"); $i++) {
            $methodIndent .= $code[$i];
        }
        $bodyIndent = $methodIndent.'    ';

        $lines = '';
        if ($toAdd !== []) {
            // Marker so a consumer reading their own provider after eject is not
            // misled by the shipped stub's "bindings are omitted" note further
            // down — that note predates the eject; these App-owned bindings now
            // match the (rewritten) App event dispatch and fire exactly once.
            $lines .= "\n".$bodyIndent.'// sk:eject: App\\Domain event bindings injected below (the explanatory note further down predates the eject).';
        }
        foreach ($toAdd as [$eventFqcn, $listenerFqcn]) {
            $lines .= "\n".$bodyIndent.'\\Illuminate\\Support\\Facades\\Event::listen('
                .'\\'.$eventFqcn.'::class, \\'.$listenerFqcn.'::class);';
        }

        return substr($code, 0, $bracePos).$lines.substr($code, $bracePos);
    }

    /**
     * Whether a binding for the given App event + listener FQCNs is already
     * declared anywhere in the provider source. Tolerant of an optional leading
     * backslash so `\App\...::class` and `App\...::class` both count as present.
     */
    private function bindingAlreadyPresent(string $code, string $eventFqcn, string $listenerFqcn): bool
    {
        $eventNeedle = '\\'.$eventFqcn.'::class';
        $listenerNeedle = '\\'.$listenerFqcn.'::class';

        $hasEvent = str_contains($code, $eventNeedle) || str_contains($code, $eventFqcn.'::class');
        $hasListener = str_contains($code, $listenerNeedle) || str_contains($code, $listenerFqcn.'::class');

        return $hasEvent && $hasListener;
    }

    // ══════════════════════════════════════════════════════════════════════
    // AUTOLOAD
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Regenerate Composer's autoload so the newly ejected app/Domain classes are
     * discoverable. After this the backwardCompatAliasPlan() file_exists() guard
     * sees the App copy and stops aliasing the domain to vendor — the consumer
     * copy wins.
     *
     * @return bool false when the dump ran and failed (caller exits non-zero);
     *              true when it succeeded or there was nothing to dump.
     */
    private function refreshAutoload(): bool
    {
        $base = $this->resolveDestination('');

        // Only dump autoload against a real Composer project (a composer.json at
        // the destination root). Isolated test destinations have none — skipping
        // there keeps the command side-effect-free under --destination and is not
        // a failure.
        if (! $this->files->exists($base.DIRECTORY_SEPARATOR.'composer.json')) {
            return true;
        }

        $composer = $this->findComposerBinary($base);
        $process = new Process([...$composer, 'dump-autoload', '-q'], $base, null, null, 120);
        $process->run();

        // A silent failure here is dangerous: with the alias now disabled, the
        // ejected App\Domain\{Name}\* classes must land in the regenerated
        // autoload map or they will not resolve under optimized /
        // classmap-authoritative autoloaders. Surface it AND report failure so the
        // command exits non-zero — a broken autoload must not look like a
        // successful eject to CI/scripts.
        if (! $process->isSuccessful()) {
            $this->components->warn(
                'composer dump-autoload failed — run it manually so the ejected classes are discoverable '
                .'(required under optimized / classmap-authoritative autoloaders).'
            );
            $error = trim($process->getErrorOutput());
            if ($error !== '') {
                $this->line('  <fg=gray>'.$error.'</>');
            }

            return false;
        }

        return true;
    }

    /**
     * Locate the composer executable for the given working directory.
     *
     * @return list<string>
     */
    private function findComposerBinary(string $base): array
    {
        if ($this->files->exists($base.DIRECTORY_SEPARATOR.'composer.phar')) {
            return [PHP_BINARY, $base.DIRECTORY_SEPARATOR.'composer.phar'];
        }

        return ['composer'];
    }

    // ══════════════════════════════════════════════════════════════════════
    // SUMMARY
    // ══════════════════════════════════════════════════════════════════════

    /**
     * @param  array{controllers: int, requests: int, resources: int, skipped: list<string>, routeRewritten: list<string>, routeSkipped: list<string>}  $httpResult
     * @param  array{copied: int, skipped: list<string>}  $vueResult
     * @param  list<string>  $bindings
     */
    private function printSummary(string $domain, int $backendCount, array $httpResult, array $vueResult, array $bindings, bool $noVue, bool $dryRun, bool $autoloadOk = true, bool $skipAutoload = false): void
    {
        $hasBackend = isset(self::DOMAIN_MANIFEST[$domain]) && self::DOMAIN_MANIFEST[$domain]['backend'] !== '';

        $this->newLine();

        $verb = $dryRun ? 'Would eject' : 'Ejected';
        $this->components->info("{$verb} domain {$domain}.");

        if ($hasBackend) {
            $this->components->twoColumnDetail('<fg=green>Backend files</>', (string) $backendCount.' → app/Domain/'.$domain);
        } else {
            $this->components->twoColumnDetail('<fg=gray>Backend</>', 'kept in vendor (Vue-only eject)');
        }

        // v13.6.0 vendor-first HTTP layer summary (controllers + requests +
        // resources + route).
        $resourceCount = $httpResult['resources'] ?? 0;
        if ($httpResult['controllers'] > 0 || $httpResult['requests'] > 0 || $resourceCount > 0) {
            $this->components->twoColumnDetail(
                '<fg=green>HTTP files</>',
                $httpResult['controllers'].' controller(s), '.$httpResult['requests'].' request(s), '.$resourceCount.' resource(s) → app/Http'
            );
        }

        if ($httpResult['routeRewritten'] !== []) {
            $rverb = $dryRun ? 'would rewrite import in' : 'rewrote import in';
            $this->components->twoColumnDetail('<fg=green>Route</>', $rverb.' '.implode(', ', $httpResult['routeRewritten']));
        }

        if ($httpResult['routeSkipped'] !== []) {
            $this->components->twoColumnDetail(
                '<fg=yellow>Route preserved</>',
                count($httpResult['routeSkipped']).' route file(s) left untouched (customized/missing) — update the controller import by hand'
            );
            foreach ($httpResult['routeSkipped'] as $path) {
                $this->line('  <fg=gray>•</> '.$path);
            }
        }

        if ($httpResult['skipped'] !== []) {
            $this->components->twoColumnDetail(
                '<fg=yellow>HTTP preserved</>',
                count($httpResult['skipped']).' existing file(s) left untouched — pass --force to overwrite'
            );
            foreach ($httpResult['skipped'] as $path) {
                $this->line('  <fg=gray>•</> '.$path);
            }
        }

        if ($noVue) {
            $this->components->twoColumnDetail('<fg=yellow>Vue pages</>', 'skipped (--no-vue)');
        } else {
            $this->components->twoColumnDetail('<fg=green>Vue files</>', (string) $vueResult['copied']);

            if ($vueResult['skipped'] !== []) {
                $this->components->twoColumnDetail(
                    '<fg=yellow>Vue preserved</>',
                    count($vueResult['skipped']).' existing file(s) left untouched — pass --force to overwrite'
                );
                foreach ($vueResult['skipped'] as $path) {
                    $this->line('  <fg=gray>•</> '.$path);
                }
            }
        }

        if ($bindings !== []) {
            $verb = $dryRun ? 'would inject' : 'injected';
            $this->components->twoColumnDetail('<fg=green>Event bindings</>', count($bindings)." {$verb} into DomainServiceProvider");
            foreach ($bindings as $binding) {
                $this->line('  <fg=gray>→</> '.$binding);
            }
        }

        // The controller/backend alias is only relevant when something with an alias
        // was ejected (a backend domain or a vendor-first controller). A Vue-only
        // eject (Files) flips no class alias — the page wins via app.ts app-first.
        $aliasRelevant = $hasBackend || $httpResult['controllers'] > 0;
        if ($aliasRelevant) {
            $aliasVerb = $dryRun ? 'will be' : 'is now';
            $this->components->twoColumnDetail('<fg=gray>Backward-compat alias</>', "{$aliasVerb} disabled for {$domain} (your copy wins)");
        }

        $this->newLine();

        if (! $dryRun) {
            if ($hasBackend) {
                $this->components->warn("You now own app/Domain/{$domain}. The kit will NOT ship security/bugfix updates for this domain to you anymore.");
                $this->line('  <fg=gray>To revert: delete app/Domain/'.$domain.', remove the injected Event::listen lines from</>');
                $this->line('  <fg=gray>app/Providers/DomainServiceProvider.php, then run `composer dump-autoload`.</>');
            } else {
                $this->components->warn("You now own the {$domain} Vue pages. The kit will NOT ship UI updates for them to you anymore.");
                $this->line('  <fg=gray>To revert: delete the copied resources/js/pages/Admin/'.$domain.' files; the vendor pages take over via app.ts.</>');
            }
        }

        // --skip-autoload: the eject ran no dump on purpose; tell the reader the
        // caller is responsible so the missing dump is not mistaken for the
        // (suppressed) "classes may not load" error below.
        if (! $dryRun && $skipAutoload) {
            $this->components->twoColumnDetail(
                '<fg=gray>Autoload</>',
                'dump skipped (caller will run composer dump-autoload)'
            );
        }

        // Only a REAL failed dump prints this error. It can never fire under
        // --skip-autoload because $autoloadOk stays true when the dump is skipped.
        if (! $dryRun && ! $skipAutoload && ! $autoloadOk) {
            $this->components->error(
                'Autoload regeneration FAILED — files are ejected but the new classes may not load until you run '
                .'`composer dump-autoload`. The command exits non-zero so CI/scripts halt.'
            );
        }

        $this->newLine();
    }

    // ══════════════════════════════════════════════════════════════════════
    // PATH RESOLUTION
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Resolve an absolute destination path. With no --destination the path is
     * rooted at base_path() (real consumer app); with --destination it is rooted
     * at the override so tests can eject into an isolated tree. Mirrors
     * PublishCommand::resolveDestination.
     */
    private function resolveDestination(string $relative): string
    {
        $override = $this->option('destination');

        if (! is_string($override) || $override === '') {
            return $relative === '' ? base_path() : base_path($relative);
        }

        $root = rtrim($override, DIRECTORY_SEPARATOR);

        if ($relative === '') {
            return $root;
        }

        return $root.DIRECTORY_SEPARATOR.ltrim($relative, DIRECTORY_SEPARATOR);
    }
}
