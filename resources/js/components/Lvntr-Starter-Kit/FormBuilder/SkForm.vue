<script setup lang="ts">
    import { router, useForm, usePage } from '@inertiajs/vue3';
    import { useToast } from 'primevue/usetoast';
    import type {
        DatePickerFieldConfig,
        ExistingMedia,
        FieldConfig,
        FileUploadFieldConfig,
        FormBuilderConfig,
        OptionFilter,
        SelectFieldConfig,
        SelectOption,
        SectionFieldConfig,
        TranslatableTextFieldConfig,
    } from './core';
    import { applyLocaleFilters, resolveContentLocales } from './core/locales';
    import type { SharedPageProps } from '@/types';
    import { useApi } from '@/composables/useApi';
    import { useCan } from '@/composables/useCan';
    import { useDefinition } from '@/composables/useDefinition';
    import { trans } from 'laravel-vue-i18n';
    import SkFormFieldRenderer from './SkFormFieldRenderer.vue';
    import type { RenderCtx } from './SkFormFieldRenderer.vue';
    import SkCard from '../ui/SkCard.vue';

    /**
     * Render a field's label: translates it via laravel-vue-i18n when the label is
     * a translation key (default), or returns it as-is when the field opted out
     * via `.trans(false)` — i.e. already holds a pre-resolved string.
     */
    function displayLabel(field: FieldConfig): string {
        return field.translateLabel === false ? field.label : trans(field.label);
    }

    interface Props {
        config: FormBuilderConfig;
        /**
         * Validation errors — only used in v-model mode.
         * In internal mode (config.submit set), errors come from the internal Inertia form.
         */
        errors?: Record<string, string>;
    }

    const props = withDefaults(defineProps<Props>(), {
        errors: () => ({}),
    });

    /**
     * v-model — used in external form mode (when config.submit is NOT set).
     * Optional: when config.submit is set, FormBuilder manages form state internally.
     */
    const modelValue = defineModel<Record<string, unknown>>({ default: () => ({}) });

    const emit = defineEmits<{
        /** Fired after a successful Inertia submit (internal mode only). */
        success: [];
        /** Fired when the cancel button is clicked and onCancel is 'emit' (default). */
        cancel: [];
    }>();

    // ── Dialog / Back button logic ────────────────────────────────────────────
    const isDialogMode = computed(() => props.config.inDialog === true);
    const showCancelButton = computed(() => {
        // Explicit hideCancel takes priority
        if (props.config.actionLabels?.hideCancel !== undefined) {
            return !props.config.actionLabels.hideCancel;
        }
        return isDialogMode.value || props.config.showBack === true;
    });

    const cancelLabel = computed(() => {
        const key = props.config.actionLabels?.cancel ?? (isDialogMode.value ? 'sk-button.cancel' : 'sk-button.back');
        return trans(key);
    });

    const cancelIcon = computed(() => {
        if (props.config.actionLabels?.cancelIcon !== undefined) return props.config.actionLabels.cancelIcon;
        return isDialogMode.value ? undefined : 'pi pi-arrow-left';
    });

    function handleCancel(): void {
        const behavior = props.config.onCancel ?? (isDialogMode.value ? 'emit' : 'back');
        if (behavior === 'back') {
            window.history.back();
        } else {
            emit('cancel');
        }
    }

    // `toast: false` — SkForm raises its OWN `data_load_error` / `options_load_error`
    // toasts with the exact wording and toast group the form needs. Left on the
    // default, useApi would fire a second, generic toast for the same failure, and
    // `refreshRemoteData()` — deliberately silent — would surface an error the user
    // must not see after a successful save. Only this instance is affected; a
    // consumer's own `useApi()` keeps its default toasts.
    const api = useApi({ toast: false });
    const toast = useToast();
    const { can } = useCan();
    const { options: definitionOptions, load: loadDefinitions } = useDefinition();

    // ── Permission gate ─────────────────────────────────────────────────────────
    /**
     * When config.permission is set and the current user lacks it, the form
     * becomes read-only: all fields are disabled and the submit button is hidden.
     */
    const isReadOnly = computed(() => !!props.config.permission && !can(props.config.permission));

    // ── Remote data loading ─────────────────────────────────────────────────────
    const restoringDefaults = ref(false);
    const dataLoading = ref(false);
    const dataError = ref(false);
    const remoteData = ref<Record<string, unknown> | null>(null);

    /** Fetches `dataUrl` and unwraps `dataKey`. Throws on failure. */
    async function requestRemoteData(url: string): Promise<Record<string, unknown>> {
        const response = await api.get<Record<string, unknown>>(url);
        return props.config.dataKey ? (response[props.config.dataKey] as Record<string, unknown>) : response;
    }

    /**
     * Generation counter shared by every `remoteData` writer. `reload()`, the
     * `reloadOnDataUrlChange` watch and the post-save silent refresh can overlap
     * (a dialog swapped from record A to B while A is still loading); only the
     * newest request may write, so a slower, older response never puts record A
     * back on screen — the same guard `fetchOptions` applies per field.
     */
    let remoteDataSeq = 0;

    /**
     * Owner token for the loading UI, bumped ONLY by `fetchRemoteData()`. The
     * data write and the spinner have different owners: a silent
     * `refreshRemoteData()` that supersedes a loading request — a host that
     * calls `reload()` from its `success` listener, immediately followed by the
     * post-save refresh — takes over the write, but must not orphan the
     * spinner, since it never clears `dataLoading` itself. Keeping a separate
     * counter lets the loading request still clear the spinner when it settles.
     */
    let loadingSeq = 0;

    /**
     * Silent refresh used after a successful save. Updates `remoteData` without
     * touching the loading/error UI: the form is already on screen with valid
     * data, so flashing the skeleton — or worse, replacing a just-saved form
     * with the load-error panel — would be wrong. Returns `false` when the
     * refresh failed or was superseded by a newer request, in which case the
     * previous data is left untouched.
     */
    async function refreshRemoteData(): Promise<boolean> {
        if (!props.config.dataUrl) return false;
        const seq = ++remoteDataSeq;
        try {
            const data = await requestRemoteData(props.config.dataUrl);
            if (seq !== remoteDataSeq) return false;
            remoteData.value = data;
            return true;
        } catch {
            return false;
        }
    }

    async function fetchRemoteData(): Promise<void> {
        if (!props.config.dataUrl) return;
        const seq = ++remoteDataSeq;
        const loadSeq = ++loadingSeq;
        dataLoading.value = true;
        dataError.value = false;
        try {
            const data = await requestRemoteData(props.config.dataUrl);
            if (seq !== remoteDataSeq) return;
            remoteData.value = data;
        } catch {
            if (seq !== remoteDataSeq) return;
            // Surface the failure instead of swallowing it: flag an in-form retry
            // state and notify the user via a toast.
            dataError.value = true;
            toast.add({
                severity: 'error',
                summary: trans('sk-common.error'),
                detail: trans('sk-common.data_load_error'),
                group: 'bc',
                life: 4000,
            });
        } finally {
            // A superseded LOADING request must not clear the spinner the newer
            // loading request owns; a silent refresh never owns it, so being
            // superseded by one still leaves this request responsible for it.
            if (loadSeq === loadingSeq) dataLoading.value = false;
        }
    }

    /**
     * Opt-in refetch when `dataUrl` changes after mount.
     *
     * Default stays mount-only: a form whose config is rebuilt on every parent
     * render (a computed `FB.form()...build()`) would otherwise refetch on each
     * rebuild, and a same-URL rebuild must not wipe in-progress edits. Hosts that
     * genuinely swap the record behind a persistent form (a dialog reused for a
     * different id) opt in with `.reloadOnDataUrlChange()`.
     */
    watch(
        () => props.config.dataUrl,
        () => {
            if (props.config.reloadOnDataUrlChange !== true) return;
            void fetchRemoteData();
        },
    );

    /**
     * Collect all definitionKey values from select fields to preload them.
     * Uses flatFields so section-nested select fields are also included.
     */
    const definitionKeys = computed(() =>
        Array.from(iterateAllFields(props.config.fields))
            .filter((f): f is SelectFieldConfig => {
                if (!SELECT_TYPES.has(f.type)) return false;
                const sf = f as SelectFieldConfig;
                return !!(sf.definitionKey ?? sf.enumKey);
            })
            .map((f) => ((f as SelectFieldConfig).definitionKey ?? (f as SelectFieldConfig).enumKey)!),
    );

    onMounted(async () => {
        if (props.config.dataUrl) {
            fetchRemoteData();
        }
        if (definitionKeys.value.length > 0) {
            await loadDefinitions(definitionKeys.value);
        }
    });

    // ── Field iteration helper (section flattening) ───────────────────────────

    /**
     * Generator that yields all non-section leaf fields, recursively opening
     * sections. Used to build flat lists for defaults/options/submit logic.
     */
    function* iterateAllFields(fields: FieldConfig[]): Iterable<FieldConfig> {
        for (const f of fields) {
            if (f.type === 'section') {
                yield* iterateAllFields((f as SectionFieldConfig).fields);
            } else {
                yield f;
            }
        }
    }

    // ── Resolve existingMediaKey from loaded data ─────────────────────────────

    /**
     * Resolves `existingMediaKey` for file-upload fields.
     * Recursive: also resolves fields nested inside sections.
     */
    const resolvedFields = computed<FieldConfig[]>(() => {
        const data = remoteData.value ?? props.config.initialData;
        if (!data) return props.config.fields;

        // Capture as non-nullable for use inside the nested closure
        const resolvedData: Record<string, unknown> = data;

        function resolveField(field: FieldConfig): FieldConfig {
            if (field.type === 'section') {
                const sec = field as SectionFieldConfig;
                return { ...sec, fields: sec.fields.map(resolveField) };
            }
            if (field.type !== 'file-upload') return field;
            const f = field as FileUploadFieldConfig;
            if (!f.existingMediaKey) return field;
            const media = resolvedData[f.existingMediaKey];
            const resolved: ExistingMedia[] = Array.isArray(media) ? media : media ? [media as ExistingMedia] : [];
            return { ...f, existingMedia: resolved };
        }

        return props.config.fields.map(resolveField);
    });

    /**
     * Flat list of all leaf input fields (sections opened, non-input types excluded).
     * Used for derivedDefaults, currentValues, hasFileFields, dateOnlyFields, etc.
     */
    const flatFields = computed<FieldConfig[]>(() => Array.from(iterateAllFields(resolvedFields.value)));

    // ── Field type sets ─────────────────────────────────────────────────────────
    const NON_INPUT_TYPES = new Set(['title', 'slot', 'section']);
    const SELECT_TYPES = new Set(['select', 'multiselect', 'radio', 'select-button', 'checkbox-group']);
    const INLINE_LABEL_TYPES = new Set(['checkbox', 'toggle-button', 'toggle-switch']);

    // ── Internal form mode (Inertia useForm) ─────────────────────────────────────

    // ── Translatable field helpers ──────────────────────────────────────────────

    function isTranslatableField(field: FieldConfig): boolean {
        return (
            field.type === 'translatable-text' ||
            field.type === 'translatable-textarea' ||
            field.type === 'translatable-editor'
        );
    }

    /**
     * Empty `{ locale: '' }` seed for a translatable field on a create form.
     *
     * Resolved through `core/locales` — the SAME helpers TranslatableInput uses —
     * so the default carries exactly the locales the input renders. Reading
     * `availableLocales` directly here (as this used to) produced admin-UI locales
     * while the input rendered DB-backed content locales, so the payload could
     * ship keys the user never saw.
     *
     * `usePage()` stays INSIDE the function on purpose: it is reached only when a
     * translatable field exists, and the reactive page proxy still tracks inside
     * the `derivedDefaults` computed that calls it.
     */
    function buildTranslatableDefault(field: FieldConfig): Record<string, string> {
        const page = usePage<SharedPageProps>();
        const locales = applyLocaleFilters(
            resolveContentLocales(page.props),
            field as Partial<TranslatableTextFieldConfig>,
        );
        return Object.fromEntries(locales.map((l) => [l.code, '']));
    }

    function translatableErrorsFor(field: FieldConfig): Record<string, string> | undefined {
        if (!isTranslatableField(field)) return undefined;
        const result: Record<string, string> = {};
        const prefix = `${field.key}.`;
        for (const [k, v] of Object.entries(activeErrors.value)) {
            if (k.startsWith(prefix)) result[k] = String(v);
        }
        return Object.keys(result).length > 0 ? result : undefined;
    }

    // ── Date parsing (read side) ────────────────────────────────────────────────

    /** `YYYY-MM-DD` with no time component. */
    const DATE_ONLY_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

    /**
     * Parses a date string coming from the server into a Date.
     *
     * A date-only string is built as LOCAL midnight: `new Date('2024-03-10')`
     * follows the ISO rule and parses it as UTC midnight, which renders as the
     * PREVIOUS day anywhere west of UTC — and the write side
     * (`toLocalDateStr`) then serialises that shifted day back, so a form
     * round-trip silently moved the date. Building it component-wise keeps read
     * and write on the same calendar day in every timezone.
     *
     * Strings carrying a time part are unambiguous (or explicitly offset), so
     * they keep the native parse.
     */
    function parseDateString(value: string): Date {
        if (!DATE_ONLY_PATTERN.test(value)) {
            return new Date(value);
        }
        const [year, month, day] = value.split('-').map(Number);
        return new Date(year, month - 1, day);
    }

    /**
     * Normalises a date-picker value from remote/initial data.
     * `range` / `multiple` fields carry an array — every string entry is converted
     * the same way and `null` slots (an open-ended range) are preserved.
     */
    function toDatePickerValue(field: FieldConfig, value: unknown): unknown {
        const mode = (field as DatePickerFieldConfig).selectionMode ?? 'single';
        if ((mode === 'range' || mode === 'multiple') && Array.isArray(value)) {
            return value.map((entry) => (typeof entry === 'string' ? parseDateString(entry) : entry));
        }
        return typeof value === 'string' ? parseDateString(value) : value;
    }

    /**
     * Auto-derive initial form values.
     * Priority: initialData[key] → field.defaultValue → null
     * Uses flatFields so section-nested fields are included; section nodes themselves are excluded.
     */
    const derivedDefaults = computed(() => {
        const initial = remoteData.value ?? props.config.initialData ?? {};
        return Object.fromEntries(
            flatFields.value
                .filter((f) => !NON_INPUT_TYPES.has(f.type))
                .map((f) => {
                    let fromData = initial[f.key];
                    if (fromData !== undefined && fromData !== null) {
                        if (f.type === 'date-picker') {
                            fromData = toDatePickerValue(f, fromData);
                        }
                        return [f.key, fromData];
                    }
                    // Translatable field'lar için locale bazlı boş Record üret
                    if (isTranslatableField(f)) {
                        return [f.key, buildTranslatableDefault(f)];
                    }
                    return [f.key, f.defaultValue ?? null];
                }),
        );
    });

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const internalForm = useForm(derivedDefaults.value as any);

    const isInternalMode = computed(() => !!props.config.submit);
    const isEditMode = computed(() => ['put', 'patch'].includes(props.config.submit?.method ?? ''));

    /**
     * Value equality used by the record comparison below.
     *
     * Dates compare by time and arrays compare element-wise, because both are
     * rebuilt as NEW instances on every `derivedDefaults` recompute: a `range` /
     * `multiple` date-picker maps its incoming array into fresh Date objects, so
     * reference equality would report "changed" for byte-identical data and the
     * watch would reset the form on every unrelated re-render.
     */
    function valuesEqual(a: unknown, b: unknown): boolean {
        if (a instanceof Date && b instanceof Date) {
            return a.getTime() === b.getTime();
        }
        if (Array.isArray(a) && Array.isArray(b)) {
            return a.length === b.length && a.every((entry, i) => valuesEqual(entry, b[i]));
        }
        return a === b;
    }

    /**
     * Shallow equality for plain value records — Date instances compared by time,
     * arrays compared element-wise.
     * Used to skip redundant resets when derivedDefaults recomputes with identical data.
     */
    function shallowRecordEqual(a: Record<string, unknown>, b: Record<string, unknown>): boolean {
        const aKeys = Object.keys(a);
        const bKeys = Object.keys(b);
        if (aKeys.length !== bKeys.length) {
            return false;
        }
        for (const key of aKeys) {
            if (!valuesEqual(a[key], b[key])) {
                return false;
            }
        }
        return true;
    }

    /**
     * When field defaults change (e.g. a different record is loaded into a dialog),
     * re-populate the internal form and clear validation errors.
     *
     * Guard: page.props refresh (e.g. Inertia back()) can rebuild formConfig and
     * produce a new derivedDefaults object with identical values. Skip the reset
     * in that case so the user's in-progress edits are not clobbered by stale
     * remoteData captured at the initial mount.
     */
    watch(derivedDefaults, (newValues, oldValues) => {
        if (!isInternalMode.value) {
            return;
        }
        if (oldValues && shallowRecordEqual(newValues, oldValues)) {
            return;
        }
        // Protect user input: once the form is dirty, treat derivedDefaults
        // changes as "new baseline" rather than an overwrite. This prevents
        // a late-arriving async payload or a parent re-render from wiping
        // the values the user is actively typing.
        if (internalForm.isDirty) {
            internalForm.defaults(newValues);
            return;
        }
        restoringDefaults.value = true;
        internalForm.defaults(newValues);
        for (const [key, value] of Object.entries(newValues)) {
            (internalForm as unknown as Record<string, unknown>)[key] = value;
        }
        internalForm.clearErrors();
        nextTick(() => {
            restoringDefaults.value = false;
        });
    });

    // ── Unified value & error access ─────────────────────────────────────────────

    /** Unified read/write for form field values (internal or external mode). */
    function getValue(key: string): unknown {
        if (isInternalMode.value) {
            return (internalForm as unknown as Record<string, unknown>)[key];
        }
        return modelValue.value[key];
    }

    function setValue(key: string, value: unknown): void {
        if (isInternalMode.value) {
            (internalForm as unknown as Record<string, unknown>)[key] = value;
        } else {
            modelValue.value = { ...modelValue.value, [key]: value };
        }
    }

    /**
     * Snapshot of all current values — used for visible/disabled callbacks and dynamic options.
     * Uses flatFields (section-nested fields included, section nodes excluded).
     */
    const currentValues = computed<Record<string, unknown>>(() => {
        if (isInternalMode.value) {
            return Object.fromEntries(
                flatFields.value.map((f) => [f.key, (internalForm as unknown as Record<string, unknown>)[f.key]]),
            );
        }
        return modelValue.value;
    });

    const activeErrors = computed<Record<string, string>>(() =>
        isInternalMode.value ? (internalForm.errors as Record<string, string>) : props.errors,
    );

    // ── Submit & Reset ────────────────────────────────────────────────────────────

    /** Check if the form contains any file upload fields (including section-nested). */
    const hasFileFields = computed(() => flatFields.value.some((f) => f.type === 'file-upload'));

    // ── Dirty-form leave guard ────────────────────────────────────────────────────
    /**
     * Set true while this form drives its own Inertia submit so the leave guard
     * does not prompt on the form's own navigation.
     */
    const selfSubmitting = ref(false);

    /** Opt-out prop: `confirmLeave: false` disables the dirty-navigation prompt. */
    const confirmLeaveEnabled = computed(() => props.config.confirmLeave !== false && isInternalMode.value);

    /**
     * True only when THIS form instance holds unsaved edits and should block
     * navigation. Multiple SkForms can coexist on one page — each registers its
     * own listener and guards only when its own internalForm is dirty.
     */
    function shouldGuardLeave(): boolean {
        return confirmLeaveEnabled.value && internalForm.isDirty && !isReadOnly.value && !selfSubmitting.value;
    }

    function handleBeforeUnload(e: BeforeUnloadEvent): void {
        if (!shouldGuardLeave()) return;
        e.preventDefault();
        // Legacy browsers require returnValue to be set to trigger the prompt.
        e.returnValue = '';
    }

    let removeInertiaBefore: (() => void) | undefined;

    onMounted(() => {
        window.addEventListener('beforeunload', handleBeforeUnload);
        // Inertia SPA navigation: cancel the visit (return false) when the user
        // declines the confirm on a dirty form.
        removeInertiaBefore = router.on('before', () => {
            if (!shouldGuardLeave()) return;
            // eslint-disable-next-line no-alert
            if (!window.confirm(trans('sk-common.confirm_leave'))) {
                return false;
            }
        });
    });

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', handleBeforeUnload);
        removeInertiaBefore?.();
    });

    /**
     * Clears file-upload field values after a successful save.
     *
     * Required for two reasons:
     *  1. The uploaded file now lives on the server and is rendered as
     *     `existingMedia`; the `File` left in form state lists the same file a
     *     SECOND time.
     *  2. `isDirty` can never clear otherwise. Inertia rebaselines with
     *     `cloneDeep(data())` (es-toolkit) and a deep watcher then recomputes
     *     `isDirty = !isEqual(data(), defaults)`. A `File` survives the clone as
     *     a DIFFERENT instance, and `isEqual` does not treat two distinct `File`
     *     objects as equal → the flag flips straight back to true, leaving the
     *     unsaved badge and the leave-guard permanently on. Rebaselining ALONE
     *     cannot fix it; the `File` has to leave form state.
     *
     * For `multiple` fields the value is `[keepId..., File...]`; it is reduced to
     * the current media id list returned by the server. Single-file fields get `null`.
     */
    function clearSubmittedFileInputs(): void {
        for (const field of flatFields.value) {
            if (field.type !== 'file-upload') continue;
            const f = field as FileUploadFieldConfig;
            setValue(f.key, f.multiple ? (f.existingMedia ?? []).map((m) => m.id) : null);
        }
    }

    function handleSubmit(): void {
        if (!props.config.submit || isReadOnly.value) {
            return;
        }
        // Double-submit guard: ignore re-entrant submits while a request is in flight.
        if (internalForm.processing) {
            return;
        }
        const { url, method, preserveScroll = true } = props.config.submit;

        const dateOnlyFields = flatFields.value.filter(
            (f): f is DatePickerFieldConfig => f.type === 'date-picker' && !f.showTime,
        );

        function toLocalDateStr(d: Date): string {
            return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        }

        selfSubmitting.value = true;
        // Snapshot of exactly what this request carries. `currentValues` rebuilds a
        // fresh object on every read, so this stays a true point-in-time copy.
        const submittedValues = currentValues.value;
        const request = internalForm.transform((data: Record<string, unknown>) => {
            if (dateOnlyFields.length === 0) return data;
            const result = { ...data };
            for (const field of dateOnlyFields) {
                const val = result[field.key];
                if (val instanceof Date) {
                    result[field.key] = toLocalDateStr(val);
                } else if (Array.isArray(val)) {
                    result[field.key] = val.map((v) => (v instanceof Date ? toLocalDateStr(v) : v));
                }
            }
            return result;
        });
        request[method](url, {
                preserveScroll,
                forceFormData: hasFileFields.value,
                onSuccess: () => {
                    emit('success');
                    // Save succeeded → rebaseline Inertia's dirty tracking to the
                    // saved state so isDirty clears (the unsaved-changes banner and
                    // the beforeunload / Inertia leave-guard both hang off it). Done
                    // explicitly because the derivedDefaults watch does not fire
                    // reliably here: preserveScroll submits, its shallow-equal
                    // early-return, and remote-data forms that never refetch all
                    // leave the form stuck-dirty after a real save.
                    //
                    // Skipped when the form no longer matches what was sent: fields
                    // stay editable while the request is in flight, so an edit made
                    // mid-request was never submitted. Rebaselining would mark that
                    // unsent input as saved and silently drop it — the form must
                    // stay dirty instead. The emit runs first so a host that calls
                    // reset() on success (create forms) is compared in its post-reset
                    // state, which already equals its defaults — skipping is a no-op
                    // there.
                    if (!shallowRecordEqual(currentValues.value, submittedValues)) {
                        return;
                    }
                    // File cleanup needs the FRESH server-side media id list, and it
                    // is only safe once that list is in hand. For `dataUrl` forms
                    // `remoteData` is captured once at mount, so `existingMedia`
                    // still holds the PRE-upload ids — reducing the field to those
                    // would hide the file just uploaded and make the next save
                    // delete it (`syncMediaCollection` drops any media missing from
                    // the submitted keep-list). Refresh first, silently.
                    void (async () => {
                        if (hasFileFields.value && props.config.dataUrl && !(await refreshRemoteData())) {
                            // Refresh failed → the ids in hand are unknown/stale, so
                            // touching the file fields could destroy the upload. Leave
                            // form state alone; staying dirty is the honest outcome.
                            return;
                        }
                        // nextTick lets the derivedDefaults watch settle (and picks up
                        // a parent that rebuilt the config from its new page props)
                        // before the values are cleared and rebaselined.
                        await nextTick();
                        clearSubmittedFileInputs();
                        internalForm.defaults();
                    })();
                },
                onFinish: () => {
                    selfSubmitting.value = false;
                },
            });
    }

    function reset(): void {
        if (!isInternalMode.value) {
            return;
        }
        internalForm.reset();
        internalForm.clearErrors();
    }

    /** Programmatic submit — lets a host (e.g. a card-integrated footer) drive the form. */
    function submit(): void {
        handleSubmit();
    }

    /** Reactive form-state mirrors for host-rendered action bars. */
    const processing = computed(() => internalForm.processing);
    const isDirty = computed(() => internalForm.isDirty);

    /**
     * Programmatic refetch of `dataUrl` — same loading-state and error-toast
     * semantics as the mount-time load. Lets a host refresh the form after an
     * out-of-band change (a sibling dialog saved the same record) without
     * remounting it. No-op when the form has no `dataUrl`.
     */
    function reload(): Promise<void> {
        return fetchRemoteData();
    }

    defineExpose({ reset, submit, reload, processing, isDirty, dataLoading, remoteData, currentValues, setValue });

    // ── Dynamic Options ───────────────────────────────────────────────────────────

    const dynamicOptions = ref<Record<string, SelectOption[]>>({});
    const loadingOptions = ref<Set<string>>(new Set());
    const optionErrors = ref<Set<string>>(new Set());
    const lastOptionUrl = ref<Record<string, string | null>>({});

    /**
     * Select-like fields that source their options from a URL.
     * Uses SELECT_TYPES so `checkbox-group` — which accepts `optionsUrl` in the
     * config type but was missing from the old literal list, leaving its options
     * permanently empty — is included.
     */
    const dynamicSelectFields = computed<SelectFieldConfig[]>(() =>
        flatFields.value.filter(
            (f): f is SelectFieldConfig => SELECT_TYPES.has(f.type) && !!(f as SelectFieldConfig).optionsUrl,
        ),
    );

    /**
     * Monotonic per-field request counter. A dependent select (`optionsUrl` as a
     * function of the parent's value) can have several requests in flight when the
     * user changes the parent quickly; without this, a SLOW earlier response
     * lands after a fast later one and overwrites the correct option list — or
     * flags a stale error on a field that has since loaded fine.
     */
    const optionRequestTokens = new Map<string, number>();

    async function fetchOptions(field: SelectFieldConfig, url: string): Promise<void> {
        const token = (optionRequestTokens.get(field.key) ?? 0) + 1;
        optionRequestTokens.set(field.key, token);
        const isCurrent = (): boolean => optionRequestTokens.get(field.key) === token;

        loadingOptions.value = new Set([...loadingOptions.value, field.key]);
        try {
            const options = await api.get<SelectOption[]>(url);
            // Superseded response — drop it entirely: no options write, no error
            // flag, no toast. The newer request owns this field's state.
            if (!isCurrent()) return;
            dynamicOptions.value = { ...dynamicOptions.value, [field.key]: options };
            if (optionErrors.value.has(field.key)) {
                const cleared = new Set(optionErrors.value);
                cleared.delete(field.key);
                optionErrors.value = cleared;
            }
        } catch {
            if (!isCurrent()) return;
            // Field-level error indicator + toast instead of a silent empty list.
            dynamicOptions.value = { ...dynamicOptions.value, [field.key]: [] };
            optionErrors.value = new Set([...optionErrors.value, field.key]);
            toast.add({
                severity: 'error',
                summary: trans('sk-common.error'),
                detail: trans('sk-common.options_load_error'),
                group: 'bc',
                life: 4000,
            });
        } finally {
            // Only the current request clears the spinner: a superseded one must
            // not switch it off while its successor is still loading.
            if (isCurrent()) {
                const next = new Set(loadingOptions.value);
                next.delete(field.key);
                loadingOptions.value = next;
            }
        }
    }

    async function syncDynamicOptions(optionUrls: Record<string, string | null>, isInitial = false): Promise<void> {
        const skipReset = isInitial || restoringDefaults.value;
        for (const field of dynamicSelectFields.value) {
            const url = optionUrls[field.key] ?? null;
            const prev = lastOptionUrl.value[field.key] ?? undefined;
            if (url === prev) {
                continue;
            }

            lastOptionUrl.value = { ...lastOptionUrl.value, [field.key]: url };

            if (!url) {
                dynamicOptions.value = { ...dynamicOptions.value, [field.key]: [] };
                continue;
            }

            if (!skipReset && prev !== undefined) {
                setValue(field.key, null);
            }

            await fetchOptions(field, url);
        }
    }

    const dynamicOptionUrls = computed<Record<string, string | null>>(() =>
        Object.fromEntries(
            dynamicSelectFields.value.map((field) => {
                const url =
                    typeof field.optionsUrl === 'function'
                        ? field.optionsUrl(currentValues.value)
                        : (field.optionsUrl ?? null);

                return [field.key, url];
            }),
        ),
    );

    onMounted(() => syncDynamicOptions(dynamicOptionUrls.value, true));

    watch(dynamicOptionUrls, (optionUrls) => {
        void syncDynamicOptions(optionUrls);
    });

    // ── Field Helpers ─────────────────────────────────────────────────────────────

    function applyFilter(items: SelectOption[], filter?: OptionFilter): SelectOption[] {
        if (!filter) return items;
        if (filter.only) {
            const allowed = new Set(filter.only.map(String));
            return items.filter((item) => allowed.has(String(item.value)));
        }
        if (filter.except) {
            const excluded = new Set(filter.except.map(String));
            return items.filter((item) => !excluded.has(String(item.value)));
        }
        return items;
    }

    function getOptions(field: FieldConfig): SelectOption[] {
        if (!SELECT_TYPES.has(field.type)) {
            return [];
        }
        const sf = field as SelectFieldConfig;
        const defKey = sf.definitionKey ?? sf.enumKey;
        if (defKey) {
            return applyFilter(definitionOptions(defKey), sf.definitionFilter ?? sf.enumFilter);
        }
        return sf.optionsUrl ? (dynamicOptions.value[field.key] ?? []) : (sf.options ?? []);
    }

    function isVisible(field: FieldConfig): boolean {
        return field.visible ? field.visible(currentValues.value) : true;
    }

    function isDisabled(field: FieldConfig): boolean {
        if (isReadOnly.value) {
            return true;
        }
        return field.disabled ? field.disabled(currentValues.value) : false;
    }

    function isLoading(field: FieldConfig): boolean {
        return loadingOptions.value.has(field.key);
    }

    function optionError(field: FieldConfig): boolean {
        return optionErrors.value.has(field.key);
    }

    function hasInlineLabel(field: FieldConfig): boolean {
        return INLINE_LABEL_TYPES.has(field.type);
    }

    function hasInlineFieldLabel(field: FieldConfig): boolean {
        return !hasInlineLabel(field) && field.labelPlacement === 'inline';
    }

    function isControlRight(field: FieldConfig): boolean {
        return field.controlPosition === 'right';
    }

    /** True for section fields rendered in the two-column "aside" layout. */
    function isAsideSection(field: FieldConfig): boolean {
        return field.type === 'section' && (field as SectionFieldConfig).aside === true;
    }

    // ── Actions ───────────────────────────────────────────────────────────────────

    /** Host suppresses the whole bar and drives submit via the exposed `submit()`. */
    const actionsHidden = computed(() => props.config.actionLabels?.hideActions === true);

    const showTopActions = computed(() => {
        if (isDialogMode.value) return false;
        const pos = props.config.actionsPosition;
        return pos === 'top' || pos === 'both';
    });

    const showBottomActions = computed(() => {
        if (isDialogMode.value) return true;
        const pos = props.config.actionsPosition;
        return !pos || pos === 'bottom' || pos === 'both';
    });

    const slots = useSlots();
    const hasActionArea = computed(
        () =>
            !actionsHidden.value &&
            (isInternalMode.value || !!slots.actions || !!slots['actions-start'] || !!slots['actions-end']),
    );

    // ── Grid helpers ──────────────────────────────────────────────────────────────

    const colsClassMap: Record<number, string> = {
        1: 'grid-cols-1',
        2: 'grid-cols-1 md:grid-cols-2',
        3: 'grid-cols-1 md:grid-cols-3',
        4: 'grid-cols-1 md:grid-cols-2 lg:grid-cols-4',
        5: 'grid-cols-1 md:grid-cols-5',
        6: 'grid-cols-1 md:grid-cols-6',
        7: 'grid-cols-1 md:grid-cols-7',
        8: 'grid-cols-1 md:grid-cols-8',
        9: 'grid-cols-1 md:grid-cols-9',
        10: 'grid-cols-1 md:grid-cols-10',
        11: 'grid-cols-1 md:grid-cols-11',
        12: 'grid-cols-1 md:grid-cols-12',
    };

    // Statik — Tailwind 4 JIT dinamik `col-span-${n}` purge eder. Bu map purge-safe.
    const colSpanClassMap: Record<number, string> = {
        1: 'md:col-span-1',   2: 'md:col-span-2',   3: 'md:col-span-3',
        4: 'md:col-span-4',   5: 'md:col-span-5',   6: 'md:col-span-6',
        7: 'md:col-span-7',   8: 'md:col-span-8',   9: 'md:col-span-9',
        10: 'md:col-span-10', 11: 'md:col-span-11', 12: 'md:col-span-12',
    };

    const gridClass = computed(() => colsClassMap[props.config.cols] ?? 'grid-cols-2');

    // ── Card mode ────────────────────────────────────────────────────────────────

    const isTransparentCard = computed(() => props.config.inDialog || !props.config.isCard);

    // ── renderCtx — passed to SkFormFieldRenderer ─────────────────────────────

    const renderCtx = computed<RenderCtx>(() => ({
        config: props.config,
        getValue,
        setValue,
        getOptions,
        isVisible,
        isDisabled,
        isLoading,
        optionError,
        hasInlineLabel,
        hasInlineFieldLabel,
        isControlRight,
        isTranslatableField,
        displayLabel,
        translatableErrorsFor,
        activeErrors: activeErrors.value,
        colsClassMap,
        colSpanClassMap,
        currentValues: currentValues.value,
    }));
