# Datatable

The starter kit ships with a reusable datatable stack made of two parts:

- frontend `SkDatatable` component
- backend `DatatableQueryBuilder`

## Imports

```ts
import { DB } from '@lvntr/components/DatatableBuilder/core';
import SkDatatable from '@lvntr/components/DatatableBuilder/SkDatatable.vue';
```

## Frontend Builder

Use the fluent `DB` API to configure the table:

```vue
<script setup lang="ts">
    import { DB } from '@lvntr/components/DatatableBuilder/core';
    import SkDatatable from '@lvntr/components/DatatableBuilder/SkDatatable.vue';
    import users from '@/routes/users';

    interface UserRow {
        id: string;
        full_name: string;
        email: string;
        role: string;
        status: string;
        created_at: string;
    }

    const tableConfig = DB.table<UserRow>()
        .route(users.dtApi.url())
        .addColumns(
            DB.column<UserRow>().label('sk-common.full_name').key('full_name'),
            DB.column<UserRow>().key('email'),
            DB.column<UserRow>().label('sk-common.role').key('role'),
            DB.column<UserRow>().key('status').tag('definition').tagKey('userStatus').tagOutlined(),
        )
        .addFilters(DB.filter().key('status').definitionOptions('userStatus'))
        .addActions(
            DB.action<UserRow>()
                .icon('pi pi-pencil')
                .severity('warn')
                .label('sk-button.edit')
                .handle((row) => console.log(row.id)),
        )
        .build();
</script>

<template>
    <SkDatatable :config="tableConfig" refresh-key="users-table" />
</template>
```

Main capabilities:

- server-side pagination, search, sorting, and filters
- inline or panel filters
- row actions and menu actions
- definition-backed tags
- sticky columns

## Table Builder API

- `route(url)` — accepts a string, a Wayfinder result object, or a callback returning `{ url }`
- `sortable(enabled)`
- `pagination(enabled)`
- `searchable(enabled)`
- `isCard(enabled)`
- `cardTitle(title)`
- `cardSubtitle(subtitle)`
- `title(title)` — toolbar heading rendered to the left of the search input
- `subtitle(subtitle)` — sub-heading shown under the toolbar title
- `columnToggle(enabled)` — show/hide the column visibility & order menu button (default: `true`)
- `perPage(count)`
- `idColumn(config | false)`
- `addColumns(...columns)`
- `addFilters(...filters)`
- `addActions(...actions)`
- `addMenuActions(...menuActions)`
- `menuButton(config)`
- `create(config)`

## Column Builder

- `key(string)`
- `label(string)`
- `sortable(boolean)`
- `render((row, escape) => string)`
- `tag('definition' | 'value', tagKey?)`
- `tagKey(key)`
- `tagLabels(map)` — value-mode label map (raw cell value → display label)
- `tagSeverityKey(key)` — row property holding the tag severity (e.g. a backend-seeded color column); `colors()` wins when both match
- `colors(map)`
- `icons(map)`
- `tagIconPos('left' | 'right')`
- `tagSoft(enabled = true)`
- `tagRounded(enabled = true)`
- `tagOutlined(enabled = true)`
- `sticky()`
- `hidden()` — start hidden; the user can enable the column from the column menu
- `visible(boolean)` — initial column-menu visibility (default: `true`)
- `locked(enabled = true)` — always visible, cannot be hidden from the column menu

Tag rendering is definition-driven. Use `tag('definition')` when the column value maps to a definition key such as `userStatus`. `SkDatatable` resolves the label, severity, and icon from the definitions payload, and you can still override the visual layer with `colors({...})`, `icons({...})`, `tagSoft()`, `tagRounded()`, `tagOutlined()`, and `tagIconPos()`.

```ts
DB.column<UserRow>()
    .key('status')
    .tag('definition', 'userStatus')
    .colors({
        active: 'emerald',
        inactive: 'rose',
    })
    .icons({
        active: 'pi pi-check-circle',
        inactive: 'pi pi-times-circle',
    })
    .tagIconPos('right')
    .tagOutlined()
    .tagRounded();
```

For values that are not definitions (dynamic data such as role keys), use value mode: the raw cell value becomes the tag label, optionally mapped through `tagLabels()` and coloured through `colors()`.

```ts
DB.column<UserRow>()
    .key('role')
    .tag('value')
    .tagLabels(Object.fromEntries(roleOptions.map((o) => [o.value, o.label])))
    .tagSeverityKey('role_color') // color seeded via config/permission-resources.php → role_colors
    .tagSoft();
```

