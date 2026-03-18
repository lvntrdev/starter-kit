<script setup lang="ts">
    import type {
        ColorSelectorFieldConfig,
        ExistingMedia,
        FieldConfig,
        FileUploadFieldConfig,
        InputNumberFieldConfig,
        InputOtpFieldConfig,
        InputTextFieldConfig,
        PasswordFieldConfig,
        SelectFieldConfig,
        SelectOption,
        TextareaFieldConfig,
        ToggleButtonFieldConfig,
    } from '@lvntr/components/FormBuilder/core';
    import ColorSelector from '@lvntr/components/FormBuilder/SkColorSelector.vue';
    import { useApi } from '@/composables/useApi';
    import { useConfirm } from '@/composables/useConfirm';
    import { trans } from 'laravel-vue-i18n';

    interface Props {
        field: FieldConfig;
        value: unknown;
        disabled?: boolean;
        invalid?: boolean;
        options?: SelectOption[];
        loading?: boolean;
    }

    const props = withDefaults(defineProps<Props>(), {
        disabled: false,
        invalid: false,
        options: () => [],
        loading: false,
    });

    const emit = defineEmits<{
        update: [value: unknown];
    }>();

    // ── Type-narrowed accessors ───────────────────────────────────────────────────

    const asInputText = computed(() => props.field as InputTextFieldConfig);
    const asInputNumber = computed(() => props.field as InputNumberFieldConfig);
    const asInputOtp = computed(() => props.field as InputOtpFieldConfig);
    const asSelect = computed(() => props.field as SelectFieldConfig);
    const asPassword = computed(() => props.field as PasswordFieldConfig);
    const asTextarea = computed(() => props.field as TextareaFieldConfig);
    const asToggleButton = computed(() => props.field as ToggleButtonFieldConfig);
    const asFileUpload = computed(() => props.field as FileUploadFieldConfig);
    const asColorSelector = computed(() => props.field as ColorSelectorFieldConfig);

    /** Extra props passed to the underlying PrimeVue component via .props({...}). */
    const extraProps = computed(() => props.field.componentProps ?? {});

    /** Translate option labels via trans() so consumers can pass translation keys. */
    const translatedOptions = computed(() => props.options.map((opt) => ({ ...opt, label: trans(opt.label) })));
    const controlPosition = computed(() => props.field.controlPosition ?? 'left');

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

    const anyVal = computed({
        get: () => props.value ?? null,
        set: (v) => emit('update', v),
    });

    // ── File Upload ───────────────────────────────────────────────────────────────

    const { confirmDelete } = useConfirm();
    const api = useApi();
    const existingFiles = ref<ExistingMedia[]>([]);

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

    function handleFileSelect(event: Event): void {
        const input = event.target as HTMLInputElement;
        if (!input.files?.length) return;

        const config = asFileUpload.value;
        const files = Array.from(input.files);

        if (config.multiple) {
            const currentFiles = (props.value as File[]) ?? [];
            const keepIds = existingFiles.value.map((m) => m.id);
            emit('update', [...keepIds, ...currentFiles, ...files]);
        } else {
            emit('update', files[0]);
        }

        input.value = '';
    }

    function removeNewFile(index: number): void {
        confirmDelete(() => {
            const config = asFileUpload.value;
            if (config.multiple) {
                const currentValue = (props.value as (File | number)[]) ?? [];
                const newFiles = currentValue.filter((item): item is File => item instanceof File);
                const keepIds = currentValue.filter((item): item is number => typeof item === 'number');
                newFiles.splice(index, 1);
                emit('update', [...keepIds, ...newFiles]);
            } else {
                emit('update', null);
            }
        }, 'Are you sure you want to remove this file?');
    }

    function removeExistingFile(media: ExistingMedia): void {
        confirmDelete(async () => {
            try {
                await api.delete(`/media/${media.id}`);
                existingFiles.value = existingFiles.value.filter((m) => m.id !== media.id);

                if (asFileUpload.value.multiple) {
                    const currentValue = (props.value as (File | number)[]) ?? [];
                    emit(
                        'update',
                        currentValue.filter((item) => item !== media.id),
                    );
                }
            } catch {
                // silently fail
            }
        }, 'Are you sure you want to delete this file? This action cannot be undone.');
    }

    const newFiles = computed<File[]>(() => {
        if (props.field.type !== 'file-upload') return [];
        const config = asFileUpload.value;

        if (config.multiple) {
            const val = (props.value as (File | number)[]) ?? [];
            return val.filter((item): item is File => item instanceof File);
        }

        return props.value instanceof File ? [props.value] : [];
    });

    const newFilePreviews = computed(() =>
        newFiles.value.map((file) => ({
            file,
            url: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
        })),
    );
