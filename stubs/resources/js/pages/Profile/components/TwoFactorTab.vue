<script setup lang="ts">
    import { useForm, router } from '@inertiajs/vue3';
    import axios, { type AxiosError } from 'axios';

    interface Props {
        twoFactorEnabled: boolean;
        twoFactorConfirmed: boolean;
    }

    const props = defineProps<Props>();

    const twoFactorProcessing = ref(false);
    const qrCodeSvg = ref('');
    const setupKey = ref('');
    const recoveryCodes = ref<string[]>([]);
    const showRecoveryCodes = ref(false);

    /**
     * Render the Fortify QR SVG through an <img src="data:..."> element
     * rather than v-html. An <img> sandbox neutralises any inline <script>
     * or event handlers that a compromised intermediary could smuggle in.
     */
    const qrCodeDataUrl = computed<string>(() => {
        if (!qrCodeSvg.value) return '';
        try {
            const encoded = window.btoa(unescape(encodeURIComponent(qrCodeSvg.value)));
            return `data:image/svg+xml;base64,${encoded}`;
        } catch {
            return '';
        }
    });

    const confirmForm = useForm({
        code: '',
    });

    // ── Password Confirmation Dialog ─────────────────────────────────────────────

    const showPasswordDialog = ref(false);
    const passwordConfirmError = ref('');
    const passwordConfirmProcessing = ref(false);
    type PendingAction = 'enable' | 'disable' | 'show-codes' | 'regenerate-codes';
    const pendingAction = ref<PendingAction | null>(null);

    const passwordConfirmForm = useForm({
        password: '',
    });

    function requirePasswordConfirmation(action: PendingAction) {
        pendingAction.value = action;
        passwordConfirmForm.reset();
        passwordConfirmError.value = '';
        showPasswordDialog.value = true;
    }

    async function submitPasswordConfirmation() {
        passwordConfirmProcessing.value = true;
        passwordConfirmError.value = '';

        try {
            await axios.post('/user/confirm-password', {
                password: passwordConfirmForm.password,
            });

            showPasswordDialog.value = false;

            if (pendingAction.value === 'enable') {
                await enableTwoFactor();
            } else if (pendingAction.value === 'disable') {
                await disableTwoFactor();
            } else if (pendingAction.value === 'show-codes') {
                await fetchRecoveryCodes();
                showRecoveryCodes.value = true;
            } else if (pendingAction.value === 'regenerate-codes') {
                await regenerateRecoveryCodes();
                showRecoveryCodes.value = true;
            }
        } catch (err) {
            const error = err as AxiosError<{ errors?: { password?: string[] } }>;
            if (error.response?.status === 422) {
                passwordConfirmError.value = error.response.data?.errors?.password?.[0] ?? 'The password is incorrect.';
            }
        } finally {
            passwordConfirmProcessing.value = false;
            pendingAction.value = null;
        }
    }

    async function enableTwoFactor() {
        twoFactorProcessing.value = true;

        try {
            if (!props.twoFactorEnabled) {
                await axios.post('/user/two-factor-authentication');
                // Wait for the new props to land before hitting the QR endpoint
                // — otherwise Fortify may still report "not enabled" when we ask
                // for the QR / secret key.
                await new Promise<void>((resolve) => {
                    router.reload({
                        only: ['twoFactorEnabled', 'twoFactorConfirmed'],
                        onFinish: () => resolve(),
                    });
                });
            }

            await loadQrAndSetupKey();
        } finally {
            twoFactorProcessing.value = false;
        }
    }

    async function loadQrAndSetupKey() {
        const [qrResponse, keyResponse] = await Promise.all([
            axios.get('/user/two-factor-qr-code'),
            axios.get('/user/two-factor-secret-key'),
        ]);
        qrCodeSvg.value = qrResponse.data.svg;
        setupKey.value = keyResponse.data.secretKey;
    }

    async function confirmTwoFactor() {
        confirmForm.post('/user/confirmed-two-factor-authentication', {
            preserveScroll: true,
            onSuccess: async () => {
                confirmForm.reset();
                qrCodeSvg.value = '';
                setupKey.value = '';
                await fetchRecoveryCodes();
                showRecoveryCodes.value = true;
                router.reload({ only: ['twoFactorEnabled', 'twoFactorConfirmed'] });
            },
        });
    }

    async function disableTwoFactor() {
        twoFactorProcessing.value = true;

        try {
            await axios.delete('/user/two-factor-authentication');

            qrCodeSvg.value = '';
            setupKey.value = '';
            showRecoveryCodes.value = false;
            recoveryCodes.value = [];

            router.reload({ only: ['twoFactorEnabled', 'twoFactorConfirmed'] });
        } finally {
            twoFactorProcessing.value = false;
        }
    }

    async function fetchRecoveryCodes() {
        const response = await axios.get('/user/two-factor-recovery-codes');
        recoveryCodes.value = response.data;
    }

    async function regenerateRecoveryCodes() {
        await axios.post('/user/two-factor-recovery-codes');
        await fetchRecoveryCodes();
    }

    async function showExistingRecoveryCodes() {
        if (showRecoveryCodes.value) {
            showRecoveryCodes.value = false;
            return;
        }
        requirePasswordConfirmation('show-codes');
    }

    onMounted(async () => {
        if (props.twoFactorEnabled && !props.twoFactorConfirmed) {
            try {
                await loadQrAndSetupKey();
            } catch {
                // Password confirmation may have expired
            }
        }
    });
