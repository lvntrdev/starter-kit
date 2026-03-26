// resources/js/tab-builder/builder.ts

import type { TabBuilderConfig, TabItemConfig, TabLayout } from './types';

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
        if (!this.config.key) {
            throw new Error('Tab item must have a key');
        }
        if (!this.config.label) {
            this.config.label = this.config.key;
        }
        return this.config as TabItemConfig;
    }
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

    addTabs(...tabs: TabItemBuilder[]): this {
        this.config.tabs.push(...tabs.map((t) => t.build()));
        return this;
    }

    build(): TabBuilderConfig {
        if (this.config.tabs.length === 0) {
            throw new Error('TabBuilder must have at least one tab');
        }
        return { ...this.config };
    }
}
