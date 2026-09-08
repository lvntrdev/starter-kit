# Artisan Commands

This document is the command reference for the starter kit. Architectural notes for DDD live separately in [ddd.md](./ddd.md).

## End-User Commands

| Command                                   | Purpose                                                      |
| ----------------------------------------- | ------------------------------------------------------------ |
| `php artisan sk:doctor`                   | Run environment health checks and report any issues          |
| `php artisan sk:install`                  | Install the starter kit into the project                     |
| `php artisan sk:update`                   | Update installed kit files safely                            |
| `php artisan sk:upgrade`                  | Upgrade an older starter-kit/Laravel line to the current one |
| `php artisan sk:publish`                  | Publish optional components, language files, or config       |
| `php artisan sk:eject`                    | Eject a vendor-resident domain into the app for full customization |
| `php artisan make:sk-domain`              | Generate a new domain scaffold                               |
| `php artisan remove:sk-domain`            | Remove a generated domain                                    |
| `php artisan env:sync`                    | Sync `.env` keys into `.env.example`                         |
| `php artisan env:sync --reverse`          | Check whether `.env` is missing keys from `.env.example`     |
| `php artisan site:install`                | Reset and reinstall site data for local/dev usage            |
| `php artisan sk:seed-permissions --fresh` | Rebuild role and permission data from config                 |
| `php artisan postman:sync`                | Push the api-dock OpenAPI document to Postman                |
| `php artisan apidog:sync`                 | Push the api-dock OpenAPI document to Apidog                 |
| `php artisan api-dock:agent-guide`        | Install the API Dock authoring rules into agent instruction files |
| `php artisan api-dock:sync`               | Regenerate, compare, and store the API Dock OpenAPI snapshot |
| `php artisan api-dock:diff`               | Compare the generated OpenAPI document with the stored snapshot |
| `php artisan api-dock:export`             | Export API Dock artifacts for AI tools and OpenAPI consumers |
| `php artisan sk:redact-activity-secrets`  | Irreversibly remove credentials from existing activity logs  |
| `php artisan file-manager:purge-trash`    | Permanently delete old File Manager trash                    |
| `php artisan encryption:key`              | Generate a dedicated `DATA_ENCRYPTION_KEY`, preserving the old key |
| `php artisan encryption:rekey`            | Re-encrypt settings and 2FA secrets onto the primary data-encryption key |
| `php artisan encryption:health`           | Report which key each encrypted value needs (read-only)      |

## `sk:doctor`

Runs a series of environment health checks and reports the result of each.

```bash
php artisan sk:doctor
php artisan sk:doctor --json
php artisan sk:doctor --only=database-connection,redis-connection
php artisan sk:doctor --only=timezone-storage
```

- `--json` outputs machine-readable JSON instead of a table
- `--only=<selectors>` runs a comma-separated subset of checks. A selector is the check's stable id — its class name without the `Check` suffix, hyphenated and lowercased (`DatabaseConnectionCheck` → `database-connection`) — so it does NOT change with the active locale even though the displayed check name is now translated. The three selectors in the table that differ from their class name (`filemanager-disk`, `permission-matrix`, `unresolved-routes`) keep working as aliases

Checks (name → `--only` selector):

| Check                 | `--only` selector       |
| ---------------------- | ------------------------ |
| PHP Extensions         | `php-extensions`         |
| Node Version           | `node-version`           |
| Database Connection    | `database-connection`    |
| Redis Connection       | `redis-connection`       |
| Passport Keys          | `passport-keys`          |
| Storage Symlink        | `storage-symlink`        |
| Writable Directories   | `writable-directories`   |
| Log Channel            | `log-channel`            |
| Log Stack              | `log-stack`              |
| Queue Driver           | `queue-driver`           |
| Queue Worker           | `queue-worker`           |
| Schedule Configured    | `schedule-configured`    |
| Mail Driver            | `mail-driver`            |
| NPM Build Artifacts    | `npm-build-artifacts`    |
| Config Cache           | `config-cache`           |
| FileManager Disk       | `filemanager-disk`       |
| Theme Manifest         | `theme-manifest`         |
| Timezone Storage       | `timezone-storage`       |
| Activity Log Secrets   | `activity-log-secrets`   |
| Permission Matrix      | `permission-matrix`      |
| Unresolved Routes      | `unresolved-routes`      |
| Data Encryption Key    | `data-encryption-key`    |

