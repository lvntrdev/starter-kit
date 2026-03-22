// resources/js/datatable/types.ts

export type FilterOption = {
    label: string;
    value: string | number | null;
};

export type FilterType = 'select' | 'select-button' | 'date' | 'daterange';

export type FilterPlacement = 'inline' | 'panel';

export type ActionSeverity = 'primary' | 'secondary' | 'success' | 'info' | 'warn' | 'danger' | 'contrast';

export type ButtonSize = 'small' | 'large';

export type ButtonVariant = 'outlined' | 'text';

export type TagSeverity = 'success' | 'info' | 'warn' | 'danger' | 'secondary' | 'contrast';

/** PrimeVue Button style options — shared by actions and create button. */
export interface ButtonStyleOptions {
    severity?: ActionSeverity;
    size?: ButtonSize;
    variant?: ButtonVariant;
    rounded?: boolean;
    raised?: boolean;
    text?: boolean;
    outlined?: boolean;
}

export interface ColumnConfig {
    label?: string;
    key: string;
    sortable: boolean;
    render?: (row: unknown, escape: (str: string) => string) => string;
    tag?: 'definition' | 'custom';
    tagKey?: string;
    severities?: Record<string, TagSeverity>;
    /** Pin this column so it stays visible while scrolling horizontally. */
    sticky?: boolean;
}

export interface IdColumnConfig {
    /** Set to false to hide the built-in ID column. Default: true. */
    visible?: boolean;
    /** Row property to use as the ID value. Default: 'id'. */
    key?: string;
}

export interface FilterConfig {
    key: string;
    label?: string;
    type: FilterType;
    placement: FilterPlacement;
    options?: FilterOption[];
    placeholder?: string;
}

export interface ActionConfig<T = unknown> extends ButtonStyleOptions {
    icon: string;
    label?: string;
    tooltip?: string;
    handle: (row: T) => void;
    visible?: (row: T) => boolean;
}

export interface MenuActionConfig<T = unknown> {
    label: string;
    icon?: string;
    separator?: boolean;
    handle: (row: T) => void;
    visible?: (row: T) => boolean;
}

export interface CreateButtonConfig extends ButtonStyleOptions {
    label?: string;
    icon?: string;
    /** Navigate to this URL (renders as a link). */
    url?: string;
    /** Call this handler (renders as a dialog trigger). */
    onClick?: () => void;
}

export interface MenuButtonConfig extends ButtonStyleOptions {
    icon?: string;
}

export interface DataTableConfig<T = unknown> {
    route: string;
    sortable: boolean;
    pagination: boolean;
    searchable: boolean;
    isCard: boolean;
    cardTitle?: string;
    cardSubtitle?: string;
    perPage: number;
    idColumn: IdColumnConfig;
    columns: ColumnConfig[];
    filters: FilterConfig[];
    actions: ActionConfig<T>[];
    menuActions: MenuActionConfig<T>[];
    menuButton: MenuButtonConfig;
    createButton?: CreateButtonConfig;
}

export interface DataTableResponse<T> {
    data: T[];
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
}
