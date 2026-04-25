<script setup lang="ts">
    import { useApi } from '@/composables/useApi';
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import logs from '@/routes/logs';
    import { Link } from '@inertiajs/vue3';
    import { trans } from 'laravel-vue-i18n';
    import { Button, DatePicker, InputText, MultiSelect, ProgressSpinner } from 'primevue';
    import { useToast } from 'primevue/usetoast';
    import { computed, onMounted, reactive, ref } from 'vue';

    interface LogFileMeta {
        name: string;
        path: string;
        size_bytes: number;
        modified_at: string;
        channel_type: 'daily' | 'single' | 'other';
        is_active: boolean;
    }

    interface LogEntry {
        timestamp: string;
        level: string;
        env: string;
        message: string;
        context: Record<string, unknown> | null;
        stack: string | null;
        is_raw: boolean;
    }

    interface PageEnvelope {
        entries: LogEntry[];
        next_cursor: number | null;
        eof: boolean;
    }

    interface Props {
        file: LogFileMeta;
    }

    const props = defineProps<Props>();
    const api = useApi({ toast: false });
    const toast = useToast();

    const LEVEL_OPTIONS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    const filter = reactive({
        levels: [] as string[],
        from: null as Date | null,
        to: null as Date | null,
        keyword: '',
    });

    const entries = ref<LogEntry[]>([]);
    const expanded = reactive(new Set<number>());
    const cursor = ref<number | null>(null);
    const eof = ref(false);
    const loading = ref(false);

    function formatSize(bytes: number): string {
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
        return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
    }

    function levelClass(level: string): string {
        const map: Record<string, string> = {
            emergency: 'bg-red-700 text-white',
            alert: 'bg-red-600 text-white',
            critical: 'bg-red-500 text-white',
            error: 'bg-red-100 text-red-800',
            warning: 'bg-yellow-100 text-yellow-800',
            notice: 'bg-blue-100 text-blue-800',
            info: 'bg-blue-50 text-blue-700',
            debug: 'bg-gray-100 text-gray-700',
        };
        return map[level] ?? 'bg-gray-100 text-gray-700';
    }

    function buildQueryString(): string {
        const params = new URLSearchParams();
        if (filter.levels.length > 0) {
            filter.levels.forEach((l) => params.append('levels[]', l));
        }
        if (filter.from) params.append('from', filter.from.toISOString());
        if (filter.to) params.append('to', filter.to.toISOString());
        if (filter.keyword) params.append('keyword', filter.keyword);
        if (cursor.value !== null) params.append('cursor', String(cursor.value));
        params.append('per_page', '100');
        return params.toString();
    }

    async function fetchPage(append: boolean) {
        loading.value = true;
        try {
            const url = logs.entries.url({ filename: props.file.name }) + '?' + buildQueryString();
            const res = await api.get<PageEnvelope>(url);
            if (append) {
                entries.value.push(...res.entries);
            } else {
                entries.value = res.entries;
                expanded.clear();
            }
            cursor.value = res.next_cursor;
            eof.value = res.eof;
        } catch (e) {
            toast.add({
                severity: 'error',
                summary: trans('sk-common.error'),
                detail: (e as Error).message,
                group: 'bc',
                life: 5000,
            });
        } finally {
            loading.value = false;
        }
    }

    function applyFilters() {
        cursor.value = null;
        eof.value = false;
        fetchPage(false);
    }

    function resetFilters() {
        filter.levels = [];
        filter.from = null;
        filter.to = null;
        filter.keyword = '';
        applyFilters();
    }

    function loadMore() {
        if (!eof.value && !loading.value) {
            fetchPage(true);
        }
    }

    function toggle(idx: number) {
        if (expanded.has(idx)) expanded.delete(idx);
        else expanded.add(idx);
    }

    const isExpanded = (idx: number) => expanded.has(idx);
    const visibleCount = computed(() => entries.value.length);

    onMounted(() => fetchPage(false));
</script>

