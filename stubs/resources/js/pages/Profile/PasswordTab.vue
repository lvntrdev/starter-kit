<script setup lang="ts">
    import { FB } from '@lvntr/components/FormBuilder/core';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
    import userPassword from '@/routes/user-password';
    import { trans } from 'laravel-vue-i18n';

    const formConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .isCard(false)
            .cols(1)
            .submit({
                url: userPassword.update.url(),
                method: 'put',
                preserveScroll: true,
            })
            .addFields(
                FB.password().key('current_password').label(trans('sk-profile.current_password')).toggleMask(),
                FB.password().key('password').label(trans('sk-profile.new_password')).toggleMask().feedback(true),
                FB.password().key('password_confirmation').label(trans('sk-common.confirm_password')).toggleMask(),
            )
            .actionLabels({ submit: trans('sk-profile.update_password') })
            .build(),
    );
</script>

<template>
    <Card>
        <template #title>
            {{ $t('sk-profile.password_title') }}
        </template>
        <template #subtitle>
            {{ $t('sk-profile.password_subtitle') }}
        </template>
        <template #content>
            <SkForm :config="formConfig" />
        </template>
    </Card>
</template>
