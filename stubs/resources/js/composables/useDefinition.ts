export interface EnumItem {
    value: string | number;
    label: string;
    severity: string | null;
    icon?: string | null;
}

export type DefinitionKey = string;

export interface DefinitionFilter {
    only?: (string | number)[];
    except?: (string | number)[];
}

type DefinitionStore = Record<string, EnumItem[]>;

/** Reactive cache shared across all component instances. */
const cache = reactive<DefinitionStore>({});
let fetchPromise: Promise<void> | null = null;

/**
 * Access definitions via API.
 *
 * All definition data (labels, severities) is stored in the definitions DB table
 * and served via the /definitions endpoint.
 *
 * Usage:
 *   const { list, find, options, load, loaded } = useDefinition();
 *
 *   onMounted(async () => {
 *       await load(['userStatus', 'gender']);
 *   });
 *
 *   const statuses = list('userStatus');
 *   const opts = options('gender');
 *   const item = find('userStatus', 'active');
 */
export function useDefinition() {
    const loaded = ref(false);

    function applyFilter(items: EnumItem[], filter?: DefinitionFilter): EnumItem[] {
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
    function list(key: DefinitionKey, filter?: DefinitionFilter): EnumItem[] {
        return applyFilter(cache[key] ?? [], filter);
    }

    /**
     * Get definition items formatted as select/filter options.
     */
    function options(key: DefinitionKey, filter?: DefinitionFilter): { label: string; value: string | number }[] {
        return list(key, filter).map((item) => ({
            label: item.label,
            value: item.value,
        }));
    }

    /**
     * Find a single definition item by value.
     */
    function find(key: DefinitionKey, value: string | number | boolean): EnumItem | undefined {
        const normalized = typeof value === 'boolean' ? (value ? 1 : 0) : value;
        return list(key).find((item) => String(item.value) === String(normalized));
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
