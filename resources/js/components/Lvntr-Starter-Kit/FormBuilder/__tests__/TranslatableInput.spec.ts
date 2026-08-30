import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h, nextTick } from 'vue';
import PrimeVue from 'primevue/config';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';

import TranslatableInput from '../inputs/TranslatableInput.vue';
import { FB } from '../core';
import { resolveContentLocales } from '../core/locales';
import type { TranslatableTextFieldConfig } from '../core/types';

// ── Mocks ────────────────────────────────────────────────────────────────────

// jsdom has no ResizeObserver; PrimeVue TabList binds one for the ink bar.
class ResizeObserverStub {
    observe() {}
    unobserve() {}
    disconnect() {}
}
// eslint-disable-next-line @typescript-eslint/no-explicit-any
globalThis.ResizeObserver = ResizeObserverStub as any;

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({
        props: {
            availableLocales: { tr: 'Türkçe', en: 'English' },
        },
    }),
}));

// SkForm's own composable surface, mocked the same way as SkForm.fileUpload.spec.ts —
// only needed by the "same page props" contract test below, which mounts SkForm
// alongside TranslatableInput.
vi.mock('@/composables/useApi', () => ({
    useApi: () => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }),
}));
vi.mock('@/composables/useDefinition', () => ({
    useDefinition: () => ({ load: vi.fn(), options: () => [], find: () => undefined }),
}));
vi.mock('@/composables/useCan', () => ({
    useCan: () => ({ can: () => true }),
}));
vi.mock('primevue/usetoast', () => ({
    useToast: () => ({ add: vi.fn() }),
}));
vi.mock('laravel-vue-i18n', () => ({
    trans: (key: string) => key,
}));

const { default: SkForm } = await import('../SkForm.vue');

// Mock EditorInput (heavy tiptap dependency)
vi.mock('../inputs/EditorInput.vue', () => ({
    default: defineComponent({
        name: 'EditorInput',
        props: ['modelValue', 'minHeight', 'toolbar', 'disabled', 'invalid'],
        emits: ['update:modelValue'],
        setup(props, { emit }) {
            return () =>
                h('div', { class: 'mock-editor-input', 'data-testid': 'editor-input' }, [
                    h('textarea', {
                        value: props.modelValue ?? '',
                        'data-testid': 'editor-textarea',
                        onInput: (e: Event) => emit('update:modelValue', (e.target as HTMLTextAreaElement).value),
                    }),
                ]);
        },
    }),
}));

// ── Global config ────────────────────────────────────────────────────────────

const globalConfig = {
    plugins: [PrimeVue],
    components: {
        InputText,
        Textarea,
        Tabs,
        TabList,
        Tab,
        TabPanels,
        TabPanel,
    },
};

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeTextField(overrides: Partial<TranslatableTextFieldConfig> = {}): TranslatableTextFieldConfig {
    return {
        type: 'translatable-text',
        key: 'name',
        label: 'Name',
        ...overrides,
    };
}

/** Inputs that are actually visible (active tab panel only). */
function visibleInputs(wrapper: ReturnType<typeof mount>) {
    return wrapper.findAll('input').filter((i) => (i.element as HTMLElement).offsetParent !== undefined);
}

// ── Tests: single locale ─────────────────────────────────────────────────────

describe('TranslatableInput — single locale', () => {
    it('renders a plain input without tabs in single-locale mode', () => {
        const field = makeTextField({ onlyLocales: ['tr'] });
        const wrapper = mount(TranslatableInput, {
            props: { field, modelValue: { tr: 'Test' } },
            global: globalConfig,
        });

        expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
        expect(wrapper.find('.sk-translatable-field--single').exists()).toBe(true);
    });

    it('renders one input in single-locale mode', () => {
        const field = makeTextField({ onlyLocales: ['tr'] });
        const wrapper = mount(TranslatableInput, {
            props: { field, modelValue: { tr: 'Value' } },
            global: globalConfig,
        });

        const inputs = wrapper.findAll('input');
        expect(inputs).toHaveLength(1);
    });
});

