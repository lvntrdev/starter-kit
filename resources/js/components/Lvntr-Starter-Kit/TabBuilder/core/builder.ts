// resources/js/tab-builder/builder.ts

import type {
    TabBadgeSeverity,
    TabBuilderConfig,
    TabHistoryMode,
    TabIconColor,
    TabItemConfig,
    TabLayout,
    TabUrlMode,
} from './types';

/**
 * A copy of a tab item whose array-valued fields are copied too. A spread alone
 * is shallow, so `permission`/`role` would stay SHARED with the builder's
 * private config: pushing to a built config's `permission` array would silently
 * re-gate every later `build()` — and every sibling config already handed out.
 */
function cloneTab(tab: TabItemConfig): TabItemConfig {
    const clone = { ...tab };
    if (Array.isArray(clone.permission)) clone.permission = [...clone.permission];
    if (Array.isArray(clone.role)) clone.role = [...clone.role];
    return clone;
}

export class TabItemBuilder {
    private config: Partial<TabItemConfig> = {};

    key(key: string): this {
        this.config.key = key;
        return this;
    }

    label(label: string): this {
        this.config.label = label;
        return this;
    }

    icon(icon: string): this {
        this.config.icon = icon;
        return this;
    }

    description(text: string): this {
        this.config.description = text;
        return this;
    }

    iconColor(color: TabIconColor): this {
        this.config.iconColor = color;
        return this;
    }

    badge(value: string | number, severity?: TabBadgeSeverity): this {
        this.config.badge = value;
        if (severity) this.config.badgeSeverity = severity;
        return this;
    }

    checked(value: boolean = true): this {
        this.config.checked = value;
        return this;
    }

    permission(...permissions: string[]): this {
        this.config.permission = permissions.length === 1 ? permissions[0] : permissions;
        return this;
    }

    role(...roles: string[]): this {
        this.config.role = roles.length === 1 ? roles[0] : roles;
        return this;
    }

    visible(condition: boolean | (() => boolean)): this {
        this.config.visible = condition;
        return this;
    }

    disabled(condition: boolean | (() => boolean)): this {
        this.config.disabled = condition;
        return this;
    }

    isCard(value: boolean = true): this {
        this.config.isCard = value;
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

    build(): TabItemConfig {
        // A whitespace-only key is as unusable as a missing one: it addresses no
        // slot and produces a `?tab=%20` URL nobody can link to. The message keeps
        // its original prefix so existing assertions still match.
        if (!this.config.key || this.config.key.trim() === '') {
            throw new Error('Tab item must have a key (non-empty string)');
        }
        if (!this.config.label) {
            this.config.label = this.config.key;
        }
        // A copy, not the live config: the same item builder may be built twice
        // (or reused across two tab configs), and a shared object would let a
        // later chained call mutate a config that was already handed out.
        return cloneTab(this.config as TabItemConfig);
    }
}

/** Keys appearing more than once, in first-seen order and reported once each. */
function findDuplicateKeys(tabs: TabItemConfig[]): string[] {
    const seen = new Set<string>();
    const duplicates = new Set<string>();
    for (const tab of tabs) {
        if (seen.has(tab.key)) {
            duplicates.add(tab.key);
        }
        seen.add(tab.key);
    }
    return [...duplicates];
}

export class TabsBuilder {
    private config: TabBuilderConfig = {
        layout: 'horizontal',
        tabs: [],
        queryParam: 'tab',
    };

    layout(layout: TabLayout): this {
        this.config.layout = layout;
        return this;
    }

    vertical(): this {
        this.config.layout = 'vertical';
        return this;
    }

    horizontal(): this {
        this.config.layout = 'horizontal';
        return this;
    }

    queryParam(param: string): this {
        this.config.queryParam = param;
        return this;
    }

    class(cssClass: string): this {
        this.config.cssClass = cssClass;
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

    isCard(value: boolean = true): this {
        this.config.isCard = value;
        return this;
    }

    /** Mount only the active panel. `lazy(false)` clears the setting entirely. */
    lazy(value: boolean = true): this {
        if (value) {
            this.config.panels = 'active';
        } else {
            delete this.config.panels;
        }
        return this;
    }

    /** Mount every panel and keep it alive. `keepAlive(false)` clears the setting. */
    keepAlive(value: boolean = true): this {
        if (value) {
            this.config.panels = 'all';
        } else {
            delete this.config.panels;
        }
        return this;
    }

    history(mode: TabHistoryMode): this {
        this.config.history = mode;
        return this;
    }

    urlMode(mode: TabUrlMode): this {
        this.config.urlMode = mode;
        return this;
    }

    syncUrl(value: boolean = true): this {
        this.config.syncUrl = value;
        return this;
    }

    addTabs(...tabs: TabItemBuilder[]): this {
        this.config.tabs.push(...tabs.map((t) => t.build()));
        return this;
    }

    build(): TabBuilderConfig {
        if (this.config.tabs.length === 0) {
            throw new Error('TabBuilder must have at least one tab');
        }

        const duplicates = findDuplicateKeys(this.config.tabs);
        if (duplicates.length > 0) {
            // Duplicate keys silently break slot resolution and URL selection: the
            // second tab renders the first one's content and can never be reached
            // by `?tab=`. Loud in development, non-fatal in production — a shipped
            // screen must not blank out over a config mistake, and de-duplicating
            // behind the developer's back would only hide it.
            const message = `TabBuilder has duplicate tab keys: ${duplicates.map((key) => `"${key}"`).join(', ')}`;
            if (import.meta.env.DEV) {
                throw new Error(message);
            }
            console.error(message);
        }

        // A snapshot, not a view: `build()` may be called before further `addTabs`
        // calls on the same builder, and the returned config is handed straight to
        // a component. Copying the array and its items keeps an already-built
        // config frozen against both later chaining and consumer mutation.
        return { ...this.config, tabs: this.config.tabs.map((tab) => cloneTab(tab)) };
    }
}
