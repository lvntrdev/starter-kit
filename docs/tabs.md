# Tabs

The starter kit uses `SkTabs` with a fluent `TB` builder to keep multi-section screens clean. Settings, profile, and similar screens often grow into multiple sections — tabs give a single route a structured UI without breaking the page into many routes.

## Imports

```ts
import { TB } from '@lvntr/components/TabBuilder/core';
import SkTabs from '@lvntr/components/TabBuilder/SkTabs.vue';
import type { TabIconColor, TabBadgeSeverity } from '@lvntr/components/TabBuilder/core';
import type { TabChangePayload, SkTabsExposed, TabPanelMode, TabHistoryMode, TabUrlMode } from '@lvntr/components/TabBuilder/core';
```

## Basic Example

```vue
<script setup lang="ts">
import { TB } from '@lvntr/components/TabBuilder/core';
import SkTabs from '@lvntr/components/TabBuilder/SkTabs.vue';

const tabConfig = TB.tabs()
    .queryParam('tab')
    .addTabs(
        TB.item().key('general').label('General').icon('pi pi-user'),
        TB.item().key('security').label('Security').icon('pi pi-shield'),
        TB.item().key('sessions').label('Sessions').icon('pi pi-desktop'),
    )
    .build();
</script>

<template>
    <SkTabs :config="tabConfig">
        <template #general>
            <p>General content</p>
        </template>

        <template #security>
            <p>Security content</p>
        </template>

        <template #sessions>
            <p>Session content</p>
        </template>
    </SkTabs>
</template>
```

## Tabs Builder API

- `layout('horizontal' | 'vertical')`
- `vertical()`
- `horizontal()`
- `queryParam(string)`
- `class(string)`
- `cardTitle(string)`
- `cardSubtitle(string)`
- `isCard(boolean)`
- `addTabs(...tabs)`
- `lazy(value = true)` — mount only the active panel (`panels: 'active'`); `lazy(false)` clears the override
- `keepAlive(value = true)` — mount every panel and keep it alive across switches (`panels: 'all'`); `keepAlive(false)` clears the override
- `history('push' | 'replace')` — history entry written on a tab switch; default `replace`
- `urlMode('server' | 'client')` — `server` syncs through an Inertia visit (default), `client` rewrites the URL with no server request
- `syncUrl(boolean)` — mirror the active tab in the URL query string; default `true`

## Tab Item API

- `key(string)`
- `label(string)`
- `icon(string)`
- `description(string)` — secondary line under the label (vertical layout only)
- `iconColor(color)` — colored icon tile preset (vertical layout only); defaults to `slate`. One of: `blue`, `amber`, `emerald`, `purple`, `teal`, `red`, `rose`, `indigo`, `slate`, `pink`, `orange`, `cyan`, `green`, `yellow`
- `badge(value, severity?)` — trailing badge (text or number). Severity: `success` / `warn` / `info` / `danger` / `secondary` (default)
- `checked(value = true)` — trailing green check mark; takes precedence over `badge`
- `permission(...permissions)` — hide the tab unless the user holds at least one of the given permissions (variadic; OR across multiple values — same as `canAny()`)
- `role(...roles)` — hide the tab unless the user holds at least one of the given roles (variadic; OR across multiple values)
- `visible(boolean | () => boolean)`
- `disabled(boolean | () => boolean)`
- `isCard(boolean)`
- `cardTitle(string)`
- `cardSubtitle(string)`

```ts
TB.item().key('billing').label('Billing').permission('billing.view', 'billing.manage'),
TB.item().key('admin-tools').label('Admin Tools').role('admin', 'superadmin'),
```

## Component Props & Events

- `config: TabBuilderConfig` — the built config (required)
- `v-model` (`modelValue?: string`) — optional two-way binding for the active tab key. In URL mode a deep link (e.g. `?tab=security`) wins over a different incoming `modelValue` on mount; in local mode (`.syncUrl(false)`) `modelValue` seeds the initial selection instead. Either way, writing `modelValue` goes through the same setter a click uses.
- `@update:modelValue="(key: string) => …"` — fires whenever the resolved active key differs from `modelValue`, including immediately after mount
- `@change="(payload: TabChangePayload) => …"` — fires on every tab change **after** mount (the initial mount is not a change); payload is `{ key, previousKey, tab }`, where `previousKey` is `null` when nothing was resolvable before
- `#empty` slot — rendered alone, with no sidebar or tab strip, when no tab is selectable: every tab is gated away by `.permission()`/`.role()`/`.visible()`, or every visible tab is `.disabled()`
- exposed instance (`SkTabsExposed`, via a template ref) — `{ activeTab: string; isActive: (key: string) => boolean }`

```vue
<script setup lang="ts">
import { ref } from 'vue';
import type { TabChangePayload } from '@lvntr/components/TabBuilder/core';

const activeTab = ref('general');

function onTabChange(payload: TabChangePayload) {
    console.log(payload.previousKey, '→', payload.key);
}
</script>

<template>
    <SkTabs :config="tabConfig" v-model="activeTab" @change="onTabChange">
        <!-- ... -->
    </SkTabs>
</template>
```

