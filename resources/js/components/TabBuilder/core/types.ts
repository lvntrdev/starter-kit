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
