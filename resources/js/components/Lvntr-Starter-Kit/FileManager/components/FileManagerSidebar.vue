<script setup lang="ts">
    import { trans } from 'laravel-vue-i18n';
    import { computed, ref } from 'vue';
    import { folderPaletteAt } from '../palette';
    import type { FolderNode, QuickView } from '../types';

    interface Props {
        tree: FolderNode[];
        currentFolderId: string | null;
        quickView: QuickView;
        usedBytes: number;
        quotaBytes: number;
        readonly?: boolean;
        enableTrash?: boolean;
        uploading?: boolean;
    }

    const props = withDefaults(defineProps<Props>(), { readonly: false, enableTrash: true, uploading: false });
    const emit = defineEmits<{
        (e: 'select-quick', view: QuickView): void;
        (e: 'select-folder', folderId: string): void;
        (e: 'new-folder'): void;
        (e: 'upload'): void;
    }>();

    function humanSize(bytes: number): string {
        if (!bytes) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        const value = bytes / 1024 ** i;
        return `${value.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
    }

    const usagePercent = computed(() => {
        if (props.quotaBytes <= 0) return 0;
        return Math.min(100, Math.round((props.usedBytes / props.quotaBytes) * 100));
    });

    // Depolama halkası — conic-gradient; renk eşiklere göre değişir.
    const ringColor = computed(() => {
        if (usagePercent.value >= 90) return 'var(--p-rose-500)';
        if (usagePercent.value >= 70) return 'var(--p-amber-500)';
        return 'var(--p-primary-500)';
    });

    const ringStyle = computed(() => ({
        background: `conic-gradient(${ringColor.value} ${usagePercent.value * 3.6}deg, var(--p-content-border-color) 0)`,
    }));

    interface QuickItem {
        key: QuickView;
        label: string;
        icon: string;
    }

    const quickItems = computed<QuickItem[]>(() => [
        { key: 'all', label: trans('sk-file-manager.labels.sidebar.all_files'), icon: 'pi pi-folder' },
        { key: 'recent', label: trans('sk-file-manager.labels.sidebar.recent'), icon: 'pi pi-clock' },
        { key: 'favorites', label: trans('sk-file-manager.labels.sidebar.favorites'), icon: 'pi pi-heart' },
    ]);

    // Klasör listesi — varsayılan kapalı, başlıkla açılır/kapanır.
    const foldersOpen = ref(false);

    function navClass(active: boolean): string {
        return active
            ? 'bg-primary-50 font-semibold text-primary-600 dark:bg-primary-950/40 dark:text-primary-300'
            : 'text-surface-600 hover:bg-surface-100 hover:text-surface-900 dark:text-surface-300 dark:hover:bg-surface-800 dark:hover:text-surface-100';
    }
</script>

<template>
    <aside
        class="fm-sidebar flex h-full flex-col gap-1 overflow-y-auto rounded-[5px] border border-surface-200 bg-surface-0 p-3 dark:border-surface-700 dark:bg-surface-900"
    >
        <!-- Sayfa başlığı — yalnız kart-içi header barındıran temalarda (aura) dolu
             gelir; başka temalarda layout başlığı kendi yerinde çizer, slot boştur. -->
        <div
            v-if="$slots.header"
            class="fm-sidebar__page-header mb-3 shrink-0 border-b border-surface-200 pb-2 dark:border-surface-700"
            data-sk-page-header
        >
            <slot name="header" />
        </div>

        <!-- Yükleme butonu — favoriler/çöp sanal görünümlerinde hedef klasör yok, devre dışı -->
        <button
            type="button"
            class="mb-2 flex h-10 shrink-0 items-center justify-center gap-2 rounded-[5px] bg-primary-500 text-[13.5px] font-semibold text-white transition hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="readonly || uploading || quickView === 'favorites' || quickView === 'trash'"
            @click="emit('upload')"
        >
            <i :class="uploading ? 'pi pi-spin pi-spinner' : 'pi pi-cloud-upload'" style="font-size: 0.875rem" />
            <span>{{ trans('sk-file-manager.labels.upload_new') }}</span>
        </button>

        <!-- Görünümler -->
        <button
            v-for="item in quickItems"
            :key="item.key"
            type="button"
            class="flex w-full items-center gap-2.5 rounded-[5px] px-2.5 py-2 text-left text-[13.5px] font-medium transition-colors"
            :class="navClass(quickView === item.key && currentFolderId === null)"
            @click="emit('select-quick', item.key)"
        >
            <i :class="item.icon" class="w-[18px] text-center" style="font-size: 0.9rem" />
            <span class="truncate">{{ item.label }}</span>
        </button>

        <template v-if="enableTrash">
            <div class="mx-1 my-2 h-px shrink-0 bg-surface-200 dark:bg-surface-700" />

            <button
                type="button"
                class="flex w-full items-center gap-2.5 rounded-[5px] px-2.5 py-2 text-left text-[13.5px] font-medium transition-colors"
                :class="navClass(quickView === 'trash')"
                @click="emit('select-quick', 'trash')"
            >
                <i class="pi pi-trash w-[18px] text-center" style="font-size: 0.9rem" />
                <span class="truncate">{{ trans('sk-file-manager.labels.sidebar.trash') }}</span>
            </button>
        </template>

        <!-- Klasörler (açılır/kapanır) -->
        <button
            type="button"
            class="group flex w-full items-center gap-2 px-2 pb-1 pt-2.5"
            :aria-expanded="foldersOpen"
            @click="foldersOpen = !foldersOpen"
        >
            <span
                class="flex-1 text-left font-mono text-[10px] font-semibold uppercase tracking-[0.14em] text-surface-400 transition-colors group-hover:text-surface-600 dark:text-surface-500 dark:group-hover:text-surface-300"
            >
                {{ trans('sk-file-manager.labels.sidebar.folders') }}
            </span>
            <span class="font-mono text-[10.5px] font-bold text-surface-400 dark:text-surface-500">
                {{ tree.length }}
            </span>
            <i
                class="pi text-surface-400 transition-colors group-hover:text-surface-600 dark:text-surface-500"
                :class="foldersOpen ? 'pi-chevron-down' : 'pi-chevron-right'"
                style="font-size: 0.7rem"
            />
        </button>

        <div v-show="foldersOpen" class="flex flex-col gap-1">
            <div
                v-if="tree.length === 0"
                class="px-2.5 py-1.5 text-[12.5px] text-surface-400 dark:text-surface-500"
            >
                {{ trans('sk-file-manager.labels.sidebar.no_folders') }}
            </div>

            <button
                v-for="(folder, folderIndex) in tree"
                v-else
                :key="folder.id"
                type="button"
                class="flex w-full items-center gap-2.5 rounded-[5px] px-2.5 py-2 text-left text-[13.5px] font-medium transition-colors"
                :class="navClass(currentFolderId === folder.id)"
                @click="emit('select-folder', folder.id)"
            >
                <i
                    class="pi pi-folder w-[18px] text-center"
                    :class="currentFolderId === folder.id ? '' : folderPaletteAt(folderIndex).text"
                    style="font-size: 0.9rem"
                />
                <span class="truncate" :title="folder.name">{{ folder.name }}</span>
            </button>

            <button
                type="button"
                class="flex items-center gap-2 rounded-[5px] border border-dashed border-surface-300 px-2.5 py-2 text-[12.5px] font-medium text-surface-500 transition-colors hover:border-primary-400 hover:bg-primary-50/50 hover:text-primary-600 disabled:cursor-not-allowed disabled:opacity-50 dark:border-surface-600 dark:text-surface-400 dark:hover:border-primary-500 dark:hover:bg-primary-950/20 dark:hover:text-primary-300"
                :disabled="readonly"
                @click="emit('new-folder')"
            >
                <i class="pi pi-plus" style="font-size: 0.7rem" />
                <span>{{ trans('sk-file-manager.labels.sidebar.add_folder') }}</span>
            </button>
        </div>

        <!-- Depolama halkası -->
        <div
            v-if="quotaBytes > 0"
            class="mt-auto flex shrink-0 items-center gap-3 border-t border-surface-200 px-1 pb-1 pt-3.5 dark:border-surface-700"
        >
            <div
                class="relative grid h-[52px] w-[52px] shrink-0 place-items-center rounded-full"
                :style="ringStyle"
            >
                <span class="absolute h-10 w-10 rounded-full bg-surface-0 dark:bg-surface-900" />
                <span class="relative z-[1] text-xs font-bold tracking-tight text-surface-900 dark:text-surface-100">
                    {{ usagePercent }}%
                </span>
            </div>
            <div class="min-w-0">
                <div class="text-[12.5px] font-semibold text-surface-900 dark:text-surface-100">
                    {{ trans('sk-file-manager.labels.sidebar.storage_usage') }}
                </div>
                <div class="mt-0.5 truncate text-[11px] text-surface-400 dark:text-surface-500">
                    {{ trans('sk-file-manager.labels.sidebar.storage_used_of', { used: humanSize(usedBytes), total: humanSize(quotaBytes) }) }}
                </div>
            </div>
        </div>
    </aside>
</template>
