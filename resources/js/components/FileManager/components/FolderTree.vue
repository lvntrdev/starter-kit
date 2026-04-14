<script setup lang="ts">
    import { computed } from 'vue';
    import type { FolderNode } from '../types';
    import FolderTreeNode from './FolderTreeNode.vue';

    interface Props {
        tree: FolderNode[];
        selectedId: string | null;
        rootLabel?: string;
    }

    const props = defineProps<Props>();
    const emit = defineEmits<{
        (e: 'select', folderId: string | null): void;
    }>();

    const isRootActive = computed(() => !props.selectedId);
</script>

<template>
    <div class="fm-folder-tree flex h-full flex-col gap-1 overflow-y-auto p-4">
        <button
            type="button"
            class="flex items-center gap-2.5 rounded-lg px-3 py-2.5 text-left font-medium transition-colors"
            :class="
                isRootActive
                    ? 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-200'
                    : 'text-surface-700 hover:bg-surface-100 dark:text-surface-200 dark:hover:bg-surface-800'
            "
            @click="emit('select', null)"
        >
            <i
                class="pi pi-home"
                :class="isRootActive ? 'text-primary-600 dark:text-primary-300' : 'text-surface-500'"
                style="font-size: 1.05rem"
            />
            <span>{{ rootLabel ?? 'Root' }}</span>
        </button>

        <FolderTreeNode
            v-for="node in tree"
            :key="node.id"
            :node="node"
            :depth="0"
            :selected-id="selectedId"
            @select="(id) => emit('select', id)"
        />
    </div>
</template>
