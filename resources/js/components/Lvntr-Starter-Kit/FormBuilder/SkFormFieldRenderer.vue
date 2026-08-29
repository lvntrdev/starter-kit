<script setup lang="ts">
    import { trans } from 'laravel-vue-i18n';
    import type {
        FieldConfig,
        FormBuilderConfig,
        SectionFieldConfig,
        SelectOption,
        SlotFieldConfig,
        TitleFieldConfig,
        ToggleSwitchFieldConfig,
    } from './core';
    import { controlId, describedById } from './core/ids';
    import SkFormInput from './SkFormInput.vue';
    import SkIcon from '../ui/SkIcon.vue';
    import SkCard from '../ui/SkCard.vue';

    defineOptions({ name: 'SkFormFieldRenderer' });

    /**
     * Declare slots so vue-tsc can correctly infer scoped slot types used by
     * consuming components (e.g. FileManagerTab #field-xxx="{ value, onUpdate }").
     *
     * Dynamic slot names (field-${key}) are represented as a catch-all string
     * index that carries the standard field-override scope.
     */
    defineSlots<{
        // slot-type field slots — keyed by slotName or field.key
        [slotName: string]: (props: {
            /** Current form values (available on slot-type fields). */
            values?: Record<string, unknown>;
            /** The field config (available on field-override slots). */
            field?: FieldConfig;
            /** Current field value (available on field-override slots). */
            value?: unknown;
            /** Update callback (available on field-override slots). */
            onUpdate?: (v: unknown) => void;
        }) => unknown;
    }>();

    /**
     * Parent slots map — used for checking whether a slot is provided before
     * forwarding it into the recursive SkFormFieldRenderer call for section children.
     */
    const parentSlots = useSlots();

    export interface RenderCtx {
        config: FormBuilderConfig;
        getValue: (key: string) => unknown;
        setValue: (key: string, value: unknown) => void;
        getOptions: (field: FieldConfig) => SelectOption[];
        isVisible: (field: FieldConfig) => boolean;
        isDisabled: (field: FieldConfig) => boolean;
        isLoading: (field: FieldConfig) => boolean;
        /** True when a dynamic `optionsUrl` fetch failed for this field. */
        optionError: (field: FieldConfig) => boolean;
        hasInlineLabel: (field: FieldConfig) => boolean;
        hasInlineFieldLabel: (field: FieldConfig) => boolean;
        isControlRight: (field: FieldConfig) => boolean;
        isTranslatableField: (field: FieldConfig) => boolean;
        displayLabel: (field: FieldConfig) => string;
        translatableErrorsFor: (field: FieldConfig) => Record<string, string> | undefined;
        activeErrors: Record<string, string>;
        colsClassMap: Record<number, string>;
        /** Purge-safe statik col-span class map'i. Task 3 (section içi span) burayı kullanır. */
        colSpanClassMap: Record<number, string>;
        currentValues: Record<string, unknown>;
    }

    interface Props {
        field: FieldConfig;
        ctx: RenderCtx;
    }

    const props = defineProps<Props>();

    // ── Span yardımcıları ─────────────────────────────────────────────────────

    /**
     * colSpan değerini 1..maxCols aralığına sıkıştırır.
     * Section cols(6) iken child colSpan(12) → 6 döner, grid taşması olmaz.
     */
    function clampColSpan(span: number | undefined, maxCols: number): number {
        return Math.min(Math.max(span ?? 1, 1), maxCols);
    }

    // ── Section yardımcıları ───────────────────────────────────────────────────

    function sectionTitle(section: SectionFieldConfig): string {
        const raw = section.title ?? section.label;
        if (!raw) return '';
        return section.translateLabel === false ? raw : trans(raw);
    }

    function sectionGridClass(section: SectionFieldConfig): string {
        return props.ctx.colsClassMap[section.cols ?? props.ctx.config.cols] ?? 'grid-cols-2';
    }

    function sectionIsTransparent(section: SectionFieldConfig): boolean {
        return section.isCard === false;
    }

    function sectionIsAside(section: SectionFieldConfig): boolean {
        return section.aside === true;
    }

    /** CSS var driving the aside header column width (read by .sk-fb__aside). */
    function asideStyle(section: SectionFieldConfig): Record<string, string> {
        return { '--sk-fb-aside-w': section.asideWidth ?? '14rem' };
    }

    function sectionSubtitle(section: SectionFieldConfig): string {
        return section.subtitle ? trans(section.subtitle) : '';
    }

    // ── Icon yardımcıları ─────────────────────────────────────────────────────

    function titleIcon(field: FieldConfig): string | undefined {
        return (field as TitleFieldConfig).icon;
    }

    function titleIconPosition(field: FieldConfig): 'left' | 'right' {
        return (field as TitleFieldConfig).iconPosition ?? 'left';
    }

    function sectionIconPosition(section: SectionFieldConfig): 'left' | 'right' {
        return section.iconPosition ?? 'left';
    }

    function labelIconPosition(field: FieldConfig): 'left' | 'right' {
        return field.labelIconPosition ?? 'left';
    }

    // ── Slot adı yardımcıları ──────────────────────────────────────────────
    // Vue template parser dinamik directive arg `#[…]` içinde TypeScript cast
    // (`as Type`) syntax'ini kabul etmez (SFC compiler JS expression bekler).
    // Cast'ı script tarafına çekmek için helper fonksiyonlar kullanırız.

    function slotNameFor(child: FieldConfig): string {
        return (child as SlotFieldConfig).slotName ?? child.key;
    }

    function fieldSlotKey(key: string): string {
        return `field-${key}`;
    }

    function sectionTitleEndKey(key: string): string {
        return `section-${key}-title-end`;
    }

    // ── ToggleSwitch "rich row" (icon box + label + description + switch) ────────
    // Known icon-library class prefixes. A rowIcon descriptor is treated as a CSS
    // icon class (rendered via SkIcon) when it matches one of these; otherwise it
    // is treated as a short text glyph (e.g. 'Aa', '123', '!@#') and rendered as
    // plain text inside the icon box.
    const ICON_CLASS_PREFIXES = ['pi ', 'fa ', 'fa-', 'mdi ', 'mdi-', 'bi ', 'bi-', 'lucide'];

    function asToggleRow(field: FieldConfig): ToggleSwitchFieldConfig {
        return field as ToggleSwitchFieldConfig;
    }

    /** True when a toggle-switch opted into the rich row layout (icon and/or description set). */
    function isRichToggle(field: FieldConfig): boolean {
        if (field.type !== 'toggle-switch') return false;
        const t = field as ToggleSwitchFieldConfig;
        return !!(t.rowIcon || t.description);
    }

    /** True when the rowIcon descriptor is a CSS icon class (→ SkIcon), false → text glyph. */
    function rowIconIsClass(icon: string | undefined): boolean {
        if (!icon) return false;
        return ICON_CLASS_PREFIXES.some((p) => icon.startsWith(p));
    }

    /**
     * True when one of the error / option-error / hint <small> elements renders for a
     * field. Mirrors the v-if chain in the template exactly, so `aria-describedby` is
     * only emitted while its target element actually exists in the DOM.
     */
    function hasDescription(field: FieldConfig): boolean {
        const ctx = props.ctx;
        if (ctx.activeErrors[field.key] && !ctx.isTranslatableField(field)) return true;
        if (ctx.optionError(field)) return true;
        return !!field.hint;
    }

    function toggleDescription(field: FieldConfig): string {
        const desc = (field as ToggleSwitchFieldConfig).description;
        if (!desc) return '';
        return field.translateLabel === false ? desc : trans(desc);
    }