// ── Tests: multiple locales (tabs design) ─────────────────────────────────────

describe('TranslatableInput — multiple locales (tabs)', () => {
    it('renders the tabs design for two locales', () => {
        const field = makeTextField();
        const wrapper = mount(TranslatableInput, {
            props: { field, modelValue: { tr: '', en: '' } },
            global: globalConfig,
        });

        expect(wrapper.find('[role="tablist"]').exists()).toBe(true);
        expect(wrapper.findAll('button[role="tab"]')).toHaveLength(2);
    });

    it('renders locale badge text TR and EN as tab headers', () => {
        const field = makeTextField();
        const wrapper = mount(TranslatableInput, {
            props: { field, modelValue: { tr: '', en: '' } },
            global: globalConfig,
        });

        const tabTexts = wrapper.findAll('button[role="tab"]').map((t) => t.text());
        expect(tabTexts.some((t) => t.includes('TR'))).toBe(true);
        expect(tabTexts.some((t) => t.includes('EN'))).toBe(true);
    });

    it('shows the first locale panel as active initially', () => {
        const field = makeTextField();
        const wrapper = mount(TranslatableInput, {
            props: { field, modelValue: { tr: 'Elma', en: 'Apple' } },
            global: globalConfig,
        });

        const inputs = visibleInputs(wrapper);
        expect(inputs.length).toBeGreaterThanOrEqual(1);
        expect((inputs[0].element as HTMLInputElement).value).toBe('Elma');
    });
});

// ── Tests: locale filters ─────────────────────────────────────────────────────

describe('TranslatableInput — locale filter options', () => {
    it('onlyLocales with one locale → single mode (no tabs)', () => {
        const field = makeTextField({ onlyLocales: ['tr'] });
        const wrapper = mount(TranslatableInput, {
            props: { field, modelValue: { tr: '', en: '' } },
            global: globalConfig,
        });

        expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
        expect(wrapper.findAll('input')).toHaveLength(1);
    });

    it('exceptLocales removes locale leaving one → single mode', () => {
        const field = makeTextField({ exceptLocales: ['en'] });
        const wrapper = mount(TranslatableInput, {
            props: { field, modelValue: { tr: '', en: '' } },
            global: globalConfig,
        });

        expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
        expect(wrapper.findAll('input')).toHaveLength(1);
    });
});

// ── Tests: per-locale errors ──────────────────────────────────────────────────

describe('TranslatableInput — per-locale error display', () => {
    it('shows error message for the active tr locale', () => {
        const field = makeTextField();
        const wrapper = mount(TranslatableInput, {
            props: {
                field,
                modelValue: { tr: '', en: '' },
                errors: { 'name.tr': 'Bu alan zorunludur.' },
            },
            global: globalConfig,
        });

        const errors = wrapper.findAll('small.text-red-500');
        expect(errors.length).toBeGreaterThanOrEqual(1);

        const texts = errors.map((e) => e.text());
        expect(texts.some((t) => t.includes('Bu alan zorunludur.'))).toBe(true);
    });

    it('marks the erroring locale tab with a warning icon', () => {
        const field = makeTextField();
        const wrapper = mount(TranslatableInput, {
            props: {
                field,
                modelValue: { tr: '', en: '' },
                errors: { 'name.tr': 'Hata!' },
            },
            global: globalConfig,
        });

        expect(wrapper.find('button[role="tab"] .pi-exclamation-circle').exists()).toBe(true);
    });

    it('shows no errors when errors object is empty', () => {
        const field = makeTextField();
        const wrapper = mount(TranslatableInput, {
            props: {
                field,
                modelValue: { tr: 'Val', en: 'Val' },
                errors: {},
            },
            global: globalConfig,
        });

        const errors = wrapper.findAll('small.text-red-500');
        expect(errors).toHaveLength(0);
    });
});