## Rich Vertical Tabs

Vertical tabs can present a richer sidebar — colored icon tile, description line, trailing badge or check mark. The sidebar itself is always wrapped in a card; `.isCard(true)` instead controls whether the active tab's **content** panel renders as a card or a transparent, flush panel — the same flag `tabIsCard()` reads inside `SkTabs`:

```vue
<script setup lang="ts">
const tabConfig = TB.tabs()
    .vertical()
    .isCard(true)
    .addTabs(
        TB.item()
            .key('general')
            .label('General')
            .description('App name, language and logo')
            .icon('pi pi-cog')
            .iconColor('blue'),
        TB.item()
            .key('mail')
            .label('Mail')
            .description('SMTP and sender settings')
            .icon('pi pi-envelope')
            .iconColor('emerald')
            .badge(3, 'warn'),
        TB.item()
            .key('storage')
            .label('Storage')
            .description('S3, Spaces and local disk')
            .icon('pi pi-database')
            .iconColor('purple')
            .checked(),
    )
    .build();
</script>
```

`description`, `iconColor`, `badge`, and `checked` are ignored in horizontal layout.

## Useful Features

- vertical or horizontal layout
- rich vertical sidebar with icon tiles, descriptions, badges, check marks
- query string sync by default, or fully local (URL-less) state via `.syncUrl(false)`
- role-based and permission-based visibility
- per-tab disabled logic
- optional card wrappers with title and subtitle at both tab and container level
- optional `v-model` binding and a `change` event for host-side reactions
- an `empty` slot for the nothing-selectable case (every tab gated away, or every visible tab disabled)
- full keyboard/ARIA support in vertical layout

## Built-in Behavior

`SkTabs` already includes:

- query string synchronization by default; `.syncUrl(false)` keeps the active tab fully local instead
- vertical sidebar mode
- optional `sidebar-header` and `sidebar-footer` slots in vertical layout
- slot-based content keyed by the tab id
- **lifecycle**: defaults unchanged — vertical mounts only the active panel and unmounts it on switch, horizontal mounts every panel once and toggles visibility, so per-tab local state survives a switch only in horizontal by default. `.lazy()` overrides either layout to active-only mounting (on horizontal this is PrimeVue's own `lazy` mode); `.keepAlive()` overrides either layout to mount every panel and keep it alive, hidden instead of unmounted (useful on vertical, to preserve per-tab state across switches)
- **URL sync**: `?tab=` must name a visible, enabled tab or the first selectable tab wins; a disabled tab never activates from the URL; re-selecting the active tab is a no-op; `#hash` is preserved across switches. `.urlMode('server')` (default) syncs through an Inertia visit that re-resolves the page; `.urlMode('client')` rewrites the URL with no server request. `.history('replace')` (default) replaces the history entry on each switch, `.history('push')` gives each switch its own entry. `.syncUrl(false)` drops URL sync entirely — the active tab lives only in component state (and `v-model`)
- **accessibility (vertical layout)**: the tab list is `role="tablist"` with `aria-orientation="vertical"`, each tab button is `role="tab"` with `aria-selected`/`aria-controls`/`aria-disabled` and roving `tabindex` (`0` on the active tab, `-1` on the rest), and the panel is wrapped in `role="tabpanel"`. Arrow Down/Up move focus between enabled tabs (wrapping at the ends), Home/End jump to the first/last enabled tab — focus only, manual activation — and Enter/Space select through the button's native click. Tab icons are `aria-hidden` in both layouts (the label carries the name), and a `.checked()` tab announces its state through visually hidden text (`sk-common.completed`) beside the hidden check icon. Horizontal layout keeps PrimeVue's own accessibility
- **builder validation**: `TB.item()…build()` throws on an empty or whitespace-only key; `TB.tabs()…build()` throws with no tabs added, and throws on a duplicate tab key in development builds (`console.error`s the same message in production instead, without de-duplicating); `TB.tabs().queryParam()` throws on an empty or whitespace-only name in development builds (`console.error`s in production and keeps the name already set); each `build()` returns a fresh snapshot, so later `.addTabs()` calls on the same builder — or mutating the returned config — never affect an already-built config
- multiple `SkTabs` instances on the same page need distinct `.queryParam()` values
- `.permission()`/`.role()` gating is presentation-only — authorize the underlying data server-side, and don't serialize hidden-tab data into the page's props

The active tab is exposed from the component via `defineExpose`, so parent components can access it when needed.

## Tabs Inside a Dialog

A dialog is not a routable page, so syncing its tabs to the URL query string can fight the host page's own `?tab=` param (or simply doesn't make sense). Call `.syncUrl(false)` and drive the active tab with `v-model` instead:

```vue
<script setup lang="ts">
import { ref } from 'vue';

const activeTab = ref('general');
const tabConfig = TB.tabs().syncUrl(false).addTabs(/* … */).build();
</script>

<template>
    <AppDialog>
        <SkTabs :config="tabConfig" v-model="activeTab">
            <!-- ... -->
        </SkTabs>
    </AppDialog>
</template>
```

## Good Fit

- settings screens
- profile screens
- long create/edit views split into logical sections
