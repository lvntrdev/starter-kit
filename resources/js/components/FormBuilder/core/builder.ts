// resources/js/formbuilder/builder.ts

import type {
    CheckboxFieldConfig,
    ColorSelectorFieldConfig,
    DatePickerFieldConfig,
    ExistingMedia,
    FieldConfig,
    FileUploadFieldConfig,
    FormActionLabels,
    FormBuilderConfig,
    FormLayout,
    FormResourceConfig,
    FormSubmitConfig,
    InputNumberFieldConfig,
    InputMaskFieldConfig,
    InputOtpFieldConfig,
    InputTextFieldConfig,
    OptionFilter,
    PasswordFieldConfig,
    SelectFieldConfig,
    SelectOption,
    SlotFieldConfig,
    TextareaFieldConfig,
    TitleFieldConfig,
    ToggleButtonFieldConfig,
    ToggleSwitchFieldConfig,
} from './types';

// ── Base Builder ──────────────────────────────────────────────────────────────

abstract class BaseFieldBuilder<T extends FieldConfig> {
    protected config: Partial<T>;

    constructor(type: T['type']) {
        this.config = { type } as Partial<T>;
    }

    key(key: string): this {
        this.config.key = key;
        return this;
    }

    label(label: string | false): this {
        if (label === false) {
            this.config.hideLabel = true;
        } else {
            this.config.label = label;
        }
        return this;
    }

    /**
     * Whether the label is a translation key (default) or a pre-resolved raw string.
     * Pass `false` when supplying an already-translated value like `$t('admin.example')`
     * so the form template skips `$t()` on it.
     *
     * @example
     *   FB.inputText().key('last_name')                                  // default → $t('sk-attribute.attributes.last_name')
     *   FB.inputText().key('x').label($t('admin.example')).trans(false)  // raw render
     */
    trans(shouldTranslate = true): this {
        this.config.translateLabel = shouldTranslate;
        return this;
    }

    required(required = true): this {
        this.config.required = required;
        return this;
    }

    labelPlacement(placement: 'top' | 'inline'): this {
        this.config.labelPlacement = placement;
        return this;
    }

    controlPosition(position: 'left' | 'right'): this {
        this.config.controlPosition = position;
        return this;
    }

    /** Mark field as optional (not required). Fields are required by default. */
    optional(optional = true): this {
        this.config.required = !optional;
        return this;
    }

    /** Extra CSS class(es) applied to the field wrapper element. */
    class(cssClass: string): this {
        this.config.cssClass = cssClass;
        return this;
    }

    hint(hint: string | undefined): this {
        this.config.hint = hint;
        return this;
    }

    visible(fn: (values: Record<string, unknown>) => boolean): this {
        this.config.visible = fn;
        return this;
    }

    disabled(fn: (values: Record<string, unknown>) => boolean): this {
        this.config.disabled = fn;
        return this;
    }

    /** Default/initial value for this field. */
    default(value: unknown): this {
        this.config.defaultValue = value;
        return this;
    }

    /** Prefix addon text or icon class (wraps field with InputGroup). */
    groupPrefix(text: string): this {
        this.config.groupPrefix = text;
        return this;
    }

    /** Suffix addon text or icon class (wraps field with InputGroup). */
    groupSuffix(text: string): this {
        this.config.groupSuffix = text;
        return this;
    }

    /** Render as a hidden input — the field participates in form data but is not visible. */
    hidden(hidden = true): this {
        this.config.hidden = hidden;
        return this;
    }

    /** Pass additional props directly to the underlying PrimeVue component. */
    props(componentProps: Record<string, unknown>): this {
        this.config.componentProps = { ...(this.config.componentProps ?? {}), ...componentProps };
        return this;
    }

    build(): T {
        if (!this.config.key) {
            throw new Error('Field must have a key');
        }
        if (!this.config.label) {
            this.config.label = `sk-attribute.attributes.${this.config.key}`;
        }
        if (this.config.translateLabel === undefined) {
            this.config.translateLabel = true;
        }
        if (this.config.required === undefined) {
            this.config.required = true;
        }
        // placeholder(true) → use label as placeholder
        if ((this.config as Record<string, unknown>).placeholder === true) {
            (this.config as Record<string, unknown>).placeholder = this.config.label;
        }
        return this.config as T;
    }
}

// ── Field Builders ────────────────────────────────────────────────────────────

export class InputTextBuilder extends BaseFieldBuilder<InputTextFieldConfig> {
    constructor() {
        super('input-text');
    }

    placeholder(placeholder: string | boolean): this {
        this.config.placeholder = placeholder as string;
        return this;
    }

