# What's New

Newly added features and improvements to the starter kit are listed here.

## Unreleased

### Fixed

- **`encryption:key` no longer changes what an existing `DATA_ENCRYPTION_PREVIOUS_KEYS` entry means.** The command normalised only the key it was retiring; entries already in the list were copied through verbatim. The list is read through phpdotenv (quotes stripped, `${VAR}` resolved) and written back unquoted, so an entry holding `#`, `$` or whitespace came back as a different key on the next boot — `#` opens a comment and truncates it. Every entry is now made env-safe (re-emitted as `base64:`, decoding to the identical bytes) when its raw form cannot survive an `.env` line.
- **`encryption:key` now verifies its temporary `.env` was written in full, and flushed to disk, before renaming it into place.** A full disk makes `Filesystem::put()` return a short byte count instead of throwing, so a truncated body could replace a complete `.env` — on the first of the command's two writes, that body holds the only copy of the key being retired. A failed reopen, `fflush()` or `fsync()` aborts too: for this file durability is the safety property, and an unflushed write turns the two-write ordering back into a call order.
- **`encryption:key` now carries the `.env` owner and group onto the file it writes, and refuses to replace the file when it cannot.** `sudo php artisan encryption:key` over a `www-data:www-data` `.env` used to leave a root-owned file the web user could not read. Restoring ownership is attempted best-effort (only root can hand a file over) but the result is verified and a mismatch aborts — warning and continuing shipped an unreadable `.env` while reporting success. The mode restore is verified by the same rule: wider leaks the key, narrower locks out the service, both abort. Nothing is replaced on any of these paths.

## 2026-08-31 — v13.7.0

### Added

