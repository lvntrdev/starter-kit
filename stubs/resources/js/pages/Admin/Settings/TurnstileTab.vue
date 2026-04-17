<script setup lang="ts">
    import { FB } from '@lvntr/components/FormBuilder/core';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
    import adminSettings from '@/routes/settings';
    import { trans } from 'laravel-vue-i18n';

    interface Props {
        settings: {
            enabled: boolean;
            site_key: string | null;
            secret_key: string | null;
        };
    }

    const props = defineProps<Props>();

    const formConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(1)
            .cardTitle('sk-setting.turnstile.title')
            .cardSubtitle('sk-setting.turnstile.subtitle')
            .initialData({
                enabled: props.settings.enabled,
                site_key: props.settings.site_key ?? '',
                secret_key: props.settings.secret_key ?? '',
            })
            .submit({
                url: adminSettings.update.turnstile.url(),
                method: 'put',
                preserveScroll: true,
            })
            .addFields(
                FB.toggleSwitch().key('enabled').hint(trans('sk-setting.turnstile.enabled_hint')),
                FB.inputText().key('site_key').label('sk-setting.turnstile.site_key_label'),
                FB.password().key('secret_key').label('sk-setting.turnstile.secret_key_label').toggleMask(),
            )
            .build(),
    );
</script>

<template>
    <SkForm :config="formConfig" />
</template>
