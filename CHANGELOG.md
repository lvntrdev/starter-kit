# Changelog

All notable changes to `lvntr/laravel-starter-kit` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **A dedicated `DATA_ENCRYPTION_KEY`, independent of `APP_KEY`, now protects sensitive settings values (`mail.password`, `storage.spaces_secret`, `storage.aws_secret`, `turnstile.secret_key`, `postman.api_key`, `apidog.access_token`) and 2FA secrets/recovery codes.** Previously all of this data was encrypted with `APP_KEY`, so a routine `php artisan key:generate` on a server migration made it silently unrecoverable — `SettingService` swallowed the resulting `DecryptException` and returned `null` instead of erroring. Three new commands manage the new key: `encryption:key` generates it and preserves the old key in `DATA_ENCRYPTION_PREVIOUS_KEYS`; `encryption:rekey` re-encrypts existing rows onto the new primary key without ever touching a row it cannot decrypt; `encryption:health` reports whether the previous-key list is safe to clear, and `php artisan sk:doctor` gained a matching `Data Encryption Key` check. Adoption is opt-in — an install that runs none of this keeps working exactly as before, byte-for-byte, and a fresh `sk:install` now generates the key automatically. See [docs/encryption.md](docs/encryption.md) and [docs/server-migration-runbook.md](docs/server-migration-runbook.md).
- **`SkForm` gained a `reload()` method and a `.reloadOnDataUrlChange()` builder flag.** `reload()` (exposed via `defineExpose`) lets a host re-fetch `dataUrl` on demand — a "Refresh" button, a sibling save event — without remounting the form. `.reloadOnDataUrlChange(true)` opts a form into refetching automatically whenever its `dataUrl` prop changes after mount (e.g. a dialog reused for a different record id); the default stays mount-only so a form whose config is rebuilt on every parent render doesn't refetch on every rebuild. See [FormBuilder API](docs/formbuilder.md#form-builder-api).
- **`FB.fileUpload()` gained `.deferExistingRemoval()` and drag-and-drop.** `.deferExistingRemoval(true)` changes removing an already-saved file from an immediate `DELETE /media/{id}` to a deferred one: the item only leaves the field's keep-list, and deletion happens on save via `Lvntr\StarterKit\Traits\HasMediaCollections::syncMediaCollection()`. The upload field's drop zone now also accepts files dragged onto it, going through the same `accept`/`maxFileSize`/`fileLimit` validation as the picker button. See [File Upload Field API](docs/formbuilder.md#file-upload-field-api).
- **The `@lvntr/components` package library is now linted in CI** via a new `lint:lib` root script (`eslint --config stubs/eslint.config.js resources/js/components/Lvntr-Starter-Kit`), so a lint regression in the shared component library is caught the same way stub-side lint already is.
- **`TabIconColor` and `TabBadgeSeverity` are now exported from the TabBuilder core barrel** (`@lvntr/components/TabBuilder/core`), matching the already-exported `TabBuilderConfig`, `TabItemConfig`, and `TabLayout` — a consumer typing against a tab's icon color or badge severity no longer needs to reach into the internal `./types` module directly.
- **`TB.tabs()` gained five chainable options for panel mounting and URL behavior: `.lazy()`, `.keepAlive()`, `.history('push' | 'replace')`, `.urlMode('server' | 'client')`, and `.syncUrl(boolean)`.** `.lazy()` mounts only the active panel (PrimeVue's own lazy mode on horizontal layout) while `.keepAlive()` keeps every panel mounted and hides inactive ones, preserving per-tab state; `.history()` controls whether a switch replaces the current history entry (default) or pushes a new one; `.urlMode('client')` rewrites the URL with no server request instead of the default Inertia visit; `.syncUrl(false)` drops URL sync entirely. See [docs/tabs.md](docs/tabs.md).
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

### Fixed

- **A setting that cannot be decrypted is no longer silently indistinguishable from an unset one.** `SettingService` caught every `Exception` while decrypting and returned `null`, which `allGrouped()` then cached for an hour — so a wrong key, a corrupted payload or a misconfigured cipher quietly fell back to the env/default value on mail, storage and Turnstile. Only `DecryptException` is handled now (still `null`, but logged without the ciphertext); anything else propagates.
- **The settings cache is cleared after the outer transaction commits, not during it.** `setValue()` / `setGroup()` called `Cache::forget('settings')` inline, so a write wrapped in an outer transaction (`UpdateAuthSettingsAction`) dropped the snapshot while the rows were still uncommitted — a concurrent reader could miss, re-read the pre-write rows and cache them for another hour. The clear now runs through `DB::afterCommit()`, which still fires immediately when no transaction is open.
- **Logo, favicon and avatar uploads store the new file before dropping the old one.** All three deleted the existing asset first, so a failed `store()` left the setting pointing at a file that no longer existed. A failed upload now leaves the current image in place and returns an error instead.
- **A media object is removed from disk only once its row's deletion has committed.** Spatie's `MediaObserver::deleted()` removes the file inside the transaction that deleted the row, so a rollback restored a row pointing at a file that was already gone. The removal now goes through `DB::afterCommit()`: it is discarded on a rollback, and the worst remaining outcome is an orphaned file, which is recoverable. A non-transactional delete keeps today's timing and still surfaces its failure.
- **Restoring a folder from trash no longer creates a duplicate name.** `CreateFolderAction` rejects a duplicate, but the trash was a way around it at the root level, where MySQL and SQLite treat two NULL `parent_id` values as distinct and the unique index does not fire. The restore now refuses with the same domain error.
- **The FileManager quota calculation works on a bare Spatie `Media` model again.** `computeStorageUsed()` called `withTrashed()` unconditionally; without the SoftDeletes trait that macro does not exist, so every upload validation threw `BadMethodCallException`. It goes through the capability-aware helper the rest of the trait already used.
- **`file-manager:purge-trash` no longer loads the whole trash into memory, and reports failures.** The command read every matching row with `get()` before deleting, takes a cache lock so two schedulers cannot purge the same rows at once, walks the rows with `chunkById` (`--chunk=`, default 500), keeps going when one item fails, and returns a non-zero exit code when anything was left behind. The published schedule entry gained `withoutOverlapping()`.
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

## [13.6.16] - 2026-08-25

### Fixed

- **A datatable no longer renders empty because a neighbouring table's sort was left in the page URL.** `sort` is a page-global query parameter, so on a page hosting several tables (tabs, side-by-side panels) the table that mounted second read the first one's `sort` out of the URL and asked its own endpoint for a column that endpoint never allowed — `Spatie\QueryBuilder` answers with HTTP 400 (`InvalidSortQuery`), so the table came up blank. A bookmarked link failed the same way once a column had been renamed or dropped. `SkDatatable` now validates a restored sort key against its own columns before using it — the id column included, since it is sortable without appearing in `columns`, and this route's persisted column order too, so a column only the server publishes (a hidden `updated_at`, say) still restores its sort once the user has enabled it. A URL carrying a foreign sort is treated as another table's URL and is ignored **whole** — `page`, `per_page` and the filters with it, because reading half of it would only open this table on the neighbour's page number. A stale key coming back from the per-route session blob is dropped the same way, while a sort the table does own is still restored from both sources.

## [13.6.15] - 2026-08-24

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

- **A new project installs fail-closed on unresolved routes; an existing one is untouched.** `sk:install` writes `STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false` into the `.env` it creates. A fresh app has no legacy route to grandfather in, so it starts strict and its first ungated route surfaces during development rather than in production. Nothing carries that value into an app that already exists: `ensureEnvFile()` copies `.env.example` wholesale **only on a first install**, and the re-install path now skips a small `FIRST_INSTALL_ONLY_ENV_KEYS` list, so re-running `sk:install` on an installed app does not add the key either. `sk:update` and `sk:upgrade` never touch `.env` at all. **There is no release in which an existing installation starts denying on its own** — the `allow_unresolved` default stays `true` for anything that does not set the key, and an existing app opts in by writing the line itself once `sk:doctor --only=unresolved-routes` is clean. See the [upgrade guide](docs/UPGRADE.md#unresolved-route-fail-closed-is-opt-in-for-an-existing-install) for the ordered remediation path.

## [13.6.14] - 2026-08-15

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

- **FileManager context authorization now uses `read`, `create`, `update`, and `delete` instead of collapsing mutations into `write`.** The built-in `global` context maps these one-to-one to `files.read`, `files.create`, `files.update`, and `files.delete`; unknown abilities fail closed. A role that previously held only `files.create` loses delete and empty-trash access, while a role that held only `files.update` loses read access. Grant the specific `files.*` abilities required by each role, then run `php artisan sk:seed-permissions`. Consumer context closures must handle the four new names and will never receive `write`; see the [upgrade guide](docs/UPGRADE.md). `authorizeWrite()` remains as a deprecated alias to `authorizeUpdate()` for direct callers.
- **Disabled two-factor authentication now removes Fortify's 2FA routes.** They return 404 instead of remaining registered. The 2FA management endpoints also now carry `password.confirm` because `fortify-options.two-factor-authentication` is set before route registration; direct API consumers must complete that confirmation round-trip.

## [13.6.13] - 2026-08-15

### Changed

- **The license changed from PolyForm Noncommercial 1.0.0 to MIT.** Commercial use is now permitted without restriction: the kit may be used, modified, and redistributed in closed-source and paid products, subject only to retaining the copyright and permission notice. The SPDX identifier in `composer.json` and `package.json` is now `MIT`, and `LICENSE` carries the MIT text.
- **Behavior change: API Resource dates now emit ISO-8601 with an offset instead of preformatted display strings.** Resources use `to_api_date()` so the frontend can format one parseable instant consistently in the resolved user/site timezone. The existing `format_date()` helper is unchanged and remains backward compatible for Blade, mail, export, and other presentation callers.
- **Behavior change: storage and display timezones are now separate.** `APP_TIMEZONE` must remain `UTC`; `config('app.display_timezone')` now reads `APP_DISPLAY_TIMEZONE` instead of `APP_TIMEZONE`. Existing installs must add the new env variable and run `php artisan sk:upgrade`, whose idempotent AST step rewrites the legacy `config/app.php` entry. `sk:doctor --only=timezone-storage` fails when storage is not UTC.
- **MySQL/MariaDB connection sessions are now pinned to UTC.** `sk:install` and `sk:upgrade` add literal `'timezone' => '+00:00'` entries to existing `mysql`/`mariadb` arrays in `config/database.php` without overwriting consumer values or touching other drivers. Existing installations may already carry offset application-written `TIMESTAMP` data; that data remains offset until the one-time conversion in `docs/timezone.md` is completed. `DEFAULT CURRENT_TIMESTAMP` columns move in the opposite direction and must be excluded. `sk:upgrade` warns and asks before changing a non-UTC session with data (unattended runs without `--force` skip), but never converts rows. `sk:doctor --only=timezone-storage` now detects a non-UTC MySQL/MariaDB session, including `SYSTEM`, and warns rather than passing when the session cannot be read.
- **Per-user display timezones and timezone-aware date filters were added.** A nullable `users.timezone` preference resolves through user → General site setting → app timezone → UTC; `null` follows later site-setting changes while explicit `'UTC'` does not. Profile/admin user forms expose the selector, Inertia shares the resolved timezone, frontend date utilities format with an explicit timezone, and datatable calendar dates become half-open, index-friendly UTC ranges with DST-correct boundaries.

## [13.6.12] - 2026-07-25

### Added

- **AI skills are now published for Codex too.** `sk:install` copies the kit's three AI skills to `.claude/skills/` (Claude Code) and mirrors them to `.codex/skills/`, which the OpenAI Codex CLI reads natively as project-level skills. The `.codex` copies are a generated mirror of the published `.claude` tree (consumer customizations included): install/update regenerate only the kit-owned skill directories and never touch anything else under `.codex/skills/`; a kit skill dir whose `.claude` source is absent is left alone. `sk:install --without-ai-skill` skips both trees (and keeps suppressing the mirror on later updates via the `__skipped__` sentinel), and a new `sk:update --without-ai-skill` flag skips regenerating the mirror on a single run. The mirror is best-effort — a failure warns instead of failing an otherwise completed install/update.

### Changed

- **The three shipped AI skills (`stubs/.claude/skills/`) were refreshed from v13.5.11-era content to the current kit.** Now covered: the vendor-first architecture with `class_alias`/local-first override semantics, `sk:eject` (14 ejectable domains) and the install-time User/Role eject, `sk:doctor`, `sk:install --resume/--without-eject`, `file-manager:purge-trash`, the full 12-tag `sk:publish` list (adds `filemanager`, `composables`, `plugins`), `make:sk-domain --with=/--relations=`, the actual `sk:update` semantics (SAFE_UPDATE now only regenerates `PermissionEnum.php`; `permission-resources.php`/`settings.php` are never overwritten; vendor-resident paths are reported, not copied), the current composable surface (vendor-resident, local-first), the full FormBuilder field-type list (adds `editor`, `section`, `translatableText/Textarea/Editor`), the SkForm safety guards, and the `VITE_SK_THEME` theme system. Skill bodies are now fully in English (Turkish trigger keywords retained) so the same files serve both Claude and Codex.

## [13.6.11] - 2026-07-22

### Fixed

- **`SkForm` file-upload fields are no longer left holding the submitted `File` after a successful save.** Two user-visible symptoms shared one root cause. The uploaded file is written to the server and re-rendered as `existingMedia`, but the `File` object stayed in form state, so `newFilePreviews` listed the same file a *second* time — the same image appeared as two rows. Worse, `13.6.10`'s `internalForm.defaults()` rebaseline was technically unable to clear `isDirty` while a `File` was present: Inertia rebaselines with `cloneDeep(this.data())` and a deep watcher immediately recomputes `isDirty = !isEqual(this.data(), defaults)`. A `File` survives the clone as a *different* instance, and es-toolkit's `isEqual` does not consider two distinct `File` objects equal — so the flag flipped straight back to `true` no matter how often it was reset. The unsaved-changes banner and the `beforeunload` / Inertia leave-guard therefore stayed on permanently for any form containing a file field. `onSuccess` now clears every `file-upload` field before rebaselining — `multiple` fields are reduced to the current `existingMedia` id list, single-file fields to `null` — so `defaults()` operates on cloneable state and dirty tracking actually resets. For `dataUrl`-driven forms (including `.resource()` in edit mode) the media list is **refreshed from the server first**: `remoteData` is otherwise captured once at mount, so `existingMedia` would still hold the pre-upload ids — reducing the field to those would hide the file just uploaded and make the *next* save delete it, since `HasMediaCollections::syncMediaCollection()` removes any media missing from the submitted keep-list. The refresh is silent (no loading skeleton, and a failure never replaces a just-saved form with the load-error panel); if it fails, the file fields are left untouched and the form stays dirty rather than risking the upload. Covered by `SkForm.fileUpload.spec.ts`, which drives the real Inertia `useForm` with only the transport faked. Runtime-only (`resources/js/components/`), delivered by `composer update`.

## [13.6.10] - 2026-07-21

### Fixed

- **`SkForm` internal-mode submits no longer stay dirty after a successful save.** For forms driven by `config.submit`, `onSuccess` only emitted `success` and never rebaselined Inertia's `defaults`, so `isDirty` (`data !== defaults`) stayed `true` forever against the pre-save baseline. The unsaved-changes banner and the `beforeunload` / Inertia leave-guard therefore kept firing *after* a real save — data was persisted correctly, the flag was not. `onSuccess` now calls `internalForm.defaults()` to rebaseline dirty tracking to the saved state. This is done explicitly instead of relying on the `derivedDefaults` watch, which does not fire reliably for `preserveScroll` submits, shallow-equal early-returns, or remote-data forms that never refetch. Two ordering/safety details are deliberate: `success` is emitted *before* the rebaseline, so a host that calls `reset()` on success (create forms, e.g. the Settings → Mail test-mail form) still clears to the original defaults rather than to the just-submitted values; and the rebaseline is **skipped when the form no longer matches the submitted payload**, since fields stay editable while the request is in flight — an edit typed mid-request was never sent, so the form correctly stays dirty instead of marking unsent input as saved. Runtime-only (`resources/js/components/`), delivered by `composer update`.

## [13.6.9] - 2026-07-08

### Security

- **`CheckResourcePermission` is now fail-closed on every host except `local`.** Previously, when a route resolved to a permission that was not seeded in the database, the middleware **allowed** the request through on *any* non-production environment (staging, uat, demo, `testing`) — only `production` denied it. A public staging/demo host could therefore silently expose an endpoint whose permission row had been forgotten. The middleware now **denies** an unseeded permission everywhere except `local` (which still warns + allows to preserve dev DX). Consumers who relied on the old posture can opt back in with `starter-kit.permissions.allow_unmapped => true` (env `STARTER_KIT_ALLOW_UNMAPPED_PERMISSIONS=true`), which restores allow-on-any-non-production; `production` always denies regardless. This is a deliberate behavior change on non-production hosts — see [UPGRADE.md](docs/UPGRADE.md). Runtime-only (`src/`), delivered by `composer update`.

### Changed

- **`CheckResourcePermission`'s seeded-permission lookup is now Octane-safe.** The list of seeded permission names was cached for the whole worker lifetime via a container `instance()` binding, so under Octane a long-lived worker could serve a stale set indefinitely. It is now a short-TTL `Cache::remember` (60s), and both `sk:seed-permissions` and the Roles UI permission sync flush it immediately after seeding so newly added permissions take effect at once.
- **`SkDatatable` column visibility/order preferences moved from `sessionStorage` to `localStorage`.** Existing users' saved column preferences are reset **once** after upgrading (no data loss — purely cosmetic, preferences just revert to defaults and can be re-set).

## [13.6.8] - 2026-07-04

A quality-control and UX sprint applying the findings of an internal audit report: security-test coverage, audit-log completeness, backend convention cleanup, accessibility/UX gaps, and install/upgrade DX. See [UPGRADE.md](docs/UPGRADE.md) for the one published-file behavior change (`login_throttle`) that needs `sk:update`; everything else here ships via `composer update` alone.

### Security

- **`auth.login_throttle = '0'` no longer fully disables the web login rate limiter.** Previously this setting nulled Fortify's `login` limiter entirely, so an administrator toggling it off left web login unthrottled. `SettingsServiceProvider` now swaps in a deliberately generous `login-relaxed` floor limiter instead — no admin setting can leave web login fully unthrottled. The API auth routes are unaffected (they already carry a hardcoded `throttle:5,1`). Published-file change — see [UPGRADE.md](docs/UPGRADE.md).

### Added

- **Audit log now covers role/permission changes, Settings, ApiClient/ApiToken, share links, and Content Languages.** Role↔permission pivot changes are recorded on a dedicated `audit` activity channel (attribute-only changes stay on the existing `HasActivityLogging` trait channel — no double-logging). `SettingService::setValue()`/`setGroup()` record which setting *keys* changed (never the values — secrets are never written to the log). ApiClient/ApiToken create/revoke, share-link create/revoke, and ContentLanguage CRUD are all now visible in the ActivityLog admin screen.
- **`sk:install` preflight + checkpoint/resume.** A Node.js version check runs before any file is touched (warns and lets the npm step degrade gracefully instead of a cryptic failure). Progress is checkpointed after every step to `storage/starter-kit/install-progress.json`; a failed step prints an actionable message instead of a raw stack trace, and `php artisan sk:install --resume` picks up exactly where it left off without redoing completed work.
- **`sk:doctor` gained `NodeVersionCheck` and `QueueWorkerCheck`,** and `ScheduleConfiguredCheck` now warns (instead of silently reporting OK) when no cron heartbeat is detected. Every check now runs under a timeout guard so one hung DB/Redis/SMTP check can no longer stall the whole command.
- **`sk:eject` asks for confirmation before ejecting**, unless `--force`, `--dry-run`, or `--no-interaction` is passed — ejecting is a one-way trade-off (the domain stops receiving kit runtime updates). `sk:install`'s own internal default-domain eject always passes `--force`, so fresh installs are unaffected.
- **Datatable keyboard accessibility.** Sortable column headers are keyboard-operable (`tabindex`, Enter/Space, `aria-sort`); the search-clear and filter-remove controls are real `<button>` elements instead of icon `<span>`s (visual output unchanged).
- **Datatable empty state distinguishes "no results for your filter" from "no records at all"**, with a "Clear filters" action when a search/filter is active.
- **`SkForm` gained several safety guards:** a double-submit guard (re-entrant submits while a request is in flight are ignored), a dirty-form navigation warning (both Inertia SPA navigation and browser `beforeunload`; opt out per-form with `confirmLeave: false`), a toast + in-form retry state when a form's remote data or field options fail to load (previously a silent `console.error`), and `aria-required` + a screen-reader-only "required" hint on required fields.
- **FileManager gained an aggregate upload-progress indicator** across concurrent uploads (in addition to the existing per-file progress), and `ImageLightbox` supports ←/→ arrow-key gallery navigation alongside the existing Escape-to-close.
- New feature-test coverage: `CheckResourcePermission` middleware scenarios, `ActionPipeline` transaction/rollback behavior, and a `DatabaseTestCase` schema-drift detector that fails loudly if the inline test schema diverges from the real migrations.
- **`release.sh` runs a pre-tag quality gate** (`composer lint && composer test && composer security`) before tagging a release; `--skip-checks` opts out.

### Changed

- CI's ESLint step is now blocking (`continue-on-error` removed); the Vue lint ruleset was raised from `flat/essential` to `flat/strongly-recommended` in the published `eslint.config.js` (may surface new pre-existing style warnings on the first `npm run lint` after `sk:update`).
- `LogicException` is now mapped to a 422 response centrally in `ApiExceptionHandler`; the ~12 repetitive `try/catch (LogicException)` blocks in `FileManagerController` were removed (response shape unchanged).
- Backend convention cleanup: a dedicated `UploadLogoRequest` (matching the existing `UploadFaviconRequest` pattern), `abort()`/`abort_unless()` replaced with `ApiException::*` in a few JSON-only controller methods, and the twin `Api\DefinitionController` / `Service\DefinitionServiceController` now share a common private method instead of duplicating it.
- `DefinitionService` cache invalidation was unified onto a single locale-keying strategy and wired to the `Definition` model's save/delete/restore events, so definition edits are reflected immediately instead of waiting out the ~1h cache TTL.
- `--no-interaction` installs now generate and print a fresh random admin password instead of a fixed, guessable `password`.
- `UpgradeCommand`'s PHP-version assertion was raised from 8.3 to 8.4, matching the package's actual `composer.json` requirement.
- `env:sync` no longer stacks a new `# Auto-added keys` comment block on every re-run; a new `pint.json` pins the previously-implicit `laravel` preset explicitly (no formatting change).

### Fixed

- Vitest configuration was extracted from `vite.config.ts` into its own `vitest.config.ts`, and FormBuilder/DatatableBuilder/TabBuilder gained baseline builder-chain unit tests.
- FileManager upload-error messages and the share-link modal's `aria-label` were moved from hardcoded strings into the kit's i18n bundles (EN + TR); an empty file selection now shows a clear "no file selected" message instead of a stray "coming soon" placeholder.

## [13.6.7] - 2026-07-03

### Fixed

- **Rich-text editor body was only clickable/editable where text already existed** — `EditorInput.vue` applies its `minHeight` prop as inline `min-height` on the `.sk-rte__body` wrapper, but the inner ProseMirror element used `height: 100%`. A percentage height only resolves against a parent with a definite (non-`min-`) height, so the ProseMirror node grew only to fit its own content, leaving the rest of the visually-tall box outside the actual `contenteditable` region — clicking or typing there did nothing. `.sk-rte__body` is now a flex column and `.ProseMirror` uses `flex-1` instead of `height: 100%`, so the real editable area fills the full configured height.

## [13.6.6] - 2026-06-20

### Fixed

- **Activity log accepts both uuid and bigint subjects** — `create_activity_log_table` used `nullableUuidMorphs`, producing native `uuid` columns for `subject_id`/`causer_id`. The kit logs activity on `User` (uuid key) **and** on the Spatie `Permission`/`Role` models (default bigint keys), so seeding permissions crashed with `SQLSTATE[HY000] 4078: Cannot cast 'bigint' as 'uuid'`. A new migration widens both polymorphic id columns to `char(36)`, which holds a 36-char uuid and any numeric id alike — one polymorphic column now works for every audited model. The migration converges every prior state (native uuid, legacy bigint, legacy char(36)) to `char(36)`; existing apps pick up the fix on the next `php artisan migrate`.

## [13.6.5] - 2026-06-14

### Fixed

- **Translation bundles ship with the package** — the pre-compiled kit translation bundles (`resources/js/lang/php_{en,tr}.json`) were listed in `.gitignore`, so they never entered Git and were absent from the Composer dist (which is a `git archive` of tracked files only). A freshly installed app received only the build script, not the bundles, so every kit i18n key (`sk-menu.*`, `sk-setting.*`, …) rendered as its raw key instead of the translated label. The two bundles are now tracked and shipped. The consumer does not build the package, so — unlike the consumer-built theme bundle — these must be committed to reach `vendor/`; the build script's own docs already specified "COMMITTED and shipped".

## [13.6.4] - 2026-06-14

### Fixed

- **Datatable inline filter dropdown no longer clipped** — a select filter's inline pill menu was rendered as an `absolute` element inside the table card, so a long option list was cut off at the card / scroll-container `overflow` edge. The menu is now teleported to `<body>` as a fixed overlay (the same approach PrimeVue's own `Select` uses via `appendTo`): it is positioned from its trigger, re-aligns on scroll/resize, caps at `min(60vh, 420px)` with its own scroll, and closes on outside-click / Escape. The `panel`-placement popover variant is unchanged (it already rides PrimeVue's overflow-visible portal).

## [13.6.3] - 2026-06-13

### Changed

- **Aura sidebar footer is a version pill** — the aura-theme sidebar footer is now a single-row pill card: a green status dot and the app name on the left, the version (monospace) pushed to the right edge. Its left/right inset matches the nav item cards above it (`mx-3` = the nav region's `p-3`). Scoped to `html[data-sk-theme='aura']`; the `main` theme footer is unchanged.
- **Account menu drops the external-link arrow on plain links** — the topbar user/account dropdown no longer shows the hover `↗` (`pi-arrow-up-right`) on ordinary link items (My Profile, Account Settings, Change Password, Help, Logout). Submenu items keep their chevron and the active locale keeps its check mark.
- **Datatable filter popover is panel-only** — the funnel button and its popover now render only when at least one filter uses `panel` placement; `inline()` filters are no longer duplicated inside the popover. On the **Activity Logs** page all three filters (Event, Model, Date) are now `inline()`, so its funnel/popover disappears entirely and the filters sit in the toolbar.

### Fixed

- **`sk:install` / `sk:update` banner version label** — the installer/updater header now reads `v13.6.x` (was the stale `v13.5.x`). Cosmetic only; the historical `v13.5.0+` behaviour notes are unchanged.
- **Datatable `value`-mode tags resolve i18n keys at render time** — `tagLabels()` values are now translated when the cell renders instead of when the builder runs. The builder is constructed in a page's `<script setup>` body before the i18n bundle is ready, so an eager `trans()` there froze the raw key (e.g. the Content Languages table showed `sk-content-languages.directions.ltr` instead of "Left to right (LTR)"). `trans()` returns plain (non-key) strings unchanged, so literal tag labels are unaffected.
- **Content Languages form — spurious required asterisks removed** — FormBuilder fields are required by default, so `flag`, `fallback_code` and `sort_order` (all `nullable` server-side) rendered a red `*`. They are now marked `.optional()`, matching their validation rules; `code`, `name`, `native_name` and `direction` keep the asterisk.

## [13.6.2] - 2026-06-13

### Added

- **`sk:install` default domain eject** — on a fresh install, `User` and `Role` domain runtime classes are automatically ejected into `app/Domain/User/` and `app/Domain/Role/` with namespace rewrite and `DomainServiceProvider` event binding injection. First-install detection uses the hash registry (`storage/starter-kit/hashes.json`): when that file does not yet exist the step runs; on existing installs the registry is already present so the step is skipped entirely. If `app/Domain/{User,Role}/Actions` already exists from a prior manual eject, that domain's eject is skipped with a warning. Pass `--without-eject` to keep both domains vendor-resident.
- **`sk:install --without-eject` flag** — opt out of the default install-time eject; runtime stays in vendor and resolves via `class_alias`, identical to pre-v13.6.0 behaviour.
- **`sk:eject --skip-autoload` flag** — suppresses the `composer dump-autoload` call at the end of eject. Intended for callers (such as `sk:install`) that run their own autoload regeneration step; not recommended for direct CLI use.
- **Vendor-first Phase 2 — five more `sk:eject` domains** — the remaining Settings-tab controllers and two API/Service controllers are now vendor-resident and ejectable: `SystemHealth` (controller-only), `Definitions` (Api + Service controllers wrapping the vendor `DefinitionService`), `MediaUpload` (controller-only; `media.destroy` route in `routes/web.php`), `ContentLanguage` (domain + controller + request + resource), and a full-HTTP-layer `ApiClient` that also ejects the `ApiToken` controller/request/resource. The ejectable domain count rises to fourteen. Each moved class is aliased from its `App\Http\...` / `App\Domain\ContentLanguage\...` FQCN to vendor under a `file_exists` guard; route names, permission keys, and the Passport secret single-reveal are unchanged. `App\Models\{ContentLanguage,Media,Definition}` are never moved or aliased — preserving policy discovery and route-model binding.

- **`sk:eject {domain}` command** — ejects a vendor-resident domain into the consumer app for full, project-owned customization. Copies `src/Domain/{Name}/**` to `app/Domain/{Name}/`, rewrites only that domain's namespace (`Lvntr\StarterKit\Domain\{Name}\` → `App\Domain\{Name}\`; Shared base classes and all other vendor references are left untouched), refreshes the domain's Vue pages (an existing page is left untouched unless `--force` is passed, so customizations survive), and injects `Event::listen(...)` bindings into `app/Providers/DomainServiceProvider.php` for domains that carry audit-log events (User, Role, Logs) so the audit trail keeps firing after the namespace switch. Nine domains are ejectable: `User`, `Role`, `Setting`, `Logs`, `ActivityLog`, `ApiClient`, `ApiRoute`, `Session`, `Media`. Auth screens, global helpers, and FileManager are out of scope (Auth is already app-owned; FileManager has its own infrastructure).
  - `--dry-run` prints the copy/rewrite/injection plan without writing any files.
  - `--force` overwrites files that already exist — both the backend `app/Domain/{Name}/` tree and the domain's Vue pages. Without it, eject never overwrites an existing file: an already-present `app/Domain/{Name}/` exits early, and an existing Vue page is preserved (reported as skipped) while missing pages are written.
  - `--no-vue` skips Vue page refresh; backend only.
  - `--destination=<path>` redirects output to an arbitrary directory (useful for testing).
  - The command exits non-zero if Composer's autoload regeneration fails, so CI/scripts do not treat a broken autoload as a successful eject (the files are still copied; run `composer dump-autoload` manually).
  - Alias deactivation is automatic: `backwardCompatAliasPlan()` already skips the alias when an app copy exists; no extra step required after eject.
  - **Trade-off:** ejected domains no longer receive upstream security or bug-fix updates via `composer update`. The command output and documentation call this out explicitly.
  - Reverting is manual in v1: delete `app/Domain/{Name}/`, remove the injected `Event::listen` lines from `DomainServiceProvider`, and run `composer dump-autoload`. A `--revert` flag is planned for a future release.

### Fixed

- **Admin panel layout & form alignment** — several `main`-theme layout glitches fixed: the roles form basics section is now a responsive 3-column grid that stacks on small screens (`FB.form().cols(3)`); the permissions table renders flush to its card via the `SkCard` `flush` prop instead of sitting inside the body padding; translatable-field locale tabs (`TranslatableInput`) were taller than plain labels and pushed their input down — the tab pills now match the plain-label height so all inputs in a row align; the sidebar nav crushed its rows when multiple groups were expanded — direct children are now `shrink-0` so overflow scrolls instead; and the sidebar footer height is pinned to `h-footer` (56px) so its top border lines up with the page footer border.

### Changed

- **Security settings sub-tab "Cloudflare Turnstile" renamed to "Bot Protection"** — the settings security sub-tab label (EN/TR) and the related `SecurityTab` section now read "Bot Protection" instead of the provider-specific name.

## [13.6.1] - 2026-06-13

### Fixed

- **`sk:update` self-heals stale imports of vendor-moved components** — when a component moves out of stubs into `@lvntr/components` its old local copy is force-deleted, but user-customized pages that still import the deleted local path were left untouched and broke the Vite build with an `ENOENT` load-fallback error (`@/components/Auth/TurnstileWidget.vue`). `sk:update` now rewrites such stale import specifiers to the vendor path (`@lvntr/components/ui/TurnstileWidget.vue`) across `resources/js`, so the migration that started in v13.6.0 completes on existing consumers' customized Auth pages (`Login`, `Register`, `ForgotPassword`).

## [13.6.0] - 2026-06-07

This release bundles every published-file change since v13.5.11 into one version. It completes the vendor-runtime migration (composables, backend helpers/middleware, three third-party configs, five domain modules, kit migrations, kit translations) and introduces the structured theme/layout/CSS system. **No visual change** — the default build (`VITE_SK_THEME=main`) is byte-identical to v13.5.11. See `docs/UPGRADE.md` (`v13.5.11 → v13.6.0`) for the full migration guide.

### Added

- **Kit composables moved to vendor** — 15 composables (`useApi`, `useCan`, `useConfirm`, `useDarkMode`, `useDatatableSelection`, `useDefinition`, `useDialog`, `useFileShare`, `useFlash`, `useImageLightbox`, `useMenuBuilder`, `usePageLoading`, `useRefreshBus`, `useSidebar`, `useUrlTab`) now run directly from the vendor package. Import paths (`@/composables/<name>`) are unchanged; a Vite `customResolver` + tsconfig path entry resolve local-first then vendor. `useAdminMenu` and `index.ts` remain as editable stubs.
- **`sk:publish --tag=composables`** — publishes the vendor composables into the consumer's `resources/js/composables/` for project-level customization. Once a local copy exists it automatically overrides the vendor one.
- **`TurnstileWidget.vue` moved to vendor** — `resources/js/components/Auth/TurnstileWidget.vue` stub removed; the component now ships at `@lvntr/components/ui/TurnstileWidget.vue`.
- **Backend runtime classes moved to vendor** — `HtmlSanitizer`, `TranslatableQueryHelpers`, `MediaPathGenerator`, `Scramble\ApiResponseExtension`, and the `AssignTraceId` / `SetLocale` / `ValidateTurnstile` middleware now run from `Lvntr\StarterKit\*` with no stub copied to the app. Existing `App\…` imports keep resolving via `class_alias` (the alias is skipped when you keep a customised local copy). `ApiResponseExtension` is now properly registered with Scramble.
- **Backend classes moved to vendor with a thin `App\` shim** — `App\Http\Responses\DatatableQueryBuilder`, `App\Rules\HttpsOrLocalhostUrl`, and `App\Rules\TurnstileRule` keep their familiar import path via a thin subclass while the implementation runs from vendor.
- **`HasTranslatableRules` trait moved to vendor (direct import)** — now `Lvntr\StarterKit\Support\HasTranslatableRules`. PHP traits cannot use `class_alias`, so there is no `App\` fallback; import the trait from the vendor namespace (consistent with `HasActivityLogging` / `HasMediaCollections`).
- **Permission directive plugin moved to vendor** — `resources/js/plugins/permission.ts` (the `v-can` / `v-role` directives) now resolves from the vendor package, local-first then vendor, mirroring the composables. New `@/plugins/*` resolver in `vite.config.ts` and path mapping in `tsconfig.json`. The dead `useCan()` re-export was dropped (use `@/composables/useCan`). No behavior change; `sk:publish --tag=plugins` recreates an editable local copy.
- **Theme / layout / CSS system** — the admin shell is split into a reusable `resources/js/layouts/AppShell.vue` (sidebar state, responsive margins, named regions) plus a thin `AdminLayout.vue` composition with an identical prop/slot contract. CSS lives in a structured `themes/main/` slot tree where every cascade layer (fonts, base, tokens, auth, per-component, utilities) is an individually overridable slot. A new `VITE_SK_THEME` env key (`.env.example` default `main`) selects the active theme. No visual change — the generated `_active.css` is byte-identical to v13.5.11.
- **Opt-in `themes/custom/` override theme** — set `VITE_SK_THEME=custom` to replace any CSS slot (and, via `resources/js/theme/custom/preset.ts`, the PrimeVue preset) at build time without touching the base theme or the Vue components. An absent override file falls back to the base slot.
- **Domain runtime layers moved to vendor (Phase 3)** — `ActivityLog`, `Logs`, `Session`, and `Media` modules' runtime (Actions, DTOs, Queries, Events, Listeners, Services) now run from `src/Domain` (PSR-4 `Lvntr\StarterKit\Domain\`). `App\Domain\<Module>\…` imports resolve via `class_alias`; existing app copies are preserved and win when present. The `LogFilesDeleted` listener is now wired by `StarterKitServiceProvider`. `PurgeOtherSessionsAction::execute()` now accepts `Illuminate\Contracts\Auth\Authenticatable` (callers passing `App\Models\User` are unaffected).
- **Domain runtime layers moved to vendor (Phase 6)** — `User`, `Role`, `Setting`, `ApiClient`, and `ApiRoute` modules' runtime (Actions, DTOs, Events, Listeners, Queries, and `SettingService`) now run from `src/Domain`. Controllers, FormRequests, Models, Policies, Vue pages, route files, and `config/settings.php` stay app-owned. `User` / `Role` audit events (`UserCreated`/`Updated`/`Deleted`, `RoleCreated`/`Updated`/`Deleted`) and their `Log*` listeners are wired directly by `StarterKitServiceProvider`. Secret handling (Passport `plainSecret` single-use), Setting encryption (`sensitive_keys` + `Crypt::encryptString`), and the `Store/UpdateRoleRequest` privilege boundary are byte-identical — only file locations moved.

### Changed

- **Third-party config overrides applied at runtime** — `config/activitylog.php`, `config/inertia.php`, and `config/media-library.php` are no longer published. `StarterKitServiceProvider::applyVendorConfigDefaults()` sets only the kit's required keys (`media-library.path_generator` + `media-library.media_model`, `activitylog.include_soft_deleted_subjects`, `inertia.ssr.enabled`) and skips any config the consumer has already published. Publish the upstream package's own config tag (e.g. `--tag=medialibrary-config`) to take full control.
- **`sk:install` / `sk:update`** — the media-library `path_generator` AST/string injection was removed (the value is now set at runtime); the new vendor-resident files are surfaced under `sk:update`'s informational "runs from vendor" notice.
- **Theme directory structure flattened — BREAKING** — the intermediate `themes/` directory is removed from both theme trees: `resources/css/theme/themes/main/` → `resources/css/theme/main/`, `themes/custom/` → `resources/css/theme/custom/`, and `resources/js/theme/themes/custom/preset.ts` → `resources/js/theme/custom/preset.ts`. Consumer apps must manually delete the old `resources/{css,js}/theme/themes/` directories after `sk:update` (it does not remove them) and re-run `npm run theme:build && npm run build`. Projects on the default `VITE_SK_THEME=main` have no visual change.
- **Build scripts moved to vendor — consumer wiring update required** — `scripts/sk-theme-build.mjs` and `scripts/vite-plugin-sk-theme.mjs` now ship inside the package (`resources/js/theme/`) and are no longer copied into the app. Existing installs must repoint the `vite.config.ts` plugin import and the `package.json` `theme:build` script to the vendor path; with the `skTheme()` Vite plugin generating `_active.css` inside the Vite lifecycle, the `dev` / `build` scripts no longer need the explicit `node scripts/...` prefix. The generated `_active.css` output is identical.
- **Kit migrations moved to vendor (Phase 4)** — six kit migrations (`media`, `activity_log`, `definitions`, `settings`, media `folder_id`, media `deleted_at`) moved to `database/migrations/` and are auto-loaded via `loadMigrationsFrom` when `config('starter-kit.run_migrations')` is `true`. Laravel's basename-keyed history skips any already-run migration (no double-run). Framework-default, Passport, and Spatie permission migrations are unchanged and stay in the app. Escape hatch: publish `--tag=starter-kit-migrations` and set `run_migrations` to `false`.
- **Kit translations moved to vendor (Phase 5)** — 44 kit translation files (`sk-*.php`, two locales) moved to `resources/lang/{en,tr}/`, with pre-compiled `resources/js/lang/php_{en,tr}.json` for the frontend. The i18n setup merges vendor JSON with the app's `lang/*.php` compilation — **app keys always win**; missing keys fall back to the vendor default. `lang/{locale}/validation.php` stays in the app (Laravel framework override surface). Escape hatch: `php artisan sk:publish lang`.
- **`config/settings.php` added to the never-update sanctuary** — `sk:update` will never overwrite it, protecting custom setting groups and sensitive-key whitelists. Applies from v13.6.0 forward; no manual action required.

### Removed

- **`stubs/resources/js/composables/<name>.ts`** — 15 composable stubs removed from the scaffold. Existing projects keep their local copies (local-first resolver ensures no breakage); to receive upstream updates via `composer update`, delete the unmodified files, keeping `useAdminMenu.ts`, `index.ts`, and any customized composables.
- **Backend stubs removed from the scaffold** — `app/Support/{HtmlSanitizer,TranslatableQueryHelpers,MediaPathGenerator,HasTranslatableRules}.php`, `app/Support/Scramble/ApiResponseExtension.php`, `app/Http/Middleware/{AssignTraceId,SetLocale,ValidateTurnstile}.php`, and `config/{activitylog,inertia,media-library}.php`. Existing copies are preserved in upgraded apps (shown as an informational notice by `sk:update`, never deleted automatically). Note: deleting a local copy of the `HasTranslatableRules` trait requires updating its `use` imports to the vendor namespace first (no `class_alias` for traits).
- **Domain runtime stubs removed from the scaffold (Phases 3 & 6)** — a fresh `sk:install` no longer copies the `ActivityLog`, `Logs`, `Session`, and `Media` domains, nor the `ApiClient` / `ApiRoute` domains and the runtime subtrees (`Actions`, `DTOs`, `Events`, `Listeners`, `Queries`) of `User` / `Role` / `Setting`. Existing app copies are preserved and reported by `sk:update` (informational only — never deleted automatically); vendor copies take precedence where no app copy exists.
- **Kit migration & translation stubs removed from the scaffold (Phases 4 & 5)** — a fresh `sk:install` no longer copies the six vendor-resident migrations or the 44 `sk-*.php` translation files. For existing installs, `sk:update` **force-deletes** the six migration app copies (safe — each basename is already recorded in the `migrations` table and will not re-run); the `lang/{locale}/sk-*.php` copies are reported but never deleted automatically (kept copies still override the vendor default per key).
- **Old flat-path CSS partials and build scripts** — `sk:update` adds the new `themes/main/` tree and removes the vendor-managed build scripts (`scripts/sk-theme-build.mjs`, `scripts/vite-plugin-sk-theme.mjs`) but does not delete the orphaned flat `_*.scss` / `fonts.css` / `utilities.css` copies left on disk; they are no longer imported by anything and may be deleted to keep the tree clean.

## [13.5.11] - 2026-06-04

### Added

- **Standalone AI skill set (3 skills)** — replaces the removed monolithic skill. Distributed under `stubs/.claude/skills/`:
  - **`lvntr-starter-kit`** — core rules: hard constraints, recipe pointers, permissions/i18n config, and cross-domain `references/` links.
  - **`lvntr-kit-domain`** — backend / DDD layer: Actions, Services, FormRequest, Resource, Repository conventions, and domain boundaries.
  - **`lvntr-kit-frontend`** — FormBuilder / DatatableBuilder / TabBuilder patterns, composables (`useApi`, `useDialog`, `useForm`), and starter-kit component rules.
- **`sk:install --without-ai-skill` flag** — opt-out of AI skill publishing during host application setup. The skills are self-contained and require no additional tooling.

### Removed

- **`stubs/.claude/skills/lvntr-starter-kit/SKILL.md`** — the previous 723-line monolithic skill file has been removed and replaced by the 3-skill set above. If you published the old file to your host application, delete `.claude/skills/lvntr-starter-kit/SKILL.md` before running `vendor:publish` again.

## [13.5.10] - 2026-05-30

### Added

- **`SkCard` UI primitive** — `resources/js/components/Lvntr-Starter-Kit/ui/SkCard.vue`. PrimeVue Card'ı sarmalayan paylaşımlı wrapper. Tüm Sk-prefix bileşenlerinin (`SkForm`, gelecekte `SkDatatable` vb.) ve uygulama içi card'ların tek noktadan tutarlı title/subtitle/caption davranışı için kullanması amaçlanan primitive.
  - Props: `title?: string`, `subtitle?: string`, `transparent?: boolean` (default `false` — `true` → arka plan/shadow/padding sıfır, dialog veya nested card için), `divider?: boolean` (default `true` — caption bloğunun altına alt çizgi), `pt?: Record<string, any>` (PrimeVue Card pt'sine merge edilir; consumer override eder).
  - Slots: `header`, `title`, `subtitle`, `content` (default slot da content'e map'lenir), `footer`, **`title-end`** (başlığın sağına aksiyon/badge/durum).
  - `inheritAttrs: false` + `useAttrs` ile dış `class` fallthrough'u Card root'una taşır (PrimeVue Card kendi `inheritAttrs: false` yaptığı için aksi halde class düşmüyordu).
  - `index.ts`'den `SkCard` olarak export edildi.
- **`SkForm.vue` — `#title-end` slot** — form-level card başlığının sağına render edilen yeni slot. Action button, badge veya durum göstergesi gibi içerikleri başlık metniyle aynı satırda, sağa hizalı yerleştirmek için. Slot yalnızca içerik verildiğinde render edilir.
- **`SkFormFieldRenderer.vue` — per-section `#section-${key}-title-end` slot** — her section card başlığının sağına render edilen scoped slot. `SkForm.vue` zaten generic `v-for $slots` forwarding yaptığı için tüketici doğrudan `SkForm` üzerinden `<template #section-address-title-end="{ values }">` şeklinde kullanır. Scope: `{ values }` — mevcut form değerlerinin reaktif snapshot'ı (koşullu render için).
- **Docs** — `docs/ui-components.md` ve `docs/ui-components.tr.md`'ye yeni "SkCard" bölümü; `docs/formbuilder.md` ve `docs/formbuilder.tr.md`'ye "Card Title Actions Slot" / "Card Başlık Sağ Slot" bölümü.
- **`BaseFieldConfig.colSpan?: number`** — field'ın (ya da section içindeki field'ın) form grid'inde kaç sütun kaplayacağını belirtir (`1..cols`). Belirtilmezse mevcut davranış geçerli (1 hücre). `cols` değerini aşan değerler otomatik clamp'lenir; section içinde clamp, `sectionCols`'u (section'ın kendi `cols` değeri veya form `cols`) referans alır.
- **`BaseFieldBuilder.colSpan(n: number)`** — her field builder'ına zincirlenebilir `.colSpan(n)` eklendi. Örnek: `FB.inputText().key('baslik').label('Başlık').colSpan(12)`.
- **`SkColorSelector` — 5 nötr Tailwind ailesi** — tüm 50–950 shade'leriyle `slate`, `gray`, `zinc`, `neutral`, `stone` eklendi (Tailwind v4 resmi hex değerleri). Toplam palette: 22 aile.
- **`stubs/components.d.ts`** — `SkCard` export tipi eklendi.

### Changed

- **`SkForm.vue` — root `<Card>` → `<SkCard>` refactor** — kendi içindeki `cardPt` computed'i ve `transparentCard` style sabiti kaldırıldı; `:transparent="isTransparentCard"` prop ile SkCard'a devredildi. Form card başlığı/alt başlığı `:title`/`:subtitle` prop'larıyla geçirilir; flex wrapper ve caption alt çizgisi SkCard içinde tek noktadan üretilir.
- **`SkFormFieldRenderer.vue` — section render'ı `<Card>` → `<SkCard>` refactor** — `sectionCardPt` yerine `sectionIsTransparent` helper'ı + `:transparent` prop'u kullanır. Section title flex wrapper ve `title-end` slot SkCard'a delege edildi; icon'lu title (`SkIcon` + metin) doğrudan SkCard'ın `#title` slot'unda render edilir.
- **`RenderCtx` (SkFormFieldRenderer.vue) — `transparentCard` alanı kaldırıldı** — SkCard `transparent` prop'u tek doğru yol; ctx'te dolaşan style sabiti gerek bırakmıyor.
- **`stubs/resources/css/theme/_card.scss`** — SkCard stilleri eklendi:
  - `.sk-card__title-row` (flex w-full justify-between, başlık satırı)
  - `.sk-card__title-text` (başlık metni, ikon hizalama için inline-flex)
  - `.sk-card__title-end` (sağ slot kapsayıcısı, shrink-0)
  - `.sk-card--divider .p-card-caption` (caption bloğunun altına `pb-3 mb-1 border-b` + `--p-surface-200` / `--p-surface-700` dark varyant) — yalnız SkCard içinde tetiklenir, diğer PrimeVue Card kullanımlarını etkilemez.
- **`stubs/resources/css/theme/_formbuilder.scss`** — SkCard'a taşınan geçici selektörler (`.sk-fb__card*`, `.sk-fb__section-title-wrapper`, `.sk-fb__section-title-end`, `.sk-fb__card .p-card-caption`, `.sk-fb__section .p-card-caption`) kaldırıldı. Yerlerine "SkCard'a bakın" notu eklendi.
- **`SkForm.vue` — `colsClassMap` 1–12'ye genişletildi** — 7–12 aralığı artık default grid'e düşmüyor; `cols(7)`–`cols(12)` doğrudan `md:grid-cols-N` uygular.
- **`SkForm.vue` + `SkFormFieldRenderer.vue` — `colSpanClassMap`** — purge-safe statik map eklendi; üst seviye ve section içi field wrapper'ları `colSpan` değerine göre `md:col-span-N` alır. `colSpan` belirtilmemiş field'lar öncekiyle birebir render edilir (regression yok).

## [13.5.9] - 2026-05-21

### Added

- **`SkIcon` UI primitive** — paket-bağımsız icon renderer. Tek `icon: string` prop ile üç format auto-detect: `<svg…` → ham SVG (`v-html`), `^(https?:|data:)` → `<img>`, diğer → `<i :class>` (PrimeIcons, FontAwesome, Material Design Icons, Lucide, Iconify ve diğer class-tabanlı icon set'leri için ortak API). `resources/js/components/Lvntr-Starter-Kit/ui/SkIcon.vue`. **Güvenlik:** `icon` propu yalnızca builder config'ten (developer-controlled) geçirilmelidir — kullanıcı kaynaklı string XSS riskidir (`<svg…` path'i `v-html` ile render eder).
- **`BaseFieldConfig` icon alanları** — tüm field tipleri için ortak icon API'si:
  - `labelIcon?: string` + `labelIconPosition?: 'left' | 'right'` (default: `'left'`) — label yanına icon (vertical/horizontal tüm layout path'lerinde çalışır).
  - `icon?: string` + `iconPosition?: 'left' | 'right'` (default: `'left'`) — input içine icon. Desteklenen tipler: `input-text`, `input-number`, `input-mask`, `password` (custom path — `feedback: true` ise icon yok). `groupPrefix`/`groupSuffix` öncelikli — varsa input icon devre dışı.
- **`TitleFieldConfig` icon alanları** — `icon?: string` + `iconPosition?: 'left' | 'right'`. `FB.title('Genel').icon('pi pi-info-circle')` örneği.
- **`SectionFieldConfig` (yeni field tipi `type: 'section'`)** — form içinde Card ile field gruplama:
  - `title?` (translation key, label fallback), `subtitle?`, `icon?`, `iconPosition?`
  - `cols?: number` (default: parent form's `cols`)
  - `fields: FieldConfig[]` (nested — tek seviye; nested section desteklenmez)
  - `isCard?: boolean` (default: card görünür; `false` → transparent Card)
  - **Form veri yapısı flat kalır** — section'ın `key`'i payload'a girmez; section yalnızca görsel grouping primitive'idir.
- **`SectionBuilder` ve `FB.section(title?)` factory** — fluent API: `.title(t)`, `.subtitle(s)`, `.icon(str)`, `.iconPosition(p)`, `.cols(c)`, `.isCard(enabled)`, `.addFields(...)`.
- **`BaseFieldBuilder` fluent metotları** — `.labelIcon(str)`, `.labelIconPosition(p)`, `.icon(str)`, `.iconPosition(p)` artık tüm field builder'larında mevcut (`InputTextBuilder`'dan kaldırıldı, base'e taşındı — imza aynı, davranış değişmedi).
- **`TitleBuilder.icon()` ve `.iconPosition()`** metotları.
- **`SkFormFieldRenderer.vue`** — recursive field renderer. Section render, slot forwarding, label/title icon render bu komponente taşındı; `SkForm.vue` template'i basitleşti.
- **Docs** — `docs/formbuilder.md` ve `docs/formbuilder.tr.md`'ye 5 yeni bölüm: Icons (Package-Agnostic) / İkonlar (Paket-Bağımsız), Label Icons, Input Icons, Title Icons, Section / Card Grouping. Güvenlik notu (XSS) her iki dilde mevcut.

### Changed

- **`AppDialog.vue` — `confirmSeverity` artık default `'primary'` kullanmıyor** — `state.footer?.severity ?? 'primary'` → `state.footer?.severity`. Confirm düğmesi artık `severity` belirtilmediğinde PrimeVue Button'ın kendi varsayılan görünümünü kullanır (tema preset'inden gelir). `DialogFooter.severity` ile açıkça set edilmemiş mevcut dialog'lar görsel davranış değişikliği yaşayabilir.
- **`useDialog.ts` — `DialogFooterSeverity` tipi genişletildi** — `'primary'` kaldırıldı (PrimeVue Button'da geçerli değil); `'info'`, `'help'`, `'contrast'` eklendi. Tam liste: `'secondary' | 'success' | 'info' | 'warn' | 'help' | 'danger' | 'contrast'`.
- **`SkForm.vue` — flat field iterasyonu** — `derivedDefaults`, `currentValues`, `definitionKeys`, `dynamicSelectFields`, `hasFileFields`, `dateOnlyFields` hesaplamaları yeni `flatFields` computed'i (iteratif `iterateAllFields` generator) üzerinden çalışır. Section içindeki field'lar otomatik olarak doğru kategorize edilir (file-upload existingMediaKey resolve, date-picker toLocalDateStr transform, definition preload, dynamic optionsUrl fetch). Section'sız mevcut formlar bire bir aynı render edilir (regression yok).
- **`SkFormInput.vue` — generic input icon** — eski `input-text` özelinde `IconField` wrapping pattern'i `input-number`, `input-mask`, `password` (custom path) için de aktif. Icon descriptor `SkIcon` üzerinden render edilir; PrimeIcons dışında MDI/FA/Lucide/Iconify/SVG/img URL de çalışır. `BaseFieldConfig.icon` öncelikli, `InputTextFieldConfig.icon` legacy fallback olarak korunur.
- **`stubs/resources/css/theme/_formbuilder.scss`** — `.sk-fb__title` ve `.sk-fb__label` selector'larına icon alignment için minimal `inline-flex items-center gap` eklendi (line-height ve padding değişmedi). Yeni bölümler: `SKICON & LABEL/TITLE ICONS` (`.sk-icon`, `.sk-icon--svg svg`, `.sk-icon--img`, `.sk-fb__label-icon`, `.sk-fb__title-icon`, `.sk-fb__section-icon` + `--left/--right` modifier hook'ları), `SECTION CARD` (`.sk-fb__section`, `.sk-fb__section-title`, `.sk-fb__section-field`).

### Deprecated

- **`InputTextFieldConfig.icon` ve `InputTextFieldConfig.iconPosition`** — yeni `BaseFieldConfig.icon` ve `BaseFieldConfig.iconPosition` kullanın. Legacy alanlar geriye uyumluluk için korundu (`SkFormInput.vue` `base ?? legacy` fallback ile aynı render üretir), gelecek major versiyonda kaldırılacaktır.

### Upgrade

```bash
composer update lvntr/laravel-starter-kit

# Re-publish affected stubs (warning: customised stubs are overridden — diff first)
# stubs/resources/css/theme/_formbuilder.scss
# stubs/resources/js/composables/useDialog.ts  ← DialogFooterSeverity tipi değişti
php artisan vendor:publish --tag=starter-kit-stubs --force
```

**`DialogFooterSeverity` breaking change:** `'primary'` artık geçerli bir değer değil. `useDialog().open(...)` çağrılarında `severity: 'primary'` kullandıysanız kaldırın (Button kendi theme default'unu uygular) ya da `'secondary'` / `'contrast'` gibi geçerli bir değerle değiştirin. TypeScript bu satırları zaten hata olarak işaretleyecektir.

**Migration:** legacy `InputTextFieldConfig.icon` çağrılarınız aynen çalışmaya devam eder (deprecated, kaldırılana kadar fallback). Yeni özellikleri kullanmak için:

```ts
// Label icon — her field tipinde
FB.inputText().key('email').label('E-posta').labelIcon('pi pi-envelope')

// Input icon — input-text/number/mask/password
FB.inputText().key('search').icon('pi pi-search')                    // PrimeIcons
FB.inputText().key('user').icon('mdi mdi-account')                   // Material Design Icons
FB.inputText().key('star').icon('fa fa-star').iconPosition('right')  // FontAwesome
FB.inputText().key('logo').icon('https://cdn.example.com/icon.svg')  // URL

// Title icon
FB.title('Genel Bilgiler').icon('pi pi-info-circle')

// Section / Card gruplama
FB.form()
    .isCard(false)
    .addFields(
        FB.section('Kişisel Bilgiler').icon('pi pi-user').cols(2).addFields(
            FB.inputText().key('first_name').label('Ad'),
            FB.inputText().key('last_name').label('Soyad'),
        ),
        FB.section('Adres').icon('pi pi-map-marker').addFields(/* ... */),
    )
    .build();
```

---

## [13.5.8] - 2026-05-20

### Added

- **`AppDialog` Material Flat shell** — complete `AppDialog.vue` rewrite using PrimeVue Dialog's `#container` template. Custom header carries a gradient icon lozenge, title, subtitle, and a slate-themed close button; optional slate-100 footer (`state.footer` / `useDialog().open(..., { footer })`) renders a sticky action bar with a hint icon/text on the left and Cancel/Confirm buttons on the right. Animations switched to a custom "rise" transition.
- **`useDialog` rich-header & footer API** — `OpenOptions.subtitle`, `OpenOptions.icon`, `OpenOptions.footer` added. New `DialogFooter` interface with `icon`, `text`, `cancelLabel`, `confirmLabel`, `confirmIcon`, `severity`, `onConfirm`, `hideCancel`, `disabled`, `loading`. New `setFooter()` and `patchFooter()` methods let rendered components mutate the footer from inside the dialog (e.g. toggle the confirm button's `loading` without re-opening).
- **`stubs/resources/css/theme/_dialog.scss`** — new file imported from `theme.css`. All rules are scoped via the `sk-dlg` PT class so `ConfirmDialog` and other Dialog instances are unaffected.

### Changed

- **`preset.ts` modal token** — `borderRadius.xl` → `borderRadius.md` (6 px to match the Material Flat shell), `padding: 1.25rem` → `padding: 0` (shell-level padding handled inside `AppDialog`), drop shadow tuned to a softer dual-layer.

### Fixed

- **Dialog form scrollbar gap on the right of the footer** — long forms inside `AppDialog` showed a ~10 px white gap between the right edge of the slate-100 action bar and the dialog's right edge, because the body's scrollbar consumed content width and the bar's `-mx-8 px-8` extension only reached the body's content edge. `.sk-dlg__body:has(.sk-fb--dialog)` now hides the scrollbar visually (`scrollbar-width: none` + `::-webkit-scrollbar { width: 0 }`) so the bar lands flush against the dialog's actual edge while scroll still works via wheel / trackpad / keyboard.

### Upgrade

```bash
composer update lvntr/laravel-starter-kit

# Re-publish affected stubs (warning: customised stubs are overridden — diff first)
# stubs/resources/css/theme/{_dialog.scss,_formbuilder.scss,theme.css}
# stubs/resources/js/composables/useDialog.ts
# stubs/resources/js/theme/preset.ts
php artisan vendor:publish --tag=starter-kit-stubs --force
```

**Behavioural note:** the Dialog body's scrollbar is now intentionally invisible inside form dialogs. Scroll continues to work via mouse wheel, trackpad, arrow keys, Page Up/Down, and Home/End — only the visible scrollbar track is hidden, so the slate-100 action bar reaches the dialog edge with no gap.

---

## [13.5.7] - 2026-05-19

### Added

- **Profile vertical tabs** — `Profile/Index.vue` now sets `description()` and `iconColor()` on every tab item; `sk-profile.tab_descriptions.*` i18n keys introduced (general/password/security/sessions, TR/EN). `.sk-vtab--rich` minimum height raised to fit the description row.
- **`AvatarUpload.initials` prop** — when `avatarUrl` is null, `<AvatarUpload :initials="user.initials">` renders the user's initials in the avatar slot; falls back to the existing `pi-user` icon when not provided.

### Changed

- **`AvatarUpload` redesign** — switched from a stacked card layout to a single-row layout (avatar · title/hint · actions). Avatar circle shrunk to `size-14` with a `primary-200` border on a `primary-50` background. "Remove" is now `severity-secondary text`, "Change" is `outlined`. The `title` and `subtitle` props now distinguish three states: `undefined` → falls back to the default i18n key, any non-empty string → renders the given text, **`''` (empty string) → hides the title/hint row entirely**.
- **`sk-avatar.hint`** copy updated to a more technical format: `"JPG · PNG · GIF — max 2 MB · 512×512 recommended"` (TR equivalent in `sk-avatar.hint`).
- **Typography rebased to 14px root** — `stubs/resources/css/theme/_base.scss` sets `html { font-size: 0.875rem }` (= 14px against the browser-default 16px so a11y zoom still scales proportionally). `stubs/resources/css/theme/utilities.css` declares `--text-*` tokens in rem relative to the new 14px root (`--text-base: 1rem`). User-overridable browser font-size is now honoured again.
- **FileManager text scale rebalanced** — favourites/trash empty-state headings and the file-type filter pills moved from `text-lg` to `text-base` so they match the new root size. `sk-user-menu__item` text raised from `text-sm` to `text-base` for consistency.

### Fixed

- **Dialog sticky action bar bleed** — long forms inside `AppDialog` were leaking scrolling content out from under the sticky `Cancel`/`Update` bar. Fix is three-part:
  1. `resources/js/components/Lvntr-Starter-Kit/ui/AppDialog.vue` — Dialog `content` PT now sets `padding-bottom: 0`, `display: flex`, `flex-direction: column`.
  2. `resources/js/components/Lvntr-Starter-Kit/FormBuilder/SkForm.vue` — adds a `sk-fb--dialog` marker class when `inDialog === true`.
  3. `stubs/resources/css/theme/_formbuilder.scss` — `.sk-fb__actions` now uses an opaque `var(--p-content-background)` background; in dialog mode the bar spans edge-to-edge (`-mx-5 px-5`) and its bottom corners match the Dialog's `borderRadius.xl` via `rounded-b-xl`.

### Upgrade

```bash
composer update lvntr/laravel-starter-kit

# Re-publish affected stubs (warning: customised stubs are overridden — diff first)
# stubs/resources/css/theme/{_base.scss,utilities.css,_formbuilder.scss,_tabs.scss,_menus.scss}
# stubs/resources/js/pages/Profile/Index.vue
# stubs/resources/js/pages/Profile/components/ProfileInfoTab.vue
# stubs/lang/{tr,en}/{sk-avatar.php,sk-profile.php}
php artisan vendor:publish --tag=starter-kit-stubs --force
```

**Migration note — `AvatarUpload` title/subtitle behaviour:** if you previously passed `:subtitle=""` expecting the description to render the default hint, that no longer applies — `''` now hides the row. Remove the prop entirely (or set it to a non-empty string) to restore the previous output. Default i18n behaviour kicks in only when the prop is omitted.

---

## [13.5.6] - 2026-05-10

### Fixed

- **`stubs/app/Http/Controllers/Admin/SystemHealthController.php`** — `response()->json()` replaced with `to_api([...], $message)`; return type updated from `JsonResponse|RedirectResponse` to `ApiResponse|RedirectResponse`. Using `response()->json()` violated the SK hard rule and produced a non-standard JSON body that `useApi` could not parse (missing `{ success, data, message }` envelope).
- **`stubs/resources/js/pages/Admin/Settings/components/SystemHealthTab.vue`** — `import axios from 'axios'` removed; `useApi({ toast: false })` composable added. `axios.post<...>(url)` replaced with `api.post<...>(url)`. Direct axios usage violates the SK hard rule; all API calls must go through the `useApi` composable.
- **`resources/js/components/Lvntr-Starter-Kit/FileManager/FileManager.vue`** — `@click="busy.onCancel"` changed to `@click="() => busy?.onCancel?.()"`. `BusyState.onCancel` is typed `(() => void) | null` and `busy` itself is `BusyState | null`; vue-tsc does not narrow through `v-if` in child template event handlers, so double optional-chaining is required.

### Upgrade

```bash
composer update lvntr/laravel-starter-kit

# Re-publish affected stubs (warning: customised stubs are overridden — diff first)
# SystemHealthController.php, SystemHealthTab.vue
php artisan vendor:publish --tag=starter-kit-stubs --force
```

---

## [13.5.5] - 2026-05-08

### Changed

- **System Health moved to Settings tab.** The standalone "System Health" entry in the admin sidebar is removed; the content is now presented as a tab within the Settings page via `SystemHealthTab.vue` wrapped in a PrimeVue Card. This also removed the `system-health` route import and menu item from `useAdminMenu.ts`.
- **`stubs/app/Http/Controllers/Admin/SystemHealthController.php`** — `run()` reverted from `redirect()->route('admin.system-health.index')` back to `back()`; since System Health now lives inside the Settings page, relying on the HTTP referer is more appropriate than a hard-coded route redirect.
- **`ApiClientsManageTab.vue` & `ApiTokensManageTab.vue`** — title/subtitle and create button migrated from a manual `<header>` block to the DatatableBuilder API (`isCard(true).cardTitle(...).cardSubtitle(...)` + `tableBuilder.create({...})`). `Button` import removed; the table is now fully managed by the DB builder inside the Card.

### Fixed

- **`database/migrations/2026_05_06_100000_create_file_manager_share_revocations_table.php`** — `revoked_by_user_id` column corrected from `unsignedBigInteger` to `uuid`; the users table uses UUID primary keys, so the type mismatch caused an FK error.
- **`src/Domain/FileManager/Models/ShareRevocation.php`** — `$revoked_by_user_id` PHPDoc type updated from `int|null` to `string|null` to match the migration UUID type.
- **`src/Console/Commands/InstallCommand.php`** — auto-creates a minimal `app/Helpers/custom.php` stub (`<?php`) when the file is missing, before `composer dump-autoload`; the missing file broke every subsequent artisan call.
- **`tests/DatabaseTestCase.php`** — in-memory `revoked_by_user_id` column updated from `unsignedBigInteger` to `uuid`; aligns the test schema with the fixed migration.
- **API client `scopes` field removed.** The `scopes` column does not exist on `oauth_clients` in native Passport. The field was dead code across `StoreApiClientRequest`, `UpdateApiClientRequest`, `CreateApiClientAction`, `UpdateApiClientAction`, `ApiClientController`, `ApiClientResource`, `ApiClientForm.vue` and `ApiClientsManageTab.vue`, and caused `Column not found: 1054 Unknown column 'scopes'` on every create/update. PAT scopes (`$user->createToken($name, $scopes)`) are unaffected — they write to `oauth_access_tokens.scopes` and continue to work.
- **`passport:client --personal` now runs automatically during install.** Both `sk:install` (`InstallCommand.php`) and `site:install` (`SiteInstallCommand.php`) now execute `passport:client --personal --provider=users` automatically after `passport:keys`. The missing step caused `LogicException: Unable to determine authentication provider` on token creation in fresh installs.
- **Laravel 11 `api` guard auto-injected at runtime.** `StarterKitServiceProvider::configurePassport()` now checks for `auth.guards.api` and injects `['driver' => 'passport', 'provider' => 'users']` when absent. Laravel 11 removed this guard from the default `auth.php`; Passport's `createToken()` requires it to resolve the user provider.
- **Datatable refreshes immediately after record creation.** `ApiClientsManageTab.vue` and `ApiTokensManageTab.vue` now call `bus.refresh(REFRESH_KEY)` as soon as the `onCreated` callback fires — i.e. the moment the record is successfully created. Previously, refresh only triggered when the user clicked "I've saved it" in `OneTimeSecretModal`; closing the modal with X left the table stale.

### UI

- **`SkDatatable.vue`** — in `isCard` mode the `caption` slot now receives horizontal/top padding via `var(--p-card-body-padding)`; title and subtitle align with the standard Card body while the toolbar remains full-width.
- **`SystemHealthTab.vue`** — content wrapped in a PrimeVue `Card`; the refresh button is inlined in the `#title` slot right-aligned with `size="small"`; summary stat cards and check table placed in the Card's `#content` slot.

### Upgrade

```bash
composer update lvntr/laravel-starter-kit

# Re-publish affected stubs (warning: customised stubs are overridden — diff first)
# useAdminMenu.ts, SystemHealthController.php, SystemHealthTab.vue,
# ApiClientController.php, ApiTokenController.php, ApiClientForm.vue,
# ApiClientsManageTab.vue, ApiTokensManageTab.vue, CreateTokenModal.vue,
# OneTimeSecretModal.vue, api-client-route.php, api-token-route.php
php artisan vendor:publish --tag=starter-kit-stubs --force
```

**Passport personal access client:** If you have never run `passport:client --personal`, do so now:

```bash
php artisan passport:client --personal --provider=users
```

**Migration note:** If you published `file_manager_share_revocations` in v13.5.3, run a new migration to fix the column type:

```php
// Create a new migration:
Schema::table('file_manager_share_revocations', function (Blueprint $table) {
    $table->dropForeign(['revoked_by_user_id']);
    $table->dropColumn('revoked_by_user_id');
    $table->uuid('revoked_by_user_id')->nullable()->after('revoked_at');
    $table->foreign('revoked_by_user_id')->references('id')->on('users')->nullOnDelete();
});
```

**Clean up legacy page directories:** If upgrading from v13.5.3, manually remove the now-unused directories:

```bash
rm -rf resources/js/pages/Admin/ApiClients
rm -rf resources/js/pages/Admin/ApiTokens
rm -rf resources/js/pages/Admin/SystemHealth
```

This release introduces no new permissions, config keys or behaviour changes; the permission schema is unchanged other than the System Health tab moving into Settings.

---

## [13.5.4] - 2026-05-07

### Added

- **TabBuilder — `rose` icon color.** `TabIconColor` now accepts `rose`; `_tabs.scss` ships matching `--p-rose-*` light/dark rules. Required by the System Health tab (`pi pi-heart-fill` + rose) in Settings.

### Fixed

- **`stubs/resources/js/layouts/components/AdminHeader.vue`** — `page.props.auth?.role` (singular, non-existent field) corrected to `roles?.[0]`, matching the `roles: string[]` shared page-prop shape; fixes a vue-tsc strict-mode error.
- **`stubs/resources/js/composables/useAdminMenu.ts`** — added missing `import systemHealth from '@/routes/system-health'` and a System Health menu item (`permission: 'system.health.view'`). The page added in v13.5.3 was unreachable from the admin sidebar.
- **`stubs/app/Domain/Setting/Queries/SettingsDefaultsQuery.php`** — added `storage_usage` (`used_bytes`, `quota_bytes`) payload via the `ResolvesMediaModel` trait (`computeStorageUsed()` / `storageQuotaBytes()`). Required by the `StorageQuotaCard` component shipped in v13.5.2 to display correct data in the Settings → Storage tab.
- **`stubs/app/Http/Controllers/Admin/SystemHealthController.php`** — switched from `back()` to `redirect()->route('admin.system-health.index')`. This leaves a safe GET entry in the browser history after a POST; refreshing the page no longer re-submits the form.
- **`stubs/resources/js/pages/Admin/Logs/{Index,Show}.vue`** — `trans()` / `$t()` `count` replacement values wrapped in `String(...)`; `laravel-vue-i18n` v2.8 requires `Record<string, string>` for replacements, so raw numbers like `res.deleted.length` produced `TS2322` under vue-tsc.
- **`stubs/tsconfig.json`** — added `@lvntr/components/*` path mapping (`vendor/lvntr/laravel-starter-kit/resources/js/components/Lvntr-Starter-Kit/*`), aligned with the Vite alias. The previous single `@lvntr/*` entry could not resolve paths like `@lvntr/components/FormBuilder/core`, causing `TS2307`.
- **`stubs/resources/js/env.d.ts`** — added typed global `window.turnstile` (`declare global { interface Window { turnstile?: TurnstileInstance } }`), a `@/routes/*` wildcard module declaration as fallback before wayfinder generates files, and the `TurnstileInstance` shape.

### Build / CI

- **`stubs/vite.config.ts`** — `isWayfinderAvailable()` and `isVitest` guards added.
  - Wayfinder plugin is skipped when `artisan` is absent → fixes `php artisan wayfinder:generate` crash in node-only CI jobs (no PHP).
  - `laravel-vite-plugin` and `inertia()` are skipped when `process.env.VITEST === 'true'` or `NODE_ENV === 'test'` → eliminates the "You should not run the Vite HMR server in CI" error during `vitest run`.
- **GitHub Actions Node job re-ordered:** `npm ci` → vendor symlink (`stubs/vendor/lvntr/laravel-starter-kit` → repo root) → route stubs → `npm run build` → `npm run typecheck` → lint (`continue-on-error`) → `npm run test`. Build now generates `auto-imports.d.ts` / `components.d.ts` before vue-tsc runs.
- **`scripts/ci/generate-route-stubs.mjs`** added — node-only CI fallback that writes minimal `r(url)` stub files for 16 route modules under `stubs/resources/js/routes/`. The output directory is gitignored; host apps let wayfinder generate the real files.
- **Doctor tests** updated to expect the intentional English check messages.
- **`.gitignore`** — wayfinder routes (`stubs/resources/js/routes/`), CI-only vendor symlink (`stubs/vendor/`), and Vite build artifacts (`stubs/public/build/`, `stubs/bootstrap/ssr/`) are now ignored.

### Upgrade

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

No new permissions, migrations or config keys in this release.

---

## [13.5.3] - 2026-05-06

### Added

- **`sk:doctor` artisan command** — system health check covering 12 control points: PHP extensions, database connection, Redis, Passport keys, storage symlink, writable directories, queue driver, schedule run, mail driver, npm build artifacts, config cache, FileManager disk connection. Machine-readable output via `--json`; selective checks via `--only=database,redis,...`. Exit codes: `0` OK, `1` WARN, `2` FAIL.
- **Admin Panel — System Health page** (`/admin/system-health`) — visualises `sk:doctor` output with per-check status badges and a manual refresh button. Access permission: `system.health.view`.
- **File Manager — Signed Share Link** — HMAC-signed public access URLs. `POST /file-manager/share` creates a link with a TTL; `POST /file-manager/share/revoke` revokes it; `GET /file-manager/share/{media}?expires&signature` validates. Config keys: `file-manager.share.enabled`, `default_ttl_hours` (default 24), `max_ttl_hours` (default 720), `allow_revoke`. Revocations tracked in `file_manager_share_revocations` with a `(media_id, signed_token_hash)` composite unique index. New permissions: `share-media`, `revoke-share-media`.
- **DatatableBuilder — Bulk Action API** — `BulkAction` interface and `BulkActionDispatcher` for cross-page bulk operations. `SkDatatable` supports `select_all_filtered` mode (with filter snapshot) and cross-page selection. Request payload: `{action, ids, select_all_filtered, filter_snapshot}`; response: `{processed, skipped, failed, message}`. Shipped stubs: `BulkDeleteUserAction` (rank-aware) and `BulkDeleteRoleAction` (guards against system roles).
- **Domain Generator v2 (`make:sk-domain`) — opt-in flags** — `--with-policy`, `--with-factory`, `--with-seeder`, `--with-test`, `--with-relations` individually or combined as `--with=policy,factory,test`. `--relations="belongsTo:User,hasMany:Comment,morphTo:commentable"` generates relationship scaffolding automatically. Flag-free invocation preserves v13.5.x behaviour (backward compatible).
- **API Client & Token Admin UI** — admin interface for Passport authorization_code and client_credentials grants and Personal Access Tokens (`/admin/api-clients`, `/admin/api-tokens`). Client secrets and PATs are shown in plaintext only once on creation (`Cache-Control: no-store`); `OneTimeSecretModal` cannot be dismissed. New permissions: `api-clients.create`, `api-clients.read`, `api-clients.update`, `api-clients.delete`, `api-tokens.create`, `api-tokens.read`, `api-tokens.delete`. New validation rule: `HttpsOrLocalhostUrl` (RFC 8252 §8.3 — HTTPS only, localhost HTTP exception).
- **CI Workflow (GitHub Actions)** — PHP test (`pest`), lint (`pint`), and Node 22 build/typecheck/lint jobs. Concurrent runs on the same branch/PR are cancelled via `concurrency: cancel-in-progress`.
- `composer test` (`vendor/bin/pest tests/Feature`) and `composer lint` (`vendor/bin/pint --test`) scripts added for contributors.

### Fixed

- **`DeleteFolderAction`** — descendant folders were permanently deleted via a query-builder `forceDelete()` call, which skipped Eloquent model events. The `forceDeleted` observer in `FileFolder` (responsible for cleaning up `file_favorites`) only fired for the root folder; sub-folders left orphan favorite records. Changed to model-level iteration so every `forceDeleted` event is dispatched correctly.
- **`sk:update` — `node_modules/` filtered from stubs scan.** `node_modules/` added to `NEVER_UPDATE_PATHS`; `isNeverUpdate()` check applied to all loops in `updateModifiableFiles`, `addNewFiles`, `migrateHashRegistry` and `updateHashRegistry`. In symlinked (path-repository) setups, `stubs/node_modules/` was leaking into the candidate file list.
- **`sk:doctor` and `sk:update` console output translated to English.** All user-facing messages, tips and table headers in `DoctorCommand`, `UpdateCommand` and the 12 `DoctorCheck` classes are now in English; PHP code comments are unchanged.
- **Bulk action controllers — Inertia flash response.** `UserBulkController` and `RoleBulkController` now return `back()->with('success'/'error', ...)` instead of `ApiResponse` (JSON). The previous JSON response was breaking Inertia's `onSuccess`/`onError` flow and rendering raw JSON on screen; success/error messages now reach `SkFlash`/`useFlash` via `HandleInertiaRequests` flash sharing.
- **Bulk action validation — UUID/ULID/integer ID support.** `BulkActionRequest::rules()` updated: `ids.*` rule changed from `integer` to `string|min:1|max:64`; `prepareForValidation()` casts all incoming IDs to string. The previous `integer` rule caused "The ids.0 field must be an integer" for models using `HasUuids` (User, FileBucket, FileFolder, etc.). The new rule supports integer auto-increment, UUID (36 chars) and ULID (26 chars) primary keys in a single payload schema.

### Security

- **`dedoc/scramble`** bumped to `^0.13.22` (addresses a reported RCE-class advisory).
- **`phpseclib/phpseclib`** updated to `3.0.52` (DoS advisory; transitive via `laravel/passport`).
- **Signed Share Link — cross-media token hijack protection.** `(media_id, signed_token_hash)` composite unique index prevents a token from being valid for a different media record.
- **Personal Access Token — privilege escalation guard.** `user_id` body field is rejected; tokens are always minted for the authenticated user.
- **Passport client `confidential` enforcement.** Only `confidential=true` clients can be created via the API Client UI; authorization_code grant requires min:1 redirect URIs with HTTPS. Existing DB records are unaffected.

### Changed

- **`StarterKitServiceProvider`** — Passport scope and `Gate::before` registrations consolidated to a single source; duplicate registrations removed from the `AppServiceProvider` stub.

### Upgrade

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

## [13.5.2] - 2026-05-06

### Added

- **`Admin/Settings` — `SecurityTab.vue`** consolidates Authentication and Cloudflare Turnstile settings into one tab, replacing the removed `AuthTab.vue` and `TurnstileTab.vue` stubs.
- **`Admin/Settings/components/StorageQuotaCard.vue`** — new card component that displays disk-wide storage quota usage as a progress bar.
- **`SettingsDefaultsQuery`** now includes `storage_usage` (`used_bytes`, `quota_bytes`) in the Inertia payload.
- i18n (`sk-setting`): added `security.auth_section_title`, `security.turnstile_section_title`, `storage.usage_card_title`, `storage.usage_label`, `storage.unlimited`, `file_manager.video_label`, `file_manager.audio_label`, `file_manager.video_hint`, `file_manager.audio_hint` keys.
- i18n (`sk-file-manager`): added `labels.filter.all`, `labels.filter.image`, `labels.filter.video`, `labels.filter.pdf`, `labels.filter.audio`, `labels.filter.archive` for FileManager filter pills.
- i18n (`sk-common`): added `confirmation`, `confirm_delete_header`, `confirm_delete_message` for the `useConfirm` composable.

### Changed

- **`sk-setting.tabs.auth`** label updated to "Security".
- **`sk-setting.tab_descriptions.auth`** updated to "Registration, 2FA, verification and CAPTCHA".
- **File Manager tab** — Video/Audio upload toggles now use the same checkbox-grid layout as Images, replacing the ToggleFeatureCard design.
- **FileManager minimum text size standardised to `text-lg` (14 px).** All small text classes (`text-xs`, `text-sm`, `text-[11px]`, etc.) removed from `FileManager.vue`, `FileGrid.vue`, `FileManagerSidebar.vue` and `FileManagerStats.vue`.
- **`useConfirm` composable** — hardcoded strings replaced with `trans()` calls; `confirmDelete` title and message now come from `sk-common` translation keys.
- **`Admin/Files/Index.vue`** — unnecessary wrapper `<div>` around `<FileManager>` removed.

### Fixed

- **`TrashContentsQuery`** — the trash view now only returns root-level deleted items. Items whose parent folder was also in trash were listed as independent items, causing both single and bulk restore to fail with "Cannot restore: the parent folder is also in trash". Root-level filtering ensures restore operations always start from the top of the tree.

### Removed

- **`Admin/Settings/components/AuthTab.vue`** — content merged into `SecurityTab.vue`. `sk:update` cleans up automatically via `DEPRECATED_PATHS`.
- **`Admin/Settings/components/TurnstileTab.vue`** — content merged into `SecurityTab.vue`. `sk:update` cleans up automatically via `DEPRECATED_PATHS`.
- **`sk-setting.tabs.turnstile`** and **`sk-setting.tab_descriptions.turnstile`** i18n keys removed.
- **`sk-setting.file_manager.media_cards.*`** i18n key group removed (belonged to the old ToggleFeatureCard render; no longer used).

## [13.5.1] - 2026-05-05

### Fixed

- **NPM package `main` and `exports` paths** now reflect the actual file structure (`resources/js/components/Lvntr-Starter-Kit/...`). FileManager export added.
- **`sk:publish` individual tags** (`form`, `datatable`, `tabs`, `skeleton`, `ui`) had broken source paths referencing the old structure; corrected with the `Lvntr-Starter-Kit/` segment.
- **`vendor:publish --tag=starter-kit-components` nested path bug** resolved. Was producing `resources/js/components/Lvntr-Starter-Kit/Lvntr-Starter-Kit/...`; now publishes directly to `resources/js/components/Lvntr-Starter-Kit/`.
- **`vendor:publish --tag=starter-kit-file-manager-components`** is now active. Source path pointed to the old directory name (`file-manager`); realigned with the actual directory (`Lvntr-Starter-Kit/FileManager`).
- **`index.ts` barrel** — 9 missing component exports added: `EditorInput`, `EditorImagePicker`, `EditorColorPalette`, `TranslatableInput`, `ImageLightbox`, `FilePreviewModal`, `ToggleFeatureCard`, `MimePickerField`, `SkTag`.

### Added

- **`sk:publish --tag=filemanager`** — new tag for publishing the FileManager UI separately.
- **`sk:install --without-ai-skill`** — skip AI skill publishing (`stubs/.claude/skills/`) for consumers that don't use the Claude Code skill bundle.
- **`.gitattributes`** — Composer archive now excludes `tests/`, `docs/`, `.github/`, `plan-docs/`, `package-audit-notes/` and other development-only paths; smaller archive size.
- **`.npmignore`** — NPM package excludes `__tests__/`, `*.spec.*`, `*.test.*` (root and subdirectories; compatible with npm 11 behavior).
- **Disk-wide storage quota (`storage_quota_gb`).** A single quota in GB can be set from Admin Settings > File Manager (default 10 GB). Covers all contexts (`user`, `global`, custom morph map entries) including trash (`withTrashed`).
- **Upload quota validation.** `UploadFileRequest::withValidator()` adds a quota check; when exceeded the request returns HTTP 422 with a localised `errors.quota_exceeded` message.

### Removed

- **Duplicate vendor-owned domain commands removed from stubs:** `EnvSyncCommand`, `MakeDomainCommand`, `RemoveDomainCommand`. They continue to run from vendor as the single source. `sk:update` cleans them up in existing consumer projects via `DEPRECATED_PATHS`.
- **`App\Http\Responses\ApiResponse.php` stub removed.** A `StarterKitServiceProvider` alias guard maps `App\Http\Responses\ApiResponse` → `Lvntr\StarterKit\Http\Responses\ApiResponse` once the consumer file is deleted; existing `use App\Http\Responses\ApiResponse;` imports continue to work unchanged.
- **`Lvntr\StarterKit\Enums\PermissionEnum` removed from vendor.** Canonical location is `App\Enums\PermissionEnum` (under stubs). No vendor references existed (confirmed by grep). If your code imports this namespace directly, update it to `App\Enums\PermissionEnum`:
  > ```bash
  > grep -rn "Lvntr\\StarterKit\\Enums\\PermissionEnum" app/ src/
  > # If found: use Lvntr\StarterKit\Enums\PermissionEnum;
  > # → use App\Enums\PermissionEnum;
  > ```

### Changed

- **`sk:publish` is now the primary publish command.** Granular interactive flow with namespace rewrite support. `vendor:publish --tag=starter-kit-*` is kept for backward compatibility but `sk:publish` is now the recommended path in install and command docs.
- **`StarterKitServiceProvider::registerCommands` comment updated.** From v13.5.1, "domain commands single source" accurately reflects the reality (stubs removed).
- **`ResolvesMediaModel::computeStorageUsed()` signature changed (internal trait).** No longer accepts a parameter; returns the disk-wide total via `Media::withTrashed()->sum('size')`. Previous behavior was per-context (`model_type` + `model_id` filtered). If your app extends this trait and calls `computeStorageUsed($context)`, remove the argument: `grep -rn "computeStorageUsed" app/`.
- **`FolderContentsQuery`, `FavoritesContentsQuery`, `TrashContentsQuery`** — `stats.storage_quota` field added (bytes).
- **`FileManager.vue`** — hardcoded `STORAGE_QUOTA_BYTES` constant removed; `quotaBytes` is now computed from `stats.storage_quota`. The quota sidebar hides (`v-if="quotaBytes > 0"`) when quota is zero or undefined.

### Backward Compatibility Guarantees

No breaking changes in this release for existing consumers:

- **Existing consumer apps are unaffected.** After `composer update` + `sk:update`:
  - The 4 removed stub files (`EnvSyncCommand`, `MakeDomainCommand`, `RemoveDomainCommand`, `ApiResponse`) are cleaned up automatically via `DEPRECATED_PATHS`.
  - `App\Http\Responses\ApiResponse` imports resolve to the vendor class via the alias; no code changes required.
- **No vendor `PermissionEnum` references exist**, so the removal is not a breaking change. All consumers already use `App\Enums\PermissionEnum` (the canonical stub location).
- **`sk:publish` and `vendor:publish` behaviour** does not touch the consumer's npm/composer side; the fixes are pure bug fixes.
- **`ResolvesMediaModel`** is an internal trait so the public package API is unchanged. Host apps that directly extend this trait are affected by the signature change (see Changed note above).
- **`FolderStats.storage_quota`** is optional in the TypeScript interface (`storage_quota?: number`) — safe against older responses.
- **No DB schema changes**; no migration needed. The `storage_quota_gb` setting falls back to the config default (10 GB) without running the seeder.
- v13.5.0 BC guarantees remain in force.

### Upgrade

The following stubs were updated and can be picked up via `php artisan sk:update`:
`_03_SettingSeeder`, `FileManagerSettingsDTO`, `SettingsDefaultsQuery`, `UpdateFileManagerSettingsRequest`, `SettingsServiceProvider`, `FileManagerTab.vue`, `lang/{en,tr}/sk-setting.php`, `lang/{en,tr}/sk-file-manager.php`, `lang/{en,tr}/validation.php`.

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
```

`sk:update` output will list 4 paths under "Removed" — this is expected.

## [13.5.0] - 2026-05-05

The starter kit runtime moves entirely to vendor. FileManager backend, shared base classes, traits, helpers, middleware, ApiResponse and the route loader now live under `vendor/lvntr/laravel-starter-kit/src/` with the `Lvntr\StarterKit\` namespace. The frontend component library is also now canonical inside the package, consumed by the app via vendor symlink. Existing apps only need `composer update`; no file changes, no route names break, and `php artisan migrate` returns "Nothing to migrate". Frontend migration to vendor is fully opt-in. Upgrade instructions: [docs/UPGRADE.md](docs/UPGRADE.md).

### Changed

- **Vendor-first architecture.** Package runtime no longer flows through stubs — it runs directly from `vendor/`. `sk:install` publishes skeleton files (auth, layout, user/role/settings domain, config); it no longer copies FileManager and Shared layers into `app/`.

- **`sk:update` simplified.** No file copying for vendor runtime; `composer update` is enough. Hash-tracked stubs (auth/layout/user/role/settings) retain their existing diff/notify behaviour.

- **Frontend UI lib relocated.** `resources/js/components/Lvntr-Starter-Kit/{DatatableBuilder,FormBuilder,TabBuilder,FileManager,Skeleton,ui,index.ts}` is now the canonical package location. Apps consume it via vendor symlink.

- **`stubs/vite.config.ts` alias updated.** New installs get `@lvntr/components` pointing to `vendor/lvntr/laravel-starter-kit/resources/js/components/Lvntr-Starter-Kit` with `preserveSymlinks: true` and vendor path in the `Components({ dirs })` array.

- **`FileManagerAction` abstract base + `ResolvesMediaModel` trait.** Resolves the Media model via `media-library.media_model` config; app-specific `App\Models\Media` overrides (e.g. with SoftDeletes) work without changes.

- **`Http/Requests/FileManager/UploadFileRequest`.** Protected methods — overridable on the app side (e.g. Settings integration).

### Added

- **`src/Domain/FileManager/`** — Actions, DTOs, Queries, Services, Support `Lvntr\StarterKit\Domain\FileManager\` namespace'iyle vendor'da.

- **`src/Domain/Shared/`** — BaseAction, BaseDTO, ActionPipeline, PipeableAction `Lvntr\StarterKit\Domain\Shared\` namespace'iyle vendor'da.

- **`src/Traits/`** — HasActivityLogging, HasMediaCollections `Lvntr\StarterKit\Traits\` namespace'iyle vendor'da.

- **`src/sk-helpers.php`** — `to_api()`, `definition()`, `definitionLabel()`, `sk_locale_keys()`, `sk_default_locale()`, `format_date()` fonksiyonları `function_exists` guard'larıyla vendor'da.

- **`src/Http/Responses/ApiResponse.php`** — `{success, status, message, data, errors?}` envelope preserved, moved to vendor.

- **`src/Http/Middleware/`** — CheckResourcePermission, SecurityHeaders `Lvntr\StarterKit\Http\Middleware\` namespace'iyle vendor'da.

- **`src/Http/Controllers/FileManagerController.php`** ve **`src/Http/Requests/FileManager/*`** — vendor'da.

- **`src/Console/Commands/PurgeFileManagerTrashCommand.php`** — `file-manager:purge-trash` signature preserved.

- **`src/Exceptions/`** — ApiException, ApiExceptionHandler vendor'da.

- **`src/Facades/FileManager.php`** — single-line route mount via `FileManager::routes()`.

- **`src/routes/file-manager.php`** — 19 routes, all names preserved exactly. Consumer's own route file takes precedence.

- **`database/migrations/`** — 3 FileManager migrations, filenames and content preserved exactly.

- **`config/file-manager.php`** — `models.*` and `settings.*` keys added.

### Deprecated

- **`sk:sync` (PackageSyncCommand).** No longer needed with the Composer path-repository symlink workflow. The `--force` escape hatch is preserved.

### Backward Compatibility Guarantees

No breaking changes in this release. Guarantees for existing consumers:

1. **No helper function collisions.** Vendor helpers are guarded by `function_exists`; definitions in your `app/Helpers/sk-helpers.php` take precedence.
2. **Route names preserved.** All 19 route names — including `file-manager.contents` and `file-manager.files.upload` — are unchanged. Wayfinder regeneration produces no diff.
3. **Migration history untouched.** The 3 vendor migration filenames and contents match what existing users already have in their DB. `php artisan migrate` returns "Nothing to migrate".
4. **Config is additive.** New keys added; no existing key was removed or renamed.
5. **Frontend `@lvntr` alias untouched.** The package does not modify `vite.config.ts`.
6. **Existing consumer apps are unaffected.** Apps with their own `resources/js/components/Lvntr-Starter-Kit/` copy and their own Vite alias continue to work unchanged after `composer update`. Frontend cleanup is fully opt-in (see [docs/UPGRADE.md](docs/UPGRADE.md)).

### Upgrade

```bash
composer update lvntr/laravel-starter-kit
php artisan migrate
```

Detailed instructions and optional frontend cleanup: [docs/UPGRADE.md](docs/UPGRADE.md)

Existing `app/Domain/FileManager/`, `app/Domain/Shared/`, `app/Traits/`, `app/Helpers/sk-helpers.php` and related files stay in place and continue to work. Migrating them to the vendor versions is completely optional.

## [13.4.10] - 2026-05-04

FormBuilder now supports multi-language text fields out of the box. Three new shipped field types — `FB.translatableText()`, `FB.translatableTextarea()` and `FB.translatableEditor()` — render one input per active language and submit JSON-ready locale maps for Spatie Translatable models. Backend support ships as validation, datatable and resource helpers, and the new Sample Contents admin module demonstrates the full pattern end to end. Existing consumer apps should run `composer update lvntr/laravel-starter-kit && php artisan sk:update && php artisan migrate && npm install && npm run build`.

### Added

- **Shipped translatable field support in FormBuilder.** `FB.translatableText()`, `FB.translatableTextarea()` and `FB.translatableEditor()` render per-locale input groups driven by the active language list. Adding or removing a language in Settings adapts every translatable form on the next page load. Field-level locale filters (`onlyLocales`, `exceptLocales`), inline/tabbed layouts and locale label styles (`badge`, `name`, `flag`) are supported.

- **Shipped backend helpers for translatable attributes.** `HasTranslatableRules` generates per-locale validation rules and labels inside FormRequests. `TranslatableQueryHelpers` provides JSON-column search, locale-aware sorting and `resourceShape()` for datatable resources and edit forms. Two global helpers, `sk_locale_keys()` and `sk_default_locale()`, centralise active locale access.

- **Shipped Sample Contents reference module.** A complete admin CRUD example ships with a translatable model, migration, factory, domain actions/events/listeners, FormRequests, resource, datatable query, Vue pages, route file, menu labels and permission-resource entries. It demonstrates `title`, `description` and `content` as JSON-backed translated fields.

- **Shipped `docs/translatable-fields.md`.** The new guide covers JSON migrations, model setup, FormRequest rules, FormBuilder usage, datatable search/sort, resource output, Settings interaction, limitations and migration tips.

- **Package dependency on `spatie/laravel-translatable`.** Fresh installs get the dependency automatically; upgraded apps receive it through the package update flow and should run Composer before `sk:update`.

### Improved

- **Shipped FormBuilder docs and builder guidance.** FormBuilder documentation and project builder guidance now list the translatable builders and point to the dedicated guide.

- **Shipped File Manager no-trash documentation.** The File Manager guide now clarifies that `enableTrash=false` sends single-item deletes directly to the permanent-delete endpoint and selected-item deletes to the bulk-delete endpoint with `force_delete=true`.

### Upgrade

No API response breaking change. Existing consumer apps should run:

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
php artisan migrate
npm install
npm run build
```

Existing plain string columns are not migrated automatically. Convert them to JSON in a staged migration before adding the attribute to a model's `$translatable` array. Apps with customised language/settings flows should verify that `general.languages` contains the locale codes expected by the new fields.

## [13.4.9] - 2026-05-02

File Manager now ships the feature set that was previously visible as placeholders in 13.4.8. Favorites and Trash are real quick-access views, folder and file tiles can be starred, deleted items move to trash by default, trash items can be restored or permanently deleted, and the trash view has an **Empty Trash** action. Files can also be duplicated and renamed from the context menu. This release adds two migrations (`file_favorites` and soft deletes on `media`), new shipped backend actions/queries/requests, new File Manager routes, extended EN/TR language keys, and a daily `file-manager:purge-trash` scheduled command. Existing consumer apps should run `composer update lvntr/laravel-starter-kit && php artisan sk:update && php artisan migrate && npm install && npm run build`.

### Added

- **Shipped Favorites.** New `file_favorites` table and `FileFavorite` model store starred folders/files per owner context. `FavoritesContentsQuery` powers the sidebar **Favorites** view, `FolderContentsQuery` now annotates items with `is_favorited`, and the shipped grid/context menus expose Add/Remove Favorite actions.

- **Shipped Trash and restore flow.** Files and folders now soft-delete into Trash when `enableTrash` is true. `TrashContentsQuery` powers the **Trash** quick view, deleted tiles show their deleted timestamp, and trash context menus switch to Restore / Permanently Delete actions.

- **Shipped Empty Trash.** `EmptyTrashAction` and `DELETE /file-manager/trash/empty` permanently delete all trashed File Manager items for the current context; files are removed before folders and folders are deleted post-order so nested trees clear safely.

- **Shipped file copy and file rename.** Files can be duplicated with copy-safe names such as `photo (copy).jpg` / `photo (copy 2).jpg`, and renamed through the shipped dialog and `PATCH /file-manager/files/{media}` endpoint.

- **Shipped trash purge command.** `php artisan file-manager:purge-trash --days=7` permanently deletes File Manager trash older than the selected age. It is scheduled daily from `routes/console.php`.

- **Shipped `enableTrash` prop.** `FileManager` defaults to soft-delete behaviour; setting `:enable-trash="false"` restores immediate permanent deletion semantics for projects that do not want a trash workflow.

### Security

- **Context validation centralised.** `FileManagerContextRequest` now validates and resolves the current File Manager context consistently across virtual views and item mutations, closing gaps where favorites/trash endpoints could drift from the regular folder-content checks.

- **Soft-delete scope hardening.** Restore, permanent-delete, copy, rename and favorite actions now explicitly scope items to the current context and use `withTrashed()` / `onlyTrashed()` where needed, preventing cross-context access and ensuring trashed items are found only in the intended paths.

- **Folder restore cascade guardrails.** Restoring a trashed folder restores its descendant folders and File Manager media in a transaction. If its parent folder is still trashed, restore is refused until the parent is restored first; if the parent was permanently deleted, the item is restored to root to avoid an orphan.

### Fixed

- **Bulk force delete can now find trashed items.** `BulkDeleteAction` uses `withTrashed()` when `force=true`, so permanent deletion from the Trash view no longer misses items that are already soft-deleted.

- **Language key collision fixed.** `labels.details` is now the details-section array, while the action label moved to `labels.details_action`; this prevents the file details dialog labels from being overwritten by the context-menu action string.

- **Collection scoping tightened.** Trash purge and permanent delete affect File Manager media (`collection_name = files`) without touching avatars, logos, editor uploads or other MediaLibrary collections.

### Upgrade

No API response breaking change. Existing consumer apps should run:

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
php artisan migrate
npm install
npm run build
```

Fresh installs pick everything up through `sk:install`. Existing apps that customised File Manager stubs should compare their local files before using `sk:update --force`, especially `FileManager.vue`, `useFileManager.ts`, `FileGrid.vue`, `FileManagerController.php`, `routes/web/file-manager-route.php`, `lang/{en,tr}/sk-file-manager.php`, the new request/action/query files, and the two migrations.

## [13.4.8] - 2026-04-30

File Manager UX overhaul — the same backend, the same routes, the same media table; a new shell. The single-column grid is replaced by a sidebar + main-column layout, with three new shipped components (`FileManagerSidebar`, `FileDetailsDialog`, `FileManagerStats`), a top-bar search box that filters the current folder client-side, and an expanded right-click menu with new entries (Open in new tab, Preview, Share, Copy, Rename, Add to Favorites, Details). All previously documented behaviour — uploads, drag-and-drop move, bulk delete, image lightbox, preview dialog, custom contexts, settings, permissions — works exactly as before; the change is purely shipped frontend (`FileManager.vue` + the three new components + `types.ts` + `lang/{en,tr}/sk-file-manager.php`). No new composer or npm dependency, no migration, no config, no permission entry. Existing consumer apps run `composer update lvntr/laravel-starter-kit && php artisan sk:update && npm install && npm run build` to pick up the patches; no breaking change.

### Added

- **Shipped `FileManagerSidebar.vue` — left-rail with circular storage-usage ring, quick-access list (All Files, Recently Uploaded, Favorites, Trash), folder tree, and an inline "New Folder" button.** The storage ring uses an SVG circle with a `circumference - dashOffset` fill and a colour-band threshold (primary < 70 %, amber 70–90 %, rose ≥ 90 %); used bytes come from `fm.contents.stats.total_size`, the quota is currently a sane visual default of 10 GB until a backend setting is wired. The folder tree reuses the same `fm.tree` data the move modal already loads. Quick-access targets emit `select-quick`: **All Files** resets to the root sorted by name asc, **Recently Uploaded** resets to the root sorted by date desc, **Favorites** and **Trash** surface the new `coming_soon` toast as placeholders for an upcoming feature.

- **Shipped `FileDetailsDialog.vue` — file details modal showing Name, Type, Size, Uploaded, Folder, and (for images) Dimensions.** Image dimensions are loaded async — the dialog kicks off a hidden `new Image()` against `file.url` and pushes `naturalWidth × naturalHeight` into the rendered row when `onload` fires. The dialog ships with a "Download" footer button that re-uses the same `downloadFile` handler as the right-click menu, so the action surfaces stay aligned. Wired up from the new "Details" entry in the file context menu.

- **Shipped `FileManagerStats.vue` — top-bar stats widget with Total Files, Total Size, Folder Count, Favorites, Last Upload (relative time).** The widget renders a horizontal row of icon-tinted cards (`bg-{colour}-100` containers in light, `bg-{colour}-900/40` in dark). Folder count traverses the full nested tree (`flattenTree(fm.tree.value)`); last-upload reflects the most-recent `created_at` in the current folder, formatted as "Just now / X min / X hr / X d / locale-date" via the new `sk-file-manager.labels.stats.time_*` keys.

- **Shipped top-bar search box.** A `primevue/iconfield` + `inputicon` + `InputText` strip above the body filters `fm.contents.folders` and `fm.contents.files` by `name` / `file_name` (case-insensitive `includes`), surfaced via the new `filteredFolders` / `filteredFiles` computeds. Filter is local to the rendered folder; navigating clears it implicitly the next time `fm.loadContents()` runs.

- **Shipped expanded file context menu — Open / Preview / Download / Share / Move / Copy / Rename / Add to Favorites / Details / Delete.** "Open" now opens the file in a new tab (`window.open(file.url, '_blank', 'noopener,noreferrer')`); "Preview" keeps the existing lightbox / dialog flow; "Share" copies the absolute file URL to the clipboard (`navigator.clipboard.writeText(new URL(file.url, window.location.origin).toString())`) with a localised "Link copied" toast on success and the `coming_soon` toast on permission refusal; "Details" opens the new dialog; "Copy", "Rename", "Add to Favorites" are placeholders for upcoming features. The destructive Delete row gets a new `fm-menu-danger` class so it can be styled distinctly.

- **Shipped folder context menu — adds "Add to Favorites" (placeholder) before Delete.** Same `coming_soon` toast pattern as the file-menu placeholders.

- **Shipped `types.ts` — adds `ViewMode = 'grid' | 'list'` and `QuickView = 'all' | 'recent' | 'favorites' | 'trash'`.** `ViewMode` is reserved for an upcoming list-view renderer (currently grid-only); `QuickView` is consumed by the sidebar quick-access flow. Existing exports (`SortKey`, `SortDirection`, `FolderNode`, `FolderSummary`, `FileItem`, `FolderStats`, `FolderContents`, `FileManagerProps`, `SelectionKey`, `SelectedItem`, `PendingUpload`) are unchanged.

- **Shipped `lang/{en,tr}/sk-file-manager.php` — new keys.** Top-level: `link_copied`, `coming_soon`. New labels: `upload_new`, `preview` (moved out of the legacy hint block), `share`, `copy`, `add_to_favorites`, `details`, `search_placeholder`, `view_grid`, `view_list`, `files_section`, `folders_section`, `no_results`. New nested groups: `labels.sidebar.*` (`storage_usage`, `storage_used_of`, `quick_access`, `all_files`, `recent`, `favorites`, `trash`, `folders`, `add_folder`, `no_folders`), `labels.stats.*` (`total_files`, `total_size`, `folder_count`, `favorites`, `recent_upload`, `item_count`, `never_uploaded`, `time_just_now`, `time_minutes`, `time_hours`, `time_days`), `labels.details.*` (`title`, `name`, `type`, `size`, `created_at`, `dimensions`, `folder`).

### Removed

- **Legacy header back-button + sort dropdown removed from `FileManager.vue`.** The previous shell had a `←` back button + `Select` dropdown for sort key + a direction-toggle button in the header; navigation now happens through the sidebar (folder tree + breadcrumb) and sorting is driven by the quick-access flow ("Recently Uploaded" = `setSort('date', 'desc')`). The shipped `useFileManager` composable still exposes `setSort` / `toggleSortDirection` for direct callers.

### Upgrade

No breaking changes. Existing consumer apps run `composer update lvntr/laravel-starter-kit && php artisan sk:update && npm install && npm run build` — `sk:update` will pick up the new shipped files (`FileManager.vue`, the three new components under `FileManager/components/`, `types.ts`) and the extended `lang/{en,tr}/sk-file-manager.php` keys. The data shape on the wire (`/file-manager/contents`, `/tree`, etc.) is unchanged; backend is unchanged.

## [13.4.7] - 2026-04-26

Single-fix patch — silences the `Duplicate extension names found: ['link']` warning Tiptap printed when `EditorInput` booted. Tiptap v3's `@tiptap/starter-kit` started bundling the Link extension by default, but our editor was still pushing `@tiptap/extension-link` through the optional `props.links` branch with our own `openOnClick: false, autolink: true` config — so two `link` registrations went into the same editor. The fix is a single config flag on the StarterKit call (`link: false`) so the bundled copy is disabled and our manual-push branch stays the single source of truth. Behaviour is identical for both `props.links === false` (no Link at all) and `props.links === true` (manual-push only); only the console noise is gone. Existing consumer apps run `composer update lvntr/laravel-starter-kit && php artisan sk:update` — no migration, no config, no breaking change.

### Fixed

- **Shipped `EditorInput.vue` — duplicate Link extension warning silenced.** Tiptap v3's `@tiptap/starter-kit` bundles the Link extension by default; the editor was also pushing `@tiptap/extension-link` through the optional `props.links` branch, so the editor booted with `Duplicate extension names found: ['link']` in the console. `StarterKit.configure({ heading: { levels: [2, 3, 4] }, link: false })` disables the bundled copy so our manual-push branch (with our own `openOnClick: false, autolink: true` config) is the only source. `props.links === false` cleanly removes Link entirely; `props.links === true` runs only the manual-push branch — same effective behaviour, no warning.

### Upgrade

No breaking changes. `composer update lvntr/laravel-starter-kit && php artisan sk:update` picks up the patch — the fix ships in the same shipped Vue file `sk:update` already tracks; no extra step needed.

## [13.4.6] - 2026-04-26

Two related build/upgrade fixes that surface when consumers upgrade from a pre-`EditorInput` version of the kit (any 13.4.0 or earlier install) to 13.4.2+. The package's `package.json` is no longer declaring its `@tiptap/*` set as `peerDependencies` + `peerDependenciesMeta.optional` — those declarations were tripping Vite's optional-peer-dep stub fallback (`__vite-optional-peer-dep:@tiptap/extension-table:@lvntr/starter-kit:false`) when resolving from `vendor/lvntr/laravel-starter-kit/`, even on consumer apps that already had the deps installed at the project root. The result was `"Table" is not exported by …` at build time and `does not provide an export named 'BubbleMenu'` at runtime — both produced by Vite's stub module (`export default {}; throw …`) instead of the real package. And `sk:update` now mirrors `sk:install`'s `mergePackageJson()` step so the new `@tiptap/*` set lands in the consumer's `package.json` automatically on upgrade — previously only fresh installs picked them up, leaving every consumer who upgraded from `<13.4.2` to copy 16 dependency entries by hand. Stub-version-wins for shared keys, user extras preserved, idempotent on re-runs.

### Fixed

- **Package `package.json` — dropped `peerDependencies` + `peerDependenciesMeta` for the `@tiptap/*` set.** The package is composer-distributed (not on npm) so the peer-dep declarations had no effect on `npm install`; their only practical impact was Vite's `tryNodeResolve` fallback in `node_modules/vite/dist/node/chunks/config.js`. When a bare-import (`import { Table } from '@tiptap/extension-table'`) couldn't be resolved through the normal `node_modules` walk-up — easy to trigger when the package is in `vendor/`, not `node_modules/` — Vite checked the importer's nearest `package.json`, found the dep listed as an optional peer, and returned `__vite-optional-peer-dep:<dep>:<parent>:<isRequire>` instead of erroring. The stub is loaded as `export default {}; throw new Error("Could not resolve …")` — no named exports, hence the misleading `"Table" is not exported by …` build error and the runtime `does not provide an export named 'BubbleMenu'` for the `@tiptap/vue-3/menus` subpath. Removing the declarations restores plain `node_modules` resolution which walks up to the project root and finds the real packages.

- **Shipped `sk:update` now merges `stubs/package.json` into the consumer's `package.json`.** `UpdateCommand` previously only touched files under `app/`, `config/`, `resources/` and `routes/` — never the project's `package.json`. So the 16 `@tiptap/*` entries that 13.4.2 added to the stub never reached consumers who upgraded via `composer update lvntr/laravel-starter-kit && php artisan sk:update`. The new step (4c in `handle()`) mirrors `InstallCommand::mergePackageJson()`: stub keys win at the root, `array_merge`-d `dependencies`/`devDependencies` (sorted), user extras preserved, only writes when the rendered JSON actually differs (so re-runs are no-ops). The summary surfaces the change as `package.json (merged stub dependencies — run npm install)` so the user knows to run `npm install` afterwards.

### Upgrade

No breaking changes. Existing consumer apps run `composer update lvntr/laravel-starter-kit && php artisan sk:update && npm install && npm run build` — `sk:update` will now sync the missing `@tiptap/*` entries into your `package.json` and Vite will resolve them against the real packages instead of the stubs.

## [13.4.5] - 2026-04-26

Closes a small batch of findings from a follow-up code review of the v13.4.x surface. Two security/info-disclosure fixes (the API user list now applies the same role-hierarchy filter the admin panel does, and the role JSON `data` endpoint now runs the same `CanManageRoleQuery` guard the `edit`/`destroy` actions do), one UX fix (the 2FA enable/disable buttons now reset their loading state on failure paths), one latent-bug fix (the `v-role` directive read the wrong Inertia shared-prop key and silently always returned `false`), and one i18n cleanup (the `useApi` composable's error toasts and synthesized envelope messages now flow through `sk-message.*` keys instead of hardcoded Turkish strings). All changes are additive on the wire — same response shape, same status codes, same UI. Three regression tests guard the two security fixes. Existing consumer apps run `composer update lvntr/laravel-starter-kit && php artisan sk:update` to pick up the patches; no migration, no config, no breaking change.

### Security

- **Shipped `Api/UserController::index` now delegates to `UserDatatableQuery` — same role-hierarchy filter as the admin panel.** Previously the API used a bespoke `DatatableQueryBuilder` chain that skipped the `whereDoesntHave('roles', sort_order < me)` clause `UserDatatableQuery` enforces. Result: a non-`system_admin` API consumer holding `users.read` could `GET /api/v1/users` and see every higher-rank user — including `system_admin` accounts — whereas the admin UI hid them. The shipped controller now method-injects `UserDatatableQuery` and returns its `response($request->user())` directly. The shipped query's allowlists were extended with the `first_name`, `last_name`, `email`, `status`, `id`, `created_at` sortable keys (previously API-only) so the wire contract for legitimate API callers is unchanged.

- **Shipped `Admin/RoleController::data` now runs `CanManageRoleQuery` before returning role JSON.** `data()` is the JSON sibling of `edit()` (the admin role form prefetches it via `useApi().get('/admin/roles/{role}/data')`). `edit()` and `destroy()` already gated through `CanManageRoleQuery::check()` to enforce the role hierarchy; `data()` did not — so a lower-rank admin could read the full permission set of a higher-rank role over JSON, even though the form they would render the data into is hierarchy-aware. The check is now inlined at the top of `data()` (`abort(403)` on mismatch), mirroring `edit()`.

### Fixed

- **Shipped 2FA enable/disable buttons no longer get stuck on error.** `Profile/components/TwoFactorTab.vue` set `twoFactorProcessing = true` before calling Fortify, but only reset it on the success branch. An axios 4xx/5xx (typical: an expired session, password-confirm timing out) or an Inertia `router.reload` error left the button spinner stuck until full page reload. Both `enableTwoFactor()` and `disableTwoFactor()` now reset the flag in a `finally` block, so any failure surfaces as a re-clickable button + a toast (rather than a frozen UI).

- **Shipped `v-role` directive now reads the correct Inertia shared-prop key.** `resources/js/plugins/permission.ts` checked `auth.roles`, but `HandleInertiaRequests` shares the user role names under `auth.role_names`. The directive silently always evaluated to `false` — `<div v-role="'system_admin'">` markup was never visible regardless of the actor's role. The shipped plugin now reads `auth.role_names`. The duplicate `useCan` export inside the plugin file (which read the same wrong key) was removed too — the canonical `useCan()` lives at `@/composables/useCan` and was already correct, so application code was unaffected. The plugin file now exports only the `PermissionPlugin` (registers `v-can` + `v-role`).

- **Shipped `useApi` composable error messages flow through `sk-message.*` i18n keys.** `resources/js/composables/useApi.ts` had three hardcoded Turkish error strings (synthesized envelope on non-JSON response, network-failure toast detail, toast `summary`). Replaced with `trans('sk-message.invalid_response')`, `trans('sk-message.request_failed', { status })`, `trans('sk-message.network_error')`, `trans('sk-message.error_summary')`. The four new keys are added to the shipped `lang/en/sk-message.php` and `lang/tr/sk-message.php`. EN-locale users no longer see Turkish copy when an API call fails outside the normal envelope path.

### New

- **Shipped regression tests for the two security fixes.** `tests/Feature/Api/UserTest.php` gains the `hides higher-rank users from non-system_admin api callers` test — seeds the role hierarchy via `RoleEnum` index, mirrors `users.read` + `admin` role into the `api` guard (Spatie's `Guard::getDefaultName()` switches to `api` under `Passport::actingAs`), assigns both web + api versions of the role to an admin user, and asserts the response excludes the higher-rank `system_admin` peer + the acting `system_admin` user but includes the same-rank admin peer. `tests/Feature/Admin/RoleManagementTest.php` gains two: `forbids non-system_admin from reading higher-rank role data` (admin gets 403 on `/admin/roles/{system_admin}/data`) and `allows non-system_admin to read lower-rank role data` (admin gets 200 on `/admin/roles/{user}/data`).

### Upgrade

No breaking changes. Fresh installs pick everything up via `sk:install`; existing consumer apps run `composer update lvntr/laravel-starter-kit && php artisan sk:update` to pick up the patched shipped files: `app/Http/Controllers/Api/UserController.php`, `app/Domain/User/Queries/UserDatatableQuery.php`, `app/Http/Controllers/Admin/RoleController.php`, `resources/js/composables/useApi.ts`, `resources/js/pages/Profile/components/TwoFactorTab.vue`, `resources/js/plugins/permission.ts`, and the four added keys (`error_summary`, `invalid_response`, `request_failed`, `network_error`) in `lang/en/sk-message.php` + `lang/tr/sk-message.php`.

## [13.4.4] - 2026-04-25

Adds a maintainer-only log viewer at `/logs` for browsing, searching, and deleting Laravel log files in `storage/logs/`. Self-contained — no new composer or npm dependency, no migration, no permission entry. Visible only to `system_admin` users; everyone else still sees the same panel as before. All changes are additive. Existing consumer apps pick up the shipped Vue pages, domain layer, route file, and language keys via `php artisan sk:update`.

### Added

- **Shipped `/logs` admin section — system-admin-only log viewer.** A new sidebar item under "System" lists the contents of `storage/logs/` in an `SkDatatable` (filename, channel type, size, modified time, active flag); a per-file viewer page applies structured filters (level, date range, keyword) over a cursor-paginated entry stream. Single + bulk delete are wired through the same endpoint with partial-success semantics — active files (today's daily log, anything written within the last 5 seconds) are refused per-file and reported back in `failed[]`, the rest go through. Each delete batch dispatches a `LogFilesDeleted` event; the new `LogActivityForLogFilesDeleted` listener writes a `spatie/activitylog` entry under `log_name = system`, so deletions surface automatically in **Admin → Activity Logs**.

- **Shipped `app/Domain/Logs/` bounded context.** Four DTOs (`LogFileDTO`, `LogEntryDTO`, `LogEntryFilterDTO`, `DeleteLogFilesDTO`), two queries (`LogFileQuery` for the file list, `LogEntryQuery` for streaming entries), one action (`DeleteLogFilesAction`), one event/listener pair, and a stateless `LaravelLogParser` service. `LogEntryQuery::paginate()` reads the file with `fopen('rb')` + 64KB-capped `fgets()` and a byte-offset cursor, so memory stays bounded regardless of file size; multi-line stack traces are kept attached to the entry that opened them, and any line that appears before the first Laravel-format header (or in a file with no headers at all) surfaces as a single raw `LogEntryDTO` (`is_raw = true`, gray chip, hidden timestamp) so file content is never silently dropped. Raw entries are filtered out the moment any structured filter (level / from / to / keyword) is applied.

- **Shipped `logs.*` named route group.** `routes/web/log-route.php` ships five routes — `index`, `dtApi`, `show`, `entries`, `destroy` — wrapped in `role:system_admin`. The `{filename}` parameter constraint (`[A-Za-z0-9._-]+\.log`) is enforced on both `show` and `entries`, so path traversal and non-`.log` requests never reach the controller. The file is added to the shipped `$routesWithoutPermissionMiddleware` allowlist in `routes/web.php` because the section is role-gated, not permission-gated.

- **Shipped `lang/{en,tr}/sk-log.php` translation file.** All UI copy (filter labels, empty states, delete confirmations, failure reason codes) lives behind the `sk-log.*` namespace in both languages. New `sk-menu.logs` key labels the sidebar entry.

### Security

- **Shipped path-traversal guardrail at three layers.** The safe filename regex `^[A-Za-z0-9._-]+\.log$` is enforced in (1) the route parameter constraint, (2) `DeleteLogFilesRequest` rules, and (3) `DeleteLogFilesAction::execute()` itself (defence in depth). Anything else returns a `log.invalid_filename` failure or a 404 from the route binding — the disk path is never built from raw input.

- **Shipped active-file deletion refused.** `LogFileQuery::isActive()` flags today's daily file (`laravel-{today}.log`) and any file with an `mtime` within the last 5 seconds. `DeleteLogFilesAction` rejects flagged files per-item with `reason: 'active_file_protected'`, so a bulk submit cannot accidentally truncate the file Laravel is currently appending to.

- **Shipped `role:system_admin` route gate, no permission entry.** The viewer is intentionally **not** added to `config/permission-resources.php`. Granting an `admin` role does not unlock it; only the dedicated `system_admin` role does. Non-system-admin users get a 403 on the route and never see the menu item — the feature is invisible to them.

- **Shipped per-line read cap of 64KB.** `LogEntryQuery` calls `fgets($handle, 65536)`, so a pathological single-line entry of unbounded size cannot exhaust process memory. Long lines truncate cleanly without aborting the request.

### Upgrade

No breaking changes. Fresh installs pick everything up via `sk:install`; existing consumer apps run `composer update lvntr/laravel-starter-kit && php artisan sk:update` to pick up the new shipped Vue pages (`Admin/Logs/Index.vue`, `Admin/Logs/Show.vue`), the new domain layer under `app/Domain/Logs/`, the new admin controller (`Admin/LogController.php`), the FormRequest pair under `Http/Requests/Admin/Log/`, the new `routes/web/log-route.php`, the EN/TR `sk-log.php` translation file, the new `sk-menu.logs` key, and the `LogFilesDeleted → LogActivityForLogFilesDeleted` event-listener registration in `DomainServiceProvider`. Add `'log-route.php'` to the `$routesWithoutPermissionMiddleware` array in `routes/web.php` if your project has diverged from the shipped orchestrator.

## [13.4.3] - 2026-04-25

Brings a richer vertical tab presentation through the `TB` builder (icon tile, description line, trailing badge or check) and an opt-in upper bound on the `?per_page=` query parameter handled by `DatatableQueryBuilder`. All changes are additive; no breaking changes. Existing consumer apps pick up the new shipped TabBuilder Vue components, the rewritten `_tabs.scss`, and the EN/TR `sk-setting.tab_descriptions` keys via `php artisan sk:update`; the package-tier `max_per_page` config is picked up by `composer update`.

### Added

- **Shipped `TB.item()` rich vertical tab fluent methods.** Four new fluent methods on the shipped TabBuilder: `.description(text)` for a secondary line under the label, `.iconColor(color)` for a colored icon tile preset (13 colors: `blue`, `amber`, `emerald`, `purple`, `teal`, `red`, `indigo`, `slate`, `pink`, `orange`, `cyan`, `green`, `yellow`), `.badge(value, severity?)` for a trailing badge (5 severities: `success`, `warn`, `info`, `danger`, `secondary`), and `.checked()` for a trailing green check (overrides `badge`). Vertical-only — ignored in horizontal layout. The shipped vertical sidebar now wraps in a PrimeVue Card when `.isCard(true)` is set at the tabs level. The shipped Settings → General page uses the new API as the canonical example.

- **Package `config/starter-kit.php` — `datatable.max_per_page` ceiling + `STARTER_KIT_DATATABLE_MAX_PER_PAGE` env var.** Opt-in upper bound on `?per_page=` for `DatatableQueryBuilder`. Defaults to `100` when the config key is absent.

### Security

- **Shipped `DatatableQueryBuilder` — `?per_page=` upper bound enforced.** Previously a client could send `?per_page=99999` and force the builder to materialise an entire table into a single payload. The new ceiling silently clamps the value to `config('starter-kit.datatable.max_per_page')` (default 100) — legitimate callers under the cap are unaffected.

### Improved

- **Shipped `_tabs.scss` rewrite — modern vertical sidebar.** Card wrapper padding override, color-mix hover backgrounds, icon tile preset palette (13 colors), trailing badge and check styles. The pre-existing simple vertical layout remains accessible by omitting the new `description` / `iconColor` / `badge` fields.

### Upgrade

No breaking changes. Fresh installs pick everything up via `sk:install`; existing consumer apps can run `composer update lvntr/laravel-starter-kit && php artisan sk:update` to pick up the new shipped TabBuilder Vue components (`SkTabs.vue`, `core/builder.ts`, `core/types.ts`), the rewritten `_tabs.scss`, the EN/TR `sk-setting.tab_descriptions` language keys, and the `STARTER_KIT_DATATABLE_MAX_PER_PAGE` env var template.

## [13.4.2] - 2026-04-24

Introduces a Tiptap-based `FB.editor()` FormBuilder field (paired with a server-side `HtmlSanitizer` utility), a crypto-safe password generator on `FB.password()`, and an admin dashboard welcome message authored through the editor on **Settings → General**. File upload gains an optional `folder_name` parameter so editor-scoped uploads stay grouped, and the FileManager surfaces a dedicated `too_large` error for 413 Payload Too Large responses. All changes are additive; no breaking changes. Existing consumer apps pick up the Vue components, `HtmlSanitizer`, and language keys via `php artisan sk:update`.

### Added

- **Shipped Tiptap-based `FB.editor()` FormBuilder input.** `EditorInput.vue`, `EditorColorPalette.vue` and `EditorImagePicker.vue` land under the shipped FormBuilder stub tree. Toolbar presets (`minimal` / `standard` / `full`), bubble menu, link / image / table / task list / text align / text color / text style and placeholder extensions. Image uploads route through the FileManager context with an optional folder-grouping parameter. Translations in `lang/{en,tr}/sk-editor.php`.

- **Shipped `App\Support\HtmlSanitizer` utility.** Server-side companion to `FB.editor()`. Allowlist-based tag / attribute / URL scheme stripping; paired with the shipped `tests/Unit/HtmlSanitizerTest.php` regression suite.

- **Shipped `FB.password().generator()` — crypto-safe password generator.** Opt-in fluent method on every password field. Defaults to 16 characters, mixed case + letters + digits + symbols — strictly harder than the shipped `Password::defaults()` so every generated value passes backend validation on first submit. Enabled on the shipped admin User form out of the box. New i18n keys: `generate_password`, `password_generated`, `password_generated_detail`, `show_password`, `hide_password`.

- **Shipped admin dashboard welcome message.** Optional `general.welcome_message` setting authored through `FB.editor()` on **Settings → General**. `DashboardController` shares it as an Inertia prop; `Admin/Dashboard/Index.vue` renders it inside an `sk-prose` container. Sanitized on write (FormRequest `prepareForValidation`) and on read (controller defense-in-depth), so pre-existing hostile DB rows cannot surface.

- **Shipped `POST /file-manager/files` — optional `folder_name` parameter.** Nullable string, `max:100`, strict regex (letters / digits / space / dash / underscore only). When supplied, `UploadFileAction::ensureManagedFolder` atomically ensures a root-level folder with that name exists in the current context and stores the upload inside it. Enables editor-scoped uploads to stay grouped (e.g. every welcome-message image goes under "Welcome Message") without the pre-release pattern of a read query writing to the DB.

### Security

- **Shipped `HtmlSanitizer` — URL scheme allowlist, not blocklist.** Relative URLs plus `http://`, `https://`, `mailto:` and `tel:` are permitted; `blob:`, `data:`, `file:`, `ftp:`, `javascript:` and `vbscript:` are rejected. Flipping from blocklist to allowlist means future schemes ship safe by default.

- **Shipped `SettingService::normalizeValue()` — HTML sanitize on every write path.** `setValue()` and `setGroup()` run keys listed in a new `HTML_SAFE_KEYS` whitelist through `HtmlSanitizer::sanitize()` before hitting the database. Covers FormRequest, tinker, scheduled commands, queued jobs — no non-sanitized HTML can be persisted via the normal setting API.

- **Shipped `DashboardController::index` — defense-in-depth read sanitize.** The stored welcome message is re-sanitized on read before it reaches Inertia. Historical rows written before the write-path sanitize landed are neutralised; a drifted DB value cannot reach the browser.

- **Shipped `UploadFileAction::ensureManagedFolder` — concurrency-safe managed folder creation.** `DB::transaction` + `lockForUpdate` row-lock, `QueryException` refetch fallback for the unique-constraint race, and `withTrashed()->restore()` path for soft-deleted names. Closes the race where two parallel editor uploads could either deadlock on the same folder or resurrect a soft-deleted row and trip the unique index.

- **Shipped `UploadFileRequest` — `folder_name` input strictly validated.** New field uses `nullable|string|max:100|regex:/^[\pL\pN _-]+$/u`; path-traversal and arbitrary-character content is rejected at validation time, not downstream.

### Improved

- **Shipped `useFileManager` composable — dedicated 413 error surfacing.** HTTP 413 (Payload Too Large) responses now render the new `too_large` translation (EN + TR) instead of a generic error. Every other non-200 includes the status code in the client-side message for faster triage.

- **Shipped `FB.password()` default render path.** Default rendering is now `InputText` + a custom eye toggle. Fixes the long-standing issue where PrimeVue `<Password>`'s built-in eye icon disappeared inside `InputGroup` addons, and makes `password` / `password_confirmation` fields render identically. PrimeVue `<Password>` is still used when `.feedback()` is called (strength meter path).

### Fixed

- **Shipped `SettingsDefaultsQuery` — read path no longer writes.** The previous release's `resolveWelcomeMessageFolderId()` pass ran `FileFolder::firstOrCreate(...)` inside a pure read query. On installs with a soft-deleted "Welcome Message" folder the unique index rejected the insert and the admin saw a 500 on a settings-screen load. The folder ensure path now lives exclusively in `UploadFileAction::ensureManagedFolder` at upload time; the query is side-effect-free. The frontend `welcome_message_folder_id` Inertia prop binding was removed — the editor uses `folderName` directly.

- **Shipped `EditorInput.vue` — `blob:` URL payload leak closed.** After a completed editor image upload, the parent `v-model` is now manually synced following `setContent({ emitUpdate: false })`. Stale `<img src="blob:...">` fragments from a just-replaced upload no longer travel to the server in the submitted HTML.

### Upgrade

No breaking changes. Fresh installs pick everything up via `sk:install`; existing consumer apps can run `composer update lvntr/laravel-starter-kit --with-all-dependencies && php artisan sk:update` to pick up the new shipped files (`EditorInput.vue`, `EditorColorPalette.vue`, `EditorImagePicker.vue`, `HtmlSanitizer.php`, `passwordGenerator.ts`, `sk-editor.php`, updated `SkFormInput.vue` / `useFileManager.ts` / `UploadFileAction.php`).

## [13.4.1] - 2026-04-22

Bundles the API response envelope hardening (trace-id pipeline, centralised exception handler, leak-closing controller patches) with two new API client integrations (Postman + Apidog sync), an OAuth migration compatibility fix, and an install-time Passport personal access client provisioning step. Most changes are additive. Three behavioural breaks live in the response envelope — detailed with diffs in the consumer [UPGRADE guide](https://github.com/lvntrdev/laravel-starter-kit/blob/main/docs/UPGRADE.md). Existing installs must apply the guide; new installs pick everything up automatically.

### Security

- **Shipped controllers — `$e->getMessage()` leaks closed (11 sites).** `FileManagerController` (bulkDelete / createFolder / renameFolder / moveItem / deleteFolder / upload / deleteFile), `Api\UserController::destroy`, and `Api\Auth\AuthController::login` + `twoFactorChallenge` now throw `ApiException::*` instead of returning `to_api(null, $e->getMessage(), 4xx)`. The customer-facing message is unchanged, but the response now routes through the central handler so `trace_id` is aligned, 500+ failures are logged, and `X-Correlation-ID` is echoed. Moving away from raw `LogicException::getMessage()` closes the door on accidental internal-message leaks during future refactors.

- **Package `ApiExceptionHandler` — `abort($code, 'msg')` no longer leaks the raw message.** The `HttpExceptionInterface` branch now uses the fixed `defaultMessageForStatus()` table instead of `$e->getMessage()`. `abort(400, 'SQL error: …')` returns `"Bad request."` in the body; the internal detail only surfaces in `debug.message` while `APP_DEBUG=true`. Consumers should use `throw ApiException::badRequest('…')` for controlled messaging.

- **Shipped `Api\Auth\AuthController` returns `UserResource` instead of a raw User.** `register`, `login` (default kind), `twoFactorChallenge` and `me` now produce `data.user` via `UserResource::toArray()`. Raw Eloquent serialisation relied on `$hidden`; a future sensitive column could leak if forgotten. The resource makes the wire contract explicit.

### Added

- **Shipped API Routes page — "Sync to Postman" and "Sync to Apidog" buttons.** Admin-only buttons on the `api-routes.index` page push the current Scramble OpenAPI spec to either target via a thin controller endpoint (`POST /api-routes/postman-sync` / `.../apidog-sync`). If credentials are missing, the button redirects to the settings page. Available as `php artisan postman:sync` / `apidog:sync` on the CLI.

- **Shipped Settings → API Clients tab.** One tab, two cards (Postman + Apidog). Stores `postman.api_key`, `postman.workspace_id`, `apidog.access_token`, `apidog.project_id` in the settings table; the two secrets are encrypted at rest via the shipped `config/settings.php` `sensitive_keys` list. The previously proposed `POSTMAN_*` `.env` keys are no longer used.

- **Package `Lvntr\StarterKit\Bootstrap` — registers `AssignTraceId` on the API group.** A single `prepend([AssignTraceId::class])` against the `api` middleware group so success (`ApiResponse::toResponse`) and error (`ApiExceptionHandler`) paths share one trace id. Picked up automatically by `composer update lvntr/laravel-starter-kit`.

- **Shipped `SyncPostmanAction` + `SyncApidogAction` + shared `OpenApiExporter`.** Both actions share a single exporter that runs `scramble:export` into a per-request unique temp path under `storage/app/postman/` and cleans up in a `finally` block — CLI and admin UI can run concurrently without racing on a shared file. The spec is sent **unchanged** (no content-type rewrite), so pushed collections mirror the real server contract. Postman sync runs `import-first, delete-after`: imports the fresh collection, persists the new UID to `postman.collection_id`, then best-effort deletes the previous one — a failed push never leaves the workspace without a working collection. Apidog sync does inline `POST /v1/projects/{id}/import-openapi` with `OVERWRITE_EXISTING`. Both surfaces are UI + CLI.

- **Shipped `AssignTraceId` middleware, `ApiResponseTest` feature suite, expanded `sk:update` coverage.** `AssignTraceId.php` and `Helpers/sk-helpers.php` joined the safe-update list; `sk:update` now syncs both automatically. A 16-test / 57-assertion regression suite lives in `tests/Feature/Api/ApiResponseTest.php` covering the envelope, exception mapping, trace id agreement, 204 body, `Retry-After` propagation, `debug` gating and the sanitised `X-Correlation-ID` echo.

### Improved

- **Shipped `ApiResponse` + `sk-helpers.php` — paginator / simplePaginate fixes.** `to_api(Model::simplePaginate(15))` no longer raises a type error; `to_api(paginator, 'msg', 201)` now carries pagination meta (paginator detection moved before the 201/202 branches — silent bug in the previous release). `ApiResponse` is now `final` with a shared private `buildPaginationMeta()` helper.

- **Package `ApiExceptionHandler` — trace id unified, rate-limit headers propagated.** The handler reads `$request->attributes->get('trace_id')` set by `AssignTraceId` (falls back to `Str::uuid()` on early failures). All headers from `ThrottleRequestsException::getHeaders()` (`Retry-After`, `X-RateLimit-*`) are copied to the response so throttled clients can read the standard header instead of parsing the message string. `ModelNotFoundException` message now embeds the model name (`"User not found."` via `class_basename($e->getModel())`).

- **Package `Support\Scramble\ApiResponseExtension` — enriched schema metadata.** Each envelope field now has a definition + example + validation rule description, so the generated OpenAPI doc at `/docs/api` is more self-documenting.

- **Shipped `make:sk-domain` scaffold — template emits `throw ApiException::badRequest(...)` instead of `to_api(null, $e->getMessage(), 400)`.** Freshly generated `destroy()` controllers match the v13.4.1 pattern automatically; a shipped Pest test anchors the template output.

### Fixed

- **Shipped OAuth migrations made UUID-compatible.** `oauth_access_tokens.user_id` and `oauth_auth_codes.user_id` are now `foreignUuid`; `oauth_clients.owner_*` is now `nullableUuidMorphs`. Previously the Passport defaults (`foreignId` / `nullableMorphs` = `bigint unsigned`) clashed with the UUID `users.id` primary key shipped by the kit, surfacing as `SQLSTATE 1265: Data truncated for column 'user_id'` on the first API login.

- **Shipped `SiteInstallCommand` provisions the Passport personal access client automatically.** A `passport:client --personal --provider=users` step was added between `passport:keys` and the admin-user seed. `php artisan site:install` on a fresh clone now produces a working API token path out of the box — previously the operator had to run the passport command manually.

- **Package `ApiResponse::toResponse()` honours the `$request` parameter.** The previous implementation accepted the `Responsable::toResponse($request)` signature but ignored the argument — `AssignTraceId` integration depends on this parameter, which is now actually consumed.

- **Package `ApiExceptionHandler` — `match` ordering pinned.** `ApiException extends HttpException`, so it must be matched before `HttpExceptionInterface` — otherwise custom API exceptions would fall through to the generic abort() handling. Fragile ordering is now pinned by a comment + the shipped regression suite.

- **Package `ApiResponse` — 202 Accepted dead code removed.** The `'Operation queued.'` fallback for `to_api($data, '', 202)` never fired (the default `$message` was truthy). Helper simplified to a single logical flow.

### Breaking

Migration steps in the consumer [UPGRADE guide](https://github.com/lvntrdev/laravel-starter-kit/blob/main/docs/UPGRADE.md). Summary:

- `abort($code, 'custom message')` no longer surfaces the message — use `throw ApiException::*` instead.
- `ModelNotFoundException` message now includes the model name (`"User not found."`). Frontend regex matches may need to loosen.
- `Api\Auth\AuthController` `data.user` is limited to `UserResource::toArray()` output. If consumers depended on a raw-model field, they must extend the resource.

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
- **Friendly file manager validation messages** — `UploadFileRequest` now overrides `attributes()` and `messages()`. Each `files.{i}` slot is bound to the file's `getClientOriginalName()`, so toasts show `vacation.jpg could not be uploaded: …` instead of `files.0`. Mimetypes / max-size errors map to translation keys with a readable extension list and human-friendly size limit. New keys: `errors.upload_invalid_type`, `errors.upload_too_large`, `errors.upload_invalid_file`.

### Changed

- **Helpers reorganized** — `to_api()` and `format_date()` (plus the two new helpers) now ship from the package vendor. End-user apps no longer keep a `to_api` copy under `app/`. The new `app/Helpers/custom.php` is published into the consumer app on first install and added to the app's `composer.json` `autoload.files`; it is _never_ overwritten by `sk:update` so user code is preserved across upgrades.
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
