<!-- resources/js/components/Admin/UserForm.vue -->
<script setup lang="ts">
    import roles from '@/routes/roles';
    import adminUsers from '@/routes/users';
    import { FB } from '@lvntr/components/FormBuilder/core';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
    import { trans } from 'laravel-vue-i18n';

    interface Props {
        userId?: string | null;
        inDialog?: boolean;
        showBack?: boolean;
    }

    const props = withDefaults(defineProps<Props>(), {
        userId: null,
        inDialog: false,
        showBack: false,
    });

    const emit = defineEmits<{
        success: [];
        cancel: [];
    }>();

    const formRef = ref<InstanceType<typeof SkForm>>();
    const isEdit = computed(() => !!props.userId);

    const formConfig = computed(() => {
        const builder = FB.form()
            .layout('vertical')
            .cols(2)
            .submit({
                url: isEdit.value ? adminUsers.update.url(props.userId!) : adminUsers.store.url(),
                method: isEdit.value ? 'put' : 'post',
            })
            .inDialog(props.inDialog)
            .actionsPosition('bottom');

        if (isEdit.value) {
            builder.dataUrl(adminUsers.data.url(props.userId!)).dataKey('user');
        }

        return builder
            .addFields(
                FB.inputText().key('first_name'),
                FB.inputText().key('last_name'),
                FB.inputText().key('email').inputType('email'),
                FB.select().key('status').default('active').enumOptions('userStatus'),
                FB.select().key('gender').definitionOptions('gender').placeholder('common.placeholder.select_gender'),
                FB.select()
                    .key('role')
                    .optionsUrl(roles.roleOptions.url())
                    .placeholder('common.placeholder.select_role'),
                FB.title(trans('admin.users.security')).class('col-span-full'),
                FB.fileUpload()
                    .key('identity_document')
                    .accept('image/*')
                    .maxFileSize(5 * 1024 * 1024)
                    .optional()
                    .class('col-span-full')
                    .existingMediaKey('identity_document_media'),
                FB.password()
                    .key('password')
                    .required(!isEdit.value)
                    .toggleMask()
                    .class('col-start-1')
                    .hint(isEdit.value ? 'admin.users.password_hint' : undefined)
                    .default(''),
                FB.password().key('password_confirmation').required(!isEdit.value).toggleMask().default(''),
            )
            .build();
    });

    defineExpose({ reset: () => formRef.value?.reset() });
</script>

<template>
    <div>
        <AvatarUpload
            v-if="isEdit && formRef?.remoteData"
            :avatar-url="(formRef.remoteData as any)?.avatar_url"
            :upload-url="adminUsers.uploadAvatar.url(userId!)"
            :delete-url="adminUsers.deleteAvatar.url(userId!)"
            :is-card="!inDialog"
            class="mb-6 pb-6 border-b border-surface-200 dark:border-surface-700"
        />

        <SkForm ref="formRef" :config="formConfig" @success="emit('success')" @cancel="emit('cancel')" />
    </div>
</template>
