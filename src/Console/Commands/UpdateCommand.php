<?php

namespace Lvntr\StarterKit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\Console\Commands\Concerns\ChecksStepResults;
use Lvntr\StarterKit\Console\Commands\Concerns\ComparesPublishedStubs;
use Lvntr\StarterKit\Console\Commands\Concerns\MirrorsAiSkills;
use Lvntr\StarterKit\Console\Commands\Concerns\RefusesPackageSourceTree;
use Lvntr\StarterKit\Console\Commands\Concerns\WritesFilesAtomically;
use Lvntr\StarterKit\StarterKitServiceProvider;
use Lvntr\StarterKit\Support\DocsLink;
use Lvntr\StarterKit\Support\KitDependencies;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\spin;

class UpdateCommand extends Command
{
    use ChecksStepResults;
    use ComparesPublishedStubs;
    use MirrorsAiSkills;
    use RefusesPackageSourceTree;
    use WritesFilesAtomically;

    protected $signature = 'sk:update
        {--force : Overwrite all files including user-modified ones}
        {--dry-run : Show what would be updated without making changes}
        {--without-ai-skill : Skip regenerating the .codex/skills AI-skill mirror}';

    protected $description = 'Update Starter Kit files while preserving user modifications';

    private Filesystem $files;

    /**
     * Files/directories the package owns and refreshes on every update.
     *
     * "The user should not modify" was the design intent, never an enforced
     * one — and `app/Enums/PermissionEnum.php` actively invites the opposite:
     * it is a backed enum with public `for()` / `allFor()` helpers, so adding a
     * project ability (`case Approve = 'approve';`) is the obvious thing to do.
     * See `updateSafePaths()` for what happens to such an edit now, and why the
     * copy is no longer unconditional.
     *
     * v13.5.0+: runtime files (Domain/Shared, Traits, Helpers, ApiResponse,
     * Middleware/SecurityHeaders, Exceptions) are now vendor-resident and are
     * NOT copied to the application. Only app-owned scaffold files remain here.
     *
     * @var list<string>
     */
    private const SAFE_UPDATE_PATHS = [
        // PermissionEnum is generated per-project but kept in sync with package constants
        'app/Enums/PermissionEnum.php',
    ];

    /**
     * Paths that were previously published to the application but are now
     * vendor-resident (v13.5.0+). These are NOT copied during update.
     * Displayed as an informational notice if still present in the application.
     *
     * @var list<string>
     */
    private const VENDOR_RESIDENT_PATHS = [
        'app/Domain/FileManager/',
        'app/Domain/Shared/',
        'app/Domain/ActivityLog/',
        'app/Domain/Logs/',
        'app/Domain/Session/',
        'app/Domain/Media/',
        // v13.6.0: ApiClient + ApiRoute runtime moved to vendor. Only the
        // pure-runtime Domain/ subtrees are vendor-resident; the HTTP layer
        // (controllers/requests/resources/policies) and Postman/Apidog console
        // commands stay app-owned. Existing app copies of these Domain/ dirs are
        // reported here only — never force-deleted (they may be customized, and
        // any app copy keeps WINNING via the alias-skip override invariant).
        'app/Domain/ApiClient/',
        'app/Domain/ApiRoute/',
        // v13.6.0: Setting runtime moved to vendor — SettingService
        // (encryption/cache core), Actions, settings DTOs and SettingsDefaultsQuery.
        // Only the pure-runtime Domain/Setting/ subtree is vendor-resident; the
        // Setting MODEL (app/Models/Setting.php, static facade), SettingPolicy,
        // config/settings.php and the _03_SettingSeeder stay app-owned. Existing app
        // copies of Domain/Setting/ are reported here only — never force-deleted (they
        // may be customized, and any app copy keeps WINNING via the alias-skip override
        // invariant).
        // v13.6.0: SettingsController + Settings FormRequests ALSO moved to vendor
        // (Lvntr\StarterKit\Http\...); they are migrated via VENDOR_MIGRATED_PATHS
        // (hash-guarded removal), not reported here.
        'app/Domain/Setting/',
        // v13.6.0: User + Role runtime moved to vendor — Actions, DTOs, Events,
        // Listeners and Queries (incl. rank-hierarchy queries and the audit
        // event/listener pairs). Only these pure-runtime subtrees are vendor-resident;
        // the User/Role MODELS (Spatie HasRoles + Fortify / extends Spatie Role),
        // Store/UpdateRoleRequest privilege-boundary, controllers, resources, policies,
        // Actions/Fortify/CreateNewUser, the permission-resources.php matrix and RoleEnum
        // all stay app-owned. Domain/{User,Role}/BulkActions/ ALSO stays app-owned (the
        // BulkDelete*Action classes extend the app-owned App\Http\BulkActions\
        // BulkDeleteAction override base) — hence the per-subdirectory listing below
        // instead of a blanket app/Domain/{User,Role}/ prefix. Existing app copies of
        // the listed dirs are reported here only — never force-deleted (they may be
        // customized, and any app copy keeps WINNING via the alias-skip override
        // invariant). The audit event→listener bindings moved to the vendor
        // StarterKitServiceProvider::registerEventListeners().
        'app/Domain/User/Actions/',
        'app/Domain/User/DTOs/',
        'app/Domain/User/Events/',
        'app/Domain/User/Listeners/',
        'app/Domain/User/Queries/',
        'app/Domain/Role/Actions/',
        'app/Domain/Role/DTOs/',
        'app/Domain/Role/Events/',
        'app/Domain/Role/Listeners/',
        'app/Domain/Role/Queries/',
        'app/Traits/HasActivityLogging.php',
        'app/Traits/HasMediaCollections.php',
        'app/Helpers/sk-helpers.php',
        'app/Http/Middleware/CheckResourcePermission.php',
        'app/Http/Middleware/SecurityHeaders.php',
        'app/Http/Middleware/AssignTraceId.php',
        'app/Http/Middleware/SetLocale.php',
        'app/Http/Middleware/ValidateTurnstile.php',
        'app/Exceptions/ApiException.php',
        'app/Exceptions/ApiExceptionHandler.php',
        'app/Http/Controllers/FileManagerController.php',
        'app/Http/Requests/FileManager/',
        'app/Console/Commands/PurgeFileManagerTrash.php',
        'app/Support/HtmlSanitizer.php',
        'app/Support/TranslatableQueryHelpers.php',
        'app/Support/MediaPathGenerator.php',
        'app/Support/HasTranslatableRules.php',
        'app/Support/Scramble/ApiResponseExtension.php',
        // v13.6.x: the developer component showcase moved into the package
        // (src/Http/Controllers/ComponentShowcaseController.php +
        // src/routes/sk-components.php + resources/js/pages/SkComponents/,
        // auto-mounted at /sk-components). Old published copies keep working —
        // their /components/* route names never clash with the vendor mount —
        // and are reported here only (they may be customized).
        'app/Http/Controllers/Admin/ComponentShowcaseController.php',
        'routes/web/components-route.php',
        'resources/js/pages/Admin/Components/',
        // These third-party configs are no longer published — the kit applies
        // its required overrides from vendor at runtime (StarterKitServiceProvider
        // ::applyVendorConfigDefaults). Publishing them again is optional.
        'config/media-library.php',
        'config/activitylog.php',
        'config/inertia.php',
        // v13.6.0: the kit's `sk-*` UI translations are now vendor-resident
        // (vendor/lvntr/laravel-starter-kit/resources/lang/{en,tr}/sk-*.php) and the
        // install bulk-copy was removed. The vendor copies resolve namespace-less at
        // runtime (StarterKitServiceProvider::registerNamespacelessKitTranslations)
        // and feed the frontend via a pre-compiled JSON merged app-wins in app.ts.
        // Existing consumers may still have app/lang copies (possibly customized
        // translations) — these are NOT force-deleted. They are reported here only;
        // any app copy keeps WINNING over the vendor copy (override invariant), and
        // new vendor keys still flow in for keys the app copy does not define.
        // `lang/{en,tr}/validation.php` is intentionally NOT listed: it stays a
        // consumer-owned framework-default override stub.
        'lang/en/sk-activity-log.php',
        'lang/en/sk-api-clients.php',
        'lang/en/sk-api-route.php',
        'lang/en/sk-api-tokens.php',
        'lang/en/sk-auth.php',
        'lang/en/sk-avatar.php',
        'lang/en/sk-bulk.php',
        'lang/en/sk-button.php',
        'lang/en/sk-common.php',
        'lang/en/sk-datatable.php',
        'lang/en/sk-editor.php',
        'lang/en/sk-file-manager.php',
        'lang/en/sk-file.php',
        'lang/en/sk-layout.php',
        'lang/en/sk-log.php',
        'lang/en/sk-menu.php',
        'lang/en/sk-message.php',
        'lang/en/sk-profile.php',
        'lang/en/sk-role.php',
        'lang/en/sk-setting.php',
        'lang/en/sk-system-health.php',
        'lang/en/sk-user.php',
        'lang/tr/sk-activity-log.php',
        'lang/tr/sk-api-clients.php',
        'lang/tr/sk-api-route.php',
        'lang/tr/sk-api-tokens.php',
        'lang/tr/sk-auth.php',
        'lang/tr/sk-avatar.php',
        'lang/tr/sk-bulk.php',
        'lang/tr/sk-button.php',
        'lang/tr/sk-common.php',
        'lang/tr/sk-datatable.php',
        'lang/tr/sk-editor.php',
        'lang/tr/sk-file-manager.php',
        'lang/tr/sk-file.php',
        'lang/tr/sk-layout.php',
        'lang/tr/sk-log.php',
        'lang/tr/sk-menu.php',
        'lang/tr/sk-message.php',
        'lang/tr/sk-profile.php',
        'lang/tr/sk-role.php',
        'lang/tr/sk-setting.php',
        'lang/tr/sk-system-health.php',
        'lang/tr/sk-user.php',
    ];