- **A dedicated `DATA_ENCRYPTION_KEY`, independent of `APP_KEY`, now protects sensitive settings values (`mail.password`, `storage.spaces_secret`, `storage.aws_secret`, `turnstile.secret_key`, `postman.api_key`, `apidog.access_token`) and 2FA secrets/recovery codes.** Previously all of this data was encrypted with `APP_KEY`, so a routine `php artisan key:generate` on a server migration made it silently unrecoverable — `SettingService` swallowed the resulting `DecryptException` and returned `null` instead of erroring. Three new commands manage the new key: `encryption:key` generates it and preserves the old key in `DATA_ENCRYPTION_PREVIOUS_KEYS`; `encryption:rekey` re-encrypts existing rows onto the new primary key without ever touching a row it cannot decrypt; `encryption:health` reports whether the previous-key list is safe to clear, and `php artisan sk:doctor` gained a matching `Data Encryption Key` check. Adoption is opt-in — an install that runs none of this keeps working exactly as before, byte-for-byte, and a fresh `sk:install` now generates the key automatically. See [Data Encryption](encryption.md) and the [server migration runbook](server-migration-runbook.md).
- **`SkForm` gained a `reload()` method and a `.reloadOnDataUrlChange()` builder flag.** `reload()` (exposed via `defineExpose`) lets a host re-fetch `dataUrl` on demand — a "Refresh" button, a sibling save event — without remounting the form. `.reloadOnDataUrlChange(true)` opts a form into refetching automatically whenever its `dataUrl` prop changes after mount (e.g. a dialog reused for a different record id); the default stays mount-only so a form whose config is rebuilt on every parent render doesn't refetch on every rebuild. See [FormBuilder API](formbuilder.md#form-builder-api).
- **`FB.fileUpload()` gained `.deferExistingRemoval()` and drag-and-drop.** `.deferExistingRemoval(true)` changes removing an already-saved file from an immediate `DELETE /media/{id}` to a deferred one: the item only leaves the field's keep-list, and deletion happens on save via `Lvntr\StarterKit\Traits\HasMediaCollections::syncMediaCollection()`. The upload field's drop zone now also accepts files dragged onto it, going through the same `accept`/`maxFileSize`/`fileLimit` validation as the picker button. See [File Upload Field API](formbuilder.md#file-upload-field-api).
- **The `@lvntr/components` package library is now linted in CI** via a new `lint:lib` root script (`eslint --config stubs/eslint.config.js resources/js/components/Lvntr-Starter-Kit`), so a lint regression in the shared component library is caught the same way stub-side lint already is.
- **`TabIconColor` and `TabBadgeSeverity` are now exported from the TabBuilder core barrel** (`@lvntr/components/TabBuilder/core`), matching the already-exported `TabBuilderConfig`, `TabItemConfig`, and `TabLayout` — a consumer typing against a tab's icon color or badge severity no longer needs to reach into the internal `./types` module directly.
- **`TB.tabs()` gained five chainable options for panel mounting and URL behavior: `.lazy()`, `.keepAlive()`, `.history('push' | 'replace')`, `.urlMode('server' | 'client')`, and `.syncUrl(boolean)`.** `.lazy()` mounts only the active panel (PrimeVue's own lazy mode on horizontal layout) while `.keepAlive()` keeps every panel mounted and hides inactive ones, preserving per-tab state; `.history()` controls whether a switch replaces the current history entry (default) or pushes a new one; `.urlMode('client')` rewrites the URL with no server request instead of the default Inertia visit; `.syncUrl(false)` drops URL sync entirely. See [tabs.md](tabs.md).
- **`SkTabs` now supports `v-model` and emits a `change` event.** The optional `modelValue` prop two-way-binds the active tab key in both URL and local mode — a URL deep link wins over a different incoming `modelValue` on mount; `change` fires on every switch after mount (not the initial mount) with `{ key, previousKey, tab }`.
- **`SkTabs` gained an `empty` slot**, rendered alone — no sidebar, no tab strip — when no tab is selectable: every tab is filtered out by `.permission()`/`.role()`/`.visible()`, or every visible tab is `.disabled()` (which used to leave an all-disabled strip with no active panel).
- **Vertical `SkTabs` is now a proper ARIA tablist.** The sidebar nav is `role="tablist"`/`aria-orientation="vertical"`, each tab button is `role="tab"` with `aria-selected`/`aria-controls`/`aria-disabled` and roving `tabindex`, and the panel is wrapped in `role="tabpanel"`; Arrow Up/Down, Home/End move focus between enabled tabs and Enter/Space select.
- **`TabsBuilder.build()` now validates duplicate tab keys and returns an immutable snapshot.** A duplicate key throws in development builds and logs a `console.error` in production instead of staying silent; `TabItemBuilder.build()` also now rejects a whitespace-only key, not just a missing one; every `build()` call returns a fresh copy of the config and its tabs, so a later `.addTabs()` on the same builder or mutating the returned config can no longer affect an already-built config.
- **`TabPanelMode`, `TabHistoryMode`, `TabUrlMode`, `TabChangePayload`, and `SkTabsExposed` are now exported from the TabBuilder core barrel** (`@lvntr/components/TabBuilder/core`), alongside the existing `TabBuilderConfig`, `TabItemConfig`, `TabLayout`, `TabIconColor`, and `TabBadgeSeverity` exports.
- **`useUrlTab()` now accepts a `ref` or a getter for its `tabs` argument, in addition to a plain array, and a new `{ history: 'push' | 'replace' }` third argument.** The value is read through `toValue()` on every access; `history: 'push'` gives each switch its own history entry instead of the default `'replace'`.

### Changed

- **The aura theme now puts the back button in the topbar, immediately to the left of the page title.** Aura already moved the page title out of the content area and into the top bar, but the back button stayed behind — either floating alone in an `AdminPageHeader` block above the content, or tucked into the right-hand side of the first card's header on pages that opted into `header-in-card`. Either way the affordance sat far from the title it belonged to. `AdminLayout` now hands the back button to `AdminHeader` whenever aura renders the title there, and the bar draws it as an icon button directly left of the title. The in-content `AdminPageHeader` block survives only when the page also supplies `page-actions` (so page actions are not lost), and the in-card slot stops activating — `usePageHeader().active` is now false while the topbar hosts the button, so no page shows two back affordances; pages that render into `#title-end` need no change. Other themes are untouched and keep the classic `AdminPageHeader` block. The `Logs/Show` page's own aura-only back button was removed for the same reason.
- **Vertical `SkTabs` tab buttons now carry `role="tab"` instead of no explicit role, and the panel content is wrapped in a new `role="tabpanel"` `<div>` inside the card body.** A test selecting a tab button with `getByRole('button')` must switch to `getByRole('tab')`; custom CSS relying on a direct-child selector under the card body may need a look.
- **`TabsBuilder.build()` now throws on a duplicate tab key in development builds, and logs the same message via `console.error` in production instead of staying silent.** Duplicate keys used to silently break slot resolution and URL selection — the second tab rendered the first one's content and could never be reached via `?tab=`.
- **`SkTabs` no longer imports the published `useUrlTab` copy from `@/composables`; it now owns an equivalent active-tab state internally.** This removes a version-skew risk (`sk:publish --tag=composables` could leave an app on an older `useUrlTab` than the shipped component expects), but a project that hand-edited its published `useUrlTab.ts` specifically to change `SkTabs`' behavior will no longer see that edit take effect — `useUrlTab()` itself is unaffected for app code that calls it directly.
- **Cross-page "select all filtered" bulk selection now fails closed on an unsupported filter instead of silently dropping it.** `BulkFilterSnapshot::normalize()` rejects an active `filter[...]` key it can't apply with a 422 (`sk-bulk.unknown_filters`) rather than dropping it from the snapshot, which previously resolved a set wider than what the table showed and let a bulk action reach rows the dropped filter was hiding. Affects the shipped `UserBulkSelectionQuery` and `RoleBulkSelectionQuery`. Only a `null` value or an empty array counts as inactive — the two shapes Spatie's `AllowedFilter` skips; an empty or whitespace-only string is passed through verbatim and applied with the table's own predicate (an exact filter yields the same empty set the table showed, `search`/date bounds ignore it), so a blank value can never widen the bulk set either.
- **`DatatableQueryBuilder::columns()` payload shaping is fail-closed.** A `?columns=` request parameter with no key matching a declared column previously fell back to returning the full row; it now reduces every row to the `alwaysInclude()` keys only, matching the "declared column keys must match the frontend" contract.
- **`TB.tabs().queryParam()` now rejects an empty or whitespace-only name.** It throws in development builds and `console.error`s in production, keeping the name already set (the `tab` default or an earlier call) — an empty name used to be stored as-is and produced a `?=key` URL parameter that nothing could read back, so the tabs silently stopped syncing with the URL.
- **The installer commands now exit non-zero when a mandatory step fails.** `sk:install`, `sk:update`, `sk:upgrade` (and the published `site:install` stub) invoked `migrate`, `db:seed`, `vendor:publish`, `sk:seed-permissions`, `passport:keys`, `key:generate`, `composer dump-autoload`, `npm install` and `npm run build` without ever reading the result: a `migrate` that died on a bad connection still printed `DONE`, the resume checkpoint recorded the step as finished, the stub-hash registry was written, and the command exited `0` — a CI job went green over a half-installed application. Every sub-command result is now checked; a failed **mandatory** step (publish, migrations, seeders, permission seeding, Passport keys, encryption keys) aborts the run, leaves the checkpoint pending so `sk:install --resume` picks up where it stopped, skips the registry write, and ends with one line naming the failed step and the resume command. **Frontend steps stay non-fatal on purpose** — `npm install`, the Wayfinder generation and `npm run build` (plus `composer dump-autoload` and cache clears) only warn, print the command to run by hand, and are listed again in the closing summary, so a machine without Node or composer still installs exactly as it does today. A CI pipeline that currently passes with a silently failing migration will now go red — see [docs/UPGRADE.md](UPGRADE.md). The `site:install` change lives in a stub, so it reaches new installs and `sk:update`-refreshed apps only; an existing consumer copy is untouched.

### Fixed

- **`sk:install` is no longer documented as a recovery path for an existing project.** `docs/install.md` and `docs/update.md` described re-running it as an idempotent whole-project repair. It is not: the hash registry only protects a file you deleted rather than one you edited, and a consumer-edited published file is skipped and reported rather than refreshed unless `--force` is passed, in which case it is overwritten outright — neither mode gives the selective, edit-preserving refresh `sk:update` or a scoped `sk:publish --tag=<area>` gives. Both guides now carry the warning, and `UPGRADE.md` records the boundary.
- **`sk:install` refuses to run on an app it did not install.** The command trusted its own hash registry (`storage/starter-kit/hashes.json`, git-ignored) as the only signal that a project was already installed; losing that registry made a live application look brand new. A fail-closed detection pass now runs ahead of the banner — kit schema tables and install-only paths are checked, and if any are present without a matching registry, the command stops before writing anything. `sk:update` and the new `php artisan sk:install --adopt` (rebuilds the registry only, `--dry-run` previews it) are named as the way out; `--force` still proceeds but is no longer treated as a first install.
- **An existing `.env` is never overwritten by `sk:install`, on a first install or a re-run.** A first install used to copy `.env.example` straight over an existing `.env`, destroying `DB_PASSWORD`, `APP_KEY` and anything else already configured. The installer now merges: missing `.env.example` keys are appended and first-install-only keys are seeded only where absent, and no existing value is ever rewritten. `.env` is created from `.env.example` only when it does not already exist.
- **A consumer-modified published file is now skipped by `sk:install`'s re-publish path too, not only `sk:update`'s.** Both commands share the same three-way stub/target/registry-hash comparison; a file that no longer matches the last-recorded hash is treated as a consumer edit, skipped, and reported instead of silently overwritten. `--force` remains the opt-out.
- **`sk:install` no longer overwrites an untracked file on a re-install.** A file the hash registry had no record of at all — because a newer package version started shipping into a path it had never shipped into on this app before — used to be overwritten regardless of `--force`. It is now treated the same as a consumer edit: preserved and reported, unless `--force` is passed. The protection only applies once a registry exists; a genuine first install still publishes every path, tracked or not.
- **`sk:install`'s destructive `migrate:fresh` option now requires a typed confirmation, not a `select()` answer.** The install-time menu offered "drop all tables and run fresh migrations" as an ordinary yes/no choice, one wrong keystroke away from an irreversible reset. Choosing it now prompts for the database name (or the word `fresh`) typed at a `text()` prompt; anything else, including an empty answer, falls back to the additive `migrate` path with nothing dropped. The option is also withheld outright when `APP_ENV` looks production-like, `APP_DEBUG` is off, the session cannot prompt, or any existing table already holds rows.
- **An account disabled while its session is still open is now logged out on the next request.** The login path already refused a non-active account, but could not reach an already-open session. The new `EnsureUserIsActive` middleware checks `status` on every `web`/`api` request and terminates the session when it matches the operator's deny-list (`starter-kit.security.active_status_denied`, default `['inactive', 'banned']`); it is deliberately fail-open on every ambiguous case (no status attribute, non-string value, an unlisted status) and can be disabled outright via `starter-kit.security.enforce_active_status = false`.
- **A setting that cannot be decrypted is no longer silently indistinguishable from an unset one.** `SettingService` caught every `Exception` while decrypting and returned `null`, which `allGrouped()` then cached for an hour — so a wrong key, a corrupted payload or a misconfigured cipher quietly fell back to the env/default value on mail, storage and Turnstile. Only `DecryptException` is handled now (still `null`, but logged without the ciphertext); anything else propagates.
- **`encryption:health` now fails closed when its config is cached and stale.** Under `config:cache`, the command could not tell "cache predates an `.env` edit" from "the value was never sourced from env at all" — so a stale cached chain could report `safe-to-clear` on a key set the app was about to stop using. A cached configuration whose resolved key chain no longer matches `.env`/the process environment now downgrades the verdict to `incomplete` (exit 1) instead, with a `Run php artisan config:clear` instruction, closing a path that could have turned a rotation into permanent data loss. See [encryption.md](encryption.md).
- **`sk:install`'s existing-app detection markers were dead in production.** `EXISTING_APP_DIRECTORY_MARKERS` carried `resources/js/Pages/Admin` (uppercase `Pages`), which the stub tree never ships — only case-insensitive local filesystems (macOS) masked the mismatch. `KIT_SCHEMA_TABLES` checked for a `file_manager_folders` table this kit has never created, instead of the actual `file_folders`. Both are corrected, restoring the fail-closed detection pass those markers exist for.
- **An unreachable database at install time no longer reports a successful install.** `sk:install` used to skip the database block (migrations, seeders, permission seeding) on a connection failure with only an on-screen warning, still write the stub-hash registry, clear the resume checkpoint, and exit `0`. The run now ends **incomplete**: the registry is withheld, the checkpoint is preserved so `--resume` continues exactly where it stopped, and the command exits non-zero. See [UPGRADE.md](UPGRADE.md).
- **`sk:install` no longer deletes `package-lock.json` on a re-install or a `--resume`.** The lockfile is the application's pinned dependency graph, and `installFrontend()` removed it unconditionally before `npm install` — so a re-run re-resolved every package onto versions the app had never been tested against, even while the run's own summary reported the file as kept. It is now removed on a **first** install only (where a lock left over from an unrelated `package.json` is only in the way), and the decision moved inside the `Installing npm dependencies` step: a `--resume` run that skips that checkpointed step no longer deletes the lockfile the first run had just written with nothing left to regenerate it.
- **A `--resume` run no longer wipes the `node_modules` it just installed.** Clearing the stale dependency tree sat in front of the `Installing npm dependencies` step and ran only when a tree already existed — so on a first run there was nothing to clear and nothing to checkpoint, while the `npm install` that created the tree WAS checkpointed. An interrupted install followed by `sk:install --resume` therefore deleted the freshly installed `node_modules` and then skipped the install that would have refilled it, leaving `npm run build` to fail on missing dependencies. The clear now happens inside the same step, next to the lockfile decision: skip the step, skip both.
- **`encryption:key`'s "Next steps" output now matches the documented rotation order.** The numbered list opened with `encryption:rekey`, while a cached configuration still resolves the key that was just retired — so an operator following the output re-encrypted every row onto the wrong key, or rewrote nothing at all. When config is cached, `php artisan config:clear` is now listed as its own first step, ahead of the rekey, exactly as [Data Encryption](encryption.md) prescribes.
- **`encryption:key`'s `.env` reads now match what the running app actually resolves.** `DATA_ENCRYPTION_KEY`, `APP_KEY` and `DATA_ENCRYPTION_PREVIOUS_KEYS` were read out of `.env` with a hand-rolled regex that returned a `${VAR}`-interpolated assignment (e.g. `${APP_KEY}`) verbatim instead of resolving it, and disagreed with the real dotenv parser on inline comments and some quoting. Reads now go through the same parser the app boots with (`Dotenv::parse()`), so an interpolated reference resolves the same way, and the resolved material — never the literal reference — is what gets prepended to `DATA_ENCRYPTION_PREVIOUS_KEYS`. A `.env` the parser cannot read now aborts the rotation before a key is generated or anything is written, instead of misreading it silently; the parser's own error is withheld from the report because it can quote the malformed line back, which may itself be key material. The rotation also stops when the **process environment** overrides one of those keys — directly, or through a variable that an interpolated value references — because the file would then state one value while the running app resolves another; rewriting `.env` could not close that gap, since the process value keeps winning, so the only safe move is to stop before a key is generated and let the operator resolve the divergence.
- **A `media` table migration rollback no longer leaves the schema and the ledger disagreeing.** `create_media_table` had no `down()`, and Laravel's migrator guards that call with `method_exists` — so `php artisan migrate:rollback` silently skipped the table while still deleting the migration's ledger row. The table survived, its record did not, and the next `migrate` failed on a table that already existed. It now declares a `down()` that refuses rather than destroys: an empty table is dropped, and a rollback attempted while rows remain stops with an error. Dropping a populated `media` would remove the *rows*, not the *files* — Spatie deletes the underlying blobs only through the model's deleting event, which a schema rollback bypasses, so the disk would be left holding orphaned files with nothing left to index them. Delete the media through the application first if you mean to roll it back. The two later migrations in the same chain (`add_folder_id_to_media_table`, `add_soft_deletes_to_media_table`) carry the identical refusal, because a batch rolls back newest-first: without it they would have dropped `folder_id` and `deleted_at` off a populated table before the create migration's guard was ever reached. See [UPGRADE.md](UPGRADE.md).
- **`definitions.lang` is narrowed so the table's composite unique index stops sitting under InnoDB's key-length limit.** `unique(['key', 'value', 'lang'])` over three default 255-character columns sat at 3060 of the 3072-byte limit — a single character of headroom on any one column away from breaking outright. `lang` narrows to 35 — the widest locale value the kit already accepts anywhere (`content_languages.code`), so nothing storable through the kit's own screens is affected — while `key` and `value` keep their published 255, because `lang` alone leaves ~892 bytes of headroom and narrowing them would only block data the current schema accepts; a new migration measures every existing row, soft-deleted ones included, before touching the schema and refuses — leaving the schema unchanged — if a single row would be truncated. Both directions end by asserting the unique index exists, so a table that reaches the migration with the index already missing gets it rebuilt instead of being recorded as migrated without its guarantee. See [UPGRADE.md](UPGRADE.md) for what to clean up if it refuses.
- **`sk:install` no longer deletes conflicting default Laravel files on a re-install or an update.** Removing `package-lock.json`, `vite.config.*` and `resources/js/app.js` unconditionally destroyed project state the installer has no mandate over on any run past the first. Deletion is now first-install-only; a later run reports the conflicting files it found and left alone instead of deleting them.
- **The MySQL/MariaDB CI slice now runs the real migration chain instead of the install test suite.** `tests/Feature/Install` exercised installer logic, not migrations, so a migration that broke on MySQL or MariaDB's stricter DDL could pass CI unnoticed; the MariaDB job also ran through the `mysql` driver, so `uuid` columns never took MariaDB's native path. Both jobs now run `tests/Feature/Migration` against their own driver (`mysql` / `mariadb`).
- **The settings cache is cleared after the outer transaction commits, not during it.** `setValue()` / `setGroup()` called `Cache::forget('settings')` inline, so a write wrapped in an outer transaction (`UpdateAuthSettingsAction`) dropped the snapshot while the rows were still uncommitted — a concurrent reader could miss, re-read the pre-write rows and cache them for another hour. The clear now runs through `DB::afterCommit()`, which still fires immediately when no transaction is open.
- **Logo, favicon and avatar uploads store the new file before dropping the old one.** All three deleted the existing asset first, so a failed `store()` left the setting pointing at a file that no longer existed. A failed upload now leaves the current image in place and returns an error instead.
- **A media object is removed from disk only once its row's deletion has committed.** Spatie's `MediaObserver::deleted()` removes the file inside the transaction that deleted the row, so a rollback restored a row pointing at a file that was already gone. The removal now goes through `DB::afterCommit()`: it is discarded on a rollback, and the worst remaining outcome is an orphaned file, which is recoverable. A non-transactional delete keeps today's timing and still surfaces its failure.
- **Restoring a folder from trash no longer creates a duplicate name.** `CreateFolderAction` rejects a duplicate, but the trash was a way around it at the root level, where MySQL and SQLite treat two NULL `parent_id` values as distinct and the unique index does not fire. The restore now refuses with the same domain error.
- **The FileManager quota calculation works on a bare Spatie `Media` model again.** `computeStorageUsed()` called `withTrashed()` unconditionally; without the SoftDeletes trait that macro does not exist, so every upload validation threw `BadMethodCallException`. It goes through the capability-aware helper the rest of the trait already used.
- **`file-manager:purge-trash` no longer loads the whole trash into memory, and reports failures.** The command read every matching row with `get()` before deleting, takes a cache lock so two schedulers cannot purge the same rows at once, walks the rows with `chunkById` (`--chunk=`, default 500), keeps going when one item fails, and returns a non-zero exit code when anything was left behind. The published schedule entry gained `withoutOverlapping()`.
- **Console output points at documentation URLs that exist in an installed app.** `/docs/**` is `export-ignore`d, so a `prefer-dist` install has no docs directory — yet `sk:install`, `sk:update`, `sk:upgrade` and `sk:doctor` printed local `docs/…` paths mid-migration and during key rotation. They now print a URL pinned to the installed version.
- **`encryption:health` and `encryption:rekey` say which surfaces they can actually vouch for.** Both reported on the kit's own key chain while a consumer-installed encrypter on the Fortify or model-cast path was invisible to them, so a rekey could report success over rows it never re-encrypted. Each surface is now reported with the encrypter that serves it, an encrypter the kit did not build is named as unvouched (`verdict: not-covered`, exit 1), and `encryption:rekey` refuses before reading a row instead of printing a complete rekey. The stale-published-config gap — a `config/starter-kit.php` predating the encryption block, where `DATA_ENCRYPTION_KEY` is inert while health reported "safe to clear" — is reported as its own diagnosis.
- **An encryption key is never written into a file whose permissions could not be restricted.** `encryption:key` chmod'ed its temp file to `0600` without checking the result; on a filesystem that ignores permissions the very next line wrote key material into a world-readable file. The mode is verified while the file is still empty, and the command aborts otherwise.
- **`encryption:key` and `encryption:rekey` cannot run at the same time.** Both read the key chain, decide a new one and write it back, so two concurrent runs could drop a key that was still needed to read existing rows. They now share one cache lock; a `--dry-run` rekey is unaffected.
- **`DELETE` on a FileManager file validates its context like every other route.** The endpoint built the context DTO straight from the request, so a malformed one surfaced as a 500 instead of the documented 422 envelope.

- **The timezone select in the Users create/edit dialog now lists every timezone instead of only the site default.** `UserForm` receives the identifier list as a prop, and the `Admin/Users/Index` page — where both dialogs are opened — never passed it, so the component fell back to its empty default and the select offered a single "site default" option. The list is now supplied by `UserController::index()` and forwarded by both `dialog.open()` calls. The full-page `Users/Create` and `Users/Edit` routes were unaffected.
- **A `FB.datePicker()` value no longer drifts by a day on a form round-trip.** A date-only string (`"2024-03-10"`) from the server was parsed with `new Date(value)`, which JavaScript treats as UTC midnight; formatting it back for submission (`toLocalDateStr`) then reads it in the browser's local timezone, shifting the day in any timezone behind UTC. The date is now parsed component-wise (`new Date(year, month - 1, day)`) as local midnight, matching how it's serialized back.
- **`SkForm` no longer shows two error toasts for the same failed request.** The form raises its own `data_load_error` / `options_load_error` toasts with specific wording, but its internal `useApi()` call also fired the composable's default generic toast for the same failure. `SkForm`'s `useApi()` instance now opts out with `{ toast: false }`; a consumer's own `useApi()` calls are unaffected.
- **`FB.checkboxGroup().optionsUrl(...)` now actually fetches remote options.** The dynamic-options watcher matched select-like fields against a hardcoded type list that omitted `checkbox-group`, so a checkbox-group field configured with `optionsUrl` silently never fetched — it now uses the same `SELECT_TYPES` set as everywhere else.
- **A stale response from a dependent `optionsUrl` field can no longer overwrite a newer one.** Rapidly changing the field that drives a dependent select's URL (typing in a search box, quick re-selects) could let an older request's response land after a newer one, showing outdated options. Each field now tracks a monotonic per-request counter; a response is applied only if it's still the latest request for that field, otherwise it's dropped silently (no options write, no error toast).
- **A form/field marked read-only can no longer be reactivated by a field's own `.props({ disabled: ... })`.** Form-level `disabled` (from `.permission()`) or a field's own computed `disabled` is now a floor rather than a default — `.props({ disabled: false })` can no longer unlock a read-only form, while `.props({ disabled: true })` still disables an otherwise-enabled field.
- **Translatable field defaults on a create form now match the locales the field actually renders.** The empty `{ locale: '' }` seed used to read `availableLocales` from the Inertia page directly, which lists admin-UI locales; the field itself renders DB-backed *content* locales via `TranslatableInput`. When the two lists diverged, the submitted payload could carry keys for locales the field never showed (or miss ones it did). Both now resolve through the same `core/locales` helpers.
- **A dropped file that exceeds `maxFileSize` or the multi-file `fileLimit` is rejected instead of silently added.** `FB.fileUpload()`'s drag-and-drop path now runs through the same validation as the file picker, deduplicates a file already in the keep-list, and revokes its blob object URL when removed instead of leaking it.
- **A `<label for>` on six wrapper-rendered field types (`input-number`, `date-picker`, `select`, `multiselect`, `toggle-switch`, and `password` with `.feedback()`) now targets a focusable element.** These types render their PrimeVue control inside a non-focusable wrapper, so a plain `label[for=key]` pointed at nothing clickable; the inner control now receives `${key}__control` via PrimeVue's `inputId`, and the label's `for` targets that id.
- **Vertical `SkTabs` tab buttons no longer submit an enclosing form.** The sidebar nav `<button>` had no explicit `type`, so browsers defaulted it to `type="submit"` — clicking a tab inside a `<form>` could submit the form instead of just switching tabs. It now sets `type="button"`.
- **A tab's `visible`/`disabled` state changing after mount now correctly drives the URL-synced active tab.** `useUrlTab()` used to close over a fixed snapshot of the tab list taken at mount, so a tab that became visible later couldn't be reached via `?tab=`, and an active tab that became hidden or disabled left the UI pointed at a tab no longer in the list. The selectable list is now a reactive array kept in sync with the live `visible`/`disabled` state, so a newly visible tab is immediately selectable and a hidden or disabled active tab falls back to the first selectable tab.
- **A disabled tab can no longer be activated from `?tab=`, and a disabled first tab is no longer the param-less default.** `useUrlTab()` now resolves both the URL parameter and the "no parameter" fallback against the selectable (non-disabled) tab list instead of the full list.
- **Re-selecting the already-active tab no longer fires an Inertia visit, and `#hash` now survives a tab switch.** Clicking the active tab again (or re-assigning it to its current value) used to still call `router.visit()`; it is now a no-op. Switching tabs also preserves any `#hash` present on the current URL instead of dropping it.
- **Cross-page bulk selection on the Users table now honours the same `created_at_from`/`created_at_to` date-range bounds the datatable renders with.** `UserBulkSelectionQuery` now applies dates through `DatatableQueryBuilder::applyCalendarDateRange()`, the same helper the table's own query uses, so the bulk-resolved set can no longer drift from the visible set across timezone/DST boundaries.
- **A selected row's id is sent to a bulk action exactly as the table displays it, without numeric coercion.** `useDatatableSelection()`'s `executeBulkAction()` no longer converts a numeric-looking id before posting; UUID/ULID and integer primary keys alike round-trip unchanged.
- **The ID column on the API Token and API Client tables is sortable again.** `ApiTokenController::dtApi()` and `ApiClientController::dtApi()` now allow-list `id` alongside `name` and `created_at`, so clicking the ID header no longer returns Spatie `QueryBuilder`'s `InvalidSortQuery` 400.
- **`BulkActionRequest` no longer rejects a cross-page bulk request that carries no `ids`.** The published request required `ids` (`min:1`) even when `select_all_filtered` was `true`, contradicting the documented payload and 422-ing a host that calls `useDatatableSelection().executeBulkAction()` in "all" mode with nothing selected on the current page (the shipped Users/Roles pages never reach that state — "select all filtered" is only offered from the bulk bar). `ids` is now `Rule::requiredIf(! select_all_filtered)`; ids that are sent are still shape-checked (`array`, `max:500`, opaque strings). An unmodified copy is refreshed by `sk:update`.
- **The shipped `lvntr-kit-frontend` and `lvntr-starter-kit` skills now name the real auto-label key.** Both told the agent an omitted `.label()` resolves from `sk-attribute.attributes.{key}` in a `lang/{locale}/sk-attribute.php` file that does not exist; `FB` fields and datatable column/filter labels resolve from `validation.attributes.{key}` (`lang/{locale}/validation.php`). The FormBuilder guide also now states that in external `v-model` mode `initialData()`, a field's `.default()` and `dataUrl` data seed the internal form only and never populate the bound object.
- **`SkTabs` icons are hidden from assistive technology and a checked tab announces its state.** Tab icons in both layouts carry `aria-hidden="true"` (the label already names the tab), and the `.checked()` check mark — state, not decoration — is paired with visually hidden text (`sk-common.completed`, a new key in the EN/TR bundles) so a screen reader hears it instead of skipping it.
- **Cross-page "select all filtered" now applies a literal `true` / `false` filter value exactly as the table does.** Spatie's `QueryBuilderRequest` turns those two strings into booleans before a datatable filter ever runs, but `BulkFilterSnapshot::normalize()` passed them through as text — so a search for `true` resolved a bulk set matching the word "true" while the table had matched "1", and could reach rows the table never showed. The snapshot now performs the same coercion, and the word-search predicate moved into `DatatableQueryBuilder::applySearchWords()`, the single helper the table's `search` filter and the shipped `UserBulkSelectionQuery` / `RoleBulkSelectionQuery` all use, so the two paths can no longer drift on how a value is split, escaped or coerced.
- **A comma in the datatable search box no longer breaks the request.** Spatie's query builder explodes every `filter[...]` value on `,` before the table's search callback sees it, so a search such as `Acar, Levent` reached the callback as an array and the request failed with a `TypeError` (HTTP 500). `DatatableQueryBuilder::applySearchWords()` now re-joins the exploded value with the same delimiter and searches the text as typed; the cross-page bulk selection already applied the raw text, so both sides resolve the same set.
- **The desktop search box's clear (×) control in `SkDatatable` is now a real `<button>`, reachable and operable from the keyboard.** It was a click-only `<i>` icon with no accessible name; it now carries the new `sk-datatable.clear_search` label, which the mobile search popover's clear button uses as well (it previously announced "Close"). A test or stylesheet that targeted the control as an `<i>` element must target the button instead — the `sk-dt-search__clear` class is unchanged.
- **`TranslatableInput` labels are now associated with their input.** The label rendered beside the locale switcher (and in single-locale mode) carries `for` = the field key and the active input carries the matching `id`, so clicking the label focuses the field — the same markup the regular form fields already use. When a single locale renders, a required field also marks that input `aria-required` and pairs the decorative asterisk (now `aria-hidden`) with an sr-only "required" text; with several locales the asterisk stays purely visual, because `HasTranslatableRules::translatableRules()` requires the default locale alone and makes every other locale `nullable` — announcing each locale tab as required would be wrong. A `translatable-editor` is named through `aria-labelledby` instead of `for`: its editable node is a contenteditable `<div>`, which `label[for]` cannot target.
- **`EditorInput`'s `id` and ARIA attributes now sit on the node the user actually types in.** The `id` was set on `<EditorContent>`'s wrapper `<div>` while Tiptap renders the contenteditable node inside it, so a `label[for]` pointed at a non-labelable wrapper and assistive technology read no name, role or required state for the editor at all. The editable node now carries the `id`, `role="textbox"`, `aria-multiline="true"` and the two new `ariaLabelledby` / `ariaRequired` props. A stylesheet or test selecting the editor by `#<field key>` now matches the inner `.sk-rte__content` node instead of the `.sk-rte__body` wrapper.

## 2026-08-25 — v13.6.16

### Fixed

- **A datatable no longer renders empty because a neighbouring table's sort was left in the page URL.** `sort` is a page-global query parameter, so on a page hosting several tables (tabs, side-by-side panels) the table that mounted second read the first one's `sort` out of the URL and asked its own endpoint for a column that endpoint never allowed — `Spatie\QueryBuilder` answers with HTTP 400 (`InvalidSortQuery`), so the table came up blank. A bookmarked link failed the same way once a column had been renamed or dropped. `SkDatatable` now validates a restored sort key against its own columns before using it — the id column included, since it is sortable without appearing in `columns`, and this route's persisted column order too, so a column only the server publishes (a hidden `updated_at`, say) still restores its sort once the user has enabled it. A URL carrying a foreign sort is treated as another table's URL and is ignored **whole** — `page`, `per_page` and the filters with it, because reading half of it would only open this table on the neighbour's page number. A stale key coming back from the per-route session blob is dropped the same way, while a sort the table does own is still restored from both sources.

## 2026-08-24 — v13.6.15

### Changed

- **The default panel no longer ships dead controls.** A fresh install's header carried four user-menu entries with no `command` behind them (account settings, notification preferences, change password, help) plus notification and message popovers filled with invented orders, payments and contacts — hardcoded Turkish strings in an otherwise bilingual kit, with a permanently lit badge and a "mark all read" action that did nothing. All of them are gone; the profile link, the language submenu, logout, the appearance popover and the system-admin developer popover stay. The Dashboard's `Export`, `New Report`, `View All` and `View Report` buttons, which were equally inert, were removed too, and the demo dashboard now opens with a translated banner (`sk-common.demo_banner`) stating that its metrics are sample content. The charts, KPI cards and tables are unchanged, so the screen still shows what the kit's components can do — it just no longer presents fabricated business data as if it were the consumer's own. Translation keys for the removed menu entries were left in the language files.
- **The frontend lint gate is enforced instead of merely running.** `npm run lint` exited 0 while reporting 2,708 warnings across 33 files (2,473 `vue/html-indent`, 231 `vue/max-attributes-per-line`, four other template-formatting findings), so CI's lint step could never fail and a genuinely new warning was invisible in the noise. The whole baseline was mechanically fixed with `eslint --fix` — formatting only, no behavior touched. The gate is enforced through a new `lint:ci` script (`--max-warnings=0`) that the kit's own CI runs; the consumer-facing `npm run lint` still reports warnings without failing, so warnings in an installed app's own code do not break its lint step or pipeline.
- **Inertia pages are resolved lazily, so the first visit no longer downloads the whole panel.** Both page globs in `resources/js/app.ts` were eager, which put all 54 Vue pages — the file manager, the Tiptap editor, every settings screen — into the initial bundle even for a visitor sitting on the login form; a catch-all `vendor` chunk in `vite.config.ts` then pinned those feature dependencies to that same payload. The globs are now lazy and hoisted to module scope, `resolve` returns the matched loader's promise (Inertia v3 awaits an async resolver on the client and in SSR alike, so the previous "SSR needs a sync resolver" constraint no longer holds), and the catch-all chunk is gone so single-page dependencies fall behind their own dynamic-import boundary. The measured initial payload drops from 652.2 kB to 390.4 kB gzip (−40%), split across 121 chunks with 54 dynamic imports off the entry. App-over-vendor page precedence, the `Page not found` error and the language globs (which stay eager) are unchanged. `scripts/ci/check-bundle-budget.mjs` now gzips the entry's static import closure and fails CI above 500 kB, so a regression is caught rather than shipped.
- **Two stale leftovers are gone from the shipped scaffold.** `app.blade.php`'s `<title>` fallback read `Starter Kit 12` — a version number that went stale at every release, and one that only ever surfaced when `app.name` was unset; the fallback is now just `Starter Kit` and carries no version. The Files page separately imported `MyShareLinksDrawer` and rendered it under `v-if="false"`, so ~190 lines of a component still waiting on its backend endpoint were pulled into the Files page's own chunk and downloaded by anyone opening that page, without ever being reachable. That import and its dead state (`sessionLinks`, `drawerVisible`, `drawerMediaId`, `onShareRevoked`) are removed, so the Files page no longer carries it. The component file stays where it is — the recursive vendor page glob still sees it and a build still emits a (now never-fetched) chunk for it — and a comment records what to re-wire once `GET /file-manager/share?media_id=X` exists.

### Fixed

- **Install docs no longer steer new projects onto an ancient release.** The documented flow required the package with `:^13.0`, a range wide enough to include `v13.0.1` — the last release that still accepted `spatie/laravel-activitylog:^4.9`. Because `laravel/laravel` itself only requires PHP 8.3 while this kit (and `activitylog:^5.0`) requires PHP 8.4, `composer create-project` succeeds on PHP 8.3 and Composer then resolves quietly down to `v13.0.1` instead of reporting the platform mismatch; `composer update` afterwards correctly answers "nothing to update". README and the install guides now require `:^13.6`, so Composer fails with the real reason, and they state the PHP 8.4 floor explicitly alongside `composer why-not lvntr/laravel-starter-kit 13.6.14` for diagnosing an unexpected resolved version. The Turkish README and the `sk:upgrade` remediation hint were missed by that pass and still printed `:^13.0`; both now match, and the Turkish README carries the same PHP 8.4 / Node 20.19+ warning as the English one.
- **The production CSP no longer blocks previews served from cloud storage.** `img-src` allowed only `'self' data: blob:` while the kit supports `local`, `s3` and DigitalOcean Spaces disks and the FileManager hands the browser signed URLs on the bucket's own origin — so on a remote disk every preview, and every download the frontend fetches, was blocked by the policy the kit itself sets. `SecurityHeaders` now derives the origins of the media-library disk and the public disk (a disk `url` such as a CDN base, an s3 `endpoint` plus its `*.host` bucket-subdomain form, or the region/bucket pair for plain AWS) and appends them to `img-src`, the new `media-src`, and `connect-src`. Additional origins — a remote image embedded in the welcome message, for instance — go in `starter-kit.security.csp_extra_origins`, which accepts `http(s)` origins only. A response that already carries a CSP is still left untouched, and `local` still gets no policy at all.
- **`sk:doctor` log checks report the effective setting on a config-cached app.** `LogChannelCheck` and `LogStackCheck` read `env()` directly, and once `config:cache` has run `.env` is not loaded, so both reported their defaults rather than what the application actually uses — on the exact deployments where a doctor run matters most. They now read `logging.default` and `logging.channels.stack.channels`. `LogStackCheck` additionally judges only the channel that is actually active: it read `logging.channels.stack` unconditionally, so an app on `LOG_CHANNEL=daily` was warned about a stack its log records never reach, while `LOG_CHANNEL=single` — genuinely unrotated — passed as OK. The check now resolves `logging.default`, expands it into its member channels when it is a stack, and warns when any resolved channel uses the `single` driver, pointing at whichever knob actually reaches the offending channel: `LOG_STACK` only when the active channel is the framework's own `stack`, `logging.channels.<name>.channels` for a differently named stack, and `LOG_CHANNEL` otherwise. That expansion mirrors `LogManager::createStackDriver()` rather than approximating it: a `channels` value written as a string (`LOG_STACK=single,daily`) is exploded on commas instead of being read as one channel name, and members are resolved recursively, so a `single` nested one stack deeper is no longer reported as rotated. A configuration cycle terminates on the resolution path instead of recursing.
- **A cached config no longer silently disables Inertia SSR.** When the consumer has not published `config/inertia.php`, the service provider set `inertia.ssr.enabled` from `env('INERTIA_SSR_ENABLED')` on every boot. `config:cache` captures that override correctly while `.env` is still loaded, but the same code then re-ran on each cached request with `env()` returning null and stomped the cached `true` back to `false`. The override is now skipped when the configuration is cached. Note for existing installs: an app that set `INERTIA_SSR_ENABLED=true` and ran `config:cache` was in fact still rendering client-side, and SSR genuinely engages after this fix — make sure `php artisan inertia:start-ssr` is running. If it is not, Inertia degrades to client-side rendering rather than erroring (`HttpGateway::dispatch()` returns `null` when the bundle is missing or the connection fails, unless `inertia.ssr.throw_on_error` is enabled).
- **An HTTPS asset URL is no longer rewritten to a protocol-relative one.** The mixed-content guard in `SettingsController` and `SettingsDefaultsQuery` stripped the scheme from both `http://` and `https://` public-disk URLs, so an `https://` asset opened over an HTTP page downgraded to HTTP — the opposite of what the "never a downgrade" comment claimed. Only `http://` URLs are rewritten now; an `https://` URL is never mixed content and is passed through as-is.

### Security

- **Twenty-five of the kit's own routes are no longer ungated by omission.** `CheckResourcePermission` derives a permission from a route's name, and a route with no name, fewer than two name segments, or an action segment outside the middleware's ability map resolves to nothing — previously that meant the request passed through in total silence. Twenty-five routes the package itself registers fell into that gap: the five `settings.contentLanguages.*` endpoints, fifteen `settings.update.*` / `settings.upload.*` / `settings.delete.*` writes, `settings.testMail`, `roles.syncPermissions`, and the `roles.bulk` / `users.bulk` endpoints. They are now pinned to a permission by a route-name contract that lives inside the package, so an existing installation gets the fix from `composer update` alone — the route files `sk:install` copied into your app are untouched. Every mapping was chosen to be behavior-neutral: the settings routes already enforced the same permission through an explicit `check.permission:` argument, and `roles.syncPermissions` is additionally restricted to `system_admin` by its own controller. `roles.bulk` and `users.bulk` are declared exempt rather than mapped: the ability they require depends on the action named in the request body, and `BulkActionDispatcher` already authorizes every item against the handler's own ability, so any static route-level mapping could only over-deny (`.delete`, `.update` and `.read` each break a different legitimate role). `system-health.run` is likewise exempted by name, because its controller already calls `Gate::authorize('system.health.view')` and its route group is restricted to `system_admin`. A route that carries its own explicit `check.permission:<permission>` argument is also no longer double-judged by the parameterless group pass.

### Added

- **`sk:doctor` gained an `unresolved-routes` check.** It lists every route in the application, including consumer-added ones, whose permission cannot be derived by `CheckResourcePermission` — the same routes that currently pass on a logged warning rather than a resolved permission. Run `php artisan sk:doctor --only=unresolved-routes` to see them; each one is fixed by a `<resource>.<action>` route name, an explicit `check.permission:<permission>` argument, or a listing under the new `starter-kit.permissions.unrestricted_routes` config key.
- **Two new `starter-kit.permissions` config keys.** `allow_unresolved` (env `STARTER_KIT_ALLOW_UNRESOLVED_ROUTES`, default `true`) controls whether a route whose permission cannot be resolved at all is allowed through with a logged warning or denied; unlike the existing `allow_unmapped`, it keeps applying in production once flipped, because an unresolved route is a structural route/ability-map mismatch rather than a per-host data gap. `unrestricted_routes` lists `Str::is` route-name patterns that are deliberately permission-free and are exempt from both the check and the doctor warning.

- **A new project installs fail-closed on unresolved routes; an existing one is untouched.** `sk:install` writes `STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false` into the `.env` it creates. A fresh app has no legacy route to grandfather in, so it starts strict and its first ungated route surfaces during development rather than in production. Nothing carries that value into an app that already exists: `ensureEnvFile()` copies `.env.example` wholesale **only on a first install**, and the re-install path now skips a small `FIRST_INSTALL_ONLY_ENV_KEYS` list, so re-running `sk:install` on an installed app does not add the key either. `sk:update` and `sk:upgrade` never touch `.env` at all. **There is no release in which an existing installation starts denying on its own** — the `allow_unresolved` default stays `true` for anything that does not set the key, and an existing app opts in by writing the line itself once `sk:doctor --only=unresolved-routes` is clean. See the [upgrade guide](./UPGRADE.md#unresolved-route-fail-closed-is-opt-in-for-an-existing-install) for the ordered remediation path.

## 2026-08-15 — v13.6.14

### Security

- **Activity logs no longer retain credentials.** Fillable and unguarded model logging excludes `password`, `remember_token`, `two_factor_secret`, `two_factor_recovery_codes`, and attributes ending in `*_token` or `*_secret`; a password-only update now creates no activity row. The Activity Log UI also masks these keys in legacy rows. The new irreversible data migration and the idempotent `sk:redact-activity-secrets` command recursively remove them from both `attribute_changes` and `properties`; back up the database before migrating and inspect any undecodable JSON rows reported by the command. Models may extend the deny list through `sensitiveLogAttributes()`. The data migration ships inside the package (`database/migrations/`), so `composer update` plus `php artisan migrate` delivers it without `sk:update`, and it scans every row so a differently-cased key is not skipped on a case-sensitive JSON collation. `sk:doctor` gained an `activity-log-secrets` check that FAILs while credential-bearing rows remain; it is a bounded read-only probe over the first 500 rows by primary key, identical in cost on every driver, and it decides in PHP rather than through a SQL key-name filter, so a `Password`-cased key cannot be skipped by the column's collation. Its messages state what was measured — a finding over a larger table is reported as a floor ("at least N") and a clean bounded result names its window rather than clearing the table; `sk:redact-activity-secrets --dry-run --all` remains the exhaustive count (`--all` is what turns off the SQL key-name prefilter).
- **Authentication settings now fail closed before Fortify registers its routes.** Registration, password reset, and forgot-password requests return 403 when their settings are disabled. A two-factor challenge is claimed with an atomic add-if-absent cache entry, so exactly one of two concurrent redemptions can mint a token — `Cache::pull()` reads as a claim but is a separate get and forget, which rate limiting narrows without serializing. Recovery-code use is additionally protected by a database row lock.
- **User-controlled datatable labels are escaped before reaching `v-html`.** Role display names, activity-log causers, and API-client grant types can no longer inject markup. Frontend dependency locks were also refreshed with non-breaking updates including axios 1.19.0, Vite 7.3.6, esbuild 0.28.2, form-data 4.0.6, shell-quote 1.9.0, and undici 7.29.0; both production and full `npm audit` now report 0 vulnerabilities, and frontend CI enforces `npm audit --audit-level=high --omit=dev`.

### Added

- **`sk:doctor` gained a `permission-matrix` check.** `config/permission-resources.php` is user-owned and `sk:update` never writes to it, so resources and abilities the package adds in a later release never reach an existing installation — the first sign of which is usually a 403 on a screen that used to work. The check diffs the shipped matrix against the one the application has loaded and reports what is missing (`files.update`, `files.delete`, and so on), then points at `sk:seed-permissions`. It is one-directional: resources the consumer added themselves are never reported. It compares abilities by backing value, so an entry written as a `PermissionEnum` case counts the same as the string, and it expands a `null` (all abilities) declaration on the package side from the abilities the package itself ships — never from the consumer's extensible `PermissionEnum` — while treating one on the application side as covering everything.

### Fixed

- **`sk:update` no longer overwrites a consumer-extended `PermissionEnum`.** `app/Enums/PermissionEnum.php` is package-owned and refreshed on every update, but it is also a backed enum with public `for()` / `allFor()` helpers, so adding a project ability (`case Approve = 'approve';`) is the obvious thing to do — and until now that case was copied over whenever the file merely differed from the stub, with no registry check, no backup, and nothing in the summary. The copy is now guarded by the same install-time hash every other consumer-owned file already uses: a provably untouched file is refreshed, an edited one is preserved and reported separately with its merge instructions, an untracked one goes to the existing interactive prompt instead of being assumed unmodified, and `--force` still overwrites.
- **FileManager requests now work on sub-directory installations.** `withBasePath()` is idempotent and `useApi.request()` applies it centrally, preventing missing or doubled base paths.
- **Dashboard and `SkDatatable` no longer access browser-only globals during SSR.**

### Breaking

- **FileManager context authorization now uses `read`, `create`, `update`, and `delete` instead of collapsing mutations into `write`.** The built-in `global` context maps these one-to-one to `files.read`, `files.create`, `files.update`, and `files.delete`; unknown abilities fail closed. A role that previously held only `files.create` loses delete and empty-trash access, while a role that held only `files.update` loses read access. Grant the specific `files.*` abilities required by each role, then run `php artisan sk:seed-permissions`. Consumer context closures must handle the four new names and will never receive `write`; see the [upgrade guide](./UPGRADE.md). `authorizeWrite()` remains as a deprecated alias to `authorizeUpdate()` for direct callers.
- **Disabled two-factor authentication now removes Fortify's 2FA routes.** They return 404 instead of remaining registered. The 2FA management endpoints also now carry `password.confirm` because `fortify-options.two-factor-authentication` is set before route registration; direct API consumers must complete that confirmation round-trip.

## 2026-08-15 — v13.6.13

### Changed

- **The kit is now MIT licensed** (previously PolyForm Noncommercial 1.0.0). Commercial use is allowed without restriction — you can ship the kit inside closed-source and paid products, as long as the copyright and permission notice stay in place.
- **Behavior change: API Resource dates are now ISO-8601 values with an offset, not preformatted display strings.** This gives the frontend one parseable instant to format consistently in the user's resolved timezone. `format_date()` itself is unchanged and stays compatible for existing Blade, mail, export, and other display callers.
- **Behavior change: storage and display timezones are separate.** Keep `APP_TIMEZONE=UTC`; `display_timezone` now reads the new `APP_DISPLAY_TIMEZONE` variable instead of `APP_TIMEZONE`. Existing installs must add the variable and run `php artisan sk:upgrade` so its safe, repeatable config rewrite updates `config/app.php`. `sk:doctor --only=timezone-storage` reports a failure if storage is not UTC.
- **MySQL/MariaDB connection sessions are now pinned to UTC.** `sk:install` and `sk:upgrade` add literal `'timezone' => '+00:00'` entries to existing `mysql`/`mariadb` arrays in `config/database.php` without overwriting consumer values or touching other drivers. Existing installations may already carry offset application-written `TIMESTAMP` data; it stays offset until the one-time conversion in [Timezones](timezone.md) is completed. `DEFAULT CURRENT_TIMESTAMP` columns move in the opposite direction and must be excluded. `sk:upgrade` warns and asks before changing a non-UTC session with data (unattended runs without `--force` skip), but never converts rows. `sk:doctor --only=timezone-storage` now detects a non-UTC MySQL/MariaDB session, including `SYSTEM`, and warns rather than passing when the session cannot be read.
- **Users can choose their own display timezone, and datatable date filters now respect it.** A blank user preference means “follow the General site setting” and is different from explicitly choosing UTC. The shared user → site → app → UTC fallback applies across backend and frontend formatting; calendar-date filters use DST-correct, half-open UTC ranges without wrapping the indexed column.

## 2026-07-25 — v13.6.12

### Added

- **The kit's AI skills now work with Codex as well as Claude Code.** `sk:install` publishes the three skills to `.claude/skills/` and mirrors them to `.codex/skills/`, which the OpenAI Codex CLI reads natively. Edit the `.claude` copies to customize — the `.codex` mirror is regenerated on every `sk:install`/`sk:update` and never touches your own skills in that directory. `sk:install --without-ai-skill` skips both trees; `sk:update --without-ai-skill` skips regenerating the mirror for one run.

### Changed

- **The shipped AI skills were brought up to date with the current kit** (they still described the pre-v13.6.0 layout): vendor-first architecture, `sk:eject` and the install-time User/Role eject, `sk:doctor`, the full `sk:publish` tag list, `make:sk-domain --with=` extras, the real `sk:update` overwrite rules, the current composables and FormBuilder field types, SkForm's safety guards, and the theme system. Skill bodies are now in English (Turkish trigger keywords retained) so one skill set serves both assistants.

## 2026-07-22 — v13.6.11

### Fixed

- **Uploading a file no longer shows it twice, and the "unsaved changes" warning finally clears on forms with file fields.** After saving a form with an upload field, the file you had just picked stayed in the form alongside the copy that had already been stored — so the same image showed up as two entries. The same leftover file also kept the form permanently marked as changed, which meant the unsaved-changes banner and the "are you sure you want to leave?" prompt never went away, no matter how many times you saved. Saving now refreshes the stored file list from the server and clears the picker, so you see one copy and the form goes clean — and the file you just uploaded is no longer at risk of disappearing when you save the form a second time.

## 2026-07-21 — v13.6.10

### Fixed

- **The "unsaved changes" warning no longer sticks around after you save.** On forms that submit themselves, saving worked but the form still considered itself dirty — so the unsaved-changes banner stayed visible and closing or leaving the page kept asking you to confirm, even though everything had already been saved. Saving now marks the form clean straight away. If you keep typing while the save is still running, those newer edits weren't part of the save — the form stays marked as unsaved for them, so nothing is silently lost. Create forms that clear themselves after saving still clear as before.

## 2026-07-08 — v13.6.9

### Security

- **Unmapped permissions are now denied on staging/demo, not just production.** The `CheckResourcePermission` middleware used to let a request through whenever the required permission was missing from the database on any non-production environment — so a public staging or demo host could silently expose an endpoint whose permission row was forgotten. It now denies everywhere except `local` (local still warns and allows for dev convenience). If you deliberately want the old behavior on non-production hosts, set `STARTER_KIT_ALLOW_UNMAPPED_PERMISSIONS=true`. See [UPGRADE.md](./UPGRADE.md).
- **Permission lookups are now Octane-safe** — the seeded-permission list is cached for a short time (60s) instead of for the whole worker lifetime, and both `php artisan sk:seed-permissions` and the Roles screen's permission sync clear it immediately, so newly seeded permissions take effect right away under Octane.

### Changed

- **Datatable column visibility/order preferences moved from `sessionStorage` to `localStorage`.** If you've customized which columns are shown/hidden or reordered in a `SkDatatable`, that preference resets once after upgrading — no data loss, purely cosmetic, and you can just re-set it.

## 2026-07-04 — v13.6.8

### Quality & UX sprint

A broad quality-control pass: audit-log coverage, install/upgrade DX, accessibility, and a login-throttle security fix. One published-file change needs `sk:update` — see [UPGRADE.md](./UPGRADE.md).

#### Security

- **`login_throttle = '0'` no longer fully disables the web login rate limiter** — it now swaps to a deliberately generous floor limiter instead of removing throttling entirely, so no admin setting can leave web login unthrottled.

#### Added

- **Audit log now covers role/permission changes, Settings, API Clients/Tokens, share links, and Content Languages** — these were previously invisible outside a log file (or not logged at all); they now show up in the ActivityLog admin screen. Setting values are never logged, only which keys changed.
- **`sk:install` can resume after a failure** — `php artisan sk:install --resume` picks up exactly where an interrupted install left off, and a failed step now prints a clear message instead of a raw stack trace. A Node.js version check runs up front so an old/missing Node produces a warning, not a cryptic crash mid-install.
- **`sk:doctor` checks Node.js version and whether a queue worker is actually running**, and no longer silently reports "OK" when it can't detect a cron heartbeat. Individual checks now time out instead of being able to hang the whole command.
- **`sk:eject` now asks for confirmation** before ejecting a domain (unless you pass `--force`/`--dry-run`/`--no-interaction`) — ejecting means the domain stops receiving kit updates, so it's no longer a silent one-way door.
- **Datatable is keyboard-accessible** — sortable headers, the search-clear button, and filter-remove buttons all work with Tab + Enter/Space now, and an empty table tells you whether that's because there's no data or because your filter matched nothing (with a one-click "clear filters").
- **Forms are safer to use** — double-submitting is now impossible, leaving a form with unsaved changes prompts a confirmation, a failed data/option load shows a retry option instead of failing silently, and required fields are announced to screen readers.
- **FileManager shows overall upload progress** across multiple simultaneous uploads, and the image lightbox supports arrow-key navigation between images.

#### Changed

- CI now fails the build on lint errors instead of only warning.
- `--no-interaction` installs get a fresh random admin password (printed at the end) instead of the old fixed `password`.
- A handful of backend consistency cleanups (centralised 422 error mapping, shared definition-controller logic, unified definition cache invalidation) with no visible behavior change.

## 2026-07-03 — v13.6.7

### Rich-text editor's empty area is now clickable

A single targeted CSS fix — no API or setup change.

#### Fixed

- **Clicking below the last line of text in the rich-text editor did nothing** — `EditorInput.vue` sets `minHeight` as inline `min-height` on the editor's wrapper, but the inner ProseMirror element used `height: 100%`. Percentage heights only resolve against a parent with a definite height, so ProseMirror only grew to fit its own content — the rest of the visually-tall box sat outside the real `contenteditable` region, so clicking or typing there was ignored. The wrapper is now a flex column and ProseMirror uses `flex-1` instead, so the editable area fills the whole configured height.

## 2026-06-20 — v13.6.6

### Activity log accepts UUID and numeric subjects

A single targeted database fix — no API or setup change.

#### Fixed

- **`sk:seed-permissions` no longer crashes with a uuid cast error** — the activity-log table created its polymorphic `subject_id` / `causer_id` columns as native `uuid`. But the kit logs activity on `User` (uuid key) **and** on the Spatie `Permission` / `Role` models (numeric/bigint keys), so seeding permissions failed with `SQLSTATE[HY000] 4078: Cannot cast 'bigint' as 'uuid'`. A new migration widens both id columns to `char(36)`, which stores a 36-char uuid and any numeric id alike — one polymorphic column now fits every audited model. The migration converges every prior state (native uuid, legacy bigint, legacy char(36)) to `char(36)`; existing apps pick up the fix on the next `php artisan migrate`.

## 2026-06-14 — v13.6.5

### Translation bundle now ships with the package

A packaging fix — no API or setup change.

#### Fixed

- **Fresh installs no longer show raw translation keys** — the pre-compiled kit translation bundles (`resources/js/lang/php_{en,tr}.json`) were listed in `.gitignore`, so they never entered Git and were absent from the Composer dist (which is a `git archive` of tracked files only). A freshly installed app received only the build script, not the bundles, so every kit i18n key (`sk-menu.*`, `sk-setting.*`, …) rendered as its raw key instead of the translated label. The two bundles are now tracked and shipped. The consumer does not build the package, so — unlike the consumer-built theme bundle — these must be committed to reach `vendor/`; the build script's own docs already specified "COMMITTED and shipped".

## 2026-06-14 — v13.6.4

### Datatable inline filter dropdown fix

A single targeted fix — no API or setup change.

#### Fixed

- **Inline filter dropdown no longer clipped** — a select filter's inline pill menu was rendered as an `absolute` element inside the table card, so a long option list was cut off at the card / scroll-container `overflow` edge. The menu is now teleported to `<body>` as a fixed overlay (the same approach PrimeVue's own `Select` uses via `appendTo`): it is positioned from its trigger, re-aligns on scroll/resize, caps at `min(60vh, 420px)` with its own scroll, and closes on outside-click / Escape. The `panel`-placement popover variant is unchanged (it already rides PrimeVue's overflow-visible portal).

## 2026-06-13 — v13.6.3

### Admin UI polish

A round of admin-panel UI refinements — no API or setup change.

#### Changed

- **Aura sidebar footer is a version pill** — the aura-theme sidebar footer became a single-row pill card: a green status dot and the app name on the left, the version (monospace) pushed to the right edge, with the same left/right inset as the nav item cards above it. Scoped to the aura theme only; the `main` theme footer is unchanged.
- **Account menu drops the external-link arrow on plain links** — the topbar user/account dropdown no longer shows the hover `↗` arrow on ordinary link items (My Profile, Account Settings, Change Password, Help, Logout). Submenu items keep their chevron and the active language keeps its check mark.
- **Datatable filter popover is panel-only** — the funnel button and its popover now appear only when a filter uses `panel` placement; `inline()` filters are no longer duplicated inside the popover. The **Activity Logs** page now renders all three filters (Event, Model, Date) inline in the toolbar, so its funnel/popover is gone entirely.

#### Fixed

- **`sk:install` / `sk:update` banner version label** — the installer/updater header now reads `v13.6.x` (was the stale `v13.5.x`). Cosmetic only; the historical `v13.5.0+` behaviour notes are unchanged.
- **Datatable `value`-mode tags resolve i18n keys at render time** — `tagLabels()` values are translated when the cell renders, not when the builder runs. The builder is built in a page's `<script setup>` body before the i18n bundle loads, so an eager `trans()` there froze the raw key (the Content Languages table showed `sk-content-languages.directions.ltr` instead of "Left to right (LTR)"). Literal (non-key) labels are unaffected since `trans()` returns them unchanged.
- **Content Languages form — spurious required asterisks removed** — FormBuilder fields are required by default, so `flag`, `fallback_code` and `sort_order` (all `nullable` server-side) drew a red `*`. They are now `.optional()`, matching their validation rules; `code`, `name`, `native_name`, `direction` keep the asterisk.

## 2026-06-13 — v13.6.2

### Admin panel layout & form alignment fixes

A batch of `main`-theme polish fixes for the admin panel — no API or setup change, just visual correctness.

#### Fixed

- **Roles form basics is a responsive 3-column grid** — the name / display-name / tag-color fields now sit side by side (`FB.form().cols(3)`) and stack vertically on small screens instead of always being full-width.
- **Permissions table is flush to its card** — the roles permission matrix uses the `SkCard` `flush` prop, so its row borders reach the card edges instead of floating inside the body padding (cells keep their own inner padding).
- **Translatable field inputs align with siblings** — the locale tab pills (`TranslatableInput`) were taller than a plain label and pushed their input down; the pills now match the plain-label height, so every input in a grid row starts on the same line.
- **Sidebar no longer crushes rows** — with multiple menu groups expanded the nav compressed its children before scrolling; direct children are now `shrink-0`, so overflow scrolls instead of overlapping.
- **Sidebar footer aligns with the page footer** — the sidebar footer height is pinned to `h-footer` (56px) so its top border lines up with the page footer's border at the bottom of the screen.

#### Changed

- **Security settings sub-tab "Cloudflare Turnstile" → "Bot Protection"** — the security sub-tab label (EN/TR) and the related `SecurityTab` section now use the provider-neutral name.

## 2026-06-13 — v13.6.1

### `sk:update` self-heals stale component imports

A single targeted fix — no API or setup change.

#### Fixed

- **`sk:update` no longer leaves stale imports behind after a component moves to vendor** — when a component moves out of stubs into `@lvntr/components`, its old local copy is force-deleted, but user-customized pages that still imported the deleted local path were left untouched and broke the Vite build with an `ENOENT` load-fallback error (e.g. `@/components/Auth/TurnstileWidget.vue`). `sk:update` now rewrites such stale import specifiers to the vendor path (`@lvntr/components/ui/TurnstileWidget.vue`) across `resources/js`, completing the migration that started in v13.6.0 on existing consumers' customized Auth pages (`Login`, `Register`, `ForgotPassword`).

---

## 2026-06-13 — v13.6.0 (continued)

### Vendor-first Phase 2 — Settings-tab controllers, Definitions/Media, ContentLanguage

Phase 2 finishes the vendor-first move started in Phase 1 by relocating the remaining controllers that back the vendor Settings tabs plus two API/Service controllers, and fully vendorizing the ContentLanguage domain. The Vue and migrations were already vendor — this is a PHP-layer-only move. Fresh installs receive no app copies of these files; existing installs are migrated by `sk:update` under the same hash guard.

#### Changed

- **Vendor-first HTTP layer for ApiClient, ApiToken, SystemHealth, ContentLanguage, Definitions (Api + Service), and MediaUpload** — these controllers (plus their FormRequests / API Resources where present) now live in `Lvntr\StarterKit\Http\...` and are aliased back to their `App\Http\...` FQCNs for backward compatibility. An app copy disables the alias automatically so your customisation continues to win. Route names, permission keys, and the Passport secret single-reveal are unchanged.
- **ContentLanguage domain vendorized** — `Actions` / `DTOs` / `Queries` moved to `Lvntr\StarterKit\Domain\ContentLanguage\`. The `App\Models\ContentLanguage` model stays app-owned (never aliased — keeps policy discovery + route-model binding intact); vendor code references it by `App\` FQCN.

#### Added

- **`sk:eject` gains five entries** — `SystemHealth`, `ContentLanguage`, `Definitions`, `MediaUpload`, and a full-HTTP-layer `ApiClient` (which also ejects the `ApiToken` controller/request/resource). The ejectable domain count rises from 10 to 14.

#### Migration

Run `composer update lvntr/laravel-starter-kit && php artisan sk:update`. See `docs/UPGRADE.md` (v13.5.11 → v13.6.0, "Behavior-module HTTP layer moved to vendor — Phase 2").

---

## 2026-06-13 — v13.6.0 (continued)

### Behavior-module HTTP + Vue layers moved to vendor; `sk:eject` supports `Files`

Five built-in admin modules — **Files, Logs, ActivityLogs, ApiRoutes, Settings** — now run their controllers, FormRequests, and Vue admin pages entirely from the vendor package. Fresh installs receive no app copies of these modules. Existing installs are migrated by `sk:update` under a hash guard (unmodified copies removed; modified copies preserved and reported). Vue migration additionally requires the `@lvntr/pages` vendor-fallback glob in `app.ts`.

#### Added

- **`sk:eject Files`** — ejects the FileManager admin Vue pages (`resources/js/pages/Admin/Files/`) into your app for UI customisation. The FileManager backend (controller, FormRequests, route-registry infrastructure) always stays vendor-managed; only the Vue layer is copied. Reverting deletes the copied pages and the vendor copy resumes via `app.ts` fallback.
- **Vendor-first HTTP layer for Logs, ActivityLogs, ApiRoutes, Settings** — controllers and FormRequests now live in `Lvntr\StarterKit\Http\...` and are aliased back to `App\Http\Controllers\Admin\*` for backward compatibility. An `app/Http/Controllers/Admin/SomeController.php` file in your app disables the alias automatically so your copy continues to win.
- **Group-atomic migration in `sk:update`** — vendor-first modules are migrated per layer (PHP and Vue independently). If any file in a layer is modified, the entire layer is preserved. No half-deleted module is ever produced.

#### Changed

- **`sk:eject` manifest extended** — `Files` domain added (Vue-only: `backend: ''`). The available domain list in the command signature now includes `Files`.

#### Migration

Run `composer update lvntr/laravel-starter-kit && php artisan sk:update && npm run build`. For a customised module, `sk:update` preserves it and reports it; run `sk:eject <Module>` to take full explicit ownership. See `docs/UPGRADE.md` (v13.5.11 → v13.6.0, "Behavior-module HTTP + Vue layers moved to vendor") for the three-scenario guide.

---

## 2026-06-11 — v13.6.0 (continued)

### Install-time domain eject for User + Role

Fresh installs now automatically eject the `User` and `Role` domain runtime into `app/Domain/`. These are the two domains most often customised, so they arrive as project-owned files without any extra step.

#### What changes on a fresh install

- `app/Domain/User/` and `app/Domain/Role/` are created with backend classes (Actions, DTOs, Queries, Events, Listeners) rewritten to the `App\Domain\` namespace.
- `DomainServiceProvider` receives the `Event::listen` bindings for the six audit events so activity logging continues without interruption.
- Future `composer update` runs do not touch these directories — you own them.

#### Opting out

```bash
php artisan sk:install --without-eject
```

Both domains remain vendor-resident and resolve via `class_alias`. You can run `sk:eject User` / `sk:eject Role` manually at any time.

#### Reverting after install

Delete `app/Domain/User/` and `app/Domain/Role/`, remove the injected `Event::listen` lines from `app/Providers/DomainServiceProvider.php`, and run `composer dump-autoload`.

#### Existing installs

No change. The eject step runs only when `storage/starter-kit/hashes.json` does not yet exist (first install). On existing installs the registry is already present so the step is skipped. Existing projects are unaffected.

#### New flag on `sk:eject`

`sk:eject` gains a `--skip-autoload` flag used internally by the installer so it does not run `composer dump-autoload` per-domain (the installer runs one consolidated dump after all ejects complete). This flag is not needed for normal manual `sk:eject` use.

---

## 2026-06-06 — v13.6.0

### Minor release — Vendor-runtime migration completed + structured theme/layout/CSS system

13.6.0 bundles every change made since the last published release (v13.5.11) into a single version. It completes the "package runtime runs from vendor" migration on both the backend and the frontend, and reorganises the admin-panel layout and CSS into a structured, override-ready theme system. **No visual change** — the default build (`VITE_SK_THEME=main`) is byte-identical to v13.5.11. The sections below group the bundled changes by area.

### Permission directive plugin resolves from vendor

The `v-can` / `v-role` Vue plugin (`resources/js/plugins/permission.ts`) is now served from the vendor package by default — the same resolution kit composables already use. A `@/plugins/<name>` import resolves to your local copy when one exists, otherwise it falls back to the vendor copy, so the kit can ship directive fixes without a stub re-copy. **No behavior change**: the directives are identical and `app.ts` still imports `@/plugins/permission` unchanged.

#### Changed

- **`resources/js/plugins/permission.ts`** — moved into the vendor package. The dead, unused `useCan()` export was dropped (the live composable is `@/composables/useCan`); the file now ships only `PermissionPlugin` (the `v-can` / `v-role` directives), with no auto-import dependency.
- **`vite.config.ts`** — new `@/plugins/*` alias `customResolver` (`resolvePlugin`) mirroring `@/composables/*`: local-override-then-vendor fallback, ordered before the bare `@` alias.
- **`tsconfig.json`** — new `@/plugins/*` path mapping (local + vendor).

#### Added

- **`sk:publish --tag=plugins`** — publish a local, editable copy of the Vue plugins to customise the permission directives.

#### Migration

No action required — resolution is automatic. Your existing `resources/js/plugins/permission.ts` keeps shadowing the vendor copy; delete it to adopt the vendor version. See `UPGRADE.md`.

---

### All CSS cascade layers are now override slots

Every CSS layer in the theme system is now an overridable slot. Previously `fonts.css`, `_base.scss`, `_auth.scss`, and `utilities.css` were fixed imports outside the resolver; they now live under `themes/main/` and are emitted by `scripts/sk-theme-build.mjs` in the correct cascade order alongside `tokens`, `layout/*`, and `components/*`. **No visual change** — the default build with `VITE_SK_THEME=main` is byte-identical to v13.5.11. The only difference is that a `custom` theme can now override any layer, including fonts, base reset, auth styles, and utility overrides, by placing a matching file under `themes/custom/`.

#### Changed

- **`themes/main/fonts.css`**, **`themes/main/_base.scss`**, **`themes/main/_auth.scss`**, **`themes/main/utilities.css`** — moved from `resources/css/theme/` root into `themes/main/`. Content unchanged.
- **`scripts/sk-theme-build.mjs`** — HEAD slots (`tokens.css`, `fonts.css`, `_base.scss`) and TAIL slots (`_auth.scss`, `utilities.css`) are now resolved through `resolveSlot()` (same override-or-main fallback used by `layout/*` and `components/*`). The cascade order is preserved: `tokens → fonts → _base → layout/* → components/* → _auth → utilities`.
- **`theme/theme.css`** — now contains only `@import './_active.css'`; the former fixed `_auth.scss` import is gone.
- **`app.css`** — the former fixed `utilities.css` tail import is gone; `utilities.css` is now the last slot emitted by the resolver.
- **`themes/custom/README.md`** — updated to list all overridable slots including `fonts.css`, `_base.scss`, `_auth.scss`, and `utilities.css`.

#### Migration

No action required. `sk:update` delivers the updated files. The default build is byte-identical. To override a previously fixed layer, place the matching file under `themes/custom/` (e.g. `themes/custom/fonts.css`). See `docs/theme.md` — Complete slot reference.

---

### AppShell layout composition + build-time theme-override system (`themes/main` / `themes/custom`)

The admin-panel layout and CSS are reorganised into a structured, override-ready system. **No visual change** — the default build is byte-identical to the previous published version. The layout shell is split into a reusable `AppShell.vue` (structural backbone, sidebar state, named regions) and a thin `AdminLayout.vue` composition that wires in the standard admin components. The CSS monolith (`_admin.scss` + scattered `_*.scss` partials) is dissolved into a `themes/main/` directory tree of individual slot files. A new opt-in `themes/custom/` directory and `scripts/sk-theme-build.mjs` theme resolver enable per-slot overrides at build time: set `VITE_SK_THEME=custom`, place a file in `themes/custom/components/datatable.css`, and only that slot is replaced — everything else falls back to `main`. See `docs/theme.md` for the full reference and custom-override recipe.

#### Added

- **`AppShell.vue`** (`resources/js/layouts/AppShell.vue`) — reusable structural layout shell. Owns the `.admin-layout` / `.admin-main` / `.admin-content` skeleton and `useSidebar` state (single owner). Exposes five named slots: `#sidebar` (scoped: `collapsed`, `mobileOpen`, `isMobile`, `closeMobile`), `#header` (scoped: `collapsed`, `isMobile`, `toggle`), `default`, `#footer`, `#overlays`.
- **`themes/main/` CSS tree** — `tokens.css` (CSS custom properties, light + dark), `layout/{shell,sidebar,header,page-header,footer}.css`, `components/{card,confirm,datatable,dialog,editor,formbuilder,menus,navigation,primevue,tabs,tag,toast}.css`. Values are byte-identical to the removed partials.
- **`themes/custom/` skeleton** — empty override-theme directory with a `README.md` explaining the full-replacement + fallback model.
- **`scripts/sk-theme-build.mjs`** — theme resolver. Reads `VITE_SK_THEME` (default `main`); walks `themes/main/` for the canonical slot list; for each slot emits `themes/<active>/<slot>` if it exists, else `themes/main/<slot>`; writes `theme/_active.css`. Override slots are annotated `/* override */` in the output. Invoked as an explicit `&&` step in `dev` and `build` — not via npm lifecycle hooks — so it works correctly under `ignore-scripts=true`.
- **`npm run theme:build`** — standalone script alias for the resolver (also runs as an explicit step in `dev` and `build`).
- **`VITE_SK_THEME=main`** added to `.env.example` with inline documentation.
- **PrimeVue preset resolver** — `scripts/vite-plugin-sk-theme.mjs` now intercepts the `@/theme/preset` import at build time and resolves it to `resources/js/theme/themes/<active>/preset.ts` when that file exists, otherwise falls back to the base `resources/js/theme/preset.ts`. The base file stays in place — no consumer migration required. The `resources/js/theme/themes/custom/` skeleton ships empty so the default build is byte-identical to the previous version.
- **`docs/theme.md` + `docs/theme.tr.md`** — updated: two-layer overview table (CSS override vs PrimeVue preset), PrimeVue preset layer section with directory layout, custom-palette recipe, and dependency-chain note (`tokens.css` reads `--p-*` variables).

#### Changed

- **`AdminLayout.vue`** refactored into a thin `AppShell` composition. External prop/slot contract (`title`, `subtitle`, `backUrl`, `default`, `page-actions`) is **unchanged** — all existing pages continue to work without modification.
- **`theme.css`** now imports a single `_active.css` instead of an explicit list of partials. Import order is preserved.
- **`_base.scss`** retains only base/reset rules; the `:root` / `.dark` CSS custom-property blocks moved to `themes/main/tokens.css`.

#### Removed

- **`_admin.scss`** — replaced by `themes/main/layout/*`.
- **`_datatable.scss`, `_formbuilder.scss`, `_dialog.scss`, `_toast.scss`, `_tag.scss`, `_card.scss`, `_editor.scss`, `_tabs.scss`, `_menus.scss`, `_navigation.scss`, `_confirm.scss`, `_primevue.scss`** — replaced by `themes/main/components/*`.

#### Fixed

- **Theme resolver now works under `ignore-scripts=true`** — the resolver is chained directly into the `dev` and `build` scripts (`node scripts/sk-theme-build.mjs && vite …`). Previously it ran as `predev` / `prebuild` lifecycle hooks, which npm silently skips when `ignore-scripts=true` is set (common in consumer projects and CI), causing `_active.css` to be absent and the build to hard-fail. The `predev` and `prebuild` entries have been removed.

#### Migration

`sk:update` delivers all new stubs. No migration required if no moved file was customised — `npm run build` produces a byte-identical panel. If you customised a moved file, copy your changes into the corresponding `themes/main/` slot or use `themes/custom/` for an isolated override. See `docs/UPGRADE.md` (v13.5.11 → v13.6.0) for details.

---

### Kit composables run from vendor; local-first resolver; `sk:publish --tag=composables`

v13.5.12 moves 15 kit composables out of the stub scaffold and into the vendor library. They now run directly from `vendor/lvntr/laravel-starter-kit/resources/js/composables/` and are updated with every `composer update`. Import paths are fully unchanged — `@/composables/<name>` resolves local-first (consumer file wins if it exists) then falls back to the vendor copy, so no consumer import statement needs to change. `useAdminMenu` and `index.ts` remain as editable stubs because they depend on the consumer's generated routes and project-specific menu definition. `TurnstileWidget.vue` was likewise moved to the vendor library (`@lvntr/components/ui/TurnstileWidget.vue`).

#### Added

- **15 composables in vendor** — `useApi`, `useCan`, `useConfirm`, `useDarkMode`, `useDatatableSelection`, `useDefinition`, `useDialog`, `useFileShare`, `useFlash`, `useImageLightbox`, `useMenuBuilder`, `usePageLoading`, `useRefreshBus`, `useSidebar`, `useUrlTab` shipped inside the package. Updated via `composer update` — no manual file management.
- **`sk:publish --tag=composables`** — copies vendor composables into `resources/js/composables/` for project-level customization. The local-first resolver picks up the local copy automatically; no alias or build-config changes required.
- **`TurnstileWidget.vue` moved to vendor** — now available at `@lvntr/components/ui/TurnstileWidget.vue`.

#### Removed

- **15 composable stubs** — removed from the scaffold. Existing projects are unaffected (local-first resolver keeps using local copies). To opt into vendor-managed upgrades: delete unmodified composable files from `resources/js/composables/`, keeping `useAdminMenu.ts`, `index.ts`, and any file you have customized.

### Backend runtime classes & third-party configs run from vendor

The same release continues the v13.5.0 "runtime runs from vendor" migration on the backend. A set of helper classes, validation rules, and middleware moved out of the published scaffold into the vendor package, and three third-party config files are no longer copied into your app. Existing apps are not affected — `App\…` imports keep resolving (via `class_alias` for pure-moved classes, or a thin `App\` shim for the rest), and a config you already published keeps winning. The only required step is `composer update`. See `docs/UPGRADE.md` (v13.5.11 → v13.6.0) for the full migration guide.

#### Added

- **Vendor-resident backend classes** — `HtmlSanitizer`, `TranslatableQueryHelpers`, `MediaPathGenerator`, `Scramble\ApiResponseExtension`, and the `AssignTraceId` / `SetLocale` / `ValidateTurnstile` middleware now run from `Lvntr\StarterKit\*`. No stub is copied to the app; old `App\…` imports resolve via `class_alias`. `ApiResponseExtension` is now properly registered with Scramble.
- **Vendor classes with a thin `App\` shim** — `DatatableQueryBuilder`, `HttpsOrLocalhostUrl`, and `TurnstileRule` keep their `App\…` import path while running from vendor.
- **`HasTranslatableRules` trait → vendor (direct import)** — now `Lvntr\StarterKit\Support\HasTranslatableRules`. Traits cannot be aliased, so import it from the vendor namespace (same convention as `HasActivityLogging` / `HasMediaCollections`).

#### Changed

- **Third-party config overrides at runtime** — `config/activitylog.php`, `config/inertia.php`, and `config/media-library.php` are no longer published. `StarterKitServiceProvider::applyVendorConfigDefaults()` applies only the kit's required keys (media-library `path_generator` + `media_model`, activitylog `include_soft_deleted_subjects`, inertia `ssr.enabled`) at runtime and skips any config you have published. The installer no longer AST-injects the media-library path generator.

#### Removed

- **Backend scaffold stubs** — `app/Support/{HtmlSanitizer,TranslatableQueryHelpers,MediaPathGenerator,HasTranslatableRules}.php`, `app/Support/Scramble/ApiResponseExtension.php`, `app/Http/Middleware/{AssignTraceId,SetLocale,ValidateTurnstile}.php`, and `config/{activitylog,inertia,media-library}.php` removed from the scaffold. Upgraded apps keep existing copies (informational notice from `sk:update`, never auto-deleted). For the `HasTranslatableRules` trait, switch `use` imports to the vendor namespace before deleting a local copy.

## 2026-06-04 — v13.5.11

### Patch release — Standalone 3-skill set replaces monolithic bundled skill

v13.5.11 removes the previous 723-line monolithic skill (`stubs/.claude/skills/lvntr-starter-kit/SKILL.md`) and replaces it with three focused, self-contained skills distributed under `stubs/.claude/skills/`. The new skills require no additional tooling and cover the three main concerns of a starter-kit project: core rules, backend/DDD conventions, and frontend builder patterns.

`sk:install` gains a `--without-ai-skill` flag for projects that prefer not to publish any AI skill files.

#### Added

- **`stubs/.claude/skills/lvntr-starter-kit/`** — core skill: hard rules, recipe pointers, permissions/i18n config, cross-domain `references/` links.
- **`stubs/.claude/skills/lvntr-kit-domain/`** — backend / DDD skill: Actions, Services, FormRequest, Resource, Repository conventions, and domain boundary guidance.
- **`stubs/.claude/skills/lvntr-kit-frontend/`** — frontend skill: FormBuilder / DatatableBuilder / TabBuilder patterns, composables (`useApi`, `useDialog`, `useForm`), and starter-kit component rules.
- **`sk:install --without-ai-skill`** — opt-out flag; skips publishing the skill files to the host application.

#### Removed

- **`stubs/.claude/skills/lvntr-starter-kit/SKILL.md`** — the 723-line monolithic skill has been removed. If you published the old file to your host application, delete `.claude/skills/lvntr-starter-kit/SKILL.md` before re-running `vendor:publish`.

## 2026-05-30 — v13.5.10

### Patch release — SkCard primitive, card title actions slot, caption divider, SkForm grid span + 12-column support, and SkColorSelector neutral palette

v13.5.10 introduces `SkCard`, a shared wrapper around PrimeVue Card that provides a single source of truth for the kit's card surfaces. The same patch adds two consumer-facing slots powered by it: `#title-end` on `SkForm`'s root card and per-section `#section-${key}-title-end` on every `FB.section()` card. Both render to the **right** of the title in the same row, ready to host action buttons, status badges, or contextual indicators. The section slot is scoped — it exposes `{ values }` (a reactive snapshot of the current form values) so consumers can render conditionally. `SkCard` itself accepts `title`, `subtitle`, `transparent`, `divider`, and `pt` props plus `header`/`title`/`subtitle`/`content`/`footer`/`title-end` slots; class fallthrough works via `inheritAttrs: false` + `useAttrs` because PrimeVue Card opts out of attribute inheritance on its own root. `SkForm.vue` and `SkFormFieldRenderer.vue` were refactored to use `SkCard` instead of `<Card>` directly — their `cardPt`/`transparentCard`/`sectionCardPt` helpers are gone, the title flex wrapper and caption bottom-divider styles moved out of `_formbuilder.scss` into `_card.scss` (`.sk-card--divider .p-card-caption`), and now any consumer that wraps content in `SkCard` gets the same caption header behavior (title text on the left, `#title-end` on the right, subtitle below, divider underneath the caption block).

The same release also adds field-level grid span control to `SkForm`: `BaseFieldConfig.colSpan` lets any field (or a field inside a section) declare how many columns it occupies in the form grid, so layouts like "full-width title + two fields side by side" are now possible, and `.cols()` supports the full 1–12 range instead of falling back above 6. `SkColorSelector` gains the 5 neutral Tailwind families (`slate`, `gray`, `zinc`, `neutral`, `stone`), bringing the total palette to 22 families.

#### Added

- **`BaseFieldConfig.colSpan?: number`** — sets how many columns a field (or a field inside a section) spans in the form grid (`1..cols`). Omitted → existing behavior (1 cell). Values exceeding `cols` are clamped automatically; inside a section the clamp uses `sectionCols` (the section's own `cols`, or the form `cols`).
- **`BaseFieldBuilder.colSpan(n: number)`** — chainable `.colSpan(n)` added to every field builder. Example: `FB.inputText().key('title').label('Title').colSpan(12)`.
- **`SkColorSelector` — 5 neutral Tailwind families** — `slate`, `gray`, `zinc`, `neutral`, `stone` added with all 50–950 shades (official Tailwind v4 hex). Total palette: 22 families.
- **`SkCard` UI primitive** — `resources/js/components/Lvntr-Starter-Kit/ui/SkCard.vue`. Shared wrapper around PrimeVue Card used by `SkForm` (and intended for future `SkDatatable` / page-level cards) so caption behavior, the `#title-end` slot, and the bottom divider have a single implementation.
  - Props: `title?: string`, `subtitle?: string`, `transparent?: boolean` (default `false` — `true` removes background/shadow/padding, useful inside dialogs or nested cards), `divider?: boolean` (default `true` — draws a bottom border under the caption block), `pt?: Record<string, any>` (merged into the PrimeVue Card pt; consumer keys win on conflicts).
  - Slots: `header`, `title`, `subtitle`, `content` (the default slot also maps to content), `footer`, **`title-end`** (right-aligned action/badge/status slot).
  - `inheritAttrs: false` + `useAttrs` so outer `class` fallthrough still reaches the Card root (PrimeVue Card sets `inheritAttrs: false` on its own root, which otherwise blocks class propagation).
  - Exported from `index.ts` as `SkCard`.
- **`SkForm.vue` — `#title-end` slot** — new slot rendered to the right of the form-level card title. Use it for action buttons, badges, or status indicators that should live in the same row as the heading. The slot is only rendered when content is provided.
- **`SkFormFieldRenderer.vue` — per-section `#section-${key}-title-end` slot** — scoped slot rendered to the right of each section card title. Because `SkForm.vue` already forwards every slot via the generic `v-for $slots` pattern, consumers use it directly on `<SkForm>` as `<template #section-address-title-end="{ values }">`. Slot scope: `{ values }` — a reactive snapshot of the current form values, useful for conditional rendering.
- **Docs** — new "SkCard" section in `docs/ui-components.md` and `docs/ui-components.tr.md`; new "Card Title Actions Slot" / "Card Başlık Sağ Slot" section in `docs/formbuilder.md` and `docs/formbuilder.tr.md`.

#### Changed

- **`SkForm.vue` — root `<Card>` → `<SkCard>` refactor** — the internal `cardPt` computed and `transparentCard` style constant were removed; `:transparent="isTransparentCard"` is passed to `SkCard` instead. The form card's title and subtitle are now passed via `:title` and `:subtitle` props; the flex title wrapper and caption bottom-divider are produced once inside `SkCard`.
- **`SkFormFieldRenderer.vue` — section render switched to `<SkCard>`** — `sectionCardPt` replaced with a `sectionIsTransparent` helper + `:transparent` prop. The section title flex wrapper and `title-end` slot are now delegated to `SkCard`; the icon-bearing title (`SkIcon` + text) is rendered directly inside `SkCard`'s `#title` slot.
- **`RenderCtx` (SkFormFieldRenderer.vue) — `transparentCard` field removed** — `SkCard`'s `transparent` prop is the only source of truth; the floating style constant in the context object is no longer needed.
- **`stubs/resources/css/theme/_card.scss`** — added `SkCard` styles:
  - `.sk-card__title-row` (flex w-full justify-between, title row)
  - `.sk-card__title-text` (title text, inline-flex for icon alignment)
  - `.sk-card__title-end` (right slot container, shrink-0)
  - `.sk-card--divider .p-card-caption` (`pb-3 mb-1 border-b` under the caption block + `--p-surface-200` / `--p-surface-700` dark variant). Only triggers inside `SkCard` — other PrimeVue Card usages stay untouched.
- **`stubs/resources/css/theme/_formbuilder.scss`** — the transitional selectors that this work originally introduced (`.sk-fb__card*`, `.sk-fb__section-title-wrapper`, `.sk-fb__section-title-end`, `.sk-fb__card .p-card-caption`, `.sk-fb__section .p-card-caption`) were removed. A short note now points to `_card.scss`.
- **`SkForm.vue` — `colsClassMap` extended to 1–12** — values 7–12 previously fell back to the default grid; `cols(7)`–`cols(12)` now apply `md:grid-cols-N` directly.
- **`SkForm.vue` + `SkFormFieldRenderer.vue` — `colSpanClassMap`** — purge-safe static map added; top-level and in-section field wrappers receive `md:col-span-N` based on `colSpan`. Fields without `colSpan` render identically to before (no regression).

## 2026-05-21 — v13.5.9

### Patch release — SkIcon primitive, section/card grouping, and icon APIs

v13.5.9 introduces `SkIcon`, a package-agnostic icon renderer that auto-detects three formats from a single `icon: string` prop: raw SVG (`v-html`), image URL (`<img>`), or class-based icon (`<i :class>` — works with PrimeIcons, FontAwesome, MDI, Lucide, Iconify, and any other CSS icon library). A unified icon API lands across all field types via `BaseFieldConfig`: `labelIcon` / `labelIconPosition` place an icon beside the label in any layout, while `icon` / `iconPosition` place one inside the input (supported on `input-text`, `input-number`, `input-mask`, and `password` without feedback). Title fields gain their own `icon` / `iconPosition` pair. The headline feature is `SectionFieldConfig` (`type: 'section'`) and its `FB.section()` fluent builder — fields can now be visually grouped inside a PrimeVue Card with a title, subtitle, icon, and configurable column count, while the form payload stays flat (section keys are never emitted). `SkForm.vue` gained a `flatFields` computed backed by an `iterateAllFields` generator so sections are transparent to all existing field-processing logic (file upload keys, date transforms, definition preloads, dynamic selects). `SkFormFieldRenderer.vue` was extracted to handle recursive rendering and slot forwarding. `InputTextFieldConfig.icon` / `iconPosition` are now deprecated in favour of the base-level API.

#### Added

- **`SkIcon` UI primitive** — package-agnostic icon renderer. Auto-detects from a single `icon: string` prop: `<svg…` → raw SVG (`v-html`), `^(https?:|data:)` → `<img>`, otherwise → `<i :class>` (PrimeIcons, FontAwesome, MDI, Lucide, Iconify and any class-based icon set). **Security:** `icon` must only be passed from builder config (developer-controlled) — user-sourced strings are an XSS risk (the `<svg…` path uses `v-html`).
- **`BaseFieldConfig` icon fields** — shared icon API for all field types:
  - `labelIcon?: string` + `labelIconPosition?: 'left' | 'right'` (default: `'left'`) — icon beside the label in all layout paths.
  - `icon?: string` + `iconPosition?: 'left' | 'right'` (default: `'left'`) — icon inside the input. Supported types: `input-text`, `input-number`, `input-mask`, `password` (custom path — no icon when `feedback: true`). `groupPrefix`/`groupSuffix` take precedence — input icon is disabled when they are present.
- **`TitleFieldConfig` icon fields** — `icon?: string` + `iconPosition?: 'left' | 'right'`. Example: `FB.title('General').icon('pi pi-info-circle')`.
- **`SectionFieldConfig` (new field type `type: 'section'`)** — visual field grouping inside a Card:
  - `title?` (translation key, falls back to `label`), `subtitle?`, `icon?`, `iconPosition?`
  - `cols?: number` (default: parent form's `cols`)
  - `fields: FieldConfig[]` (nested — one level only; nested sections are not supported)
  - `isCard?: boolean` (default: card visible; `false` → transparent Card)
  - **Form payload stays flat** — the section's `key` is never emitted; sections are a purely visual grouping primitive.
- **`SectionBuilder` and `FB.section(title?)` factory** — fluent API: `.title(t)`, `.subtitle(s)`, `.icon(str)`, `.iconPosition(p)`, `.cols(c)`, `.isCard(enabled)`, `.addFields(...)`.
- **`BaseFieldBuilder` fluent methods** — `.labelIcon(str)`, `.labelIconPosition(p)`, `.icon(str)`, `.iconPosition(p)` now available on all field builders (moved from `InputTextBuilder` to base — same signature, no behaviour change).
- **`TitleBuilder.icon()` and `.iconPosition()`** methods.
- **`SkFormFieldRenderer.vue`** — extracted recursive field renderer. Section render, slot forwarding, and label/title icon rendering are now handled here; `SkForm.vue`'s template is simplified.
- **Docs** — 5 new sections in `docs/formbuilder.md` and `docs/formbuilder.tr.md`: Icons (Package-Agnostic), Label Icons, Input Icons, Title Icons, Section / Card Grouping. XSS security note in both languages.

#### Changed

- **`AppDialog.vue` — `confirmSeverity` no longer defaults to `'primary'`** — `state.footer?.severity ?? 'primary'` → `state.footer?.severity`. The confirm button now falls back to PrimeVue Button's own default appearance (from the theme preset). Existing dialogs that did not explicitly set `DialogFooter.severity` may see a visual change.
- **`useDialog.ts` — `DialogFooterSeverity` type widened** — `'primary'` removed (not a valid PrimeVue Button severity); `'info'`, `'help'`, `'contrast'` added. Full list: `'secondary' | 'success' | 'info' | 'warn' | 'help' | 'danger' | 'contrast'`.
- **`SkForm.vue` — flat field iteration** — `derivedDefaults`, `currentValues`, `definitionKeys`, `dynamicSelectFields`, `hasFileFields`, `dateOnlyFields` computeds are now backed by the new `flatFields` computed (iterative `iterateAllFields` generator). Fields inside sections are automatically categorised correctly (file-upload existingMediaKey resolve, date-picker toLocalDateStr transform, definition preload, dynamic optionsUrl fetch). Forms without sections render identically to before (no regression).
- **`SkFormInput.vue` — generic input icon** — the `IconField` wrapping pattern previously only for `input-text` is now active for `input-number`, `input-mask`, and `password` (custom path). Icon descriptors render via `SkIcon`, so MDI / FA / Lucide / Iconify / SVG / img URL work in addition to PrimeIcons. `BaseFieldConfig.icon` takes precedence; `InputTextFieldConfig.icon` is kept as a legacy fallback.
- **`stubs/resources/css/theme/_formbuilder.scss`** — minimal `inline-flex items-center gap` added to `.sk-fb__title` and `.sk-fb__label` for icon alignment (line-height and padding unchanged). New sections: `SKICON & LABEL/TITLE ICONS` (`.sk-icon`, `.sk-icon--svg svg`, `.sk-icon--img`, `.sk-fb__label-icon`, `.sk-fb__title-icon`, `.sk-fb__section-icon` + `--left/--right` modifiers), `SECTION CARD` (`.sk-fb__section`, `.sk-fb__section-title`, `.sk-fb__section-field`).

#### Deprecated

- **`InputTextFieldConfig.icon` and `InputTextFieldConfig.iconPosition`** — use the new `BaseFieldConfig.icon` and `BaseFieldConfig.iconPosition` instead. Legacy fields are kept for backward compatibility (`SkFormInput.vue` uses `base ?? legacy` fallback and produces the same render); they will be removed in the next major version.

#### Upgrade

```bash
composer update lvntr/laravel-starter-kit

# Re-publish affected stubs (warning: customised stubs are overridden — diff first)
# stubs/resources/css/theme/_formbuilder.scss
# stubs/resources/js/composables/useDialog.ts  ← DialogFooterSeverity type changed
php artisan vendor:publish --tag=starter-kit-stubs --force
```

**`DialogFooterSeverity` breaking change:** `'primary'` is no longer a valid value. If you used `severity: 'primary'` in any `useDialog().open(...)` call, remove it (the Button will apply its own theme default) or replace it with a valid value such as `'secondary'` or `'contrast'`. TypeScript will already flag these as errors.

**Migration:** legacy `InputTextFieldConfig.icon` calls continue to work (deprecated, kept until removal). To use the new features:

```ts
// Label icon — any field type
FB.inputText().key('email').label('Email').labelIcon('pi pi-envelope')

// Input icon — input-text/number/mask/password
FB.inputText().key('search').icon('pi pi-search')                    // PrimeIcons
FB.inputText().key('user').icon('mdi mdi-account')                   // Material Design Icons
FB.inputText().key('star').icon('fa fa-star').iconPosition('right')  // FontAwesome
FB.inputText().key('logo').icon('https://cdn.example.com/icon.svg')  // URL

// Title icon
FB.title('General Info').icon('pi pi-info-circle')

// Section / Card grouping
FB.form()
    .isCard(false)
    .addFields(
        FB.section('Personal Info').icon('pi pi-user').cols(2).addFields(
            FB.inputText().key('first_name').label('First Name'),
            FB.inputText().key('last_name').label('Last Name'),
        ),
        FB.section('Address').icon('pi pi-map-marker').addFields(/* ... */),
    )
    .build();
```

---

## 2026-05-20 — v13.5.8

### Patch release — AppDialog Material Flat shell, rich header & footer API, scrollbar-gap fix

`AppDialog` has been redesigned around PrimeVue Dialog's `#container` template into a self-contained "Material Flat" shell: gradient icon lozenge + title + subtitle in the header, an optional slate-100 sticky footer with hint icon/text on the left and Cancel/Confirm buttons on the right, a softer dual-layer drop shadow, and a custom "rise" enter/leave animation. The shell is fully scoped (`sk-dlg` PT class) so `ConfirmDialog` and other Dialog usages remain untouched. The `useDialog` composable gained `subtitle`, `icon`, and `footer` open options, a new `DialogFooter` interface, and `setFooter()` / `patchFooter()` methods so components rendered inside the dialog can mutate the footer (e.g. flip the confirm button to a loading state) without re-opening. The remaining sticky-bar issue from v13.5.7 — a ~10 px white gap on the right side of the gray footer when the form scrolled — is fixed by hiding the dialog body's scrollbar visually; scroll still works via wheel / trackpad / keyboard, so the slate-100 bar now reaches the dialog's right edge cleanly.

#### Added

- **`AppDialog` Material Flat shell** — header now ships an icon lozenge (`state.icon`), title (`state.header`), and subtitle (`state.subtitle`); a slate-themed close button replaces PrimeVue's default. Optional opt-in footer renders a sticky slate-100 action bar with hint icon/text + Cancel/Confirm.
- **`useDialog` rich-header & footer API** — `OpenOptions.subtitle`, `OpenOptions.icon`, `OpenOptions.footer` added. New `DialogFooter` type with `icon`, `text`, `cancelLabel`, `confirmLabel`, `confirmIcon`, `severity`, `onConfirm`, `hideCancel`, `disabled`, `loading`. New `setFooter()` and `patchFooter()` methods.
- **`_dialog.scss`** — new stylesheet imported from `theme.css`; defines the shell (mask, root, head/lead/title-block, body, foot/info/actions) and is scoped via the `sk-dlg` PT class.

#### Changed

- **`preset.ts` modal token** — `borderRadius.xl` → `borderRadius.md` (6 px), `padding: 1.25rem` → `padding: 0` (shell-level padding handled inside `AppDialog`), drop shadow updated to a softer dual-layer (`0 24px 60px -20px ...`, `0 6px 20px -6px ...`).

#### Fixed

- **Form scrollbar gap on the right of the footer** — long forms inside `AppDialog` left a ~10 px white gap between the right edge of the slate-100 action bar and the dialog's right edge (the body's scrollbar consumed content width and the bar's `-mx-8` extension only reached the body's content edge). `.sk-dlg__body:has(.sk-fb--dialog)` now hides the scrollbar visually (`scrollbar-width: none` + `::-webkit-scrollbar { width: 0 }`); scroll continues to work via wheel / trackpad / arrow keys / Page Up–Down / Home–End.

