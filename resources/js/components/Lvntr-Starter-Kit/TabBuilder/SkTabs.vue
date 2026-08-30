<script setup lang="ts">
    import type { ShallowUnwrapRef } from 'vue';
    import { useCan } from '@/composables';
    import type {
        SkTabsExposed,
        TabBuilderConfig,
        TabChangePayload,
        TabItemConfig,
        TabPanelMode,
    } from './core';
    import { useActiveTab } from './core/useActiveTab';
    import SkCard from '../ui/SkCard.vue';

    interface Props {
        config: TabBuilderConfig;
        /**
         * Two-way binding for the active tab key. Optional — left out, the
         * component owns its state exactly as it did before.
         */
        modelValue?: string;
    }

    const props = defineProps<Props>();

    const emit = defineEmits<{
        'update:modelValue': [key: string];
        /** Every tab change after mount. The initial tab is not a change. */
        change: [payload: TabChangePayload];
    }>();

    const { can, canAny, hasRole } = useCan();

    const visibleTabs = computed(() =>
        props.config.tabs.filter((tab) => {
            if (tab.permission) {
                const hasPermission = Array.isArray(tab.permission) ? canAny(tab.permission) : can(tab.permission);
                if (!hasPermission) return false;
            }
            if (tab.role) {
                const roles = Array.isArray(tab.role) ? tab.role : [tab.role];
                if (!roles.some((r) => hasRole(r))) return false;
            }
            if (tab.visible === undefined) return true;
            return typeof tab.visible === 'function' ? tab.visible() : tab.visible;
        }),
    );

    function isDisabled(tab: TabItemConfig): boolean {
        if (tab.disabled === undefined) return false;
        return typeof tab.disabled === 'function' ? tab.disabled() : tab.disabled;
    }

    // Disabled tabs stay in the nav and panel loops, they are only kept out of URL
    // selection: `?tab=<disabled>` resolves to the first selectable tab, and a
    // disabled first tab is skipped as the param-less default.
    const selectableTabs = computed(() => visibleTabs.value.filter((tab) => !isDisabled(tab)));

    // The composable re-reads this getter on every access, so a tab that appears or
    // disappears after mount — or whose disabled getter flips — is picked up with no
    // list syncing on this side.
    const { activeKey: activeTab, isActive, select } = useActiveTab(
        () => selectableTabs.value.map((tab) => ({ key: tab.key, label: tab.label, icon: tab.icon })),
        {
            queryParam: props.config.queryParam,
            syncUrl: props.config.syncUrl ?? true,
            history: props.config.history ?? 'replace',
            urlMode: props.config.urlMode ?? 'server',
            initial: props.modelValue,
        },
    );

    // The resolved key is the single source of truth (the URL in URL mode, the
    // composable's own ref in local mode) and `modelValue` follows it — never the
    // other way around. The immediate run is what aligns a host that deep-linked
    // into `?tab=security` with a different `modelValue`; `change` skips that run
    // on purpose, because a mount is not a change.
    watch(
        activeTab,
        (key, previousKey) => {
            if (key !== props.modelValue) emit('update:modelValue', key);
            if (previousKey === undefined) return;

            const tab = visibleTabs.value.find((item) => item.key === key);
            // An empty previous key means nothing was resolvable before (every tab
            // was still gated away), which reads as "no previous tab" for the host.
            if (tab) emit('change', { key, previousKey: previousKey || null, tab });
        },
        { immediate: true },
    );

    // A host writing `modelValue` goes through the same setter a click uses, so URL
    // mode still produces exactly one visit instead of a second, divergent source of
    // truth living inside the component.
    //
    // A key naming no selectable tab is refused instead of obeyed: selecting it
    // would write `?tab=<invalid>` and fire a visit, while the resolved key stayed
    // on the fallback it already had — an unchanged resolved key means the watcher
    // above never emits a correction, so the host would keep a value the component
    // never holds. Emitting the resolved key back closes that gap in one step and
    // leaves the URL untouched.
    watch(
        () => props.modelValue,
        (key) => {
            if (key === undefined || key === activeTab.value) return;

            if (selectableTabs.value.some((tab) => tab.key === key)) {
                select(key);
                return;
            }

            emit('update:modelValue', activeTab.value);
        },
    );

    // Vertical has always mounted only the active panel and horizontal all of them.
    // `panels` overrides that per config without moving either default.
    const panelMode = computed<TabPanelMode>(
        () => props.config.panels ?? (props.config.layout === 'vertical' ? 'active' : 'all'),
    );

    // Sekme görünür bir kart mı? (tab override > config > varsayılan false)
    // false → şeffaf, kenara-yaslı (transparent + flush); true → düz görünür kart.
    function tabIsCard(tab: TabItemConfig): boolean {
        return tab.isCard ?? props.config.isCard ?? false;
    }

    // `useId()` is SSR-stable — server and client derive the same prefix — which a
    // counter or a random string would not be: the aria-controls/aria-labelledby
    // pair would then differ between the two renders and only surface as a
    // hydration mismatch in production.
    const idPrefix = useId();

    // The key is developer-authored and may hold characters an id cannot: a space
    // above all, which makes `aria-controls`/`aria-labelledby` parse as TWO id
    // references, neither of them existing. `encodeURIComponent` never emits
    // whitespace and is reversible, so distinct keys stay distinct ids.
    function tabId(key: string): string {
        return `${idPrefix}-tab-${encodeURIComponent(key)}`;
    }

    function panelId(key: string): string {
        return `${idPrefix}-panel-${encodeURIComponent(key)}`;
    }

    const NAV_KEYS = ['ArrowDown', 'ArrowUp', 'Home', 'End'];

    // WAI-ARIA roving tabindex: the tablist holds exactly ONE tab stop, and it
    // belongs to the tab the focus is actually on — not necessarily the selected
    // one, because the arrow keys move focus without selecting (manual
    // activation). Pinning the stop to the selection would send a Tab-out /
    // Tab-back round trip to a different tab than the one carrying the focus
    // ring. `@focus` feeds this for keyboard and mouse alike; a selection change
    // hands the stop back to the newly active tab.
    const focusedKey = ref<string | null>(null);

    watch(activeTab, () => {
        focusedKey.value = null;
    });

    // A focused tab can be gated away or disabled while it holds the stop. The
    // browser drops the focus at that moment without a blur the component could
    // observe, so the stored key is cleared for good the instant it stops naming a
    // selectable tab — otherwise the tab would reclaim the stop on its return
    // although nothing focuses it any more, stealing it from the active tab.
    watch(selectableTabs, (tabs) => {
        const key = focusedKey.value;
        if (key !== null && !tabs.some((tab) => tab.key === key)) focusedKey.value = null;
    });

    const rovingKey = computed(() => focusedKey.value ?? activeTab.value);

    /**
     * Roving focus for the vertical tablist, WAI-ARIA manual activation: the keys
     * move focus only and selection stays on the button's own click/Enter/Space, so
     * walking the list with the arrows does not fire a navigation per step.
     */
    function onNavKeydown(event: KeyboardEvent): void {
        if (!NAV_KEYS.includes(event.key)) return;

        const nav = event.currentTarget as HTMLElement;
        const buttons = Array.from(nav.querySelectorAll<HTMLElement>('.sk-vtab:not([disabled])'));
        if (buttons.length === 0) return;

        const current = buttons.indexOf(document.activeElement as HTMLElement);
        const last = buttons.length - 1;
        let next: number;

        if (event.key === 'Home') {
            next = 0;
        } else if (event.key === 'End') {
            next = last;
        } else if (event.key === 'ArrowDown') {
            next = current === -1 ? 0 : (current + 1) % buttons.length;
        } else {
            next = current === -1 ? last : (current - 1 + buttons.length) % buttons.length;
        }

        // Only for the keys handled above: the page must keep scrolling on the rest.
        event.preventDefault();
        buttons[next].focus();
    }

    defineSlots<
        {
            /** Vertical sidebar: extra content above the tab navigation */
            'sidebar-header'?(props: Record<string, never>): unknown;
            /** Vertical sidebar: extra content below the tab navigation */
            'sidebar-footer'?(props: Record<string, never>): unknown;
            /** Rendered alone when no tab is selectable: none survived permission/role/visible gating, or every visible tab is disabled */
            empty?(props: Record<string, never>): unknown;
        } & {
            /** Dynamic tab content slots — one per tab.key */
            [key: string]: (props: { tab: TabItemConfig; isActive: boolean }) => unknown;
        }
    >();

    const exposed = { activeTab, isActive };

    // The instance proxy unwraps refs, so a template ref reads `activeTab` as the
    // plain (still writable) string the published `SkTabsExposed` contract
    // promises. Typing the macro through that unwrapping is what fails the build
    // if the exposed shape and the contract ever drift apart.
    type ExposedContract = ShallowUnwrapRef<typeof exposed> extends SkTabsExposed ? typeof exposed : never;

    defineExpose<ExposedContract>(exposed);
