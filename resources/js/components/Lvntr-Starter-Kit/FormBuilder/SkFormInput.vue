<script setup lang="ts">
    import type {
        ColorSelectorFieldConfig,
        EditorFieldConfig,
        ExistingMedia,
        FieldConfig,
        FileUploadFieldConfig,
        InputNumberFieldConfig,
        InputOtpFieldConfig,
        InputMaskFieldConfig,
        DatePickerFieldConfig,
        InputTextFieldConfig,
        PasswordFieldConfig,
        PasswordGeneratorConfig,
        SelectFieldConfig,
        SelectOption,
        TextareaFieldConfig,
        ToggleButtonFieldConfig,
        TranslatableTextFieldConfig,
        TranslatableTextareaFieldConfig,
        TranslatableEditorFieldConfig,
    } from './core';
    import { controlId, describedById, passwordUsesWrapper } from './core/ids';
    import ColorSelector from './SkColorSelector.vue';
    import EditorInput from './inputs/EditorInput.vue';
    import TranslatableInput from './inputs/TranslatableInput.vue';
    import SkIcon from '../ui/SkIcon.vue';
    import { generatePassword } from './utils/passwordGenerator';
    import FilePreviewModal, {
        suggestedPreviewWidth,
        type FilePreviewFile,
    } from '../ui/FilePreviewModal.vue';
    import { InputGroup } from 'primevue';
    import { useToast } from 'primevue/usetoast';
    import { useApi } from '@/composables/useApi';
    import { useConfirm } from '@/composables/useConfirm';
    import { useDialog } from '@/composables/useDialog';
    import { useImageLightbox } from '@/composables/useImageLightbox';
    import { trans } from 'laravel-vue-i18n';

    interface Props {
        field: FieldConfig;
        value: unknown;
        disabled?: boolean;
        invalid?: boolean;
        options?: SelectOption[];
        loading?: boolean;
        translatableErrors?: Record<string, string>;
        /** Translatable alanlarda label'ı TranslatableInput bassın (dil seçici label yanında). */
        translatableLabel?: boolean;
        /**
         * True when SkFormFieldRenderer prints an error / option-error / hint <small>
         * for this field. Drives `aria-describedby`; the renderer owns that state, so
         * we take a boolean instead of reaching into it.
         */
        described?: boolean;
    }

    const props = withDefaults(defineProps<Props>(), {
        disabled: false,
        invalid: false,
        options: () => [],
        loading: false,
        translatableErrors: undefined,
        translatableLabel: false,
        described: false,
    });

    const emit = defineEmits<{
        update: [value: unknown];
    }>();

    // ── Type-narrowed accessors ───────────────────────────────────────────────────

    const asInputText = computed(() => props.field as InputTextFieldConfig);
    const asInputNumber = computed(() => props.field as InputNumberFieldConfig);
    const asInputOtp = computed(() => props.field as InputOtpFieldConfig);
    const asInputMask = computed(() => props.field as InputMaskFieldConfig);
    const asDatePicker = computed(() => props.field as DatePickerFieldConfig);
    const asSelect = computed(() => props.field as SelectFieldConfig);
    const asPassword = computed(() => props.field as PasswordFieldConfig);
    const asTextarea = computed(() => props.field as TextareaFieldConfig);
    const asEditor = computed(() => props.field as EditorFieldConfig);
    const asTranslatable = computed(
        () =>
            props.field as
                | TranslatableTextFieldConfig
                | TranslatableTextareaFieldConfig
                | TranslatableEditorFieldConfig,
    );
    const translatableValue = computed(() => props.value as Record<string, string> | null | undefined);
    const asToggleButton = computed(() => props.field as ToggleButtonFieldConfig);
    const asFileUpload = computed(() => props.field as FileUploadFieldConfig);
    const asColorSelector = computed(() => props.field as ColorSelectorFieldConfig);

    /**
     * Extra props passed to the underlying PrimeVue component via .props({...}).
     * Required fields get `aria-required="true"` (screen-reader semantics) unless
     * the consumer overrides it explicitly through componentProps.
     */
    const extraProps = computed(() => {
        const base = props.field.componentProps ?? {};
        return props.field.required ? { 'aria-required': 'true', ...base } : base;
    });

    /**
     * Form-level disabled/invalid is a floor, not a default: `.props({ disabled: false })`
     * can no longer unlock a read-only form, while `.props({ disabled: true })` still
     * disables a single field. Bound AFTER `v-bind="extraProps"` in every control so the
     * spread cannot win (Vue merges template props in source order).
     */
    const forcedDisabled = computed(() => props.disabled || !!props.field.componentProps?.disabled);
    const forcedInvalid = computed(() => props.invalid || !!props.field.componentProps?.invalid);

    /**
     * `aria-describedby` target — only emitted while the renderer actually prints the
     * matching <small>, otherwise the attribute would point at a missing element.
     */
    const describedBy = computed(() => (props.described ? describedById(props.field) : undefined));

    /** Translate option labels (and optional descriptions) via trans() so consumers can pass translation keys. */
    const translatedOptions = computed(() =>
        props.options.map((opt) => ({
            ...opt,
            label: trans(opt.label),
            description: opt.description ? trans(opt.description) : undefined,
        })),
    );
    const controlPosition = computed(() => props.field.controlPosition ?? 'left');

    /**
     * Render password fields as plain InputText + our own eye/generate
     * addons by default. Only fall back to PrimeVue's `<Password>` when the
     * consumer explicitly opts into its strength-meter feedback, since that
     * component owns its own absolute-positioned icons and fights InputGroup.
     */
    const useCustomPasswordInput = computed(
        () => props.field.type === 'password' && !passwordUsesWrapper(props.field),
    );

    // ── Icon support ─────────────────────────────────────────────────────────────

    /**
     * Generic input icon resolver.
     * BaseFieldConfig.icon is preferred; InputTextFieldConfig.icon is the
     * legacy fallback (deprecated, kept for backwards-compatibility).
     */
    const inputIcon = computed<string | undefined>(() => {
        const base = (props.field as { icon?: string }).icon;
        const legacy = (props.field as InputTextFieldConfig).icon;
        return base ?? legacy;
    });

    const inputIconPosition = computed<'left' | 'right'>(
        () =>
            (props.field as { iconPosition?: 'left' | 'right' }).iconPosition ??
            (props.field as InputTextFieldConfig).iconPosition ??
            'left',
    );

    const SUPPORTS_INPUT_ICON = new Set(['input-text', 'input-number', 'input-mask', 'password']);

    /** True when the password field should render our custom eye toggle. */
    const showPasswordToggle = computed(() => useCustomPasswordInput.value && (asPassword.value.toggleMask ?? true));

    /** True when the password field should render the generate button. */
    const showPasswordGenerator = computed(() => useCustomPasswordInput.value && !!asPassword.value.generator);

    /** True when the inline strength meter renders below the password input. */
    const showStrengthMeter = computed(
        () => props.field.type === 'password' && useCustomPasswordInput.value && !!asPassword.value.strengthMeter,
    );

    /**
     * Strength score (1–4) from length + character-class variety. 0 = empty.
     * Length alone never exceeds "fair" — variety is required for the top tiers,
     * so a long single-class string still reads as weak.
     */
    const passwordStrength = computed(() => {
        const pw = stringVal.value ?? '';
        const len = pw.length;
        if (len === 0) {
            return { score: 0, label: '', barClass: '', textClass: '', length: 0 };
        }

        const variety =
            (/[a-z]/.test(pw) ? 1 : 0) +
            (/[A-Z]/.test(pw) ? 1 : 0) +
            (/\d/.test(pw) ? 1 : 0) +
            (/[^a-zA-Z0-9]/.test(pw) ? 1 : 0);

        let score = 0;
        if (len >= 8) score++;
        if (len >= 12) score++;
        if (variety >= 2) score++;
        if (variety >= 3 && len >= 8) score++;
        score = Math.min(4, Math.max(1, score));

        const meta = [
            { label: 'sk-common.password_strength_weak', bar: 'bg-red-500', text: 'text-red-500' },
            { label: 'sk-common.password_strength_fair', bar: 'bg-orange-500', text: 'text-orange-500' },
            { label: 'sk-common.password_strength_good', bar: 'bg-amber-500', text: 'text-amber-500' },
            { label: 'sk-common.password_strength_strong', bar: 'bg-green-500', text: 'text-green-500' },
        ][score - 1];

        return { score, label: meta.label, barClass: meta.bar, textClass: meta.text, length: len };
    });

    /**
     * True when the field should render inside an IconField wrapper.
     * Exclusions:
     * - groupPrefix/groupSuffix present → InputGroup wins, IconField deactivated.
     * - password with PrimeVue feedback path → component owns its own icons.
     * - password with eye-toggle/generator addons → those force an InputGroup
     *   wrapper, and PrimeVue's InputGroup expects its input as a direct child;
     *   nesting IconField inside it breaks the border/radius grouping. The icon
     *   falls back to a leading InputGroupAddon instead (see template).
     */
    const usesIconField = computed(() => {
        if (!SUPPORTS_INPUT_ICON.has(props.field.type)) return false;
        if (!inputIcon.value) return false;
        if (props.field.groupPrefix || props.field.groupSuffix) return false;
        if (props.field.type === 'password') {
            if (!useCustomPasswordInput.value) return false;
            if (showPasswordToggle.value || showPasswordGenerator.value) return false;
        }
        return true;
    });

    /**
     * Leading icon for a grouped custom-password field, rendered as an
     * InputGroupAddon because IconField cannot live inside InputGroup.
     */
    const showPasswordIconAddon = computed(
        () =>
            props.field.type === 'password' &&
            useCustomPasswordInput.value &&
            !usesIconField.value &&
            !!inputIcon.value &&
            !props.field.groupPrefix,
    );

    /** Local visibility state — only used when we render the custom eye toggle. */
    const passwordVisible = ref(false);

    function handleTranslatableUpdate(value: Record<string, string>): void {
        emit('update', value);
    }

    /** Resolve the generator config: `true` → {}, object → itself. */
    const passwordGeneratorConfig = computed<PasswordGeneratorConfig>(() => {
        const raw = asPassword.value.generator;
        return typeof raw === 'object' && raw !== null ? raw : {};
    });

    /** InputGroup wrapper detection. */
    const hasGroup = computed(
        () =>
            !!(props.field.groupPrefix || props.field.groupSuffix) ||
            showPasswordToggle.value ||
            showPasswordGenerator.value,
    );
    const isIcon = (text: string) => text.startsWith('pi ');

    const stringVal = computed({
        get: () => (props.value as string) ?? '',
        set: (v) => emit('update', v),
    });

    const numberVal = computed({
        get: () => (props.value as number | null) ?? null,
        set: (v) => emit('update', v),
    });

    const boolVal = computed({
        get: () => (props.value as boolean) ?? false,
        set: (v) => emit('update', v),
    });

    const SELECT_TYPES = new Set(['select', 'multiselect', 'radio', 'select-button']);

    /**
     * Normalize the model value to match the option value type.
     * PrimeVue uses strict === comparison, so "1" !== 1 causes selection mismatch.
     */
    const anyVal = computed({
        get: () => {
            const raw = props.value ?? null;
            if (raw === null || !SELECT_TYPES.has(props.field.type) || !props.options.length) {
                return raw;
            }

            const valueKey = (props.field as SelectFieldConfig).optionValue ?? 'value';
            const sampleOption = props.options[0] as unknown as Record<string, unknown>;
            const sampleType = typeof sampleOption[valueKey];

            const cast = (v: unknown): unknown => {
                if (v === null || v === undefined) return v;
                if (sampleType === 'string') {
                    if (typeof v === 'boolean') return v ? '1' : '0';
                    return String(v);
                }
                if (sampleType === 'number') return Number(v);
                return v;
            };

            return Array.isArray(raw) ? raw.map(cast) : cast(raw);
        },
        set: (v) => emit('update', v),
    });

    const dateVal = computed({
        get: () => (props.value as Date | Date[] | null) ?? null,
        set: (v) => emit('update', v),
    });

    // ── Password generator ───────────────────────────────────────────────────────

    const toast = useToast();

    function handleGeneratePassword(): void {
        const value = generatePassword(passwordGeneratorConfig.value);
        emit('update', value);
        toast.add({
            severity: 'success',
            summary: trans('sk-common.password_generated'),
            detail: trans('sk-common.password_generated_detail'),
            group: 'bc',
            life: 3000,
        });
    }

    // ── File Upload ───────────────────────────────────────────────────────────────

    const { confirmDelete } = useConfirm();
    // `toast: false` — the one call on this instance (the immediate-mode media
    // DELETE below) reports its own `upload_delete_failed` toast; on the default,
    // useApi would add a second, generic toast for the same failure.
    const api = useApi({ toast: false });
    const existingFiles = ref<ExistingMedia[]>([]);
    /** Drag-and-drop hover state for the upload zone. */
    const isDragOver = ref(false);

    watchEffect(() => {
        if (props.field.type === 'file-upload') {
            existingFiles.value = [...(asFileUpload.value.existingMedia ?? [])];
        }
    });

    function formatFileSize(bytes: number): string {
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${(bytes / 1048576).toFixed(1)} MB`;
    }

    function isImageMime(mime: string): boolean {
        return mime.startsWith('image/');
    }

    function fileIcon(mime: string): string {
        if (mime === 'application/pdf') return 'pi pi-file-pdf';
        if (mime.includes('spreadsheet') || mime.includes('excel') || mime.includes('.sheet'))
            return 'pi pi-file-excel';
        if (mime.includes('wordprocessing') || mime.includes('msword') || mime.includes('.document'))
            return 'pi pi-file-word';
        return 'pi pi-file';
    }

    const dialog = useDialog();
    const lightbox = useImageLightbox();

    function openFilePreview(file: FilePreviewFile): void {
        if (file.mimeType?.startsWith('image/')) {
            lightbox.open(file.url, file.name);
            return;
        }
        const width = suggestedPreviewWidth(file.mimeType);
        dialog.open(FilePreviewModal, { file }, file.name, width ? { width } : {});
    }

    /**
     * Client-side `accept` check, used for DROPPED files only.
     *
     * The native picker enforces `accept` itself; a drop does not go through the
     * picker, so without this a drag-and-drop would bypass the restriction the
     * field declares. Non-matching drops are ignored exactly as the file dialog
     * ignores them (it never offers them in the first place).
     */
    function matchesAccept(file: File, accept?: string): boolean {
        if (!accept) return true;
        const patterns = accept
            .split(',')
            .map((p) => p.trim().toLowerCase())
            .filter(Boolean);
        if (!patterns.length) return true;

        const name = file.name.toLowerCase();
        const type = file.type.toLowerCase();

        return patterns.some((pattern) => {
            if (pattern.startsWith('.')) return name.endsWith(pattern);
            if (pattern.endsWith('/*')) return type.startsWith(pattern.slice(0, -1));
            return type === pattern;
        });
    }

    /**
     * Keep-list ids currently attached, deduplicated, in a stable order.
     *
     * Both sources are read on purpose:
     *  - `existingFiles` alone was the old bug — the ids were prepended again on
     *    every pick, so a second selection emitted each id twice.
     *  - `props.value` alone would be wrong on the FIRST pick: SkForm seeds the
     *    field from `initialData[key]` (`derivedDefaults`), and for a media field
     *    that is normally empty — the ids live under `existingMediaKey`. An empty
     *    keep-list makes a server-side sync delete every attached file.
     * Removal (immediate or deferred) drops the id from BOTH, so the union only
     * ever contains media that is still attached.
     */
    function currentKeepIds(): number[] {
        const seen = new Set<number>();
        const ids: number[] = [];

        for (const media of existingFiles.value) {
            if (seen.has(media.id)) continue;
            seen.add(media.id);
            ids.push(media.id);
        }
        for (const item of Array.isArray(props.value) ? (props.value as unknown[]) : []) {
            if (typeof item !== 'number' || seen.has(item)) continue;
            seen.add(item);
            ids.push(item);
        }

        return ids;
    }

    /** Newly picked (not yet uploaded) files held in the current value. */
    function currentNewFiles(): File[] {
        if (asFileUpload.value.multiple) {
            const val = Array.isArray(props.value) ? (props.value as unknown[]) : [];
            return val.filter((item): item is File => item instanceof File);
        }
        return props.value instanceof File ? [props.value] : [];
    }

    /**
     * Single entry point for both the picker and the drop zone.
     *
     * Limits are enforced ONLY when configured (`maxFileSize` per file,
     * `fileLimit` on existing + new in multiple mode). Rejected files never
     * disappear silently: the acceptable ones are still added and one toast
     * reports everything that was skipped.
     */
    function addFiles(incoming: File[]): void {
        if (props.disabled || !incoming.length) return;

        const config = asFileUpload.value;
        // `accept` filters BEFORE the single-file slice: a drop whose first item is
        // the wrong type must not shadow an acceptable file behind it.
        let candidates = incoming.filter((file) => matchesAccept(file, config.accept));
        if (!config.multiple) candidates = candidates.slice(0, 1);
        if (!candidates.length) return;

        const problems: string[] = [];

        if (config.maxFileSize) {
            const maxSize = config.maxFileSize;
            const tooLarge = candidates.filter((file) => file.size > maxSize);
            if (tooLarge.length) {
                candidates = candidates.filter((file) => file.size <= maxSize);
                problems.push(
                    trans('sk-common.upload_file_too_large', {
                        size: formatFileSize(maxSize),
                        files: tooLarge.map((file) => file.name).join(', '),
                    }),
                );
            }
        }

        const keepIds = currentKeepIds();
        const heldFiles = currentNewFiles();

        if (config.multiple && config.fileLimit) {
            const room = config.fileLimit - keepIds.length - heldFiles.length;
            if (candidates.length > room) {
                candidates = room > 0 ? candidates.slice(0, room) : [];
                problems.push(trans('sk-common.upload_file_limit', { limit: String(config.fileLimit) }));
            }
        }

        if (problems.length) {
            toast.add({
                severity: 'warn',
                summary: trans('sk-common.error'),
                detail: problems.join(' '),
                group: 'bc',
                life: 5000,
            });
        }

        if (!candidates.length) return;

        if (config.multiple) {
            emit('update', [...keepIds, ...heldFiles, ...candidates]);
        } else {
            emit('update', candidates[0]);
        }
    }

    function handleFileSelect(event: Event): void {
        const input = event.target as HTMLInputElement;
        if (!input.files?.length) return;

        addFiles(Array.from(input.files));

        input.value = '';
    }

    function handleDragOver(): void {
        if (props.disabled) return;
        isDragOver.value = true;
    }

    function handleDragLeave(): void {
        isDragOver.value = false;
    }

    function handleDrop(event: DragEvent): void {
        isDragOver.value = false;
        if (props.disabled) return;

        const dropped = Array.from(event.dataTransfer?.files ?? []);
        if (dropped.length) addFiles(dropped);
    }

    function removeNewFile(index: number): void {
        confirmDelete(() => {
            if (asFileUpload.value.multiple) {
                const files = currentNewFiles();
                files.splice(index, 1);
                emit('update', [...currentKeepIds(), ...files]);
            } else {
                emit('update', null);
            }
        }, trans('sk-common.upload_confirm_remove_new'));
    }

    /**
     * Detaches an already-stored media item.
     *
     * Default (`deferExistingRemoval` unset/false): the file is deleted right
     * away with `DELETE /media/{id}` — gone even if the form is never saved.
     * Opt-in defer: nothing is requested here; the item only leaves the list and
     * the emitted keep-list, and the save-side keep-list sync performs the
     * deletion (see `FileUploadFieldConfig.deferExistingRemoval`).
     */
    function removeExistingFile(media: ExistingMedia): void {
        const config = asFileUpload.value;

        function detach(): void {
            existingFiles.value = existingFiles.value.filter((m) => m.id !== media.id);

            if (config.multiple) {
                const currentValue = Array.isArray(props.value) ? (props.value as unknown[]) : [];
                emit(
                    'update',
                    currentValue.filter((item) => item !== media.id),
                );
            } else if (config.deferExistingRemoval) {
                emit('update', null);
            }
        }

        confirmDelete(async () => {
            if (config.deferExistingRemoval) {
                detach();
                return;
            }

            try {
                await api.delete(`/media/${media.id}`);
                detach();
            } catch {
                // The media is still attached — leave `existingFiles` alone and say so,
                // instead of the silent no-op that made a failed delete look successful.
                toast.add({
                    severity: 'error',
                    summary: trans('sk-common.error'),
                    detail: trans('sk-common.upload_delete_failed'),
                    group: 'bc',
                    life: 5000,
                });
            }
        }, trans('sk-common.upload_confirm_delete_existing'));
    }

    const newFiles = computed<File[]>(() => {
        if (props.field.type !== 'file-upload') return [];
        return currentNewFiles();
    });

    /**
     * One object URL per `File`, created on first preview and revoked when the
     * file leaves the value or the component unmounts. The previews used to call
     * `URL.createObjectURL` inside the computed, so every unrelated recompute
     * minted another blob URL that stayed alive until a full page reload.
     */
    const objectUrls = new Map<File, string>();

    function objectUrlFor(file: File): string {
        let url = objectUrls.get(file);
        if (!url) {
            url = URL.createObjectURL(file);
            objectUrls.set(file, url);
        }
        return url;
    }

    watch(newFiles, (files) => {
        const kept = new Set(files);
        for (const [file, url] of objectUrls) {
            if (kept.has(file)) continue;
            URL.revokeObjectURL(url);
            objectUrls.delete(file);
        }
    });

    onBeforeUnmount(() => {
        for (const url of objectUrls.values()) URL.revokeObjectURL(url);
        objectUrls.clear();
    });

    const newFilePreviews = computed(() =>
        newFiles.value.map((file) => ({
            file,
            url: objectUrlFor(file),
            isImage: file.type.startsWith('image/'),
        })),
    );
</script>

<template>
    <component :is="hasGroup ? InputGroup : 'div'" :class="{ contents: !hasGroup, 'w-full': hasGroup }">
        <InputGroupAddon v-if="field.groupPrefix">
            <i v-if="isIcon(field.groupPrefix)" :class="field.groupPrefix" />
            <template v-else>
                {{ field.groupPrefix }}
            </template>
        </InputGroupAddon>

        <!-- Leading icon for grouped password fields (IconField can't nest in InputGroup) -->
        <InputGroupAddon v-if="showPasswordIconAddon">
            <SkIcon :icon="inputIcon!" />
        </InputGroupAddon>

        <!-- InputText -->
        <IconField
            v-if="field.type === 'input-text' && usesIconField"
            :icon-position="inputIconPosition"
            class="w-full"
        >
            <InputIcon>
                <SkIcon :icon="inputIcon!" />
            </InputIcon>
            <InputText
                :id="field.key"
                v-model="stringVal"
                :type="asInputText.inputType ?? 'text'"
                :placeholder="asInputText.placeholder ? $t(asInputText.placeholder) : undefined"
                class="w-full"
                :aria-describedby="describedBy"
                v-bind="extraProps"
                :disabled="forcedDisabled"
                :invalid="forcedInvalid"
            />
        </IconField>
        <InputText
            v-else-if="field.type === 'input-text'"
            :id="field.key"
            v-model="stringVal"
            :type="asInputText.inputType ?? 'text'"
            :placeholder="asInputText.placeholder ? $t(asInputText.placeholder) : undefined"
            class="w-full"
            :aria-describedby="describedBy"
            v-bind="extraProps"
            :disabled="forcedDisabled"
            :invalid="forcedInvalid"
        />

        <!-- InputNumber -->
        <IconField
            v-else-if="field.type === 'input-number' && usesIconField"
            :icon-position="inputIconPosition"
            class="w-full"
        >
            <InputIcon>
                <SkIcon :icon="inputIcon!" />
            </InputIcon>
            <InputNumber
                :id="field.key"
                v-model="numberVal"
                :placeholder="asInputNumber.placeholder ? $t(asInputNumber.placeholder) : undefined"
                :min="asInputNumber.min"
                :max="asInputNumber.max"
                :step="asInputNumber.step"
                :prefix="asInputNumber.prefix"
                :suffix="asInputNumber.suffix"
                :show-buttons="asInputNumber.showButtons"
                :min-fraction-digits="asInputNumber.minFractionDigits"
                :max-fraction-digits="asInputNumber.maxFractionDigits"
                :use-grouping="asInputNumber.useGrouping ?? true"
                class="w-full"
                :input-id="controlId(field)"
                :aria-describedby="describedBy"
                v-bind="extraProps"
                :disabled="forcedDisabled"
                :invalid="forcedInvalid"
                @input="numberVal = ($event.value ?? null) as number | null"
            />
        </IconField>
        <InputNumber
            v-else-if="field.type === 'input-number'"
            :id="field.key"
            v-model="numberVal"
            :placeholder="asInputNumber.placeholder ? $t(asInputNumber.placeholder) : undefined"
            :min="asInputNumber.min"
            :max="asInputNumber.max"
            :step="asInputNumber.step"
            :prefix="asInputNumber.prefix"
            :suffix="asInputNumber.suffix"
            :show-buttons="asInputNumber.showButtons"
            :min-fraction-digits="asInputNumber.minFractionDigits"
            :max-fraction-digits="asInputNumber.maxFractionDigits"
            :use-grouping="asInputNumber.useGrouping ?? true"
            class="w-full"
            :input-id="controlId(field)"
            :aria-describedby="describedBy"
            v-bind="extraProps"
            :disabled="forcedDisabled"
            :invalid="forcedInvalid"
            @input="numberVal = ($event.value ?? null) as number | null"
        />

        <!-- InputOtp -->
        <InputOtp
            v-else-if="field.type === 'input-otp'"
            :id="field.key"
            v-model="stringVal"
            :length="asInputOtp.length ?? 6"
            :mask="asInputOtp.mask"
            :integer-only="asInputOtp.integerOnly"
            :aria-describedby="describedBy"
            v-bind="extraProps"
            :disabled="forcedDisabled"
            :invalid="forcedInvalid"
        />

        <!-- InputMask -->
        <IconField
            v-else-if="field.type === 'input-mask' && usesIconField"
            :icon-position="inputIconPosition"
            class="w-full"
        >
            <InputIcon>
                <SkIcon :icon="inputIcon!" />
            </InputIcon>
            <InputMask
                :id="field.key"
                v-model="stringVal"
                :mask="asInputMask.mask"
                :placeholder="asInputMask.placeholder ? $t(asInputMask.placeholder) : undefined"
                :slot-char="asInputMask.slotChar ?? '_'"
                :auto-clear="asInputMask.autoClear ?? false"
                :unmask="asInputMask.unmask ?? false"
                class="w-full"
                :aria-describedby="describedBy"
                v-bind="extraProps"
                :disabled="forcedDisabled"
                :invalid="forcedInvalid"
            />
        </IconField>
        <InputMask
            v-else-if="field.type === 'input-mask'"
            :id="field.key"
            v-model="stringVal"
            :mask="asInputMask.mask"
            :placeholder="asInputMask.placeholder ? $t(asInputMask.placeholder) : undefined"
            :slot-char="asInputMask.slotChar ?? '_'"
            :auto-clear="asInputMask.autoClear ?? false"
            :unmask="asInputMask.unmask ?? false"
            class="w-full"
            :aria-describedby="describedBy"
            v-bind="extraProps"
            :disabled="forcedDisabled"
            :invalid="forcedInvalid"
        />

        <!-- DatePicker -->
        <DatePicker
            v-else-if="field.type === 'date-picker'"
            :id="field.key"
            v-model="dateVal"
            :date-format="asDatePicker.dateFormat ?? 'dd/mm/yy'"
            :selection-mode="asDatePicker.selectionMode ?? 'single'"
            :show-time="asDatePicker.showTime ?? false"
            :hour-format="asDatePicker.hourFormat ?? '24'"
            :show-icon="asDatePicker.showIcon ?? true"
            :icon-display="asDatePicker.iconDisplay ?? 'input'"
            :min-date="asDatePicker.minDate"
            :max-date="asDatePicker.maxDate"
            :show-button-bar="asDatePicker.showButtonBar ?? false"
            :number-of-months="asDatePicker.numberOfMonths ?? 1"
            :view="asDatePicker.view ?? 'date'"
            :inline="asDatePicker.inline ?? false"
            :placeholder="asDatePicker.placeholder ? $t(asDatePicker.placeholder) : undefined"
            class="w-full"
            :input-id="controlId(field)"
            :aria-describedby="describedBy"
            v-bind="extraProps"
            :disabled="forcedDisabled"
            :invalid="forcedInvalid"
        />

        <!-- Select -->
        <Select
            v-else-if="field.type === 'select'"
            :id="field.key"
            v-model="anyVal"
            :options="translatedOptions"
            :option-label="asSelect.optionLabel ?? 'label'"
            :option-value="asSelect.optionValue ?? 'value'"
            :placeholder="
                loading
                    ? $t('sk-common.loading')
                    : asSelect.placeholder
                        ? $t(asSelect.placeholder)
                        : $t('sk-common.select')
            "
            :show-clear="asSelect.showClear"
            :filter="asSelect.filter"
            :loading="loading"
            class="w-full"
            :input-id="controlId(field)"
            :aria-describedby="describedBy"
            v-bind="extraProps"
            :disabled="forcedDisabled || loading"
            :invalid="forcedInvalid"
        />

        <!-- MultiSelect -->
        <MultiSelect
            v-else-if="field.type === 'multiselect'"
            :id="field.key"
            v-model="anyVal"
            display="chip"
            :options="translatedOptions"
            :option-label="asSelect.optionLabel ?? 'label'"
            :option-value="asSelect.optionValue ?? 'value'"
            :placeholder="
                loading
                    ? $t('sk-common.loading')
                    : asSelect.placeholder
                        ? $t(asSelect.placeholder)
                        : $t('sk-common.select')
            "
            :filter="asSelect.filter"
            :loading="loading"
            class="w-full"
            :input-id="controlId(field)"
            :aria-describedby="describedBy"
            v-bind="extraProps"
            :disabled="forcedDisabled || loading"
            :invalid="forcedInvalid"
        />

        <!-- Radio -->
        <div
            v-else-if="field.type === 'radio'"
            class="sk-fb__group"
            :class="asSelect.radioLayout === 'vertical' ? 'sk-fb__group--vertical' : 'sk-fb__group--horizontal'"
        >
            <div v-if="loading" class="sk-fb__group-loading">
                <i class="pi pi-spin pi-spinner text-sm" />
                Loading...
            </div>
            <div v-for="option in translatedOptions" v-else :key="String(option.value)" class="sk-fb__group-item">
                <template v-if="controlPosition === 'right'">
                    <label :for="`${field.key}_${option.value}`" class="sk-fb__group-label">
                        <span class="sk-fb__group-label-text">{{ option.label }}</span>
                        <span v-if="option.description" class="sk-fb__group-label-desc">{{ option.description }}</span>
                    </label>
                </template>
                <RadioButton
                    :input-id="`${field.key}_${option.value}`"
                    :name="field.key"
                    :value="option.value"
                    :model-value="anyVal"
                    v-bind="extraProps"
                    :disabled="forcedDisabled"
                    @update:model-value="(v) => emit('update', v)"
                />
                <template v-if="controlPosition !== 'right'">
                    <label :for="`${field.key}_${option.value}`" class="sk-fb__group-label">
                        <span class="sk-fb__group-label-text">{{ option.label }}</span>
                        <span v-if="option.description" class="sk-fb__group-label-desc">{{ option.description }}</span>
                    </label>
                </template>
            </div>
        </div>

        <!-- Checkbox — label is rendered by FormBuilder, not here -->
        <Checkbox
            v-else-if="field.type === 'checkbox'"
            v-model="boolVal"
            :input-id="field.key"
            :binary="true"
            :aria-describedby="describedBy"
            v-bind="extraProps"
            :disabled="forcedDisabled"
            :invalid="forcedInvalid"
        />

        <!-- CheckboxGroup -->
        <div
            v-else-if="field.type === 'checkbox-group'"
            class="sk-fb__group"
            :class="asSelect.radioLayout === 'vertical' ? 'sk-fb__group--vertical' : 'sk-fb__group--horizontal'"
        >
            <div v-if="loading" class="sk-fb__group-loading">
                <i class="pi pi-spin pi-spinner text-sm" />
                Loading...
            </div>
            <div v-for="option in translatedOptions" v-else :key="String(option.value)" class="sk-fb__group-item">
                <template v-if="controlPosition === 'right'">
                    <label :for="`${field.key}_${option.value}`" class="sk-fb__group-label">
                        <span class="sk-fb__group-label-text">{{ option.label }}</span>
                        <span v-if="option.description" class="sk-fb__group-label-desc">{{ option.description }}</span>
                    </label>
                </template>
                <Checkbox
                    :input-id="`${field.key}_${option.value}`"
                    :value="option.value"
                    :model-value="(value as unknown[]) ?? []"
                    v-bind="extraProps"
                    :disabled="forcedDisabled"
                    @update:model-value="(v) => emit('update', v)"
                />
                <template v-if="controlPosition !== 'right'">
                    <label :for="`${field.key}_${option.value}`" class="sk-fb__group-label">
                        <span class="sk-fb__group-label-text">{{ option.label }}</span>
                        <span v-if="option.description" class="sk-fb__group-label-desc">{{ option.description }}</span>
                    </label>
                </template>
            </div>
        </div>

        <!-- Password (default path) — rendered as plain InputText so the
             InputGroup can cleanly host our own eye-toggle + generate buttons
             without fighting PrimeVue Password's absolute-positioned icons.
             IconField is only used when there is no InputGroup wrapper
             (groupPrefix/groupSuffix absent), which usesIconField guarantees. -->
        <IconField
            v-else-if="field.type === 'password' && useCustomPasswordInput && usesIconField"
            :icon-position="inputIconPosition"
            class="w-full"
        >
            <InputIcon>
                <SkIcon :icon="inputIcon!" />
            </InputIcon>
            <InputText
                :id="field.key"
                v-model="stringVal"
                :type="passwordVisible ? 'text' : 'password'"
                :placeholder="asPassword.placeholder ? $t(asPassword.placeholder) : undefined"
                autocomplete="new-password"
                class="w-full"
                :aria-describedby="describedBy"
                v-bind="extraProps"
                :disabled="forcedDisabled"
                :invalid="forcedInvalid"
            />
        </IconField>
        <InputText
            v-else-if="field.type === 'password' && useCustomPasswordInput"
            :id="field.key"
            v-model="stringVal"
            :type="passwordVisible ? 'text' : 'password'"
            :placeholder="asPassword.placeholder ? $t(asPassword.placeholder) : undefined"
            autocomplete="new-password"
            class="w-full"
            :aria-describedby="describedBy"
            v-bind="extraProps"
            :disabled="forcedDisabled"
            :invalid="forcedInvalid"
        />

        <!-- Password (PrimeVue with strength feedback meter) -->
        <Password
            v-else-if="field.type === 'password'"
            :id="field.key"
            v-model="stringVal"
            :placeholder="asPassword.placeholder ? $t(asPassword.placeholder) : undefined"
            :feedback="asPassword.feedback ?? false"
            :toggle-mask="asPassword.toggleMask ?? true"
            input-class="w-full"
            class="w-full"
            :input-id="controlId(field)"
            :aria-describedby="describedBy"
            v-bind="extraProps"
            :disabled="forcedDisabled"
            :invalid="forcedInvalid"
        />

        <!-- SelectButton -->
        <SelectButton
            v-else-if="field.type === 'select-button'"
            :id="field.key"
            v-model="anyVal"
            :options="translatedOptions"
            :option-label="asSelect.optionLabel ?? 'label'"
            :option-value="asSelect.optionValue ?? 'value'"
            :aria-describedby="describedBy"
            v-bind="extraProps"
            :disabled="forcedDisabled || loading"
            :invalid="forcedInvalid"
        />

        <!-- Textarea -->
        <Textarea
            v-else-if="field.type === 'textarea'"
            :id="field.key"
            v-model="stringVal"
            :placeholder="asTextarea.placeholder ? $t(asTextarea.placeholder) : undefined"
            :rows="asTextarea.rows ?? 4"
            :auto-resize="asTextarea.autoResize ?? false"
            class="w-full"
            :aria-describedby="describedBy"
            v-bind="extraProps"
            :disabled="forcedDisabled"
            :invalid="forcedInvalid"
        />

        <!-- Editor (Tiptap rich text) -->
        <EditorInput
            v-else-if="field.type === 'editor'"
            :id="field.key"
            v-model="stringVal"
            :placeholder="asEditor.placeholder ? $t(asEditor.placeholder) : undefined"
            :toolbar="asEditor.toolbar ?? 'standard'"
            :min-height="asEditor.minHeight ?? '10rem'"
            :image-upload="asEditor.imageUpload"
            :links="asEditor.links ?? false"
            :treat-empty-as-blank="asEditor.treatEmptyAsBlank ?? true"
            class="w-full"
            :aria-describedby="describedBy"
            v-bind="extraProps"
            :disabled="forcedDisabled"
            :invalid="forcedInvalid"
        />

        <!-- ToggleButton -->
        <ToggleButton
            v-else-if="field.type === 'toggle-button'"
            :id="field.key"
            v-model="boolVal"
            :on-label="asToggleButton.onLabel ?? 'Yes'"
            :off-label="asToggleButton.offLabel ?? 'No'"
            :on-icon="asToggleButton.onIcon"
            :off-icon="asToggleButton.offIcon"
            :aria-describedby="describedBy"
            v-bind="extraProps"
            :disabled="forcedDisabled"
            :invalid="forcedInvalid"
        />

        <!-- ToggleSwitch -->
        <ToggleSwitch
            v-else-if="field.type === 'toggle-switch'"
            :id="field.key"
            v-model="boolVal"
            :input-id="controlId(field)"
            :aria-describedby="describedBy"
            v-bind="extraProps"
            :disabled="forcedDisabled"
            :invalid="forcedInvalid"
        />

        <!-- FileUpload -->
        <div v-else-if="field.type === 'file-upload'" class="w-full">
            <!-- Upload zone -->
            <label
                :for="field.key"
                class="sk-fb__upload-zone"
                :class="[
                    invalid ? 'sk-fb__upload-zone--invalid' : '',
                    disabled ? 'sk-fb__upload-zone--disabled' : '',
                    isDragOver ? 'sk-fb__upload-zone--dragover' : '',
                ]"
                @dragenter.prevent="handleDragOver"
                @dragover.prevent="handleDragOver"
                @dragleave="handleDragLeave"
                @drop.prevent="handleDrop"
            >
                <div class="sk-fb__upload-inner">
                    <span class="sk-fb__upload-icon">
                        <i class="pi pi-cloud-upload" />
                    </span>
                    <span class="sk-fb__upload-text">
                        {{
                            asFileUpload.multiple
                                ? trans('sk-common.upload_drop_hint')
                                : trans('sk-common.upload_click_hint')
                        }}
                    </span>
                    <span v-if="asFileUpload.maxFileSize" class="sk-fb__upload-hint">
                        {{ trans('sk-common.upload_max_size', { size: formatFileSize(asFileUpload.maxFileSize) }) }}
                    </span>
                </div>
                <input
                    :id="field.key"
                    type="file"
                    class="hidden"
                    :accept="asFileUpload.accept"
                    :multiple="asFileUpload.multiple"
                    :disabled="disabled"
                    @change="handleFileSelect"
                >
            </label>

            <!-- Existing media list -->
            <div v-if="existingFiles.length" class="sk-fb__file-list">
                <div v-for="media in existingFiles" :key="`existing-${media.id}`" class="sk-fb__file-item">
                    <button
                        type="button"
                        class="sk-fb__file-preview-link"
                        @click="
                            openFilePreview({
                                url: media.url,
                                name: media.name,
                                mimeType: media.mime_type,
                                size: media.size,
                            })
                        "
                    >
                        <img
                            v-if="isImageMime(media.mime_type)"
                            :src="media.url"
                            :alt="media.name"
                            class="sk-fb__file-thumb"
                        >
                        <i v-else :class="[fileIcon(media.mime_type), 'sk-fb__file-icon']" />
                    </button>
                    <div class="sk-fb__file-info">
                        <button
                            type="button"
                            class="sk-fb__file-name sk-fb__file-name--link"
                            @click="
                                openFilePreview({
                                    url: media.url,
                                    name: media.name,
                                    mimeType: media.mime_type,
                                    size: media.size,
                                })
                            "
                        >
                            {{ media.name }}
                        </button>
                        <p class="sk-fb__file-size">
                            {{ formatFileSize(media.size) }}
                        </p>
                    </div>
                    <button
                        v-if="!disabled"
                        type="button"
                        class="sk-fb__file-remove"
                        @click="removeExistingFile(media)"
                    >
                        <i class="pi pi-times text-sm" />
                    </button>
                </div>
            </div>

            <!-- New file list -->
            <div v-if="newFiles.length" class="sk-fb__file-list">
                <div
                    v-for="(item, index) in newFilePreviews"
                    :key="`new-${index}`"
                    class="sk-fb__file-item sk-fb__file-item--new"
                >
                    <button
                        type="button"
                        class="sk-fb__file-preview-link"
                        @click="
                            openFilePreview({
                                url: item.url,
                                name: item.file.name,
                                mimeType: item.file.type,
                                size: item.file.size,
                            })
                        "
                    >
                        <img v-if="item.isImage" :src="item.url" :alt="item.file.name" class="sk-fb__file-thumb">
                        <i v-else :class="[fileIcon(item.file.type), 'sk-fb__file-icon']" />
                    </button>
                    <div class="sk-fb__file-info">
                        <button
                            type="button"
                            class="sk-fb__file-name sk-fb__file-name--link"
                            @click="
                                openFilePreview({
                                    url: item.url,
                                    name: item.file.name,
                                    mimeType: item.file.type,
                                    size: item.file.size,
                                })
                            "
                        >
                            {{ item.file.name }}
                        </button>
                        <p class="sk-fb__file-size">
                            {{ formatFileSize(item.file.size) }}
                        </p>
                    </div>
                    <button v-if="!disabled" type="button" class="sk-fb__file-remove" @click="removeNewFile(index)">
                        <i class="pi pi-times text-sm" />
                    </button>
                </div>
            </div>
        </div>

        <!-- ColorSelector -->
        <ColorSelector
            v-else-if="field.type === 'color-selector'"
            v-model="stringVal"
            :colors="asColorSelector.colors"
            :tones="asColorSelector.tones"
            :format="asColorSelector.format"
            :default-tone="asColorSelector.defaultTone"
            :aria-describedby="describedBy"
            v-bind="extraProps"
            :disabled="forcedDisabled"
            :invalid="forcedInvalid"
        />

        <!-- TranslatableInput (translatable-text / translatable-textarea / translatable-editor) -->
        <TranslatableInput
            v-else-if="
                field.type === 'translatable-text' ||
                    field.type === 'translatable-textarea' ||
                    field.type === 'translatable-editor'
            "
            :field="asTranslatable"
            :model-value="translatableValue"
            :errors="translatableErrors"
            :disabled="disabled"
            :show-label="translatableLabel"
            @update="handleTranslatableUpdate"
        />

        <InputGroupAddon v-if="field.groupSuffix">
            <i v-if="isIcon(field.groupSuffix)" :class="field.groupSuffix" />
            <template v-else>
                {{ field.groupSuffix }}
            </template>
        </InputGroupAddon>

        <InputGroupAddon v-if="showPasswordToggle" class="sk-fb__password-toggle">
            <Button
                v-tooltip.top="$t(passwordVisible ? 'sk-common.hide_password' : 'sk-common.show_password')"
                type="button"
                :icon="passwordVisible ? 'pi pi-eye-slash' : 'pi pi-eye'"
                severity="secondary"
                variant="text"
                :disabled="disabled"
                :aria-label="$t(passwordVisible ? 'sk-common.hide_password' : 'sk-common.show_password')"
                @click="passwordVisible = !passwordVisible"
            />
        </InputGroupAddon>

        <InputGroupAddon v-if="showPasswordGenerator" class="sk-fb__password-generator">
            <Button
                v-tooltip.top="$t('sk-common.generate_password')"
                type="button"
                icon="pi pi-refresh"
                severity="primary"
                variant="text"
                :disabled="disabled"
                :aria-label="$t('sk-common.generate_password')"
                @click="handleGeneratePassword"
            />
        </InputGroupAddon>
    </component>

    <!-- Inline password strength meter (4-segment bar + label + char count) -->
    <div v-if="showStrengthMeter" class="mt-2">
        <div class="flex gap-1.5">
            <span
                v-for="i in 4"
                :key="i"
                class="h-1 flex-1 rounded-full transition-colors"
                :class="i <= passwordStrength.score ? passwordStrength.barClass : 'bg-surface-200 dark:bg-surface-700'"
            />
        </div>
        <div v-if="passwordStrength.length > 0" class="mt-1.5 flex items-center justify-between text-xs">
            <span class="text-surface-600 dark:text-surface-300">
                {{ $t('sk-common.password_strength') }}:
                <span class="font-semibold" :class="passwordStrength.textClass">{{ $t(passwordStrength.label) }}</span>
            </span>
            <span class="text-surface-400 dark:text-surface-500">
                {{ $t('sk-common.characters_count', { count: String(passwordStrength.length) }) }}
            </span>
        </div>
    </div>
</template>