    inputType(inputType: string): this {
        this.config.inputType = inputType;
        return this;
    }

    /** Icon class (e.g. 'pi pi-search'). Wraps with IconField + InputIcon. */
    icon(icon: string): this {
        this.config.icon = icon;
        return this;
    }

    /** Icon position: 'left' (default) or 'right'. */
    iconPosition(position: 'left' | 'right'): this {
        this.config.iconPosition = position;
        return this;
    }
}

export class InputNumberBuilder extends BaseFieldBuilder<InputNumberFieldConfig> {
    constructor() {
        super('input-number');
    }

    placeholder(placeholder: string | boolean): this {
        this.config.placeholder = placeholder as string;
        return this;
    }

    min(min: number): this {
        this.config.min = min;
        return this;
    }

    max(max: number): this {
        this.config.max = max;
        return this;
    }

    step(step: number): this {
        this.config.step = step;
        return this;
    }

    prefix(prefix: string): this {
        this.config.prefix = prefix;
        return this;
    }

    suffix(suffix: string): this {
        this.config.suffix = suffix;
        return this;
    }

    showButtons(show = true): this {
        this.config.showButtons = show;
        return this;
    }

    fractionDigits(min: number, max: number): this {
        this.config.minFractionDigits = min;
        this.config.maxFractionDigits = max;
        return this;
    }

    useGrouping(enabled = true): this {
        this.config.useGrouping = enabled;
        return this;
    }
}

export class InputOtpBuilder extends BaseFieldBuilder<InputOtpFieldConfig> {
    constructor() {
        super('input-otp');
    }

    length(length: number): this {
        this.config.length = length;
        return this;
    }

    mask(mask = true): this {
        this.config.mask = mask;
        return this;
    }

    integerOnly(integerOnly = true): this {
        this.config.integerOnly = integerOnly;
        return this;
    }
}

export class InputMaskBuilder extends BaseFieldBuilder<InputMaskFieldConfig> {
    constructor() {
        super('input-mask');
    }

    mask(mask: string): this {
        this.config.mask = mask;
        return this;
    }

    placeholder(placeholder: string | boolean): this {
        this.config.placeholder = placeholder as string;
        return this;
    }

    slotChar(char: string): this {
        this.config.slotChar = char;
        return this;
    }

    autoClear(enabled = true): this {
        this.config.autoClear = enabled;
        return this;
    }

    unmask(enabled = true): this {
        this.config.unmask = enabled;
        return this;
    }
}

export class DatePickerBuilder extends BaseFieldBuilder<DatePickerFieldConfig> {
    constructor() {
        super('date-picker');
    }

    placeholder(placeholder: string | boolean): this {
        this.config.placeholder = placeholder as string;
        return this;
    }

    dateFormat(format: string): this {
        this.config.dateFormat = format;
        return this;
    }

    selectionMode(mode: 'single' | 'range' | 'multiple'): this {
        this.config.selectionMode = mode;
        return this;
    }

    showTime(enabled = true): this {
        this.config.showTime = enabled;
        return this;
    }

    hourFormat(format: '12' | '24'): this {
        this.config.hourFormat = format;
        return this;
    }

    showIcon(enabled = true): this {
        this.config.showIcon = enabled;
        return this;
    }

    iconDisplay(display: 'input' | 'button'): this {
        this.config.iconDisplay = display;
        return this;
    }

    minDate(date: Date): this {
        this.config.minDate = date;
        return this;
    }

    maxDate(date: Date): this {
        this.config.maxDate = date;
        return this;
    }

    showButtonBar(enabled = true): this {
        this.config.showButtonBar = enabled;
        return this;
    }

    numberOfMonths(count: number): this {
        this.config.numberOfMonths = count;
        return this;
    }

    view(view: 'date' | 'month' | 'year'): this {
        this.config.view = view;
        return this;
    }

    inline(enabled = true): this {
        this.config.inline = enabled;
        return this;
    }
}

export class SelectFieldBuilder extends BaseFieldBuilder<SelectFieldConfig> {
    constructor(type: SelectFieldConfig['type'] = 'select') {
        super(type);
    }

    options(options: SelectOption[]): this {
        this.config.options = options;
        return this;
    }

    optionsUrl(url: string | ((values: Record<string, unknown>) => string | null)): this {
        this.config.optionsUrl = url;
        return this;
    }

    optionLabel(label: string): this {
        this.config.optionLabel = label;
        return this;
    }

    optionValue(value: string): this {
        this.config.optionValue = value;
        return this;
    }

    placeholder(placeholder: string | boolean): this {
        this.config.placeholder = placeholder as string;
        return this;
    }

