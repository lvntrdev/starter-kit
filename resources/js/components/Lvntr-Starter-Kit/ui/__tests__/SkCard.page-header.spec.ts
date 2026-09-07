import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h, nextTick, onMounted, provide, ref } from 'vue';

import SkCard from '../SkCard.vue';
import { SK_PAGE_HEADER_KEY, SkPageHeaderRegion } from '../pageHeader';

/**
 * The page-header host contract: `AdminLayout` provides the context and the FIRST
 * eligible `SkCard` draws the layout's header in its own head. A second claim, a
 * transparent wrapper, an opted-out card or a disabled host must never draw it —
 * two hosts would mean two page headers on screen.
 */
const header = (options: { hideTitle?: boolean } = {}) =>
    h('div', { class: 'page-header' }, options.hideTitle ? 'Geri' : 'Rolü Düzenle');

function host(enabled: boolean, cards: (ReturnType<typeof h> | null)[]) {
    const candidates = ref<symbol[]>([]);
    const enabledRef = ref(enabled);
    const wrapper = mount(
        defineComponent({
            setup() {
                provide(SK_PAGE_HEADER_KEY, { candidates, enabled: enabledRef, render: header });
                return () => h('div', cards);
            },
        }),
    );
    return { wrapper, candidates, enabled: enabledRef };
}

const card = (props: Record<string, unknown> = {}) => h(SkCard, props, { default: () => 'body' });