#### Upgrade

```bash
composer update lvntr/laravel-starter-kit

# Re-publish affected stubs (warning: customised stubs are overridden — diff first)
# stubs/resources/css/theme/{_dialog.scss,_formbuilder.scss,theme.css}
# stubs/resources/js/composables/useDialog.ts
# stubs/resources/js/theme/preset.ts
php artisan vendor:publish --tag=starter-kit-stubs --force
```

**Behavioural note:** the Dialog body's scrollbar is intentionally invisible inside form dialogs. The visible track is hidden so the slate-100 action bar reaches the dialog edge with no gap; scroll continues to work via wheel, trackpad, arrow keys, Page Up/Down, Home/End.

---

## 2026-05-19 — v13.5.7

### Patch release — Dialog sticky bar bleed fix, AvatarUpload redesign, 14px root typography

`AppDialog`'s sticky form action bar (`Cancel` / `Update`) was leaking scrolling content out from under the buttons whenever a form exceeded the Dialog's visible height — the root cause was Dialog content's default `padding: 1.25rem` leaving a transparent gap underneath the sticky bar. Fixed by zeroing the Dialog's `padding-bottom` via the PT API, having `SkForm` advertise dialog mode through a `sk-fb--dialog` marker class, and extending the sticky bar edge-to-edge with a matching `rounded-b-xl` so the bar mirrors the Dialog's rounded corners. `AvatarUpload` has been redesigned from the previous stacked card to a single-row layout (avatar · title/hint · actions) with a smaller 56px avatar, primary border accent, and a new `initials` prop for showing user initials when no photo is uploaded. The `title` and `subtitle` props now have explicit three-state semantics: omit → default i18n, non-empty string → that text, empty string → row hidden entirely. Typography has been rebased to a 14px root system written in rem (so user browser font-size preferences and a11y zoom continue to scale proportionally); the previous absolute-px override is gone. Profile vertical tabs gained description text and per-tab icon colors.

