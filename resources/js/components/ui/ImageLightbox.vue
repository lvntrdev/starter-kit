<script setup lang="ts">
    import { useImageLightbox } from '@/composables/useImageLightbox';

    const lightbox = useImageLightbox();

    function onBackdropClick(event: MouseEvent): void {
        // Only close when the click originated on the backdrop itself, not on the image
        if (event.target === event.currentTarget) {
            lightbox.close();
        }
    }

    function onKeydown(event: KeyboardEvent): void {
        if (event.key === 'Escape' && lightbox.state.visible) {
            lightbox.close();
        }
    }

    onMounted(() => {
        window.addEventListener('keydown', onKeydown);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('keydown', onKeydown);
    });
</script>

<template>
    <Teleport to="body">
        <Transition name="sk-lightbox">
            <div
                v-if="lightbox.state.visible"
                class="sk-lightbox fixed inset-0 z-[10000] flex flex-col bg-black/85 backdrop-blur-sm"
                role="dialog"
                aria-modal="true"
                :aria-label="lightbox.state.name"
                @click="onBackdropClick"
            >
                <header class="flex items-center justify-between gap-4 px-6 py-4 text-white">
                    <span class="truncate text-base font-medium">{{ lightbox.state.name }}</span>
                    <button
                        type="button"
                        class="sk-lightbox__close flex size-10 shrink-0 items-center justify-center rounded-full text-white/90 transition hover:bg-white/15 hover:text-white"
                        :aria-label="'Close'"
                        @click="lightbox.close"
                    >
                        <i class="pi pi-times text-xl" />
                    </button>
                </header>
                <div class="flex flex-1 items-center justify-center overflow-auto px-6 pb-6" @click="onBackdropClick">
                    <img
                        :src="lightbox.state.url"
                        :alt="lightbox.state.name"
                        class="max-h-full max-w-full object-contain"
                        draggable="false"
                    >
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
    .sk-lightbox-enter-active,
    .sk-lightbox-leave-active {
        transition: opacity 0.18s ease;
    }

    .sk-lightbox-enter-from,
    .sk-lightbox-leave-to {
        opacity: 0;
    }
</style>