`ActivityLogSecretsCheck` returns FAIL when an `activity_log` row still contains a password hash, token, or secret. That happens when the package was updated (which closes the leak for new rows immediately) without running `php artisan migrate`, so the historical rows were never cleaned. Back up the database and run `php artisan migrate` or `php artisan sk:redact-activity-secrets`; removal is irreversible. A missing activity-log table or a table without JSON payload columns is OK, an undecodable JSON payload is WARN, and a database error is WARN rather than a pass.

The check is a **bounded, read-only probe**, not the full cleanup pass. It reads the first 500 rows ordered by primary key — the same fixed cost on MySQL, MariaDB, SQLite, and PostgreSQL alike — and decides in PHP rather than in SQL, so a differently-cased key such as `Password` is caught regardless of the JSON column's collation. The messages state exactly what was measured: over a larger table a finding is reported as a floor ("at least N"), and a clean result names the window it covered instead of clearing the whole table. Run `php artisan sk:redact-activity-secrets --dry-run --all` for the exhaustive count — `--all` is required, because without it the command uses a SQL key-name prefilter on MySQL, MariaDB and SQLite that a differently-cased key can slip past.

`PermissionResourcesDriftCheck` returns WARN when `config/permission-resources.php` no longer covers every resource and ability the package ships — for example an installation that predates the FileManager `files.create` / `files.update` / `files.delete` split and still declares the old set. That file is in the updater's never-touch list, deliberately, because it is where you declare your OWN resources; the price is that new package entries never arrive on their own, and the symptom is a 403 on a screen that used to work. The check is one-directional: resources you added yourself are never reported. Fix by adding the listed entries to `config/permission-resources.php` and running `php artisan sk:seed-permissions`. A missing or empty config is WARN, and an unreadable package copy is WARN rather than a pass.

`TimezoneStorageCheck` returns FAIL when `config('app.timezone')` is not exactly `UTC`. When that setting is correct, it also reads `SELECT @@session.time_zone` from the default connection. For a MySQL/MariaDB connection, only `+00:00` and `UTC` pass; `SYSTEM` and every other value FAIL because `TIMESTAMP` rows can be offset on disk even while the application reads them back consistently. A query failure or missing result returns WARN, never a pass. Other database drivers report OK with the session check marked inapplicable. Keep display configuration separate through `APP_DISPLAY_TIMEZONE`, and see [Timezones](timezone.md) for the connection contract and existing-data conversion guide.

`UnresolvedRouteCheck` returns FAIL for every route that carries the kit's `check.permission` middleware without any permission being derivable from it — no `<resource>.<action>` name the ability map recognises, no explicit `check.permission:<perm>` argument, and no entry in the exempt list. Such a route reaches its controller **unauthorized**. By default the middleware lets it through and logs a throttled warning, and **no release changes that for an existing install**. Setting `STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false` (config `starter-kit.permissions.allow_unresolved`) turns every listed route into a 403 — that is the opt-in, and a newly installed project already ships with it. The check reports FAIL regardless of that setting, deliberately — its job is to show what the flip will deny, not what the current configuration allows. Routes the package ships resolve on their own through a route-name map inside `CheckResourcePermission`, so what this check lists is your own routes plus any kit route you renamed. Fix each one by renaming it to a mapped `<resource>.<action>`, gating it with an explicit permission argument, or — when it is deliberately permission-free — declaring it under `starter-kit.permissions.unrestricted_routes`. See [UPGRADE.md](UPGRADE.md) for the ordered path.

`DataEncryptionKeyCheck` reads config only — no table scan, no decryption — and never returns FAIL. No dedicated key configured (`DATA_ENCRYPTION_KEY` empty) is WARN: sensitive settings and 2FA secrets are still encrypted with `APP_KEY`, and a `php artisan key:generate` on a server migration will make them unreadable. A dedicated key with a non-empty `DATA_ENCRYPTION_PREVIOUS_KEYS` is WARN: rotation is unfinished. A dedicated key with an empty previous-key list is OK. See [Data Encryption](encryption.md) and `encryption:key` / `encryption:rekey` / `encryption:health` below.

Exit codes:

| Code | Meaning                          |
| ---- | -------------------------------- |
| `0`  | All checks passed                |
| `1`  | At least one check returned WARN |
| `2`  | At least one check returned FAIL |

## `sk:install`

Use this on first setup.

```bash
php artisan sk:install
php artisan sk:install --force
php artisan sk:install --adopt
php artisan sk:install --adopt --dry-run
php artisan sk:install --no-interaction
php artisan sk:install --without-ai-skill
php artisan sk:install --without-eject
php artisan sk:install --resume
php artisan sk:install --modules=telescope,pulse
```