#### Added

- **Profile tabs** — `Profile/Index.vue` now declares `description()` and `iconColor()` on each tab; `sk-profile.tab_descriptions.{general,password,security,sessions}` keys added (TR/EN).
- **`AvatarUpload :initials`** — renders user initials in the avatar slot when no `avatarUrl` is provided; falls back to `pi-user` otherwise.

#### Changed

- **`AvatarUpload` row layout** — avatar shrunk to `size-14`, primary-200 border on primary-50 background, "Remove" is `severity-secondary text`, "Change" is `outlined`. Title and hint render inline; the avatar block can be rendered without any caption by passing `:title=""` and/or `:subtitle=""`.
- **`AvatarUpload` `title` / `subtitle` semantics** — `undefined` → default i18n key, non-empty string → that text, `''` → element fully hidden via `v-if`. Restores the ability to opt out of the labels.
- **`sk-avatar.hint`** — copy reformatted to `"JPG · PNG · GIF — max 2 MB · 512×512 recommended"` (TR: `"JPG · PNG · GIF — en fazla 2 MB · 512×512 önerilir"`).
- **Typography (14px root, rem)** — `_base.scss` sets `html { font-size: 0.875rem }`; `utilities.css` declares all `--text-*` tokens in rem relative to that root (`--text-base: 1rem`, `--text-xs: 0.857rem`, etc). The transient absolute-px override from a previous WIP is replaced; a11y zoom now scales the whole UI again.
- **FileManager text rebalance** — favourites/trash empty-state titles/subtitles and file-type filter pills downgraded from `text-lg` to `text-base`. `sk-user-menu__item` raised from `text-sm` to `text-base`.

#### Fixed

- **Sticky action bar bleed (`AppDialog`/`SkForm`)** — long forms inside `AppDialog` were leaking scrolling content from underneath the sticky bottom bar; Dialog `content` PT now zeros `padding-bottom` and switches to a flex column layout, `SkForm` adds an `sk-fb--dialog` marker, and `_formbuilder.scss` paints `.sk-fb__actions` opaque, edge-to-edge with `rounded-b-xl` matching `borderRadius.xl`. Scroll content can no longer slide behind the buttons.

#### Upgrade

```bash
composer update lvntr/laravel-starter-kit

# Re-publish affected stubs (warning: customised stubs are overridden — diff first)
# stubs/resources/css/theme/{_base.scss,utilities.css,_formbuilder.scss,_tabs.scss,_menus.scss}
# stubs/resources/js/pages/Profile/Index.vue
# stubs/resources/js/pages/Profile/components/ProfileInfoTab.vue
# stubs/lang/{tr,en}/{sk-avatar.php,sk-profile.php}
php artisan vendor:publish --tag=starter-kit-stubs --force
```

**Behavioural note for `AvatarUpload`:** if your code was passing `:subtitle=""` expecting the default hint to render anyway, it will now hide the hint row instead. Either remove the prop (default i18n applies) or supply a non-empty value.

---

## 2026-05-10 — v13.5.6

### Patch release — axios removed from SystemHealthTab, API envelope compliance, FileManager type fix

`SystemHealthController` was using `response()->json()` instead of the required `to_api()` helper, producing a non-standard JSON body that `useApi` could not parse (no `success` envelope). Fixed. `SystemHealthTab.vue` was importing and calling axios directly, violating the SK hard rule that all API calls must go through the `useApi` composable. Replaced with `useApi({ toast: false })`. A TypeScript type error in `FileManager.vue` is also resolved: `@click` received a `BusyState | null` value; vue-tsc does not narrow `busy` through `v-if` in event handlers; double optional-chaining `busy?.onCancel?.()` resolves both null cases.

#### Fixed

- **`SystemHealthController@run`** — `response()->json()` replaced with `to_api([...], $message)`; return type updated to `ApiResponse|RedirectResponse`. The raw JSON response bypassed the standard `{ success, data, message }` envelope expected by `useApi`, causing the frontend to throw a parse error.
- **`SystemHealthTab.vue`** — `import axios from 'axios'` removed; `useApi({ toast: false })` composable added. `axios.post<...>(url)` replaced with `api.post<...>(url)`. Using axios directly violates the SK hard rule; all API calls must go through `useApi`.
- **`FileManager.vue`** — `@click="busy.onCancel"` changed to `@click="() => busy?.onCancel?.()"`. `busy` is `BusyState | null` and `onCancel` is `(() => void) | null`; vue-tsc does not narrow either through `v-if` in event handlers, so double optional-chaining is required.

#### Upgrade

```bash
composer update lvntr/laravel-starter-kit

# Re-publish affected stubs (warning: customised stubs are overridden — diff first)
# SystemHealthController.php, SystemHealthTab.vue
php artisan vendor:publish --tag=starter-kit-stubs --force
```

---

## 2026-05-08 — v13.5.5

### Patch release — Passport setup fixes, API client scopes removed, Settings tabs, UUID fix

System Health and API client/token management are now embedded as Settings tabs — no more standalone admin pages. A critical bug is fixed: the `scopes` field on OAuth clients never existed in Passport's schema and caused a fatal SQL error on every client create/update. Passport setup now happens fully automatically during `sk:install` and `site:install` (personal access client creation was previously missing). A runtime guard ensures the `api` guard required by Passport is always present even on Laravel 11 where it was removed from the default `auth.php`. Datatable refresh after record creation is now immediate. UUID type fix for the `file_manager_share_revocations` migration, and `InstallCommand` reliability improvements round out the release.

#### Changed

- **System Health moved to Settings tab.** The `/admin/system-health` standalone page is replaced by a Settings tab. The `useAdminMenu.ts` sidebar entry and `system-health` route import are removed. `SystemHealthTab.vue` is now wrapped in a PrimeVue `Card` with title, subtitle, and content slots; the refresh button is inlined in the `#title` slot with `size="small"`.
- **`SystemHealthController@run`** — reverted back to `back()`. The earlier `redirect()->route('admin.system-health.index')` was introduced in v13.5.4 but does not make sense now that System Health lives inside the Settings page.
- **`ApiClientsManageTab.vue` / `ApiTokensManageTab.vue`** — custom `<header>` block and standalone `Button` import replaced with `isCard(true).cardTitle(...).cardSubtitle(...)` on the table builder; the create action is registered via `tableBuilder.create({ label, onClick })` so the `DatatableBuilder` owns the full card layout.

#### Fixed

- **API client `scopes` field removed.** The `scopes` column does not exist on `oauth_clients` in native Passport. The field was dead code across `StoreApiClientRequest`, `UpdateApiClientRequest`, `CreateApiClientAction`, `UpdateApiClientAction`, `ApiClientController`, `ApiClientResource`, `ApiClientForm.vue`, and `ApiClientsManageTab.vue`, and caused `Column not found: 1054 Unknown column 'scopes'` on every create/update. PAT scopes (`$user->createToken($name, $scopes)`) are unaffected — they are stored on `oauth_access_tokens.scopes` and continue to work.
- **`passport:client --personal` now runs automatically during install.** Both `sk:install` and `site:install` now execute `passport:client --personal --provider=users` immediately after `passport:keys`. The missing step caused `LogicException: Unable to determine authentication provider` on token creation in fresh installs.
- **Laravel 11 `api` guard auto-injected at runtime.** `StarterKitServiceProvider::configurePassport()` now checks for `auth.guards.api` and injects `['driver' => 'passport', 'provider' => 'users']` when absent. Laravel 11 removed this guard from the default `auth.php`; Passport's `createToken()` requires it to resolve the user provider.
- **Datatable refreshes immediately after record creation.** `ApiClientsManageTab.vue` and `ApiTokensManageTab.vue` now call `bus.refresh(REFRESH_KEY)` as soon as `onCreated` fires (i.e. the moment the API responds with success), instead of waiting for the user to click "I've saved it" in `OneTimeSecretModal`.
- **`file_manager_share_revocations` migration** — `revoked_by_user_id` column changed from `unsignedBigInteger` to `uuid` to match the UUID primary key on the `users` table. Upgrading from v13.5.3: see the migration note below.
- **`ShareRevocation` model** — `$revoked_by_user_id` PHPDoc type corrected from `int|null` to `string|null`.
- **`InstallCommand`** — `app/Helpers/custom.php` is now auto-created (minimal `<?php` stub) when missing, before `composer dump-autoload`. Absence of this file caused every subsequent artisan call to fail on fresh installs.
- **`DatabaseTestCase`** — in-memory `file_manager_share_revocations` schema updated to use `uuid` for `revoked_by_user_id`, matching the fixed migration.

#### UI

- **`SkDatatable`** — in `isCard` mode the `caption` PT slot now receives `padding: var(--p-card-body-padding) var(--p-card-body-padding) 0`, so title and subtitle align with the standard Card body; the table toolbar and content remain edge-to-edge.

#### Upgrade

```bash
composer update lvntr/laravel-starter-kit

# Re-publish affected stubs (warning: customised stubs are overridden — diff first)
# useAdminMenu.ts, SystemHealthController.php, SystemHealthTab.vue,
# ApiClientController.php, ApiTokenController.php, ApiClientForm.vue,
# ApiClientsManageTab.vue, ApiTokensManageTab.vue, CreateTokenModal.vue,
# OneTimeSecretModal.vue, api-client-route.php, api-token-route.php
php artisan vendor:publish --tag=starter-kit-stubs --force
```

**Passport personal access client** — if you never ran `passport:client --personal` in a previous install, run it once:

```bash
php artisan passport:client --personal --provider=users
```

**Migration note** — if you published `file_manager_share_revocations` in v13.5.3, run a new migration to fix the column type:

```php
Schema::table('file_manager_share_revocations', function (Blueprint $table) {
    $table->dropForeign(['revoked_by_user_id']);
    $table->dropColumn('revoked_by_user_id');
    $table->uuid('revoked_by_user_id')->nullable()->after('revoked_at');
    $table->foreign('revoked_by_user_id')->references('id')->on('users')->nullOnDelete();
});
```

No new permissions or config keys in this release.

---

## 2026-05-07 — v13.5.4

### Patch release — v13.5.3 follow-up: stub fixes, type alignments and CI pipeline reliability

This patch fixes a handful of stub regressions exposed after v13.5.3 (`AdminHeader` `role` typo, missing System Health menu item, `SettingsDefaultsQuery` payload missing `storage_usage`, `SystemHealthController` redirect target, and `trans()` count typing in the Logs pages). The TabBuilder gains a `rose` icon color so the new System Health tab compiles. The CI pipeline is re-ordered so `auto-imports.d.ts` and `components.d.ts` are generated before typecheck, with new guards in `vite.config.ts` for environments without PHP (Wayfinder) or running under Vitest (laravel-vite-plugin HMR check). No new permissions, migrations or config keys.

#### Added

- **TabBuilder — `rose` icon color.** `TabIconColor` accepts `rose`; `_tabs.scss` ships matching `--p-rose-*` light/dark rules. Required by the System Health tab in Settings.

#### Fixed

- **`AdminHeader.vue`** — `page.props.auth?.role` (singular, non-existent) corrected to `roles?.[0]`, matching the `roles: string[]` shared page-prop shape.
- **`useAdminMenu.ts`** — added missing `import systemHealth from '@/routes/system-health'` and a System Health entry (`permission: 'system.health.view'`). The v13.5.3 page was reachable only by URL.
- **`SettingsDefaultsQuery.php`** — added `storage_usage` (`used_bytes`, `quota_bytes`) payload via the `ResolvesMediaModel` trait (`computeStorageUsed()` / `storageQuotaBytes()`). Drives the `StorageQuotaCard` shipped in v13.5.2.
- **`SystemHealthController@run`** — switched from `back()` to `redirect()->route('admin.system-health.index')` (POST → safe GET).
- **`Admin/Logs/{Index,Show}.vue`** — `trans()`/`$t()` `count` replacement values wrapped in `String(...)`; `laravel-vue-i18n` v2.8 strict types reject raw numbers.
- **`tsconfig.json`** — added `@lvntr/components/*` path mapping aligned with the Vite alias so `@lvntr/components/FormBuilder/core` and friends resolve under `vue-tsc`.
- **`env.d.ts`** — typed global `window.turnstile`, plus a `@/routes/*` wildcard module declaration as a fallback when wayfinder hasn't run yet.

#### Build / CI

- **`vite.config.ts`** — `isWayfinderAvailable()` skips the wayfinder plugin when there is no `artisan` (CI / package repo), and an `isVitest` guard skips `laravel-vite-plugin` + `inertia()` during `vitest run` (no more "Vite HMR server in CI" startup error).
- **GitHub Actions Node job re-ordered** — `npm ci` → vendor symlink → route stub generation → build → typecheck → lint (`continue-on-error`) → test. Build now generates `auto-imports.d.ts` / `components.d.ts` before vue-tsc runs.
- **`scripts/ci/generate-route-stubs.mjs`** — node-only CI fallback that writes 16 minimal `@/routes/*` stub files; gitignored so host apps still let wayfinder generate the real ones.
- **Doctor tests** updated to expect the (intentional) English check messages.
- **`.gitignore`** — wayfinder routes, the CI vendor symlink, and Vite build artefacts (`stubs/public/build/`, `stubs/bootstrap/ssr/`) are now ignored.

#### Upgrade

```bash
composer update lvntr/laravel-starter-kit

# Re-publish affected stubs (warning: customised stubs are overridden — diff first)
# AdminHeader.vue, useAdminMenu.ts, SystemHealthController.php, SettingsDefaultsQuery.php,
# Logs/{Index,Show}.vue, env.d.ts, tsconfig.json, vite.config.ts
php artisan vendor:publish --tag=starter-kit-stubs --force

# Re-publish theme files for the new rose tab color
php artisan vendor:publish --tag=starter-kit-theme --force

npm run build
```

If you maintain a custom `tsconfig.json`, add the `@lvntr/components/*` mapping (must come before `@lvntr/*`):

```json
"paths": {
    "@/*": ["resources/js/*"],
    "@lvntr/components/*": [
        "vendor/lvntr/laravel-starter-kit/resources/js/components/Lvntr-Starter-Kit/*"
    ],
    "@lvntr/*": ["vendor/lvntr/laravel-starter-kit/resources/js/*"]
}
```

No new permissions, migrations or config keys.

---

## 2026-05-06 — v13.5.3

### Release — sk:doctor, System Health, Signed Share Link, Bulk Action API, API Client Admin UI, security updates and bug fixes

This release adds the `sk:doctor` health-check command and its System Health admin page, HMAC-signed file share links, a cross-page Bulk Action API for the DatatableBuilder, the Domain Generator v2 opt-in flags, and a full Passport API Client & Token admin UI. It also includes security dependency bumps, event-dispatch fixes for nested folder deletes, Inertia flash response fixes for bulk controllers, and UUID/ULID bulk-action ID support. Existing apps should run the upgrade steps below.

#### Added

- **`sk:doctor` artisan command** — system health check covering 12 control points: PHP extensions, database connection, Redis, Passport keys, storage symlink, writable directories, queue driver, schedule run, mail driver, npm build artifacts, config cache, FileManager disk connection. Machine-readable output via `--json`; selective checks via `--only=database,redis,...`. Exit codes: `0` OK, `1` WARN, `2` FAIL.
- **Admin Panel — System Health page** (`/admin/system-health`) — visualises `sk:doctor` output with per-check status badges and a manual refresh button. Access permission: `system.health.view`.
- **File Manager — Signed Share Link** — HMAC-signed public access URLs. `POST /file-manager/share` creates a link with a TTL; `POST /file-manager/share/revoke` revokes it; `GET /file-manager/share/{media}?expires&signature` validates. Config keys: `file-manager.share.enabled`, `default_ttl_hours` (default 24), `max_ttl_hours` (default 720), `allow_revoke`. Revocations tracked in `file_manager_share_revocations` with a `(media_id, signed_token_hash)` composite unique index. New permissions: `share-media`, `revoke-share-media`.
- **DatatableBuilder — Bulk Action API** — `BulkAction` interface and `BulkActionDispatcher` for cross-page bulk operations. `SkDatatable` supports `select_all_filtered` mode (with filter snapshot) and cross-page selection. Request payload: `{action, ids, select_all_filtered, filter_snapshot}`; response: `{processed, skipped, failed, message}`. Shipped stubs: `BulkDeleteUserAction` (rank-aware) and `BulkDeleteRoleAction` (guards against system roles).
- **Domain Generator v2 (`make:sk-domain`) — opt-in flags** — `--with-policy`, `--with-factory`, `--with-seeder`, `--with-test`, `--with-relations` individually or combined as `--with=policy,factory,test`. `--relations="belongsTo:User,hasMany:Comment,morphTo:commentable"` generates relationship scaffolding automatically. Flag-free invocation preserves v13.5.x behaviour (backward compatible).
- **API Client & Token Admin UI** — admin interface for Passport authorization_code and client_credentials grants and Personal Access Tokens (`/admin/api-clients`, `/admin/api-tokens`). Client secrets and PATs are shown in plaintext only once on creation (`Cache-Control: no-store`); `OneTimeSecretModal` cannot be dismissed. New permissions: `api-clients.create`, `api-clients.read`, `api-clients.update`, `api-clients.delete`, `api-tokens.create`, `api-tokens.read`, `api-tokens.delete`. New validation rule: `HttpsOrLocalhostUrl` (RFC 8252 §8.3 — HTTPS only, localhost HTTP exception).
- **CI Workflow (GitHub Actions)** — PHP test (`pest`), lint (`pint`), and Node 22 build/typecheck/lint jobs. Concurrent runs on the same branch/PR are cancelled via `concurrency: cancel-in-progress`.
- `composer test` (`vendor/bin/pest tests/Feature`) and `composer lint` (`vendor/bin/pint --test`) scripts added for contributors.

