# Changelog

All notable changes to `lvntr/laravel-starter-kit` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [13.4.0] - 2026-04-21

Security hardening sprint — a parallel code-review sweep surfaced ~37 findings across HIGH / MEDIUM / LOW severities. 36 are closed in this release; 1 HIGH (Passport private-key rotation in git history) is a manual operator step documented in the consumer [UPGRADE guide](https://github.com/lvntrdev/laravel-starter-kit/blob/main/docs/UPGRADE.md). Most patches touch **shipped** files (the files `sk:install` copies into the consumer app), so existing consumer apps must follow the UPGRADE guide to apply the diffs; new installs pick everything up automatically. Package-tier changes (HSTS `preload`, stub refresh) arrive via `composer update lvntr/laravel-starter-kit`.

### Security

- **Self-delete blocked on shipped `UserPolicy::delete` + null guard on shipped `Api\UserController::destroy`.** The shipped `UserPolicy::delete` stub previously returned `true` when actor === target, so any authenticated user holding `users.delete` could remove themselves via `DELETE /api/v1/users/{self}`. The self-branch now returns `false` — the only supported self-removal path is the password-confirmed Fortify flow in Profile. `Api\UserController::destroy` also returns a clean 401 when `$request->user()` is null (stale/expired bearer), replacing the previous `(string) null = ''` cast that logged an empty performer id.

- **Shipped `CreateRoleAction` + `UpdateRoleAction` wrap role + permission sync in `DB::transaction`.** `Role::create(...)` followed by `->syncPermissions(...)` ran outside a transaction; a permission-cache race or connection drop between the two writes could leave a role row with no permissions. Both actions now run inside `DB::transaction(...)`; `RoleCreated` / `RoleUpdated` dispatch after commit so listeners observe a consistent state.

- **Shipped `UpdateAuthSettingsAction` wraps the 2FA revoke loop in `DB::transaction`.** When the admin toggles `auth.two_factor` off, the action writes the setting and then clears `two_factor_*` columns on every user. A mid-loop failure previously left the system in a half-revoked state — the setting said "2FA off" but some users still had active TOTP secrets. The full operation is now atomic.

- **Shipped `LogoutUserAction` — null-safe token revoke.** The API logout endpoint called `$user->token()->revoke()`; if the request reached the controller without an active access token the chained call threw `Error: Call to a member function revoke() on null` and 500'd. Now uses `?->revoke()`.

- **Shipped FileManager subtree walks reduced from N queries to 1.** `BulkDeleteAction::collectDescendantIds` and `DeleteFolderAction::collectDescendantIds` issued a `FileFolder::find` per hop — a 50-level tree meant 50 serial queries, giving attackers a request-timing DoS knob. Both actions now load the owner-scoped `(id, parent_id)` map in one `select` and walk the tree in PHP with a visited-set cycle guard.

- **Shipped `SettingsServiceProvider` — SMTP `encryption=none` now disables TLS correctly.** The "No encryption" Mail settings option wrote the literal string `'none'` into `config('mail.mailers.smtp.encryption')`. Laravel's SMTP transport treats any non-null value as "use this TLS mode", so saved configurations fell back to STARTTLS on first connect. The provider now maps `'none' → null`.

- **Shipped `ApiExceptionHandler` — exception-message leak + `X-Request-ID` log injection.** The `default` arm of the exception→status mapping returned `config('app.debug') ? $e->getMessage() : 'A server error occurred.'`; in any environment where `APP_DEBUG` was accidentally left on, unhandled exceptions leaked stack-trace-grade detail to API consumers. The handler now returns the generic message unconditionally; debug details live only in `Log::error` plus the `debug` block that is already gated on `APP_DEBUG`. The trace id is always server-generated via `Str::uuid()`; any client-supplied `X-Request-ID` is accepted only after a `[A-Za-z0-9._-]{1,128}` sanitiser and is logged as `client_request_id` — a malicious client can no longer inject a CRLF payload or a fake trace id into the application log.

- **Package `SecurityHeaders` HSTS directive gains `preload`.** The baseline HSTS header moved from `max-age=31536000; includeSubDomains` to `max-age=31536000; includeSubDomains; preload`, making the deployment eligible for the HSTS preload list. Ships from the package `src/` — picked up automatically by `composer update`.

- **Shipped `AppServiceProvider` raises the password policy.** A project-wide `Password::defaults(...)` now enforces 10+ chars, mixed case, letters, numbers and symbols; every FormRequest relying on the default picks this up automatically (registration, password reset, password confirm, profile password change). Existing users' passwords are not invalidated — only new passwords are measured against the stronger rule.

- **Shipped `resources/js/app.ts` — Axios CSRF + credential defaults.** `axios.defaults.withCredentials = true`, `xsrfCookieName = 'XSRF-TOKEN'`, `xsrfHeaderName = 'X-XSRF-TOKEN'` + `X-Requested-With: XMLHttpRequest` + `Accept: application/json`. Admin UI calls to Fortify endpoints (2FA, sessions, password-confirm) now pass through the same CSRF check the web flow relies on.

- **Shipped `TwoFactorTab.vue` — QR code rendered through `<img src="data:image/svg+xml;base64,...">` instead of `v-html`.** Fortify returns the QR code as an SVG string; a man-in-the-middle or compromised Fortify override could have smuggled `<script>` / `onload` into it. The new approach base64-encodes the SVG into an `<img>` data URL — the `<img>` sandbox does not execute inline scripts.

- **Shipped `useDefinition.load()` / `loadAll()` — error-safe fetch.** The composable is the one-stop loader for the definition JSON that drives datatable / form option dropdowns. It previously chained `.then(r => r.json())` directly — a failed fetch left `loaded.value = true` with an empty payload, so consumers rendered stale or empty dropdowns with no console feedback. Both methods are now `try/catch`-wrapped, check `res.ok`, surface errors to the console, and leave `loaded.value = false` on failure so consumers can retry.

- **Shipped FormRequest `authorize(): return true;` — eleven offenders closed.** The following requests — admin user store, API user store, admin role store, admin settings (auth/general/mail/storage/filemanager/turnstile), test-mail, destroy-sessions — now delegate `authorize()` to the matching `*.create` / `*.update` permission (destroy-sessions checks `$this->user() !== null`). The `CheckResourcePermission` middleware already enforced these at the route level, but the in-request check closes the defense-in-depth gap that opens the moment the action is invoked off-route or the route-name map drifts. Public auth endpoints and FileManager context-based requests are intentionally left alone.

- **Shipped `TwoFactorChallengeAction` — single-use challenge.** The action previously left the `api:2fa_challenge:{uuid}` cache entry intact on a wrong TOTP / wrong recovery code / empty submit, so an attacker with a valid challenge id got the full 5-minute TTL × `throttle:5/min` window to try codes. Every failure arm now calls `Cache::forget($cacheKey)`.

- **Shipped `SettingService` — read from `allGrouped()` cache + `setGroup()` wrapped in `DB::transaction`.** The hot read path previously ran one query per `getValue()` / `getGroup()` even though a full-cache layer existed. Settings-heavy requests (Dashboard, FileManager, Admin pages) save a handful of round-trips per request. Bulk writes are also now atomic.

- **Shipped `MoveItemRequest` — typed `item_id` based on `item_type`.** Effective rule: `integer|min:1` for `item_type=file`, `uuid` for `item_type=folder`, matching the DB schema; `item_type` itself uses `Rule::in([...])` instead of the `string|in:...` string form.

- **New shipped `DeleteFolderRequest` replaces a bare `Request` in `FileManagerController::deleteFolder`.** Extends `FileManagerRequest`, runs the shared context rules, and exposes `$request->context()` — identical surface to the other FileManager endpoints.

- **Shipped `Admin\UserController::uploadAvatar` runs an explicit `Gate::authorize('update', $user)`.** Redundant with the existing `UploadAvatarRequest::authorize()` delegation to `UserPolicy::update`, but mirrors the belt-and-braces pattern used on view/update/delete and keeps the check visible when reading the controller in isolation.

### Security — manual operator step (not automated)

- **Passport private-key rotation (GV-H1).** `storage/oauth-private.key` / `storage/oauth-public.key` live in git history for legacy installs that committed them before the `.gitignore` rule landed. The [UPGRADE guide](https://github.com/lvntrdev/laravel-starter-kit/blob/main/docs/UPGRADE.md) documents the `git filter-repo` + `passport:keys --force` + `passport:purge` + team-wide `git reset --hard` sequence. If the install never committed the key files, this step is skipped.

### Changed

- **Shipped `.env.example` — `LOG_LEVEL` default flipped from `debug` to `error`.** `debug` in production fills the log with SQL traces and Passport debug info — noisy and occasionally sensitive. Production profiles should ship `error` or `warning`.

- **Shipped `.env.example` — `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` stubs + Turnstile placeholders.** Two commented-out placeholders document the env-based key-loading path (recommended over committing `storage/oauth-*.key`), and an uncommented `TURNSTILE_ENABLED=false` + empty site/secret keys make the Turnstile middleware a no-op on fresh installs.

- **Shipped `composer.json` stub — `laravel/tinker` moved from `require` to `require-dev`.** Tinker is a dev tool; shipping it as a production dependency pulled PsySH and its transitive chain into every container build. Local dev still installs it because it's in `require-dev`.

- **Shipped `HandleInertiaRequests::share` — `appEnv` / `appDebug` only leak outside production.** Both keys return `null` / `false` under `app()->environment('production')`; non-prod keeps the real value for the dev overlay.

- **Shipped `config/cors.php` — `max_age` raised from 0 to 7200 seconds.** SPA / mobile clients can cache the OPTIONS response for 2 hours instead of re-running the preflight on every mutating request.

### Fixed

- **Shipped `useDialog` / `useImageLightbox` — 300 ms timer leak.** A rapid `open → close → open` sequence queued two timers; the trailing one fired after the dialog was re-opened and cancelled the render. A module-level timer ref is now cleared on both `open()` and `close()` entry.

- **Shipped `SkForm` — dirty-form guard stops parent prop updates from wiping user input.** `watch(derivedDefaults, ...)` used to reset the form unconditionally whenever the parent passed a new object; a polled datatable / shared-state update wiped in-progress input. The watcher now checks `internalForm.isDirty` — dirty forms record new values as defaults without touching the live state.

- **Shipped `SkDatatable` URL filters — `api.get` + `Promise.allSettled`.** The URL-driven filter loader used bare `fetch(...)` + `Promise.all`; a single failing filter-options endpoint poisoned the whole filter bar. The loader now uses the shared `api.get<T>()` helper (picks up the Axios defaults + XSRF) and `Promise.allSettled`, so each filter is independent; failing endpoints fall back to an empty list with a console warning. Same file flips `let activeMenuItems` → `const activeMenuItems`.

- **Shipped `TwoFactorTab.enableTwoFactor` awaits the Inertia reload.** `router.reload({ only: [...] })` is now wrapped in a promise that resolves on `onFinish` — the QR fetch no longer races the reload on slow connections.

- **Shipped `ProfileInfoTab` / `UserForm` — `as any` avatar casts replaced with typed shapes.** No behaviour change, but the cast hid a legitimate TypeScript error if the backing type ever dropped the `avatar_url` accessor.

- **Shipped `Admin\DashboardController::index` gains an explicit `: Response` return type.** Closes the last Larastan `return_type_missing` finding.

### Upgrade

New installs via `sk:install` pick up everything automatically. Existing consumer apps: `composer update lvntr/laravel-starter-kit --with-all-dependencies` picks up only the package `src/` tier (HSTS `preload`, stub refresh) — the rest of the fixes land in published / stub-backed files. Follow [docs/UPGRADE.md](https://github.com/lvntrdev/laravel-starter-kit/blob/main/docs/UPGRADE.md) for the full diff-style patch list and smoke-test checklist.

## [13.3.3] - 2026-04-20

### Fixed

- **Windows production build failed with `Could not load .../FormBuilder/core`.** `FormBuilder`, `DatatableBuilder` and `TabBuilder` each expose a `core/` directory whose `index.ts` is imported as `@lvntr/components/<Builder>/core`. On some Windows setups Vite's resolver skipped the directory→`index.ts` step and fell through to `vite:load-fallback`, which tried to read the directory as a file and raised `ENOENT`. Fix: a sibling `core.ts` barrel file now re-exports from `./core/index` for each of the three builders, so the import resolves to a real file on every platform. macOS/Linux behaviour is unchanged, and existing subpath imports like `/core/builder` are untouched. Fixes lvntrdev/laravel-starter-kit#1.

## [13.3.2] - 2026-04-19

### Security

- **Privilege escalation via unvalidated role assignment — admin user flow.** Shipped `StoreUserRequest` and `UpdateUserRequest` stubs validated the `role` field with `Rule::exists('roles', 'name')` only, so any user holding `users.create` or `users.update` could submit `role=system_admin` via a raw HTTP request regardless of the dropdown options in the UI — instantly granting themselves super-admin (which bypasses every authorization check via `Gate::before`). `UpdateUserRequest` additionally had no rank check on the target user, so a lower-ranked actor could edit or demote a higher-ranked one. Fix: `role` is now validated with `Rule::in(...)` built from `RoleSelectOptionsQuery` (the hierarchy-aware list that feeds the dropdown). `UpdateUserRequest::authorize()` now rejects attempts to edit a target whose top-ranked role outranks the actor's. A user holding `users.*` as a direct Spatie permission without any assigned role is treated as the lowest possible rank — they can no longer assign any role or edit anyone other than themselves; the previous `(int) null = 0` fallback accidentally opened the full role list including `system_admin`.

- **Settings secrets no longer leak to the frontend.** The shipped **Settings** page was sending `mail.password`, `storage.spaces_secret`, `storage.aws_secret` and `turnstile.secret_key` in plain text as Inertia props for any user with `settings.read`. Even values that lived only in `.env` leaked out through the `config()` fallback. Fix: `SettingsDefaultsQuery` now returns `null` for every secret field and adds a parallel `*_is_set: bool` flag. `MailSettingsDTO`, `StorageSettingsDTO` and `TurnstileSettingsDTO` omit the secret key from `toArray()` when submitted blank so `SettingService::setGroup()` preserves the stored value. The shipped `MailTab.vue`, `StorageTab.vue` and `TurnstileTab.vue` render a `••••••••` placeholder when `*_is_set` is true and submit an empty string when the admin doesn't type a new value. A new `tests/Feature/Admin/Settings/SecretsDisclosureTest` stub asserts the Inertia payload never contains the raw secret string anywhere.

- **`storage.aws_secret` now stored encrypted at rest.** `stubs/config/settings.php` gained `storage.aws_secret` in its `sensitive_keys` list — it previously contained `mail.password`, `storage.spaces_secret` and `turnstile.secret_key` but not the AWS counterpart, so S3 secrets saved through the UI lived as plaintext in the `settings` table. `SettingService` encrypts every listed key with `Crypt::encryptString` on write and decrypts on read.

- **`CheckResourcePermission` middleware now fails closed in production.** The middleware used to allow the request through when a route-derived permission (e.g. `users.read` for `users.index`) was not seeded in the database — silently unprotecting any new route whose permission row was forgotten. The middleware now throws `AuthorizationException` (403) when running under `app()->environment('production')` and `Log::warning`s the unseeded permission in non-production environments. Dev ergonomics preserved, the production foot-gun is closed.

- **Test-mail endpoint no longer reflects raw exception details.** The shipped `SettingsController::testMail()` used to flash the SMTP exception message (host / username / TLS details) back to the browser. It now writes the exception class + message to `Log::error` and returns a generic "Failed to send test email. Check the server logs for details." message to the user — same success/failure signal without the information disclosure.

- **API auth — email verification and 2FA parity with the web flow.** The shipped `RegisterUserAction`, `LoginUserAction`, `AuthController` and `routes/api/public-api.php` stubs were reworked. The API previously issued an access token immediately on register and on any successful password login, bypassing the email-verification and 2FA checkpoints that the web flow enforces:
    - **`register`** — when Fortify's `emailVerification` feature is enabled, no token is issued. The action creates the user, fires `Illuminate\Auth\Events\Registered` so Fortify's notification pipeline sends the verification link, and returns `{ user, requires_verification: true }` with 201. Feature-off behaviour (token issued on register) is preserved.
    - **`login`** — returns a discriminated payload: `{ user, token }` on normal success, `{ requires_verification: true }` when the email is unverified with the feature on, or `{ requires_two_factor: true, challenge: "<uuid>" }` when the account has confirmed 2FA. The challenge id is cached for 5 minutes.
    - **`two-factor-challenge`** — new endpoint `POST /api/v1/auth/two-factor-challenge` (throttled `5/min`) plus a `TwoFactorChallengeAction` + `TwoFactorChallengeRequest` stub. Accepts `{ challenge, code }` for TOTP or `{ challenge, recovery_code }`. On success it issues `{ user, token }`. TOTP is verified through Fortify's `TwoFactorAuthenticationProvider`; recovery codes are matched with `hash_equals` and consumed via `replaceRecoveryCode`. Invalid / unknown / expired challenges return 401.

    **Breaking for API consumers** — clients that expected `{ user, token }` on every 2xx response from `register` / `login` must now branch on `data.requires_verification` and `data.requires_two_factor` flags, and complete the challenge at `/api/v1/auth/two-factor-challenge` before receiving a token when 2FA is confirmed on the account. Non-2FA, verified users keep seeing the old shape.

- **Settings `required` validation now matches the UI secret indicator.** `UpdateMailSettingsRequest` and `UpdateTurnstileSettingsRequest` previously only checked the DB row when deciding whether a secret was "already set"; if the value lived only in `.env`, the UI's `*_is_set` flag reported `true` (because `SettingsDefaultsQuery` falls back to `config()`) but a blank submit triggered a confusing `required` validation error. The `required` branch now mirrors the query — DB row OR config fallback — so env-backed installations no longer see the spurious error.

- **IDOR on admin avatar upload / delete (shipped stubs).** `POST /users/{user}/avatar` and `DELETE /users/{user}/avatar` resolved to no permission under `CheckResourcePermission` — the route actions `uploadAvatar` / `deleteAvatar` were not in the middleware's `ACTION_ABILITY_MAP`, and `UploadAvatarRequest::authorize()` returned `true` unconditionally. Any authenticated + email-verified user could overwrite or delete any other user's avatar, system admin included. Fix: the map gains `uploadAvatar => update` and `deleteAvatar => update`; `UploadAvatarRequest::authorize()` delegates to `UserPolicy::update` when a `{user}` route param is bound (profile self-upload is preserved); `UserController::deleteAvatar` calls `Gate::authorize('update', $user)` explicitly.

- **Rank-hierarchy guard on view / update / delete (admin + API).** `GET /users/{user}/data`, `GET /users/{user}/edit`, `DELETE /users/{user}` (admin), `PATCH /api/v1/users/{user}` and `DELETE /api/v1/users/{user}` previously relied solely on the `users.read` / `users.update` / `users.delete` permission. A lower-ranked admin holding the permission could still read or delete a higher-ranked user through these endpoints. Fix: `UserPolicy::view / update / delete` now run the same `canManage()` rank check (system_admin bypasses, role-less actors are treated as the lowest rank). Admin and API controllers call `Gate::authorize('view' / 'update' / 'delete', $user)` on every cross-user operation; admin + API `UpdateUserRequest::authorize()` both delegate to `UserPolicy::update` so the rank check is uniform.

- **`POST /api-routes/regenerate-docs` was reachable by any authenticated user.** The `regenerateDocs` action was not in the `ACTION_ABILITY_MAP`, so `CheckResourcePermission` passed the request through without a permission check. Fix: `regenerateDocs => update` added to the map; `api-routes.update` added to `config/permission-resources.php` so the seeder creates the permission row.

- **SVG uploads blocked on logo + FileManager (stored XSS).** Both the logo uploader and the FileManager default MIME list accepted `image/svg+xml` and stored files on the `public` disk; SVG can embed `<script>` / `onload` / foreignObject JavaScript and executes in the app origin when viewed through `/storage/...`. Fix: logo validation now pins `mimes:png,jpg,jpeg,webp` + `dimensions:max_width=4096,max_height=4096`. `UploadFileRequest` keeps a `BLOCKED_MIMES` list that is stripped from the effective MIME list on every upload regardless of stored settings. `UpdateFileManagerSettingsRequest` rejects those MIMEs at settings-save time (`Rule::notIn(...)` + MIME regex). The shipped UI pickers (`MimePickerField`, `FileManagerTab`, `GeneralTab` logo input) no longer list SVG. `SettingsDefaultsQuery::fileManager()` strips the blocked MIMEs from the stored list before returning the payload so older installs do not see SVG as a selected option.

- **Avatar rule tightened.** `UploadAvatarRequest::rules()` upgraded from `['required','image','max:2048']` to `required | image | mimes:jpg,jpeg,png,webp | max:2048 | dimensions:max_width=4096,max_height=4096` — blocks SVG and caps pixel dimensions against decompression bombs.

- **`media-library.disk_name` default changed from `public` to `local`.** Missing or mis-seeded configuration no longer places user-uploaded documents on a world-readable URL. FileManager already streams downloads via `DownloadFileAction`, so a private disk is sufficient.

- **`SESSION_ENCRYPT` + `SESSION_SECURE_COOKIE` default to `true`.** Deployments that forgot to set either env var would ship plaintext session payloads over an insecure cookie on HTTPS. Both defaults are now `true`; local dev is unaffected because `.env.example` already sets both.

- **Baseline CSP header on `SecurityHeaders` middleware.** Both the Lvntr-namespaced middleware (`src/`) and the shipped stub now emit a conservative `Content-Security-Policy` in non-local environments. Local dev is exempt because the Vite HMR dev-server origin varies per developer and would just block normal work.

- **Scramble "Try It" disabled in production.** `config/scramble.php` shipped with `hide_try_it: false` + `try_it_credentials_policy: 'include'`, handing any admin with `api-docs.read` an in-browser API tester that forwarded their session cookies on every request. Both values now branch on `APP_ENV === 'production'` (hidden + `omit` in prod).

- **Passport access-token TTL shortened, scope catalogue seeded.** `config/starter-kit.php` defaults changed from 15 days / 30 days / 6 months (access / refresh / personal) to 60 minutes / 14 days / 30 days. Legacy `PASSPORT_TOKEN_DAYS` / `PASSPORT_PERSONAL_TOKEN_MONTHS` env keys still take precedence when set, so existing installs with explicit env values are not disturbed. `StarterKitServiceProvider::configurePassport` accepts both the new `access_token_minutes` / `personal_token_days` keys and the legacy `_days` / `_months` keys. An opt-in scope catalogue (`users.read`, `users.write`, `files.read`, `files.write`, `admin`) is pre-wired via `Passport::tokensCan()` — attach `middleware('scope:...')` to specific API routes when you are ready to enforce.

- **API register / login now honour the `turnstile` middleware.** The shipped `routes/api/public-api.php` stub attaches `turnstile` middleware to `POST /api/v1/auth/register` and `POST /api/v1/auth/login`. When Turnstile is disabled in settings the middleware is a no-op; when enabled it picks up the same `cf_turnstile_response` enforcement as the web forms, so automated account creation is capped.

### Fixed

- **User domain events now dispatch on Create/Update/Delete.** Shipped `CreateUserAction`, `UpdateUserAction` and `DeleteUserAction` stubs previously had their `UserCreated::dispatch(...)` / `UserUpdated::dispatch(...)` calls commented out or missing — any listener registered in `DomainServiceProvider` (e.g. the audit-log listener) never ran for user writes. `Create` and `Update` now dispatch only when at least one tracked field changes; `Delete` captures id/email before deletion and dispatches `UserDeleted` on success, matching the `Role*` action pattern.

- **Admin `users.show` route returned 500.** The shipped `routes/web/user-route.php` used `Route::resource('users', UserController::class)`, implicitly opening `GET /users/{user}` — but `UserController` never had a `show()` method, so every hit threw `BadMethodCallException`. Resource registration is now scoped with `->except(['show'])`. Detail data remains available via the existing `GET /users/{user}/data` endpoint consumed by the admin UI.

- **`SettingsController` logo endpoints now return the `ApiResponse` envelope.** `POST /settings/logo` and `DELETE /settings/logo` used raw `response()->json([...])` / `response()->json(status: 204)`, breaking the "every JSON response carries `{ success, status, message, data }`" contract. Both endpoints now go through `to_api(...)`. Frontend consumer shape (`json.data.logo_url`) is preserved.

- **`UserPolicy` gained a `delete` ability.** `DELETE /media/{media}` calls `Gate::authorize('delete', $media->model)`. For media owned by a `User`, `UserPolicy` had no `delete` method (only `view` and `update`), so the Gate fell through to the default deny and returned 403 — even for the owner deleting their own avatar. The new `delete(User $actor, User $user)` mirrors `update`: self is always allowed, otherwise the actor needs the `users.delete` permission.

- **`CheckResourcePermission` middleware: process-wide `static` cache replaced with a request-scoped container binding.** The permission-existence lookup used to memoise its result in `static $cached`. Under long-lived workers (Octane, queue workers keeping the container warm), newly-seeded permissions were invisible until the worker restarted. Inside the test suite the static survived across tests despite `RefreshDatabase`, producing intermittent 403s. The cache now lives in `app()->instance('check-permission.cache', ...)` — request-scoped in production, test-scoped under the testing container.

- **`UserFactory` seeds `two_factor_*` columns as `null` by default.** Eloquent strict mode (enabled in non-prod by `Lvntr\StarterKit\StarterKitServiceProvider::shouldBeStrict`) throws "attribute [two_factor_secret] either does not exist or was not retrieved" when code reads those columns on a fresh factory instance. The factory now writes `two_factor_secret`, `two_factor_recovery_codes` and `two_factor_confirmed_at` as explicit `null`s so consumer tests relying on `User::factory()->create()` don't need a `->refresh()` before hitting Fortify-aware code.

- **`CreateUserAction` + `UpdateUserAction` now wrap the write + role sync in `DB::transaction`.** A `syncRoles` failure after `User::create` previously left a user row with no roles. Events dispatch post-commit so listeners observe consistent state.

- **`MoveItemAction::wouldCreateCycle` collapsed from N queries to 1.** The ancestor walk used to issue a `FileFolder::find` per hop. The ancestor map is now loaded once per call; the walk happens in memory with a visited-guard against cycles in corrupt data.

- **Folder create / rename / move now catch unique-constraint violations.** Concurrent requests could pass the `->exists()` check in lockstep; the second write surfaced a raw `QueryException`. `CreateFolderAction`, `RenameFolderAction` and `MoveItemAction` now catch SQLSTATE `23000` / MySQL `1062` and rethrow a localised `LogicException` — the controllers already translate that to a 422.

- **`UserDatatableQuery` eager-loads `media`.** `UserResource::$appends` forces the `avatar_url` accessor (calls `getFirstMedia('avatar')`). Without `media` in the eager load, each row triggered a separate media lookup (N+1). Datatable render drops from `1 + n` queries to `2`.

- **`RoleController@data` and `@edit` use the new `RoleResource` instead of spreading `$role->toArray()`.** The old spread would silently broadcast any future sensitive column added to the `roles` table. The new resource lists the intended fields explicitly; frontend payload shape preserved.

- **`resources/js/pages/Admin/ApiRoutes/Index.vue`: `rel="noopener noreferrer"` added to the external `target="_blank"` link.** Consistent with the rest of the project.

- **Missing 2FA-disable confirmation dialog translations.** `sk-setting.auth.two_factor_disable_title` and `sk-setting.auth.two_factor_disable_warning` were referenced from the Auth settings tab but not defined in either language file. Added for EN and TR.

### Added

- **Passport key auto-generation for the API test suite.** The shipped `tests/Pest.php` registers a `beforeEach` hook scoped to `tests/Feature/Api` that runs `passport:keys --force` when `storage/oauth-private.key` is missing. Fresh clones and CI runs no longer need `php artisan site:install` before Passport-backed tests can pass.

- **`App\Http\Resources\Admin\Role\RoleResource`.** Canonical response shape for the role dialog / edit screen; replaces ad-hoc `$role->toArray()` spreads. Automatically picked up by the shipped `RoleController` stubs.

### Compatibility

- The **Fixed** changes are additive or behaviour-preserving in the happy path; consumers who publish the affected stubs should re-run `php artisan sk:update` (or copy the new versions) to pick up the user-event dispatch and the policy additions. Hash-aware merge will skip any of these files you have modified — review the update summary and resolve manually.

- The **Security** changes are behaviour-changing and should not be skipped. Re-run `php artisan sk:update` and make sure the following files land (or merge them manually):

    - `app/Http/Requests/Admin/User/{Store,Update}UserRequest.php` — new hierarchy-aware `role` validation and the `UpdateUserRequest::authorize()` rank check.
    - `app/Domain/Setting/Queries/SettingsDefaultsQuery.php` — secret redaction + `*_is_set` flags.
    - `app/Domain/Setting/DTOs/{Mail,Storage,Turnstile}SettingsDTO.php` — blank-preserves-stored-value semantics.
    - `app/Http/Requests/Admin/Settings/Update{Mail,Turnstile}SettingsRequest.php` — config-aware `hasEffectiveSecret()` check.
    - `app/Http/Middleware/CheckResourcePermission.php` — fail-closed in production.
    - `app/Http/Controllers/Admin/SettingsController.php` — generic test-mail error message.
    - `app/Domain/Auth/Actions/{Register,Login,TwoFactorChallenge}UserAction.php`, `app/Http/Controllers/Api/Auth/AuthController.php`, `app/Http/Requests/Api/Auth/TwoFactorChallengeRequest.php`, `routes/api/public-api.php` — API verification + 2FA parity.
    - `config/settings.php` — `storage.aws_secret` added to `sensitive_keys`.
    - Shipped Vue: `resources/js/pages/Admin/Settings/components/{MailTab,StorageTab,TurnstileTab}.vue` + `resources/js/pages/Admin/Settings/Index.vue` prop types.

- If any `storage.aws_secret` rows already exist in your `settings` table (saved through the UI before this release), they are still plaintext — rotate the AWS secret through the admin panel (or re-encrypt via a one-off tinker snippet) so the at-rest value becomes encrypted.

- **API consumers must update** to handle `data.requires_verification` and `data.requires_two_factor` flags on the login response and to call `POST /api/v1/auth/two-factor-challenge` when 2FA is confirmed on the account. See the **Security → API auth** bullet above for the full payload shapes.

## [13.3.0] - 2026-04-18

### Added

- **Cloudflare Turnstile captcha** — login, register and password-reset flows can now be protected by Turnstile. Ships with a `turnstile` middleware alias (`ValidateTurnstile`), a `TurnstileRule` for FormRequest validation, `TurnstileSettingsDTO`, a `TurnstileWidget.vue` (mounted by the auth pages), and a **Settings → Turnstile** admin tab. Site key / secret key are managed from the UI.

- **Last login tracking** — `UpdateLastLogin` listener on the `Illuminate\Auth\Events\Login` event writes `last_login_at` and `last_login_ip` to the user. Surfaced on user detail pages and in the users datatable.

- **Inactive user block on login** — `FortifyServiceProvider` now rejects the login attempt when the user's status is not `active`, returning a clear error instead of starting a session. Admins can suspend accounts without deleting them.

- **`FormBuilder.trans(bool)`** — new fluent method on every field builder that controls whether the label is treated as a translation key (default `true`) or as a pre-resolved raw string (`.trans(false)`). Use `.trans(false)` when supplying `trans('admin.example')` or any already-translated value; the form template then renders it verbatim instead of running `$t()` on it again. Default behaviour unchanged — existing code is not affected.

- **`FilePreviewModal` + `ImageLightbox`** — file previews in both the file manager and form file-upload fields now open in-app instead of a new browser tab. Images render inside a Google-Drive-style fullscreen overlay (`ImageLightbox` — backdrop blur, ESC to close). PDF, video, audio and text files render inside a mime-aware dialog (`FilePreviewModal`) with a built-in "Open in new tab" escape hatch for unsupported formats. Register the global overlay by adding `<ImageLightbox />` next to `<AppDialog />` in your admin layout.

- **`MimePickerField`** — replaces the accepted-mime-types multiselect dropdown in **Settings → File Manager** with a categorized card-checkbox grid (Images / Documents / Archive), each option showing its file-type icon. Easier to scan than the dropdown list.

- **`ToggleFeatureCard`** — new UI primitive for boolean feature flags. Shows a coloured icon, a bold label and a helper description next to a toggle switch, styled to match the `MimePickerField` cards. Used by the "Video uploads" and "Audio uploads" toggles in the file-manager settings.

- **`lang/{en,tr}/validation.php`** — Laravel's default validation rule messages are now shipped with the kit, including the `attributes` and `custom` sections used by both the Laravel validator and by FormBuilder / DatatableBuilder (they auto-resolve a field's label via `validation.attributes.{key}` when `.label()` is not given). Turkish rule messages follow the Laravel-Lang/lang conventions.

- **Role name localisation fallback chain** — the role label shared with Inertia via `auth.role` (shown in the admin topbar / sidebar) now resolves in three steps: (1) `roles.display_name[locale]` from the database; (2) `config('permission-resources.display_names.roles.{name}.{locale}')`; (3) `Str::headline($role->name)`. A freshly seeded role like `system_admin` renders as "System Admin" instead of the raw slug even when no localised value is configured.

### Changed

- **Shipped translations now carry an `sk-*` filename prefix** — every `stubs/lang/{locale}/*.php` has a `sk-` counterpart (`sk-admin.php`, `sk-auth.php`, `sk-button.php`, `sk-datatable.php`, `sk-menu.php`, `sk-setting.php`, `sk-user.php`, …). All shipped Vue pages and PHP code now reference the new keys (`__('sk-button.save')` instead of `__('button.save')`), so consumer apps can freely own the unprefixed namespace.

- **FileManager actions** — consistent response envelopes and captcha-aware request validation.

- **`SettingsDefaultsQuery`** — now returns Turnstile defaults alongside existing sections.

- **File-upload field preview UX** — in `SkFormInput` the existing-media thumbnails and newly-selected file previews no longer open in a new tab. Click now routes to the lightbox (images) or the preview modal (everything else). The file-name text next to each thumbnail became a `<button>` instead of an `<a>`; styling was updated to keep the link-like appearance.

### Fixed

- **Upload validation rejected `.ogg` video and `.avi` files** — `UploadFileRequest`'s `allow_video=true` branch only whitelisted `video/mp4`, `video/webm`, `video/quicktime` and `video/x-matroska`. Added `video/ogg`, `video/x-msvideo` and `video/avi`, plus the matching extension labels (`.ogv`, `.avi`) shown in the error message's "Allowed types" list.

- **`npm run build` noise cleanup** — two spurious warnings have been scrubbed from production builds: (1) the "Sourcemap is likely to be incorrect" notices emitted by `@tailwindcss/vite` and `@inertiajs/vite` (both plugins skip sourcemap regeneration after their transform; runtime output is unaffected) are now filtered via a targeted Rollup `onwarn` hook in the shipped `stubs/vite.config.ts` — other warnings still pass through; (2) the `resolveDirective imported but never used` warning emitted for the shipped `SkDatatable.vue` and `FileManager.vue` — PrimeVue's `v-tooltip` / `v-ripple` directives are now bound explicitly in the `<script setup>` block (`const vTooltip = Tooltip`) so templates compile to a direct reference instead of a dynamic lookup.

### Removed

- **Legacy unprefixed translation stubs** — `stubs/lang/{en,tr}/{admin,auth,button,common,datatable,enums,file-manager,message,pagination,passwords,validation}.php` (21 files) are no longer shipped. The application-side code (Vue pages, FormRequests) has fully moved to the `sk-*` keys — the new `lang/{en,tr}/validation.php` above is the native Laravel replacement, not an unprefixed stub — so these files were orphans in fresh installs. The legacy **package-level `starter-kit::` namespace is untouched** — `resources/lang/` inside the package still loads the original files, so `__('starter-kit::admin.menu')` calls keep resolving.

### Compatibility

- The **legacy `starter-kit::` translation namespace keeps working.** `__('starter-kit::admin.menu')` and any `lang/vendor/starter-kit/` publishes continue to resolve.

- **If you are upgrading from 13.2.x, manual steps are required.** `sk:update` uses hash-aware merging: files you have not modified are overwritten with the new version; files you have modified are skipped with a warning. Several 13.3 feature files (`SettingsController`, `SettingsDefaultsQuery`, `FortifyServiceProvider`, `HandleInertiaRequests`, `AppServiceProvider`, and the new FormRequests) may be reported as `skipped` or `untracked` in the update summary. Review each and copy the package version over, or run:

  ```bash
  php artisan sk:update --force
  ```

  to accept the package version for every file at once. Use `--force` only if you have not customised your app/ layer.

- **Lang files are never overwritten by `sk:update`** (lang paths are not in `SAFE_UPDATE_PATHS`). Pull the new `sk-*.php` files manually:

  ```bash
  cp vendor/lvntr/laravel-starter-kit/stubs/lang/en/sk-*.php lang/en/
  cp vendor/lvntr/laravel-starter-kit/stubs/lang/tr/sk-*.php lang/tr/
  ```

  If your `lang/en/` contains `admin.php`, `auth.php`, … from a prior `sk:install` (they were stubs in 13.2.x), they will remain as orphans. The package no longer ships or references them; safe to delete once you have migrated your own `__('admin.x')` calls to `__('sk-admin.x')`.

- **New Vue component location.** `resources/js/components/Auth/TurnstileWidget.vue` is shipped as a stub; it is imported by `Login.vue`, `Register.vue` and `ForgotPassword.vue`. Fresh installs get it automatically; existing installs missing it will fail `npm run build` — copy it from `vendor/lvntr/laravel-starter-kit/stubs/resources/js/components/Auth/TurnstileWidget.vue`.

---

## [13.2.9] - 2026-04-16

### Fixed

- **`npm run build` no longer emits the lang JSON dual-import warning** — `resources/js/app.ts` (shipped via `stubs/`) held two `import.meta.glob('../../lang/*.json', ...)` calls — one `eager: true` for SSR and one dynamic for client — both targeting the same files. Vite analysed both branches statically and warned that the dynamic branch would not move modules into separate chunks because the static branch already pulled them into the bundle. Collapsed to a single eager glob hoisted to module scope, with a `Promise.resolve()` wrapper for the client branch. Behaviour and bundle size unchanged; the two `lang/php_*.json dynamically imported but also statically imported` warnings are gone.

---

## [13.2.8] - 2026-04-16

### Removed

- **`stubs/.claude/`** — 68 files (~736K) of AI tooling stubs (developer-side agents, skills, settings) were sitting in the package and being shipped to consumer projects by `sk:install` despite serving no end-user purpose. Used by neither `sk:sync` nor `sk:publish` — orphan manual copy from an earlier iteration.
- **`stubs/.cz-config.cjs`** — developer-specific commit prompt configuration (Turkish prompts, custom commit types) deleted entirely. Consumers' commit conventions are their own.

### Fixed

- **`stubs/.env.example` no longer leaks the maintainer's database name** — old `env:sync` output had stuck to the bottom of the file, writing `DB_*` variables a second time and leaking `DB_DATABASE=starter_kit_12` (a former development project name) into freshly installed consumer apps. Trimmed back to clean Laravel defaults plus starter-kit-specific keys with generic placeholders.
- **`stubs/package.json` no longer ships a half-finished husky scaffold** — `prepare: "husky"` ran on the consumer's `npm install` and looked for `.husky/`, but `stubs/.husky/` and `stubs/commitlint.config.mjs` were never shipped, leaving consumers with a broken hook setup. Removed the `commit` / `prepare` scripts, `commitizen` / `cz-customizable` config, `lint-staged` block, and 6 commit/lint dev dependencies (`commitizen`, `cz-customizable`, `husky`, `lint-staged`, `@commitlint/cli`, `@commitlint/config-conventional`). The consumer's commit/lint strategy is their call.

---

## [13.2.7] - 2026-04-15

### Fixed

- **File manager upload on HTTP contexts** — `useFileManager` generated pending-upload ids via `crypto.randomUUID()`, which is only defined in secure contexts (HTTPS or `localhost`). On plain-HTTP dev domains (Herd's `.test`, bare intranet IPs, etc.) the call threw `TypeError: crypto.randomUUID is not a function` and the upload aborted before the first XHR. Replaced with a three-tier fallback: `crypto.randomUUID()` → `crypto.getRandomValues()` hex → `Date.now()` + `Math.random()`. The tempId is UI-only (pending-upload correlation), so cryptographic strength is not required.

### Changed

- **`Permissions-Policy` header** — `SecurityHeaders` middleware now emits `geolocation=(self)` instead of `geolocation=()`, allowing first-party scripts to request geolocation when legitimately needed while still blocking third-party frames.

---

## [13.2.6] - 2026-04-15

### Added

- **Two new global helpers** — `definition($key, $value)` returns the matching record (object) from `DefinitionService`; `definitionLabel($key, $value)` returns its `label`. Useful for resolving enum-style values to display strings without re-fetching the definition list per call. Both ship from `vendor/lvntr/laravel-starter-kit/src/sk-helpers.php` and are autoloaded automatically.
- **`sk:publish --tag=helpers`** — publishes the package's `sk-helpers.php` into `app/Helpers/sk-helpers.php` so consumers can override or extend the bundled helpers without forking. The vendor file detects the published copy at autoload time and routes through it via `require_once`; a realpath guard prevents self-recursion. No `composer.json` change is needed. Deleting the published file reverts to the vendor implementation immediately.
- **Friendly file manager validation messages** — `UploadFileRequest` now overrides `attributes()` and `messages()`. Each `files.{i}` slot is bound to the file's `getClientOriginalName()`, so toasts show `vacation.jpg yüklenemedi: …` instead of `files.0`. Mimetypes / max-size errors map to translation keys with a readable extension list (`İzinli tipler: WEBP, PDF, JPG, …`) and human-friendly size limit (`en fazla 10 MB`). New keys: `errors.upload_invalid_type`, `errors.upload_too_large`, `errors.upload_invalid_file`.

### Changed

- **Helpers reorganized** — `to_api()` and `format_date()` (plus the two new helpers) now ship from the package vendor. End-user apps no longer keep a `to_api` copy under `app/`. The new `app/Helpers/custom.php` is published into the consumer app on first install and added to the app's `composer.json` `autoload.files`; it is *never* overwritten by `sk:update` so user code is preserved across upgrades.
- **`app/helpers.php` deprecated** — `sk:update` compares the existing file's md5 against a list of known stock hashes; a stock copy is removed silently. A user-modified copy is left in place with a console warning so user code is not destroyed. The `composer.json` autoload entry is rewritten only when the file is actually gone.
- **`InstallCommand` injects helpers autoload entry** — fresh installs now have `app/Helpers/custom.php` registered in `composer.json` `autoload.files` automatically. Idempotent: re-running `sk:install` is a no-op once injected. Legacy `app/helpers.php` entries are rewritten to `app/Helpers/custom.php` in the same step.

### Fixed

- **File manager toasts now actually surface** — every `toast.add()` call in `FileManager.vue` was missing `group: 'bc'`, so the shared `ToastComponent` (mounted with `group="bc"`) silently dropped them. Folder create/rename/delete/move and file upload toasts (success and error) all show again.
- **Server-side validation errors reach the user** — the upload XHR previously read only `envelope.message` (the generic "Validation error.") on a 422. The composable now walks `envelope.errors` and surfaces the first field-specific message, so the toast carries the actual reason (mime/size/etc).

---

## [13.2.4] - 2026-04-15

### Fixed

- **Type-safety sweep** — source now passes `vue-tsc --noEmit` and `eslint 'resources/js/**/*.{ts,vue}'` with zero errors and zero warnings.
    - `SkDatatable.vue` `activeFilters` widened to a single `FilterValue` union (`string | number | Date | (Date | null)[] | null`); DatePicker filters migrated from `v-model` to `:model-value` + `@update:model-value` with narrow casts.
    - `:icon` expression coerces trailing null to `undefined`; `datatable.records_info` pagination params are passed through `String(... ?? 0)` to match i18n string arguments.
    - `SelectOption` cast in `SkFormInput.vue` routed through `unknown`.
    - `router.reload({ preserveScroll: true })` calls reduced to `router.reload()` (Inertia v3 preserves scroll/state on reload by default).
- **Typed shared props aligned with runtime shape** — `SharedPageProps` gained a `[key: string]: unknown` index signature so it satisfies Inertia's `PageProps` constraint; `env.d.ts` now declares `sharedPageProps.auth` as `{ user, role, role_names, permissions }` plus `appEnv / appDebug / locale / availableLocales`.
- **Page-level prop/type fixes** — `Dashboard/Index.vue` reads `user?.first_name` (real field) instead of a non-existent `user?.name`; `Settings/Index.vue` declares `logo_url: string | null` on the `general` shape; `RoleForm.vue` calls Wayfinder as `update.url({ id: props.role!.id! })`.
- **ESLint warnings cleared** — `Breadcrumb.rootLabel`, `FileGrid.emptyLabel` and `SkTag.{value, icon, color, severity}` have `withDefaults` fallbacks; `SkDatatable` `v-html` usage is marked with a reasoned `eslint-disable-next-line` (render string is author-defined, `escapeHtml` helper is exposed).

### Changed

- **tsconfig deduplication** — `tsconfig.json` excludes `packages/**` and adds a new `"@lvntr/components/*"` path that resolves first to `resources/js/components/Lvntr-Starter-Kit/*` with a fallback to the package copy; the previous dual-include produced duplicate type-check errors for every synced component.
- **Vite `Components` plugin is single-source** — the `dirs` entry was trimmed to `resources/js/components` only; the package path is gone. The auto-generated `components.d.ts` now references source paths.