// ── Tests: emit on update ─────────────────────────────────────────────────────

describe('TranslatableInput — emit on user input', () => {
    it('emits update event with merged payload when tr input changes', async () => {
        const field = makeTextField();
        const wrapper = mount(TranslatableInput, {
            props: { field, modelValue: { tr: '', en: 'Apple' } },
            global: globalConfig,
        });

        const inputs = visibleInputs(wrapper);
        expect(inputs.length).toBeGreaterThanOrEqual(1);

        await inputs[0].setValue('Elma');

        const emitted = wrapper.emitted();
        const hasAnyUpdate = 'update' in emitted || 'update:modelValue' in emitted;
        expect(hasAnyUpdate).toBe(true);

        const payloads = (emitted['update'] ?? emitted['update:modelValue'] ?? []) as Array<[Record<string, string>]>;
        expect(payloads.length).toBeGreaterThanOrEqual(1);

        const last = payloads[payloads.length - 1][0];
        expect(last).toMatchObject({ tr: 'Elma', en: 'Apple' });
    });
});

// ── Tests: unresolved-locale preservation (data-loss regression) ──────────────

describe('TranslatableInput — preserves locales outside resolvedLocales', () => {
    it('onlyLocales keeps translations of the excluded locales on emit', async () => {
        // resolvedLocales = [tr]; `en` is present in modelValue but filtered out.
        const field = makeTextField({ onlyLocales: ['tr'] });
        const wrapper = mount(TranslatableInput, {
            props: { field, modelValue: { tr: 'Elma', en: 'Apple' } },
            global: globalConfig,
        });

        const inputs = wrapper.findAll('input');
        expect(inputs).toHaveLength(1);

        await inputs[0].setValue('Armut');

        const payloads = (wrapper.emitted('update:modelValue') ?? []) as Array<[Record<string, string>]>;
        expect(payloads.length).toBeGreaterThanOrEqual(1);

        const last = payloads[payloads.length - 1][0];
        // tr updated, en (filtered out of the UI) preserved — not dropped.
        expect(last).toEqual({ tr: 'Armut', en: 'Apple' });
    });

    it('exceptLocales keeps the removed locale translation on emit', async () => {
        // resolvedLocales = [tr]; `en` is removed from the UI via exceptLocales.
        const field = makeTextField({ exceptLocales: ['en'] });
        const wrapper = mount(TranslatableInput, {
            props: { field, modelValue: { tr: 'Elma', en: 'Apple' } },
            global: globalConfig,
        });

        const inputs = wrapper.findAll('input');
        expect(inputs).toHaveLength(1);

        await inputs[0].setValue('Armut');

        const payloads = (wrapper.emitted('update:modelValue') ?? []) as Array<[Record<string, string>]>;
        const last = payloads[payloads.length - 1][0];
        expect(last).toEqual({ tr: 'Armut', en: 'Apple' });
    });

    it('preserves a content locale absent from availableLocales', async () => {
        // `de` is not in availableLocales (mock: tr/en) so it never resolves,
        // yet the stored translation must survive an edit to a visible locale.
        const field = makeTextField();
        const wrapper = mount(TranslatableInput, {
            props: { field, modelValue: { tr: '', en: 'Apple', de: 'Apfel' } },
            global: globalConfig,
        });

        const inputs = visibleInputs(wrapper);
        await inputs[0].setValue('Elma');

        const payloads = (wrapper.emitted('update:modelValue') ?? []) as Array<[Record<string, string>]>;
        const last = payloads[payloads.length - 1][0];
        expect(last).toMatchObject({ tr: 'Elma', en: 'Apple', de: 'Apfel' });
        // The out-of-band locale is still present.
        expect(last.de).toBe('Apfel');
    });

    it('sequential edits across visible locales preserve the hidden locale', async () => {
        // Simulate the v-model round-trip: parent writes the emitted value back.
        const field = makeTextField({ exceptLocales: ['en'] });
        const wrapper = mount(TranslatableInput, {
            props: { field, modelValue: { tr: 'A', en: 'KEEP' } as Record<string, string> },
            global: globalConfig,
        });

        const input = () => wrapper.findAll('input')[0];
        await input().setValue('A2');

        const first = (wrapper.emitted('update:modelValue') ?? []) as Array<[Record<string, string>]>;
        // Parent propagates emitted value back into modelValue.
        await wrapper.setProps({ modelValue: first[first.length - 1][0] });
        await nextTick();

        await input().setValue('A3');
        const payloads = (wrapper.emitted('update:modelValue') ?? []) as Array<[Record<string, string>]>;
        const last = payloads[payloads.length - 1][0];
        expect(last).toEqual({ tr: 'A3', en: 'KEEP' });
    });
});

