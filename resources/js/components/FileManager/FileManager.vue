<script setup lang="ts">
    import { useConfirm } from '@/composables/useConfirm';
    import { useDialog } from '@/composables/useDialog';
    import { useImageLightbox } from '@/composables/useImageLightbox';
    import { trans } from 'laravel-vue-i18n';
    import Button from 'primevue/button';
    import ContextMenu from 'primevue/contextmenu';
    import Dialog from 'primevue/dialog';
    import InputText from 'primevue/inputtext';
    import ProgressSpinner from 'primevue/progressspinner';
    import Select from 'primevue/select';
    import { useToast } from 'primevue/usetoast';
    import { computed, onMounted, ref } from 'vue';
    import FilePreviewModal, { suggestedPreviewWidth } from '@lvntr/components/ui/FilePreviewModal.vue';
    import Breadcrumb from './components/Breadcrumb.vue';
    import FileGrid from './components/FileGrid.vue';
    import FolderTree from './components/FolderTree.vue';
    import { useFileManager } from './composables/useFileManager';
    import type { FileItem, FileManagerProps, FolderSummary, SelectionKey, SortKey } from './types';

    const props = withDefaults(defineProps<FileManagerProps>(), {
        contextId: null,
        readonly: false,
        height: '600px',
    });

    const toast = useToast();
    const { confirmDelete } = useConfirm();

    const fm = useFileManager({ context: props.context, contextId: props.contextId });

    onMounted(async () => {
        await fm.loadTree();
        await fm.loadContents(null);
        window.addEventListener('keydown', onKeyDown);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('keydown', onKeyDown);
    });

    function isTypingElement(el: EventTarget | null): boolean {
        if (!(el instanceof HTMLElement)) return false;
        if (el.isContentEditable) return true;
        const tag = el.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
    }

    function isAnyDialogOpen(): boolean {
        return showNewFolder.value || showRename.value || showMove.value;
    }

    function onKeyDown(event: KeyboardEvent): void {
        if (isTypingElement(event.target)) return;
        if (isAnyDialogOpen()) return;
        if (busyMessage.value) return;

        const meta = event.ctrlKey || event.metaKey;

        if (meta && event.key.toLowerCase() === 'a') {
            if (fm.contents.folders.length + fm.contents.files.length === 0) return;
            event.preventDefault();
            fm.selectAll();
            return;
        }

        if (event.key === 'Escape') {
            if (fm.selectionCount.value > 0) {
                event.preventDefault();
                fm.clearSelection();
            }
            return;
        }

        if ((event.key === 'Delete' || event.key === 'Backspace') && !props.readonly) {
            if (fm.selectionCount.value === 0) return;
            event.preventDefault();
            confirmBulkDelete();
        }
    }

    // ── Sort ─────────────────────────────────────────────────────
    const sortOptions = computed(() => [
        { label: trans('sk-file-manager.labels.sort_name'), value: 'name' as SortKey },
        { label: trans('sk-file-manager.labels.sort_size'), value: 'size' as SortKey },
        { label: trans('sk-file-manager.labels.sort_date'), value: 'date' as SortKey },
    ]);

    function onSortChange(value: SortKey): void {
        fm.setSort(value, fm.direction.value);
    }

    function toggleSortDir(): void {
        fm.toggleSortDirection();
    }

    const sortDirectionTooltip = computed(() =>
        fm.direction.value === 'asc'
            ? trans('sk-file-manager.labels.sort_asc_tooltip')
            : trans('sk-file-manager.labels.sort_desc_tooltip'),
    );

    // ── Stats ────────────────────────────────────────────────────
    function humanSize(bytes: number): string {
        if (!bytes) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        const value = bytes / 1024 ** i;
        return `${value.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
    }

    // ── New folder / rename dialogs ──────────────────────────────
    const showNewFolder = ref(false);
    const newFolderName = ref('');

    function openNewFolder(): void {
        newFolderName.value = '';
        showNewFolder.value = true;
    }

    async function submitNewFolder(): Promise<void> {
        const name = newFolderName.value.trim();
        if (!name) return;
        try {
            await fm.createFolder(name);
            showNewFolder.value = false;
            toast.add({
                severity: 'success',
                summary: '',
                group: 'bc',
                detail: trans('sk-file-manager.folder_created'),
                life: 2500,
            });
        } catch {
            /* handled by useApi */
        }
    }

    const showRename = ref(false);
    const renameTarget = ref<FolderSummary | null>(null);
    const renameValue = ref('');

    // ── Move modal ───────────────────────────────────────────────
    interface MoveSource {
        type: 'folder' | 'file';
        id: string;
        name: string;
    }

    const showMove = ref(false);
    const moveSources = ref<MoveSource[]>([]);
    const moveTargetId = ref<string | null>(null);

    const moveHeaderLabel = computed(() => {
        if (moveSources.value.length === 0) return '';
        if (moveSources.value.length === 1) return moveSources.value[0].name;
        return trans('sk-file-manager.labels.selected_count', { count: String(moveSources.value.length) });
    });

    function folderSummaryById(id: string): FolderSummary | null {
        return fm.contents.folders.find((f) => f.id === id) ?? null;
    }

    function fileItemById(id: string): FileItem | null {
        return fm.contents.files.find((f) => String(f.id) === id) ?? null;
    }

    function openMoveFolder(folder: FolderSummary): void {
        const multi = fm.selectionCount.value > 1 && fm.isSelected('folder', folder.id);
        openMoveDialog(multi ? collectSelectedSources() : [{ type: 'folder', id: folder.id, name: folder.name }]);
    }

    function openMoveFile(file: FileItem): void {
        const multi = fm.selectionCount.value > 1 && fm.isSelected('file', file.id);
        openMoveDialog(
            multi ? collectSelectedSources() : [{ type: 'file', id: String(file.id), name: file.file_name }],
        );
    }

    function collectSelectedSources(): MoveSource[] {
        const sources: MoveSource[] = [];
        for (const item of fm.selectedItems.value) {
            if (item.type === 'folder') {
                const f = folderSummaryById(item.id);
                if (f) sources.push({ type: 'folder', id: f.id, name: f.name });
            } else {
                const f = fileItemById(item.id);
                if (f) sources.push({ type: 'file', id: String(f.id), name: f.file_name });
            }
        }
        return sources;
    }

    function openMoveDialog(sources: MoveSource[]): void {
        if (sources.length === 0) return;
        moveSources.value = sources;
        moveTargetId.value = fm.currentFolderId.value;
        showMove.value = true;
    }

    async function runCancellableBulk<T extends { type: 'folder' | 'file'; id: string }>(
        title: string,
        items: T[],
        target: string | null,
        op: (item: T) => Promise<void>,
    ): Promise<void> {
        let cancelled = false;
        busy.value = {
            title,
            description: trans('sk-file-manager.labels.bulk_remaining', { count: String(items.length) }),
            onCancel: items.length > 1 ? () => (cancelled = true) : null,
        };
        try {
            let remaining = items.length;
            for (const item of items) {
                if (cancelled) break;
                if (item.type === 'folder' && item.id === target) {
                    remaining--;
                    setBusyDescription(trans('sk-file-manager.labels.bulk_remaining', { count: String(remaining) }));
                    continue;
                }
                await op(item);
                remaining--;
                setBusyDescription(trans('sk-file-manager.labels.bulk_remaining', { count: String(remaining) }));
            }
        } finally {
            busy.value = null;
        }
    }

    async function submitMove(): Promise<void> {
        if (moveSources.value.length === 0) return;
        const sources = moveSources.value;
        const target = moveTargetId.value;
        showMove.value = false;
        try {
            await runCancellableBulk(trans('sk-file-manager.labels.moving'), sources, target, (source) =>
                fm.moveItem(source.type, source.id, target),
            );
            toast.add({
                severity: 'success',
                summary: '',
                group: 'bc',
                detail: trans('sk-file-manager.item_moved'),
                life: 2500,
            });
            fm.clearSelection();
        } catch {
            /* handled */
        }
    }

    async function handleDropOnFolder(targetFolderId: string): Promise<void> {
        const items = fm.selectedItems.value.length > 0 ? [...fm.selectedItems.value] : [];
        if (items.length === 0) return;
        try {
            await runCancellableBulk(trans('sk-file-manager.labels.moving'), items, targetFolderId, (item) =>
                fm.moveItem(item.type, item.id, targetFolderId),
            );
            toast.add({
                severity: 'success',
                summary: '',
                group: 'bc',
                detail: trans('sk-file-manager.item_moved'),
                life: 2500,
            });
            fm.clearSelection();
        } catch {
            /* handled */
        }
    }

    function onInternalDragStart(type: 'folder' | 'file', id: string | number): void {
        const key = `${type}:${String(id)}` as SelectionKey;
        if (!fm.isSelected(type, id)) {
            fm.setSelection([key]);
        }
    }

    // ── Preview modal ────────────────────────────────────────────
    const dialog = useDialog();
    const lightbox = useImageLightbox();

    function openPreview(file: FileItem): void {
        if (file.mime_type.startsWith('image/')) {
            lightbox.open(file.url, file.file_name);
            return;
        }
        const width = suggestedPreviewWidth(file.mime_type);
        dialog.open(
            FilePreviewModal,
            {
                file: {
                    url: file.url,
                    name: file.file_name,
                    mimeType: file.mime_type,
                    size: file.size,
                },
                onDownload: () => downloadFile(file),
                showExternalOpen: true,
            },
            file.file_name,
            width ? { width } : {},
        );
    }

    function openRename(folder: FolderSummary): void {
        renameTarget.value = folder;
        renameValue.value = folder.name;
        showRename.value = true;
    }

    async function submitRename(): Promise<void> {
        if (!renameTarget.value) return;
        const name = renameValue.value.trim();
        if (!name || name === renameTarget.value.name) {
            showRename.value = false;
            return;
        }
        try {
            showRename.value = false;
            await runBusy(trans('sk-file-manager.labels.renaming'), () =>
                fm.renameFolder(renameTarget.value!.id, name),
            );
            toast.add({
                severity: 'success',
                summary: '',
                group: 'bc',
                detail: trans('sk-file-manager.folder_renamed'),
                life: 2500,
            });
        } catch {
            /* handled */
        }
    }

    // ── Context menus ────────────────────────────────────────────
    const folderMenu = ref<InstanceType<typeof ContextMenu> | null>(null);
    const fileMenu = ref<InstanceType<typeof ContextMenu> | null>(null);
    const emptyMenu = ref<InstanceType<typeof ContextMenu> | null>(null);
    const contextFolder = ref<FolderSummary | null>(null);
    const contextFile = ref<FileItem | null>(null);

    const bulkActive = computed(() => fm.selectionCount.value > 1);

    const folderMenuItems = computed(() => {
        const multi = Boolean(
            bulkActive.value && contextFolder.value && fm.isSelected('folder', contextFolder.value.id),
        );
        return [
            {
                label: trans('sk-file-manager.labels.open'),
                icon: 'pi pi-folder-open',
                disabled: multi,
                command: () => contextFolder.value && fm.loadContents(contextFolder.value.id),
            },
            {
                label: trans('sk-file-manager.labels.rename'),
                icon: 'pi pi-pencil',
                disabled: props.readonly || multi,
                command: () => contextFolder.value && openRename(contextFolder.value),
            },
            {
                label: multi
                    ? trans('sk-file-manager.labels.move') + ` (${fm.selectionCount.value})`
                    : trans('sk-file-manager.labels.move'),
                icon: 'pi pi-arrow-right-arrow-left',
                disabled: props.readonly,
                command: () => contextFolder.value && openMoveFolder(contextFolder.value),
            },
            { separator: true },
            {
                label: multi
                    ? trans('sk-file-manager.labels.delete_selected') + ` (${fm.selectionCount.value})`
                    : trans('sk-file-manager.labels.delete'),
                icon: 'pi pi-trash',
                disabled: props.readonly,
                command: () => {
                    if (multi) {
                        confirmBulkDelete();
                    } else if (contextFolder.value) {
                        confirmDeleteFolder(contextFolder.value);
                    }
                },
            },
        ];
    });

    const fileMenuItems = computed(() => {
        const multi = Boolean(bulkActive.value && contextFile.value && fm.isSelected('file', contextFile.value.id));
        return [
            {
                label: trans('sk-file-manager.labels.open'),
                icon: 'pi pi-eye',
                disabled: multi,
                command: () => contextFile.value && openPreview(contextFile.value),
            },
            {
                label: trans('sk-file-manager.labels.download'),
                icon: 'pi pi-download',
                disabled: multi,
                command: () => contextFile.value && downloadFile(contextFile.value),
            },
            {
                label: multi
                    ? trans('sk-file-manager.labels.move') + ` (${fm.selectionCount.value})`
                    : trans('sk-file-manager.labels.move'),
                icon: 'pi pi-arrow-right-arrow-left',
                disabled: props.readonly,
                command: () => contextFile.value && openMoveFile(contextFile.value),
            },
            { separator: true },
            {
                label: multi
                    ? trans('sk-file-manager.labels.delete_selected') + ` (${fm.selectionCount.value})`
                    : trans('sk-file-manager.labels.delete'),
                icon: 'pi pi-trash',
                disabled: props.readonly,
                command: () => {
                    if (multi) {
                        confirmBulkDelete();
                    } else if (contextFile.value) {
                        confirmDeleteFile(contextFile.value);
                    }
                },
            },
        ];
    });

    function showFolderMenu(event: MouseEvent, folder: FolderSummary): void {
        contextFolder.value = folder;
        folderMenu.value?.show(event);
    }

    function showFileMenu(event: MouseEvent, file: FileItem): void {
        contextFile.value = file;
        fileMenu.value?.show(event);
    }

    const emptyMenuItems = computed(() => [
        {
            label: trans('sk-file-manager.labels.new_folder'),
            icon: 'pi pi-folder-plus',
            disabled: props.readonly,
            command: () => openNewFolder(),
        },
        {
            label: trans('sk-file-manager.labels.upload'),
            icon: 'pi pi-upload',
            disabled: props.readonly || uploading.value,
            command: () => triggerUpload(),
        },
        { separator: true },
        {
            label: trans('sk-file-manager.labels.select_all'),
            icon: 'pi pi-check-square',
            disabled: fm.contents.folders.length + fm.contents.files.length === 0,
            command: () => fm.selectAll(),
        },
        {
            label: trans('sk-file-manager.labels.refresh'),
            icon: 'pi pi-refresh',
            command: () => fm.refresh(),
        },
    ]);

    function showEmptyMenu(event: MouseEvent): void {
        emptyMenu.value?.show(event);
    }

    function confirmDeleteFolder(folder: FolderSummary): void {
        confirmDelete(async () => {
            await runBusy(trans('sk-file-manager.labels.deleting'), () => fm.deleteFolder(folder.id));
            toast.add({
                severity: 'success',
                summary: '',
                group: 'bc',
                detail: trans('sk-file-manager.folder_deleted'),
                life: 2500,
            });
        });
    }

    function confirmDeleteFile(file: FileItem): void {
        confirmDelete(async () => {
            await runBusy(trans('sk-file-manager.labels.deleting'), () => fm.deleteFile(file.id));
            toast.add({
                severity: 'success',
                summary: '',
                group: 'bc',
                detail: trans('sk-file-manager.file_deleted'),
                life: 2500,
            });
        });
    }

    function confirmBulkDelete(): void {
        if (fm.selectionCount.value === 0) return;
        confirmDelete(async () => {
            await runBusy(trans('sk-file-manager.labels.deleting'), () => fm.bulkDelete());
            toast.add({
                severity: 'success',
                summary: '',
                group: 'bc',
                detail: trans('sk-file-manager.bulk_deleted'),
                life: 2500,
            });
        });
    }

    function downloadFile(file: FileItem): void {
        const params = new URLSearchParams({ context: props.context });
        if (props.contextId) params.set('context_id', props.contextId);
        window.location.href = `/file-manager/files/${file.id}/download?${params.toString()}`;
    }

    // ── Selection helpers ────────────────────────────────────────
    function onToggleSelect(type: 'folder' | 'file', id: string | number, event: MouseEvent): void {
        if (event.shiftKey || event.ctrlKey || event.metaKey) {
            fm.toggleSelect(type, id);
        } else {
            // plain click → single select
            fm.setSelection([`${type}:${String(id)}` as SelectionKey]);
        }
    }

    // ── Busy overlay ─────────────────────────────────────────────
    interface BusyState {
        title: string;
        description: string;
        onCancel: (() => void) | null;
    }

    const busy = ref<BusyState | null>(null);
    const busyMessage = computed(() => busy.value?.title ?? null);

    async function runBusy<T>(title: string, task: () => Promise<T>): Promise<T> {
        busy.value = { title, description: '', onCancel: null };
        try {
            return await task();
        } finally {
            busy.value = null;
        }
    }

    function setBusyDescription(description: string): void {
        if (busy.value) busy.value = { ...busy.value, description };
    }

    // ── Upload ───────────────────────────────────────────────────
    const fileInput = ref<HTMLInputElement | null>(null);
    const uploading = ref(false);
    const isDropping = ref(false);

    function triggerUpload(): void {
        fileInput.value?.click();
    }

    function isMimeAllowed(file: File): boolean {
        if (!props.acceptedMimes || props.acceptedMimes.length === 0) return true;
        const name = file.name.toLowerCase();
        return props.acceptedMimes.some((rule) => {
            const r = rule.trim().toLowerCase();
            if (!r) return false;
            if (r.startsWith('.')) return name.endsWith(r);
            if (r.endsWith('/*')) return file.type.startsWith(r.slice(0, -1));
            return file.type === r;
        });
    }

    function partitionFiles(list: File[]): { accepted: File[]; rejections: string[] } {
        const accepted: File[] = [];
        const rejections: string[] = [];
        const maxBytes = props.maxSizeKb ? props.maxSizeKb * 1024 : null;
        for (const file of list) {
            if (!isMimeAllowed(file)) {
                rejections.push(trans('sk-file-manager.errors.invalid_type', { name: file.name }));
                continue;
            }
            if (maxBytes !== null && file.size > maxBytes) {
                rejections.push(
                    trans('sk-file-manager.errors.file_too_large', {
                        name: file.name,
                        max: humanSize(maxBytes),
                    }),
                );
                continue;
            }
            accepted.push(file);
        }
        return { accepted, rejections };
    }

    async function handleFiles(fileList: FileList | File[] | null): Promise<void> {
        if (!fileList || (fileList as FileList).length === 0) return;
        const { accepted, rejections } = partitionFiles(Array.from(fileList as ArrayLike<File>));
        for (const message of rejections) {
            toast.add({ severity: 'warn', summary: '', group: 'bc', detail: message, life: 4000 });
        }
        if (accepted.length === 0) {
            if (fileInput.value) fileInput.value.value = '';
            return;
        }
        uploading.value = true;
        try {
            const result = await fm.uploadFiles(accepted);
            if (result.uploaded.length > 0) {
                toast.add({
                    severity: 'success',
                    summary: '',
                    group: 'bc',
                    detail: trans('sk-file-manager.files_uploaded'),
                    life: 2500,
                });
            }
            for (const message of result.errors) {
                toast.add({ severity: 'error', summary: '', group: 'bc', detail: message, life: 4000 });
            }
        } finally {
            uploading.value = false;
            if (fileInput.value) fileInput.value.value = '';
        }
    }

    function onFileChange(event: Event): void {
        handleFiles((event.target as HTMLInputElement).files);
    }

    function onDrop(event: DragEvent): void {
        event.preventDefault();
        isDropping.value = false;
        handleFiles(event.dataTransfer?.files ?? null);
    }

    function onDragOver(event: DragEvent): void {
        event.preventDefault();
        if (event.dataTransfer && Array.from(event.dataTransfer.types).includes('Files')) {
            isDropping.value = true;
        }
    }

    function onDragLeave(): void {
        isDropping.value = false;
    }

    function openFileFromGrid(file: FileItem): void {
        openPreview(file);
    }

    const bulkLabel = computed(() =>
        trans('sk-file-manager.labels.selected_count', { count: String(fm.selectionCount.value) }),
    );

    const visiblePending = computed(() =>
        fm.pendingUploads.value.filter((p) => (p.folderId ?? null) === (fm.currentFolderId.value ?? null)),
    );

    const currentFolderName = computed(() => {
        const trail = fm.breadcrumb.value;
        if (trail.length === 0) return trans('sk-file-manager.labels.root');
        return trail[trail.length - 1].name;
    });

    const parentFolderId = computed<string | null>(() => {
        const trail = fm.breadcrumb.value;
        if (trail.length <= 1) return null;
        return trail[trail.length - 2].id;
    });

    function goBack(): void {
        fm.loadContents(parentFolderId.value);
    }
</script>

<template>
    <div
        class="fm-root relative flex overflow-hidden rounded-xl border border-surface-200 bg-surface-0 dark:border-surface-700 dark:bg-surface-900"
        :style="{ height }"
        @dragover="onDragOver"
        @dragleave="onDragLeave"
        @drop="onDrop"
    >
        <section
            class="fm-main flex min-w-0 flex-1 flex-col"
            :class="{ 'bg-primary-50/50 dark:bg-primary-950/20': isDropping }"
        >
            <header
                class="flex flex-wrap items-center gap-2 border-b border-surface-200 px-4 py-3 dark:border-surface-700"
            >
                <div class="flex min-w-0 flex-1 items-center gap-2">
                    <Button
                        v-if="fm.currentFolderId.value"
                        severity="secondary"
                        text
                        rounded
                        icon="pi pi-arrow-left"
                        :aria-label="trans('sk-file-manager.labels.back')"
                        @click="goBack"
                    />
                    <i class="pi pi-folder-open text-primary-500" style="font-size: 1.6rem" />
                    <h2 class="truncate text-2xl font-semibold" :title="currentFolderName">
                        {{ currentFolderName }}
                    </h2>
                </div>

                <div class="flex items-center gap-1.5">
                    <Select
                        :model-value="fm.sort.value"
                        :options="sortOptions"
                        option-label="label"
                        option-value="value"
                        class="fm-sort-select w-32"
                        @update:model-value="onSortChange"
                    />
                    <Button
                        v-tooltip.bottom="sortDirectionTooltip"
                        severity="secondary"
                        text
                        :icon="fm.direction.value === 'asc' ? 'pi pi-sort-amount-up' : 'pi pi-sort-amount-down'"
                        :aria-label="sortDirectionTooltip"
                        @click="toggleSortDir"
                    />
                </div>

                <Button
                    severity="secondary"
                    icon="pi pi-folder-plus"
                    :label="trans('sk-file-manager.labels.new_folder')"
                    :disabled="readonly"
                    @click="openNewFolder"
                />

                <Button
                    :icon="uploading ? 'pi pi-spin pi-spinner' : 'pi pi-upload'"
                    :label="trans('sk-file-manager.labels.upload')"
                    :disabled="readonly || uploading"
                    @click="triggerUpload"
                />

                <input
                    ref="fileInput"
                    type="file"
                    multiple
                    class="hidden"
                    :accept="acceptedMimes?.join(',')"
                    @change="onFileChange"
                >
            </header>

            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b border-surface-200 bg-surface-50 px-4 py-2 text-surface-600 dark:border-surface-700 dark:bg-surface-950 dark:text-surface-300"
            >
                <div class="flex items-center gap-4">
                    <span class="inline-flex items-center gap-1.5">
                        <i class="pi pi-file text-surface-400" style="font-size: 0.85rem" />
                        {{
                            trans('sk-file-manager.labels.total_files', { count: String(fm.contents.stats.file_count) })
                        }}
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <i class="pi pi-database text-surface-400" style="font-size: 0.85rem" />
                        {{
                            trans('sk-file-manager.labels.total_size', {
                                size: humanSize(fm.contents.stats.total_size),
                            })
                        }}
                    </span>
                </div>

                <div v-if="fm.selectionCount.value > 0" class="flex items-center gap-2">
                    <span class="font-medium text-primary-600 dark:text-primary-300">{{ bulkLabel }}</span>
                    <Button
                        size="small"
                        severity="secondary"
                        text
                        icon="pi pi-times"
                        label="Clear"
                        @click="fm.clearSelection"
                    />
                    <Button
                        size="small"
                        severity="danger"
                        icon="pi pi-trash"
                        :label="trans('sk-file-manager.labels.delete_selected')"
                        :disabled="readonly"
                        @click="confirmBulkDelete"
                    />
                </div>
            </div>

            <div
                class="border-b border-surface-200 bg-surface-50 px-3 py-2 dark:border-surface-700 dark:bg-surface-950"
            >
                <Breadcrumb
                    :trail="fm.breadcrumb.value"
                    :root-label="trans('sk-file-manager.labels.root')"
                    @navigate="(id) => fm.loadContents(id)"
                />
            </div>

            <div class="relative flex-1 overflow-hidden">
                <FileGrid
                    :folders="fm.contents.folders"
                    :files="fm.contents.files"
                    :pending="visiblePending"
                    :loading="fm.loading.contents"
                    :empty-label="trans('sk-file-manager.labels.empty_folder')"
                    :is-selected="fm.isSelected"
                    @open-folder="(id) => fm.loadContents(id)"
                    @open-file="openFileFromGrid"
                    @context-folder="showFolderMenu"
                    @context-file="showFileMenu"
                    @context-empty="showEmptyMenu"
                    @download-file="downloadFile"
                    @toggle-select="onToggleSelect"
                    @set-selection="(keys) => fm.setSelection(keys)"
                    @clear-selection="fm.clearSelection"
                    @dismiss-pending="(id) => fm.dismissPending(id)"
                    @drop-on-folder="(targetId) => handleDropOnFolder(targetId)"
                    @internal-drag-start="onInternalDragStart"
                    @check-toggle="(type, id) => fm.toggleSelect(type, id)"
                />

                <div
                    v-if="fm.loading.contents"
                    class="absolute inset-0 flex items-center justify-center bg-white/50 dark:bg-surface-900/50"
                >
                    <ProgressSpinner style="width: 32px; height: 32px" stroke-width="4" />
                </div>
            </div>
        </section>

        <!-- Full-area drop zone overlay -->
        <div
            v-if="isDropping"
            class="pointer-events-none absolute inset-0 z-30 flex items-center justify-center bg-primary-500/10 backdrop-blur-sm"
        >
            <div
                class="flex flex-col items-center gap-3 rounded-2xl border-2 border-dashed border-primary-400 bg-surface-0/95 px-10 py-8 text-primary-700 shadow-xl dark:border-primary-500 dark:bg-surface-900/95 dark:text-primary-200"
            >
                <i class="pi pi-cloud-upload" style="font-size: 3.5rem" />
                <span class="text-lg font-semibold">{{ trans('sk-file-manager.labels.drop_files_here') }}</span>
            </div>
        </div>

        <!-- Busy overlay (modal card) -->
        <div
            v-if="busy"
            class="absolute inset-0 z-40 flex items-center justify-center bg-slate-900/40 backdrop-blur-sm"
        >
            <div
                class="flex w-88 max-w-[90%] flex-col gap-3 rounded-2xl bg-surface-0 p-6 shadow-2xl dark:bg-surface-900"
            >
                <div class="flex items-center gap-3">
                    <i class="pi pi-spin pi-spinner shrink-0 text-primary-500" style="font-size: 1.4rem" />
                    <h3 class="text-lg font-semibold leading-tight text-surface-900 dark:text-surface-100">
                        {{ busy.title }}
                    </h3>
                </div>
                <p v-if="busy.description" class="text-surface-500 dark:text-surface-400">
                    {{ busy.description }}
                </p>
                <div v-if="busy.onCancel" class="mt-2 flex justify-end">
                    <Button rounded :label="trans('sk-file-manager.labels.stop')" @click="busy.onCancel" />
                </div>
            </div>
        </div>

        <ContextMenu ref="folderMenu" class="fm-context-menu" :model="folderMenuItems" />
        <ContextMenu ref="fileMenu" class="fm-context-menu" :model="fileMenuItems" />
        <ContextMenu ref="emptyMenu" class="fm-context-menu" :model="emptyMenuItems" />

        <Dialog
            v-model:visible="showNewFolder"
            :header="trans('sk-file-manager.labels.new_folder')"
            modal
            :style="{ width: '24rem' }"
        >
            <InputText v-model="newFolderName" class="w-full" autofocus @keyup.enter="submitNewFolder" />
            <template #footer>
                <Button severity="secondary" text label="Cancel" @click="showNewFolder = false" />
                <Button label="OK" @click="submitNewFolder" />
            </template>
        </Dialog>

        <Dialog
            v-model:visible="showRename"
            :header="trans('sk-file-manager.labels.rename')"
            modal
            :style="{ width: '24rem' }"
        >
            <InputText v-model="renameValue" class="w-full" autofocus @keyup.enter="submitRename" />
            <template #footer>
                <Button severity="secondary" text label="Cancel" @click="showRename = false" />
                <Button label="OK" @click="submitRename" />
            </template>
        </Dialog>

        <Dialog
            v-model:visible="showMove"
            :header="trans('sk-file-manager.labels.move_header', { name: moveHeaderLabel })"
            modal
            :style="{ width: '28rem' }"
        >
            <div class="mb-3 text-surface-600 dark:text-surface-300">
                {{ trans('sk-file-manager.labels.move_hint') }}
            </div>
            <div class="max-h-80 overflow-auto rounded-lg border border-surface-200 dark:border-surface-700">
                <FolderTree
                    :tree="fm.tree.value"
                    :selected-id="moveTargetId"
                    :root-label="trans('sk-file-manager.labels.root')"
                    @select="(id) => (moveTargetId = id)"
                />
            </div>
            <template #footer>
                <Button
                    severity="secondary"
                    text
                    :label="trans('sk-file-manager.labels.close')"
                    @click="showMove = false"
                />
                <Button icon="pi pi-check" :label="trans('sk-file-manager.labels.move')" @click="submitMove" />
            </template>
        </Dialog>

    </div>
</template>

<style>
    .fm-context-menu.p-contextmenu {
        min-width: 14rem;
        padding: 0.5rem;
        background: var(--p-surface-0);
        border: 1px solid var(--p-surface-200);
        border-radius: 0.875rem;
        box-shadow:
            0 10px 30px -12px rgba(15, 23, 42, 0.18),
            0 4px 10px -4px rgba(15, 23, 42, 0.08);
    }
    .fm-context-menu.p-contextmenu .p-contextmenu-item-content {
        border-radius: 0.625rem;
    }
    .fm-context-menu.p-contextmenu .p-contextmenu-item-link {
        padding: 0.65rem 0.9rem;
        gap: 0.9rem;
        font-weight: 500;
        color: var(--p-surface-800);
    }
    .fm-context-menu.p-contextmenu .p-contextmenu-item-icon {
        color: var(--p-surface-600);
        font-size: 1rem;
    }
    .fm-context-menu.p-contextmenu .p-contextmenu-item:not(.p-disabled) .p-contextmenu-item-content:hover {
        background: var(--p-surface-100);
    }
    .fm-context-menu.p-contextmenu .p-contextmenu-separator {
        margin: 0.35rem 0;
        border-top: 1px solid var(--p-surface-200);
    }
    .dark .fm-context-menu.p-contextmenu {
        background: var(--p-surface-900);
        border-color: var(--p-surface-700);
        box-shadow:
            0 10px 30px -12px rgba(0, 0, 0, 0.6),
            0 4px 10px -4px rgba(0, 0, 0, 0.35);
    }
    .dark .fm-context-menu.p-contextmenu .p-contextmenu-item-link {
        color: var(--p-surface-100);
    }
    .dark .fm-context-menu.p-contextmenu .p-contextmenu-item-icon {
        color: var(--p-surface-400);
    }
    .dark .fm-context-menu.p-contextmenu .p-contextmenu-item:not(.p-disabled) .p-contextmenu-item-content:hover {
        background: var(--p-surface-800);
    }
    .dark .fm-context-menu.p-contextmenu .p-contextmenu-separator {
        border-color: var(--p-surface-700);
    }
</style>
