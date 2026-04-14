<script setup lang="ts">
    import type { FolderSummary } from '../types';

    interface Props {
        trail: FolderSummary[];
        rootLabel?: string;
        maxChars?: number;
    }

    const props = withDefaults(defineProps<Props>(), { maxChars: 18 });
    const emit = defineEmits<{
        (e: 'navigate', folderId: string | null): void;
    }>();

    function truncate(value: string): string {
        return value.length > props.maxChars ? `${value.slice(0, props.maxChars - 1)}…` : value;
    }
</script>

<template>
    <div class="fm-breadcrumb flex min-w-0 flex-wrap items-center gap-1.5">
        <button
            type="button"
            class="fm-crumb inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 font-medium transition-colors"
            :class="
                trail.length === 0
                    ? 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-200'
                    : 'bg-surface-100 text-surface-700 hover:bg-surface-200 dark:bg-surface-800 dark:text-surface-200 dark:hover:bg-surface-700'
            "
            @click="emit('navigate', null)"
        >
            <i class="pi pi-home" style="font-size: 0.8rem" />
            <span>{{ rootLabel ?? 'Root' }}</span>
        </button>

        <template v-for="(folder, idx) in trail" :key="folder.id">
            <i class="pi pi-angle-right text-surface-400" style="font-size: 0.75rem" />
            <button
                type="button"
                class="fm-crumb inline-flex items-center rounded-full px-3 py-1.5 font-medium transition-colors"
                :class="
                    idx === trail.length - 1
                        ? 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-200'
                        : 'bg-surface-100 text-surface-700 hover:bg-surface-200 dark:bg-surface-800 dark:text-surface-200 dark:hover:bg-surface-700'
                "
                :title="folder.name"
                @click="emit('navigate', folder.id)"
            >
                {{ truncate(folder.name) }}
            </button>
        </template>
    </div>
</template>
