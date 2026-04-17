<script setup lang="ts">
    import { DB } from '@lvntr/components/DatatableBuilder/core';
    import { useDialog } from '@/composables/useDialog';
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import ActivityLogDetail from '@/pages/Admin/ActivityLogs/components/ActivityLogDetail.vue';
    import activityLogs from '@/routes/activity-logs';
    import { trans } from 'laravel-vue-i18n';

    interface FilterOption {
        label: string;
        value: string;
    }

    const { subjectTypes } = defineProps<{
        subjectTypes: FilterOption[];
    }>();

    interface ActivityLog {
        id: number;
        log_name: string;
        description: string;
        subject_type: string | null;
        subject_id: string | null;
        causer_type: string | null;
        causer_id: string | null;
        event: string | null;
        attribute_changes: {
            old?: Record<string, unknown>;
            attributes?: Record<string, unknown>;
        } | null;
        properties: Record<string, unknown> | null;
        created_at: string;
        causer?: { id: string; name?: string; email?: string } | null;
        subject?: Record<string, unknown> | null;
    }

    const dialog = useDialog();

    function modelShortName(fqcn: string | null): string {
        if (!fqcn) return '—';
        const parts = fqcn.split('\\');
        return parts[parts.length - 1];
    }

    function escapeHtml(str: string): string {
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function eventBadge(event: string | null): string {
        const map: Record<string, string> = {
            created: `<span class="inline-flex items-center gap-1 text-green-700 bg-green-100 dark:text-green-400 dark:bg-green-900/30 px-2 py-0.5 rounded">● ${trans('sk-activity-log.event_created')}</span>`,
            updated: `<span class="inline-flex items-center gap-1  text-blue-700 bg-blue-100 dark:text-blue-400 dark:bg-blue-900/30 px-2 py-0.5 rounded">● ${trans('sk-activity-log.event_updated')}</span>`,
            deleted: `<span class="inline-flex items-center gap-1  text-red-700 bg-red-100 dark:text-red-400 dark:bg-red-900/30 px-2 py-0.5 rounded">● ${trans('sk-activity-log.event_deleted')}</span>`,
        };
        const label = event ? escapeHtml(event) : '—';
        return (
            map[event ?? ''] ??
            `<span class="inline-flex items-center text-xs font-medium text-surface-600 dark:text-surface-400 bg-surface-100 dark:bg-surface-800 px-2 py-0.5 rounded">${label}</span>`
        );
    }

    // ── Detail dialog ─────────────────────────────────────────────────────────────

    function openDetailDialog(activity: ActivityLog) {
        dialog.openAsync<ActivityLog>(
            ActivityLogDetail,
            activityLogs.show.url(activity.id),
            trans('sk-activity-log.detail_title'),
            {
                mapResponse: (data) => ({ data }),
            },
            { onCancel: () => dialog.close() },
        );
    }

    // ── SkDatatable ─────────────────────────────────────────────────────────────────

    const tableConfig = DB.table<ActivityLog>()
        .route(activityLogs.dtApi.url())
        .addColumns(
            DB.column<ActivityLog>()
                .label(trans('sk-activity-log.event'))
                .key('event')
                .render((row) => eventBadge(row.event)),
            // DB.column<ActivityLog>().label(trans('sk-activity-log.description')).key('description'),
            DB.column<ActivityLog>().label(trans('sk-activity-log.model_id')).key('subject_id').sortable(false),
            DB.column<ActivityLog>()
                .label(trans('sk-activity-log.model'))
                .key('subject_type')
                .sortable(false)
                .render((row) => modelShortName(row.subject_type)),
            DB.column<ActivityLog>()
                .label(trans('sk-activity-log.causer'))
                .key('causer')
                .sortable(false)
                .render((row) => {
                    if (!row.causer) return '<span class="text-surface-400">System</span>';
                    return row.causer.name ?? row.causer.email ?? String(row.causer_id);
                }),
            DB.column<ActivityLog>()
                .label(trans('sk-activity-log.date'))
                .key('created_at')
                .render((row) =>
                    new Date(row.created_at).toLocaleDateString('tr-TR', {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                    }),
                ),
        )
        .addFilters(
            DB.filter()
                .key('event')
                .label(trans('sk-activity-log.event'))
                .type('select')
                .inline()
                .options([
                    { label: trans('sk-activity-log.event_created'), value: 'created' },
                    { label: trans('sk-activity-log.event_updated'), value: 'updated' },
                    { label: trans('sk-activity-log.event_deleted'), value: 'deleted' },
                ]),
            DB.filter().key('subject_type').label(trans('sk-activity-log.model')).type('select').options(subjectTypes),
            DB.filter().key('created_at').label(trans('sk-activity-log.date')).type('daterange'),
        )
        .addActions(
            DB.action<ActivityLog>()
                .icon('pi pi-eye')
                .severity('info')
                .tooltip(trans('sk-activity-log.detail'))
                .handle((row) => openDetailDialog(row)),
        )
        .build();
</script>

<template>
    <AdminLayout :title="$t('sk-activity-log.title')" :subtitle="$t('sk-activity-log.subtitle')">
        <SkDatatable :config="tableConfig" refresh-key="activity-logs-table" />
    </AdminLayout>
</template>