// ── Tests: value normalization ────────────────────────────────────────────────

describe('TranslatableInput — initial value normalization', () => {
    it('mounts without errors when modelValue is null', () => {
        const field = makeTextField();
        const wrapper = mount(TranslatableInput, {
            props: { field, modelValue: null },
            global: globalConfig,
        });
        expect(wrapper.exists()).toBe(true);
    });

    it('mounts without errors when modelValue is undefined', () => {
        const field = makeTextField();
        const wrapper = mount(TranslatableInput, {
            props: { field, modelValue: undefined },
            global: globalConfig,
        });
        expect(wrapper.exists()).toBe(true);
    });

    it('initializes missing locale keys to empty string', async () => {
        const field = makeTextField();
        const wrapper = mount(TranslatableInput, {
            props: { field, modelValue: { tr: 'Elma' } }, // en missing
            global: globalConfig,
        });

        // Switch to the EN tab — its input must exist and be empty
        const tabs = wrapper.findAll('button[role="tab"]');
        await tabs[1].trigger('click');
        await nextTick();

        const inputs = wrapper.findAll('input');
        const enInput = inputs[inputs.length - 1];
        expect((enInput.element as HTMLInputElement).value).toBe('');
    });
});

// ── Tests: resolveContentLocales (shared helper, pure function) ───────────────

describe('resolveContentLocales', () => {
    it('prefers availableContentLocales over availableLocales when both are present', () => {
        const result = resolveContentLocales({
            availableContentLocales: { tr: 'Türkçe' },
            availableLocales: { tr: 'Türkçe', en: 'English' },
        });

        expect(result).toEqual([{ code: 'tr', name: 'Türkçe' }]);
    });

    it('falls back to availableLocales when availableContentLocales is absent', () => {
        const result = resolveContentLocales({ availableLocales: { en: 'English' } });

        expect(result).toEqual([{ code: 'en', name: 'English' }]);
    });

    it('falls back to availableLocales when availableContentLocales is an empty object', () => {
        const result = resolveContentLocales({
            availableContentLocales: {},
            availableLocales: { en: 'English' },
        });

        expect(result).toEqual([{ code: 'en', name: 'English' }]);
    });
});

// ── Tests: SkForm + TranslatableInput agree on the locale set ─────────────────

describe('SkForm and TranslatableInput resolve the SAME locale set from identical page props', () => {
    it('a translatable field default carries exactly the codes TranslatableInput renders as tabs', async () => {
        // Both read `usePage().props` through `core/locales` — this mock (tr/en,
        // no availableContentLocales) is the SAME one every test above uses.
        const field = FB.translatableText().key('title').label('Title').build();
        const formConfig = {
            ...FB.form().submit({ url: '/api/form', method: 'put' }).build(),
            fields: [field],
        };

        const formWrapper = mount(SkForm, {
            props: { config: formConfig },
            global: { mocks: { $t: (key: string) => key }, stubs: { SkFormFieldRenderer: true, SkCard: true } },
        });
        const defaultKeys = Object.keys(formWrapper.vm.currentValues.title as Record<string, string>).sort();
        formWrapper.unmount();

        const inputWrapper = mount(TranslatableInput, {
            props: { field, modelValue: {} },
            global: globalConfig,
        });
        const tabTexts = inputWrapper.findAll('button[role="tab"]').map((t) => t.text().toUpperCase());

        expect(defaultKeys).toEqual(['en', 'tr']);
        expect(tabTexts).toHaveLength(defaultKeys.length);
        for (const key of defaultKeys) {
            expect(tabTexts.some((t) => t.includes(key.toUpperCase()))).toBe(true);
        }
    });
});

