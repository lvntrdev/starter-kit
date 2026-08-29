import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive, ref } from 'vue';
import type { TabDefinition } from '../useUrlTab';

/**
 * Locks the public `useUrlTab` contract, plus the reactive-array tracking
 * mechanism SkTabs leans on instead of a snapshot, and covers the regressions
 * closed in the "regressions" block below: U5 re-selecting the active tab is
 * a no-op, U6 the URL hash survives a switch.
 *
 * Mirrors the reactive-page mock shape used by the sibling SkTabs.spec.ts:
 * `usePage()` returns a mutable `reactive({ url })` the test writes to
 * directly, and `router.visit` is a spy the test asserts against.
 */

const page = reactive<{ url: string }>({ url: '/settings' });
const routerVisit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    router: { visit: routerVisit },
}));

const { useUrlTab } = await import('../useUrlTab');

function tabs(...keys: string[]): TabDefinition[] {
    return keys.map((key) => ({ key, label: key }));
}

beforeEach(() => {
    page.url = '/settings';
    routerVisit.mockClear();
});

describe('useUrlTab — activeTab getter', () => {
    it('no query param resolves to the first tab', () => {
        const { activeTab } = useUrlTab(tabs('a', 'b'));

        expect(activeTab.value).toBe('a');
    });

    it('a matching ?tab= param resolves to that key', () => {
        page.url = '/settings?tab=b';
        const { activeTab } = useUrlTab(tabs('a', 'b'));

        expect(activeTab.value).toBe('b');
    });

    it('an unknown key falls back to the first tab', () => {
        page.url = '/settings?tab=missing';
        const { activeTab } = useUrlTab(tabs('a', 'b'));

        expect(activeTab.value).toBe('a');
    });

    it('an empty tabs list resolves to an empty string', () => {
        page.url = '/settings?tab=a';
        const { activeTab } = useUrlTab([]);

        expect(activeTab.value).toBe('');
    });

    it('reads a custom query param name', () => {
        page.url = '/settings?section=b';
        const { activeTab } = useUrlTab(tabs('a', 'b'), 'section');

        expect(activeTab.value).toBe('b');
    });
});

describe('useUrlTab — activeTab setter', () => {
    it('visits with the query param set to the selected key', () => {
        const { activeTab } = useUrlTab(tabs('a', 'b'));

        activeTab.value = 'b';

        expect(routerVisit).toHaveBeenCalledWith('/settings?tab=b', {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    });

    it('selecting the first tab deletes the param and keeps other params', () => {
        page.url = '/settings?tab=b&foo=bar';
        const { activeTab } = useUrlTab(tabs('a', 'b'));

        activeTab.value = 'a';

        expect(routerVisit).toHaveBeenCalledWith('/settings?foo=bar', {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    });
});

describe('useUrlTab — activeIndex', () => {
    it('gets the index of the active key', () => {
        page.url = '/settings?tab=b';
        const { activeIndex } = useUrlTab(tabs('a', 'b', 'c'));

        expect(activeIndex.value).toBe(1);
    });

    it('an unmatched key resolves the index to 0', () => {
        page.url = '/settings?tab=missing';
        const { activeIndex } = useUrlTab(tabs('a', 'b', 'c'));

        expect(activeIndex.value).toBe(0);
    });

    it('setting the index selects that tab', () => {
        const { activeIndex } = useUrlTab(tabs('a', 'b', 'c'));

        activeIndex.value = 2;

        expect(routerVisit).toHaveBeenCalledWith('/settings?tab=c', {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    });
});

describe('useUrlTab — isActive', () => {
    it('reflects whether the key matches the current active tab', () => {
        page.url = '/settings?tab=b';
        const { isActive } = useUrlTab(tabs('a', 'b'));

        expect(isActive('b')).toBe(true);
        expect(isActive('a')).toBe(false);
    });
});

describe('useUrlTab — reactive tabs array (mechanism Task 2 builds on)', () => {
    it('activeTab/activeIndex track mutations of a reactive tabs array — green today', () => {
        const list = reactive(tabs('a'));
        const { activeTab, activeIndex } = useUrlTab(list);

        expect(activeTab.value).toBe('a');

        list.splice(0, 0, { key: 'z', label: 'Z' });

        expect(activeTab.value).toBe('z');
        expect(activeIndex.value).toBe(0);

        page.url = '/settings?tab=a';

        expect(activeIndex.value).toBe(1);
    });
});

describe('useUrlTab — regressions (Task 2 fixes)', () => {
    it('U5: setting the already-active key does not re-visit', () => {
        page.url = '/settings?tab=a';
        const { activeTab } = useUrlTab(tabs('a', 'b'));

        activeTab.value = 'a';

        expect(routerVisit).not.toHaveBeenCalled();
    });

    it('U6: the URL hash survives a tab switch', () => {
        page.url = '/settings?tab=a#security';
        const { activeTab } = useUrlTab(tabs('a', 'b'));

        activeTab.value = 'b';

        expect(routerVisit).toHaveBeenCalledWith('/settings?tab=b#security', {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    });
});

describe('useUrlTab — ref / getter tab lists', () => {
    it('a getter-backed list is re-read on every access', () => {
        const keys = ref(['a']);
        const { activeTab, activeIndex } = useUrlTab(() => tabs(...keys.value));

        expect(activeTab.value).toBe('a');

        keys.value = ['z', 'a'];

        expect(activeTab.value).toBe('z');
        expect(activeIndex.value).toBe(0);

        page.url = '/settings?tab=a';

        expect(activeTab.value).toBe('a');
        expect(activeIndex.value).toBe(1);
    });

    it('a ref-backed list is tracked, setter included', () => {
        const list = ref<TabDefinition[]>(tabs('a'));
        const { activeTab, activeIndex } = useUrlTab(list);

        expect(activeTab.value).toBe('a');

        list.value = tabs('z', 'a');

        expect(activeTab.value).toBe('z');

        activeIndex.value = 1;

        expect(routerVisit).toHaveBeenCalledWith('/settings?tab=a', {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    });
});

describe('useUrlTab — history option', () => {
    it("'push' visits with replace: false", () => {
        const { activeTab } = useUrlTab(tabs('a', 'b'), 'tab', { history: 'push' });

        activeTab.value = 'b';

        expect(routerVisit).toHaveBeenCalledWith('/settings?tab=b', {
            preserveState: true,
            preserveScroll: true,
            replace: false,
        });
    });

    it("'replace' and an omitted option both keep replace: true", () => {
        useUrlTab(tabs('a', 'b'), 'tab', { history: 'replace' }).activeTab.value = 'b';
        useUrlTab(tabs('a', 'b'), 'tab', {}).activeTab.value = 'b';

        expect(routerVisit).toHaveBeenCalledTimes(2);
        expect(routerVisit.mock.calls.every((call) => call[1].replace === true)).toBe(true);
    });
});
