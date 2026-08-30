// @vitest-environment jsdom

import { beforeEach, describe, expect, it, vi } from 'vitest';

const routerPost = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: { post: routerPost },
}));
vi.mock('primevue/usetoast', () => ({
    useToast: () => ({ add: vi.fn() }),
}));
vi.mock('laravel-vue-i18n', () => ({
    trans: (key: string) => key,
}));

const { useDatatableSelection } = await import('../useDatatableSelection');

describe('useDatatableSelection — executeBulkAction id fidelity', () => {
    beforeEach(() => {
        routerPost.mockReset();
    });

    it('sends selected ids unchanged in type and value', () => {
        const selection = useDatatableSelection({ bulkUrl: '/admin/users/bulk' });

        const ids = [123, '123', '00123', '9f8e2c3a-1b2d-4e5f-8a9b-0c1d2e3f4a5b', '01ARZ3NDEKTSV4RRFFQ69G5FAV'];
        ids.forEach((id) => selection.selectedIds.value.add(id));

        selection.executeBulkAction('delete');

        expect(routerPost).toHaveBeenCalledTimes(1);
        const [url, payload] = routerPost.mock.calls[0];
        expect(url).toBe('/admin/users/bulk');
        expect(payload.ids).toEqual(ids);
        expect(payload.ids.map((id: unknown) => typeof id)).toEqual([
            'number',
            'string',
            'string',
            'string',
            'string',
        ]);
    });

    it('marks select_all_filtered true and forwards filter_snapshot only in "all" mode', () => {
        const selection = useDatatableSelection({ bulkUrl: '/admin/users/bulk' });
        selection.selectAllFiltered();

        const snapshot = { status: 'active' };
        selection.executeBulkAction('delete', snapshot);

        const [, payload] = routerPost.mock.calls[0];
        expect(payload.select_all_filtered).toBe(true);
        expect(payload.filter_snapshot).toEqual(snapshot);
    });

    it('uses select_all_filtered=false and an empty filter_snapshot in page mode', () => {
        const selection = useDatatableSelection({ bulkUrl: '/admin/users/bulk' });
        selection.selectedIds.value.add(1);

        selection.executeBulkAction('delete', { status: 'active' });

        const [, payload] = routerPost.mock.calls[0];
        expect(payload.select_all_filtered).toBe(false);
        expect(payload.filter_snapshot).toEqual({});
    });

    it('does not post when there is no selection in page mode', () => {
        const selection = useDatatableSelection({ bulkUrl: '/admin/users/bulk' });

        selection.executeBulkAction('delete');

        expect(routerPost).not.toHaveBeenCalled();
    });
});
