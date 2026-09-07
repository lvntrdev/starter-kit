import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h, nextTick, provide, ref } from 'vue';

import SkCard from '../SkCard.vue';
import { SK_PAGE_HEADER_KEY } from '../pageHeader';
import { usePageHeaderHost } from '../usePageHeaderHost';

/**
 * `SkDatatable` shape: a titled toolbar stands in for the page heading, so the table
 * hosts the page header itself (back button + page actions, `hideTitle`) and opts its
 * wrapping card out — otherwise the card would stack a full page header above the
 * toolbar's own title, which is the duplicate heading the settings screens showed.
 */
const Table = defineComponent({
    props: { tableTitle: { type: String, default: '' } },
    setup(props) {
        const { hostsPageHeader, hostedPageHeader } = usePageHeaderHost(
            () => !!props.tableTitle,
            () => true,
        );

        return () =>
            h(SkCard, { hostPageHeader: !props.tableTitle }, {
                default: () =>
                    h('div', { class: 'toolbar' }, [
                        props.tableTitle ? h('span', { class: 'toolbar-title' }, props.tableTitle) : null,
                        hostsPageHeader.value ? h(hostedPageHeader.value!) : null,
                    ]),
            });
    },
});

function mountTable(tableTitle: string) {
    const candidates = ref<symbol[]>([]);
    const render = (options: { hideTitle?: boolean } = {}) =>
        h('div', { class: 'page-header' }, options.hideTitle ? 'actions-only' : 'Ayarlar');

    const wrapper = mount(
        defineComponent({
            setup() {
                provide(SK_PAGE_HEADER_KEY, { candidates, enabled: ref(true), render });

                return () => h(Table, { tableTitle });
            },
        }),
    );

    return { wrapper, candidates };
}

describe('usePageHeaderHost — datatable toolbar hosting', () => {
    it('draws the header in the titled toolbar, not in the wrapping card head', async () => {
        const { wrapper, candidates } = mountTable('API Tokenleri');
        await nextTick();

        expect(candidates.value).toHaveLength(1);
        expect(wrapper.find('.toolbar .page-header').text()).toBe('actions-only');
        expect(wrapper.find('.sk-card__head').exists()).toBe(false);
        expect(wrapper.findAll('.page-header')).toHaveLength(1);
    });

    it('leaves hosting to the card when the toolbar has no title', async () => {
        const { wrapper } = mountTable('');
        await nextTick();

        expect(wrapper.find('.toolbar .page-header').exists()).toBe(false);
        expect(wrapper.find('.sk-card__head--page .page-header').text()).toBe('Ayarlar');
    });

    it('releases the toolbar claim on unmount', async () => {
        const { wrapper, candidates } = mountTable('API İstemcileri');
        await nextTick();
        expect(candidates.value).toHaveLength(1);

        wrapper.unmount();
        expect(candidates.value).toHaveLength(0);
    });
});
