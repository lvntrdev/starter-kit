<script setup lang="ts">
    import { FB } from '@lvntr/components/FormBuilder/core';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
    import adminSettings from '@/routes/settings';
    import { useConfirm } from '@/composables/useConfirm';
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
    const { confirmAction } = useConfirm();
    const formRef = ref<InstanceType<typeof SkForm>>();

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

    /**
     * Watch two_factor toggle — if user turns it OFF while it was ON,
     * show a warning that all users' 2FA will be revoked.
     */
    watch(
        () => formRef.value?.currentValues?.two_factor,
        (newVal, oldVal) => {
            if (oldVal === true && newVal === false) {
                confirmAction({
                    message: trans('admin.settings.auth.two_factor_disable_warning'),
                    header: trans('admin.settings.auth.two_factor_disable_title'),
                    icon: 'pi pi-exclamation-triangle',
                    acceptLabel: trans('button.confirm'),
                    acceptClass: 'p-button-danger',
                    onAccept: () => {
                        // User confirmed — value stays false, they can submit normally
                    },
                    onReject: () => {
                        formRef.value?.setValue('two_factor', true);
                    },
                });
            }
        },
    );
</script>

<template>
    <SkForm ref="formRef" :config="formConfig" />
</template>
