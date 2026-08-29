import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import PrimeVue from 'primevue/config';
import { FB } from '../core';
import { controlId, describedById } from '../core/ids';
import type { FieldConfig, FormBuilderConfig } from '../core/types';

/**
 * Regression tests for SkFormInput's forced-disabled contract and the
 * wrapper-id / control-id / aria-describedby wiring `core/ids.ts` promises.
 *
 * Mount setup mirrors `FormBuilder.contract.spec.ts` (`mount(SkFormInput, ...)`
 * with the PrimeVue plugin, useConfirm/useDialog mocked so file-preview/media
 * composables never inject services this test host doesn't install).
 */

vi.mock('@/composables/useApi', () => ({
    useApi: () => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }),
}));
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

if (!window.matchMedia) {
    // jsdom lacks matchMedia; PrimeVue Select/DatePicker bind a media-query listener on mount.
    window.matchMedia = ((query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: () => {},
        removeListener: () => {},
        addEventListener: () => {},
        removeEventListener: () => {},
        dispatchEvent: () => false,
    })) as unknown as typeof window.matchMedia;
}

const { default: SkFormInput } = await import('../SkFormInput.vue');
const { default: SkForm } = await import('../SkForm.vue');
vi.mock('@/composables/useDefinition', () => ({
    useDefinition: () => ({ load: vi.fn(), options: () => [], find: () => undefined }),
}));
vi.mock('@/composables/useCan', () => ({
    useCan: () => ({ can: () => true }),
}));

const globalOpts = { global: { mocks: { $t: (key: string) => key }, plugins: [PrimeVue] } };

/** SkCard stub that still renders `content`, mirroring SkForm.contract.spec.ts. */
const SkCardStub = defineComponent({
    name: 'SkCard',
    setup(_props, { slots }) {
        return () => h('div', slots.content?.());
    },
});

describe('SkFormInput — forcedDisabled contract', () => {
    it('componentProps.disabled: false cannot unlock a field the `disabled` prop forces off', () => {
        const field = FB.inputNumber().key('qty').label('Qty').props({ disabled: false }).build();
        const wrapper = mount(SkFormInput, {
            props: { field, value: 1, disabled: true },
            ...globalOpts,
        });

        expect((wrapper.get('input').element as HTMLInputElement).disabled).toBe(true);
    });

    it('componentProps.disabled: true still disables even when the `disabled` prop is false', () => {
        const field = FB.inputNumber().key('qty').label('Qty').props({ disabled: true }).build();
        const wrapper = mount(SkFormInput, {
            props: { field, value: 1, disabled: false },
            ...globalOpts,
        });

        expect((wrapper.get('input').element as HTMLInputElement).disabled).toBe(true);
    });

    it('neither prop nor componentProps set disabled → field stays enabled', () => {
        const field = FB.inputNumber().key('qty').label('Qty').build();
        const wrapper = mount(SkFormInput, {
            props: { field, value: 1, disabled: false },
            ...globalOpts,
        });

        expect((wrapper.get('input').element as HTMLInputElement).disabled).toBe(false);
    });
});

describe('SkFormInput / SkFormFieldRenderer — control-id contract', () => {
    /**
     * For every WRAPPER_CONTROL_TYPE the label targets `${key}__control`
     * (the focusable inner control via PrimeVue's `inputId`), while the
     * outer PrimeVue component keeps the plain field key as its own `id` —
     * both ids must be present and distinct.
     */
    const cases: Array<[string, () => FieldConfig]> = [
        ['input-number', () => FB.inputNumber().key('qty').label('Qty').build()],
        ['date-picker', () => FB.datePicker().key('when').label('When').build()],
        ['select', () => FB.select().key('role').label('Role').options([{ label: 'Admin', value: 'admin' }]).build()],
        [
            'multiselect',
            () => FB.multiselect().key('roles').label('Roles').options([{ label: 'Admin', value: 'admin' }]).build(),
        ],
        ['password', () => FB.password().key('pwd').label('Password').feedback().build()],
        ['toggle-switch', () => FB.toggleSwitch().key('active').label('Active').build()],
    ];

    it.each(cases)('%s: label[for] targets controlId, wrapper keeps the field key as its id', (_type, buildField) => {
        const field = buildField();
        const config: FormBuilderConfig = { ...FB.form().build(), fields: [field] };
        const wrapper = mount(SkForm, {
            props: { config },
            global: { mocks: { $t: (key: string) => key }, plugins: [PrimeVue], stubs: { SkCard: SkCardStub } },
        });

        const label = wrapper.find(`label[for="${controlId(field)}"]`);
        expect(label.exists()).toBe(true);
        expect(controlId(field)).toBe(`${field.key}__control`);
        expect(wrapper.find(`#${field.key}`).exists()).toBe(true);

        wrapper.unmount();
    });
});

describe('SkFormInput / SkFormFieldRenderer — aria-describedby contract', () => {
    it('a rendered validation error carries the id the control points at via aria-describedby', () => {
        const field = FB.inputText().key('email').label('Email').build();
        const config: FormBuilderConfig = { ...FB.form().build(), fields: [field] };
        const wrapper = mount(SkForm, {
            props: { config, modelValue: { email: '' }, errors: { email: 'Required' } },
            global: { mocks: { $t: (key: string) => key }, plugins: [PrimeVue], stubs: { SkCard: SkCardStub } },
        });

        const errorEl = wrapper.find(`#${describedById(field)}`);
        expect(errorEl.exists()).toBe(true);
        expect(errorEl.text()).toBe('Required');
        expect(wrapper.get('input#email').attributes('aria-describedby')).toBe(describedById(field));
    });
});
