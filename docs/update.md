# Update

This guide explains the safest way to update the starter kit in an existing project.

> **Hardening / security releases:** When the release notes mention edits to **published files** (files that `sk:install` copied into your app — controllers, requests, policies, composables, configs), `sk:update` will **not** overwrite them if you have modified them locally (which is the common case). For those releases, follow [UPGRADE.md](UPGRADE.md) — it contains the diff-style patch list you need to apply by hand, plus a smoke-test checklist.
>
> The split is deliberate: the `composer update` tier moves package-internal code (`vendor/lvntr/laravel-starter-kit/src/`), and the UPGRADE guide moves the copy-in-your-app tier.

> **v13.4.1:** This release also ships three install-time fixes (OAuth UUID migrations, Postman settings migration, Passport personal access client provisioning) in addition to the published-file patches — see [UPGRADE.md §7](UPGRADE.md) for the commands existing installs must run once.

## Recommended Workflow

1. Commit your current work.
2. Preview the package update.
3. Apply the package update.
4. Run migrations, env sync, and rebuild assets. (v13.4.1: also re-run the `oauth_*` migrations — see [UPGRADE.md §7.1](UPGRADE.md).)
5. Re-check permissions, routes, auth/settings screens, and critical pages.

## 1. Update Composer Package

```bash
composer update lvntr/laravel-starter-kit
```

## 2. Preview Changes First

```bash
php artisan sk:update --dry-run
```

Use `--dry-run` before real updates when the project has custom controllers, routes, pages, or config decisions.

## 3. Apply The Update

```bash
php artisan sk:update
```

### What `sk:update` Does

- runtime code (`Domain/Shared/`, Traits, Middleware, helpers, `ApiResponse`, FileManager layer) lives in `vendor/` since v13.5.0 — `composer update` is sufficient, `sk:update` does not copy these
- removes deprecated app-side files that have been moved to vendor
- migrates vendor-first behavior modules (Files/Logs/ActivityLogs/ApiRoutes/Settings) — see below
- notifies of hash-tracked stub changes (auth/layout Vue components, user/role/settings skeleton); applies them only when the local hash still matches
- updates user-modifiable files only if they were not changed locally
- asks how to handle untracked files
- adds new files introduced by the package
- injects missing filesystem and media library config pieces
- can optionally run newly added migrations

### Vendor-first behavior module migration (v13.6.0+)

Five behavior modules — **Files, Logs, ActivityLogs, ApiRoutes, Settings** — run their controllers, FormRequests, and Vue admin pages from the vendor package. `sk:update` migrates existing app copies under a hash guard and an `app.ts` guard.

**Removal is decided per module group, in two independent layers (PHP and Vue):**

- `php` layer — the controller + FormRequest directory tree. Resolved via the server-side alias bridge; can migrate regardless of `app.ts` state.
- `vue` layer — the Inertia page tree. Requires `app.ts` to contain the `@lvntr/pages` vendor-fallback glob. If the marker is absent, the Vue groups are left in place with a warning until you update `app.ts` and re-run `sk:update`.

**Group atomicity:** if even one file in a module's layer is user-modified or untracked, the entire layer for that module is preserved. A half-deleted module is never produced.

#### Scenario A — unmodified install

All five modules migrate automatically:

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
npm run build
```

#### Scenario B — modified file(s) in one or more modules

`sk:update` reports preserved modules. Your customised files keep working unchanged. To explicitly take ownership of a module that has migrated to vendor, run `sk:eject`:

```bash
php artisan sk:eject Logs             # copies controller + FormRequests + Vue pages into your app
php artisan sk:eject Logs --dry-run   # preview first
php artisan sk:eject Logs --no-vue    # backend only
php artisan sk:eject Files            # Vue pages only (Files backend always stays vendor)
```

After ejection `sk:update` treats those files as consumer-owned and never removes them.

#### Scenario C — fresh install from v13.6.0+

No action required. `sk:install` does not copy the five vendor-first modules. They run from vendor from day one.

## 4. Force Mode

```bash
php artisan sk:update --force
```

Use this only when you intentionally want package files to overwrite local changes.

## 5. Post-Update Checklist

Run these after a successful update:

```bash
npm install
npm run build
php artisan migrate
php artisan env:sync
```

Then check whether the update expects permissions your matrix does not declare yet:

```bash
php artisan sk:doctor --only=permission-matrix
```

If you changed permission resources or roles — or the check above listed anything — also run:

```bash
php artisan sk:seed-permissions --fresh
```

Then list the routes that reach a controller without any permission being checked:

```bash
php artisan sk:doctor --only=unresolved-routes
```

This check reports FAIL for every route `CheckResourcePermission` cannot resolve a permission for. Such a route **passes through today** — the middleware only logs a throttled warning — and keeps passing until you say otherwise — **no release turns this into a 403 for an existing install**. Opt in by setting `STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false` once this check is clean; a newly installed project already ships with that line. Routes the kit itself ships are already handled inside the package; what this check lists is your own routes, plus any kit route you renamed in your copy. See [UPGRADE.md](UPGRADE.md) for the ordered remediation path.

If the update introduced new settings groups or auth behavior, also review these screens once:

- Settings -> Auth
- Settings -> Turnstile
- Settings -> File Manager
- Profile security tabs

This release adds a dedicated `DATA_ENCRYPTION_KEY` for sensitive settings and 2FA secrets, independent of `APP_KEY`. **An existing install needs no action** — `DATA_ENCRYPTION_KEY` stays empty, encryption keeps using `APP_KEY` exactly as before, and nothing about `composer update` / `sk:update` forces adoption. Adopting the dedicated key is opt-in: see [Data Encryption](encryption.md) for the `encryption:key` → `encryption:rekey` → `encryption:health` walkthrough, and the [server migration runbook](server-migration-runbook.md) if you are about to move this install to a new server.

## File Update Strategy Summary

- Package-owned core paths are refreshed automatically — but only when your copy still matches the hash recorded at install/update time. `app/Enums/PermissionEnum.php` is the one entry here, and an ability case you added to it is preserved and reported instead of overwritten. Merge the package's new cases by hand (diff against the same relative path under `vendor/lvntr/laravel-starter-kit/stubs/`) or re-run with `--force` to take the package version and discard your edits.
- Customizable files are protected unless unchanged.
- `config/permission-resources.php` is treated as a user-owned file and is never written to. The flip side: resources and abilities the package adds do not arrive on their own. `php artisan sk:doctor --only=permission-matrix` reports what your matrix is missing.
- New package files are added automatically.

## Rolling Back a Customized File

There is no dedicated `sk:rollback` command — rollback is done through `sk:publish --force` on the tag that owns the file. This is deliberate: it keeps the code path identical to a fresh install, so recovery never relies on shadow state.

```bash
# List available tags
php artisan sk:publish --help

