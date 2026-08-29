import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount, shallowMount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { FB } from '../core';
import type { FormBuilderConfig } from '../core';

/**
 * Characterization/contract suite for SkForm's PUBLIC surface — props, emits,
 * `defineExpose`, slots + their scopes, and the handful of load-bearing DOM
 * class markers other components/tests key off. This is a pre-refactor safety
 * net (see plan-docs/2026-08-29-formbuilder-geriye-uyumlu-sertlestirme.md):
 * Task 3-5 must keep every assertion here green.
 *
 * Mount/mock setup mirrors `SkForm.fileUpload.spec.ts` — real `useForm`, only
 * the transport (`@inertiajs/core` router) and a handful of composables mocked.
 */

const apiGet = vi.fn();
const toastAdd = vi.fn();

vi.mock('@/composables/useApi', () => ({
    useApi: () => ({ get: apiGet, post: vi.fn(), put: vi.fn(), delete: vi.fn() }),
}));
vi.mock('@/composables/useDefinition', () => ({
    useDefinition: () => ({ load: vi.fn(), options: () => [], find: () => undefined }),
}));
vi.mock('@/composables/useCan', () => ({
    useCan: () => ({ can: () => true }),
}));
vi.mock('primevue/usetoast', () => ({
    useToast: () => ({ add: toastAdd }),
}));
vi.mock('laravel-vue-i18n', () => ({
    trans: (key: string) => key,
}));

// Same controlled transport as SkForm.fileUpload.spec.ts: runs the real
// `useForm` onSuccess/onFinish lifecycle without a real network call.
vi.mock('@inertiajs/core', async (importOriginal) => {
    const actual = await importOriginal<Record<string, unknown>>();

    interface VisitOptions {
        onBefore?: (visit: unknown) => unknown;
        onStart?: (visit: unknown) => unknown;
        onSuccess?: (page: unknown) => unknown;
        onFinish?: (visit: unknown) => unknown;
    }

    async function visit(_url: string, _data?: unknown, options: VisitOptions = {}): Promise<void> {
        options.onBefore?.({});
        options.onStart?.({});
        await Promise.resolve();
        await options.onSuccess?.({ props: {} });
        options.onFinish?.({});
    }

    return {
        ...actual,
        router: {
            ...(actual.router as Record<string, unknown>),
            get: visit,
            post: visit,
            put: visit,
            patch: visit,
            delete: (url: string, options?: VisitOptions) => visit(url, undefined, options),
            on: () => () => {},
        },
    };
});

// `usePage()` sits inside the translatable-locales helper only — none of these
// fixtures use a translatable-* field, so it is never evaluated and stays
// unmocked (as in SkForm.fileUpload.spec.ts).

const { default: SkForm } = await import('../SkForm.vue');

/** SkCard stub that still renders `content`, so shallow-mounted fixtures render the form body. */
const SkCardStub = defineComponent({
    name: 'SkCard',
    setup(_props, { slots }) {
        return () => h('div', [slots.title?.(), slots['title-end']?.(), slots.content?.()]);
    },
});

const mountedWrappers: Array<{ unmount: () => void }> = [];

afterEach(() => {
    for (const wrapper of mountedWrappers.splice(0)) {
        wrapper.unmount();
    }
});

beforeEach(() => {
    apiGet.mockReset();
    toastAdd.mockClear();
});

interface MountOpts {
    props?: Record<string, unknown>;
    slots?: Record<string, unknown>;
}

function shallow(config: FormBuilderConfig, opts: MountOpts = {}) {
    const wrapper = shallowMount(SkForm, {
        props: { config, ...opts.props },
        slots: opts.slots,
        global: { mocks: { $t: (key: string) => key }, stubs: { SkCard: SkCardStub } },
    });
    mountedWrappers.push(wrapper);
    return wrapper;
}

/**
 * Full mount (SkFormFieldRenderer + real SkCard rendered), only SkFormInput
 * stubbed out — that is where the load-bearing markers below actually live.
 */
