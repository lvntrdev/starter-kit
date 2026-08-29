---
name: lvntr-kit-frontend
description: "Enforces the Lvntr Starter Kit frontend rules. Activate when: writing or modifying Vue pages under resources/js/pages/Admin/**; using @lvntr/components/* (SkForm, SkDatatable, SkTabs, AppDialog, AvatarUpload, PageLoading); building form/table/tab configs with the FormBuilder / DatatableBuilder / TabBuilder (FB / DB / TB); calling useDialog, useConfirm, useApi, useDefinition, useRefreshBus, useCan, useFlash, useSidebar, useDarkMode, useTheme, useAccentColor, useDatatableSelection, useMenuBuilder composables; opening dialogs, refreshing tables, adding permission gating, or theming (VITE_SK_THEME). Turkish triggers: tablo ekle, form ekle, dialog aç, vue sayfası, bileşen, composable. Use when building Vue/Inertia/PrimeVue UI (forms, tables, tabs, dialogs) in a Lvntr Starter Kit app."
---

# Lvntr Kit — Frontend Skill

Reference skill for frontend components, builder APIs, and composables.
Backend layer (Action/DTO/API/route) → `lvntr-kit-domain`.
All hard rules, project shape, and command reference → `lvntr-starter-kit`.

---

## Iron Law (Frontend)

The kit breaks when any of these four rules is violated; no justification is valid.

- **Use SkForm / SkDatatable / SkTabs — do not use raw PrimeVue.** Using `DataTable`, `TabView`, or a raw `<form>` breaks the kit's builder chain.
- **Do not bypass `useDialog()` / `useConfirm()`.** Do not import PrimeVue `Dialog` directly or call native `confirm()` / `alert()`. `AppDialog` and `ConfirmDialog group="app"` are already mounted in `AdminLayout.vue`.
- **Use `useApi()` — no `import axios`.** API calls must go through the kit's CSRF-aware HTTP client.
- **Do not hardcode URLs in Vue.** Import from `@/routes/**` or `@/actions/**` and call `.url()`. Run `php artisan wayfinder:generate` after a route change.

> The numbered canonical hard rules (1-8) live only in the `lvntr-starter-kit` core; the rules above are this skill's frontend summary (core #4/#5 + the SkForm/useApi frontend additions).

---

## Triggers

The skill is active when any of these paths or symbols appears:

- `resources/js/pages/Admin/**`
- `@lvntr/components/*` — `SkForm`, `SkDatatable`, `SkTabs`, `AppDialog`, `AvatarUpload`, `PageLoading`
- `FB`, `DB`, `TB` builder objects
- `useDialog`, `useConfirm`, `useApi`, `useDefinition`, `useRefreshBus`, `useCan`, `useFlash`, `useSidebar`, `useDarkMode`, `usePageLoading`, `useTheme`, `useAccentColor`, `useDatatableSelection`, `useMenuBuilder`
- `refreshKey`, `dtApi`, `definitionOptions`, `inDialog`, `VITE_SK_THEME`

---

## §1 — FormBuilder (FB)

```ts
import { FB } from '@lvntr/components/FormBuilder/core';
import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';

const formConfig = computed(() =>
  FB.form()
    .cols(2)
    .submit({ url: products.store.url(), method: 'post' })   // OR .resource({ store, update, data, key, id? })
    .inDialog(true)                                          // dialog mode → forces the cancel emit
    .addFields(
      FB.inputText().key('name'),
      FB.inputNumber().key('price').min(0).fractionDigits(2, 2).suffix('₺'),
      FB.select().key('status').definitionOptions('productStatus'),
      FB.textarea().key('description').optional().class('col-span-full'),
      FB.fileUpload().key('image').accept('image/*').existingMediaKey('image'),
    )
    .build()
);
```

```vue
<SkForm :config="formConfig" @success="onSuccess" @cancel="onCancel" />
```

### Field types

`inputText`, `inputNumber`, `inputOtp`, `inputMask`, `datePicker`,
`select`, `multiselect`, `radio`, `selectButton`, `checkbox`, `checkboxGroup`,
`password`, `textarea`, `editor`, `toggleButton`, `toggleSwitch`,
`fileUpload`, `colorSelector`, `title`, `section`, `slot`,
`translatableText`, `translatableTextarea`, `translatableEditor`

### Chainable methods shared by every field

`.key(req)`, `.label(s|false)`, `.required(b)`, `.optional()`, `.hint(s)`,
`.visible(fn)`, `.disabled(fn)`, `.default(v)`, `.props({...})`, `.class(css)`,
`.hidden(b)`, `.groupPrefix(s)`, `.groupSuffix(s)`, `.controlPosition('left'|'right')`

Fields with options: `.optionsUrl(url|fn)` (the fn form is reactive in a cascading select)
or `.definitionOptions('key', { only?, except? })`.

