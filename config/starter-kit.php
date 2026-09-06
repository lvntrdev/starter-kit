<?php

use Lvntr\StarterKit\Http\Middleware\CheckResourcePermission;
use Lvntr\StarterKit\Http\Middleware\SecurityHeaders;

return [

    /*
    |--------------------------------------------------------------------------
    | Package Migrations
    |--------------------------------------------------------------------------
    |
    | When true (default), the package auto-loads its own migrations via
    | loadMigrationsFrom() so consumer apps do not need to publish them.
    | Filenames inside database/migrations/ MUST stay stable across releases:
    | Laravel records the bare basename in the `migrations` table, so any
    | rename would re-run an already-applied migration and likely fail with
    | "table already exists". Apps that prefer to own a physical copy can
    | still publish via `vendor:publish --tag=starter-kit-migrations` and
    | flip this flag to false to disable auto-load.
    |
    */

    'run_migrations' => true,

    /*
    |--------------------------------------------------------------------------
    | Stub Manifest Version
    |--------------------------------------------------------------------------
    |
    | Used by sk:update to track which files have been published
    | and whether they have been modified by the user.
    |
    */

    'version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Published Stubs Hash Registry
    |--------------------------------------------------------------------------
    |
    | Stores hashes of published stubs so sk:update can detect
    | user modifications and skip those files.
    | This is auto-managed — do not edit manually.
    |
    */

    'published_hashes' => storage_path('starter-kit/hashes.json'),

    /*
    |--------------------------------------------------------------------------
    | Datatable defaults
    |--------------------------------------------------------------------------
    |
    | Used by DatatableQueryBuilder when the caller does not override the
    | value via perPage() or ?per_page=. Existing callers are unaffected —
    | the builder falls back to 10 when this key is absent.
    |
    | `max_per_page` caps the value accepted from the `?per_page=` query
    | parameter to protect against expensive queries / large payloads. The
    | builder falls back to 100 when this key is absent.
    |
    */

    'datatable' => [
        'default_per_page' => (int) env('STARTER_KIT_DATATABLE_PER_PAGE', 10),
        'max_per_page' => (int) env('STARTER_KIT_DATATABLE_MAX_PER_PAGE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Application namespace
    |--------------------------------------------------------------------------
    |
    | The namespace used by the consumer application. Publish/install flows
    | rewrite `App\…` references in published stubs to this value when it is
    | not the default `App`. Leave as `App` to keep the historical behavior.
    |
    */

    'app_namespace' => env('STARTER_KIT_APP_NAMESPACE', 'App'),

    /*
    |--------------------------------------------------------------------------
    | Eloquent strict mode
    |--------------------------------------------------------------------------
    |
    | When true (default), StarterKitServiceProvider enables Eloquent strict
    | mode (Model::shouldBeStrict) OUTSIDE production only — lazy-loading,
    | accessing a missing attribute and silently discarding a non-fillable
    | assignment all throw during local/staging/testing so bugs surface early,
    | while production traffic is never risked with a strictness 500.
    |
    | Set to false to opt out of this opinionated global mutation entirely
    | (e.g. when integrating a legacy schema that trips these guards).
    |
    */

    'strict_models' => env('STARTER_KIT_STRICT_MODELS', true),

    /*
    |--------------------------------------------------------------------------
    | Resource permission gating (CheckResourcePermission)
    |--------------------------------------------------------------------------
    |
    | The CheckResourcePermission middleware derives the required permission
    | from the route name (admin.users.index → users.read). When the resolved
    | permission is NOT seeded in the database the middleware is FAIL-CLOSED by
    | default: only `local` allows the request through (+ a logged warning);
    | every other environment — production, staging, uat, demo, testing —
    | denies it. This stops a forgotten permission row from silently exposing
    | an endpoint on a public non-production host.
    |
    | Two DIFFERENT failure axes live here — do not confuse them:
    |
    |   UNMAPPED   — a permission WAS derived from the route name, but no row
    |                with that name is seeded in the database.
    |   UNRESOLVED — NO permission could be derived at all: the route has no
    |                name, its name has fewer than two segments, or its action
    |                segment is not in the middleware's ACTION_ABILITY_MAP.
    |
    | `allow_unmapped` (env: STARTER_KIT_ALLOW_UNMAPPED_PERMISSIONS) covers the
    | first. Set it to true to restore the legacy behavior where ANY
    | non-production environment lets the unmapped permission through with a
    | warning. Production always denies regardless of this flag; local always
    | allows.
    |
    | `allow_unresolved` (env: STARTER_KIT_ALLOW_UNRESOLVED_ROUTES) covers the
    | second. Historically an unresolved route passed through in TOTAL SILENCE,
    | which is exactly how an ungated endpoint hides. With this flag true (the
    | shipped default) the request still passes but the middleware logs a
    | throttled warning naming the route, so the gap is visible. Set it to
    | false to deny instead.
    |
    | WHO GETS WHICH DEFAULT: `sk:install` seeds
    | STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false into a NEW project's .env, so a
    | fresh app is fail-closed from the first request. An app that sets nothing
    | falls through to CheckResourcePermission::ALLOW_UNRESOLVED_DEFAULT, which
    | is true.
    |
    | NO RELEASE FLIPS THAT CONSTANT ON A LIVE APP. A published copy of this
    | file predating the key lands on the same constant, so changing it would
    | alter authorization on a plain `composer update` for apps that edited
    | nothing. An existing install opts in itself: audit with
    | `php artisan sk:doctor --only=unresolved-routes`, then set the env var
    | (or this key) to false.
    |
    | ASYMMETRY, deliberate: unlike `allow_unmapped`, `allow_unresolved` keeps
    | applying in production once flipped. An unmapped permission is a DATA gap
    | the operator fixes on the host by seeding the row; an unresolved route is
    | a STRUCTURAL mismatch between the route table and the ability map, fixable
    | only by renaming a route or shipping code. The escape hatch therefore has
    | to exist on the host where it breaks.
    |
    | `unrestricted_routes` lists route-name patterns (Str::is wildcards, e.g.
    | 'api.v1.auth.*') that are DELIBERATELY permission-free: they pass with no
    | warning and are never denied. This is the supported way to declare intent.
    | Two limits worth knowing:
    |   - It is consulted ONLY on the UNRESOLVED axis. It can never disable the
    |     check for a route whose permission DOES resolve, so it cannot be used
    |     to bypass a real gate.
    |   - Keep the patterns TIGHT. A broad entry such as 'admin.*' exempts every
    |     unresolved admin route at once — including ones added later that you
    |     never reviewed — and permanently opts them out of the flip above.
    |     Prefer listing endpoints, not trees.
    |
    */

    'permissions' => [
        'allow_unmapped' => (bool) env('STARTER_KIT_ALLOW_UNMAPPED_PERMISSIONS', false),

        'allow_unresolved' => (bool) env(
            'STARTER_KIT_ALLOW_UNRESOLVED_ROUTES',
            CheckResourcePermission::ALLOW_UNRESOLVED_DEFAULT,
        ),

        'unrestricted_routes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Passport OAuth2 configuration
    |--------------------------------------------------------------------------
    |
    | Token lifetimes and optional scope definitions. Scopes are opt-in:
    | leave empty to keep Passport's defaults (a single implicit scope).
    | When populated, StarterKitServiceProvider calls Passport::tokensCan()
    | at boot and Passport::setDefaultScope() with the configured default.
    |
    */

    'passport' => [
        // Auth provider backing the auto-registered `api` guard. The provider
        // is only synthesised when the consumer app has not already defined an
        // `api` guard, so a custom guard is never overridden. Point this at the
        // auth provider whose model is your Passport `HasApiTokens` user (the
        // key must exist under `auth.providers`).
        'provider' => env('STARTER_KIT_PASSPORT_PROVIDER', 'users'),

        // Access tokens are short-lived by default — leaked bearer tokens
        // should expire before they are abused. Prefer refresh tokens for
        // session longevity, not long access-token TTLs.
        'access_token_minutes' => (int) env('PASSPORT_TOKEN_MINUTES', 60),
        'refresh_token_days' => (int) env('PASSPORT_REFRESH_TOKEN_DAYS', 14),
        'personal_token_days' => (int) env('PASSPORT_PERSONAL_TOKEN_DAYS', 30),

        // Legacy keys kept for backward compatibility. If `access_token_days`
        // is set (non-null) it overrides `access_token_minutes`.
        'access_token_days' => env('PASSPORT_TOKEN_DAYS'),
        'personal_token_months' => env('PASSPORT_PERSONAL_TOKEN_MONTHS'),

        // Default catalog of scopes. Enforcement is opt-in: attach
        // `middleware('scope:users.read')` (or similar) to API routes you
        // want to restrict. Leaving `default_scopes` empty preserves
        // Passport's implicit `*` scope so existing clients keep working.
        'scopes' => [
            'users.read' => 'Read user data',
            'users.write' => 'Create and modify users',
            'files.read' => 'Read files and folders',
            'files.write' => 'Create, move, and delete files',
            'admin' => 'Full administrative access',
        ],

        'default_scopes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | csp_extra_origins (SecurityHeaders middleware)
    | ----------------------------------------------
    | Extra origins appended to the img-src / media-src / connect-src CSP
    | directives, on top of the origins derived automatically from the
    | media-library disk and the public disk (a disk `url`, an s3 `endpoint`,
    | or plain-AWS region/bucket). Use full origins, e.g.:
    |
    |   'csp_extra_origins' => ['https://cdn.example.com'],
    |
    | csp_nonce (SecurityHeaders middleware, env: STARTER_KIT_CSP_NONCE)
    | ------------------------------------------------------------------
    | Switches script-src from `'unsafe-inline'` to a per-request
    | `'nonce-<random>'`, so an injected inline <script> no longer executes.
    | The middleware generates the nonce through `Vite::useCspNonce()` BEFORE
    | the response is rendered, and the view prints it on the one inline script
    | the kit ships (the FOUC-killer theme script in `resources/views/
    | app.blade.php`).
    |
    | OPT-IN, default false, ON PURPOSE — this is the one flag here whose
    | stricter setting can break a working install. A browser IGNORES
    | `'unsafe-inline'` the moment a nonce appears in script-src, so an app
    | whose PUBLISHED `app.blade.php` predates the nonce attribute loses that
    | theme script outright the second this flag flips: no error, no blocked
    | request, just a panel that opens in the wrong theme and flashes on every
    | load.
    |
    | WHO GETS WHICH VALUE — same mechanism as `permissions.allow_unresolved`
    | above: `sk:install` seeds STARTER_KIT_CSP_NONCE=true into the `.env` of a
    | NEW project (whose freshly published Blade carries the attribute) through
    | its FIRST_INSTALL_ONLY_ENV_KEYS list. Re-running `sk:install` on an
    | installed app skips the key, and `sk:update` / `sk:upgrade` never write
    | `.env` at all, so no existing install is flipped by an update. An app that
    | sets nothing lands on SecurityHeaders::CSP_NONCE_DEFAULT, which is false.
    |
    | Turning it on by hand is a two-step change: put
    | `nonce="{{ Vite::cspNonce() }}"` on that script tag in your published
    | `resources/views/app.blade.php` first, then set the env var. With the flag
    | off that attribute renders empty and is inert — a policy carrying no
    | nonce-source still honours 'unsafe-inline'. `style-src 'unsafe-inline'` is
    | unaffected either way — PrimeVue writes inline styles at runtime and
    | cannot be nonce'd.
    |
    | Active-account enforcement (EnsureUserIsActive middleware)
    | ----------------------------------------------------------
    | The login path already refuses a non-active account, but it cannot reach
    | a session that is ALREADY open. EnsureUserIsActive closes that window: an
    | account disabled mid-session is cut on its next request.
    |
    |   enforce_active_status  The kill switch. Set to false to disable the
    |                          middleware outright without touching
    |                          bootstrap/app.php.
    |
    |   active_status_denied   The ONLY statuses that terminate a session. The
    |                          middleware never infers "not active therefore
    |                          blocked" — an unknown value, and null, pass
    |                          through. The default holds exactly the two
    |                          non-active values the shipped `userStatus`
    |                          definition produces (see the DefinitionSeeder
    |                          stub); an install that uses its own vocabulary
    |                          adds it here, e.g.:
    |
    |                            'active_status_denied' => [
    |                                'inactive', 'banned', 'suspended',
    |                            ],
    |
    |                          Matching is case-insensitive and trimmed. An
    |                          EMPTY array blocks nothing — it is treated as a
    |                          deliberate opt-out, not as "use the default".
    |
    |   active_status_guards   Guards consulted for an authenticated user, in
    |                          order. A guard missing from `auth.guards` is
    |                          skipped silently, so leaving `api` listed on an
    |                          install without Passport costs nothing.
    |
    | STALE PUBLISHED CONFIG: mergeConfigFrom merges TOP-LEVEL keys only. A file
    | published before this release has no `security` key at all, so the vendor
    | block is inherited whole; but a file that carries a PARTIAL `security`
    | array replaces the vendor one for every nested key. The middleware
    | therefore falls back to class constants that reproduce these literals
    | exactly (SecurityHeaders::CSP_NONCE_DEFAULT, EnsureUserIsActive::
    | ENFORCE_DEFAULT / ::DENIED_DEFAULT / ::GUARDS_DEFAULT), so both
    | populations resolve the same values. Keep the two in sync — same
    | discipline as the `encryption` block below.
    |
    */

    'security' => [
        'csp_extra_origins' => [],

        'csp_nonce' => (bool) env('STARTER_KIT_CSP_NONCE', SecurityHeaders::CSP_NONCE_DEFAULT),

        'enforce_active_status' => true,

        'active_status_denied' => ['inactive', 'banned'],

        'active_status_guards' => ['web', 'api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Data encryption (DataEncrypterFactory, DataCrypt)
    |--------------------------------------------------------------------------
    |
    | The kit encrypts sensitive `settings.value` rows (mail password, storage
    | secrets, Turnstile secret, API tokens) plus the Fortify 2FA secret and
    | recovery codes. Historically ALL of that rode on APP_KEY, which means one
    | `php artisan key:generate` during a server migration makes every one of
    | those values permanently unreadable — and silently, because
    | SettingService swallows the decrypt failure and returns null.
    |
    | DATA_ENCRYPTION_KEY decouples that data from APP_KEY. It is OPT-IN: leave
    | it blank and the primary key stays APP_KEY, behaviourally identical to
    | today. `php artisan encryption:key` adopts a dedicated key on an existing
    | install. See docs/encryption.md and docs/server-migration-runbook.md.
    |
    | KEY RESOLUTION CONTRACT — implemented by DataEncrypterFactory, locked by
    | tests/Feature/Encryption and tests/Feature/BackwardCompat:
    |
    |   DATA_ENCRYPTION_KEY blank -> primary = APP_KEY
    |                                chain   = DATA_ENCRYPTION_PREVIOUS_KEYS
    |   DATA_ENCRYPTION_KEY set   -> primary = DATA_ENCRYPTION_KEY
    |                                chain   = DATA_ENCRYPTION_PREVIOUS_KEYS,
    |                                          then APP_KEY LAST
    |
    | APP_KEY is appended to the read chain whenever it differs from the
    | primary, so every row written before adoption keeps decrypting with no
    | command run at all. Laravel's own APP_PREVIOUS_KEYS list rides along for
    | the same reason: every encrypted setting and every Fortify 2FA column used
    | to be read through the framework's `encrypter` binding, which honours it,
    | so an install part-way through an APP_KEY rotation would otherwise lose
    | those rows the moment it upgraded. The chain is ordered, empties are dropped and
    | duplicates are removed. A malformed key is NEVER skipped silently — it
    | throws and names the offending env var, because a silently skipped key
    | during rotation looks exactly like data loss and pushes an operator into
    | causing the real thing by clearing DATA_ENCRYPTION_PREVIOUS_KEYS.
    |
    | `cipher` deliberately has NO literal default here. Left null the factory
    | falls back to `app.cipher`, then to DataEncrypterFactory::DEFAULT_CIPHER.
    | Two reasons, both about not breaking a live app:
    |   - An app that changed `app.cipher` (e.g. AES-128-CBC with a 16-byte
    |     APP_KEY) would otherwise get a 256-bit cipher forced onto its 128-bit
    |     APP_KEY and throw on every encrypted read.
    |   - mergeConfigFrom is a SHALLOW merge, so an app whose published copy of
    |     this file predates this block hides the whole `encryption` array and
    |     reads null for every key below. A literal default here would make
    |     those two populations resolve DIFFERENT ciphers. Every factory read
    |     is null-safe for exactly that reason — do not add a literal default
    |     the null path cannot reproduce (same discipline as
    |     CheckResourcePermission::ALLOW_UNRESOLVED_DEFAULT above).
    |
    | When BOTH ciphers are set they must agree. APP_KEY always closes the
    | read chain and one cipher serves the whole chain, so the factory refuses
    | a DATA_ENCRYPTION_CIPHER that differs from `app.cipher` instead of
    | decrypting APP_KEY-written rows with the wrong algorithm.
    |
    */

    'encryption' => [
        'key' => env('DATA_ENCRYPTION_KEY'),
        'previous_keys' => env('DATA_ENCRYPTION_PREVIOUS_KEYS'),
        'cipher' => env('DATA_ENCRYPTION_CIPHER'),
    ],

];
