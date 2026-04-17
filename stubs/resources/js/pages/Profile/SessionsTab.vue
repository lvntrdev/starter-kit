<script setup lang="ts">
    import { useForm } from '@inertiajs/vue3';
    import axios, { type AxiosError } from 'axios';
    import browserSessions from '@/routes/browser-sessions';

    interface SessionDevice {
        browser: string;
        platform: string;
        desktop: boolean;
        mobile: boolean;
    }

    interface BrowserSession {
        device: SessionDevice;
        ip_address: string;
        is_current_device: boolean;
        last_active: string;
    }

    const sessions = ref<BrowserSession[]>([]);
    const sessionsLoading = ref(false);
    const logoutProcessing = ref(false);
    const logoutSuccess = ref(false);

    const showLogoutDialog = ref(false);
    const logoutPasswordError = ref('');

    const logoutPasswordForm = useForm({
        password: '',
    });

    async function fetchSessions() {
        sessionsLoading.value = true;
        try {
            const response = await axios.get(browserSessions.index.url());
            sessions.value = response.data.data;
        } finally {
            sessionsLoading.value = false;
        }
    }

    function openLogoutOtherSessionsDialog() {
        logoutPasswordForm.reset();
        logoutPasswordError.value = '';
        showLogoutDialog.value = true;
    }

    async function logoutOtherSessions() {
        logoutProcessing.value = true;
        logoutPasswordError.value = '';

        try {
            await axios.delete(browserSessions.destroy.url(), {
                data: { password: logoutPasswordForm.password },
            });

            showLogoutDialog.value = false;
            logoutSuccess.value = true;
            setTimeout(() => (logoutSuccess.value = false), 3000);
            await fetchSessions();
        } catch (err) {
            const error = err as AxiosError<{ errors?: { password?: string[] } }>;
            if (error.response?.status === 422) {
                logoutPasswordError.value = error.response.data?.errors?.password?.[0] ?? 'Incorrect password.';
            }
        } finally {
            logoutProcessing.value = false;
        }
    }

    onMounted(() => {
        fetchSessions();
    });
</script>

<template>
    <div>
        <Card>
            <template #title>
                {{ $t('sk-profile.sessions_title') }}
            </template>
            <template #subtitle>
                {{ $t('sk-profile.sessions_subtitle') }}
            </template>
            <template #content>
                <div class="space-y-4">
                    <p class="text-sm text-surface-500 dark:text-surface-400">
                        {{ $t('sk-profile.sessions_description') }}
                    </p>

                    <!-- Loading State -->
                    <div v-if="sessionsLoading" class="space-y-3">
                        <div v-for="i in 2" :key="i" class="flex items-center gap-3">
                            <div class="h-8 w-8 animate-pulse rounded-full bg-surface-200 dark:bg-surface-700" />
                            <div class="flex-1 space-y-1">
                                <div class="h-4 w-40 animate-pulse rounded bg-surface-200 dark:bg-surface-700" />
                                <div class="h-3 w-24 animate-pulse rounded bg-surface-200 dark:bg-surface-700" />
                            </div>
                        </div>
                    </div>

                    <!-- Session List -->
                    <div v-else-if="sessions.length > 0" class="space-y-3">
                        <div v-for="(session, index) in sessions" :key="index" class="flex items-center gap-3">
                            <!-- Device Icon -->
                            <div class="shrink-0 text-surface-400 dark:text-surface-500">
                                <svg
                                    v-if="session.device.desktop"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="1.5"
                                    stroke="currentColor"
                                    class="h-8 w-8"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25A2.25 2.25 0 0 1 5.25 3h13.5A2.25 2.25 0 0 1 21 5.25Z"
                                    />
                                </svg>
                                <svg
                                    v-else
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="1.5"
                                    stroke="currentColor"
                                    class="h-8 w-8"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M10.5 1.5H8.25A2.25 2.25 0 0 0 6 3.75v16.5a2.25 2.25 0 0 0 2.25 2.25h7.5A2.25 2.25 0 0 0 18 20.25V3.75a2.25 2.25 0 0 0-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3"
                                    />
                                </svg>
                            </div>

                            <!-- Session Info -->
                            <div class="flex-1">
                                <div class="text-sm font-medium text-surface-700 dark:text-surface-300">
                                    {{ session.device.platform }} — {{ session.device.browser }}
                                </div>
                                <div class="text-xs text-surface-500 dark:text-surface-400">
                                    {{ session.ip_address }}
                                    <span v-if="session.is_current_device" class="font-semibold text-green-500">
                                        — {{ $t('sk-profile.sessions_this_device') }}
                                    </span>
                                    <span v-else>
                                        — {{ $t('sk-profile.sessions_last_active', { time: session.last_active }) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-3">
                        <Button
                            :label="$t('sk-profile.sessions_logout')"
                            icon="pi pi-sign-out"
                            severity="danger"
                            :loading="logoutProcessing"
                            @click="openLogoutOtherSessionsDialog"
                        />
                        <Transition
                            enter-active-class="transition duration-300"
                            enter-from-class="opacity-0"
                            leave-active-class="transition duration-300"
                            leave-to-class="opacity-0"
                        >
                            <span v-if="logoutSuccess" class="text-sm text-green-600 dark:text-green-400">
                                {{ $t('sk-profile.sessions_done') }}
                            </span>
                        </Transition>
                    </div>
                </div>
            </template>
        </Card>

        <!-- Log Out Other Sessions Dialog -->
        <Dialog
            v-model:visible="showLogoutDialog"
            :header="$t('sk-profile.sessions_logout')"
            modal
            :style="{ width: '25rem' }"
        >
            <p class="mb-4 text-sm text-surface-500 dark:text-surface-400">
                {{ $t('sk-profile.sessions_logout_confirm') }}
            </p>

            <form @submit.prevent="logoutOtherSessions">
                <div class="flex flex-col gap-1">
                    <label for="logout_password" class="text-sm font-medium text-surface-700 dark:text-surface-300">
                        {{ $t('sk-common.password') }}
                    </label>
                    <Password
                        v-model="logoutPasswordForm.password"
                        input-id="logout_password"
                        :invalid="!!logoutPasswordError"
                        :feedback="false"
                        autocomplete="current-password"
                        toggle-mask
                        fluid
                        autofocus
                    />
                    <small v-if="logoutPasswordError" class="text-red-500">
                        {{ logoutPasswordError }}
                    </small>
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <Button
                        type="button"
                        :label="$t('sk-common.cancel')"
                        severity="secondary"
                        @click="showLogoutDialog = false"
                    />
                    <Button
                        type="submit"
                        :label="$t('sk-profile.sessions_logout')"
                        icon="pi pi-sign-out"
                        severity="danger"
                        :loading="logoutProcessing"
                    />
                </div>
            </form>
        </Dialog>
    </div>
</template>
