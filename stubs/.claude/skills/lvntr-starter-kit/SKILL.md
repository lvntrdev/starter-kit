---
name: lvntr-starter-kit
description: "Use this skill whenever working in a Laravel project that has the Lvntr Starter Kit (lvntr/laravel-starter-kit) installed. ALWAYS activate when: writing or modifying controllers under app/Http/Controllers/Admin, Api or Service; adding business logic under app/Domain/; creating FormRequests, API Resources, Actions, DTOs, Queries, Events, Listeners; touching routes/web/*-route.php or routes/api/*-route.php; building Vue pages under resources/js/pages/Admin/**; using @lvntr/components/* (SkForm, SkDatatable, SkTabs, AppDialog, AvatarUpload, PageLoading); calling FB/DB/TB builders or composables (useDialog, useConfirm, useApi, useDefinition, useRefreshBus, useCan, useFlash, useSidebar, useDarkMode, useTheme, useAccentColor, useDatatableSelection, useMenuBuilder); editing lang/{locale}/sk-*.php translations; running sk:install / sk:update / sk:publish / sk:upgrade / sk:doctor / sk:eject / make:sk-domain / remove:sk-domain / sk:seed-permissions / site:install / env:sync / file-manager:purge-trash / encryption:key / encryption:rekey / encryption:health; updating config/permission-resources.php, config/starter-kit.php, config/settings.php; working with the file manager, activity log, definitions, settings panel, or theme system (VITE_SK_THEME); or when the user mentions: starter kit, sk-, ApiResponse, to_api, ApiException, BaseAction, BaseDTO, ActionPipeline, DatatableQueryBuilder, RoleEnum, PermissionEnum, definitionOptions, refreshKey, dtApi, eject, vendor-first. Also triggers on Turkish: yeni domain, domain ekle, tablo ekle, form ekle, dialog aç. Enforces the kit's hard rules and upgrade-safety, and routes builder/domain detail to the lvntr-kit-frontend / lvntr-kit-domain skills."
---

# Lvntr Starter Kit — Core Gate

This project is built on top of **Lvntr Starter Kit** (`lvntr/laravel-starter-kit`).
The kit ships an admin-first scaffolding for Laravel 13 / Inertia v3 / Vue 3 /
PrimeVue 4 / Tailwind 4: authentication (Fortify + Passport), users / roles /
permissions, activity & audit logs, settings panel, file manager, definitions,
API documentation (the `/api-dock` panel via `lvntr/api-dock`), a slot-based
theme system, a fluent Vue component library (FormBuilder, DatatableBuilder,
TabBuilder), and a DDD-flavored domain layer.

The kit is **vendor-first** (v13.6.0+). Three things matter:

1. **Your app owns the HTTP layer, Models, Policies, routes, Vue pages, and
   config** — plus the `User`/`Role` domains, which are auto-ejected on a
   fresh install.
2. **Module runtimes, kit middleware, kit migrations, kit translations, and
   the composables run from the vendor package** and resolve through
   `class_alias` / local-first resolvers — **a local copy in your app always
   wins**. Take full ownership of a module with `php artisan sk:eject {Domain}`
   (trade-off: no more upstream updates for it).
3. **The package itself stays untouched** (`vendor/lvntr/laravel-starter-kit/`)
   — it is upgradable via `composer update` + `php artisan sk:update`.

