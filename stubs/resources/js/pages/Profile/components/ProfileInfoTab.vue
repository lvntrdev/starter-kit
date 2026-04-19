<script setup lang="ts">
    import userProfileInformation from '@/routes/user-profile-information';
    import { usePage } from '@inertiajs/vue3';
    import { FB } from '@lvntr/components/FormBuilder/core';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';

    const page = usePage();
    const user = computed(() => page.props.auth?.user);

    const formConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(2)
            .cardTitle('sk-profile.info_title')
            .cardSubtitle('sk-profile.info_subtitle')
            .initialData({
                first_name: user.value?.first_name ?? '',
                last_name: user.value?.last_name ?? '',
                email: user.value?.email ?? '',
            })
            .submit({
                url: userProfileInformation.update.url(),
                method: 'put',
                preserveScroll: true,
            })
            .addFields(
                FB.inputText().key('first_name'),
                FB.inputText().key('last_name'),
                FB.inputText().key('email').inputType('email').placeholder('example@mail.com').class('col-span-full'),
            )
            .build(),
    );
</script>

<template>
    <!-- Avatar -->
    <AvatarUpload
        :avatar-url="(user as any)?.avatar_url"
        upload-url="/user/avatar"
        delete-url="/user/avatar"
        :title="$t('sk-avatar.title')"
        :subtitle="$t('sk-avatar.subtitle')"
        class="mb-8"
    />

    <!-- Profile form -->
    <SkForm :config="formConfig" />
</template>
