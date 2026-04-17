<script setup lang="ts">
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import FileManager from '@lvntr/components/FileManager/FileManager.vue';
</script>

<template>
    <AdminLayout :title="$t('sk-file.title')" :subtitle="$t('sk-file.subtitle')">
        <div class="flex min-h-0 flex-1">
            <FileManager context="global" height="100%" class="flex-1" />
        </div>
    </AdminLayout>
</template>