> **Online docs:** [starter-kit.lvntr.dev](https://starter-kit.lvntr.dev/) — full
> reference, API tables, screenshots. Use this skill for the day-to-day rules.

---

## Iron Law

```
Never touch vendor/ or auto-generated files; never bypass the envelope/dialog/URL/Action rules;
never finish without pint, and never edit a committed migration.
```

Full list: the **Hard rules** section below.

---

## Red Flags — STOP

Stop the moment any of these thoughts appears:

- "A small patch in vendor/ will do" — STOP. `vendor/` is untouchable; use `sk:publish`, `sk:eject`, or an `app/` override.
- "`response()->json()` is faster" — STOP. `to_api()` / `ApiResponse::*` is mandatory; the envelope cannot be bypassed.
- "I'll use `confirm()` for now" — STOP. `useConfirm()` is mandatory; native `confirm()/alert()` is forbidden.
- "I'll hardcode the URL and fix it later" — STOP. No URL is ever hardcoded in Vue; `@/routes/**` + `.url()`.
- "I'll run the Wayfinder regen later" — STOP. `wayfinder:generate` runs immediately after a route change.
- "Two lines of logic in the controller won't hurt" — STOP. Business logic lives under `app/Domain/{Entity}/Actions/`; controllers stay ~5 lines.
- "I'll hand-edit the auto-generated file, it's a small fix" — STOP. `wayfinder/routes/actions/`, `*.d.ts`, `_ide_helper*`, `_active.css` are never edited by hand.
- "Fixing the committed migration is quicker" — STOP. Add a new migration; a committed one is never edited.
- "I'll edit the ejected copy AND expect vendor updates" — STOP. Ejecting is a one-way trade: ownership instead of upstream updates.

---

## Rationalization Prevention

| Excuse | Reality |
|---|---|
| "The vendor/ change is tiny, composer won't lose it" | Every patch is wiped on `composer update`; it silently breaks on the next upgrade. |
| "`response()->json()` produces the same output as the envelope" | The exception handler manages the envelope only through `ApiResponse`; `response()->json()` skips trace_id, error mapping and status normalization. |
| "Importing PrimeVue `Dialog` directly is less boilerplate" | It bypasses the single `<AppDialog />` mount in `AdminLayout.vue`; z-index, focus trap and destroy lifecycle break. |
| "Hardcoding one URL makes no difference" | When the route name or params change you get a runtime 404 instead of a TypeScript error; it creates refactor blindness. |
| "If I skip pint the pre-commit hook will catch it anyway" | The hook rejects the commit; fixing with `--amend` pollutes the previous commit. Run pint first. |
| "I'll customize the vendor-resident class by editing vendor" | Publish or eject instead — a local copy in `app/` always wins via the alias-skip invariant. |

---

## 0. When to apply

- Any work under `app/Domain/`, `app/Http/Controllers/{Admin,Api,Service}/`,
  `app/Http/Requests/`, `app/Http/Resources/`, `routes/web/*-route.php`,
  `routes/api/*-route.php`, `resources/js/pages/Admin/**`,
  `resources/js/components/Lvntr-Starter-Kit/**`, or `lang/{locale}/sk-*.php`
- Adding a new entity (use `php artisan make:sk-domain Foo`)
- Building a form / table / tab / dialog (use FB / DB / TB builders, never
  PrimeVue Dialog directly)
- Returning JSON from an API controller (use `to_api()` or `ApiResponse::*`)
- Adding a permission (edit `config/permission-resources.php`, run
  `sk:seed-permissions --fresh`)
- Customizing a published Vue component or composable (run `sk:publish` first)
- Taking ownership of a kit module (`sk:eject {Domain}` — read the trade-off)
- Upgrading the kit (`composer update`, then `sk:update --dry-run`, then `sk:update`)
- Diagnosing environment/config issues (`sk:doctor`)

## When NOT to apply

- Pure Laravel work that the kit doesn't touch (queues, mail, scheduling) —
  use Laravel Boost / `laravel-best-practices` instead
- Pest test writing — use `pest-testing`
- Inertia/Vue patterns unrelated to the kit's components — use
  `inertia-vue-development`

If in doubt, apply this skill anyway — it overlaps with the others, but its
rules about *what not to touch* are kit-specific.

---

## 1. Hard rules (these break the kit if ignored)

1. **Never edit `vendor/lvntr/laravel-starter-kit/`.** The kit is upgradable —
   patches there vanish on `composer update`. To change kit code:
   (a) override via your `app/` layer (a local copy always wins),
   (b) publish the asset with `sk:publish`, or
   (c) take domain ownership with `sk:eject`.
2. **Never edit auto-generated files.** Each is regenerated by build/composer:
   - `resources/js/{wayfinder,routes,actions}/`
   - `auto-imports.d.ts`, `components.d.ts`
   - `_ide_helper.php`, `_ide_helper_models.php`, `.phpstorm.meta.php`
   - `resources/css/theme/_active.css` (generated by the `skTheme()` vite plugin)
3. **Never use `response()->json()` in API controllers.** Use `to_api(...)` or
   `ApiResponse::*`, throw `ApiException::*` for errors. The standard envelope
   is enforced by the exception handler installed by the kit.
4. **Never bypass `useDialog()` / `useConfirm()`** by importing PrimeVue
   `Dialog` or using `confirm()/alert()`. The kit's `<AppDialog />` and
   `<ConfirmDialog group="app" />` are mounted in `AdminLayout.vue`.
5. **Never hardcode URLs in Vue.** Import from `@/routes/**` or
   `@/actions/**` and call `.url()`. Run `php artisan wayfinder:generate`
   after route changes.
6. **Never put business logic in controllers.** Push it into an Action under
   `app/Domain/{Entity}/Actions/`. Controllers stay 5-line thin.
7. **Run `vendor/bin/pint --dirty --format agent`** before finishing any PHP
   change. The pre-commit hook will reject otherwise.
8. **Never edit a committed migration** — add a new one. Two-step destructive
   changes (write, then drop in a follow-up migration) keep production safe.
9. **Never re-run `sk:install` on an installed app.** It is a first-install
   command, not a repair tool: it stops fail-closed when it detects the kit is
   already there without a hash registry. Use `sk:update`, a scoped
   `sk:publish --tag=<area>`, or `sk:install --adopt` (rebuilds
   `storage/starter-kit/hashes.json` only — copies no file, runs no migration,
   never touches `.env`). Never suggest `--force` to get past the stop, and
   never suggest the installer's `migrate:fresh` branch on a database that
   holds data.

---

## Domain layer & API envelope

Action/DTO/Query patterns, the `to_api` / `ApiException` envelope rules and
the add-an-entity recipe: **`lvntr-kit-domain`** skill.

Building a form/table/Vue page? → **`lvntr-kit-frontend`**.
Domain/controller/API work? → **`lvntr-kit-domain`**.

---

## Frontend builders & composables

The full FormBuilder/DatatableBuilder/TabBuilder API, the composables
reference and the Vue page recipe: **`lvntr-kit-frontend`** skill.

---

## 7. Permissions

Permissions are **declarative**, not hand-rolled. You declare resources and
abilities, and dynamic middleware resolves a route name like `admin.products.index`
to the permission `products.read`. In **production an unmapped route is
denied**; in non-production it warns and allows.

### Adding a new resource

1. Edit `config/permission-resources.php`:
   ```php
   'products' => [
       'label' => 'sk-product.product',
       'abilities' => ['read', 'create', 'update', 'delete'],
   ],
   ```

2. Re-seed:
   ```bash
   php artisan sk:seed-permissions --fresh
   ```

### Roles (already seeded)

- `system_admin` — bypasses all gates (via `Gate::before` in StarterKitServiceProvider)
- `admin`
- `user`

### Frontend gating

```ts
const { can, canAny, hasRole } = useCan();

if (can('products.update')) { … }
if (canAny(['products.update', 'products.delete'])) { … }
if (hasRole('system_admin')) { … }
```

```vue
<Button v-can="'products.create'" label="New" />
<section v-role="'admin'">…</section>
```

The `v-can` / `v-role` directives resolve local-first from
`resources/js/plugins/` and otherwise run from the vendor package
(`sk:publish --tag=plugins` recreates an editable copy).

### Backend gating

The `check.permission` middleware runs automatically on routes loaded via
`routes/web/*-route.php` and `routes/api/*-route.php`. You don't add it
manually — adding the resource to `permission-resources.php` is enough.

---

## 8. Translations & validation messages

The kit's 44 `sk-*` translation files (EN + TR) run **from the vendor
package** with precompiled frontend JSON. Your app's `lang/` files override
them **per key** — app keys always win; missing keys fall back to the vendor
default. `lang/{locale}/validation.php` stays app-owned.

| File / namespace | Use for |
|---|---|
| `validation.php` `attributes` block (app-owned) | field labels |
| `sk-attribute.php` | field labels (kit namespace) |
| `sk-button.php` | button labels |
| `sk-message.php` | flash messages (created/updated/deleted/etc.) |
| `sk-common.php` | shared UI strings |
| `sk-{entity}.php` | per-entity strings (`sk-user`, `sk-role`, `sk-file-manager`, …) |

Frontend access: `$t('sk-message.created', { entity: $t('sk-user.user') })`
Backend access: `__('sk-message.created', ['entity' => __('sk-user.user')])`

To customize kit strings wholesale: `php artisan sk:publish --tag=lang`.

### Field label auto-resolution

If you omit `.label()` on a FormBuilder field, the label resolves to
`validation.attributes.{key}`. Add the attribute to the `attributes` array in
`lang/{locale}/validation.php` instead of hardcoding labels. Same for
DataTable column and filter labels.

### Build step

`npm run build` compiles app PHP translations and merges them over the
vendor-precompiled JSON. Only the active locale is lazy-loaded at runtime.

---

## References (detail — read on demand)

This skill is a lean gate; heavy reference is read on demand:
- Project file shape after `sk:install` (vendor-first layout, what is app-owned) → `references/project-shape.md`
- Command reference (install incl. `--adopt`/first-install-only rules, update/publish/upgrade/doctor/eject, make:sk-domain incl. `--with=`, seed-permissions, purge-trash, encryption key/rekey/health, api-dock sync/diff/export/agent-guide, site:install, env:sync, wayfinder:generate) → `references/commands.md`
- Built-in modules (file manager, activity & audit log, settings panel, definitions, OAuth/Passport, API documentation (`/api-dock`), theme system) → `references/modules.md`
- Safe update flow (`sk:update`, hash registry, SAFE_UPDATE vs NEVER_UPDATE vs vendor-resident) → `references/update-flow.md`
- "Where to look" lookup table (which pattern lives in which file) → `references/lookup.md`

---

## Skill bridges

- Domain/controller/API layer → **`lvntr-kit-domain`**
- Frontend builder/composable/Vue page → **`lvntr-kit-frontend`**

These skills are published to both `.claude/skills/` (Claude) and
`.codex/skills/` (Codex). The `.codex` copies are a **generated mirror** —
edit the `.claude` copies; `sk:install`/`sk:update` re-sync the mirror.

---

## Bottom Line

The kit's upgrade safety rests on the eight hard rules. `vendor/` and
auto-generated files are never edited under any circumstances. The API
envelope, the dialog system, URL management and the Action layer cannot be
bypassed. PHP changes end with pint; committed migrations are never edited.
Customization goes through `app/` overrides, `sk:publish`, or `sk:eject` —
never through vendor. These rules are non-negotiable.
