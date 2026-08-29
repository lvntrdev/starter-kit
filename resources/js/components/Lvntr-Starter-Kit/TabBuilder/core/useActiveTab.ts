import { computed, ref, toValue } from 'vue';
import type { MaybeRefOrGetter, WritableComputedRef } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import type { TabHistoryMode, TabUrlMode } from './types';

/**
 * Active-tab state for <SkTabs>, owned by the component library on purpose.
 *
 * The published `@/composables/useUrlTab` resolves to the CONSUMER's copy when
 * one exists (`sk:publish --tag=composables`, or a hand-edited local file), so a
 * component that depended on it would silently run against whatever version of
 * that file the host happens to have — a screen shipped with this library would
 * then break on an app that published the composable two releases ago. Keeping
 * the state here removes that version skew: the component and its state logic
 * always ship together. `useUrlTab` stays the public, URL-only composable for
 * app code; this module additionally covers the local (URL-less) and
 * client-side-URL modes SkTabs needs.
 */

export interface ActiveTabEntry {
    key: string;
    label: string;
    icon?: string;
}

export interface UseActiveTabOptions {
    /** Query parameter carrying the active key. Only read when `syncUrl` is true. */
    queryParam: string;
    /** Mirror the active tab in the URL. False keeps the state local to the component. */
    syncUrl: boolean;
    history: TabHistoryMode;
    urlMode: TabUrlMode;
    /** Local-mode starting key. Ignored when it names no listed entry. */
    initial?: string;
}

export interface UseActiveTabReturn {
    activeKey: WritableComputedRef<string>;
    isActive: (key: string) => boolean;
    select: (key: string) => void;
}

export function useActiveTab(tabs: MaybeRefOrGetter<ActiveTabEntry[]>, options: UseActiveTabOptions): UseActiveTabReturn {
    const page = usePage();

    // Read through `toValue` at every use, never snapshotted: the caller hands in
    // a getter/ref whose contents change as tabs gain or lose visibility, and the
    // computeds below have to track that.
    function entries(): ActiveTabEntry[] {
        return toValue(tabs) ?? [];
    }

    function parseUrl(): URL {
        const origin = typeof window !== 'undefined' ? window.location.origin : 'http://localhost';
        return new URL(page.url, origin);
    }

    function resolveFromUrl(): string {
        const list = entries();
        const param = parseUrl().searchParams.get(options.queryParam);
        const found = list.find((tab) => tab.key === param);
        return found ? found.key : (list[0]?.key ?? '');
    }

    // Local mode holds the selection here. `null` means "nothing selected yet",
    // which is what lets `initial` apply lazily — the list is often still empty
    // when the composable is created and fills in on the first sync.
    const localKey = ref<string | null>(null);

    function resolveLocal(): string {
        const list = entries();
        const desired = localKey.value ?? options.initial;
        const found = list.find((tab) => tab.key === desired);
        return found ? found.key : (list[0]?.key ?? '');
    }

    function resolveActiveKey(): string {
        return options.syncUrl ? resolveFromUrl() : resolveLocal();
    }

    function writeUrl(key: string): void {
        const url = parseUrl();
        if (key === entries()[0]?.key) {
            url.searchParams.delete(options.queryParam);
        } else {
            url.searchParams.set(options.queryParam, key);
        }
        // `url.hash` is empty unless the current URL carries one; keep it so an
        // in-page anchor survives the switch.
        const target = url.pathname + url.search + url.hash;

        if (options.urlMode === 'client') {
            // No request at all — Inertia rewrites the current page's URL, and the
            // getter re-resolves because `page.url` is reactive. Panels that load
            // their own data keep it; nothing server-side is re-run.
            const visit = { url: target, preserveState: true, preserveScroll: true };
            if (options.history === 'push') {
                router.push(visit);
            } else {
                router.replace(visit);
            }
            return;
        }

        router.visit(target, {
            preserveState: true,
            preserveScroll: true,
            replace: options.history === 'replace',
        });
    }

    const activeKey = computed<string>({
        get: () => resolveActiveKey(),
        set: (value: string) => {
            // Re-selecting the active tab would only redo work already done.
            if (value === resolveActiveKey()) return;

            if (!options.syncUrl) {
                localKey.value = value;
                return;
            }

            writeUrl(value);
        },
    });

    function isActive(key: string): boolean {
        return activeKey.value === key;
    }

    function select(key: string): void {
        activeKey.value = key;
    }

    return { activeKey, isActive, select };
}
