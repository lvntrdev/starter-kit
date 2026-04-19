<script setup lang="ts">
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import UserForm from '@/pages/Admin/Users/components/UserForm.vue';
    import FileManager from '@lvntr/components/FileManager/FileManager.vue';
    import { TB } from '@lvntr/components/TabBuilder/core';

    interface Props {
        userId: string;
        roleOptions: { label: string; value: string }[];
    }

    defineProps<Props>();

    const tabConfig = TB.tabs()
        .addTabs(
            TB.item().key('general').label('sk-user.tabs.general').icon('pi pi-user'),
            TB.item().key('files').label('sk-user.tabs.files').icon('pi pi-folder'),
        )
        .build();
</script>

<template>
    <AdminLayout :title="$t('sk-user.edit')" :subtitle="userId" :back-url="true">
        <SkTabs :config="tabConfig">
            <template #general>
                <UserForm :user-id="userId" :role-options="roleOptions" />
            </template>

            <template #files>
                <div class="p-2">
                    <FileManager context="user" :context-id="userId" height="650px" />
                </div>
            </template>
        </SkTabs>
    </AdminLayout>
</template>
