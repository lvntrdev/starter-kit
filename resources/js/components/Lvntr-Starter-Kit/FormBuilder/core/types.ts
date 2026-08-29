// resources/js/formbuilder/types.ts

export type FormLayout = 'vertical' | 'horizontal';

export type FieldType =
    | 'input-text'
    | 'input-number'
    | 'input-otp'
    | 'input-mask'
    | 'date-picker'
    | 'select'
    | 'multiselect'
    | 'radio'
    | 'checkbox'
    | 'password'
    | 'select-button'
    | 'textarea'
    | 'editor'
    | 'toggle-button'
    | 'toggle-switch'
    | 'checkbox-group'
    | 'file-upload'
    | 'color-selector'
    | 'title'
    | 'slot'
    | 'translatable-text'
    | 'translatable-textarea'
    | 'translatable-editor'
    | 'section';

export interface SelectOption {
    label: string;
    value: string | number | boolean | null;
    /**
     * Optional secondary line shown under the label in radio / checkbox groups
     * (e.g. the card-style FB.radio() options). May be a translation key.
     */
    description?: string;
}

export interface BaseFieldConfig {
    key: string;
    label: string;
    type: FieldType;
    required?: boolean;
    /**
     * Whether `label` is a translation key (default: true) or a pre-resolved raw string.
     * Set false via `.trans(false)` when passing an already-translated string like `$t('admin.example')`
     * so the template renders `label` as-is instead of calling `$t()` on it.
     */
    translateLabel?: boolean;
    /** Placement for the field label in vertical form layout. */
    labelPlacement?: 'top' | 'inline';
    /** Position of the control relative to its label text. */
    controlPosition?: 'left' | 'right';
    /** Hide the label entirely (e.g. for single-field forms). */
    hideLabel?: boolean;
    /** Extra CSS class(es) applied to the field wrapper element. */
    cssClass?: string;
    /** Helper text shown below the field. */
    hint?: string;
    /** Hide the field based on current form values. */
    visible?: (values: Record<string, unknown>) => boolean;
    /** Disable the field based on current form values. */
    disabled?: (values: Record<string, unknown>) => boolean;
    /** Render as a hidden input — the field participates in form data but is not visible. */
    hidden?: boolean;
    /** Additional props passed directly to the underlying PrimeVue component. */
    componentProps?: Record<string, unknown>;
    /** Default/initial value for this field. FormBuilder auto-derives initialValues from this. */
    defaultValue?: unknown;
    /** Prefix addon text or icon class (wraps field with InputGroup + InputGroupAddon). */
    groupPrefix?: string;
    /** Suffix addon text or icon class (wraps field with InputGroup + InputGroupAddon). */
    groupSuffix?: string;
    /** Icon descriptor shown next to the field label. See SkIcon for accepted formats (class string / raw SVG / URL / data URI). */
    labelIcon?: string;
    /** Label icon position (default: 'left'). */
    labelIconPosition?: 'left' | 'right';
    /** Icon descriptor rendered inside the input (where supported: input-text, input-number, input-mask, password). */
    icon?: string;
    /** Input icon position (default: 'left'). */
    iconPosition?: 'left' | 'right';
    /**
     * Field'ın grid içinde kaç kolon kaplayacağı (1..cols).
     * Belirtilmezse 1 hücre. `cols` değerini aşan değer otomatik clamp edilir.
     */
    colSpan?: number;
}

export interface InputTextFieldConfig extends BaseFieldConfig {
    type: 'input-text';
    placeholder?: string;
    /** HTML input type (text, email, url, tel, etc.) */
    inputType?: string;
    /**
     * Icon class (e.g. 'pi pi-search'). Wraps field with IconField + InputIcon.
     * @deprecated Use BaseFieldConfig.icon instead. This will be removed in a future release.
     */
    icon?: string;
    /**
     * Icon position (default: 'left').
     * @deprecated Use BaseFieldConfig.iconPosition instead. This will be removed in a future release.
     */
    iconPosition?: 'left' | 'right';
}

export interface InputNumberFieldConfig extends BaseFieldConfig {
    type: 'input-number';
    placeholder?: string;
    min?: number;
    max?: number;
    step?: number;
    prefix?: string;
    suffix?: string;
    showButtons?: boolean;
    minFractionDigits?: number;
    maxFractionDigits?: number;
    useGrouping?: boolean;
}