</script>

<template>
    <!--
        Empty state: no tab is selectable — every tab was gated away by
        permission/role/visible, or every visible tab is disabled. Only the slot
        is rendered — a host's own empty message must not land inside an orphan
        sidebar, an all-disabled tab strip, or beside a content area that has no
        active panel. Without the slot the markup below is unchanged, so nothing
        moves for an existing screen.
    -->
    <template v-if="selectableTabs.length === 0 && $slots.empty">
        <slot name="empty" />
    </template>

    <!-- Vertical layout: tabs on the left, content on the right -->
    <div v-else-if="config.layout === 'vertical'" class="sk-tabs-vertical" :class="config.cssClass">
        <div class="sk-tabs-vertical__sidebar">
            <!-- Sidebar header slot: extra content above tabs (e.g. avatar) -->
            <div v-if="$slots['sidebar-header']" class="sk-vtab-header">
                <slot name="sidebar-header" />
            </div>

            <SkCard class="sk-vtab-card">
                <template #content>
                    <nav class="sk-vtab-nav" role="tablist" aria-orientation="vertical" @keydown="onNavKeydown">
                        <button
                            v-for="tab in visibleTabs"
                            :id="tabId(tab.key)"
                            :key="tab.key"
                            type="button"
                            role="tab"
                            class="sk-vtab"
                            :class="[
                                { 'sk-vtab--active': isActive(tab.key), 'sk-vtab--disabled': isDisabled(tab) },
                                tab.description ? 'sk-vtab--rich' : null,
                            ]"
                            :disabled="isDisabled(tab)"
                            :aria-selected="isActive(tab.key)"
                            :aria-controls="panelId(tab.key)"
                            :aria-disabled="isDisabled(tab) ? 'true' : undefined"
                            :tabindex="rovingKey === tab.key ? 0 : -1"
                            @focus="focusedKey = tab.key"
                            @click="activeTab = tab.key"
                        >
                            <span
                                v-if="tab.icon"
                                class="sk-vtab__icon-tile"
                                :class="`sk-vtab__icon-tile--${tab.iconColor ?? 'slate'}`"
                            >
                                <i :class="tab.icon" class="sk-vtab__icon" aria-hidden="true" />
                            </span>

                            <span class="sk-vtab__body">
                                <span class="sk-vtab__label">{{ $t(tab.label) }}</span>
                                <span v-if="tab.description" class="sk-vtab__description">
                                    {{ $t(tab.description) }}
                                </span>
                            </span>

                            <span v-if="tab.checked || tab.badge != null" class="sk-vtab__trailing">
                                <!--
                                    The check is state, not decoration: the icon is
                                    hidden from assistive tech and the state is
                                    spoken through visually hidden text instead.
                                -->
                                <template v-if="tab.checked">
                                    <i class="pi pi-check-circle sk-vtab__check" aria-hidden="true" />
                                    <span class="sr-only">{{ $t('sk-common.completed') }}</span>
                                </template>
                                <span
                                    v-else-if="tab.badge != null"
                                    class="sk-vtab__badge"
                                    :class="`sk-vtab__badge--${tab.badgeSeverity ?? 'secondary'}`"
                                >
                                    {{ tab.badge }}
                                </span>
                            </span>
                        </button>
                    </nav>
                </template>
            </SkCard>

            <!-- Sidebar footer slot: extra content below tabs -->
            <div v-if="$slots['sidebar-footer']" class="sk-vtab-footer">
                <slot name="sidebar-footer" />
            </div>
        </div>

        <div class="sk-tabs-vertical__content">
            <template v-for="tab in visibleTabs" :key="tab.key">
                <!--
                    v-if runs first, so the default 'active' mode still mounts only
                    the current panel; 'all' mounts every one and hides the inactive
                    ones with v-show, which is what keeps their state alive.
                -->
                <SkCard
                    v-if="panelMode === 'all' || isActive(tab.key)"
                    v-show="isActive(tab.key)"
                    :transparent="!tabIsCard(tab)"
                    :flush="!tabIsCard(tab)"
                >
                    <template v-if="tab.cardTitle ?? config.cardTitle" #title>
                        {{ $t(tab.cardTitle ?? config.cardTitle!) }}
                    </template>
                    <template v-if="tab.cardSubtitle ?? config.cardSubtitle" #subtitle>
                        {{ $t(tab.cardSubtitle ?? config.cardSubtitle!) }}
                    </template>
                    <template #content>
                        <!--
                            SkCard is `inheritAttrs: false`, so the panel semantics
                            cannot fall through to its root and live on a wrapper
                            inside the card body instead.
                        -->
                        <div
                            :id="panelId(tab.key)"
                            role="tabpanel"
                            :aria-labelledby="tabId(tab.key)"
                            tabindex="0"
                        >
                            <slot :name="tab.key" :tab="tab" :is-active="isActive(tab.key)" />
                        </div>
                    </template>
                </SkCard>
            </template>
        </div>
    </div>

    <!-- Horizontal layout: default PrimeVue Tabs -->
    <!--
        `lazy` is PrimeVue's own panel gate: false (the default here) mounts every
        panel exactly as before, true leaves only the active one mounted.
    -->
    <Tabs
        v-else
        :value="activeTab"
        :lazy="panelMode === 'active'"
        :class="config.cssClass"
        @update:value="activeTab = $event as string"
    >
        <TabList>
            <Tab v-for="tab in visibleTabs" :key="tab.key" :value="tab.key" :disabled="isDisabled(tab)">
                <i v-if="tab.icon" :class="tab.icon" class="sk-vtab__icon" aria-hidden="true" />
                {{ $t(tab.label) }}
            </Tab>
        </TabList>

        <TabPanels>
            <TabPanel v-for="tab in visibleTabs" :key="tab.key" :value="tab.key">
                <SkCard :transparent="!tabIsCard(tab)" :flush="!tabIsCard(tab)">
                    <template v-if="tab.cardTitle ?? config.cardTitle" #title>
                        {{ $t(tab.cardTitle ?? config.cardTitle!) }}
                    </template>
                    <template v-if="tab.cardSubtitle ?? config.cardSubtitle" #subtitle>
                        {{ $t(tab.cardSubtitle ?? config.cardSubtitle!) }}
                    </template>
                    <template #content>
                        <div class="sk-tabs__panel">
                            <slot :name="tab.key" :tab="tab" :is-active="isActive(tab.key)" />
                        </div>
                    </template>
                </SkCard>
            </TabPanel>
        </TabPanels>
    </Tabs>
</template>
