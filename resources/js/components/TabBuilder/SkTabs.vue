<script setup lang="ts">
    import { useCan, useUrlTab } from '@/composables';
    import type { TabBuilderConfig, TabItemConfig } from './core';

    interface Props {
        config: TabBuilderConfig;
    }

    const props = defineProps<Props>();
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

    const tabDefinitions = computed(() => visibleTabs.value.map((t) => ({ key: t.key, label: t.label, icon: t.icon })));

    const { activeTab, isActive } = useUrlTab(tabDefinitions.value, props.config.queryParam);

    function isDisabled(tab: TabItemConfig): boolean {
        if (tab.disabled === undefined) return false;
        return typeof tab.disabled === 'function' ? tab.disabled() : tab.disabled;
    }

    const transparentCard = { style: 'background: transparent; box-shadow: none; border: 0; padding: 0' };
    const noPad = { style: 'padding: 0' };

    function tabCardPt(tab: TabItemConfig) {
        const showCard = tab.isCard ?? props.config.isCard ?? false;
        if (!showCard) {
            return { root: transparentCard, body: noPad, content: noPad };
        }
        return {};
    }

    defineSlots<
        {
            /** Vertical sidebar: extra content above the tab navigation */
            'sidebar-header'?(props: Record<string, never>): unknown;
            /** Vertical sidebar: extra content below the tab navigation */
            'sidebar-footer'?(props: Record<string, never>): unknown;
        } & {
            /** Dynamic tab content slots — one per tab.key */
            [key: string]: (props: { tab: TabItemConfig; isActive: boolean }) => unknown;
        }
    >();

    defineExpose({ activeTab, isActive });
</script>

<template>
    <!-- Vertical layout: tabs on the left, content on the right -->
    <div v-if="config.layout === 'vertical'" class="sk-tabs-vertical" :class="config.cssClass">
        <div class="sk-tabs-vertical__sidebar">
            <!-- Sidebar header slot: extra content above tabs (e.g. avatar) -->
            <div v-if="$slots['sidebar-header']" class="sk-vtab-header">
                <slot name="sidebar-header" />
            </div>

            <nav class="sk-vtab-nav">
                <button
                    v-for="tab in visibleTabs"
                    :key="tab.key"
                    class="sk-vtab"
                    :class="{ 'sk-vtab--active': isActive(tab.key), 'sk-vtab--disabled': isDisabled(tab) }"
                    :disabled="isDisabled(tab)"
                    @click="activeTab = tab.key"
                >
                    <i v-if="tab.icon" :class="tab.icon" class="sk-vtab__icon" />
                    {{ $t(tab.label) }}
                </button>
            </nav>

            <!-- Sidebar footer slot: extra content below tabs -->
            <div v-if="$slots['sidebar-footer']" class="sk-vtab-footer">
                <slot name="sidebar-footer" />
            </div>
        </div>

        <div class="sk-tabs-vertical__content">
            <template v-for="tab in visibleTabs" :key="tab.key">
                <Card v-if="isActive(tab.key)" :pt="tabCardPt(tab)">
                    <template v-if="tab.cardTitle ?? config.cardTitle" #title>
                        {{ $t(tab.cardTitle ?? config.cardTitle!) }}
                    </template>
                    <template v-if="tab.cardSubtitle ?? config.cardSubtitle" #subtitle>
                        {{ $t(tab.cardSubtitle ?? config.cardSubtitle!) }}
                    </template>
                    <template #content>
                        <slot :name="tab.key" :tab="tab" :is-active="true" />
                    </template>
                </Card>
            </template>
        </div>
    </div>

    <!-- Horizontal layout: default PrimeVue Tabs -->
    <Tabs v-else :value="activeTab" :class="config.cssClass" @update:value="activeTab = $event as string">
        <TabList>
            <Tab v-for="tab in visibleTabs" :key="tab.key" :value="tab.key" :disabled="isDisabled(tab)">
                <i v-if="tab.icon" :class="tab.icon" class="sk-vtab__icon" />
                {{ $t(tab.label) }}
            </Tab>
        </TabList>

        <TabPanels>
            <TabPanel v-for="tab in visibleTabs" :key="tab.key" :value="tab.key">
                <Card :pt="tabCardPt(tab)">
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
                </Card>
            </TabPanel>
        </TabPanels>
    </Tabs>
</template>
