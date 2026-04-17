<script setup lang="ts">
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import { trans } from 'laravel-vue-i18n';
    import RoleForm from './components/RoleForm.vue';

    interface PermissionGroup {
        label: string;
        resources: Record<string, string[]>;
    }

    interface Props {
        permissionsByGroup: Record<string, PermissionGroup>;
        availableLocales: Record<string, string>;
        userPermissions: string[] | null;
    }

    defineProps<Props>();
</script>

<template>
    <AdminLayout :title="trans('sk-role.create')" :back-url="true">
        <RoleForm
            :permissions-by-group="permissionsByGroup"
            :available-locales="availableLocales"
            :user-permissions="userPermissions"
        />
    </AdminLayout>
</template>