</script>

<template>
    <!-- ── Hidden field ──────────────────────────────────────────────────── -->
    <input
        v-if="field.hidden"
        type="hidden"
        :name="field.key"
        :value="String(ctx.getValue(field.key) ?? '')"
    >

    <!-- ── Section (aside) — two-column: header left / fields right ───────── -->
    <div
        v-else-if="field.type === 'section' && sectionIsAside(field as SectionFieldConfig)"
        class="sk-fb__aside"
        :class="field.cssClass"
        :style="asideStyle(field as SectionFieldConfig)"
    >
        <div class="sk-fb__aside-header">
            <div v-if="sectionTitle(field as SectionFieldConfig)" class="sk-fb__aside-title">
                <SkIcon
                    v-if="(field as SectionFieldConfig).icon && sectionIconPosition(field as SectionFieldConfig) === 'left'"
                    :icon="(field as SectionFieldConfig).icon!"
                    class="sk-fb__section-icon sk-fb__section-icon--left"
                />
                {{ sectionTitle(field as SectionFieldConfig) }}
                <SkIcon
                    v-if="(field as SectionFieldConfig).icon && sectionIconPosition(field as SectionFieldConfig) === 'right'"
                    :icon="(field as SectionFieldConfig).icon!"
                    class="sk-fb__section-icon sk-fb__section-icon--right"
                />
            </div>
            <p v-if="sectionSubtitle(field as SectionFieldConfig)" class="sk-fb__aside-desc">
                {{ sectionSubtitle(field as SectionFieldConfig) }}
            </p>
            <div v-if="parentSlots[sectionTitleEndKey(field.key)]" class="sk-fb__aside-extra">
                <slot :name="sectionTitleEndKey(field.key)" :values="ctx.currentValues" />
            </div>
        </div>
        <div class="sk-fb__aside-fields">
            <div class="sk-fb__grid" :class="sectionGridClass(field as SectionFieldConfig)">
                <template v-for="child in (field as SectionFieldConfig).fields" :key="child.key">
                    <input
                        v-if="child.hidden"
                        type="hidden"
                        :name="child.key"
                        :value="String(ctx.getValue(child.key) ?? '')"
                    >
                    <div
                        v-else-if="ctx.isVisible(child)"
                        class="sk-fb__section-field"
                        :class="[
                            child.cssClass,
                            child.colSpan
                                ? ctx.colSpanClassMap[clampColSpan(child.colSpan, (field as SectionFieldConfig).cols ?? ctx.config.cols)]
                                : undefined,
                        ]"
                    >
                        <SkFormFieldRenderer :field="child" :ctx="ctx">
                            <template
                                v-if="parentSlots[slotNameFor(child)]"
                                #[slotNameFor(child)]
                            >
                                <slot
                                    :name="slotNameFor(child)"
                                    :values="ctx.currentValues"
                                />
                            </template>
                            <template
                                v-if="parentSlots[fieldSlotKey(child.key)]"
                                #[fieldSlotKey(child.key)]
                            >
                                <slot
                                    :name="fieldSlotKey(child.key)"
                                    :field="child"
                                    :value="ctx.getValue(child.key)"
                                    :on-update="(v: unknown) => ctx.setValue(child.key, v)"
                                />
                            </template>
                        </SkFormFieldRenderer>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- ── Section — SkCard wrapper + recursive render ───────────────────── -->
    <SkCard
        v-else-if="field.type === 'section'"
        :transparent="sectionIsTransparent(field as SectionFieldConfig)"
        :class="['sk-fb__section', field.cssClass]"
    >
        <template v-if="sectionTitle(field as SectionFieldConfig)" #title>
            <SkIcon
                v-if="(field as SectionFieldConfig).icon && sectionIconPosition(field as SectionFieldConfig) === 'left'"
                :icon="(field as SectionFieldConfig).icon!"
                class="sk-fb__section-icon sk-fb__section-icon--left"
            />
            {{ sectionTitle(field as SectionFieldConfig) }}
            <SkIcon
                v-if="(field as SectionFieldConfig).icon && sectionIconPosition(field as SectionFieldConfig) === 'right'"
                :icon="(field as SectionFieldConfig).icon!"
                class="sk-fb__section-icon sk-fb__section-icon--right"
            />
        </template>
        <template
            v-if="parentSlots[sectionTitleEndKey(field.key)]"
            #title-end
        >
            <slot
                :name="sectionTitleEndKey(field.key)"
                :values="ctx.currentValues"
            />
        </template>
        <template v-if="(field as SectionFieldConfig).subtitle" #subtitle>
            {{ $t((field as SectionFieldConfig).subtitle!) }}
        </template>
        <template #content>
            <div class="sk-fb__grid" :class="sectionGridClass(field as SectionFieldConfig)">
                <template v-for="child in (field as SectionFieldConfig).fields" :key="child.key">
                    <!-- Hidden child field -->
                    <input
                        v-if="child.hidden"
                        type="hidden"
                        :name="child.key"
                        :value="String(ctx.getValue(child.key) ?? '')"
                    >
                    <!-- Visible child field — recursive -->
                    <div
                        v-else-if="ctx.isVisible(child)"
                        class="sk-fb__section-field"
                        :class="[
                            child.cssClass,
                            child.colSpan
                                ? ctx.colSpanClassMap[clampColSpan(child.colSpan, (field as SectionFieldConfig).cols ?? ctx.config.cols)]
                                : undefined,
                        ]"
                    >
                        <!--
                            Render child field via SkFormFieldRenderer.
                            Since sections are single-level (no nested sections), slot forwarding
                            is handled by checking parentSlots in SkFormFieldRenderer itself —
                            see the `field-${key}` and slot-type slot rendering in the non-section
                            branches below. The `parentSlots` composable provides slot functions
                            without triggering vue-tsc's TS7022 recursive-inference limitation.
                        -->
                        <SkFormFieldRenderer :field="child" :ctx="ctx">
                            <template
                                v-if="parentSlots[slotNameFor(child)]"
                                #[slotNameFor(child)]
                            >
                                <slot
                                    :name="slotNameFor(child)"
                                    :values="ctx.currentValues"
                                />
                            </template>
                            <template
                                v-if="parentSlots[fieldSlotKey(child.key)]"
                                #[fieldSlotKey(child.key)]
                            >
                                <!--
                                    Field override slot: we reconstruct the slot scope from
                                    ctx rather than destructuring #[name]="props" to avoid
                                    the vue-tsc TS7022 recursive-component implicit-any bug.
                                -->
                                <slot
                                    :name="fieldSlotKey(child.key)"
                                    :field="child"
                                    :value="ctx.getValue(child.key)"
                                    :on-update="(v: unknown) => ctx.setValue(child.key, v)"
                                />
                            </template>
                        </SkFormFieldRenderer>
                    </div>
                </template>
            </div>
        </template>
    </SkCard>

    <!-- ── Non-section, non-hidden fields ───────────────────────────────── -->
    <template v-else>
        <!-- ── Title ─────────────────────────────────────────────────── -->
        <component
            :is="(field as TitleFieldConfig).tag ?? 'h3'"
            v-if="field.type === 'title'"
            class="sk-fb__title"
        >
            <SkIcon
                v-if="titleIcon(field) && titleIconPosition(field) === 'left'"
                :icon="titleIcon(field)!"
                class="sk-fb__title-icon sk-fb__title-icon--left"
            />
            {{ ctx.displayLabel(field) }}
            <SkIcon
                v-if="titleIcon(field) && titleIconPosition(field) === 'right'"
                :icon="titleIcon(field)!"
                class="sk-fb__title-icon sk-fb__title-icon--right"
            />
        </component>

        <!-- ── Slot ──────────────────────────────────────────────────── -->
        <slot
            v-else-if="field.type === 'slot'"
            :name="(field as SlotFieldConfig).slotName ?? field.key"
            :values="ctx.currentValues"
        />

        <!-- ── Vertical layout ───────────────────────────────────────── -->
        <template v-else-if="ctx.config.layout === 'vertical'">
            <!-- ToggleSwitch "rich row": icon box + label + description + switch -->
            <div v-if="isRichToggle(field)" class="sk-fb__field-vertical">
                <div class="sk-fb__secrow">
                    <span
                        v-if="asToggleRow(field).rowIcon"
                        class="sk-fb__secrow-icon"
                        :class="{ 'sk-fb__secrow-icon--glyph': !rowIconIsClass(asToggleRow(field).rowIcon) }"
                    >
                        <SkIcon
                            v-if="rowIconIsClass(asToggleRow(field).rowIcon)"
                            :icon="asToggleRow(field).rowIcon!"
                        />
                        <template v-else>{{ asToggleRow(field).rowIcon }}</template>
                    </span>

                    <label :for="controlId(field)" class="sk-fb__secrow-body">
                        <span v-if="!field.hideLabel" class="sk-fb__secrow-title">
                            {{ ctx.displayLabel(field) }}
                        </span>
                        <span v-if="toggleDescription(field)" class="sk-fb__secrow-desc">
                            {{ toggleDescription(field) }}
                        </span>
                    </label>

                    <div class="sk-fb__secrow-control">
                        <slot
                            :name="`field-${field.key}`"
                            :field="field"
                            :value="ctx.getValue(field.key)"
                            :on-update="(v: unknown) => ctx.setValue(field.key, v)"
                        >
                            <SkFormInput
                                :field="field"
                                :value="ctx.getValue(field.key)"
                                :disabled="ctx.isDisabled(field)"
                                :invalid="!!ctx.activeErrors[field.key]"
                                :options="ctx.getOptions(field)"
                                :loading="ctx.isLoading(field)"
                                :translatable-errors="ctx.translatableErrorsFor(field)"
                                :described="hasDescription(field)"
                                @update="(v) => ctx.setValue(field.key, v)"
                            />
                        </slot>
                    </div>
                </div>

                <small
                    v-if="ctx.activeErrors[field.key] && !ctx.isTranslatableField(field)"
                    :id="describedById(field)"
                    class="sk-fb__error"
                >{{ ctx.activeErrors[field.key] }}</small>
                <small
                    v-else-if="ctx.optionError(field)"
                    :id="describedById(field)"
                    class="sk-fb__error"
                >{{ $t('sk-common.options_load_error') }}</small>
                <small v-else-if="field.hint" :id="describedById(field)" class="sk-fb__hint">{{ $t(field.hint) }}</small>
            </div>

            <!-- Checkbox / Toggle: inline-label row -->
            <div v-else-if="ctx.hasInlineLabel(field)" class="sk-fb__field-vertical">
                <div class="sk-fb__inline-row">
                    <template v-if="ctx.isControlRight(field) && !field.hideLabel">
                        <label :for="controlId(field)" class="sk-fb__label sk-fb__label--inline">
                            <SkIcon
                                v-if="field.labelIcon && labelIconPosition(field) === 'left'"
                                :icon="field.labelIcon"
                                class="sk-fb__label-icon sk-fb__label-icon--left"
                            />
                            {{ ctx.displayLabel(field) }}
                            <span v-if="field.required" class="sk-fb__required" aria-hidden="true">*</span><span v-if="field.required" class="sr-only">{{ $t('sk-common.required') }}</span>
                            <SkIcon
                                v-if="field.labelIcon && labelIconPosition(field) === 'right'"
                                :icon="field.labelIcon"
                                class="sk-fb__label-icon sk-fb__label-icon--right"
                            />
                        </label>
                    </template>

                    <slot
                        :name="`field-${field.key}`"
                        :field="field"
                        :value="ctx.getValue(field.key)"
                        :on-update="(v: unknown) => ctx.setValue(field.key, v)"
                    >
                        <SkFormInput
                            :field="field"
                            :value="ctx.getValue(field.key)"
                            :disabled="ctx.isDisabled(field)"
                            :invalid="!!ctx.activeErrors[field.key]"
                            :options="ctx.getOptions(field)"
                            :loading="ctx.isLoading(field)"
                            :translatable-errors="ctx.translatableErrorsFor(field)"
                            :described="hasDescription(field)"
                            @update="(v) => ctx.setValue(field.key, v)"
                        />
                    </slot>

                    <template v-if="!ctx.isControlRight(field) && !field.hideLabel">
                        <label :for="controlId(field)" class="sk-fb__label sk-fb__label--inline">
                            <SkIcon
                                v-if="field.labelIcon && labelIconPosition(field) === 'left'"
                                :icon="field.labelIcon"
                                class="sk-fb__label-icon sk-fb__label-icon--left"
                            />
                            {{ ctx.displayLabel(field) }}
                            <span v-if="field.required" class="sk-fb__required" aria-hidden="true">*</span><span v-if="field.required" class="sr-only">{{ $t('sk-common.required') }}</span>
                            <SkIcon
                                v-if="field.labelIcon && labelIconPosition(field) === 'right'"
                                :icon="field.labelIcon"
                                class="sk-fb__label-icon sk-fb__label-icon--right"
                            />
                        </label>
                    </template>
                </div>

                <small
                    v-if="ctx.activeErrors[field.key] && !ctx.isTranslatableField(field)"
                    :id="describedById(field)"
                    class="sk-fb__error"
                >{{ ctx.activeErrors[field.key] }}</small>
                <small
                    v-else-if="ctx.optionError(field)"
                    :id="describedById(field)"
                    class="sk-fb__error"
                >{{ $t('sk-common.options_load_error') }}</small>
                <small v-else-if="field.hint" :id="describedById(field)" class="sk-fb__hint">{{ $t(field.hint) }}</small>
            </div>

            <!-- Regular fields: label on top OR inline -->
            <div
                v-else
                class="sk-fb__field-vertical"
                :class="{ 'sk-fb__field-vertical--inline': ctx.hasInlineFieldLabel(field) }"
            >
                <div v-if="ctx.hasInlineFieldLabel(field)" class="sk-fb__field-row">
                    <label
                        v-if="!field.hideLabel && !ctx.isTranslatableField(field)"
                        :for="controlId(field)"
                        class="sk-fb__label sk-fb__label--field-inline"
                    >
                        <SkIcon
                            v-if="field.labelIcon && labelIconPosition(field) === 'left'"
                            :icon="field.labelIcon"
                            class="sk-fb__label-icon sk-fb__label-icon--left"
                        />
                        {{ ctx.displayLabel(field) }}
                        <span v-if="field.required" class="sk-fb__required" aria-hidden="true">*</span><span v-if="field.required" class="sr-only">{{ $t('sk-common.required') }}</span>
                        <SkIcon
                            v-if="field.labelIcon && labelIconPosition(field) === 'right'"
                            :icon="field.labelIcon"
                            class="sk-fb__label-icon sk-fb__label-icon--right"
                        />
                    </label>

                    <div class="sk-fb__field-control">
                        <slot
                            :name="`field-${field.key}`"
                            :field="field"
                            :value="ctx.getValue(field.key)"
                            :on-update="(v: unknown) => ctx.setValue(field.key, v)"
                        >
                            <SkFormInput
                                :field="field"
                                :value="ctx.getValue(field.key)"
                                :disabled="ctx.isDisabled(field)"
                                :invalid="!!ctx.activeErrors[field.key]"
                                :options="ctx.getOptions(field)"
                                :loading="ctx.isLoading(field)"
                                :translatable-errors="ctx.translatableErrorsFor(field)"
                                :described="hasDescription(field)"
                                :translatable-label="!field.hideLabel"
                                @update="(v) => ctx.setValue(field.key, v)"
                            />
                        </slot>
                    </div>
                </div>

                <template v-else>
                    <label
                        v-if="!field.hideLabel && !ctx.isTranslatableField(field)"
                        :for="controlId(field)"
                        class="sk-fb__label"
                    >
                        <SkIcon
                            v-if="field.labelIcon && labelIconPosition(field) === 'left'"
                            :icon="field.labelIcon"
                            class="sk-fb__label-icon sk-fb__label-icon--left"
                        />
                        {{ ctx.displayLabel(field) }}
                        <span v-if="field.required" class="sk-fb__required" aria-hidden="true">*</span><span v-if="field.required" class="sr-only">{{ $t('sk-common.required') }}</span>
                        <SkIcon
                            v-if="field.labelIcon && labelIconPosition(field) === 'right'"
                            :icon="field.labelIcon"
                            class="sk-fb__label-icon sk-fb__label-icon--right"
                        />
                    </label>

                    <slot
                        :name="`field-${field.key}`"
                        :field="field"
                        :value="ctx.getValue(field.key)"
                        :on-update="(v: unknown) => ctx.setValue(field.key, v)"
                    >
                        <SkFormInput
                            :field="field"
                            :value="ctx.getValue(field.key)"
                            :disabled="ctx.isDisabled(field)"
                            :invalid="!!ctx.activeErrors[field.key]"
                            :options="ctx.getOptions(field)"
                            :loading="ctx.isLoading(field)"
                            :translatable-errors="ctx.translatableErrorsFor(field)"
                            :described="hasDescription(field)"
                            :translatable-label="!field.hideLabel"
                            @update="(v) => ctx.setValue(field.key, v)"
                        />
                    </slot>
                </template>

                <small
                    v-if="ctx.activeErrors[field.key] && !ctx.isTranslatableField(field)"
                    :id="describedById(field)"
                    class="sk-fb__error"
                >{{ ctx.activeErrors[field.key] }}</small>
                <small
                    v-else-if="ctx.optionError(field)"
                    :id="describedById(field)"
                    class="sk-fb__error"
                >{{ $t('sk-common.options_load_error') }}</small>
                <small v-else-if="field.hint" :id="describedById(field)" class="sk-fb__hint">{{ $t(field.hint) }}</small>
            </div>
        </template>

        <!-- ── Horizontal layout ─────────────────────────────────────── -->
        <div v-else class="sk-fb__field-horizontal">
            <label
                v-if="!field.hideLabel"
                :for="controlId(field)"
                class="sk-fb__label sk-fb__label--horizontal"
            >
                <SkIcon
                    v-if="field.labelIcon && labelIconPosition(field) === 'left'"
                    :icon="field.labelIcon"
                    class="sk-fb__label-icon sk-fb__label-icon--left"
                />
                {{ ctx.displayLabel(field) }}
                <span v-if="field.required" class="sk-fb__required" aria-hidden="true">*</span><span v-if="field.required" class="sr-only">{{ $t('sk-common.required') }}</span>
                <SkIcon
                    v-if="field.labelIcon && labelIconPosition(field) === 'right'"
                    :icon="field.labelIcon"
                    class="sk-fb__label-icon sk-fb__label-icon--right"
                />
            </label>

            <div class="sk-fb__field-content">
                <div v-if="ctx.hasInlineLabel(field)" class="sk-fb__inline-wrap">
                    <slot
                        :name="`field-${field.key}`"
                        :field="field"
                        :value="ctx.getValue(field.key)"
                        :on-update="(v: unknown) => ctx.setValue(field.key, v)"
                    >
                        <SkFormInput
                            :field="field"
                            :value="ctx.getValue(field.key)"
                            :disabled="ctx.isDisabled(field)"
                            :invalid="!!ctx.activeErrors[field.key]"
                            :options="ctx.getOptions(field)"
                            :loading="ctx.isLoading(field)"
                            :translatable-errors="ctx.translatableErrorsFor(field)"
                            :described="hasDescription(field)"
                            @update="(v) => ctx.setValue(field.key, v)"
                        />
                    </slot>
                </div>

                <slot
                    v-else
                    :name="`field-${field.key}`"
                    :field="field"
                    :value="ctx.getValue(field.key)"
                    :on-update="(v: unknown) => ctx.setValue(field.key, v)"
                >
                    <SkFormInput
                        :field="field"
                        :value="ctx.getValue(field.key)"
                        :disabled="ctx.isDisabled(field)"
                        :invalid="!!ctx.activeErrors[field.key]"
                        :options="ctx.getOptions(field)"
                        :loading="ctx.isLoading(field)"
                        :translatable-errors="ctx.translatableErrorsFor(field)"
                        :described="hasDescription(field)"
                        @update="(v) => ctx.setValue(field.key, v)"
                    />
                </slot>

                <small
                    v-if="ctx.activeErrors[field.key] && !ctx.isTranslatableField(field)"
                    :id="describedById(field)"
                    class="sk-fb__error"
                >{{ ctx.activeErrors[field.key] }}</small>
                <small
                    v-else-if="ctx.optionError(field)"
                    :id="describedById(field)"
                    class="sk-fb__error"
                >{{ $t('sk-common.options_load_error') }}</small>
                <small v-else-if="field.hint" :id="describedById(field)" class="sk-fb__hint">{{ $t(field.hint) }}</small>
            </div>
        </div>
    </template>
</template>
