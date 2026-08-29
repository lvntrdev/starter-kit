<script setup lang="ts">
    import { computed, onMounted, reactive, ref } from 'vue';
    import { Head } from '@inertiajs/vue3';
    import { formatDateTime, formatTime } from '@lvntr/components/utils/datetime';
    import { trans } from 'laravel-vue-i18n';
    import { Button } from 'primevue';
    import { useToast } from 'primevue/usetoast';
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import { useApi } from '@/composables/useApi';
    import logs from '@/routes/logs';

    // Aura: başlık ve geri butonu topbar'da (AdminLayout `back-url`'ü oraya
    // taşır) — bu sayfa ayrı bir geri afordansı çizmez.

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

    // Severity → Tailwind class sets. Full strings so Tailwind's JIT picks them up.
    interface LevelMeta {
        bar: string;
        pill: string;
        dot: string;
    }

    const LEVEL_META: Record<string, LevelMeta> = {
        emergency: { bar: 'bg-red-800', pill: 'bg-red-800/15 text-red-800 dark:text-red-300', dot: 'bg-red-800' },
        alert: { bar: 'bg-red-700', pill: 'bg-red-700/15 text-red-700 dark:text-red-300', dot: 'bg-red-700' },
        critical: { bar: 'bg-rose-700', pill: 'bg-rose-700/15 text-rose-700 dark:text-rose-300', dot: 'bg-rose-700' },
        error: { bar: 'bg-red-600', pill: 'bg-red-600/15 text-red-700 dark:text-red-400', dot: 'bg-red-600' },
        warning: { bar: 'bg-amber-600', pill: 'bg-amber-600/15 text-amber-700 dark:text-amber-400', dot: 'bg-amber-600' },
        notice: { bar: 'bg-teal-600', pill: 'bg-teal-600/15 text-teal-700 dark:text-teal-400', dot: 'bg-teal-600' },
        info: { bar: 'bg-blue-600', pill: 'bg-blue-600/15 text-blue-700 dark:text-blue-400', dot: 'bg-blue-600' },
        debug: { bar: 'bg-slate-500', pill: 'bg-slate-500/15 text-slate-600 dark:text-slate-300', dot: 'bg-slate-500' },
    };

    const FALLBACK_META: LevelMeta = {
        bar: 'bg-surface-400',
        pill: 'bg-surface-400/15 text-surface-600 dark:text-surface-300',
        dot: 'bg-surface-400',
    };

    function levelMeta(level: string): LevelMeta {
        return LEVEL_META[level.toLowerCase()] ?? FALLBACK_META;
    }

    // ── Filter state ────────────────────────────────────────────────────
    const filter = reactive({
        level: 'all' as string,
        start: '' as string, // HH:MM:SS
        end: '' as string,
        keyword: '' as string,
    });

    const entries = ref<LogEntry[]>([]);
    const expanded = reactive(new Set<number>());
    const cursor = ref<number | null>(null);
    const eof = ref(false);
    const loading = ref(false);

    // Base date for the time-only filter inputs: the date encoded in a daily
    // filename (laravel-YYYY-MM-DD.log), else the file's modified date.
    const baseDate = computed(() => {
        const m = props.file.name.match(/(\d{4}-\d{2}-\d{2})/);
        return m ? m[1] : props.file.modified_at.slice(0, 10);
    });

    function formatSize(bytes: number): string {
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
        return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
    }

    function timeToIso(time: string): string | null {
        if (!time) return null;
        const d = new Date(`${baseDate.value}T${time}`);
        return Number.isNaN(d.getTime()) ? null : d.toISOString();
    }

    function buildQueryString(): string {
        const parts: string[] = [];
        if (filter.level !== 'all') parts.push(`levels[]=${encodeURIComponent(filter.level)}`);
        const from = timeToIso(filter.start);
        const to = timeToIso(filter.end);
        if (from) parts.push(`from=${encodeURIComponent(from)}`);
        if (to) parts.push(`to=${encodeURIComponent(to)}`);
        if (filter.keyword) parts.push(`keyword=${encodeURIComponent(filter.keyword)}`);
        if (cursor.value !== null) parts.push(`cursor=${cursor.value}`);
        parts.push('per_page=100');
        return parts.join('&');
    }

    async function fetchPage(append: boolean): Promise<void> {
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

    function applyFilters(): void {
        cursor.value = null;
        eof.value = false;
        fetchPage(false);
    }

    function resetFilters(): void {
        filter.level = 'all';
        filter.start = '';
        filter.end = '';
        filter.keyword = '';
        applyFilters();
    }

    function selectLevel(level: string): void {
        filter.level = filter.level === level ? 'all' : level;
        applyFilters();
    }

    function loadMore(): void {
        if (!eof.value && !loading.value) fetchPage(true);
    }

    // ── Expand / collapse ───────────────────────────────────────────────
    function toggle(idx: number): void {
        if (expanded.has(idx)) expanded.delete(idx);
        else expanded.add(idx);
    }

    const isExpanded = (idx: number) => expanded.has(idx);

    function expandAll(): void {
        entries.value.forEach((_, i) => expanded.add(i));
    }

    function collapseAll(): void {
        expanded.clear();
    }

    // ── Derived ─────────────────────────────────────────────────────────
    const visibleCount = computed(() => entries.value.length);

    function levelCount(level: string): number {
        return entries.value.filter((e) => e.level.toLowerCase() === level).length;
    }

    const presentLevels = computed(() => LEVEL_OPTIONS.filter((l) => levelCount(l) > 0));

    function entryTime(entry: LogEntry): string {
        if (entry.is_raw) return '';
        return formatTime(entry.timestamp, { hour: 'numeric', minute: 'numeric', second: 'numeric' });
    }

    /** Stack string → trimmed lines for the numbered trace view. */
    function traceLines(stack: string | null): string[] {
        if (!stack) return [];
        return stack
            .split('\n')
            .map((l) => l.trim())
            .filter(Boolean);
    }

    onMounted(() => fetchPage(false));
</script>

<template>
    <Head :title="file.name" />

    <AdminLayout :title="file.name" :subtitle="$t('sk-log.subtitle')" :back-url="logs.index.url()" :header-in-card="true">
        <div class="flex flex-col gap-4">
            <!-- File header bar -->
            <div
                class="flex items-center gap-3.5 rounded border border-surface-200 bg-surface-0 px-4 py-3.5 dark:border-surface-700 dark:bg-surface-900"
            >
                <span
                    class="grid h-[42px] w-[42px] shrink-0 place-items-center rounded-md bg-[color-mix(in_srgb,var(--p-primary-color)_11%,transparent)] text-[18px] text-[var(--p-primary-color)]"
                >
                    <i class="pi pi-file" />
                </span>
                <div class="min-w-0 flex-1">
                    <span
                        class="block truncate font-mono text-[12.5px] text-surface-500 dark:text-surface-400"
                        :title="file.path"
                    >
                        {{ file.path }}
                    </span>
                    <div class="mt-1.5 flex flex-wrap items-center gap-3.5">
                        <span class="inline-flex items-center gap-1.5 text-[12px] text-surface-600 dark:text-surface-300">
                            <i class="pi pi-database text-[12px] text-surface-400" />
                            <b class="font-semibold text-surface-900 dark:text-surface-0">{{ formatSize(file.size_bytes) }}</b>
                        </span>
                        <span class="inline-flex items-center gap-1.5 text-[12px] text-surface-600 dark:text-surface-300">
                            <i class="pi pi-clock text-[12px] text-surface-400" />
                            <b class="font-semibold text-surface-900 dark:text-surface-0">
                                {{
                                    formatDateTime(file.modified_at, {
                                        year: 'numeric',
                                        month: 'numeric',
                                        day: 'numeric',
                                        hour: 'numeric',
                                        minute: 'numeric',
                                        second: 'numeric',
                                    })
                                }}
                            </b>
                        </span>
                        <span
                            v-if="file.is_active"
                            class="inline-flex h-[24px] items-center gap-1.5 rounded-full border border-green-600/25 bg-green-600/10 px-2.5 text-[11.5px] font-semibold text-green-700 dark:text-green-300"
                        >
                            <span class="h-1.5 w-1.5 rounded-full bg-green-600 dark:bg-green-400" />
                            {{ $t('sk-log.active_yes') }}
                        </span>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <Button
                        :label="$t('sk-button.refresh')"
                        icon="pi pi-refresh"
                        severity="secondary"
                        text
                        :loading="loading"
                        @click="applyFilters"
                    />
                </div>
            </div>

            <!-- Filter card -->
            <div
                class="overflow-hidden rounded border border-surface-200 bg-surface-0 dark:border-surface-700 dark:bg-surface-900"
            >
                <div class="flex flex-col gap-4 p-[18px] md:flex-row md:items-end">
                    <div class="grid flex-1 grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <!-- level -->
                        <div>
                            <label class="mb-1.5 block font-mono text-[11px] font-semibold tracking-wider text-surface-500 uppercase dark:text-surface-400">
                                {{ $t('sk-log.level') }}
                            </label>
                            <div class="relative">
                                <select
                                    v-model="filter.level"
                                    class="h-10 w-full cursor-pointer appearance-none rounded-md border border-surface-200 bg-surface-0 pr-9 pl-3 text-[13.5px] text-surface-900 outline-none focus:border-[var(--p-primary-color)] dark:border-surface-700 dark:bg-surface-900 dark:text-surface-0"
                                >
                                    <option value="all">{{ $t('sk-log.all_levels') }}</option>
                                    <option v-for="l in LEVEL_OPTIONS" :key="l" :value="l">{{ l }}</option>
                                </select>
                                <i class="pi pi-chevron-down pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-[11px] text-surface-400" />
                            </div>
                        </div>
                        <!-- start time -->
                        <div>
                            <label class="mb-1.5 block font-mono text-[11px] font-semibold tracking-wider text-surface-500 uppercase dark:text-surface-400">
                                {{ $t('sk-log.from') }}
                            </label>
                            <input
                                v-model="filter.start"
                                type="time"
                                step="1"
                                class="h-10 w-full rounded-md border border-surface-200 bg-surface-0 px-3 text-[13.5px] text-surface-900 outline-none focus:border-[var(--p-primary-color)] dark:border-surface-700 dark:bg-surface-900 dark:text-surface-0"
                            />
                        </div>
                        <!-- end time -->
                        <div>
                            <label class="mb-1.5 block font-mono text-[11px] font-semibold tracking-wider text-surface-500 uppercase dark:text-surface-400">
                                {{ $t('sk-log.to') }}
                            </label>
                            <input
                                v-model="filter.end"
                                type="time"
                                step="1"
                                class="h-10 w-full rounded-md border border-surface-200 bg-surface-0 px-3 text-[13.5px] text-surface-900 outline-none focus:border-[var(--p-primary-color)] dark:border-surface-700 dark:bg-surface-900 dark:text-surface-0"
                            />
                        </div>
                        <!-- keyword -->
                        <div>
                            <label class="mb-1.5 block font-mono text-[11px] font-semibold tracking-wider text-surface-500 uppercase dark:text-surface-400">
                                {{ $t('sk-log.search_messages') }}
                            </label>
                            <div class="relative">
                                <i class="pi pi-search pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-[13px] text-surface-400" />
                                <input
                                    v-model="filter.keyword"
                                    type="text"
                                    :placeholder="$t('sk-log.keyword_placeholder')"
                                    class="h-10 w-full rounded-md border border-surface-200 bg-surface-0 pr-3 pl-9 text-[13.5px] text-surface-900 outline-none placeholder:text-surface-400 focus:border-[var(--p-primary-color)] dark:border-surface-700 dark:bg-surface-900 dark:text-surface-0"
                                    @keyup.enter="applyFilters"
                                />
                            </div>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <Button :label="$t('sk-log.apply')" icon="pi pi-search" :loading="loading" @click="applyFilters" />
                        <Button
                            :label="$t('sk-log.reset')"
                            icon="pi pi-replay"
                            severity="secondary"
                            text
                            @click="resetFilters"
                        />
                    </div>
                </div>

                <!-- Severity summary -->
                <div
                    class="flex flex-wrap items-center gap-2 border-t border-surface-200 bg-surface-50 px-[18px] py-3 dark:border-surface-700 dark:bg-surface-800/50"
                >
                    <button
                        type="button"
                        class="inline-flex h-[30px] items-center gap-2 rounded-md border px-2.5 text-[12px] font-medium transition-colors"
                        :class="
                            filter.level === 'all'
                                ? 'border-surface-300 bg-surface-100 text-surface-700 dark:border-surface-600 dark:bg-surface-700 dark:text-surface-100'
                                : 'border-surface-200 bg-surface-0 text-surface-500 hover:text-surface-700 dark:border-surface-700 dark:bg-surface-900 dark:text-surface-400'
                        "
                        @click="selectLevel('all')"
                    >
                        {{ $t('sk-log.all') }}
                        <span class="font-mono text-[11.5px] font-semibold tabular-nums text-surface-700 dark:text-surface-200">
                            {{ visibleCount }}
                        </span>
                    </button>
                    <button
                        v-for="l in presentLevels"
                        :key="l"
                        type="button"
                        class="inline-flex h-[30px] items-center gap-2 rounded-md border px-2.5 text-[12px] font-medium transition-colors"
                        :class="
                            filter.level === l
                                ? 'border-surface-300 bg-surface-100 text-surface-900 dark:border-surface-600 dark:bg-surface-700 dark:text-surface-0'
                                : 'border-surface-200 bg-surface-0 text-surface-600 hover:text-surface-900 dark:border-surface-700 dark:bg-surface-900 dark:text-surface-300'
                        "
                        @click="selectLevel(l)"
                    >
                        <span class="h-2 w-2 rounded-full" :class="levelMeta(l).dot" />
                        {{ l }}
                        <span class="font-mono text-[11.5px] font-semibold tabular-nums text-surface-700 dark:text-surface-200">
                            {{ levelCount(l) }}
                        </span>
                    </button>
                </div>
            </div>

            <!-- Entry list -->
            <div
                class="overflow-hidden rounded border border-surface-200 bg-surface-0 dark:border-surface-700 dark:bg-surface-900"
            >
                <div
                    class="flex items-center gap-3 border-b border-surface-200 bg-surface-50 px-[18px] py-3 dark:border-surface-700 dark:bg-surface-800/50"
                >
                    <span class="text-[12.5px] text-surface-500 dark:text-surface-400">
                        {{ $t('sk-log.showing_n_entries', { count: String(visibleCount) }) }}
                    </span>
                    <div class="ml-auto flex items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex h-[30px] items-center gap-1.5 rounded-md px-2.5 text-[12px] text-surface-500 transition-colors hover:bg-surface-100 hover:text-surface-700 dark:text-surface-400 dark:hover:bg-surface-800 dark:hover:text-surface-200"
                            :disabled="!entries.length"
                            @click="expandAll"
                        >
                            <i class="pi pi-angle-double-down" />{{ $t('sk-log.expand_all') }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex h-[30px] items-center gap-1.5 rounded-md px-2.5 text-[12px] text-surface-500 transition-colors hover:bg-surface-100 hover:text-surface-700 dark:text-surface-400 dark:hover:bg-surface-800 dark:hover:text-surface-200"
                            :disabled="!entries.length"
                            @click="collapseAll"
                        >
                            <i class="pi pi-angle-double-up" />{{ $t('sk-log.collapse_all') }}
                        </button>
                    </div>
                </div>

                <template v-if="entries.length">
                    <div
                        v-for="(entry, idx) in entries"
                        :key="idx"
                        class="border-b border-surface-200 last:border-0 dark:border-surface-700"
                        :class="isExpanded(idx) ? 'bg-[color-mix(in_srgb,var(--p-primary-color)_4%,transparent)]' : ''"
                    >
                        <button
                            type="button"
                            class="relative flex w-full items-center gap-3.5 py-3 pr-[18px] text-left transition-colors hover:bg-surface-50 dark:hover:bg-surface-800/50"
                            @click="toggle(idx)"
                        >
                            <!-- severity bar -->
                            <span class="absolute top-0 bottom-0 left-0 w-[3px]" :class="levelMeta(entry.level).bar" />
                            <span
                                class="ml-[18px] inline-grid h-[22px] min-w-[74px] place-items-center rounded-md px-2 font-mono text-[10.5px] font-bold tracking-wider uppercase"
                                :class="levelMeta(entry.level).pill"
                            >
                                {{ entry.level }}
                            </span>
                            <span
                                v-if="!entry.is_raw"
                                class="shrink-0 font-mono text-[12px] tabular-nums text-surface-500 dark:text-surface-400"
                            >
                                {{ entryTime(entry) }}
                            </span>
                            <span
                                class="min-w-0 flex-1 font-mono text-[12.5px]"
                                :class="
                                    isExpanded(idx)
                                        ? 'whitespace-normal text-surface-900 dark:text-surface-0'
                                        : 'truncate text-surface-600 dark:text-surface-300'
                                "
                            >
                                {{ entry.message }}
                            </span>
                            <span
                                class="grid h-6 w-6 shrink-0 place-items-center text-[12px] transition-transform"
                                :class="
                                    isExpanded(idx)
                                        ? 'rotate-90 text-[var(--p-primary-color)]'
                                        : 'text-surface-400 dark:text-surface-500'
                                "
                            >
                                <i class="pi pi-chevron-right" />
                            </span>
                        </button>

                        <div v-if="isExpanded(idx)" class="px-[18px] pt-1 pb-5 pl-[39px]">
                            <p class="my-2 font-mono text-[12.5px] leading-relaxed break-words text-surface-900 dark:text-surface-100">
                                {{ entry.message }}
                            </p>
                            <template v-if="entry.context">
                                <div class="mb-2 inline-flex items-center gap-1.5 font-mono text-[11px] font-bold tracking-widest text-surface-500 uppercase dark:text-surface-400">
                                    <i class="pi pi-code" />[context]
                                </div>
                                <pre
                                    class="mb-3 overflow-x-auto rounded-md border border-surface-200 bg-surface-50 p-3.5 font-mono text-[11.5px] leading-relaxed text-surface-700 dark:border-surface-700 dark:bg-surface-800 dark:text-surface-300"
                                >{{ JSON.stringify(entry.context, null, 2) }}</pre>
                            </template>
                            <template v-if="traceLines(entry.stack).length">
                                <div class="mb-2 inline-flex items-center gap-1.5 font-mono text-[11px] font-bold tracking-widest text-surface-500 uppercase dark:text-surface-400">
                                    <i class="pi pi-bars" />[{{ $t('sk-log.stacktrace') }}]
                                </div>
                                <div
                                    class="overflow-x-auto rounded-md border border-surface-200 bg-surface-50 p-3.5 dark:border-surface-700 dark:bg-surface-800"
                                >
                                    <div
                                        v-for="(line, i) in traceLines(entry.stack)"
                                        :key="i"
                                        class="flex gap-3 font-mono text-[11.5px] leading-relaxed whitespace-nowrap text-surface-600 dark:text-surface-300"
                                    >
                                        <span class="min-w-7 shrink-0 text-right text-surface-400 select-none dark:text-surface-500">
                                            #{{ i }}
                                        </span>
                                        <span>{{ line }}</span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- load more / EOF footer -->
                    <div class="flex items-center justify-center p-4">
                        <Button
                            v-if="!eof"
                            :label="$t('sk-log.load_more')"
                            icon="pi pi-chevron-down"
                            severity="secondary"
                            outlined
                            :loading="loading"
                            @click="loadMore"
                        />
                        <span
                            v-else
                            class="flex items-center gap-2.5 font-mono text-[12px] text-surface-400 dark:text-surface-500 before:h-px before:w-[60px] before:bg-surface-200 after:h-px after:w-[60px] after:bg-surface-200 dark:before:bg-surface-700 dark:after:bg-surface-700"
                        >
                            {{ $t('sk-log.eof') }}
                        </span>
                    </div>
                </template>

                <!-- Empty state -->
                <div v-else-if="!loading" class="flex flex-col items-center gap-2 px-6 py-16">
                    <i class="pi pi-inbox text-3xl text-surface-300 dark:text-surface-600" />
                    <p class="text-sm font-semibold text-surface-700 dark:text-surface-200">
                        {{ $t('sk-log.no_entries') }}
                    </p>
                    <p class="text-[13px] text-surface-500 dark:text-surface-400">
                        {{ $t('sk-log.no_entries_sub') }}
                    </p>
                </div>

                <div v-else class="flex items-center justify-center p-10">
                    <i class="pi pi-spinner animate-spin text-2xl text-surface-400" />
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