    /**
     * Files that are NEVER updated — only installed once.
     * These are user-customizable config/template files.
     *
     * @var list<string>
     */
    private const NEVER_UPDATE_PATHS = [
        'config/permission-resources.php',
        // v13.6.0: the sensitive-keys whitelist. Setting runtime (SettingService)
        // is now vendor-resident and reads config('settings.sensitive_keys') at runtime,
        // but the whitelist itself is consumer-owned — consumers add their own sensitive
        // keys (and other settings) here. Never overwrite it on update, or a consumer's
        // added sensitive key would be lost and that value would be stored plaintext.
        'config/settings.php',
        'node_modules/',
    ];

    /**
     * Files/directories removed from the package that should be cleaned up.
     * These are deleted during update if they exist in the application.
     *
     * @var list<string>
     */
    private const DEPRECATED_PATHS = [
        'app/Enums/EnumRegistry.php',
        'app/Enums/Contracts/HasDefinition.php',
        'app/Enums/Attributes/InertiaShared.php',
        'app/Enums/Contracts/',
        'app/Enums/Attributes/',
        'app/Enums/IdentityType.php',
        'app/Enums/YesNo.php',
        'app/Traits/HasEnumAccessors.php',
        'resources/js/composables/useEnum.ts',
        'app/Console/Commands/EnvSyncCommand.php',
        'app/Console/Commands/MakeDomainCommand.php',
        'app/Console/Commands/RemoveDomainCommand.php',
        // app/Http/Responses/ApiResponse.php is intentionally NOT tracked: it is
        // no longer shipped as a stub. App\Http\Responses\ApiResponse is provided
        // as an unconditional vendor alias by StarterKitServiceProvider (it has no
        // valid consumer override — a subclass breaks query return-type covariance).
        'resources/js/pages/Admin/Settings/components/AuthTab.vue',
        'resources/js/pages/Admin/Settings/components/TurnstileTab.vue',
        'resources/js/components/Auth/TurnstileWidget.vue',
        // v13.6.0: kit-specific migrations moved from stubs into the package
        // (vendor-resident database/migrations/, auto-loaded via loadMigrationsFrom
        // + config('starter-kit.run_migrations')). Old app copies are force-deleted
        // here; this is safe because each basename is already recorded in the
        // consumer's `migrations` table, and Laravel keys history by basename — the
        // single vendor copy is "already run" and never re-executes. Removing the
        // app copy also eliminates the vendor↔app basename duplicate, so the silent
        // keyBy(basename) dedupe becomes deterministic (one source of truth).
        'database/migrations/2026_03_08_205445_create_media_table.php',
        'database/migrations/2026_03_11_071628_create_activity_log_table.php',
        'database/migrations/2026_03_12_001950_create_definitions_table.php',
        'database/migrations/2026_03_14_080933_create_settings_table.php',
        'database/migrations/2026_04_13_100200_add_folder_id_to_media_table.php',
        'database/migrations/2026_05_02_094121_add_soft_deletes_to_media_table.php',
    ];

    /**
     * Stale import specifiers from components that moved out of stubs into the
     *
     * @lvntr/components vendor library. sk:update force-deletes the old local copy
     * (see DEPRECATED_PATHS), but consumer page files the user customized are NOT
     * refreshed by the hash-tracked updater — they keep importing the deleted local
     * path and the Vite build dies with an ENOENT load-fallback error. This map
     * rewrites those stale specifiers to the vendor path so update is self-healing.
     *
     * v13.5.12+: TurnstileWidget moved to @lvntr/components/ui/TurnstileWidget.vue;
     * the Auth pages (Login/Register/ForgotPassword) are user-owned, so their stale
     * `@/components/Auth/TurnstileWidget.vue` import survives the move and breaks.
     *
     * @var array<string, string>
     */
    private const STALE_IMPORT_REWRITES = [
        '@/components/Auth/TurnstileWidget.vue' => '@lvntr/components/ui/TurnstileWidget.vue',
    ];

    /**
     * Deprecated files that were previously copied into the app but may have been
     * edited by the user, so they are NOT force-deleted like DEPRECATED_PATHS.
     *
     * v13.5.14+: the theme build tooling (sk-theme-build.mjs / vite-plugin-sk-theme.mjs)
     * moved from `stubs/scripts/` into the package itself (resources/js/theme/, vendor-
     * resident). Old consumer copies under `scripts/` are obsolete and removed here —
     * but only when the on-disk content matches a known shipped version. If the user
     * customized the script, the copy is left in place with a warning so their edits
     * are never silently destroyed.
     *
     * Map of relative path => list of known-stock md5 hashes (every version the kit
     * ever shipped). A copy matching any of these is provably unmodified and safe to
     * delete; anything else is treated as user-customized.
     *
     * @var array<string, list<string>>
     */
    private const DEPRECATED_MODIFIABLE_PATHS = [
        'scripts/sk-theme-build.mjs' => [
            '933a01f1ff82cbf8a29b6e7f792f64da',
            'd1fc9be90d365a4bafef4fc8e468ae4b',
        ],
        'scripts/vite-plugin-sk-theme.mjs' => [
            'f4ffbd6afadbd8be82c776ccded8bed2',
            'fdcee61d27adf60cf7e3bc23df7d876d',
        ],
    ];

    /**
     * v13.6.0 — behavior-module HTTP + UI layers (Files, Logs, ActivityLogs,
     * ApiRoutes, Settings) moved vendor-first. Their controllers + FormRequests
     * now live in Lvntr\StarterKit\Http\... and their Vue pages under the package
     * resources/js/pages/Admin/... (resolved via app.ts app-first / vendor-fallback);
     * sk:install no longer copies them. Existing consumers may still hold the old
     * published copies, so these are migrated under a hash-guard: a copy whose
     * on-disk content matches the hash recorded for it at install/update time is
     * provably unmodified and removed (the vendor copy then takes over — the
     * controller/request via the file_exists-guarded alias in
     * StarterKitServiceProvider::backwardCompatAliasPlan(), the Vue page via the
     * app.ts vendor fallback). A copy with no record, or one that differs from its
     * record, is treated as user-customized.
     *
     * Removal is decided per MODULE GROUP, not per file (see
     * VENDOR_MIGRATED_MODULES — the source of truth for the migration logic). This
     * flat list is the union of every path the grouped manifest covers, kept as a
     * stable single-array surface for callers/tests that just need "every covered
     * path". A sync test guards the two against drift.
     *
     * Unlike DEPRECATED_MODIFIABLE_PATHS (single files with fixed stock-hash lists),
     * these are whole directory trees with per-version, per-consumer-variable
     * content, so the registry-recorded hash is the ONLY safe unmodified signal —
     * there is no stock-hash list to compare against. Directory entries (trailing
     * "/") are walked recursively; bare files are matched directly.
     *
     * @var list<string>
     */
    private const VENDOR_MIGRATED_PATHS = [
        // Vue pages (Task 1 — moved to package resources/js/pages/Admin/...).
        'resources/js/pages/Admin/Files/',
        'resources/js/pages/Admin/Logs/',
        'resources/js/pages/Admin/ActivityLogs/',
        'resources/js/pages/Admin/ApiRoutes/',
        'resources/js/pages/Admin/Settings/',
        // Controllers + FormRequests (Task 2 — moved to src/Http/...).
        'app/Http/Controllers/Admin/LogController.php',
        'app/Http/Controllers/Admin/ActivityLogController.php',
        'app/Http/Controllers/Admin/ApiRouteController.php',
        'app/Http/Controllers/Admin/SettingsController.php',
        'app/Http/Requests/Admin/Log/',
        'app/Http/Requests/Admin/Settings/',
        // v13.6.0 Faz 2 — Settings-tab handlers + Definitions/MediaUpload + the
        // full ContentLanguage stack (controller/request/resource/domain). The Vue
        // layer for these tabs already shipped vendor-first in Faz 1 (under
        // resources/js/pages/Admin/Settings/), so this wave is php-only. The
        // App\Models\* classes are intentionally NOT listed — the models stay
        // app-owned (see backwardCompatAliasPlan invariant).
        'app/Http/Controllers/Admin/ApiClientController.php',
        'app/Http/Controllers/Admin/ApiTokenController.php',
        'app/Http/Controllers/Admin/SystemHealthController.php',
        'app/Http/Controllers/Admin/ContentLanguageController.php',
        'app/Http/Controllers/Api/DefinitionController.php',
        'app/Http/Controllers/Api/MediaUploadController.php',
        'app/Http/Controllers/Service/DefinitionServiceController.php',
        'app/Http/Requests/Admin/ApiClient/',
        'app/Http/Requests/Admin/ApiToken/',
        'app/Http/Requests/Admin/ContentLanguage/',
        'app/Http/Resources/Admin/ApiClient/',
        'app/Http/Resources/Admin/ApiToken/',
        'app/Http/Resources/Admin/ContentLanguage/',
        'app/Domain/ContentLanguage/',
    ];