If `.label()` is omitted, the label resolves automatically from `sk-attribute.attributes.{key}` —
add it to `lang/{locale}/sk-attribute.php` instead of hardcoding a string.

### Built-in SkForm guards (v13.6.8+)

- **Double-submit guard** — re-entrant submits while a request is in flight are ignored.
- **Dirty-form navigation warning** — both Inertia SPA navigation and browser
  `beforeunload`; opt out per-form with `confirmLeave: false`.
- **Load-failure retry state** — if remote form data or field options fail to
  load, the form shows a toast + in-form retry instead of failing silently.
- Required fields render `aria-required` + a screen-reader "required" hint.

---

## §2 — DatatableBuilder (DB)

```ts
import { DB } from '@lvntr/components/DatatableBuilder/core';
import SkDatatable from '@lvntr/components/DatatableBuilder/SkDatatable.vue';

const tableConfig = DB.table<Product>()
  .route(products.dtApi.url())
  .addColumns(
    DB.column<Product>().key('name').label('sk-product.name'),
    DB.column<Product>().key('price').render((row, escape) =>
      `<b>${escape(String(row.price))} ₺</b>`),          // escape: XSS prevention
    DB.column<Product>().key('status').tag('definition').tagKey('productStatus').tagOutlined(),
  )
  .addFilters(
    DB.filter().key('status').definitionOptions('productStatus'),
  )
  .addActions(
    DB.action<Product>()
      .icon('pi pi-pencil').severity('warn').label('sk-button.edit')
      .visible(() => can('products.update'))
      .handle((p) => openEdit(p.id)),
    DB.action<Product>()
      .icon('pi pi-trash').severity('danger').label('sk-button.delete')
      .visible(() => can('products.delete'))
      .handle((p) => deleteProduct(p)),
  )
  .build();
```

```vue
<SkDatatable :config="tableConfig" refreshKey="products-table" />
```

The backend endpoint `ProductDatatableQuery::response()` must return through the `DatatableQueryBuilder` chain.
`SkDatatable` expects exactly the `DataTableResponse<T>` shape — no other shape works.

`tag('definition')` colors automatically using the matching definition's `severity`.
**Always** use the `escape` callback inside `.render()` — this prevents XSS.

Bulk selection across pages goes through `useDatatableSelection()` — do not
hand-roll checkbox state. Sortable headers are keyboard-operable and the
empty state distinguishes "no results for your filter" (with a Clear-filters
action) from "no records at all" — don't reimplement either.

---

## §3 — TabBuilder (TB)

```ts
import { TB } from '@lvntr/components/TabBuilder/core';
import SkTabs from '@lvntr/components/TabBuilder/SkTabs.vue';

const tabConfig = TB.tabs()
  .vertical()
  .addTabs(
    TB.item().key('general').label('sk-setting.tabs.general').icon('pi pi-cog'),
    TB.item().key('auth').label('sk-setting.tabs.auth').icon('pi pi-shield')
      .permission('settings.update', 'settings.read'),
  )
  .build();
```

```vue
<SkTabs :config="tabConfig">
  <template #general><GeneralTab /></template>
  <template #auth><AuthTab /></template>
</SkTabs>
```

The slot name must match `tab.key` exactly. The active tab is synchronized to the URL through `queryParam` (default `tab`). Permission/role gating hides the tab completely — it does not disable it.

- Inside an `AppDialog`, call `.syncUrl(false)` on the tab config — a dialog isn't a routable page, so its tabs shouldn't fight the host page's own `?tab=` query param.
- Vertical `SkTabs` tab buttons carry `role="tab"`, not `role="button"` — select them in tests with `getByRole('tab')`, not `getByRole('button')`.

---

## §3.5 — Back button (Aura) — a standalone box is FORBIDDEN

Under the Aura theme the page title lives in the **topbar**; a boxed **"← Back"** button sitting in
its own block above the content **does not exist and is not added**. On a detail/edit page the back
button **always** lives in the first card's title-right (action) slot:

- **Tab / form page** → `SkForm`/`SkCard` `#title-end` (right of the form/card title).
- **Datatable page** → right of the datatable/card title (`#title-end`).
- **Plain page** → wrap the content in an `SkCard`; the back button goes in that card's `#title-end`.

**Mechanism:** the page passes `AdminLayout` both `:back-url="..."` **and** `:header-in-card="true"`
→ under Aura `showPageHeader` turns off (no standalone box is rendered) and the `usePageHeader()`
context becomes `active: true`.

**⚠ Inject context — the part that actually bites:** `usePageHeader()` reads `AdminLayout`'s
`provide`. Vue inject only flows **down**, so the `provide` reaches only AdminLayout's
**descendants**. The page itself — the component that renders the `AdminLayout` element, e.g.
`Account/Show.vue` — is AdminLayout's **ANCESTOR**: calling `usePageHeader()` in its own `setup`
returns **INACTIVE** (`active` stays false, no button appears, and nothing throws). So the back
button must be consumed by a **child component living inside AdminLayout's slot**:

