// resources/js/tab-builder/types.ts

export type TabLayout = 'horizontal' | 'vertical';

export interface TabItemConfig {
    key: string;
    label: string;
    icon?: string;
    permission?: string | string[];
    role?: string | string[];
    visible?: boolean | (() => boolean);
    disabled?: boolean | (() => boolean);
    /** Per-tab Card visibility. Overrides the global isCard when set. */
    isCard?: boolean;
    /** Per-tab Card title. Overrides the global cardTitle when set. */
    cardTitle?: string;
    /** Per-tab Card subtitle. Overrides the global cardSubtitle when set. */
    cardSubtitle?: string;
}

export interface TabBuilderConfig {
    layout: TabLayout;
    tabs: TabItemConfig[];
    queryParam: string;
    cssClass?: string;
    cardTitle?: string;
    cardSubtitle?: string;
    isCard?: boolean;
}