    /**
     * v13.6.0 — the vendor-first behavior modules, grouped by module and split
     * into two INDEPENDENT removal layers:
     *
     *   - `php`: the controller + FormRequest directory tree. These resolve via
     *     the server-side alias bridge (backwardCompatAliasPlan), so removing the
     *     consumer copy is safe regardless of app.ts state.
     *   - `vue`: the Inertia page tree. These resolve only via the app.ts
     *     vendor-fallback ('@lvntr/pages' glob) — a guard checks app.ts ships that
     *     fallback before any Vue group is removed (see removeVendorMigratedPaths).
     *
     * Removal is decided per LAYER GROUP, atomically: if even ONE file in a
     * module's layer is preserved (user-modified or untracked), the WHOLE layer is
     * preserved. This prevents a half-deleted module — e.g. a consumer who edited
     * only one Settings FormRequest must keep the matching SettingsController too,
     * because the vendor controller type-hints its requests by vendor FQCN and
     * would never call the consumer's hardened (validation/authorize) request; and
     * a half-deleted Vue tree would break the consumer build, since vendor pages
     * import their sibling components by relative path. The php and vue layers of
     * the same module are evaluated independently of each other.
     *
     * @var array<string, array{php: list<string>, vue: list<string>}>
     */
    private const VENDOR_MIGRATED_MODULES = [
        'Files' => [
            // Files is a Vue-only module (its backend is the vendor FileManager
            // runtime — no app-owned controller/request tree was ever published).
            'php' => [],
            'vue' => [
                'resources/js/pages/Admin/Files/',
            ],
        ],
        'Logs' => [
            'php' => [
                'app/Http/Controllers/Admin/LogController.php',
                'app/Http/Requests/Admin/Log/',
            ],
            'vue' => [
                'resources/js/pages/Admin/Logs/',
            ],
        ],
        'ActivityLogs' => [
            'php' => [
                'app/Http/Controllers/Admin/ActivityLogController.php',
            ],
            'vue' => [
                'resources/js/pages/Admin/ActivityLogs/',
            ],
        ],
        'ApiRoutes' => [
            'php' => [
                'app/Http/Controllers/Admin/ApiRouteController.php',
            ],
            'vue' => [
                'resources/js/pages/Admin/ApiRoutes/',
            ],
        ],
        'Settings' => [
            'php' => [
                'app/Http/Controllers/Admin/SettingsController.php',
                'app/Http/Requests/Admin/Settings/',
            ],
            'vue' => [
                'resources/js/pages/Admin/Settings/',
            ],
        ],
        // v13.6.0 Faz 2 — php-only modules. The Settings-tab Vue (ApiClient,
        // ApiToken, SystemHealth, ContentLanguage tabs) already shipped vendor-
        // first in Faz 1 under the 'Settings' group's Vue layer above, so these
        // groups carry an empty 'vue' layer — the flat-path drift-guard stays
        // exact (no duplicate Settings tree). Each group is still removed
        // atomically: a single user-modified file preserves the whole php layer
        // (the vendor controller type-hints its sibling request/resource by vendor
        // FQCN and would never call a half-removed consumer copy). No
        // App\Models\* path appears in any layer — the models stay app-owned.
        'ApiClient' => [
            'php' => [
                'app/Http/Controllers/Admin/ApiClientController.php',
                'app/Http/Requests/Admin/ApiClient/',
                'app/Http/Resources/Admin/ApiClient/',
            ],
            'vue' => [],
        ],
        'ApiToken' => [
            'php' => [
                'app/Http/Controllers/Admin/ApiTokenController.php',
                'app/Http/Requests/Admin/ApiToken/',
                'app/Http/Resources/Admin/ApiToken/',
            ],
            'vue' => [],
        ],
        'SystemHealth' => [
            'php' => [
                'app/Http/Controllers/Admin/SystemHealthController.php',
            ],
            'vue' => [],
        ],
        'Definitions' => [
            'php' => [
                'app/Http/Controllers/Api/DefinitionController.php',
                'app/Http/Controllers/Service/DefinitionServiceController.php',
            ],
            'vue' => [],
        ],
        'MediaUpload' => [
            'php' => [
                'app/Http/Controllers/Api/MediaUploadController.php',
            ],
            'vue' => [],
        ],
        'ContentLanguage' => [
            // Full Tier 3 stack: controller + request + resource + domain.
            // The App\Models\ContentLanguage model is NOT here (app-owned).
            'php' => [
                'app/Http/Controllers/Admin/ContentLanguageController.php',
                'app/Http/Requests/Admin/ContentLanguage/',
                'app/Http/Resources/Admin/ContentLanguage/',
                'app/Domain/ContentLanguage/',
            ],
            'vue' => [],
        ],
    ];

    /**
     * The app.ts marker proving the consumer's resolver ships the vendor page
     * fallback. Vue groups are only ever removed when this string is present in
     * resources/js/app.ts; otherwise the deleted pages would have no resolver and
     * the 5 screens would fail to build. The PHP layer is unaffected by this (its
     * alias bridge is server-side).
     */
    private const APP_TS_VENDOR_PAGE_MARKER = '@lvntr/pages';

    /** @var list<string> */
    private array $updated = [];

    /** @var list<string> */
    private array $skipped = [];

    /** @var list<string> */
    private array $added = [];

    /** @var list<string> Files with no hash record (untracked) */
    private array $untracked = [];

    /** @var list<string> */
    private array $removed = [];

    /** @var list<string> Deprecated files left in place because the user modified them */
    private array $preservedDeprecated = [];

    /** @var list<string> Package-owned (safe-path) files left in place because the user modified them */
    private array $safePathConflicts = [];

