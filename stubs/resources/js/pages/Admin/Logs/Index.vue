<script setup lang="ts">
    import { DB } from '@lvntr/components/DatatableBuilder/core';
    import { useApi } from '@/composables/useApi';
    import { useConfirm } from '@/composables/useConfirm';
    import { useRefreshBus } from '@/composables/useRefreshBus';
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import logs from '@/routes/logs';
    import { router } from '@inertiajs/vue3';
    import { trans } from 'laravel-vue-i18n';
    import { useToast } from 'primevue/usetoast';

    // TODO: enable bulk multi-select delete when SkDatatable adds selection support
    // (see DatatableBuilder/core/builder.ts — current TableBuilder has no selectable / addBulkActions API)

    interface LogFileRow {
        name: string;
        path: string;
        size_bytes: number;
        modified_at: string;
        channel_type: 'daily' | 'single' | 'other';
        is_active: boolean;
    }

    const REFRESH_KEY = 'logs-table';
    const api = useApi({ toast: false });
    const toast = useToast();
    const { confirmDelete } = useConfirm();
    const bus = useRefreshBus();

    function formatSize(bytes: number): string {
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
        return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
    }

    function formatLocal(iso: string): string {
        const date = new Date(iso);
        return date.toLocaleString('tr-TR', { dateStyle: 'short', timeStyle: 'short' });
    }

    async function deleteSingle(file: LogFileRow) {
        try {
            const res = await api.delete<{ deleted: string[]; failed: { filename: string; reason: string }[] }>(
                logs.destroy.url(),
                { filenames: [file.name] },
            );
            if (res.deleted.length > 0) {
                toast.add({
                    severity: 'success',
                    summary: trans('sk-log.deleted_count', { count: res.deleted.length }),
                    group: 'bc',
                    life: 3000,
                });
            }
            if (res.failed.length > 0) {
                const reasons = res.failed
                    .map((f) => `${f.filename}: ${trans(`sk-log.reason_${f.reason}`)}`)
                    .join('\n');
                toast.add({
                    severity: 'error',
                    summary: trans('sk-log.failed_count', { count: res.failed.length }),
                    detail: reasons,
                    group: 'bc',
                    life: 6000,
                });
            }
            bus.refresh(REFRESH_KEY);
        } catch (e) {
            toast.add({
                severity: 'error',
                summary: trans('sk-common.error'),
                detail: (e as Error).message,
                group: 'bc',
                life: 5000,
            });
        }
    }

    function onDeleteRow(file: LogFileRow) {
        confirmDelete(() => deleteSingle(file), trans('sk-log.delete_confirm', { name: file.name }));
    }

    const tableConfig = DB.table<LogFileRow>()
        .route(logs.dtApi.url())
        .idColumn(false)
        .addColumns(
            DB.column<LogFileRow>().key('name').label(trans('sk-log.filename')),
            DB.column<LogFileRow>()
                .key('channel_type')
                .label(trans('sk-log.channel'))
                .render((row, escape) => {
                    const label = trans(`sk-log.channel_${row.channel_type}`);
                    const cls =
                        row.channel_type === 'daily'
                            ? 'bg-blue-100 text-blue-800'
                            : row.channel_type === 'single'
                                ? 'bg-amber-100 text-amber-800'
                                : 'bg-slate-100 text-slate-700';
                    return `<span class="px-2 py-1 rounded text-xs ${cls}">${escape(label)}</span>`;
                }),
            DB.column<LogFileRow>()
                .key('size_bytes')
                .label(trans('sk-log.size'))
                .render((row) => formatSize(row.size_bytes)),
            DB.column<LogFileRow>()
                .key('modified_at')
                .label(trans('sk-log.modified'))
                .render((row) => formatLocal(row.modified_at)),
            DB.column<LogFileRow>()
                .key('is_active')
                .label(trans('sk-log.active'))
                .sortable(false)
                .render((row, escape) =>
                    row.is_active
                        ? `<span class="px-2 py-1 rounded text-xs bg-green-100 text-green-800">${escape(trans('sk-log.active_yes'))}</span>`
                        : '',
                ),
        )
        .addActions(
            DB.action<LogFileRow>()
                .icon('pi pi-eye')
                .severity('info')
                .tooltip(trans('sk-common.view'))
                .handle((row) => router.visit(logs.show.url({ filename: row.name }))),
            DB.action<LogFileRow>()
                .icon('pi pi-trash')
                .severity('danger')
                .tooltip(trans('sk-common.delete'))
                .visible((row) => !row.is_active)
                .handle((row) => onDeleteRow(row)),
        )
        .build();
</script>

<template>
    <AdminLayout :title="$t('sk-log.title')" :subtitle="$t('sk-log.subtitle')">
        <SkDatatable :config="tableConfig" :refresh-key="REFRESH_KEY" />
    </AdminLayout>
</template>
