# UPGRADE — Lvntr Starter Kit

This file is the cross-major-version migration guide. Every release gets its own section, newest at the top. Small bug fixes live only in `CHANGELOG.md` — this document covers only changes that touch **published** files (i.e. files copied into your app by `sk:install`), because those changes cannot be delivered by `composer update` alone.

---

## v13.6.16 → v13.7.0

### `sk:install` now refuses to run on an app it did not install

`sk:install` used to trust its own hash registry (`storage/starter-kit/hashes.json`) as the only signal that a project was already installed — and that registry is git-ignored, so a stateless deploy or a cleared `storage/` directory made a live application look brand new. The command now runs a fail-closed detection pass ahead of the banner: it looks for the kit's schema tables and a handful of paths that only an install creates. If any of that evidence is present but the registry is missing, the command stops before writing anything and prints exactly what it found.

The stop names two ways out: `sk:update` for changing an installed app, and `php artisan sk:install --adopt` (add `--dry-run` to preview) to rebuild the registry from the files already on disk — it copies no file, runs no migration, and never touches `.env`. `--force` still proceeds past the stop for a genuine edge case, but read it as "overwrite the paths listed above," and note that a forced run is **not** treated as a first install (no default-domain eject, no first-install `.env` seeding).

### `.env` is never overwritten — a first install onto an app that already has one now merges

A first install used to copy `.env.example` over an existing `.env` outright — destroying `DB_PASSWORD`, `APP_KEY`, and everything else already configured whenever `sk:install` ran on the ordinary `composer create-project` shape (a Laravel app that already ships a `.env`). `.env` is no longer overwritten on either path. If the file already exists, the installer merges: every key present in `.env.example` but missing from `.env` is appended, and the first-install-only keys are seeded **only where absent**. No existing key's value is ever rewritten. `.env` is created from `.env.example` only when it does not exist at all. `APP_KEY` is still generated when the merged file leaves it blank, so the app can boot.

### A consumer-modified published file is skipped by default — `--force` is the opt-out

Both `sk:install` and `sk:update` now decide whether to overwrite a published path through the same three-way comparison (shipped stub hash vs. on-disk hash vs. the hash recorded in the registry at the last install/update). When the on-disk copy no longer matches what was recorded, the difference is treated as a consumer edit and the file is **skipped and reported**, instead of being silently overwritten — this now also covers `sk:install`'s re-publish path, not only `sk:update`'s. Pass `--force` to overwrite anyway; commit first so Git keeps the previous version reachable.

This closes the one remaining gap in that guard: on a re-install, a file the hash registry has **no record of at all** — because a newer package version started shipping into a path it never shipped into on this app before — used to be overwritten regardless of `--force`. It is now treated the same as a consumer edit: preserved and reported, not overwritten, unless `--force` is passed. The protection only applies once a registry exists to be authoritative; a genuine first install still publishes every path, tracked or not, because there is nothing yet to compare against.

### Inactive users are cut off mid-session

The login path already refused a non-active account, but it could not reach a session that was already open — an operator deactivating a user had to wait for that user's session cookie to expire on its own. A new `EnsureUserIsActive` middleware (wired automatically onto the `web` and `api` guards) now checks the authenticated user's `status` on every request and, if it matches the operator's deny-list, logs a web session out and redirects to login, or returns a 403 for an API request.

This is deliberately **fail-open** on every ambiguous case: a guard with no authenticated user, an unresolvable guard, a user model with no `status` attribute, a non-string `status`, or — critically — a value that is **not on the deny-list**, all pass through untouched. The middleware never infers "not active therefore blocked"; it only ever blocks a status that was explicitly listed. The default deny-list is `['inactive', 'banned']`, matching the shipped `userStatus` definition; an install using its own vocabulary adds its own values via `starter-kit.security.active_status_denied`. `starter-kit.security.enforce_active_status = false` is the kill switch — set it to disable the middleware outright without touching `bootstrap/app.php`. A consumer who published `config/starter-kit.php` before this `security` block existed is still covered: the middleware falls back to class constants matching the shipped defaults when the published config is missing the new keys.

### Installer commands now exit non-zero on a failed mandatory step

`sk:install`, `sk:update`, `sk:upgrade`, and the published `site:install` stub used to invoke `migrate`, `db:seed`, `vendor:publish`, `sk:seed-permissions`, `passport:keys`, `key:generate` and other sub-commands without reading their result, so a failed migration still printed `DONE`, recorded the step as complete in the resume checkpoint, and the command exited `0` — a CI job could go green over a half-installed application. Every sub-command result is now checked; a failed **mandatory** step (publish, migrations, seeders, permission seeding, Passport keys, encryption keys) now aborts the run with a non-zero exit code, leaves the checkpoint pending so `sk:install --resume` picks up where it stopped, and skips the stub-hash registry write.

If a CI pipeline currently passes despite a silently failing installer step, it will start failing after this upgrade — that is the intended signal, not a regression to work around. Frontend and tooling steps (`npm install`, Wayfinder generation, `npm run build`, `composer dump-autoload`, cache clears) deliberately stay non-fatal: they warn, print the command to run by hand, and are listed again in the closing summary, so a machine without Node or Composer still installs exactly as it does today. The `site:install` change ships through `stubs/`, so it reaches new installs and `sk:update`-refreshed apps only — an existing, untouched consumer copy of `site:install` is not changed.

An unreachable database at install time used to still print `Lvntr Starter Kit installed successfully!` and exit `0` — the database block (migrations, seeders, permission seeding) was skipped with only an on-screen warning. That run now ends the install as **incomplete**: no stub-hash registry is written, the resume checkpoint from the filesystem steps that did finish is kept, and the command exits non-zero. Fix the database connection and run `php artisan sk:install --resume` to pick up exactly where it stopped, rather than starting over.

### `migrate:fresh` now demands a typed confirmation

When `sk:install` finds an existing database with tables already in it, it offers a `select()` menu that included a "Drop all tables and run fresh migrations" option, confirmed by the ordinary yes/no `select()` answer — one accidental keystroke away from an irreversible `migrate:fresh`. That option is now gated behind a **typed** confirmation: the operator must type the database name (or the literal word `fresh`) at a `text()` prompt before the drop runs; anything else, including an empty answer or a reflexive `y`, falls back to the additive `migrate` path with nothing dropped. The destructive option is also withheld outright — the prompt explains why — when `APP_ENV` looks production-like, `APP_DEBUG` is off, the session cannot prompt at all (`--no-interaction`, CI, no TTY), or any existing table already holds rows (a table that cannot be read counts as holding data). There is no escape hatch around the typed confirmation itself; the only way to skip it is to run against an empty database or use `migrate` instead.

### `sk:install` is no longer documented as a recovery path

`docs/install.md` and `docs/update.md` previously described re-running `php artisan sk:install` on an existing project as an idempotent whole-project recovery step. It is not, and that advice is withdrawn — treat `storage/starter-kit/` as persistent operational state in your deploy strategy — it must survive a release the same way `storage/app/` does.

Two of the risks that originally motivated withdrawing that advice are now addressed above rather than open: a missing registry no longer makes `sk:install` silently treat an installed app as a first install (see "`sk:install` now refuses to run on an app it did not install" above), and a first install onto an app that already has `.env` no longer overwrites it (see "`.env` is never overwritten" above). What still makes `sk:install` the wrong tool for touching an already-installed app is the consumer-edit handling described in "A consumer-modified published file is skipped by default" above: without `--force` an edited file is skipped and reported rather than refreshed, and with `--force` it is overwritten outright, so neither mode gives you the selective, edit-preserving refresh `sk:update` or a scoped `sk:publish --tag=<area>` gives you. Use those commands to change an installed app instead.

### `DATA_ENCRYPTION_CIPHER` must match `app.cipher` — enforced

The whole read chain (`DATA_ENCRYPTION_KEY`, `DATA_ENCRYPTION_PREVIOUS_KEYS[n]`, `APP_PREVIOUS_KEYS[n]`, `APP_KEY`) is used under **one** cipher, so a `DATA_ENCRYPTION_CIPHER` that differs from `app.cipher` would leave rows written under the other cipher unreadable even though their key is still listed. `DataEncrypterFactory::cipher()` now throws a `RuntimeException` naming both values instead of failing later with an opaque `DecryptException`. Unset the variable, or set it to the same value as `app.cipher`.

Changing `app.cipher` itself on a database that already holds encrypted settings or 2FA data is a **one-way boundary**: the previous-key chain does not carry a per-key cipher, so old payloads become unreadable and neither `encryption:health` nor `encryption:rekey` can recover them. If you must change it, rekey **under the old cipher** first, verify with `encryption:health`, take a backup, and only then switch — treating the switch as a migration, not a config edit.

### Activity-log morph widening migration — forward-fix only

`2026_06_20_000000_widen_activity_log_morphs_to_string` widens `activity_log.subject_id` / `causer_id` to `char(36)` so UUID users and bigint Role/Permission ids can share the table. Its `down()` reverts both columns to `uuid`. On MariaDB 10.7+ that is a **native UUID type**, so rolling back a table that holds numeric subject/causer ids fails or truncates data; on MySQL the two types coincide, which is why the risk is easy to miss in testing.

Do not roll this migration back on a populated `activity_log`. Fix forward with a new migration, or restore from the backup taken before the upgrade.

### Activity-log credential redaction — back up before migrating

New activity rows no longer record sensitive attributes, but existing `activity_log` rows may still contain password hashes, tokens, or secrets. The new data-only migration recursively removes those keys from both the `attribute_changes` and `properties` JSON columns.

