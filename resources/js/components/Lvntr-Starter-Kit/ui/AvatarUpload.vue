<script setup lang="ts">
    import { router } from '@inertiajs/vue3';
    import { useConfirm } from '@/composables/useConfirm';
    import { trans } from 'laravel-vue-i18n';
    import SkCard from './SkCard.vue';

    interface Props {
        avatarUrl?: string | null;
        uploadUrl: string;
        deleteUrl: string;
        /**
         * Inline başlık.
         * - verilmezse $t('sk-avatar.title') basılır
         * - '' (boş string) verilirse başlık tamamen gizlenir
         */
        title?: string;
        /**
         * Açıklama satırı.
         * - verilmezse $t('sk-avatar.hint') basılır
         * - '' (boş string) verilirse açıklama tamamen gizlenir
         */
        subtitle?: string;
        /** Foto yokken avatar kutusunda gösterilecek baş harfler (örn: "AU"). */
        initials?: string | null;
        /** Dialog gibi yerlerde Card kabuğunu gizlemek için false. */
        isCard?: boolean;
    }

    const props = withDefaults(defineProps<Props>(), {
        avatarUrl: null,
        initials: null,
        isCard: true,
    });

    const { confirmDelete } = useConfirm();
    const currentUrl = ref<string | null>(props.avatarUrl);
    const uploading = ref(false);
    const fileInput = ref<HTMLInputElement | null>(null);

    watch(
        () => props.avatarUrl,
        (val) => {
            currentUrl.value = val ?? null;
        },
    );

    function getCsrfToken(): string | undefined {
        const match = document.cookie.match(/(^|;\s*)XSRF-TOKEN=([^;]*)/);
        return match ? decodeURIComponent(match[2]) : undefined;
    }

    function selectFile() {
        fileInput.value?.click();
    }

    async function onFileSelected(event: Event) {
        const input = event.target as HTMLInputElement;
        if (!input.files?.length) return;

        const file = input.files[0];
        input.value = '';

        // Instant preview
        const reader = new FileReader();
        reader.onload = (e) => {
            currentUrl.value = e.target?.result as string;
        };
        reader.readAsDataURL(file);

        // Upload
        uploading.value = true;
        try {
            const formData = new FormData();
            formData.append('avatar', file);

            const headers: Record<string, string> = {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            };
            const xsrf = getCsrfToken();
            if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;

            const response = await fetch(props.uploadUrl, {
                method: 'POST',
                headers,
                credentials: 'same-origin',
                body: formData,
            });

            if (response.ok) {
                const json = await response.json();
                currentUrl.value = json.data?.avatar_url ?? currentUrl.value;
                router.reload({ only: ['auth'] });
            } else {
                currentUrl.value = props.avatarUrl ?? null;
            }
        } catch {
            currentUrl.value = props.avatarUrl ?? null;
        } finally {
            uploading.value = false;
        }
    }

    function removeAvatar() {
        confirmDelete(async () => {
            uploading.value = true;
            try {
                const headers: Record<string, string> = {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                };
                const xsrf = getCsrfToken();
                if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;

                const response = await fetch(props.deleteUrl, {
                    method: 'DELETE',
                    headers,
                    credentials: 'same-origin',
                });

                if (response.ok || response.status === 204) {
                    currentUrl.value = null;
                    router.reload({ only: ['auth'] });
                }
            } catch {
                // silently fail
            } finally {
                uploading.value = false;
            }
        }, trans('sk-avatar.remove_confirm'));
    }
</script>

<template>
    <!-- Avatar chrome above the form, not the page content card. -->
    <SkCard
        :transparent="!isCard"
        :host-page-header="false"
    >
        <template #content>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                <!-- Avatar -->
                <button
                    type="button"
                    class="group relative flex size-12 shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded bg-primary-50 transition-colors hover:bg-primary-100 dark:bg-primary-900/30 dark:hover:bg-primary-900/50"
                    :aria-label="$t('sk-avatar.change')"
                    @click="selectFile"
                >
                    <img v-if="currentUrl" :src="currentUrl" alt="" class="size-full object-cover">
                    <span
                        v-else-if="initials"
                        class="text-base font-semibold uppercase text-primary-600 dark:text-primary-300"
                    >
                        {{ initials }}
                    </span>
                    <i v-else class="pi pi-user text-lg text-primary-400 dark:text-primary-500" />

                    <div
                        class="absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 transition-opacity group-hover:opacity-100"
                    >
                        <i v-if="uploading" class="pi pi-spin pi-spinner text-base text-white" />
                        <i v-else class="pi pi-camera text-base text-white" />
                    </div>
                </button>

                <!-- Title + hint -->
                <div v-if="title !== '' || subtitle !== ''" class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <div
                        v-if="title !== ''"
                        class="text-sm font-semibold text-surface-900 dark:text-surface-100"
                    >
                        {{ title ?? $t('sk-avatar.title') }}
                    </div>
                    <small v-if="subtitle !== ''" class="text-surface-500 dark:text-surface-400">
                        {{ subtitle ?? $t('sk-avatar.hint') }}
                    </small>
                </div>

                <!-- Actions -->
                <div class="flex shrink-0 items-center gap-2">
                    <Button
                        v-if="currentUrl"
                        type="button"
                        :label="$t('sk-avatar.remove')"
                        icon="pi pi-trash"
                        size="small"
                        severity="danger"
                        text
                        :disabled="uploading"
                        @click="removeAvatar"
                    />
                    <Button
                        type="button"
                        :label="$t('sk-avatar.change')"
                        icon="pi pi-upload"
                        size="small"
                        outlined
                        :loading="uploading"
                        @click="selectFile"
                    />
                </div>

                <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onFileSelected">
            </div>
        </template>
    </SkCard>
</template>
