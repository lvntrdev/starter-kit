// resources/js/tab-builder/types.ts

export type TabLayout = 'horizontal' | 'vertical';

export type TabIconColor =
    | 'blue'
    | 'amber'
    | 'emerald'
    | 'purple'
    | 'teal'
    | 'red'
    | 'rose'
    | 'indigo'
    | 'slate'
    | 'pink'
    | 'orange'
    | 'cyan'
    | 'green'
    | 'yellow';

export type TabBadgeSeverity = 'success' | 'warn' | 'info' | 'danger' | 'secondary';

/**
 * Which panels are mounted: 'active' keeps only the current tab in the DOM
 * (lazy), 'all' mounts every panel up front and keeps it alive across switches.
 */
export type TabPanelMode = 'active' | 'all';

/** History entry written when the active tab changes. */
export type TabHistoryMode = 'push' | 'replace';

/**
 * How the URL is updated on a tab switch: 'server' issues an Inertia visit
 * (the page component re-resolves server side), 'client' rewrites the URL
 * without any request.
 */
export type TabUrlMode = 'server' | 'client';

/** Payload emitted when the active tab changes. */
export interface TabChangePayload {
    key: string;
    /** The key that was active before this change, or null on the first change. */
    previousKey: string | null;
    tab: TabItemConfig;
}

/** The instance surface `<SkTabs>` exposes to a template ref. */
export interface SkTabsExposed {
    activeTab: string;
    isActive: (key: string) => boolean;
}

export interface TabItemConfig {
    key: string;
    label: string;
    icon?: string;
    /** Secondary description shown below the label. Vertical layout only. */
    description?: string;
    /** Icon tile color preset (vertical layout only). Defaults to 'slate'. */
    iconColor?: TabIconColor;
    /** Trailing badge value (number or short text). Ignored when `checked` is true. */
    badge?: string | number;
    /** Trailing badge severity. Defaults to 'secondary'. */
    badgeSeverity?: TabBadgeSeverity;
    /** Shows a green check mark on the trailing edge. Takes precedence over `badge`. */
    checked?: boolean;
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
    /** Panel mounting strategy. Unset keeps today's behaviour. */
    panels?: TabPanelMode;
    /** History entry written on a switch. Defaults to 'replace' when unset. */
    history?: TabHistoryMode;
    /** URL update strategy. Defaults to 'server' when unset. */
    urlMode?: TabUrlMode;
    /** Mirror the active tab in the URL query. Defaults to true when unset. */
    syncUrl?: boolean;
}
