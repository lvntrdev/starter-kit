<script setup lang="ts">
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import UserForm from '@/pages/Admin/Users/components/UserForm.vue';

    interface Props {
        roleOptions: { label: string; value: string }[];
        timezones: string[];
    }

    defineProps<Props>();
</script>

<template>
  <AdminLayout
    :title="$t('sk-user.create')"
    :back-url="true"
  >
    <UserForm
      :role-options="roleOptions"
      :timezones="timezones"
    />
  </AdminLayout>
</template>
