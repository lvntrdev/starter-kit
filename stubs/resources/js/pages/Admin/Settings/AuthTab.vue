<script setup lang="ts">
    import { FB } from '@lvntr/components/FormBuilder/core';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
    import adminSettings from '@/routes/settings';
    import { trans } from 'laravel-vue-i18n';

    interface Props {
        settings: {
            registration: boolean;
            email_verification: boolean;
            two_factor: boolean;
            password_reset: boolean;
        };
    }

    const props = defineProps<Props>();

    const formConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(1)
            .cardTitle('admin.settings.auth.title')
            .cardSubtitle('admin.settings.auth.subtitle')
            .initialData(props.settings)
            .submit({
                url: adminSettings.update.auth.url(),
                method: 'put',
                preserveScroll: true,
            })
            .addFields(
                FB.toggleSwitch().key('registration').hint(trans('admin.settings.auth.registration_hint')),
                FB.toggleSwitch().key('email_verification').hint(trans('admin.settings.auth.email_verification_hint')),
                FB.toggleSwitch().key('two_factor').hint(trans('admin.settings.auth.two_factor_hint')),
                FB.toggleSwitch().key('password_reset').hint(trans('admin.settings.auth.password_reset_hint')),
            )
            .build(),
    );
</script>

<template>
    <SkForm :config="formConfig" />
</template>
