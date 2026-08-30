// resources/js/composables/useDatatableSelection.ts

import { router } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { trans } from 'laravel-vue-i18n';

export type BulkSelectionMode = 'page' | 'all';

export interface BulkActionResult {
    processed: number;
    skipped: number;
    failed: Array<{ id: number | string; reason: string }>;
    message: string;
}

export interface BulkActionPayload {
    action: string;
    ids: (string | number)[];
    select_all_filtered: boolean;
    filter_snapshot: Record<string, unknown>;
    [key: string]: unknown;
}

/**
 * Composable for DataTable row selection and bulk actions.
 *
 * Provides:
 * - selectedIds: Set of selected row IDs
 * - selectionMode: 'page' (current page only) | 'all' (cross-page, uses backend filter recompute)
 * - select/deselect helpers
 * - bulk action dispatcher (Inertia router.post)
 *
 * Usage:
 *   const selection = useDatatableSelection({
 *     bulkUrl: users.bulk.url(),   // Wayfinder typed route
 *     idKey: 'id',
 *     onSuccess: () => bus.refresh(REFRESH_KEY),
 *   });
 *   // In template: bind to :selection="selection" on <SkDatatable>
 *   // Bulk delete: selection.executeBulkAction('delete', filterSnapshot)
 */
export function useDatatableSelection(options: {
    /** Absolute URL for the bulk endpoint (use Wayfinder: users.bulk.url()). */
    bulkUrl: string;
    /** Row property used as ID. Default: 'id' */
    idKey?: string;
    /** Called after a successful bulk action to refresh the table. */
    onSuccess?: () => void;
}) {
    const { bulkUrl, idKey = 'id', onSuccess } = options;

    const toast = useToast();

    // ── State ─────────────────────────────────────────────────────────────────────

    const selectedIds = ref<Set<string | number>>(new Set());
    const selectionMode = ref<BulkSelectionMode>('page');
    const submitting = ref(false);

    // ── Helpers ───────────────────────────────────────────────────────────────────

    function getRowId(row: unknown): string | number {
        return (row as Record<string, unknown>)[idKey] as string | number;
    }

    // ── Row selection ─────────────────────────────────────────────────────────────

    function toggleRow(row: unknown) {
        const id = getRowId(row);
        if (selectedIds.value.has(id)) {
            selectedIds.value.delete(id);
        } else {
            selectedIds.value.add(id);
        }
        // If something is deselected, drop cross-page mode
        if (selectionMode.value === 'all' && !selectedIds.value.has(id)) {
            selectionMode.value = 'page';
        }
    }

    function isRowSelected(row: unknown): boolean {
        return selectedIds.value.has(getRowId(row));
    }

    /**
     * Select or deselect all rows on the current page.
     * Does NOT activate cross-page mode — user must click "select all filtered".
     */
    function togglePageSelection(rows: unknown[], selected: boolean) {
        if (selected) {
            rows.forEach((row) => selectedIds.value.add(getRowId(row)));
        } else {
            rows.forEach((row) => selectedIds.value.delete(getRowId(row)));
            selectionMode.value = 'page';
        }
    }

    function isPageFullySelected(rows: unknown[]): boolean {
        if (rows.length === 0) return false;
        return rows.every((row) => selectedIds.value.has(getRowId(row)));
    }

    function isPagePartiallySelected(rows: unknown[]): boolean {
        const count = rows.filter((row) => selectedIds.value.has(getRowId(row))).length;
        return count > 0 && count < rows.length;
    }

    /** Activate cross-page selection — all filtered records will be included on submit. */
    function selectAllFiltered() {
        selectionMode.value = 'all';
    }

    function clearSelection() {
        selectedIds.value.clear();
        selectionMode.value = 'page';
    }

    const selectedCount = computed(() => selectedIds.value.size);
    const hasSelection = computed(() => selectedIds.value.size > 0);
    const isAllFilteredMode = computed(() => selectionMode.value === 'all');

    // ── Bulk action ───────────────────────────────────────────────────────────────

    /**
     * Execute a bulk action via Inertia router.post.
     *
     * @param action         - Action key, e.g. 'delete'
     * @param filterSnapshot - Current active filters (for cross-page selection backend recompute)
     * @param overrideUrl    - Optional URL override. Defaults to the bulkUrl provided in options.
     */
    function executeBulkAction(
        action: string,
        filterSnapshot: Record<string, unknown> = {},
        overrideUrl?: string,
    ) {
        if (submitting.value) return;
        if (!hasSelection.value && selectionMode.value !== 'all') return;

        const url = overrideUrl ?? bulkUrl;

        const payload: BulkActionPayload = {
            action,
            ids: [...selectedIds.value],
            select_all_filtered: selectionMode.value === 'all',
            filter_snapshot: selectionMode.value === 'all' ? filterSnapshot : {},
        };

        submitting.value = true;

        // Cast to satisfy Inertia's RequestPayload type — our payload is a plain JSON
        // object compatible with FormDataConvertible at runtime; the type recursion
        // makes static narrowing impractical without the cast.
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        router.post(url, payload as any, {
            preserveScroll: true,
            onSuccess: () => {
                clearSelection();
                onSuccess?.();
                // Flash message is handled by the backend via Inertia shared flash.
                // AdminLayout/SkFlash picks it up automatically.
            },
            onError: (errors) => {
                const firstError = Object.values(errors)[0] ?? trans('sk-datatable.bulk_error');
                toast.add({
                    severity: 'error',
                    summary: trans('sk-datatable.bulk_error_summary'),
                    detail: firstError,
                    group: 'bc',
                    life: 5000,
                });
            },
            onFinish: () => {
                submitting.value = false;
            },
        });
    }

    return {
        selectedIds,
        selectionMode,
        submitting,
        selectedCount,
        hasSelection,
        isAllFilteredMode,
        toggleRow,
        isRowSelected,
        togglePageSelection,
        isPageFullySelected,
        isPagePartiallySelected,
        selectAllFiltered,
        clearSelection,
        executeBulkAction,
    };
}