// ── Tests: accessibility (label ↔ input association, required semantics) ─────

describe('TranslatableInput — accessibility', () => {
    it('associates the rendered label with the input in single-locale mode', () => {
        const field = makeTextField({ onlyLocales: ['tr'], required: true });
        const wrapper = mount(TranslatableInput, {
            props: { field, modelValue: { tr: '' }, showLabel: true },
            global: globalConfig,
        });

        const input = wrapper.find('input');
        expect(wrapper.find('label').attributes('for')).toBe('name');
        expect(input.attributes('id')).toBe('name');
        expect(input.attributes('aria-required')).toBe('true');
        expect(wrapper.find('.sk-fb__required').attributes('aria-hidden')).toBe('true');
        expect(wrapper.find('.sr-only').text()).toBe('sk-common.required');
    });

    it('keeps the label pointing at the active locale input in multi-locale mode', async () => {
        const field = makeTextField({ required: true });
        const wrapper = mount(TranslatableInput, {
            props: { field, modelValue: { tr: '', en: '' }, showLabel: true },
            global: globalConfig,
        });

        expect(wrapper.find('label').attributes('for')).toBe('name');
        expect(wrapper.find('input').attributes('id')).toBe('name');
        // Only the DEFAULT locale is `required` server-side (HasTranslatableRules),
        // and the rendered content locales carry no signal for which one that is —
        // so no locale tab's input is announced as required.
        expect(wrapper.find('input').attributes('aria-required')).toBeUndefined();
        expect(wrapper.find('.sr-only').exists()).toBe(false);
        expect(wrapper.find('.sk-fb__required').attributes('aria-hidden')).toBe('true');

        await wrapper.findAll('button[role="tab"]')[1].trigger('click');
        await nextTick();

        // Only the active locale's input is rendered, so the id stays unique.
        expect(wrapper.findAll('input')).toHaveLength(1);
        expect(wrapper.find('input').attributes('id')).toBe('name');
    });

    it('names a translatable editor through aria-labelledby instead of a dead label[for]', () => {
        const field = { ...makeTextField({ onlyLocales: ['tr'], required: true }), type: 'translatable-editor' };
        const wrapper = mount(TranslatableInput, {
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            props: { field: field as any, modelValue: { tr: '' }, showLabel: true },
            global: globalConfig,
        });

        const label = wrapper.find('label');
        const editor = wrapper.find('[data-testid="editor-input"]');

        // A contenteditable is not labelable, so the label drops `for` and names
        // the editor by id; EditorInput forwards both onto the editable node.
        expect(label.attributes('id')).toBe('name__label');
        expect(label.attributes('for')).toBeUndefined();
        expect(editor.attributes('id')).toBe('name');
        expect(editor.attributes('aria-labelledby')).toBe('name__label');
        expect(editor.attributes('aria-required')).toBe('true');
    });

    it('does not mark an optional field as required', () => {
        const field = makeTextField({ onlyLocales: ['tr'] });
        const wrapper = mount(TranslatableInput, {
            props: { field, modelValue: { tr: '' }, showLabel: true },
            global: globalConfig,
        });

        expect(wrapper.find('input').attributes('aria-required')).toBeUndefined();
        expect(wrapper.find('.sk-fb__required').exists()).toBe(false);
        expect(wrapper.find('.sr-only').exists()).toBe(false);
    });
});
