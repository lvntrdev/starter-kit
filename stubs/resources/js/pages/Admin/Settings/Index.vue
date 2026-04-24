<script setup lang="ts">
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import { TB } from '@lvntr/components/TabBuilder/core';
    import ApiClientsTab from './components/ApiClientsTab.vue';
    import AuthTab from './components/AuthTab.vue';
    import FileManagerTab from './components/FileManagerTab.vue';
    import GeneralTab from './components/GeneralTab.vue';
    import MailTab from './components/MailTab.vue';
    import StorageTab from './components/StorageTab.vue';
    import TurnstileTab from './components/TurnstileTab.vue';

    interface Props {
        settings: {
            general: {
                app_name: string;
                timezone: string;
                languages: string[];
                logo_url: string | null;
                welcome_message: string | null;
            };
            auth: {
                registration: boolean;
                email_verification: boolean;
                two_factor: boolean;
                password_reset: boolean;
            };
            mail: {
                mailer: string;
                host: string | null;
                port: number | null;
                username: string | null;
                password: null;
                password_is_set: boolean;
                encryption: string | null;
                from_address: string;
                from_name: string;
            };
            storage: {
                media_disk: string;
                spaces_key: string | null;
                spaces_secret: null;
                spaces_secret_is_set: boolean;
                spaces_region: string | null;
                spaces_bucket: string | null;
                spaces_endpoint: string | null;
                spaces_url: string | null;
                aws_key: string | null;
                aws_secret: null;
                aws_secret_is_set: boolean;
                aws_region: string | null;
                aws_bucket: string | null;
                aws_url: string | null;
                aws_endpoint: string | null;
            };
            file_manager: {
                max_size_kb: number;
                accepted_mimes: string[];
                allow_video: boolean;
                allow_audio: boolean;
            };
            turnstile: {
                enabled: boolean;
                site_key: string | null;
                secret_key: null;
                secret_key_is_set: boolean;
            };
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
        };
        timezones: string[];
        availableLanguages: Record<string, string>;
    }

    const props = defineProps<Props>();

    const tabConfig = TB.tabs()
        .vertical()
        .addTabs(
            TB.item().key('general').label('sk-setting.tabs.general').icon('pi pi-cog'),
            TB.item().key('auth').label('sk-setting.tabs.auth').icon('pi pi-shield'),
            TB.item().key('mail').label('sk-setting.tabs.mail').icon('pi pi-envelope'),
            TB.item().key('storage').label('sk-setting.tabs.storage').icon('pi pi-cloud'),
            TB.item().key('file_manager').label('sk-setting.tabs.file_manager').icon('pi pi-sliders-h'),
            TB.item().key('turnstile').label('sk-setting.tabs.turnstile').icon('pi pi-shield'),
            TB.item().key('api_clients').label('sk-setting.tabs.api_clients').icon('pi pi-send'),
        )
        .build();
</script>

<template>
    <AdminLayout :title="$t('sk-setting.title')" :subtitle="$t('sk-setting.subtitle')">
        <SkTabs :config="tabConfig">
            <template #general>
                <GeneralTab
                    :settings="props.settings.general"
                    :timezones="props.timezones"
                    :available-languages="props.availableLanguages"
                />
            </template>

            <template #auth>
                <AuthTab :settings="props.settings.auth" />
            </template>

            <template #mail>
                <MailTab :settings="props.settings.mail" />
            </template>

            <template #storage>
                <StorageTab :settings="props.settings.storage" />
            </template>

            <template #file_manager>
                <FileManagerTab :settings="props.settings.file_manager" />
            </template>

            <template #turnstile>
                <TurnstileTab :settings="props.settings.turnstile" />
            </template>

            <template #api_clients>
                <ApiClientsTab :postman="props.settings.postman" :apidog="props.settings.apidog" />
            </template>
        </SkTabs>
    </AdminLayout>
</template>
