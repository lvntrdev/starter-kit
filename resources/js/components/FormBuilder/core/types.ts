// resources/js/formbuilder/types.ts

export type FormLayout = 'vertical' | 'horizontal';

export type FieldType =
    | 'input-text'
    | 'input-number'
    | 'input-otp'
    | 'select'
    | 'multiselect'
    | 'radio'
    | 'checkbox'
    | 'password'
    | 'select-button'
    | 'textarea'
    | 'toggle-button'
    | 'toggle-switch'
    | 'checkbox-group'
    | 'file-upload'
    | 'color-selector'
    | 'title'
    | 'slot';

export interface SelectOption {
    label: string;
    value: string | number | boolean | null;
}

export interface BaseFieldConfig {
    key: string;
    label: string;
    type: FieldType;
    required?: boolean;
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
    /** Additional props passed directly to the underlying PrimeVue component. */
    componentProps?: Record<string, unknown>;
    /** Default/initial value for this field. FormBuilder auto-derives initialValues from this. */
    defaultValue?: unknown;
}

export interface InputTextFieldConfig extends BaseFieldConfig {
    type: 'input-text';
    placeholder?: string;
    /** HTML input type (text, email, url, tel, etc.) */
    inputType?: string;
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
     * Inertia shared enum key (e.g. 'userStatus').
     * Options are resolved from `page.props.enums[enumKey]` at render time.
     */
    enumKey?: string;
    /** Filter for enum options (only/except specific values). */
    enumFilter?: OptionFilter;
    /** DB-based definition key (e.g. 'gender'). Options are fetched from /api/v1/definitions. */
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

export interface PasswordFieldConfig extends BaseFieldConfig {
    type: 'password';
    placeholder?: string;
    /** Show strength meter (default: false). */
    feedback?: boolean;
    toggleMask?: boolean;
}

export interface TextareaFieldConfig extends BaseFieldConfig {
    type: 'textarea';
    placeholder?: string;
    rows?: number;
    autoResize?: boolean;
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
}

export interface TitleFieldConfig extends BaseFieldConfig {
    type: 'title';
    /** Tag to render: h2, h3, h4, etc. Default: 'h3'. */
    tag?: string;
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
    /** Maximum file size in bytes (default: 10MB). */
    maxFileSize?: number;
    /** Maximum number of files when multiple is true. */
    fileLimit?: number;
    /** Existing media items to display in edit mode. */
    existingMedia?: ExistingMedia[];
    /** Key in initialData/remoteData to auto-populate existingMedia (e.g. 'identity_document_media'). */
    existingMediaKey?: string;
}

export interface ColorSelectorFieldConfig extends BaseFieldConfig {
    type: 'color-selector';
    /** Available color keys. Defaults to all Tailwind color palettes. */
    colors?: string[];
    /** Tone steps to display. Defaults to [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]. */
    tones?: number[];
}

export interface SlotFieldConfig extends BaseFieldConfig {
    type: 'slot';
    /** The slot name to render. Defaults to field.key. */
    slotName?: string;
}

export type FieldConfig =
    | InputTextFieldConfig
    | InputNumberFieldConfig
    | InputOtpFieldConfig
    | SelectFieldConfig
    | CheckboxFieldConfig
    | PasswordFieldConfig
    | TextareaFieldConfig
    | ToggleButtonFieldConfig
    | ToggleSwitchFieldConfig
    | FileUploadFieldConfig
    | ColorSelectorFieldConfig
    | TitleFieldConfig
    | SlotFieldConfig;

export interface FormSubmitConfig {
    url: string;
    method: 'post' | 'put' | 'patch';
    preserveScroll?: boolean;
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
}

export interface FormBuilderConfig {
    layout: FormLayout;
    /** Number of grid columns (default: 2). */
    cols: number;
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
}
