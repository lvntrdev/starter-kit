import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount, shallowMount } from '@vue/test-utils';
import { defineComponent, h, nextTick } from 'vue';
import { FB } from '../core';
import type { ExistingMedia, FormBuilderConfig } from '../core';

/**
 * Locks in the post-save cleanup of `file-upload` fields in SkForm.
 *
 * Two user-visible failures share one cause: the submitted `File` staying in
 * form state. It renders a second time next to the media the server just
 * stored, and it pins `isDirty` on forever — Inertia deep-clones the form data
 * to build its dirty baseline, a `File` survives that clone as a *different*
 * instance, and the equality check that recomputes `isDirty` never accepts two
 * distinct `File` instances as equal. Clearing the field is what makes the
 * baseline comparable at all.
 *
 * The tests drive the REAL `useForm` — only the transport (the core router) and
 * `usePage` are replaced. A hand-rolled form mock would be asserting its own
 * dirty-tracking semantics, and Inertia's semantics are exactly what the fix
 * turns on: its `onSuccess` wrapper rebaselines with `cloneDeep(data())` when
 * the callback did not call `defaults()` itself — the step that silently fails
 * while a `File` is present.
 */

const apiGet = vi.fn();
const apiDelete = vi.fn();
const toastAdd = vi.fn();
const confirmAccept = vi.fn((onAccept: () => void) => onAccept());

vi.mock('@/composables/useApi', () => ({
    useApi: () => ({ get: apiGet, post: vi.fn(), put: vi.fn(), delete: apiDelete }),
}));
vi.mock('@/composables/useDefinition', () => ({
    useDefinition: () => ({ load: vi.fn(), options: () => [], find: () => undefined }),
}));
vi.mock('@/composables/useCan', () => ({
    useCan: () => ({ can: () => true }),
}));
// Only exercised by the full-mount (real SkFormInput) tests below — the
// shallow-mounted post-save tests above never render SkFormInput at all.
vi.mock('@/composables/useConfirm', () => ({
    useConfirm: () => ({ confirmDelete: confirmAccept, confirmAction: vi.fn() }),
}));
vi.mock('@/composables/useDialog', () => ({
    useDialog: () => ({ open: vi.fn(), close: vi.fn() }),
}));
vi.mock('primevue/usetoast', () => ({
    useToast: () => ({ add: toastAdd }),
}));
vi.mock('laravel-vue-i18n', () => ({
    trans: (key: string) => key,
}));

// The transport is the only part of Inertia that is faked: a visit runs the
// lifecycle callbacks the real router would run, in the real order, so
// `useForm`'s own onSuccess wrapper executes exactly as it does in a browser.
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
        // A real visit never completes synchronously. Yielding here is what lets
        // a test type into the form *while the request is in flight* — the exact
        // situation the mid-request guard exists for.
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

// `@inertiajs/vue3` is deliberately NOT mocked: `importOriginal` would load it
// with its REAL dependencies and bypass the core mock above, handing SkForm the
// live router. Its `usePage()` call sits inside the translatable-locales
// computed, which these fixtures never evaluate.

// Imported after the mocks so SkForm receives the controlled API and transport.
const { default: SkForm } = await import('../SkForm.vue');

/** SkCard stub that still renders its `content` slot, so the form body mounts. */
const SkCardStub = defineComponent({
    name: 'SkCard',
    setup(_props, { slots }) {
        return () => h('div', slots.content?.());
    },
});

const mountedWrappers: Array<{ unmount: () => void }> = [];

function media(id: number): ExistingMedia {
    return { id, name: `image-${id}.png`, url: `/media/${id}`, size: 100, mime_type: 'image/png' };
}

function upload(): File {
    return new File(['image'], 'a.png', { type: 'image/png' });
}

function mountForm(config: FormBuilderConfig, props: Record<string, unknown> = {}) {
    const wrapper = shallowMount(SkForm, {
        props: { config, ...props },
        global: { mocks: { $t: (key: string) => key }, stubs: { SkCard: SkCardStub } },
    });
    mountedWrappers.push(wrapper);
    return wrapper;
}

/**
 * Full mount — SkFormInput (and its upload-zone/existing-media DOM) renders
 * for real, unlike `mountForm()` above which shallow-mounts and never
 * exercises the actual pick/drop/remove interaction code.
 */