export interface InputOtpFieldConfig extends BaseFieldConfig {
    type: 'input-otp';
    /** Number of OTP digits (default: 6). */
    length?: number;
    mask?: boolean;
    integerOnly?: boolean;
}

export interface InputMaskFieldConfig extends BaseFieldConfig {
    type: 'input-mask';
    /** Mask pattern (e.g. '(999) 999-9999', '99999999999', '99/99/9999'). */
    mask?: string;
    placeholder?: string;
    /** Character used for unfilled positions (default: '_'). */
    slotChar?: string;
    /** Whether to include the literal characters in the value (default: false). */
    autoClear?: boolean;
    /** When true, mask is removed from the model value (default: false). */
    unmask?: boolean;
}

export interface DatePickerFieldConfig extends BaseFieldConfig {
    type: 'date-picker';
    placeholder?: string;
    /** Date format string (default: 'dd/mm/yy'). */
    dateFormat?: string;
    /** Selection mode: single date, date range, or multiple dates (default: 'single'). */
    selectionMode?: 'single' | 'range' | 'multiple';
    /** Show time picker alongside the calendar (default: false). */
    showTime?: boolean;
    /** Hour format: 12h or 24h (default: '24'). */
    hourFormat?: '12' | '24';
    /** Show a calendar icon (default: true). */
    showIcon?: boolean;
    /** Icon display mode (default: 'input'). */
    iconDisplay?: 'input' | 'button';
    /** Minimum selectable date. */
    minDate?: Date;
    /** Maximum selectable date. */
    maxDate?: Date;
    /** Show Today and Clear buttons (default: false). */
    showButtonBar?: boolean;
    /** Number of months to display side by side (default: 1). */
    numberOfMonths?: number;
    /** Calendar view mode (default: 'date'). */
    view?: 'date' | 'month' | 'year';
    /** Render the calendar inline instead of as a popup. */
    inline?: boolean;
}

/** Filter to include or exclude specific values from enum/definition options. */
export interface OptionFilter {
    /** Only include these values. */
    only?: (string | number)[];
    /** Exclude these values. */
    except?: (string | number)[];
}

export interface SelectFieldConfig extends BaseFieldConfig {
    type: 'select' | 'multiselect' | 'select-button' | 'radio' | 'checkbox-group';
    /** Static option list. */
    options?: SelectOption[];
    /**
     * Dynamic options URL.
     * - String: fetched once on mount.
     * - Function: re-fetched whenever form values change and URL differs.
     *   Return null to skip fetching (used for cascading: dependent field not yet filled).
     */
    optionsUrl?: string | ((values: Record<string, unknown>) => string | null);
    /**
     * Definition key (e.g. 'userStatus', 'gender').
     * Options are fetched from the /definitions endpoint.
     * @deprecated Use `definitionKey` instead.
     */
    enumKey?: string;
    /** @deprecated Use `definitionFilter` instead. */
    enumFilter?: OptionFilter;
    /** Definition key (e.g. 'userStatus', 'gender'). Options are fetched from /definitions. */
    definitionKey?: string;
    /** Filter for definition options (only/except specific values). */
    definitionFilter?: OptionFilter;
    optionLabel?: string;
    optionValue?: string;
    placeholder?: string;
    showClear?: boolean;
    /** Enable built-in filter for Select / MultiSelect. */
    filter?: boolean;
    /** Layout for radio buttons: horizontal (default) or vertical. */
    radioLayout?: 'horizontal' | 'vertical';
}

export interface CheckboxFieldConfig extends BaseFieldConfig {
    type: 'checkbox';
}

export interface PasswordGeneratorConfig {
    /** Total character count (default: 16). Clamped to [8, 128]. */
    length?: number;
    /** Include both upper- and lower-case letters (default: true). */
    mixedCase?: boolean;
    /** Include letters (default: true). */
    letters?: boolean;
    /** Include digits 0–9 (default: true). */
    numbers?: boolean;
    /** Include symbol characters (default: true). */
    symbols?: boolean;
}

