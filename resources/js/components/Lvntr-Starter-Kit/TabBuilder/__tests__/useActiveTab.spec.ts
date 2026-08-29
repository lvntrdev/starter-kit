import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive, ref } from 'vue';
import type { ActiveTabEntry, UseActiveTabOptions } from '../core/useActiveTab';

/**
 * Locks the library-owned active-tab state: the three modes (server URL,
 * client URL, local), the history mode, and the list-tracking contract SkTabs
 * depends on (getter/ref read through `toValue` on every access).
 *
 * Mock shape mirrors the sibling SkTabs.spec.ts: `usePage()` returns a mutable
 * `reactive({ url })` the tests write to directly, and the router methods are
 * spies the tests assert against.
 */

const page = reactive<{ url: string }>({ url: '/settings' });
const routerVisit = vi.fn();
const routerReplace = vi.fn();
const routerPush = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    router: { visit: routerVisit, replace: routerReplace, push: routerPush },
}));

const { useActiveTab } = await import('../core/useActiveTab');

function tabs(...keys: string[]): ActiveTabEntry[] {
    return keys.map((key) => ({ key, label: key }));
}

/** Server URL sync with the defaults SkTabs uses today. */
function serverOptions(extra: Partial<UseActiveTabOptions> = {}): UseActiveTabOptions {
    return { queryParam: 'tab', syncUrl: true, history: 'replace', urlMode: 'server', ...extra };
}

beforeEach(() => {
    page.url = '/settings';
    routerVisit.mockClear();
    routerReplace.mockClear();
    routerPush.mockClear();
});