- Tab/card content is a separate component (e.g. `ProductForm.vue`, `LoginMethodsTab.vue`) → that
  component calls `usePageHeader()` and renders `#title-end` in its own card.
- Content is inline on the page (e.g. `Account/Show.vue`'s tabs) → use the shared
  **`@/components/HeaderBackButton.vue`**; being a descendant, its inject resolves correctly:

```vue
<!-- page (Account/Show.vue) — do NOT call usePageHeader in SETUP -->
<AdminLayout :title="title" :subtitle="..." :back-url="index.url()" :header-in-card="true">
  <SkCard :title="$t('...')">
    <template #title-end><HeaderBackButton /></template>
    <!-- content -->
  </SkCard>
</AdminLayout>
```

On `SkTabs` pages the back button sits in the active tab's card (vertical layout mounts only the
active tab and unmounts it on switch; horizontal layout keeps every panel mounted and toggles
visibility instead). Omit `:header-in-card="true"` and Aura prints the standalone "← Back" box —
which is exactly what this rule forbids. Reference: `Product/Edit.vue` (component tabs),
`Account/Show.vue` (inline tab + `HeaderBackButton`).

---

## §4 — Composables

The kit composables **run from the vendor package** and resolve local-first:
`@/composables/<name>` uses a local copy when present (published via
`php artisan sk:publish --tag=composables` or customized) and otherwise falls
back to the vendor copy. Only `useAdminMenu` (menu definition) and
`usePageHeader` ship as editable app stubs.

| Composable | Short description |
|---|---|
| `useDialog()` | `dialog.open(Component, props, title, opts)`, `dialog.openAsync(Component, dataUrl, title, opts)`, `dialog.close()`. If `refreshKey` is passed in opts, the table refreshes automatically after a successful save. **Never import PrimeVue Dialog directly.** |
| `useConfirm()` | `confirmDelete(cb, message?, icon?)` and `confirmAction({...})` — `<ConfirmDialog group="app" />` is already mounted in `AdminLayout.vue`. **Never use native `confirm()`/`alert()`.** |
| `useApi()` | CSRF-aware HTTP client. `api.get<T>(url)`, `.post`, `.put`, `.patch`, `.delete`. Use when an Inertia visit is insufficient (autocomplete, file upload). `useApi({ toast: false })` disables error toasts. |
| `useDefinition()` | Cached enums from the `/definitions` endpoint. `await load(['userStatus'])`, then `list(key)`, `options(key)`, `find(key, value)`. `.definitionOptions(key)` uses it indirectly. |
| `useRefreshBus()` | Cross-component refresh. Tables register with `refreshKey`; mutations call `bus.refresh('o-key')`. `useDialog({ refreshKey })` wires this automatically. |
| `useCan()` | `can(perm)`, `canAny([perms])`, `hasRole(role)` — comes from Inertia shared props. |
| `useFlash()` | Reactive Inertia flash: `flash.value = { success?, error?, warning?, info?, status? }`. |
| `useDatatableSelection()` | Cross-page bulk selection state for `SkDatatable` bulk actions (`BulkSelectionMode`, `BulkActionPayload`). |
| `useMenuBuilder()` | Fluent builder consumed by `useAdminMenu` to declare the sidebar menu (groups, items, permissions). |
| `useSidebar()` | `{ isCollapsed, isMobileOpen, isMobile, toggle, openMobile, closeMobile }`. |
| `useDarkMode()` | `{ isDark, toggleDark }` — toggles `.dark` on `<html>` and persists to localStorage. |
| `useTheme()` / `useAccentColor()` | Runtime appearance: active theme, accent color and sidebar style (`ACCENT_COLORS`, `SIDEBAR_STYLES`); pairs with `useAppearanceDefaults()`. |
| `usePageLoading(delay = 150)` | `{ isLoading, isNavigating }` — Inertia navigation flags with an anti-flicker delay. |
| `useUrlTab(tabs, paramName?)` | Manual URL↔tab synchronization. TabBuilder handles this internally; needed only for custom tabs. |

---

## §5 — Frontend recipe (steps 14-16 when adding an entity)

The complete step sequence is in `lvntr-kit-domain` (backend steps 1-13). The following is only the frontend portion.

**Step 14 — Index.vue**