<template>
    <AdminLayout :title="file.name" :subtitle="$t('sk-log.subtitle')">
        <template #page-actions>
            <Link :href="logs.index.url()">
                <Button :label="$t('sk-log.back_to_list')" icon="pi pi-arrow-left" severity="secondary" outlined />
            </Link>
        </template>

        <div class="space-y-4">
            <div class="bg-white dark:bg-slate-800 rounded p-4 flex flex-wrap gap-4 items-center text-sm">
                <span class="font-mono text-slate-500">{{ file.path }}</span>
                <span>{{ formatSize(file.size_bytes) }}</span>
                <span>{{ new Date(file.modified_at).toLocaleString('tr-TR') }}</span>
                <span v-if="file.is_active" class="px-2 py-1 rounded text-xs bg-green-100 text-green-800">
                    {{ $t('sk-log.active_yes') }}
                </span>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded p-4 grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="text-xs font-medium block mb-1">{{ $t('sk-log.level') }}</label>
                    <MultiSelect
                        v-model="filter.levels"
                        :options="LEVEL_OPTIONS"
                        :placeholder="$t('sk-log.all_levels')"
                        class="w-full"
                    />
                </div>
                <div>
                    <label class="text-xs font-medium block mb-1">{{ $t('sk-log.from') }}</label>
                    <DatePicker v-model="filter.from" show-time class="w-full" />
                </div>
                <div>
                    <label class="text-xs font-medium block mb-1">{{ $t('sk-log.to') }}</label>
                    <DatePicker v-model="filter.to" show-time class="w-full" />
                </div>
                <div>
                    <label class="text-xs font-medium block mb-1">{{ $t('sk-log.search_messages') }}</label>
                    <InputText v-model="filter.keyword" class="w-full" />
                </div>
                <div class="md:col-span-4 flex gap-2">
                    <Button :label="$t('sk-log.apply')" icon="pi pi-search" :loading="loading" @click="applyFilters" />
                    <Button
                        :label="$t('sk-log.reset')"
                        icon="pi pi-refresh"
                        severity="secondary"
                        outlined
                        @click="resetFilters"
                    />
                </div>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded">
                <div class="px-4 py-2 text-xs text-slate-500 border-b border-slate-200 dark:border-slate-700">
                    {{ $t('sk-log.showing_n_entries', { count: visibleCount }) }}
                </div>

                <div v-if="entries.length === 0 && !loading" class="p-6 text-center text-slate-500">
                    {{ $t('sk-log.no_entries') }}
                </div>

                <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                    <li v-for="(entry, idx) in entries" :key="idx" class="p-3">
                        <button class="flex items-start gap-3 w-full text-left" @click="toggle(idx)">
                            <span
                                :class="[
                                    'px-2 py-0.5 rounded text-xs uppercase font-semibold shrink-0',
                                    levelClass(entry.level),
                                ]"
                            >
                                {{ entry.level }}
                            </span>
                            <span v-if="!entry.is_raw" class="font-mono text-xs text-slate-500 shrink-0">
                                {{ new Date(entry.timestamp).toLocaleTimeString('tr-TR') }}
                            </span>
                            <span class="flex-1 truncate font-mono text-sm">{{ entry.message }}</span>
                            <i :class="['pi', isExpanded(idx) ? 'pi-chevron-down' : 'pi-chevron-right']" />
                        </button>
                        <div v-if="isExpanded(idx)" class="mt-2 ml-12 space-y-2 text-xs">
                            <div class="font-mono whitespace-pre-wrap break-words">
                                {{ entry.message }}
                            </div>
                            <pre v-if="entry.context" class="bg-slate-50 dark:bg-slate-900 p-2 rounded overflow-auto">{{
                                JSON.stringify(entry.context, null, 2)
                            }}</pre>
                            <pre
                                v-if="entry.stack"
                                class="bg-slate-50 dark:bg-slate-900 p-2 rounded overflow-auto whitespace-pre-wrap"
                            >{{ entry.stack }}</pre>
                        </div>
                    </li>
                </ul>

                <div class="p-4 flex justify-center">
                    <Button
                        v-if="!eof"
                        :label="$t('sk-log.load_more')"
                        icon="pi pi-chevron-down"
                        severity="secondary"
                        :loading="loading"
                        @click="loadMore"
                    />
                    <ProgressSpinner v-if="loading && entries.length === 0" style="width: 32px; height: 32px" />
                    <span v-if="eof && entries.length > 0" class="text-xs text-slate-400">
                        {{ $t('sk-log.eof') }}
                    </span>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
