<script setup lang="ts">
    import { TB } from '@lvntr/components/TabBuilder/core';
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import AuthTab from './AuthTab.vue';
    import GeneralTab from './GeneralTab.vue';
    import MailTab from './MailTab.vue';
    import StorageTab from './StorageTab.vue';

    interface Props {
        settings: {
            general: {
                app_name: string;
                app_url: string;
                timezone: string;
                languages: string[];
                debug: boolean;
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
                password: string | null;
                encryption: string | null;
                from_address: string;
                from_name: string;
            };
            storage: {
                media_disk: string;
                spaces_key: string | null;
                spaces_secret: string | null;
                spaces_region: string | null;
                spaces_bucket: string | null;
                spaces_endpoint: string | null;
                spaces_url: string | null;
                aws_key: string | null;
                aws_secret: string | null;
                aws_region: string | null;
                aws_bucket: string | null;
                aws_url: string | null;
                aws_endpoint: string | null;
            };
        };
        timezones: string[];
        availableLanguages: Record<string, string>;
    }

    const props = defineProps<Props>();

    const tabConfig = TB.tabs()
        .vertical()
        .addTabs(
            TB.item().key('general').label('admin.settings.tabs.general').icon('pi pi-cog'),
            TB.item().key('auth').label('admin.settings.tabs.auth').icon('pi pi-shield'),
            TB.item().key('mail').label('admin.settings.tabs.mail').icon('pi pi-envelope'),
            TB.item().key('storage').label('admin.settings.tabs.storage').icon('pi pi-cloud'),
        )
        .build();
</script>

<template>
    <AdminLayout :title="$t('admin.settings.title')" :subtitle="$t('admin.settings.subtitle')">
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
        </SkTabs>
    </AdminLayout>
</template>