    public function handle(): int
    {
        if (! $this->option('dry-run') && $this->isPackageSourceTree()) {
            return $this->renderPackageSourceTreeStop();
        }

        $this->files = new Filesystem;

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>Lvntr Starter Kit Updater (v13.7.x)</>');
        $this->newLine();
        $this->line('  <fg=gray>v13.5.0+: package runtime runs from vendor/lvntr/laravel-starter-kit.</>');
        $this->line('  <fg=gray>composer update is enough; runtime files are not copied to your app.</>');
        $this->newLine();

        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        // 1. Refresh package-owned files — but never over a consumer edit
        $this->updateSafePaths($force, $dryRun);

        // 1b. Remove deprecated files
        $this->removeDeprecatedPaths($dryRun);

        // 1c. Remove deprecated-but-possibly-modified files (hash-guarded so user
        // edits are never silently destroyed).
        $this->removeDeprecatedModifiablePaths($dryRun);

        // 1d. Migrate behavior-module HTTP + UI to vendor (v13.6.0): remove the old
        // published copies of controllers/requests/Vue pages, but only the ones the
        // user has not modified (registry-hash guarded). Modified copies are left in
        // place and reported — they keep winning over the vendor copy.
        $this->removeVendorMigratedPaths($dryRun);

        // 1e. Rewrite stale import paths in user-owned files that reference a
        // component which moved out of stubs into the @lvntr/components vendor
        // library (its local copy was force-deleted above). Keeps the build from
        // dying with an ENOENT load-fallback on the deleted path.
        $this->migrateStaleImports($dryRun);

        // 2. Update user-modifiable files only if not modified
        $this->updateModifiableFiles($force, $dryRun);

        // 3. Handle untracked files (no hash record — ask user)
        if (! $force && ! empty($this->untracked)) {
            $this->resolveUntrackedFiles($dryRun);
        }

        // 4. Add new files that don't exist yet
        $this->addNewFiles($dryRun);

        // 4b. Inject filesystem config if missing (added in later versions)
        if (! $dryRun) {
            $this->injectFilesystemsConfig();
            $this->injectRoleColorsConfig();
            $this->migrateLegacyHelpersFile();
            $this->rewriteHelpersAutoload();
        }

        // 4c. Merge stub package.json into the consumer's package.json so newly added
        // npm dependencies (e.g. the @tiptap/* set added with EditorInput in v13.4.x)
        // land automatically on `sk:update` instead of forcing every consumer to copy
        // them by hand. Stub versions win for shared keys; user extras are preserved.
        if (! $dryRun) {
            $this->mergePackageJson();
        }

        // 5. Run new migrations
        //
        // spin() hands back the callback's value and it used to be dropped on the
        // floor: a `migrate` that died still printed "Migrations completed." and
        // the command still exited 0. The result is now the command's result.
        $failedSteps = [];

        if (! $dryRun && ! empty($this->added) && $this->hasNewMigrations()) {
            if (confirm('New migrations found. Run them now?', default: true)) {
                $migrated = spin(function () {
                    return $this->runArtisan('migrate', ['--force' => true]);
                }, 'Running new migrations...');

                if ($migrated) {
                    $this->components->info('Migrations completed.');
                } else {
                    $failedSteps[] = 'Running new migrations';
                    $this->components->error($this->stepFailureDetail ?? 'Migrations failed.');
                    $this->stepFailureDetail = null;
                }
            }
        }

        // 5b. role_colors was just injected → the roles.color column (vendor-resident
        // migration) and a seeder run are needed for the colors to appear.
        if (! $dryRun && in_array('config/permission-resources.php (injected role_colors)', $this->updated, true)) {
            if (confirm('Role colors added. Run migrate + sk:seed-permissions to apply them now?', default: true)) {
                $applied = spin(function () {
                    return $this->runArtisan('migrate', ['--force' => true]);
                }, 'Running migrations...');

                $applied = $applied && spin(function () {
                    return $this->runArtisan('sk:seed-permissions');
                }, 'Seeding role colors...');

                if ($applied) {
                    $this->components->info('Role colors applied.');
                } else {
                    $failedSteps[] = 'Applying role colors (migrate + sk:seed-permissions)';
                    $this->components->error($this->stepFailureDetail ?? 'Role colors could not be applied.');
                    $this->stepFailureDetail = null;
                }
            }
        }

        // 5c. Republish api-dock's compiled panel.
        //
        // The bundle in public/vendor/api-dock is vendor output that Composer
        // never places, and nothing else in the update path publishes it: a
        // consumer who deploys with `composer install` (the usual production
        // path) does not run Laravel's `post-update-cmd`, which is where the
        // stock skeleton republishes the `laravel-assets` tag. Without this the
        // panel keeps serving the PREVIOUS release's JS/CSS against a newer
        // package — or has no assets at all on the release that introduced it.
        //
        // Unconditional and unprompted: an asset directory carries no user
        // edits to protect, and a half-updated bundle is a broken panel.
        if (! $dryRun) {
            $published = spin(function () {
                return $this->runArtisan('vendor:publish', [
                    '--tag' => 'api-dock-assets',
                    '--force' => true,
                ]);
            }, 'Publishing API Dock panel assets...');

            if (! $published) {
                $failedSteps[] = 'Publishing API Dock panel assets';
                $this->components->error($this->stepFailureDetail ?? 'API Dock assets could not be published.');
                $this->stepFailureDetail = null;
            }
        }

        // 6. Update hash registry
        if (! $dryRun) {
            $this->updateHashRegistry();
        }

        // 6b. Flush the cached backward-compat alias manifest. This run may have
        // added or removed app/ override files (removeVendorMigratedPaths /
        // addNewFiles) without a composer dump-autoload, so the manifest's
        // mtime-based self-invalidation would miss it — clear it explicitly so
        // the ServiceProvider rebuilds a fresh plan on the next request.
        if (! $dryRun) {
            StarterKitServiceProvider::flushBackwardCompatAliasCache();
        }

        // 6c. Regenerate only the kit-owned Codex skills from the consumer's
        // Claude copies. User-owned .codex skills are outside this mirror.
        // Best-effort: a mirror failure must not fail an otherwise completed update.
        try {
            $hashes = $this->loadHashRegistry();
            $this->mirrorAiSkills(
                dryRun: $dryRun,
                skipped: $this->option('without-ai-skill') || $this->aiSkillsWereSkipped($hashes),
            );
        } catch (\Throwable $e) {
            $this->components->warn('AI skills could not be mirrored to .codex/skills: '.$e->getMessage());
        }

        // Optional: inform user about vendor-resident paths still in app/
        $this->printVendorResidentNotice();

        // Summary
        $this->newLine();
        $this->printSummary($dryRun);

        // Reported after the summary rather than inside it: a missing kit
        // dependency is true regardless of whether this run touched any file,
        // and printSummary() returns early on "Everything is up to date!".
        $this->reportMissingDependencies($dryRun);

        // A failed database step does not roll back the file work above, and the
        // hash registry deliberately still records it: the stub copies really are
        // on disk, and a registry that pretended otherwise would make the NEXT
        // update treat every one of them as untracked. What changes is the exit
        // code — the run reports the failure it actually had.
        if ($failedSteps !== []) {
            $this->newLine();
            $this->line(
                '  <fg=red;options=bold>Update failed at step "'.implode('", "', $failedSteps)
                .'" — fix the issue, then re-run `php artisan sk:update`.</>'
            );
            $this->newLine();

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Refresh the package-owned files listed in SAFE_UPDATE_PATHS.
     *
     * This loop used to copy the stub over whatever was on disk unless the two
     * files happened to be byte-identical — no registry lookup, no backup, no
     * prompt, and no line in the summary. A consumer who had added an ability
     * case to `app/Enums/PermissionEnum.php` therefore lost it on the next
     * `sk:update` and had no way to notice: the enum feeds
     * `config/permission-resources.php`, so the loss surfaces later as
     * permissions that no longer seed.
     *
     * The decision rule is now the SAME one every consumer-owned file already
     * gets from `updateModifiableFiles()` — the hash recorded at install/update
     * time is the only trusted "unmodified" signal:
     *
     *   - identical to the stub  → nothing to do
     *   - target missing         → copy (first install of that file)
     *   - `--force`              → copy, as documented
     *   - `__skipped__` record   → an install-time opt-out; honoured, untouched
     *   - hash === recorded      → provably untouched, copy
     *   - no record (or the file was re-created after a recorded deletion)
     *                            → untracked: routed to the same multiselect
     *                              prompt the rest of the update uses, because
     *                              guessing here is what caused the data loss
     *   - hash differs           → consumer-edited: PRESERVE and report
     *
     * Preserving does leave the copy stale, and stale is not free — the package
     * expects its own cases to exist. That is why a preserved safe path is
     * reported separately from the generic skip list, with the merge
     * instruction attached (`printSummary()`).
     */
    private function updateSafePaths(bool $force, bool $dryRun): void
    {
        $stubsPath = StarterKitServiceProvider::stubsPath();
        $hashes = $this->loadHashRegistry();

        foreach (self::SAFE_UPDATE_PATHS as $safePath) {
            $stubSource = $stubsPath.DIRECTORY_SEPARATOR.$safePath;

            if (str_ends_with($safePath, '/')) {
                // Directory
                if (! $this->files->isDirectory($stubSource)) {
                    continue;
                }

                foreach ($this->files->allFiles($stubSource, true) as $file) {
                    $relativePath = $safePath.str_replace('\\', '/', $file->getRelativePathname());

                    $this->updateSafePath($relativePath, $file->getPathname(), $hashes, $force, $dryRun);
                }

                continue;
            }

            // Single file
            if (! $this->files->exists($stubSource)) {
                continue;
            }

            $this->updateSafePath($safePath, $stubSource, $hashes, $force, $dryRun);
        }
    }

    /**
     * Apply the safe-path decision rule to a single file.
     *
     * Split out so the directory and single-file branches cannot drift apart:
     * the guard is worth nothing if only one of the two paths through the loop
     * consults it.
     *
     * The rule itself lives in {@see ComparesPublishedStubs} — sk:install runs
     * the same one over its publish loop, and a second copy of it here would be
     * a second copy of a data-loss guard.
     *
     * STUB_UP_TO_DATE is folded into the conflict report rather than passing
     * silently: this command's whole purpose is to tell the operator that a
     * package-owned file is out of sync, and a preserved copy is out of sync
     * whether or not the stub itself moved in this release.
     *
     * @param  array<string, string>  $hashes  registry, path => stub hash at install/update time
     */
    private function updateSafePath(string $relativePath, string $stubSource, array $hashes, bool $force, bool $dryRun): void
    {
        $target = base_path($relativePath);
        $targetExists = $this->files->exists($target);

        // md5_file() is false for an unreadable path; '' / null keep that case
        // on the conservative side of the rule instead of raising a TypeError.
        $decision = $this->decidePublishedStub(
            md5_file($stubSource) ?: '',
            $targetExists ? (md5_file($target) ?: null) : null,
            $hashes[$relativePath] ?? null,
            $force,
        );

        switch ($decision) {
            // Already current, or opted out at install time (e.g.
            // --without-ai-skill) — the file on disk is the consumer's business.
            case self::STUB_IDENTICAL:
            case self::STUB_OPTED_OUT:
                return;

                // No usable record: the copy predates hash tracking, or it was
                // re-created after a recorded deletion. Either way we cannot tell
                // a stale stock file from an edited one — so we ask.
            case self::STUB_UNTRACKED:
                $this->untracked[] = $relativePath;

                return;

            case self::STUB_UP_TO_DATE:
            case self::STUB_MODIFIED:
                $this->skipped[] = $relativePath;
                $this->safePathConflicts[] = $relativePath;

                return;
        }

        if (! $dryRun) {
            $this->ensureDirectoryExists(dirname($target));
            $this->files->copy($stubSource, $target);
        }

        $this->updated[] = $relativePath;
    }

    /**
     * Remove deprecated files/directories that are no longer part of the package.
     */
    private function removeDeprecatedPaths(bool $dryRun): void
    {
        foreach (self::DEPRECATED_PATHS as $path) {
            $target = base_path($path);

            if (str_ends_with($path, '/')) {
                // Directory — remove only if empty
                if ($this->files->isDirectory($target) && empty($this->files->files($target))) {
                    if (! $dryRun) {
                        $this->files->deleteDirectory($target);
                    }
                    $this->removed[] = $path;
                }
            } else {
                // File
                if ($this->files->exists($target)) {
                    if (! $dryRun) {
                        $this->files->delete($target);
                    }
                    $this->removed[] = $path;
                }
            }
        }
    }

    /**
     * Remove deprecated files that may have been customized by the user.
     *
     * Unlike removeDeprecatedPaths(), these are deleted only when the on-disk
     * content matches a known shipped version (any entry in the path's stock-hash
     * list) OR matches the hash we recorded for it at install/update time. A copy
     * that matches neither is assumed to be user-modified: it is left untouched and
     * reported so the user can migrate their edits manually — no silent data loss.
     *
     * `--force` removes the file regardless of modification, mirroring the override
     * semantics used elsewhere in this command.
     */
    private function removeDeprecatedModifiablePaths(bool $dryRun): void
    {
        $force = (bool) $this->option('force');
        $hashes = $this->loadHashRegistry();
        $registryDirty = false;

        foreach (self::DEPRECATED_MODIFIABLE_PATHS as $path => $stockHashes) {
            $target = base_path($path);

            if (! $this->files->exists($target)) {
                continue;
            }

            $currentHash = md5_file($target);
            $recordedHash = $hashes[$path] ?? null;

            $isUnmodified = $this->deprecatedCopyIsUnmodified($currentHash, $stockHashes, $recordedHash);

            if (! $force && ! $isUnmodified) {
                // User customized the obsolete script — preserve it and warn.
                $this->preservedDeprecated[] = $path;

                continue;
            }

            if (! $dryRun) {
                $this->files->delete($target);

                // Drop any stale registry entry for the removed obsolete path so
                // it does not linger forever. The file no longer ships from stubs,
                // so a '__deleted__' sentinel is unnecessary.
                if (array_key_exists($path, $hashes)) {
                    unset($hashes[$path]);
                    $registryDirty = true;
                }
            }

            $this->removed[] = $path;
        }

        if ($registryDirty && ! $dryRun) {
            $this->saveHashRegistry($hashes);
        }
    }

    /**
     * Decide whether a deprecated, possibly-modified copy is provably unmodified
     * and therefore safe to delete. True when its current hash matches any known
     * shipped (stock) version, or matches the hash recorded for it in the registry.
     * Anything else is treated as user-customized and must be preserved.
     *
     * Pure hash-in / bool-out so the modified-copy safety rule can be unit tested
     * without filesystem access.
     *
     * @param  list<string>  $stockHashes
     */
    private function deprecatedCopyIsUnmodified(string $currentHash, array $stockHashes, ?string $recordedHash): bool
    {
        if (in_array($currentHash, $stockHashes, true)) {
            return true;
        }

        return $recordedHash !== null && $recordedHash === $currentHash;
    }

    /**
     * Migrate the v13.6.0 vendor-first behavior modules: delete the consumer's old
     * published controller/request/Vue copies, but decide removal per MODULE LAYER
     * GROUP — never per individual file. A layer is removed only when EVERY file in
     * it is provably unmodified (its on-disk hash equals the hash recorded for it in
     * the registry). If even one file is preserved (user-modified, untracked, or
     * sentinel-guarded), the ENTIRE layer is preserved.
     *
     * Why group-atomic:
     *   - PHP layer: the vendor controller type-hints its FormRequests by vendor
     *     FQCN. Deleting an unmodified controller while a customized request stays
     *     on disk would route to the vendor controller, which never calls the
     *     consumer's hardened (validation/authorize) request — a one-way alias
     *     bridge (App→vendor) with no vendor→App path. So a single preserved file
     *     pins the whole controller+request group.
     *   - Vue layer: vendor pages import sibling components by relative path; a
     *     partial deletion breaks the consumer build. The tree is all-or-nothing.
     *
     * The php and vue layers of the same module are evaluated INDEPENDENTLY.
     *
     * Vue groups carry an extra precondition: the consumer's resources/js/app.ts
     * must ship the '@lvntr/pages' vendor-fallback resolver. Without it the deleted
     * pages have no resolver and 5 screens fail to build, so when the marker is
     * absent ALL Vue groups are preserved and an actionable warning is printed. The
     * PHP layer is never affected by app.ts (its alias bridge is server-side).
     *
     * A removed file's registry entry becomes a '__deleted__' sentinel so a later
     * sk:install / sk:update never restores it (these no longer ship from stubs, so
     * the sentinel keeps the deletion durable across a cleared storage/). A
     * preserved file is reported so the user keeps WINNING — the controller/request
     * via the file_exists-guarded alias (backwardCompatAliasPlan), the Vue page via
     * the app.ts vendor fallback. `--force` removes regardless of modification,
     * mirroring the override semantics used elsewhere; it does NOT bypass the
     * '__ejected__' guard or the app.ts Vue precondition.
     */
    private function removeVendorMigratedPaths(bool $dryRun): void
    {
        $force = (bool) $this->option('force');
        $hashes = $this->loadHashRegistry();
        $registryDirty = false;

        $appTsHasVendorFallback = $this->appTsShipsVendorPageFallback();

        foreach (self::VENDOR_MIGRATED_MODULES as $layers) {
            foreach (['php', 'vue'] as $layer) {
                $entries = $layers[$layer] ?? [];

                if ($entries === []) {
                    continue;
                }

                // Vue pages resolve only via the app.ts vendor fallback. Without
                // that resolver, deleting them would break the build — preserve the
                // whole group (and let the one-time warning below explain why).
                if ($layer === 'vue' && ! $appTsHasVendorFallback) {
                    foreach ($this->vendorMigratedLayerFiles($entries) as $relativePath) {
                        $this->preservedDeprecated[] = $relativePath;
                    }

                    continue;
                }

                $registryDirty = $this->processVendorMigratedLayer(
                    $entries,
                    $hashes,
                    $force,
                    $dryRun,
                ) || $registryDirty;
            }
        }

        if ($registryDirty && ! $dryRun) {
            $this->saveHashRegistry($hashes);
        }

        if (! $appTsHasVendorFallback && $this->vendorMigratedModulesHaveVuePages()) {
            $this->warnAppTsVendorFallbackMissing();
        }
    }

    /**
     * Evaluate a single module layer (php or vue) as one atomic unit. Removal is
     * all-or-nothing: the layer is deleted only if EVERY file in it is removable
     * (unmodified vs. its registry record, or --force). If any file is preserved
     * the whole layer is preserved. Sentinel-recorded files ('__deleted__' /
     * '__skipped__' / '__ejected__') are treated as already-resolved: they neither
     * block the group nor get re-stamped — the prior decision stands.
     *
     * Returns whether the hash registry was mutated (so the caller can persist it).
     *
     * @param  list<string>  $entries  the layer's path entries (dirs and/or files)
     * @param  array<string, string>  $hashes  by-reference registry
     */
    private function processVendorMigratedLayer(array $entries, array &$hashes, bool $force, bool $dryRun): bool
    {
        $present = [];   // [relativePath => currentHash] for files we may delete
        $blocked = false;

        foreach ($this->vendorMigratedLayerFiles($entries) as $relativePath) {
            $target = base_path($relativePath);

            if (! $this->files->exists($target)) {
                continue;
            }

            $recordedHash = $hashes[$relativePath] ?? null;

            // Sentinels are already-resolved decisions: skip without touching and
            // without letting them block the rest of the group. The '__ejected__'
            // guard sits BEFORE the --force path on purpose — this sweep removes
            // OLD published copies, never the user's own ejected module, so even
            // --force must leave an ejected copy alone (the consumer revokes it by
            // deleting the file, not via sk:update).
            if ($recordedHash === '__deleted__' || $recordedHash === '__skipped__' || $recordedHash === '__ejected__') {
                continue;
            }

            $currentHash = md5_file($target);

            if (! $this->vendorMigratedCopyIsRemovable($currentHash, $recordedHash, $force)) {
                // No record (untracked) or content differs → user-customized. One
                // preserved file pins the WHOLE group (atomicity).
                $blocked = true;

                continue;
            }

            $present[$relativePath] = $currentHash;
        }

        if ($blocked) {
            // Atomic preserve: report every still-present file in the group so the
            // user sees the complete set their edit is keeping consumer-owned.
            foreach ($this->vendorMigratedLayerFiles($entries) as $relativePath) {
                if ($this->files->exists(base_path($relativePath))) {
                    $this->preservedDeprecated[] = $relativePath;
                }
            }

            return false;
        }

        if ($present === []) {
            return false;
        }

        $registryDirty = false;

        foreach ($present as $relativePath => $_currentHash) {
            $target = base_path($relativePath);

            if (! $dryRun) {
                $this->files->delete($target);
                $this->pruneEmptyAncestors(dirname($target));

                // Persist the deletion so install/update never restore it.
                $hashes[$relativePath] = '__deleted__';
                $registryDirty = true;
            }

            $this->removed[] = $relativePath;
        }

        return $registryDirty;
    }

    /**
     * Expand a layer's path entries into the concrete relative file paths they
     * cover (directories walked recursively, bare files passed through).
     *
     * @param  list<string>  $entries
     * @return list<string>
     */
    private function vendorMigratedLayerFiles(array $entries): array
    {
        $files = [];

        foreach ($entries as $entry) {
            foreach ($this->vendorMigratedRelativeFiles($entry) as $relativePath) {
                $files[] = $relativePath;
            }
        }

        return $files;
    }

    /**
     * Whether the consumer's resources/js/app.ts ships the vendor page fallback
     * resolver ('@lvntr/pages' glob). True when the marker is present. When app.ts
     * is absent we return false (no resolver guarantee → preserve Vue, warn).
     */
    private function appTsShipsVendorPageFallback(): bool
    {
        $appTs = base_path('resources/js/app.ts');

        if (! $this->files->exists($appTs)) {
            return false;
        }

        return str_contains($this->files->get($appTs), self::APP_TS_VENDOR_PAGE_MARKER);
    }

    /**
     * Whether any vendor-migrated module still has Vue page files on disk. Used to
     * suppress the app.ts warning when there is nothing to migrate anyway.
     */
    private function vendorMigratedModulesHaveVuePages(): bool
    {
        foreach (self::VENDOR_MIGRATED_MODULES as $layers) {
            foreach ($this->vendorMigratedLayerFiles($layers['vue'] ?? []) as $relativePath) {
                if ($this->files->exists(base_path($relativePath))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Warn that Vue groups were preserved because app.ts lacks the vendor page
     * fallback, with the concrete remediation steps.
     */
    private function warnAppTsVendorFallbackMissing(): void
    {
        $this->newLine();
        $this->components->warn('resources/js/app.ts has no vendor page fallback — the 5 behavior-module Vue trees were left in place.');
        $this->line('  <fg=gray>The migrated pages resolve through the \'@lvntr/pages\' fallback in app.ts, which your</>');
        $this->line('  <fg=gray>copy does not ship yet. Deleting them now would leave the screens unresolvable at build.</>');
        $this->line('  <fg=gray>Update app.ts first (sk:update can refresh it if your copy is unmodified), then re-run</>');
        $this->line('  <fg=gray>sk:update to migrate the Vue trees. Controllers/requests were unaffected (server-side alias).</>');
        $this->newLine();
    }

    /**
     * Decide whether a vendor-migrated app copy may be safely deleted. Unlike the
     * theme-script rule there is NO stock-hash list — the only safe unmodified signal
     * is an exact match against the hash recorded for the file at install/update time.
     * A file with no record (untracked) or one whose content differs from its record
     * is treated as user-customized and preserved. `--force` removes it regardless.
     *
     * Pure hash-in / bool-out so the destructive decision can be unit tested without
     * filesystem access (mirrors deprecatedCopyIsUnmodified). Sentinel records
     * ('__deleted__'/'__skipped__') are filtered by the caller before this is reached.
     */
    private function vendorMigratedCopyIsRemovable(string $currentHash, ?string $recordedHash, bool $force): bool
    {
        if ($force) {
            return true;
        }

        return $recordedHash !== null && $recordedHash === $currentHash;
    }

    /**
     * Expand a VENDOR_MIGRATED_PATHS entry into the concrete relative file paths it
     * covers. A directory entry (trailing "/") yields every file under it (only
     * those that still exist on disk); a bare file yields itself.
     *
     * @return list<string>
     */
    private function vendorMigratedRelativeFiles(string $path): array
    {
        if (! str_ends_with($path, '/')) {
            return [$path];
        }

        $dir = base_path($path);

        if (! $this->files->isDirectory($dir)) {
            return [];
        }

        $files = [];
        foreach ($this->files->allFiles($dir, true) as $file) {
            $files[] = $path.str_replace('\\', '/', $file->getRelativePathname());
        }

        return $files;
    }

    /**
     * Remove now-empty directories left behind after deleting a migrated file,
     * walking up toward base_path() but never deleting base_path() itself or any
     * directory that still has contents. Keeps the consumer tree tidy after the
     * controller/request/Vue copies are migrated to vendor.
     */
    private function pruneEmptyAncestors(string $dir): void
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR);

        while (true) {
            $normalized = rtrim($dir, DIRECTORY_SEPARATOR);

            if ($normalized === $base || $normalized === '' || ! str_starts_with($normalized, $base.DIRECTORY_SEPARATOR)) {
                return;
            }

            if (! $this->files->isDirectory($dir)) {
                return;
            }

            // Stop at the first non-empty directory (files OR subdirectories).
            if ($this->files->files($dir) !== [] || $this->files->directories($dir) !== []) {
                return;
            }

            $this->files->deleteDirectory($dir);
            $dir = dirname($dir);
        }
    }

    /**
     * Update files that the user may have modified.
     * Only updates if the file hasn't been modified since last install/update.
     */
    private function updateModifiableFiles(bool $force, bool $dryRun): void
    {
        $stubsPath = StarterKitServiceProvider::stubsPath();
        $hashes = $this->loadHashRegistry();

        foreach ($this->files->allFiles($stubsPath, true) as $file) {
            $relativePath = $file->getRelativePathname();
            $targetPath = base_path($relativePath);

            // Skip if it's in safe paths (already handled)
            if ($this->isInSafePaths($relativePath)) {
                continue;
            }

            // Skip files that should never be updated
            if ($this->isNeverUpdate($relativePath)) {
                continue;
            }

            if ($this->isIgnoredStubFile($relativePath)) {
                continue;
            }

            // Skip if target doesn't exist (handled in addNewFiles)
            if (! $this->files->exists($targetPath)) {
                continue;
            }

            // Skip files explicitly opted out at install time (e.g. --without-ai-skill).
            // Even if the target somehow exists, we must not overwrite with package version.
            if (($hashes[$relativePath] ?? null) === '__skipped__') {
                continue;
            }

            // Skip if files are identical
            if ($this->filesAreIdentical($file->getPathname(), $targetPath)) {
                continue;
            }

            // Check if user has modified the file
            $currentHash = md5_file($targetPath);
            $originalHash = $hashes[$relativePath] ?? null;

            if (! $force) {
                if ($originalHash === null) {
                    // No hash record — collect for interactive prompt
                    $this->untracked[] = $relativePath;

                    continue;
                }

                if ($currentHash !== $originalHash) {
                    // User has modified this file — skip it
                    $this->skipped[] = $relativePath;

                    continue;
                }
            }

            if (! $dryRun) {
                $this->ensureDirectoryExists(dirname($targetPath));
                $this->files->copy($file->getPathname(), $targetPath);
            }

            $this->updated[] = $relativePath;
        }
    }

    /**
     * Handle untracked files — no hash record exists.
     * Ask the user which ones to update via multiselect.
     */
    private function resolveUntrackedFiles(bool $dryRun): void
    {
        if (empty($this->untracked)) {
            return;
        }

        if ($dryRun) {
            // In dry-run mode, just report them — no interactive prompt
            return;
        }

        $this->newLine();
        $this->components->warn('The following files have no tracking history and differ from the package version.');
        $this->line('  <fg=gray>This usually happens after a package update that changed these files.</>');
        $this->newLine();

        $options = [];
        foreach ($this->untracked as $path) {
            $options[$path] = $path;
        }

        $toUpdate = multiselect(
            label: 'Which files should be updated? (select only files you did NOT modify)',
            options: $options,
        );

        $stubsPath = StarterKitServiceProvider::stubsPath();

        foreach ($this->untracked as $path) {
            if (in_array($path, $toUpdate)) {
                $stubPath = $stubsPath.DIRECTORY_SEPARATOR.$path;
                $targetPath = base_path($path);
                $this->ensureDirectoryExists(dirname($targetPath));
                $this->files->copy($stubPath, $targetPath);

                $this->updated[] = $path;
            } else {
                $this->skipped[] = $path;
            }
        }
    }

    /**
     * Add new stub files that don't exist in the application yet.
     */
    private function addNewFiles(bool $dryRun): void
    {
        $stubsPath = StarterKitServiceProvider::stubsPath();
        $hashes = $this->loadHashRegistry();

        foreach ($this->files->allFiles($stubsPath, true) as $file) {
            $relativePath = $file->getRelativePathname();
            $targetPath = base_path($relativePath);

            if ($this->isNeverUpdate($relativePath)) {
                continue;
            }

            if ($this->isIgnoredStubFile($relativePath)) {
                continue;
            }

            if ($this->files->exists($targetPath)) {
                continue;
            }

            // Skip if already handled
            if (in_array($relativePath, $this->updated)) {
                continue;
            }

            // If hash registry has an entry, the file was previously handled:
            // installed-then-deleted ('__deleted__'), or explicitly skipped at
            // install time via --without-ai-skill ('__skipped__'). Either way,
            // respect that decision — don't re-add.
            if (isset($hashes[$relativePath])) {
                continue;
            }

            if (! $dryRun) {
                $this->ensureDirectoryExists(dirname($targetPath));
                $this->files->copy($file->getPathname(), $targetPath);
            }

            $this->added[] = $relativePath;
        }
    }

    /**
     * Check if newly added files include migrations.
     */
    private function hasNewMigrations(): bool
    {
        foreach ($this->added as $path) {
            if (str_starts_with($path, 'database/migrations/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a relative path falls within safe update paths.
     */
    private function isInSafePaths(string $relativePath): bool
    {
        foreach (self::SAFE_UPDATE_PATHS as $safePath) {
            if (str_ends_with($safePath, '/')) {
                if (str_starts_with($relativePath, $safePath)) {
                    return true;
                }
            } else {
                if ($relativePath === $safePath) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a file should never be updated (user-customizable files).
     */
    private function isNeverUpdate(string $relativePath): bool
    {
        foreach (self::NEVER_UPDATE_PATHS as $path) {
            if (str_ends_with($path, '/')) {
                if (str_starts_with($relativePath, $path)) {
                    return true;
                }
            } elseif ($relativePath === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * Locally-reproduced artifacts that must never be copied into — or recorded
     * for — a consumer app: dependencies, Vite build output, Wayfinder/unplugin
     * codegen, and OS junk. Composer's export-ignore strips these from the dist
     * tarball, but path / working-copy installs read the stubs directory straight
     * from disk where these may exist. Mirrors InstallCommand::isIgnoredStubFile()
     * so install and update stay in lockstep.
     */
    private function isIgnoredStubFile(string $relativePath): bool
    {
        $normalizedPath = str_replace('\\', '/', $relativePath);

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

        // Generated type declarations (unplugin auto-import / components resolver),
        // the theme resolver's generated manifest (sk-theme-build.mjs output), and
        // the stub-side npm lockfile (regenerated by install; copying it would pin
        // the consumer to the kit's dependency graph and break `npm ci`).
        if (in_array($normalizedPath, ['auto-imports.d.ts', 'components.d.ts', 'resources/css/theme/_active.css', 'package-lock.json'], true)) {
            return true;
        }

        return basename($normalizedPath) === '.DS_Store';
    }

    /**
     * Check if two files have identical content.
     */
    private function filesAreIdentical(string $source, string $target): bool
    {
        if (! $this->files->exists($target)) {
            return false;
        }

        return md5_file($source) === md5_file($target);
    }

    /**
     * Load the hash registry from storage.
     * Automatically migrates old format (target hashes) to new format (stub hashes).
     *
     * @return array<string, string>
     */
    private function loadHashRegistry(): array
    {
        $hashFile = config('starter-kit.published_hashes', storage_path('starter-kit/hashes.json'));

        if (! $this->files->exists($hashFile)) {
            return [];
        }

        $data = json_decode($this->files->get($hashFile), true) ?: [];

        // Already migrated to v2 format (stores stub hashes)
        if (($data['_format'] ?? null) === 'v2') {
            unset($data['_format']);

            return $data;
        }

        // Migrate from old format: old registry stored TARGET hashes which is unreliable.
        // Re-derive by comparing each target against its stub.
        return $this->migrateHashRegistry($data);
    }

    /**
     * Migrate old hash registry (target hashes) to new format (stub hashes).
     *
     * For each stub file:
     * - If target === stub → store stub hash (user hasn't modified)
     * - If target !== stub → don't store (assume user modified, will be skipped)
     *
     * @param  array<string, string>  $oldHashes
     * @return array<string, string>
     */
    private function migrateHashRegistry(array $oldHashes): array
    {
        $stubsPath = StarterKitServiceProvider::stubsPath();
        $newHashes = [];

        foreach ($this->files->allFiles($stubsPath, true) as $file) {
            $relativePath = $file->getRelativePathname();
            $targetPath = base_path($relativePath);

            if ($this->isNeverUpdate($relativePath)) {
                continue;
            }

            if ($this->isIgnoredStubFile($relativePath)) {
                continue;
            }

            if (! $this->files->exists($targetPath)) {
                // File was deleted by user, explicitly skipped at install, or never installed.
                // Preserve the original sentinel so addNewFiles/updateModifiableFiles respect
                // the previous decision (__deleted__ or __skipped__).
                if (isset($oldHashes[$relativePath])) {
                    $sentinel = $oldHashes[$relativePath] === '__skipped__' ? '__skipped__' : '__deleted__';
                    $newHashes[$relativePath] = $sentinel;
                }

                continue;
            }

            $stubHash = md5_file($file->getPathname());
            $targetHash = md5_file($targetPath);

            if ($stubHash === $targetHash) {
                // Target matches current stub — user hasn't modified this file
                $newHashes[$relativePath] = $stubHash;
            } elseif (isset($oldHashes[$relativePath]) && $targetHash === $oldHashes[$relativePath]) {
                // Target matches what was installed (old v1 hash) — user hasn't modified,
                // but the STUB has changed since last install. Store target hash so update
                // will detect it's safe to overwrite.
                $newHashes[$relativePath] = $targetHash;
            }

            // If neither match, user modified the file — don't store → skip (safe).
        }

        // Save migrated registry immediately
        $this->saveHashRegistry($newHashes);

        $this->components->info('Migrated hash registry to v2 format.');

        return $newHashes;
    }

    /**
     * Update the hash registry with STUB hashes for files that were actually updated or added.
     */
    private function updateHashRegistry(): void
    {
        $hashes = $this->loadHashRegistry();
        $stubsPath = StarterKitServiceProvider::stubsPath();

        $changedFiles = array_merge($this->updated, $this->added);

        foreach ($changedFiles as $relativePath) {
            // Skip descriptive entries like "config/filesystems.php (injected DO Spaces disk)"
            if (str_contains($relativePath, ' ')) {
                continue;
            }

            $stubPath = $stubsPath.DIRECTORY_SEPARATOR.$relativePath;
            if ($this->files->exists($stubPath)) {
                // Store the STUB hash, not the target hash
                $hashes[$relativePath] = md5_file($stubPath);
            }
        }

        // Mark stub files that were previously installed but are now missing as deleted.
        // This persists the user's deletion decision so future sk:update / sk:install
        // runs do not restore the file — even after storage/ is cleared or re-deployed.
        foreach ($this->files->allFiles($stubsPath, true) as $file) {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            if ($this->isNeverUpdate($relativePath)) {
                continue;
            }

            if ($this->isIgnoredStubFile($relativePath)) {
                continue;
            }

            $existing = $hashes[$relativePath] ?? null;

            if ($existing === null || $existing === '__deleted__' || $existing === '__skipped__') {
                continue;
            }

            if (! $this->files->exists(base_path($relativePath))) {
                $hashes[$relativePath] = '__deleted__';
            }
        }

        $this->saveHashRegistry($hashes);
    }

    /**
     * Persist the hash registry to disk.
     *
     * @param  array<string, string>  $hashes
     */
    private function saveHashRegistry(array $hashes): void
    {
        $hashFile = config('starter-kit.published_hashes', storage_path('starter-kit/hashes.json'));

        $dir = dirname($hashFile);
        if (! $this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        $hashes['_format'] = 'v2';

        $this->atomicPut($hashFile, json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Inject DigitalOcean Spaces disk into config/filesystems.php if not already present.
     */
    private function injectFilesystemsConfig(): void
    {
        $configPath = config_path('filesystems.php');

        if (! $this->files->exists($configPath)) {
            return;
        }

        $content = $this->files->get($configPath);

        // Check if 'do' disk is already inside the 'disks' section
        $disksPos = strpos($content, "'disks'");
        if ($disksPos === false) {
            return;
        }

        $disksClosingPos = strpos($content, "\n    ],", $disksPos);
        if ($disksClosingPos === false) {
            return;
        }

        $disksSection = substr($content, $disksPos, $disksClosingPos - $disksPos);
        if (str_contains($disksSection, "'do'")) {
            return;
        }

        $diskConfig = <<<'PHP'

        'do' => [
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
PHP;

        $content = substr_replace($content, $diskConfig."\n\n    ],", $disksClosingPos + 1, strlen('    ],'));

        $this->files->put($configPath, $content);

        $this->updated[] = 'config/filesystems.php (injected DO Spaces disk)';
    }

    /**
     * Inject the role_colors block into config/permission-resources.php if missing.
     * The config is user-modifiable (never overwritten on update), so new keys
     * added by the kit must be injected — same pattern as the DO Spaces disk.
     */
    private function injectRoleColorsConfig(): void
    {
        $configPath = config_path('permission-resources.php');

        if (! $this->files->exists($configPath)) {
            return;
        }

        $content = $this->files->get($configPath);

        if (str_contains($content, "'role_colors'")) {
            return;
        }

        // Anchor: insert right after the role_groups array closes.
        $groupsPos = strpos($content, "'role_groups'");
        if ($groupsPos === false) {
            return;
        }

        $closingPos = strpos($content, "\n    ],", $groupsPos);
        if ($closingPos === false) {
            return;
        }

        $block = <<<'PHP'

    /*
    |--------------------------------------------------------------------------
    | Role Colors
    |--------------------------------------------------------------------------
    |
    | Tag color for each default role — a Tailwind color name (indigo, emerald,
    | rose, …) or a PrimeVue severity. Seeded into the roles table and used by
    | the role tags in the admin UI.
    |
    */

    'role_colors' => [
        'system_admin' => 'rose',
        'admin' => 'indigo',
        'user' => 'slate',
    ],
PHP;

        $content = substr_replace($content, "\n    ],\n".$block, $closingPos, strlen("\n    ],"));

        $this->files->put($configPath, $content);

        $this->updated[] = 'config/permission-resources.php (injected role_colors)';
    }

    /**
     * Rewrite composer.json autoload `files` entry: legacy `app/helpers.php`
     * becomes `app/Helpers/custom.php`. The package now ships `to_api()` and
     * `format_date()` from vendor — the app file is purely user-owned.
     */
    /**
     * Known historical md5 hashes of stock `app/helpers.php` shipped by the
     * package. If the user's file matches any of these, we know they have not
     * customized it and it is safe to delete during the migration to
     * `app/Helpers/custom.php`.
     *
     * @var list<string>
     */
    private const LEGACY_HELPERS_STOCK_HASHES = [
        '34375d826bb4ea188ab738cc12bcb096', // initial package release
    ];

    /**
     * One-time migration: when the legacy `app/helpers.php` exists, decide
     * whether it is safe to remove (matches a known stock hash) or whether
     * the user has customized it (warn and leave the file in place so their
     * code is not destroyed).
     */
    private function migrateLegacyHelpersFile(): void
    {
        $legacyPath = base_path('app/helpers.php');

        if (! $this->files->exists($legacyPath)) {
            return;
        }

        $hash = md5_file($legacyPath);

        if (in_array($hash, self::LEGACY_HELPERS_STOCK_HASHES, true)) {
            $this->files->delete($legacyPath);
            $this->removed[] = 'app/helpers.php';

            return;
        }

        $this->newLine();
        $this->components->warn('app/helpers.php contains custom code — left in place.');
        $this->line('  <fg=gray>Move your helpers to app/Helpers/custom.php and delete app/helpers.php manually.</>');
        $this->line('  <fg=gray>to_api() and format_date() are now provided by the package — drop them from your file.</>');
        $this->newLine();
    }

    /**
     * Merge the stub package.json into the application's package.json.
     *
     * Mirrors the strategy used at install time: stub version wins for shared
     * dependency versions, user-added dependencies (and any extra root-level
     * keys) are preserved. We only record an "updated" entry when the merge
     * actually changes the file on disk — re-running `sk:update` is otherwise
     * a no-op for users whose package.json is already in sync.
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
            $this->added[] = 'package.json';

            return;
        }

        /** @var array<string, mixed>|null $stub */
        $stub = json_decode($this->files->get($stubPath), true);
        /** @var array<string, mixed>|null $current */
        $current = json_decode($this->files->get($targetPath), true);

        if (! is_array($stub) || ! is_array($current)) {
            // Malformed JSON — fall back to stub to guarantee a working build.
            $this->files->copy($stubPath, $targetPath);
            $this->updated[] = 'package.json';

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

        $rendered = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        if ($rendered === $this->files->get($targetPath)) {
            return;
        }

        $this->files->put($targetPath, $rendered);
        $this->updated[] = 'package.json (merged stub dependencies — run npm install)';
    }

    /**
     * Rewrite stale import specifiers (see STALE_IMPORT_REWRITES) in consumer
     * Vue/TS/JS files under resources/js. Touches only files that actually contain
     * a stale path, and only the specifier substring — user code is otherwise left
     * untouched. Idempotent: a file already on the vendor path is skipped.
     */
    private function migrateStaleImports(bool $dryRun): void
    {
        $root = base_path('resources/js');

        if (! $this->files->isDirectory($root)) {
            return;
        }

        foreach ($this->files->allFiles($root) as $file) {
            if (! in_array($file->getExtension(), ['vue', 'ts', 'js'], true)) {
                continue;
            }

            $path = $file->getPathname();
            $content = $this->files->get($path);
            $rewritten = $content;

            foreach (self::STALE_IMPORT_REWRITES as $old => $new) {
                if (str_contains($rewritten, $old)) {
                    $rewritten = str_replace($old, $new, $rewritten);
                }
            }

            if ($rewritten === $content) {
                continue;
            }

            if (! $dryRun) {
                $this->files->put($path, $rewritten);
            }

            $this->updated[] = str_replace(base_path().'/', '', $path).' (rewrote stale import path)';
        }
    }

    private function rewriteHelpersAutoload(): void
    {
        $composerPath = base_path('composer.json');

        if (! $this->files->exists($composerPath)) {
            return;
        }

        $data = json_decode($this->files->get($composerPath), true);

        if (! is_array($data)) {
            return;
        }

        $files = $data['autoload']['files'] ?? [];
        $hasLegacyEntry = in_array('app/helpers.php', $files, true);
        $hasCustomEntry = in_array('app/Helpers/custom.php', $files, true);
        $legacyFileStillPresent = $this->files->exists(base_path('app/helpers.php'));

        // Only drop the legacy autoload entry when the file is gone too —
        // otherwise the user's custom code in app/helpers.php would silently
        // stop loading. migrateLegacyHelpersFile() handles deletion.
        $shouldDropLegacy = $hasLegacyEntry && ! $legacyFileStillPresent;
        $shouldAddCustom = ! $hasCustomEntry;

        if (! $shouldDropLegacy && ! $shouldAddCustom) {
            return;
        }

        if ($shouldDropLegacy) {
            $files = array_values(array_filter($files, fn ($entry) => $entry !== 'app/helpers.php'));
        }

        if ($shouldAddCustom) {
            $files[] = 'app/Helpers/custom.php';
        }

        $data['autoload']['files'] = array_values(array_unique($files));

        $this->files->put(
            $composerPath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );

        $this->updated[] = 'composer.json (rewrote helpers autoload entry)';
    }

    /**
     * Ensure a directory exists.
     */
    private function ensureDirectoryExists(string $path): void
    {
        if (! $this->files->isDirectory($path)) {
            $this->files->makeDirectory($path, 0755, true);
        }
    }

    /**
     * Print an informational notice about vendor-resident paths that are still
     * present in the application. Shown only when at least one such path exists.
     * No automatic cleanup is performed — the user decides.
     */
    private function printVendorResidentNotice(): void
    {
        $present = [];

        foreach (self::VENDOR_RESIDENT_PATHS as $path) {
            $target = base_path($path);

            if (str_ends_with($path, '/')) {
                if ($this->files->isDirectory($target)) {
                    $present[] = $path;
                }
            } else {
                if ($this->files->exists($target)) {
                    $present[] = $path;
                }
            }
        }

        if (empty($present)) {
            return;
        }

        $this->newLine();
        $this->components->warn('v13.5.0+: package runtime is vendor-resident, but these app copies still SHADOW it (your copy wins):');
        $this->newLine();

        foreach ($present as $path) {
            $this->line("  <fg=yellow>•</> {$path}");
        }

        $this->newLine();
        $this->line('  <fg=gray>While these app copies exist they WIN: the alias-skip override (StarterKitServiceProvider)</>');
        $this->line('  <fg=gray>binds the vendor class only where NO app copy exists, so the kit keeps running your</>');
        $this->line('  <fg=gray>copy and vendor bugfixes do NOT reach it. To adopt the vendor version, delete the</>');
        $this->line('  <fg=gray>files you have NOT customized (keep any you have intentionally edited).</>');

        // Translations follow the SAME app-wins rule, via a different mechanism
        // (translation override rather than class alias): a customized
        // app/lang/sk-*.php also wins, so deleting it reverts that string to the
        // vendor default.
        if (collect($present)->contains(fn (string $path): bool => str_starts_with($path, 'lang/'))) {
            $this->line('  <fg=gray>Same rule for lang/.../sk-*.php (translation override): deleting a customized copy</>');
            $this->line('  <fg=gray>reverts that string to the vendor default.</>');
        }

        $this->line('  <fg=gray>See: '.DocsLink::to('migrate-existing-project-to-vendor.md').'</>');
        $this->newLine();
    }

    /**
     * Print the update summary.
     */
    private function printSummary(bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        $hasChanges = ! empty($this->updated) || ! empty($this->added) || ! empty($this->removed) || ! empty($this->skipped) || ! empty($this->untracked) || ! empty($this->preservedDeprecated) || ! empty($this->safePathConflicts);

        if (! $hasChanges) {
            $this->components->info($prefix.'Everything is up to date!');

            return;
        }

        if (! empty($this->updated)) {
            $this->components->twoColumnDetail("<fg=green>{$prefix}Updated</>", count($this->updated).' files');
            foreach ($this->updated as $path) {
                $this->line("  <fg=green>↻</> {$path}");
            }
        }

        if (! empty($this->added)) {
            $this->newLine();
            $this->components->twoColumnDetail("<fg=blue>{$prefix}Added</>", count($this->added).' new files');
            foreach ($this->added as $path) {
                $this->line("  <fg=blue>+</> {$path}");
            }
        }

        if (! empty($this->removed)) {
            $this->newLine();
            $this->components->twoColumnDetail("<fg=red>{$prefix}Removed</>", count($this->removed).' deprecated files');
            foreach ($this->removed as $path) {
                $this->line("  <fg=red>-</> {$path}");
            }
        }

        if (! empty($this->preservedDeprecated)) {
            $this->newLine();
            $this->components->warn('These files now ship from vendor but were left in place as consumer-owned (their module group was preserved):');
            foreach ($this->preservedDeprecated as $path) {
                $this->line("  <fg=yellow>!</> {$path}");
            }
            $this->newLine();
            $this->line('  <fg=gray>A module is migrated as one group: if any file in it was customized (or is untracked), the WHOLE group stays</>');
            $this->line('  <fg=gray>consumer-owned — including files you did NOT change. This is required because the vendor controller calls its</>');
            $this->line('  <fg=gray>own (vendor) FormRequests and vendor Vue pages import sibling components by relative path, so a half-migrated</>');
            $this->line('  <fg=gray>module would skip your hardened request or break the build. For a customized controller/Vue page your copy still</>');
            $this->line('  <fg=gray>wins (alias skip / app.ts app-first); a preserved-but-unchanged file is just held with its group, not "winning".</>');
            $this->line('  <fg=gray>Run `sk:eject <module>` to own the module cleanly, or migrate your edits and delete the copies. The theme build</>');
            $this->line('  <fg=gray>tooling now also ships from vendor (resources/js/theme/). Use --force to remove these copies regardless of edits.</>');
        }

        // Show untracked files (dry-run: not yet resolved; normal: already resolved into updated/skipped)
        if ($dryRun && ! empty($this->untracked)) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=magenta>Untracked</>', count($this->untracked).' files need review');
            foreach ($this->untracked as $path) {
                $this->line("  <fg=magenta>?</> {$path}");
            }
            $this->newLine();
            $this->line('  <fg=gray>Run without --dry-run to choose which files to update.</>');
        }

        if (! empty($this->safePathConflicts)) {
            $this->newLine();
            $this->components->warn('These files ship with the package but YOUR copy differs — they were NOT overwritten:');
            foreach ($this->safePathConflicts as $path) {
                $this->line("  <fg=yellow>~</> {$path}");
            }
            $this->newLine();
            $this->line('  <fg=gray>The kit keeps these in sync with its own constants, so a preserved copy goes stale: config/permission-resources.php</>');
            $this->line('  <fg=gray>is type-hinted against the enum cases the package ships. Diff your file against the same relative path under</>');
            $this->line('  <fg=gray>vendor/lvntr/laravel-starter-kit/stubs/ and merge the new cases by hand, or re-run with --force to take the</>');
            $this->line('  <fg=gray>package version and DISCARD your edits.</>');
        }

        if (! empty($this->skipped)) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=yellow>Skipped</>', count($this->skipped).' user-modified files');
            foreach ($this->skipped as $path) {
                $this->line("  <fg=yellow>~</> {$path}");
            }
            $this->newLine();
            $this->line('  <fg=gray>Use --force to overwrite user-modified files.</>');
        }

        if (! $dryRun && (! empty($this->updated) || ! empty($this->added))) {
            $this->newLine();
            $this->components->warn('Run the following commands to apply frontend changes:');
            $this->line('  <fg=cyan>npm install && npm run build</>');
        }
    }

    /**
     * Report kit-required Composer packages the consumer app does not have, and
     * offer to pull them in.
     *
     * A newer kit release can add a `require` entry that the consumer's
     * `composer.lock` predates (the app was updated with `--no-update`, a
     * package was removed by hand, or the lock drifted). The kit code that
     * depends on it then dies at runtime with an opaque "class not found", far
     * from the update that introduced it.
     *
     * Composer is only ever run after an explicit yes: `--dry-run`,
     * `--no-interaction` and any TTY-less session (CI, piped stdin) print the
     * command and start no process. The prompt's default is `false` for the
     * same reason — every fallback path a prompt can take here resolves to "do
     * not run". The exit code is untouched on both outcomes: this is advisory,
     * and a failed `composer update` is not a failed `sk:update`.
     */
    private function reportMissingDependencies(bool $dryRun): void
    {
        $missing = KitDependencies::missing();

        if ($missing === []) {
            return;
        }

        $command = [...$this->findComposerBinary(), 'update', 'lvntr/laravel-starter-kit', '-W'];
        $printable = implode(' ', $command);

        $this->newLine();
        $this->components->warn('Packages the kit requires are NOT installed: '.implode(', ', $missing));
        $this->line('  <fg=gray>Every kit feature that depends on them stays broken until they are installed.</>');

        // Same "is there anybody to ask?" test the install command uses before a
        // prompt (see InstallCommand::promptForRecipes()).
        $canPrompt = ! $dryRun
            && ! $this->option('no-interaction')
            && $this->input->isInteractive()
            && defined('STDIN')
            && stream_isatty(STDIN);

        if (! $canPrompt || ! confirm('Run `'.$printable.'` now?', default: false)) {
            $this->line('  <fg=cyan>'.$printable.'</>');

            return;
        }

        $installed = spin(
            fn (): bool => $this->runProcessStep($command, timeout: 300),
            'Installing missing packages...',
        );

        if ($installed) {
            $this->components->info('Missing packages are now installed.');

            return;
        }

        $this->components->warn($this->stepFailureDetail ?? '`composer update` failed.');
        $this->line('  <fg=gray>Run it by hand:</>');
        $this->line('  <fg=cyan>'.$printable.'</>');
        $this->stepFailureDetail = null;
    }

    /**
     * Locate the composer executable, same resolution InstallCommand uses: a
     * project-local `composer.phar` when there is one, else whatever `composer`
     * PATH resolves to.
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
}