    showClear(show = true): this {
        this.config.showClear = show;
        return this;
    }

    filter(enabled = true): this {
        this.config.filter = enabled;
        return this;
    }

    radioLayout(layout: 'horizontal' | 'vertical'): this {
        this.config.radioLayout = layout;
        return this;
    }

    /** Resolve options from a definition (e.g. 'userStatus', 'gender'). */
    definitionOptions(key: string, filter?: OptionFilter): this {
        this.config.definitionKey = key;
        if (filter) {
            this.config.definitionFilter = filter;
        }
        return this;
    }

    /** @deprecated Use `definitionOptions()` instead. */
    enumOptions(key: string, filter?: OptionFilter): this {
        return this.definitionOptions(key, filter);
    }
}

export class CheckboxBuilder extends BaseFieldBuilder<CheckboxFieldConfig> {
    constructor() {
        super('checkbox');
    }
}

export class PasswordBuilder extends BaseFieldBuilder<PasswordFieldConfig> {
    constructor() {
        super('password');
    }

    placeholder(placeholder: string | boolean): this {
        this.config.placeholder = placeholder as string;
        return this;
    }

    feedback(show = true): this {
        this.config.feedback = show;
        return this;
    }

    toggleMask(show = true): this {
        this.config.toggleMask = show;
        return this;
    }
}

export class TextareaBuilder extends BaseFieldBuilder<TextareaFieldConfig> {
    constructor() {
        super('textarea');
    }

    placeholder(placeholder: string | boolean): this {
        this.config.placeholder = placeholder as string;
        return this;
    }

    rows(rows: number): this {
        this.config.rows = rows;
        return this;
    }

    autoResize(enabled = true): this {
        this.config.autoResize = enabled;
        return this;
    }
}

export class ToggleButtonBuilder extends BaseFieldBuilder<ToggleButtonFieldConfig> {
    constructor() {
        super('toggle-button');
    }

    onLabel(label: string): this {
        this.config.onLabel = label;
        return this;
    }

    offLabel(label: string): this {
        this.config.offLabel = label;
        return this;
    }

    onIcon(icon: string): this {
        this.config.onIcon = icon;
        return this;
    }

    offIcon(icon: string): this {
        this.config.offIcon = icon;
        return this;
    }
}

export class ToggleSwitchBuilder extends BaseFieldBuilder<ToggleSwitchFieldConfig> {
    constructor() {
        super('toggle-switch');
    }
}

export class TitleBuilder extends BaseFieldBuilder<TitleFieldConfig> {
    private static counter = 0;

    constructor(text?: string) {
        super('title');
        const id = `__title_${++TitleBuilder.counter}`;
        this.config.key = id;
        this.config.label = text ?? id;
    }

    build(): TitleFieldConfig {
        if (this.config.translateLabel === undefined) {
            this.config.translateLabel = true;
        }
        return this.config as TitleFieldConfig;
    }

    tag(tag: string): this {
        this.config.tag = tag;
        return this;
    }
}

export class FileUploadBuilder extends BaseFieldBuilder<FileUploadFieldConfig> {
    constructor() {
        super('file-upload');
    }

    multiple(multiple = true): this {
        this.config.multiple = multiple;
        return this;
    }

    accept(accept: string): this {
        this.config.accept = accept;
        return this;
    }

    maxFileSize(bytes: number): this {
        this.config.maxFileSize = bytes;
        return this;
    }

    fileLimit(limit: number): this {
        this.config.fileLimit = limit;
        return this;
    }

    existingMedia(media: ExistingMedia[]): this {
        this.config.existingMedia = media;
        return this;
    }

    existingMediaKey(key: string): this {
        this.config.existingMediaKey = key;
        return this;
    }
}

export class ColorSelectorBuilder extends BaseFieldBuilder<ColorSelectorFieldConfig> {
    constructor() {
        super('color-selector');
    }

    colors(colors: string[]): this {
        this.config.colors = colors;
        return this;
    }

    tones(tones: number[]): this {
        this.config.tones = tones;
        return this;
    }

    format(format: 'hex' | 'name' | 'name-tone'): this {
        this.config.format = format;
        return this;
    }

    defaultTone(tone: number): this {
        this.config.defaultTone = tone;
        return this;
    }
}

export class SlotBuilder extends BaseFieldBuilder<SlotFieldConfig> {
    constructor() {
        super('slot');
    }

    build(): SlotFieldConfig {
        if (!this.config.key) {
            throw new Error('Slot field must have a key');
        }
        if (this.config.translateLabel === undefined) {
            this.config.translateLabel = true;
        }
        return this.config as SlotFieldConfig;
    }