#### Fixed

- **`DeleteFolderAction`** — descendant folders were permanently deleted via a query-builder `forceDelete()` call, which skipped Eloquent model events. The `forceDeleted` observer in `FileFolder` (responsible for cleaning up `file_favorites`) never fired for sub-folders, leaving orphan favorite records. Changed to model-level iteration so every `forceDeleted` event is dispatched correctly.
- **`sk:update` — `node_modules/` filtered from stubs scan.** `node_modules/` added to `NEVER_UPDATE_PATHS`; `isNeverUpdate()` check applied to all loops in `updateModifiableFiles`, `addNewFiles`, `migrateHashRegistry` and `updateHashRegistry`. In symlinked (path-repository) setups, `stubs/node_modules/` was leaking into the candidate file list.
- **`sk:doctor` and `sk:update` console output translated to English.** All user-facing messages, tips and table headers in `DoctorCommand`, `UpdateCommand` and the 12 `DoctorCheck` classes are now in English; PHP code comments are unchanged.
- **Bulk action controllers — Inertia flash response.** `UserBulkController` and `RoleBulkController` now return `back()->with('success'/'error', ...)` instead of `ApiResponse` (JSON). The previous JSON response was breaking Inertia's `onSuccess`/`onError` flow and rendering raw JSON on screen; success/error messages now reach `SkFlash`/`useFlash` via `HandleInertiaRequests` flash sharing.
- **Bulk action validation — UUID/ULID/integer ID support.** `BulkActionRequest::rules()` updated: `ids.*` rule changed from `integer` to `string|min:1|max:64`; `prepareForValidation()` casts all incoming IDs to string. The previous `integer` rule caused "The ids.0 field must be an integer" for models using `HasUuids` (User, FileBucket, FileFolder, etc.). The new rule supports integer auto-increment, UUID (36 chars) and ULID (26 chars) primary keys in a single payload schema.

#### Security

- **`dedoc/scramble`** bumped from `^0.13` to `^0.13.22` to address a reported RCE-class advisory (GHSA fixed in v0.13.22).
- **`phpseclib/phpseclib`** updated from `3.0.51` to `3.0.52` to address a high-severity DoS advisory (transitive via `laravel/passport`).
- **Signed Share Link — cross-media token hijack protection.** `(media_id, signed_token_hash)` composite unique index prevents a token from being valid for a different media record.
- **Personal Access Token — privilege escalation guard.** `user_id` body field is rejected; tokens are always minted for the authenticated user.
- **Passport client `confidential` enforcement.** Only `confidential=true` clients can be created via the API Client UI; authorization_code grant requires min:1 redirect URIs with HTTPS. Existing DB records are unaffected.

#### Changed

- **`StarterKitServiceProvider`** — Passport scope and `Gate::before` registrations consolidated to a single source; duplicate registrations removed from the `AppServiceProvider` stub.

#### Upgrade

```bash
composer update lvntr/laravel-starter-kit

# Publish and run new migrations
php artisan vendor:publish --tag=starter-kit-migrations
php artisan migrate

# Publish updated file-manager config (new share.* keys)
php artisan vendor:publish --tag=starter-kit-config --force

# Publish new admin page and controller stubs
# WARNING: customised stubs will be overridden — diff first
php artisan vendor:publish --tag=starter-kit-stubs --force

# Add new permissions and reset permission cache
php artisan db:seed --class=PermissionResourcesSeeder
php artisan permission:cache-reset
```

**New permissions:** `system.health.view`, `share-media`, `revoke-share-media`, `api-clients.create`, `api-clients.read`, `api-clients.update`, `api-clients.delete`, `api-tokens.create`, `api-tokens.read`, `api-tokens.delete`.

**Behaviour changes:**

- `confidential=false` authorization_code Passport clients can no longer be created via the UI. Existing DB records are unaffected.
- Personal Access Token minting: `user_id` body field removed; to mint a PAT for another user use an artisan command or a custom action.
- If your `AppServiceProvider` stub has duplicate Passport scope / `Gate::before` blocks, remove them — `StarterKitServiceProvider` handles this now.

---

## 2026-05-06 -v.13.5.2

### Patch release — Settings security consolidation, FileManager restore fix and i18n improvements

Settings now consolidates Auth and Turnstile into a single **Security** tab and adds a Storage Quota card that visualises disk usage. The File Manager trash restore bug is fixed: the trash view now only shows root-level deleted items so single and bulk restore always succeed without the "parent in trash" error. All File Manager component text sizes are standardised to `text-lg` (14 px), confirmation dialogs are translated via `trans()`, and filter pill labels are internationalised. Existing apps should run `composer update lvntr/laravel-starter-kit && php artisan sk:update && npm run build`.

#### Added

- **`SecurityTab.vue`** consolidates Authentication and Cloudflare Turnstile settings into one tab, replacing the removed `AuthTab.vue` and `TurnstileTab.vue` stubs.
- **`StorageQuotaCard.vue`** displays disk-wide storage quota usage as a progress bar in the Settings panel.
- **`SettingsDefaultsQuery`** now includes `storage_usage` (`used_bytes`, `quota_bytes`) in the Inertia payload.
- **i18n keys** added in `sk-setting` (security/storage section labels), `sk-file-manager` (filter pill labels: `all`, `image`, `video`, `pdf`, `audio`, `archive`) and `sk-common` (confirmation dialog strings).
- **`config('file-manager.settings.enable_trash')`** — new config key that controls soft-delete vs hard-delete for the entire FileManager. `true` (default) sends deleted files and folders to Trash; `false` permanently deletes immediately. Both `DeleteFileAction` and `DeleteFolderAction` read the config at delete time. The value is shared automatically via Inertia (`fileManagerSettings.enable_trash`) so the Vue component falls back to the config without needing the `:enable-trash` prop — the prop can still be passed to override per-instance.

#### Fixed

- **Trash restore bug.** `TrashContentsQuery` now only returns root-level trashed items. Items whose parent folder was also in trash were listed as independent items, making both single and bulk restore fail with "Cannot restore: the parent folder is also in trash". Root-level filtering ensures restore operations always start from the top of the tree.

#### Changed

- **FileManager minimum text size** standardised to `text-lg` (14 px) across `FileManager.vue`, `FileGrid.vue`, `FileManagerSidebar.vue` and `FileManagerStats.vue`.
- **`useConfirm` composable** confirmation strings moved to `trans()` calls using new `sk-common` translation keys.
- **`Admin/Files/Index.vue`** simplified — unnecessary wrapper `<div>` removed.
- **File Manager tab** — Video/Audio upload toggles now use the same checkbox-grid layout as Images.

#### Removed

- **`AuthTab.vue`** and **`TurnstileTab.vue`** stubs — content merged into `SecurityTab.vue`. `sk:update` cleans them up automatically via `DEPRECATED_PATHS`.

#### Upgrade

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
npm run build
```

---

## 2026-05-05 -v.13.5.1

### Patch release — NPM exports fix, sk:publish improvements, storage quota and upload validation

NPM package `main` and `exports` paths are corrected to match the actual file structure. Individual `sk:publish` tags now work correctly. Storage quota is configurable in GB from Admin Settings > File Manager, and upload requests now return a localised error when the quota is exceeded. Existing apps should run `composer update lvntr/laravel-starter-kit && php artisan sk:update && npm install && npm run build`.

#### Fixed

- **NPM package `main` and `exports` paths** now reflect the actual file structure (`resources/js/components/Lvntr-Starter-Kit/...`). FileManager export added.
- **`sk:publish` individual tags** (`form`, `datatable`, `tabs`, `skeleton`, `ui`) had broken source paths referencing the old structure; corrected with the `Lvntr-Starter-Kit/` segment.
- **`vendor:publish --tag=starter-kit-components` nested path bug** resolved. Was producing `resources/js/components/Lvntr-Starter-Kit/Lvntr-Starter-Kit/...`; now publishes directly to `resources/js/components/Lvntr-Starter-Kit/`.
- **`vendor:publish --tag=starter-kit-file-manager-components`** is now active. Source path pointed to the old directory name (`file-manager`); realigned with the actual directory (`Lvntr-Starter-Kit/FileManager`).
- **`index.ts` barrel** — 9 missing component exports added: `EditorInput`, `EditorImagePicker`, `EditorColorPalette`, `TranslatableInput`, `ImageLightbox`, `FilePreviewModal`, `ToggleFeatureCard`, `MimePickerField`, `SkTag`.

#### Added

- **`sk:publish --tag=filemanager`** — new tag for publishing the FileManager UI separately.
- **`sk:install --without-ai-skill`** — skip AI skill publishing (`stubs/.claude/skills/`) for consumers that don't use the Claude Code skill bundle.
- **`.gitattributes`** — Composer archive now excludes `tests/`, `docs/`, `.github/`, `plan-docs/`, `package-audit-notes/` and other development-only paths; smaller archive size.
- **`.npmignore`** — NPM package excludes `__tests__/`, `*.spec.*`, `*.test.*` (root and subdirectories; compatible with npm 11 behavior).
- **Disk-wide storage quota (`storage_quota_gb`).** A single quota in GB can be set from Admin Settings > File Manager (default 10 GB). Covers all contexts (`user`, `global`, custom morph map entries) including trash (`withTrashed`).
- **Upload quota validation.** `UploadFileRequest::withValidator()` adds a quota check; when exceeded the request returns HTTP 422 with a localised `errors.quota_exceeded` message.

#### Removed

- **Duplicate domain commands removed from stubs:** `EnvSyncCommand`, `MakeDomainCommand`, `RemoveDomainCommand`. They continue to run from vendor as the single source. `sk:update` cleans them up in existing consumer projects via `DEPRECATED_PATHS`.
- **`App\Http\Responses\ApiResponse.php` stub removed.** A `StarterKitServiceProvider` alias guard maps `App\Http\Responses\ApiResponse` → `Lvntr\StarterKit\Http\Responses\ApiResponse` once the consumer file is deleted; existing `use App\Http\Responses\ApiResponse;` imports continue to work unchanged.
- **`Lvntr\StarterKit\Enums\PermissionEnum` removed from vendor.** Canonical location is `App\Enums\PermissionEnum` (under stubs). No vendor references existed (confirmed by grep). If your code imports this namespace directly, update it to `App\Enums\PermissionEnum`.

#### Changed

- **`sk:publish` is now the primary publish command.** Granular interactive flow with namespace rewrite support. `vendor:publish --tag=starter-kit-*` is kept for backward compatibility but `sk:publish` is now the recommended path in install and command docs.
- **`ResolvesMediaModel::computeStorageUsed()` signature changed (internal trait).** No longer accepts a parameter; returns the disk-wide total via `Media::withTrashed()->sum('size')`. Previous behavior was per-context (`model_type` + `model_id` filtered). If your app extends this trait and calls `computeStorageUsed($context)`, remove the argument.
- **`FolderContentsQuery`, `FavoritesContentsQuery`, `TrashContentsQuery`** — `stats.storage_quota` field added (bytes).
- **`FileManager.vue`** — hardcoded `STORAGE_QUOTA_BYTES` constant removed; `quotaBytes` is now computed from `stats.storage_quota`. The quota sidebar hides (`v-if="quotaBytes > 0"`) when quota is zero or undefined.

#### Upgrade

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
```

`sk:update` output will list 4 paths under "Removed" — this is expected.

---

## 2026-05-05 -v.13.5.0

### Major release — Vendor-first runtime and frontend UI lib

