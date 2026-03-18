<script setup lang="ts">
    import { FB } from '@lvntr/components/FormBuilder/core';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
    import userProfileInformation from '@/routes/user-profile-information';
    import { usePage } from '@inertiajs/vue3';
    import { trans } from 'laravel-vue-i18n';

    const page = usePage();
    const user = computed(() => page.props.auth?.user);

    const formConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(2)
            .isCard(false)
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
                FB.inputText().key('first_name').label(trans('admin.common.first_name')),
                FB.inputText().key('last_name').label(trans('admin.common.last_name')),
                FB.inputText()
                    .key('email')
                    .label(trans('admin.common.email'))
                    .inputType('email')
                    .placeholder('example@mail.com')
                    .class('col-span-full'),
            )
            .build(),
    );
</script>

<template>
    <Card>
        <template #title>
            {{ $t('admin.profile.info_title') }}
        </template>
        <template #subtitle>
            {{ $t('admin.profile.info_subtitle') }}
        </template>
        <template #content>
            <div class="space-y-6">
                <!-- Avatar (independent upload) -->
                <div class="border-b border-surface-200 pb-6 dark:border-surface-700">
                    <AvatarUpload
                        :avatar-url="(user as any)?.avatar_url"
                        upload-url="/user/avatar"
                        delete-url="/user/avatar"
                    />
                </div>

                <!-- Profile form -->
                <SkForm :config="formConfig" />
            </div>
        </template>
    </Card>
</template>