    slotName(name: string): this {
        this.config.slotName = name;
        return this;
    }
}

// ── Form Builder ──────────────────────────────────────────────────────────────

export class FormBuilder {
    private config: FormBuilderConfig = {
        layout: 'vertical',
        cols: 2,
        fields: [],
        isCard: true,
    };

    layout(layout: FormLayout): this {
        this.config.layout = layout;
        return this;
    }

    cols(cols: number): this {
        this.config.cols = cols;
        return this;
    }

    /** Extra CSS class(es) applied to the form root element. */
    class(cssClass: string): this {
        this.config.cssClass = cssClass;
        return this;
    }

    /**
     * URL to fetch initial data from (via useApi GET).
     * SkForm shows a loading skeleton until data arrives.
     */
    dataUrl(url: string): this {
        this.config.dataUrl = url;
        return this;
    }

    /**
     * Key to extract from the dataUrl response (e.g. 'user').
     * If not set, the entire response is used as initialData.
     */
    dataKey(key: string): this {
        this.config.dataKey = key;
        return this;
    }

    /**
     * Initial data to auto-populate fields by key.
     * When a field has no .default(), its value is taken from initialData[field.key].
     */
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    initialData(data: Record<string, any> | null | undefined): this {
        this.config.initialData = data ?? undefined;
        return this;
    }

    /** Where to render the action area. Defaults to 'bottom'. */
    actionsPosition(position: 'top' | 'bottom' | 'both'): this {
        this.config.actionsPosition = position;
        return this;
    }

    /**
     * Submit configuration — FormBuilder handles useForm, submit, errors, and loading internally.
     * Initial values are auto-derived from each field's .default() value.
     */
    submit(config: FormSubmitConfig): this {
        this.config.submit = config;
        return this;
    }

    /**
     * Resource shorthand — automatically configures submit, dataUrl, and dataKey
     * based on whether an id is provided.
     *
     * When `id` is truthy → edit mode (PUT to `update`, GET from `data`).
     * When `id` is falsy  → create mode (POST to `store`).
     */
    resource(config: FormResourceConfig): this {
        const isEdit = !!config.id;
        this.config.submit = {
            url: isEdit ? config.update : config.store,
            method: isEdit ? 'put' : 'post',
        };
        if (isEdit) {
            this.config.dataUrl = config.data;
        }
        this.config.dataKey = config.key;
        return this;
    }

    /** Labels for the built-in cancel/submit buttons (used when config.submit is set). */
    actionLabels(labels: FormActionLabels): this {
        this.config.actionLabels = labels;
        return this;
    }

    /** Hide the cancel/back button in the actions area. */
    hideCancel(hide = true): this {
        this.config.actionLabels = { ...(this.config.actionLabels ?? {}), hideCancel: hide };
        return this;
    }

    /** Hide the submit button in the actions area. */
    hideSubmit(hide = true): this {
        this.config.actionLabels = { ...(this.config.actionLabels ?? {}), hideSubmit: hide };
        return this;
    }

    /** Cancel behavior: 'back' (window.history.back) or 'emit' (emit cancel event). Default: 'emit'. */
    onCancel(behavior: 'back' | 'emit'): this {
        this.config.onCancel = behavior;
        return this;
    }

    /** Mark this form as a dialog form — shows cancel button, onCancel defaults to 'emit'. */
    inDialog(enabled = true): this {
        this.config.inDialog = enabled;
        return this;
    }

    /** Show a "Back" button with arrow icon (only when not in dialog mode). */
    showBack(enabled = true): this {
        this.config.showBack = enabled;
        return this;
    }

    /** Wrap the form in a PrimeVue Card with this title. */
    cardTitle(title: string): this {
        this.config.cardTitle = title;
        return this;
    }

    /** Card subtitle (only rendered when cardTitle is set). */
    cardSubtitle(subtitle: string): this {
        this.config.cardSubtitle = subtitle;
        return this;
    }

    /** Strip Card bg/shadow/border (transparent mode). */
    isCard(enabled = true): this {
        this.config.isCard = enabled;
        return this;
    }

    /**
     * Permission key required to edit the form (e.g. 'users.update').
     * When the authenticated user lacks this permission, all fields become
     * disabled and the submit button is hidden.
     */
    permission(key: string): this {
        this.config.permission = key;
        return this;
    }

    addFields(...fields: BaseFieldBuilder<FieldConfig>[]): this {
        this.config.fields.push(...fields.map((f) => f.build()));
        return this;
    }

    build(): FormBuilderConfig {
        return { ...this.config };
    }
}
