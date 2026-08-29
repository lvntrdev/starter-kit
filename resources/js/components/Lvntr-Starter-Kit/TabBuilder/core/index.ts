// resources/js/tab-builder/index.ts

export type {
    TabBuilderConfig,
    TabItemConfig,
    TabLayout,
    TabIconColor,
    TabBadgeSeverity,
    TabPanelMode,
    TabHistoryMode,
    TabUrlMode,
    TabChangePayload,
    SkTabsExposed,
} from './types';
export { TabsBuilder, TabItemBuilder } from './builder';

// `useActiveTab` is intentionally NOT re-exported here: it is SkTabs' internal
// state module and pulls in the Inertia router, while this barrel is also the
// config-only entry point (`@lvntr/components/TabBuilder/core`) that pages
// import just to build a `TabBuilderConfig`. SkTabs imports it by path.

import { TabsBuilder, TabItemBuilder } from './builder';

/**
 * Tab builder — fluent API for configuring the <SkTabs> component.
 *
 * @example
 * const config = TB.tabs()
 *   .vertical()
 *   .queryParam('tab')
 *   .addTabs(
 *     TB.item().key('general').label('General').icon('pi pi-user'),
 *     TB.item().key('password').label('Password').icon('pi pi-lock'),
 *     TB.item().key('security').label('Security').icon('pi pi-shield'),
 *   )
 *   .build();
 */
export const TB = {
    tabs: () => new TabsBuilder(),
    item: () => new TabItemBuilder(),
};