The migration ships **inside the package** (`database/migrations/`, auto-loaded like the rest of the kit's schema), so it is delivered by `composer update` alone — `sk:install` / `sk:update` are not required to receive it. It runs on the next `php artisan migrate`, which is why the backup below is not optional.

This redaction is **IRREVERSIBLE**. A database backup is **REQUIRED before running `php artisan migrate`**. The migration's `down()` method is intentionally a no-op because deleted credential material cannot be reconstructed.

After taking the backup, run the normal migration:

```bash
php artisan migrate
```

The migration scans every row rather than using the sensitive-key prefilter, because on MySQL a JSON column compares case-sensitively and a differently-cased key (`Password`) would otherwise be skipped. On a very large `activity_log` the step therefore takes a while; it holds no table lock and commits one short transaction per 500-row page.

`php artisan sk:doctor` includes an `activity-log-secrets` check, so an installation that never ran the migration reports a FAIL instead of staying silent. The check is a bounded, read-only probe rather than a second full pass: it reads the first 500 rows by primary key — the same fixed cost on every driver, PostgreSQL included — and decides in PHP, so a differently-cased key is caught regardless of collation. Because it stops at that window, a finding on a large table is reported as a floor ("at least N") and a clean result names the window it covered. Use `php artisan sk:redact-activity-secrets --dry-run --all` when you need the exhaustive count. The `--all` flag matters: without it the command falls back to a SQL key-name prefilter on MySQL, MariaDB and SQLite, which a differently-cased key can slip past.

The underlying command is idempotent and can be run separately, including after restoring an older backup:

```bash
php artisan sk:redact-activity-secrets --dry-run
php artisan sk:redact-activity-secrets
php artisan sk:redact-activity-secrets --chunk=500
php artisan sk:redact-activity-secrets --all
```

- `--dry-run` reports rows that would change without writing them.
- `--chunk=` controls rows processed per round trip (default 500, maximum 5000).
- `--all` scans every row instead of using the sensitive-key prefilter.

If the command warns that a JSON payload could not be decoded, that payload is left unchanged and may still contain a credential. Inspect and redact every reported row manually before treating the upgrade as complete.

### FileManager context abilities — BREAKING

Consumer-registered FileManager context closures now receive exactly one of `read`, `create`, `update`, or `delete`. The kit **never passes `write`** anymore.

A closure using the documented read-versus-mutation shape remains safe:

```php
'authorize' => fn (Model $actor, string $ability, Model $owner): bool =>
    $ability === 'read' ? $readCheck : $writeCheck,
```

However, the inverse legacy shape is dangerous:

```php
'authorize' => fn (Model $actor, string $ability, Model $owner): bool =>
    $ability === 'write' ? $writeCheck : $readCheck,
```

Because `write` is never passed, every mutation now falls into that closure's **read branch**. If `$readCheck` is broader than `$writeCheck`, create, update, and delete requests can be silently **over-permitted**.

Rewrite every consumer-registered closure to match all four ability names explicitly:

```php
'authorize' => fn (Model $actor, string $ability, Model $owner): bool => match ($ability) {
    'read' => $readCheck,
    'create' => $createCheck,
    'update' => $updateCheck,
    'delete' => $deleteCheck,
    default => false,
},
```

The built-in `global` context now maps these abilities one-to-one to `files.read`, `files.create`, `files.update`, and `files.delete`. Consequently, a role that held only `files.create` no longer has delete or empty-trash access, and a role that held only `files.update` no longer has read access. Grant each role the specific `files.*` abilities it needs, then rebuild the seeded permissions:

```bash
php artisan sk:seed-permissions
```

### Unresolved-route fail-closed is opt-in for an existing install

**Nothing breaks in this release.** A route whose permission cannot be resolved by `CheckResourcePermission` still **passes through today**, exactly as before — the middleware now additionally logs a throttled warning naming the route, so the gap is visible instead of silent. No request that currently succeeds starts failing because of this release.

The kit's own routes are fixed **inside the package** (`src/`): every route the kit ships now resolves to a permission on its own. An existing installation receives this fix through `composer update` alone — **no route-file edits, no `sk:update` reconciliation**. The reason is not that the routes live in the package — they are registered in `stubs/routes/web/*-route.php`, which `sk:install` did copy into your app. What lives in `src/` is the *contract*: a route-name → permission map inside `CheckResourcePermission`, keyed by the names those files already use. So the fix arrives without touching a file you may have edited.

The flip side is worth knowing: **if you renamed one of the kit's routes in your copy, the map no longer matches it** and that route falls back to passing on a warning — and would be denied after the flip. `sk:doctor --only=unresolved-routes` lists exactly those.

One case is deliberately left ungated at the middleware layer: `roles.bulk` and `users.bulk`. The ability those endpoints require depends on the action named in the request body, not on the route, and `BulkActionDispatcher` already authorizes every item against the handler's own ability (`BulkDeleteUserAction` requires `users.delete`). Any single route-level mapping could only over-deny — `.delete`, `.update` and `.read` each break a different legitimate role, since those abilities are independent in `permission-resources.php`. They are therefore declared under the package's exempt list, which also keeps them off the unresolved axis so the flip cannot break bulk actions later. Per-item authorization is unchanged and remains the real gate.

**Ordered remediation path**, to run before the default flips:

1. Run `php artisan sk:doctor --only=unresolved-routes` to list every route in your own app that still passes on a warning rather than resolving to a permission.
2. Fix each listed route with one of:
   - Give it a `<resource>.<action>` route name whose action segment is in the middleware's ability map, so a permission resolves automatically.
   - Gate it with an explicit permission argument, e.g. `check.permission:reports.read`.
   - If the route is deliberately permission-free (a public webhook, a health check, …), declare it under `starter-kit.permissions.unrestricted_routes` (tight `Str::is` patterns — prefer listing endpoints over whole trees, since a broad pattern silently exempts routes added later too).
3. With steps 1–2 done, set `STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false` (or `starter-kit.permissions.allow_unresolved` = `false`) in a staging environment and confirm nothing you rely on gets denied. Once staging is clean, set the same value in production. That line is the whole opt-in — nothing else has to change, and nothing will do it for you.

**If you published the kit's config** (`php artisan sk:publish --tag=config`), your `config/starter-kit.php` predates both new keys, and `mergeConfigFrom` merges only the top level — the package's `permissions` array does not fill gaps inside yours. Nothing breaks: `allow_unresolved` falls back to the package default in code, and an absent `unrestricted_routes` reads as an empty list. But step 2's third option does nothing until you add `'unrestricted_routes' => [...]` to your published `permissions` array yourself. Diff your copy against `vendor/lvntr/laravel-starter-kit/config/starter-kit.php` to pick up both keys.

**No release will flip this for you.** `STARTER_KIT_ALLOW_UNRESOLVED_ROUTES` (config `starter-kit.permissions.allow_unresolved`) defaults to `true` for an app that does not set it, and that default does not change anywhere in 13.x. There is no scheduled release in which your app starts denying on its own; step 3 is the only thing that turns it on, and you choose when.

The reason it is not simply switched is how far the switch would reach. An installation that never published the config, and one whose published copy predates the key, both fall through to the package's own constant — so changing that constant would alter authorization on a plain `composer update`, for every app, without anyone editing a file. A default with that reach is not a default anyone can change safely inside a release line; if it is ever revisited, it belongs in a major, with its own upgrade note.

**A brand-new project is different, and already strict.** `sk:install` writes `STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false` into the `.env` it creates: a fresh app has no legacy route to grandfather in, so it starts fail-closed and its first ungated route is caught during development instead of in production. This applies to first installs only — re-running `sk:install` on an existing app will not add the key, and neither will `sk:update` or `sk:upgrade`.

Whichever way you set it, the env var stays a valid production escape hatch: set it back to `true` if you need more time to finish remediation, keeping in mind that every route left unresolved is, by definition, ungated for as long as it stays that way.

### Cross-page bulk selection now fail-closes on unsupported filters

If a page adds its own filter on top of the kit's Users or Roles table — a custom `filter[...]` key the datatable doesn't declare — clicking "select all filtered" for a bulk action now returns a **422** (`sk-bulk.unknown_filters`) instead of silently running the bulk action against a set that ignores that filter. Before this release, an unsupported active filter was dropped from the snapshot and the resolved set was **wider** than what the table showed — deleting or acting on rows the filter was meant to hide.

A blank value is active too. `filter[status]=` (an empty or whitespace-only string) is applied by the table as `WHERE status = ''` — an empty set — so the bulk side now passes it through verbatim instead of treating it as "no filter": on a supported key it resolves the same (empty) set the table showed, on an unsupported key it is rejected with the same 422. Only a `null` value or an empty array is ignored, matching what Spatie's `AllowedFilter` skips. The shipped `SkDatatable` never writes a blank filter into the URL, so the stock Users/Roles pages are unaffected; a page that builds its own `filter_snapshot` should drop blank keys rather than send them.

**Do not "fix" the 422 by stripping the unsupported filter out of the snapshot before it reaches the backend** — that re-widens the set back to the old, unsafe behavior. Instead, extend the allow-list the query class actually applies (`UserBulkSelectionQuery::ALLOWED_FILTERS` / the Roles equivalent) so the new filter is honoured with the same semantics the table uses, or leave cross-page selection disabled while that filter is active and fall back to per-row selection.

This fix lives in the vendor query classes (`Lvntr\StarterKit\Domain\User\Queries\UserBulkSelectionQuery`, `Lvntr\StarterKit\Domain\Role\Queries\RoleBulkSelectionQuery`). **An ejected copy of either query — via `make:sk-domain` or any manual copy out of the vendor namespace — does not receive this fix on `composer update`**; re-diff your copy against the vendor source to pick it up. Likewise, a copy of `useDatatableSelection.ts` published with `php artisan sk:publish --tag=composables` keeps sending whatever id shape it sent before until you re-publish or manually port the change described below.

### `BulkActionRequest` no longer requires `ids` in cross-page mode

`app/Http/Requests/Admin/BulkActionRequest.php` required `ids` (`min:1`) even when `select_all_filtered` was `true`, contradicting the documented payload (`ids` is empty then) — a host calling `useDatatableSelection().executeBulkAction()` directly with nothing selected on the current page got a 422 (`sk-bulk.ids_required`). The rule is now `Rule::requiredIf(! select_all_filtered)`; any ids that are sent are still validated (`array`, `max:500`, opaque strings), and the cross-page set stays bounded by the selection query's `MAX_ITEMS` cap. This is a published stub: an unmodified copy is refreshed by `php artisan sk:update`; a copy you edited keeps the old rule until you port the change.

### Bulk selection ids are sent as opaque strings, no numeric coercion

`useDatatableSelection()`'s `executeBulkAction()` no longer coerces selected row ids before posting them. Backend `ids.*` validation already accepts `string|min:1|max:64`, so UUID/ULID primary keys were already valid — but a numeric-looking id could previously round-trip through a coercion step. If your own bulk endpoint parses `ids` with a strict integer cast, confirm it still accepts the exact string PrimeVue key type your `idKey` column uses.

### `DatatableQueryBuilder::columns()` payload shaping is fail-closed

A backend that declares `columns()` and receives a `?columns=` request parameter with **no key matching a declared column** now reduces every row to the `alwaysInclude()` keys only — it no longer falls back to returning the full row. An absent `columns` parameter is unaffected and still returns the full row. If a frontend column key and the corresponding backend `columns()` key have ever drifted apart (a rename on one side only), affected cells now render empty instead of masking the mismatch with the full payload; audit both sides if you see missing cell data after upgrading.

### `definitions.lang` is narrowed — the migration can refuse

`create_definitions_table` declared `unique(['key', 'value', 'lang'])` over three default `string()` (255-character, utf8mb4) columns — 3060 of MySQL/MariaDB's 3072-byte InnoDB key limit, one column-width away from breaking outright. A new migration narrows **`lang` only**, to 35 characters — the widest locale value the kit already accepts anywhere, taken from `content_languages.code`, so any tag you could have stored through the kit's own screens still fits and the refusal below stays unreachable for it. `key` and `value` keep their published 255: `lang` alone brings the index down to 2180 bytes, ~892 under the ceiling, so narrowing them would only have blocked data the current schema accepts.

**Before touching the schema, the migration measures every existing row** (`lang` character length, soft-deleted rows included — they still occupy the unique index) and refuses, unchanged, if any row would lose characters at the new limit. If it refuses on your data:

1. Read the error — it names the column, the row count over the limit, and the longest value found.
2. Shorten or delete the offending `definitions` rows (including soft-deleted ones — `deleted_at` does not exempt a row from the unique index).
3. Re-run `php artisan migrate`.

The migration is a straight rollback (`down()` widens `lang` back to 255 — a widening never truncates, so it needs no probe). Either direction ends by asserting the unique index is present, so a table that reached it with the index already missing (a half-finished earlier run) gets it rebuilt rather than recorded as migrated without its guarantee. On a table grown well beyond the kit's own ~34 seeded rows, the `ALTER TABLE` + index rebuild holds a metadata lock for its duration; schedule it with the same care as any other ALTER on a large table.

### `media` table migration now has a rollback path — one that refuses rather than destroys

`create_media_table` had no `down()`. Laravel's migrator guards that call with `method_exists`, so `php artisan migrate:rollback` did not error — it silently skipped the table and deleted the migration's ledger row anyway, leaving a `media` table that the app no longer had a record of and a re-`migrate` that failed on it. It now declares a `down()`: the table is dropped when it is empty, and a rollback attempted while rows remain stops with an error naming the table. The two later migrations in the same chain (`add_folder_id_to_media_table`, `add_soft_deletes_to_media_table`) carry the identical refusal, because a batch rolls back newest-first: without it they would have dropped `folder_id` and `deleted_at` off a populated table before the create migration's guard was ever reached.

**This is a behaviour change for existing consumers**: a `migrate:rollback` covering the batch this migration belongs to used to walk past `media` in silence; on an install that has media rows it now **fails**. That failure is the feature. Dropping a populated `media` would remove the **rows**, not the **files**: every row points at a blob on a configured disk, and Spatie deletes that blob only through the model's deleting event — a schema rollback bypasses Eloquent entirely, so the storage directories would survive intact while the only index of them was destroyed, leaving orphaned files nothing in the app can enumerate afterwards.

To roll the migration back deliberately, delete the media **through the application** first, so the blobs go with the rows, then run the rollback again. Do not empty the table with raw SQL to get past the guard — that reproduces exactly the orphaning the guard exists to prevent.

## v13.6.8 → v13.6.9

### `CheckResourcePermission` is now fail-closed on staging/demo (behavior change)

This is a **runtime** (`src/`) change delivered by `composer update` alone — no published file changes, no `sk:update` needed. It is listed here because it is a deliberate, security-motivated **behavior change** on non-production hosts.

**Before:** when the middleware resolved a route to a permission that was **not seeded** in the database, it **allowed** the request through on any *non-production* environment (staging, uat, demo, `testing`) with a logged warning — only `production` denied. A public staging/demo host could therefore silently expose an endpoint whose permission row had been forgotten.

**After:** an unseeded permission is **denied** on every environment except `local`. `local` still warns + allows so day-to-day development is not blocked by a not-yet-seeded permission.

**What you may notice:** on a public staging / uat / demo deployment, a route whose permission has not been seeded (via `php artisan sk:seed-permissions`) now returns **403** instead of silently passing. The fix is to seed the permission — which is exactly what production already required.

**Opt-out (restore the old posture):** if you deliberately want the previous "allow on any non-production environment" behavior, set in your `.env`:

```dotenv
STARTER_KIT_ALLOW_UNMAPPED_PERMISSIONS=true
```

(or `config(['starter-kit.permissions.allow_unmapped' => true])`). Production always denies regardless of this flag; `local` always allows.

**Also in this change:** the middleware's seeded-permission lookup is now Octane-safe — it caches the permission-name set for a short TTL (60s) instead of for the whole worker lifetime, and `sk:seed-permissions` flushes that cache immediately after seeding, so a newly seeded permission takes effect at once rather than after a stale worker recycles.

**Cache-store dependency:** the seeded-permission check now goes through `Cache::remember()` (your app's configured default cache store) instead of a container-instance binding. If you run a networked cache store (Redis, Memcached, …), the permission-check path now talks to that store on a cache miss — worth knowing if you monitor cache traffic or run a shared/clustered store. Projects on the `file` or `array` cache driver see no behavior difference.

---

## v13.6.7 → v13.6.8

### Summary

A quality/UX pass across several published stub files. The headline change is a security fix: `auth.login_throttle = '0'` no longer disables the web login rate limiter entirely — it now swaps in a deliberately generous floor limiter instead. Everything else in this release (audit-log expansion, `sk:install`/`sk:doctor`/`sk:eject` DX, form/datatable accessibility) lives in `src/` (vendor runtime) and needs only `composer update` — see `CHANGELOG.md` for the full list. Run the steps below once; the sections after are reference detail.

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update   # delivers the updated SettingsServiceProvider/FortifyServiceProvider, eslint.config.js, vitest.config.ts, Definition model, datatable.css
npm run build
```

### `login_throttle = '0'` no longer fully disables the web login limiter

**Before:** setting `auth.login_throttle` to `'0'` in Settings → Security nulled Fortify's `login` rate limiter entirely — a `0` value on this one setting made web login accept unlimited attempts.

**After:** `stubs/app/Providers/SettingsServiceProvider.php` now swaps the strict `login` limiter for a new `login-relaxed` limiter (defined in `stubs/app/Providers/FortifyServiceProvider.php`) instead of nulling it. Web login can be loosened by an administrator, but never fully unthrottled. The API auth routes are unaffected either way — they carry their own hardcoded `throttle:5,1` middleware.

**If you have not customised `SettingsServiceProvider.php` or `FortifyServiceProvider.php`:** `sk:update` delivers both changes automatically; no action needed beyond the standard upgrade steps above.

**If you customised either file:** `sk:update` preserves your version and reports a hash difference. Apply the two changes manually:
- Add a `login-relaxed` `RateLimiter::for(...)` definition to `FortifyServiceProvider::boot()` (see the vendor stub for the exact limits).
- In `SettingsServiceProvider`, change `config(['fortify.limiters.login' => null])` to `config(['fortify.limiters.login' => 'login-relaxed'])` when `auth.login_throttle === '0'`.

If you have your own project-specific reason to allow a fully unthrottled login limiter, you can still set `config(['fortify.limiters.login' => null])` directly in your own copy — this is no longer the kit's default behavior.

### Related smaller changes

- **`stubs/eslint.config.js` ruleset raised** — `pluginVue` flat config moved from `essential` to `strongly-recommended`. `sk:update` delivers the new file; if you have not customised it, `npm run lint` may report new (pre-existing) Vue style warnings the first time you run it after updating. Fix them at your own pace or pin the rule back to `warn` in your own copy.
- **Vitest config split out of `vite.config.ts`** — the inline `test: {...}` block moved to a new `stubs/vitest.config.ts`. `sk:update` delivers both files together; if you customised `vite.config.ts`, add the new `vitest.config.ts` alongside it manually (see the vendor stub for the `environment`/`globals` defaults it carries).
- **API two-factor challenges are claimed atomically** — `app/Domain/Auth/Actions/TwoFactorChallengeAction.php` no longer treats `Cache::pull()` as the claim. `Cache::pull()` is a separate get and forget on every cache driver, so two concurrent redemptions of one challenge id could both read the user id and both mint an access token; the route's `throttle:5,1` narrows that race without serializing it. The action now claims the challenge with `Cache::add()` on a companion key (`api:2fa_challenge_claimed:{uuid}`) — add-if-absent, atomic inside the store — and only the winner reads the payload. `Cache::add()` was chosen over `Cache::lock()` deliberately: a lock on the `database` cache driver needs the separate `cache_locks` table, and an install without it would get a hard failure on the 2FA endpoint. No configuration change and no new table is required, and single-use behaviour is unchanged (a wrong code still burns the challenge). **If you customised either `TwoFactorChallengeAction.php` or `LoginUserAction.php`,** `sk:update` preserves your copies and reports a hash difference — port the change by hand: `LoginUserAction` gains a public `TWO_FACTOR_CHALLENGE_TTL` constant and a `challengeClaimKey()` helper, and `TwoFactorChallengeAction::execute()` must return `null` when `Cache::add(LoginUserAction::challengeClaimKey($challenge), true, LoginUserAction::TWO_FACTOR_CHALLENGE_TTL)` is false, before it reads anything else.
- **`Definition` model gained a cache-flush observer** — `app/Models/Definition.php` now flushes the definition cache on every write path (`saved`/`deleted`/`restored`/`forceDeleted`), fixing a bug where writing a Definition through anything other than the seeder could leave stale cached values for up to the ~1h TTL. No visible change unless you were relying on the old (buggy) staleness.
- **Datatable inline search-clear / filter-remove markup** — `stubs/resources/css/theme/main/components/datatable.css` swapped the underlying icon-only `<span>` for a real `<button>` for keyboard accessibility; the CSS reset keeps it visually identical. No action needed beyond the standard `sk:update && npm run build`.

---

## v13.5.11 → v13.6.0

### Summary

13.6.0 bundles every published-file change since v13.5.11 (the last released version) into one upgrade. It completes the vendor-runtime migration — backend helper classes, middleware, three third-party configs, 15 composables, `TurnstileWidget.vue`, and the `v-can` / `v-role` permission directive plugin all run from the vendor package — and introduces the structured theme/layout/CSS system: an `AppShell.vue` composition, the `themes/main/` slot tree (every CSS cascade layer is an overridable slot), and the opt-in `themes/custom/` override theme. It also introduces the Security Settings redesign: the Security tab gains three sub-tabs (Authentication / Password Policy / Cloudflare Turnstile), six new `auth.*` setting keys, and full enforcement of password rules and password expiry via `EnsurePasswordNotExpired` middleware. **No visual change to the default build** — the default build (`VITE_SK_THEME=main`) is byte-identical to v13.5.11 for projects that do not touch the security settings. Run the upgrade once with the steps below; the per-area sections that follow are reference detail (apply only the "if you customised…" notes that match your project).

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update          # delivers the new stubs: layout, CSS theme tree, resolver, .env.example + package.json updates
php artisan migrate            # adds password_changed_at column to users
npm install
npm run build                  # panel should look identical
```

---

### Runtime theme switching — `main` and `aura`

Theme selection in **Settings → Appearance** now applies instantly for the two built-in kit themes (`main` and `aura`) — no rebuild required. Both are always bundled; `aura` activates via a `data-sk-theme="aura"` attribute on `<html>` written at runtime by the new `useTheme` composable.

| Theme | How it activates | Rebuild required? |
|---|---|---|
| `main` | Default — no `data-sk-theme` attribute | No |
| `aura` | `data-sk-theme="aura"` set on `<html>` by `useTheme` | No |
| Custom (consumer-created) | `VITE_SK_THEME=<name>` in `.env` | Yes |

#### Existing installs

Run `php artisan sk:update && npm run build` (the standard v13.6.0 upgrade). The updated `AdminLayout.vue` stub calls `useTheme()` alongside the existing `useDarkMode()` and `useAccentColor()` calls. After the build, switching between `main` and `aura` in Settings → Appearance is instant.

Custom themes you created under `resources/css/theme/<name>/` continue to work exactly as before — the build-time slot resolver is unchanged.

#### `aura` CSS moved to `theme-runtime/`

The aura CSS files that were previously at `resources/css/theme/aura/` have moved to `resources/css/theme-runtime/aura/`. All rules are now scoped to `html[data-sk-theme='aura']`.

`sk:update` delivers the new `theme-runtime/aura/` tree and removes the old `theme/aura/` directory. The `theme.css` entry file now contains two imports:

```css
@import './_active.css';
@import '../theme-runtime/aura/aura.css';
```

**If you customised `theme/theme.css`:** re-add the second import after `sk:update`. `sk:update` will report a hash difference for `theme.css`; apply the two-import pattern to your copy manually.

**If you set `VITE_SK_THEME=aura`:** remove that variable — it no longer has the intended effect. The `aura` theme is no longer a slot-based build-time theme; it activates at runtime via Settings → Appearance only. Setting `VITE_SK_THEME=aura` with the new layout causes the resolver to produce `24 slots, 0 overrides` (aura is not in the slot tree), and the `aura` visual style will still activate — but only via the runtime attribute, not via `_active.css`.

#### No visual change

For projects that used `main` (the default), the visual output after the build is byte-identical. For projects that used `VITE_SK_THEME=aura`, remove the variable, rebuild, and switch to `aura` via Settings → Appearance — the result is visually identical.

#### Related smaller changes

- **`sk:doctor` Theme Manifest check** no longer warns when the `_active.css` header theme differs from `VITE_SK_THEME`. Saving a runtime theme now routinely resets the marker to `main`, so that comparison would produce systematic false positives. The hard-fail on a missing manifest and the `../` traversal warning remain.
- **`.env.example` no longer ships `VITE_SK_THEME`.** The variable is still honored by the build-time resolver (marker → `VITE_SK_THEME` → `main`) for custom themes — add it to your `.env` yourself when you use one.

---

### Install-time domain eject (User + Role)

Starting from this release, `sk:install` automatically ejects the `User` and `Role` domain runtime into `app/Domain/User/` and `app/Domain/Role/` on the first run. This is a new-install-only change — **existing installs are not affected**.

#### Existing installs — no action required

The eject step is guarded by the hash registry: it runs only when `storage/starter-kit/hashes.json` does not yet exist (i.e. first install). On existing installs the registry is already present so the step is skipped entirely. If `app/Domain/{User,Role}/Actions` already exists from a prior manual eject, that domain's eject is skipped with a warning and the rest of the install continues normally.

#### New installs from this release

`app/Domain/User/` and `app/Domain/Role/` are created with backend classes rewritten to the `App\Domain\` namespace, and `DomainServiceProvider` receives the six audit-event `Event::listen` bindings. The trade-off: these files are now yours — `composer update` will not deliver upstream changes to them. See the `sk:eject` update-loss note in [artisan-commands.md](./artisan-commands.md) for details.

To opt out during install:

```bash
php artisan sk:install --without-eject
```

#### Reverting an automatic eject

```bash
rm -rf app/Domain/User/ app/Domain/Role/
# Remove the injected Event::listen lines for User and Role from:
# app/Providers/DomainServiceProvider.php
composer dump-autoload
```

The `class_alias` entries in `StarterKitServiceProvider` resume resolving `App\Domain\User\*` and `App\Domain\Role\*` imports back to the vendor copies automatically.

---

### Behavior-module HTTP + Vue layers moved to vendor (v13.6.0)

Five behavior modules — **Files, Logs, ActivityLogs, ApiRoutes, Settings** — have had their HTTP layer (controllers + FormRequests) and Vue admin pages moved from your app into the vendor package. On a fresh install these files are no longer copied into `app/`. On an existing install `sk:update` migrates them automatically under a hash guard (see below).

#### What changed per module

| Module | Was in your app | Now vendor-resident |
|---|---|---|
| Files (File Manager) | `resources/js/pages/Admin/Files/` (Vue only — backend was already vendor) | Vue pages served from package via `app.ts` fallback |
| Logs | `app/Http/Controllers/Admin/LogController.php`, `app/Http/Requests/Admin/Log/`, `resources/js/pages/Admin/Logs/` | controller + requests + Vue pages |
| Activity Logs | `app/Http/Controllers/Admin/ActivityLogController.php`, `resources/js/pages/Admin/ActivityLogs/` | controller + Vue pages |
| API Routes | `app/Http/Controllers/Admin/ApiRouteController.php`, `resources/js/pages/Admin/ApiRoutes/` | controller + Vue pages |
| Settings | `app/Http/Controllers/Admin/SettingsController.php`, `app/Http/Requests/Admin/Settings/`, `resources/js/pages/Admin/Settings/` | controller + requests + Vue pages |

#### How vendor resolution works

- **Controllers + FormRequests** resolve via `StarterKitServiceProvider::backwardCompatAliasPlan()`: `App\Http\Controllers\Admin\SettingsController` (and the other four) are aliased to their `Lvntr\StarterKit\Http\...` counterparts. The alias is disabled as soon as an `app/Http/Controllers/Admin/SettingsController.php` file exists — so any existing app copy keeps winning without any import changes.
- **Vue pages** resolve via the `app.ts` vendor-fallback loader: `import.meta.glob('@lvntr/pages/...')` is checked after the local `resources/js/pages/` glob. The app-first glob always wins when a local file exists.

#### `app.ts` vendor-page fallback requirement

The Vue migration depends on `resources/js/app.ts` containing the `@lvntr/pages` vendor glob. `sk:update` checks for this marker before removing any vendor-migrated Vue files; if it is absent, the Vue groups are left in place with a warning:

```
 WARN  app.ts does not contain the @lvntr/pages vendor fallback — Vue migration skipped.
       Run `php artisan sk:update` after updating app.ts to complete the migration.
```

If you see this warning, add the vendor-page resolver to your `app.ts`. The updated stub is delivered by `sk:update` itself — apply the hash-tracked change to `app.ts` and run `sk:update` again.

#### Existing installs — what `sk:update` does

`sk:update` migrates the files per **module group**, in two independent layers (PHP and Vue):

- **Unmodified copies** (on-disk hash matches the registry record): deleted. The vendor copy takes over — controller via the alias bridge, Vue pages via `app.ts` fallback.
- **Modified copies** (hash differs, or no registry record): kept in place, reported as preserved. Your customised file continues to win over the vendor copy.
- **Group atomicity**: if even one file in a module's PHP layer is modified, the entire PHP layer for that module is preserved (e.g. a customised `SettingsController.php` keeps its matching `app/Http/Requests/Admin/Settings/` directory). The PHP and Vue layers are evaluated independently.

After `sk:update`, run:

```bash
npm run build
```

No migration, no route change, no permission change is needed.

#### Three common scenarios

**Scenario A — unmodified install (standard case)**

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update   # hash-guarded removal — all five modules migrate automatically
npm run build
```

`sk:update` removes the unmodified app copies and vendor takes over. No further action needed.

**Scenario B — modified file(s) in one or more modules**

`sk:update` reports each preserved module group:

```
 WARN  Vendor-migrated paths preserved (user-modified or untracked):
  • app/Http/Controllers/Admin/SettingsController.php (modified)
  • app/Http/Requests/Admin/Settings/ (preserved with controller)
  • resources/js/pages/Admin/Settings/ (modified)
```

Your customised files keep working — no action required until you choose to migrate them. To take full ownership of the customised module explicitly:

```bash
php artisan sk:eject Setting   # copies vendor controller + requests + Vue into your app with App\ namespace
```

**Scenario C — fresh install from v13.6.0+**

No action required. `sk:install` does not copy any of the five modules. They run from vendor from day one.

#### Taking full ownership of a vendor-resident module

To customise a module's backend (controller, FormRequests) or Vue pages after they have migrated to vendor, use `sk:eject`:

```bash
php artisan sk:eject Logs             # backend + Vue pages
php artisan sk:eject Logs --no-vue    # backend only
php artisan sk:eject Logs --dry-run   # preview first
php artisan sk:eject Files            # Vue pages only (Files backend always stays vendor)
```

After ejection `sk:update` treats those files as consumer-owned and never removes them. See [artisan-commands.md](./artisan-commands.md) for the full `sk:eject` flag reference and update-loss trade-off.

#### What does not change

| Area | Status |
|---|---|
| Route files (`routes/web/*-route.php`) | Unchanged — remain in your app |
| `App\Http\Controllers\Admin\*` imports in your route files | Keep working — the alias bridge or ejected copy resolves them |
| Permission keys, route names | Unchanged |
| `config/permission-resources.php` | Unchanged — sanctuary, never overwritten |
| User / Role / Dashboard / Auth / Profile modules | Unchanged — remain fully app-owned |

---

### Behavior-module HTTP layer moved to vendor — Phase 2 (v13.6.0)

Phase 1 (above) moved the Files / Logs / ActivityLogs / ApiRoutes / Settings controllers and Vue pages to vendor. Phase 2 completes the picture by moving the **remaining controllers that back the vendor Settings tabs** and two API/Service controllers that already wrap vendor services. The Vue and migrations were already vendor (delivered in Phase 1); Phase 2 is a **PHP-layer-only** move.

#### What changed per module

| Module | Was in your app | Now vendor-resident |
|---|---|---|
| API Clients | `app/Http/Controllers/Admin/ApiClientController.php`, `app/Http/Requests/Admin/ApiClient/`, `app/Http/Resources/Admin/ApiClient/` | controller + requests + resource |
| API Tokens | `app/Http/Controllers/Admin/ApiTokenController.php`, `app/Http/Requests/Admin/ApiToken/`, `app/Http/Resources/Admin/ApiToken/` | controller + request + resource |
| System Health | `app/Http/Controllers/Admin/SystemHealthController.php` | controller (no domain / request / resource) |
| Definitions (API + Service) | `app/Http/Controllers/Api/DefinitionController.php`, `app/Http/Controllers/Service/DefinitionServiceController.php` | both controllers (the vendor `DefinitionService` was already vendor) |
| Media upload/delete | `app/Http/Controllers/Api/MediaUploadController.php` | controller |
| Content Languages | `app/Domain/ContentLanguage/` (Actions/DTOs/Queries), `app/Http/Controllers/Admin/ContentLanguageController.php`, `app/Http/Requests/Admin/ContentLanguage/`, `app/Http/Resources/Admin/ContentLanguage/` | domain runtime + controller + requests + resource |

#### How vendor resolution works

Identical to Phase 1: each moved controller / FormRequest / Resource is aliased from its `App\Http\...` FQCN to its `Lvntr\StarterKit\Http\...` counterpart by `StarterKitServiceProvider::backwardCompatAliasPlan()`, under a `file_exists` guard — the moment an `app/Http/Controllers/Admin/ApiClientController.php` (or any other) file exists, the alias steps aside and your copy wins. The `App\Domain\ContentLanguage\...` runtime classes resolve the same way (alias to `Lvntr\StarterKit\Domain\ContentLanguage\...`). Your route files keep their existing `App\Http\Controllers\...` imports unchanged.

#### Models stay app-owned

`App\Models\ContentLanguage`, `App\Models\Media`, and `App\Models\Definition` are **not** moved to vendor and **not** aliased — relocating a model would break Laravel's `XPolicy` discovery and route-model binding. The vendor `ContentLanguageController`, `MediaUploadController`, and `DefinitionController` reference these models by their `App\` FQCN; the ejected `app/Domain/ContentLanguage` runtime keeps its `App\Models\ContentLanguage` reference unchanged. `content_languages` and `media` migrations are already vendor (Phase 4) — no migration change in Phase 2.

#### Existing installs — upgrade steps

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update   # hash-guarded removal of the now-vendor PHP copies
```

`sk:update` removes the unmodified app copies of the Phase 2 PHP layer per module group (same group-atomic rule as Phase 1: any modified file in a module's PHP layer preserves the whole layer). No Vue rebuild is required for Phase 2 alone — the Vue migrated in Phase 1 — but running `npm run build` after the full v13.6.0 upgrade remains the correct single step.

#### Taking full ownership

```bash
php artisan sk:eject ApiClient          # ApiClient + ApiToken controllers + requests + resources
php artisan sk:eject ContentLanguage    # domain + controller + request + resource
php artisan sk:eject SystemHealth       # controller-only
php artisan sk:eject Definitions        # Api + Service controllers (DefinitionService stays vendor)
php artisan sk:eject MediaUpload        # controller-only (media.destroy route in routes/web.php)
```

After ejection `sk:update` treats those files as consumer-owned and never removes them. See [artisan-commands.md](./artisan-commands.md) for the full eject domain table and update-loss trade-off.

#### What does not change

| Area | Status |
|---|---|
| Route files (`routes/web/*-route.php`, `routes/web.php`, `routes/api/service-route.php`) | Unchanged — remain in your app; only the controller `use` import points at vendor |
| Permission keys, route names | Unchanged — route names drive `CheckResourcePermission`; nothing renamed |
| Passport client/token secret single-reveal | Unchanged — the `ApiClientController` / `ApiTokenController` logic is byte-identical, only the file location moved |
| `App\Models\{ContentLanguage,Media,Definition}` | Never moved, never aliased — app-owned |
| `RoleServiceController` | Unchanged — backs the Role/Setting scaffold screens, stays app-owned |
| `LocaleController`, `Api/UserController`, `Api/Auth/*`, Dashboard / User / Role / Profile / Auth controllers | Unchanged — scaffold, fully app-owned |

---

### Domain runtime layers moved to vendor (Phase 6)

Five domain modules have had their **runtime layer** (Actions, DTOs, Queries, Events, Listeners, and the Setting service) moved from `stubs/app/Domain/` into the package (`src/Domain/`, PSR-4 `Lvntr\StarterKit\Domain\`). The consumer-facing surface — Controllers, FormRequests, Models, Vue pages, route files, Policies, and `config/settings.php` — stays in your app and is **not affected**.

Affected domains: `ApiClient`, `ApiRoute`, `Setting`, `User`, `Role`.

#### What does not change

| Area | Status |
|---|---|
| `App\Domain\<Module>\...` imports in your controllers / providers | Keep working — `class_alias` resolves them to the vendor namespace transparently |
| Existing `app/Domain/{ApiClient,ApiRoute,Setting,User,Role}/` copies | Preserved, never deleted automatically |
| Controllers, FormRequests, Models, Vue pages, route files | Unchanged — stays in your app |
| `App\Models\User`, `App\Models\Role`, `App\Models\Setting` | Never moved to vendor |
| `Store/UpdateRoleRequest` privilege-boundary (`validated()`) | Unchanged — stays app-owned |
| `config/permission-resources.php` | Unchanged — sanctuary (never overwritten by `sk:update`) |
| `config/settings.php` | Unchanged — sanctuary (never overwritten by `sk:update`, added this release) |
| Policies (`UserPolicy`, `RolePolicy`, `SettingPolicy`, `ApiClientPolicy`, `ApiTokenPolicy`) | Unchanged — stays app-owned |
| Postman / Apidog console commands | Unchanged — stays app-owned |
| `BulkDeleteUserAction`, `BulkDeleteRoleAction` | Unchanged — stays app-owned (extends the app-owned `App\Http\BulkActions\BulkDeleteAction` override base) |
| Permission keys, route names, API response envelope | Unchanged |
| `RoleEnum` (system_admin / admin / user contract) | Unchanged — stays app-owned |

#### New installs (v13.6.0+)

A fresh `sk:install` no longer copies `app/Domain/ApiClient/`, `app/Domain/ApiRoute/`, `app/Domain/Setting/`, `app/Domain/User/Actions/`, `app/Domain/User/DTOs/`, `app/Domain/User/Events/`, `app/Domain/User/Listeners/`, `app/Domain/User/Queries/`, `app/Domain/Role/Actions/`, `app/Domain/Role/DTOs/`, `app/Domain/Role/Events/`, `app/Domain/Role/Listeners/`, or `app/Domain/Role/Queries/` into `app/`. These modules' runtime classes run directly from `vendor/lvntr/laravel-starter-kit/src/Domain/`. `App\Domain\<Module>\...` imports in scaffold controllers resolve via `class_alias` — no import changes needed in generated code.

#### Existing installs — upgrade steps

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
```

`sk:update` reports the app copies that are now vendor-resident (informational only — never deleted automatically):

```
 WARN  v13.5.0+: package runtime runs from vendor. The following files still exist in your app:

  • app/Domain/ApiClient/
  • app/Domain/ApiRoute/
  • app/Domain/Setting/
  • app/Domain/User/Actions/
  • app/Domain/User/DTOs/
  • app/Domain/User/Events/
  • app/Domain/User/Listeners/
  • app/Domain/User/Queries/
  • app/Domain/Role/Actions/
  • app/Domain/Role/DTOs/
  • app/Domain/Role/Events/
  • app/Domain/Role/Listeners/
  • app/Domain/Role/Queries/

  Deleting these files is optional; vendor copies take precedence.
```

No other steps are required. Your app continues to work unchanged.

#### Optional cleanup — reconcile stale app copies

This step is entirely optional and can happen later.

**If you have not customised any of these domain files**, delete the app copies so the vendor versions (via `class_alias`) take over:

```bash
# ApiClient + ApiRoute
rm -rf app/Domain/ApiClient/
rm -rf app/Domain/ApiRoute/

# Setting runtime (keep app/Models/Setting.php and app/Policies/SettingPolicy.php)
rm -rf app/Domain/Setting/

# User runtime (keep app/Domain/User/BulkActions/)
rm -rf app/Domain/User/Actions/
rm -rf app/Domain/User/DTOs/
rm -rf app/Domain/User/Events/
rm -rf app/Domain/User/Listeners/
rm -rf app/Domain/User/Queries/

# Role runtime (keep app/Domain/Role/BulkActions/)
rm -rf app/Domain/Role/Actions/
rm -rf app/Domain/Role/DTOs/
rm -rf app/Domain/Role/Events/
rm -rf app/Domain/Role/Listeners/
rm -rf app/Domain/Role/Queries/
```

**If you have customised a domain file**, keep your `app/Domain/<Module>/` directory or the specific subdirectory. The `class_alias` guard detects it and steps aside — your customised version continues to win over the vendor copy. You can delete individual unchanged subdirectories while keeping modified ones; the guard operates at the class level.

**Partial reconcile example** — keep a customised Action but delete the rest:

```bash
# Remove everything in User Actions except your customised file
rm app/Domain/User/Actions/DeleteUserAction.php
rm app/Domain/User/Actions/UpdateUserAction.php
# Keep: app/Domain/User/Actions/CreateUserAction.php (customised)
```

#### Per-domain override paths

All runtime classes that moved are in the `overridable` tier: if an `app/Domain/<Module>/<Class>.php` file exists, it wins over the vendor copy — the alias guard steps aside automatically. No explicit opt-in is required.

| Layer | Override path |
|---|---|
| Action, DTO, Query, Event, Listener, Service | Create `app/Domain/<Module>/<path>.php` with the `App\Domain\<Module>\...` namespace — the alias guard skips the vendor version automatically |
| Controller, FormRequest, Resource, Policy | Already app-owned — edit in place |
| `config/settings.php` | Already app-owned sanctuary — edit in place |
| Vue pages (`resources/js/pages/Admin/*`) | Already app-owned — edit in place |
| Runtime class re-publish | There is no `sk:publish <domain>` tag for PHP runtime classes. Copy the source from `vendor/lvntr/laravel-starter-kit/src/Domain/<Module>/` into `app/Domain/<Module>/` with the `App\Domain\<Module>\` namespace and the alias guard handles the rest |

#### User / Role audit log event wiring

`UserCreated`, `UserUpdated`, `UserDeleted`, `RoleCreated`, `RoleUpdated`, and `RoleDeleted` events and their `Log*` listeners are now vendor-resident. The `StarterKitServiceProvider` registers the event→listener bindings directly, replacing the `DomainServiceProvider` registrations that are no longer needed for these pairs.

**Fresh install:** `DomainServiceProvider` no longer contains `Event::listen` calls for these six pairs — the vendor provider handles them.

**Existing install:** if your `app/Providers/DomainServiceProvider.php` still contains the `Event::listen` registrations for these pairs (e.g. `Event::listen(UserCreated::class, LogUserCreated::class)`), remove them after reconciling the app copies. Keeping the registrations alongside app-owned copies is harmless — when app copies exist the alias guard skips the vendor binding and the app-side registration fires exactly once. Remove both (app copies + `DomainServiceProvider` registrations) together to switch fully to vendor.

#### `config/settings.php` added to the never-update sanctuary

`config/settings.php` is now in `NEVER_UPDATE_PATHS`. `sk:update` will never overwrite it, protecting any sensitive keys or setting groups you have added. This applies from v13.6.0 forward — no manual action required.

#### What does not change

| Area | Status |
|---|---|
| Auth / permission behavior | Unchanged — `CheckResourcePermission`, `permission-resources.php`, `RoleEnum` untouched |
| API secret handling — Passport `plainSecret` single-use rule | Unchanged — secret-producing path stays in app-owned `ApiClientController` and `ApiClientResource` |
| Setting encryption — `SettingService` `sensitive_keys` read / `Crypt::encryptString` write | Unchanged — logic byte-identical; only the file location moved |
| `config/settings.php` sensitive-keys whitelist | Unchanged — stays app-owned and is now sanctuary |
| Route files and middleware tiers | Unchanged — all route files stay app-owned; no route registry change |

---

### Kit translations moved to vendor (Phase 5)

The 44 kit-specific translation files (`sk-*.php`, two locales) have moved from `stubs/lang/` into the package itself (`resources/lang/{en,tr}/sk-*.php`). Pre-compiled JSON (`resources/js/lang/php_en.json` / `php_tr.json`) ships alongside them and is consumed by the frontend i18n setup automatically. Translation files are no longer bulk-copied into your app on a fresh install.

#### How translations are delivered

| Layer | Before v13.6.0 | v13.6.0+ |
|---|---|---|
| Frontend (`$t('sk-common.*')`) | `app/lang/*.php` compiled by Vite plugin | Vendor JSON merged with `app/lang` at build time — app wins on collision |
| PHP backend (`__('sk-common.*')`) | `app/lang/{locale}/sk-*.php` copied by `sk:install` | Vendor `resources/lang/{locale}/sk-*.php` registered by `StarterKitServiceProvider` at boot |

#### Merge priority

The frontend i18n setup (`resources/js/app.ts`) now loads two sources:

1. **Vendor JSON** — `vendor/lvntr/laravel-starter-kit/resources/js/lang/php_{locale}.json` (fallback for any key not overridden in your app)
2. **App JSON** — `app/lang/*.php` compiled by the Vite i18n plugin into `lang/php_{locale}.json` (takes precedence — your customisations always win)

Missing translations fall back to the vendor default. There is no visible change unless you have customised a `sk-*` key, in which case your version continues to show.

#### New installs (v13.6.0+)

A fresh `sk:install` no longer copies `lang/{en,tr}/sk-*.php` into your app. Kit translations are served from the vendor package. `lang/{en,tr}/validation.php` is still copied — it is the standard Laravel validation override surface and remains in your app.

No extra step is needed.

#### Existing installs — upgrade steps

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
npm run build
```

`sk:update` reports any `lang/{locale}/sk-*.php` copies that remain in your app (informational only — they are **never deleted automatically**):

```
 WARN  These files are now vendor-resident. Your app copies are kept; vendor copies take precedence where no app copy exists.

  • lang/en/sk-activity-log.php
  • lang/en/sk-api-clients.php
  • ... (22 files per locale)

  Deleting these files is optional; if present, they continue to take precedence over the vendor default for any keys they define.
```

Your app keeps working unchanged after the update. Run `npm run build` to pick up the new vendor JSON source in the frontend bundle.

#### Optional cleanup

If you have **not customised** any `sk-*.php` translation files, you can delete the app copies to lean on the vendor default entirely:

```bash
rm lang/en/sk-*.php
rm lang/tr/sk-*.php
```

If you have **customised** one or more files, keep them — or keep only the specific files you modified. Any key defined in `app/lang/{locale}/sk-*.php` overrides the vendor value for that key. Keys absent from your app copy fall back to the vendor default.

#### Customisation and the escape hatch

To publish the vendor translation files into your app for full customisation:

```bash
php artisan sk:publish lang
```

This copies the vendor `resources/lang/` into `lang/vendor/starter-kit/` and makes the namespaced `starter-kit::` translations available. For the namespace-less `sk-*` keys used by the frontend and backend, place your overrides directly in `lang/{locale}/sk-*.php` — the merge picks them up automatically.

#### What does not change

| Area | Status |
|---|---|
| Translation content — all `sk-*` strings | Unchanged — only the file location moves |
| `lang/{locale}/validation.php` | Unchanged — remains in your app (Laravel framework override surface) |
| Permission keys, route names, API envelope | Unchanged |
| Frontend `$t('sk-*')` call sites | Unchanged |
| PHP `__('sk-*')` call sites in vendor runtime | Unchanged — resolved from vendor `resources/lang/` via `StarterKitServiceProvider` |

---

### Kit migrations moved to vendor (Phase 4)

Six kit-specific migrations have moved from `stubs/database/migrations/` into the package itself (`database/migrations/`, auto-loaded via `loadMigrationsFrom`). They are no longer copied into your app on a fresh install.

#### Moved migrations

| File | Table |
|---|---|
| `2026_03_08_205445_create_media_table.php` | `media` |
| `2026_03_11_071628_create_activity_log_table.php` | `activity_log` |
| `2026_03_12_001950_create_definitions_table.php` | `definitions` |
| `2026_03_14_080933_create_settings_table.php` | `settings` |
| `2026_04_13_100200_add_folder_id_to_media_table.php` | `media` (add `folder_id`) |
| `2026_05_02_094121_add_soft_deletes_to_media_table.php` | `media` (add `deleted_at`) |

Framework-default migrations (`create_users_table`, `create_cache_table`, `create_jobs_table`), Passport OAuth migrations, and the Spatie permission migration were **not moved** — they remain in `stubs/database/migrations/` and continue to be copied into your app.

#### How it works

The package loads all vendor-resident migrations automatically when `config('starter-kit.run_migrations')` is `true` (the default). Laravel keys migration history by bare filename, so a migration that already ran in your app is silently skipped — no double-run, no error.

#### New installs (v13.6.0+)

A fresh `sk:install` no longer copies the six migrations listed above. They run directly from the vendor package. No extra step is needed.

#### Existing installs — upgrade steps

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
php artisan migrate
```

`sk:update` force-deletes the six app copies listed above. This is safe: each filename is already recorded in your `migrations` table and will not be re-run (Laravel's basename-keyed deduplication). `php artisan migrate` returns "Nothing to migrate" unless other pending migrations are queued.

#### Disabling auto-load (escape hatch)

If you need a physical copy in your app — for example, to modify a migration before it has run, or to satisfy a static-analysis tool — publish the vendor migrations and disable auto-load:

```bash
php artisan vendor:publish --tag=starter-kit-migrations
php artisan vendor:publish --tag=starter-kit-config
```

Then set `run_migrations` to `false` in `config/starter-kit.php`:

```php
'run_migrations' => false,
```

With auto-load disabled the package never calls `loadMigrationsFrom`; your published copies are the sole source of truth. Do not set this flag to `false` without also publishing the migrations, or the tables will never be created on a fresh install.

#### What does not change

| Area | Status |
|---|---|
| Migration history (`migrations` table) | Unchanged — basenames already recorded |
| Schema — tables, columns, indexes | Unchanged — pure file relocation |
| Framework-default, Passport, and Spatie migrations | Unchanged — remain in your app |
| Permission keys, route names, API envelope | Unchanged |

---

### Domain runtime layers moved to vendor (Phase 3)

Four domain modules have had their **runtime layer** (Actions, DTOs, Queries, Events, Listeners, Services) moved from `stubs/app/Domain/` into the package (`src/Domain/`, PSR-4 `Lvntr\StarterKit\Domain\`). The consumer-facing surface — Controllers, FormRequests, Models, Vue pages, and route files — stays in your app and is **not affected**.

Affected domains: `ActivityLog`, `Logs`, `Session`, `Media`.

#### What does not change

| Area | Status |
|---|---|
| `App\Domain\<Module>\...` imports in your controllers / providers | Keep working — `class_alias` resolves them to the vendor namespace transparently |
| Existing `app/Domain/{ActivityLog,Logs,Session,Media}/` copies | Preserved, never deleted automatically |
| Controllers, FormRequests, Models, Vue pages, routes | Unchanged — stays in your app |
| `App\Models\User` | Never moved to vendor |
| Kit migrations | Moved to vendor in v13.6.0 (Phase 4) — see above |
| Permission keys, route names, API envelope | Unchanged |

#### New installs (v13.6.0+)

A fresh `sk:install` no longer copies `app/Domain/ActivityLog/`, `app/Domain/Logs/`, `app/Domain/Session/`, or `app/Domain/Media/` into `app/`. These modules' runtime classes run directly from `vendor/lvntr/laravel-starter-kit/src/Domain/`. `App\Domain\<Module>\...` imports in scaffold controllers resolve via `class_alias` — no import changes needed in generated code.

#### Existing installs — upgrade steps

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
```

`sk:update` reports the app copies that are now vendor-resident (informational only — never deleted automatically):

```
 WARN  v13.5.0+: package runtime runs from vendor. The following files still exist in your app:

  • app/Domain/ActivityLog/
  • app/Domain/Logs/
  • app/Domain/Session/
  • app/Domain/Media/

  Deleting these files is optional; vendor copies take precedence.
  See: docs/migrate-existing-project-to-vendor.md
```

No other steps are required. Your app continues to work unchanged.

#### Optional cleanup — reconcile stale app copies

This step is entirely optional and can happen later.

**If you have not customised any of these domain files**, delete the app copies so the vendor versions (via `class_alias`) take over:

```bash
rm -rf app/Domain/ActivityLog/
rm -rf app/Domain/Logs/
rm -rf app/Domain/Session/
rm -rf app/Domain/Media/
```

**If you have customised a domain file**, keep your `app/Domain/<Module>/` directory. The `class_alias` guard detects it and steps aside — your customised version continues to win over the vendor copy. You can delete individual unchanged files while keeping modified ones; the guard operates at the class level.

**Partial reconcile example** — keep a customised Action but delete the rest:

```bash
# Remove everything except your customised file
rm -rf app/Domain/Logs/DTOs/
rm -rf app/Domain/Logs/Events/
rm -rf app/Domain/Logs/Listeners/
rm -rf app/Domain/Logs/Queries/
rm -rf app/Domain/Logs/Services/
# Keep: app/Domain/Logs/Actions/DeleteLogFilesAction.php (customised)
```

#### Session domain — `Authenticatable` decoupling

`PurgeOtherSessionsAction::execute()` now accepts `Illuminate\Contracts\Auth\Authenticatable` instead of the concrete `App\Models\User`. The method uses only `getAuthPassword()` and `getAuthIdentifier()`, which are part of the `Authenticatable` contract. Callers that pass an `App\Models\User` instance are unaffected — `User` implements `Authenticatable`.

No changes to `ProfileController::destroySessions()` or any other call site are required.

#### Logs domain — event listener wiring

The `LogFilesDeleted → LogActivityForLogFilesDeleted` listener is now registered by the vendor `StarterKitServiceProvider` (both the event and the listener are vendor-resident). The fresh-install scaffold's `app/Providers/DomainServiceProvider.php` no longer registers this pair: a `class_alias`'d `App\...::class` literal resolves to the *alias* name at compile time, which never matches the vendor event's runtime class — so an app-side registration would silently drop the "log files deleted" audit entry. **No action needed on a fresh install.** If you upgrade and keep your own `app/Domain/Logs/` copies, your existing `DomainServiceProvider` registration keeps working for them (the vendor registration stays dormant — no double-fire); if you reconcile (delete the app copies), the vendor registration handles the now-vendor dispatch.

---

### Build scripts moved to vendor — consumer wiring update required

`scripts/sk-theme-build.mjs` and `scripts/vite-plugin-sk-theme.mjs` are no longer copied into your app. They now ship inside the package and are resolved from `vendor/lvntr/laravel-starter-kit/resources/js/theme/` — the same mechanism used by `@lvntr/components` and the kit composables.

**New installs** (`sk:install`) are not affected — the scripts are never copied.

**Existing installs** require the manual steps below.

#### Migration steps

1. Update the package:

   ```bash
   composer update lvntr/laravel-starter-kit
   php artisan sk:update
   ```

   `sk:update` removes the old `scripts/sk-theme-build.mjs` and `scripts/vite-plugin-sk-theme.mjs` copies from your app automatically. If you have **not modified** those files, no further action is needed — skip to step 3.

2. **If you modified `scripts/sk-theme-build.mjs` or `scripts/vite-plugin-sk-theme.mjs`** — the files are now vendor-managed. Any local customisations must be moved elsewhere (e.g. a project-local wrapper script that imports and extends the vendor version). Contact the kit maintainer if you need an official override hook.

3. **Update `vite.config.ts`** — change the plugin import from the old local path to the vendor path:

   ```diff
   - import skTheme from './scripts/vite-plugin-sk-theme.mjs';
   + import skTheme from './vendor/lvntr/laravel-starter-kit/resources/js/theme/vite-plugin-sk-theme.mjs';
   ```

   > If you customised `vite.config.ts`, `sk:update` does not overwrite it automatically. Apply this change by hand.

4. **Update `package.json` scripts** — the `theme:build` script must point to the vendor path; the `dev` and `build` scripts no longer need an explicit `node scripts/...` prefix (the `skTheme()` Vite plugin guarantees theme generation inside the Vite lifecycle):

   ```diff
   - "theme:build": "node scripts/sk-theme-build.mjs",
   - "dev": "node scripts/sk-theme-build.mjs && vite",
   - "build": "node scripts/sk-theme-build.mjs && vite build && vite build --ssr",
   + "theme:build": "node vendor/lvntr/laravel-starter-kit/resources/js/theme/sk-theme-build.mjs",
   + "dev": "vite",
   + "build": "vite build && vite build --ssr",
   ```

   > The `skTheme()` plugin generates `_active.css` inside Vite's transform pipeline (`buildStart` / `configureServer` hooks), so the explicit `&&` prefix is no longer required for normal `dev` and `build` runs. Use `npm run theme:build` for manual or CI-only regeneration without a full build.

5. Rebuild:

   ```bash
   npm run build
   ```

#### No visual change

The resolver logic is unchanged — only the file location moves. The generated `_active.css` output is identical.

---

### Theme directory structure flattened — BREAKING

The `themes/` intermediate directory has been removed from both the CSS and JS theme trees. Consumer apps that reference these paths must migrate manually.

| Before | After |
|---|---|
| `resources/css/theme/themes/main/` | `resources/css/theme/main/` |
| `resources/css/theme/themes/custom/` | `resources/css/theme/custom/` |
| `resources/js/theme/themes/` | removed — override is now `resources/js/theme/custom/preset.ts` |

Empty placeholder directories are no longer shipped.

#### Migration steps

1. Run `php artisan sk:update` — this delivers the new `main/` tree under `resources/css/theme/main/`.

2. **Manually delete** the old directories — `sk:update` does not remove them automatically:

   ```bash
   rm -rf resources/css/theme/themes/
   rm -rf resources/js/theme/themes/
   ```

3. Rebuild the theme and assets:

   ```bash
   npm run theme:build && npm run build
   ```

#### If you use `VITE_SK_THEME=custom`

Move your override files to the new locations:

| Before | After |
|---|---|
| `resources/css/theme/themes/custom/` | `resources/css/theme/custom/` |
| `resources/js/theme/themes/custom/preset.ts` | `resources/js/theme/custom/preset.ts` |

After moving, re-run `npm run theme:build && npm run build` to verify.

#### Default theme — no visual change

Projects using the default `VITE_SK_THEME=main` (or no variable set) have no visual change. The generated `_active.css` output is identical after the migration.

---

### Backend vendor move

#### Backend (PHP) — pure move (no stub; resolved via `class_alias`)

| Was (`App\`) | Now (vendor) |
|---|---|
| `App\Support\HtmlSanitizer` | `Lvntr\StarterKit\Support\HtmlSanitizer` |
| `App\Support\TranslatableQueryHelpers` | `Lvntr\StarterKit\Support\TranslatableQueryHelpers` |
| `App\Support\MediaPathGenerator` | `Lvntr\StarterKit\Support\MediaPathGenerator` |
| `App\Support\Scramble\ApiResponseExtension` | `Lvntr\StarterKit\Support\Scramble\ApiResponseExtension` |
| `App\Http\Middleware\AssignTraceId` | `Lvntr\StarterKit\Http\Middleware\AssignTraceId` |
| `App\Http\Middleware\SetLocale` | `Lvntr\StarterKit\Http\Middleware\SetLocale` |
| `App\Http\Middleware\ValidateTurnstile` | `Lvntr\StarterKit\Http\Middleware\ValidateTurnstile` |

`AssignTraceId`, `SetLocale`, and `ValidateTurnstile` are wired by `Lvntr\StarterKit\Bootstrap::middleware()` (already called from your `bootstrap/app.php`), so no bootstrap change is needed. Only `HandleInertiaRequests` stays in the scaffold — it carries app-specific Inertia shared data.

#### Backend (PHP) — vendor + thin `App\` shim (import path unchanged)

| Class | Note |
|---|---|
| `App\Http\Responses\DatatableQueryBuilder` | thin subclass of the vendor builder |
| `App\Rules\HttpsOrLocalhostUrl` | thin subclass |
| `App\Rules\TurnstileRule` | thin subclass |

#### Backend (PHP) — traits (direct vendor import, no alias)

PHP traits **cannot** be resolved with `class_alias()`, so traits do not get the transparent `App\…` fallback that classes get. The kit's traits are imported directly from the vendor namespace:

| Trait | Import |
|---|---|
| `HasTranslatableRules` | `use Lvntr\StarterKit\Support\HasTranslatableRules;` |
| `HasActivityLogging` (since v13.5.0) | `use Lvntr\StarterKit\Traits\HasActivityLogging;` |
| `HasMediaCollections` (since v13.5.0) | `use Lvntr\StarterKit\Traits\HasMediaCollections;` |

The shipped model/request scaffold already imports these from `Lvntr\StarterKit\…`. **If your project still has a local copy of one of these traits (e.g. `app/Support/HasTranslatableRules.php` from an older version) and you delete it, you must first change every `use` statement that references the `App\…` trait to the vendor namespace** — there is no alias to fall back on.

#### What does not change

| Area | Status |
|---|---|
| Existing `app/…` copies of the moved classes | Preserved, not deleted |
| `App\…` **class** imports in your code | Keep working (`class_alias` for pure-moved classes; thin shim for `DatatableQueryBuilder` / Rules) |
| `App\…` **trait** imports (`HasTranslatableRules`, `HasActivityLogging`, `HasMediaCollections`) | No alias — use the vendor namespace (see the traits note above) |
| `@/composables/<name>` import paths | Unchanged |
| Route names, permission keys, API response envelope | Unchanged |
| Migration history | "Nothing to migrate" |

#### Optional cleanup

These steps are entirely optional and can happen later.

**Pure-moved PHP classes** — if you never customised them, delete the orphaned files so the vendor versions (via `class_alias`) take over:

```bash
rm -f app/Support/HtmlSanitizer.php \
      app/Support/TranslatableQueryHelpers.php \
      app/Support/MediaPathGenerator.php \
      app/Support/Scramble/ApiResponseExtension.php \
      app/Http/Middleware/AssignTraceId.php \
      app/Http/Middleware/SetLocale.php \
      app/Http/Middleware/ValidateTurnstile.php
```

> If a previously-published `config/media-library.php` sets `path_generator` to `App\Support\MediaPathGenerator`, it keeps working via the alias. To move fully to the runtime default, delete `config/media-library.php` — the kit then enforces the vendor path generator and `App\Models\Media`.

**Shimmed PHP classes** (`DatatableQueryBuilder`, `HttpsOrLocalhostUrl`, `TurnstileRule`) — leave the thin `App\` subclass in place; it is the supported override point. Delete it only if you switch your imports to `Lvntr\StarterKit\…` directly.

**Traits** (`HasTranslatableRules`, `HasActivityLogging`, `HasMediaCollections`) — if you have a local copy, first switch every `use` statement to the vendor namespace, *then* delete the local file. Skipping the import update breaks the class, because traits have no `class_alias` fallback.

**If you customised any moved class**, keep your `app/…` file: the `class_alias` guard (for pure-moved classes) detects it and steps aside, so your version continues to win. For shimmed classes, customise the shim itself.

#### sk:update output

`sk:update` reports the moved files that still exist in your app (informational only — never deleted automatically):

```
v13.5.0+: package runtime runs from vendor. The following files still exist in your app:

  • app/Http/Middleware/AssignTraceId.php
  • app/Http/Middleware/SetLocale.php
  • app/Http/Middleware/ValidateTurnstile.php
  • app/Support/HtmlSanitizer.php
  • app/Support/TranslatableQueryHelpers.php
  • app/Support/MediaPathGenerator.php
  • app/Support/HasTranslatableRules.php
  • app/Support/Scramble/ApiResponseExtension.php
  • config/media-library.php
  • config/activitylog.php
  • config/inertia.php
```

(The v13.5.0 vendor-resident files — `app/Domain/FileManager/`, `app/Domain/Shared/`, etc. — also appear in the list if still present.)

#### New install (v13.6.0+)

A fresh `sk:install` no longer copies these helper classes, middleware, traits, or the three third-party configs into `app/` / `config/`. They run from `vendor/lvntr/laravel-starter-kit/src/` plus the kit's runtime config overrides. The scaffold still ships the thin `App\` shims for `DatatableQueryBuilder` and the two validation Rules so generated domain code keeps its familiar imports; trait helpers (`HasTranslatableRules`) are imported directly from the vendor namespace.

---

### Third-party configs

`config/activitylog.php`, `config/inertia.php`, and `config/media-library.php` are no longer copied into your app. The kit applies only the overrides it requires at runtime via `StarterKitServiceProvider::applyVendorConfigDefaults()`:

- `media-library.path_generator` → vendor `MediaPathGenerator`, and `media-library.media_model` → `App\Models\Media` (when that model exists) — **required for File Manager Trash / soft-deletes**.
- `activitylog.include_soft_deleted_subjects` → `true`
- `inertia.ssr.enabled` → `env('INERTIA_SSR_ENABLED', false)`

Each override is **skipped if you publish your own copy** of that config — publishing remains the escape hatch for full control. Use the upstream package's own publish tag, e.g. `php artisan vendor:publish --tag=medialibrary-config`.

> The previous installer behaviour that AST-injected `App\Support\MediaPathGenerator` into a published `config/media-library.php` has been removed; the path generator is now set at runtime.

If you already published any of these configs, your file wins and the runtime override is skipped — no action required.

---

### Frontend composables

15 composables (`useApi`, `useCan`, … `useUrlTab`) run from vendor; `@/composables/<name>` resolves local-first then vendor. `useAdminMenu.ts` and `index.ts` stay as editable stubs.

`TurnstileWidget.vue` now ships at `@lvntr/components/ui/TurnstileWidget.vue`.

---

### Page-switch loading overlay (opt-in)

This release ships `SkPageLoader` — an animated full-screen page-switch loader at `@lvntr/components/ui/SkPageLoader.vue`, driven by the `usePageLoading` composable (Inertia router events with an anti-flicker delay) and styled by `theme/main/components/page-loader.css`. Both the CSS slot and the composable are delivered by `sk:update`, and the CSS is already imported in the generated `_active.css`. The brand word animates letter-by-letter and follows the active accent + theme (see [theme.md](./theme.md) — Accent color system).

**It is opt-in — the shipped scaffold does not mount it.** No layout in the v13.6.0 scaffold renders `<SkPageLoader/>`, so the loader stays dormant (and its CSS inert) until you wire it. There is no automatic behavior change on upgrade.

To enable it, add the component to the `overlays` slot of your `AdminLayout.vue`, alongside the other global overlays:

```vue
import SkPageLoader from '@lvntr/components/ui/SkPageLoader.vue';

<template #overlays>
    <ConfirmDialogComponent />
    <ToastComponent />
    <AppDialog />
    <ImageLightbox />
    <SkPageLoader :delay="250" />
</template>
```

The loader reads the `sk-layout.loading` translation key for its animated word and honors `prefers-reduced-motion`. Remove the line to turn it off.

---

### Theme / CSS / layout reorganisation

This release reorganises the admin-panel CSS and layout shell. The visual output is **unchanged** — the default build (`VITE_SK_THEME=main`) is byte-identical to v13.5.11. All layout and component class names, token values, and the DOM structure are preserved. What changes is the file layout: styles that were in a monolithic `_admin.scss` and scattered `_*.scss` partials now live in a structured `themes/main/` directory tree, and the layout shell is split into a reusable `AppShell.vue` + a thin `AdminLayout.vue` composition.

The new opt-in **theme-override system** (`themes/custom/`) lets you replace any CSS slot at build time without touching the base theme or the Vue components.

No migration is required for existing projects that only run `composer update` and `npm install`. Manual steps are only needed if you have **hand-edited** any of the moved files.

#### Files that moved (CSS — from `_admin.scss` / `_*.scss` partials)

| Removed | Replaced by |
|---|---|
| `resources/css/theme/_admin.scss` | `themes/main/layout/{shell,sidebar,header,page-header,footer}.css` |
| `resources/css/theme/_datatable.scss` | `themes/main/components/datatable.css` |
| `resources/css/theme/_formbuilder.scss` | `themes/main/components/formbuilder.css` |
| `resources/css/theme/_dialog.scss` | `themes/main/components/dialog.css` |
| `resources/css/theme/_toast.scss` | `themes/main/components/toast.css` |
| `resources/css/theme/_tag.scss` | `themes/main/components/tag.css` |
| `resources/css/theme/_card.scss` | `themes/main/components/card.css` |
| `resources/css/theme/_editor.scss` | `themes/main/components/editor.css` |
| `resources/css/theme/_tabs.scss` | `themes/main/components/tabs.css` |
| `resources/css/theme/_menus.scss` | `themes/main/components/menus.css` |
| `resources/css/theme/_navigation.scss` | `themes/main/components/navigation.css` |
| `resources/css/theme/_confirm.scss` | `themes/main/components/confirm.css` |
| `resources/css/theme/_primevue.scss` | `themes/main/components/primevue.css` |
| `:root` / `.dark` blocks in `_base.scss` | `themes/main/tokens.css` |
| `theme.css` (explicit slot imports) | `theme.css` (single `@import './_active.css'`) |

#### Files that moved (CSS — cascade layer completion)

| Removed | Replaced by |
|---|---|
| `resources/css/theme/fonts.css` | `themes/main/fonts.css` |
| `resources/css/theme/_base.scss` | `themes/main/_base.scss` |
| `resources/css/theme/_auth.scss` | `themes/main/_auth.scss` |
| `resources/css/theme/utilities.css` | `themes/main/utilities.css` |

#### Files that changed (import cleanup)

| File | Change |
|---|---|
| `theme/theme.css` | Fixed `_auth.scss` import removed — resolver emits it. Now contains only `@import './_active.css'`. |
| `app.css` | Fixed `utilities.css` tail import removed — resolver emits it as the last slot. |

#### Files that moved (layout)

`resources/js/layouts/AdminLayout.vue` is now a thin composition around the new `resources/js/layouts/AppShell.vue`. The external prop/slot contract (`title`, `subtitle`, `backUrl`, `default`, `page-actions`) is **identical** — no page needs to change its import or template.

#### New files

| File | Purpose |
|---|---|
| `resources/js/layouts/AppShell.vue` | Reusable structural shell (sidebar state, responsive margins, named regions) |
| `resources/css/theme/themes/main/` | Built-in base theme (source of truth for all CSS slots) |
| `resources/css/theme/themes/custom/` | Empty CSS override theme skeleton (see `themes/custom/README.md` in that directory) |
| `scripts/sk-theme-build.mjs` | CSS theme resolver — writes `_active.css`; called explicitly by `dev` and `build` |
| `resources/js/theme/themes/custom/` | Empty PrimeVue preset override skeleton — ships with `.gitkeep` and a `README.md` explaining the override recipe |
| `scripts/vite-plugin-sk-theme.mjs` | Vite plugin — generates `_active.css` inside Vite's lifecycle and resolves `@/theme/preset` to the active theme's preset at build time |

#### Generated artifact

`resources/css/theme/_active.css` is produced by `scripts/sk-theme-build.mjs`. It is:

- Listed in `.gitignore` — never committed.
- Regenerated on every `npm run dev` and `npm run build` — the resolver is called explicitly in both scripts (not via npm lifecycle hooks, so it works correctly under `ignore-scripts=true`).
- Not hash-tracked by `sk:update`.

#### `.env.example` — new key

```dotenv
VITE_SK_THEME=main
```

Add this line to your `.env` and `.env.example` if it is not already present. The default is `main`; omitting the variable has the same effect.

#### `package.json` — resolver chained into `dev` and `build`

The `dev`, `build`, and `theme:build` scripts were updated so the resolver runs explicitly as part of the chain:

```json
"theme:build": "node scripts/sk-theme-build.mjs",
"dev": "node scripts/sk-theme-build.mjs && vite",
"build": "node scripts/sk-theme-build.mjs && vite build && vite build --ssr",
```

If you manage your own `package.json`, update `dev` and `build` to match this pattern. The resolver must be an explicit `&&` step — **do not use `predev` / `prebuild` lifecycle hooks**, as npm silently skips them when `ignore-scripts=true` (a common security setting in consumer projects and CI), which causes `_active.css` to be absent and the build to fail. `npm run theme:build` is available for generating the file on demand without a full build.

#### If you have customised any moved CSS file

**Moved from flat `_*.scss` partials:**

1. Run `php artisan sk:update` — it will report a hash difference for the moved files.
2. Copy your customisations into the corresponding `themes/main/` file (see the tables above).
3. If your changes are extensive, consider placing them in `themes/custom/` instead (see `docs/theme.md` — custom override recipe).
4. Run `npm run build` to verify.

**Moved cascade-layer files (`fonts.css`, `_base.scss`, `_auth.scss`, `utilities.css`):**

1. Run `php artisan sk:update` — it will report a hash difference for the moved files.
2. Copy your customisations into the corresponding `themes/main/` file (see the cascade-layer table above).
3. If your changes are theme-specific, consider placing them in `themes/custom/` instead (e.g. `themes/custom/fonts.css`). See `docs/theme.md` — Complete slot reference.
4. Run `npm run build` to verify.

**If you have customised `AdminLayout.vue`:**

`sk:update` will report a hash difference. The new file is a thin composition around `AppShell`. Apply your customisations to the new version — the external contract (props, slots) is unchanged, so page-level templates need no edits.

#### Orphaned files (safe to delete)

`sk:update` adds the new `themes/main/` files but does not remove the old flat-path copies already on disk. After upgrading, these files are no longer imported by anything and may be deleted to keep the tree clean — leaving them is harmless (nothing imports them):

- `resources/css/theme/fonts.css`
- `resources/css/theme/_base.scss`
- `resources/css/theme/_auth.scss`
- `resources/css/theme/utilities.css`

#### PrimeVue preset — no action required

The PrimeVue preset resolver is fully additive and backward-compatible:

- `resources/js/theme/preset.ts` **stays where it is** — the kit never moves it.
- `app.ts` continues to import `@/theme/preset` without any change.
- With `VITE_SK_THEME=main` (or no variable set), the build resolves `@/theme/preset` to the base `preset.ts` — byte-identical behaviour to the previous version.
- The `themes/custom/preset.ts` override only takes effect when `VITE_SK_THEME=custom` **and** you have created the file. An absent file falls back to the base.

Existing consumers who have customised `preset.ts` continue using their customised file. The resolver does not interfere. To give a custom theme its own PrimeVue palette, see `docs/theme.md` — PrimeVue preset layer.

#### No visual change

The reorganisation is purely structural. With `VITE_SK_THEME=main` (the default), the generated `_active.css` imports exactly the same CSS rules in the same order as the v13.5.11 output. Token values (light and dark), class names, and DOM structure are identical.

---

### Permission plugin

The `v-can` / `v-role` permission directive plugin (`resources/js/plugins/permission.ts`) now resolves from the vendor package by default, mirroring how kit composables already work. Your `app.ts` import of `@/plugins/permission` is unchanged; it falls back to the vendor copy when no local file exists. **No behavior change** — the directives are identical.

No migration is required. Existing projects keep their local `resources/js/plugins/permission.ts`, which continues to shadow the vendor copy, so nothing breaks.

#### What changed

| File | Change |
|---|---|
| `resources/js/plugins/permission.ts` | Now provided from vendor. Dead `useCan()` export removed (use `@/composables/useCan`); only `PermissionPlugin` (`v-can` / `v-role`) ships. |
| `vite.config.ts` | New `@/plugins/*` resolver — local copy first, vendor fallback — mirroring `@/composables/*`. |
| `tsconfig.json` | New `@/plugins/*` path mapping. |

#### Your local copy is now optional

`sk:update` no longer ships `resources/js/plugins/permission.ts` as a stub, but it does **not** delete the copy already in your project. That local copy keeps working — it shadows the vendor version. You can:

- **Delete it** to adopt the vendor-managed version (recommended if you never edited it): `rm resources/js/plugins/permission.ts`.
- **Keep it** to stay pinned to your copy, or to customise the directives.

To recreate an editable copy later, run `php artisan sk:publish --tag=plugins` — it shadows the vendor version again.

---

### Security Settings redesign — password policy enforcement

This release redesigns **Settings → Security** and adds full server-side enforcement of password rules and password expiry.

#### New migration — `users.password_changed_at`

A nullable `timestamp` column is added to the `users` table. Existing rows are back-filled with `now()` at migration time so no user is immediately treated as expired on deployment.

```bash
php artisan migrate
```

#### New `auth.*` setting keys

Six keys are added to the `auth` group. All have backward-compatible fallbacks so existing installations are not affected when they upgrade without seeding.

| Key | Runtime fallback (key absent from DB) | Seeder (fresh install) |
|---|---|---|
| `auth.login_throttle` | `'1'` (throttle already active) | `'1'` |
| `auth.password_min_length` | `10` | `'10'` |
| `auth.password_expiry_days` | `0` (no expiry) | `'0'` |
| `auth.password_require_mixed_case` | `'1'` | `'1'` |
| `auth.password_require_numbers` | `'1'` | `'1'` |
| `auth.password_require_symbols` | `'1'` | `'1'` |

**Existing installs:** `sk:update` delivers the updated `_03_SettingSeeder.php`. Seeding is optional; without seeding the runtime fallbacks above apply — behaviour does not change (the fallbacks match the pre-feature hardened baseline).

**New installs:** run the seeder as part of `sk:install` to apply the recommended defaults:

```bash
php artisan db:seed --class=_03_SettingSeeder
```

#### Login throttle toggle

`auth.login_throttle = '0'` disables the Fortify login rate limiter at runtime. The default is `'1'` (throttle active). Disabling throttle is a deliberate security downgrade; the setting is exposed only to administrators.

#### Password policy enforcement

When the password policy settings are configured, the `PasswordValidationRules` trait applies them to every new password — registration, password reset, password confirmation, and profile update. Rules apply only to newly submitted passwords; existing stored passwords are not invalidated.

| Setting | Effect when enabled |
|---|---|
| `password_min_length` | Enforces `Password::min(n)` |
| `password_require_mixed_case` | Enforces `->mixedCase()` |
| `password_require_numbers` | Enforces `->numbers()` |
| `password_require_symbols` | Enforces `->symbols()` |

When no policy is configured (all fallbacks), the behaviour is equivalent to the previous `Password::default()` setup.

#### Password expiry middleware (`EnsurePasswordNotExpired`)

When `auth.password_expiry_days > 0`, authenticated users whose `password_changed_at` is older than the configured number of days are redirected to a dedicated, guest-style password-expired screen (`Auth/PasswordExpired.vue`, route `password.expired`) until they update their password. The screen mirrors the login / reset-password layout — no sidebar or panel chrome — and bounces the user to the dashboard once the password is current again.

Exempt routes (redirect loop cannot occur):

- the password-expired page (`password.expired`, the redirect target)
- logout
- two-factor challenge
- Fortify password endpoints

`password_changed_at = null` is treated as exempt (in practice it does not occur after migration back-fill).

The middleware is registered in the `web + auth` middleware group via the stub's `routes/web.php` — it wraps the authenticated panel route group defined there. If you have customised `routes/web.php` (i.e. `sk:update` does not touch it), add `EnsurePasswordNotExpired` to the auth group manually:

```php
use App\Http\Middleware\EnsurePasswordNotExpired;

Route::middleware(['auth', 'verified', EnsurePasswordNotExpired::class])->group(function () {
    // your authenticated routes
});
```

#### SecurityTab customisation

If you have customised `resources/js/pages/Admin/Settings/components/SecurityTab.vue`, run `sk:update --dry-run` to see the diff and merge your changes into the new three-sub-tab structure. The update will be flagged as a hash mismatch — apply it manually.

#### What does not change

| Area | Status |
|---|---|
| Existing `auth.*` setting keys (`registration`, `email_verification`, `password_reset`, `two_factor`) | Unchanged |
| `UpdateAuthSettingsRequest` — old four-field POST | Still accepted; new fields are all `sometimes` |
| Login throttle default | Still active (`'1'`) — no behaviour change on upgrade |
| Existing users' passwords | Never invalidated by a policy change |
| API response envelope | Unchanged |

---

## v13.5.0 → v13.5.3

### Summary

This release adds `sk:doctor` / System Health dashboard, Signed Share Links for File Manager, Bulk Action API hardening, and the API Client UI. New migrations, config keys, and permissions are required.

### Upgrade steps

**1. Update the package:**

```bash
composer update lvntr/laravel-starter-kit
```

**2. Publish and run new migrations:**

```bash
php artisan vendor:publish --tag=starter-kit-migrations
php artisan migrate
```

New migration: `file_manager_share_revocations` table (required for Signed Share Link revocation).

**3. Update File Manager config (new `share.*` keys):**

```bash
php artisan vendor:publish --tag=starter-kit-config --force
```

The following keys are added to `config/file-manager.php`: `share.enabled`, `share.default_ttl_hours`, `share.max_ttl_hours`, `share.allow_revoke`. Existing keys are not affected.

**4. Publish new stubs (caution: customised stubs will be overwritten — diff first):**

```bash
php artisan vendor:publish --tag=starter-kit-stubs --force
```

**5. Seed new permissions and reset the cache:**

```bash
php artisan db:seed --class=PermissionResourcesSeeder
php artisan permission:cache-reset
```

New permissions: `system.health.view`, `share-media`, `revoke-share-media`, `api-clients.create`, `api-clients.read`, `api-clients.update`, `api-clients.delete`, `api-tokens.create`, `api-tokens.read`, `api-tokens.delete`

### Behaviour changes

- **Passport client UI** — `confidential=false` authorization-code clients can no longer be created through the UI. Existing DB records are not affected.
- **Personal Access Token mint** — the `user_id` body field has been removed. To mint a PAT on behalf of another user, use an artisan command.
- **`AppServiceProvider` stub** — remove the duplicate Passport scope / `Gate::before` block if present; `StarterKitServiceProvider` continues to register them.
- **`BulkActionRequest`** — IDs are now validated as `string|min:1|max:64`. Existing integer-only bulk actions are not affected.

---

## v13.4.x → v13.5.0

### Summary

In this release the package runtime was moved to vendor. Your existing files in `app/` **are not affected** and continue to work as-is. `composer update` is the only required step.

### Upgrade steps

```bash
composer update lvntr/laravel-starter-kit
php artisan migrate
```

`php artisan migrate` returns "Nothing to migrate" because your existing migration history exactly matches this release's vendor migration files.

#### Optional steps

```bash
# Regenerate Wayfinder typed route files (no diff expected)
php artisan wayfinder:generate

# Check for stub updates (reports if a hash changed; never forces)
php artisan sk:update --dry-run
```

### What does not change

| Area | Status |
|------|--------|
| `app/Domain/FileManager/` files | Preserved, not deleted |
| `app/Domain/Shared/` files | Preserved, not deleted |
| `app/Traits/HasActivityLogging.php` | Preserved |
| `app/Traits/HasMediaCollections.php` | Preserved |
| `app/Helpers/sk-helpers.php` | Preserved; your functions take precedence |
| `app/Http/Responses/ApiResponse.php` | Preserved |
| `app/Http/Middleware/CheckResourcePermission.php` | Preserved |
| Route names (`file-manager.*`) | All 19 route names unchanged |
| Migration history | "Nothing to migrate" |
| Config keys (`starter-kit.*`, `file-manager.*`) | Existing keys preserved |
| Frontend `@lvntr` alias | Untouched |
| Permission keys (`files.read`, `files.update`, etc.) | Unchanged |
| API response envelope (`success`, `status`, `message`, `data`) | Unchanged |

### Optional cleanup

#### Backend files (vendor migration)

Files such as `app/Domain/FileManager/` and `app/Domain/Shared/` now also run from vendor. If you want to remove them from your app and rely on the vendor version, see the step-by-step guide at:

`docs_project/migrate-existing-project-to-vendor.tr.md` (in the application worktree)

This step is entirely optional and does not need to happen right away.

#### Frontend (switch to vendor symlink)

If `resources/js/components/Lvntr-Starter-Kit/` is still in your app and you have no custom modifications, you can switch to the vendor frontend:

1. **Vite alias** — point the `@lvntr/components` alias at the vendor path in `vite.config.ts`:

   ```ts
   '@lvntr/components': path.resolve(__dirname, 'vendor/lvntr/laravel-starter-kit/resources/js/components/Lvntr-Starter-Kit'),
   ```

   Add the vendor path to the `Components({ dirs })` plugin array:

   ```ts
   dirs: [
     'resources/js/components',
     'vendor/lvntr/laravel-starter-kit/resources/js/components/Lvntr-Starter-Kit',
   ],
   ```

   Make sure `preserveSymlinks: true` is set.

2. **Delete the app copy**:

   ```bash
   rm -rf resources/js/components/Lvntr-Starter-Kit
   ```

3. **Build smoke test**:

   ```bash
   npm run build
   ```

   Should exit 0.

If you have customised components, do not delete them — keep your app-specific components under `resources/js/components/<X>` while importing from the vendor lib.

#### sk:sync deprecation

`php artisan sk:sync` is deprecated. It was never needed for the composer path-repository (symlink) workflow. The `--force` flag preserves the old behaviour but is not recommended.

### sk:update output

From this release onwards, `sk:update` no longer copies runtime files that have moved to vendor. The output will include an informational message similar to:

```
[INFO] v13.5.0+: The following files now run from vendor.
       Deleting them is optional:
         app/Domain/FileManager/
         app/Domain/Shared/{Actions,Contracts,DTOs,Pipelines}
         app/Traits/HasActivityLogging.php
         app/Traits/HasMediaCollections.php
         app/Helpers/sk-helpers.php
         app/Http/Responses/ApiResponse.php
         app/Http/Middleware/CheckResourcePermission.php
         app/Http/Middleware/SecurityHeaders.php
         app/Exceptions/ApiException.php
         app/Exceptions/ApiExceptionHandler.php
         app/Http/Controllers/FileManagerController.php
         app/Http/Requests/FileManager/
         app/Console/Commands/PurgeFileManagerTrash.php
```

Hash-tracked stubs (auth / layout / user / role / settings / config) retain the existing diff / notification behaviour.

### New install (v13.5.0+)

On a fresh project, `php artisan sk:install` no longer copies `app/Domain/FileManager/`, `app/Domain/Shared/`, `app/Traits/`, `app/Helpers/sk-helpers.php`, `app/Http/Responses/ApiResponse.php`, or `app/Http/Middleware/CheckResourcePermission.php` into `app/`. These modules run directly from `vendor/lvntr/laravel-starter-kit/src/`.

Files published to the application: auth / layout Vue components, User / Role / Setting domain scaffold, config files, single-line route stubs.

---

## v13.4.8 → v13.4.9

See [CHANGELOG.md](../CHANGELOG.md#13490---2026-05-02).

Quick upgrade:

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
php artisan migrate
npm install
npm run build
```

---

## v13.4.x → v13.4.10

See [CHANGELOG.md](../CHANGELOG.md#134100---2026-05-04).

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
php artisan migrate
npm install
npm run build
```

---

## 13.4.0 → 13.4.1 — API response hardening + Postman/Apidog sync + OAuth UUID fix

> **Summary:** This patch bundles the end-to-end API response envelope rework (trace-id pipeline, centralised exception handler, leak-closing controller patches) with two new API client integrations (Postman + Apidog sync) and a pair of install-time fixes (OAuth migrations made UUID-compatible, `site:install` now provisions the Passport personal access client automatically). Most changes are **additive** (new body fields / headers, new admin buttons), but **three behavioural API-response breaks** matter — they can affect UI toast copy and strict client schemas: `abort()` raw-message whitelist + `ModelNotFoundException` message format + `Api/AuthController` raw User → `UserResource`.

### 0. Who is affected?

| Audience | Action |
| --- | --- |
| Fresh installs (`composer create-project` + `sk:install`) | Nothing — stubs already carry 13.4.1. |
| Teams running `sk:update` regularly | `composer update` + `php artisan sk:update`. `ApiResponse`, `ApiExceptionHandler`, `AssignTraceId`, `sk-helpers.php` are carried automatically; **controllers are manual** (Step 4). |
| Projects with customised controllers | Apply the Step 4 patches by hand — especially the `catch (LogicException $e) → throw ApiException::...` pattern flip. |
| Package `src/`-only consumers (never published) | `composer update lvntr/laravel-starter-kit` is enough; `Bootstrap` registers the middleware for you. |
| Anyone with their own `app/Http/Middleware/AssignTraceId.php` | Class name collision — either accept the package stub or rename your class. |

### 1. Pre-upgrade checklist

1. **Branch + backup:** `git checkout -b upgrade/v13.4.1 && git push`
2. **Notify frontend/mobile:** additive fields (body `trace_id`, header `X-Request-ID`, echoed `X-Correlation-ID`, `Retry-After` on 429) are being introduced; strict-schema clients should register them.
3. **QA:** if your UI surfaces error messages as toasts, run a short QA pass for the **behavioural breaks in Step 2** (abort() messages, model-not-found format, auth me/login payload).
4. **Sanity check:** `composer test` + `npm run build` green on the current version?

### 2. Behavioural breaking changes

Status codes unchanged; envelope field list unchanged; only `message` text and the `data.user` shape under auth endpoints may change.

#### 2.1 `abort($code, 'custom message')` no longer leaks the message

```diff
- // Before: body.message = "SQL error: table users missing col xyz"
- abort(400, 'SQL error: table users missing col xyz');
+ // Now: body.message = "Bad request."  (the internal detail is dropped)
+ abort(400, 'SQL error: ...');   // That message never reaches the client.
```

**Why:** the `HttpExceptionInterface` branch now uses the fixed `defaultMessageForStatus()` table instead of `$e->getMessage()` (K3). Internal messages land in `debug.message` when `APP_DEBUG=true`.

**Migration:** for controlled user-facing messages use the curated API exception instead:

```php
// Old
abort(400, 'Invalid coupon code.');

// New (routed through the handler — trace_id + correlation headers attached)
throw \App\Exceptions\ApiException::badRequest('Invalid coupon code.');
```

#### 2.2 `ModelNotFoundException` message now embeds the model name

```diff
- body.message: "The requested resource was not found."
+ body.message: "User not found."          // or Role, Product, …
```

**Why:** `ApiExceptionHandler::modelNotFoundMessage` now resolves via `class_basename($e->getModel())` (K4 — matches the prior AGENTS.md contract). No security impact: the model class name is already inferable from the URL.

**Migration:** if frontend code regex-matches the message, loosen the pattern (`/(not found|bulunamadı)/i`) or branch on status code (404).

#### 2.3 `Api/AuthController` raw User → `UserResource`

```diff
  POST /api/v1/auth/login (default kind)
  POST /api/v1/auth/register (no-verification path)
  POST /api/v1/auth/two-factor-challenge
  GET  /api/v1/auth/me

- data.user: {
-     id: 1, first_name: "...", email: "...",
-     status: "active", email_verified_at: "...",
-     two_factor_confirmed_at: null,
-     avatar_url: "...", created_at: "...", updated_at: "..."
- }
+ data.user: <UserResource::toArray() output, app/Http/Resources/Admin/User/UserResource.php>
```

**Why:** raw Eloquent serialisation relied on `$hidden`; if a future sensitive column was added and forgotten, it would silently leak. `UserResource` makes the wire contract explicit.

**Migration:** inspect the fields returned by `UserResource` (`app/Http/Resources/Admin/User/UserResource.php`). If you depend on a raw-model field not declared in the resource, either extend `UserResource` or introduce a dedicated `AuthUserResource` used by `AuthController`.

### 3. Package update

```bash
composer update lvntr/laravel-starter-kit --with-all-dependencies
php artisan sk:update              # auto: ApiResponse + ApiExceptionHandler + sk-helpers + AssignTraceId
npm install                         # no JS changes, but keep it in the routine
```

`sk:update` auto-syncs:
- `app/Http/Responses/ApiResponse.php`
- `app/Exceptions/ApiExceptionHandler.php`
- `app/Helpers/sk-helpers.php`
- `app/Http/Middleware/AssignTraceId.php` (**new** — created if missing)
- `app/Http/Middleware/SecurityHeaders.php` (unchanged this release but tracked)

> **Important:** if `AssignTraceId.php` is missing after `sk:update`, the package-level `Bootstrap::middleware()` references `App\Http\Middleware\AssignTraceId` and **the first API request throws ClassNotFoundException**. A successful `sk:update` fixes this; to verify: `ls app/Http/Middleware/AssignTraceId.php`.

### 4. Manual controller patches (for published customisations)

`sk:update` never overwrites controllers (most projects add custom methods). Clean up the 11 leak sites by hand. The pattern is uniform:

```diff
- catch (LogicException $e) {
-     return to_api(null, $e->getMessage(), 422);
- }
+ catch (LogicException $e) {
+     throw \App\Exceptions\ApiException::unprocessable($e->getMessage());
+ }
```

**Affected files:**

| File | Method / count |
|---|---|
| `app/Http/Controllers/FileManagerController.php` | `bulkDelete`, `createFolder`, `renameFolder`, `moveItem`, `deleteFolder`, `upload`, `deleteFile` — 7 sites |
| `app/Http/Controllers/Api/UserController.php` | `destroy` — `to_api(null, 'Unauthenticated.', 401)` → `throw ApiException::unauthorized()`; `to_api(null, $e->getMessage(), 400)` → `throw ApiException::badRequest(...)` |
| `app/Http/Controllers/Api/Auth/AuthController.php` | `login` — `to_api(null, 'Invalid email or password.', 401)` → `throw ApiException::unauthorized(...)`; `twoFactorChallenge` — same for "Invalid or expired two-factor code." |

Remember to add `use App\Exceptions\ApiException;` at the top of each touched controller. Finally, in destroy-style methods move `return to_api(status: 204);` **outside** the `try` block (Step 2 exit-flow change):

```diff
- try {
-     $action->execute($user, (string) $performedById);
-     return to_api(status: 204);
- } catch (\LogicException $e) {
-     return to_api(null, $e->getMessage(), 400);
- }
+ try {
+     $action->execute($user, (string) $performedById);
+ } catch (\LogicException $e) {
+     throw ApiException::badRequest($e->getMessage());
+ }
+
+ return to_api(status: 204);
```

### 5. Api/AuthController UserResource migration (if published)

To adopt the Step 2.3 behaviour, patch `Api/Auth/AuthController.php`:

```diff
 use App\Domain\Auth\Actions\TwoFactorChallengeAction;
 use App\Domain\Auth\DTOs\LoginDTO;
 use App\Domain\Auth\DTOs\RegisterDTO;
+use App\Exceptions\ApiException;
 use App\Http\Controllers\Controller;
 use App\Http\Requests\Api\Auth\LoginRequest;
 use App\Http\Requests\Api\Auth\RegisterRequest;
 use App\Http\Requests\Api\Auth\TwoFactorChallengeRequest;
+use App\Http\Resources\Admin\User\UserResource;
 use App\Http\Responses\ApiResponse;

 public function register(...): ApiResponse
 {
     $result = $action->execute(...);
+    $userPayload = new UserResource($result['user']->loadMissing('roles'));

     if ($result['requires_verification']) {
         return to_api(
-            ['user' => $result['user'], 'requires_verification' => true],
+            ['user' => $userPayload, 'requires_verification' => true],
             'Registration successful. ...',
             201,
         );
     }

-    return to_api($result, 'Registration successful.', 201);
+    return to_api(
+        ['user' => $userPayload, 'token' => $result['token'], 'requires_verification' => false],
+        'Registration successful.',
+        201,
+    );
 }

 // login default branch
-    default => to_api(
-        ['user' => $result['user'], 'token' => $result['token']],
-        'Login successful.',
-    ),
+    default => to_api(
+        [
+            'user' => new UserResource($result['user']->loadMissing('roles')),
+            'token' => $result['token'],
+        ],
+        'Login successful.',
+    ),

 // me
-    return to_api($request->user());
+    return to_api(new UserResource($request->user()->loadMissing('roles')));

 // twoFactorChallenge
-    return to_api($result, 'Login successful.');
+    return to_api(
+        [
+            'user' => new UserResource($result['user']->loadMissing('roles')),
+            'token' => $result['token'],
+        ],
+        'Login successful.',
+    );
```

### 6. MakeDomainCommand scaffold (if published)

If `app/Console/Commands/MakeDomainCommand.php` was published, two spots need the new scaffold template:

```diff
 use {$dtoNamespace}\\{$this->dn}DTO;
+use App\Exceptions\ApiException;
 use App\Http\Controllers\Controller;
 ...

 public function destroy({$this->dn} \${$v}, Delete{$this->dn}Action \$action): ApiResponse|JsonResponse
 {
     try {
         \$action->execute(\${$v});
-
-        return to_api(status: 204);
     } catch (\LogicException \$e) {
-        return to_api(null, \$e->getMessage(), 400);
+        throw ApiException::badRequest(\$e->getMessage());
     }
+
+    return to_api(status: 204);
 }
```

If your `tests/Feature/Console/MakeDomainCommandTest.php` asserts the scaffold output, update it:

```diff
 expect(file_get_contents(app_path("Http/Controllers/Api/{$domain}Controller.php")))
-    ->toContain('return to_api(null, $e->getMessage(), 400);');
+    ->toContain('throw ApiException::badRequest($e->getMessage());');
```

### 7. Install-time fixes (OAuth + Postman settings + Passport personal client)

These three chores apply to **any existing install** that was seeded before 13.4.1. They are orthogonal to the API response work — run them after `sk:update` whether or not you published controllers.

#### 7.1 OAuth migrations made UUID-compatible

Three Passport migrations now use `foreignUuid` / `nullableUuidMorphs` instead of the default `foreignId` / `nullableMorphs`. This matches the `char(36)` primary key on `users.id` that the starter kit ships. Without this patch the API login path fails with `SQLSTATE 1265: Data truncated for column 'user_id'` the first time Passport tries to insert an access token.

Fresh installs pick this up automatically via `site:install`. For **existing installs**, re-run the three migrations against live data:

```bash
# 1. Roll back the three migrations (data-loss safe — oauth_* tables
#    are rebuilt on every token issue):
php artisan migrate:rollback --path=database/migrations/2026_03_04_205119_create_oauth_auth_codes_table.php
php artisan migrate:rollback --path=database/migrations/2026_03_04_205120_create_oauth_access_tokens_table.php
php artisan migrate:rollback --path=database/migrations/2026_03_04_205122_create_oauth_clients_table.php

# 2. Re-run with the new schema:
php artisan migrate
```

If you cannot roll back (rows with `char(36)` user ids already exist in a fork of your schema), apply the column change manually:

```sql
ALTER TABLE oauth_access_tokens MODIFY user_id CHAR(36) NULL;
ALTER TABLE oauth_auth_codes    MODIFY user_id CHAR(36) NOT NULL;
ALTER TABLE oauth_clients       MODIFY owner_id CHAR(36) NULL;
```

Verify with a login test — see Step 9 (Regression test).

#### 7.2 Postman / Apidog credentials moved from `.env` to the settings table

The earlier preview that wired Postman via three `.env` keys is gone. Configuration now lives in the `postman` / `apidog` settings groups and the `api_key` / `access_token` fields are encrypted at rest through `config/settings.php → sensitive_keys`.

If you had `POSTMAN_API_KEY`, `POSTMAN_WORKSPACE_ID`, or `POSTMAN_COLLECTION_ID` in `.env`, copy them into the settings table once, then delete the `.env` entries:

```bash
php artisan tinker --execute '
use App\Models\Setting;
Setting::setValue("postman.api_key", env("POSTMAN_API_KEY"));
Setting::setValue("postman.workspace_id", env("POSTMAN_WORKSPACE_ID"));
Setting::setValue("postman.collection_id", env("POSTMAN_COLLECTION_ID"));
echo "migrated";
'
```

Then remove the three keys from both `.env` and `.env.example`. The admin UI at **Settings → API Clients → Postman** shows the stored values (secrets are masked); use it to rotate the key later. Apidog is configured the same way at **Settings → API Clients → Apidog** (Access Token + Project ID).

#### 7.3 Passport personal access client (new `site:install` step)

`site:install` now runs `passport:client --personal --provider=users` automatically between `passport:keys` and the admin-user seed. If your existing install never had a personal access client (symptom: `RuntimeException: Personal access client not found for 'users'` on API login), create one once:

```bash
php artisan passport:client --personal --provider=users --name="$(php artisan config:show app.name)" --no-interaction
```

One row lands in `oauth_clients` with `revoked=0`. API token issuance starts working immediately — no app restart needed.

### 8. New additive features — no code changes required

These land automatically and surface new body fields / headers to clients. Loop in the frontend team:

| Feature | Where it appears |
|---|---|
| `trace_id` (UUID) | Every JSON body (success and error), plus `X-Request-ID` response header |
| `X-Correlation-ID` | Echoes a sanitised client-supplied `X-Request-ID` |
| `Retry-After` | Attached to 429 Too Many Requests responses |
| `simplePaginate()` support | `to_api(Model::simplePaginate(...))` works without a type error; meta carries `has_more` |
| "Sync to Postman" button | API Routes page → pushes the current OpenAPI spec to Postman once configured |
| "Sync to Apidog" button | API Routes page → pushes the current OpenAPI spec to Apidog once configured |
| Settings → API Clients tab | Postman + Apidog credentials UI; `postman.api_key` / `apidog.access_token` encrypted at rest |

### 9. Regression test — optional but recommended

The package ships `tests/Feature/Api/ApiResponseTest.php` — a 16-test contract file covering the envelope, exception mapping, trace id, 204, Retry-After, and the debug guard. If you don't already have one, copy the example:

```bash
cp vendor/lvntr/laravel-starter-kit/tests/examples/ApiResponseTest.php \
   tests/Feature/Api/ApiResponseTest.php
php artisan test --compact --filter=ApiResponseTest
```

Expected: 16 tests / 57 assertions pass. If something fails, confirm `AssignTraceId` is active in the `api` middleware group.

### 10. Rollback

If the release is reverted:

```bash
git revert <upgrade-commit>
composer install
php artisan sk:update --force   # restores published files to the previous version
```

`AssignTraceId.php` did not exist in 13.4.x — after rollback either delete it or leave it (no-op) provided the previous `Bootstrap.php` does not reference the class.

---

## 13.3.x → 13.4.0 — Security hardening sprint

> **Summary:** Following a three-pass parallel code review, ~37 findings were closed (HIGH: 13 → 1 manual, MEDIUM: 14, LOW: 4). The bulk of the changes are security (auth bypass, brute-force, XSS, log injection) and data integrity (missing DB transactions). **New installs** pick these fixes up automatically; **existing installs** must apply the patch list in this document.

### 0. Who is affected?

| Audience | What to do |
| --- | --- |
| Fresh installs (`composer create-project` + `sk:install`) | Nothing — stubs already carry the new version. |
| Existing consumer apps | Follow **Steps 1–8** in this document. |
| Consumers using only the package `src/` (never published) | `composer update lvntr/laravel-starter-kit` is enough. |

### 1. Pre-upgrade checklist

1. **Branch + backup:** `git checkout -b upgrade/v13.4.0 && git push`
2. **DB backup:** Snapshot / dump before rolling changes to production.
3. **Baseline:** Make sure `composer test` + `npm run build` pass on your current version.
4. **Expect a PR review:** Most of these changes are patch-style edits and deserve a real code review.

### 2. Package update

```bash
composer update lvntr/laravel-starter-kit --with-all-dependencies
npm install
```

This step picks up the Tier-1 changes (those that live inside the package `src/`) automatically:
- `SecurityHeaders` HSTS `preload` directive (`src/Http/Middleware/SecurityHeaders.php`)
- `MakeDomainCommand` / stub improvements

Everything else lives in published files, so **you must update your own copy** in the app.

---

### 3. HIGH — Security & data integrity patches

Apply these **in order**. Each is independent, but sequential commits keep history clean.

#### 3.1 (BE-H1) `UserPolicy::delete` + `Api\UserController::destroy` null guard

**File:** `app/Policies/UserPolicy.php`

Flip the self-match branch in `delete()`:

```diff
     public function delete(User $actor, User $user): bool
     {
         if ($actor->is($user)) {
-            return true;
+            return false;
         }

         if (! $this->canManage($actor, $user)) {
             return false;
         }

         return $actor->can('users.delete');
     }
```

**File:** `app/Http/Controllers/Api/UserController.php`

Add a null guard to `destroy`:

```diff
     public function destroy(Request $request, User $user, DeleteUserAction $action): ApiResponse|JsonResponse
     {
         Gate::authorize('delete', $user);

+        $performedById = $request->user()?->id;
+        if ($performedById === null) {
+            return to_api(null, 'Unauthenticated.', 401);
+        }
+
         try {
-            $action->execute($user, (string) $request->user()?->id);
+            $action->execute($user, (string) $performedById);
             return to_api(status: 204);
```

**Verify:** `DELETE /api/v1/users/{your_own_id}` must return 403 (policy denies it); an expired token must return 401.

---

#### 3.2 (BE-H2) `CreateRoleAction` + `UpdateRoleAction` DB transaction

**File:** `app/Domain/Role/Actions/CreateRoleAction.php`

```diff
 use App\Models\Role;
 use Illuminate\Support\Facades\Auth;
+use Illuminate\Support\Facades\DB;
 ...
     public function execute(RoleDTO $dto): Role
     {
-        $role = Role::create($dto->toArray());
-        $role->syncPermissions($dto->permissions);
+        $role = DB::transaction(function () use ($dto): Role {
+            $role = Role::create($dto->toArray());
+            $role->syncPermissions($dto->permissions);
+
+            return $role;
+        });

         RoleCreated::dispatch($role, Auth::id());
         return $role;
     }
```

**File:** `app/Domain/Role/Actions/UpdateRoleAction.php`

```diff
 use Illuminate\Support\Facades\Auth;
+use Illuminate\Support\Facades\DB;
 ...
         $oldPermissions = $role->permissions->pluck('name')->sort()->values()->all();

-        $role->update($data);
-        $role->refresh();
-        $role->syncPermissions($dto->permissions);
+        $role = DB::transaction(function () use ($role, $data, $dto): Role {
+            $role->update($data);
+            $role->refresh();
+            $role->syncPermissions($dto->permissions);
+
+            return $role;
+        });
```

---

#### 3.3 (BE-H3) `UpdateAuthSettingsAction` 2FA revoke transaction

**File:** `app/Domain/Setting/Actions/UpdateAuthSettingsAction.php`

```diff
 use App\Models\User;
+use Illuminate\Support\Facades\DB;

 ...
     public function execute(AuthSettingsDTO $dto): void
     {
-        $wasTwoFactorEnabled = Setting::getValue('auth.two_factor', '1') === '1';
-        $isTwoFactorDisabled = $dto->twoFactor === '0';
-
-        Setting::setGroup('auth', $dto->toArray());
-
-        if ($wasTwoFactorEnabled && $isTwoFactorDisabled) {
-            $this->revokeAllTwoFactorAuth();
-        }
+        DB::transaction(function () use ($dto): void {
+            $wasTwoFactorEnabled = Setting::getValue('auth.two_factor', '1') === '1';
+            $isTwoFactorDisabled = $dto->twoFactor === '0';
+
+            Setting::setGroup('auth', $dto->toArray());
+
+            if ($wasTwoFactorEnabled && $isTwoFactorDisabled) {
+                $this->revokeAllTwoFactorAuth();
+            }
+        });
     }
```

---

#### 3.4 (BE-H4) `LogoutUserAction` null-safe

**File:** `app/Domain/Auth/Actions/LogoutUserAction.php`

```diff
     public function execute(User $user): void
     {
-        $user->token()->revoke();
+        $user->token()?->revoke();
     }
```

A single character — but in production a logout request from a user without an active access token currently 500s.

---

#### 3.5 (BE-H5) FileManager N+1 fix

**Files:** `app/Domain/FileManager/Actions/BulkDeleteAction.php` and `DeleteFolderAction.php`.

Replace the `collectDescendantIds` method in both files — the new version loads the owner-scoped `parent_id` map in a single query and walks the tree in PHP. Because the change is large, copy the full new files from `vendor/lvntr/laravel-starter-kit/stubs/app/Domain/FileManager/Actions/BulkDeleteAction.php` and `DeleteFolderAction.php`.

**Highlights:**
- `BulkDeleteAction` gains `buildChildrenMap(FileManagerContextDTO $context): array`. `collectDescendantIds($folder, $childrenByParent)` takes the map as a parameter.
- `DeleteFolderAction::collectDescendantIds` now takes a context parameter and loads every folder row belonging to the owner in a single query.

A 50-level folder tree drops from 50 queries to 1.

---

#### 3.6 (BE-H6) SMTP encryption `'none'` fix

**File:** `app/Providers/SettingsServiceProvider.php`

```diff
             if (array_key_exists('encryption', $mail)) {
-                config(['mail.mailers.smtp.encryption' => $mail['encryption']]);
+                // Laravel's SMTP mailer expects null (not the string "none") to send without TLS.
+                $encryption = $mail['encryption'] === 'none' ? null : $mail['encryption'];
+                config(['mail.mailers.smtp.encryption' => $encryption]);
             }
```

---

#### 3.7 (GV-H2 + GV-H3) `ApiExceptionHandler` — message leak + X-Request-ID injection

**File:** `app/Exceptions/ApiExceptionHandler.php`

Two changes:

**A) Change trace ID generation in `handle()`:**

```diff
     private static function handle(Throwable $e, Request $request): JsonResponse
     {
-        // 1. Trace ID — use client-provided value or generate a new one
-        $traceId = $request->header('X-Request-ID', (string) Str::uuid());
+        // 1. Trace ID — always server-generated to prevent log / header injection.
+        //    Any client-supplied X-Request-ID is accepted as correlation metadata
+        //    only after being sanitised and length-capped.
+        $traceId = (string) Str::uuid();
+        $clientRequestId = self::sanitizeClientRequestId($request->header('X-Request-ID'));

         // 2. Status + Message mapping
         [$status, $message] = self::resolve($e);

         // 3. Logging — 500+ non-validation errors
         if ($status >= 500 && ! ($e instanceof ValidationException)) {
             Log::error("[API {$status}] {$message}", [
                 'trace_id' => $traceId,
+                'client_request_id' => $clientRequestId,
                 'exception' => get_class($e),
                 ...
             ]);
         }
```

**B) Harden the `default` arm in `resolve()` and add the new helper to the class:**

```diff
-            // Unexpected errors
             default => [
                 500,
-                config('app.debug') ? $e->getMessage() : 'A server error occurred.',
+                'A server error occurred.',
             ],
         };
     }

+    /**
+     * Accept a client-provided X-Request-ID only if it matches a safe charset
+     * (letters, digits, dash, underscore, dot) and is ≤ 128 chars long.
+     */
+    private static function sanitizeClientRequestId(mixed $value): ?string
+    {
+        if (! is_string($value) || $value === '') {
+            return null;
+        }
+
+        $trimmed = substr($value, 0, 128);
+
+        return preg_match('/^[A-Za-z0-9._-]+$/', $trimmed) === 1 ? $trimmed : null;
+    }
```

---

#### 3.8 (FE-H1) Axios CSRF defaults

**File:** `resources/js/app.ts`

At the top of the file, right after the imports:

```diff
 import '../css/app.css';
 import 'primeicons/primeicons.css';
 import { createInertiaApp, usePage } from '@inertiajs/vue3';
+import axios from 'axios';
 import { i18nVue } from 'laravel-vue-i18n';
 ...
 import { PermissionPlugin } from '@/plugins/permission';

+// Axios defaults — send session + XSRF cookies on every request so Fortify
+// endpoints that rely on the web session (2FA, sessions, password-confirm)
+// stay CSRF-protected. XSRF cookie/header names match Laravel's defaults.
+axios.defaults.withCredentials = true;
+axios.defaults.xsrfCookieName = 'XSRF-TOKEN';
+axios.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';
+axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
+axios.defaults.headers.common['Accept'] = 'application/json';
```

---

#### 3.9 (FE-H2) `TwoFactorTab` QR SVG XSS fix

**File:** `resources/js/pages/Profile/components/TwoFactorTab.vue` (or legacy path `pages/Profile/TwoFactorTab.vue`)

**A) In `<script setup>` — right below the `qrCodeSvg` ref:**

```diff
     const qrCodeSvg = ref('');
     const setupKey = ref('');
     const recoveryCodes = ref<string[]>([]);
     const showRecoveryCodes = ref(false);

+    /**
+     * Render the Fortify QR SVG through an <img src="data:..."> element
+     * rather than v-html. An <img> sandbox neutralises any inline <script>
+     * or event handlers that a compromised intermediary could smuggle in.
+     */
+    const qrCodeDataUrl = computed<string>(() => {
+        if (!qrCodeSvg.value) return '';
+        try {
+            const encoded = window.btoa(unescape(encodeURIComponent(qrCodeSvg.value)));
+            return `data:image/svg+xml;base64,${encoded}`;
+        } catch {
+            return '';
+        }
+    });
```

**B) In the template — replace the `v-html` block:**

```diff
-                            <!-- eslint-disable vue/no-v-html -- QR SVG from trusted server -->
-                            <div class="inline-block rounded-lg bg-white p-4" v-html="qrCodeSvg" />
-                            <!-- eslint-enable vue/no-v-html -->
+                            <div class="inline-block rounded-lg bg-white p-4">
+                                <img
+                                    v-if="qrCodeDataUrl"
+                                    :src="qrCodeDataUrl"
+                                    :alt="$t('sk-profile.two_factor_scan')"
+                                    class="h-48 w-48"
+                                />
+                            </div>
```

---

#### 3.10 (FE-H3) `useDefinition.load()` error handling

**File:** `resources/js/composables/useDefinition.ts`

Replace both `load()` and `loadAll()` with the new versions from `vendor/lvntr/laravel-starter-kit/stubs/resources/js/composables/useDefinition.ts`. The core change: every `fetch` call is wrapped in `try/catch`, `res.ok` is checked, `loaded.value` stays false on failure, and errors are logged to the console.

---

### 4. MEDIUM — Authorization, performance, UX

#### 4.1 (BE-M1) FormRequest `authorize(): true` cleanup

In the following files, replace `return true;` with the corresponding permission check:

| File | Permission |
| --- | --- |
| `app/Http/Requests/Admin/User/StoreUserRequest.php` | `users.create` |
| `app/Http/Requests/Api/User/StoreUserRequest.php` | `users.create` |
| `app/Http/Requests/Admin/Role/StoreRoleRequest.php` | `roles.create` |
| `app/Http/Requests/Admin/Settings/UpdateAuthSettingsRequest.php` | `settings.update` |
| `app/Http/Requests/Admin/Settings/UpdateGeneralSettingsRequest.php` | `settings.update` |
| `app/Http/Requests/Admin/Settings/UpdateMailSettingsRequest.php` | `settings.update` |
| `app/Http/Requests/Admin/Settings/UpdateStorageSettingsRequest.php` | `settings.update` |
| `app/Http/Requests/Admin/Settings/UpdateFileManagerSettingsRequest.php` | `settings.update` |
| `app/Http/Requests/Admin/Settings/UpdateTurnstileSettingsRequest.php` | `settings.update` |
| `app/Http/Requests/Admin/Settings/SendTestMailRequest.php` | `settings.update` |

```diff
     public function authorize(): bool
     {
-        return true;
+        return $this->user()?->can('users.create') ?? false;
     }
```

(Swap in the right permission name per file.)

Also `app/Http/Requests/DestroySessionsRequest.php`:

```diff
-        return true;
+        return $this->user() !== null;
```

**Leave auth / public endpoints alone:** `Api/Auth/LoginRequest.php`, `RegisterRequest.php`, `TwoFactorChallengeRequest.php` remain public.

**Leave FileManager endpoints alone:** `FileManagerRequest.php` and its subclasses rely on context-based authorization.

---

#### 4.2 (BE-M4) TwoFactorChallenge brute-force hardening

**File:** `app/Domain/Auth/Actions/TwoFactorChallengeAction.php`

Add `Cache::forget($cacheKey)` to all three failure arms — the challenge becomes single-use:

```diff
         if ($code !== null && $code !== '') {
             $valid = $this->provider->verify(...);

             if (! $valid) {
+                Cache::forget($cacheKey);
+
                 return null;
             }
         } elseif ($recoveryCode !== null && $recoveryCode !== '') {
             $match = collect($user->recoveryCodes())->first(...);

             if ($match === null) {
+                Cache::forget($cacheKey);
+
                 return null;
             }

             $user->replaceRecoveryCode($match);
         } else {
+            Cache::forget($cacheKey);
+
             return null;
         }
```

The route-level `throttle:5,1` is already in place.

---

#### 4.3 (BE-M7 + BE-M12) `SettingService` transaction + cache

**File:** `app/Domain/Setting/SettingService.php`

Easiest path: replace the whole file with `vendor/lvntr/laravel-starter-kit/stubs/app/Domain/Setting/SettingService.php`. In summary:

1. `DB` facade import added.
2. `getValue()` and `getGroup()` now read from the `allGrouped()` cache — no per-lookup queries.
3. `setGroup()` is wrapped in `DB::transaction(...)`.

Same behaviour, better performance and atomicity.

---

#### 4.4 (BE-M8) `MoveItemRequest` tighter validation

**File:** `app/Http/Requests/FileManager/MoveItemRequest.php`

```diff
 <?php

 namespace App\Http\Requests\FileManager;

+use Illuminate\Validation\Rule;
+
 class MoveItemRequest extends FileManagerRequest
 {
     public function rules(): array
     {
+        $itemType = $this->input('item_type');
+
+        $itemIdRules = ['required'];
+        if ($itemType === 'file') {
+            $itemIdRules = ['required', 'integer', 'min:1'];
+        } elseif ($itemType === 'folder') {
+            $itemIdRules = ['required', 'uuid'];
+        }
+
         return [
             ...$this->contextRules(),
-            'item_type' => ['required', 'string', 'in:folder,file'],
-            'item_id' => ['required'],
+            'item_type' => ['required', 'string', Rule::in(['folder', 'file'])],
+            'item_id' => $itemIdRules,
             'target_folder_id' => ['nullable', 'uuid'],
         ];
     }
 }
```

---

#### 4.5 (BE-M9) `DeleteFolderRequest` FormRequest

**New file:** `app/Http/Requests/FileManager/DeleteFolderRequest.php`

```php
<?php

namespace App\Http\Requests\FileManager;

class DeleteFolderRequest extends FileManagerRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->contextRules();
    }
}
```

**File:** `app/Http/Controllers/FileManagerController.php`

Add the use statement + change the method signature:

```diff
 use App\Http\Requests\FileManager\BulkDeleteRequest;
