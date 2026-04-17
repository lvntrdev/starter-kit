<script setup lang="ts">
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import { trans } from 'laravel-vue-i18n';
    import RoleForm from './components/RoleForm.vue';

    interface PermissionGroup {
        label: string;
        resources: Record<string, string[]>;
    }

    interface Role {
        id: number;
        name: string;
        display_name?: Record<string, string>;
        permissions: string[];
    }

    interface Props {
        role: Role;
        permissionsByGroup: Record<string, PermissionGroup>;
        availableLocales: Record<string, string>;
        userPermissions: string[] | null;
    }

    defineProps<Props>();
</script>

<template>
    <AdminLayout :title="trans('sk-role.edit')" :subtitle="role.name" :back-url="true">
        <RoleForm
            :role="role"
            :permissions-by-group="permissionsByGroup"
            :available-locales="availableLocales"
            :user-permissions="userPermissions"
        />
    </AdminLayout>
</template>