function mountFull(config: FormBuilderConfig) {
    const wrapper = mount(SkForm, {
        props: { config },
        global: { mocks: { $t: (key: string) => key }, stubs: { SkCard: SkCardStub } },
    });
    mountedWrappers.push(wrapper);
    return wrapper;
}

/** A `File` of an exact byte size, for `maxFileSize` boundary tests. */
function sizedFile(name: string, bytes: number): File {
    return new File([new Uint8Array(bytes)], name, { type: 'image/png' });
}

/** jsdom's file input has no real `files` setter — this is the standard workaround. */
function setInputFiles(input: HTMLInputElement, files: File[]): void {
    Object.defineProperty(input, 'files', { value: files, configurable: true });
}

function remoteMultipleConfig(): FormBuilderConfig {
    return FB.form()
        .dataUrl('/api/form')
        .dataKey('record')
        .submit({ url: '/api/form', method: 'put' })
        .addFields(FB.fileUpload().key('attachments').label('Attachments').multiple().existingMediaKey('media'))
        .build();
}

describe('SkForm — file-upload cleanup after a successful save', () => {
    beforeEach(() => {
        apiGet.mockReset();
        toastAdd.mockClear();
    });

    afterEach(() => {
        for (const wrapper of mountedWrappers.splice(0)) {
            wrapper.unmount();
        }
    });

    it('reduces a multiple field to the media ids from the REFRESHED payload', async () => {
        // remoteData is captured once at mount, so without the post-save refresh
        // the field would fall back to the pre-upload id list — and the next save
        // would send that list as the keep-set, deleting media 22 server-side.
        apiGet
            .mockResolvedValueOnce({ record: { attachments: [11], media: [media(11)] } })
            .mockResolvedValueOnce({ record: { attachments: [11, 22], media: [media(11), media(22)] } });
        const wrapper = mountForm(remoteMultipleConfig());
        await flushPromises();

        wrapper.vm.setValue('attachments', [11, upload()]);
        await nextTick();
        wrapper.vm.submit();
        await flushPromises();

        expect(apiGet).toHaveBeenCalledTimes(2);
        expect(wrapper.vm.currentValues.attachments).toEqual([11, 22]);
    });

    it('clears a submitted single-file field to null', async () => {
        const config = FB.form()
            .initialData({ avatar: null, avatar_media: [] })
            .submit({ url: '/api/form', method: 'post' })
            .addFields(FB.fileUpload().key('avatar').label('Avatar').existingMediaKey('avatar_media'))
            .build();
        const wrapper = mountForm(config);

        wrapper.vm.setValue('avatar', upload());
        await nextTick();
        wrapper.vm.submit();
        await flushPromises();

        expect(wrapper.vm.currentValues.avatar).toBeNull();
    });

    it('clears dirty state, which is impossible while the File is still in form data', async () => {
        const config = FB.form()
            .initialData({ attachments: null, media: [media(11)] })
            .submit({ url: '/api/form', method: 'put' })
            .addFields(FB.fileUpload().key('attachments').label('Attachments').multiple().existingMediaKey('media'))
            .build();
        const wrapper = mountForm(config);

        wrapper.vm.setValue('attachments', [upload()]);
        await nextTick();
        expect(wrapper.vm.isDirty).toBe(true);

        wrapper.vm.submit();
        await flushPromises();

        expect(wrapper.vm.currentValues.attachments).toEqual([11]);
        expect(wrapper.vm.isDirty).toBe(false);
    });

    it('leaves the File and the dirty flag untouched when the silent refresh fails', async () => {
        // Server state is unknown: reducing the field to the ids in hand could
        // drop the upload on the next save, so staying dirty is the right call.
        apiGet
            .mockResolvedValueOnce({ record: { attachments: [11], media: [media(11)] } })
            .mockRejectedValueOnce(new Error('refresh failed'));
        const wrapper = mountForm(remoteMultipleConfig());
        await flushPromises();
        const file = upload();

        wrapper.vm.setValue('attachments', [11, file]);
        await nextTick();
        wrapper.vm.submit();
        await flushPromises();

        expect(wrapper.emitted('success')).toHaveLength(1);
        expect(apiGet).toHaveBeenCalledTimes(2);
        expect(wrapper.vm.currentValues.attachments).toContain(file);
        expect(wrapper.vm.isDirty).toBe(true);
        // A failed *refresh* must never surface as a failed *load*: the save
        // succeeded, so the form must not be replaced by the retry panel.
        expect(wrapper.find('.sk-fb__data-error').exists()).toBe(false);
        expect(toastAdd).not.toHaveBeenCalled();
    });

    it('a reload() issued from the success listener does not leave dataLoading stuck after the silent refresh', async () => {
        // A host that refreshes the form from its `@success` handler starts a
        // LOADING request, and the post-save silent refresh immediately
        // supersedes it. The loading request must still own — and clear — the
        // spinner when it settles, while the newest request keeps the data write.
        apiGet
            .mockResolvedValueOnce({ record: { attachments: [11], media: [media(11)] } })
            .mockResolvedValueOnce({ record: { attachments: [99], media: [media(99)] } })
            .mockResolvedValueOnce({ record: { attachments: [11, 22], media: [media(11), media(22)] } });
        let form: { reload: () => Promise<void> } | null = null;
        const wrapper = mountForm(remoteMultipleConfig(), {
            onSuccess: () => {
                void form?.reload();
            },
        });
        form = wrapper.vm as unknown as { reload: () => Promise<void> };
        await flushPromises();

        wrapper.vm.setValue('attachments', [11, upload()]);
        await nextTick();
        wrapper.vm.submit();
        await flushPromises();

        // Mount load, the listener's reload(), the post-save silent refresh.
        expect(apiGet).toHaveBeenCalledTimes(3);
        expect(wrapper.vm.dataLoading).toBe(false);
        expect(wrapper.find('.sk-fb__skeleton').exists()).toBe(false);
        // The superseded reload payload (99) must never land: the refresh was
        // issued last, so the data-write ordering guard belongs to it.
        expect(wrapper.vm.remoteData?.attachments).toEqual([11, 22]);
        expect(wrapper.vm.currentValues.attachments).toEqual([11, 22]);
    });

    it('does not refetch dataUrl after saving a form without file fields', async () => {
        apiGet.mockResolvedValueOnce({ record: { name: 'Before' } });
        const config = FB.form()
            .dataUrl('/api/form')
            .dataKey('record')
            .submit({ url: '/api/form', method: 'put' })
            .addFields(FB.inputText().key('name').label('Name'))
            .build();
        const wrapper = mountForm(config);
        await flushPromises();

        wrapper.vm.setValue('name', 'After');
        await nextTick();
        wrapper.vm.submit();
        await flushPromises();

        expect(apiGet).toHaveBeenCalledTimes(1);
        expect(wrapper.vm.isDirty).toBe(false);
    });

    it('skips cleanup when a field changed while the request was in flight', async () => {
        // That edit was never submitted; clearing or rebaselining it would mark
        // unsent input as saved and silently drop it.
        const config = FB.form()
            .initialData({ title: 'Original', attachment: null, attachment_media: [] })
            .submit({ url: '/api/form', method: 'put' })
            .addFields(
                FB.inputText().key('title').label('Title'),
                FB.fileUpload().key('attachment').label('Attachment').existingMediaKey('attachment_media'),
            )
            .build();
        const wrapper = mountForm(config);
        const file = upload();

        wrapper.vm.setValue('attachment', file);
        await nextTick();
        wrapper.vm.submit();
        wrapper.vm.setValue('title', 'Edited while saving');
        await flushPromises();

        expect(wrapper.vm.currentValues.attachment).toBe(file);
        expect(wrapper.vm.currentValues.title).toBe('Edited while saving');
        expect(wrapper.vm.isDirty).toBe(true);
    });
});

