import { router, usePage } from '@inertiajs/vue3';
import type { MaybeRefOrGetter } from 'vue';

export interface TabDefinition {
    key: string;
    label: string;
    icon?: string;
}

/** History entry written when the active tab changes. */
export type TabHistoryMode = 'push' | 'replace';

export interface UseUrlTabOptions {
    /**
     * 'replace' (default) overwrites the current history entry, so Back leaves
     * the page instead of walking every tab the user tried. 'push' gives each
     * switch its own entry when the tabs are steps the user should be able to
     * navigate back through.
     */
    history?: TabHistoryMode;
}

/**
 * Composable that syncs active tab state with URL query parameter.
 * Uses Inertia router for URL updates without full page reload.
 *
 * `tabs` may be a plain array, a ref or a getter; it is read through `toValue`
 * on every access, so a list that changes (a tab appearing after a permission
 * check resolves) is picked up instead of being frozen at call time.
 */
export function useUrlTab(tabs: MaybeRefOrGetter<TabDefinition[]>, queryParam = 'tab', options: UseUrlTabOptions = {}) {
    const page = usePage();

    function currentTabs(): TabDefinition[] {
        return toValue(tabs);
    }

    function parseUrl(): URL {
        const origin = typeof window !== 'undefined' ? window.location.origin : 'http://localhost';
        return new URL(page.url, origin);
    }

    const currentQuery = computed(() => parseUrl().searchParams);

    /** The key the current URL resolves to — the getter body, reusable from the setter. */
    function resolveActiveKey(): string {
        const list = currentTabs();
        const param = currentQuery.value.get(queryParam);
        const found = list.find((t) => t.key === param);
        return found ? found.key : (list[0]?.key ?? '');
    }

    const activeTab = computed({
        get: () => resolveActiveKey(),
        set: (value: string) => {
            // Re-selecting the active tab would only re-request the very same page.
            if (value === resolveActiveKey()) return;

            const url = parseUrl();
            if (value === currentTabs()[0]?.key) {
                url.searchParams.delete(queryParam);
            } else {
                url.searchParams.set(queryParam, value);
            }
            // `url.hash` is empty unless the current URL carries one; keep it so an
            // in-page anchor survives the switch.
            router.visit(url.pathname + url.search + url.hash, {
                preserveState: true,
                preserveScroll: true,
                replace: options.history !== 'push',
            });
        },
    });

    const activeIndex = computed({
        get: () => {
            const idx = currentTabs().findIndex((t) => t.key === activeTab.value);
            return idx >= 0 ? idx : 0;
        },
        set: (index: number) => {
            const target = currentTabs()[index];
            if (target) {
                activeTab.value = target.key;
            }
        },
    });

    function isActive(key: string): boolean {
        return activeTab.value === key;
    }

    return {
        tabs,
        activeTab,
        activeIndex,
        isActive,
    };
}
