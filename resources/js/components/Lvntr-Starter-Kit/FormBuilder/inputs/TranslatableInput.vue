<script setup lang="ts">
    import type {
        TranslatableEditorFieldConfig,
        TranslatableTextareaFieldConfig,
        TranslatableTextFieldConfig,
    } from '../core';
    import type { ResolvedLocale } from '../core/locales';
    import { applyLocaleFilters, resolveContentLocales } from '../core/locales';
    import EditorInput from './EditorInput.vue';
    import { usePage } from '@inertiajs/vue3';
    import { trans } from 'laravel-vue-i18n';
    import type { SharedPageProps } from '@/types';

    interface Props {
        field: TranslatableTextFieldConfig | TranslatableTextareaFieldConfig | TranslatableEditorFieldConfig;
        modelValue: Record<string, string> | null | undefined;
        errors?: Record<string, string>;
        disabled?: boolean;
        autocomplete?: string;
        /**
         * Render the field label inside this component (next to the locale switcher).
         * SkForm enables this in vertical layout and skips its own label.
         */
        showLabel?: boolean;
    }

    const props = withDefaults(defineProps<Props>(), {
        modelValue: undefined,
        errors: undefined,
        disabled: false,
        autocomplete: undefined,
        showLabel: false,
    });

    const emit = defineEmits<{
        'update:modelValue': [Record<string, string>];
        update: [Record<string, string>];
    }>();

    // ── Locale resolution ────────────────────────────────────────────────────────

    const page = usePage<SharedPageProps>();

    // Resolution + filtering live in `core/locales` so SkForm's translatable
    // DEFAULT (the empty `{ locale: '' }` record it seeds on a create form) is
    // built from exactly the locales rendered here.
    const resolvedLocales = computed<ResolvedLocale[]>(() =>
        applyLocaleFilters(resolveContentLocales(page.props), props.field),
    );

    const isSingle = computed(() => resolvedLocales.value.length <= 1);
    const fieldType = computed(() => props.field.type);

    // ── Internal reactive value ──────────────────────────────────────────────────

    const value = ref<Record<string, string>>({});

    function normalizeValue(raw: Record<string, string> | null | undefined): Record<string, string> {
        const base = raw ?? {};
        const normalized: Record<string, string> = {};
        for (const loc of resolvedLocales.value) {
            normalized[loc.code] = base[loc.code] ?? '';
        }
        return normalized;
    }

    // init
    value.value = normalizeValue(props.modelValue);

    watch(
        () => props.modelValue,
        (next) => {
            value.value = normalizeValue(next);
        },
    );

    watch(resolvedLocales, () => {
        value.value = normalizeValue(props.modelValue);
    });

    function handleUpdate(locale: string, newVal: string): void {
        // Keep internal display state in sync for the resolved locales only.
        value.value = { ...value.value, [locale]: newVal };

        // Emit against the *original* modelValue base — not the normalized
        // `value` ref, which holds only the resolved locales. Locales present in
        // modelValue but outside resolvedLocales (onlyLocales/exceptLocales
        // filtering, or content languages absent from availableLocales) would
        // otherwise be silently dropped on every keystroke — a data-loss bug.
        const emitted = { ...(props.modelValue ?? {}), [locale]: newVal };
        emit('update:modelValue', emitted);
        emit('update', emitted);
    }

    // ── Tabs state ───────────────────────────────────────────────────────────────

    const activeTab = ref<string>('');

    watch(
        resolvedLocales,
        (locs) => {
            if (!activeTab.value && locs.length > 0) {
                activeTab.value = locs[0].code;
            }
        },
        { immediate: true },
    );

    // ── Helpers ──────────────────────────────────────────────────────────────────

    function getFlagEmoji(code: string): string {
        const flagMap: Record<string, string> = {
            tr: '🇹🇷',
            en: '🇬🇧',
            de: '🇩🇪',
            fr: '🇫🇷',
            es: '🇪🇸',
            it: '🇮🇹',
            ru: '🇷🇺',
            ar: '🇸🇦',
            zh: '🇨🇳',
            ja: '🇯🇵',
            ko: '🇰🇷',
            pt: '🇵🇹',
            nl: '🇳🇱',
            pl: '🇵🇱',
        };
        if (code in flagMap) return flagMap[code];
        // Generic fallback: regional indicator letters from ISO country code
        const upper = code.slice(0, 2).toUpperCase();
        return [...upper].map((c) => String.fromCodePoint(c.codePointAt(0)! + 127397)).join('');
    }

    function formatLocaleLabel(loc: { code: string; name: string }): string {
        if (props.field.localeLabelStyle === 'name') return loc.name;
        if (props.field.localeLabelStyle === 'flag') return getFlagEmoji(loc.code);
        return loc.code.toUpperCase();
    }

    function errorFor(locale: string): string | undefined {
        return props.errors?.[`${props.field.key}.${locale}`];
    }

    function hasErrorInLocale(locale: string): boolean {
        return !!errorFor(locale);
    }

    // ── Editor field accessor ─────────────────────────────────────────────────────

    const asTranslatableEditor = computed(() => props.field as TranslatableEditorFieldConfig);
    const asTranslatableTextarea = computed(() => props.field as TranslatableTextareaFieldConfig);
    const asTranslatableText = computed(() => props.field as TranslatableTextFieldConfig);

    // ── Label / active locale ─────────────────────────────────────────────────────

    const displayFieldLabel = computed(() =>
        props.field.translateLabel === false ? props.field.label : trans(props.field.label),
    );

    const activeLocale = computed(
        () => resolvedLocales.value.find((l) => l.code === activeTab.value) ?? resolvedLocales.value[0],
    );

    // ── Label association / required semantics ───────────────────────────────────

    const labelId = computed(() => `${props.field.key}__label`);

    /**
     * A rich-text editor's editable node is a contenteditable `<div>` — not a
     * labelable element, so `label[for]` can never target it. That type is named
     * through `aria-labelledby` on the editable node instead (see EditorInput),
     * and the label drops its `for` rather than pointing at nothing.
     */
    const labelFor = computed(() => (fieldType.value === 'translatable-editor' ? undefined : props.field.key));

    /**
     * Requiredness is announced on the control ONLY when a single locale renders.
     *
     * The kit's own rule builder (`HasTranslatableRules::translatableRules()`)
     * keeps `required` on the default locale and turns every other locale into
     * `nullable`, and nothing in the page props says which of the rendered
     * CONTENT locales that default is — so marking each locale tab's input
     * required would announce optional locales as required. With one locale the
     * question does not arise: that input is the one the asterisk is about. In
     * multi-locale mode the asterisk stays visual (`aria-hidden`), exactly as it
     * was before the label was associated.
     */
    const requiredControl = computed(() => props.field.required === true && isSingle.value);
