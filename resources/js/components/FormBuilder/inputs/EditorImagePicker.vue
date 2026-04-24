<script setup lang="ts">
    import { computed, onMounted, ref } from 'vue';
    import { useFileManager } from '@lvntr/components/FileManager/composables/useFileManager';
    import type { FileItem } from '@lvntr/components/FileManager/types';
    import { useDialog } from '@/composables/useDialog';
    import ProgressSpinner from 'primevue/progressspinner';
    import { useToast } from 'primevue/usetoast';
    import { trans } from 'laravel-vue-i18n';

    interface Props {
        context: string;
        contextId?: string | number | null;
        folderId?: string | null;
        acceptedMimes?: string[];
        onPick: (file: FileItem) => void;
    }

    const props = withDefaults(defineProps<Props>(), {
        contextId: null,
        folderId: null,
        acceptedMimes: () => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    });

    const dialog = useDialog();
    const toast = useToast();

    const fm = useFileManager({
        context: props.context,
        contextId: props.contextId != null ? String(props.contextId) : null,
    });

    onMounted(async () => {
        await fm.loadTree();
        await fm.loadContents(props.folderId ?? null);
    });

    const filteredFiles = computed<FileItem[]>(() =>
        fm.contents.files.filter((file) => props.acceptedMimes.includes(file.mime_type)),
    );

    const uploading = ref(false);
    const dragOver = ref(false);
    const fileInput = ref<HTMLInputElement | null>(null);

    function resolveUploadError(message: string): string {
        if (message.startsWith('sk-') || message.startsWith('validation.')) {
            const translated = trans(message);
            return translated === message ? trans('sk-editor.image_upload_failed') : translated;
        }
        return message || trans('sk-editor.image_upload_failed');
    }

    async function handleFiles(list: FileList | File[]): Promise<void> {
        const filtered = Array.from(list).filter((file) => props.acceptedMimes.includes(file.type));
        if (filtered.length === 0) return;

        uploading.value = true;
        try {
            const { uploaded, errors } = await fm.uploadFiles(filtered, props.folderId ?? null);
            if (errors.length > 0) {
                toast.add({
                    severity: 'error',
                    group: 'bc',
                    summary: trans('sk-editor.image_upload_failed'),
                    detail: resolveUploadError(errors[0]),
                    life: 4000,
                });
            }
            if (uploaded[0]) {
                props.onPick(uploaded[0]);
                dialog.close();
            }
        } finally {
            uploading.value = false;
        }
    }

    function onInputChange(event: Event): void {
        const target = event.target as HTMLInputElement;
        if (target.files && target.files.length > 0) {
            void handleFiles(target.files);
        }
        target.value = '';
    }

    function onDrop(event: DragEvent): void {
        dragOver.value = false;
        if (event.dataTransfer?.files.length) {
            void handleFiles(event.dataTransfer.files);
        }
    }

    function pickExisting(file: FileItem): void {
        props.onPick(file);
        dialog.close();
    }

    function openFileBrowser(): void {
        fileInput.value?.click();
    }

    function humanSize(bytes: number): string {
        if (!bytes) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        return `${(bytes / 1024 ** i).toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
    }
</script>

<template>
    <div class="flex flex-col gap-4">
        <div
            class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed px-6 py-8 text-center transition-colors"
            :class="
                dragOver
                    ? 'border-primary-500 bg-primary-50 dark:bg-primary-950/30'
                    : 'border-surface-300 hover:border-surface-400 dark:border-surface-600 dark:hover:border-surface-500'
            "
            @dragover.prevent="dragOver = true"
            @dragleave.prevent="dragOver = false"
            @drop.prevent="onDrop"
            @click="openFileBrowser"
        >
            <ProgressSpinner
                v-if="uploading"
                style="width: 2.25rem; height: 2.25rem"
                stroke-width="4"
                animation-duration=".8s"
            />
            <template v-else>
                <i class="pi pi-cloud-upload mb-2 text-3xl text-surface-500" />
                <p class="text-sm font-medium text-surface-700 dark:text-surface-200">
                    {{ $t('sk-editor.picker_upload_hint') }}
                </p>
                <p class="mt-1 text-xs text-surface-500">
                    {{ acceptedMimes.join(', ') }}
                </p>
            </template>
            <input
                ref="fileInput"
                type="file"
                class="hidden"
                :accept="acceptedMimes.join(',')"
                @change="onInputChange"
                @click.stop
            >
        </div>

        <div v-if="fm.loading.contents" class="flex justify-center py-6">
            <ProgressSpinner style="width: 1.5rem; height: 1.5rem" stroke-width="4" />
        </div>

        <div v-else-if="filteredFiles.length" class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
            <button
                v-for="file in filteredFiles"
                :key="file.id"
                type="button"
                class="group relative overflow-hidden rounded-md border border-surface-200 bg-surface-0 transition-all hover:ring-2 hover:ring-primary-400 focus:outline-none focus:ring-2 focus:ring-primary-400 dark:border-surface-700 dark:bg-surface-900"
                :title="file.name"
                @click="pickExisting(file)"
            >
                <img :src="file.url" :alt="file.name" class="aspect-square w-full object-cover">
                <span
                    class="absolute inset-x-0 bottom-0 flex items-center justify-between gap-1 bg-black/60 px-2 py-1 text-left text-[11px] text-white"
                >
                    <span class="truncate">{{ file.name }}</span>
                    <span class="shrink-0 opacity-70">{{ humanSize(file.size) }}</span>
                </span>
            </button>
        </div>

        <div v-else class="py-6 text-center text-sm text-surface-500">
            {{ $t('sk-editor.picker_empty') }}
        </div>
    </div>
</template>