describe('useActiveTab — server URL mode', () => {
    it('no query param resolves to the first entry', () => {
        const { activeKey } = useActiveTab(tabs('a', 'b'), serverOptions());

        expect(activeKey.value).toBe('a');
    });

    it('a matching param resolves to that key, an unknown one falls back', () => {
        page.url = '/settings?tab=b';
        expect(useActiveTab(tabs('a', 'b'), serverOptions()).activeKey.value).toBe('b');

        page.url = '/settings?tab=missing';
        expect(useActiveTab(tabs('a', 'b'), serverOptions()).activeKey.value).toBe('a');
    });

    it('an empty list resolves to an empty string', () => {
        page.url = '/settings?tab=a';

        expect(useActiveTab([], serverOptions()).activeKey.value).toBe('');
    });

    it('selecting a key visits with the exact shape SkTabs locks today', () => {
        const { select } = useActiveTab(tabs('a', 'b'), serverOptions());

        select('b');

        expect(routerVisit).toHaveBeenCalledWith('/settings?tab=b', {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
        expect(routerReplace).not.toHaveBeenCalled();
        expect(routerPush).not.toHaveBeenCalled();
    });

    it('selecting the first entry deletes the param and keeps the others', () => {
        page.url = '/settings?tab=b&foo=bar';
        const { select } = useActiveTab(tabs('a', 'b'), serverOptions());

        select('a');

        expect(routerVisit).toHaveBeenCalledWith('/settings?foo=bar', {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    });

    it('the URL hash survives a switch', () => {
        page.url = '/settings?tab=a#security';
        const { select } = useActiveTab(tabs('a', 'b'), serverOptions());

        select('b');

        expect(routerVisit).toHaveBeenCalledWith('/settings?tab=b#security', expect.objectContaining({ replace: true }));
    });

    it('re-selecting the active key is a no-op', () => {
        page.url = '/settings?tab=a';
        const { select } = useActiveTab(tabs('a', 'b'), serverOptions());

        select('a');

        expect(routerVisit).not.toHaveBeenCalled();
    });

    it("history 'push' visits with replace: false", () => {
        const { select } = useActiveTab(tabs('a', 'b'), serverOptions({ history: 'push' }));

        select('b');

        expect(routerVisit).toHaveBeenCalledWith('/settings?tab=b', {
            preserveState: true,
            preserveScroll: true,
            replace: false,
        });
    });

    it('reads a custom query param name', () => {
        page.url = '/settings?section=b';
        const { activeKey, select } = useActiveTab(tabs('a', 'b'), serverOptions({ queryParam: 'section' }));

        expect(activeKey.value).toBe('b');

        select('a');

        expect(routerVisit).toHaveBeenCalledWith('/settings', expect.anything());
    });
});

describe('useActiveTab — client URL mode', () => {
    it("history 'replace' calls router.replace and never visits", () => {
        const { select } = useActiveTab(tabs('a', 'b'), serverOptions({ urlMode: 'client' }));

        select('b');

        expect(routerReplace).toHaveBeenCalledWith({
            url: '/settings?tab=b',
            preserveState: true,
            preserveScroll: true,
        });
        expect(routerPush).not.toHaveBeenCalled();
        expect(routerVisit).not.toHaveBeenCalled();
    });

    it("history 'push' calls router.push and never visits", () => {
        const { select } = useActiveTab(tabs('a', 'b'), serverOptions({ urlMode: 'client', history: 'push' }));

        select('b');

        expect(routerPush).toHaveBeenCalledWith({
            url: '/settings?tab=b',
            preserveState: true,
            preserveScroll: true,
        });
        expect(routerReplace).not.toHaveBeenCalled();
        expect(routerVisit).not.toHaveBeenCalled();
    });

    it('the getter re-resolves once the client visit updated page.url', () => {
        const { activeKey, select } = useActiveTab(tabs('a', 'b'), serverOptions({ urlMode: 'client' }));

        select('b');
        // What Inertia's client visit does: it merges the url into the page object.
        page.url = routerReplace.mock.calls[0][0].url;

        expect(activeKey.value).toBe('b');
    });
});

describe('useActiveTab — local mode', () => {
    const localOptions = (extra: Partial<UseActiveTabOptions> = {}): UseActiveTabOptions => ({
        queryParam: 'tab',
        syncUrl: false,
        history: 'replace',
        urlMode: 'server',
        ...extra,
    });

    it('never touches the router', () => {
        page.url = '/settings?tab=b';
        const { select } = useActiveTab(tabs('a', 'b'), localOptions());

        select('b');

        expect(routerVisit).not.toHaveBeenCalled();
        expect(routerReplace).not.toHaveBeenCalled();
        expect(routerPush).not.toHaveBeenCalled();
    });

    it('ignores the query param and starts at the first entry', () => {
        page.url = '/settings?tab=b';

        expect(useActiveTab(tabs('a', 'b'), localOptions()).activeKey.value).toBe('a');
    });

    it('honours `initial` when it names a listed key', () => {
        expect(useActiveTab(tabs('a', 'b'), localOptions({ initial: 'b' })).activeKey.value).toBe('b');
    });

    it('falls back to the first entry when `initial` names nothing', () => {
        expect(useActiveTab(tabs('a', 'b'), localOptions({ initial: 'missing' })).activeKey.value).toBe('a');
    });

    it('select() moves the state and re-selecting is a no-op', () => {
        const { activeKey, select } = useActiveTab(tabs('a', 'b'), localOptions());

        select('b');
        expect(activeKey.value).toBe('b');

        select('b');
        expect(activeKey.value).toBe('b');
    });

    it('falls back to the first entry when the active key leaves the list', () => {
        const keys = ref(['a', 'b']);
        const { activeKey, select, isActive } = useActiveTab(() => tabs(...keys.value), localOptions());

        select('b');
        expect(activeKey.value).toBe('b');

        keys.value = ['a', 'c'];

        expect(activeKey.value).toBe('a');
        expect(isActive('b')).toBe(false);
    });
});

describe('useActiveTab — list tracking', () => {
    it('a getter-backed list is re-read on every access', () => {
        const keys = ref(['a']);
        const { activeKey } = useActiveTab(() => tabs(...keys.value), serverOptions());

        expect(activeKey.value).toBe('a');

        keys.value = ['z', 'a'];

        expect(activeKey.value).toBe('z');
    });

    it('a ref-backed list is tracked', () => {
        const list = ref<ActiveTabEntry[]>(tabs('a'));
        const { activeKey } = useActiveTab(list, serverOptions());

        expect(activeKey.value).toBe('a');

        list.value = tabs('z', 'a');

        expect(activeKey.value).toBe('z');

        page.url = '/settings?tab=a';

        expect(activeKey.value).toBe('a');
    });

    it('a reactive array mutated in place is tracked (the shape SkTabs feeds it)', () => {
        const list = reactive<ActiveTabEntry[]>(tabs('a'));
        const { activeKey } = useActiveTab(list, serverOptions());

        expect(activeKey.value).toBe('a');

        list.splice(0, 0, { key: 'z', label: 'Z' });

        expect(activeKey.value).toBe('z');
    });

    it('isActive reflects the resolved key', () => {
        page.url = '/settings?tab=b';
        const { isActive } = useActiveTab(tabs('a', 'b'), serverOptions());

        expect(isActive('b')).toBe(true);
        expect(isActive('a')).toBe(false);
    });
});