function full(config: FormBuilderConfig, opts: MountOpts = {}) {
    const wrapper = mount(SkForm, {
        props: { config, ...opts.props },
        slots: opts.slots,
        global: { mocks: { $t: (key: string) => key }, stubs: { SkFormInput: true } },
    });
    mountedWrappers.push(wrapper);
    return wrapper;
}

// ── Props ───────────────────────────────────────────────────────────────────

describe('SkForm — props contract', () => {
    it('accepts `config` (required) and `errors` (optional, external-mode only)', () => {
        const config = FB.form().addFields(FB.inputText().key('name').label('Name')).build();
        const wrapper = shallow(config, { props: { errors: { name: 'Required' } } });

        expect(wrapper.props('config')).toStrictEqual(config);
        expect(wrapper.props('errors')).toEqual({ name: 'Required' });
    });
});

// ── v-model (external mode) ──────────────────────────────────────────────────

describe('SkForm — v-model external mode (config.submit absent)', () => {
    it('round-trips: modelValue seeds currentValues, setValue emits update:modelValue', async () => {
        const config = FB.form().addFields(FB.inputText().key('name').label('Name')).build();
        const wrapper = shallow(config, { props: { modelValue: { name: 'Ada' } } });

        expect(wrapper.vm.currentValues).toEqual({ name: 'Ada' });

        wrapper.vm.setValue('name', 'Grace');

        expect(wrapper.emitted('update:modelValue')).toBeTruthy();
        expect(wrapper.emitted('update:modelValue')![0][0]).toEqual({ name: 'Grace' });
    });
});

// ── Emits ─────────────────────────────────────────────────────────────────────

describe('SkForm — emits contract', () => {
    it('fires `success` BEFORE the dirty rebaseline (internal mode)', async () => {
        const config = FB.form()
            .initialData({ name: 'Before' })
            .submit({ url: '/api/form', method: 'put' })
            .addFields(FB.inputText().key('name').label('Name'))
            .build();
        let dirtyAtSuccessTime: boolean | undefined;
        const wrapper = shallow(config, {
            props: {
                onSuccess: () => {
                    dirtyAtSuccessTime = wrapper.vm.isDirty;
                },
            },
        });

        wrapper.vm.setValue('name', 'After');
        wrapper.vm.submit();
        await flushPromises();

        expect(wrapper.emitted('success')).toHaveLength(1);
        // Still dirty at the instant `success` fires — the rebaseline runs after,
        // asynchronously (see SkForm.vue's onSuccess comment on ordering).
        expect(dirtyAtSuccessTime).toBe(true);
        expect(wrapper.vm.isDirty).toBe(false);
    });

    it('`cancel` fires when onCancel is "emit" (dialog-mode default) instead of navigating back', async () => {
        const backSpy = vi.spyOn(window.history, 'back').mockImplementation(() => {});
        const config = FB.form()
            .inDialog()
            .submit({ url: '/api/form', method: 'post' })
            .addFields(FB.inputText().key('name').label('Name'))
            .build();
        const wrapper = full(config);

        await wrapper.find('button').trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(1);
        expect(backSpy).not.toHaveBeenCalled();
        backSpy.mockRestore();
    });

    it('onCancel defaults to "back" (history.back()) outside dialog mode', async () => {
        const backSpy = vi.spyOn(window.history, 'back').mockImplementation(() => {});
        const config = FB.form()
            .showBack()
            .submit({ url: '/api/form', method: 'post' })
            .addFields(FB.inputText().key('name').label('Name'))
            .build();
        const wrapper = full(config);

        await wrapper.find('button').trigger('click');

        expect(backSpy).toHaveBeenCalledTimes(1);
        expect(wrapper.emitted('cancel')).toBeFalsy();
        backSpy.mockRestore();
    });
});

// ── defineExpose ──────────────────────────────────────────────────────────────

