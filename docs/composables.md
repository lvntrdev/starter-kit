# Composables

Kit composables are shipped inside the package and run directly from the vendor library by default — they no longer need to be copied into the consumer app. Imports throughout the application use `@/composables/<name>` (or the bare `@/composables` barrel) exactly as before; a Vite `customResolver` plus a matching tsconfig path entry resolve those paths **local-first, then vendor**: if a file exists under the consumer's `resources/js/composables/` it wins, otherwise the vendor copy is used automatically.

**`useAdminMenu`** and **`usePageHeader`** are the only composables that still ship as editable stubs (`resources/js/composables/useAdminMenu.ts`, `resources/js/composables/usePageHeader.ts`). `useAdminMenu` depends on the consumer's generated `@/routes/*` files and is the project's own menu definition, so it must remain editable. `usePageHeader` defines the page-header context that `AdminLayout.vue` (itself a stub) provides and that form pages such as `UserForm.vue` consume, so it ships alongside the layout as an editable stub. The `@/composables/index.ts` barrel also stays as a stub.

### Upgrading composables via Composer

Because the kit composables live in the package, they are updated when you run `composer update lvntr/laravel-starter-kit`. No manual file copying is required.

### Publishing composables for customization

To edit a composable, publish it to the consumer app first:

```bash
php artisan sk:publish --tag=composables
```

This copies the current vendor versions into `resources/js/composables/`. Once a local copy exists the local-first resolver picks it up automatically — no alias changes or build config edits are needed.

### Migration note for existing installs

Projects created before this change already have all composables under `resources/js/composables/`. The local-first resolver means those local copies continue to be used, so **nothing breaks**. The trade-off is that those projects will not receive upstream composable fixes via `composer update` until the unmodified files are removed. To opt into vendor-managed upgrades, delete the composables you have not customized from `resources/js/composables/` — keeping `useAdminMenu.ts`, `index.ts`, and any file you have intentionally edited. The kit never auto-deletes local files.

## Commonly Used Composables

- `useApi` for JSON requests using the API response envelope
- `useCan` for permission and role checks from Inertia shared props
- `useDefinition` for cached definition loading
- `useDialog` for dialog state and remote-loading flows
- `useImageLightbox` for fullscreen image preview from FileManager and file-upload fields
- `useConfirm` for confirmation actions
- `useFlash` for flash message handling
- `useDarkMode` for dark mode persistence
- `useTheme` for applying the active runtime theme (`main`/`aura`)
- `usePageLoading` for Inertia loading state
- `useRefreshBus` for forcing table or widget refreshes
- `useSidebar` for responsive sidebar state
- `useUrlTab` for tab state synced to the URL
- `useAdminMenu` and `useMenuBuilder` for admin navigation composition
- `usePageHeader` for the back-button page-header context shared between `AdminLayout` and form pages

## Core Request and Dialog Helpers

### useApi()

Small `fetch()` wrapper for the project's `to_api()` / `ApiResponse` JSON envelope.

- Adds `Accept: application/json` and `X-Requested-With: XMLHttpRequest`
- Adds `X-XSRF-TOKEN` when available
- Unwraps the `data` payload
- Throws `ApiError` on failed responses
- Can show PrimeVue toast errors unless `toast: false` is passed

```ts
const api = useApi();

const user = await api.get<User>('/api/v1/users/1');
await api.post('/api/v1/users', { name: 'John Doe' });
await api.put('/api/v1/users/1', { name: 'Jane Doe' });
await api.patch('/api/v1/users/1', { status: 'active' });
await api.delete('/api/v1/users/1');
```

### useConfirm()

PrimeVue `ConfirmationService` wrapper with two helpers:

- `confirmDelete(onAccept, message?, icon?)`
- `confirmAction({ message, onAccept, header?, icon?, acceptLabel?, rejectLabel?, acceptClass? })`

```ts
const { confirmDelete, confirmAction } = useConfirm();

confirmDelete(() => {
    console.log('Delete accepted');
});

confirmAction({
    message: 'Publish this record now?',
    acceptLabel: 'Publish',
    onAccept: () => console.log('Confirmed'),
});
```

### useDialog()

Global dialog manager used together with `@lvntr/components/ui/AppDialog.vue`.

- `open(component, props?, header?, options?)`
- `openAsync(component, url, header?, options?, baseProps?)`
- `close()`
- `setLoading(val)`

If `refreshKey` is provided in options, `onSuccess` and `onCancel` callbacks are injected automatically.

### useImageLightbox()

Shared fullscreen image preview state rendered through the global `ImageLightbox` overlay in `AdminLayout.vue`.

- `open(url, name?)`
- `close()`
- `state.visible`, `state.url`, `state.name`

Use this for images. For non-image files, keep using `useDialog()` with `FilePreviewModal`.

## Authorization and Navigation

### useCan()

Reads permission and role data from Inertia shared props.

