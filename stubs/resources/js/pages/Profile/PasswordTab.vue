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
                FB.password().key('current_password').label(trans('admin.profile.current_password')).toggleMask(),
                FB.password().key('password').label(trans('admin.profile.new_password')).toggleMask().feedback(true),
                FB.password().key('password_confirmation').label(trans('admin.common.confirm_password')).toggleMask(),
            )
            .actionLabels({ submit: trans('admin.profile.update_password') })
            .build(),
    );
</script>

<template>
    <Card>
        <template #title>
            {{ $t('admin.profile.password_title') }}
        </template>
        <template #subtitle>
            {{ $t('admin.profile.password_subtitle') }}
        </template>
        <template #content>
            <SkForm :config="formConfig" />
        </template>
    </Card>
</template>