Notes:

- `tagKey()` points to the definition group key, for example `userStatus`
- `colors()` and `icons()` are matched against the current row value
- when you do not override them, `SkDatatable` uses the severity and icon returned by `useDefinition()`
- in value mode no definition lookup happens — label comes from `tagLabels()` (falling back to the raw value), severity only from `colors()`

## Filter Builder

- `key(string)`
- `label(string)`
- `type('select' | 'select-button' | 'date' | 'daterange')`
- `options([...])`
- `definitionOptions(key)`
- `optionsUrl(url)`
- `placeholder(string)`
- `inline()`
- `placement('inline' | 'panel')`

`inline()` filters render directly in the toolbar; `panel` filters (the default) live behind the funnel button in a popover. The funnel button and popover appear **only when at least one `panel` filter exists** — if every filter is `inline()`, the funnel/popover is hidden entirely and inline filters are not duplicated inside it.

Free-text searching is handled by the table-level search box through `searchable(true)`, not by a dedicated text filter type.

## Row Actions

### Inline actions

Use `DB.action()` for buttons rendered directly in the row.

- `icon`
- `severity`
- `size`
- `variant`
- `rounded`
- `raised`
- `text`
- `outlined`
- `label`
- `tooltip`
- `visible(fn)`
- `handle(fn)`

### Menu actions

Use `DB.menuAction()` for actions inside the three-dot dropdown menu.

- `label`
- `icon`
- `separator`
- `visible(fn)`
- `handle(fn)`

## Bulk Actions

Bulk actions let the user select multiple rows — across pages — and run a single backend operation on all of them. Selection can cover an explicit set of IDs or every row matching the current filter state.

### Frontend

Create a selection with the `useDatatableSelection()` composable and pass it to `SkDatatable` through the `selection` prop — this renders the checkbox column. Provide the bulk buttons through the `#bulk-actions` slot: while rows are selected, `SkDatatable` shows a **floating dark action bar** pinned bottom-center, with a built-in selected-count label and a clear (×) button; the slot content renders between them. Slotted PrimeVue buttons are restyled as ghost buttons on the dark surface automatically (use `variant="text"`; `severity="danger"` turns rose).

```vue
<script setup lang="ts">
    const selection = useDatatableSelection({
        bulkUrl: users.bulk.url(),
        idKey: 'id',
        onSuccess: () => bus.refresh('users-table'),
    });
</script>

<template>
    <SkDatatable :config="tableConfig" :selection="selection" refresh-key="users-table">
        <template #bulk-actions>
            <Button
                :label="$t('sk-datatable.bulk_delete')"
                icon="pi pi-trash"
                size="small"
                severity="danger"
                variant="text"
                @click="confirmBulkDelete(totalFiltered)"
            />
        </template>
    </SkDatatable>
</template>
```

The legacy pattern — rendering a `.sk-dt-bulk-toolbar` block inside the `#toolbar` slot — keeps working; the floating bar only appears when a `#bulk-actions` slot is provided.

When an action calls `executeBulkAction()`, the composable posts the following payload:

```json
{
    "action": "delete",
    "ids": ["uuid-1", "uuid-2"],
    "select_all_filtered": false,
    "filter_snapshot": {}
}
```

When `select_all_filtered` is `true`, `ids` is empty and `filter_snapshot` carries the current filter state so the backend can reconstruct the filtered set.

Selection is preserved across page changes. `onSuccess` and `onError` Inertia router callbacks fire after the backend responds.

### Request Validation

`ids.*` is validated as `string|min:1|max:64`, which covers integer auto-increment keys, UUIDs (36 chars), and ULIDs (26 chars) without a type-specific rule. `ids` itself is required only in page mode; when `select_all_filtered` is `true` it may be empty or omitted, because the set is resolved from `filter_snapshot`. Any ids sent in either mode are still capped at 500.

### Backend

Implement the `BulkAction` interface:

```php
interface BulkAction
{
    public function handle(Collection $models, array $meta): BulkActionResult;
}
```

`BulkActionDispatcher` resolves the right action from the `action` key and passes either the explicit model set (when `ids` is present) or the full filtered set (when `select_all_filtered` is `true`).

### Select-all-filtered fail-closed contract