The starter kit runtime moves entirely to vendor. FileManager backend, shared base classes, traits, helpers, middleware, ApiResponse and the route loader now live under `vendor/lvntr/laravel-starter-kit/src/` with the `Lvntr\StarterKit\` namespace. The frontend component library (`DatatableBuilder`, `FormBuilder`, `TabBuilder`, `FileManager`, `Skeleton`, `ui`) is also now canonical inside the package, consumed by the app via vendor symlink. Existing apps only need `composer update`; no file changes, no route names break, and `php artisan migrate` returns "Nothing to migrate". Frontend migration to vendor is fully opt-in. Upgrade instructions: [UPGRADE.md](UPGRADE.md).

#### Changed

- **Vendor-first architecture.** Package runtime no longer flows through stubs — it runs directly from `vendor/`. `sk:install` publishes skeleton files (auth, layout, user/role/settings domain, config); it no longer copies FileManager and Shared layers into `app/`.
- **`sk:update` simplified.** No file copying for vendor runtime; `composer update` is enough. Hash-tracked stubs (auth/layout/user/role/settings) retain their existing diff/notify behaviour.
- **Frontend UI lib relocated.** `resources/js/components/Lvntr-Starter-Kit/{DatatableBuilder,FormBuilder,TabBuilder,FileManager,Skeleton,ui,index.ts}` is now the canonical package location. Apps consume it via vendor symlink.
- **`stubs/vite.config.ts` alias updated.** New installs get `@lvntr/components` pointing to `vendor/lvntr/laravel-starter-kit/resources/js/components/Lvntr-Starter-Kit` with `preserveSymlinks: true` and vendor path in the `Components({ dirs })` array.
- **`FileManagerAction` abstract base + `ResolvesMediaModel` trait.** Resolves the Media model via `media-library.media_model` config; app-specific `App\Models\Media` overrides (e.g. with SoftDeletes) work without changes.
- **`Http/Requests/FileManager/UploadFileRequest`.** Protected methods — overridable on the app side (e.g. Settings integration).

#### Added

- **`src/Domain/FileManager/`** — Actions, DTOs, Queries, Services, Support under `Lvntr\StarterKit\Domain\FileManager\` in vendor.
- **`src/Domain/Shared/`** — BaseAction, BaseDTO, ActionPipeline, PipeableAction under `Lvntr\StarterKit\Domain\Shared\` in vendor.
- **`src/Traits/`** — HasActivityLogging, HasMediaCollections under `Lvntr\StarterKit\Traits\` in vendor.
- **`src/sk-helpers.php`** — `to_api()`, `definition()`, `definitionLabel()`, `sk_locale_keys()`, `sk_default_locale()`, `format_date()` with `function_exists` guards in vendor.
- **`src/Http/Responses/ApiResponse.php`** — `{success, status, message, data, errors?}` envelope preserved, moved to vendor.
- **`src/Http/Middleware/`** — CheckResourcePermission, SecurityHeaders under `Lvntr\StarterKit\Http\Middleware\` in vendor.
- **`src/Http/Controllers/FileManagerController.php`** and **`src/Http/Requests/FileManager/*`** — in vendor.
- **`src/Console/Commands/PurgeFileManagerTrashCommand.php`** — `file-manager:purge-trash` signature preserved.
- **`src/Exceptions/`** — ApiException, ApiExceptionHandler in vendor.
- **`src/Facades/FileManager.php`** — single-line route mount via `FileManager::routes()`.
- **`src/routes/file-manager.php`** — 19 routes, all names preserved exactly. Consumer's own route file takes precedence.
- **`database/migrations/`** — 3 FileManager migrations, filenames and content preserved exactly.
- **`config/file-manager.php`** — `models.*` and `settings.*` keys added.

#### Deprecated

- **`sk:sync` (PackageSyncCommand).** No longer needed with the Composer path-repository symlink workflow. The `--force` escape hatch is preserved.

#### Upgrade

```bash
composer update lvntr/laravel-starter-kit
php artisan migrate
```

Existing `app/Domain/FileManager/`, `app/Domain/Shared/`, `app/Traits/`, `app/Helpers/sk-helpers.php` and related files stay in place and continue to work. Migrating them to the vendor versions is completely optional. Frontend cleanup (switching the Vite alias to vendor path and removing the app-side copy) is also opt-in. See [UPGRADE.md](UPGRADE.md) for both guides.

---

## 2026-05-04 -v.13.4.10

### Minor release — Translatable FormBuilder fields and Sample Contents reference module

FormBuilder now supports multi-language text fields out of the box. Three new builders — `FB.translatableText()`, `FB.translatableTextarea()` and `FB.translatableEditor()` — render one input per active language and submit JSON-ready locale maps for Spatie Translatable models. The release also adds backend helpers for validation, datatable search/sort and resource output, plus a shipped Sample Contents module that demonstrates the full pattern end to end. Existing apps should run `composer update lvntr/laravel-starter-kit && php artisan sk:update && php artisan migrate && npm install && npm run build`.

#### Added

- **Translatable FormBuilder fields.** `FB.translatableText()`, `FB.translatableTextarea()` and `FB.translatableEditor()` render per-locale inputs driven by the active language list. They support locale filtering (`onlyLocales`, `exceptLocales`), inline or tabbed layouts, and locale label styles (`badge`, `name`, `flag`).
- **Backend translatable helpers.** `HasTranslatableRules` generates FormRequest rules and validation labels per locale. `TranslatableQueryHelpers` provides JSON-column search, locale-aware sorting and `resourceShape()` output for datatables and edit forms.
- **Locale helper functions.** `sk_locale_keys()` returns active locale codes in order, while `sk_default_locale()` resolves the primary locale with a fallback to `app.fallback_locale`.
- **Sample Contents module.** A complete admin CRUD reference ships with a translatable model, migration, factory, domain actions/events/listeners, FormRequests, resource, datatable query, Vue pages and menu/permission entries.
- **Documentation.** New [Translatable Fields](./translatable-fields.md) and [Çevrilebilir Alanlar](./translatable-fields.tr.md) guides document the full backend/frontend flow, migration strategy and Sample Contents reference implementation.
- **Package dependency.** `spatie/laravel-translatable` is now part of the application dependency set for JSON-backed translated attributes.

#### Improved

- **FormBuilder docs.** The FormBuilder guide now lists the translatable builders and links to the dedicated guide.
- **File Manager no-trash mode docs.** The File Manager guide now clarifies that `enableTrash=false` routes single and bulk delete operations to permanent deletion, including `force_delete=true` for bulk deletion.
- **Lvntr builder skill docs.** Project agent guidance for FormBuilder now includes the translatable field builders so future generated admin forms use the supported API.

#### Upgrade

Run migrations and rebuild frontend assets after updating:

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
php artisan migrate
npm install
npm run build
```

Apps that already have custom language/settings handling should verify the active language list used by `general.languages`. Existing plain string columns are not migrated automatically; convert them to JSON with a staged migration before switching a model attribute to Spatie `HasTranslations`.

## 2026-05-02 -v.13.4.9

### Minor release — File Manager favorites, trash, restore, permanent delete, copy and rename

File Manager now ships the feature set that was previously visible as placeholders in v13.4.8. Favorites and Trash are real quick-access views, folder/file tiles can be starred, deleted items move to trash by default, trash items can be restored or permanently deleted, and the trash view has an **Empty Trash** action. Files can also be duplicated and renamed from the context menu. This release adds two migrations (`file_favorites` and soft deletes on `media`), new backend actions/queries/requests, new File Manager routes, extended EN/TR language keys, and a daily `file-manager:purge-trash` scheduled command. Existing apps should run `composer update lvntr/laravel-starter-kit && php artisan sk:update && php artisan migrate && npm install && npm run build`.

#### Added

- **Favorites.** New `file_favorites` table and `FileFavorite` model store starred folders/files per owner context. `FavoritesContentsQuery` powers the sidebar **Favorites** view, `FolderContentsQuery` now annotates items with `is_favorited`, and the grid/context menus expose Add/Remove Favorite actions.
- **Trash and restore flow.** Files and folders now soft-delete into Trash when `enableTrash` is true. `TrashContentsQuery` powers the **Trash** quick view, deleted tiles show their deleted timestamp, and trash context menus switch to Restore / Permanently Delete actions.
- **Empty Trash.** `EmptyTrashAction` and `DELETE /file-manager/trash/empty` permanently delete all trashed File Manager items for the current context; files are removed before folders and folders are deleted post-order so nested trees clear safely.
- **File copy and file rename.** Files can be duplicated with copy-safe names such as `photo (copy).jpg` / `photo (copy 2).jpg`, and renamed through the shipped dialog and `PATCH /file-manager/files/{media}` endpoint.
- **Trash purge command.** `php artisan file-manager:purge-trash --days=7` permanently deletes File Manager trash older than the selected age. It is scheduled daily from `routes/console.php`.
- **`enableTrash` prop.** `FileManager` defaults to soft-delete behaviour; setting `:enable-trash="false"` restores immediate permanent deletion semantics for projects that do not want a trash workflow.

#### Security

- **Context validation centralised.** `FileManagerContextRequest` now validates and resolves the current File Manager context consistently across virtual views and item mutations, closing gaps where favorites/trash endpoints could drift from the regular folder-content checks.
- **Soft-delete scope hardening.** Restore, permanent-delete, copy, rename and favorite actions now explicitly scope items to the current context and use `withTrashed()` / `onlyTrashed()` where needed, preventing cross-context access and ensuring trashed items are found only in the intended paths.
- **Folder restore cascade guardrails.** Restoring a trashed folder restores its descendant folders and File Manager media in a transaction. If its parent is still trashed, restore is refused until the parent is restored first; if the parent was permanently deleted, the item is restored to root to avoid an orphan.

#### Fixed

- **Bulk force delete can now find trashed items.** `BulkDeleteAction` uses `withTrashed()` when `force=true`, so permanent deletion from the Trash view no longer misses items that are already soft-deleted.
- **Language key collision fixed.** `labels.details` is now the details-section array, while the action label moved to `labels.details_action`; this prevents the file details dialog labels from being overwritten by the context-menu action string.
- **Collection scoping tightened.** Trash purge and permanent delete affect File Manager media (`collection_name = files`) without touching avatars, logos, editor uploads or other MediaLibrary collections.

#### Upgrade

Run migrations after update:

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
php artisan migrate
npm install
npm run build
```

No breaking API response change. Apps that customised File Manager stubs should compare their local files with the shipped updates before using `sk:update --force`, especially `FileManager.vue`, `useFileManager.ts`, `FileGrid.vue`, `FileManagerController.php`, `routes/web/file-manager-route.php`, `lang/{en,tr}/sk-file-manager.php`, the new requests/actions/queries, and the two migrations.

## 2026-04-30 -v.13.4.8

### Minor release — File Manager UX overhaul (sidebar + stats + details + search)

File Manager UX overhaul — the same backend, same routes, same media table; a new shell. The single-column grid is replaced by a sidebar + main-column layout, with three new shipped components (`FileManagerSidebar`, `FileDetailsDialog`, `FileManagerStats`), a top-bar search box that filters the current folder client-side, and an expanded right-click menu with new entries (Open in new tab, Preview, Share, Copy, Rename, Add to Favorites, Details). All previously documented behaviour — uploads, drag-and-drop move, bulk delete, image lightbox, preview dialog, custom contexts, settings, permissions — works exactly as before; the change is purely shipped frontend (`FileManager.vue` + the three new components + `types.ts` + `lang/{en,tr}/sk-file-manager.php`). No new composer or npm dependency, no migration, no config, no permission entry. Existing consumer apps run `composer update lvntr/laravel-starter-kit && php artisan sk:update && npm install && npm run build` to pick up the patches; no breaking change.

#### Added

- **`FileManagerSidebar.vue` — left-rail with circular storage-usage ring, quick-access list, folder tree, "New Folder" button.** The storage ring uses an SVG circle with a `circumference - dashOffset` fill and a colour-band threshold (primary < 70 %, amber 70–90 %, rose ≥ 90 %); used bytes come from `fm.contents.stats.total_size`, the quota is currently a sane visual default of 10 GB until a backend setting is wired. The folder tree reuses the same `fm.tree` data the move modal already loads. Quick-access targets: **All Files** resets to the root sorted by name asc, **Recently Uploaded** resets to the root sorted by date desc, **Favorites** and **Trash** show the new `coming_soon` toast as placeholders for an upcoming feature.

- **`FileDetailsDialog.vue` — file details modal showing Name, Type, Size, Uploaded, Folder, and (for images) Dimensions.** Image dimensions are loaded async — the dialog kicks off a hidden `new Image()` against `file.url` and pushes `naturalWidth × naturalHeight` into the rendered row when `onload` fires. The dialog ships with a "Download" footer button that reuses the same `downloadFile` handler as the right-click menu, so the action surfaces stay aligned. Wired up from the new "Details" entry in the file context menu.

- **`FileManagerStats.vue` — top-bar stats widget (Total Files, Total Size, Folder Count, Favorites, Last Upload).** Renders a horizontal row of icon-tinted cards (`bg-{colour}-100` in light, `bg-{colour}-900/40` in dark). Folder count traverses the full nested tree (`flattenTree(fm.tree.value)`); last-upload reflects the most-recent `created_at` in the current folder, formatted as "Just now / X min / X hr / X d / locale-date" via the new `stats.time_*` keys.

- **Top-bar search.** `IconField` + `InputText` strip above the body filters `fm.contents.folders` and `fm.contents.files` by `name` / `file_name` (case-insensitive `includes`), surfaced via the new `filteredFolders` / `filteredFiles` computeds. Filter is local to the rendered folder; navigating clears it implicitly the next time `fm.loadContents()` runs.

- **Expanded file context menu — Open / Preview / Download / Share / Move / Copy / Rename / Add to Favorites / Details / Delete.** "Open" now opens the file in a new tab (`window.open(file.url, '_blank', 'noopener,noreferrer')`); "Preview" keeps the existing lightbox / dialog flow; "Share" copies the absolute file URL to the clipboard (`navigator.clipboard.writeText(...)`) with a localised "Link copied" toast on success and the `coming_soon` toast on permission refusal; "Details" opens the new dialog; "Copy", "Rename", "Add to Favorites" are placeholders for upcoming features. The destructive Delete row gets a new `fm-menu-danger` class so it can be styled distinctly.

- **Folder context menu — adds "Add to Favorites" (placeholder) before Delete.** Same `coming_soon` toast pattern as the file-menu placeholders.

- **`types.ts` — adds `ViewMode = 'grid' | 'list'` and `QuickView = 'all' | 'recent' | 'favorites' | 'trash'`.** `ViewMode` is reserved for an upcoming list-view renderer (currently grid-only); `QuickView` is consumed by the sidebar quick-access flow. Existing exports unchanged.

- **`lang/{en,tr}/sk-file-manager.php` — new keys.** Top-level: `link_copied`, `coming_soon`. Labels: `upload_new`, `preview`, `share`, `copy`, `add_to_favorites`, `details`, `search_placeholder`, `view_grid`, `view_list`, `files_section`, `folders_section`, `no_results`. New nested groups: `labels.sidebar.*`, `labels.stats.*`, `labels.details.*`.

#### Removed

- **Legacy header back-button + sort dropdown removed from `FileManager.vue`.** The previous shell had a `←` back button + `Select` dropdown for sort key + a direction-toggle button in the header; navigation now happens through the sidebar (folder tree + breadcrumb) and sorting is driven by the quick-access flow ("Recently Uploaded" = `setSort('date', 'desc')`). The `useFileManager` composable still exposes `setSort` / `toggleSortDirection` for direct callers.

#### Upgrade

No breaking changes. Existing consumer apps run `composer update lvntr/laravel-starter-kit && php artisan sk:update && npm install && npm run build` — `sk:update` will pick up the new shipped files and the extended language keys. The data shape on the wire is unchanged; backend is unchanged.

## 2026-04-26 -v.13.4.7

### Patch release — silence duplicate Link extension warning in `EditorInput`

Single-fix patch — silences the `Duplicate extension names found: ['link']` warning Tiptap printed when `EditorInput` booted. Tiptap v3's `@tiptap/starter-kit` started bundling the Link extension by default, but our editor was still pushing `@tiptap/extension-link` through the optional `props.links` branch with our own `openOnClick: false, autolink: true` config — so two `link` registrations went into the same editor. The fix is a single config flag on the StarterKit call (`link: false`) so the bundled copy is disabled and our manual-push branch stays the single source of truth. Behaviour is identical for both `props.links === false` (no Link at all) and `props.links === true` (manual-push only); only the console noise is gone. Existing consumer apps run `composer update lvntr/laravel-starter-kit && php artisan sk:update` — no migration, no config, no breaking change.

#### Fixed

- **`EditorInput.vue` — duplicate Link extension warning silenced.** Tiptap v3's `@tiptap/starter-kit` bundles the Link extension by default; the editor was also pushing `@tiptap/extension-link` through the optional `props.links` branch, so the editor booted with `Duplicate extension names found: ['link']` in the console. `StarterKit.configure({ heading: { levels: [2, 3, 4] }, link: false })` disables the bundled copy so our manual-push branch (with our own `openOnClick: false, autolink: true` config) is the only source. `props.links === false` cleanly removes Link entirely; `props.links === true` runs only the manual-push branch — same effective behaviour, no warning.

#### Upgrade

No breaking changes. `composer update lvntr/laravel-starter-kit && php artisan sk:update` picks up the patch — the fix ships in the same shipped Vue file `sk:update` already tracks; no extra step needed.

## 2026-04-26 -v.13.4.6

### Patch release — Vite optional-peer-dep stub + `sk:update` package.json merge

Two related build/upgrade fixes that surface when consumers upgrade from a pre-`EditorInput` version of the kit (any 13.4.0 or earlier install) to 13.4.2+. The package's `package.json` no longer declares its `@tiptap/*` set as `peerDependencies` + `peerDependenciesMeta.optional` — those declarations were tripping Vite's optional-peer-dep stub fallback (`__vite-optional-peer-dep:@tiptap/extension-table:@lvntr/starter-kit:false`) when resolving from `vendor/lvntr/laravel-starter-kit/`, even on consumer apps that already had the deps installed at the project root. The result was `"Table" is not exported by …` at build time and `does not provide an export named 'BubbleMenu'` at runtime — both produced by Vite's stub module (`export default {}; throw …`) instead of the real package. And `sk:update` now mirrors `sk:install`'s `mergePackageJson()` step so the new `@tiptap/*` set lands in the consumer's `package.json` automatically on upgrade — previously only fresh installs picked them up, leaving every consumer who upgraded from `<13.4.2` to copy 16 dependency entries by hand. Stub-version-wins for shared keys, user extras preserved, idempotent on re-runs.

#### Fixed

- **Package `package.json` — dropped `peerDependencies` + `peerDependenciesMeta` for the `@tiptap/*` set.** The package is composer-distributed (not on npm) so the peer-dep declarations had no effect on `npm install`; their only practical impact was Vite's `tryNodeResolve` fallback. When a bare-import (`import { Table } from '@tiptap/extension-table'`) couldn't be resolved through the normal `node_modules` walk-up — easy to trigger when the package is in `vendor/`, not `node_modules/` — Vite checked the importer's nearest `package.json`, found the dep listed as an optional peer, and returned `__vite-optional-peer-dep:<dep>:<parent>:<isRequire>` instead of erroring. The stub is loaded as `export default {}; throw new Error("Could not resolve …")` — no named exports, hence the misleading `"Table" is not exported by …` build error and the runtime `does not provide an export named 'BubbleMenu'` for the `@tiptap/vue-3/menus` subpath. Removing the declarations restores plain `node_modules` resolution which walks up to the project root and finds the real packages.

- **`sk:update` now merges `stubs/package.json` into the consumer's `package.json`.** `UpdateCommand` previously only touched files under `app/`, `config/`, `resources/` and `routes/` — never the project's `package.json`. So the 16 `@tiptap/*` entries that 13.4.2 added to the stub never reached consumers who upgraded via `composer update lvntr/laravel-starter-kit && php artisan sk:update`. The new step (4c in `handle()`) mirrors `InstallCommand::mergePackageJson()`: stub keys win at the root, `array_merge`-d `dependencies`/`devDependencies` (sorted), user extras preserved, only writes when the rendered JSON actually differs (so re-runs are no-ops). The summary surfaces the change as `package.json (merged stub dependencies — run npm install)` so the user knows to run `npm install` afterwards.

#### Upgrade

No breaking changes. Existing consumer apps run `composer update lvntr/laravel-starter-kit && php artisan sk:update && npm install && npm run build` — `sk:update` will now sync the missing `@tiptap/*` entries into your `package.json` and Vite will resolve them against the real packages instead of the stubs.

## 2026-04-26 -v.13.4.5

### Patch release — code-review sweep (API hierarchy + role-data + 2FA loading + permission directive + i18n)

Closes a small batch of findings from a follow-up code review of the v13.4.x surface. Two security/info-disclosure fixes (API user list now applies the same role-hierarchy filter the admin panel does, and the role JSON `data` endpoint now runs the same `CanManageRoleQuery` guard the `edit`/`destroy` actions do), one UX fix (the 2FA enable/disable buttons now reset their loading state on failure paths, not just the happy path), one latent-bug fix (the `v-role` directive read the wrong Inertia shared-prop key and silently always returned `false`), and one i18n cleanup (the `useApi` composable's error toasts and synthesized envelope messages now flow through `sk-message.*` keys instead of hardcoded Turkish strings). All changes are additive on the wire — same response shape, same status codes, same UI. Three regression tests guard the two security fixes. Existing consumer apps pick the patches up via `php artisan sk:update`; no migration, no config, no breaking change.

#### Security

- **`Api/UserController::index` now delegates to `UserDatatableQuery` — same role-hierarchy filter as the admin panel.** Previously the API used a bespoke `DatatableQueryBuilder` chain that skipped the `whereDoesntHave('roles', sort_order < me)` clause `UserDatatableQuery` enforces. Result: a non-`system_admin` API consumer holding `users.read` could `GET /api/v1/users` and see every higher-rank user — including `system_admin` accounts — whereas the admin UI hid them. The controller now method-injects `UserDatatableQuery` and returns its `response($request->user())` directly. The query's allowlists were extended with the `first_name`, `last_name`, `email`, `status`, `id`, `created_at` sortable keys (previously API-only) so the wire contract for legitimate API callers is unchanged. Covered by the new `tests/Feature/Api/UserTest.php` "hides higher-rank users from non-system_admin api callers" regression test.

- **`Admin/RoleController::data` now runs `CanManageRoleQuery` before returning role JSON.** `data()` is the JSON sibling of `edit()` (the admin role form prefetches it via `useApi().get('/admin/roles/{role}/data')`). `edit()` and `destroy()` already gated through `CanManageRoleQuery::check()` to enforce the role hierarchy; `data()` did not — so a lower-rank admin could read the full permission set of a higher-rank role over JSON, even though the form they would render the data into is hierarchy-aware. The check is now inlined at the top of `data()` (`abort(403)` on mismatch), mirroring `edit()`. Covered by two new `tests/Feature/Admin/RoleManagementTest.php` regression tests ("forbids non-system_admin from reading higher-rank role data" + the positive sibling for same/lower rank).

#### Fixed

- **2FA enable/disable buttons no longer get stuck on error.** `Profile/components/TwoFactorTab.vue` set `twoFactorProcessing = true` before calling Fortify, but only reset it on the success branch. An axios 4xx/5xx (typical: an expired session, password-confirm timing out) or an Inertia `router.reload` error left the button spinner stuck until full page reload. Both `enableTwoFactor()` and `disableTwoFactor()` now reset the flag in a `finally` block, so any failure surfaces as a re-clickable button + a toast (rather than a frozen UI).

- **`v-role` directive now reads the correct Inertia shared-prop key.** `resources/js/plugins/permission.ts` checked `auth.roles`, but `HandleInertiaRequests` shares the user role names under `auth.role_names`. The directive silently always evaluated to `false` — `<div v-role="'system_admin'">` markup was never visible regardless of the actor's role. The plugin now reads `auth.role_names`. The duplicate `useCan` export inside the plugin file (which read the same wrong key) was removed too — the canonical `useCan()` lives at `@/composables/useCan` and was already correct, so application code was unaffected. The plugin file now exports only the `PermissionPlugin` (registers `v-can` + `v-role`).

- **`useApi` composable error messages flow through `sk-message.*` i18n keys.** `resources/js/composables/useApi.ts` had three hardcoded Turkish error strings (synthesized envelope on non-JSON response, network-failure toast detail, toast `summary`). Replaced with `trans('sk-message.invalid_response')`, `trans('sk-message.request_failed', { status })`, `trans('sk-message.network_error')`, `trans('sk-message.error_summary')`. The four new keys are added to both `lang/en/sk-message.php` and `lang/tr/sk-message.php`. EN-locale users no longer see Turkish copy when an API call fails outside the normal envelope path.

#### New

- **Regression tests for the two security fixes.** `tests/Feature/Api/UserTest.php` gains the `hides higher-rank users from non-system_admin api callers` test — seeds the role hierarchy via `RoleEnum` index, mirrors `users.read` + `admin` role into the `api` guard (Spatie's `Guard::getDefaultName()` switches to `api` under `Passport::actingAs`), assigns both web + api versions of the role to an admin user, and asserts the response excludes the higher-rank `system_admin` peer + the acting `system_admin` user but includes the same-rank admin peer. `tests/Feature/Admin/RoleManagementTest.php` gains two: `forbids non-system_admin from reading higher-rank role data` (admin gets 403 on `/admin/roles/{system_admin}/data`) and `allows non-system_admin to read lower-rank role data` (admin gets 200 on `/admin/roles/{user}/data`).

## 2026-04-25 -v.13.4.4

### Patch release — system-admin log viewer (`/logs`)

Adds a maintainer-only admin section for browsing, searching and deleting Laravel log files in `storage/logs/`. Self-contained — no new composer or npm dependency, no migration, no permission entry. Visible only to `system_admin` users; everyone else still sees the same panel as before. All additive.

#### Added

- **`/logs` admin section — system-admin-only log viewer.** A new sidebar item under "System" lists the contents of `storage/logs/` in an `SkDatatable` (filename, channel type, size, modified time, active flag), and a per-file viewer page applies structured filters (level, date range, keyword) over a cursor-paginated entry stream. Single + bulk delete are wired through the same endpoint with partial-success semantics — active files (today's daily log, anything written within the last 5 seconds) are refused per-file and reported back in `failed[]`, the rest go through. Each delete batch dispatches a `LogFilesDeleted` event; the new `LogActivityForLogFilesDeleted` listener writes a `spatie/activitylog` entry under `log_name = system`, so deletions surface automatically in **Admin → Activity Logs**.

- **`app/Domain/Logs/` bounded context.** Four DTOs (`LogFileDTO`, `LogEntryDTO`, `LogEntryFilterDTO`, `DeleteLogFilesDTO`), two queries (`LogFileQuery` for the file list, `LogEntryQuery` for streaming entries), one action (`DeleteLogFilesAction`), one event/listener pair, and a stateless `LaravelLogParser` service. `LogEntryQuery::paginate()` reads the file with `fopen('rb')` + 64KB-capped `fgets()` and a byte-offset cursor, so memory stays bounded regardless of file size; multi-line stack traces are kept attached to the entry that opened them, and any line that appears before the first Laravel-format header (or in a file with no headers at all) surfaces as a single raw `LogEntryDTO` (`is_raw = true`, gray chip, hidden timestamp) so file content is never silently dropped. Raw entries are filtered out the moment any structured filter (level / from / to / keyword) is applied.

- **`logs.*` named route group.** `routes/web/log-route.php` ships five routes — `index`, `dtApi`, `show`, `entries`, `destroy` — wrapped in `role:system_admin`. The `{filename}` parameter constraint (`[A-Za-z0-9._-]+\.log`) is enforced on both `show` and `entries`, so path traversal and non-`.log` requests never reach the controller. The file is added to the `$routesWithoutPermissionMiddleware` allowlist in `routes/web.php` because the section is role-gated, not permission-gated.

- **`lang/{en,tr}/sk-log.php` translation file.** All UI copy (filter labels, empty states, delete confirmations, failure reason codes) lives behind the `sk-log.*` namespace in both languages. The new `sk-menu.logs` key labels the sidebar entry.

#### Security

- **Path-traversal guardrail at three layers.** The safe filename regex `^[A-Za-z0-9._-]+\.log$` is enforced in (1) the route parameter constraint, (2) `DeleteLogFilesRequest` rules, and (3) `DeleteLogFilesAction::execute()` itself (defence in depth). Anything else returns a `log.invalid_filename` failure or a 404 from the route binding — the disk path is never built from raw input.

- **Active-file deletion refused.** `LogFileQuery::isActive()` flags today's daily file (`laravel-{today}.log`) and any file with an `mtime` within the last 5 seconds. `DeleteLogFilesAction` rejects flagged files per-item with `reason: 'active_file_protected'`, so a bulk submit cannot accidentally truncate the file Laravel is currently appending to.

- **`role:system_admin` route gate, no permission entry.** The viewer is intentionally **not** added to `config/permission-resources.php`. Granting an `admin` role does not unlock it; only the dedicated `system_admin` role does. Non-system-admin users get a 403 on the route and never see the menu item — the feature is invisible to them.

- **Per-line read cap of 64KB.** `LogEntryQuery` calls `fgets($handle, 65536)`, so a pathological single-line entry of unbounded size cannot exhaust process memory. Long lines truncate cleanly without aborting the request.

## 2026-04-25 -v.13.4.3

### Patch release — rich vertical tabs + datatable per_page upper bound

Brings a richer vertical tab presentation through the `TB` builder (icon tile, description line, trailing badge or check) and an opt-in upper bound on the `?per_page=` query parameter handled by `DatatableQueryBuilder`. Both are additive — no breaking changes. `sk:update` ships the new TabBuilder Vue components, the rewritten `_tabs.scss`, and the EN/TR `sk-setting.tab_descriptions` language keys; `composer update` is sufficient for the package-tier `max_per_page` config.

#### Added

- **`TB.item()` rich vertical tab fluent methods.** Four new fluent methods: `.description(text)` for a secondary line under the label, `.iconColor(color)` for a colored icon tile preset (13 colors: `blue`, `amber`, `emerald`, `purple`, `teal`, `red`, `indigo`, `slate`, `pink`, `orange`, `cyan`, `green`, `yellow`), `.badge(value, severity?)` for a trailing badge (5 severities: `success`, `warn`, `info`, `danger`, `secondary`), and `.checked()` for a trailing green check (takes precedence over `badge`). Existing tab definitions render unchanged. The shipped Settings → General page uses the new API (per-tab description + icon color) as the canonical example. New i18n block `sk-setting.tab_descriptions` covers the seven settings tabs.

- **`STARTER_KIT_DATATABLE_MAX_PER_PAGE` env var + `config('starter-kit.datatable.max_per_page')`.** Opt-in upper bound on the `?per_page=` query parameter for `DatatableQueryBuilder`. Defaults to `100` when the config key is absent.

#### Security

- **`DatatableQueryBuilder` — `?per_page=` upper bound enforced.** Previously a client could send `?per_page=99999` and force the builder to materialise an entire table into a single payload. The new ceiling (`config('starter-kit.datatable.max_per_page')`, default 100) silently clamps the value — anything inside the cap behaves identically, so legitimate callers are unaffected.

#### Improved

- **Vertical tab sidebar — PrimeVue Card wrap via `.isCard(true)`.** Set at the tabs level (not per-tab), the vertical sidebar wraps in a Card with reduced internal padding. Combined with the new icon tile + description fields, the Settings page sidebar now matches modern admin-panel layouts out of the box.

#### Fixed

- **Branding — legacy "Starter Kit 12" references.** Two places still read "Starter Kit 12" — `config/scramble.php` API description and `app.blade.php` fallback title; both now read "Starter Kit 13".

## 2026-04-24 -v.13.4.2

### Patch release — Tiptap editor input, password generator, dashboard welcome message + security hardening

Introduces a rich-text `FB.editor()` FormBuilder field (Tiptap v3 under the hood) paired with a server-side `HtmlSanitizer` utility, a crypto-safe password generator on `FB.password()`, and an admin dashboard welcome message authored through the editor on **Settings → General**. File upload gains an optional `folder_name` parameter so editor-scoped uploads stay grouped, and the FileManager now surfaces a dedicated error for 413 Payload Too Large. All additive — no breaking changes. `sk:update` ships the published files (new Vue components, `HtmlSanitizer`, language keys); `composer update` is sufficient for package-tier changes.

#### Added

- **Tiptap-based `FB.editor()` FormBuilder input.** A new form field type backed by Tiptap v3 with bubble menu, link / image / table / task list / text align / text color / text style and placeholder extensions. Toolbar layout is chosen via `.toolbar('minimal' | 'standard' | 'full')`; image uploads route through the FileManager context with an optional folder-grouping parameter, and companion components (`EditorColorPalette`, `EditorImagePicker`) cover the color and image picker flows. Translations live in `lang/{en,tr}/sk-editor.php`. Content flows through the new `App\Support\HtmlSanitizer` on save, so only allowlisted tags / attributes / URL schemes are persisted.

- **`FB.password().generator()` — crypto-safe password generator.** Opt-in fluent method that adds a generate button next to the password field, backed by `crypto.getRandomValues()`. Defaults are intentionally stricter than `Password::defaults()` (16 characters, mixed case + letters + digits + symbols) so every generated value passes the framework-wide password policy on the first submit. Paired with a rewritten custom eye toggle so `password` and `password_confirmation` fields render identically inside `InputGroup` containers. PrimeVue `<Password>` is now only used when `.feedback()` opts in to the strength meter — every other usage falls through the lighter `InputText + eye` path. Enabled on the admin User form out of the box.

- **Admin dashboard welcome message.** **Settings → General** gains an optional `welcome_message` WYSIWYG field rendered through `FB.editor()`. The dashboard shares the sanitized HTML as an Inertia prop, and `resources/js/pages/Admin/Dashboard/Index.vue` renders it inside an `sk-prose` container via `v-html`. The value is sanitized on write (FormRequest `prepareForValidation` hook) and on read (DashboardController defense-in-depth pass) so pre-existing rows with hostile markup cannot surface even if the on-disk value drifts.

- **File upload `folder_name` parameter.** `POST /file-manager/files` now accepts an optional `folder_name` string (nullable, `max:100`, strict regex: letters / digits / space / dash / underscore only — path-traversal and arbitrary-character risk closed at validation). When supplied, `UploadFileAction::ensureManagedFolder` atomically ensures a root-level folder with that name exists in the current context and stores the upload inside it. The welcome-message editor uses this to keep all inline image uploads grouped under a single "Welcome Message" folder without a read query ever writing side-effects. Frontend `EditorImageUploadConfig` exposes the same field via `folderName`.

#### Security

- **`App\Support\HtmlSanitizer` — allowlist for tags, attributes, and URL schemes.** New utility that strips every tag, attribute and URL scheme not on a small allowlist from editor payloads. URL handling flipped from blocklist to allowlist: relative URLs plus `http://`, `https://`, `mailto:` and `tel:` are permitted — anything else (`blob:`, `data:`, `file:`, `ftp:`, `javascript:`, `vbscript:`) is rejected. Covered by a dedicated `tests/Unit/HtmlSanitizerTest.php` suite.

- **`SettingService::normalizeValue()` — HTML sanitize on every write path.** `setValue()` and `setGroup()` now pass every value through a shared `normalizeValue()` hook. Keys listed in a new `HTML_SAFE_KEYS` whitelist (currently `general.welcome_message`) are run through `HtmlSanitizer::sanitize()` before hitting the database, so non-FormRequest writes — tinker sessions, scheduled commands, queued jobs — cannot leave unsanitized HTML behind.

- **Dashboard welcome message — defense-in-depth read sanitize.** `DashboardController::index` runs the stored welcome message through `HtmlSanitizer::sanitize()` a second time before sharing it to Inertia. Historical rows written before the write-path sanitize landed are neutralised, and a drifted or manually-poisoned DB value cannot reach the browser.

- **`UploadFileAction::ensureManagedFolder` — concurrency-safe managed folder creation.** The ensure path runs inside `DB::transaction` with `lockForUpdate` on the candidate row, falls back on a `QueryException` catch for the unique-constraint race, and restores soft-deleted folders via `withTrashed()` instead of creating a duplicate. Combined, the three layers close the race window where two parallel editor uploads could either deadlock on the same folder name or resurrect a soft-deleted row by creating a sibling that trips the unique index.

- **`UploadFileRequest` — `folder_name` input strictly validated.** The new field uses `nullable|string|max:100|regex:/^[\pL\pN _-]+$/u`; path-traversal and arbitrary-character content is rejected at the FormRequest boundary, not downstream.

#### Improved

- **FileManager upload error messages.** The client composable now recognises HTTP 413 (Payload Too Large) and surfaces the dedicated `too_large` translation (EN + TR) instead of a generic failure string; every other non-200 response carries the raw status code alongside the message, so upload failures are easier to diagnose without opening the devtools network tab.

- **Password field default render path.** Beyond the `.generator()` addition above, the default `FB.password()` render now uses `InputText` + a custom eye toggle rather than PrimeVue `<Password>`. Fixes the long-standing issue where `<Password>`'s built-in eye icon disappeared inside `InputGroup` addons, and makes `password` / `password_confirmation` fields render identically. `<Password>` is still used when `.feedback()` is called (strength meter path). New i18n keys: `generate_password`, `password_generated`, `password_generated_detail`, `show_password`, `hide_password` (EN + TR).

#### Fixed

- **`SettingsDefaultsQuery` read path no longer writes.** The previous release read the **Settings → General** screen and, as a side effect, tried to `firstOrCreate` a "Welcome Message" folder through `resolveWelcomeMessageFolderId()`. On installs with a soft-deleted folder of that name, the unique index rejected the insert and the admin saw a 500 on a pure read. The folder ensure path is now owned exclusively by `UploadFileAction::ensureManagedFolder` at upload time, and `SettingsDefaultsQuery` is side-effect-free again. The frontend `welcome_message_folder_id` Inertia prop binding is gone as well — the editor uses `folderName` directly.

- **Editor upload — stale `blob:` URLs no longer leak into the form payload.** `EditorInput.vue` now manually syncs the parent `v-model` after `setContent({ emitUpdate: false })`, so replaced / broken `<img src="blob:...">` fragments from a just-completed upload no longer travel to the server in the submitted HTML.

## 2026-04-22 -v.13.4.1

### Patch release — API response hardening + Postman/Apidog sync + OAuth UUID fix

This release bundles the end-to-end API response envelope rework (trace-id pipeline, centralised exception handler, leak-closing controller patches) with two new API client integrations (Postman and Apidog sync) and a pair of install-time fixes (OAuth UUID compatibility, automatic Passport personal access client). Most changes are additive (new body fields + headers, new admin buttons), but three API-response behavioural breaks matter for strict clients — see [docs/UPGRADE.md](UPGRADE.md). Fresh installs pick everything up automatically; existing projects should follow the upgrade guide. `sk:update` ships the published files; controller patches and the post-install Passport step are manual.

#### Security

- **Controller `$e->getMessage()` leaks closed (11 sites).** `FileManagerController` (bulkDelete/createFolder/renameFolder/moveItem/deleteFolder/upload/deleteFile), `Api/UserController::destroy`, and `Api/Auth/AuthController::login` + `twoFactorChallenge` swapped the `to_api(null, $e->getMessage(), 4xx)` pattern for `throw ApiException::*`. The client-facing message is unchanged, but the response now routes through the central handler — the `trace_id` is aligned, 500+ errors are logged, `X-Correlation-ID` is echoed. Moving away from raw `LogicException::getMessage()` closes the door on accidental internal-message leaks during future refactors.

- **`abort($code, 'msg')` no longer leaks the raw message.** The `HttpExceptionInterface` branch now uses the fixed `defaultMessageForStatus()` table instead of `$e->getMessage()`. `abort(400, 'SQL error: ...')` now returns `"Bad request."` in the body; the internal detail only surfaces in `debug.message` while `APP_DEBUG=true`. Use `throw ApiException::badRequest('...')` for controlled messaging.

- **`Api/AuthController` returns `UserResource` instead of a raw User.** `register`, `login` (default kind), `twoFactorChallenge`, and `me` now produce `data.user` via `UserResource::toArray()`. Raw Eloquent serialisation relied on `$hidden`; a future sensitive column could leak if forgotten. The resource makes the wire contract explicit.

#### Added

- **Postman sync — admin button + CLI.** New "Sync to Postman" action on the API Routes page (and `php artisan postman:sync`) pushes the Scramble OpenAPI spec through Postman's `/import/openapi` endpoint with `folderStrategy=Tags` so tags become folders. Each sync imports a fresh collection, persists the newly issued UID to the settings store, then best-effort deletes the previous collection — an `import-first, delete-after` sequence so a transient Postman outage or invalid token never leaves the workspace without a working collection. Configuration: Settings → API Clients → Postman card (API Key + Workspace ID; collection ID is managed automatically).

- **Apidog sync — admin button + CLI.** Same pipeline pushes to Apidog's `POST /v1/projects/{projectId}/import-openapi` endpoint with inline JSON input and `OVERWRITE_EXISTING` merge behavior. Also available as `php artisan apidog:sync`. Configuration: Settings → API Clients → Apidog card (Access Token + Project ID).

- **Settings → API Clients tab.** Single tab hosts both Postman and Apidog configuration as separate cards. Secret fields (`postman.api_key`, `apidog.access_token`) are encrypted at rest through the existing `sensitive_keys` list in `config/settings.php`. The previous `POSTMAN_*` `.env` keys are no longer used — existing values are migrated into the settings table.

- **Shared `OpenApiExporter` helper.** Both sync Actions share a single exporter that runs `scramble:export`, writes to a per-request unique path under `storage/app/postman/`, and cleans up in a `finally` block — the CLI command and the admin UI button can run concurrently without racing on a shared file. The spec is emitted **unchanged**: no content-type rewriting, so the pushed collection mirrors the real server contract (clients are free to toggle the body view between raw / form-data in their own UI).

#### Improved

- **Success and error responses share a single `trace_id`.** The new `AssignTraceId` middleware prepended to the `api` group generates a UUID per request and both branches (`ApiResponse::toResponse` on success, `ApiExceptionHandler` on error) pick it up from `$request->attributes`. Body `trace_id` + header `X-Request-ID` + a sanitised echo of the client's `X-Request-ID` as `X-Correlation-ID`. In support scenarios, client-side logs and server-side logs correlate via one id.

- **`ModelNotFoundException` message includes the model name.** `"The requested resource was not found."` → `"User not found."` (or `Role`, `Product`, …). `ApiExceptionHandler::modelNotFoundMessage` resolves it via `class_basename($e->getModel())`. Matches the previous AGENTS.md contract; no security impact since the model class name is already inferable from the URL.

- **`Retry-After` header propagated on 429 responses.** All rate-limit headers from `ThrottleRequestsException::getHeaders()` (`Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`) are copied to the response. Throttled clients can read the standard header instead of parsing it out of the message string.

- **`simplePaginate()` support.** `to_api(Model::simplePaginate(15))` no longer raises a type error; lightweight pagination with just `meta.has_more` is now supported. `LengthAwarePaginator` and `CursorPaginator` behaviour unchanged.

- **`to_api(paginator, 'msg', 201)` no longer loses pagination meta.** Helper's paginator detection now runs before the 201/202 branches; batch-create style endpoints emit meta too (previous release serialised the paginator as a raw object — silent bug).

- **`ApiResponse` DRY + `final`.** The meta builder for `paginated()` and `paginatedCollection()` was extracted into a single private helper. The class is now `final` to prevent subclass invariants drifting. No behavioural change to the controller return-type signatures; public API surface unchanged.

- **Scramble `ApiResponseExtension` schema descriptions enriched.** Each envelope field now has a definition + example + validation-rule description. Multi-status schema (distinct `Response` objects for 201 / 204 / 4xx / 5xx) is deferred — `TypeToSchemaExtension` does not model it directly, so `OperationExtension` will take over in a follow-up.

#### Fixed

- **OAuth migrations UUID-compatible.** `oauth_access_tokens.user_id` and `oauth_auth_codes.user_id` are now `foreignUuid` (previously `foreignId` / `bigint unsigned`); `oauth_clients.owner_*` is now `nullableUuidMorphs`. Combined with the UUID `users.id` primary key shipped by this starter kit, the previous mismatch surfaced as `SQLSTATE 1265: Data truncated for column 'user_id'` on login — the API login flow is now clean out of the box.

- **`site:install` provisions the Passport personal access client automatically.** A `passport:client --personal --provider=users` step was added between `passport:keys` and the admin-user seed. Fresh installs can issue API tokens right away; previously the operator had to remember to run that command manually after `site:install`.

- **202 Accepted dead code removed.** The `'Operation queued.'` fallback for `to_api($data, '', 202)` never fired (the default `$message` was truthy). Helper simplified to a single logical flow.

- **`ApiResponse::toResponse()` honours the `$request` parameter.** The previous implementation accepted the `Responsable::toResponse($request)` signature but ignored the argument — integration with the new middleware depends on this parameter, which is now actually consumed.

- **Exception handler `match` ordering criticality documented.** `ApiException extends HttpException`, so it must be matched before the `HttpExceptionInterface` branch — otherwise custom API exceptions would fall through to the generic abort() handling. The fragile ordering is pinned by a comment and by the regression suite (`tests/Feature/Api/ApiResponseTest.php`).

#### New

- **Regression test file: `tests/Feature/Api/ApiResponseTest.php` (16 tests, 57 assertions).** Covers the envelope shape, exception → status mapping, trace id agreement, empty 204 body, `Retry-After` propagation, `debug` guarded by `APP_DEBUG=true`, and the sanitised `X-Correlation-ID` echo. Example copy available at `vendor/lvntr/laravel-starter-kit/tests/examples/ApiResponseTest.php`.

- **Expanded `sk:update` coverage.** `app/Http/Middleware/AssignTraceId.php` and `app/Helpers/sk-helpers.php` joined the safe-update list; `php artisan sk:update` now syncs both automatically. `ApiResponse.php` and `ApiExceptionHandler.php` were already tracked.

#### Breaking

Detailed migration steps in [docs/UPGRADE.md](UPGRADE.md). Summary:

- `abort($code, 'custom message')` no longer surfaces the message — use `throw ApiException::*` instead.
- `ModelNotFoundException` message now includes the model name (`"User not found."`). Frontend regex matches may need to loosen.
- `Api/Auth/AuthController` `data.user` is limited to `UserResource::toArray()` output. If you depended on a raw-model field, extend the resource.

## 2026-04-21 -v.13.4.0

### Minor release — Security hardening sprint

A parallel code-review sweep surfaced ~37 findings — 13 HIGH, 14 MEDIUM, 4 LOW. 36 are closed in this release; 1 HIGH (Passport private-key rotation in git history) is a manual operator step. Most patches touch **published** files (the files `sk:install` copies into your app), so existing consumer apps need to apply the diffs in [docs/UPGRADE.md](UPGRADE.md). Fresh installs pick everything up automatically. The rare package-tier changes (HSTS `preload`, stub updates) arrive via `composer update lvntr/laravel-starter-kit`.

#### Security

- **Self-delete blocked on `UserPolicy::delete` + null guard on API `UserController::destroy`.** `UserPolicy::delete` previously returned `true` when actor === target, so any authenticated user holding `users.delete` could remove themselves via `DELETE /api/v1/users/{self}`. The self-branch now returns `false` — the only supported self-removal path is the password-confirmed Fortify flow in Profile. `Api\UserController::destroy` also returns a clean 401 when `$request->user()` is null (stale/expired bearer), replacing the previous `(string) null = ''` cast that would log an empty performer id.

- **`CreateRoleAction` + `UpdateRoleAction` wrap role + permission sync in `DB::transaction`.** `Role::create(...)` followed by `->syncPermissions(...)` ran outside a transaction; a permission-cache race or a connection drop between the two writes could leave a role row with no permissions. Both actions now run inside `DB::transaction(...)`; `RoleCreated` / `RoleUpdated` dispatch after commit so listeners observe a consistent state.

- **`UpdateAuthSettingsAction` wraps the 2FA revoke loop in `DB::transaction`.** When the admin toggles `auth.two_factor` off, the action writes the setting and then clears `two_factor_secret` / `two_factor_recovery_codes` / `two_factor_confirmed_at` on every user. A failure mid-loop used to leave the system in a half-revoked state — the setting said "2FA off" but some users still had active TOTP secrets. The full operation is now atomic.

- **`LogoutUserAction` null-safe token revoke.** The API logout endpoint called `$user->token()->revoke()`; if the request hit the controller without an active access token (stale token, cleared cache, worker race) the chained call threw `Error: Call to a member function revoke() on null` and the endpoint 500'd. Now uses `?->revoke()` — returns a clean 204 even when the token is already gone.

- **FileManager subtree walks reduced from N queries to 1.** `BulkDeleteAction::collectDescendantIds` and `DeleteFolderAction::collectDescendantIds` issued a `FileFolder::find` per hop when walking the subtree of a folder being deleted — a 50-level tree meant 50 serial queries, and the cost grew with siblings, giving attackers a request-timing DoS knob. Both actions now load the owner-scoped `(id, parent_id)` map in one `select` and walk the tree in PHP with a visited-set cycle guard for corrupt data.

- **SMTP `encryption=none` now disables TLS correctly.** The shipped Mail settings screen offered a "No encryption" option, but `SettingsServiceProvider` rebroadcast the literal string `'none'` into `config('mail.mailers.smtp.encryption')`. Laravel's SMTP transport treats any non-null value — including `'none'` — as "use this TLS mode", so saved "No encryption" configurations fell back to the default STARTTLS upgrade on the first connect and could fail against servers that do not offer it. The provider now maps `'none' → null` on the outbound config write.

- **`ApiExceptionHandler` — exception-message leak + `X-Request-ID` log injection.** The `default` arm of the exception→status mapping returned `config('app.debug') ? $e->getMessage() : 'A server error occurred.'`; in any environment where `APP_DEBUG` was accidentally left on, unhandled exceptions leaked stack-trace-grade detail to API consumers. The handler now returns the generic message unconditionally; debug details live only in `Log::error` plus the `debug` block that is already gated on `APP_DEBUG`. The trace id is now always server-generated via `Str::uuid()`; any `X-Request-ID` header sent by the client is accepted only as correlation metadata after a charset + length-cap sanitiser (`[A-Za-z0-9._-]`, ≤128 chars), then logged as `client_request_id` — a malicious client can no longer inject a CRLF payload or a fake trace id into the application log.

- **`SecurityHeaders` HSTS directive gains `preload`.** The baseline HSTS header moved from `max-age=31536000; includeSubDomains` to `max-age=31536000; includeSubDomains; preload`, making the deployment eligible for the HSTS preload list. Ships from the package `src/` — picked up automatically by `composer update`.

- **Password policy raised to 10+ / mixed case / digits / symbols.** `AppServiceProvider` now installs a project-wide `Password::defaults(...)` that every FormRequest relying on the default picks up automatically (registration, password reset, password confirm, profile password change). Existing users' passwords are not invalidated — only new passwords are measured against the stronger rule.

- **Axios CSRF + credential defaults.** `resources/js/app.ts` now sets `axios.defaults.withCredentials = true`, `xsrfCookieName = 'XSRF-TOKEN'`, `xsrfHeaderName = 'X-XSRF-TOKEN'`, plus `X-Requested-With: XMLHttpRequest` and `Accept: application/json`. The admin UI calls Fortify endpoints (2FA, sessions, password-confirm) directly via Axios; without `withCredentials` and the XSRF header the browser sent the session cookie but not the CSRF token on mutating requests, so a compromised origin could interact with the session without the CSRF check the web flow relies on.

- **2FA QR code rendered through `<img src="data:image/svg+xml;base64,...">` instead of `v-html`.** Fortify returns the QR code as an SVG string. The previous `v-html="qrCodeSvg"` worked but would have evaluated `<script>` or `onload` attributes in the SVG if a man-in-the-middle (or a compromised Fortify override) slipped them in. The new approach base64-encodes the SVG into an `<img>` data URL — the `<img>` sandbox does not execute inline scripts, even if the SVG contains them.

- **`useDefinition.load()` / `loadAll()` no longer flip `loaded.value = true` on a failed fetch.** The composable is the one-stop loader for the definition JSON that drives datatable / form option dropdowns. It previously chained `.then(r => r.json())` directly — if the fetch failed (network error, 500, parse failure) `loaded.value` stayed `true` and the UI kept rendering stale / empty option lists without any console feedback. Both methods are now wrapped in `try/catch`, `res.ok` is checked, errors surface to the console, and `loaded.value` stays `false` on failure so consumers can retry.

- **Eleven `FormRequest::authorize(): return true;` offenders closed.** The following requests — admin user store, API user store, admin role store, admin settings (auth/general/mail/storage/filemanager/turnstile), test-mail, destroy-sessions — now delegate `authorize()` to the matching `*.create` / `*.update` permission (destroy-sessions checks `$this->user() !== null`). The `CheckResourcePermission` middleware already enforced these at the route level, but moving the check into the request closes the defense-in-depth gap that would open the moment a controller action was invoked off-route (tests, internal dispatch) or the action map drifted out of sync with new route names. Public auth endpoints (`Api/Auth/*Request`) and FileManager context-based requests are intentionally left alone.

- **2FA challenge is now strictly single-use.** `TwoFactorChallengeAction` previously left the `api:2fa_challenge:{uuid}` cache entry intact on a wrong TOTP / wrong recovery code / empty submit, so an attacker with a valid challenge id got the full 5-minute TTL × `throttle:5/min` window to try codes. Every failure arm now calls `Cache::forget($cacheKey)` — the challenge id works exactly once; subsequent attempts hit `invalidChallenge()` and the client must re-login to get a fresh uuid.

- **`SettingService::getValue` / `getGroup` read from the `allGrouped()` cache + `setGroup()` wrapped in `DB::transaction`.** The hot read path previously ran one query per call even though a cache layer existed for the full `allGrouped()` result. Settings-heavy request paths (Dashboard, FileManager, Admin pages) saved a handful of round-trips per request. The bulk write path is also now atomic — a partial failure during a multi-setting save no longer leaves the DB in a mixed state.

- **`MoveItemRequest` — typed `item_id` based on `item_type`.** The rules used to accept any `item_id` value for any `item_type`. The effective rule is now `integer|min:1` for `item_type=file` and `uuid` for `item_type=folder`, matching the DB schema; `item_type` itself uses `Rule::in([...])` instead of the `string|in:...` string form.

- **`DeleteFolderRequest` — explicit FormRequest replaces a bare `Request`.** `FileManagerController::deleteFolder` previously accepted a raw `Request`, built the context in the controller, and called the authorizer directly. The new `DeleteFolderRequest` extends `FileManagerRequest`, runs the shared context rules, and exposes `$request->context()` — identical surface to the other FileManager endpoints; controller drops two lines of boilerplate.

- **`UserController::uploadAvatar` runs an explicit `Gate::authorize('update', $user)`.** `UploadAvatarRequest::authorize()` already delegates to `UserPolicy::update` when a `{user}` route param is bound, but the redundant Gate call in the controller mirrors the belt-and-braces pattern used on view/update/delete and keeps the check visible when reading the controller in isolation.

#### Security — manual operator step

- **GV-H1 — Passport private keys rotation.** `storage/oauth-private.key` and `storage/oauth-public.key` live in git history for legacy installs that committed them before the `.gitignore` rule landed. [docs/UPGRADE.md §6](UPGRADE.md#6-gv-h1--passport-private-key-rotation-critical-manual) documents the `git filter-repo` + `passport:keys --force` + `passport:purge` + team-wide `git reset --hard` sequence; this cannot be automated by the package. If your repo never committed the key files, skip this step.

#### Changed

- **`LOG_LEVEL` default is now `error`.** `.env.example` previously shipped `LOG_LEVEL=debug`, which in production (if committed verbatim) fills the log with SQL traces, Passport token debug info and similar — noisy and occasionally sensitive. Production profiles should ship `error` or `warning`.

- **`laravel/tinker` moved to `require-dev`.** Tinker is a developer convenience — shipping it as a production dependency pulled PsySH and its transitive chain into every container build. Local dev still installs it because it's in `require-dev`.

- **`.env.example` gains Passport key + Turnstile placeholders.** Two commented-out `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` stubs document the env-based key-loading path (the recommended alternative to committing `storage/oauth-*.key`), and an uncommented `TURNSTILE_ENABLED=false` + empty site/secret keys make the Turnstile middleware a no-op on fresh installs until the admin turns it on.

- **Inertia `appEnv` / `appDebug` shared props no longer leak in production.** `HandleInertiaRequests::share` used to return `config('app.env')` + `config('app.debug')` unconditionally. In production this leaked the environment name and advertised whether `APP_DEBUG` was on to every authenticated user. Both keys now return `null` / `false` under `app()->environment('production')`; non-prod keeps the real value for the dev overlay.

- **CORS preflight cache raised from 0 to 7200 seconds.** `config/cors.php` previously shipped `max_age => 0`, forcing the browser to re-run a preflight on every mutating request. With `max_age=7200` SPA / mobile clients cache the OPTIONS response for 2 hours.

#### Fixed

- **`useDialog` / `useImageLightbox` — 300 ms timer leak.** Both composables started a `setTimeout` in `close()` to delay DOM removal so the exit animation could play. A rapid `open → close → open` sequence could queue two timers, with the trailing one firing after the dialog was re-opened and cancelling the render. A module-level timer ref is now cleared on both `open()` and `close()` entry; the timeout body nulls the ref when it fires.

- **`SkForm` dirty-form guard — stops parent prop updates from wiping user input.** The `watch(derivedDefaults, ...)` block unconditionally reset the form to the new defaults whenever the parent passed a new object. If the user was halfway through filling a form and the parent polled (e.g. a sibling datatable refresh triggered a shared-state update), their in-progress input was wiped. The watcher now checks `internalForm.isDirty` — when the form is dirty, the new values are recorded as defaults (so a subsequent `reset()` picks them up) but the live form state is preserved.

- **`SkDatatable` URL filters — `api.get` + `Promise.allSettled`.** The URL-driven filter loader used bare `fetch(...)` + `Promise.all`, so a single 500 on one filter's options endpoint poisoned the whole filter bar via an unhandled rejection. The loader now uses the shared `api.get<T>()` helper (picks up the Axios defaults + XSRF) and `Promise.allSettled`, so each filter is independent; a failing endpoint falls back to an empty list with a console warning. Same file flips `let activeMenuItems` → `const activeMenuItems` (the ref was never re-assigned).

- **`TwoFactorTab.enableTwoFactor` awaits the Inertia reload.** The original code fired `router.reload({ only: [...] })` without awaiting and immediately moved on to `loadQrAndSetupKey()`. On a slow connection the QR fetch could race the reload and render a stale screen. `router.reload` is now wrapped in a promise that resolves on `onFinish`.

- **`ProfileInfoTab` / `UserForm` — drop `as any` avatar casts.** Two `(x as any)?.avatar_url` accesses were replaced with typed shapes — no behaviour change, but the cast hid a legitimate TypeScript error if the backing type ever dropped the `avatar_url` accessor.

- **`DashboardController::index` gains an explicit `: Response` return type.** Closes the last Larastan `return_type_missing` finding under the project's configured level.

### Upgrade

`composer update lvntr/laravel-starter-kit --with-all-dependencies` picks up only the package `src/` tier (HSTS `preload`, stub updates). Every other fix above lives in published / stub-backed files. Follow [docs/UPGRADE.md](UPGRADE.md) for the full diff-style patch list and smoke-test checklist.

## 2026-04-20 -v.13.3.3

### Patch release — Windows build fix for Builder core imports

#### Fixed

- **Windows production build failed with `Could not load .../FormBuilder/core`.** `FormBuilder`, `DatatableBuilder` and `TabBuilder` each expose a `core/` directory whose `index.ts` is imported as `@lvntr/components/<Builder>/core`. On some Windows setups Vite's resolver skipped the directory→`index.ts` step and fell through to `vite:load-fallback`, which tried to read the directory as a file and raised `ENOENT`. Fix: a sibling `core.ts` barrel file now re-exports from `./core/index` for each of the three builders, so the import resolves to a real file on every platform. macOS/Linux behaviour is unchanged, and existing subpath imports like `/core/builder` are untouched. Fixes lvntrdev/laravel-starter-kit#1.

## 2026-04-19 -v.13.3.2

### Patch release — security hardening, user audit events, Logo API envelope, media-delete policy, permission-middleware cache correctness, test bootstrap

A batch of latent bugs uncovered by a full test-suite audit, plus a dedicated security review pass that closed a privilege-escalation path in the admin user flow, stopped the settings screen from leaking SMTP/S3/Turnstile secrets to the frontend, and brought the API auth flow to parity with the web flow (email verification + two-factor challenge). Most of the original bugs only showed up under specific runtimes (Octane/queue workers, fresh clones without `site:install`) or silently swallowed side-effects (audit log for user writes).

#### Security

- **Privilege escalation via unvalidated role assignment — admin user flow.** `StoreUserRequest` and `UpdateUserRequest` used to validate the `role` field with `Rule::exists('roles', 'name')` only, so any user holding `users.create` or `users.update` could submit `role=system_admin` in a raw HTTP request regardless of what the admin UI dropdown offered — instantly granting themselves the super-admin role that bypasses every authorization gate via `Gate::before`. `UpdateUserRequest` additionally had no rank check on the target user, so a lower-ranked actor could edit (or demote) a higher-ranked one. Fix: `role` is now validated with `Rule::in(...)` built from `RoleSelectOptionsQuery`, the same hierarchy-aware list that feeds the dropdown (`sort_order >= actor's min sort_order`, `system_admin` excluded for non-system_admin actors). `UpdateUserRequest::authorize()` additionally rejects edits where the target's top-ranked role outranks the actor's. A user holding `users.*` as a direct Spatie permission without any assigned role is treated as the lowest possible rank — they can no longer assign any role or edit anyone other than themselves; the previous `(int) null = 0` fallback accidentally opened the full role list including `system_admin`.

- **Settings secrets no longer leak to the frontend.** The admin **Settings** page was sending `mail.password`, `storage.spaces_secret`, `storage.aws_secret` and `turnstile.secret_key` in plain text as Inertia props for any user with `settings.read`. Even values that lived only in `.env` leaked out through the `config()` fallback. Fix: `SettingsDefaultsQuery` now returns `null` for every secret field and adds a parallel `*_is_set: bool` flag. The admin UI renders a `••••••••` placeholder when a value is set and submits an empty string to keep the current secret; writing a non-empty value replaces it. The new `tests/Feature/Admin/Settings/SecretsDisclosureTest` asserts the Inertia payload never contains the raw secret string anywhere.

- **`storage.aws_secret` now stored encrypted at rest.** `config/settings.php` gained `storage.aws_secret` in its `sensitive_keys` list — it previously had `mail.password`, `storage.spaces_secret` and `turnstile.secret_key` but not the AWS counterpart, so S3 secrets saved through the UI lived as plaintext in the `settings` table. `SettingService` encrypts every listed key with `Crypt::encryptString` on write and decrypts on read.

- **`check.permission` middleware now fails closed in production.** The middleware used to allow the request through when a route-derived permission (e.g. `users.read` for `users.index`) was not seeded in the database. In production this silently unprotected any new route whose permission row was forgotten. The middleware now throws `AuthorizationException` (403) when running under `app()->environment('production')` and `Log::warning`s the unseeded permission in non-production environments — dev ergonomics preserved, the production foot-gun is closed.

- **Test-mail endpoint no longer reflects raw exception details.** `SettingsController::testMail()` used to flash the SMTP exception message (host / username / TLS details) back to the browser. The message is now written to `Log::error` with class + message context; the user sees a generic "Failed to send test email. Check the server logs for details." — same success/failure signal without the information disclosure.

- **API auth — email verification and two-factor parity with the web flow.** The API previously handed out an access token immediately on register and on any successful password login, bypassing the same email-verification and 2FA checkpoints the web flow enforces. All three `POST /api/v1/auth/*` endpoints were reworked:
    - **`register`** — when Fortify's `emailVerification` feature is enabled (the default), no token is issued on registration. The endpoint creates the user, fires `Illuminate\Auth\Events\Registered` (so Fortify's notification pipeline sends the verification link) and returns `{ data: { user, requires_verification: true } }` with 201. When the feature is disabled, the previous token-on-register behaviour is kept.
    - **`login`** — returns a discriminated payload:
        - `{ user, token }` — normal success
        - `{ requires_verification: true }` — credentials are valid but the email is not verified (when the verification feature is on)
        - `{ requires_two_factor: true, challenge: "<uuid>" }` — credentials are valid but the account has confirmed 2FA; a single-use challenge id is issued with a 5-minute cache TTL. No access token is issued yet.
    - **`two-factor-challenge`** — new endpoint `POST /api/v1/auth/two-factor-challenge` (throttled `5/min`). Accepts `{ challenge, code }` for TOTP or `{ challenge, recovery_code }`. On success it returns `{ user, token }`. TOTP is verified via Fortify's `TwoFactorAuthenticationProvider`; recovery codes are matched with `hash_equals` and consumed via `replaceRecoveryCode` so they cannot be reused. Invalid / unknown / expired challenges return 401.

    **Breaking for API consumers** — existing clients that expected `{ user, token }` on every 2xx response from `register` / `login` must now branch on `data.requires_verification` and `data.requires_two_factor` flags, and complete the challenge at `/api/v1/auth/two-factor-challenge` before receiving a token when 2FA is confirmed on the account. Non-2FA, verified users keep seeing the old shape.

- **Settings `required` validation now matches the UI secret indicator.** `UpdateMailSettingsRequest` and `UpdateTurnstileSettingsRequest` previously only checked the DB row when deciding whether a secret was "already set"; if the value lived only in `.env`, the UI's `*_is_set` flag reported `true` (because `SettingsDefaultsQuery` falls back to `config()`) but submitting the form with a blank password / secret_key triggered a confusing `required` validation error. The `required` branch now mirrors the query — DB row OR config fallback — so env-backed installations no longer see the spurious error.

- **IDOR on admin avatar upload / delete.** `POST /users/{user}/avatar` and `DELETE /users/{user}/avatar` resolved to no permission under `CheckResourcePermission` because the route actions `uploadAvatar` / `deleteAvatar` were not in the middleware's `ACTION_ABILITY_MAP`; the middleware returned `$next($request)` without a permission check. `UploadAvatarRequest::authorize()` also returned `true` unconditionally. Any authenticated + email-verified user (including `user` role with only `dashboard.read`) could overwrite or delete any other user's avatar — system admin included. Fix: the action map now contains `uploadAvatar => update` and `deleteAvatar => update`; `UploadAvatarRequest::authorize()` delegates to `UserPolicy::update` when a `{user}` route param is present (self-upload via Profile route is preserved); `SettingsController::deleteAvatar` calls `Gate::authorize('update', $user)` explicitly.

- **Admin `UserController` and API `UserController`: rank-hierarchy guard on view / update / delete.** `GET /users/{user}/data`, `GET /users/{user}/edit`, `DELETE /users/{user}`, `PATCH /api/v1/users/{user}` and `DELETE /api/v1/users/{user}` used to rely solely on the `users.read` / `users.update` / `users.delete` permission and the (admin-only) `UpdateUserRequest::authorize()` rank check. A lower-ranked admin holding the permission could still read or delete a higher-ranked user through the data endpoint or the API. Fix: `UserPolicy::view / update / delete` now run the same `canManage()` rank check used by the admin update request (system_admin bypasses, role-less actors are treated as the lowest rank). Admin and API controllers call `Gate::authorize('view' / 'update' / 'delete', $user)` on every cross-user operation. The admin `UpdateUserRequest` and the API `UpdateUserRequest` both delegate `authorize()` to `UserPolicy::update` so the rank check is uniform across flows.

- **`POST /api-routes/regenerate-docs` was reachable by any authenticated user.** The route action `regenerateDocs` was not in the `ACTION_ABILITY_MAP`, so `CheckResourcePermission` returned `$next($request)` without a permission check. Any authenticated + verified user could trigger the OpenAPI regeneration (which runs an artisan command server-side). Fix: `regenerateDocs => update` added to the map; `api-routes.update` added to `config/permission-resources.php` so the seeder creates the permission row.

- **SVG uploads blocked on logo + FileManager.** Both the admin logo uploader (`SettingsController::uploadLogo`) and the FileManager default MIME list accepted `image/svg+xml` and stored the file on the `public` disk. SVG can embed `<script>`, `onload` and foreignObject JavaScript; when a victim opens the direct `/storage/...` URL, the script executes in the app origin (stored XSS). Fix: logo validation now pins `mimes:png,jpg,jpeg,webp` + `dimensions:max_width=4096,max_height=4096`. `UploadFileRequest` keeps a `BLOCKED_MIMES` list (`image/svg+xml`, `image/svg`, `text/html`, `application/xhtml+xml`) that is stripped from the effective MIME list on every upload, regardless of what is stored in `file_manager.accepted_mimes`. `UpdateFileManagerSettingsRequest` rejects those MIME types at settings-save time via `Rule::notIn(...)` plus a `^[a-z0-9.+-]+/[a-z0-9.+-]+$` regex. The admin UI pickers (`MimePickerField`, `FileManagerTab`, `GeneralTab` logo input) no longer list SVG. `SettingsDefaultsQuery::fileManager()` also strips the blocked MIMEs from the stored list before sending the payload to the UI, so older installs whose seed included `image/svg+xml` no longer see it as a selected option.

- **Avatar rule tightened.** `UploadAvatarRequest::rules()` used to be `['required','image','max:2048']` — the `image` rule allows SVG and does not bound pixel dimensions, leaving the door open for polyglot files and decompression-bomb PNGs. New rule: `required | image | mimes:jpg,jpeg,png,webp | max:2048 | dimensions:max_width=4096,max_height=4096`.

- **`media-library.disk_name` now defaults to `local`.** The previous default was `public` — if the installer seeder failed, an admin flipped the FileManager disk toggle, or someone deployed without running the seeder, user-uploaded documents landed on a world-readable URL. The default is now `local` so missing configuration fails closed; the FileManager already streams downloads through `DownloadFileAction`, it never needed a public URL path.

- **`SESSION_ENCRYPT` + `SESSION_SECURE_COOKIE` default to `true`.** `config/session.php` had `'encrypt' => env('SESSION_ENCRYPT', false)` and `'secure' => env('SESSION_SECURE_COOKIE')` (null default). A deployment that forgot to set either env var would ship plaintext session payloads over an insecure cookie on HTTPS. Both defaults are now `true`; local dev continues to work because `.env.example` already sets both to `true` and Herd serves over HTTPS.

- **`SecurityHeaders` middleware now emits a baseline CSP.** The middleware already set X-Frame-Options / X-Content-Type-Options / Referrer-Policy / Permissions-Policy / HSTS, but no `Content-Security-Policy`. With two `v-html` sinks in the codebase (the Fortify 2FA QR SVG and the DataTable `column.render` escape hatch) a CSP meaningfully limits blast radius. The header is applied in non-local environments only — Vite HMR in local dev needs the dev-server origin on script/connect/style, which varies per developer, so enforcing a tight CSP there would just block normal work.

- **Scramble "Try It" disabled in production.** `config/scramble.php` shipped with `hide_try_it: false` and `try_it_credentials_policy: 'include'`, which in production handed any admin with `api-docs.read` an in-browser API tester that attached their session cookies to every request. Both values now branch on `APP_ENV === 'production'` (hidden + `omit` in prod, interactive in local/staging).

- **Passport access-token TTL shortened, scope catalogue seeded.** Access tokens were valid for 15 days, personal access tokens for 6 months. A leaked bearer token stayed usable for weeks. Defaults are now `access_token_minutes=60`, `refresh_token_days=14`, `personal_token_days=30`; the legacy `PASSPORT_TOKEN_DAYS` / `PASSPORT_PERSONAL_TOKEN_MONTHS` env keys still take precedence when set, so existing installs are not disturbed. `config/starter-kit.php` also ships an opt-in scope catalogue (`users.read`, `users.write`, `files.read`, `files.write`, `admin`) so `Passport::tokensCan()` is pre-wired; attach `middleware('scope:...')` to specific API routes when you are ready to enforce per-scope access.

- **API register / login now honour the `turnstile` middleware.** Cloudflare Turnstile was already wired for the browser auth forms via `FortifyServiceProvider` + `ValidateTurnstile`, but the API routes (`POST /api/v1/auth/register`, `POST /api/v1/auth/login`) only had the `throttle:5,1` limiter. An attacker could automate account registration at five accounts per IP per minute. Both routes now run through the existing `turnstile` middleware alias — when Turnstile is disabled in settings the middleware is a no-op, when it is enabled the API picks up the same `cf_turnstile_response` enforcement as the web forms.

#### Fixed

- **User domain events now fire on Create/Update/Delete.** `App\Domain\User\Actions\CreateUserAction`, `UpdateUserAction` and `DeleteUserAction` previously had their `UserCreated::dispatch(...)` / `UserUpdated::dispatch(...)` calls commented out or missing — listeners registered in `DomainServiceProvider` (e.g. the audit-log listener) never ran for user writes. `Create` and `Update` now dispatch when a change actually occurs (no-op updates do not fire `UserUpdated`); `Delete` captures the id/email before deletion and dispatches `UserDeleted` on success, matching the `Role*` action pattern.

- **Admin `users.show` route returned 500.** `routes/web/user-route.php` registered `Route::resource('users', UserController::class)`, which implicitly opened a `GET /users/{user}` route — but `UserController` never had a `show()` method, so any hit on that URL threw `BadMethodCallException`. The resource registration is now scoped with `->except(['show'])`; detail data remains available via the existing `GET /users/{user}/data` endpoint used by the admin UI.

- **Settings logo endpoints now return the `ApiResponse` envelope.** `POST /settings/logo` and `DELETE /settings/logo` in `App\Http\Controllers\Admin\SettingsController` used to return raw `response()->json([...])` / `response()->json(status: 204)`, breaking the "every JSON response carries `{ success, status, message, data }`" contract that the rest of the admin API follows. Both endpoints now go through `to_api(...)`. The frontend consumer (`GeneralTab.vue`) reads `json.data.logo_url`, which is unchanged.

- **`App\Policies\UserPolicy` gained a `delete` ability.** `DELETE /media/{media}` calls `Gate::authorize('delete', $media->model)` in `MediaUploadController`. For a media item owned by a `User`, the `delete` ability was undefined on `UserPolicy` (only `view` and `update` existed), so the Gate fell through to the default deny and returned 403 — even for the owner deleting their own avatar/file. The new `delete(User $actor, User $user)` method mirrors `update`: self is always allowed, otherwise the actor needs the `users.delete` permission.

- **`CheckResourcePermission` middleware: process-wide cache replaced with request-scoped cache.** The permission-existence lookup inside the middleware held its result in a `static $cached` variable. On long-lived workers (Laravel Octane, queue workers that keep the container warm across jobs) this cache never rebuilt, so newly-created permission rows were invisible until the worker restarted. Worse, inside the test suite the static survived across tests — `RefreshDatabase` truncated the `permissions` table between tests but the middleware kept reporting permission names seeded by an earlier test as still existing, producing intermittent 403s on routes that should have been permission-less. The cache is now stored via `app()->instance('check-permission.cache', ...)` — request-scoped in production, test-scoped under the testing container.

- **`UserFactory` seeds `two_factor_*` columns as `null` by default.** Eloquent strict mode (`Model::shouldBeStrict(! isProduction())`, set by `Lvntr\StarterKit\StarterKitServiceProvider`) throws "attribute [two_factor_secret] either does not exist or was not retrieved" when code reads those columns on a fresh factory instance (e.g. from `ProfileController` after `$this->actingAs(User::factory()->create())`). The factory now writes `two_factor_secret`, `two_factor_recovery_codes` and `two_factor_confirmed_at` as explicit `null`s so the in-memory model has all three attributes without a `->refresh()`.

- **`CreateUserAction` and `UpdateUserAction` now wrap the write + role sync in a transaction.** `User::create(...)` followed by `->syncRoles(...)` was running outside a transaction — if `syncRoles` failed (connection drop, permission cache invalidation, role-not-found race), the user row persisted with no roles, leaving inconsistent state in the admin list. Both actions now run inside `DB::transaction(...)`; the event dispatch happens after the transaction commits so listeners see a consistent state.

- **`MoveItemAction::wouldCreateCycle` no longer issues one SELECT per ancestor.** The method used to walk the folder tree by `FileFolder::find($parentId)` on every hop, so moving a folder with N ancestors produced N queries. For large trees this was both a perf footgun and a potential route for slow-query DoS. The ancestor map is now loaded once per call (single `SELECT id, parent_id WHERE owner_type=? AND owner_id=?`) and the walk happens in memory with a cycle-visited guard.

- **Folder create / rename / move now catch unique-constraint violations.** `CreateFolderAction`, `RenameFolderAction` and `MoveItemAction` check-then-act against `(owner_type, owner_id, parent_id, name)` uniqueness. Two concurrent requests could pass the existence check in lockstep and the second one would surface a raw `QueryException` (500) instead of a validation error. The race window is now closed — each action catches SQL-state `23000` (or MySQL 1062) and rethrows a localised `LogicException`, which the controllers already translate to a 422 with the `sk-file-manager.errors.duplicate_folder` message. The existing pre-check still handles `parent_id=NULL` (where the unique index does not enforce uniqueness on MySQL/SQLite because NULL is treated as distinct).

- **`UserDatatableQuery` now eager-loads `media`.** `UserResource::$appends` forces the `avatar_url` accessor, which calls `$user->getFirstMedia('avatar')`. With the datatable query eager-loading only `roles`, every row triggered a separate media lookup (N+1). `media` is now part of the eager load list; per-page rendering drops from `1 + n` queries to `2`.

- **`RoleController@data` and `@edit` now use a `RoleResource` instead of spreading `$role->toArray()`.** The spread was cheap to add but violated the project's "responses go through a Resource" convention and would silently broadcast any future sensitive column added to the `roles` table. The new `App\Http\Resources\Admin\Role\RoleResource` lists the intended fields explicitly (`id`, `name`, `display_name`, `group`, `sort_order`, `guard_name`, `seeded_permissions`, timestamps, + conditional `permissions` when loaded). Frontend payload shape is preserved.

- **`resources/js/pages/Admin/ApiRoutes/Index.vue`: external link now has `rel="noopener noreferrer"`.** The "Open API Docs" anchor used `target="_blank"` without the usual rel attributes. Consistent with the rest of the project.

- **Missing translations for the 2FA disable confirmation dialog.** `sk-setting.auth.two_factor_disable_title` and `sk-setting.auth.two_factor_disable_warning` were referenced from the Auth settings tab but not defined in either language file. Added for EN and TR.

#### Added

- **Passport key auto-generation for the API test suite.** `tests/Pest.php` now registers a `beforeEach` hook scoped to `tests/Feature/Api` that runs `passport:keys --force` when `storage/oauth-private.key` is missing. Fresh clones and CI runners no longer need `php artisan site:install` before the Passport-backed tests (`AuthTest`, `UserTest`) can pass — the old behaviour was an opaque `LogicException: Invalid key supplied` from `league/oauth2-server`.

- **`tests/Feature/Domain/User/UserEventsTest.php`.** Pins the event-dispatch contract introduced by the fix above — asserts that `UserCreated` fires on create, `UserUpdated` fires only when at least one tracked field changes, `UserDeleted` fires on successful delete, and that the self-deletion guard does not spuriously dispatch.

- **Logo upload/delete coverage in `tests/Feature/Admin/SettingsTest.php`.** Locks the `ApiResponse` envelope on `POST /settings/logo` (200 with `data.logo_url`) and the 204 contract on `DELETE /settings/logo`.

## 2026-04-18 -v.13.3.0

### Feature release — Cloudflare Turnstile, last-login tracking, file preview modals, shipped `validation.php`, and the `sk-*` translation namespace

A large release. Several independent additions plus one architectural shift on the translation layer.

#### Added

- **Cloudflare Turnstile captcha on the auth flows.** Login, register and password-reset forms now host a Turnstile widget (`resources/js/components/Auth/TurnstileWidget.vue`) and validate the token server-side. Ships with: a `turnstile` middleware alias backed by `App\Http\Middleware\ValidateTurnstile`, an `App\Rules\TurnstileRule` for ad-hoc validation, `App\Domain\Setting\DTOs\TurnstileSettingsDTO`, and a **Settings → Turnstile** admin tab to manage site key / secret key from the UI. Turn it on/off per installation; widgets short-circuit cleanly when the feature is disabled.

- **Last login tracking.** A new `App\Listeners\UpdateLastLogin` listener, wired to `Illuminate\Auth\Events\Login`, writes `last_login_at` and `last_login_ip` to the user on every successful sign-in. Visible on the user detail page and exposed as a sortable column on the users datatable.

- **Inactive user block on login.** `App\Providers\FortifyServiceProvider` now rejects the login attempt when the authenticated user's status is not `active`, returning a clear error message instead of starting a session. Suspending an account no longer requires deleting it.

- **`FormBuilder.trans(bool)`.** New fluent method available on every field builder (`FB.inputText()`, `FB.select()`, `FB.toggleSwitch()`, …). Controls whether the label is rendered as a translation key (default, `true`) or as a pre-resolved raw string (`false`). Useful when you want to compose a label from `trans('admin.example')` inside the script itself — normally this breaks because the form template would call `$t()` again on the already-translated text and fall back to the original string. With `.trans(false)` the template skips the second translation step. Default behaviour is unchanged; existing pages continue to work without any edit.

    ```ts
    FB.inputText().key('last_name'); // default — label → $t('validation.attributes.last_name')
    FB.inputText().key('x').label(trans('admin.example')).trans(false); // raw render, no second $t() pass
    ```

- **In-app file previews (lightbox + modal).** Uploaded files — in the file manager and in any `FB.fileUpload()` form field — no longer open in a new browser tab when you click the thumbnail or file-name. Images fly up in a **fullscreen lightbox** (Google-Drive style: blurred black backdrop, ESC to dismiss, name in the top-left). Non-image files (PDF, video, audio, text) open inside a **mime-aware dialog** that embeds the correct viewer (iframe / `<video>` / `<audio>`) and offers a "Download" button in the file manager and an "Open in new tab" escape hatch for unrecognised formats. The lightbox is a single global overlay registered next to `<AppDialog />` in `AdminLayout`; the modal is a `FilePreviewModal` component opened through the existing `useDialog` composable.

- **Categorized mime-type picker in File Manager settings.** **Settings → File Manager → Accepted file types** used to be a long multiselect dropdown. It is now a categorized card-checkbox grid (Images / Documents / Archive) where each option shows the matching file-type icon next to the label. Easier to scan, click target is the whole card, and the list is grouped rather than alphabetical.

- **Feature-toggle cards for "Video uploads" and "Audio uploads".** The two toggles in File Manager settings share the same card aesthetic as the mime picker — a tinted icon on the left, a bold label and a short description (e.g. "Allow MP4, WebM, MOV, MKV, AVI and OGG videos.") next to the switch on the right. Clicking anywhere on the card flips the toggle.

- **`lang/{en,tr}/validation.php` are now shipped with the kit.** Laravel's default rule messages plus the `attributes` and `custom` sections used by both the Laravel validator and by FormBuilder / DatatableBuilder, which auto-resolve a field's label via `validation.attributes.{key}` when `.label()` is not specified. Turkish messages follow the Laravel-Lang/lang conventions. Consumer apps can edit these files freely to adjust wording or add new attribute labels — no custom translation loader is involved; everything runs through Laravel's native translation system.

- **Role name localisation with a graceful fallback chain.** The role label shown in the admin topbar / sidebar (shared via Inertia `auth.role`) now resolves in three steps: first `roles.display_name[locale]` from the database; then the locale key under `config('permission-resources.display_names.roles.{name}.{locale}')`; and finally `Str::headline($role->name)` — so a freshly seeded role like `system_admin` shows as "System Admin" instead of the raw slug, even when nothing localised has been configured.

#### Changed — translations moved to the `sk-*` namespace

Every shipped translation file now has an `sk-` filename prefix: `sk-admin.php`, `sk-auth.php`, `sk-button.php`, `sk-datatable.php`, `sk-menu.php`, `sk-setting.php`, `sk-user.php`, `sk-attribute.php`, `sk-file-manager.php`, `sk-activity-log.php`, … All shipped Vue pages and PHP code now reference the new keys (`__('sk-button.save')` instead of `__('button.save')`). The goal: consumer apps are free to own the unprefixed namespace (`lang/en/admin.php` for their own dashboard strings, not a collision with the starter kit's menu items).

#### Removed

The pre-13.3 unprefixed stubs — `stubs/lang/{en,tr}/{admin,auth,button,common,datatable,enums,file-manager,message,pagination,passwords,validation}.php` (21 files) — are no longer shipped. No code path in the kit references them after the `sk-*` migration; keeping them in fresh installs only caused confusion. The **package-level `starter-kit::` namespace is untouched** — `__('starter-kit::admin.menu')` calls still resolve.

#### Fixed

- **Upload validation rejected `.ogg` video and `.avi` files even with "Video uploads" enabled.** The `allow_video=true` branch of the upload request only whitelisted `video/mp4`, `video/webm`, `video/quicktime` and `video/x-matroska`. Added `video/ogg`, `video/x-msvideo` and `video/avi`, and added the matching extension labels (`.OGV`, `.AVI`) to the "Allowed types" list shown in validation error messages.

- **Spurious `npm run build` warnings silenced.** Two noisy warnings have been scrubbed from production builds: (1) the "Sourcemap is likely to be incorrect" notices emitted by `@tailwindcss/vite` and `@inertiajs/vite` — both plugins skip sourcemap regeneration after their transform, the runtime is unaffected — are now filtered via a targeted Rollup `onwarn` hook in `vite.config.ts` (other warnings still pass through); (2) the `resolveDirective imported but never used` warning from the shipped `SkDatatable.vue` and `FileManager.vue` — PrimeVue's `v-tooltip` / `v-ripple` directives are now bound explicitly in the `<script setup>` block (`const vTooltip = Tooltip`) so the template compiles to a direct reference instead of a dynamic lookup.

#### Upgrading from 13.2.x

`sk:update` is hash-aware: files you have not modified are replaced with the new version; files you have modified are reported as `skipped` or `untracked` and left alone. Several 13.3 feature files — `SettingsController`, `SettingsDefaultsQuery`, `FortifyServiceProvider`, `HandleInertiaRequests`, `AppServiceProvider`, and the new FormRequest classes — will likely show up in that list and need attention.

1. Run `php artisan sk:update --dry-run` to see what is skipped/untracked.
2. If you have no local customisations in the `app/` layer, take the package version for everything:

    ```bash
    php artisan sk:update --force
    ```

3. Pull the new translation files manually (`sk:update` does not touch `lang/`):

    ```bash
    cp vendor/lvntr/laravel-starter-kit/stubs/lang/en/sk-*.php lang/en/
    cp vendor/lvntr/laravel-starter-kit/stubs/lang/tr/sk-*.php lang/tr/
    ```

4. If your `lang/en/` still contains `admin.php`, `auth.php`, … from a prior `sk:install`, they now linger as orphans. The package no longer references them; delete them after migrating your `__('admin.x')` calls to `__('sk-admin.x')`.
5. `npm run build` — the new `TurnstileWidget.vue` is shipped and imported by `Login/Register/ForgotPassword`. Fresh installs get it automatically. Existing installs missing the file will see the build fail with `Could not load resources/js/components/Auth/TurnstileWidget.vue`; `sk:update` should copy it (it is a new file, not a replacement), but if not, copy it from `vendor/lvntr/laravel-starter-kit/stubs/resources/js/components/Auth/TurnstileWidget.vue`.

---

## 2026-04-16 -v.13.2.9

### `npm run build` — lang JSON dual-import warning eliminated

Consumer projects were emitting two warnings on every `npm run build`:

```
(!) lang/php_en.json is dynamically imported by resources/js/app.ts but also statically imported by resources/js/app.ts, dynamic import will not move module into another chunk.
(!) lang/php_tr.json is dynamically imported ...
```

Cause: the `i18nVue` resolve callback in `resources/js/app.ts` held two separate `import.meta.glob('../../lang/*.json', ...)` calls for SSR and client — one with `eager: true` (static) and one without (dynamic). Vite analysed both branches statically, saw the same files imported in both static and dynamic form, and warned that the dynamic branch would not get its own chunk. The dynamic branch never produced any benefit because the files were already in the static bundle.

Collapsed to a single eager glob hoisted to the module scope, with a `Promise.resolve()` wrapper for the client branch:

```ts
const langs = import.meta.glob<Record<string, string>>('../../lang/*.json', { eager: true });
const resolveLang = (lang: string) => langs[`../../lang/php_${lang}.json`];
app.use(i18nVue, {
    resolve: ssr ? resolveLang : (lang: string) => Promise.resolve(resolveLang(lang)),
});
```

Lang JSON files are small (a few KB), so static bundling has negligible bundle-size impact — the warning is gone permanently while behaviour is unchanged.

---

## 2026-04-16 -v.13.2.8

### Cleaner fresh installs

Fresh installs no longer include leftover development-only files or noisy placeholder data.

- **`.env.example` cleanup** — duplicate `DB_*` entries and an old sample database name were removed. The file now keeps only generic placeholders such as `your_database` and `your_username`.
- **Frontend/install cleanup** — unnecessary development-only frontend tooling entries were removed so `npm install` starts from a cleaner baseline.
- **Less clutter in new projects** — stray assistant/tooling files that did not belong in a fresh application are no longer shipped.

---

## 2026-04-15 -v.13.2.7

### File manager upload — `crypto.randomUUID` fallback for HTTP contexts

The file manager upload composable generated a temporary id per queued file via `crypto.randomUUID()`. That API is only defined in a secure context — HTTPS or `localhost` — so any consumer running on a plain-HTTP dev domain (Herd's `.test`, a bare intranet IP, etc.) hit `TypeError: crypto.randomUUID is not a function` and the upload aborted before the first XHR fired.

`useFileManager` now routes through a local `generateTempId()` helper with a three-tier fallback:

1. `crypto.randomUUID()` when available (HTTPS / localhost)
2. `crypto.getRandomValues(new Uint8Array(16))` serialized as hex (available in every modern browser, no secure-context requirement)
3. `Date.now().toString(16)` + `Math.random().toString(16)` as last-resort

The tempId is only used to correlate a pending-upload row with its completion/error callback — no cryptographic strength needed, so the fallback is safe.

### Security headers — geolocation permitted from own origin

`SecurityHeaders` middleware `Permissions-Policy` was `geolocation=()` (fully denied). Changed to `geolocation=(self)` so first-party scripts can request geolocation when a feature legitimately needs it; third-party frames remain blocked.

---

## 2026-04-15 -v.13.2.6

### File manager validation messages — readable, localised, with original filename

Server-side rejection toasts now actually surface in the file manager UI and carry a friendly message instead of Laravel's raw `files.0 field must be a file of type: image/webp` text.

- **Toast group fix** — every `toast.add()` call in `FileManager.vue` now passes `group: 'bc'`. The shared `ToastComponent` is mounted with `group="bc"`, so previous calls without the key were silently dropped. Folder create/rename/delete/move and file upload toasts (success and error) all surface again.
- **Server error extraction** — the upload XHR previously read only `envelope.message` ("Validation error.") on a 422. The composable now walks `envelope.errors` and surfaces the first field-specific message, so the toast carries the actual reason.
- **Per-file friendly validation messages** — `UploadFileRequest` overrides `attributes()` and `messages()`. Each `files.{i}` slot is bound to the file's `getClientOriginalName()` (so the toast says `vacation.jpg yüklenemedi: …` instead of `files.0`). The mimetypes / max-size errors map to translation keys with a readable extension list (`İzinli tipler: WEBP, PDF, JPG, …`) and human-friendly size limit (`en fazla 10 MB`).
- **Translation keys** — `errors.upload_invalid_type`, `errors.upload_too_large`, `errors.upload_invalid_file` added in `lang/{en,tr}/file-manager.php`.

Two new feature tests cover the friendly messages: `it returns a friendly validation message with original filename when mime is rejected` and `… with size limit when file is too large`. The full file manager + install + publish suites stay green (22/22 + 11/11).

### Helpers reorganized — vendor-owned core, user-owned custom, publishable override

`to_api()` and `format_date()` (plus two new helpers — see below) now ship from the package vendor and are autoloaded automatically. End-user apps no longer keep a `to_api` copy under `app/`, removing the merge headache that used to come up every `sk:update`.

- **`vendor/lvntr/laravel-starter-kit/src/sk-helpers.php`** is the canonical location. It is registered via the package's `composer.json` `autoload.files`, so any consumer gets the helpers the moment they `composer require`.
- **`app/Helpers/custom.php`** is published into the consumer app on first install, added to the app's `composer.json` `autoload.files`, and is _never_ overwritten by `sk:update`. This is where user-specific global helpers live.
- **`app/helpers.php` is deprecated.** `sk:update` now compares the existing file's md5 against a list of known stock hashes; if it matches, the file is removed silently. If the user added their own functions, the file is left in place with a console warning so their code is preserved. The `composer.json` autoload entry is rewritten only when the file is actually gone — never silently breaking user code.
- **Two new helpers** — `definition($key, $value)` returns the matching definition record (object) from `DefinitionService`; `definitionLabel($key, $value)` returns its `label`. Useful for resolving enum-style values to display strings without re-fetching the definition list per call.

### `sk:publish --tag=helpers` — override the bundled helpers without forking

A new tag exposes `sk-helpers.php` to the publish command. After publishing, the file lands at `app/Helpers/sk-helpers.php` and the user can edit it freely.

The vendor file detects the published copy at autoload time and routes through it via `require_once`:

```php
$skPublishedHelpers = dirname(__DIR__, 4).'/app/Helpers/sk-helpers.php';
if (is_file($skPublishedHelpers) && realpath($skPublishedHelpers) !== realpath(__FILE__)) {
    require_once $skPublishedHelpers;

    return;
}
```

The realpath guard prevents self-recursion when the file is loaded as the published copy. No `composer.json` change is needed — composer autoload still triggers vendor's file, which then delegates to the user's. Deleting the published file reverts to the vendor implementation immediately.

The `sk:publish` interactive prompt gained a fourth option: **Global Helpers (sk-helpers.php)**.

---

## 2026-04-14 -v.13.2.4

### Type-safety sweep — zero `vue-tsc` and ESLint warnings

The starter kit source now passes `vue-tsc --noEmit` and `eslint 'resources/js/**/*.{ts,vue}'` with 0 errors / 0 warnings. No behavioural changes — purely type and lint cleanup.

