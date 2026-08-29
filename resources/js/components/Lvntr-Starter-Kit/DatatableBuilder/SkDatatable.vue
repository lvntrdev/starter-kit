<script setup lang="ts">
    import { useApi } from '@/composables/useApi';
    import { useDefinition } from '@/composables/useDefinition';
    import { useRefreshBus } from '@/composables/useRefreshBus';
    import { TAILWIND_PALETTES } from '@/composables/useAccentColor';
    import { Link } from '@inertiajs/vue3';
    import type {
        ActionConfig,
        ColumnConfig,
        DataTableConfig,
        DataTableResponse,
        FilterConfig,
        FilterOption,
        ServerColumn,
    } from './core';
    import { escapeHtml } from './core/escapeHtml';
    import type { UseDatatableSelectionReturn } from './selection';
    import { getActiveLanguage, trans } from 'laravel-vue-i18n';
    import type { MenuItem } from 'primevue/menuitem';
    import Ripple from 'primevue/ripple';
    import Tooltip from 'primevue/tooltip';
    import SkCard from '../ui/SkCard.vue';

    // Explicit binding so the template's `v-tooltip` / `v-ripple` compile to a
    // direct reference rather than a dynamic `resolveDirective(...)` call —
    // avoids the "resolveDirective imported but never used" warning in consumer builds.
    const vTooltip = Tooltip;
    const vRipple = Ripple;

    interface Props {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        config: DataTableConfig<any>;
        /** Register this table in the refresh bus under the given key. */
        refreshKey?: string;
        /**
         * Optional selection state from useDatatableSelection().
         * When provided, a checkbox column is shown and row selection is enabled.
         * When omitted, the table behaves exactly as before (no regression).
         */
        selection?: UseDatatableSelectionReturn;
    }

    const props = defineProps<Props>();

    const emit = defineEmits<{
        /** Fired after each successful data fetch. Carries the current page rows and total filtered count. */
        load: [data: unknown[], total: number];
    }>();

    const api = useApi();
    const definition = useDefinition();

    // ── Auto-load definitions for columns and filters ──────────────────────────
    const definitionKeys = [
        ...props.config.columns.filter((c) => c.tag === 'definition' && c.tagKey).map((c) => c.tagKey!),
        ...props.config.filters.filter((f) => f.definitionKey).map((f) => f.definitionKey!),
    ];

    if (definitionKeys.length) {
        onMounted(() => definition.load([...new Set(definitionKeys)]));
    }

    // ── URL-based filter options ───────────────────────────────────────────────
    const urlOptions = reactive<Record<string, FilterOption[]>>({});

    const urlFilters = props.config.filters.filter((f) => f.optionsUrl);
    if (urlFilters.length) {
        onMounted(async () => {
            await Promise.allSettled(
                urlFilters.map(async (f) => {
                    try {
                        // Use the shared api wrapper so XSRF + envelope unwrap
                        // match the rest of the datatable's traffic. A failed
                        // fetch falls back to an empty list; the toast handler
                        // in useApi already surfaces the error.
                        const data = await api.get<FilterOption[]>(f.optionsUrl!);
                        urlOptions[f.key] = data ?? [];
                    } catch {
                        urlOptions[f.key] = [];
                    }
                }),
            );
        });
    }

    /** Resolve filter options: static > definition > URL. */
    function getFilterOptions(filter: FilterConfig): FilterOption[] {
        if (filter.options) return filter.options;
        if (filter.definitionKey) return definition.options(filter.definitionKey);
        if (filter.optionsUrl) return urlOptions[filter.key] ?? [];
        return [];
    }

    /**
     * Option color dot background. `opt.color` is typically a Tailwind color
     * name (e.g. "sky", "emerald", "slate") which is NOT a valid CSS named
     * color, so a raw `background: sky` would render nothing. Tailwind also
     * tree-shakes unused `--color-*` variables, so `var(--color-sky-500)` is
     * not reliably present at runtime — resolve against the inlined v4 oklch
     * palettes (the same source the accent swatches use) so every palette dot
     * renders, falling back to the literal value for hex / real CSS colors.
     */
    function dotStyle(color: string): Record<string, string> {
        return { background: TAILWIND_PALETTES[color]?.[500] ?? color };
    }

    // ── Scroll constraint ──────────────────────────────────────────────────────

    const scrollRef = ref<HTMLElement | null>(null);
    const scrollMaxH = ref<string | undefined>(undefined);

    /** Reserve for pagination + admin-content bottom padding */
    const PAGINATION_RESERVE = 76;

    function calcScrollMax() {
        if (!scrollRef.value) return;

        const adminContent = scrollRef.value.closest('.admin-content');
        if (!adminContent) return;

        // Reset scroll so measurements are from the natural top
        const savedScroll = adminContent.scrollTop;
        adminContent.scrollTop = 0;

        requestAnimationFrame(() => {
            if (!scrollRef.value || !adminContent) return;

            const scrollTop = scrollRef.value.getBoundingClientRect().top;
            const contentBottom = adminContent.getBoundingClientRect().bottom;
            const available = contentBottom - scrollTop - PAGINATION_RESERVE;

            if (available > 100) {
                scrollMaxH.value = `${available}px`;
            }

            adminContent.scrollTop = savedScroll;
        });
    }

    onMounted(() => calcScrollMax());

    const browserWindow = typeof window === 'undefined' ? undefined : window;
    const browserDocument = typeof document === 'undefined' ? undefined : document;

    useEventListener(browserWindow, 'resize', useDebounceFn(calcScrollMax, 200));

    // ── State ────────────────────────────────────────────────────────────────────

    const loading = ref(false);
    const data = ref<unknown[]>([]);
    const meta = ref({
        total: 0,
        per_page: props.config.perPage,
        current_page: 1,
        last_page: 1,
        from: null as number | null,
        to: null as number | null,
    });

    const perPageOptions = computed(() => {
        const defaults = [10, 20, 50, 100];
        return defaults.includes(props.config.perPage)
            ? defaults
            : [...defaults, props.config.perPage].sort((a, b) => a - b);
    });

    function changePerPage(value: number) {
        meta.value.per_page = value;
        currentPage.value = 1;
        fetchData();
    }

    const search = ref('');
    const debouncedSearch = refDebounced(search, 350);

    const sortKey = ref('');
    const sortOrder = ref<'asc' | 'desc'>('asc');
    const currentPage = ref(1);

    type DateFilterValue = Date | null;
    type DaterangeFilterValue = DateFilterValue[] | null;
    type FilterValue = string | number | Date | DateFilterValue[] | null;

    const activeFilters = ref<Record<string, FilterValue>>(
        Object.fromEntries(props.config.filters.map((f) => [f.key, null])),
    );

    // ── Column visibility & order ────────────────────────────────────────────────
    // The server may publish its own column list (DatatableQueryBuilder::columns())
    // so that initially-hidden columns are still offered in the column menu and the
    // backend can shape its payload to the requested `columns=` selection.

    const serverColumns = ref<ServerColumn[] | null>(null);

    const configColumnsByKey = computed(() => new Map(props.config.columns.map((c) => [c.key, c])));

    /** Master column list: server meta (order / labels / defaults) merged over local config. */
    const allColumns = computed<ColumnConfig[]>(() => {
        if (!serverColumns.value?.length) return props.config.columns;

        const merged: ColumnConfig[] = serverColumns.value.map((sc) => {
            const local = configColumnsByKey.value.get(sc.key);
            return {
                ...(local ?? { key: sc.key, sortable: sc.sortable ?? true }),
                key: sc.key,
                label: local?.label ?? sc.label,
                sortable: sc.sortable ?? local?.sortable ?? true,
                visible: sc.visible ?? local?.visible,
                locked: sc.locked ?? local?.locked,
            };
        });

        // Client-only columns (computed/render columns unknown to the server) keep rendering.
        for (const c of props.config.columns) {
            if (!merged.some((m) => m.key === c.key)) merged.push(c);
        }

        return merged;
    });

    const columnOrder = ref<string[]>([]);
    const hiddenColumns = ref<Set<string>>(new Set());

    /** Keys whose default visibility was applied once. */
    const defaultAppliedKeys = new Set<string>();

    /** Keys the user explicitly toggled or restored from a saved state — never overridden. */
    const userTouchedKeys = new Set<string>();

    /** Column keys read back from this route's persisted column order (server-only ones included). */
    const persistedColumnKeys = new Set<string>();

    /** Server column meta gets one shot at (re)applying defaults over the local config. */
    let serverDefaultsApplied = false;

    function reconcileColumns(): void {
        const defs = allColumns.value;
        const available = defs.map((c) => c.key);

        const next = columnOrder.value.filter((k) => available.includes(k));
        for (const key of available) {
            if (!next.includes(key)) next.push(key);
        }
        columnOrder.value = next;

        const applyServerDefaults = serverColumns.value !== null && !serverDefaultsApplied;

        for (const def of defs) {
            // The first server column meta may override a locally-applied default
            // (the mount-time reconcile runs before the server's visible flag is
            // known) — but never a column the user touched.
            const serverOverride = applyServerDefaults && !userTouchedKeys.has(def.key);

            if (!defaultAppliedKeys.has(def.key) || serverOverride) {
                defaultAppliedKeys.add(def.key);
                if (!def.locked) {
                    if (def.visible === false) {
                        hiddenColumns.value.add(def.key);
                    } else if (serverOverride) {
                        hiddenColumns.value.delete(def.key);
                    }
                }
            }
            // Locked columns can never stay hidden.
            if (def.locked) {
                hiddenColumns.value.delete(def.key);
            }
        }

        if (serverColumns.value !== null) {
            serverDefaultsApplied = true;
        }
    }

    reconcileColumns();

    /** All columns in user-defined order (column menu list — includes hidden ones). */
    const orderedColumns = computed<ColumnConfig[]>(() => {
        const byKey = new Map(allColumns.value.map((c) => [c.key, c]));
        return columnOrder.value.map((k) => byKey.get(k)).filter((c): c is ColumnConfig => !!c);
    });

    /** Columns actually rendered in the table. */
    const displayColumns = computed<ColumnConfig[]>(() =>
        orderedColumns.value.filter((c) => !hiddenColumns.value.has(c.key)),
    );

    const showColumnToggle = computed(() => props.config.columnToggle !== false && allColumns.value.length > 0);
    const visibleColumnCount = computed(() => allColumns.value.length - hiddenColumns.value.size);

    function isColumnVisible(key: string): boolean {
        return !hiddenColumns.value.has(key);
    }

    function toggleColumn(def: ColumnConfig): void {
        if (def.locked) return;
        userTouchedKeys.add(def.key);
        const set = new Set(hiddenColumns.value);
        if (set.has(def.key)) {
            set.delete(def.key);
        } else {
            set.add(def.key);
        }
        hiddenColumns.value = set;
        onColumnSelectionChanged();
    }

    function showAllColumns(): void {
        if (!hiddenColumns.value.size) return;
        for (const key of hiddenColumns.value) {
            userTouchedKeys.add(key);
        }
        hiddenColumns.value = new Set();
        onColumnSelectionChanged();
    }

    /** Visibility changes refetch when the server shapes its payload per column. */
    function onColumnSelectionChanged(): void {
        if (serverColumns.value !== null) {
            fetchData();
        } else {
            syncState();
        }
    }

    // ── Column drag & drop reorder (column menu) ────────────────────────────────

    const columnsPopoverRef = ref();
    const dragColumnKey = ref<string | null>(null);

    function onColumnDragStart(key: string, event: DragEvent): void {
        dragColumnKey.value = key;
        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            // Firefox needs data for the drag to start.
            event.dataTransfer.setData('text/plain', key);
        }
    }

    function onColumnDragEnter(overKey: string): void {
        const from = dragColumnKey.value;
        if (!from || from === overKey) return;

        const order = [...columnOrder.value];
        const fromIndex = order.indexOf(from);
        const toIndex = order.indexOf(overKey);
        if (fromIndex === -1 || toIndex === -1) return;

        order.splice(fromIndex, 1);
        order.splice(toIndex, 0, from);
        columnOrder.value = order;
    }

    function onColumnDragEnd(): void {
        if (!dragColumnKey.value) return;
        dragColumnKey.value = null;
        syncState();
    }

    // ── State Persistence ────────────────────────────────────────────────────────

    /** Guard flag — prevents watchers from resetting page during initial restore. */
    let initializing = true;

    /** Storage key — unique per DataTable instance. */
    const storageKey = `dt:${props.config.route}`;
    const reloadFlagKey = `${storageKey}:reload`;
    // Column order/visibility persist SEPARATELY in localStorage so they survive
    // Inertia SPA navigation (which clears the sessionStorage filter/page blob).
    // Keyed by route — the table's instance identity in this kit; two tables on
    // the same route would share prefs, the same constraint `storageKey` already has.
    const columnStorageKey = `dt:cols:${props.config.route}`;

    /**
     * Mark that a page reload is happening (F5/close).
     * `beforeunload` fires on browser reload/close but NOT on Inertia SPA navigation.
     */
    function onBeforeUnload() {
        sessionStorage.setItem(reloadFlagKey, '1');
    }

    /** Strip the `-` descending marker off a `sort` parameter. */
    function sortParamKey(value: string): string {
        return value.startsWith('-') ? value.slice(1) : value;
    }

    /**
     * Can THIS table ask the backend to sort by `key`?
     *
     * A sort the user CLICKS is always one of this table's own columns. A sort read back
     * from the URL or from a stale session blob is not: `sort` is a page-global query
     * parameter, so on a page hosting several tables (tabs, side-by-side panels) the one
     * that mounts second reads the first one's sort and asks its own endpoint for a column
     * that endpoint never allowed — `Spatie\QueryBuilder` answers with HTTP 400
     * (`InvalidSortQuery`) and the table renders empty. The same happens to a bookmarked
     * link after a column is renamed or dropped.
     *
     * Three sources answer the question, because no single one is complete at restore time:
     * the id column (sortable without appearing in `columns`), `allColumns` — which still falls
     * back to the local config, since the server column meta arrives with the first response,
     * after restore — and `persistedColumnKeys`, which `restoreColumnState()` has just read
     * from the route-scoped `dt:cols:` blob. That last one is what keeps a server-only column
     * (a hidden `updated_at` the local page never declares) working: once the user has enabled
     * it, its key is in this table's own persisted column order, so a reload or a shared link
     * still restores the sort. A key seen for the first time on a machine that never loaded
     * this table is treated as foreign — the safe answer, since the alternative is the 400.
     */
    function isOwnSortKey(key: string): boolean {
        if (!key) return false;
        if (key === idKey.value) return true;
        if (persistedColumnKeys.has(key)) return true;

        return allColumns.value.some((c) => c.key === key && c.sortable !== false);
    }

    /**
     * Restore DataTable state.
     * Priority: URL query params (shareable links) → sessionStorage (survives reload & navigation).
     *
     * A URL carrying a FOREIGN sort is another table's URL, so none of it is read — not the
     * sort, not `page`/`per_page`, not the filters. Taking only half of it would open this
     * table on the neighbour's page number, which is the same bug one symptom quieter.
     */
    function restoreState(): void {
        const urlParams = new URLSearchParams(window.location.search);
        const urlSort = urlParams.get('sort');
        const urlSortIsForeign = urlSort !== null && !isOwnSortKey(sortParamKey(urlSort));

        if (urlParams.toString().length > 0 && !urlSortIsForeign) {
            restoreFromUrlParams(urlParams);
            return;
        }

        try {
            const raw = sessionStorage.getItem(storageKey);
            if (raw) {
                const saved = JSON.parse(raw) as Record<string, unknown>;
                search.value = (saved.search as string) ?? '';
                // The blob is keyed per route, so it cannot hold a NEIGHBOUR's sort — but it
                // can hold this table's OWN sort from before a column was renamed or dropped,
                // and the backend rejects that key exactly the same way.
                const savedSortKey = (saved.sortKey as string) ?? '';
                sortKey.value = isOwnSortKey(savedSortKey) ? savedSortKey : '';
                sortOrder.value = (saved.sortOrder as 'asc' | 'desc') ?? 'asc';
                currentPage.value = (saved.page as number) ?? 1;
                meta.value.per_page = (saved.perPage as number) ?? props.config.perPage;
                const daterangeKeys = new Set(
                    props.config.filters.filter((f) => f.type === 'daterange').map((f) => f.key),
                );
                const savedFilters = saved.filters as Record<string, unknown> | undefined;
                if (savedFilters) {
                    for (const [key, val] of Object.entries(savedFilters)) {
                        if (key in activeFilters.value) {
                            if (daterangeKeys.has(key) && Array.isArray(val)) {
                                activeFilters.value[key] = val.map((d: unknown) =>
                                    typeof d === 'string' ? new Date(d) : null,
                                );
                            } else {
                                activeFilters.value[key] = val as FilterValue;
                            }
                        }
                    }
                }
                // Column order/visibility are NOT read here anymore — they live in
                // their own localStorage bucket (see restoreColumnState) so they
                // survive Inertia navigation independently of this session blob.
            }
        } catch {
            // sessionStorage unavailable or corrupt — start clean
        }
    }

    /**
     * Restore DataTable state from URL query parameters.
     */
    function restoreFromUrlParams(params: URLSearchParams): void {
        const urlSearch = params.get('filter[search]');
        if (urlSearch) {
            search.value = urlSearch;
        }

        if (props.config.sortable) {
            const urlSort = params.get('sort');
            if (urlSort) {
                if (urlSort.startsWith('-')) {
                    sortKey.value = urlSort.slice(1);
                    sortOrder.value = 'desc';
                } else {
                    sortKey.value = urlSort;
                    sortOrder.value = 'asc';
                }
            }
        }

        if (props.config.pagination) {
            const urlPage = params.get('page');
            if (urlPage) {
                const p = Number(urlPage);
                if (p >= 1) {
                    currentPage.value = p;
                }
            }
            const urlPerPage = params.get('per_page');
            if (urlPerPage) {
                const pp = Number(urlPerPage);
                if (pp >= 1) {
                    meta.value.per_page = pp;
                }
            }
        }

        for (const filter of props.config.filters) {
            if (filter.type === 'daterange') {
                const from = params.get(`filter[${filter.key}_from]`);
                const to = params.get(`filter[${filter.key}_to]`);
                if (from || to) {
                    activeFilters.value[filter.key] = [
                        from ? new Date(from) : null,
                        to ? new Date(to) : null,
                    ] as unknown as string | number | null;
                }
            } else {
                const val = params.get(`filter[${filter.key}]`);
                if (val !== null) {
                    activeFilters.value[filter.key] = val;
                }
            }
        }
    }

    /**
     * Restore column order / visibility from localStorage. Kept separate from
     * restoreState() because column prefs survive Inertia navigation (localStorage)
     * whereas filter/sort/page state does not (sessionStorage, cleared on navigate).
     */
    function restoreColumnState(): void {
        try {
            const raw = localStorage.getItem(columnStorageKey);
            if (!raw) return;
            const saved = JSON.parse(raw) as { order?: string[]; hidden?: string[] };
            columnOrder.value = saved.order ?? [];
            hiddenColumns.value = new Set(saved.hidden ?? []);
            // Persisted columns are the user's chosen state — protect them from
            // both local and server defaults.
            for (const key of columnOrder.value) {
                defaultAppliedKeys.add(key);
                userTouchedKeys.add(key);
                // Kept BEFORE the reconcile below, which filters a server-only key out of
                // `columnOrder` until the first response republishes it: isOwnSortKey()
                // still needs it to recognise the sort as this table's own.
                persistedColumnKeys.add(key);
            }
            reconcileColumns();
        } catch {
            // localStorage unavailable or corrupt — fall back to config defaults
        }
    }

    /** Persist column order / visibility to localStorage (survives navigation). */
    function saveColumnState(): void {
        try {
            localStorage.setItem(
                columnStorageKey,
                JSON.stringify({
                    order: columnOrder.value,
                    hidden: [...hiddenColumns.value],
                }),
            );
        } catch {
            // localStorage unavailable — column prefs simply won't persist
        }
    }

    /**
     * Sync current DataTable state to URL query parameters and sessionStorage.
     * URL params keep URLs shareable; sessionStorage survives page reload and Inertia navigation.
     */
    function syncState(): void {
        const params = new URLSearchParams();

        if (search.value) {
            params.set('filter[search]', search.value);
        }

        if (sortKey.value) {
            const sortParam = sortOrder.value === 'desc' ? `-${sortKey.value}` : sortKey.value;
            params.set('sort', sortParam);
        }

        if (currentPage.value > 1) {
            params.set('page', String(currentPage.value));
        }

        if (meta.value.per_page !== props.config.perPage) {
            params.set('per_page', String(meta.value.per_page));
        }

        const daterangeKeys = new Set(props.config.filters.filter((f) => f.type === 'daterange').map((f) => f.key));

        Object.entries(activeFilters.value).forEach(([key, value]) => {
            if (value === null || value === undefined || value === '') return;

            if (daterangeKeys.has(key) && Array.isArray(value)) {
                const [from, to] = value as [Date | null, Date | null];
                if (from) params.set(`filter[${key}_from]`, formatDateParam(from));
                if (to) params.set(`filter[${key}_to]`, formatDateParam(to));
            } else {
                params.set(`filter[${key}]`, String(value));
            }
        });

        // Preserve non-datatable query params (e.g. ?type=systemManagers).
        // Datatable-owned keys count as managed even when unset (page=1, default
        // per_page, cleared sort) — otherwise the stale URL value gets copied back.
        const managedKeys = new Set<string>(['page', 'per_page', 'sort']);
        params.forEach((_value, key) => managedKeys.add(key));

        const currentParams = new URLSearchParams(window.location.search);
        for (const [key, value] of currentParams) {
            if (!managedKeys.has(key) && !key.startsWith('filter[')) {
                params.set(key, value);
            }
        }

        const qs = params.toString();
        const url = qs ? `${window.location.pathname}?${qs}` : window.location.pathname;

        // Serialize dates as ISO strings for sessionStorage
        const serializableFilters: Record<string, unknown> = {};
        for (const [key, value] of Object.entries(activeFilters.value)) {
            if (daterangeKeys.has(key) && Array.isArray(value)) {
                serializableFilters[key] = (value as (Date | null)[]).map((d) =>
                    d instanceof Date ? d.toISOString() : null,
                );
            } else {
                serializableFilters[key] = value;
            }
        }

        try {
            sessionStorage.setItem(
                storageKey,
                JSON.stringify({
                    search: search.value,
                    sortKey: sortKey.value,
                    sortOrder: sortOrder.value,
                    page: currentPage.value,
                    perPage: meta.value.per_page,
                    filters: serializableFilters,
                }),
            );
        } catch {
            // sessionStorage unavailable — URL params still work
        }

        // Column prefs live in their own localStorage bucket (survives navigation).
        saveColumnState();

        window.history.replaceState(window.history.state, '', url);
    }

    // ── Data fetching ─────────────────────────────────────────────────────────────

    // Monotonic token guarding against out-of-order responses: a slow earlier
    // request (filter A) must never overwrite a newer one (filter B) if it
    // resolves late. Each call captures its token; a response whose token is no
    // longer current is discarded. The recursive re-fetch below (server-added
    // default column) bumps the token too, so the outer call self-invalidates
    // and lets the inner, newest fetch own the final state.
    let fetchSeq = 0;

    async function fetchData() {
        const seq = ++fetchSeq;
        loading.value = true;

        try {
            const params = new URLSearchParams();

            if (search.value) {
                params.set('filter[search]', search.value);
            }

            if (props.config.sortable && sortKey.value) {
                const sortParam = sortOrder.value === 'desc' ? `-${sortKey.value}` : sortKey.value;
                params.set('sort', sortParam);
            }

            if (props.config.pagination) {
                params.set('page', String(currentPage.value));
                params.set('per_page', String(meta.value.per_page));
            }

            const daterangeKeys = new Set(props.config.filters.filter((f) => f.type === 'daterange').map((f) => f.key));

            Object.entries(activeFilters.value).forEach(([key, value]) => {
                if (value === null || value === undefined || value === '') return;

                if (daterangeKeys.has(key) && Array.isArray(value)) {
                    const [from, to] = value as [Date | null, Date | null];
                    if (from) params.set(`filter[${key}_from]`, formatDateParam(from));
                    if (to) params.set(`filter[${key}_to]`, formatDateParam(to));
                } else {
                    params.set(`filter[${key}]`, String(value));
                }
            });

            // Ask only for the visible columns' data — the backend shapes its payload
            // when DatatableQueryBuilder::columns() is configured, otherwise ignores it.
            let requestedColumns: string[] | null = null;
            if (showColumnToggle.value && displayColumns.value.length) {
                requestedColumns = displayColumns.value.map((c) => c.key);
                params.set('columns', requestedColumns.join(','));
            }

            const separator = props.config.route.includes('?') ? '&' : '?';
            const url = `${props.config.route}${separator}${params.toString()}`;

            const response = await api.get<DataTableResponse<unknown>>(url);

            // A newer fetch superseded this one while it was in flight — drop the
            // stale result so it can't clobber fresher data/meta (out-of-order guard).
            if (seq !== fetchSeq) return;

            data.value = response.data;
            meta.value = {
                total: response.total,
                per_page: response.per_page,
                current_page: response.current_page,
                last_page: response.last_page,
                from: response.from,
                to: response.to,
            };

            if (response.columns?.length) {
                serverColumns.value = response.columns;
                reconcileColumns();
            }

            syncState();
            emit('load', response.data, response.total);

            // The server may have introduced a default-visible column we didn't know
            // about (and therefore didn't request) — fetch once more including it.
            if (requestedColumns && response.columns?.length) {
                const requested = new Set(requestedColumns);
                if (displayColumns.value.some((c) => !requested.has(c.key))) {
                    await fetchData();
                }
            }
        } finally {
            // Only the current (winning) request owns the loading flag; a stale
            // request returning after a newer one must not flip it off early.
            if (seq === fetchSeq) loading.value = false;
        }
    }

    onMounted(() => {
        window.addEventListener('beforeunload', onBeforeUnload);

        if (props.refreshKey) {
            const bus = useRefreshBus();
            bus.on(props.refreshKey, fetchData);
        }

        // On F5 reload: beforeunload set the flag → keep sessionStorage.
        // On Inertia navigation: no flag → clear stale sessionStorage.
        const isReload = sessionStorage.getItem(reloadFlagKey) === '1';
        sessionStorage.removeItem(reloadFlagKey);

        if (!isReload) {
            sessionStorage.removeItem(storageKey);
        }

        // Column prefs come from localStorage and survive navigation, so they are
        // restored unconditionally — independent of the URL/sessionStorage filter
        // state that restoreState() handles (and its URL-param early return).
        restoreColumnState();
        restoreState();
        fetchData();

        // Disable init guard after debounce period (350ms) + safety buffer.
        setTimeout(() => {
            initializing = false;
        }, 500);
    });

    onUnmounted(() => {
        window.removeEventListener('beforeunload', onBeforeUnload);

        // If this is an Inertia navigation (no reload flag), clear stored state.
        if (!sessionStorage.getItem(reloadFlagKey)) {
            sessionStorage.removeItem(storageKey);
        }
    });

    watch(debouncedSearch, () => {
        if (initializing) return;
        // The result set changes — previously selected row IDs may fall outside
        // the new filter, so a bulk action on them would target stale rows. Reset.
        props.selection?.clearSelection();
        currentPage.value = 1;
        fetchData();
    });

    watch(
        activeFilters,
        () => {
            if (initializing) return;
            // Same rationale as search: filtering invalidates the current selection.
            props.selection?.clearSelection();
            currentPage.value = 1;
            fetchData();
        },
        { deep: true },
    );

    // ── Sorting ───────────────────────────────────────────────────────────────────

    function handleSort(key: string) {
        if (sortKey.value === key) {
            sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc';
        } else {
            sortKey.value = key;
            sortOrder.value = 'asc';
        }
        fetchData();
    }

    function sortIcon(key: string): string {
        if (sortKey.value !== key) return 'pi pi-sort-alt';
        return sortOrder.value === 'asc' ? 'pi pi-sort-amount-up-alt' : 'pi pi-sort-amount-down';
    }

    function ariaSort(sortable: boolean, key: string): 'ascending' | 'descending' | 'none' | undefined {
        if (!sortable) return undefined;
        if (sortKey.value !== key) return 'none';
        return sortOrder.value === 'asc' ? 'ascending' : 'descending';
    }

    // ── Pagination ────────────────────────────────────────────────────────────────

    function goToPage(page: number) {
        currentPage.value = page;
        fetchData();
    }

    /** Page numbers to display (sliding window of max 7). */
    const visiblePages = computed<number[]>(() => {
        const last = meta.value.last_page;
        if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);

        const current = meta.value.current_page;
        const start = Math.max(1, current - 3);
        const end = Math.min(last, start + 6);
        return Array.from({ length: end - start + 1 }, (_, i) => start + i);
    });

    // ── Helpers ───────────────────────────────────────────────────────────────────

    /**
     * Label a date the user picked in the calendar.
     *
     * A picked date is a CALENDAR DAY, not an instant, so it must not go
     * through the timezone-converting formatter: the picker hands back local
     * midnight, and re-rendering that in a different timezone would show a
     * day the user did not pick — while `formatDateParam()` below still sends
     * the local one to the server. The label and the filter have to name the
     * same day, so both read the Date's local fields.
     */
    function formatPickedDay(date: Date): string {
        return new Intl.DateTimeFormat(getActiveLanguage() || undefined, {
            year: 'numeric',
            month: 'numeric',
            day: 'numeric',
        }).format(date);
    }

    function formatDateParam(date: Date): string {
        // Calendar filters use local Y-m-d values by backend contract, not ISO instants.
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function getNestedValue(obj: unknown, key: string): unknown {
        return key.split('.').reduce((acc: unknown, part) => {
            if (acc !== null && typeof acc === 'object') {
                return (acc as Record<string, unknown>)[part];
            }
            return undefined;
        }, obj);
    }

    function isActionVisible(action: ActionConfig, row: unknown): boolean {
        return action.visible ? action.visible(row) : true;
    }

    /**
     * Resolve the definition-driven tag descriptor (label / severity / icon) for a
     * cell, in one pass. `colors`/`icons` column maps override the definition. The
     * resulting `severity` may be a PrimeVue severity OR a Tailwind color name —
     * the unlayered `.p-tag[data-p~="…"]` theme rules colour both (see tag.css).
     */
    function resolveTag(
        row: unknown,
        column: ColumnConfig,
    ): { label?: string; severity?: string; icon?: string } {
        const defValue = getNestedValue(row, column.key) as string | number | boolean;
        const mapKey = getNestedValue(row, column.tagKey ?? column.key) as string;

        // Row-level severity (e.g. a backend-seeded color column) — colors() map wins.
        const rowSeverity = column.tagSeverityKey
            ? (getNestedValue(row, column.tagSeverityKey) as string | undefined)
            : undefined;

        // Value-mode: the cell value itself is the label (optionally mapped) — no definition lookup.
        // Empty cells return no label → the template falls back to plain '-' instead of an empty tag.
        if (column.tag === 'value') {
            if (defValue === null || defValue === undefined || defValue === '') {
                return {};
            }
            const mapped = column.tagLabels?.[String(defValue)];
            return {
                // tagLabels values are i18n keys — resolve at render time, not at build
                // time (the builder runs before i18n is ready, so an eager trans() in the
                // page would freeze the raw key). trans() returns the input unchanged for
                // plain (non-key) strings, so literal labels still work.
                label: mapped !== undefined ? trans(mapped) : String(defValue),
                severity: column.colors?.[mapKey] ?? rowSeverity,
                icon: column.icons?.[mapKey],
            };
        }

        const def = definition.find(column.tagKey!, defValue);

        return {
            label: def?.label,
            severity: column.colors?.[mapKey] ?? rowSeverity ?? def?.severity ?? undefined,
            icon: column.icons?.[mapKey] ?? def?.icon ?? undefined,
        };
    }

    const hasActions = computed(() => props.config.actions.length > 0 || props.config.menuActions.length > 0);
    const hasMenuActions = computed(() => props.config.menuActions.length > 0);
    const showIdColumn = computed(() => props.config.idColumn?.visible !== false);
    const idKey = computed(() => props.config.idColumn?.key ?? 'id');
    const hasSelection = computed(() => !!props.selection);
    const colspan = computed(
        () =>
            displayColumns.value.length +
            (showIdColumn.value ? 1 : 0) +
            (hasActions.value ? 1 : 0) +
            (hasSelection.value ? 1 : 0),
    );

    // ── ID Popover ────────────────────────────────────────────────────────────
    const idPopoverRef = ref();
    const idPopoverValue = ref<string | number>('');

    function openIdPopover(event: Event, value: unknown) {
        idPopoverValue.value = value as string | number;
        nextTick(() => {
            idPopoverRef.value?.toggle(event);
        });
    }

    const idCopied = ref(false);

    async function copyId() {
        try {
            await navigator.clipboard.writeText(String(idPopoverValue.value));
            idCopied.value = true;
            setTimeout(() => {
                idCopied.value = false;
            }, 1500);
        } catch {
            // clipboard unavailable
        }
    }

    // ── Filter Panel ──────────────────────────────────────────────────────────────

    function resolveFilterLabel(filter: { key: string; label?: string }): string {
        return filter.label ? trans(filter.label) : trans('validation.attributes.' + filter.key);
    }

    const inlineFilters = computed(() => props.config.filters.filter((f) => f.placement === 'inline'));
    const panelFilters = computed(() => props.config.filters.filter((f) => f.placement !== 'inline'));
    const filterPopoverRef = ref();
    const searchPopoverRef = ref();

    /** Popover open states — drive the primary highlight on their toggle buttons. */
    const filterPopoverOpen = ref(false);
    const columnsPopoverOpen = ref(false);

    // ── Inline filter pills (design-language dropdowns for select filters) ──────

    const openFilterKey = ref<string | null>(null);

    // Inline-toolbar pill menus are teleported to <body> and positioned as a
    // fixed overlay so a long option list is not clipped by the card / scroll
    // container's `overflow` (the panel-popover variant rides PrimeVue's own
    // overflow-visible portal and keeps its in-place menu). `openFilterObj` is
    // set ONLY for inline keys; panel keys (`pp:`) leave it null and render
    // their menu inside the popover untouched.
    const openFilterObj = ref<FilterConfig | null>(null);
    const menuTrigger = ref<HTMLElement | null>(null);
    const menuPos = ref<{ top: number; left: number; minWidth: number }>({ top: 0, left: 0, minWidth: 0 });

    function updateMenuPos(): void {
        const el = menuTrigger.value;
        if (!el) return;
        const r = el.getBoundingClientRect();
        menuPos.value = { top: r.bottom + 6, left: r.left, minWidth: Math.max(234, r.width) };
    }

    function closeFilterMenu(): void {
        openFilterKey.value = null;
        openFilterObj.value = null;
        menuTrigger.value = null;
    }

    function toggleFilterMenu(key: string, filter?: FilterConfig, el?: HTMLElement): void {
        if (openFilterKey.value === key) {
            closeFilterMenu();
            return;
        }
        openFilterKey.value = key;
        if (filter && el && !key.startsWith('pp:')) {
            openFilterObj.value = filter;
            menuTrigger.value = el;
            nextTick(updateMenuPos);
        } else {
            openFilterObj.value = null;
            menuTrigger.value = null;
        }
    }

    useEventListener(browserDocument, 'click', closeFilterMenu);
    useEventListener(browserDocument, 'keydown', (e: KeyboardEvent) => {
        if (e.key === 'Escape') closeFilterMenu();
    });
    // keep the teleported menu glued to its trigger while the page/container scrolls
    useEventListener(browserWindow, 'scroll', () => openFilterObj.value && updateMenuPos(), {
        capture: true,
        passive: true,
    });
    useEventListener(browserWindow, 'resize', () => openFilterObj.value && updateMenuPos());

    /** Pill menu options — an "All" (null) entry followed by the filter's own options. */
    function pillOptions(filter: FilterConfig): FilterOption[] {
        return [{ label: trans('sk-common.all'), value: null }, ...getFilterOptions(filter)];
    }

    function isPillOptionSelected(filter: FilterConfig, option: FilterOption): boolean {
        const current = activeFilters.value[filter.key];
        if (option.value === null) return current === null || current === undefined || current === '';
        return current === option.value;
    }

    function selectPillOption(filter: FilterConfig, option: FilterOption): void {
        activeFilters.value[filter.key] = option.value;
        closeFilterMenu();
    }

    function isPillActive(filter: FilterConfig): boolean {
        const current = activeFilters.value[filter.key];
        return current !== null && current !== undefined && current !== '';
    }

    /** Label shown in the pill: the selected option's label, or "All". */
    function pillValueLabel(filter: FilterConfig): string {
        if (!isPillActive(filter)) return trans('sk-common.all');
        return filterDisplayValue(filter) ?? trans('sk-common.all');
    }

    /** Count chip for the currently selected option — only when options carry counts. */
    function pillCount(filter: FilterConfig): number | null {
        const options = pillOptions(filter);
        const selected = options.find((o) => isPillOptionSelected(filter, o));
        return selected?.count ?? null;
    }

    /** Badge count for filter button — all active filter tags count. */
    const panelFilterBadge = computed(() => {
        const count = activeTags.value.filter((t) => t.type === 'filter').length;
        return count > 0 ? String(count) : '';
    });

    /** Human-readable label for an active filter value. */
    function filterDisplayValue(filter: (typeof props.config.filters)[number]): string | null {
        const val = activeFilters.value[filter.key] as unknown;
        if (val === null || val === undefined || val === '') return null;

        if (filter.type === 'select' || filter.type === 'select-button') {
            const opts = getFilterOptions(filter);
            const opt = opts.find((o) => o.value === val);
            return opt ? opt.label : String(val);
        }

        if (filter.type === 'daterange' && Array.isArray(val)) {
            const [from, to] = val as [Date | null, Date | null];
            if (from && to) return `${formatPickedDay(from)} – ${formatPickedDay(to)}`;
            if (from) return `${formatPickedDay(from)} –`;
            if (to) return `– ${formatPickedDay(to)}`;
            return null;
        }

        if (filter.type === 'date' && val instanceof Date) {
            return formatPickedDay(val);
        }

        return String(val);
    }

    interface ActiveTag {
        key: string;
        label: string;
        value: string;
        type: 'filter';
    }

    const activeTags = computed<ActiveTag[]>(() => {
        const tags: ActiveTag[] = [];

        for (const filter of props.config.filters) {
            const display = filterDisplayValue(filter);
            if (display) {
                tags.push({ key: filter.key, label: resolveFilterLabel(filter), value: display, type: 'filter' });
            }
        }

        return tags;
    });

    /** Whether the empty table state is caused by an active search/filter (vs. genuinely no data). */
    const hasActiveSearchOrFilter = computed(() => activeTags.value.length > 0 || search.value.trim() !== '');

    function clearFilter(key: string) {
        activeFilters.value[key] = null;
    }

    function clearAllFilters() {
        search.value = '';
        for (const key of Object.keys(activeFilters.value)) {
            activeFilters.value[key] = null;
        }
    }

    // ── Context Menu ────────────────────────────────────────────────────────────

    const menuRef = ref();

    function buildMenuItems(row: unknown): MenuItem[] {
        const items: MenuItem[] = [];
        const visibleActions = props.config.menuActions.filter((ma) => (ma.visible ? ma.visible(row) : true));

        for (const ma of visibleActions) {
            if (ma.separator) {
                items.push({ separator: true });
            }
            items.push({
                label: ma.label,
                icon: ma.icon,
                disabled: ma.disabled ? ma.disabled(row) : false,
                command: () => ma.handle(row),
            });
        }

        return items;
    }

    const activeMenuItems = ref<MenuItem[]>([]);

    function toggleMenu(event: Event, row: unknown): void {
        activeMenuItems.value = buildMenuItems(row);
        nextTick(() => {
            menuRef.value?.toggle(event);
        });
    }

    // ── Floating bulk bar ─────────────────────────────────────────────────────────
    // Shown when a selection exists AND the page provides a #bulk-actions slot.
    // Pages still using the legacy #toolbar pattern keep their old inline bar.

    const showBulkBar = computed(
        () =>
            !!props.selection &&
            !!slots['bulk-actions'] &&
            (props.selection.hasSelection.value || props.selection.isAllFilteredMode.value),
    );

    const bulkBarLabel = computed(() => {
        if (!props.selection) return '';
        // All-filtered mode spans every page — the real count is the filtered
        // total from the server, not the page-local selectedIds size.
        const allFiltered = props.selection.isAllFilteredMode.value;
        const count = String(allFiltered ? meta.value.total : props.selection.selectedCount.value);
        return allFiltered
            ? trans('sk-datatable.bulk_selected_all_filtered', { count })
            : trans('sk-datatable.bulk_selected', { count });
    });

    // ── Public API ────────────────────────────────────────────────────────────────

    defineSlots<{
        toolbar?(): unknown;
        'toolbar-start'?(): unknown;
        'bulk-actions'?(): unknown;
        [key: `cell-${string}`]: (props: { row: unknown; value: unknown }) => unknown;
    }>();

    const slots = useSlots();

    defineExpose({ refresh: fetchData, pageData: data, total: computed(() => meta.value.total) });

    // ── Card sarmalayıcı ───────────────────────────────────────────────────────────
    // isCard true  → görünür kart; içerik kenara-yaslı (toolbar tam genişlikte) → flush.
    //                Başlık padding'i SkCard head'inden gelir.
    // isCard false → şeffaf sarmalayıcı (yüzey yok), içerik kenara-yaslı → transparent + flush.
    const isCardSurface = computed(() => props.config.isCard);
</script>

<template>
    <SkCard :transparent="!isCardSurface" :flush="true">
        <template v-if="config.cardTitle" #title>
            {{ $t(config.cardTitle) }}
        </template>
        <template v-if="config.cardSubtitle" #subtitle>
            {{ $t(config.cardSubtitle) }}
        </template>
        <template #content>
            <!-- Toolbar: Title, Search, Inline Filters, Filter Button, Columns, Create Button, Custom Slots -->
            <div
                v-if="
                    config.searchable ||
                    config.filters.length > 0 ||
                    config.createButton ||
                    config.title ||
                    config.subtitle ||
                    showColumnToggle ||
                    $slots.toolbar ||
                    $slots['toolbar-start']
                "
                class="sk-dt-toolbar"
                :class="{ 'no-padding': !config.isCard, 'sk-dt-toolbar--titled': !!config.title }"
            >
                <!-- Left: optional title / subtitle block (before the search input) -->
                <div v-if="config.title || config.subtitle" class="sk-dt-toolbar__head">
                    <div v-if="config.title" class="sk-dt-toolbar__title">{{ $t(config.title) }}</div>
                    <div v-if="config.subtitle" class="sk-dt-toolbar__subtitle">{{ $t(config.subtitle) }}</div>
                </div>

                <!-- Left: Search (hidden on mobile, shown on sm+) — gray compact box per design -->
                <div v-if="config.searchable" class="sk-dt-search sk-dt-toolbar__search">
                    <i class="pi pi-search" />
                    <input
                        v-model="search"
                        type="text"
                        :placeholder="$t('sk-common.search')"
                        autocomplete="one-time-code"
                    />
                    <i v-if="search" class="pi pi-times sk-dt-search__clear" @click="search = ''" />
                </div>

                <!-- Inline filter pills — directly after the search box, per design -->
                <template v-for="filter in inlineFilters" :key="filter.key">
                    <!-- Select filters render as design-language pills with a custom dropdown -->
                    <div v-if="filter.type === 'select'" class="sk-dt-pillwrap" @click.stop>
                        <button
                            type="button"
                            class="sk-dt-pill"
                            :class="{
                                'sk-dt-pill--open': openFilterKey === filter.key,
                                'sk-dt-pill--active': isPillActive(filter),
                            }"
                            @click="toggleFilterMenu(filter.key, filter, $event.currentTarget as HTMLElement)"
                        >
                            <span class="sk-dt-pill__key">{{ resolveFilterLabel(filter) }}:</span>
                            <span class="sk-dt-pill__val">{{ pillValueLabel(filter) }}</span>
                            <span v-if="pillCount(filter) !== null" class="sk-dt-pill__count">{{
                                pillCount(filter)
                            }}</span>
                            <i
                                v-if="isPillActive(filter)"
                                class="pi pi-times sk-dt-pill__clear"
                                :aria-label="$t('sk-button.clear_all')"
                                @click.stop="clearFilter(filter.key)"
                            />
                            <i
                                class="pi pi-chevron-down sk-dt-pill__caret"
                                :class="{ 'rotate-180': openFilterKey === filter.key }"
                            />
                        </button>
                        <!-- menu is teleported to <body> (see the Teleport at the end of
                             the toolbar) so a long list escapes the card/scroll clip -->
                    </div>

                    <!-- Other inline filter types keep their PrimeVue inputs -->
                    <div v-else class="sk-dt-toolbar__inline-filter">
                        <!-- Date pickers carry their label via the placeholder; only
                             select-button needs an external label. -->
                        <span
                            v-if="filter.type === 'select-button'"
                            class="sk-dt-toolbar__inline-filter-label"
                            >{{ resolveFilterLabel(filter) }}</span
                        >
                        <SelectButton
                            v-if="filter.type === 'select-button'"
                            v-model="activeFilters[filter.key]"
                            :options="getFilterOptions(filter)"
                            option-label="label"
                            option-value="value"
                            :allow-empty="true"
                        />
                        <DatePicker
                            v-else-if="filter.type === 'date'"
                            :model-value="activeFilters[filter.key] as DateFilterValue"
                            :placeholder="filter.placeholder ?? resolveFilterLabel(filter)"
                            date-format="dd.mm.yy"
                            show-button-bar
                            class="min-w-48"
                            @update:model-value="(val) => (activeFilters[filter.key] = val as DateFilterValue)"
                        />
                        <DatePicker
                            v-else-if="filter.type === 'daterange'"
                            :model-value="activeFilters[filter.key] as DaterangeFilterValue"
                            :placeholder="filter.placeholder ?? resolveFilterLabel(filter)"
                            date-format="dd.mm.yy"
                            selection-mode="range"
                            show-button-bar
                            class="min-w-56"
                            @update:model-value="(val) => (activeFilters[filter.key] = val as DaterangeFilterValue)"
                        />
                    </div>
                </template>

                <!-- Inline pill dropdown — teleported to <body> as a fixed overlay so a
                     long option list is never clipped by the card / scroll container. -->
                <Teleport to="body">
                    <Transition name="sk-dt-dd">
                        <div
                            v-if="openFilterObj"
                            class="sk-dt-pillmenu sk-dt-pillmenu--floating"
                            :style="{
                                top: `${menuPos.top}px`,
                                left: `${menuPos.left}px`,
                                minWidth: `${menuPos.minWidth}px`,
                            }"
                            @click.stop
                        >
                            <button
                                v-for="opt in pillOptions(openFilterObj)"
                                :key="String(opt.value)"
                                type="button"
                                class="sk-dt-pillmenu__item"
                                :class="{ 'sk-dt-pillmenu__item--active': isPillOptionSelected(openFilterObj, opt) }"
                                @click="selectPillOption(openFilterObj, opt)"
                            >
                                <span class="sk-dt-pillmenu__lead">
                                    <i
                                        v-if="isPillOptionSelected(openFilterObj, opt)"
                                        class="pi pi-check sk-dt-pillmenu__check"
                                    />
                                    <span
                                        v-else-if="opt.color"
                                        class="sk-dt-pillmenu__dot"
                                        :style="dotStyle(opt.color)"
                                    />
                                </span>
                                <span class="sk-dt-pillmenu__label">{{ opt.label }}</span>
                                <span
                                    v-if="opt.count !== undefined"
                                    class="sk-dt-pillmenu__count"
                                    :class="{ 'sk-dt-pillmenu__count--active': isPillOptionSelected(openFilterObj, opt) }"
                                    >{{ opt.count }}</span
                                >
                            </button>
                        </div>
                    </Transition>
                </Teleport>

                <!-- Right: Filter Popover, Columns, Actions -->
                <div class="sk-dt-toolbar__right">
                    <!-- Search button (mobile only, hidden on sm+) -->
                    <Button
                        v-if="config.searchable"
                        icon="pi pi-search"
                        severity="secondary"
                        variant="outlined"
                        :badge="search ? '1' : ''"
                        badge-severity="contrast"
                        class="sk-dt-toolbar__search-toggle"
                        @click="(e: Event) => searchPopoverRef?.toggle(e)"
                    />

                    <!-- Filter Popover Toggle — quiet icon button per design -->
                    <button
                        v-if="panelFilters.length > 0"
                        type="button"
                        class="sk-dt-filterbtn"
                        :class="{
                            'sk-dt-filterbtn--active': panelFilterBadge,
                            'sk-dt-filterbtn--open': filterPopoverOpen,
                        }"
                        :aria-label="$t('sk-datatable.clear_filters')"
                        @click="(e: Event) => filterPopoverRef?.toggle(e)"
                    >
                        <i class="pi pi-filter" />
                        <span v-if="panelFilterBadge" class="sk-dt-filterbtn__badge">{{ panelFilterBadge }}</span>
                    </button>

                    <!-- Column visibility / order menu -->
                    <button
                        v-if="showColumnToggle"
                        type="button"
                        class="sk-dt-colbtn"
                        :class="{ 'sk-dt-colbtn--open': columnsPopoverOpen }"
                        :aria-label="$t('sk-datatable.columns')"
                        @click="(e: Event) => columnsPopoverRef?.toggle(e)"
                    >
                        <i class="pi pi-table" />
                        <span class="sk-dt-colbtn__count">{{ visibleColumnCount }}/{{ allColumns.length }}</span>
                    </button>

                    <span
                        v-if="showColumnToggle && (config.createButton || $slots.toolbar || $slots['toolbar-start'])"
                        class="sk-dt-toolbar__divider"
                    />

                    <!-- Create / Custom actions -->
                    <div
                        v-if="config.createButton || $slots.toolbar || $slots['toolbar-start']"
                        class="sk-dt-toolbar__actions"
                    >
                        <!-- Custom slot — rendered before (to the left of) the create button -->
                        <slot name="toolbar-start" />

                        <!-- Create: link button -->
                        <Link v-if="config.createButton?.url" :href="config.createButton.url">
                            <Button
                                :label="$t(config.createButton.label ?? 'Add')"
                                :icon="config.createButton.icon ?? 'pi pi-plus'"
                                :severity="config.createButton.severity ?? 'success'"
                                :size="config.createButton.size"
                                :variant="config.createButton.variant"
                                :rounded="config.createButton.rounded"
                                :raised="config.createButton.raised"
                                :text="config.createButton.text"
                                :outlined="config.createButton.outlined"
                            />
                        </Link>

                        <!-- Create: dialog/action button -->
                        <Button
                            v-else-if="config.createButton?.onClick"
                            :label="$t(config.createButton.label ?? 'Add')"
                            :icon="config.createButton.icon ?? 'pi pi-plus'"
                            :severity="config.createButton.severity ?? 'success'"
                            :size="config.createButton.size"
                            :variant="config.createButton.variant"
                            :rounded="config.createButton.rounded"
                            :raised="config.createButton.raised"
                            :text="config.createButton.text"
                            :outlined="config.createButton.outlined"
                            @click="config.createButton.onClick()"
                        />

                        <!-- Custom toolbar slot -->
                        <slot name="toolbar" />
                    </div>
                </div>
            </div>
            <!-- END :: TOOLBAR -->

            <!-- Filter Popover — lists only panel-placed filters (inline ones live in the toolbar) -->
            <Popover
                v-if="panelFilters.length > 0"
                ref="filterPopoverRef"
                class="sk-dt-filter-popover"
                @show="filterPopoverOpen = true"
                @hide="filterPopoverOpen = false"
            >
                <div class="sk-dt-filter-popover__content">
                    <div v-for="filter in panelFilters" :key="filter.key" class="sk-dt-filter-popover__item">
                        <!-- Select filters render as the same design pills used in the header -->
                        <div v-if="filter.type === 'select'" class="sk-dt-pillwrap" @click.stop>
                            <button
                                type="button"
                                class="sk-dt-pill"
                                :class="{
                                    'sk-dt-pill--open': openFilterKey === `pp:${filter.key}`,
                                    'sk-dt-pill--active': isPillActive(filter),
                                }"
                                @click="toggleFilterMenu(`pp:${filter.key}`)"
                            >
                                <span class="sk-dt-pill__key">{{ resolveFilterLabel(filter) }}:</span>
                                <span class="sk-dt-pill__val">{{ pillValueLabel(filter) }}</span>
                                <span v-if="pillCount(filter) !== null" class="sk-dt-pill__count">{{
                                    pillCount(filter)
                                }}</span>
                                <i
                                    v-if="isPillActive(filter)"
                                    class="pi pi-times sk-dt-pill__clear"
                                    :aria-label="$t('sk-button.clear_all')"
                                    @click.stop="clearFilter(filter.key)"
                                />
                                <i
                                    class="pi pi-chevron-down sk-dt-pill__caret"
                                    :class="{ 'rotate-180': openFilterKey === `pp:${filter.key}` }"
                                />
                            </button>
                            <Transition name="sk-dt-dd">
                                <div v-if="openFilterKey === `pp:${filter.key}`" class="sk-dt-pillmenu">
                                    <button
                                        v-for="opt in pillOptions(filter)"
                                        :key="String(opt.value)"
                                        type="button"
                                        class="sk-dt-pillmenu__item"
                                        :class="{
                                            'sk-dt-pillmenu__item--active': isPillOptionSelected(filter, opt),
                                        }"
                                        @click="selectPillOption(filter, opt)"
                                    >
                                        <span class="sk-dt-pillmenu__lead">
                                            <i
                                                v-if="isPillOptionSelected(filter, opt)"
                                                class="pi pi-check sk-dt-pillmenu__check"
                                            />
                                            <span
                                                v-else-if="opt.color"
                                                class="sk-dt-pillmenu__dot"
                                                :style="dotStyle(opt.color)"
                                            />
                                        </span>
                                        <span class="sk-dt-pillmenu__label">{{ opt.label }}</span>
                                        <span
                                            v-if="opt.count !== undefined"
                                            class="sk-dt-pillmenu__count"
                                            :class="{
                                                'sk-dt-pillmenu__count--active': isPillOptionSelected(filter, opt),
                                            }"
                                            >{{ opt.count }}</span
                                        >
                                    </button>
                                </div>
                            </Transition>
                        </div>
                        <template v-else>
                            <!-- Date pickers carry their label via the placeholder; only
                                 select-button needs the uppercase heading. -->
                            <label
                                v-if="filter.type === 'select-button'"
                                class="sk-dt-filter-popover__label"
                                >{{ resolveFilterLabel(filter) }}</label
                            >
                            <SelectButton
                                v-if="filter.type === 'select-button'"
                                v-model="activeFilters[filter.key]"
                                :options="getFilterOptions(filter)"
                                option-label="label"
                                option-value="value"
                                :allow-empty="true"
                                class="w-full"
                            />
                            <DatePicker
                                v-else-if="filter.type === 'date'"
                                :model-value="activeFilters[filter.key] as DateFilterValue"
                                :placeholder="filter.placeholder ?? resolveFilterLabel(filter)"
                                date-format="dd.mm.yy"
                                show-button-bar
                                class="w-full"
                                @update:model-value="(val) => (activeFilters[filter.key] = val as DateFilterValue)"
                            />
                            <DatePicker
                                v-else-if="filter.type === 'daterange'"
                                :model-value="activeFilters[filter.key] as DaterangeFilterValue"
                                :placeholder="filter.placeholder ?? resolveFilterLabel(filter)"
                                date-format="dd.mm.yy"
                                selection-mode="range"
                                show-button-bar
                                class="w-full"
                                @update:model-value="(val) => (activeFilters[filter.key] = val as DaterangeFilterValue)"
                            />
                        </template>
                    </div>
                </div>
            </Popover>

            <!-- Columns Popover — visibility toggles + drag & drop ordering -->
            <Popover
                v-if="showColumnToggle"
                ref="columnsPopoverRef"
                class="sk-dt-colmenu"
                @show="columnsPopoverOpen = true"
                @hide="columnsPopoverOpen = false"
            >
                <div class="sk-dt-colmenu__head">
                    <span class="sk-dt-colmenu__title">{{ $t('sk-datatable.visible_columns') }}</span>
                    <button type="button" class="sk-dt-colmenu__all" @click="showAllColumns">
                        {{ $t('sk-datatable.show_all') }}
                    </button>
                </div>
                <div
                    v-for="def in orderedColumns"
                    :key="def.key"
                    class="sk-dt-colmenu__item"
                    :class="{
                        'sk-dt-colmenu__item--locked': def.locked,
                        'sk-dt-colmenu__item--dragging': dragColumnKey === def.key,
                    }"
                    draggable="true"
                    @dragstart="onColumnDragStart(def.key, $event)"
                    @dragenter.prevent="onColumnDragEnter(def.key)"
                    @dragover.prevent
                    @dragend="onColumnDragEnd"
                    @click="toggleColumn(def)"
                >
                    <i class="pi pi-bars sk-dt-colmenu__grip" @click.stop />
                    <span
                        class="sk-dt-colmenu__box"
                        :class="{
                            'sk-dt-colmenu__box--checked': isColumnVisible(def.key) && !def.locked,
                            'sk-dt-colmenu__box--locked': def.locked,
                        }"
                    >
                        <i class="pi pi-check" />
                    </span>
                    <span class="sk-dt-colmenu__label">
                        {{ def.label ? $t(def.label) : $t('validation.attributes.' + def.key) }}
                    </span>
                    <i v-if="def.locked" class="pi pi-lock sk-dt-colmenu__lock" />
                </div>
            </Popover>

            <!-- Search Popover (mobile only) -->
            <Popover v-if="config.searchable" ref="searchPopoverRef" class="sk-dt-search-popover">
                <div class="sk-dt-search-popover__content">
                    <IconField class="w-full">
                        <InputIcon class="pi pi-search" />
                        <InputText
                            v-model="search"
                            :placeholder="$t('sk-common.search')"
                            class="w-full"
                            autofocus
                            autocomplete="one-time-code"
                        />
                        <button
                            v-if="search"
                            type="button"
                            class="p-inputicon pi pi-times sk-dt-toolbar__search-clear"
                            :aria-label="$t('sk-button.close')"
                            @click="search = ''"
                        />
                    </IconField>
                </div>
            </Popover>

            <!-- Active Filter Tags -->
            <div v-if="activeTags.length > 0" class="sk-dt-tags">
                <span v-for="tag in activeTags" :key="tag.key" class="sk-dt-tags__tag">
                    <span class="sk-dt-tags__tag-label">{{ tag.label }}:</span>
                    <span class="sk-dt-tags__tag-value">{{ tag.value }}</span>
                    <button
                        type="button"
                        class="sk-dt-tags__tag-remove"
                        :aria-label="$t('sk-button.clear_all')"
                        @click="clearFilter(tag.key)"
                    />
                </span>
                <button class="sk-dt-tags__clear-all" @click="clearAllFilters">
                    {{ $t('sk-button.clear_all') }}
                </button>
            </div>

            <!-- Table -->
            <div class="sk-dt">
                <div ref="scrollRef" class="sk-dt__scroll" :style="scrollMaxH ? { maxHeight: scrollMaxH } : undefined">
                    <table class="sk-dt__table">
                        <thead class="sk-dt__thead">
                            <tr>
                                <!-- Selection checkbox column header -->
                                <th v-if="hasSelection" class="sk-dt__th sk-dt__th--checkbox">
                                    <Checkbox
                                        :model-value="selection!.isPageFullySelected(data)"
                                        :indeterminate="selection!.isPagePartiallySelected(data)"
                                        binary
                                        @update:model-value="(val) => selection!.togglePageSelection(data, val)"
                                    />
                                </th>

                                <!-- Built-in ID column header -->
                                <th
                                    v-if="showIdColumn"
                                    class="sk-dt__th sk-dt__th--sticky sk-dt__th--id"
                                    :class="{ 'sk-dt__th--sortable': config.sortable }"
                                    :tabindex="config.sortable ? 0 : undefined"
                                    :aria-sort="ariaSort(config.sortable, idKey)"
                                    @click="config.sortable ? handleSort(idKey) : undefined"
                                    @keydown.enter="config.sortable ? handleSort(idKey) : undefined"
                                    @keydown.space.prevent="config.sortable ? handleSort(idKey) : undefined"
                                >
                                    <span class="sk-dt__sort-label">
                                        {{ $t('sk-common.id') }}
                                        <i v-if="config.sortable" :class="sortIcon(idKey)" class="sk-dt__sort-icon" />
                                    </span>
                                </th>

                                <th
                                    v-for="column in displayColumns"
                                    :key="column.key"
                                    class="sk-dt__th"
                                    :class="{
                                        'sk-dt__th--sortable': config.sortable && column.sortable,
                                        'sk-dt__th--sorted': config.sortable && sortKey === column.key,
                                        'sk-dt__th--sticky': column.sticky,
                                    }"
                                    :tabindex="config.sortable && column.sortable ? 0 : undefined"
                                    :aria-sort="ariaSort(config.sortable && column.sortable, column.key)"
                                    @click="config.sortable && column.sortable ? handleSort(column.key) : undefined"
                                    @keydown.enter="config.sortable && column.sortable ? handleSort(column.key) : undefined"
                                    @keydown.space.prevent="config.sortable && column.sortable ? handleSort(column.key) : undefined"
                                >
                                    <span class="sk-dt__sort-label">
                                        {{
                                            column.label ? $t(column.label) : $t('validation.attributes.' + column.key)
                                        }}

                                        <i
                                            v-if="config.sortable && column.sortable"
                                            :class="sortIcon(column.key)"
                                            class="sk-dt__sort-icon"
                                        />
                                    </span>
                                </th>
                                <th v-if="hasActions" class="sk-dt__th sk-dt__th--actions sk-dt__th--sticky-right" />
                            </tr>
                        </thead>

                        <tbody>
                            <!-- Loading -->
                            <tr v-if="loading">
                                <td :colspan="colspan" class="sk-dt__loading">
                                    <div class="sk-dt__loading-spinner">
                                        <i class="pi pi-spinner sk-dt__loading-icon" />
                                        <span class="sk-dt__loading-text">{{ $t('sk-datatable.loading') }}</span>
                                    </div>
                                </td>
                            </tr>

                            <template v-else>
                                <!-- Rows -->
                                <tr
                                    v-for="(row, rowIndex) in data"
                                    :key="rowIndex"
                                    class="sk-dt__row"
                                    :class="{ 'sk-dt__row--selected': hasSelection && selection!.isRowSelected(row) }"
                                >
                                    <!-- Selection checkbox cell -->
                                    <td v-if="hasSelection" class="sk-dt__td sk-dt__td--checkbox">
                                        <Checkbox
                                            :model-value="selection!.isRowSelected(row)"
                                            binary
                                            @update:model-value="() => selection!.toggleRow(row)"
                                        />
                                    </td>

                                    <!-- Built-in ID cell -->
                                    <td v-if="showIdColumn" class="sk-dt__td sk-dt__td--sticky sk-dt__td--id">
                                        <button
                                            class="sk-dt__id-trigger"
                                            @click="openIdPopover($event, getNestedValue(row, idKey))"
                                        >
                                            <i class="pi pi-info-circle" />
                                        </button>
                                    </td>

                                    <td
                                        v-for="column in displayColumns"
                                        :key="column.key"
                                        class="sk-dt__td"
                                        :class="{ 'sk-dt__td--sticky': column.sticky }"
                                    >
                                        <!-- Custom slot -->
                                        <slot
                                            v-if="slots[`cell-${column.key}`]"
                                            :name="`cell-${column.key}`"
                                            :row="row"
                                            :value="getNestedValue(row, column.key)"
                                        />
                                        <!-- Tag column (definition- or value-mode) rendered through
                                             PrimeVue <Tag>. `v-for over [resolveTag(...)]` resolves the
                                             descriptor once per cell. soft/outlined are opt-in CSS classes
                                             (PrimeVue Tag has no such props); icon-right uses the
                                             default slot, icon-left uses the native value/icon props
                                             so PrimeVue's own markup renders. -->
                                        <template v-else-if="column.tag">
                                            <template
                                                v-for="t in [resolveTag(row, column)]"
                                                :key="t.label"
                                            >
                                                <template v-if="t.label === undefined">-</template>
                                                <Tag
                                                    v-else-if="column.tagIconPos === 'right'"
                                                    :severity="(t.severity as any)"
                                                    :rounded="column.tagRounded"
                                                    :class="{
                                                        'p-tag-soft': column.tagSoft,
                                                        'p-tag-outlined': column.tagOutlined,
                                                    }"
                                                >
                                                    <span class="p-tag-label">{{ t.label }}</span>
                                                    <i v-if="t.icon" :class="t.icon" class="p-tag-icon" />
                                                </Tag>
                                                <Tag
                                                    v-else
                                                    :value="t.label"
                                                    :severity="(t.severity as any)"
                                                    :icon="t.icon"
                                                    :rounded="column.tagRounded"
                                                    :class="{
                                                        'p-tag-soft': column.tagSoft,
                                                        'p-tag-outlined': column.tagOutlined,
                                                    }"
                                                />
                                            </template>
                                        </template>
                                        <!-- eslint-disable-next-line vue/no-v-html -- render callbacks own the HTML contract: author markup may be static, but every untrusted dynamic value must pass through the second-argument escapeHtml helper before interpolation -->
                                        <span v-else-if="column.render" v-html="column.render(row, escapeHtml)" />
                                        <template v-else>
                                            {{ getNestedValue(row, column.key) ?? '-' }}
                                        </template>
                                    </td>

                                    <td v-if="hasActions" class="sk-dt__td sk-dt__td--actions sk-dt__td--sticky-right">
                                        <div class="sk-dt__actions-group">
                                            <template
                                                v-for="(action, actionIndex) in config.actions"
                                                :key="actionIndex"
                                            >
                                                <Button
                                                    v-if="isActionVisible(action, row)"
                                                    v-tooltip.top="action.tooltip ? $t(action.tooltip) : ''"
                                                    :icon="action.icon"
                                                    :severity="action.severity ?? 'info'"
                                                    :size="action.size ?? 'small'"
                                                    :variant="action.variant"
                                                    :rounded="action.rounded ?? false"
                                                    :raised="action.raised"
                                                    :text="action.text"
                                                    :outlined="action.outlined"
                                                    :label="action.label ? $t(action.label) : ''"
                                                    :disabled="action.disabled ? action.disabled(row) : false"
                                                    @click="action.handle(row)"
                                                />
                                            </template>
                                            <Button
                                                v-if="hasMenuActions"
                                                :icon="config.menuButton.icon ?? 'pi pi-ellipsis-v'"
                                                :severity="config.menuButton.severity ?? 'secondary'"
                                                :size="config.menuButton.size ?? 'small'"
                                                :variant="config.menuButton.variant"
                                                :rounded="config.menuButton.rounded ?? false"
                                                :raised="config.menuButton.raised"
                                                :text="config.menuButton.text"
                                                :outlined="config.menuButton.outlined"
                                                @click="toggleMenu($event, row)"
                                            />
                                        </div>
                                    </td>
                                </tr>

                                <!-- Empty state -->
                                <tr v-if="data.length === 0">
                                    <td :colspan="colspan" class="sk-dt__empty">
                                        <div class="sk-dt__empty-inner">
                                            <div class="sk-dt__empty-icon">
                                                <i class="pi pi-inbox" />
                                            </div>
                                            <p class="sk-dt__empty-text">
                                                {{
                                                    hasActiveSearchOrFilter
                                                        ? $t('sk-datatable.no_results_filtered')
                                                        : $t('sk-datatable.no_records')
                                                }}
                                            </p>
                                            <Button
                                                v-if="hasActiveSearchOrFilter"
                                                :label="$t('sk-datatable.clear_filters')"
                                                severity="secondary"
                                                text
                                                size="small"
                                                @click="clearAllFilters"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="config.pagination && meta.total > 0" class="sk-dt-pagination">
                    <!-- Left: record info + per-page selector -->
                    <div class="sk-dt-pagination__info">
                        <span>{{
                            $t('sk-datatable.records_info', {
                                from: String(meta.from ?? 0),
                                to: String(meta.to ?? 0),
                                total: String(meta.total),
                            })
                        }}</span>
                        <span class="sk-dt-pagination__per-page-label">{{ $t('sk-datatable.per_page') }}</span>
                        <Select
                            :model-value="meta.per_page"
                            :options="perPageOptions"
                            class="w-24"
                            @update:model-value="changePerPage"
                        />
                    </div>

                    <!-- Right: page buttons -->
                    <div v-if="meta.last_page > 1" class="sk-dt-pagination__pages">
                        <Button
                            icon="pi pi-angle-double-left"
                            severity="secondary"
                            text
                            size="small"
                            :aria-label="$t('sk-datatable.first_page')"
                            :disabled="meta.current_page === 1"
                            @click="goToPage(1)"
                        />
                        <Button
                            icon="pi pi-chevron-left"
                            severity="secondary"
                            text
                            size="small"
                            :aria-label="$t('sk-datatable.previous_page')"
                            :disabled="meta.current_page === 1"
                            @click="goToPage(meta.current_page - 1)"
                        />
                        <Button
                            v-for="page in visiblePages"
                            :key="page"
                            :label="String(page)"
                            :severity="page === meta.current_page ? undefined : 'secondary'"
                            :text="page !== meta.current_page"
                            size="small"
                            @click="goToPage(page)"
                        />
                        <Button
                            icon="pi pi-chevron-right"
                            severity="secondary"
                            text
                            size="small"
                            :aria-label="$t('sk-datatable.next_page')"
                            :disabled="meta.current_page === meta.last_page"
                            @click="goToPage(meta.current_page + 1)"
                        />
                        <Button
                            icon="pi pi-angle-double-right"
                            severity="secondary"
                            text
                            size="small"
                            :aria-label="$t('sk-datatable.last_page')"
                            :disabled="meta.current_page === meta.last_page"
                            @click="goToPage(meta.last_page)"
                        />
                    </div>
                </div>
            </div>

            <!-- Row context menu (three-dot) -->
            <Menu v-if="hasMenuActions" ref="menuRef" :model="activeMenuItems" :popup="true" class="sk-dt-menu">
                <template #item="{ item, props: itemProps }">
                    <a v-ripple class="sk-dt-menu__link" v-bind="itemProps.action">
                        <span v-if="item.icon" :class="item.icon" class="sk-dt-menu__icon" />
                        <span class="sk-dt-menu__label">{{ $t(String(item.label ?? '')) }}</span>
                    </a>
                </template>
            </Menu>

            <!-- Floating bulk action bar — appears bottom-center while rows are selected -->
            <Teleport to="body">
                <Transition name="sk-dt-bulkbar">
                    <div v-if="showBulkBar" class="sk-dt-bulkbar" role="toolbar">
                        <span class="sk-dt-bulkbar__label">{{ bulkBarLabel }}</span>
                        <button
                            type="button"
                            class="sk-dt-bulkbar__clear"
                            :aria-label="$t('sk-datatable.bulk_clear_selection')"
                            @click="selection!.clearSelection()"
                        >
                            <i class="pi pi-times" />
                        </button>
                        <div class="sk-dt-bulkbar__actions">
                            <slot name="bulk-actions" />
                        </div>
                    </div>
                </Transition>
            </Teleport>

            <!-- ID Popover -->
            <Popover ref="idPopoverRef" class="sk-dt-id-popover">
                <div class="sk-dt-id-popover__content">
                    <span class="sk-dt-id-popover__label">ID : </span>
                    <span class="sk-dt-id-popover__value">{{ idPopoverValue }}</span>
                    <span class="px-2">|</span>
                    <Button
                        :icon="idCopied ? 'pi pi-check' : 'pi pi-copy'"
                        :severity="idCopied ? 'success' : 'secondary'"
                        text
                        rounded
                        @click="copyId"
                    />
                </div>
            </Popover>
        </template>
    </SkCard>
</template>
