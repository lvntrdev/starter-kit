<script setup lang="ts">
    import { TB } from '@lvntr/components/TabBuilder/core';
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import { usePage } from '@inertiajs/vue3';
    import PasswordTab from './components/PasswordTab.vue';
    import ProfileInfoTab from './components/ProfileInfoTab.vue';
    import SessionsTab from './components/SessionsTab.vue';
    import TwoFactorTab from './components/TwoFactorTab.vue';

    interface Props {
        twoFactorEnabled: boolean;
        twoFactorConfirmed: boolean;
    }

    const props = defineProps<Props>();

    const page = usePage<{ features: { two_factor: boolean } }>();

    const tabConfig = TB.tabs()
        .vertical()
        .addTabs(
            TB.item().key('general').label('sk-profile.tabs.general').icon('pi pi-user'),
            TB.item().key('password').label('sk-profile.tabs.password').icon('pi pi-lock'),
            TB.item()
                .key('security')
                .label('sk-profile.tabs.security')
                .icon('pi pi-shield')
                .visible(page.props.features.two_factor),
            TB.item().key('sessions').label('sk-profile.tabs.sessions').icon('pi pi-desktop'),
        )
        .build();
</script>

<template>
    <AdminLayout :title="$t('sk-profile.title')" :subtitle="$t('sk-profile.subtitle')">
        <SkTabs :config="tabConfig">
            <template #general>
                <ProfileInfoTab />
            </template>

            <template #password>
                <PasswordTab />
            </template>

            <template #security>
                <TwoFactorTab
                    :two-factor-enabled="props.twoFactorEnabled"
                    :two-factor-confirmed="props.twoFactorConfirmed"
                />
            </template>

            <template #sessions>
                <SessionsTab />
            </template>
        </SkTabs>
    </AdminLayout>
</template>
