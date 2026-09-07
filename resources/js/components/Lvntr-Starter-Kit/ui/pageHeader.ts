// resources/js/components/Lvntr-Starter-Kit/ui/pageHeader.ts

import { computed, defineComponent, inject, provide } from 'vue';
import type { InjectionKey, Ref, VNodeChild } from 'vue';

/**
 * Page-header hosting context — the admin layout provides it, `SkCard` claims it.
 *
 * Themes disagree on WHERE a page's title / subtitle / back button / page actions
 * belong: the `main` theme draws them as a bare strip above the content, while
 * `aura` wants them inside the content card's own header (the datatable card, the
 * form card, …). Rather than branching every page on the active theme, the layout
 * hands its ONE page header over as a render function and the first card that
 * claims this context draws it in its own head.
 *
 * Rendering rather than teleporting is deliberate: a teleport resolves its DOM
 * target once, when it mounts, which is unreliable on a page whose hosting card
 * appears later (a vertical `SkTabs` panel mounts only after the active tab
 * resolves) — the lookup caches `null` and the header is stranded outside the card.
 *
 * Cards register as CANDIDATES rather than claiming a single slot: the first one
 * still registered draws the header, and a card that disappears (a switched tab
 * panel, a closed dialog, the outgoing page of an Inertia visit) just drops out of
 * the list. A single claim would strand the header — an incoming card runs its
 * `setup` while the outgoing one is still mounted, so it would find the slot taken,
 * and the outgoing card would then release it to nobody.
 */
export interface SkPageHeaderHost {
    /** Cards able to host the header, in mount order; the first one draws it. */
    candidates: Ref<symbol[]>;
    /** False when the layout wants the header rendered in place (non-hosting themes). */
    enabled: Ref<boolean>;
    /**
     * Draws the layout's page header; called by the hosting card in its own head.
     * `hideTitle` drops the page title and subtitle so a card that already has a
     * title of its own keeps it and only takes over the back button and page
     * actions — two stacked headings in one card head read as a mistake.
     */
    render: (options?: { hideTitle?: boolean }) => VNodeChild;
}

export const SK_PAGE_HEADER_KEY: InjectionKey<SkPageHeaderHost> = Symbol('sk-page-header-host');

/**
 * Whether the region a card lives in is the one the user is actually looking at.
 *
 * A tab panel is not always unmounted when it goes inactive: horizontal `SkTabs`
 * (and any `panels: 'all'` config) mounts every panel and merely hides the
 * inactive ones, so their cards stay in the candidate list. Without this flag the
 * first panel's card kept the page header after a tab switch and the heading, back
 * button and page actions went invisible with it.
 *
 * Absent injection means "visible" — a card outside any region hosts as before.
 */
export const SK_PAGE_HEADER_REGION_KEY: InjectionKey<Ref<boolean>> = Symbol('sk-page-header-region');

/**
 * Renderless provider of {@link SK_PAGE_HEADER_REGION_KEY}. Nests: a region inside
 * a hidden region is hidden too, so a nested `SkTabs` cannot resurrect the claim.
 */
export const SkPageHeaderRegion = defineComponent({
    name: 'SkPageHeaderRegion',
    props: {
        active: { type: Boolean, required: true },
    },
    setup(props, { slots }) {
        const parent = inject(SK_PAGE_HEADER_REGION_KEY, null);

        provide(
            SK_PAGE_HEADER_REGION_KEY,
            computed(() => (parent?.value ?? true) && props.active),
        );

        return () => slots.default?.();
    },
});