export interface PasswordFieldConfig extends BaseFieldConfig {
    type: 'password';
    placeholder?: string;
    /** Show PrimeVue's overlay strength meter (default: false). */
    feedback?: boolean;
    /**
     * Show an inline strength meter below the input: a 4-segment bar, a
     * strength label, and a live character count. Works with the custom
     * eye-toggle input (does not force PrimeVue's `<Password>` overlay).
     */
    strengthMeter?: boolean;
    toggleMask?: boolean;
    /**
     * Render a "generate" button next to the input.
     * Pass `true` for defaults (16 chars, all categories) or a config
     * object to customise. Generated passwords are intentionally stricter
     * than the server-side `Password::defaults()` policy by default so
     * validation always passes.
     */
    generator?: boolean | PasswordGeneratorConfig;
}

export interface TextareaFieldConfig extends BaseFieldConfig {
    type: 'textarea';
    placeholder?: string;
    rows?: number;
    autoResize?: boolean;
}

export type EditorToolbarPreset = 'minimal' | 'standard' | 'full';

export interface EditorImageUploadConfig {
    /** FileManager context key (e.g. 'user', 'global'). Must be registered in ContextRegistry. */
    context: string;
    /** Owner id when the context path requires one (e.g. user id). */
    contextId?: string | number | null;
    /** Target folder id within the context — null/omitted uploads to the root. */
    folderId?: string | null;
    /**
     * Name of a root-level "managed" folder to host these uploads.
     * When provided (and `folderId` is not), the backend idempotently
     * resolves/restores/creates this folder before the upload. Useful
     * for grouping editor-originated media (e.g. 'Welcome Message')
     * without requiring the client to pre-create the folder.
     * Must match /^[\p{L}\p{N} _-]+$/u (max 100 chars).
     */
    folderName?: string;
    /** Override accepted MIME types (default: image/jpeg, image/png, image/gif, image/webp). */
    acceptedMimes?: string[];
}

export interface EditorFieldConfig extends BaseFieldConfig {
    type: 'editor';
    placeholder?: string;
    /** Minimum editor content height as a CSS value. Default: '10rem'. */
    minHeight?: string;
    /** Toolbar preset controlling which buttons are rendered. Default: 'standard'. */
    toolbar?: EditorToolbarPreset;
    /** When provided, enables the image button + paste/drop image uploads. */
    imageUpload?: EditorImageUploadConfig;
    /** Enable link toolbar button and paste auto-linking. Default: false. */
    links?: boolean;
    /** Emit empty string instead of '<p></p>' when the editor is empty. Default: true. */
    treatEmptyAsBlank?: boolean;
}

export interface ToggleButtonFieldConfig extends BaseFieldConfig {
    type: 'toggle-button';
    onLabel?: string;
    offLabel?: string;
    onIcon?: string;
    offIcon?: string;
}

export interface ToggleSwitchFieldConfig extends BaseFieldConfig {
    type: 'toggle-switch';
    /**
     * Opt-in "rich row" leading icon. When set (together with or without
     * `description`), the toggle renders as a settings-style row: a rounded
     * icon box on the left, label + description in the middle, switch on the
     * right. Omit for the default inline render (backwards-compatible).
     *
     * Accepts an icon class (e.g. 'pi pi-key') rendered via SkIcon, OR a short
     * text glyph (e.g. 'Aa', '123', '!@#') rendered as plain text inside the box.
     * A value is treated as a glyph unless it looks like an icon class (contains
     * a space and starts with a known icon-library prefix, e.g. 'pi ').
     */
    rowIcon?: string;
    /**
     * Opt-in "rich row" description shown under the label (translation key by
     * default). Enables the rich row layout even without `rowIcon`.
     */
    description?: string;
}

export interface TitleFieldConfig extends BaseFieldConfig {
    type: 'title';
    /** Tag to render: h2, h3, h4, etc. Default: 'h3'. */
    tag?: string;
    /** Icon descriptor rendered before the title text. */
    icon?: string;
    /** Title icon position (default: 'left'). */
    iconPosition?: 'left' | 'right';
}

export interface ExistingMedia {
    id: number;
    name: string;
    url: string;
    size: number;
    mime_type: string;
}