- **tsconfig deduplication** — type-checking paths were simplified so the same UI sources are no longer scanned twice. This removes the duplicate errors that were confusing local development.
- **Vite `Components` plugin is single-source** — the `dirs` entry was trimmed to `resources/js/components` only; the package path is gone. The auto-generated `components.d.ts` now references source paths.
- **SkDatatable filter types widened** — `activeFilters` is now typed as a single `FilterValue` alias (`string | number | Date | (Date | null)[] | null`). DatePicker usages switched from `v-model` to `:model-value` + `@update:model-value` with narrow casts, so `select`, `select-button`, `date` and `daterange` filters each operate on their own typed value.
- **Tag icon / pagination i18n fixes** — the `:icon` expression closes off null leakage with `?? undefined`, and the `from/to/total` params passed to `datatable.records_info` are now `String(... ?? 0)` to match the expected `string` i18n argument type.
- **`SharedPageProps` index signature** — `[key: string]: unknown` added so the interface satisfies Inertia's `PageProps` constraint. `useCan()` now compiles cleanly under `usePage<SharedPageProps>()`.
- **`env.d.ts` auth shape aligned with runtime** — Inertia `sharedPageProps.auth` now carries `{ user, role, role_names, permissions }`; AdminHeader's `page.props.auth?.role` and similar reads resolve against correct types. `appEnv`, `appDebug`, `locale`, `availableLocales` are also typed on the shared props.
- **Small prop / cast fixes** — `RoleForm.vue` calls Wayfinder as `update.url({ id })` (narrowing the optional `id`), `Settings/Index.vue` adds `logo_url: string | null` to the `general` type, `Dashboard/Index.vue` greets by the real field (`user?.first_name` instead of a non-existent `user?.name`), and the redundant `preserveScroll: true` option was dropped from `router.reload()` calls — Inertia v3 already preserves scroll and state on `reload()` by default.
- **ESLint warnings** — the `v-html` usage inside `SkDatatable` is marked with a reasoned `eslint-disable-next-line` (the render string is author-defined and `escapeHtml` is provided). `Breadcrumb.rootLabel`, `FileGrid.emptyLabel` and `SkTag.{value,icon,color,severity}` now have `withDefaults` fallbacks.

