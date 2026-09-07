// resources/js/components/Lvntr-Starter-Kit/ui/usePageHeaderHost.ts

import { computed, inject, onBeforeUnmount, watch, type ComputedRef, type VNodeChild } from 'vue';

import { SK_PAGE_HEADER_KEY, SK_PAGE_HEADER_REGION_KEY } from './pageHeader';

/**
 * Claim the layout's page header for this component instance.
 *
 * `SkCard` uses it to draw the header in its head; `SkDatatable` uses it to draw
 * the header in its own toolbar when that toolbar already carries a title, so the
 * screen never shows a table heading under a page heading.
 *
 * Candidates queue in mount order and the first one still mounted draws the header
 * — see `pageHeader.ts` for why a single claim strands it on a tab switch.
 *
 * @param eligible  Whether this instance may host at all (read once, in setup).
 * @param hideTitle Whether the host already shows a title of its own, in which case
 *                  the header contributes only its back button and page actions.
 */
export function usePageHeaderHost(
    eligible: () => boolean,
    hideTitle: () => boolean,
): {
    hostsPageHeader: ComputedRef<boolean>;
    hostedPageHeader: ComputedRef<(() => VNodeChild) | null>;
} {
    const host = inject(SK_PAGE_HEADER_KEY, null);
    const region = inject(SK_PAGE_HEADER_REGION_KEY, null);
    const id = Symbol('sk-page-header-host');

    // Reactive, not a one-shot setup read: the active theme comes from Inertia's
    // shared props, so switching it on the Appearance screen flips `enabled` while
    // every card stays mounted. A candidacy frozen at setup left the outgoing theme's
    // host still claiming the header (aura → main drew it twice) and the incoming
    // theme's cards unable to claim it at all.
    const mayHost = computed(() => !!host && host.enabled.value && (region?.value ?? true) && eligible());

    function register(): void {
        if (!host) return;
        if (!host.candidates.value.includes(id)) host.candidates.value.push(id);
    }

    function unregister(): void {
        if (!host) return;
        const candidates = host.candidates.value;
        const index = candidates.indexOf(id);
        if (index !== -1) candidates.splice(index, 1);
    }

    // Registered in SETUP so the header is drawn on the very first render pass
    // instead of flashing in the layout's fallback position.
    if (mayHost.value) {
        register();
    }

    watch(mayHost, (may) => (may ? register() : unregister()));

    onBeforeUnmount(unregister);

    const hostsPageHeader = computed(() => mayHost.value && host?.candidates.value[0] === id);

    const hostedPageHeader = computed(() => {
        if (!hostsPageHeader.value) return null;
        const bare = hideTitle();

        return () => host!.render({ hideTitle: bare });
    });

    return { hostsPageHeader, hostedPageHeader };
}
