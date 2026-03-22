<script setup lang="ts">
    import type { DataTableConfig, DataTableResponse, ActionConfig } from '@lvntr/components/DatatableBuilder/core';
    import { useApi } from '@/composables/useApi';
    import { useDefinition } from '@/composables/useDefinition';
    import { useRefreshBus } from '@/composables/useRefreshBus';
    import { Link } from '@inertiajs/vue3';
    import type { MenuItem } from 'primevue/menuitem';
    import { trans } from 'laravel-vue-i18n';

    interface Props {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        config: DataTableConfig<any>;
        /** Register this table in the refresh bus under the given key. */
        refreshKey?: string;
    }

    const props = defineProps<Props>();

    const emit = defineEmits<{
        load: [data: unknown[]];
    }>();

    const api = useApi();
    const definition = useDefinition();

    // ── Auto-load definitions for definition-tag columns ───────────────────────
    const definitionKeys = props.config.columns
        .filter((c) => c.tag === 'definition' && c.tagKey)
        .map((c) => c.tagKey!);

    if (definitionKeys.length) {
        onMounted(() => definition.load(definitionKeys));
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

    useEventListener(window, 'resize', useDebounceFn(calcScrollMax, 200));

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

    const activeFilters = ref<Record<string, string | number | null>>(
        Object.fromEntries(props.config.filters.map((f) => [f.key, null])),
    );

    // ── State Persistence ────────────────────────────────────────────────────────

    /** Guard flag — prevents watchers from resetting page during initial restore. */
    let initializing = true;

    /** Storage key — unique per DataTable instance. */
    const storageKey = `dt:${props.config.route}`;
    const reloadFlagKey = `${storageKey}:reload`;

    /**
     * Mark that a page reload is happening (F5/close).
     * `beforeunload` fires on browser reload/close but NOT on Inertia SPA navigation.
     */
    function onBeforeUnload() {
        sessionStorage.setItem(reloadFlagKey, '1');
    }

    /**
     * Restore DataTable state.
     * Priority: URL query params (shareable links) → sessionStorage (survives reload & navigation).
     */
    function restoreState(): void {
        const urlParams = new URLSearchParams(window.location.search);

        if (urlParams.toString().length > 0) {
            restoreFromUrlParams(urlParams);
            return;
        }

        try {
            const raw = sessionStorage.getItem(storageKey);
            if (raw) {
                const saved = JSON.parse(raw) as Record<string, unknown>;
                search.value = (saved.search as string) ?? '';
                sortKey.value = (saved.sortKey as string) ?? '';
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
                                ) as unknown as string | number | null;
                            } else {
                                activeFilters.value[key] = val as string | number | null;
                            }
                        }
                    }
                }
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

        // Preserve non-datatable query params (e.g. ?type=systemManagers)
        const managedKeys = new Set<string>();
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

        window.history.replaceState(window.history.state, '', url);
    }

    // ── Data fetching ─────────────────────────────────────────────────────────────

    async function fetchData() {
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

            const separator = props.config.route.includes('?') ? '&' : '?';
            const url = `${props.config.route}${separator}${params.toString()}`;

            const response = await api.get<DataTableResponse<unknown>>(url);

            data.value = response.data;
            meta.value = {
                total: response.total,
                per_page: response.per_page,
                current_page: response.current_page,
                last_page: response.last_page,
                from: response.from,
                to: response.to,
            };

            syncState();
            emit('load', response.data);
        } finally {
            loading.value = false;
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
        currentPage.value = 1;
        fetchData();
    });

    watch(
        activeFilters,
        () => {
            if (initializing) return;
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

    function escapeHtml(str: string): string {
        const map: Record<string, string> = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return str.replace(/[&<>"']/g, (c) => map[c]);
    }

    function formatDateParam(date: Date): string {
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

    const hasActions = computed(() => props.config.actions.length > 0 || props.config.menuActions.length > 0);
    const hasMenuActions = computed(() => props.config.menuActions.length > 0);
    const showIdColumn = computed(() => props.config.idColumn?.visible !== false);
    const idKey = computed(() => props.config.idColumn?.key ?? 'id');
    const colspan = computed(
        () => props.config.columns.length + (showIdColumn.value ? 1 : 0) + (hasActions.value ? 1 : 0),
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
    const panelFilters = computed(() => props.config.filters.filter((f) => f.placement === 'panel'));
    const filterPopoverRef = ref();
    const searchPopoverRef = ref();

    /** Badge count for filter button — all active filter tags count. */
    const panelFilterBadge = computed(() => {
        const count = activeTags.value.filter((t) => t.type === 'filter').length;
        return count > 0 ? String(count) : '';
    });

    /** Human-readable label for an active filter value. */
    function filterDisplayValue(filter: (typeof props.config.filters)[number]): string | null {
        const val = activeFilters.value[filter.key] as unknown;
        if (val === null || val === undefined || val === '') return null;

        if ((filter.type === 'select' || filter.type === 'select-button') && filter.options) {
            const opt = filter.options.find((o) => o.value === val);
            return opt ? opt.label : String(val);
        }

        if (filter.type === 'daterange' && Array.isArray(val)) {
            const [from, to] = val as [Date | null, Date | null];
            const fmt = (d: Date) => d.toLocaleDateString('tr-TR');
            if (from && to) return `${fmt(from)} – ${fmt(to)}`;
            if (from) return `${fmt(from)} –`;
            if (to) return `– ${fmt(to)}`;
            return null;
        }

        if (filter.type === 'date' && val instanceof Date) {
            return val.toLocaleDateString('tr-TR');
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
                command: () => ma.handle(row),
            });
        }

        return items;
    }

    let activeMenuItems = ref<MenuItem[]>([]);

    function toggleMenu(event: Event, row: unknown): void {
        activeMenuItems.value = buildMenuItems(row);
        nextTick(() => {
            menuRef.value?.toggle(event);
        });
    }

    // ── Public API ────────────────────────────────────────────────────────────────

    defineSlots<{ toolbar?(): unknown }>();

    defineExpose({ refresh: fetchData });

    // ── Card passthrough ─────────────────────────────────────────────────────────

    const cardPt = computed(() => {
        const noPad = { style: 'padding: 0' };
        if (props.config.isCard) {
            // isCard true → Card visible (bg/shadow)
            return {
                root: {},
                body: noPad,
                content: noPad,
            };
        }
        // Default → transparent wrapper
        return {
            root: { style: 'background: transparent; box-shadow: none; border: 0; padding: 0' },
            body: noPad,
            content: noPad,
        };
    });
</script>

<template>
    <Card :pt="cardPt">
        <template v-if="config.cardTitle" #title>
            {{ $t(config.cardTitle) }}
        </template>
        <template v-if="config.cardSubtitle" #subtitle>
            {{ $t(config.cardSubtitle) }}
        </template>
        <template #content>
            <!-- Toolbar: Search, Inline Filters, Filter Button, Create Button, Custom Slot -->
            <div
                v-if="config.searchable || config.filters.length > 0 || config.createButton || $slots.toolbar"
                class="sk-dt-toolbar"
            >
                <!-- Left: Search (hidden on mobile, shown on sm+) -->
                <div v-if="config.searchable" class="sk-dt-toolbar__search">
                    <IconField>
                        <InputIcon class="pi pi-search" />
                        <InputText
                            v-model="search"
                            :placeholder="$t('common.search')"
                            class="w-full"
                            autocomplete="one-time-code"
                        />
                        <InputIcon v-if="search" class="pi pi-times sk-dt-toolbar__search-clear" @click="search = ''" />
                    </IconField>
                </div>

                <!-- Right: Inline Filters, Filter Popover, Actions -->
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

                    <!-- Inline Filters (hidden on mobile — shown in popover instead) -->
                    <div v-for="filter in inlineFilters" :key="filter.key" class="sk-dt-toolbar__inline-filter">
                        <span class="sk-dt-toolbar__inline-filter-label">{{ resolveFilterLabel(filter) }}</span>
                        <Select
                            v-if="filter.type === 'select'"
                            v-model="activeFilters[filter.key]"
                            :options="filter.options ?? []"
                            option-label="label"
                            option-value="value"
                            :placeholder="filter.placeholder ?? resolveFilterLabel(filter)"
                            show-clear
                            class="min-w-48"
                        />
                        <SelectButton
                            v-else-if="filter.type === 'select-button'"
                            v-model="activeFilters[filter.key]"
                            :options="filter.options ?? []"
                            option-label="label"
                            option-value="value"
                            :allow-empty="true"
                        />
                        <DatePicker
                            v-else-if="filter.type === 'date'"
                            v-model="activeFilters[filter.key]"
                            :placeholder="filter.placeholder ?? resolveFilterLabel(filter)"
                            date-format="dd.mm.yy"
                            show-button-bar
                            class="min-w-48"
                        />
                        <DatePicker
                            v-else-if="filter.type === 'daterange'"
                            v-model="activeFilters[filter.key]"
                            :placeholder="filter.placeholder ?? resolveFilterLabel(filter)"
                            date-format="dd.mm.yy"
                            selection-mode="range"
                            show-button-bar
                            class="min-w-56"
                        />
                    </div>
                    <!-- END FILTERS GROUP -->

                    <!-- Filter Popover Toggle -->
                    <Button
                        v-if="panelFilters.length > 0"
                        icon="pi pi-filter"
                        severity="secondary"
                        variant="outlined"
                        :badge="panelFilterBadge"
                        badge-severity="contrast"
                        @click="(e: Event) => filterPopoverRef?.toggle(e)"
                    />

                    <!-- Create / Custom actions -->
                    <div v-if="config.createButton || $slots.toolbar" class="sk-dt-toolbar__actions">
                        <!-- Create: link button -->
                        <Link v-if="config.createButton?.url" :href="config.createButton.url">
                            <Button
                                :label="config.createButton.label ?? 'Add'"
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
                            :label="config.createButton.label ?? 'Add'"
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

            <!-- Filter Popover — on mobile: all filters, on desktop: only panel filters -->
            <Popover v-if="panelFilters.length > 0" ref="filterPopoverRef" class="sk-dt-filter-popover">
                <div class="sk-dt-filter-popover__content">
                    <div
                        v-for="filter in config.filters"
                        :key="filter.key"
                        class="sk-dt-filter-popover__item"
                        :class="{ 'sk-dt-filter-popover__item--inline-only': filter.placement === 'inline' }"
                    >
                        <label class="sk-dt-filter-popover__label">{{ resolveFilterLabel(filter) }}</label>
                        <Select
                            v-if="filter.type === 'select'"
                            v-model="activeFilters[filter.key]"
                            :options="filter.options ?? []"
                            option-label="label"
                            option-value="value"
                            :placeholder="filter.placeholder ?? resolveFilterLabel(filter)"
                            show-clear
                            class="w-full"
                        />
                        <SelectButton
                            v-else-if="filter.type === 'select-button'"
                            v-model="activeFilters[filter.key]"
                            :options="filter.options ?? []"
                            option-label="label"
                            option-value="value"
                            :allow-empty="true"
                            class="w-full"
                        />
                        <DatePicker
                            v-else-if="filter.type === 'date'"
                            v-model="activeFilters[filter.key]"
                            :placeholder="filter.placeholder ?? resolveFilterLabel(filter)"
                            date-format="dd.mm.yy"
                            show-button-bar
                            class="w-full"
                        />
                        <DatePicker
                            v-else-if="filter.type === 'daterange'"
                            v-model="activeFilters[filter.key]"
                            :placeholder="filter.placeholder ?? resolveFilterLabel(filter)"
                            date-format="dd.mm.yy"
                            selection-mode="range"
                            show-button-bar
                            class="w-full"
                        />
                    </div>
                </div>
            </Popover>

            <!-- Search Popover (mobile only) -->
            <Popover v-if="config.searchable" ref="searchPopoverRef" class="sk-dt-search-popover">
                <div class="sk-dt-search-popover__content">
                    <IconField class="w-full">
                        <InputIcon class="pi pi-search" />
                        <InputText
                            v-model="search"
                            :placeholder="$t('common.search')"
                            class="w-full"
                            autofocus
                            autocomplete="one-time-code"
                        />
                        <InputIcon v-if="search" class="pi pi-times sk-dt-toolbar__search-clear" @click="search = ''" />
                    </IconField>
                </div>
            </Popover>

            <!-- Active Filter Tags -->
            <div v-if="activeTags.length > 0" class="sk-dt-tags">
                <span v-for="tag in activeTags" :key="tag.key" class="sk-dt-tags__tag">
                    <span class="sk-dt-tags__tag-label">{{ tag.label }}:</span>
                    <span class="sk-dt-tags__tag-value">{{ tag.value }}</span>
                    <i class="pi pi-times sk-dt-tags__tag-remove" @click="clearFilter(tag.key)" />
                </span>
                <button class="sk-dt-tags__clear-all" @click="clearAllFilters">
                    {{ $t('button.clear_all') }}
                </button>
            </div>

            <!-- Table -->
            <div class="sk-dt">
                <div ref="scrollRef" class="sk-dt__scroll" :style="scrollMaxH ? { maxHeight: scrollMaxH } : undefined">
                    <table class="sk-dt__table">
                        <thead class="sk-dt__thead">
                            <tr>
                                <!-- Built-in ID column header -->
                                <th
                                    v-if="showIdColumn"
                                    class="sk-dt__th sk-dt__th--sticky sk-dt__th--id"
                                    :class="{ 'sk-dt__th--sortable': config.sortable }"
                                    @click="config.sortable ? handleSort(idKey) : undefined"
                                >
                                    <span class="sk-dt__sort-label">
                                        {{ $t('common.id') }}
                                        <i v-if="config.sortable" :class="sortIcon(idKey)" class="sk-dt__sort-icon" />
                                    </span>
                                </th>

                                <th
                                    v-for="column in config.columns"
                                    :key="column.key"
                                    class="sk-dt__th"
                                    :class="{
                                        'sk-dt__th--sortable': config.sortable && column.sortable,
                                        'sk-dt__th--sticky': column.sticky,
                                    }"
                                    @click="config.sortable && column.sortable ? handleSort(column.key) : undefined"
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
                                        <span class="sk-dt__loading-text">{{ $t('datatable.loading') }}</span>
                                    </div>
                                </td>
                            </tr>

                            <template v-else>
                                <!-- Rows -->
                                <tr v-for="(row, rowIndex) in data" :key="rowIndex" class="sk-dt__row">
                                    <!-- Built-in ID cell -->
                                    <td v-if="showIdColumn" class="sk-dt__td sk-dt__td--sticky sk-dt__td--id">
                                        <button
                                            class="sk-dt__id-trigger"
                                            @click="openIdPopover($event, getNestedValue(row, idKey))"
                                        >
                                            <i class="pi pi-info" />
                                        </button>
                                    </td>

                                    <td
                                        v-for="column in config.columns"
                                        :key="column.key"
                                        class="sk-dt__td"
                                        :class="{ 'sk-dt__td--sticky': column.sticky }"
                                    >
                                        <Tag
                                            v-if="column.tag === 'definition'"
                                            :value="
                                                definition.find(
                                                    column.tagKey!,
                                                    getNestedValue(row, column.key) as string | number,
                                                )?.label
                                            "
                                            :severity="
                                                definition.find(
                                                    column.tagKey!,
                                                    getNestedValue(row, column.key) as string | number,
                                                )?.severity ?? undefined
                                            "
                                        />
                                        <Tag
                                            v-else-if="column.tag === 'custom'"
                                            :value="String(getNestedValue(row, column.key) ?? '-')"
                                            :severity="
                                                column.severities?.[
                                                    getNestedValue(row, column.tagKey!) as string
                                                ]
                                            "
                                        />
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
                                                {{ $t('datatable.no_records') }}
                                            </p>
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
                            $t('datatable.records_info', { from: meta.from, to: meta.to, total: meta.total })
                        }}</span>
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
                            :aria-label="$t('datatable.first_page')"
                            :disabled="meta.current_page === 1"
                            @click="goToPage(1)"
                        />
                        <Button
                            icon="pi pi-chevron-left"
                            severity="secondary"
                            text
                            size="small"
                            :aria-label="$t('datatable.previous_page')"
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
                            :aria-label="$t('datatable.next_page')"
                            :disabled="meta.current_page === meta.last_page"
                            @click="goToPage(meta.current_page + 1)"
                        />
                        <Button
                            icon="pi pi-angle-double-right"
                            severity="secondary"
                            text
                            size="small"
                            :aria-label="$t('datatable.last_page')"
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
    </Card>
</template>
