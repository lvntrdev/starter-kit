<script setup lang="ts">
    import { ref, computed, watch } from 'vue';
    import { router } from '@inertiajs/vue3';
    import { FB } from '@lvntr/components/FormBuilder/core';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
    import adminSettings from '@/routes/settings';
    import { trans } from 'laravel-vue-i18n';
    import { useConfirm } from '@/composables/useConfirm';

    interface Props {
        settings: {
            app_name: string;
            app_url: string;
            timezone: string;
            languages: string[];
            debug: boolean;
            logo_url: string | null;
        };
        timezones: string[];
        availableLanguages: Record<string, string>;
    }

    const props = defineProps<Props>();

    const { confirmDelete } = useConfirm();
    const logoUrl = ref<string | null>(props.settings.logo_url);
    const uploading = ref(false);
    const fileInput = ref<HTMLInputElement | null>(null);

    watch(
        () => props.settings.logo_url,
        (val) => {
            logoUrl.value = val ?? null;
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

        const reader = new FileReader();
        reader.onload = (e) => {
            logoUrl.value = e.target?.result as string;
        };
        reader.readAsDataURL(file);

        uploading.value = true;
        try {
            const formData = new FormData();
            formData.append('logo', file);

            const headers: Record<string, string> = {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            };
            const xsrf = getCsrfToken();
            if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;

            const response = await fetch(adminSettings.upload.logo.url(), {
                method: 'POST',
                headers,
                credentials: 'same-origin',
                body: formData,
            });

            if (response.ok) {
                const json = await response.json();
                logoUrl.value = json.data?.logo_url ?? logoUrl.value;
                router.reload({ preserveScroll: true });
            } else {
                logoUrl.value = props.settings.logo_url;
            }
        } catch {
            logoUrl.value = props.settings.logo_url;
        } finally {
            uploading.value = false;
        }
    }

    function removeLogo() {
        confirmDelete(async () => {
            uploading.value = true;
            try {
                const headers: Record<string, string> = {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                };
                const xsrf = getCsrfToken();
                if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;

                const response = await fetch(adminSettings.delete.logo.url(), {
                    method: 'DELETE',
                    headers,
                    credentials: 'same-origin',
                });

                if (response.ok || response.status === 204) {
                    logoUrl.value = null;
                    router.reload({ preserveScroll: true });
                }
            } catch {
                // silently fail
            } finally {
                uploading.value = false;
            }
        }, trans('admin.settings.general.logo_remove_confirm'));
    }

    const timezoneOptions = computed(() => props.timezones.map((tz) => ({ label: tz, value: tz })));

    const languageOptions = computed(() =>
        Object.entries(props.availableLanguages).map(([locale, label]) => ({
            label,
            value: locale,
        })),
    );

    const formConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(2)
            .cardTitle('admin.settings.general.title')
            .cardSubtitle('admin.settings.general.subtitle')
            .initialData(props.settings)
            .submit({
                url: adminSettings.update.general.url(),
                method: 'put',
                preserveScroll: true,
            })
            .addFields(
                FB.inputText().key('app_name'),
                FB.inputText().key('app_url'),
                FB.select().key('timezone').options(timezoneOptions.value).filter(true).class('col-span-full'),
                FB.checkboxGroup()
                    .key('languages')
                    .hint(trans('admin.settings.general.languages_hint'))
                    .labelPlacement('inline')
                    .controlPosition('left')
                    .options(languageOptions.value)
                    .class('col-span-full'),
                FB.toggleSwitch().key('debug').class('col-span-full'),
            )
            .build(),
    );
</script>

<template>
    <!-- Logo Upload -->
    <Card class="mb-6">
        <template #title>
            {{ $t('admin.settings.general.logo') }}
        </template>
        <template #subtitle>
            {{ $t('admin.settings.general.logo_hint') }}
        </template>
        <template #content>
            <div class="flex items-center gap-4">
                <div
                    class="relative flex h-16 w-40 shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded border border-surface-200 bg-surface-50 dark:border-surface-700 dark:bg-surface-800"
                    @click="selectFile"
                >
                    <img v-if="logoUrl" :src="logoUrl" alt="Logo" class="h-full w-full object-contain p-2">
                    <i v-else class="pi pi-image text-2xl text-surface-400" />
                    <div
                        class="absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 transition-opacity hover:opacity-100"
                    >
                        <i v-if="uploading" class="pi pi-spin pi-spinner text-lg text-white" />
                        <i v-else class="pi pi-upload text-lg text-white" />
                    </div>
                </div>
                <div class="flex flex-col gap-1">
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            :label="$t('admin.settings.general.logo_upload')"
                            icon="pi pi-upload"
                            size="small"
                            outlined
                            :loading="uploading"
                            @click="selectFile"
                        />
                        <Button
                            v-if="logoUrl"
                            type="button"
                            :label="$t('admin.settings.general.logo_remove')"
                            icon="pi pi-trash"
                            size="small"
                            severity="danger"
                            outlined
                            :disabled="uploading"
                            @click="removeLogo"
                        />
                    </div>
                    <small class="text-surface-400">PNG, JPG, SVG, WebP - max 2MB</small>
                </div>
            </div>
            <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/svg+xml,image/webp" class="hidden" @change="onFileSelected">
        </template>
    </Card>

    <!-- General Settings Form -->
    <SkForm :config="formConfig" />
</template>