When a query class implements cross-page selection (e.g. `UserBulkSelectionQuery`), it re-applies the datatable's own filter predicates — for the shipped Users table that's `status`, `role`, `search`, `created_at_from`, `created_at_to` — via `BulkFilterSnapshot::normalize()`. Any other **active** `filter[...]` key present in the client's `filter_snapshot` is rejected with a 422 (`sk-bulk.unknown_filters`) instead of being silently dropped, because dropping it would resolve a wider set than the one the user saw and filtered on. Only a `null` value or an empty array counts as inactive — the two shapes Spatie's `AllowedFilter` itself skips. An empty or whitespace-only string is an active value and is passed through verbatim (no trim), to be applied with the table's own predicate: an exact filter such as `status` yields the same empty set the table showed, while `search` and the date bounds ignore it exactly as the table does — so a blank value can never widen the bulk set, and a blank value on an unsupported key is rejected like any other active one. A literal `true` / `false` string is coerced to a boolean before it is applied — the same coercion Spatie's `QueryBuilderRequest` performs for the table — so both sides bind the identical PHP value (the table's `search` callback, for instance, receives `true` and searches `1`, not the word "true"); the word-search predicate itself is `DatatableQueryBuilder::applySearchWords()`, shared by the table's `search` filter and the bulk queries. `ids` are sent and validated as opaque strings (`string|min:1|max:64`) with no numeric coercion, so UUID/ULID primary keys pass through unchanged.

`BulkActionResult` carries:

```php
new BulkActionResult(
    processed: 12,
    skipped: 1,
    failed: 0,
    message: 'Deleted 12 users.',
);
```

The controller returns an Inertia flash response — not a JSON response:

```php
return back()->with('success', $result->message);
// or
return back()->with('error', $result->message);
```

### Stub Examples

**BulkDeleteUserAction** — skips users with a higher admin rank than the acting user:

```php
final class BulkDeleteUserAction implements BulkAction
{
    public function __construct(private readonly User $actor) {}

    public function handle(Collection $models, array $meta): BulkActionResult
    {
        $processed = 0;
        $skipped   = 0;

        foreach ($models as $user) {
            if ($user->rank >= $this->actor->rank) {
                $skipped++;
                continue;
            }
            $user->delete();
            $processed++;
        }

        return new BulkActionResult($processed, $skipped, 0);
    }
}
```

**BulkDeleteRoleAction** — protects system roles from deletion:

```php
final class BulkDeleteRoleAction implements BulkAction
{
    public function handle(Collection $models, array $meta): BulkActionResult
    {
        $systemRoles = config('permission-resources.system_roles', []);
        $processed   = 0;
        $skipped     = 0;

        foreach ($models as $role) {
            if (in_array($role->name, $systemRoles, true)) {
                $skipped++;
                continue;
            }
            $role->delete();
            $processed++;
        }

        return new BulkActionResult($processed, $skipped, 0);
    }
}
```

## Custom Cell Slots

`SkDatatable` exposes per-column slots using the `cell-{column.key}` naming pattern. Each slot receives:

- `row`: the full row object
- `value`: the resolved value for the current column key

Use PrimeVue's `<Tag>` (auto-imported, no import needed) when you want slot content to match the built-in badge styling. `severity` accepts the 6 PrimeVue severities and supported SK palette names (e.g. `indigo`, `emerald`); soft/outlined are opt-in via the `p-tag-soft` / `p-tag-outlined` classes:

```vue
<template>
    <SkDatatable :config="tableConfig">
        <template #cell-status="{ row, value }">
            <Tag :value="String(value)" :severity="row.is_active ? 'success' : 'danger'" rounded class="p-tag-soft" />
        </template>
    </SkDatatable>
</template>
```

When a matching `cell-*` slot exists, it overrides the built-in rendering for that column, including definition tags.

## Column Visibility & Ordering

The toolbar shows a column menu button with a live `visible/total` counter. From the menu the user can:

- toggle columns on/off — `locked()` columns stay visible and cannot be unchecked
- reorder columns by dragging the grip handle — the built-in ID and selection checkbox columns are fixed and never move
- restore everything with "Show all"

Column state (order + hidden set) persists in `sessionStorage` together with the rest of the table state. Disable the whole feature with `columnToggle(false)`.

On every fetch, `SkDatatable` sends the visible column keys as a `columns=key1,key2` query param. Backends that don't opt in simply ignore it; backends that declare `DatatableQueryBuilder::columns()` shape their payload to the selection (see below).

### Server-driven column list

When the backend declares its column list, the response carries a `columns` meta array. `SkDatatable` merges it over the local config by key: the server list controls availability, order, labels and default visibility — including columns that are **not** in the frontend config at all (e.g. initially hidden extras) — while the local `DB.column()` config keeps supplying the render layer (tags, custom render, sticky). Client-only columns missing from the server list keep rendering after it.

## Toolbar Slots

- `#toolbar-start` — rendered inside the actions group, **to the left of the create button** (e.g. an Export button)
- `#toolbar` — rendered after the create button (used by the bulk-action toolbar)

```vue
<SkDatatable :config="tableConfig">
    <template #toolbar-start>
        <Button label="Export" icon="pi pi-download" severity="secondary" outlined />
    </template>
</SkDatatable>
```

## Backend Builder

Use `DatatableQueryBuilder` inside controllers or dedicated query classes:

```php
return DatatableQueryBuilder::for(User::query())
    ->searchable(['name', 'email'])
    ->sortable(['id', 'name', 'email', 'created_at'])
    ->filterable(['status'])
    ->columns([
        ['key' => 'name', 'locked' => true],
        'email',
        ['key' => 'created_at', 'visible' => false],
    ])
    ->alwaysInclude(['name'])
    ->defaultSort('-created_at')
    ->response();
```

### Column declaration & payload shaping

`columns()` declares the column list the table offers. Each entry is a key string or an array with optional `label`, `sortable`, `visible`, and `locked` flags. The list is returned as `columns` meta — so the frontend menu can offer initially-hidden columns — and enables payload shaping: **fail-closed** — the full row is only returned when the request carries no `columns` parameter at all. Once the parameter is present, every row is reduced to the `alwaysInclude()` keys (default `['id']`) plus whichever requested keys actually match a declared column key; a present parameter with no matching key still reduces rows to `alwaysInclude()` only — it never falls back to the full row. Use `alwaysInclude()` for fields row actions need regardless of visibility (names for confirm dialogs, URLs, etc.). Dot keys such as `role.name` keep their top-level `role` segment. Declared column keys must match the frontend column keys exactly, or the corresponding cells render empty.

### Search semantics

`searchable()` splits the incoming `filter[search]` value by whitespace into
words. Each word is matched against every listed column with `LIKE '%word%'`
(OR across columns), and **all words must match** (AND across words). So
`filter[search]=john doe` against `['name', 'email']` returns rows where each
of `john` and `doe` appears in at least one of name/email. `%` and `_` in the
search value are escaped and treated literally.

The default per-page count (when the caller does not call `perPage()` and no
`?per_page=` query param is present) is read from
`config('starter-kit.datatable.default_per_page')` and falls back to `10`.

`?per_page=` is capped at `config('starter-kit.datatable.max_per_page')` (or
the `STARTER_KIT_DATATABLE_MAX_PER_PAGE` env var) and falls back to `100`
when the key is absent. Higher requested values are silently clamped to the
ceiling — protects the server from accidental or hostile large-payload
requests without breaking legitimate callers.

## Recommended Pattern

For larger modules, keep datatable logic in `app/Domain/*/Queries/*DatatableQuery.php` and inject that query class into the controller.

## Expected Response Shape

`SkDatatable` expects a payload like:

```json
{
    "data": [],
    "total": 0,
    "per_page": 15,
    "current_page": 1,
    "last_page": 1,
    "from": null,
    "to": null
}
```

When the backend declares `columns()`, the payload additionally carries a `columns` array (`[{ "key": "email", "visible": false, ... }]`).

## Built-in Behavior

`SkDatatable` already includes:

- server-side search, sort, pagination, and filters
- automatic definition loading for tag columns and definition-backed filters
- definition-based tag labels, severities, and icons rendered through PrimeVue's `<Tag>`
- query string sync for shareable table URLs
- `sessionStorage` persistence between reloads
- optional refresh bus integration through `refresh-key`
- automatic per-page controls
- a column menu with visibility toggles and drag & drop ordering (ID/checkbox columns stay fixed)
- per-column data fetching: visible columns are sent as `columns=` and shaped server-side when enabled
- per-column custom render overrides through `cell-{column.key}` slots
- a `load` event that emits fetched rows

## Good Use Cases

- admin user lists
- role lists
- activity logs
- any resource that needs filters, actions, and server-side pagination