/**
 * Full-mount interaction tests — these exercise SkFormInput's actual pick /
 * drop / limit / remove code (`addFiles`, `handleDrop`, `removeExistingFile`),
 * which the shallow-mounted suite above never renders.
 */
describe('SkForm — file-upload interaction (full mount)', () => {
    beforeEach(() => {
        apiGet.mockReset();
        apiDelete.mockReset();
        toastAdd.mockClear();
        confirmAccept.mockClear();
    });

    afterEach(() => {
        for (const wrapper of mountedWrappers.splice(0)) {
            wrapper.unmount();
        }
    });

    it('two successive selections in multiple mode emit each existing id ONCE', async () => {
        const config = FB.form()
            .initialData({ attachments: [11], media: [media(11)] })
            .submit({ url: '/api/form', method: 'put' })
            .addFields(FB.fileUpload().key('attachments').label('Attachments').multiple().existingMediaKey('media'))
            .build();
        const wrapper = mountFull(config);
        const input = wrapper.get('input[type="file"]').element as HTMLInputElement;

        const first = upload();
        setInputFiles(input, [first]);
        input.dispatchEvent(new Event('change'));
        await nextTick();

        const second = upload();
        setInputFiles(input, [second]);
        input.dispatchEvent(new Event('change'));
        await nextTick();

        const value = wrapper.vm.currentValues.attachments as unknown[];
        expect(value.filter((v) => v === 11)).toHaveLength(1);
        expect(value).toContain(first);
        expect(value).toContain(second);
    });

    it('maxFileSize/fileLimit reject only the offending files with ONE toast', async () => {
        const config = FB.form()
            .initialData({ attachments: [] })
            .submit({ url: '/api/form', method: 'put' })
            .addFields(
                FB.fileUpload()
                    .key('attachments')
                    .label('Attachments')
                    .multiple()
                    .maxFileSize(1000)
                    .fileLimit(1),
            )
            .build();
        const wrapper = mountFull(config);
        const input = wrapper.get('input[type="file"]').element as HTMLInputElement;

        const ok = sizedFile('ok.png', 500);
        const tooLarge = sizedFile('big.png', 2000);
        const alsoOk = sizedFile('ok2.png', 500);
        setInputFiles(input, [ok, tooLarge, alsoOk]);
        input.dispatchEvent(new Event('change'));
        await nextTick();

        // `ok` survives the size filter and then the fileLimit(1) slice keeps
        // only it — both problems fold into a SINGLE toast call.
        expect(toastAdd).toHaveBeenCalledTimes(1);
        expect(wrapper.vm.currentValues.attachments).toEqual([ok]);
    });

    it('a drop event feeds the same addFiles() path as the picker', async () => {
        const config = FB.form()
            .initialData({ attachment: null })
            .submit({ url: '/api/form', method: 'put' })
            .addFields(FB.fileUpload().key('attachment').label('Attachment'))
            .build();
        const wrapper = mountFull(config);
        const file = upload();

        await wrapper.get('.sk-fb__upload-zone').trigger('drop', { dataTransfer: { files: [file] } });

        expect(wrapper.vm.currentValues.attachment).toBe(file);
    });

    it('deferExistingRemoval emits the value without the id and never calls api.delete', async () => {
        const config = FB.form()
            .initialData({ attachments: [11], media: [media(11)] })
            .submit({ url: '/api/form', method: 'put' })
            .addFields(
                FB.fileUpload()
                    .key('attachments')
                    .label('Attachments')
                    .multiple()
                    .existingMediaKey('media')
                    .deferExistingRemoval(),
            )
            .build();
        const wrapper = mountFull(config);

        await wrapper.get('.sk-fb__file-remove').trigger('click');
        await flushPromises();

        expect(confirmAccept).toHaveBeenCalledTimes(1);
        expect(apiDelete).not.toHaveBeenCalled();
        expect(wrapper.vm.currentValues.attachments).toEqual([]);
        expect(wrapper.find('.sk-fb__file-item').exists()).toBe(false);
    });

    it('immediate mode (deferExistingRemoval unset) surfaces a delete-failure toast and keeps the media listed', async () => {
        apiDelete.mockRejectedValueOnce(new Error('delete failed'));
        const config = FB.form()
            .initialData({ attachments: [11], media: [media(11)] })
            .submit({ url: '/api/form', method: 'put' })
            .addFields(
                FB.fileUpload().key('attachments').label('Attachments').multiple().existingMediaKey('media'),
            )
            .build();
        const wrapper = mountFull(config);

        await wrapper.get('.sk-fb__file-remove').trigger('click');
        await flushPromises();

        expect(apiDelete).toHaveBeenCalledTimes(1);
        expect(toastAdd).toHaveBeenCalledTimes(1);
        expect(wrapper.find('.sk-fb__file-item').exists()).toBe(true);
        // The keep-list must not have been touched by the failed delete either.
        expect(wrapper.vm.currentValues.attachments).toEqual([11]);
    });
});
