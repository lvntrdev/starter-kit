<?php

namespace Lvntr\StarterKit;

use App\Enums\RoleEnum;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Translation\FileLoader;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Laravel\Passport\Passport;
use LvntR\ApiDock\ApiDockServiceProvider;
use Lvntr\StarterKit\Domain\ActivityLog\Queries\ActivityLogDatatableQuery;
use Lvntr\StarterKit\Domain\ApiClient\Actions\CreateApiClientAction;
use Lvntr\StarterKit\Domain\ApiClient\Actions\CreatePersonalAccessTokenAction;
use Lvntr\StarterKit\Domain\ApiClient\Actions\RevokeApiClientAction;
use Lvntr\StarterKit\Domain\ApiClient\Actions\RevokeApiTokenAction;
use Lvntr\StarterKit\Domain\ApiClient\Actions\UpdateApiClientAction;
use Lvntr\StarterKit\Domain\ApiRoute\Actions\RegenerateApiDocsAction;
use Lvntr\StarterKit\Domain\ApiRoute\Actions\SyncApidogAction;
use Lvntr\StarterKit\Domain\ApiRoute\Actions\SyncPostmanAction;
use Lvntr\StarterKit\Domain\ApiRoute\Queries\ApiRouteListQuery;
use Lvntr\StarterKit\Domain\ApiRoute\Support\OpenApiExporter;
use Lvntr\StarterKit\Domain\FileManager\Policies\MediaPolicy;
use Lvntr\StarterKit\Domain\FileManager\Support\ContextRegistry;
use Lvntr\StarterKit\Domain\Logs\Actions\DeleteLogFilesAction;
use Lvntr\StarterKit\Domain\Logs\DTOs\DeleteLogFilesDTO;
use Lvntr\StarterKit\Domain\Logs\DTOs\LogEntryFilterDTO;
use Lvntr\StarterKit\Domain\Logs\Events\LogFilesDeleted;
use Lvntr\StarterKit\Domain\Logs\Listeners\LogActivityForLogFilesDeleted;
use Lvntr\StarterKit\Domain\Logs\Queries\LogEntryQuery;
use Lvntr\StarterKit\Domain\Logs\Queries\LogFileQuery;
use Lvntr\StarterKit\Domain\Media\Actions\ClearMediaAction;
use Lvntr\StarterKit\Domain\Media\Actions\UploadMediaAction;
use Lvntr\StarterKit\Domain\Role\Actions\CreateRoleAction;
use Lvntr\StarterKit\Domain\Role\Actions\DeleteRoleAction;
use Lvntr\StarterKit\Domain\Role\Actions\SyncPermissionsAction;
use Lvntr\StarterKit\Domain\Role\Actions\UpdateRoleAction;
use Lvntr\StarterKit\Domain\Role\DTOs\RoleDTO;
use Lvntr\StarterKit\Domain\Role\Events\RoleCreated;
use Lvntr\StarterKit\Domain\Role\Events\RoleDeleted;
use Lvntr\StarterKit\Domain\Role\Events\RoleUpdated;
use Lvntr\StarterKit\Domain\Role\Listeners\LogRoleCreated;
use Lvntr\StarterKit\Domain\Role\Listeners\LogRoleDeleted;
use Lvntr\StarterKit\Domain\Role\Listeners\LogRoleUpdated;
use Lvntr\StarterKit\Domain\Role\Queries\CanManageRoleQuery;
use Lvntr\StarterKit\Domain\Role\Queries\GroupedPermissionsQuery;
use Lvntr\StarterKit\Domain\Role\Queries\RoleBulkSelectionQuery;
use Lvntr\StarterKit\Domain\Role\Queries\RoleDatatableQuery;
use Lvntr\StarterKit\Domain\Role\Queries\RoleSelectOptionsQuery;
use Lvntr\StarterKit\Domain\Role\Queries\UserGrantablePermissionsQuery;
use Lvntr\StarterKit\Domain\Session\Actions\PurgeOtherSessionsAction;
use Lvntr\StarterKit\Domain\Session\Queries\UserSessionsQuery;
use Lvntr\StarterKit\Domain\Setting\Actions\SendTestMailAction;
use Lvntr\StarterKit\Domain\Setting\Actions\UpdateAuthSettingsAction;
use Lvntr\StarterKit\Domain\Setting\Actions\UpdateSettingsAction;
use Lvntr\StarterKit\Domain\Setting\DTOs\ApidogSettingsDTO;
use Lvntr\StarterKit\Domain\Setting\DTOs\AppearanceSettingsDTO;
use Lvntr\StarterKit\Domain\Setting\DTOs\AuthSettingsDTO;
use Lvntr\StarterKit\Domain\Setting\DTOs\FileManagerSettingsDTO;
use Lvntr\StarterKit\Domain\Setting\DTOs\GeneralSettingsDTO;
use Lvntr\StarterKit\Domain\Setting\DTOs\MailSettingsDTO;
use Lvntr\StarterKit\Domain\Setting\DTOs\PostmanSettingsDTO;
use Lvntr\StarterKit\Domain\Setting\DTOs\StorageSettingsDTO;
use Lvntr\StarterKit\Domain\Setting\DTOs\TurnstileSettingsDTO;
use Lvntr\StarterKit\Domain\Setting\Queries\SettingsDefaultsQuery;
use Lvntr\StarterKit\Domain\Setting\SettingService;
use Lvntr\StarterKit\Domain\Shared\Actions\BaseAction;
use Lvntr\StarterKit\Domain\Shared\Contracts\PipeableAction;
use Lvntr\StarterKit\Domain\Shared\DTOs\BaseDTO;
use Lvntr\StarterKit\Domain\Shared\Pipelines\ActionPipeline;
use Lvntr\StarterKit\Domain\Shared\Services\DefinitionService;
use Lvntr\StarterKit\Domain\User\Actions\CreateUserAction;
use Lvntr\StarterKit\Domain\User\Actions\DeleteUserAction;
use Lvntr\StarterKit\Domain\User\Actions\UpdateUserAction;
use Lvntr\StarterKit\Domain\User\DTOs\UserDTO;
use Lvntr\StarterKit\Domain\User\Events\UserCreated;
use Lvntr\StarterKit\Domain\User\Events\UserDeleted;
use Lvntr\StarterKit\Domain\User\Events\UserUpdated;
use Lvntr\StarterKit\Domain\User\Listeners\LogUserCreated;
use Lvntr\StarterKit\Domain\User\Listeners\LogUserDeleted;
use Lvntr\StarterKit\Domain\User\Listeners\LogUserUpdated;
use Lvntr\StarterKit\Domain\User\Queries\UserBulkSelectionQuery;
use Lvntr\StarterKit\Domain\User\Queries\UserDatatableQuery;
use Lvntr\StarterKit\Exceptions\ApiException;
use Lvntr\StarterKit\Exceptions\ApiExceptionHandler;
use Lvntr\StarterKit\Facades\FileManager as FileManagerFacade;
use Lvntr\StarterKit\Http\Middleware\AssignTraceId;
use Lvntr\StarterKit\Http\Middleware\CheckResourcePermission;
use Lvntr\StarterKit\Http\Middleware\EnsureUserIsActive;
use Lvntr\StarterKit\Http\Middleware\SecurityHeaders;
use Lvntr\StarterKit\Http\Middleware\SetLocale;
use Lvntr\StarterKit\Http\Middleware\ValidateTurnstile;
use Lvntr\StarterKit\Http\Responses\ApiResponse;
use Lvntr\StarterKit\Support\DeferredDeleteMediaFilesystem;
use Lvntr\StarterKit\Support\Encryption\DataEncrypterFactory;
use Lvntr\StarterKit\Support\Encryption\EncrypterCoverage;
use Lvntr\StarterKit\Support\Encryption\KitOwnedEncrypter;
use Lvntr\StarterKit\Support\HtmlSanitizer;
use Lvntr\StarterKit\Support\MediaPathGenerator;
use Lvntr\StarterKit\Support\Scramble\ApiResponseExtension;
use Lvntr\StarterKit\Support\TranslatableQueryHelpers;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\Filesystem as MediaFilesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class StarterKitServiceProvider extends ServiceProvider
{
    /**
     * Resolved backward-compat alias manifest, relative to bootstrap/cache.
     *
     * Caches the output of backwardCompatAliasPlan() so the per-request
     * override-existence scan (one file_exists() per overridable alias) runs
     * once instead of on every boot. See resolveBackwardCompatAliases().
     */
    private const ALIAS_MANIFEST_RELATIVE = 'cache/starter-kit-aliases.php';

    /**
     * Whether the Scramble document transformers have been registered in this
     * process (Scramble's configure() state is process-global, so appending
     * the bearer transformer twice would duplicate it). Extensions are NOT
     * guarded by this — see applyScrambleDocumentWiring().
     */
    private static bool $scrambleTransformersRegistered = false;

    /**
     * Register package services.
     */
    public function register(): void
    {
        // Helper autoload order: load the consumer-published copy FIRST (when
        // present) so its function declarations register before the vendor
        // helper's `function_exists` guards run. The vendor file then fills in
        // any helper the consumer did not override.
        //
        // We load helpers here (instead of via `autoload.files` in
        // composer.json) because Composer would load the vendor copy before
        // the consumer's, and the symlinked `vendor/lvntr/laravel-starter-kit`
        // path makes a `dirname(__DIR__, 4)` walk inside the helper file
        // unreliable for locating the consumer copy. Loading from the
        // ServiceProvider lets us use `base_path()` directly.
        $userHelpers = base_path('app/Helpers/sk-helpers.php');
        if (is_file($userHelpers)) {
            require_once $userHelpers;
        }

        require_once __DIR__.'/sk-helpers.php';

        $this->mergeConfigFrom(__DIR__.'/../config/starter-kit.php', 'starter-kit');

        // Task 6/7 hook: file-manager config will live at
        // config/file-manager.php in the package once that domain is moved
        // vendor-first. Guard with file_exists() so we can land this hook
        // in Task 1 without breaking anything until the file ships.
        $fileManagerConfig = __DIR__.'/../config/file-manager.php';
        if (file_exists($fileManagerConfig)) {
            $this->mergeConfigFrom($fileManagerConfig, 'file-manager');
        }

        // Backward-compatibility aliases: consumer apps generated before
        // v13.5.1 still import these classes from the `App\` namespace; the
        // aliases make those imports resolve to the vendor classes so consumer
        // code keeps working. New installs should import directly from
        // `Lvntr\StarterKit\*`. The tiering (unconditional vs. consumer-
        // overridable) lives in backwardCompatAliasPlan().
        //
        // `base_path()` can be unavailable mid-bootstrap, so fall back to the
        // application instance when the helper is missing.
        $basePath = function_exists('base_path') ? base_path() : $this->app->basePath();

        foreach ($this->resolveBackwardCompatAliases($basePath) as $appClass => $vendorClass) {
            if (! class_exists($appClass, false) && ! interface_exists($appClass, false)) {
                class_alias($vendorClass, $appClass);
            }
        }

        // Make the vendor `sk-*` PHP lang files resolvable WITHOUT a namespace
        // (e.g. __('sk-bulk.result')), so the 21 vendor src/ callers keep working
        // after the lang bulk-copy is cut (v15.x Faz 5). Registered in register()
        // — before the translator resolves on the first __() call — by extending
        // the framework's `translation.loader` so the vendor lang dir is inserted
        // BETWEEN the framework defaults and the consumer's app/lang path.
        $this->registerNamespacelessKitTranslations();

        $this->registerDataEncryption();

        $this->registerMediaFilesystem();

        // The kit's documentation UI is api-dock (`api-dock`, `api-dock/spec`),
        // which documents the SAME `default` Scramble API. Scramble's own
        // `docs/api` + `docs/api.json` routes would serve a second, unstyled
        // copy of that document, so they are switched off.
        //
        // MUST run in register(): `Scramble::ignoreDefaultRoutes()` only flips
        // the `Scramble::$defaultRoutesIgnored` static, which ScrambleServiceProvider
        // reads in its own `bootingPackage()` (it calls `expose(false)` there).
        // Every provider's register() precedes every provider's boot(), so this
        // is the only phase that is order-independent — from boot() it would be a
        // coin flip on provider order and the routes would often still register.
        //
        // Both class_exists() checks are load-bearing. Scramble's guards an
        // install that removed it. api-dock's guards the window where the
        // replacement is NOT there: a consumer who pulled this version's source
        // without letting Composer resolve its new requirement (a path/VCS
        // repository on `dev-main`, a vendor dir restored from an older lock, an
        // install that pinned api-dock away). Switching `docs/api` off in that
        // window would leave the app with NO documentation surface at all —
        // `api-dock` is not registered either — and the API Routes screen hides
        // its panel button on the same missing route. Never retire the old
        // surface unless the new one is actually there to take over.
        if (class_exists(Scramble::class) && class_exists(ApiDockServiceProvider::class)) {
            Scramble::ignoreDefaultRoutes();
        }

        $this->secureApiDockDefaults();
    }

    /**
     * Close the api-dock surface when the consumer has not published
     * `config/api-dock.php` yet.
     *
     * api-dock ships `enabled => true`, `middleware => ['web']` and
     * `gate.enabled => false`, so its panel, `/spec` and the try-it proxy are
     * ANONYMOUS on the package defaults. The kit's gated stack lives in
     * `stubs/config/api-dock.php`, which only reaches the app through
     * `sk:install` / `sk:update` — an existing consumer that runs `composer
     * update` alone (or upgrades and postpones `sk:update`) would serve the
     * whole internal API surface, and an outbound request proxy, to the public
     * in that window. Authorization must not depend on a scaffold step.
     *
     * Only the untouched package default (`['web']`) is replaced, so a consumer
     * who published the config and edited the stack keeps their own decision —
     * this is a safe default, not a lock. Runs in register(): api-dock builds
     * its route middleware from this config when it loads its route file during
     * boot.
     *
     * If `App\Http\Middleware\CheckApiDocsAccess` is absent (the kit was never
     * installed into this app), there is no gate to install, so the surface is
     * switched off entirely rather than left open — fail closed either way.
     */
    private function secureApiDockDefaults(): void
    {
        if (! class_exists(ApiDockServiceProvider::class)) {
            return;
        }

        if (config('api-dock.middleware') !== ['web']) {
            return;
        }

        $gate = 'App\Http\Middleware\CheckApiDocsAccess';

        if (! class_exists($gate)) {
            config(['api-dock.enabled' => false]);

            return;
        }

        config(['api-dock.middleware' => ['web', 'auth', $gate]]);
    }

    /**
     * Make the physical removal of a media object wait for the commit of the
     * transaction that removed its row.
     *
     * Spatie deletes the file from `MediaObserver::deleted()`, i.e. INSIDE the
     * caller's transaction. Every kit force-delete path opens one (folder
     * cascade, bulk delete, empty trash), so a rollback anywhere in that
     * transaction used to restore the rows while leaving the already-removed
     * files gone — a row pointing at bytes that no longer exist, which nothing
     * can undo. {@see DeferredDeleteMediaFilesystem} moves the removal behind
     * `afterCommit()`.
     *
     * Bound at the ONE choke point every removal goes through
     * (`Filesystem::removeAllFiles()`), so no delete action, observer or
     * command has to remember the ordering.
     *
     * `bind`, not `singleton`: Spatie does not bind this class at all, so
     * `app(Filesystem::class)` builds a fresh instance per resolve today, and
     * this keeps that lifecycle byte-for-byte. An app that bound the class
     * itself is left alone — its binding is the one Spatie must keep using,
     * and the guard also covers the reverse order (an app provider registers
     * after this one and simply wins).
     */
    private function registerMediaFilesystem(): void
    {
        if ($this->app->bound(MediaFilesystem::class)) {
            return;
        }

        $this->app->bind(
            MediaFilesystem::class,
            static fn ($app): MediaFilesystem => new DeferredDeleteMediaFilesystem($app->make(FilesystemFactory::class)),
        );
    }

    /**
     * Bind the data-at-rest encrypter.
     *
     * Two singletons:
     *   - DataEncrypterFactory::class — owns the memoised key chain and cipher,
     *     so flushDataEncrypter() has a single place to clear.
     *   - DataEncrypterFactory::BINDING — the Encrypter itself. DataCrypt is the
     *     facade over it and the Fortify shim resolves it by that name.
     *
     * Both are LAZY on purpose. Nothing here reads a key, so a request that
     * touches no encrypted value never builds an Encrypter. Resolving eagerly
     * would turn "no APP_KEY yet" (fresh clone, `composer install` before .env,
     * `package:discover`) and "malformed DATA_ENCRYPTION_KEY" into a boot-time
     * fatal on every command and every request, instead of an error confined to
     * the paths that actually touch ciphertext.
     */
    private function registerDataEncryption(): void
    {
        $this->app->singleton(DataEncrypterFactory::class);

        $this->app->singleton(
            DataEncrypterFactory::BINDING,
            static fn ($app): Encrypter => $app->make(DataEncrypterFactory::class)->make(),
        );
    }

    /**
     * Drop every memoised copy of the data encrypter so the next use re-reads
     * the key configuration.
     *
     * Callers: the encryption commands after they rewrite .env, an Octane worker
     * whose config was reloaded, and any test that swaps DATA_ENCRYPTION_KEY
     * mid-run. All four caches must go together — the factory's own chain, both
     * container instances, and the facade's resolved instance. Leaving one
     * behind lets reads and writes land on different keys, which is
     * indistinguishable from data loss at the call site.
     */
    public static function flushDataEncrypter(): void
    {
        // Deliberately NOT Container::getInstance(): that call CREATES a bare
        // container and installs it globally when none is set, which would
        // replace the running Application for the rest of the process. Read the
        // facade's application instead, which is null-safe.
        $app = Facade::getFacadeApplication();

        if ($app instanceof Container) {
            if ($app->resolved(DataEncrypterFactory::class)) {
                $app->make(DataEncrypterFactory::class)->flush();
            }

            $app->forgetInstance(DataEncrypterFactory::class);
            $app->forgetInstance(DataEncrypterFactory::BINDING);
        }

        Facade::clearResolvedInstance(DataEncrypterFactory::BINDING);
    }

    /**
     * Insert the vendor `resources/lang` directory into the translation loader's
     * namespace-less path list so `__('sk-*')` group keys resolve from the package
     * without a `starter-kit::` prefix.
     *
     * Precedence (override invariant): the framework's default loader is built with
     * paths `[frameworkDefaults, app/lang]` and `FileLoader::loadPaths()` merges them
     * with `array_replace_recursive` — LAST path wins. We rebuild the loader with
     * `[frameworkDefaults, vendor/resources/lang, app/lang]`, so a consumer's own
     * `app/lang/{locale}/sk-*.php` override still wins over the vendor copy, while
     * the vendor copy wins over (and falls back to) the framework defaults. Missing
     * app keys fall back to vendor; missing vendor keys fall back to framework.
     *
     * This is the PHP half of the two-consumer lang invariant (the Vite/i18n half
     * lives in stubs/resources/js/app.ts). `validation.php` is intentionally NOT
     * vendor-resident — it stays a consumer-owned framework-default override stub —
     * and the existing `starter-kit::` namespace + JSON registration in
     * registerTranslations() is left untouched.
     */
    private function registerNamespacelessKitTranslations(): void
    {
        $vendorLangPath = __DIR__.'/../resources/lang';

        $this->app->extend('translation.loader', function ($loader, $app) use ($vendorLangPath) {
            // Only reorder a FileLoader (the framework default). Custom loaders are
            // left as-is so we never break a consumer's replacement.
            if (! $loader instanceof FileLoader) {
                return $loader;
            }

            $paths = $loader->paths();

            // Skip if already present (idempotent — defensive against double-extend).
            if (in_array($vendorLangPath, $paths, true)) {
                return $loader;
            }

            // Insert the vendor path just before the LAST entry (the app/lang path),
            // so app overrides keep winning. If the shape is unexpected (no app path),
            // fall back to appending — vendor still resolves, app override unaffected.
            if (count($paths) >= 1) {
                array_splice($paths, count($paths) - 1, 0, [$vendorLangPath]);
            } else {
                $paths[] = $vendorLangPath;
            }

            return new FileLoader($app['files'], $paths);
        });
    }

    /**
     * Plan the `App\` → vendor backward-compatibility class aliases to register
     * for a consumer rooted at `$basePath`. Pure (no `class_alias` side effects)
     * so the decision is unit-testable.
     *
     * Two tiers:
     *
     *  - **Unconditional.** `App\Http\Responses\ApiResponse` has NO valid
     *    consumer override: a real `App\` subclass breaks the return-type
     *    covariance of `DatatableQueryBuilder::response()` (which returns the
     *    vendor type) in query classes — that is exactly why it is an alias,
     *    not an extension point. It is therefore aliased on EVERY boot, early
     *    (before any controller return-type check) and regardless of any file
     *    at the consumer path. That determinism is the fix for the intermittent
     *    post-install "Return value must be of type App\Http\Responses\
     *    ApiResponse, Lvntr\StarterKit\Http\Responses\ApiResponse returned"
     *    TypeError: previously the alias was deferred to a class_alias-only stub
     *    (`app/Http/Responses/ApiResponse.php`) that declares no class, so it is
     *    absent from the optimized classmap and its load — and thus the alias's
     *    existence and timing — depended on PSR-4 fallback + opcache state.
     *
     *  - **Overridable.** The rest may be replaced by a consumer's own `app/`
     *    class; the alias is skipped when such a file exists so the override
     *    wins (otherwise `class_alias` would short-circuit Composer's autoloader
     *    and silently drop the override).
     *
     * Note: PHP traits cannot be safely aliased via class_alias() —
     * HasActivityLogging/HasMediaCollections are NOT here. DatatableQueryBuilder,
     * HttpsOrLocalhostUrl and TurnstileRule ship a thin App\ subclass shim in the
     * scaffold, so they need no alias here either.
     *
     * @return array<class-string, class-string>
     */
    protected function backwardCompatAliasPlan(string $basePath): array
    {
        // Aliased unconditionally — no valid consumer override exists.
        $plan = [
            'App\Http\Responses\ApiResponse' => ApiResponse::class,
        ];

        // Aliased only when the consumer ships no override at that path.
        $overridable = [
            'App\Domain\ActivityLog\Queries\ActivityLogDatatableQuery' => ActivityLogDatatableQuery::class,
            // Faz 6 — ApiClient runtime (Passport secret-handling actions). HTTP
            // layer (controller/request/resource/policy) stays app-owned; only the
            // pure-runtime actions are vendor-resident behind these aliases.
            'App\Domain\ApiClient\Actions\CreateApiClientAction' => CreateApiClientAction::class,
            'App\Domain\ApiClient\Actions\CreatePersonalAccessTokenAction' => CreatePersonalAccessTokenAction::class,
            'App\Domain\ApiClient\Actions\RevokeApiClientAction' => RevokeApiClientAction::class,
            'App\Domain\ApiClient\Actions\RevokeApiTokenAction' => RevokeApiTokenAction::class,
            'App\Domain\ApiClient\Actions\UpdateApiClientAction' => UpdateApiClientAction::class,
            // Faz 6 — ApiRoute runtime (Postman/Apidog sync + OpenAPI export).
            // ApiRouteController stays app-owned (Inertia render + app shim).
            'App\Domain\ApiRoute\Actions\RegenerateApiDocsAction' => RegenerateApiDocsAction::class,
            'App\Domain\ApiRoute\Actions\SyncApidogAction' => SyncApidogAction::class,
            'App\Domain\ApiRoute\Actions\SyncPostmanAction' => SyncPostmanAction::class,
            'App\Domain\ApiRoute\Queries\ApiRouteListQuery' => ApiRouteListQuery::class,
            'App\Domain\ApiRoute\Support\OpenApiExporter' => OpenApiExporter::class,
            'App\Domain\Logs\Actions\DeleteLogFilesAction' => DeleteLogFilesAction::class,
            'App\Domain\Logs\DTOs\DeleteLogFilesDTO' => DeleteLogFilesDTO::class,
            'App\Domain\Logs\DTOs\LogEntryFilterDTO' => LogEntryFilterDTO::class,
            'App\Domain\Logs\Events\LogFilesDeleted' => LogFilesDeleted::class,
            'App\Domain\Logs\Listeners\LogActivityForLogFilesDeleted' => LogActivityForLogFilesDeleted::class,
            'App\Domain\Logs\Queries\LogEntryQuery' => LogEntryQuery::class,
            'App\Domain\Logs\Queries\LogFileQuery' => LogFileQuery::class,
            'App\Domain\Media\Actions\ClearMediaAction' => ClearMediaAction::class,
            'App\Domain\Media\Actions\UploadMediaAction' => UploadMediaAction::class,
            // Faz 6 — Role runtime (Actions/DTO/Events/Listeners/Queries). The Role
            // MODEL (extends Spatie Role), Store/UpdateRoleRequest (privilege-boundary
            // validated()), RoleController, RoleResource and RolePolicy stay app-owned.
            // permission-resources.php and RoleEnum are out of scope (sanctuary).
            // Event/listener registration moves to the vendor registerEventListeners()
            // so the dispatched vendor event matches the binding key (class_alias does
            // not rewrite a `::class` literal). BulkActions/BulkDeleteRoleAction stays
            // app-owned: it extends the app-owned App\Http\BulkActions\BulkDeleteAction
            // override base, so it is not vendor-aliased here (a vendor class with an
            // app-owned parent would fatal under class_alias eager-load).
            'App\Domain\Role\Actions\CreateRoleAction' => CreateRoleAction::class,
            'App\Domain\Role\Actions\DeleteRoleAction' => DeleteRoleAction::class,
            'App\Domain\Role\Actions\SyncPermissionsAction' => SyncPermissionsAction::class,
            'App\Domain\Role\Actions\UpdateRoleAction' => UpdateRoleAction::class,
            'App\Domain\Role\DTOs\RoleDTO' => RoleDTO::class,
            'App\Domain\Role\Events\RoleCreated' => RoleCreated::class,
            'App\Domain\Role\Events\RoleDeleted' => RoleDeleted::class,
            'App\Domain\Role\Events\RoleUpdated' => RoleUpdated::class,
            'App\Domain\Role\Listeners\LogRoleCreated' => LogRoleCreated::class,
            'App\Domain\Role\Listeners\LogRoleDeleted' => LogRoleDeleted::class,
            'App\Domain\Role\Listeners\LogRoleUpdated' => LogRoleUpdated::class,
            'App\Domain\Role\Queries\CanManageRoleQuery' => CanManageRoleQuery::class,
            'App\Domain\Role\Queries\RoleBulkSelectionQuery' => RoleBulkSelectionQuery::class,
            'App\Domain\Role\Queries\GroupedPermissionsQuery' => GroupedPermissionsQuery::class,
            'App\Domain\Role\Queries\RoleDatatableQuery' => RoleDatatableQuery::class,
            'App\Domain\Role\Queries\RoleSelectOptionsQuery' => RoleSelectOptionsQuery::class,
            'App\Domain\Role\Queries\UserGrantablePermissionsQuery' => UserGrantablePermissionsQuery::class,
            'App\Domain\Session\Actions\PurgeOtherSessionsAction' => PurgeOtherSessionsAction::class,
            'App\Domain\Session\Queries\UserSessionsQuery' => UserSessionsQuery::class,
            // Faz 6 — Setting runtime: SettingService (encryption/cache core,
            // config('settings.sensitive_keys') read), Actions, 8 settings DTOs and
            // SettingsDefaultsQuery move to vendor. The Setting MODEL and SettingPolicy
            // stay app-owned (the model is a static facade delegating to SettingService
            // via app(); keeping it app-owned avoids an App\Models\Setting alias and
            // preserves Laravel's App\Models\Setting → App\Policies\SettingPolicy
            // auto-discovery). The SettingService alias is the critical one — the
            // app-owned Setting model and _03_SettingSeeder reference it by App\ FQCN.
            'App\Domain\Setting\SettingService' => SettingService::class,
            'App\Domain\Setting\Actions\SendTestMailAction' => SendTestMailAction::class,
            'App\Domain\Setting\Actions\UpdateAuthSettingsAction' => UpdateAuthSettingsAction::class,
            'App\Domain\Setting\Actions\UpdateSettingsAction' => UpdateSettingsAction::class,
            'App\Domain\Setting\DTOs\ApidogSettingsDTO' => ApidogSettingsDTO::class,
            'App\Domain\Setting\DTOs\AppearanceSettingsDTO' => AppearanceSettingsDTO::class,
            'App\Domain\Setting\DTOs\AuthSettingsDTO' => AuthSettingsDTO::class,
            'App\Domain\Setting\DTOs\FileManagerSettingsDTO' => FileManagerSettingsDTO::class,
            'App\Domain\Setting\DTOs\GeneralSettingsDTO' => GeneralSettingsDTO::class,
            'App\Domain\Setting\DTOs\MailSettingsDTO' => MailSettingsDTO::class,
            'App\Domain\Setting\DTOs\PostmanSettingsDTO' => PostmanSettingsDTO::class,
            'App\Domain\Setting\DTOs\StorageSettingsDTO' => StorageSettingsDTO::class,
            'App\Domain\Setting\DTOs\TurnstileSettingsDTO' => TurnstileSettingsDTO::class,
            'App\Domain\Setting\Queries\SettingsDefaultsQuery' => SettingsDefaultsQuery::class,
            'App\Domain\Shared\Actions\BaseAction' => BaseAction::class,
            'App\Domain\Shared\Contracts\PipeableAction' => PipeableAction::class,
            'App\Domain\Shared\DTOs\BaseDTO' => BaseDTO::class,
            'App\Domain\Shared\Pipelines\ActionPipeline' => ActionPipeline::class,
            'App\Domain\Shared\Services\DefinitionService' => DefinitionService::class,
            // Faz 6 — User runtime (Actions/DTO/Events/Listeners/Queries). The User
            // MODEL (Spatie HasRoles + Fortify contracts), Store/UpdateUserRequest,
            // UserController (Admin + Api), UserResource and UserPolicy stay app-owned.
            // Actions/Fortify/CreateNewUser stays app-owned. Rank-hierarchy behaviour in
            // UserDatatableQuery is byte-identical (relocation only). Event/listener
            // registration moves to the vendor registerEventListeners().
            // BulkActions/BulkDeleteUserAction stays app-owned: it extends the app-owned
            // App\Http\BulkActions\BulkDeleteAction override base, so it is not vendor-
            // aliased here (a vendor class with an app-owned parent would fatal under
            // class_alias eager-load).
            'App\Domain\User\Actions\CreateUserAction' => CreateUserAction::class,
            'App\Domain\User\Actions\DeleteUserAction' => DeleteUserAction::class,
            'App\Domain\User\Actions\UpdateUserAction' => UpdateUserAction::class,
            'App\Domain\User\DTOs\UserDTO' => UserDTO::class,
            'App\Domain\User\Events\UserCreated' => UserCreated::class,
            'App\Domain\User\Events\UserDeleted' => UserDeleted::class,
            'App\Domain\User\Events\UserUpdated' => UserUpdated::class,
            'App\Domain\User\Listeners\LogUserCreated' => LogUserCreated::class,
            'App\Domain\User\Listeners\LogUserDeleted' => LogUserDeleted::class,
            'App\Domain\User\Listeners\LogUserUpdated' => LogUserUpdated::class,
            'App\Domain\User\Queries\UserDatatableQuery' => UserDatatableQuery::class,
            'App\Domain\User\Queries\UserBulkSelectionQuery' => UserBulkSelectionQuery::class,
            'App\Exceptions\ApiException' => ApiException::class,
            'App\Exceptions\ApiExceptionHandler' => ApiExceptionHandler::class,
            'App\Http\Middleware\CheckResourcePermission' => CheckResourcePermission::class,
            'App\Http\Middleware\SecurityHeaders' => SecurityHeaders::class,
            'App\Http\Middleware\AssignTraceId' => AssignTraceId::class,
            'App\Http\Middleware\SetLocale' => SetLocale::class,
            'App\Http\Middleware\ValidateTurnstile' => ValidateTurnstile::class,
            'App\Support\HtmlSanitizer' => HtmlSanitizer::class,
            'App\Support\TranslatableQueryHelpers' => TranslatableQueryHelpers::class,
            'App\Support\MediaPathGenerator' => MediaPathGenerator::class,
            'App\Support\Scramble\ApiResponseExtension' => ApiResponseExtension::class,
            'App\Domain\FileManager\Support\ContextRegistry' => ContextRegistry::class,
            // v13.6.0 — behavior-module HTTP layer moved vendor-first. The
            // Log/ActivityLog/ApiRoute/Settings controllers + their FormRequests
            // now live in Lvntr\StarterKit\Http\...; these aliases keep an older
            // consumer's `App\Http\Controllers\Admin\X` / `App\Http\Requests\Admin\X`
            // imports (and any route file still referencing the App\ FQCN)
            // resolving to the vendor classes. Overridable: the file_exists guard
            // skips the alias when the consumer still ships its own copy (an
            // unmodified copy is removed by sk:update, a modified one keeps
            // winning), and `sk:eject` re-homes them under App\ so the override
            // wins again. FQ string literals (not ::class) so no import churn.
            'App\Http\Controllers\Admin\LogController' => 'Lvntr\StarterKit\Http\Controllers\Admin\LogController',
            'App\Http\Controllers\Admin\ActivityLogController' => 'Lvntr\StarterKit\Http\Controllers\Admin\ActivityLogController',
            'App\Http\Controllers\Admin\ApiRouteController' => 'Lvntr\StarterKit\Http\Controllers\Admin\ApiRouteController',
            'App\Http\Controllers\Admin\SettingsController' => 'Lvntr\StarterKit\Http\Controllers\Admin\SettingsController',
            'App\Http\Requests\Admin\Log\DeleteLogFilesRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\Log\DeleteLogFilesRequest',
            'App\Http\Requests\Admin\Log\EntryFilterRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\Log\EntryFilterRequest',
            'App\Http\Requests\Admin\Settings\SendTestMailRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\Settings\SendTestMailRequest',
            'App\Http\Requests\Admin\Settings\UpdateApidogSettingsRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\Settings\UpdateApidogSettingsRequest',
            'App\Http\Requests\Admin\Settings\UpdateAppearanceSettingsRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\Settings\UpdateAppearanceSettingsRequest',
            'App\Http\Requests\Admin\Settings\UpdateAuthSettingsRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\Settings\UpdateAuthSettingsRequest',
            'App\Http\Requests\Admin\Settings\UpdateFileManagerSettingsRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\Settings\UpdateFileManagerSettingsRequest',
            'App\Http\Requests\Admin\Settings\UpdateGeneralSettingsRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\Settings\UpdateGeneralSettingsRequest',
            'App\Http\Requests\Admin\Settings\UpdateMailSettingsRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\Settings\UpdateMailSettingsRequest',
            'App\Http\Requests\Admin\Settings\UpdatePostmanSettingsRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\Settings\UpdatePostmanSettingsRequest',
            'App\Http\Requests\Admin\Settings\UpdateStorageSettingsRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\Settings\UpdateStorageSettingsRequest',
            'App\Http\Requests\Admin\Settings\UpdateTurnstileSettingsRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\Settings\UpdateTurnstileSettingsRequest',
            'App\Http\Requests\Admin\Settings\UploadAppearanceLogoRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\Settings\UploadAppearanceLogoRequest',
            'App\Http\Requests\Admin\Settings\UploadFaviconRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\Settings\UploadFaviconRequest',
            // v13.6.0 Faz 2 — second wave of behavior-module HTTP-layer classes
            // moved vendor-first: the Settings-tab handlers (ApiClient, ApiToken,
            // SystemHealth, ContentLanguage) plus the Definitions/MediaUpload
            // API/Service controllers. Same overridable contract as the Faz 1
            // block above — the file_exists guard skips the alias when the
            // consumer still ships its own copy, sk:update removes an unmodified
            // copy, and sk:eject re-homes them under App\ so the override wins
            // again. FQ string literals (not ::class) so no import churn.
            //
            // INVARIANT: NO App\Models\* alias here. The ContentLanguage / Media /
            // Definition models stay app-owned (publish) so Laravel's
            // App\Models\X → App\Policies\XPolicy auto-discovery and route-model
            // binding keep working; the vendor classes reference them by App\ FQCN
            // (see StarterKitServiceProvider Setting-model note above).
            'App\Http\Controllers\Admin\ApiClientController' => 'Lvntr\StarterKit\Http\Controllers\Admin\ApiClientController',
            'App\Http\Controllers\Admin\ApiTokenController' => 'Lvntr\StarterKit\Http\Controllers\Admin\ApiTokenController',
            'App\Http\Controllers\Admin\SystemHealthController' => 'Lvntr\StarterKit\Http\Controllers\Admin\SystemHealthController',
            'App\Http\Controllers\Admin\ContentLanguageController' => 'Lvntr\StarterKit\Http\Controllers\Admin\ContentLanguageController',
            'App\Http\Controllers\Api\DefinitionController' => 'Lvntr\StarterKit\Http\Controllers\Api\DefinitionController',
            'App\Http\Controllers\Api\MediaUploadController' => 'Lvntr\StarterKit\Http\Controllers\Api\MediaUploadController',
            'App\Http\Controllers\Service\DefinitionServiceController' => 'Lvntr\StarterKit\Http\Controllers\Service\DefinitionServiceController',
            'App\Http\Requests\Admin\ApiClient\StoreApiClientRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\ApiClient\StoreApiClientRequest',
            'App\Http\Requests\Admin\ApiClient\UpdateApiClientRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\ApiClient\UpdateApiClientRequest',
            'App\Http\Requests\Admin\ApiToken\StoreApiTokenRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\ApiToken\StoreApiTokenRequest',
            'App\Http\Requests\Admin\ContentLanguage\StoreContentLanguageRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\ContentLanguage\StoreContentLanguageRequest',
            'App\Http\Requests\Admin\ContentLanguage\UpdateContentLanguageRequest' => 'Lvntr\StarterKit\Http\Requests\Admin\ContentLanguage\UpdateContentLanguageRequest',
            'App\Http\Resources\Admin\ApiClient\ApiClientResource' => 'Lvntr\StarterKit\Http\Resources\Admin\ApiClient\ApiClientResource',
            'App\Http\Resources\Admin\ApiToken\ApiTokenResource' => 'Lvntr\StarterKit\Http\Resources\Admin\ApiToken\ApiTokenResource',
            'App\Http\Resources\Admin\ContentLanguage\ContentLanguageResource' => 'Lvntr\StarterKit\Http\Resources\Admin\ContentLanguage\ContentLanguageResource',
            // ContentLanguage domain (Actions/DTO/Query) — Tier 3 full vendorize.
            // The App\Models\ContentLanguage model is NOT aliased (app-owned); the
            // vendor domain references it by App\ FQCN.
            'App\Domain\ContentLanguage\Actions\CreateContentLanguageAction' => 'Lvntr\StarterKit\Domain\ContentLanguage\Actions\CreateContentLanguageAction',
            'App\Domain\ContentLanguage\Actions\UpdateContentLanguageAction' => 'Lvntr\StarterKit\Domain\ContentLanguage\Actions\UpdateContentLanguageAction',
            'App\Domain\ContentLanguage\Actions\DeleteContentLanguageAction' => 'Lvntr\StarterKit\Domain\ContentLanguage\Actions\DeleteContentLanguageAction',
            'App\Domain\ContentLanguage\DTOs\ContentLanguageDTO' => 'Lvntr\StarterKit\Domain\ContentLanguage\DTOs\ContentLanguageDTO',
            'App\Domain\ContentLanguage\Queries\ContentLanguageDatatableQuery' => 'Lvntr\StarterKit\Domain\ContentLanguage\Queries\ContentLanguageDatatableQuery',
        ];

        foreach ($overridable as $appClass => $vendorClass) {
            $relativePath = str_replace('\\', '/', $appClass);
            if (str_starts_with($relativePath, 'App/')) {
                $relativePath = substr($relativePath, 4);
            }

            if (! file_exists($basePath.'/app/'.$relativePath.'.php')) {
                $plan[$appClass] = $vendorClass;
            }
        }

        return $plan;
    }

    /**
     * Resolve the backward-compat alias plan, using a cached manifest to avoid
     * re-running the per-request override-existence scan on every boot.
     *
     * backwardCompatAliasPlan() does one file_exists() per overridable alias
     * (~120+ stat syscalls) to decide which App\ names to alias to the vendor
     * class. That decision only changes when a consumer adds/removes an
     * app/<Class>.php override — a rare, tooling-driven event — so it is cached
     * to bootstrap/cache and re-used until an invalidation signal fires.
     *
     * Timing: this runs in register(), before the translator/router resolve, so
     * the cache path is a plain `include` of a `return [...]` file (opcache-hot
     * after the first request) with no container or config dependency beyond the
     * environment + base path already available at that point.
     *
     * Invalidation strategy (three independent layers, so a stale manifest can
     * never silently shadow — or drop — a consumer override):
     *
     *  1. **Dev/test skip.** In `local`/`testing` the plan is always computed
     *     fresh (backwardCompatAliasCacheEnabled() === false). Override files
     *     churn constantly during development; caching there would be a footgun.
     *     This is the mandated safe default — no manifest is ever written in dev.
     *
     *  2. **mtime self-invalidation.** The manifest is treated stale when any
     *     invalidation signal (the Composer classmap / composer.lock) is newer
     *     than it (aliasManifestIsFresh()). Every `composer dump-autoload` —
     *     which sk:install/sk:eject/sk:upgrade run, and which a consumer MUST run
     *     for a newly added app/ override to autoload under an optimized /
     *     classmap-authoritative build — rewrites the classmap, so the manifest
     *     self-heals on the next request without any explicit clear.
     *
     *  3. **Explicit flush.** sk:update mutates app/ override files directly and
     *     does NOT run composer dump-autoload, so layer 2 would not catch it;
     *     it calls flushBackwardCompatAliasCache() to drop the manifest, which
     *     is then rebuilt fresh on the next request.
     *
     * On any read miss the plan is recomputed and the manifest re-written
     * (best-effort — a non-writable bootstrap/cache degrades to computing every
     * request, i.e. exactly the pre-cache behaviour, never a boot failure).
     *
     * @return array<class-string, class-string>
     */
    protected function resolveBackwardCompatAliases(string $basePath): array
    {
        if (! $this->backwardCompatAliasCacheEnabled()) {
            return $this->backwardCompatAliasPlan($basePath);
        }

        $manifestPath = $this->aliasManifestPath();

        if ($this->aliasManifestIsFresh($manifestPath)) {
            $cached = @include $manifestPath;

            // A valid plan is never empty (the ApiResponse alias is
            // unconditional), so an empty/corrupt include is ignored and the
            // plan is recomputed below — defends against a torn/partial file.
            if (is_array($cached) && $cached !== []) {
                return $cached;
            }
        }

        $plan = $this->backwardCompatAliasPlan($basePath);

        $this->writeAliasManifest($manifestPath, $plan);

        return $plan;
    }

    /**
     * Whether the resolved alias plan may be cached to disk.
     *
     * Disabled in `local`/`testing`: override files change frequently there and
     * a stale manifest would silently shadow (or drop) a consumer override.
     */
    protected function backwardCompatAliasCacheEnabled(): bool
    {
        return ! $this->app->environment('local', 'testing');
    }

    /**
     * Absolute path of the cached alias manifest (bootstrap/cache).
     */
    protected function aliasManifestPath(): string
    {
        return $this->app->bootstrapPath(self::ALIAS_MANIFEST_RELATIVE);
    }

    /**
     * Files whose modification means the override surface may have changed, so
     * the cached alias manifest must be recomputed. Newer-than-manifest ⇒ stale.
     *
     * @return array<int, string>
     */
    protected function aliasInvalidationSignals(): array
    {
        $base = $this->app->basePath();

        return [
            // Rewritten by every `composer dump-autoload` (install/eject/upgrade
            // and any manual override add/remove under an optimized autoloader).
            $base.'/vendor/composer/autoload_classmap.php',
            // Bumped by composer update/require/remove (package version changes
            // that could alter the alias set).
            $base.'/composer.lock',
        ];
    }

    /**
     * True when the manifest exists and no invalidation signal is newer than it.
     */
    private function aliasManifestIsFresh(string $manifestPath): bool
    {
        if (! is_file($manifestPath)) {
            return false;
        }

        $manifestTime = @filemtime($manifestPath);

        if ($manifestTime === false) {
            return false;
        }

        foreach ($this->aliasInvalidationSignals() as $signal) {
            $signalTime = @filemtime($signal);

            if ($signalTime !== false && $signalTime > $manifestTime) {
                return false;
            }
        }

        return true;
    }

    /**
     * Write the resolved alias plan to the manifest (best-effort, atomic).
     *
     * Never throws: a non-writable bootstrap/cache simply skips the write and
     * the plan is recomputed on the next boot (pre-cache behaviour). The
     * temp-file + rename keeps a concurrent reader from ever seeing a partial
     * file on the register() hot path.
     *
     * @param  array<class-string, class-string>  $plan
     */
    private function writeAliasManifest(string $path, array $plan): void
    {
        $dir = dirname($path);

        if (! is_dir($dir) || ! is_writable($dir)) {
            return;
        }

        $contents = "<?php\n\n"
            ."// Auto-generated by Lvntr\\StarterKit — do not edit.\n"
            ."// Backward-compat alias plan cache; rebuilt automatically when the\n"
            ."// Composer classmap changes or sk:update flushes it.\n\n"
            .'return '.var_export($plan, true).";\n";

        $tmp = $path.'.'.getmypid().'.tmp';

        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            return;
        }

        if (! @rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    /**
     * Drop the cached backward-compat alias manifest.
     *
     * Called by tooling that mutates app/ override files WITHOUT triggering a
     * composer dump-autoload (notably sk:update), so the plan is rebuilt fresh
     * on the next request. No-op when the manifest is absent or the container is
     * unavailable. See resolveBackwardCompatAliases() invalidation layer 3.
     */
    public static function flushBackwardCompatAliasCache(): void
    {
        if (! function_exists('app')) {
            return;
        }

        $path = app()->bootstrapPath(self::ALIAS_MANIFEST_RELATIVE);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        $this->applyVendorConfigDefaults();
        $this->app->booted(fn () => $this->configureDataEncryption());
        $this->registerRouteModelBindings();
        $this->configureModels();
        $this->configurePassport();
        $this->configureGates();
        $this->configurePolicies();
        $this->configureRateLimiting();
        $this->configureScramble();
        $this->registerCommands();
        $this->registerEventListeners();
        $this->registerTranslations();
        $this->registerPublishables();
        $this->registerMigrations();
        $this->registerViews();

        // Middleware aliases — registered here so new vendor-first installs
        // resolve both alias names to the same vendor class. ServiceProvider
        // boot() runs AFTER bootstrap/app.php's withMiddleware() closure, so
        // even when consumer apps call Bootstrap::middleware() and register
        // 'check.permission' with their own App\Http\Middleware\CheckResourcePermission,
        // the vendor alias re-registration here wins last. Functionally this
        // is safe: the consumer's CheckResourcePermission and the vendor's
        // implementation both delegate to the same Spatie permission table.
        // Consumers needing a true override should bind the vendor class to
        // their own subclass via the container, not via alias re-registration.
        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('check.permission', CheckResourcePermission::class);
        $router->aliasMiddleware('check.resource.permission', CheckResourcePermission::class);
        $router->aliasMiddleware('sk.active', EnsureUserIsActive::class);
        $this->app->booted(fn () => $this->attachActiveUserMiddleware($router));

        $this->registerRoutes();
        $this->shareInertiaData();
    }

    /**
     * Append EnsureUserIsActive to the `web` and `api` middleware groups.
     *
     * Done here rather than in Bootstrap::middleware() so an EXISTING install
     * picks the guard up on `composer update` without editing bootstrap/app.php.
     *
     * TIMING — deferred to `booted()`, deliberately. EVERY kernel middleware
     * setter (setMiddlewareGroups, appendMiddlewareToGroup, …) ends in
     * Kernel::syncMiddlewareToRouter(), which calls Router::middlewareGroup()
     * and REPLACES a group wholesale, so anything that touches the kernel after
     * us would silently drop this append. The normal path — withMiddleware()'s
     * `afterResolving(HttpKernel)` hook — fires when the kernel is resolved,
     * strictly before BootProviders, so boot() would already be safe; running
     * on `booted()` additionally puts us after every OTHER provider's boot(),
     * which is the only window a package could still reach the kernel. Groups
     * are expanded by the router at dispatch time, so being late costs nothing
     * and stays correct under `route:cache`.
     *
     * Two defensive rules, both about never surprising a consumer:
     *
     *   1. A group the app does not define is SKIPPED, not created.
     *      Router::pushMiddlewareToGroup() would happily invent an `api` group
     *      on an app that deliberately has none; a phantom group is a config
     *      the operator never wrote.
     *   2. A group that already carries the middleware — by class OR by the
     *      `sk.active` alias, which pushMiddlewareToGroup()'s own in_array()
     *      dedupe cannot see — is left alone, so a consumer who wired it by
     *      hand does not get it twice.
     */
    private function attachActiveUserMiddleware(Router $router): void
    {
        $groups = $router->getMiddlewareGroups();

        foreach (['web', 'api'] as $group) {
            if (! array_key_exists($group, $groups)) {
                continue;
            }

            $existing = (array) $groups[$group];

            if (in_array(EnsureUserIsActive::class, $existing, true)
                || in_array('sk.active', $existing, true)) {
                continue;
            }

            $router->pushMiddlewareToGroup($group, EnsureUserIsActive::class);
        }
    }

    /**
     * Register cache-safe route-model binders for the FileManager `{media}`
     * and `{folder}` route parameters.
     *
     * SECURITY — trashed media access guard: `{media}` is bound to the
     * CONFIGURED media model (`media-library.media_model`) instead of relying
     * on implicit binding against Spatie's base Media class. The consumer's
     * Media model uses SoftDeletes, so its global scope drops trashed rows
     * from every `{media}` binding site (share show, download, rename, copy,
     * delete) with a 404 — trash means "not accessible" until restore, even
     * for otherwise-valid signed share URLs. On bare installs where the
     * config points at the base (non-SoftDeletes) model the binder is a
     * behavioral no-op: same resolution as implicit binding today.
     *
     * Registered here in boot() — NOT in src/routes/file-manager.php —
     * because `Route::model()` binders are not part of the route cache: under
     * `route:cache` the route files are never loaded, so a binder registered
     * only there silently disappears exactly where it matters most
     * (production). Must run AFTER applyVendorConfigDefaults() so the
     * vendor-supplied `media-library.media_model` default is visible.
     *
     * Note: binders are router-global — any consumer route using a `{media}`
     * or `{folder}` parameter resolves through the same configured models
     * (documented kit-wide semantics).
     */
    private function registerRouteModelBindings(): void
    {
        $mediaModel = config('media-library.media_model');

        if (is_string($mediaModel) && $mediaModel !== '' && is_subclass_of($mediaModel, Model::class)) {
            Route::model('media', $mediaModel);
        }

        $folderModel = config('file-manager.models.folder');

        if (is_string($folderModel) && $folderModel !== '' && is_subclass_of($folderModel, Model::class)) {
            Route::model('folder', $folderModel);
        }
    }

    /**
     * Share file-manager settings with Inertia so Vue components can read them
     * without explicit prop passing.
     */
    private function shareInertiaData(): void
    {
        if (! class_exists(Inertia::class)) {
            return;
        }

        Inertia::share('fileManagerSettings', fn () => [
            'enable_trash' => (bool) config('file-manager.settings.enable_trash', true),
        ]);
    }

    /**
     * Configure Eloquent strict mode.
     *
     * Strict mode is an opinionated global mutation (lazy-loading, accessing a
     * missing attribute and silently discarding a non-fillable assignment all
     * throw). It is deliberately enabled outside production only, so consumer
     * production traffic never 500s on a strictness violation. Apps that want
     * to opt out entirely — e.g. while integrating a legacy schema — can set
     * `starter-kit.strict_models` to false; the default (true) preserves the
     * kit's historical behaviour.
     */
    private function configureModels(): void
    {
        if (! config('starter-kit.strict_models', true)) {
            return;
        }

        Model::shouldBeStrict(! $this->app->isProduction());
    }

    /**
     * Point Fortify's two-factor encryption at the kit's data encrypter.
     *
     * COVERAGE — Fortify funnels every 2FA read and write through
     * `Fortify::currentEncrypter()`: EnableTwoFactorAuthentication and
     * ConfirmTwoFactorAuthentication (secret), GenerateNewRecoveryCodes and the
     * TwoFactorAuthenticatable trait's recoveryCodes()/replaceRecoveryCode()
     * (codes), TwoFactorLoginRequest, TwoFactorSecretKeyController,
     * RecoveryCodeController, and the kit's own
     * stubs/app/Domain/Auth/Actions/TwoFactorChallengeAction. Setting it once
     * here moves all of them onto DATA_ENCRYPTION_KEY without editing a stub.
     *
     * PRECEDENCE — Fortify::$encrypter is process-global static state, so the
     * decision of who wins has to be explicit rather than incidental:
     *
     *   1. A consumer that already called `Fortify::encryptUsing()` wins. Its
     *      existing two_factor_secret / two_factor_recovery_codes rows are
     *      encrypted with THAT encrypter; overwriting it would lock every 2FA
     *      user out of their own account on the next login. The check reads the
     *      public static directly instead of `currentEncrypter()`, because
     *      currentEncrypter() falls through to the Crypt facade and would both
     *      resolve the app encrypter at boot and throw when APP_KEY is absent.
     *   2. A consumer that called `Model::encryptUsing()` wins for the same
     *      reason: Fortify falls back to it today, so that app's 2FA columns
     *      are already written with it.
     *   3. Otherwise the kit installs its own shim. The check runs from an
     *      `$app->booted()` callback — after EVERY provider, package and
     *      application alike, has booted — so a consumer that calls
     *      `Fortify::encryptUsing()` or `Model::encryptUsing()` in its own
     *      boot() is seen and left alone. Package providers boot BEFORE
     *      application providers: a boot()-time check would install the shim
     *      first, and because currentEncrypter() prefers Fortify's own static
     *      over Model::$encrypter, a Model encrypter set later in that same
     *      boot would be silently outranked — locking every 2FA row written
     *      with it. The register()-time case is covered by the same guard.
     *
     * Nothing here resolves a key — see dataEncrypterProxy().
     */
    private function configureDataEncryption(): void
    {
        if (! class_exists(Fortify::class)) {
            return;
        }

        if (Fortify::$encrypter !== null || Model::$encrypter !== null) {
            return;
        }

        Fortify::encryptUsing($this->dataEncrypterProxy());
    }

    /**
     * A stateless shim that forwards to the `sk.data_encrypter` singleton,
     * resolved at CALL time rather than captured at boot.
     *
     * Two properties are load-bearing, and handing Fortify a resolved Encrypter
     * would forfeit both:
     *
     *   - Lazy. boot() must not read key material. A malformed
     *     DATA_ENCRYPTION_KEY, or no APP_KEY at all, would otherwise fail every
     *     request and every artisan command rather than only the paths that
     *     touch ciphertext.
     *   - Never stale. Fortify::$encrypter is static and outlives a container
     *     rebind — an Octane worker reload, or a test/command that swaps the key
     *     through flushDataEncrypter(). A captured instance there would keep
     *     writing 2FA secrets under the OLD key while SettingService wrote under
     *     the new one: a split-key corruption that surfaces only as a login
     *     failure, long after the change. Resolving per call cannot drift.
     *
     * It holds no key material, so it is also safe to serialize.
     *
     * It carries {@see KitOwnedEncrypter} so `encryption:health` and
     * `encryption:rekey` can tell this shim from an encrypter the APPLICATION
     * installed on Fortify. The two are behaviourally indistinguishable, and
     * the difference decides whether those commands may vouch for the 2FA
     * surface at all — see {@see EncrypterCoverage}. The marker is declarative:
     * it adds no method and changes nothing at runtime.
     */
    private function dataEncrypterProxy(): EncrypterContract
    {
        return new class implements EncrypterContract, KitOwnedEncrypter, StringEncrypter
        {
            public function encrypt(#[\SensitiveParameter] $value, $serialize = true)
            {
                return $this->encrypter()->encrypt($value, $serialize);
            }

            public function decrypt($payload, $unserialize = true)
            {
                return $this->encrypter()->decrypt($payload, $unserialize);
            }

            public function encryptString(#[\SensitiveParameter] $value)
            {
                return $this->encrypter()->encryptString($value);
            }

            public function decryptString($payload)
            {
                return $this->encrypter()->decryptString($payload);
            }

            public function getKey()
            {
                return $this->encrypter()->getKey();
            }

            public function getAllKeys()
            {
                return $this->encrypter()->getAllKeys();
            }

            public function getPreviousKeys()
            {
                return $this->encrypter()->getPreviousKeys();
            }

            /**
             * Keep key material out of dd()/dump()/var_dump() output.
             *
             * @return array<string, string>
             */
            public function __debugInfo(): array
            {
                return ['encrypter' => DataEncrypterFactory::BINDING.' (resolved per call; key material withheld)'];
            }

            private function encrypter(): Encrypter
            {
                return app(DataEncrypterFactory::BINDING);
            }
        };
    }

    /**
     * Configure Passport token lifetimes + optional scopes.
     */
    private function configurePassport(): void
    {
        if (! class_exists('Laravel\Passport\Passport')) {
            return;
        }

        // Access token TTL: prefer minutes-based config, fall back to the
        // legacy `access_token_days` key when explicitly set.
        $accessMinutes = (int) config('starter-kit.passport.access_token_minutes', 60);
        $legacyAccessDays = config('starter-kit.passport.access_token_days');
        if ($legacyAccessDays !== null && $legacyAccessDays !== '') {
            $accessMinutes = (int) $legacyAccessDays * 24 * 60;
        }

        $refreshDays = (int) config('starter-kit.passport.refresh_token_days', 14);

        // Personal access tokens: prefer days-based config, fall back to
        // the legacy `personal_token_months` key when explicitly set.
        $personalDays = (int) config('starter-kit.passport.personal_token_days', 30);
        $legacyPersonalMonths = config('starter-kit.passport.personal_token_months');
        if ($legacyPersonalMonths !== null && $legacyPersonalMonths !== '') {
            $personalDays = (int) $legacyPersonalMonths * 30;
        }

        // Laravel 11 varsayılan auth.php'de 'api' guard artık yok.
        // Passport::createToken() guard'ı config'den aradığı için bulamazsa
        // LogicException fırlatır. Kullanıcı kendi guard'ını tanımlamışsa dokunma.
        if (! config('auth.guards.api')) {
            config(['auth.guards.api' => [
                'driver' => 'passport',
                'provider' => config('starter-kit.passport.provider', 'users'),
            ]]);
        }

        Passport::tokensExpireIn(now()->addMinutes($accessMinutes));
        Passport::refreshTokensExpireIn(now()->addDays($refreshDays));
        Passport::personalAccessTokensExpireIn(now()->addDays($personalDays));

        $scopes = config('starter-kit.passport.scopes', []);

        if (is_array($scopes) && $scopes !== []) {
            Passport::tokensCan($scopes);

            $defaultScopes = config('starter-kit.passport.default_scopes', []);

            if (is_array($defaultScopes) && $defaultScopes !== []) {
                Passport::setDefaultScope($defaultScopes);
            }
        }
    }

    /**
     * Configure authorization gates.
     */
    private function configureGates(): void
    {
        if (! class_exists('App\Enums\RoleEnum') || ! class_exists('App\Models\User')) {
            return;
        }

        $systemAdminRole = RoleEnum::SystemAdmin;

        Gate::before(function (User $user) use ($systemAdminRole): ?bool {
            return $user->hasRole($systemAdminRole) ? true : null;
        });

        Gate::define('viewPulse', function (User $user) use ($systemAdminRole) {
            return $user->hasRole($systemAdminRole);
        });
    }

    /**
     * FileManager domain share gate'lerini register eder.
     *
     * K4 (security): Gate::policy(Media::class, MediaPolicy::class) kaldırıldı.
     * Policy-based kayıt tüm Media abilities'i (view, delete, update, ...) zorunlu
     * yapar ve MediaPolicy sadece share/revokeShare tanımladığından diğer abilities
     * için false dönüyor — consumer uygulamalarda sessiz erişim regression'ı yaratır.
     *
     * Yerine flat gate tanımları kullanılır. MediaPolicy class'ı internal kullanım için
     * hâlâ mevcuttur; ancak artık Gate'e register edilmez. Flat gate'ler yalnızca
     * kendi ability adlarını etkiler, başka Media abilities'e dokunmaz.
     *
     * Gate::before ile admin kullanıcılar zaten tüm gate'leri atlatır;
     * bu tanımlar non-admin kullanıcılar için ownership'i zorlar.
     */
    private function configurePolicies(): void
    {
        if (! config('file-manager.share.enabled', true)) {
            return;
        }

        $policy = new MediaPolicy;

        Gate::define('share-media', function ($user, Media $media) use ($policy): bool {
            // $user null → guest isteği; auth middleware bunu yakalamış olmalı
            // ama gate seviyesinde de güvenli bir şekilde reddediyoruz.
            if ($user === null) {
                return false;
            }

            return $policy->share($user, $media);
        });

        Gate::define('revoke-share-media', function ($user, Media $media) use ($policy): bool {
            if ($user === null) {
                return false;
            }

            return $policy->revokeShare($user, $media);
        });
    }

    /**
     * Apply the kit's third-party config defaults from vendor.
     *
     * These configs (media-library, activitylog, inertia) are no longer
     * published into the consumer app — the kit ships only the few overrides
     * it requires and applies them at runtime here. `mergeConfigFrom()` cannot
     * be used because the third-party providers already register the same keys
     * and shallow merge never overrides an existing key. Each override is
     * skipped when the consumer published their own copy of that config, so
     * publishing (the optional escape hatch) keeps full control.
     */
    private function applyVendorConfigDefaults(): void
    {
        $configPath = fn (string $file): string => function_exists('config_path')
            ? config_path($file)
            : $this->app->basePath('config/'.$file);

        // media-library: the FileManager Trash feature needs the kit's
        // soft-deletes Media model and the context-aware path generator.
        if (! file_exists($configPath('media-library.php'))) {
            config(['media-library.path_generator' => MediaPathGenerator::class]);

            if (class_exists('App\\Models\\Media')) {
                config(['media-library.media_model' => 'App\\Models\\Media']);
            }
        }

        // media-library: block active-content extensions at the media layer.
        // Uploads keep their client file name and are served as-is from the
        // public disk, so an .html/.svg/.js segment anywhere in the name is
        // stored XSS. Applied unconditionally — outside the "config not
        // published" guard above — so a consumer who published (or pinned an
        // older copy of) config/media-library.php is hardened too.
        $disallowedExtensions = config('media-library.disallowed_extensions');

        if (! is_array($disallowedExtensions)) {
            // property_exists() keeps this boot-safe on media-library builds
            // that predate the disallowed-extension guard; the literal list
            // mirrors Spatie's default so the kit's own per-segment check
            // (FileManagerRequest::hasDisallowedExtensionSegment) blocks the
            // same names on those builds.
            $disallowedExtensions = property_exists(FileAdder::class, 'defaultDisallowedExtensions')
                ? FileAdder::$defaultDisallowedExtensions
                : [
                    'php', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8',
                    'phtml', 'phtm', 'pht', 'phps', 'phar',
                    'shtml', 'shtm', 'stm',
                    'htaccess', 'htpasswd',
                    'cgi', 'pl', 'asp', 'aspx', 'jsp', 'jspx',
                ];
        }

        config(['media-library.disallowed_extensions' => array_values(array_unique([
            ...$disallowedExtensions,
            'html', 'htm', 'xhtml', 'xht', 'svg', 'svgz', 'xml', 'xsl', 'xslt', 'js', 'mjs', 'hta',
        ]))]);

        // activitylog: include soft-deleted subjects in the subject relation.
        if (! file_exists($configPath('activitylog.php'))) {
            config(['activitylog.include_soft_deleted_subjects' => true]);
        }

        // inertia: SSR is opt-in (enable via INERTIA_SSR_ENABLED=true).
        // Skipped when the configuration is cached: config:cache boots the app
        // and captures this very override into the cache while .env is still
        // loaded — re-running it on a cached boot would read env() as null and
        // stomp an enabled SSR flag back to false on every request.
        if (! $this->app->configurationIsCached() && ! file_exists($configPath('inertia.php'))) {
            config(['inertia.ssr.enabled' => (bool) env('INERTIA_SSR_ENABLED', false)]);
        }
    }

    /**
     * Configure rate limiters.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Per-account limiter for POST /api/v1/auth/login
        // (stubs/routes/api/public-api.php).
        //
        // It lives HERE, in the vendor layer, and not in the published
        // FortifyServiceProvider, because the route file that names it is a
        // published stub too and `sk:update` refreshes published files
        // INDEPENDENTLY of one another: a consumer who had customised their
        // provider (preserved on a hash mismatch) but not their route file
        // (refreshed, hash matched) would end up with a route naming a limiter
        // nobody registered, and Laravel answers that with a
        // MissingRateLimiterException — a 500 on every API login. A limiter
        // shipped from vendor cannot go missing. A consumer who wants different
        // numbers still overrides it by re-declaring the same name in their own
        // provider, which boots after this one.
        //
        // A SEPARATE limiter from the web 'login' one, not a reuse, for two
        // reasons:
        //
        //   1. It can never be relaxed. The login-throttle setting rewrites
        //      config('fortify.limiters.login'), which only Fortify's own route
        //      registration reads; the API route names this limiter literally,
        //      so no admin toggle reaches it.
        //   2. It preserves the endpoint's historical 5/min-per-IP ceiling. The
        //      web 'login' limiter allows 10/min per IP — reusing it would have
        //      doubled what a single IP may spend on the API.
        //
        // The per-email limit is the actual fix: until it existed, an attacker
        // spreading attempts for one account across many IPs hit no per-account
        // cap on the API at all. A separate email+IP limit like the web
        // limiter's would be dead weight here — it can never trip before the
        // 5/min IP limit does.
        //
        // Keyed on the literal 'email' field, deliberately NOT
        // Fortify::username(): the API login contract is fixed to `email`
        // (App\Http\Requests\Api\Auth\LoginRequest, App\Domain\Auth\DTOs\LoginDTO),
        // so a consumer who repoints fortify.username at another column would
        // otherwise collapse every API attempt into one empty-email bucket and
        // lock the endpoint out for everyone at 3 requests a minute.
        RateLimiter::for('api-login', function (Request $request) {
            // The body is unvalidated here — the limiter runs BEFORE
            // LoginRequest. A non-string `email` (an array, an object) must not
            // reach a string cast: that raises "Array to string conversion",
            // which the error handler turns into a 500 on a payload that used
            // to answer 422. A missing or non-string value gets no per-email
            // limit at all rather than sharing one global empty-email bucket
            // that anyone could hold at 429; such a request is rejected by
            // validation moments later anyway.
            $raw = $request->input('email');
            $email = is_string($raw) ? Str::transliterate(Str::lower($raw)) : '';

            $limits = [Limit::perMinute(5)->by('ip:'.(string) $request->ip())];

            if ($email !== '') {
                $limits[] = Limit::perMinute(3)->by('email:'.$email);
            }

            return $limits;
        });
    }

    /**
     * Configure Scramble API documentation.
     *
     * Scramble's document wiring (transformers + envelope extension) and the
     * docs-access gate are only ever needed when the OpenAPI spec is actually
     * built: during console doc export, or on a request targeting api-dock's
     * panel/spec routes. Booting the document generator on every ordinary
     * web/API request is pure overhead, so this is skipped outside that
     * context. class_exists() also keeps installs that removed Scramble from
     * fataling here.
     */
    private function configureScramble(): void
    {
        if (! class_exists(Scramble::class) || ! $this->runningInScrambleContext()) {
            return;
        }

        self::applyScrambleDocumentWiring();

        // `can()` returns false for an unknown/unseeded ability, whereas
        // hasPermissionTo() throws PermissionDoesNotExist — a fresh install
        // without the seeded api-docs permission should simply deny (403), not
        // 500. The docs middleware relies on this gate for its authorization.
        //
        // The parameter is typed to Authorizable, not App\Models\User: Gate
        // hands the closure whatever the configured auth provider resolved, so
        // an app whose user model is not App\Models\User would otherwise get a
        // TypeError (500) here instead of the intended 403. `can()` is declared
        // on Authorizable, which every Laravel user model implements.
        Gate::define('viewApiDocs', function (Authorizable $user) {
            return $user->can('api-docs.read');
        });
    }

    /**
     * Register Scramble's document wiring (bearer security transformer +
     * ApiResponse envelope extension) so an export produces the package's
     * full spec.
     *
     * Public and static because the boot-time context gate above cannot see
     * web-triggered exports: the admin API-route actions (`regenerate-docs`,
     * `postman-sync`, `apidog-sync`) run `Artisan::call('scramble:export')`
     * inside an ordinary web request, where runningInScrambleContext() was
     * false at boot. Those call sites invoke this right before exporting.
     *
     * Both halves reach api-dock's document unchanged, and no api-specific
     * re-registration is needed — verified against the installed sources:
     * api-dock does NOT register a private Scramble API. It documents
     * `config('api-dock.scramble_api')`, which defaults to
     * `Scramble::DEFAULT_API`, and `DocumentGenerator` generates from
     * `Scramble::getGeneratorConfig()` of exactly that name — the same
     * GeneratorConfig instance `Scramble::configure()` returns here, so the
     * bearer transformer below is appended to the collection it actually uses.
     * The envelope extension travels by config because
     * `ApiResponseExtension` is a TypeToSchemaExtension, and Scramble reads
     * `config('scramble.extensions')` for those inside its lazy
     * `TypeTransformer` binding (ScrambleServiceProvider), i.e. at generation
     * time rather than at its own boot — so provider boot order cannot lose it.
     * (Only OperationExtensions are collected eagerly in `bootingPackage()`;
     * this one is not an OperationExtension.)
     */
    public static function applyScrambleDocumentWiring(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        if (! self::$scrambleTransformersRegistered) {
            self::$scrambleTransformersRegistered = true;

            Scramble::configure()
                ->withDocumentTransformers(function (OpenApi $openApi) {
                    $openApi->secure(
                        SecurityScheme::http('bearer')
                    );
                });
        }

        // Teach Scramble to document the ApiResponse envelope. The extension
        // runs from vendor now, so it is registered here rather than relying
        // on a published config/scramble.php in the consumer app. The merge is
        // idempotent (array_unique), so it deliberately re-runs on every call —
        // under Octane a request-scoped config sandbox would otherwise lose it
        // while the static transformer guard survived the worker.
        config(['scramble.extensions' => array_values(array_unique(array_merge(
            (array) config('scramble.extensions', []),
            [ApiResponseExtension::class],
        )))]);
    }

    /**
     * True when Scramble's document wiring is actually needed: console doc
     * export/generation, or a request to api-dock's routes.
     *
     * api-dock mounts everything it serves under one configurable prefix
     * (`config/api-dock.php` → `route_prefix`, default `api-dock`): the panel
     * at the prefix root and the generated document at `<prefix>/spec` (see
     * vendor/lvntr/api-dock/routes/api-dock.php). Matching the prefix and its
     * subpaths therefore covers every request that can reach
     * `LvntR\ApiDock\Support\DocumentGenerator`. Scramble's own `docs/api*`
     * routes are no longer registered — see register().
     *
     * A blank prefix would mount api-dock at the application root, which this
     * gate deliberately does not follow (it would turn every request into a
     * documentation context); it falls back to the documented default instead.
     *
     * Under Pest/Testbench runningInConsole() is true, so doc-oriented tests
     * still exercise the wiring.
     */
    private function runningInScrambleContext(): bool
    {
        if ($this->app->runningInConsole()) {
            return true;
        }

        if (! $this->app->bound('request')) {
            return false;
        }

        $request = $this->app['request'];

        if (! $request instanceof Request) {
            return false;
        }

        $prefix = trim((string) config('api-dock.route_prefix', 'api-dock'), '/');

        if ($prefix === '') {
            $prefix = 'api-dock';
        }

        return $request->is($prefix, $prefix.'/*');
    }

    /**
     * Register Artisan commands.
     * Domain commands run from vendor only — they are NOT published to the
     * consumer's app/Console/Commands. Stub copies were removed in v13.5.2;
     * the vendor command registration here is the single source.
     */
    private function registerCommands(): void
    {
        // DoctorCommand is registered unconditionally so that Artisan::call('sk:doctor')
        // works from web requests (e.g. SystemHealthController::run).
        $this->commands([Console\Commands\DoctorCommand::class]);

        if ($this->app->runningInConsole()) {
            $commands = [
                Console\Commands\InstallCommand::class,
                Console\Commands\UpdateCommand::class,
                Console\Commands\UpgradeCommand::class,
                Console\Commands\PublishCommand::class,
                Console\Commands\EjectCommand::class,
                Console\Commands\MakeDomainCommand::class,
                Console\Commands\RemoveDomainCommand::class,
                Console\Commands\EnvSyncCommand::class,
                Console\Commands\SeedPermissionsCommand::class,
                Console\Commands\RedactActivityLogSecretsCommand::class,

                // Data-encryption key lifecycle. Deliberately INSIDE the
                // runningInConsole() block, unlike DoctorCommand: encryption:key
                // rewrites .env and encryption:rekey rewrites every encrypted
                // row, so neither may become reachable through
                // Artisan::call() from a web request the way sk:doctor is.
                // encryption:health is read-only but stays with them — it
                // reports which key opens which row, which is reconnaissance
                // that has no business on an HTTP surface.
                Console\Commands\EncryptionKeyCommand::class,
                Console\Commands\EncryptionRekeyCommand::class,
                Console\Commands\EncryptionHealthCommand::class,
            ];

            // Register the vendor PurgeFileManagerTrashCommand only when the
            // consumer app does not define its own version. The signature
            // 'file-manager:purge-trash' must appear exactly once — duplicate
            // registration causes an Artisan conflict exception.
            if (! class_exists('App\\Console\\Commands\\PurgeFileManagerTrash')) {
                $commands[] = Console\Commands\PurgeFileManagerTrashCommand::class;
            }

            $this->commands($commands);
        }
    }

    /**
     * Register vendor-resident domain event listeners.
     *
     * Only listeners whose BOTH event and listener live in vendor
     * (`Lvntr\StarterKit\Domain\*`) belong here — the registration key and the
     * dispatched object's `get_class()` are then the same vendor string, so the
     * dispatcher's string-keyed lookup matches.
     *
     * Why this is NOT in the consumer's DomainServiceProvider for the Logs
     * domain: the Logs event+listener were moved vendor-first, and
     * `DeleteLogFilesAction` (vendor) dispatches the VENDOR `LogFilesDeleted`.
     * On a fresh install the stub provider registered the listener under the
     * `App\Domain\Logs\Events\LogFilesDeleted::class` literal — a plain lexical
     * string that the class_alias never rewrites — so the dispatched vendor
     * object never matched and the audit listener silently never fired. Binding
     * here, with the vendor FQCN on both sides, is the fix.
     *
     * No double-fire risk (applies to every binding below):
     *   - Fresh install: only this vendor binding exists; vendor dispatch → 1 run.
     *     The stub DomainServiceProvider no longer registers these (the App-keyed
     *     Event::listen lines were removed when the domain moved vendor-first).
     *   - Existing consumer that kept its App\ event/listener+action: their
     *     App-keyed registration + App dispatch run once; this vendor binding is
     *     dormant (their App action never dispatches the vendor event).
     *   - Existing consumer reconciled to vendor (App copies removed): the alias
     *     makes the App import resolve to vendor, dispatch is the vendor object,
     *     and only this vendor binding matches — still exactly one run.
     *
     * Faz 6 — User and Role audit events (UserCreated/Updated/Deleted +
     * RoleCreated/Updated/Deleted) moved vendor-first alongside their Log*
     * listeners and their dispatching Create/Update/Delete actions. Their
     * registration moved here from the stub DomainServiceProvider for the SAME
     * reason as Logs: the vendor action dispatches the vendor event, and a stub
     * App-keyed `::class` literal would never match it.
     */
    private function registerEventListeners(): void
    {
        Event::listen(
            LogFilesDeleted::class,
            LogActivityForLogFilesDeleted::class,
        );

        // ── User audit events (vendor event + vendor listener) ───────────────
        Event::listen(UserCreated::class, LogUserCreated::class);
        Event::listen(UserUpdated::class, LogUserUpdated::class);
        Event::listen(UserDeleted::class, LogUserDeleted::class);

        // ── Role audit events (vendor event + vendor listener) ───────────────
        Event::listen(RoleCreated::class, LogRoleCreated::class);
        Event::listen(RoleUpdated::class, LogRoleUpdated::class);
        Event::listen(RoleDeleted::class, LogRoleDeleted::class);

        // ── schedule:run cron heartbeat ──────────────────────────────────────
        // Her `schedule:run` çağrısında (görev due olsun olmasın) bir timestamp
        // dosyası yazılır; sk:doctor ScheduleConfiguredCheck bununla cron'un
        // canlı olup olmadığını (dosya var mı / bayat mı) tespit eder. Yalnız
        // schedule:run komutunu dinler — string karşılaştırması, ihmal edilebilir
        // maliyet. Yazma en-iyi-çaba (@) — heartbeat başarısızlığı komutu bozmaz.
        Event::listen(CommandFinished::class, static function (CommandFinished $event): void {
            if ($event->command !== 'schedule:run') {
                return;
            }

            @file_put_contents(
                storage_path('framework/.schedule-last-run'),
                (string) time()
            );
        });
    }

    /**
     * Register translation/lang files.
     *
     * Two resolution paths for the SAME vendor `resources/lang` directory:
     *  - Namespaced: __('starter-kit::admin.menu.dashboard') via loadTranslationsFrom.
     *  - Namespace-less: __('sk-bulk.result') via registerNamespacelessKitTranslations()
     *    (called in register(); inserts the vendor lang dir into the loader's path
     *    list before app/lang so consumer overrides win).
     *
     * Users can override by publishing to lang/vendor/starter-kit/ (namespaced) or by
     * placing app/lang/{locale}/sk-*.php (namespace-less, app wins — see register()).
     */
    private function registerTranslations(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'starter-kit');
        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');
    }

    /**
     * Register publishable resources.
     *
     * Tag naming convention: every tag is prefixed with `starter-kit-` so
     * the package never collides with consumer-defined tags. Existing tags
     * (`starter-kit-config`, `starter-kit-lang`, `starter-kit-components`)
     * are kept verbatim because `InstallCommand` already references them;
     * Task 1 only adds new placeholder tags for resources that ship in
     * later tasks (views, migrations, stubs, file-manager subset). All
     * placeholder publishes are guarded by file_exists() / is_dir() so
     * `vendor:publish` does not error out before the source ships.
     */
    private function registerPublishables(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // Config
        $this->publishes([
            __DIR__.'/../config/starter-kit.php' => config_path('starter-kit.php'),
        ], 'starter-kit-config');

        // Lang files (optional publish for customization)
        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/starter-kit'),
        ], 'starter-kit-lang');

        // Vue components (optional publish for customization)
        $this->publishes([
            __DIR__.'/../resources/js/components/Lvntr-Starter-Kit' => resource_path('js/components/Lvntr-Starter-Kit'),
        ], 'starter-kit-components');

        // Task 1 placeholders: registered conditionally so they become
        // active automatically once the source files land in later tasks.
        // No existing publish flow changes today.

        // Blade views (Task 2+ may ship a few server-rendered views)
        $viewsPath = __DIR__.'/../resources/views';
        if (is_dir($viewsPath)) {
            $this->publishes([
                $viewsPath => resource_path('views/vendor/starter-kit'),
            ], 'starter-kit-views');
        }

        // Migrations (Task 8 will move package migrations here)
        $migrationsPath = __DIR__.'/../database/migrations';
        if (is_dir($migrationsPath)) {
            $this->publishes([
                $migrationsPath => database_path('migrations'),
            ], 'starter-kit-migrations');
        }

        // Stubs (Task 5+ may publish customizable scaffolding stubs)
        $stubsPath = __DIR__.'/../stubs';
        if (is_dir($stubsPath)) {
            $this->publishes([
                $stubsPath => base_path('stubs/starter-kit'),
            ], 'starter-kit-stubs');
        }

        // FileManager domain publishables (Task 6/7)
        $fileManagerConfig = __DIR__.'/../config/file-manager.php';
        if (file_exists($fileManagerConfig)) {
            $this->publishes([
                $fileManagerConfig => config_path('file-manager.php'),
            ], 'starter-kit-file-manager-config');
        }

        $fileManagerComponentsPath = __DIR__.'/../resources/js/components/Lvntr-Starter-Kit/FileManager';
        if (is_dir($fileManagerComponentsPath)) {
            $this->publishes([
                $fileManagerComponentsPath => resource_path('js/components/Lvntr-Starter-Kit/FileManager'),
            ], 'starter-kit-file-manager-components');
        }
    }

    /**
     * Register package migrations.
     *
     * Default behaviour: auto-load migrations from the package so consumer
     * apps inherit FileManager schema without a publish step. Existing apps
     * that already ran these files have their basenames recorded in the
     * `migrations` table — Laravel keys migration history by basename, so
     * the duplicate vendor copy is silently skipped on the next migrate run.
     * Filenames inside database/migrations/ are therefore immutable.
     */
    private function registerMigrations(): void
    {
        if ($this->app->runningInConsole() && config('starter-kit.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    /**
     * The vendor-mountable module route registry.
     *
     * Each descriptor declares a self-contained route module the package can
     * auto-mount on a fresh install — and that the consumer can override by
     * shipping their own route stub.
     *
     * Descriptor shape:
     *   - name:          Human-readable module key (diagnostics / errors only).
     *   - overrideStubs: Consumer route-file paths (absolute, base_path()).
     *                    If ANY of them exists, the package steps aside — the
     *                    consumer's route orchestrator (routes/web.php /
     *                    routes/api.php) loads it instead, so the package must
     *                    not auto-mount or it would double-register names.
     *   - middleware:    The outer middleware tier the group mounts under.
     *                    This is the SINGLE source of truth for the module's
     *                    auth/permission stack on the auto-mount path.
     *   - loader:        Closure that mounts the vendor route group. Held in
     *                    code (not config) so it survives `config:cache` —
     *                    closures are not serializable, which is exactly why
     *                    the registry lives here and not in config/.
     *
     * Adding a module (Faz 3/6 recipe): append one descriptor here with its
     * own override stubs, middleware tier and a `loader` closure that requires
     * the vendor route file (mirroring FileManager::routes()). registerRoutes()
     * picks it up generically — no further wiring needed.
     *
     * @return array<int, array{
     *     name: string,
     *     overrideStubs: array<int, string>,
     *     middleware: array<int, string>,
     *     loader: \Closure(): void
     * }>
     */
    protected function moduleRouteRegistry(): array
    {
        return [
            [
                'name' => 'file-manager',
                'overrideStubs' => [
                    base_path('routes/web/file-manager-route.php'),
                    base_path('routes/api/file-manager-route.php'),
                ],
                // K1 (security): The public share/show endpoint strips
                // auth+verified via withoutMiddleware() inside the route file
                // itself, so anonymous signed-URL access works even though the
                // group mounts under auth+verified here. No special handling
                // needed at this tier — the route file is self-contained.
                'middleware' => ['web', 'auth', 'verified'],
                'loader' => static function (): void {
                    FileManagerFacade::routes();
                },
            ],
            [
                'name' => 'sk-components',
                // Vendor-resident developer showcase (never published). The
                // override stub does not ship by default; a consumer can create
                // it to take over (or disable) the mount.
                'overrideStubs' => [
                    base_path('routes/web/sk-components-route.php'),
                ],
                // role:system_admin is applied inside the route file itself —
                // this tier only guarantees an authenticated, verified session.
                'middleware' => ['web', 'auth', 'verified'],
                'loader' => static function (): void {
                    require __DIR__.'/routes/sk-components.php';
                },
            ],
        ];
    }

    /**
     * Register vendor module routes from the registry.
     *
     * Override mechanism: when the consumer app already ships a module's
     * override stub (e.g. `routes/web/file-manager-route.php`) the orchestrator
     * in `routes/web.php` / `routes/api.php` loads it directly. In that case
     * the package MUST NOT auto-mount that module — doing so would register the
     * same route names twice and clash with the consumer's customized
     * controller.
     *
     * On a fresh install where no override stub exists, the package mounts the
     * module itself under its declared middleware tier — matching the
     * previously published stub's behaviour 1:1.
     *
     * Route-cache guard: under `route:cache` the compiled route file already
     * contains every mounted route, and boot() runs on top of that loaded
     * collection. Re-running the registry here would register each module's
     * route names a SECOND time (duplicate registration) and needlessly hit the
     * filesystem for the override-stub `file_exists()` probes on every request.
     * When routes are cached we therefore skip the whole registry. Model
     * binders (registerRouteModelBindings()) are deliberately NOT guarded —
     * `Route::model()` binders are never part of the route cache, so they must
     * be re-registered on every boot, cached or not (see that method's docblock).
     */
    private function registerRoutes(): void
    {
        // Cached routes are already loaded from the compiled cache file;
        // re-mounting here would duplicate every module's route names and run
        // the override-stub FS scan for nothing. Skip in that path entirely.
        if ($this->app->routesAreCached()) {
            return;
        }

        foreach ($this->moduleRouteRegistry() as $module) {
            // Consumer override: if any override stub is present, the consumer
            // owns the mount (via the stub one-liner that calls the module
            // loader, or a fully customized route file). The orchestrator in
            // routes/web.php picks it up automatically — skip this module.
            $overridden = false;

            foreach ($module['overrideStubs'] as $overrideStub) {
                if (file_exists($overrideStub)) {
                    $overridden = true;

                    break;
                }
            }

            if ($overridden) {
                continue;
            }

            // Fresh install fallback: mount under the module's declared
            // middleware tier so the feature works out of the box without
            // requiring a stub route file in the consumer app.
            Route::middleware($module['middleware'])->group($module['loader']);
        }
    }

    /**
     * Register package views (Blade templates).
     */
    private function registerViews(): void
    {
        $viewPath = __DIR__.'/../resources/views';

        if (is_dir($viewPath)) {
            $this->loadViewsFrom($viewPath, 'starter-kit');
        }
    }

    /**
     * Get the package base path.
     */
    public static function basePath(string $path = ''): string
    {
        return dirname(__DIR__).($path ? DIRECTORY_SEPARATOR.$path : '');
    }

    /**
     * Get the stubs path.
     */
    public static function stubsPath(string $path = ''): string
    {
        return static::basePath('stubs').($path ? DIRECTORY_SEPARATOR.$path : '');
    }
}