</script>

<template>
    <SkCard
        :title="config.cardTitle ? $t(config.cardTitle) : undefined"
        :subtitle="config.cardSubtitle ? $t(config.cardSubtitle) : undefined"
        :transparent="isTransparentCard"
    >
        <template v-if="$slots['title-end']" #title-end>
            <slot name="title-end" />
        </template>
        <template #content>
            <!-- ── Loading skeleton (when dataUrl is set and data is loading) ──────── -->
            <div v-if="dataLoading" class="sk-fb__skeleton" :class="config.cssClass">
                <div class="grid gap-5" :class="gridClass">
                    <div
                        v-for="field in flatFields.filter((f) => !NON_INPUT_TYPES.has(f.type) && !f.hidden)"
                        :key="field.key"
                        class="flex flex-col gap-2"
                        :class="field.cssClass"
                    >
                        <div class="h-4 w-24 rounded bg-surface-200 dark:bg-surface-700 animate-pulse" />
                        <div class="h-10 rounded bg-surface-200 dark:bg-surface-700 animate-pulse" />
                    </div>
                </div>
            </div>

            <!-- ── Data load error (dataUrl fetch failed) — in-form retry ──────────── -->
            <div
                v-else-if="dataError"
                class="sk-fb__data-error flex flex-col items-center gap-3 py-8 text-center"
                :class="config.cssClass"
            >
                <i class="pi pi-exclamation-triangle text-2xl text-red-500" />
                <p class="text-surface-600 dark:text-surface-300">{{ $t('sk-common.data_load_error') }}</p>
                <Button
                    :label="$t('sk-button.retry')"
                    icon="pi pi-refresh"
                    severity="secondary"
                    outlined
                    type="button"
                    @click="fetchRemoteData"
                />
            </div>

            <!--
        Root element is <form> in internal mode (config.submit set) so that
        type="submit" buttons work naturally. In v-model mode it's a plain <div>.
    -->
            <component
                :is="isInternalMode ? 'form' : 'div'"
                v-else
                class="sk-fb"
                :class="[
                    { 'sk-fb--dialog': isDialogMode, 'sk-fb--divided': config.dividers && config.layout === 'horizontal' },
                    config.cssClass,
                ]"
                @submit.prevent="handleSubmit"
            >
                <!-- ── Top actions ─────────────────────────────────────────────────────── -->
                <div v-if="showTopActions && hasActionArea" class="sk-fb__actions sk-fb__actions--top">
                    <slot name="actions-start" />
                    <template v-if="isInternalMode">
                        <Button
                            v-if="showCancelButton"
                            :label="cancelLabel"
                            :icon="cancelIcon"
                            severity="secondary"
                            outlined
                            type="button"
                            @click="handleCancel"
                        />
                    </template>
                    <slot name="actions" />
                    <template v-if="isInternalMode">
                        <Button
                            v-if="!config.actionLabels?.hideSubmit && !isReadOnly"
                            :label="
                                config.actionLabels?.submit
                                    ? $t(config.actionLabels.submit)
                                    : isEditMode
                                        ? $t('sk-button.update')
                                        : $t('sk-button.save')
                            "
                            :icon="config.actionLabels?.submitIcon ?? (isEditMode ? 'pi pi-check' : 'pi pi-plus')"
                            type="submit"
                            :loading="internalForm.processing"
                            :disabled="!internalForm.isDirty"
                        />
                    </template>
                    <slot name="actions-end" />
                </div>

                <!-- ── Fields grid ─────────────────────────────────────────────────────── -->
                <div class="sk-fb__grid" :class="gridClass">
                    <template v-for="field in resolvedFields" :key="field.key">
                        <!--
                            Section fields are handled by SkFormFieldRenderer (Card wrapper + grid).
                            Hidden fields (non-section): render as hidden input directly.
                            Visible fields: delegate all rendering to SkFormFieldRenderer.
                        -->
                        <input
                            v-if="field.hidden && field.type !== 'section'"
                            type="hidden"
                            :name="field.key"
                            :value="String(getValue(field.key) ?? '')"
                        >

                        <div
                            v-else-if="isVisible(field)"
                            :class="[
                                field.type !== 'section' ? field.cssClass : undefined,
                                field.type !== 'section' && field.colSpan
                                    ? colSpanClassMap[Math.min(Math.max(field.colSpan, 1), config.cols)]
                                    : undefined,
                                isAsideSection(field) ? 'sk-fb__aside-wrap' : undefined,
                            ]"
                            :style="field.type === 'section' ? 'grid-column: 1 / -1' : undefined"
                        >
                            <SkFormFieldRenderer :field="field" :ctx="renderCtx">
                                <template v-for="(_, name) in $slots" #[name]="slotData">
                                    <slot :name="name" v-bind="slotData ?? {}" />
                                </template>
                            </SkFormFieldRenderer>
                        </div>
                    </template>
                </div>

                <!-- ── Bottom actions ─────────────────────────────────────────────────── -->
                <div v-if="showBottomActions && hasActionArea" class="sk-fb__actions sk-fb__actions--bottom">
                    <small
                        v-if="isInternalMode && !isDialogMode && !isReadOnly && internalForm.isDirty"
                        class="mr-auto flex items-center gap-1.5 text-amber-600 dark:text-amber-400"
                    >
                        <i class="pi pi-exclamation-circle text-xs" />
                        {{ $t('sk-common.unsaved') }}
                    </small>
                    <slot name="actions-start" />
                    <template v-if="isInternalMode">
                        <Button
                            v-if="showCancelButton"
                            :label="cancelLabel"
                            :icon="cancelIcon"
                            severity="secondary"
                            :outlined="!isDialogMode"
                            :text="isDialogMode"
                            type="button"
                            @click="handleCancel"
                        />
                    </template>
                    <slot name="actions" />
                    <template v-if="isInternalMode">
                        <Button
                            v-if="!config.actionLabels?.hideSubmit && !isReadOnly"
                            :label="
                                config.actionLabels?.submit
                                    ? $t(config.actionLabels.submit)
                                    : isEditMode
                                        ? $t('sk-button.update')
                                        : $t('sk-button.save')
                            "
                            :icon="config.actionLabels?.submitIcon ?? (isEditMode ? 'pi pi-save' : 'pi pi-save')"
                            type="submit"
                            severity="primary"
                            :loading="internalForm.processing"
                            :disabled="!internalForm.isDirty"
                        />
                    </template>
                    <slot name="actions-end" />
                </div>
            </component>
        </template>
    </SkCard>
</template>
