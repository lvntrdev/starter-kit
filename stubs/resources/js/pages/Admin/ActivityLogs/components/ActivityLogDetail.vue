<script setup lang="ts">
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
        updated_at: string;
        causer?: { id: string; name?: string; email?: string } | null;
        subject?: Record<string, unknown> | null;
    }

    interface Props {
        data: ActivityLog;
    }

    const props = defineProps<Props>();

    const emit = defineEmits<{
        cancel: [];
    }>();

    function modelShortName(fqcn: string | null): string {
        if (!fqcn) return '—';
        const parts = fqcn.split('\\');
        return parts[parts.length - 1];
    }

    const eventColorMap: Record<string, string> = {
        created: 'text-green-700 bg-green-100 dark:text-green-400 dark:bg-green-900/30',
        updated: 'text-blue-700 bg-blue-100 dark:text-blue-400 dark:bg-blue-900/30',
        deleted: 'text-red-700 bg-red-100 dark:text-red-400 dark:bg-red-900/30',
    };

    const eventClass = computed(
        () =>
            eventColorMap[props.data.event ?? ''] ??
            'text-surface-600 bg-surface-100 dark:text-surface-400 dark:bg-surface-800',
    );

    const changedKeys = computed(() => {
        const attrs = props.data.attribute_changes?.attributes ?? {};
        return Object.keys(attrs);
    });

    function formatValue(val: unknown): string {
        if (val === null || val === undefined) return '—';
        if (typeof val === 'object') return JSON.stringify(val);
        return String(val);
    }
</script>

<template>
    <div class="space-y-5 p-1">
        <!-- Header info -->
        <div class="grid grid-cols-2 gap-4">
            <div>
                <span class="block text-xs font-medium uppercase text-surface-400 dark:text-surface-500">{{
                    $t('sk-activity-log.event')
                }}</span>
                <span
                    class="mt-1 inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold"
                    :class="eventClass"
                >
                    {{ data.event ?? '—' }}
                </span>
            </div>
            <div>
                <span class="block text-xs font-medium uppercase text-surface-400 dark:text-surface-500">{{
                    $t('sk-activity-log.log_name')
                }}</span>
                <span class="mt-1 block text-sm text-surface-800 dark:text-surface-200">{{ data.log_name }}</span>
            </div>
            <div>
                <span class="block text-xs font-medium uppercase text-surface-400 dark:text-surface-500">{{
                    $t('sk-activity-log.model')
                }}</span>
                <span class="mt-1 block text-sm text-surface-800 dark:text-surface-200">
                    {{ modelShortName(data.subject_type) }}
                    <span v-if="data.subject_id" class="text-surface-400">#{{ data.subject_id }}</span>
                </span>
            </div>
            <div>
                <span class="block text-xs font-medium uppercase text-surface-400 dark:text-surface-500">{{
                    $t('sk-activity-log.causer')
                }}</span>
                <span class="mt-1 block text-sm text-surface-800 dark:text-surface-200">
                    <template v-if="data.causer">
                        {{ data.causer.name ?? data.causer.email ?? data.causer_id }}
                    </template>
                    <template v-else>
                        <span class="text-surface-400">System</span>
                    </template>
                </span>
            </div>
            <div>
                <span class="block text-xs font-medium uppercase text-surface-400 dark:text-surface-500">{{
                    $t('sk-activity-log.date')
                }}</span>
                <span class="mt-1 block text-sm text-surface-800 dark:text-surface-200">
                    {{
                        new Date(data.created_at).toLocaleDateString('tr-TR', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit',
                            second: '2-digit',
                        })
                    }}
                </span>
            </div>
            <div>
                <span class="block text-xs font-medium uppercase text-surface-400 dark:text-surface-500">
                    {{ $t('sk-activity-log.description') }}
                </span>
                <span class="mt-1 block text-sm text-surface-800 dark:text-surface-200">{{ data.description }}</span>
            </div>
        </div>

        <!-- Changes table -->
        <div v-if="changedKeys.length > 0">
            <h3 class="mb-2 text-sm font-semibold text-surface-700 dark:text-surface-300">
                {{ $t('sk-activity-log.changes') }}
            </h3>
            <div class="overflow-hidden rounded-lg border border-surface-200 dark:border-surface-700">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-surface-50 dark:bg-surface-800">
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-surface-500">
                                {{ $t('sk-activity-log.field') }}
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-surface-500">
                                {{ $t('sk-activity-log.old') }}
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-surface-500">
                                {{ $t('sk-activity-log.new') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="key in changedKeys"
                            :key="key"
                            class="border-t border-surface-200 dark:border-surface-700"
                        >
                            <td class="px-3 py-2 font-medium text-surface-700 dark:text-surface-300">
                                {{ key }}
                            </td>
                            <td class="px-3 py-2 text-red-600 dark:text-red-400">
                                {{ formatValue(data.attribute_changes?.old?.[key]) }}
                            </td>
                            <td class="px-3 py-2 text-green-600 dark:text-green-400">
                                {{ formatValue(data.attribute_changes?.attributes?.[key]) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Custom properties (withProperties user data) -->
        <div v-if="data.properties && Object.keys(data.properties).length > 0">
            <h3 class="mb-2 text-sm font-semibold text-surface-700 dark:text-surface-300">
                {{ $t('sk-activity-log.properties') }}
            </h3>
            <pre
                class="overflow-auto rounded bg-surface-50 p-3 text-xs text-surface-700 dark:bg-surface-800 dark:text-surface-300"
            >{{ JSON.stringify(data.properties, null, 2) }}</pre>
        </div>

        <!-- Close button -->
        <div class="flex justify-end pt-2">
            <Button
                :label="$t('sk-button.close')"
                icon="pi pi-times"
                severity="secondary"
                outlined
                @click="emit('cancel')"
            />
        </div>
    </div>
</template>