</script>

<template>
    <div>
        <Card>
            <template #title>
                {{ $t('sk-profile.two_factor_title') }}
            </template>
            <template #subtitle>
                {{ $t('sk-profile.two_factor_subtitle') }}
            </template>
            <template #content>
                <div class="space-y-4">
                    <!-- Status: Enabled & Confirmed -->
                    <div
                        v-if="props.twoFactorConfirmed"
                        class="flex items-center gap-2 text-sm font-medium text-green-600 dark:text-green-400"
                    >
                        <i class="pi pi-check-circle" />
                        {{ $t('sk-profile.two_factor_enabled') }}
                    </div>

                    <!-- Status: Not Enabled -->
                    <div v-if="!props.twoFactorEnabled" class="text-sm text-surface-500 dark:text-surface-400">
                        {{ $t('sk-profile.two_factor_disabled') }}
                    </div>

                    <!-- QR Code Setup (enabled but not confirmed OR just enabled) -->
                    <template v-if="props.twoFactorEnabled && !props.twoFactorConfirmed">
                        <div class="flex items-center gap-2 text-sm font-medium text-yellow-600 dark:text-yellow-400">
                            <i class="pi pi-exclamation-triangle" />
                            {{ $t('sk-profile.two_factor_finish') }}
                        </div>

                        <!-- QR loaded -->
                        <div v-if="qrCodeSvg" class="space-y-4">
                            <p class="text-sm text-surface-600 dark:text-surface-400">
                                {{ $t('sk-profile.two_factor_scan') }}
                            </p>

                            <div class="inline-block rounded-lg bg-white p-4">
                                <img
                                    v-if="qrCodeDataUrl"
                                    :src="qrCodeDataUrl"
                                    :alt="$t('sk-profile.two_factor_scan')"
                                    class="h-48 w-48"
                                >
                            </div>

                            <div v-if="setupKey" class="text-sm text-surface-600 dark:text-surface-400">
                                <p class="font-medium">
                                    {{ $t('sk-profile.two_factor_manual') }}
                                </p>
                                <code
                                    class="mt-1 block rounded bg-surface-100 px-3 py-2 font-mono text-sm dark:bg-surface-800"
                                >
                                    {{ setupKey }}
                                </code>
                            </div>

                            <!-- Confirm Code -->
                            <form class="space-y-3" @submit.prevent="confirmTwoFactor">
                                <div class="flex flex-col gap-1">
                                    <label
                                        for="confirm_code"
                                        class="text-sm font-medium text-surface-700 dark:text-surface-300"
                                    >
                                        {{ $t('sk-profile.two_factor_code_label') }}
                                    </label>
                                    <InputText
                                        id="confirm_code"
                                        v-model="confirmForm.code"
                                        type="text"
                                        inputmode="numeric"
                                        placeholder="000000"
                                        :invalid="!!confirmForm.errors.code"
                                        autocomplete="one-time-code"
                                    />
                                    <small v-if="confirmForm.errors.code" class="text-red-500">
                                        {{ confirmForm.errors.code }}
                                    </small>
                                </div>

                                <div class="flex items-center gap-2">
                                    <Button
                                        type="submit"
                                        :label="$t('sk-profile.two_factor_verify')"
                                        icon="pi pi-check"
                                        :loading="confirmForm.processing"
                                    />
                                    <Button
                                        type="button"
                                        :label="$t('sk-profile.two_factor_cancel_setup')"
                                        icon="pi pi-times"
                                        severity="secondary"
                                        :loading="twoFactorProcessing"
                                        @click="requirePasswordConfirmation('disable')"
                                    />
                                </div>
                            </form>
                        </div>

                        <!-- QR not loaded yet (password confirmation expired) -->
                        <div v-else class="space-y-3">
                            <p class="text-sm text-surface-500 dark:text-surface-400">
                                {{ $t('sk-profile.two_factor_expired') }}
                            </p>
                            <div class="flex items-center gap-2">
                                <Button
                                    :label="$t('sk-profile.two_factor_continue')"
                                    icon="pi pi-refresh"
                                    @click="requirePasswordConfirmation('enable')"
                                />
                                <Button
                                    :label="$t('sk-profile.two_factor_cancel_setup')"
                                    icon="pi pi-times"
                                    severity="secondary"
                                    :loading="twoFactorProcessing"
                                    @click="requirePasswordConfirmation('disable')"
                                />
                            </div>
                        </div>
                    </template>

                    <!-- Recovery Codes (shown after confirmation) -->
                    <div v-if="showRecoveryCodes && recoveryCodes.length > 0" class="space-y-3">
                        <p class="text-sm font-medium text-surface-700 dark:text-surface-300">
                            {{ $t('sk-profile.two_factor_recovery_info') }}
                        </p>
                        <div class="rounded-lg bg-surface-100 p-4 dark:bg-surface-800">
                            <code v-for="code in recoveryCodes" :key="code" class="block font-mono text-sm">
                                {{ code }}
                            </code>
                        </div>
                        <Button
                            :label="$t('sk-profile.two_factor_regenerate')"
                            icon="pi pi-refresh"
                            severity="secondary"
                            size="small"
                            @click="regenerateRecoveryCodes"
                        />
                    </div>

                    <!-- Actions: Not enabled -->
                    <div v-if="!props.twoFactorEnabled" class="flex flex-wrap items-center gap-2">
                        <Button
                            :label="$t('sk-profile.two_factor_enable')"
                            icon="pi pi-shield"
                            :loading="twoFactorProcessing"
                            @click="requirePasswordConfirmation('enable')"
                        />
                    </div>

                    <!-- Actions: Enabled & Confirmed -->
                    <div v-if="props.twoFactorConfirmed" class="flex flex-wrap items-center gap-2">
                        <Button
                            :label="$t('sk-profile.two_factor_show_codes')"
                            icon="pi pi-key"
                            severity="secondary"
                            @click="showExistingRecoveryCodes"
                        />
                        <Button
                            :label="$t('sk-profile.two_factor_disable')"
                            icon="pi pi-times"
                            severity="danger"
                            :loading="twoFactorProcessing"
                            @click="requirePasswordConfirmation('disable')"
                        />
                    </div>
                </div>
            </template>
        </Card>

        <!-- Password Confirmation Dialog -->
        <Dialog
            v-model:visible="showPasswordDialog"
            :header="$t('sk-profile.confirm_password_title')"
            modal
            :style="{ width: '25rem' }"
        >
            <p class="mb-4 text-sm text-surface-500 dark:text-surface-400">
                {{ $t('sk-profile.confirm_password_message') }}
            </p>

            <form @submit.prevent="submitPasswordConfirmation">
                <div class="flex flex-col gap-1">
                    <label
                        for="confirm_password_dialog"
                        class="text-sm font-medium text-surface-700 dark:text-surface-300"
                    >
                        {{ $t('sk-common.password') }}
                    </label>
                    <Password
                        v-model="passwordConfirmForm.password"
                        input-id="confirm_password_dialog"
                        :invalid="!!passwordConfirmError"
                        :feedback="false"
                        autocomplete="current-password"
                        toggle-mask
                        fluid
                        autofocus
                    />
                    <small v-if="passwordConfirmError" class="text-red-500">
                        {{ passwordConfirmError }}
                    </small>
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <Button
                        type="button"
                        :label="$t('sk-button.cancel')"
                        severity="secondary"
                        @click="showPasswordDialog = false"
                    />
                    <Button
                        type="submit"
                        :label="$t('sk-button.confirm')"
                        icon="pi pi-lock"
                        :loading="passwordConfirmProcessing"
                    />
                </div>
            </form>
        </Dialog>
    </div>
</template>
