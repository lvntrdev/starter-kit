import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { FB } from '../core';
import type { FormBuilderConfig } from '../core';

/**
 * Regression tests for SkForm's data layer: date-only round-tripping across
 * timezones, the dependent-`optionsUrl` race guard, `checkbox-group` sourcing
 * options from a URL, load-failure toasts, and the two refetch paths
 * (`reload()` / `reloadOnDataUrlChange`).
 *
 * Mount/mock setup mirrors `SkForm.fileUpload.spec.ts` — shallow-mounted
 * (SkFormInput never renders), only the transport and a handful of
 * composables mocked, so `currentValues` and `SkFormFieldRenderer`'s `ctx`
 * prop are read directly instead of the rendered controls.
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

const { default: SkForm } = await import('../SkForm.vue');

const SkCardStub = defineComponent({
    name: 'SkCard',
    setup(_props, { slots }) {
        return () => h('div', slots.content?.());
    },
});

const mountedWrappers: Array<{ unmount: () => void }> = [];

function mountForm(config: FormBuilderConfig, props: Record<string, unknown> = {}) {
    const wrapper = shallowMount(SkForm, {
        props: { config, ...props },
        global: { mocks: { $t: (key: string) => key }, stubs: { SkCard: SkCardStub } },
    });
    mountedWrappers.push(wrapper);
    return wrapper;
}

/** Ctx handed to SkFormFieldRenderer — `getOptions()` is how dynamicOptions is read. */
function ctxOf(wrapper: ReturnType<typeof mountForm>) {
    return wrapper.findComponent({ name: 'SkFormFieldRenderer' }).props('ctx') as {
        getOptions: (field: unknown) => Array<{ label: string; value: unknown }>;
    };
}

/** A promise the test controls the resolution/rejection timing of. */
function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason?: unknown) => void;
    const promise = new Promise<T>((res, rej) => {
        resolve = res;
        reject = rej;
    });
    return { promise, resolve, reject };
}

beforeEach(() => {
    apiGet.mockReset();
    toastAdd.mockClear();
});

afterEach(() => {
    for (const wrapper of mountedWrappers.splice(0)) {
        wrapper.unmount();
    }
});

describe('SkForm — date-picker initial value keeps its calendar day', () => {
    it('single mode: a date-only string parses at LOCAL midnight, not shifted a day west of UTC', async () => {
        const config = FB.form()
            .initialData({ birthday: '2024-03-10' })
            .submit({ url: '/api/form', method: 'put' })
            .addFields(FB.datePicker().key('birthday').label('Birthday'))
            .build();
        const wrapper = mountForm(config);

        const value = wrapper.vm.currentValues.birthday as Date;
        expect(value).toBeInstanceOf(Date);
        expect(value.getFullYear()).toBe(2024);
        expect(value.getMonth()).toBe(2);
        expect(value.getDate()).toBe(10);
    });

    it('range mode: every date-only entry keeps its own calendar day', async () => {
        const config = FB.form()
            .initialData({ span: ['2024-03-10', '2024-03-15'] })
            .submit({ url: '/api/form', method: 'put' })
            .addFields(FB.datePicker().key('span').label('Span').selectionMode('range'))
            .build();
        const wrapper = mountForm(config);

        const [start, end] = wrapper.vm.currentValues.span as Date[];
        expect(start.getDate()).toBe(10);
        expect(end.getDate()).toBe(15);
    });

    it('multiple mode: every date-only entry keeps its own calendar day', async () => {
        const config = FB.form()
            .initialData({ dates: ['2024-03-10', '2024-12-31'] })
            .submit({ url: '/api/form', method: 'put' })
            .addFields(FB.datePicker().key('dates').label('Dates').selectionMode('multiple'))
            .build();
        const wrapper = mountForm(config);

        const [first, second] = wrapper.vm.currentValues.dates as Date[];
        expect(first.getDate()).toBe(10);
        expect(second.getMonth()).toBe(11);
        expect(second.getDate()).toBe(31);
    });

    it('a string carrying a time part is still parsed as a real instant (native Date parse)', () => {
        const iso = '2024-03-10T23:30:00Z';
        const config = FB.form()
            .initialData({ meeting: iso })
            .submit({ url: '/api/form', method: 'put' })
            .addFields(FB.datePicker().key('meeting').label('Meeting').showTime())
            .build();
        const wrapper = mountForm(config);

        const value = wrapper.vm.currentValues.meeting as Date;
        expect(value).toBeInstanceOf(Date);
        expect(value.getTime()).toBe(new Date(iso).getTime());
    });
});

describe('SkForm — dependent optionsUrl race guard', () => {
    function dependentConfig(): FormBuilderConfig {
        return FB.form()
            .initialData({ parent: null, child: null })
            .addFields(
                FB.select().key('parent').label('Parent').options([
                    { label: 'A', value: 'a' },
                    { label: 'B', value: 'b' },
                ]),
                FB.select()
                    .key('child')
                    .label('Child')
                    .optionsUrl((values) => (values.parent ? `/api/options/${values.parent}` : null)),
            )
            .build();
    }

    it('discards a stale response even when it resolves AFTER the newer request', async () => {
        const first = deferred<Array<{ label: string; value: unknown }>>();
        const second = deferred<Array<{ label: string; value: unknown }>>();
        apiGet.mockImplementation((url: string) => (url === '/api/options/a' ? first.promise : second.promise));

        const wrapper = mountForm(dependentConfig());
        await flushPromises();

        wrapper.vm.setValue('parent', 'a');
        await flushPromises();
        wrapper.vm.setValue('parent', 'b');
        await flushPromises();

        // The FIRST (now-superseded) request resolves after the second is already
        // in flight — it must never win.
        first.resolve([{ label: 'Stale A', value: 'stale' }]);
        await flushPromises();
        second.resolve([{ label: 'Fresh B', value: 'fresh' }]);
        await flushPromises();

        const childField = { key: 'child', type: 'select', optionsUrl: dependentConfig().fields[1].optionsUrl };
        expect(ctxOf(wrapper).getOptions(childField)).toEqual([{ label: 'Fresh B', value: 'fresh' }]);
    });
});

