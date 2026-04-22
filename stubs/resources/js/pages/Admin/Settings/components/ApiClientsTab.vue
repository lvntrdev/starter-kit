<script setup lang="ts">
    import { FB } from '@lvntr/components/FormBuilder/core';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
    import adminSettings from '@/routes/settings';
    import { trans } from 'laravel-vue-i18n';

    interface Props {
        postman: {
            workspace_id: string | null;
            collection_id: string | null;
            api_key: null;
            api_key_is_set: boolean;
        };
        apidog: {
            project_id: string | null;
            access_token: null;
            access_token_is_set: boolean;
        };
    }

    const props = defineProps<Props>();

    const postmanApiKeyPlaceholder = computed(() => (props.postman.api_key_is_set ? '••••••••' : ''));
    const apidogTokenPlaceholder = computed(() => (props.apidog.access_token_is_set ? '••••••••' : ''));

    const postmanFormConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(1)
            .cardTitle('sk-setting.postman.title')
            .cardSubtitle('sk-setting.postman.subtitle')
            .initialData({
                workspace_id: props.postman.workspace_id ?? '',
                api_key: '',
            })
            .submit({
                url: adminSettings.update.postman.url(),
                method: 'put',
                preserveScroll: true,
            })
            .addFields(
                FB.password()
                    .key('api_key')
                    .label('sk-setting.postman.api_key_label')
                    .hint(trans('sk-setting.postman.api_key_hint'))
                    .toggleMask()
                    .placeholder(postmanApiKeyPlaceholder.value),
                FB.inputText()
                    .key('workspace_id')
                    .label('sk-setting.postman.workspace_id_label')
                    .hint(trans('sk-setting.postman.workspace_id_hint')),
            )
            .build(),
    );

    const apidogFormConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(1)
            .cardTitle('sk-setting.apidog.title')
            .cardSubtitle('sk-setting.apidog.subtitle')
            .initialData({
                project_id: props.apidog.project_id ?? '',
                access_token: '',
            })
            .submit({
                url: adminSettings.update.apidog.url(),
                method: 'put',
                preserveScroll: true,
            })
            .addFields(
                FB.password()
                    .key('access_token')
                    .label('sk-setting.apidog.access_token_label')
                    .hint(trans('sk-setting.apidog.access_token_hint'))
                    .toggleMask()
                    .placeholder(apidogTokenPlaceholder.value),
                FB.inputText()
                    .key('project_id')
                    .label('sk-setting.apidog.project_id_label')
                    .hint(trans('sk-setting.apidog.project_id_hint')),
            )
            .build(),
    );
</script>

<template>
    <div id="api_clients" class="space-y-6">
        <section id="postman" class="space-y-3">
            <SkForm :config="postmanFormConfig" />
            <div
                v-if="postman.collection_id"
                class="rounded-md border border-surface-200 bg-surface-50 p-3 text-xs text-surface-600 dark:border-surface-700 dark:bg-surface-900 dark:text-surface-400"
            >
                <div class="mb-1 font-medium">
                    {{ $t('sk-setting.postman.collection_id_label') }}
                </div>
                <code class="font-mono break-all">{{ postman.collection_id }}</code>
                <p class="mt-2 text-surface-500">
                    {{ $t('sk-setting.postman.collection_id_hint') }}
                </p>
            </div>
        </section>

        <section id="apidog" class="space-y-3">
            <SkForm :config="apidogFormConfig" />
        </section>
    </div>
</template>
