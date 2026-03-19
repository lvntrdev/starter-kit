<?php

use App\Enums\PermissionEnum;

/*
|--------------------------------------------------------------------------
| Permission Resources Configuration
|--------------------------------------------------------------------------
|
| Define which resources have permissions and which abilities apply.
| Each resource gets permissions generated as "resource.ability".
|
| To add a new resource (e.g. "students"), add it here and run:
|   php artisan db:seed --class=RolePermissionSeeder
|
| 'abilities' accepts PermissionEnum values. Use null for all abilities.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Resources
    |--------------------------------------------------------------------------
    |
    | Each key is the resource name, value is the array of abilities.
    | null = all abilities (create, read, update, delete, import, export)
    |
    */

    'resources' => [
        'dashboard' => ['read'],
        'users' => null, // all abilities
        'roles' => ['create', 'read', 'update', 'delete'],
        'activity-logs' => ['read'],
        'settings' => ['read', 'update'],
        'api-routes' => ['read'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sub-Resources
    |--------------------------------------------------------------------------
    |
    | Permissions scoped under a parent resource using "parent:type.ability" format.
    | Useful when a single resource has different types that need separate permissions.
    |
    | Format: 'parent' => [ 'type' => abilities ]
    |
    | Example: 'users' => [ 'student' => ['create', 'read', 'update', 'delete'] ]
    | Generates: users:student.create, users:student.read, etc.
    |
    | These appear as separate rows in the permissions table, grouped visually
    | under the parent resource (e.g. "Users → Student", "Users → Guardian").
    |
    | The middleware resolves these via query string: /admin/users?type=student
    | maps to "users:student.read" instead of "users.read".
    |
    */

    'sub_resources' => [
        // 'users' => [
        //     'student' => ['create', 'read', 'update', 'delete'],
        //     'guardian' => ['create', 'read', 'update', 'delete'],
        //     'personal' => ['create', 'read', 'update', 'delete'],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Permissions
    |--------------------------------------------------------------------------
    |
    | Permissions that are NOT tied to a resource controller.
    | Useful for external tools (Pulse, Telescope, etc.) or custom gates.
    | These are created as standalone permissions without resource.ability format.
    |
    */

    'custom_permissions' => [
        'pulse.read',
        'api-docs.read',
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Groups (Categories)
    |--------------------------------------------------------------------------
    |
    | Group resource permissions into categories for display in the role form.
    | Each key is a group name, value is an array of resource names.
    |
    | Resources not listed here will appear under the 'other' group.
    |
    */

    'permission_groups' => [
        'users' => ['users'],
        // 'users' => ['users', 'users:student', 'users:guardian', 'users:personal'],
        'system' => ['roles', 'settings'],
        'developer' => ['activity-logs', 'api-routes', 'pulse', 'api-docs'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Groups
    |--------------------------------------------------------------------------
    |
    | Assign each default role to a group for categorization.
    |
    */

    'role_groups' => [
        'system_admin' => 'system',
        'admin' => 'system',
        'user' => 'system',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Role Permissions
    |--------------------------------------------------------------------------
    |
    | Define which permissions each default role receives.
    | Use resource.ability format or '*' for all permissions.
    |
    | Sub-resource permissions use "parent:type.ability" format:
    |   'users:student.create', 'users:guardian.read'
    |
    */

    'role_permissions' => [
        'system_admin' => '*', // all permissions
        'admin' => [
            'users.create', 'users.read', 'users.update', 'users.delete',
            'roles.read',
            'dashboard.read',
            // 'users:student.create', 'users:student.read', 'users:student.update', 'users:student.delete',
            // 'users:guardian.create', 'users:guardian.read', 'users:guardian.update', 'users:guardian.delete',
        ],
        'user' => [
            'dashboard.read',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Display Names (Translations)
    |--------------------------------------------------------------------------
    |
    | Multi-language display names for roles, resources, and abilities.
    | These are seeded into the database so admins can edit them from the panel.
    |
    | Format: ['en' => 'English Name', 'tr' => 'Türkçe Ad']
    |
    | Sub-resource display names use "parent:type" as key:
    |   'users:student' => ['en' => 'Users → Student', 'tr' => 'Kullanıcılar → Öğrenci']
    |
    */

    'display_names' => [

        'groups' => [
            'users' => ['en' => 'User Management', 'tr' => 'Kullanıcı Yönetimi'],
            'system' => ['en' => 'System', 'tr' => 'Sistem'],
            'other' => ['en' => 'Other', 'tr' => 'Diğer'],
        ],

        'roles' => [
            'system_admin' => ['en' => 'System Admin', 'tr' => 'Sistem Yöneticisi'],
            'admin' => ['en' => 'Admin', 'tr' => 'Yönetici'],
            'user' => ['en' => 'User', 'tr' => 'Kullanıcı'],
        ],

        'resources' => [
            'users' => ['en' => 'Users', 'tr' => 'Kullanıcılar'],
            'roles' => ['en' => 'Roles', 'tr' => 'Roller'],
            'activity-logs' => ['en' => 'Activity Logs', 'tr' => 'İşlem Kayıtları'],
            'settings' => ['en' => 'Settings', 'tr' => 'Ayarlar'],
            'api-routes' => ['en' => 'API Routes', 'tr' => 'API Rotaları'],
            'pulse' => ['en' => 'Pulse', 'tr' => 'Pulse'],
            'api-docs' => ['en' => 'API Docs', 'tr' => 'API Dökümanları'],
            'dashboard' => ['en' => 'Dashboard', 'tr' => 'Dashboard'],
            // 'users:student' => ['en' => 'Users → Student', 'tr' => 'Kullanıcılar → Öğrenci'],
            // 'users:guardian' => ['en' => 'Users → Guardian', 'tr' => 'Kullanıcılar → Veli'],
            // 'users:personal' => ['en' => 'Users → Personal', 'tr' => 'Kullanıcılar → Personel'],
        ],

        'abilities' => [
            'create' => ['en' => 'Create', 'tr' => 'Oluşturma'],
            'read' => ['en' => 'Read', 'tr' => 'Görüntüleme'],
            'update' => ['en' => 'Update', 'tr' => 'Güncelleme'],
            'delete' => ['en' => 'Delete', 'tr' => 'Silme'],
            'import' => ['en' => 'Import', 'tr' => 'İçe Aktarma'],
            'export' => ['en' => 'Export', 'tr' => 'Dışa Aktarma'],
        ],

    ],

];
