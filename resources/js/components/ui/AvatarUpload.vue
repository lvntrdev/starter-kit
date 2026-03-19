<script setup lang="ts">
    import { router } from '@inertiajs/vue3';
    import { useConfirm } from '@/composables/useConfirm';
    import { trans } from 'laravel-vue-i18n';

    interface Props {
        avatarUrl?: string | null;
        uploadUrl: string;
        deleteUrl: string;
        title?: string;
        subtitle?: string;
        isCard?: boolean;
    }

    const props = withDefaults(defineProps<Props>(), {
        avatarUrl: null,
        title: '',
        subtitle: '',
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
                router.reload({ preserveScroll: true });
            } else {
                currentUrl.value = props.avatarUrl ?? null;
            }
        } catch {
            currentUrl.value = props.avatarUrl ?? null;
        } finally {
            uploading.value = false;
        }
    }

    const transparentCard = { style: 'background: transparent; box-shadow: none; border: 0; padding: 0' };
    const cardPt = computed(() => {
        if (!props.isCard) {
            return {
                root: transparentCard,
                body: { style: 'padding: 0' },
                content: { style: 'padding: 0' },
            };
        }
        return {};
    });

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
                    router.reload({ preserveScroll: true });
                }
            } catch {
                // silently fail
            } finally {
                uploading.value = false;
            }
        }, trans('admin.avatar.remove_confirm'));
    }
</script>

<template>
    <Card :pt="cardPt">
        <template v-if="isCard && title" #title>
            {{ title }}
        </template>
        <template v-if="isCard && subtitle" #subtitle>
            {{ subtitle }}
        </template>
        <template #content>
            <div class="flex flex-col items-center gap-3 sm:flex-row">
                <div
                    class="relative flex size-24 shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded-full bg-surface-100 dark:bg-surface-800"
                    @click="selectFile"
                >
                    <img v-if="currentUrl" :src="currentUrl" alt="Avatar" class="size-full object-cover">
                    <i v-else class="pi pi-user text-3xl text-surface-400" />
                    <div
                        class="absolute inset-0 flex items-center justify-center rounded-full bg-black/40 opacity-0 transition-opacity hover:opacity-100"
                    >
                        <i v-if="uploading" class="pi pi-spin pi-spinner text-lg text-white" />
                        <i v-else class="pi pi-camera text-lg text-white" />
                    </div>
                </div>
                <div class="flex flex-col gap-1">
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            :label="$t('admin.avatar.change')"
                            icon="pi pi-upload"
                            size="small"
                            outlined
                            :loading="uploading"
                            @click="selectFile"
                        />
                        <Button
                            v-if="currentUrl"
                            type="button"
                            :label="$t('admin.avatar.remove')"
                            icon="pi pi-trash"
                            size="small"
                            severity="danger"
                            outlined
                            :disabled="uploading"
                            @click="removeAvatar"
                        />
                    </div>
                    <small class="text-surface-400">{{ $t('admin.avatar.hint') }}</small>
                </div>
                <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onFileSelected">
            </div>
        </template>
    </Card>
</template>