- `can(permission)`
- `canAny(permissions)`
- `hasRole(role)`

### useAdminMenu()

Defines the project's admin sidebar items and delegates visibility and active-state behavior to `useMenuBuilder()`.

### useMenuBuilder()

Shared menu helper for sidebar-style navigation.

- Filters top-level items and child items by permission and role
- Removes empty section headers after filtering
- Detects active links for both plain URLs and URLs with query parameters
- Keeps parent groups open when one of their children is active

```ts
const allItems: MenuItem[] = [{ title: 'sk-menu.dashboard', href: '/dashboard' }];

return useMenuBuilder(allItems);
```

### useUrlTab()

Keeps a tab selection in sync with a query string key such as `?tab=security`. `tabs` accepts a plain array (a `reactive` one included — mutations are tracked live, so a tab list that changes after mount, e.g. one filtered by permission, stays in sync), a `ref`, or a getter (`MaybeRefOrGetter<TabDefinition[]>`), read through `toValue()` on every access. Setting the active tab to its current value is a no-op (no navigation fires); setting it to the list's first entry removes the query param instead of writing it; any `#hash` on the current URL is preserved across the switch. An optional third argument, `{ history: 'push' | 'replace' }`, controls the history entry written on a switch — `'replace'` (default) overwrites the current entry, `'push'` gives each switch its own. `SkTabs` no longer builds on this composable directly — it owns an internal, equivalent active-tab state so its behavior never depends on whichever copy of `useUrlTab` an app has published.

### useRefreshBus()

Simple global refresh bus for components such as DataTable. Registered callbacks are automatically cleaned up on component unmount.

- `on(key, callback)` — register a refresh callback
- `refresh(...keys)` — trigger one or more named refresh callbacks
- `refreshAll()` — trigger all registered callbacks

```ts
const bus = useRefreshBus();

bus.on('users-table', () => fetchData());
bus.refresh('users-table');
```

## UI State Helpers

### useSidebar()

Handles desktop collapse state and mobile drawer state for the admin sidebar.

### useDarkMode()

Persists dark mode in local storage and toggles the `.dark` class on `<html>`.

### useTheme()

Applies the active runtime theme (`main`, `aura`) to `<html>` via the `data-sk-theme` attribute, driven by the admin-wide `appearance.theme` value from Inertia shared props.

- `theme` — the resolved runtime theme name (`undefined` during a partial reload that omits the `appearance` prop)
- `runtimeThemes` — the set of instantly-switchable themes; falls back to `['main', 'aura']` when the server omits `runtime_themes`
- `applyTheme(value)` — sets or removes `data-sk-theme` on `<html>`

### usePageLoading()

Tracks Inertia navigation state using `inertia:start` and `inertia:finish` browser events.

### useFlash()

Returns reactive flash data from Inertia shared props.

In this project, flash messages are displayed in `AdminLayout.vue`, not inside the composable itself.

### usePageHeader()

Provides the page-header injection context that `AdminLayout.vue` sets and that back-button form pages (e.g. `UserForm.vue`, `RoleForm.vue`) read to render their title/subtitle inside the first card instead of a separate page header. Ships as an editable stub alongside `useAdminMenu` — see the note above.

- `active` — `true` only when the Aura theme, a back button, and the page's `header-in-card` opt-in all align; otherwise the injected default is inert
- `title`, `subtitle`, `goBack()` — consumed by the first card of an opted-in form page

## Definition Helpers

### useDefinition()

Loads definition records from the authenticated `/definitions` endpoint and stores them in a shared reactive cache.

- `load(keys)` — loads only the requested keys; deduplicates through shared cache
- `loadAll()` — loads all available definition keys
- `list(key, filter?)` — returns raw definition items, optionally filtered
- `options(key, filter?)` — returns items as `{ label, value }` pairs for selects
- `find(key, value)` — looks up a single item by value
- `clearCache()` — resets the reactive cache
- `loaded` — reactive boolean that becomes `true` once any load completes

Typical keys in this project include `userStatus`, `gender`, `identityType`, and `yesNo`.

```ts
const { load, options, find } = useDefinition();

await load(['userStatus', 'gender']);

const statusOptions = options('userStatus');
const activeStatus = find('userStatus', 'active');
```

`list()` and `options()` accept an optional `filter` object with `only` or `except` arrays when you need a subset of a definition list:

```ts
// Only show active and pending statuses
const filteredOptions = options('userStatus', { only: ['active', 'pending'] });

// Exclude a specific status
const filteredOptions = options('userStatus', { except: ['archived'] });
```

## DataTable Selection

### useDatatableSelection()

Manages row selection and bulk action submission for `SkDatatable`. Consumer pages import this directly — it is the recommended way to add checkboxes and bulk actions to any index screen.

**Types exported:**