describe('SkForm — checkbox-group sources options from optionsUrl', () => {
    it('loads options for a checkbox-group field', async () => {
        apiGet.mockResolvedValueOnce([{ label: 'One', value: 1 }, { label: 'Two', value: 2 }]);
        const config = FB.form()
            .addFields(FB.checkboxGroup().key('tags').label('Tags').optionsUrl('/api/tags'))
            .build();
        const wrapper = mountForm(config);
        await flushPromises();

        const field = { key: 'tags', type: 'checkbox-group', optionsUrl: '/api/tags' };
        expect(ctxOf(wrapper).getOptions(field)).toEqual([
            { label: 'One', value: 1 },
            { label: 'Two', value: 2 },
        ]);
    });
});

describe('SkForm — load-failure toasts fire exactly once', () => {
    it('a failing dataUrl request produces exactly one toast', async () => {
        apiGet.mockRejectedValueOnce(new Error('network down'));
        const config = FB.form()
            .dataUrl('/api/form')
            .dataKey('record')
            .addFields(FB.inputText().key('name').label('Name'))
            .build();
        mountForm(config);
        await flushPromises();

        expect(toastAdd).toHaveBeenCalledTimes(1);
    });

    it('a failing options request produces exactly one toast', async () => {
        apiGet.mockRejectedValueOnce(new Error('network down'));
        const config = FB.form()
            .addFields(FB.select().key('tags').label('Tags').optionsUrl('/api/tags'))
            .build();
        mountForm(config);
        await flushPromises();

        expect(toastAdd).toHaveBeenCalledTimes(1);
    });
});

describe('SkForm — reload() and reloadOnDataUrlChange', () => {
    it('reload() refetches dataUrl', async () => {
        apiGet.mockResolvedValueOnce({ record: { name: 'Before' } }).mockResolvedValueOnce({ record: { name: 'After' } });
        const config = FB.form()
            .dataUrl('/api/form')
            .dataKey('record')
            .submit({ url: '/api/form', method: 'put' })
            .addFields(FB.inputText().key('name').label('Name'))
            .build();
        const wrapper = mountForm(config);
        await flushPromises();

        await wrapper.vm.reload();

        expect(apiGet).toHaveBeenCalledTimes(2);
        expect(wrapper.vm.currentValues.name).toBe('After');
    });

    it('without reloadOnDataUrlChange, a changed dataUrl prop does NOT trigger a refetch', async () => {
        apiGet.mockResolvedValueOnce({ record: { name: 'Before' } });
        const configFor = (url: string) =>
            FB.form().dataUrl(url).dataKey('record').addFields(FB.inputText().key('name').label('Name')).build();
        const wrapper = mountForm(configFor('/api/form/1'));
        await flushPromises();

        await wrapper.setProps({ config: configFor('/api/form/2') });
        await flushPromises();

        expect(apiGet).toHaveBeenCalledTimes(1);
    });

    it('with reloadOnDataUrlChange, a changed dataUrl prop DOES trigger a refetch', async () => {
        apiGet.mockResolvedValueOnce({ record: { name: 'Before' } }).mockResolvedValueOnce({ record: { name: 'After' } });
        const configFor = (url: string) =>
            FB.form()
                .dataUrl(url)
                .dataKey('record')
                .reloadOnDataUrlChange()
                .submit({ url: '/api/form', method: 'put' })
                .addFields(FB.inputText().key('name').label('Name'))
                .build();
        const wrapper = mountForm(configFor('/api/form/1'));
        await flushPromises();

        await wrapper.setProps({ config: configFor('/api/form/2') });
        await flushPromises();

        expect(apiGet).toHaveBeenCalledTimes(2);
        expect(wrapper.vm.currentValues.name).toBe('After');
    });
});

describe('SkForm — remoteData request-order guard', () => {
    it('a slower response for a superseded dataUrl never overwrites the newer record', async () => {
        const first = deferred<{ record: { name: string } }>();
        const second = deferred<{ record: { name: string } }>();
        apiGet.mockImplementation((url: string) => (url === '/api/form/1' ? first.promise : second.promise));
        const configFor = (url: string) =>
            FB.form()
                .dataUrl(url)
                .dataKey('record')
                .reloadOnDataUrlChange()
                .submit({ url: '/api/form', method: 'put' })
                .addFields(FB.inputText().key('name').label('Name'))
                .build();

        const wrapper = mountForm(configFor('/api/form/1'));
        await flushPromises();
        // Record 1 is still loading when the host swaps the form to record 2.
        await wrapper.setProps({ config: configFor('/api/form/2') });
        await flushPromises();

        second.resolve({ record: { name: 'Fresh 2' } });
        await flushPromises();
        first.resolve({ record: { name: 'Stale 1' } });
        await flushPromises();

        expect(apiGet).toHaveBeenCalledTimes(2);
        expect(wrapper.vm.currentValues.name).toBe('Fresh 2');
        expect(wrapper.vm.dataLoading).toBe(false);
    });
});