+use App\Http\Requests\FileManager\DeleteFolderRequest;
 use App\Http\Requests\FileManager\MoveItemRequest;
 ...

-    public function deleteFolder(Request $request, FileFolder $folder, DeleteFolderAction $action): ApiResponse
+    public function deleteFolder(DeleteFolderRequest $request, FileFolder $folder, DeleteFolderAction $action): ApiResponse
     {
-        $context = $this->contextFromRequest($request);
+        $context = $request->context();
         $this->authorizer->authorizeWrite($context);
```

---

#### 4.6 (BE-M10) `uploadAvatar` Gate::authorize consistency

**File:** `app/Http/Controllers/Admin/UserController.php`

```diff
     public function uploadAvatar(UploadAvatarRequest $request, User $user, UploadMediaAction $action): ApiResponse
     {
+        Gate::authorize('update', $user);
+
         $action->execute($user, $request, 'avatar');
```

---

#### 4.7 (FE-M1) `useDialog` timer leak

**File:** `resources/js/composables/useDialog.ts`

Full version in `vendor/lvntr/laravel-starter-kit/stubs/resources/js/composables/useDialog.ts`. The edits:

1. Module-level `let closeTimer: ReturnType<typeof setTimeout> | null = null;` right below `state`.
2. `open()` starts with `clearTimeout(closeTimer)` + `closeTimer = null`.
3. `close()` does the same clear, then `closeTimer = setTimeout(..., 300)`, and the timeout body sets `closeTimer = null`.

---

#### 4.8 (FE-M2) `useImageLightbox` timer leak

Same pattern as `useDialog`. Copy from `vendor/lvntr/laravel-starter-kit/stubs/resources/js/composables/useImageLightbox.ts`.

---

#### 4.9 (FE-M4) `SkForm` isDirty guard — prevent data loss

**File:** `resources/js/components/Lvntr-Starter-Kit/FormBuilder/SkForm.vue` (if you import the component directly from the package instead, this change arrives via `composer update` — the package source was fixed).

Add the isDirty arm to the `watch(derivedDefaults, …)` block:

```diff
     watch(derivedDefaults, (newValues, oldValues) => {
         if (!isInternalMode.value) {
             return;
         }
         if (oldValues && shallowRecordEqual(newValues, oldValues)) {
             return;
         }
+        if (internalForm.isDirty) {
+            internalForm.defaults(newValues);
+            return;
+        }
         restoringDefaults.value = true;
```

---

#### 4.10 (FE-M6) `SkDatatable` urlFilters → api.get

**File:** `resources/js/components/Lvntr-Starter-Kit/DatatableBuilder/SkDatatable.vue`

```diff
     if (urlFilters.length) {
         onMounted(async () => {
-            await Promise.all(
+            await Promise.allSettled(
                 urlFilters.map(async (f) => {
-                    const res = await fetch(f.optionsUrl!, {
-                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
-                        credentials: 'same-origin',
-                    });
-                    const json = await res.json();
-                    urlOptions[f.key] = json.data ?? json;
+                    try {
+                        const data = await api.get<FilterOption[]>(f.optionsUrl!);
+                        urlOptions[f.key] = data ?? [];
+                    } catch {
+                        urlOptions[f.key] = [];
+                    }
                 }),
             );
         });
     }
```

In the same file, change `let activeMenuItems = ref<MenuItem[]>([]);` → `const activeMenuItems = ref<MenuItem[]>([]);` (FE-M9).

---

#### 4.11 (FE-M7) `TwoFactorTab` router.reload await

**File:** `resources/js/pages/Profile/components/TwoFactorTab.vue`

```diff
     async function enableTwoFactor() {
         twoFactorProcessing.value = true;

         if (!props.twoFactorEnabled) {
             await axios.post('/user/two-factor-authentication');
-            router.reload({ only: ['twoFactorEnabled', 'twoFactorConfirmed'] });
+            await new Promise<void>((resolve) => {
+                router.reload({
+                    only: ['twoFactorEnabled', 'twoFactorConfirmed'],
+                    onFinish: () => resolve(),
+                });
+            });
         }

         await loadQrAndSetupKey();
```

---

#### 4.12 (FE-M8) Drop `as any` casts

**File:** `resources/js/pages/Profile/components/ProfileInfoTab.vue`

```diff
-        :avatar-url="(user as any)?.avatar_url"
+        :avatar-url="user?.avatar_url"
```

**File:** `resources/js/pages/Admin/Users/components/UserForm.vue`

```diff
-            :avatar-url="(formRef.remoteData as any)?.avatar_url"
+            :avatar-url="(formRef.remoteData as { avatar_url?: string | null } | null)?.avatar_url"
```

---

### 5. Config / env hardening

#### 5.1 (GV-M1) LOG_LEVEL in `.env.example` and `.env`

**File:** `.env.example`

```diff
-LOG_LEVEL=debug
+LOG_LEVEL=error
```

Make sure production `.env` files use `LOG_LEVEL=error` or `warning` as well.

---

#### 5.2 (GV-M2) Move tinker from `require` to `require-dev`

**File:** `composer.json`

```diff
     "require": {
         "php": "^8.3",
         "laravel/framework": "^13.0",
         "laravel/pulse": "^1.7",
-        "laravel/tinker": "^2.10.1 || ^3.0",
         "lvntr/laravel-starter-kit": "@dev"
     },
     "require-dev": {
         ...
         "laravel/sail": "^1.41",
+        "laravel/tinker": "^2.10.1 || ^3.0",
         "mockery/mockery": "^1.6",
```

Then: `composer update`.

---

#### 5.3 (GV-M3, GV-M4) `.env.example` — Turnstile & Passport key placeholders

**File:** `.env.example`

Add after the Passport section:

```
# Passport OAuth2 keys — prefer loading via env in production instead of
# committing the key files at storage/oauth-*.key. Run `php artisan passport:keys`
# once, move the generated strings into these env vars, then delete the files.
# PASSPORT_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
# PASSPORT_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----"

# Cloudflare Turnstile (bot / captcha). When TURNSTILE_ENABLED=false the
# `turnstile` middleware becomes a no-op, so leaving the keys empty during
# development is safe.
TURNSTILE_ENABLED=false
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=
```

---

#### 5.4 (GV-M5) `HandleInertiaRequests` — appEnv / appDebug scope

**File:** `app/Http/Middleware/HandleInertiaRequests.php`

```diff
             'appVersion' => InstalledVersions::getPrettyVersion('lvntr/laravel-starter-kit'),
-            'appEnv' => config('app.env'),
-            'appDebug' => config('app.debug'),
+            'appEnv' => fn () => app()->environment('production') ? null : config('app.env'),
+            'appDebug' => fn () => app()->environment('production') ? false : (bool) config('app.debug'),
```

If any front-end code branches on `appEnv === 'production'`, update it to expect `null` in that case.

---

#### 5.5 (GV-M7) CORS preflight cache

**File:** `config/cors.php`

```diff
-    'max_age' => 0,
+    // Cache preflight (OPTIONS) results in the browser for 2 hours so SPA /
+    // mobile clients don't re-run the CORS handshake on every mutating call.
+    'max_age' => 7200,
```

---

#### 5.6 (GV-L1) `Password::defaults` policy

**File:** `app/Providers/AppServiceProvider.php`

```diff
 use Illuminate\Support\Facades\Event;
 use Illuminate\Support\ServiceProvider;
+use Illuminate\Validation\Rules\Password;

 class AppServiceProvider extends ServiceProvider
 {
     ...
     public function boot(): void
     {
         Event::listen(Login::class, UpdateLastLogin::class);
+
+        Password::defaults(function () {
+            return Password::min(10)
+                ->mixedCase()
+                ->letters()
+                ->numbers()
+                ->symbols();
+        });
     }
 }
```

**Heads up:** This change does NOT invalidate existing users' passwords, but new registration / password-change flows now require 10+ characters with mixed case, digits, and symbols.

---

### 6. GV-H1 — Passport private key rotation (CRITICAL, MANUAL)

This step involves destructive operations; run it **off-hours, with team sign-off and a rollback plan**.

```bash
# 1. Install git-filter-repo (filter-branch is deprecated)
brew install git-filter-repo          # or: pipx install git-filter-repo

# 2. Strip the key files from history
cd /path/to/starter-kit-app
git filter-repo --path storage/oauth-private.key --invert-paths
git filter-repo --path storage/oauth-public.key  --invert-paths

# 3. Generate a fresh key pair (file form for now)
php artisan passport:keys --force

# 4. Move the contents into .env, delete the files
# (PASSPORT_PRIVATE_KEY and PASSPORT_PUBLIC_KEY — config/passport.php already reads them)
rm storage/oauth-private.key storage/oauth-public.key

# 5. Purge every active token
php artisan passport:purge

# 6. Force-push (team sign-off required)
git push --force-with-lease origin <branch>
```

**Important:**
- Everyone on the team must `git fetch && git reset --hard origin/<branch>` after the force push.
- Scrub any cached repo copies on CI/CD runners.
- Put `PASSPORT_*` env values in your production vault / secrets manager (never commit to git).

---

### 7. Verification

```bash
# Backend
composer install
php artisan migrate --force
php artisan sk:seed-permissions --fresh
vendor/bin/pint --dirty --format agent

# Frontend
npm install
npm run build

# Tests
php artisan test --compact
npm run test
```

Do not commit until everything turns green. If a test breaks, isolate and hot-fix the offending patch — don't defer to the other patches in this release; they're all independent.

### 8. Smoke-test checklist

- [ ] Login → 2FA challenge → wrong code → consumes the single attempt (BE-M4).
- [ ] API `DELETE /api/v1/users/{your_own_id}` returns 403 (BE-H1).
- [ ] Role create + permission assignment: changes land in the DB (BE-H2).
- [ ] Settings > Auth disable 2FA: every user's 2FA secret is cleared + setting saved (BE-H3).
- [ ] Large folder (50+ levels) bulk delete: no timeouts (BE-H5).
- [ ] SMTP encryption set to "none": mail sends successfully (BE-H6).
- [ ] With `APP_DEBUG=true`, a 500 on an API endpoint: response `message` is generic; details live in the `debug` block (GV-H2).
- [ ] Request with `X-Request-ID: ../etc/passwd`: response header `X-Request-ID` is a UUID; log has `client_request_id: null` (GV-H3).
- [ ] 2FA setup page: QR code renders as `<img>`, no `v-html` (FE-H2).
- [ ] Rapid dialog open/close/open: content survives (FE-M1).
- [ ] FormBuilder form open, parent prop changes: user input is not wiped (FE-M4).

---

## Troubleshooting

### General

**Missing classes after `sk:update`:**

```bash
composer dump-autoload
```

**Vite manifest error after `sk:update`:**

```bash
npm run build
# or start the dev server
npm run dev
```

**Migration error after `sk:update`:**

Do not reach for `migrate:fresh` / `migrate:refresh` — this project's migrations are additive and can be run incrementally. Fix the failing migration (or roll it back with `php artisan migrate:rollback --step=1`) and re-run `php artisan migrate`.

**Passport keys missing after upgrading:**

```bash
php artisan passport:keys --force
```

### "422 Unprocessable Content" — new FormRequest authorize
The new `authorize()` check is strict. Make sure the permission is actually assigned to the user: run `php artisan sk:seed-permissions --fresh`.

### 2FA verification says "challenge expired"
After BE-M4 the challenge is single-use. If the 6-digit code is wrong the flow restarts — pull the fresh code from your OTP app (it rotates every 30 seconds) and log in again.

### Axios requests aren't 419'ing but there's no session
After FE-H1 `withCredentials = true`. If your front-end is served from a different domain (subdomains included) make sure `config/cors.php` sets `supports_credentials => true` and `allowed_origins` does not contain a wildcard.

### Dashboard looks empty
`appEnv` / `appDebug` are now `null` / `false` in prod — if your Vue templates branch on them, make sure you have a fallback.

---

## Previous releases

- **13.3.3** (2026-04-20) — Windows build fix: sibling `core.ts` barrel for Builder `core/` imports. Details: [CHANGELOG.md](CHANGELOG.md).
- **13.3.2** (2026-04-19) — Security hardening + user audit + API auth parity. Details: [CHANGELOG.md](CHANGELOG.md).

Full change history lives in [CHANGELOG.md](CHANGELOG.md).
