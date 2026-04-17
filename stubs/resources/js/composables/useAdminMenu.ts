// resources/js/composables/useAdminMenu.ts
import { useMenuBuilder } from '@/composables/useMenuBuilder';
import activityLogs from '@/routes/activity-logs';
import apiRoutes from '@/routes/api-routes';
import dashboard from '@/routes/dashboard';
import files from '@/routes/files';
import roles from '@/routes/roles';
import settings from '@/routes/settings';
import users from '@/routes/users';
import type { MenuItem } from '@/types';

export function useAdminMenu() {
    const allItems: MenuItem[] = [
        {
            title: 'sk-menu.dashboard',
            icon: 'pi pi-home',
            href: dashboard.index.url(),
        },
        {
            title: 'sk-menu.user_management',
            section: true,
        },
        {
            title: 'sk-menu.users',
            icon: 'pi pi-users',
            href: users.index.url(),
            permission: 'users.read',
        },
        {
            title: 'sk-menu.roles_permissions',
            icon: 'pi pi-shield',
            href: roles.index.url(),
            permission: 'roles.read',
        },
        {
            title: 'sk-menu.files',
            icon: 'pi pi-folder',
            href: files.index.url(),
            permission: 'files.read',
        },
        {
            title: 'sk-menu.system',
            section: true,
        },
        {
            title: 'sk-menu.activity_logs',
            icon: 'pi pi-history',
            href: activityLogs.index.url(),
            permission: 'activity-logs.read',
        },
        {
            title: 'sk-menu.settings',
            icon: 'pi pi-cog',
            permission: 'settings.read',
            children: [
                {
                    title: 'sk-menu.settings',
                    href: settings.index.url(),
                },
            ],
        },
        {
            title: 'sk-menu.developer',
            section: true,
        },
        {
            title: 'sk-menu.api_routes',
            icon: 'pi pi-code',
            href: apiRoutes.index.url(),
            permission: 'api-routes.read',
        },
        {
            title: 'sk-menu.laravel_docs',
            icon: 'pi pi-external-link',
            href: 'https://laravel.com/docs',
            external: true,
        },
        {
            title: 'sk-menu.kits_docs',
            icon: 'pi pi-external-link',
            href: 'https://starter-kit.lvntr.dev',
            external: true,
        },
    ];

    return useMenuBuilder(allItems);
}