# Reset a single customizable area (e.g. only the FormBuilder) to the package's shipped version
php artisan sk:publish --tag=form --force

# Reset to an isolated directory first to inspect the diff without touching your code
php artisan sk:publish --tag=form --destination=/tmp/sk-compare
diff -ru resources/js/components/Lvntr-Starter-Kit/FormBuilder /tmp/sk-compare/resources/js/components/Lvntr-Starter-Kit/FormBuilder
```

Commit before `--force` so Git keeps the old version reachable.

> **Do not re-run `php artisan sk:install` on a project that is already installed.** An earlier revision of this guide offered it as a whole-project recovery path. That advice was wrong and is withdrawn — `sk:install` is a first-install command, not a repair tool.
>
> - Only `lang/` is preservable. Every other published path is overwritten **even without `--force`**, so a controller, provider, route file, Vue page or config you edited is replaced by the packaged stub.
> - The hash registry protects a file you **deleted**, not one you **changed**.
> - That registry lives at `storage/starter-kit/hashes.json`, which is git-ignored. If a stateless deploy loses it, `sk:install` classifies the app as a **first install**: it copies `.env.example` over your existing `.env` — dropping the database, cache, mail and storage credentials — and can then regenerate a blank `APP_KEY`, which makes existing session cookies and every `APP_KEY`-encrypted value unreadable.
>
> To repair an installed project, use `sk:update` (hash-aware, keeps your edits) or reset one area with `sk:publish --tag=<area> --force` after inspecting the diff via `sk:publish --tag=<area> --destination=/tmp/sk-compare`. `sk:update` re-applies the `config/filesystems.php` and `config/permission-resources.php` injections; the remaining install-time injections (`config/app.php`, `bootstrap/app.php`, provider registration, `media-library.php`, `services.php`) have no automated repair path yet — re-apply them by hand against the stub at the same relative path under `vendor/lvntr/laravel-starter-kit/stubs/`.

## When To Use `sk:upgrade` Instead

Use `sk:upgrade` when you are crossing a starter-kit or Laravel major line, such as Laravel 12 -> 13. Use normal `sk:update` for same-line package updates.

For the timezone behavior change, existing installs must run `sk:upgrade` once even when staying on the same Laravel line. Its idempotent AST steps rewrite a legacy `config/app.php` entry from `'display_timezone' => env('APP_TIMEZONE', ...)` to `env('APP_DISPLAY_TIMEZONE', ...)` and add literal `'timezone' => '+00:00'` entries to existing `mysql` and `mariadb` connection arrays in `config/database.php`. An existing `timezone` value is left unchanged; missing connections and `sqlite`/`pgsql`/`sqlsrv` are skipped. Add `APP_DISPLAY_TIMEZONE` to `.env` and keep `APP_TIMEZONE=UTC`.

Before applying the database edit, the upgrade inspects the default MySQL/MariaDB session and whether the `users` table contains data. If data exists and the session offset is not UTC, it warns that the two `TIMESTAMP` write classes move in opposite directions, links the [one-time conversion guide](timezone.md#one-time-conversion-for-existing-data), and asks `Pin the MySQL/MariaDB connection timezone to +00:00 now?`. Declining skips the database edit and prints the manual follow-up. An unattended run without the explicit `--force` override — including `--no-interaction` or a non-TTY shell — also skips it; failure to inspect the session/data skips it as well. `--force` is an explicit consent bypass and should be used only after the offset and conversion plan have been verified. Re-running `sk:upgrade` is safe for the config rewrite, but **the command does not convert existing rows and never will**. Applying only the config change to a live database creates a mixed dataset until the documented conversion reconciles the old rows.

```bash
php artisan sk:upgrade
php artisan sk:upgrade --force
php artisan sk:upgrade --skip-build
```

## When To Read This Together With Other Docs

- read [install.md](./install.md) for first-time setup
- read [artisan-commands.md](./artisan-commands.md) for command details
- read [project-documentation.md](./project-documentation.md) before updating deeper architecture pieces
