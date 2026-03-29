// resources/js/composables/useMenuBuilder.ts
import { useCan } from '@/composables/useCan';
import type { MenuItem } from '@/types';
import { usePage } from '@inertiajs/vue3';

/**
 * Provides menu filtering, active-state detection, and group-open logic.
 * Keeps presentation logic in the package so updates don't conflict with
 * project-specific menu definitions in useAdminMenu.
 */
export function useMenuBuilder(allItems: MenuItem[]) {
    const page = usePage();
    const currentUrl = computed(() => page.url);
    const { can, hasRole } = useCan();

    /**
     * Check if a menu item is visible based on permission and role constraints.
     */
    function isVisible(item: MenuItem): boolean {
        if (item.permission && !can(item.permission)) return false;
        if (item.role) {
            const roles = Array.isArray(item.role) ? item.role : [item.role];
            if (!roles.some((r) => hasRole(r))) return false;
        }
        return true;
    }

    const items = computed(() => {
        const filtered = allItems
            .map((item) => {
                // Filter children by permission and role
                if (item.children) {
                    const visibleChildren = item.children.filter(isVisible);
                    if (visibleChildren.length === 0) return null;
                    return { ...item, children: visibleChildren };
                }
                // Check top-level permission and role
                if (!isVisible(item)) return null;
                return item;
            })
            .filter((item): item is MenuItem => item !== null);

        // Remove section headers that have no visible items after them
        return filtered.filter((item, index) => {
            if (!item.section) return true;
            const nextItems = filtered.slice(index + 1);
            return nextItems.length > 0 && !nextItems[0].section;
        });
    });

    function matchesPath(current: string, target: string): boolean {
        if (current === target) return true;
        return current.startsWith(target + '/') || current.startsWith(target + '?');
    }

    function isItemActive(item: MenuItem): boolean {
        if (!item.href || item.external) {
            return false;
        }

        const current = currentUrl.value;

        // For URLs with query params, compare path and check that all item query params exist in current URL
        if (item.href.includes('?')) {
            const [itemPath, itemQuery] = item.href.split('?');
            const [currentPath] = current.split('?');

            if (!matchesPath(currentPath, itemPath)) return false;

            const itemParams = new URLSearchParams(itemQuery);
            const currentParams = new URLSearchParams(current.split('?')[1] || '');

            for (const [key, value] of itemParams) {
                if (currentParams.get(key) !== value) return false;
            }

            return true;
        }

        return matchesPath(current, item.href);
    }

    function isGroupOpen(item: MenuItem): boolean {
        if (!item.children) {
            return false;
        }

        return item.children.some((child) => isItemActive(child) || isGroupOpen(child));
    }

    return { items, isItemActive, isGroupOpen, currentUrl };
}