- `--force` overwrites existing publishable files, **and** bypasses the already-installed safety stop described below. It is the opt-out for every preservation rule: a consumer-edited published file and a file the hash registry has no record of are both overwritten
- `--adopt` rebuilds `storage/starter-kit/hashes.json` from the shipped stubs for an app that is already installed but lost its registry (a stateless deploy, a cleared `storage/`). It copies no file, runs no migration and never touches `.env`; combine with `--dry-run` to preview the registry it would write
- `--dry-run` prints what would be written and exits without writing anything
- `--no-interaction` accepts all defaults automatically; useful for CI or scripted installs
- `--without-ai-skill` skips publishing the Lvntr Starter Kit AI skills entirely — both the Claude Code copies (`.claude/skills/`) and their Codex mirror (`.codex/skills/`). Useful when the consumer uses neither Claude Code nor Codex with the kit's skill bundle
- `--without-eject` skips the default `User` and `Role` domain eject on a first install; the runtime stays in vendor and resolves via `class_alias`. Omit this flag to have `app/Domain/User/` and `app/Domain/Role/` created automatically. See [install.md](./install.md) for the ownership trade-off.
- `--resume` resumes a previously interrupted install, skipping steps already checkpointed as completed. See [install.md](./install.md) for the full resume workflow.
- `--modules=` selects optional observability recipes to `composer require` and wire up (`telescope`, `pulse`, `horizon`, `sentry`; comma-separated or repeatable). Left empty, you are prompted interactively in a TTY and skipped entirely under `--no-interaction` or a non-interactive session. An unrecognized key fails fast before anything is written. See [Optional Observability Recipes](./install.md#optional-observability-recipes) in install.md for the full recipe table and behavior.

**It is a first-install command, not a repair tool.** Before the banner is printed, a fail-closed detection pass looks for kit schema tables and install-only paths; if it finds any without a matching hash registry, the command **stops before writing a single byte** and points at `sk:update`, `sk:publish --tag=<area>` or `--adopt`. An existing `.env` is never overwritten, on a first install or a re-run: missing `.env.example` keys are appended and first-install-only keys are seeded only where absent, and no existing value is ever rewritten.

**Exit codes.** A failed **mandatory** step (publish, migrations, seeders, permission seeding, Passport keys, encryption keys) aborts the run, leaves the checkpoint pending for `--resume`, skips the hash-registry write and exits non-zero. Frontend steps (`npm install`, Wayfinder generation, `npm run build`, `composer dump-autoload`, cache clears) stay non-fatal on purpose — they warn, print the command to run by hand, and are listed again in the closing summary.

**The migration step asks how to proceed** when the database already holds tables. The default (and the only option a non-interactive session ever gets) is `Run pending migrations only`; `Skip migrations` is always offered. The destructive `Drop all tables and run fresh migrations` entry is **withheld entirely** when `APP_ENV` looks production-like, `APP_DEBUG` is off, the session cannot prompt, or any existing table already holds rows — and when it is offered, choosing it requires typing the database name (or the word `fresh`) at a text prompt. Anything else, an empty answer included, falls back to the additive `migrate` path with nothing dropped.

The config phase idempotently adds `'timezone' => '+00:00'` to existing `mysql` and `mariadb` arrays in `config/database.php`. It preserves an existing value, skips a missing connection, and does not touch other drivers. On a re-run against a database that already holds data on a non-UTC session, the step is skipped and points at `sk:upgrade` instead. See [install.md](./install.md) and [Timezones](timezone.md).

## `sk:update`

Use this after `composer update`.

```bash
php artisan sk:update
php artisan sk:update --dry-run
php artisan sk:update --force
php artisan sk:update --without-ai-skill
```

- `--without-ai-skill` skips regenerating the `.codex/skills/` AI-skill mirror for this run. (An install-time `--without-ai-skill` opt-out is honored automatically — skipped skills are never re-added.)

**Your edits survive, including in package-owned files.** Every copied file is compared against the hash recorded for it at install/update time, and a file whose content no longer matches that record is preserved and listed under "Skipped". This now covers `app/Enums/PermissionEnum.php` too: it is package-owned and refreshed on every update, but it is also a backed enum with public `for()` / `allFor()` helpers, so a project ability added to it (`case Approve = 'approve';`) is preserved instead of silently overwritten. A preserved copy is reported separately, because the package does expect its own cases to exist — diff your file against the same relative path under `vendor/lvntr/laravel-starter-kit/stubs/`, merge the new cases, or re-run with `--force` to take the package version and discard your edits. A copy with no hash record (an installation predating hash tracking) is offered in the same interactive prompt as every other untracked file rather than assumed to be untouched.

## `sk:upgrade`

Use this when moving between major starter-kit/Laravel lines, such as Laravel 12 -> 13.

The command also runs idempotent AST config steps for existing installs: a legacy `'display_timezone' => env('APP_TIMEZONE', ...)` entry in `config/app.php` is rewritten to read `APP_DISPLAY_TIMEZONE`, and missing UTC `timezone` entries are added to existing MySQL/MariaDB arrays in `config/database.php` without overwriting consumer values.

If the default MySQL/MariaDB session is not UTC and the `users` table holds data, the command warns and asks for explicit consent before pinning the connection. Declining, an inspection failure, or an unattended run without `--force` (`--no-interaction` or non-TTY) skips the edit and reports how to apply it later. `--force` bypasses this consent gate. The command never converts stored rows; follow the [one-time conversion guide](timezone.md#one-time-conversion-for-existing-data) first. Re-running the upgrade does not duplicate the config entries.

```bash
php artisan sk:upgrade
php artisan sk:upgrade --force
php artisan sk:upgrade --skip-build
```

## `sk:publish`

Use this only when you want project-owned copies of package assets.

```bash
php artisan sk:publish
php artisan sk:publish --tag=components
php artisan sk:publish --tag=datatable
php artisan sk:publish --tag=form
php artisan sk:publish --tag=tabs
php artisan sk:publish --tag=skeleton
php artisan sk:publish --tag=ui
php artisan sk:publish --tag=filemanager
php artisan sk:publish --tag=composables
php artisan sk:publish --tag=plugins
php artisan sk:publish --tag=lang
php artisan sk:publish --tag=config
php artisan sk:publish --tag=helpers
```

## `sk:eject`

Use this when you need to fully customize a domain whose runtime currently runs from the vendor package. Ejecting copies the domain's backend classes into `app/Domain/{Name}/`, rewrites their namespaces to `App\Domain\{Name}\`, refreshes the domain's Vue pages, and wires any event/listener bindings into `app/Providers/DomainServiceProvider.php` so the audit log keeps firing. Run `--dry-run` first to preview what will change.

Unless `--force`, `--dry-run`, or `--no-interaction` is passed, the command asks for confirmation before doing any work — ejecting is a one-way trade-off (the domain stops receiving kit runtime updates via `composer update`). `sk:install`'s own internal default-domain eject always passes `--force`, so the fresh-install flow is not interrupted by this prompt.

```bash
php artisan sk:eject User
php artisan sk:eject User --dry-run
php artisan sk:eject User --force
php artisan sk:eject User --no-vue
php artisan sk:eject Role --destination=/tmp/eject-preview
php artisan sk:eject ApiClient          # controllers + requests + resources (ApiClient + ApiToken)
php artisan sk:eject ContentLanguage    # domain + controller + request + resource
```

- `--dry-run` prints the copy/rewrite/injection plan without writing any files. Always run this first.
- `--force` overwrites files that already exist — both the backend `app/Domain/{Name}/` tree and the domain's Vue pages. **Without `--force`, eject never overwrites an existing file:** an already-present `app/Domain/{Name}/` makes the command exit early, and any Vue page that already exists is left untouched and reported as preserved — only missing pages are written. This protects edits you made to pages shipped by `sk:install`.
- `--no-vue` skips refreshing the domain's Vue pages; only the backend classes are ejected.
- `--destination=<path>` redirects output to an arbitrary directory instead of the app root. Intended for isolated testing.
- `--skip-autoload` skips the `composer dump-autoload` call at the end of eject. Use this only when the calling process (such as `sk:install`) will run `composer dump-autoload` itself afterwards. Without this flag, eject always regenerates autoload and exits non-zero if regeneration fails.

> **Exit code:** if Composer's autoload regeneration fails (e.g. `composer` is missing or errors out), the command prints the error and **exits non-zero** even though the files were copied — so CI and scripts do not mistake a broken autoload for a successful eject. Run `composer dump-autoload` manually, then re-verify.

### Ejectable domains

Fourteen domains can be ejected. Domains not in this list are already app-owned and do not need ejecting.

| Domain            | Backend classes | Vue pages | HTTP layer ejected          | Event bindings injected |
| ----------------- | --------------- | --------- | --------------------------- | ----------------------- |
| `User`            | yes             | yes       | —                           | 3 (Created/Updated/Deleted) |
| `Role`            | yes             | yes       | —                           | 3 (Created/Updated/Deleted) |
| `Setting`         | yes             | yes       | controller + requests       | —                       |
| `Logs`            | yes             | yes       | controller + requests       | 1 (FilesDeleted)        |
| `ActivityLog`     | yes             | yes       | controller                  | —                       |
| `ApiClient`       | yes             | —         | ApiClient + ApiToken controllers + requests + resources | — |
| `ApiRoute`        | yes             | yes       | controller                  | —                       |
| `ContentLanguage` | yes             | —         | controller + requests + resource | —                  |
| `SystemHealth`    | no (controller-only) | —    | controller                  | —                       |
| `Definitions`     | no (controller-only) | —    | API + Service controllers   | —                       |
| `MediaUpload`     | no (controller-only) | —    | controller                  | —                       |
| `Files`           | no (Vue only)   | yes       | —                           | —                       |
| `Session`         | yes             | —         | —                           | —                       |
| `Media`           | yes             | —         | —                           | —                       |

**`ApiClient` ejects the API-token flow too:** the ApiClient domain owns both the OAuth client and the personal-access-token actions, so `sk:eject ApiClient` copies the `ApiClientController` **and** the `ApiTokenController` (plus their FormRequests and API Resources, and rewrites both `api-client-route.php` and `api-token-route.php` imports). The one-time Passport client-secret reveal stays byte-identical — eject moves the file, it does not change behavior.

**`SystemHealth`, `Definitions`, and `MediaUpload` are controller-only:** they have no `app/Domain/{Name}` backend tree. `SystemHealth` drives Artisan + `Gate` directly from its controller; `Definitions` ejects both the `Api\DefinitionController` and the `Service\DefinitionServiceController` (which wrap the vendor `DefinitionService` — that service stays vendor); `MediaUpload` ejects the `Api\MediaUploadController` whose `media.destroy` route lives in the shared `routes/web.php`. None ship a FormRequest or `app/Domain` folder, so no autoload-affecting class is added unless a controller is copied.

**Models stay app-owned — eject never relocates a Model.** `App\Models\{ContentLanguage,Media,Definition,...}` remain published in your app and are never aliased to vendor (an `App\Models\X` alias would break Laravel's `XPolicy` discovery and route-model binding). The vendor controllers/domains reference these models by their `App\` FQCN, and an ejected `app/Domain/ContentLanguage` keeps that `App\Models\ContentLanguage` reference unchanged.

**Why Auth and Helpers are not ejectable:** Auth screens are already 100% app-owned — `sk:update` keeps them fresh without any eject. The `sk-helpers.php` global helpers ship as a single overridable file; consumers delete what they do not need.

**`Files` is Vue-only:** the FileManager backend (controller, FormRequests, route-registry infrastructure) stays vendor-managed after ejecting `Files`. Only the admin Vue pages (`resources/js/pages/Admin/Files/`) are copied into your app so the UI can be customised while the backend continues to receive kit updates. To revert, delete the copied `resources/js/pages/Admin/Files/` directory — the vendor pages take over via `app.ts` fallback.

### What the namespace rewrite covers

Only the ejected domain's own namespace is rewritten. Every other vendor reference is left untouched:

- `Lvntr\StarterKit\Domain\User\Actions\CreateUserAction` → `App\Domain\User\Actions\CreateUserAction`
- `use Lvntr\StarterKit\Domain\Shared\Actions\BaseAction;` — **unchanged** (`Shared` base classes stay in vendor)
- `Lvntr\StarterKit\Http\Responses\ApiResponse` — **unchanged**
- Any other domain not being ejected — **unchanged**

### Update-loss trade-off

> **Warning:** after ejecting a domain, future `composer update` runs that include security fixes or bug fixes to that domain's vendor runtime will not apply to your copy. You own the files — you must apply upstream changes manually.

`sk:update` never touches backend files in `app/Domain/` (they are not hash-tracked stubs). Vue pages ejected with `--force` follow the normal hash-tracking rules: if you edit them, `sk:update` marks them as customized and skips them.

### Reverting an eject (v1: manual)

A `--revert` flag is planned for a future version. To revert manually:

1. Delete `app/Domain/{Name}/`.
2. Remove the `Event::listen(...)` lines for that domain from `app/Providers/DomainServiceProvider.php`.
3. Run `composer dump-autoload`.

The `class_alias` entries in `StarterKitServiceProvider` will resume resolving `App\Domain\{Name}\*` imports back to the vendor copies automatically.

## `make:sk-domain`

Creates a new domain with the starter kit structure.

```bash
# Bare domain (backward compatible)
php artisan make:sk-domain Article

# Namespaced
php artisan make:sk-domain Store/Product

# Core options
php artisan make:sk-domain Product --admin --api --events --fields="name:string,price:decimal"
php artisan make:sk-domain Product --from-migration=2026_03_21_create_products_table.php

# Opt-in extras — individual flags
php artisan make:sk-domain Article --with-policy --with-factory

# Opt-in extras — bulk syntax
php artisan make:sk-domain Article --with=policy,factory,test

# Relation scaffold
php artisan make:sk-domain Article --with-relations --relations="belongsTo:User,hasMany:Comment"

# Full
php artisan make:sk-domain Article --with=policy,factory,seeder,test,relations --relations="belongsTo:User,morphTo:commentable"
```

Core flags:

| Flag | What it does |
| ---- | ------------ |
| `--fields="name:string,age:integer"` | Comma-separated `field:type` pairs. Available types: `string`, `integer`, `bigInteger`, `unsignedBigInteger`, `float`, `decimal`, `boolean`, `text`, `longText`, `json`, `date`, `dateTime`, `timestamp`. Omit to be prompted field-by-field. |
| `--id-type=id\|uuid\|ulid` | Primary key strategy. `id` (default) is an auto-increment bigint; `uuid`/`ulid` add the matching `HasUuids`/`HasUlids` concern and switch the migration's `id` column. Prompts interactively when omitted — skipped entirely with `--from-migration` (detected from the file). |
| `--api` / `--no-api` | Force-generate or force-skip the API controller + routes. Prompts (default: yes) when neither is passed. |
| `--admin` / `--no-admin` | Force-generate or force-skip the Admin controller + routes. Prompts (default: yes) when neither is passed. |
| `--events` / `--no-events` | Force-generate or force-skip the Created/Updated/Deleted events and their logging listeners. Prompts (default: yes) when neither is passed. |
| `--soft-deletes` / `--no-soft-deletes` | Force-enable or force-disable `SoftDeletes` on the model and migration. Prompts (default: yes) when neither is passed — skipped entirely with `--from-migration` (detected from the file). |
| `--vue=none\|empty\|full` | Vue page generation mode; only applies when the Admin layer is generated (forced to `none` otherwise). `full` scaffolds Index (DataTable) + Create/Edit (FormBuilder); `empty` scaffolds an empty Index page only; `none` skips Vue generation. Prompts interactively (default: `full`) when omitted. |
| `--vue-fields` / `--no-vue-fields` | Only relevant with `--vue=full`. Include the model's fields in the generated DataTable columns and FormBuilder, or generate an id-only skeleton. Prompts (default: yes) when neither is passed and fields exist. |
| `--from-migration=<filename>` | Parse fields, ID type, and soft-deletes from an existing migration file instead of `--fields`/`--id-type`/prompts, e.g. `--from-migration=2026_03_21_create_products_table.php`. Accepts a full or partial filename (glob-matched under `database/migrations/`). |

Opt-in flags (v2):

| Flag | What it generates |
| ---- | ----------------- |
| `--with-policy` | Policy class |
| `--with-factory` | Factory |
| `--with-seeder` | Seeder |
| `--with-test` | Feature test |
| `--with-permissions` | Registers the resource (all abilities) in `config/permission-resources.php`, plus an EN display name — TR label and role assignment are left for you to fill in |
| `--with-relations` | Relation scaffold (use together with `--relations`) |
| `--with=<policy,factory,seeder,test,permissions,relations>` | Bulk syntax — any combination of the opt-ins above in a single flag; individual `--with-*` flags are additive on top of it |
| `--relations="belongsTo:User,hasMany:Comment,morphTo:commentable"` | Relation definitions for the scaffold. Supported types: `belongsTo`, `hasMany`, `morphTo`. Passing `--relations=` implies `--with-relations` |

Use it when you want the package conventions for actions, DTOs, queries, requests, routes, and Vue screens.

## `remove:sk-domain`

Removes a generated domain and its related files.

```bash
php artisan remove:sk-domain Product
php artisan remove:sk-domain Product --force
```

- `--force` skips the confirmation prompt

## `env:sync`

Keeps `.env.example` aligned with the project `.env` keys.

```bash
php artisan env:sync
php artisan env:sync --reverse
```

`--reverse` is a safe validation mode: it does not write files, it only reports keys that exist in `.env.example` but are missing from `.env`.

## `site:install`

Useful in local development when you want a clean installation flow again.

```bash
php artisan site:install
```

The command shows the target environment and database before confirmation, only allows `local` and `setup`, and hard-blocks environments that look like production.

Since v13.4.1 the pipeline runs `passport:client --personal --provider=users` between `passport:keys` and the default admin seed, so a fresh install leaves you with a working personal-access-token path without any manual follow-up.

## `postman:sync`

Pushes the api-dock-generated OpenAPI document to Postman so the workspace collection stays in sync with the current API surface.

```bash
php artisan postman:sync
```

Reads the `postman` settings group: `postman.api_key` and `postman.workspace_id` are required, and `postman.collection_id` is rewritten with the upstream id after a successful push. The command fails early with a clear error when the key or workspace id is missing — set them under **Settings → API Clients → Postman** in the admin panel (or insert the rows directly) and re-run. Internally it delegates to `Lvntr\StarterKit\Domain\ApiRoute\Actions\SyncPostmanAction`, which uses the shared `OpenApiExporter` helper to resolve api-dock's `DocumentGenerator` and hands the document to Postman unchanged. The Action imports the fresh collection first, persists the new UID, then best-effort deletes the old one — a failed push never leaves the workspace without a working collection.

## `apidog:sync`

Pushes the same api-dock OpenAPI document to Apidog for teams that mirror the collection there.

```bash
php artisan apidog:sync
```

Reads the `apidog` settings group: `apidog.access_token` and `apidog.project_id` are required. If either value is missing the command aborts with a "not configured" error — populate them under **Settings → API Clients → Apidog** (or insert the rows directly) and re-run. The heavy lifting is done by `Lvntr\StarterKit\Domain\ApiRoute\Actions\SyncApidogAction`, which shares the `OpenApiExporter` helper with `postman:sync` — the document is uploaded to Apidog unchanged so the pushed project mirrors the real server contract.

## `api-dock:agent-guide`

Installs api-dock's authoring rules into this project's agent instruction files, so a coding agent working on API endpoints follows the same documentation conventions the panel expects.

```bash
php artisan api-dock:agent-guide
php artisan api-dock:agent-guide --file=AGENTS.md
php artisan api-dock:agent-guide --print
```

- `--file=` (repeatable) targets a specific instruction file relative to the project root. Without it, the command writes to whichever of `AGENTS.md`, `CLAUDE.md`, `GEMINI.md` already exist in the project — `AGENTS.md` is the cross-vendor convention, the other two are vendor-specific and are only written when the project already keeps one.
- `--print` writes the instruction block to output instead of to a file.

## `api-dock:sync`

Regenerates the OpenAPI document, compares it against the stored snapshot, and writes the new snapshot to `config('api-dock.snapshot.path')` (default `storage/api-dock/openapi.json`).

```bash
php artisan api-dock:sync
php artisan api-dock:sync --check
```

- `--check` exits with code `1` on breaking changes and does not write the snapshot — use this in CI to fail a build on an accidental breaking API change.

## `api-dock:diff`

Compares the currently generated OpenAPI document with the stored snapshot without writing anything.

```bash
php artisan api-dock:diff
php artisan api-dock:diff --json
```

- `--json` emits the structured diff as JSON instead of the human-readable summary.

## `api-dock:export`

Exports API Dock artifacts for AI tools and OpenAPI consumers, built through the same `DocumentGenerator` the `/api-dock` panel uses.

```bash
php artisan api-dock:export --openapi
php artisan api-dock:export --mcp --llms
php artisan api-dock:export --openapi --output=storage/app/api-exports
```

- `--openapi` writes the generated OpenAPI document (`openapi.json`)
- `--mcp` writes MCP tool definitions
- `--llms` writes the `llms.txt` bundle
- `--output=` overrides the export directory (default `config('api-dock.ai.export_path')`, i.e. `storage/api-dock`)

This is the command the admin panel's **Regenerate Docs** button runs under the hood (`api-dock:export --openapi`) via `Lvntr\StarterKit\Domain\ApiRoute\Actions\RegenerateApiDocsAction`.

## `sk:redact-activity-secrets`

Recursively removes sensitive keys from both the `attribute_changes` and `properties` JSON columns of existing activity-log rows while preserving all other keys. The operation is irreversible: take a database backup before running it.

```bash
php artisan sk:redact-activity-secrets --dry-run
php artisan sk:redact-activity-secrets
php artisan sk:redact-activity-secrets --chunk=500
php artisan sk:redact-activity-secrets --all
```

| Flag | Purpose |
| --- | --- |
| `--dry-run` | Report rows that would be redacted without writing changes |
| `--chunk=<rows>` | Process this many rows per round trip (default 500, maximum 5000) |
| `--all` | Scan every row instead of using the sensitive-key prefilter |

The command is idempotent and should be re-run after restoring an older backup. If a JSON payload cannot be decoded, it is counted, reported with a warning, and left unchanged; inspect that row manually because it may still contain a credential.

## `encryption:key`

Generates a dedicated `DATA_ENCRYPTION_KEY`, preserving the current primary key in `DATA_ENCRYPTION_PREVIOUS_KEYS`. See [Data Encryption](encryption.md) for the full adoption and rotation walkthrough.

```bash
php artisan encryption:key
php artisan encryption:key --show
php artisan encryption:key --force
```

| Flag | Purpose |
| --- | --- |
| `--show` | Print a freshly generated key and write nothing to `.env` |
| `--force` | Run even when the environment looks like production |

A default run: (1) resolves the current primary key (`DATA_ENCRYPTION_KEY`, or `APP_KEY` on first adoption); (2) generates a new random key; (3) prepends the old primary to `DATA_ENCRYPTION_PREVIOUS_KEYS`; (4) only then writes the new `DATA_ENCRYPTION_KEY`. `APP_KEY` is never touched, on any path. The command refuses to run under a production environment without `--force`. After it finishes, run `encryption:rekey`, then `encryption:health`, and clear `DATA_ENCRYPTION_PREVIOUS_KEYS` only after health reports OK.

## `encryption:rekey`

Re-encrypts settings and 2FA secrets onto the primary data-encryption key. Read the [server migration runbook](server-migration-runbook.md) before running this during a maintenance window.

```bash
php artisan encryption:rekey
php artisan encryption:rekey --dry-run
php artisan encryption:rekey --only=settings
php artisan encryption:rekey --chunk=500
```

| Flag | Purpose |
| --- | --- |
| `--dry-run` | Perform every decrypt attempt and print the summary without writing a single byte |
| `--only=<surface>` | Limit the run to `settings` or `two-factor` (comma-separated to combine) |
| `--chunk=<rows>` | Rows read, locked and rewritten per round trip (default 200, maximum 2000) |

Each row is tried against every key in the resolution chain, in order. The first key that decrypts it re-encrypts and writes back with the primary key; a row already on the primary key is skipped with no write. A row that decrypts with **no** key is left byte-for-byte untouched, counted, and listed by identifier (`settings.key` / `users.id`) in the summary — it is never nulled, deleted, or overwritten.

## `encryption:health`

Reports which key each encrypted value needs and whether `DATA_ENCRYPTION_PREVIOUS_KEYS` can be cleared. Read-only — no key material is ever printed.

```bash
php artisan encryption:health
php artisan encryption:health --json
```

- `--json` emits a machine-readable report mirroring `sk:doctor --json`'s shape

Verdicts: everything on the primary key and nothing undecryptable → "Safe to clear `DATA_ENCRYPTION_PREVIOUS_KEYS`" (exit `0`); any row still on a previous key → "Run `encryption:rekey` first; do NOT clear `DATA_ENCRYPTION_PREVIOUS_KEYS`"; any undecryptable row → the loudest failure, naming the affected rows and the missing key. A surface that could not be fully scanned (missing table, query error) only ever downgrades the verdict, never upgrades it.

## `file-manager:purge-trash`

Permanently deletes soft-deleted File Manager items older than the configured age.

```bash
php artisan file-manager:purge-trash
php artisan file-manager:purge-trash --days=30
php artisan file-manager:purge-trash --chunk=1000
```

The default is 7 days. The command only targets File Manager media (`collection_name = files`) and trashed folders; avatar, logo, editor upload and other MediaLibrary collections are not touched. The shipped `routes/console.php` schedules it daily, with `withoutOverlapping()`.

- `--chunk=<n>` (default 500, range 1–5000) rows are loaded per round trip and walked with `chunkById`, so the whole trash is never held in memory.
- The run takes a **cache lock** (`starter-kit:file-manager:purge-trash`, 1 h TTL) so two schedulers — or an operator racing the cron — cannot hand the same rows to two `forceDelete()` calls. A second concurrent run warns and exits `0` without purging.
- One failing item does not stop the run; the remaining items are still processed and the command **exits non-zero** when anything was left behind.
