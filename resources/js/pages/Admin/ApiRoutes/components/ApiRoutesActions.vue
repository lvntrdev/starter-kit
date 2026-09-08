<script setup lang="ts">
    import { useCan } from '@/composables/useCan';

    interface Props {
        /**
         * Panel URL resolved server-side from the `api-dock.docs` route, since
         * `api-dock.route_prefix` is configurable. Null/undefined when api-dock
         * is absent or disabled — the button is then hidden rather than linking
         * somewhere that 404s.
         */
        apiDocsUrl?: string | null;
        regenerating?: boolean;
        syncingPostman?: boolean;
        syncingApidog?: boolean;
    }

    withDefaults(defineProps<Props>(), {
        apiDocsUrl: null,
        regenerating: false,
        syncingPostman: false,
        syncingApidog: false,
    });

    defineEmits<{
        settings: [];
        regenerate: [];
        'sync-postman': [];
        'sync-apidog': [];
    }>();

    const { can } = useCan();
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <Button
            v-if="can('settings.update')"
            :label="$t('sk-api-route.settings')"
            icon="pi pi-cog"
            severity="secondary"
            outlined
            @click="$emit('settings')"
        />
        <span
            v-if="can('settings.update')"
            class="mx-1 h-6 w-px self-center bg-surface-200 dark:bg-surface-700"
        />
        <Button
            :label="$t('sk-api-route.regenerate_docs')"
            icon="pi pi-sync"
            severity="amber"
            outlined
            :loading="regenerating"
            @click="$emit('regenerate')"
        />
        <Button
            :label="$t('sk-api-route.sync_postman')"
            icon="pi pi-send"
            severity="sky"
            outlined
            :loading="syncingPostman"
            @click="$emit('sync-postman')"
        />
        <Button
            :label="$t('sk-api-route.sync_apidog')"
            icon="pi pi-share-alt"
            severity="violet"
            outlined
            :loading="syncingApidog"
            @click="$emit('sync-apidog')"
        />
        <a v-if="apiDocsUrl" :href="apiDocsUrl" target="_blank" rel="noopener noreferrer">
            <Button
                :label="$t('sk-api-route.open_api_docs')"
                icon="pi pi-book"
                severity="blue"
                outlined
            />
        </a>
    </div>
</template>