</script>

<template>
    <div class="sk-translatable-field" :class="{ 'sk-translatable-field--single': isSingle }">
        <!-- Tek dil -->
        <template v-if="isSingle">
            <template v-if="resolvedLocales.length === 1">
                <label v-if="showLabel" :id="labelId" :for="labelFor" class="sk-fb__label">
                    {{ displayFieldLabel }}
                    <span v-if="field.required" class="sk-fb__required" aria-hidden="true">*</span><span v-if="requiredControl" class="sr-only">{{ trans('sk-common.required') }}</span>
                </label>
                <InputText
                    v-if="fieldType === 'translatable-text'"
                    :id="field.key"
                    :aria-required="requiredControl ? 'true' : undefined"
                    :model-value="value[resolvedLocales[0].code] ?? ''"
                    :type="asTranslatableText.inputType ?? 'text'"
                    :placeholder="asTranslatableText.placeholder"
                    :maxlength="asTranslatableText.maxLength"
                    :disabled="disabled"
                    :invalid="!!errorFor(resolvedLocales[0].code)"
                    :autocomplete="autocomplete"
                    class="w-full"
                    v-bind="field.componentProps"
                    @update:model-value="(v) => handleUpdate(resolvedLocales[0].code, String(v ?? ''))"
                />
                <Textarea
                    v-else-if="fieldType === 'translatable-textarea'"
                    :id="field.key"
                    :aria-required="requiredControl ? 'true' : undefined"
                    :model-value="value[resolvedLocales[0].code] ?? ''"
                    :placeholder="asTranslatableTextarea.placeholder"
                    :rows="asTranslatableTextarea.rows ?? 4"
                    :auto-resize="asTranslatableTextarea.autoResize ?? false"
                    :disabled="disabled"
                    :invalid="!!errorFor(resolvedLocales[0].code)"
                    class="w-full"
                    v-bind="field.componentProps"
                    @update:model-value="(v) => handleUpdate(resolvedLocales[0].code, String(v ?? ''))"
                />
                <EditorInput
                    v-else-if="fieldType === 'translatable-editor'"
                    :id="field.key"
                    :aria-labelledby="showLabel ? labelId : undefined"
                    :aria-required="requiredControl"
                    :model-value="value[resolvedLocales[0].code] ?? ''"
                    :min-height="asTranslatableEditor.minHeight ?? '10rem'"
                    :toolbar="asTranslatableEditor.toolbar ?? 'standard'"
                    :disabled="disabled"
                    :invalid="!!errorFor(resolvedLocales[0].code)"
                    class="w-full"
                    @update:model-value="(v) => handleUpdate(resolvedLocales[0].code, v)"
                />
                <small v-if="errorFor(resolvedLocales[0].code)" class="text-red-500 block mt-1">
                    {{ errorFor(resolvedLocales[0].code) }}
                </small>
            </template>
            <!-- resolvedLocales boşsa hiçbir şey render etme -->
        </template>

        <!-- Çok dil: label satırında dil seçici, altında yalnız aktif dilin girişi -->
        <template v-else>
            <div class="mb-2 flex items-center gap-3">
                <label v-if="showLabel" :id="labelId" :for="labelFor" class="sk-fb__label mb-0!">
                    {{ displayFieldLabel }}
                    <span v-if="field.required" class="sk-fb__required" aria-hidden="true">*</span><span v-if="requiredControl" class="sr-only">{{ trans('sk-common.required') }}</span>
                </label>
                <div
                    class="inline-flex items-center gap-0.5 rounded-lg bg-surface-100 p-0.5 dark:bg-surface-800"
                    role="tablist"
                >
                    <button
                        v-for="loc in resolvedLocales"
                        :key="loc.code"
                        type="button"
                        role="tab"
                        :aria-selected="activeTab === loc.code"
                        class="inline-flex cursor-pointer items-center gap-1 rounded-md border-none px-2.5 py-0.5 text-xs font-semibold transition-colors"
                        :class="
                            activeTab === loc.code
                                ? 'bg-white text-primary shadow-sm dark:bg-surface-700'
                                : 'bg-transparent text-surface-500 hover:text-surface-700 dark:text-surface-400 dark:hover:text-surface-200'
                        "
                        @click="activeTab = loc.code"
                    >
                        {{ formatLocaleLabel(loc) }}
                        <i
                            v-if="hasErrorInLocale(loc.code)"
                            class="pi pi-exclamation-circle text-red-500"
                            aria-hidden="true"
                        />
                    </button>
                </div>
            </div>

            <template v-if="activeLocale">
                <InputText
                    v-if="fieldType === 'translatable-text'"
                    :id="field.key"
                    :aria-required="requiredControl ? 'true' : undefined"
                    :model-value="value[activeLocale.code] ?? ''"
                    :type="asTranslatableText.inputType ?? 'text'"
                    :placeholder="asTranslatableText.placeholder"
                    :maxlength="asTranslatableText.maxLength"
                    :disabled="disabled"
                    :invalid="hasErrorInLocale(activeLocale.code)"
                    :autocomplete="autocomplete"
                    class="w-full"
                    v-bind="field.componentProps"
                    @update:model-value="(v) => handleUpdate(activeLocale!.code, String(v ?? ''))"
                />
                <Textarea
                    v-else-if="fieldType === 'translatable-textarea'"
                    :id="field.key"
                    :aria-required="requiredControl ? 'true' : undefined"
                    :model-value="value[activeLocale.code] ?? ''"
                    :placeholder="asTranslatableTextarea.placeholder"
                    :rows="asTranslatableTextarea.rows ?? 4"
                    :auto-resize="asTranslatableTextarea.autoResize ?? false"
                    :disabled="disabled"
                    :invalid="hasErrorInLocale(activeLocale.code)"
                    class="w-full"
                    v-bind="field.componentProps"
                    @update:model-value="(v) => handleUpdate(activeLocale!.code, String(v ?? ''))"
                />
                <EditorInput
                    v-else-if="fieldType === 'translatable-editor'"
                    :id="field.key"
                    :aria-labelledby="showLabel ? labelId : undefined"
                    :aria-required="requiredControl"
                    :model-value="value[activeLocale.code] ?? ''"
                    :min-height="asTranslatableEditor.minHeight ?? '10rem'"
                    :toolbar="asTranslatableEditor.toolbar ?? 'standard'"
                    :disabled="disabled"
                    :invalid="hasErrorInLocale(activeLocale.code)"
                    class="w-full"
                    @update:model-value="(v) => handleUpdate(activeLocale!.code, v)"
                />
                <small v-if="errorFor(activeLocale.code)" class="text-red-500 block mt-1">
                    {{ errorFor(activeLocale.code) }}
                </small>
            </template>
        </template>
    </div>
</template>
