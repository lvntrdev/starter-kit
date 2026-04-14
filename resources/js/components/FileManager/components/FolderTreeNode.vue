<script setup lang="ts">
    import { ref } from 'vue';
    import type { FolderNode } from '../types';

    interface Props {
        node: FolderNode;
        depth: number;
        selectedId: string | null;
    }

    const props = defineProps<Props>();
    const emit = defineEmits<{
        (e: 'select', folderId: string): void;
    }>();

    const expanded = ref(true);

    function toggle(event: MouseEvent): void {
        event.stopPropagation();
        expanded.value = !expanded.value;
    }

    function select(): void {
        emit('select', props.node.id);
    }
</script>

<template>
    <div class="fm-tree-node">
        <button
            type="button"
            class="group flex w-full items-center gap-2.5 rounded-lg px-3 py-2.5 text-left transition-colors"
            :class="[
                selectedId === node.id
                    ? 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-200'
                    : 'text-surface-700 hover:bg-surface-100 dark:text-surface-200 dark:hover:bg-surface-800',
            ]"
            :style="{ paddingLeft: `${depth * 18 + 12}px` }"
            @click="select"
        >
            <span
                v-if="node.children.length > 0"
                class="flex h-5 w-5 shrink-0 items-center justify-center text-surface-400 hover:text-surface-700 dark:hover:text-surface-200"
                @click="toggle"
            >
                <i :class="expanded ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" style="font-size: 0.75rem" />
            </span>
            <span v-else class="h-5 w-5 shrink-0" />

            <i
                class="pi"
                :class="[
                    node.children.length > 0 && expanded ? 'pi-folder-open' : 'pi-folder',
                    selectedId === node.id ? 'text-primary-600 dark:text-primary-300' : 'text-amber-500',
                ]"
                style="font-size: 1.05rem"
            />
            <span class="truncate" :title="node.name">{{ node.name }}</span>
        </button>

        <div v-if="expanded && node.children.length > 0" class="mt-1 space-y-1">
            <FolderTreeNode
                v-for="child in node.children"
                :key="child.id"
                :node="child"
                :depth="depth + 1"
                :selected-id="selectedId"
                @select="(id) => emit('select', id)"
            />
        </div>
    </div>
</template>
