<script lang="ts">
    /**
     * Suggested dialog width based on content type. Use when opening this component
     * via `useDialog().open(FilePreviewModal, props, header, { width: suggestedPreviewWidth(mime) })`.
     *
     * Images are expected to be handled via `useImageLightbox` (fullscreen overlay),
     * not this modal — so no image case here.
     *
     * - PDF / text  → wide iframe viewer
     * - video       → medium-wide
     * - audio / fallback → leave undefined so useDialog uses its own default (640px)
     */
    export function suggestedPreviewWidth(mimeType?: string): string | undefined {
        if (!mimeType) return undefined;
        if (mimeType === 'application/pdf' || mimeType.startsWith('text/')) {
            return 'min(1100px, 90vw)';
        }
        if (mimeType.startsWith('video/')) {
            return 'min(900px, 90vw)';
        }
        return undefined;
    }
</script>

<script setup lang="ts">
    import { trans } from 'laravel-vue-i18n';
    import Button from 'primevue/button';

    export interface FilePreviewFile {
        url: string;
        name: string;
        mimeType?: string;
        size?: number;
    }

    interface Props {
        file: FilePreviewFile;
        /** Show a download button in the footer — invokes the provided callback. */
        onDownload?: () => void;
        /** Show "Open in new tab" button in the footer (default: true). */
        showExternalOpen?: boolean;
    }

    const props = withDefaults(defineProps<Props>(), {
        onDownload: undefined,
        showExternalOpen: true,
    });

    // ── Mime helpers ──────────────────────────────────────────────────────────
    function isImage(mime?: string): boolean {
        return !!mime?.startsWith('image/');
    }
    function isVideo(mime?: string): boolean {
        return !!mime?.startsWith('video/');
    }
    function isAudio(mime?: string): boolean {
        return !!mime?.startsWith('audio/');
    }
    function isPdf(mime?: string): boolean {
        return mime === 'application/pdf';
    }
    function isText(mime?: string): boolean {
        return !!mime?.startsWith('text/');
    }

    function openExternal(): void {
        window.open(props.file.url, '_blank', 'noopener,noreferrer');
    }
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="file-preview-body flex items-center justify-center">
            <img
                v-if="isImage(file.mimeType)"
                :src="file.url"
                :alt="file.name"
                class="mx-auto max-h-[85vh] w-auto object-contain"
            >
            <video
                v-else-if="isVideo(file.mimeType)"
                :src="file.url"
                controls
                autoplay
                class="max-h-[75vh] w-full"
            />
            <audio v-else-if="isAudio(file.mimeType)" :src="file.url" controls class="w-full" />
            <iframe
                v-else-if="isPdf(file.mimeType) || isText(file.mimeType)"
                :src="file.url"
                :title="file.name"
                class="h-[75vh] w-full border-0"
            />
            <div
                v-else
                class="flex flex-col items-center gap-4 p-10 text-center text-surface-600 dark:text-surface-300"
            >
                <i class="pi pi-file text-surface-400" style="font-size: 3rem" />
                <p>{{ trans('sk-file-manager.labels.no_preview') }}</p>
            </div>
        </div>

        <div
            v-if="onDownload || (showExternalOpen && !isImage(file.mimeType))"
            class="flex flex-wrap justify-end gap-2 border-t border-surface-200 pt-3 dark:border-surface-700"
        >
            <Button
                v-if="showExternalOpen && !isImage(file.mimeType)"
                severity="secondary"
                text
                icon="pi pi-external-link"
                :label="trans('sk-file-manager.labels.open_in_new_tab')"
                @click="openExternal"
            />
            <Button
                v-if="onDownload"
                severity="secondary"
                icon="pi pi-download"
                :label="trans('sk-file-manager.labels.download')"
                @click="onDownload"
            />
        </div>
    </div>
</template>