```vue
<!-- resources/js/pages/Admin/Products/Index.vue -->
<script setup lang="ts">
import { DB } from '@lvntr/components/DatatableBuilder/core';
import SkDatatable from '@lvntr/components/DatatableBuilder/SkDatatable.vue';
import { useDialog } from '@/composables/useDialog';
import { useCan } from '@/composables/useCan';
import { products } from '@/routes/products';
import ProductForm from './components/ProductForm.vue';

const { can } = useCan();
const dialog = useDialog();

const tableConfig = DB.table<Product>()
  .route(products.dtApi.url())
  .addColumns(/* ... */)
  .addActions(
    DB.action<Product>()
      .icon('pi pi-pencil').severity('warn').label('sk-button.edit')
      .visible(() => can('products.update'))
      .handle((p) => dialog.open(ProductForm, { id: p.id, inDialog: true }, 'Edit Product', { refreshKey: 'products-table' })),
  )
  .build();
</script>

<template>
  <SkDatatable :config="tableConfig" refreshKey="products-table" />
  <Button v-can="'products.create'" @click="dialog.open(ProductForm, { inDialog: true }, 'New Product', { refreshKey: 'products-table' })" />
</template>
```

**Step 15 — ProductForm.vue**

```vue
<!-- resources/js/pages/Admin/Products/components/ProductForm.vue -->
<script setup lang="ts">
import { FB } from '@lvntr/components/FormBuilder/core';
import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
import { products } from '@/routes/products';

const props = defineProps<{ id?: string; inDialog?: boolean }>();

const formConfig = computed(() =>
  FB.form()
    .cols(2)
    .inDialog(props.inDialog ?? false)
    .resource({ store: products.store.url(), update: products.update.url(), data: products.show.url(), key: 'product', id: props.id })
    .addFields(
      FB.inputText().key('name'),
      FB.inputNumber().key('price').min(0).fractionDigits(2, 2).suffix('₺'),
      FB.select().key('status').definitionOptions('productStatus'),
    )
    .build()
);
</script>

<template>
  <SkForm :config="formConfig" @success="$emit('success')" @cancel="$emit('cancel')" />
</template>
```

**Step 16 — Dialog wiring and refresh**

```ts
// Opening a dialog
dialog.open(ProductForm, { inDialog: true }, 'New Product', { refreshKey: 'products-table' });

// Dialog with async data (edit flow)
dialog.openAsync(ProductForm, products.show.url(id), 'Edit Product', { refreshKey: 'products-table' });

// Manual refresh (after a mutation outside the dialog)
const bus = useRefreshBus();
bus.refresh('products-table');
```

---

## §6 — Frontend pitfalls

1. **Hardcoded URL** — `fetch('/api/products')` breaks Wayfinder typing. Import from `@/routes/products` and call `.url()`. Do not forget `php artisan wayfinder:generate` when a route changes.
2. **Custom datatable shape** — `SkDatatable` expects exactly `DataTableResponse<T>`. The backend endpoint must always go through the `DatatableQueryBuilder` chain; a hand-shaped response does not work.
3. **Hardcoded label** — use a translation key (`'sk-product.name'`) instead of `.label('Product Name')`. A missing key resolves automatically from `sk-attribute.attributes.{key}`.
4. **Missing `refreshKey`** — every table with a mutation dialog must set both `<SkDatatable refreshKey="...">` AND `useDialog({ refreshKey: '...' })`. If either is omitted, the table does not refresh after saving.
5. **Skipping `wayfinder:generate`** — if generation does not run after adding or changing a route, Vue imports break or resolve to the old URL.
6. **Editing `resources/css/theme/_active.css`** — it is generated by the `skTheme()` vite plugin. Theme changes go into the `resources/css/theme/{main,custom}/` slot tree (`VITE_SK_THEME` selects the active theme).

---

## Hard rule reminder (when this skill triggers directly)

The full list is in `lvntr-starter-kit` §1 (8 rules). The critical frontend rules are #1, #2, #4, #5:

- **#1** Do not edit `vendor/lvntr/laravel-starter-kit/`
- **#2** Do not edit auto-generated files (`wayfinder/routes/actions`, `*.d.ts`, `_ide_helper*`, `.phpstorm.meta.php`, `_active.css`)
- **#4** Do not bypass `useDialog()`/`useConfirm()` (no direct PrimeVue `Dialog`, no native `confirm()/alert()`)
- **#5** No hardcoded URLs in Vue → `@/routes`/`@/actions` `.url()` + `wayfinder:generate`

---

## Cross-ref

Works with `lvntr-starter-kit` (core: all 8 hard rules, project shape, command reference, permissions, i18n).
The same entity's backend (Action / DTO / API / route) → `lvntr-kit-domain`.

---

## Bottom Line

For forms, `FB` + `SkForm`; for tables, `DB` + `SkDatatable`; for tabs, `TB` + `SkTabs`.
Dialog with `useDialog()`, confirmation with `useConfirm()`, HTTP with `useApi()`, refresh with `useRefreshBus()`.
No hardcoded URLs; `@/routes/**` + `.url()`. Theme edits go into the slot tree, never into `_active.css`.
