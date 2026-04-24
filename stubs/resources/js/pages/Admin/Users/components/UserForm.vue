<!-- resources/js/components/Admin/UserForm.vue -->
<script setup lang="ts">
    import adminUsers from '@/routes/users';
    import { FB } from '@lvntr/components/FormBuilder/core';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';

    interface Props {
        userId?: string | null;
        inDialog?: boolean;
        showBack?: boolean;
        roleOptions?: { label: string; value: string }[];
    }

    const props = withDefaults(defineProps<Props>(), {
        userId: null,
        inDialog: false,
        showBack: false,
        roleOptions: () => [],
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
                FB.inputText().key('email').inputType('email').class('col-span-full'),
                FB.select().key('role').options(props.roleOptions).filter(true),
                FB.select().key('status').default('active').definitionOptions('userStatus'),
                FB.password()
                    .key('password')
                    .generator()
                    .required(!isEdit.value)
                    .toggleMask()
                    .hint(isEdit.value ? 'sk-user.password_hint' : undefined)
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
            :avatar-url="(formRef.remoteData as { avatar_url?: string | null } | null)?.avatar_url"
            :upload-url="adminUsers.uploadAvatar.url(userId!)"
            :delete-url="adminUsers.deleteAvatar.url(userId!)"
            :is-card="!inDialog"
            class="mb-6 pb-6 border-b border-surface-200 dark:border-surface-700"
        />
        <SkForm ref="formRef" :config="formConfig" @success="emit('success')" @cancel="emit('cancel')" />
    </div>
</template>
