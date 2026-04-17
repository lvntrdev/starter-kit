# Changelog

All notable changes to `lvntr/laravel-starter-kit` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [13.3.0] - 2026-04-18

### Added

- **Cloudflare Turnstile captcha** — login, register and password-reset flows can now be protected by Turnstile. Ships with a `turnstile` middleware alias (`ValidateTurnstile`), a `TurnstileRule` for FormRequest validation, `TurnstileSettingsDTO`, a `TurnstileWidget.vue` (mounted by the auth pages), and a **Settings → Turnstile** admin tab. Site key / secret key are managed from the UI.

- **Last login tracking** — `UpdateLastLogin` listener on the `Illuminate\Auth\Events\Login` event writes `last_login_at` and `last_login_ip` to the user. Surfaced on user detail pages and in the users datatable.

- **Inactive user block on login** — `FortifyServiceProvider` now rejects the login attempt when the user's status is not `active`, returning a clear error instead of starting a session. Admins can suspend accounts without deleting them.

- **`BaseFormRequest`** — shared parent for every admin/API FormRequest. Centralises `authorize()` defaults, Turnstile guard wiring, and attribute translation. All shipped FormRequests have been migrated to extend it.

- **`SkAttributeTranslationLoader`** — resolves `sk-attribute.{field}` keys for validation error messages (with sensible fallbacks), wired globally via `AppServiceProvider`.

- **`FormBuilder.trans(bool)`** — new fluent method on every field builder that controls whether the label is treated as a translation key (default `true`) or as a pre-resolved raw string (`.trans(false)`). Use `.trans(false)` when supplying `trans('admin.example')` or any already-translated value; the form template then renders it verbatim instead of running `$t()` on it again. Default behaviour unchanged — existing code is not affected.

- **`FilePreviewModal` + `ImageLightbox`** — file previews in both the file manager and form file-upload fields now open in-app instead of a new browser tab. Images render inside a Google-Drive-style fullscreen overlay (`ImageLightbox` — backdrop blur, ESC to close). PDF, video, audio and text files render inside a mime-aware dialog (`FilePreviewModal`) with a built-in "Open in new tab" escape hatch for unsupported formats. Register the global overlay by adding `<ImageLightbox />` next to `<AppDialog />` in your admin layout.

- **`MimePickerField`** — replaces the accepted-mime-types multiselect dropdown in **Settings → File Manager** with a categorized card-checkbox grid (Images / Documents / Archive), each option showing its file-type icon. Easier to scan than the dropdown list.

- **`ToggleFeatureCard`** — new UI primitive for boolean feature flags. Shows a coloured icon, a bold label and a helper description next to a toggle switch, styled to match the `MimePickerField` cards. Used by the "Video uploads" and "Audio uploads" toggles in the file-manager settings.

### Changed

- **Shipped translations now carry an `sk-*` filename prefix** — every `stubs/lang/{locale}/*.php` has a `sk-` counterpart (`sk-admin.php`, `sk-auth.php`, `sk-button.php`, `sk-datatable.php`, `sk-menu.php`, `sk-setting.php`, `sk-user.php`, …). All shipped Vue pages and PHP code now reference the new keys (`__('sk-button.save')` instead of `__('button.save')`), so consumer apps can freely own the unprefixed namespace.

- **FileManager actions** — consistent response envelopes and captcha-aware request validation.

- **`SettingsDefaultsQuery`** — now returns Turnstile defaults alongside existing sections.

- **File-upload field preview UX** — in `SkFormInput` the existing-media thumbnails and newly-selected file previews no longer open in a new tab. Click now routes to the lightbox (images) or the preview modal (everything else). The file-name text next to each thumbnail became a `<button>` instead of an `<a>`; styling was updated to keep the link-like appearance.

### Fixed

- **Upload validation rejected `.ogg` video and `.avi` files** — `UploadFileRequest`'s `allow_video=true` branch only whitelisted `video/mp4`, `video/webm`, `video/quicktime` and `video/x-matroska`. Added `video/ogg`, `video/x-msvideo` and `video/avi`, plus the matching extension labels (`.ogv`, `.avi`) shown in the error message's "Allowed types" list.

### Removed

- **Legacy unprefixed translation stubs** — `stubs/lang/{en,tr}/{admin,auth,button,common,datatable,enums,file-manager,message,pagination,passwords,validation}.php` (21 files) are no longer shipped. The application-side code (Vue pages, FormRequests, `SkAttributeTranslationLoader`) has fully moved to the `sk-*` keys, so these files were orphans in fresh installs. The legacy **package-level `starter-kit::` namespace is untouched** — `resources/lang/` inside the package still loads the original files, so `__('starter-kit::admin.menu')` calls keep resolving.

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
