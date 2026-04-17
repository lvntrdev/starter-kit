<script setup lang="ts">
    import { FB } from '@lvntr/components/FormBuilder/core';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
    import adminSettings from '@/routes/settings';
    import { computed } from 'vue';

    interface Props {
        settings: {
            max_size_kb: number;
            accepted_mimes: string[];
            allow_video: boolean;
            allow_audio: boolean;
        };
    }

    const props = defineProps<Props>();

    const mimeOptions = [
        { label: 'JPEG Image', value: 'image/jpeg' },
        { label: 'PNG Image', value: 'image/png' },
        { label: 'GIF Image', value: 'image/gif' },
        { label: 'WebP Image', value: 'image/webp' },
        { label: 'SVG Image', value: 'image/svg+xml' },
        { label: 'PDF Document', value: 'application/pdf' },
        { label: 'Word (DOC)', value: 'application/msword' },
        {
            label: 'Word (DOCX)',
            value: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        },
        { label: 'Excel (XLS)', value: 'application/vnd.ms-excel' },
        {
            label: 'Excel (XLSX)',
            value: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        },
        { label: 'Plain Text', value: 'text/plain' },
        { label: 'CSV', value: 'text/csv' },
        { label: 'ZIP Archive', value: 'application/zip' },
    ];

    const formConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(2)
            .cardTitle('sk-setting.file_manager.title')
            .cardSubtitle('sk-setting.file_manager.subtitle')
            .initialData({
                max_size_kb: props.settings.max_size_kb,
                accepted_mimes: props.settings.accepted_mimes,
                allow_video: props.settings.allow_video,
                allow_audio: props.settings.allow_audio,
            })
            .submit({
                url: adminSettings.update.fileManager.url(),
                method: 'put',
                preserveScroll: true,
            })
            .addFields(
                FB.inputNumber().key('max_size_kb').min(1).max(1048576).class('col-span-1'),
                FB.multiselect().key('accepted_mimes').options(mimeOptions).class('col-span-full'),
                FB.toggleSwitch().key('allow_video').class('col-span-1'),
                FB.toggleSwitch().key('allow_audio').class('col-span-1'),
            )
            .build(),
    );
</script>

<template>
    <SkForm :config="formConfig" />
</template>