describe('SkCard — page-header hosting', () => {
    it('lets the first eligible card draw the header and no other', async () => {
        const { wrapper } = host(true, [card(), card()]);
        await nextTick();

        expect(wrapper.findAll('.page-header')).toHaveLength(1);
        expect(wrapper.findAllComponents(SkCard)[0].find('.page-header').exists()).toBe(true);
    });

    it('draws nothing while the host is disabled (non-hosting theme)', async () => {
        const { wrapper } = host(false, [card()]);
        await nextTick();

        expect(wrapper.find('.page-header').exists()).toBe(false);
        expect(wrapper.find('[data-sk-page-header]').exists()).toBe(false);
    });

    it('follows a live theme switch in both directions', async () => {
        // The theme comes from Inertia's shared props, so the Appearance screen flips
        // `enabled` while every card stays mounted: a candidacy frozen at setup drew the
        // header twice on aura → main, and never inside a card on main → aura.
        const { wrapper, enabled, candidates } = host(false, [card()]);
        await nextTick();
        expect(wrapper.find('.page-header').exists()).toBe(false);

        enabled.value = true;
        await nextTick();
        expect(candidates.value).toHaveLength(1);
        expect(wrapper.find('.page-header').exists()).toBe(true);

        enabled.value = false;
        await nextTick();
        expect(candidates.value).toHaveLength(0);
        expect(wrapper.find('.page-header').exists()).toBe(false);
    });

    it('leaves the claim to the visible panel when an inactive one stays mounted', async () => {
        // Horizontal SkTabs (and any `panels: 'all'`) mounts every panel and only hides
        // the inactive ones, so their cards would otherwise keep the header off-screen.
        const active = ref(false);
        const panel = (isActive: () => boolean) =>
            h(SkPageHeaderRegion, { active: isActive() }, { default: () => [card()] });

        const candidates = ref<symbol[]>([]);
        const wrapper = mount(
            defineComponent({
                setup() {
                    provide(SK_PAGE_HEADER_KEY, { candidates, enabled: ref(true), render: header });
                    return () => h('div', [panel(() => !active.value), panel(() => active.value)]);
                },
            }),
        );
        await nextTick();

        const panels = wrapper.findAllComponents(SkCard);
        expect(panels[0].find('.page-header').exists()).toBe(true);
        expect(panels[1].find('.page-header').exists()).toBe(false);

        active.value = true;
        await nextTick();

        expect(wrapper.findAll('.page-header')).toHaveLength(1);
        expect(panels[1].find('.page-header').exists()).toBe(true);
    });

    it('skips transparent wrappers and opted-out cards', async () => {
        const { wrapper } = host(true, [card({ transparent: true }), card({ hostPageHeader: false }), card()]);
        await nextTick();

        const cards = wrapper.findAllComponents(SkCard);
        expect(cards[0].find('.page-header').exists()).toBe(false);
        expect(cards[1].find('.page-header').exists()).toBe(false);
        expect(cards[2].find('.page-header').exists()).toBe(true);
    });

    it('hands the claim past chrome cards to the real content card', async () => {
        // The vertical-tabs nav card, the avatar card and a form section all opt out —
        // otherwise a page's header would land in its navigation sidebar.
        const { wrapper } = host(true, [card({ hostPageHeader: false }), card({ title: 'İzinler' })]);
        await nextTick();

        const cards = wrapper.findAllComponents(SkCard);
        expect(cards[0].find('.page-header').exists()).toBe(false);
        expect(cards[1].find('.page-header').exists()).toBe(true);
    });

    it('still draws the header on a card whose head is fully overridden', async () => {
        // `#header` replaces the structured head, so the inline region that carries
        // the back button and page actions is never rendered there. The card must
        // fall back to its own header row instead of swallowing them.
        const wrapper = mount(
            defineComponent({
                setup() {
                    const candidates = ref<symbol[]>([]);
                    provide(SK_PAGE_HEADER_KEY, { candidates, enabled: ref(true), render: header });
                    return () =>
                        h(SkCard, { title: 'Sayfa' }, {
                            header: () => h('div', { class: 'custom-head' }, 'custom'),
                            default: () => 'body',
                        });
                },
            }),
        );
        await nextTick();

        expect(wrapper.find('.custom-head').exists()).toBe(true);
        expect(wrapper.find('.page-header').exists()).toBe(true);
    });

    it('releases the claim when the hosting card unmounts', async () => {
        const { wrapper, candidates } = host(true, [card()]);
        await nextTick();
        expect(candidates.value).toHaveLength(1);

        wrapper.unmount();
        expect(candidates.value).toHaveLength(0);
    });

    /**
     * The regression that made the header "land wherever it likes": switching a tab
     * (or an Inertia visit) runs the INCOMING card's setup while the outgoing card is
     * still mounted. With a single claim the newcomer found the slot taken and the
     * leaver then released it to nobody — so every other navigation dropped the
     * header back outside the card. Candidates queue instead of competing.
     */
    it('hands the header to the incoming card when the outgoing one leaves', async () => {
        const candidates = ref<symbol[]>([]);
        const outgoingMounted = ref(true);
        const wrapper = mount(
            defineComponent({
                setup() {
                    provide(SK_PAGE_HEADER_KEY, { candidates, enabled: ref(true), render: header });
                    return () =>
                        h('div', [
                            outgoingMounted.value ? card({ title: 'Genel' }) : null,
                            card({ title: 'Görünüm' }),
                        ]);
                },
            }),
        );
        await nextTick();
        expect(wrapper.findAllComponents(SkCard)[0].find('.page-header').exists()).toBe(true);

        outgoingMounted.value = false;
        await nextTick();

        const remaining = wrapper.findAllComponents(SkCard);
        expect(remaining).toHaveLength(1);
        expect(remaining[0].find('.sk-card__actions .page-header').exists()).toBe(true);
        expect(wrapper.findAll('.page-header')).toHaveLength(1);
        expect(candidates.value).toHaveLength(1);
    });

    it('gives the hosted header a head row of its own when the card has no title', async () => {
        const { wrapper } = host(true, [card()]);
        await nextTick();

        const heads = wrapper.findAll('.sk-card__head');
        expect(heads).toHaveLength(1);
        expect(heads[0].attributes('data-sk-page-header')).toBeDefined();
        expect(heads[0].find('.page-header').exists()).toBe(true);
    });

    /**
     * A card that has a title of its own keeps it: the header drops its own title
     * and subtitle (`hideTitle`) and joins that head row with just the back button
     * and the page actions. Stacking a page heading above the card's heading read as
     * a duplicated title on the settings screens.
     */
    it('folds the header into the card head row when the card has a title', async () => {
        const { wrapper } = host(true, [card({ title: 'İzinler' })]);
        await nextTick();

        const heads = wrapper.findAll('.sk-card__head');
        expect(heads).toHaveLength(1);
        expect(heads[0].find('.sk-card__title').text()).toBe('İzinler');
        expect(heads[0].find('.sk-card__actions .page-header').text()).toBe('Geri');
        expect(wrapper.findAll('.page-header')).toHaveLength(1);
    });

    /**
     * The regression this mechanism replaced a teleport for: on a vertical-`SkTabs`
     * page the panel card mounts only after the active tab resolves. A teleport had
     * already resolved its DOM target against a card-less DOM and cached `null`, so
     * the header stayed outside and the card showed an empty head row. Rendering
     * through the context has no target to resolve — a card that appears later just
     * claims and draws.
     */
    it('is picked up by a hosting card that mounts later (tab panel)', async () => {
        const candidates = ref<symbol[]>([]);
        const panelReady = ref(false);
        const wrapper = mount(
            defineComponent({
                setup() {
                    provide(SK_PAGE_HEADER_KEY, { candidates, enabled: ref(true), render: header });
                    onMounted(() => (panelReady.value = true));
                    return () => h('div', [panelReady.value ? card({ title: 'Güvenlik Ayarları' }) : null]);
                },
            }),
        );
        await nextTick();
        await nextTick();

        expect(wrapper.find('.sk-card__actions .page-header').exists()).toBe(true);
        expect(wrapper.findAll('.page-header')).toHaveLength(1);
    });
});