describe('SkForm — defineExpose contract', () => {
    it('exposes exactly {reset, submit, processing, isDirty, dataLoading, remoteData, currentValues, setValue}', () => {
        const config = FB.form().addFields(FB.inputText().key('name').label('Name')).build();
        const wrapper = shallow(config);

        expect(typeof wrapper.vm.reset).toBe('function');
        expect(typeof wrapper.vm.submit).toBe('function');
        expect(typeof wrapper.vm.setValue).toBe('function');
        expect(typeof wrapper.vm.processing).toBe('boolean');
        expect(typeof wrapper.vm.isDirty).toBe('boolean');
        expect(typeof wrapper.vm.dataLoading).toBe('boolean');
        expect(wrapper.vm.remoteData === null || typeof wrapper.vm.remoteData === 'object').toBe(true);
        expect(typeof wrapper.vm.currentValues).toBe('object');
    });
});

// ── Slots + scopes ────────────────────────────────────────────────────────────

describe('SkForm — slots contract', () => {
    it('renders title-end, actions-start, actions, actions-end', () => {
        // hasActionArea only turns true in internal mode (or when an actions
        // slot is registered) — drive it through internal mode so the whole
        // actions row (and SkCard's title-end passthrough) mounts.
        const internalConfig = FB.form()
            .submit({ url: '/api/form', method: 'post' })
            .addFields(FB.inputText().key('name').label('Name'))
            .build();
        const w = shallow(internalConfig, {
            slots: {
                'title-end': '<span class="title-end-probe" />',
                'actions-start': '<span class="actions-start-probe" />',
                actions: '<span class="actions-probe" />',
                'actions-end': '<span class="actions-end-probe" />',
            },
        });

        expect(w.find('.title-end-probe').exists()).toBe(true);
        expect(w.find('.actions-start-probe').exists()).toBe(true);
        expect(w.find('.actions-probe').exists()).toBe(true);
        expect(w.find('.actions-end-probe').exists()).toBe(true);
    });

    it('the per-field named slot (FB.slot()) receives a `values` scope', () => {
        const config = FB.form()
            .addFields(FB.slot().key('custom_block'))
            .build();
        const wrapper = full(config, {
            props: { modelValue: { custom_block: undefined, other: 'x' } },
            slots: {
                custom_block: (slotData: { values?: Record<string, unknown> }) =>
                    h('span', { class: 'slot-probe' }, JSON.stringify(slotData.values)),
            },
        });

        const probe = wrapper.find('.slot-probe');
        expect(probe.exists()).toBe(true);
        expect(probe.text()).toContain('other');
    });

    it("a section's title-end slot receives a `values` scope, keyed section-<key>-title-end", () => {
        const config = FB.form()
            .addFields(
                FB.section('General')
                    .key('general')
                    .addFields(FB.inputText().key('name').label('Name')),
            )
            .build();
        const wrapper = full(config, {
            props: { modelValue: { name: 'Ada' } },
            slots: {
                'section-general-title-end': (slotData: { values?: Record<string, unknown> }) =>
                    h('span', { class: 'section-slot-probe' }, JSON.stringify(slotData.values)),
            },
        });

        const probe = wrapper.find('.section-slot-probe');
        expect(probe.exists()).toBe(true);
        expect(probe.text()).toContain('name');
    });
});

// ── Load-bearing DOM class markers ───────────────────────────────────────────

describe('SkForm — load-bearing DOM class markers', () => {
    it('renders sk-fb__grid, sk-fb__label, sk-fb__required, sk-fb__error, sk-fb__hint', () => {
        const config = FB.form()
            .addFields(
                FB.inputText().key('name').label('Name'), // required by default → sk-fb__required
                FB.inputText().key('email').label('Email').hint('some.hint.key'),
            )
            .build();
        const wrapper = full(config, {
            props: { modelValue: { name: '', email: '' }, errors: { name: 'Name is required' } },
        });

        expect(wrapper.find('.sk-fb__grid').exists()).toBe(true);
        expect(wrapper.find('.sk-fb__label').exists()).toBe(true);
        expect(wrapper.find('.sk-fb__required').exists()).toBe(true);
        expect(wrapper.find('.sk-fb__error').exists()).toBe(true);
        expect(wrapper.find('.sk-fb__hint').exists()).toBe(true);
    });
});