```ts
type BulkSelectionMode = 'page' | 'all';

interface BulkActionPayload {
    action: string;
    ids: (string | number)[];
    select_all_filtered: boolean;
    filter_snapshot: Record<string, unknown>;
    [key: string]: unknown;
}

interface BulkActionResult {
    processed: number;
    skipped: number;
    failed: Array<{ id: number | string; reason: string }>;
    message: string;
}
```

**Signature:**

```ts
const selection = useDatatableSelection({
    bulkUrl: string;       // Absolute URL for the bulk endpoint (use Wayfinder: users.bulk.url())
    idKey?: string;        // Row property used as ID — default: 'id'
    onSuccess?: () => void; // Called after a successful bulk action (refresh the table here)
});
```

**Returns:**

| Property / Method | Type | Description |
|---|---|---|
| `selectedIds` | `Ref<Set<string\|number>>` | Currently selected row IDs |
| `selectionMode` | `Ref<BulkSelectionMode>` | `'page'` (current page) or `'all'` (cross-page filtered) |
| `submitting` | `Ref<boolean>` | True while the bulk request is in flight |
| `selectedCount` | `ComputedRef<number>` | Number of selected IDs |
| `hasSelection` | `ComputedRef<boolean>` | True when at least one row is selected |
| `isAllFilteredMode` | `ComputedRef<boolean>` | True when cross-page selection is active |
| `toggleRow(row)` | function | Select or deselect one row |
| `isRowSelected(row)` | function | Returns true if row is selected |
| `togglePageSelection(rows, selected)` | function | Select or deselect all rows on the current page |
| `isPageFullySelected(rows)` | function | True when every row on the page is selected |
| `isPagePartiallySelected(rows)` | function | True when some (not all) rows on the page are selected |
| `selectAllFiltered()` | function | Activate cross-page mode — backend recomputes from filters |
| `clearSelection()` | function | Clear all selected IDs and reset to page mode |
| `executeBulkAction(action, filterSnapshot?, overrideUrl?)` | function | Post the bulk payload via Inertia router |

**Usage:**

```ts
import { useDatatableSelection } from '@/composables/useDatatableSelection';
import { useRefreshBus } from '@/composables/useRefreshBus';
import users from '@/routes/users';

const bus = useRefreshBus();

const selection = useDatatableSelection({
    bulkUrl: users.bulk.url(),
    idKey: 'id',
    onSuccess: () => bus.refresh('users-table'),
});

// Bind to SkDatatable:
// <SkDatatable :selection="selection" ...>

// Trigger a bulk delete with current filters:
selection.executeBulkAction('delete', activeFilterSnapshot.value);
```

Pass `selection` to `<SkDatatable :selection="selection">` — this renders the checkbox column. Bulk action buttons go in the `#bulk-actions` slot; while rows are selected, `SkDatatable` shows a floating dark action bar at the bottom of the viewport. See `docs/datatable.md` for the full bulk-action pattern including the backend `BulkAction` interface.

## Internal Composables

The composables below are used by vendor UI and are not intended to be called directly from consumer pages. They are listed for reference.

### useFileShare() — Internal

Used by the vendor `Files` module (`ShareLinkModal`, `MyShareLinksDrawer`) to create and revoke signed share links for FileManager media. Consumer pages do not call this directly — share link actions are available through the built-in Files UI. See `docs/file-manager.md` for the share link API.

- `createShare(mediaId: number, ttlHours: number): Promise<ShareLinkResult | null>` — creates a signed share link (TTL: 1–720 hours); returns `{ url, expires_at, token_hash }` or `null` on error
- `revokeShare(mediaId: number, token: string): Promise<boolean>` — revokes an existing link by token hash

### useAccentColor() — Internal

Used by `AdminLayout` and the Appearance tab to manage the per-user accent color preference and sidebar surface. Consumers interact with accent color through the header popover, not by calling this composable directly. See `docs/theme.md` for the accent color system.

### useAppearanceDefaults() — Internal

Reads the global appearance defaults (accent color, dark mode, sidebar style, logo and favicon URLs) from Inertia shared props on every page load. Used by `useAccentColor`, `useDarkMode`, and layouts to seed initial state before any per-user override is applied.

### getXsrfToken() — Internal

Exported from `useCsrf.ts`. Single source of truth for reading the Laravel `XSRF-TOKEN` cookie — `useApi()`, the FileManager upload XHR, and the rich-text editor's image upload all read through this so cookie parsing stays consistent. Returns `''` when the cookie is absent (SSR, or not yet set).

- `getXsrfToken(): string`

### withBasePath() — Internal

Exported from `useBasePath.ts`. Used by vendor UI (the rich-text `EditorInput`, FileManager) for raw `fetch`/`XMLHttpRequest` calls that build their own URL and need the app's deploy sub-path prefix; Inertia navigation already honors the base automatically.

- `withBasePath(path: string): string`

## Recommendation

When a UI behavior appears in more than one page, move it into a composable before repeating it inline.
