import { usePage } from '@inertiajs/vue3';

export interface EnumItem {
    value: string | number;
    label: string;
    severity: string | null;
    icon?: string | null;
}

export type EnumKey = 'userStatus' | 'identityType' | 'yesNo' | (string & {});

export interface EnumFilter {
    only?: (string | number)[];
    except?: (string | number)[];
}

type EnumStore = Record<string, EnumItem[]>;

/**
 * Access PHP enum data from Inertia shared props.
 *
 * Only enums marked with `#[InertiaShared]` are available here.
 * For DB-based definitions, use `useDefinition()` instead.
 *
 * Usage:
 *   const { list, find, options } = useEnum();
 *
 *   const statuses = list('userStatus');
 *   const active = find('userStatus', 'active');
 *   const opts = options('userStatus'); // for Select / filter dropdowns
 */
export function useEnum() {
    const page = usePage<{ enums?: EnumStore }>();

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
     * Get enum items from Inertia shared props.
     */
    function list(key: EnumKey, filter?: EnumFilter): EnumItem[] {
        return applyFilter(page.props.enums?.[key] ?? [], filter);
    }

    /**
     * Get enum items formatted as select/filter options.
     */
    function options(key: EnumKey, filter?: EnumFilter): { label: string; value: string | number }[] {
        return list(key, filter).map((item) => ({
            label: item.label,
            value: item.value,
        }));
    }

    /**
     * Find a single enum item by value.
     */
    function find(key: EnumKey, value: string | number): EnumItem | undefined {
        return list(key).find((item) => String(item.value) === String(value));
    }

    return { list, options, find };
}