No action required for existing installs — changes are purely type/lint level.

## 2026-04-14 -v.13.2.3

### Installer DX — AST-based injection, bootstrap helper, preset-aware guidance

A round of installer and upgrade ergonomics that make `composer require lvntr/laravel-starter-kit` on a fresh Laravel safer and less invasive.

- **AST-based config injection** — `sk:install` now edits `config/app.php`, `config/filesystems.php` and `config/media-library.php` via `nikic/php-parser` with format-preserving pretty printing. Regex-based patching is gone; the injection is tolerant of different Laravel config formats and fully idempotent (re-running `sk:install` is a no-op once injected).
- **Bootstrap helper cleanup** — middleware and exception wiring now flows through a single shared bootstrap helper, making installation updates more predictable.
- **`bootstrap/app.php` is no longer overwritten** — the stub copy is removed. Instead the installer **AST-injects three lines** into the user's existing Laravel default file: `api: __DIR__.'/../routes/api.php'` inside `withRouting(...)`, plus `Bootstrap::middleware(...)` / `Bootstrap::exceptions(...)` calls inside the two closures. User-added middleware, trusted proxies, custom exception reporters etc. are preserved.
- **`bootstrap/providers.php` is no longer overwritten** — the installer appends `DomainServiceProvider`, `FortifyServiceProvider`, `SettingsServiceProvider` to the array (idempotent, skips any already registered), leaving the user's existing entries untouched.
- **`package.json` JSON-merge** — instead of blind overwrite, the installer merges: stub versions win for shared dependencies, but any user-added deps, scripts, workspaces or root-level keys survive.
- **First-install detection for lang files** — `lang/*` is still preserved on re-install (so customisations aren't lost), but on a genuine first install (no hash registry) the installer now force-copies lang stubs so fresh projects don't inherit sparse Laravel defaults while the starter kit UI expects richer keys.
- **Dead code removed** — old `IdentityType` and `YesNo` enums that are no longer part of the active flow were removed from fresh installs and update paths.
- **IdeHelper cleanup** — `AppServiceProvider` no longer carries an unnecessary `class_exists(IdeHelperServiceProvider::class)` guard in fresh installs.
- **Explicit `nikic/php-parser ^5.0` requirement** — was transitively available via Tinker, now a direct package dep.
- **"Bare Laravel" install guidance** — README (EN/TR) and [install.md](./install.md) / [install.tr.md](./install.tr.md) open with a warning: do **not** run `install:inertia`, `install:api`, Breeze, Jetstream or similar presets before installing the starter kit — they scaffold controllers, routes, pages and layouts that this kit also ships, and the installer cannot detect them, leaving orphan dead code.
- **Tests** — 12 new `InstallCommandTest` cases cover AST config injection (all three files), idempotency, format/comment preservation, `package.json` merge, first-install detection, bootstrap app/providers AST injection with user-code preservation. Total installer-related suite 20/20 green.

No action required for existing installs — all installer-side changes are backwards-compatible and gated on first-install detection or idempotency guards.

## 2026-04-14 -v.13.2.2

### FileManager — pluggable contexts via `ContextRegistry`

The FileManager is no longer limited to `user` / `global`. Any Eloquent model can own a folder tree with **zero service-provider wiring**.

- **New `ContextRegistry` service** (`app/Domain/FileManager/Support/`) resolves a context key in three steps: explicit `register()` → Laravel morph-map alias → `App\Models\{Studly(key)}` convention fallback. Unknown keys still return 422 via validation.
- **Zero-config custom contexts** — a model class + a matching policy (`view` / `update`) is all that's needed:
    ```vue
    <FileManager context="vehicle" :context-id="vehicle.id" height="100%" />
    ```
- **`global` baked into the registry** — the old registration in `AppServiceProvider::boot()` moved into `ContextRegistry`'s constructor, so adopting the starter kit no longer requires any boot-time setup for FM. `AppServiceProvider` only binds the singleton now.
- **`user` is fully auto-resolved** — via the `App\Models\User` convention plus the new shipped `app/Policies/UserPolicy.php` (self-access + `users.read` / `users.update` admin gate). The built-in registrations for `user` were removed in favour of policy-driven auth.
- **Default authorizer with self-match short-circuit** — auto-resolved contexts automatically allow an actor to manage their own record (actor IS owner). Other requests delegate to Laravel policies: `can('view', $owner)` for reads, `can('update', $owner)` for writes.
- **MorphMap-aware storage** — `FileManagerContextDTO` now stores `ownerType` via `$owner->getMorphClass()` so queries and path generation work even when the model has a morph-map alias.
- **Runtime-driven validation** — `FileManagerRequest` replaced the hard-coded `in:user,global` rule with a closure that queries `ContextRegistry` at runtime. Adding a new context no longer touches any Request file; `context_id` is only required when the registered path contains `{id}`.
- **Frontend type loosens for custom keys** — `FileManagerContext` is now `'user' | 'global' | (string & {})`, so calling `<FileManager context="vehicle" />` stays fully type-checked without losing autocomplete on the built-ins.
- **Upload resilience** — `UploadFileRequest` now falls back to a sensible MIME list (image / pdf / office / text) when `file_manager.accepted_mimes` isn't seeded yet, so a fresh install never hits the "file must be of type: ." 422.
- **Tests** — new `CustomContextTest` exercises explicit registration, path override, folder listings, unknown-context rejection and morph-map auto-resolution. Total 26/26 FileManager tests green.
- **Docs** — [file-manager.md](./file-manager.md) picked up a "Custom contexts" chapter with resolution order, zero-config walkthrough, `VehiclePolicy` example, contract table and override guidance.

## 2026-04-14 -v.13.2.1

### FileManager — UX polish & follow-ups

A batch of refinements landed on top of the initial 13.2.0 release, driven by real usage:

- **Preview modal** — tile click (for files) or context **Open** now opens a 90vw modal with inline preview for images, PDF, video, audio and text; non-previewable types fall back to an "Open in new tab" + "Download" pair.
- **Per-tile upload progress** — uploads now stream per-file via XHR with a progress bar drawn on an optimistic placeholder tile; failed uploads show a dismissable error tile while successful ones slot in when the list refreshes. The toolbar Upload button also spins during the batch.
- **Drag-and-drop move** — tiles are `draggable`; dropping onto a folder tile moves the whole selection there. External file-drag is detected via the `Files` data-transfer type, so internal drags no longer accidentally trigger the upload overlay.
- **Move modal with folder tree** — new Move action in both folder & file context menus opens a dialog with a `FolderTree` picker. Works for both single and bulk selections.
- **Busy overlay (modal card)** — Delete / Move / Rename operations now paint a white modal card over the FileManager area with a spinner, title, description and — for bulk ops — a live "N items remaining" counter plus a **Stop** button that cancels the remaining iterations.
- **Always-visible selection checkbox** — each folder / file tile has a top-right checkbox (primary-filled when selected, outline-on-hover when not). Plain click on folders just selects; **double-click opens**. File tiles still single-click-to-preview. The old 3-dot menus on tiles are gone — right-click is the single entry point.
- **Right-click no longer forces selection** — opening the context menu on an unselected tile keeps the existing selection untouched; bulk operations apply only when the right-clicked item is already in the selection.
- **Keyboard shortcuts** — `Ctrl/Cmd + A` selects all items in the current folder, `Delete` / `Backspace` deletes the selection (with confirmation), `Esc` clears the selection. All shortcuts are guarded so they never fire while typing in an input or with a dialog open.
- **Breadcrumb redesign** — replaced the PrimeVue breadcrumb with chip/pill crumbs, separated by chevrons, and moved below the info bar. Long folder names are truncated with `…` (configurable `maxChars`, default 18). Full path stays accessible as a `title` tooltip.
- **Current-folder header + back button** — removed the left folder tree (still used inside the Move picker). The main area now shows the current folder name with an icon, plus a `←` button when not at the root.
- **Empty-folder illustration** — empty folders render a large outlined folder SVG, a heading, and two-line hints (`Upload` / `New Folder`), replacing the plain "This folder is empty" line.
- **Aggregate info-bar stats** — the file count + total size shown in the info bar now walks the entire subtree of the current folder, not just its immediate files.
- **Download across disks** — `DownloadFileAction` now uses `Storage::disk($media->disk)->download(...)` so the force-download route works for local, S3 and DigitalOcean Spaces alike.
- **Context menu restyle** — white rounded card, larger item padding, separator before destructive **Delete** in both folder and file menus.
- **Sort direction tooltip** — the asc/desc toggle now has a dynamic PrimeVue tooltip ("Ascending · click for descending" / TR equivalent). As a side effect, the `Tooltip` directive is now globally registered in `app.ts`.
- **Footer credit** — `AdminFooter` shows _Crafted with **Lvntr Starter Kit**_ linking to lvntr.dev.

See [file-manager.md](./file-manager.md) for the refreshed usage guide, props and composable exports.

## 2026-04-14 -v.13.2.0

### FileManager — file management module

A new **FileManager** module shipped: a Windows Explorer-style UI delivering full file management for user-scoped or global files.

- **Nested folders** — create, rename, move, cascade delete
- **Multi-file upload** — drag & drop or button
- **Selection** — single click, `Ctrl/Cmd + click`, rubber-band drag for bulk
- **Bulk delete** — toolbar button or right-click on selected items
- **Sort** — by name / size / date + asc/desc
- **Type-aware previews** — image thumbnails + color-coded icons for PDF/Word/Excel/Video/Audio/Archive
- **Info bar** — current folder file count and aggregate size
- **Context menus** — separate actions for folder / file / empty area (New Folder, Upload, Select All, Refresh)

Added pages: **Files** in the main sidebar, **Files** tab on `Admin > Users > Edit`. Max upload size, accepted MIME types and video/audio toggles are configurable under `Admin > Settings > File Manager`.

Storage: `user/{id}/files/{uuid}/...` and `global/files/{uuid}/...` — folder moves are metadata-only, files never move on disk.

See [file-manager.md](./file-manager.md) for usage and API details.

## 2026-04-13 -v.13.1.10

### FormBuilder — stale form reset fix

Fixed a bug where `FB`-generated forms could silently reset to stale remote data after an Inertia `back()` navigation or any `page.props` refresh that caused `formConfig` to be recomputed. The internal `SkForm` now shallow-compares the new derived defaults against the previous ones and skips the reset when the values are identical, preserving in-progress user edits.

Affected: any form built with `FB` whose config depends on `page.props` (e.g. conditional `isFieldsLocked`, `isSelf`, auth-based field visibility). No API change — existing forms benefit automatically.

## 2026-04-13 -v.13.1.8

### FormBuilder — ColorSelector output format

`FB.colorSelector()` now supports configurable output formats via `.format()` and `.defaultTone()`:

- `format('name')` _(default)_ → stores `"blue"`
- `format('name-tone')` → stores `"blue-500"`
- `format('hex')` → stores `"#3b82f6"`

A clickable tone selector is rendered below the dropdown for `'name-tone'` and `'hex'` formats, with the resolved value shown next to the tone pills. When the initial model value is a hex string, the component reverse-looks it up against the Tailwind palette to restore the matching color + tone.

See [formbuilder.md](./formbuilder.md#colorselector-field-api) for details.
