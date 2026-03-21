// resources/js/composables/useAdminMenu.ts
import { useCan } from '@/composables/useCan';
import activityLogs from '@/routes/activity-logs';
import apiRoutes from '@/routes/api-routes';
import dashboard from '@/routes/dashboard';
import roles from '@/routes/roles';
import settings from '@/routes/settings';
import users from '@/routes/users';
import type { MenuItem } from '@/types';
import { usePage } from '@inertiajs/vue3';

export function useAdminMenu() {
    const page = usePage();
    const currentUrl = computed(() => page.url);
    const { can, hasRole } = useCan();

    const allItems: MenuItem[] = [
        {
            title: 'admin.menu.dashboard',
            icon: 'pi pi-home',
            href: dashboard.index.url(),
        },
        {
            title: 'admin.menu.user_management',
            section: true,
        },
        {
            title: 'admin.menu.users',
            icon: 'pi pi-users',
            href: users.index.url(),
            permission: 'users.read',
        },
        {
            title: 'admin.menu.roles_permissions',
            icon: 'pi pi-shield',
            href: roles.index.url(),
            permission: 'roles.read',
        },
        {
            title: 'admin.menu.system',
            section: true,
        },
        {
            title: 'admin.menu.activity_logs',
            icon: 'pi pi-history',
            href: activityLogs.index.url(),
            permission: 'activity-logs.read',
        },
        {
            title: 'admin.menu.settings',
            icon: 'pi pi-cog',
            permission: 'settings.read',
            children: [
                {
                    title: 'admin.menu.settings',
                    href: settings.index.url(),
                },
            ],
        },
        {
            title: 'admin.menu.developer',
            section: true,
        },
        {
            title: 'admin.menu.api_routes',
            icon: 'pi pi-code',
            href: apiRoutes.index.url(),
            permission: 'api-routes.read',
        },
        {
            title: 'admin.menu.api_docs',
            icon: 'pi pi-book',
            href: '/docs/api',
            external: true,
            permission: 'api-docs.read',
        },
        {
            title: 'admin.menu.pulse',
            icon: 'pi pi-chart-bar',
            href: '/pulse',
            external: true,
            permission: 'pulse.read',
        },
        {
            title: 'admin.menu.laravel_docs',
            icon: 'pi pi-external-link',
            href: 'https://laravel.com/docs',
            external: true,
        },
        {
            title: 'admin.menu.kits_docs',
            icon: 'pi pi-external-link',
            href: 'https://kit-docs.lvntr.dev',
            external: true,
        },
    ];

    const items = computed(() => {
        const filtered = allItems.filter((item) => {
            if (item.permission && !can(item.permission)) {
                return false;
            }
            if (item.role) {
                const roles = Array.isArray(item.role) ? item.role : [item.role];
                if (!roles.some((r) => hasRole(r))) return false;
            }
            return true;
        });

        // Remove section headers that have no visible items after them
        return filtered.filter((item, index) => {
            if (!item.section) return true;
            const nextItems = filtered.slice(index + 1);
            return nextItems.length > 0 && !nextItems[0].section;
        });
    });

    function isItemActive(item: MenuItem): boolean {
        if (!item.href || item.external) {
            return false;
        }

        return currentUrl.value.startsWith(item.href);
    }

    function isGroupOpen(item: MenuItem): boolean {
        if (!item.children) {
            return false;
        }

        return item.children.some((child) => isItemActive(child) || isGroupOpen(child));
    }

    return { items, isItemActive, isGroupOpen, currentUrl };
}
