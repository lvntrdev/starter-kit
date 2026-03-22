// resources/js/datatable/builder.ts

import type {
    ActionConfig,
    ActionSeverity,
    ButtonSize,
    ButtonVariant,
    ColumnConfig,
    CreateButtonConfig,
    DataTableConfig,
    FilterConfig,
    FilterOption,
    FilterPlacement,
    FilterType,
    IdColumnConfig,
    MenuActionConfig,
    MenuButtonConfig,
    TagSeverity,
} from './types';

export class ColumnBuilder<_T = unknown> {
    private config: Partial<ColumnConfig> = { sortable: true };

    label(label: string): this {
        this.config.label = label;
        return this;
    }

    key(key: string): this {
        this.config.key = key;
        return this;
    }

    sortable(sortable: boolean): this {
        this.config.sortable = sortable;
        return this;
    }

    /** Custom HTML renderer. An `escape` helper is passed as the second argument for XSS-safe output. */
    render(fn: (row: _T, escape: (str: string) => string) => string): this {
        this.config.render = fn as (row: unknown, escape: (str: string) => string) => string;
        return this;
    }

    /** Render cell as a PrimeVue Tag. Use 'definition' for enum/DB definitions, 'custom' for manual severity mapping. */
    tag(type: 'definition' | 'custom'): this {
        this.config.tag = type;
        return this;
    }

    /** Definition key or row property key used to resolve the tag severity. */
    tagKey(key: string): this {
        this.config.tagKey = key;
        return this;
    }

    /** Severity map for custom tags – keys are matched against the tagKey value. */
    severities(map: Record<string, TagSeverity>): this {
        this.config.severities = map;
        return this;
    }

    /** Pin this column so it stays visible while scrolling horizontally. */
    sticky(): this {
        this.config.sticky = true;
        return this;
    }

    build(): ColumnConfig {
        if (!this.config.key) {
            throw new Error('Column must have a key');
        }
        return this.config as ColumnConfig;
    }
}

export class FilterBuilder {
    private config: Partial<FilterConfig> = { type: 'select', placement: 'panel' };

    key(key: string): this {
        this.config.key = key;
        return this;
    }

    label(label: string): this {
        this.config.label = label;
        return this;
    }

    type(type: FilterType): this {
        this.config.type = type;
        return this;
    }

    options(options: FilterOption[]): this {
        this.config.options = options;
        return this;
    }

    placeholder(placeholder: string): this {
        this.config.placeholder = placeholder;
        return this;
    }

    /** Show this filter directly in the toolbar instead of the filter panel. */
    inline(): this {
        this.config.placement = 'inline';
        return this;
    }

    /** Set filter placement: 'inline' (toolbar) or 'panel' (filter box, default). */
    placement(placement: FilterPlacement): this {
        this.config.placement = placement;
        return this;
    }

    build(): FilterConfig {
        if (!this.config.key) {
            throw new Error('Filter must have a key');
        }
        return this.config as FilterConfig;
    }
}

export class ActionBuilder<T = unknown> {
    private config: Partial<ActionConfig<T>> = {};

    icon(icon: string): this {
        this.config.icon = icon;
        return this;
    }

    severity(severity: ActionSeverity): this {
        this.config.severity = severity;
        return this;
    }

    size(size: ButtonSize): this {
        this.config.size = size;
        return this;
    }

    variant(variant: ButtonVariant): this {
        this.config.variant = variant;
        return this;
    }

    rounded(enabled = true): this {
        this.config.rounded = enabled;
        return this;
    }

    raised(enabled = true): this {
        this.config.raised = enabled;
        return this;
    }

    text(enabled = true): this {
        this.config.text = enabled;
        return this;
    }

    outlined(enabled = true): this {
        this.config.outlined = enabled;
        return this;
    }

    label(label: string): this {
        this.config.label = label;
        return this;
    }

    tooltip(tooltip: string): this {
        this.config.tooltip = tooltip;
        return this;
    }

