// resources/js/composables/useAdminMenu.ts
import { useMenuBuilder } from '@/composables/useMenuBuilder';
import activityLogs from '@/routes/activity-logs';
import apiRoutes from '@/routes/api-routes';
import dashboard from '@/routes/dashboard';
import roles from '@/routes/roles';
import settings from '@/routes/settings';
import users from '@/routes/users';
import type { MenuItem } from '@/types';

export function useAdminMenu() {
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
            title: 'admin.menu.laravel_docs',
            icon: 'pi pi-external-link',
            href: 'https://laravel.com/docs',
            external: true,
        },
        {
            title: 'admin.menu.kits_docs',
            icon: 'pi pi-external-link',
            href: 'https://starter-kit.lvntr.dev',
            external: true,
        },
    ];

    return useMenuBuilder(allItems);
}
