import { describe, it, expect, vi } from 'vitest';
import { mount, shallowMount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import PrimeVue from 'primevue/config';
import { FB } from '../core';
import type { FormBuilderConfig, SectionFieldConfig, SelectFieldConfig } from '../core/types';
import SkFormInput from '../SkFormInput.vue';

/** SkCard stub that still renders `content`, mirroring SkForm.contract.spec.ts. */
const SkCardStub = defineComponent({
    name: 'SkCard',
    setup(_props, { slots }) {
        return () => h('div', [slots.content?.()]);
    },
});

// SkForm.vue needs these regardless of which contract is under test — same
// minimal mock set as SkForm.fileUpload.spec.ts / SkForm.contract.spec.ts.
const definitionOptionsByKey: Record<string, Array<{ label: string; value: unknown }>> = {
    userStatus: [{ label: 'Active', value: 'active' }],
};
vi.mock('@/composables/useApi', () => ({
    useApi: () => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }),
}));
vi.mock('@/composables/useDefinition', () => ({
    useDefinition: () => ({
        load: vi.fn(),
        options: (key: string) => definitionOptionsByKey[key] ?? [],
        find: () => undefined,
    }),
}));
vi.mock('@/composables/useCan', () => ({
    useCan: () => ({ can: () => true }),
}));
// SkFormInput pulls in useConfirm/useDialog for its file-preview/media actions;
// neither is exercised by the input-text icon fixtures below, but both inject
// PrimeVue services this test host never installs.
vi.mock('@/composables/useConfirm', () => ({
    useConfirm: () => ({ confirmDelete: vi.fn(), confirm: vi.fn() }),
}));
vi.mock('@/composables/useDialog', () => ({
    useDialog: () => ({ open: vi.fn(), close: vi.fn() }),
}));
vi.mock('primevue/usetoast', () => ({
    useToast: () => ({ add: vi.fn() }),
}));
vi.mock('laravel-vue-i18n', () => ({
    trans: (key: string) => key,
}));

const { default: SkForm } = await import('../SkForm.vue');

/**
 * Characterization/contract suite for the FB builder chain's PUBLIC defaults
 * and deprecated aliases — pre-refactor safety net (see
 * plan-docs/2026-08-29-formbuilder-geriye-uyumlu-sertlestirme.md). Task 3-5
 * must keep every assertion here green.
 */

describe('BaseFieldBuilder — required default', () => {
    it('.build() defaults `required` to true when not set', () => {
        const cfg = FB.inputText().key('name').label('Name').build();

        expect(cfg.required).toBe(true);
    });

    it('.optional() flips it to false', () => {
        const cfg = FB.inputText().key('name').label('Name').optional().build();

        expect(cfg.required).toBe(false);
    });
});

describe('FormBuilder — cols default', () => {
    it('.build() defaults `cols` to 2 when .cols() is never called', () => {
        const cfg = FB.form().build();

        expect(cfg.cols).toBe(2);
    });
});

describe('SectionBuilder — isCard default', () => {
    it('leaves `isCard` unset by default — SkFormFieldRenderer treats anything but `=== false` as a card', () => {
        const cfg = FB.section('General').build();

        expect(cfg.isCard).toBeUndefined();
    });

    it('.isCard(false) explicitly opts the section out of the card wrapper', () => {
        const cfg = FB.section('General').isCard(false).build() as SectionFieldConfig;

        expect(cfg.isCard).toBe(false);
    });
});

describe('SelectFieldBuilder — enumOptions() delegates to definitionOptions()', () => {
    it('sets the same `definitionKey`/`definitionFilter` as definitionOptions()', () => {
        const filter = (opt: { value: unknown }) => opt.value !== 'x';
        const cfg = FB.select().key('status').enumOptions('userStatus', filter).build() as SelectFieldConfig;

        expect(cfg.definitionKey).toBe('userStatus');
        expect(cfg.definitionFilter).toBe(filter);
        // Deprecated alias fields themselves stay untouched by the delegation.
        expect(cfg.enumKey).toBeUndefined();
        expect(cfg.enumFilter).toBeUndefined();
    });
});

describe('SelectFieldConfig — legacy `enumKey`/`enumFilter` still accepted at runtime', () => {
    it("SkForm's option resolution falls back to `enumKey` when `definitionKey` is absent", () => {
        // No dedicated builder chain method sets `enumKey` — it predates
        // `.enumOptions()`/`.definitionOptions()` and is only reachable by
        // hand-building the FieldConfig object, which legacy consumer code did.
        const field: SelectFieldConfig = {
            type: 'select',
            key: 'status',
            label: 'Status',
            required: true,
            enumKey: 'userStatus',
        };
        const config: FormBuilderConfig = { layout: 'vertical', cols: 2, isCard: true, fields: [field] };
        const wrapper = shallowMount(SkForm, {
            props: { config, modelValue: { status: null } },
            global: { mocks: { $t: (key: string) => key }, plugins: [PrimeVue], stubs: { SkCard: SkCardStub } },
        });

        const renderer = wrapper.findComponent({ name: 'SkFormFieldRenderer' });
        const ctx = renderer.props('ctx') as { getOptions: (f: unknown) => Array<{ value: unknown }> };

        expect(ctx.getOptions(field)).toEqual([{ label: 'Active', value: 'active' }]);
        wrapper.unmount();
    });
});

describe('InputTextBuilder — deprecated `icon`/`iconPosition` still render an icon', () => {
    it('renders SkIcon inside an IconField for a plain input-text field with .icon()', () => {
        const field = FB.inputText().key('search').label('Search').icon('pi pi-search').build();
        const wrapper = mount(SkFormInput, {
            props: { field, value: '' },
            global: { mocks: { $t: (key: string) => key }, plugins: [PrimeVue] },
        });

        const icon = wrapper.findComponent({ name: 'SkIcon' });
        expect(icon.exists()).toBe(true);
        expect(icon.props('icon')).toBe('pi pi-search');
    });

    it('.iconPosition("right") is honoured by the IconField wrapper', () => {
        const field = FB.inputText()
            .key('search')
            .label('Search')
            .icon('pi pi-search')
            .iconPosition('right')
            .build();
        const wrapper = mount(SkFormInput, {
            props: { field, value: '' },
            global: { mocks: { $t: (key: string) => key }, plugins: [PrimeVue] },
        });

        const iconField = wrapper.findComponent({ name: 'IconField' });
        expect(iconField.exists()).toBe(true);
        // IconField has no declared `iconPosition` prop of its own — the
        // template passes it through as a plain attribute, driving PrimeVue's
        // pass-through CSS positioning.
        expect(iconField.attributes('icon-position')).toBe('right');
    });
});
