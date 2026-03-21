import type { EnumFilter, EnumItem } from './useEnum';

type DefinitionKey = 'system' | 'gender' | (string & {});

type DefinitionStore = Record<string, EnumItem[]>;

/** Reactive cache shared across all component instances. */
const cache = reactive<DefinitionStore>({});
let fetchPromise: Promise<void> | null = null;

/**
 * Access DB-based definitions via API.
 *
 * For PHP enums (Inertia shared), use `useEnum()` instead.
 *
 * Usage:
 *   const { list, find, options, load, loaded } = useDefinition();
 *
 *   // Load specific keys on mount
 *   onMounted(async () => {
 *       await load(['system', 'gender']);
 *   });
 *
 *   // Then use synchronously in template
 *   const systems = list('system');
 *   const opts = options('gender');
 *   const item = find('system', 1);
 */
export function useDefinition() {
    const loaded = ref(false);

    function applyFilter(items: EnumItem[], filter?: EnumFilter): EnumItem[] {
        if (!filter) return items;
        if (filter.only) {
            const allowed = new Set(filter.only.map(String));
            return items.filter((item) => allowed.has(String(item.value)));
        }
        if (filter.except) {
            const excluded = new Set(filter.except.map(String));
            return items.filter((item) => !excluded.has(String(item.value)));
        }
        return items;
    }

    /**
     * Get definition items from the reactive cache.
     */
    function list(key: DefinitionKey, filter?: EnumFilter): EnumItem[] {
        return applyFilter(cache[key] ?? [], filter);
    }

    /**
     * Get definition items formatted as select/filter options.
     */
    function options(key: DefinitionKey, filter?: EnumFilter): { label: string; value: string | number }[] {
        return list(key, filter).map((item) => ({
            label: item.label,
            value: item.value,
        }));
    }

    /**
     * Find a single definition item by value.
     */
    function find(key: DefinitionKey, value: string | number): EnumItem | undefined {
        return list(key).find((item) => String(item.value) === String(value));
    }

    /**
     * Load specific definition keys from the API.
     * Results are merged into the shared reactive cache.
     */
    async function load(keys: DefinitionKey[]): Promise<void> {
        const missing = keys.filter((k) => !(k in cache));

        if (missing.length === 0) {
            loaded.value = true;
            return;
        }

        const url = `/definitions?keys=${encodeURIComponent(missing.join(','))}`;
        const res = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const json = await res.json();
        const data: DefinitionStore = json.data ?? {};

        Object.assign(cache, data);
        loaded.value = true;
    }

    /**
     * Load all definitions from the API at once.
     * Uses deduplication — concurrent calls share the same request.
     */
    async function loadAll(): Promise<void> {
        if (fetchPromise) {
            await fetchPromise;
            loaded.value = true;
            return;
        }

        fetchPromise = fetch('/definitions', {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((res) => res.json())
            .then((json) => {
                const data: DefinitionStore = json.data ?? {};
                Object.assign(cache, data);
                fetchPromise = null;
            })
            .catch(() => {
                fetchPromise = null;
            });

        await fetchPromise;
        loaded.value = true;
    }

    /**
     * Clear the reactive cache (e.g. after locale change).
     */
    function clearCache(): void {
        for (const key of Object.keys(cache)) {
            delete cache[key];
        }
        fetchPromise = null;
        loaded.value = false;
    }

    return { list, options, find, load, loadAll, clearCache, loaded };
}
