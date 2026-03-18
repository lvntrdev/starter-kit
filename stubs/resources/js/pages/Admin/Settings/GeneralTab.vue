<script setup lang="ts">
    import { FB } from '@lvntr/components/FormBuilder/core';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
    import adminSettings from '@/routes/settings';
    import { trans } from 'laravel-vue-i18n';

    interface Props {
        settings: {
            app_name: string;
            app_url: string;
            timezone: string;
            languages: string[];
            debug: boolean;
        };
        timezones: string[];
        availableLanguages: Record<string, string>;
    }

    const props = defineProps<Props>();

    const timezoneOptions = computed(() => props.timezones.map((tz) => ({ label: tz, value: tz })));

    const languageOptions = computed(() =>
        Object.entries(props.availableLanguages).map(([locale, label]) => ({
            label,
            value: locale,
        })),
    );

    const formConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(2)
            .cardTitle('admin.settings.general.title')
            .cardSubtitle('admin.settings.general.subtitle')
            .initialData(props.settings)
            .submit({
                url: adminSettings.update.general.url(),
                method: 'put',
                preserveScroll: true,
            })
            .addFields(
                FB.inputText().key('app_name'),
                FB.inputText().key('app_url'),
                FB.select().key('timezone').options(timezoneOptions.value).filter(true).class('col-span-full'),
                FB.checkboxGroup()
                    .key('languages')
                    .hint(trans('admin.settings.general.languages_hint'))
                    .labelPlacement('inline')
                    .controlPosition('left')
                    .options(languageOptions.value)
                    .class('col-span-full'),
                FB.toggleSwitch().key('debug').class('col-span-full'),
            )
            .build(),
    );
</script>

<template>
    <SkForm :config="formConfig" />
</template>