export interface FileUploadFieldConfig extends BaseFieldConfig {
    type: 'file-upload';
    /** Allow multiple file uploads (default: false). */
    multiple?: boolean;
    /** Accepted file types (e.g. 'image/*', '.pdf,.doc'). */
    accept?: string;
    /**
     * Maximum size of a SINGLE file, in bytes. Unset = no client-side size check
     * at all (there is no implicit default); the server limits still apply.
     * When set, oversized files are rejected on select/drop with a toast.
     */
    maxFileSize?: number;
    /**
     * Maximum number of files when `multiple` is true, counting the existing
     * media that is still attached plus the newly picked files. Unset = no
     * client-side count check.
     */
    fileLimit?: number;
    /** Existing media items to display in edit mode. */
    existingMedia?: ExistingMedia[];
    /** Key in initialData/remoteData to auto-populate existingMedia (e.g. 'identity_document_media'). */
    existingMediaKey?: string;
    /**
     * Defer deletion of existing media to the save request (default: false).
     *
     * `false` (default) — removing an existing file fires `DELETE /media/{id}`
     * immediately, so it is gone even if the form is never submitted.
     *
     * `true` — removal only drops the item from the rendered list and from the
     * emitted keep-list; nothing is deleted until the form is saved. Requires
     * the server side to sync against that keep-list (`HasMediaCollections::
     * syncMediaCollection` deletes the ids missing from it) — without such a
     * sync the file is never deleted at all.
     */
    deferExistingRemoval?: boolean;
}

export interface ColorSelectorFieldConfig extends BaseFieldConfig {
    type: 'color-selector';
    /** Available color keys. Defaults to all Tailwind color palettes. */
    colors?: string[];
    /** Tone steps to display. Defaults to [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]. */
    tones?: number[];
    /** Output format for emitted value. Defaults to 'name'. */
    format?: 'hex' | 'name' | 'name-tone';
    /** Default tone when format requires one. Defaults to 500. */
    defaultTone?: number;
}

export interface SlotFieldConfig extends BaseFieldConfig {
    type: 'slot';
    /** The slot name to render. Defaults to field.key. */
    slotName?: string;
}

interface TranslatableBaseConfig extends BaseFieldConfig {
    /** Field bazında dil filtresi: bu liste dışındaki diller render edilmez. */
    onlyLocales?: string[];
    /** Field bazında dil filtresi: bu listedeki diller render edilmez. */
    exceptLocales?: string[];
    /** Dil etiketinin görünüş şekli. Default: 'badge' (uppercase iki harf). */
    localeLabelStyle?: 'badge' | 'name' | 'flag';
}

export interface TranslatableTextFieldConfig extends TranslatableBaseConfig {
    type: 'translatable-text';
    placeholder?: string;
    inputType?: 'text' | 'email' | 'url';
    maxLength?: number;
}

export interface TranslatableTextareaFieldConfig extends TranslatableBaseConfig {
    type: 'translatable-textarea';
    placeholder?: string;
    rows?: number;
    autoResize?: boolean;
}

export interface TranslatableEditorFieldConfig extends TranslatableBaseConfig {
    type: 'translatable-editor';
    minHeight?: string;
    toolbar?: 'minimal' | 'full';
}

export interface SectionFieldConfig extends BaseFieldConfig {
    type: 'section';
    /** Section header title (translation key — falls back to label). */
    title?: string;
    /** Section subtitle (translation key). */
    subtitle?: string;
    /** Section header icon (overrides labelIcon for sections). */
    icon?: string;
    /** Section icon position (default: 'left'). */
    iconPosition?: 'left' | 'right';
    /** Grid columns inside the section. Default: parent form's cols. */
    cols?: number;
    /** Nested fields (single level — nested sections not supported). */
    fields: FieldConfig[];
    /** Strip Card bg/shadow/border (transparent mode). Default: false. */
    isCard?: boolean;
    /**
     * Two-column "aside" layout: the section header (title + subtitle) sits in a
     * fixed-width left column and the fields grid fills the right column. Renders
     * without a Card wrapper; consecutive aside sections are separated by a hairline
     * rule. Ideal for settings-style pages. Default: false.
     */
    aside?: boolean;
    /** Width of the aside header column (any CSS length). Default: '14rem'. */
    asideWidth?: string;
}

export type FieldConfig =
    | InputTextFieldConfig
    | InputNumberFieldConfig
    | InputOtpFieldConfig
    | InputMaskFieldConfig
    | DatePickerFieldConfig
    | SelectFieldConfig
    | CheckboxFieldConfig
    | PasswordFieldConfig
    | TextareaFieldConfig
    | EditorFieldConfig
    | ToggleButtonFieldConfig
    | ToggleSwitchFieldConfig
    | FileUploadFieldConfig
    | ColorSelectorFieldConfig
    | TitleFieldConfig
    | SlotFieldConfig
    | TranslatableTextFieldConfig
    | TranslatableTextareaFieldConfig
    | TranslatableEditorFieldConfig
    | SectionFieldConfig;

