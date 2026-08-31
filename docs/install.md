# Installation

> **Active Development Notice**
>
> This repository is under active development and is subject to frequent changes. The stability of the project is not yet guaranteed. Please consider the following points before use:
>
> 1. **Code Changes:** The directory structure or core classes may undergo radical changes without prior notice.
> 2. **Update Process:** Updates may not always provide an automated migration path. In addition to running update commands, you may need to perform manual interventions by checking the README or CHANGELOG files.
> 3. **Risk:** Significant changes may lead to data loss or breaking issues in your existing project.

This guide explains the recommended installation flow for a fresh project.

> **Start from a bare Laravel install.** Do **not** run `php artisan install:inertia`, `install:api`, Breeze, Jetstream, or any other starter preset before installing this package. Presets scaffold controllers, routes, pages, and layouts that this starter kit also ships — the installer cannot detect them, so they remain as orphaned dead code next to the kit's own files.
>
> Recommended flow:
>
> ```bash
> composer create-project laravel/laravel my-app
> cd my-app
> composer require lvntr/laravel-starter-kit:^13.7
> php artisan sk:install
> ```
>
> **Verify `php -v` reports 8.4 or newer before you start.** `composer
> create-project laravel/laravel` only requires PHP 8.3, so it succeeds on 8.3
> and leaves you one step short of this kit's floor. Require the kit with
> `:^13.7` rather than a looser `:^13.0` — a loose constraint lets Composer
> silently install an old release that still fits PHP 8.3 (`composer update`
> then reports "nothing to update") instead of failing with the real reason.
> If an install resolves to an unexpected version, run
> `composer why-not lvntr/laravel-starter-kit 13.7.0` to see what blocks it.

## Requirements

| Requirement | Version         |
| ----------- | --------------- |
| PHP         | 8.4+            |
| Laravel     | 13              |
| Node.js     | 20.19+             |
| Database    | MySQL / MariaDB |

## 1. Prepare The Project

Make sure the project has a working database connection and a valid `.env`. Set the basics before starting:

```env
APP_NAME="My Application"
APP_URL=https://my-app.test

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=my_app
DB_USERNAME=root
DB_PASSWORD=
```

### Environment Variables Of Note

The installer writes a starter `.env.example` that carries a few keys new installs should review:

```env
# Timestamp storage must stay UTC. Use APP_DISPLAY_TIMEZONE for the site's
# display fallback; users can override it from their profile.
APP_TIMEZONE=UTC
APP_DISPLAY_TIMEZONE=UTC

# Log level — 'debug' is fine for local dev; production should ship 'error' or 'warning'.
LOG_LEVEL=error

# Deny a request whose permission cannot be derived from its route name, rather
# than letting it reach the controller ungated. Seeded as false for a NEW
# project on purpose — there is no legacy route to grandfather in, so an
# ungated route of yours is caught in development instead of in production.
STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false

# Cloudflare Turnstile (bot / captcha). When TURNSTILE_ENABLED=false the
# `turnstile` middleware is a no-op, so leaving the keys empty locally is safe.
TURNSTILE_ENABLED=false
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=

# Session hardening — both default to 'true'. Keep these on in production.
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true

# Dedicated key for sensitive settings (settings.value) and 2FA secrets,
# independent of APP_KEY. `php artisan key:generate` never touches these.
# Generated automatically on a FIRST install; carried together with .env on
# every server migration (see docs/server-migration-runbook.md).
DATA_ENCRYPTION_KEY=
DATA_ENCRYPTION_PREVIOUS_KEYS=

# Passport OAuth2 keys — the recommended production pattern is to load these
# via env instead of committing the key files at storage/oauth-*.key.
# Run `php artisan passport:keys` once, move the generated strings into these
# env vars, then delete the files.
# PASSPORT_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
# PASSPORT_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----"
```

`STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false` reaches a **new** `.env` only. An existing app upgrading the package keeps the permissive default and is never flipped by a release — see [Upgrade Notes](UPGRADE.md#unresolved-route-fail-closed-is-opt-in-for-an-existing-install). If a route of yours starts returning 403 after a fresh install, `php artisan sk:doctor --only=unresolved-routes` names every route whose permission cannot be derived; give the route a `resource.action` name, list it under `permissions.unrestricted_routes` in `config/starter-kit.php` if it is deliberately permission-free, or set the key back to `true` while you sort it out.

Do not set `APP_TIMEZONE` to the site's regional timezone: it controls Laravel's storage timezone. Set `APP_DISPLAY_TIMEZONE` instead, or choose the site fallback in **Settings → General** after installation. See [Timezones](timezone.md) for per-user overrides and the complete resolution chain.

`DATA_ENCRYPTION_KEY` and `DATA_ENCRYPTION_PREVIOUS_KEYS` protect sensitive settings values and 2FA secrets independently of `APP_KEY` — a fresh install generates the key automatically, and no action is required. What matters is `.env` discipline going forward: a server migration must carry both keys along with the rest of `.env`, and `php artisan key:generate` must never be treated as a substitute. See [Data Encryption](encryption.md) for the full key-resolution contract and rotation commands, and the [server migration runbook](server-migration-runbook.md) before moving to a new server.

## 2. Require The Package

```bash
composer require lvntr/laravel-starter-kit:^13.7
```

## 3. Run The Installer

```bash
php artisan sk:install
```

> **`sk:install` is a first-install command, not a repair tool.** Run it once, on a project that the kit has not been installed into yet. It is **not** safe to re-run on an installed app: publishing is hash-aware, so a file is preserved when you excluded that path at install time, when the package has shipped nothing new for it, when a new version exists and you edited your copy, or when the hash registry simply has no record of it at all — on a re-install a file the registry has never tracked is treated as yours and is preserved and reported instead of overwritten, unless `--force` is passed. That protection only applies once a registry exists: on a genuine first install there is nothing to be authoritative yet, so every path (including one that is already untracked) publishes normally. Your `.env` is never overwritten. The registry lives under the git-ignored `storage/starter-kit/hashes.json`; if it goes missing (a stateless deploy, a cleared `storage/` directory), the installer no longer assumes a first install — it inspects the app for evidence the kit is already there and, if it finds any, stops with an error before writing a single byte unless `--force` is passed. A run that cannot finish (for example an unreachable database) closes as `INCOMPLETE` with a non-zero exit code and does not write the hash registry, so it stays resumable with `sk:install --resume` instead of being recorded as a successful install. To change an installed app use [`sk:update`](update.md) or `sk:publish --tag=<area>`.

Before touching any file, the installer runs a **preflight** check (Node.js version — warns and lets the npm step degrade later if Node is missing or older than 20.19, the Vite 7 engine floor; never hard-fails) and loads any **checkpoint** left by a previous interrupted run (`storage/starter-kit/install-progress.json`). If a step throws, the installer stops with an actionable message ("Step failed: `<step>` — fix the issue, then run `sk:install --resume`") instead of a raw stack trace; completed steps are checkpointed so `--resume` skips them and continues from the failure point. The progress file is deleted automatically once the install completes successfully.

The installer then walks through each step interactively:

| Step | What it does                                                                                     |
| ---- | ------------------------------------------------------------------------------------------------ |
| 1    | Publish application scaffolding (Controllers, Models, Routes, Vue pages, Enums, Providers, etc.) |
| 2    | Merge `package.json` dependencies                                                                |
| 3    | Seed `.env` from the freshly published `.env.example`, then generate `APP_KEY` when blank. An existing `.env` is **merged, never overwritten**: missing `.env.example` keys are appended, first-install-only keys are seeded only where absent, and no existing value is rewritten. The file is created from `.env.example` only when it does not already exist |
| 4    | Configure database connection (driver, host, port, database, credentials) — skipped in `--no-interaction` |
| 5    | Remove conflicting default Laravel files (`vite.config.js`, `welcome.blade.php`, etc.) — **on a first install only**; on any later run they are kept and listed in the closing report |
| 6    | Merge kit `.gitignore` entries into the project's existing file                                  |
| 7    | Publish and inject config files (`app.php`, including `display_timezone` backed by `APP_DISPLAY_TIMEZONE`; `database.php`, pinning existing MySQL/MariaDB connection arrays to `+00:00`; `filesystems.php`; `services.php` for Turnstile; `media-library.php`), wire `bootstrap/app.php`, register service providers, and register the custom-helpers autoload entry |
| 8    | Eject `User` + `Role` domain runtime into `app/Domain/` (skipped when `--without-eject` is passed or when `storage/starter-kit/hashes.json` already exists) |
| 9    | Regenerate Composer autoload                                                                     |
| 10   | Run database migrations — see [Choosing a migration strategy](#choosing-a-migration-strategy) below. If the database is unreachable the database steps are skipped and the run closes as `INCOMPLETE` with a **non-zero exit code** (no hash registry is written); fix the connection and re-run with `--resume` |
| 11   | Run seeders (Roles, Permissions, Definitions, Settings)                                          |
| 12   | Seed permissions from `config/permission-resources.php`                                          |
| 13   | Generate Passport encryption keys                                                                |
| 14   | Create default admin user (`admin@lvntr.dev` / random password printed at the end)                |
| 15   | Install npm dependencies and build frontend assets                                               |
| 16   | Finalize the application key and save stub hashes for `sk:update` tracking                       |

During the config step, `sk:install` adds the literal `'timezone' => '+00:00'` contract to the existing `mysql` and `mariadb` arrays in `config/database.php`. It does not replace a consumer-defined `timezone`, create a missing connection, or touch `sqlite`, `pgsql`, or `sqlsrv`.

Fresh installs do not need a data conversion. Because a consumer can point `sk:install` at a database that is already populated, it first checks whether the default MySQL/MariaDB connection already holds data on a non-UTC session — if it does, it **skips** the pin and tells you to run `sk:upgrade`, whose consent gate handles that case, after reading the [one-time conversion guide](timezone.md#one-time-conversion-for-existing-data). An unreachable database is treated as a fresh install and does not block the step.

### Choosing a migration strategy

When the default connection already carries tables, the migration step asks how to proceed:

| Option | What it does |
| --- | --- |
| `Run pending migrations only (keep existing data)` | **Always first, always the default.** Additive `migrate`; nothing is dropped. |
| `Drop all tables and run fresh migrations (ALL DATA WILL BE LOST)` | `migrate:fresh`. Offered only under the conditions below, and only after a typed confirmation. |
| `Skip migrations` | Runs no migrations at all. |

A session that cannot prompt (`--no-interaction`, CI, no TTY) is never offered the destructive branch and never has one selected for it — it runs pending migrations only.

The destructive option is **withheld outright** when any of these hold, and the reason is printed:

- `APP_ENV` looks production-like;
- `APP_DEBUG` is off, so the app is treated as deployed;
- the session cannot prompt for confirmation;
- any existing table already holds rows. This probe **fails closed** — a table that cannot be read (permission-limited, a view the credentials cannot select from, dropped mid-probe) counts as holding live data, never as empty. The `migrations` ledger is excluded, since its rows are not data an operator can lose.

When it *is* offered, choosing it prints the connection and database name and asks you to **type** the database name (or the word `fresh`). Surrounding whitespace is forgiven; nothing else is. An empty line, `y`, `yes` or any other answer falls back to the additive `migrate` path with nothing dropped — deliberately not to `Skip`, which would walk on into seeders against a schema that was never built.

### Default domain eject (User + Role)

On a fresh install the installer automatically ejects the `User` and `Role` domain runtime classes into `app/Domain/User/` and `app/Domain/Role/`. These are the two domains most often customised in real projects, so they land in your app from day one.

**What this means:**

- The backend classes (Actions, DTOs, Queries, Events, Listeners) are copied into your `app/Domain/{User,Role}/` with the `App\Domain\` namespace.
- `DomainServiceProvider` receives the corresponding `Event::listen` bindings so the audit log keeps firing.
- From that point on, **the kit no longer ships runtime updates to those domains via `composer update`** — the files are yours to maintain. This is the same trade-off as a manual `sk:eject` call.

**Reverting or opting out:**

To undo the eject after installation, delete `app/Domain/User/` and `app/Domain/Role/`, remove the injected `Event::listen` lines from `app/Providers/DomainServiceProvider.php`, and run `composer dump-autoload`. The vendor runtime and alias resolution resume automatically.

To skip the eject step entirely during a fresh install, pass `--without-eject`:

```bash
php artisan sk:install --without-eject
```

The domains remain vendor-resident and resolve via `class_alias`, identical to the pre-eject behaviour. You can run `sk:eject User` / `sk:eject Role` manually at any time later.

### Useful Flags

```bash
php artisan sk:install --force
php artisan sk:install --adopt
php artisan sk:install --adopt --dry-run
php artisan sk:install --no-interaction
php artisan sk:install --without-ai-skill
php artisan sk:install --without-eject
php artisan sk:install --resume
```

- `--force` overwrites existing publishable files — including a file you edited and one the registry has never tracked — and bypasses the already-installed safety stop. A `--force` run is no longer treated as a first install
- `--adopt` is the recovery path for an app that **is** installed but lost `storage/starter-kit/hashes.json`. It rebuilds the registry from the shipped stubs and nothing else: no file is copied, no migration runs, `.env` is not touched. Pair it with `--dry-run` to see the registry it would write first
- `--dry-run` prints what would be written and exits without writing anything
- `--no-interaction` is useful for CI or scripted installs; accepts all defaults automatically; the admin password is always a fresh random value (printed at the end) since there is no operator to type one in
- `--without-ai-skill` skips publishing the Lvntr Starter Kit AI skills entirely — both the Claude Code copies (`.claude/skills/`) and their Codex mirror (`.codex/skills/`). Useful when the consumer uses neither Claude Code nor Codex with the kit's skill bundle
- `--without-eject` skips the default `User` and `Role` domain eject; runtime stays in vendor and resolves via `class_alias`
- `--resume` picks up an install that failed partway through: steps already checkpointed in `storage/starter-kit/install-progress.json` are skipped and the run continues from the failed step. Passed without a prior checkpoint, it just runs a full install with a warning.

## 4. Build Frontend Assets

If you skipped the asset step during installation, run:

```bash
npm install
npm run build
```

For local development:

```bash
composer dev
```

## 5. Verify The Installation

After installation, confirm these areas work:

- web login page (log in with `admin@lvntr.dev` and the password printed by the installer, or the credentials you entered interactively)
- register and forgot-password pages, including Turnstile when enabled
- dashboard access
- user and role management pages
- profile security page (password, 2FA, browser sessions, avatar)
- settings page tabs: General, Auth, Mail, Storage, File Manager, API Integrations, API Clients, API Tokens, System Health
- file manager
- `/api/v1/auth/login` and `/api/v1/auth/me`

## 6. Module Ownership After Install

`sk:install` copies only the modules you are expected to customise from day one. Behaviour modules whose logic is unlikely to need project-specific changes run entirely from the vendor package — they do not produce files in your app.

| Module | Files installed into your app | Vendor-resident (no app copy) |
|---|---|---|
| Users, Roles | Controllers, FormRequests, Vue pages, routes, Models, Policies | — |
| Dashboard, Auth screens, Profile | Controllers, FormRequests, Vue pages, routes | — |
| Files (File Manager) | — | Vue pages + controller |
| Logs | — | Vue pages + controller |
| Activity Logs | — | Vue pages + controller |
| API Routes | — | Vue pages + controller |
| Settings | — | Vue pages + controller |

**Vendor-resident modules** are resolved by the `app.ts` vendor-fallback page loader — no file in your app is needed. To take full ownership of a vendor-resident module (for deep customisation), run `sk:eject`:

```bash
php artisan sk:eject Logs             # copies controller + FormRequests + Vue pages into your app
php artisan sk:eject Logs --dry-run   # preview first
php artisan sk:eject Logs --no-vue    # backend only
```

Once ejected, the module's files live in your app and `sk:update` treats them as yours — upstream updates no longer reach them automatically.

## 7. Optional Publishing

The package keeps many assets inside the package by default. Publish them only when you need project-level customization:

```bash
php artisan sk:publish
php artisan sk:publish --tag=components
php artisan sk:publish --tag=composables
php artisan sk:publish --tag=filemanager
php artisan sk:publish --tag=lang
php artisan sk:publish --tag=config
```

## Additional Configuration

Two `config/starter-kit.php` keys are not part of the interactive installer but change kit behavior — set them via env before installing, or override the published config:

| Config key | Env var | Default | Effect |
|---|---|---|---|
| `app_namespace` | `STARTER_KIT_APP_NAMESPACE` | `App` | Only consumed by `sk:publish` (not by `sk:install`'s main scaffolding step): when set to a non-default value, it rewrites `namespace App\…` / `use App\…` / `App\…` references in the `.php` files that command copies (`config/starter-kit.php` under `--tag=config`, `app/Helpers/sk-helpers.php` under `--tag=helpers`) to the configured namespace. Files copied by `sk:install` itself are copied verbatim — a non-default application namespace still needs manual edits after `sk:install`. |
| `strict_models` | `STARTER_KIT_STRICT_MODELS` | `true` | When `true`, `StarterKitServiceProvider` enables Eloquent's `Model::shouldBeStrict()` outside production (local/staging/testing) — lazy-loading, reading a missing attribute, and silently discarding a non-fillable mass-assignment all throw so bugs surface early. Production traffic is never affected regardless of this setting. Set to `false` to opt out entirely, e.g. when integrating a legacy schema that trips these guards. |

The `security` block of the same file has no env vars and is edited in the published config: `enforce_active_status`, `active_status_denied`, `active_status_guards` (cutting off an account disabled mid-session — see [Authentication](auth.md#cutting-off-an-account-that-is-already-signed-in)) and `csp_extra_origins` (extra origins appended to the kit's Content-Security-Policy header).

## Resetting the Database (site:install)

For development, the `site:install` command drops all tables and reinstalls from scratch:

```bash
php artisan site:install
```

This command:

1. Shows target database and environment details for confirmation
2. Runs `migrate:fresh` (drops all tables and re-runs migrations)
3. Runs all seeders (files prefixed with `_` in `database/seeders/`)
4. Generates Passport keys
5. Creates the default admin user

**Safety guards:**

- Only runs in `local` and `setup` environments
- Permanently blocked in any environment containing `prod` or `production`
- Requires explicit confirmation before proceeding

> **Note:** `site:install` is published as a stub file. If you customize it (e.g., add custom seeders or change admin defaults), the `sk:update` command will detect your changes and skip the file during updates.

## Updating The Package

When a new version is released:

```bash
# 1. Update the Composer package
composer update lvntr/laravel-starter-kit

# 2. Sync application files
php artisan sk:update
```

The update command uses a hash-based tracking system to safely merge package updates with your customizations:

| File category                                                                                           | Behavior                                                                            |
| ------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| **Runtime (vendor)** — `Domain/Shared/`, Traits, Middleware, helpers, `ApiResponse`, FileManager layer  | Live in `vendor/` — updated automatically via `composer update`; `sk:update` does not copy them |
| **Hash-tracked stubs** — auth/layout Vue components, user/role/settings domain skeleton                 | Diff-notified when the package version changes; applied only when the local hash still matches |
| **User-modifiable files** (Controllers, Models, Pages, Routes, `SiteInstallCommand`)                   | Updated only if you haven't modified them since last install/update                 |
| **Never-update files** (`config/permission-resources.php`)                                             | Installed once, never touched again                                                 |
| **Your custom domains**                                                                                | Never touched                                                                       |
| **New files from package**                                                                             | Automatically added                                                                 |
| **Deprecated files**                                                                                   | Automatically removed                                                               |

```bash
# Preview what would change without modifying anything
php artisan sk:update --dry-run

# Force update everything (overwrites your customizations)
php artisan sk:update --force
```

## Upgrading From Laravel 12

If you have an existing Starter Kit project on Laravel 12:

```bash
# 1. Update composer.json to require Laravel 13
composer require laravel/framework:^13.0 lvntr/laravel-starter-kit:^13.7 -W

# 2. Run the upgrade wizard
php artisan sk:upgrade
```

The upgrade command verifies Laravel 13+, Starter Kit v13+, PHP 8.4+; syncs stubs; clears caches; runs new migrations (optional); re-seeds roles and permissions (optional); and rebuilds frontend assets.

```bash
php artisan sk:upgrade --force       # skip confirmation prompts
php artisan sk:upgrade --skip-build  # skip npm install / npm run build
```

## All Available Commands

| Command            | Description                                    |
| ------------------ | ---------------------------------------------- |
| `sk:install`       | Full installation wizard                       |
| `sk:update`        | Update package files preserving user changes   |
| `sk:upgrade`       | Upgrade from previous Laravel version          |
| `sk:publish`       | Publish optional assets for customization      |
| `site:install`     | Reset database and reinstall with default data |
| `make:sk-domain`   | Scaffold a complete DDD domain interactively   |
| `remove:sk-domain` | Remove a domain and all its files              |
| `env:sync`         | Sync `.env` keys to `.env.example`             |

## Troubleshooting

**Vite manifest error after install:**

```bash
npm run build
# or start dev server
npm run dev
```

**Frontend changes not reflected:**

```bash
npm run dev
# or rebuild
npm run build
```

**Missing classes after install:**

```bash
composer dump-autoload
```

**Passport keys missing:**

```bash
php artisan passport:keys --force
```

**`php artisan tinker` not found after deploy:**

`laravel/tinker` ships in `require-dev` — production builds that run `composer install --no-dev` will not have it. This is deliberate. If you need tinker on the server, install it explicitly with `composer require laravel/tinker` (outside `require-dev`).

Related docs:

- [update.md](./update.md)
- [artisan-commands.md](./artisan-commands.md)
- [ui-components.md](./ui-components.md)