    handle(fn: (row: T) => void): this {
        this.config.handle = fn;
        return this;
    }

    /** Conditionally show this action per row. */
    visible(fn: (row: T) => boolean): this {
        this.config.visible = fn;
        return this;
    }

    build(): ActionConfig<T> {
        if (!this.config.icon || !this.config.handle) {
            throw new Error('Action must have an icon and a handle callback');
        }
        return this.config as ActionConfig<T>;
    }
}

export class MenuActionBuilder<T = unknown> {
    private config: Partial<MenuActionConfig<T>> = {};

    label(label: string): this {
        this.config.label = label;
        return this;
    }

    icon(icon: string): this {
        this.config.icon = icon;
        return this;
    }

    separator(enabled = true): this {
        this.config.separator = enabled;
        return this;
    }

    handle(fn: (row: T) => void): this {
        this.config.handle = fn;
        return this;
    }

    /** Conditionally show this menu action per row. */
    visible(fn: (row: T) => boolean): this {
        this.config.visible = fn;
        return this;
    }

    build(): MenuActionConfig<T> {
        if (!this.config.label || !this.config.handle) {
            throw new Error('MenuAction must have a label and a handle callback');
        }
        return this.config as MenuActionConfig<T>;
    }
}

export class TableBuilder<T = unknown> {
    private config: DataTableConfig<T> = {
        route: '',
        sortable: true,
        pagination: true,
        searchable: true,
        isCard: true,
        perPage: 10,
        idColumn: { visible: true, key: 'id' },
        columns: [],
        filters: [],
        actions: [],
        menuActions: [],
        menuButton: { icon: 'pi pi-ellipsis-v', severity: 'secondary', size: 'small' },
    };

    route(url: string | { url: string } | ((...args: unknown[]) => { url: string })): this {
        if (typeof url === 'function') {
            this.config.route = url().url;
        } else if (typeof url === 'string') {
            this.config.route = url;
        } else {
            this.config.route = url.url;
        }
        return this;
    }

    sortable(enabled: boolean): this {
        this.config.sortable = enabled;
        return this;
    }

    pagination(enabled: boolean): this {
        this.config.pagination = enabled;
        return this;
    }

    searchable(enabled: boolean): this {
        this.config.searchable = enabled;
        return this;
    }

    isCard(enabled = true): this {
        this.config.isCard = enabled;
        return this;
    }

    cardTitle(title: string): this {
        this.config.cardTitle = title;
        return this;
    }

    cardSubtitle(subtitle: string): this {
        this.config.cardSubtitle = subtitle;
        return this;
    }

    perPage(count: number): this {
        this.config.perPage = count;
        return this;
    }

    /** Configure the built-in ID column. Pass `false` to hide it. */
    idColumn(config: IdColumnConfig | false): this {
        this.config.idColumn = config === false ? { visible: false } : { visible: true, key: 'id', ...config };
        return this;
    }

    addColumns(...columns: ColumnBuilder<T>[]): this {
        this.config.columns.push(...columns.map((c) => c.build()));
        return this;
    }

    addFilters(...filters: FilterBuilder[]): this {
        this.config.filters.push(...filters.map((f) => f.build()));
        return this;
    }

    addActions(...actions: ActionBuilder<T>[]): this {
        this.config.actions.push(...actions.map((a) => a.build()));
        return this;
    }

    addMenuActions(...menuActions: MenuActionBuilder<T>[]): this {
        this.config.menuActions.push(...menuActions.map((m) => m.build()));
        return this;
    }

    /** Customize the three-dot menu button appearance. */
    menuButton(config: MenuButtonConfig): this {
        this.config.menuButton = { ...this.config.menuButton, ...config };
        return this;
    }

    create(config: CreateButtonConfig): this {
        this.config.createButton = config;
        return this;
    }

    build(): DataTableConfig<T> {
        if (!this.config.route) {
            throw new Error('DataTable must have a route');
        }
        return { ...this.config };
    }
}