export interface FormSubmitConfig {
    url: string;
    method: 'post' | 'put' | 'patch';
    preserveScroll?: boolean;
}

export interface FormResourceConfig {
    /** URL for creating a new record (POST). */
    store: string;
    /** URL for updating an existing record (PUT). */
    update: string;
    /** URL to fetch existing data from (GET). */
    data: string;
    /** Key to extract from the data response (e.g. 'contact'). */
    key: string;
    /** Record ID — when truthy the form operates in edit mode (PUT + dataUrl). */
    id?: string | number | null;
}

export interface FormActionLabels {
    /** Submit button label (default: 'Save'). */
    submit?: string;
    submitIcon?: string;
    /** Cancel button label (default: 'Back'). */
    cancel?: string;
    cancelIcon?: string;
    /** Hide the cancel/back button entirely. */
    hideCancel?: boolean;
    /** Hide the submit button entirely. */
    hideSubmit?: boolean;
    /**
     * Suppress the entire action bar (top + bottom). Use when the host renders
     * its own footer/actions and drives submit via the exposed `submit()` method
     * (e.g. a card-integrated footer). Default: false.
     */
    hideActions?: boolean;
}

export interface FormBuilderConfig {
    layout: FormLayout;
    /** Number of grid columns (default: 2). */
    cols: number;
    /**
     * In horizontal layout, draw hairline dividers between field rows and
     * left-align the labels (settings-style stacked rows). No effect in vertical
     * layout. Default: false.
     */
    dividers?: boolean;
    fields: FieldConfig[];
    /** Extra CSS class(es) applied to the form root element. */
    cssClass?: string;
    /**
     * URL to fetch initial data from (via useApi GET).
     * When set, SkForm shows a loading skeleton until data arrives,
     * then populates fields from the response using dataKey.
     */
    dataUrl?: string;
    /**
     * Refetch `dataUrl` when it changes after mount. Default: false (mount-only).
     *
     * Off by default because a config rebuilt on every parent render would
     * otherwise refetch continuously and wipe in-progress edits. Turn it on for a
     * persistent form whose record genuinely changes (a dialog reused for a
     * different id).
     */
    reloadOnDataUrlChange?: boolean;
    /**
     * Key to extract from the dataUrl response (e.g. 'user').
     * If not set, the entire response is used as initialData.
     */
    dataKey?: string;
    /**
     * Initial data to populate fields.
     * Field values are resolved in order: initialData[field.key] → field.defaultValue → null.
     */
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    initialData?: Record<string, any>;
    /** Where to render the action area. Defaults to 'bottom'. */
    actionsPosition?: 'top' | 'bottom' | 'both';
    /**
     * Submit configuration — enables FormBuilder's internal Inertia form management.
     * When set, FormBuilder handles useForm, submit, errors, and loading internally.
     */
    submit?: FormSubmitConfig;
    /** Labels for the built-in action buttons (used when config.submit is set). */
    actionLabels?: FormActionLabels;
    /**
     * Cancel behavior. When set, FormBuilder handles cancel internally.
     * - 'back': calls window.history.back()
     * - 'emit': emits 'cancel' event to parent
     * Default: 'emit'.
     */
    onCancel?: 'back' | 'emit';
    /**
     * When true, the form behaves as a dialog form:
     * - Cancel button is shown with "Cancel" label (no icon)
     * - onCancel defaults to 'emit'
     * When false (default), cancel button is hidden unless showBack is true.
     */
    inDialog?: boolean;
    /** Show a "Back" button with arrow icon (only applies when inDialog is false). */
    showBack?: boolean;
    /** Card title — when set, SkForm wraps content in a PrimeVue Card. */
    cardTitle?: string;
    /** Card subtitle. */
    cardSubtitle?: string;
    /** When true, strip Card bg/shadow/border (transparent mode). Default: false. */
    isCard?: boolean;
    /**
     * Permission key required to edit the form (e.g. 'users.update').
     * When set and the authenticated user lacks the permission, all fields are
     * disabled and the submit button is hidden.
     */
    permission?: string;
    /**
     * Warn the user before navigating away while the form has unsaved changes.
     * Only applies in internal mode (config.submit set). Default: true.
     * Set false to opt out of the dirty-navigation prompt.
     */
    confirmLeave?: boolean;
}
