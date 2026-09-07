<!-- resources/js/components/Admin/UserForm.vue -->
<script setup lang="ts">
    import adminUsers from '@/routes/users';
    import { usePage } from '@inertiajs/vue3';
    import { FB } from '@lvntr/components/FormBuilder/core';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
    import { trans } from 'laravel-vue-i18n';

    interface Props {
        userId?: string | null;
        inDialog?: boolean;
        roleOptions?: { label: string; value: string }[];
        timezones?: string[];
    }

    const props = withDefaults(defineProps<Props>(), {
        userId: null,
        inDialog: false,
        roleOptions: () => [],
        timezones: () => [],
    });

    const emit = defineEmits<{
        success: [];
        cancel: [];
    }>();

    const formRef = ref<InstanceType<typeof SkForm>>();
    const page = usePage<{ timezone: string }>();
    const isEdit = computed(() => !!props.userId);
    const timezoneOptions = computed(() => [
        {
            label: trans('sk-user.timezone_site_default', { timezone: page.props.timezone }),
            value: null,
        },
        ...props.timezones.map((timezone) => ({ label: timezone, value: timezone })),
    ]);

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
                FB.select()
                    .key('timezone')
                    .label('sk-user.timezone')
                    .options(timezoneOptions.value)
                    .filter(true)
                    .icon('pi pi-clock')
                    .hint('sk-user.timezone_hint')
                    .optional()
                    .default(null)
                    .class('col-span-full'),
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
    <SkForm
      ref="formRef"
      :config="formConfig"
      @success="emit('success')"
      @cancel="emit('cancel')"
    />
  </div>
</template>