</script>

<template>
    <!-- InputText -->
    <InputText
        v-if="field.type === 'input-text'"
        :id="field.key"
        v-model="stringVal"
        :type="asInputText.inputType ?? 'text'"
        :placeholder="asInputText.placeholder ? $t(asInputText.placeholder) : undefined"
        :disabled="disabled"
        :invalid="invalid"
        class="w-full"
        v-bind="extraProps"
    />

    <!-- InputNumber -->
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
        :disabled="disabled"
        :invalid="invalid"
        class="w-full"
        v-bind="extraProps"
    />

    <!-- InputOtp -->
    <InputOtp
        v-else-if="field.type === 'input-otp'"
        :id="field.key"
        v-model="stringVal"
        :length="asInputOtp.length ?? 6"
        :mask="asInputOtp.mask"
        :integer-only="asInputOtp.integerOnly"
        :disabled="disabled"
        :invalid="invalid"
        v-bind="extraProps"
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
            loading ? $t('common.loading') : asSelect.placeholder ? $t(asSelect.placeholder) : $t('common.select')
        "
        :show-clear="asSelect.showClear"
        :filter="asSelect.filter"
        :disabled="disabled || loading"
        :invalid="invalid"
        :loading="loading"
        class="w-full"
        v-bind="extraProps"
    />

    <!-- MultiSelect -->
    <MultiSelect
        v-else-if="field.type === 'multiselect'"
        :id="field.key"
        v-model="anyVal"
        :options="translatedOptions"
        :option-label="asSelect.optionLabel ?? 'label'"
        :option-value="asSelect.optionValue ?? 'value'"
        :placeholder="
            loading ? $t('common.loading') : asSelect.placeholder ? $t(asSelect.placeholder) : $t('common.select')
        "
        :filter="asSelect.filter"
        :disabled="disabled || loading"
        :invalid="invalid"
        :loading="loading"
        class="w-full"
        v-bind="extraProps"
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
                    {{ option.label }}
                </label>
            </template>
            <RadioButton
                :input-id="`${field.key}_${option.value}`"
                :name="field.key"
                :value="option.value"
                :model-value="anyVal"
                :disabled="disabled"
                v-bind="extraProps"
                @update:model-value="(v) => emit('update', v)"
            />
            <template v-if="controlPosition !== 'right'">
                <label :for="`${field.key}_${option.value}`" class="sk-fb__group-label">
                    {{ option.label }}
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
        :disabled="disabled"
        :invalid="invalid"
        v-bind="extraProps"
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
                    {{ option.label }}
                </label>
            </template>
            <Checkbox
                :input-id="`${field.key}_${option.value}`"
                :value="option.value"
                :model-value="(value as unknown[]) ?? []"
                :disabled="disabled"
                v-bind="extraProps"
                @update:model-value="(v) => emit('update', v)"
            />
            <template v-if="controlPosition !== 'right'">
                <label :for="`${field.key}_${option.value}`" class="sk-fb__group-label">
                    {{ option.label }}
                </label>
            </template>
        </div>
    </div>

    <!-- Password -->
    <Password
        v-else-if="field.type === 'password'"
        :id="field.key"
        v-model="stringVal"
        :placeholder="asPassword.placeholder ? $t(asPassword.placeholder) : undefined"
        :feedback="asPassword.feedback ?? false"
        :toggle-mask="asPassword.toggleMask ?? true"
        :disabled="disabled"
        :invalid="invalid"
        input-class="w-full"
        class="w-full"
        v-bind="extraProps"
    />

    <!-- SelectButton -->
    <SelectButton
        v-else-if="field.type === 'select-button'"
        :id="field.key"
        v-model="anyVal"
        :options="translatedOptions"
        :option-label="asSelect.optionLabel ?? 'label'"
        :option-value="asSelect.optionValue ?? 'value'"
        :disabled="disabled || loading"
        :invalid="invalid"
        v-bind="extraProps"
    />

    <!-- Textarea -->
    <Textarea
        v-else-if="field.type === 'textarea'"
        :id="field.key"
        v-model="stringVal"
        :placeholder="asTextarea.placeholder ? $t(asTextarea.placeholder) : undefined"
        :rows="asTextarea.rows ?? 4"
        :auto-resize="asTextarea.autoResize ?? false"
        :disabled="disabled"
        :invalid="invalid"
        class="w-full"
        v-bind="extraProps"
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
        :disabled="disabled"
        :invalid="invalid"
        v-bind="extraProps"
    />

    <!-- ToggleSwitch -->
    <ToggleSwitch
        v-else-if="field.type === 'toggle-switch'"
        :id="field.key"
        v-model="boolVal"
        :disabled="disabled"
        :invalid="invalid"
        v-bind="extraProps"
    />

    <!-- FileUpload -->
    <div v-else-if="field.type === 'file-upload'" class="w-full">
        <!-- Upload zone -->
        <label
            :for="field.key"
            class="sk-fb__upload-zone"
            :class="[invalid ? 'sk-fb__upload-zone--invalid' : '', disabled ? 'sk-fb__upload-zone--disabled' : '']"
        >
            <div class="sk-fb__upload-inner">
                <i class="pi pi-cloud-upload sk-fb__upload-icon" />
                <span class="sk-fb__upload-text">
                    {{ asFileUpload.multiple ? 'Drop files here or click to upload' : 'Click to select a file' }}
                </span>
                <span v-if="asFileUpload.maxFileSize" class="sk-fb__upload-hint">
                    Maks. {{ formatFileSize(asFileUpload.maxFileSize) }}
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
                <a :href="media.url" target="_blank" rel="noopener noreferrer" class="sk-fb__file-preview-link">
                    <img
                        v-if="isImageMime(media.mime_type)"
                        :src="media.url"
                        :alt="media.name"
                        class="sk-fb__file-thumb"
                    >
                    <i v-else class="pi pi-file sk-fb__file-icon" />
                </a>
                <div class="sk-fb__file-info">
                    <a
                        :href="media.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="sk-fb__file-name sk-fb__file-name--link"
                    >
                        {{ media.name }}
                    </a>
                    <p class="sk-fb__file-size">
                        {{ formatFileSize(media.size) }}
                    </p>
                </div>
                <button v-if="!disabled" type="button" class="sk-fb__file-remove" @click="removeExistingFile(media)">
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
                <a
                    v-if="item.url"
                    :href="item.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="sk-fb__file-preview-link"
                >
                    <img :src="item.url" :alt="item.file.name" class="sk-fb__file-thumb">
                </a>
                <i v-else class="pi pi-file sk-fb__file-icon sk-fb__file-icon--new" />
                <div class="sk-fb__file-info">
                    <p class="sk-fb__file-name">
                        {{ item.file.name }}
                    </p>
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
        :disabled="disabled"
        :invalid="invalid"
        v-bind="extraProps"
    />
</template>
