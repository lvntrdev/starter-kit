import { router, usePage } from '@inertiajs/vue3';

export interface TabDefinition {
    key: string;
    label: string;
    icon?: string;
}

/**
 * Composable that syncs active tab state with URL query parameter.
 * Uses Inertia router for URL updates without full page reload.
 */
export function useUrlTab(tabs: TabDefinition[], queryParam = 'tab') {
    const page = usePage();

    function parseUrl(): URL {
        const origin = typeof window !== 'undefined' ? window.location.origin : 'http://localhost';
        return new URL(page.url, origin);
    }

    const currentQuery = computed(() => parseUrl().searchParams);

    const activeTab = computed({
        get: () => {
            const param = currentQuery.value.get(queryParam);
            const found = tabs.find((t) => t.key === param);
            return found ? found.key : (tabs[0]?.key ?? '');
        },
        set: (value: string) => {
            const url = parseUrl();
            if (value === tabs[0]?.key) {
                url.searchParams.delete(queryParam);
            } else {
                url.searchParams.set(queryParam, value);
            }
            router.visit(url.pathname + url.search, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        },
    });

    const activeIndex = computed({
        get: () => {
            const idx = tabs.findIndex((t) => t.key === activeTab.value);
            return idx >= 0 ? idx : 0;
        },
        set: (index: number) => {
            if (tabs[index]) {
                activeTab.value = tabs[index].key;
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
