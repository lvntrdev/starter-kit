import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h, nextTick, onMounted, reactive, ref } from 'vue';
import type { PropType } from 'vue';
import PrimeVue from 'primevue/config';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';

import { TB } from '../core';
import type { TabBuilderConfig, TabItemConfig } from '../core';

/**
 * Locks today's public SkTabs contract (DOM markers, permission/role gating,
 * visible/disabled, the dynamic content slot, sidebar slots, defineExpose,
 * URL sync, and the vertical-vs-horizontal mount-lifecycle divergence) with
 * green tests, and covers the regressions closed in the "regressions" block
 * below: R1 `type="button"`, R2 the reactive (non-snapshot) tab list, R3
 * disabled tabs kept out of URL selection.
 *
 * The blocks after the regressions cover the additive surface (v-model, the
 * change event, url/history modes, panel modes, the empty slot, ARIA and
 * keyboard navigation); everything above them is the untouched contract.
 *
 * Mock shapes reused from the sibling FormBuilder specs in this library:
 * Inertia + useCan from TranslatableInput.spec.ts, `$t` + SkCard stub from
 * SkForm.fileUpload.spec.ts.
 */

// jsdom has no ResizeObserver; PrimeVue's TabList binds one for the ink bar.
class ResizeObserverStub {
    observe() {}
    unobserve() {}
    disconnect() {}
}
// eslint-disable-next-line @typescript-eslint/no-explicit-any
globalThis.ResizeObserver = ResizeObserverStub as any;

// `usePage()` returns a mutable `reactive({ url })` the tests write to
// directly; `router.visit` is a spy the tests assert against.
const page = reactive<{ url: string; props: Record<string, unknown> }>({
    url: '/settings',
    props: {},
});
const routerVisit = vi.fn();
// `replace`/`push` are the client-side URL mode's transport; a server-mode test
// asserts against them staying untouched, so they are spies here too.
const routerReplace = vi.fn();
const routerPush = vi.fn();

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => page,
    router: { visit: routerVisit, replace: routerReplace, push: routerPush },
}));

// Configurable permissions/roles for the "permission/role OR semantics" tests.
const authState = reactive<{ permissions: string[]; roles: string[] }>({
    permissions: [],
    roles: [],
});

vi.mock('@/composables/useCan', () => ({
    useCan: () => ({
        can: (permission: string) => authState.permissions.includes(permission),
        canAny: (permissions: string[]) => permissions.some((p) => authState.permissions.includes(p)),
        hasRole: (role: string) => authState.roles.includes(role),
    }),
}));

const { default: SkTabs } = await import('../SkTabs.vue');

/** SkCard stub that still renders its `content` slot, same shape as SkForm.fileUpload.spec.ts. */
const SkCardStub = defineComponent({
    name: 'SkCard',
    setup(_props, { slots }) {
        return () => h('div', slots.content?.());
    },
});

const globalOptions = {
    mocks: { $t: (key: string) => key },
    stubs: { SkCard: SkCardStub },
    plugins: [PrimeVue],
    components: { Tabs, TabList, Tab, TabPanels, TabPanel },
};

const mountedWrappers: Array<{ unmount: () => void }> = [];

beforeEach(() => {
    page.url = '/settings';
    authState.permissions = [];
    authState.roles = [];
    routerVisit.mockClear();
    routerReplace.mockClear();
    routerPush.mockClear();
});

afterEach(() => {
    mountedWrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

/** One content slot per tab, rendering a `data-testid="panel-<key>"` marker. */
function defaultSlots(config: TabBuilderConfig) {
    const slots: Record<string, (props: { tab: TabItemConfig; isActive: boolean }) => unknown> = {};
    for (const tab of config.tabs) {
        slots[tab.key] = (slotProps: { tab: TabItemConfig; isActive: boolean }) =>
            h('div', { 'data-testid': `panel-${tab.key}` }, `${tab.key}:${slotProps.isActive}`);
    }
    return slots;
}

/**
 * Extra props/slots and `attachTo` are optional: every call written before the
 * v-model work keeps its one-argument shape and its default slots.
 */
interface MountTabsOptions {
    props?: Record<string, unknown>;
    slots?: Record<string, (...args: never[]) => unknown>;
    attachTo?: Element;
}

function mountTabs(config: TabBuilderConfig, options: MountTabsOptions = {}) {
    const wrapper = mount(SkTabs, {
        props: { config, ...options.props },
        slots: options.slots ?? defaultSlots(config),
        attachTo: options.attachTo,
        global: globalOptions,
    });
    mountedWrappers.push(wrapper);
    return wrapper;
}

/** Logs its own mount so a panel-mode case can assert what was rendered. */
function mountProbe(mountLog: string[]) {
    return defineComponent({
        props: { id: { type: String, required: true } },
        setup(props) {
            onMounted(() => mountLog.push(props.id));
            return () => h('div', props.id);
        },
    });
}

// ── DOM markers ────────────────────────────────────────────────────────────

describe('SkTabs — layout DOM markers', () => {
    it('horizontal (default) layout renders the PrimeVue panel wrapper, not the vertical shell', () => {
        const config = TB.tabs().addTabs(TB.item().key('general'), TB.item().key('security')).build();
        const wrapper = mountTabs(config);

        expect(wrapper.find('.sk-tabs-vertical').exists()).toBe(false);
        expect(wrapper.find('.sk-tabs__panel').exists()).toBe(true);
    });

    it('vertical layout renders the sidebar nav with active/disabled button markers', () => {
        const config = TB.tabs()
            .vertical()
            .addTabs(TB.item().key('general'), TB.item().key('security').disabled(true))
            .build();
        const wrapper = mountTabs(config);

        expect(wrapper.find('.sk-tabs-vertical').exists()).toBe(true);
        expect(wrapper.find('.sk-vtab-nav').exists()).toBe(true);

        const buttons = wrapper.findAll('.sk-vtab');
        expect(buttons).toHaveLength(2);
        expect(buttons[0].classes()).toContain('sk-vtab--active');
        expect(buttons[1].classes()).toContain('sk-vtab--disabled');
    });
});

// ── permission / role ────────────────────────────────────────────────────────

describe('SkTabs — permission/role gating (OR semantics)', () => {
    it('a tab with a required permission is hidden until granted', () => {
        const config = TB.tabs()
            .vertical()
            .addTabs(TB.item().key('general'), TB.item().key('security').permission('users.update'))
            .build();
        const wrapper = mountTabs(config);

        expect(wrapper.findAll('.sk-vtab')).toHaveLength(1);
    });

    it('a tab with a required permission renders once granted', () => {
        authState.permissions = ['users.update'];
        const config = TB.tabs()
            .vertical()
            .addTabs(TB.item().key('general'), TB.item().key('security').permission('users.update'))
            .build();
        const wrapper = mountTabs(config);

        expect(wrapper.findAll('.sk-vtab')).toHaveLength(2);
    });

    it('multiple permissions are OR-ed — holding just one is enough', () => {
        authState.permissions = ['users.delete'];
        const config = TB.tabs()
            .vertical()
            .addTabs(TB.item().key('general'), TB.item().key('security').permission('users.update', 'users.delete'))
            .build();
        const wrapper = mountTabs(config);

        expect(wrapper.findAll('.sk-vtab')).toHaveLength(2);
    });

    it('multiple roles are OR-ed — holding just one is enough', () => {
        authState.roles = ['auditor'];
        const config = TB.tabs()
            .vertical()
            .addTabs(TB.item().key('general'), TB.item().key('security').role('admin', 'auditor'))
            .build();
        const wrapper = mountTabs(config);

        expect(wrapper.findAll('.sk-vtab')).toHaveLength(2);
    });

    it('a tab requiring both permission and role stays hidden when neither is granted', () => {
        const config = TB.tabs()
            .vertical()
            .addTabs(TB.item().key('general'), TB.item().key('security').permission('users.update').role('admin'))
            .build();
        const wrapper = mountTabs(config);

        expect(wrapper.findAll('.sk-vtab')).toHaveLength(1);
    });
});

// ── visible ──────────────────────────────────────────────────────────────────

describe('SkTabs — visible', () => {
    it('a boolean false hides the tab', () => {
        const config = TB.tabs()
            .vertical()
            .addTabs(TB.item().key('general'), TB.item().key('security').visible(false))
            .build();
        const wrapper = mountTabs(config);

        expect(wrapper.findAll('.sk-vtab')).toHaveLength(1);
    });

    it('a getter function is evaluated to decide visibility', () => {
        const config = TB.tabs()
            .vertical()
            .addTabs(TB.item().key('general'), TB.item().key('security').visible(() => false))
            .build();
        const wrapper = mountTabs(config);

        expect(wrapper.findAll('.sk-vtab')).toHaveLength(1);
    });
});

// ── disabled ─────────────────────────────────────────────────────────────────

describe('SkTabs — disabled', () => {
    it('a boolean true renders the disabled attribute and class', () => {
        const config = TB.tabs()
            .vertical()
            .addTabs(TB.item().key('general'), TB.item().key('security').disabled(true))
            .build();
        const wrapper = mountTabs(config);
        const button = wrapper.findAll('.sk-vtab')[1];

        expect(button.classes()).toContain('sk-vtab--disabled');
        expect(button.attributes('disabled')).toBeDefined();
    });

    it('a getter function is evaluated for the disabled attribute', () => {
        const config = TB.tabs()
            .vertical()
            .addTabs(TB.item().key('general'), TB.item().key('security').disabled(() => true))
            .build();
        const wrapper = mountTabs(config);
        const button = wrapper.findAll('.sk-vtab')[1];

        expect(button.classes()).toContain('sk-vtab--disabled');
        expect(button.attributes('disabled')).toBeDefined();
    });

    it('false leaves the button enabled', () => {
        const config = TB.tabs()
            .vertical()
            .addTabs(TB.item().key('general'), TB.item().key('security').disabled(false))
            .build();
        const wrapper = mountTabs(config);
        const button = wrapper.findAll('.sk-vtab')[1];

        expect(button.classes()).not.toContain('sk-vtab--disabled');
        expect(button.attributes('disabled')).toBeUndefined();
    });
});

// ── content slot ─────────────────────────────────────────────────────────────

describe('SkTabs — content slot', () => {
    it('passes { tab, isActive } to the slot named by the tab key', () => {
        const config = TB.tabs().vertical().addTabs(TB.item().key('general').label('General')).build();
        const wrapper = mount(SkTabs, {
            props: { config },
            slots: {
                general: (slotProps: { tab: TabItemConfig; isActive: boolean }) =>
                    h(
                        'div',
                        { 'data-testid': 'slot-probe' },
                        `${slotProps.tab.key}|${slotProps.tab.label}|${slotProps.isActive}`,
                    ),
            },
            global: globalOptions,
        });
        mountedWrappers.push(wrapper);

        expect(wrapper.get('[data-testid="slot-probe"]').text()).toBe('general|General|true');
    });
});

// ── sidebar-header / sidebar-footer ──────────────────────────────────────────

describe('SkTabs — sidebar-header / sidebar-footer slots', () => {
    it('renders both slots in vertical layout', () => {
        const config = TB.tabs().vertical().addTabs(TB.item().key('general')).build();
        const wrapper = mount(SkTabs, {
            props: { config },
            slots: {
                ...defaultSlots(config),
                'sidebar-header': () => h('div', { 'data-testid': 'sb-header' }),
                'sidebar-footer': () => h('div', { 'data-testid': 'sb-footer' }),
            },
            global: globalOptions,
        });
        mountedWrappers.push(wrapper);

        expect(wrapper.find('[data-testid="sb-header"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="sb-footer"]').exists()).toBe(true);
    });

    it('is not rendered in horizontal layout even when the slot content is provided', () => {
        const config = TB.tabs().addTabs(TB.item().key('general')).build();
        const wrapper = mount(SkTabs, {
            props: { config },
            slots: {
                ...defaultSlots(config),
                'sidebar-header': () => h('div', { 'data-testid': 'sb-header' }),
            },
            global: globalOptions,
        });
        mountedWrappers.push(wrapper);

        expect(wrapper.find('[data-testid="sb-header"]').exists()).toBe(false);
    });
});

// ── defineExpose ─────────────────────────────────────────────────────────────

describe('SkTabs — defineExpose', () => {
    it('exposes activeTab and isActive on the component instance', () => {
        const config = TB.tabs().vertical().addTabs(TB.item().key('general'), TB.item().key('security')).build();
        page.url = '/settings?tab=security';
        const wrapper = mountTabs(config);

        expect(wrapper.vm.activeTab).toBe('security');
        expect(wrapper.vm.isActive('security')).toBe(true);
        expect(wrapper.vm.isActive('general')).toBe(false);
    });
});

// ── URL sync (read) ──────────────────────────────────────────────────────────

describe('SkTabs — URL sync (read)', () => {
    it('no param resolves to the first tab', () => {
        const config = TB.tabs().vertical().addTabs(TB.item().key('general'), TB.item().key('security')).build();
        const wrapper = mountTabs(config);

        expect(wrapper.vm.activeTab).toBe('general');
    });

    it('?tab=<key> resolves to that tab', () => {
        page.url = '/settings?tab=security';
        const config = TB.tabs().vertical().addTabs(TB.item().key('general'), TB.item().key('security')).build();
        const wrapper = mountTabs(config);

        expect(wrapper.vm.activeTab).toBe('security');
    });

    it('an unknown key falls back to the first tab', () => {
        page.url = '/settings?tab=unknown';
        const config = TB.tabs().vertical().addTabs(TB.item().key('general'), TB.item().key('security')).build();
        const wrapper = mountTabs(config);

        expect(wrapper.vm.activeTab).toBe('general');
    });

    it('honors a custom queryParam name', () => {
        page.url = '/settings?section=security';
        const config = TB.tabs()
            .vertical()
            .queryParam('section')
            .addTabs(TB.item().key('general'), TB.item().key('security'))
            .build();
        const wrapper = mountTabs(config);

        expect(wrapper.vm.activeTab).toBe('security');
    });
});

// ── URL sync (click, vertical) ───────────────────────────────────────────────

describe('SkTabs — URL sync (click, vertical)', () => {
    it('clicking a tab visits with the query param set to its key', async () => {
        const config = TB.tabs().vertical().addTabs(TB.item().key('general'), TB.item().key('security')).build();
        const wrapper = mountTabs(config);

        await wrapper.findAll('.sk-vtab')[1].trigger('click');

        expect(routerVisit).toHaveBeenCalledWith('/settings?tab=security', {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    });

    it('clicking the first tab removes the param while other params survive', async () => {
        page.url = '/settings?tab=security&foo=bar';
        const config = TB.tabs().vertical().addTabs(TB.item().key('general'), TB.item().key('security')).build();
        const wrapper = mountTabs(config);

        await wrapper.findAll('.sk-vtab')[0].trigger('click');

        expect(routerVisit).toHaveBeenCalledWith('/settings?foo=bar', {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    });
});

// ── lifecycle divergence ─────────────────────────────────────────────────────

describe('SkTabs — lifecycle (documents the current mount divergence, do not change)', () => {
    it('vertical mounts only the active panel', () => {
        const mountLog: string[] = [];
        const Probe = defineComponent({
            props: { id: { type: String, required: true } },
            setup(props) {
                onMounted(() => mountLog.push(props.id));
                return () => h('div', props.id);
            },
        });
        const config = TB.tabs().vertical().addTabs(TB.item().key('general'), TB.item().key('security')).build();
        const wrapper = mount(SkTabs, {
            props: { config },
            slots: {
                general: () => h(Probe, { id: 'general' }),
                security: () => h(Probe, { id: 'security' }),
            },
            global: globalOptions,
        });
        mountedWrappers.push(wrapper);

        expect(mountLog).toEqual(['general']);
    });

    it('horizontal mounts every panel regardless of which tab is active', () => {
        const mountLog: string[] = [];
        const Probe = defineComponent({
            props: { id: { type: String, required: true } },
            setup(props) {
                onMounted(() => mountLog.push(props.id));
                return () => h('div', props.id);
            },
        });
        const config = TB.tabs().addTabs(TB.item().key('general'), TB.item().key('security')).build();
        const wrapper = mount(SkTabs, {
            props: { config },
            slots: {
                general: () => h(Probe, { id: 'general' }),
                security: () => h(Probe, { id: 'security' }),
            },
            global: globalOptions,
        });
        mountedWrappers.push(wrapper);

        expect([...mountLog].sort()).toEqual(['general', 'security']);
    });
});

// ── regressions (Task 2 fixes) ───────────────────────────────────────────────

describe('SkTabs — regressions (Task 2 fixes)', () => {
    it(
        'R1: vertical tab buttons carry type="button" so a wrapping form never submits on click',
        () => {
            const config = TB.tabs().vertical().addTabs(TB.item().key('general'), TB.item().key('security')).build();
            const submitHandler = vi.fn((event: Event) => event.preventDefault());

            const Host = defineComponent({
                props: {
                    config: { type: Object as PropType<TabBuilderConfig>, required: true },
                    submitHandler: { type: Function as PropType<(event: Event) => void>, required: true },
                },
                setup(props) {
                    return () =>
                        h('form', { onSubmit: props.submitHandler }, [
                            h(
                                SkTabs,
                                { config: props.config },
                                Object.fromEntries(
                                    props.config.tabs.map((tab) => [tab.key, () => h('div', tab.key)]),
                                ),
                            ),
                        ]);
                },
            });

            const wrapper = mount(Host, {
                props: { config, submitHandler },
                attachTo: document.body,
                global: globalOptions,
            });
            mountedWrappers.push(wrapper);

            const buttons = wrapper.findAll('.sk-vtab');
            expect(buttons.every((button) => button.attributes('type') === 'button')).toBe(true);

            (buttons[0].element as HTMLButtonElement).click();

            expect(submitHandler).not.toHaveBeenCalled();
        },
    );

    it(
        'R2a: a tab that becomes visible after mount is trackable — clicking it updates isActive/panel once the URL reflects it',
        async () => {
            const show = ref(false);
            const config = TB.tabs()
                .vertical()
                .addTabs(TB.item().key('general'), TB.item().key('security').visible(() => show.value))
                .build();
            const wrapper = mountTabs(config);

            show.value = true;
            await nextTick();

            await wrapper.findAll('.sk-vtab')[1].trigger('click');
            expect(routerVisit).toHaveBeenCalledWith('/settings?tab=security', {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });

            page.url = '/settings?tab=security';
            await nextTick();

            expect(wrapper.vm.isActive('security')).toBe(true);
            expect(wrapper.find('[data-testid="panel-security"]').exists()).toBe(true);
        },
    );

    it(
        'R2b: an active tab that becomes hidden falls back to the first selectable tab instead of rendering nothing',
        async () => {
            const show = ref(true);
            const config = TB.tabs()
                .vertical()
                .addTabs(TB.item().key('general'), TB.item().key('security').visible(() => show.value))
                .build();
            page.url = '/settings?tab=security';
            const wrapper = mountTabs(config);
            expect(wrapper.vm.isActive('security')).toBe(true);

            show.value = false;
            await nextTick();

            expect(wrapper.vm.activeTab).toBe('general');
            expect(wrapper.find('[data-testid="panel-general"]').exists()).toBe(true);
        },
    );

    it('R3a: a disabled tab named in the URL is not activated — the first selectable tab wins and the disabled panel stays unmounted', () => {
        const config = TB.tabs()
            .vertical()
            .addTabs(TB.item().key('general'), TB.item().key('security').disabled(true))
            .build();
        page.url = '/settings?tab=security';
        const wrapper = mountTabs(config);

        expect(wrapper.vm.activeTab).toBe('general');
        expect(wrapper.find('[data-testid="panel-security"]').exists()).toBe(false);
    });

    it(
        'R3b: the first selectable tab, not a disabled first tab, is the param-less default and the param-removed target',
        async () => {
            // A third selectable tab is required, not decoration: with only
            // `security` selectable, every click on it re-selects the tab that is
            // already active and U5's no-op guard (rightly) suppresses the visit,
            // so the param-removed target would be unobservable. Parking the URL on
            // `sessions` first makes the click below a real tab change.
            const config = TB.tabs()
                .vertical()
                .addTabs(
                    TB.item().key('general').disabled(true),
                    TB.item().key('security'),
                    TB.item().key('sessions'),
                )
                .build();
            const wrapper = mountTabs(config);

            expect(wrapper.vm.activeTab).toBe('security');

            page.url = '/settings?tab=sessions';
            await nextTick();
            expect(wrapper.vm.activeTab).toBe('sessions');

            await wrapper.findAll('.sk-vtab')[1].trigger('click');
            expect(routerVisit).toHaveBeenCalledWith('/settings', {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        },
    );

    it(
        'R3c: an active tab whose disabled getter flips to true falls back to the first selectable tab',
        async () => {
            const disabled = ref(false);
            const config = TB.tabs()
                .vertical()
                .addTabs(TB.item().key('general'), TB.item().key('security').disabled(() => disabled.value))
                .build();
            page.url = '/settings?tab=security';
            const wrapper = mountTabs(config);
            expect(wrapper.vm.activeTab).toBe('security');

            disabled.value = true;
            await nextTick();

            expect(wrapper.vm.activeTab).toBe('general');
        },
    );
});

// ── v-model / change (URL mode) ──────────────────────────────────────────────

describe('SkTabs — v-model (URL mode)', () => {
    it('a modelValue disagreeing with the URL is corrected on mount, not obeyed', () => {
        page.url = '/settings?tab=security';
        const config = TB.tabs().vertical().addTabs(TB.item().key('general'), TB.item().key('security')).build();
        const wrapper = mountTabs(config, { props: { modelValue: 'general' } });

        expect(wrapper.vm.activeTab).toBe('security');
        expect(wrapper.emitted('update:modelValue')).toEqual([['security']]);
        // Mount is not a change.
        expect(wrapper.emitted('change')).toBeUndefined();
    });

    it('a modelValue already matching the URL emits nothing', () => {
        page.url = '/settings?tab=security';
        const config = TB.tabs().vertical().addTabs(TB.item().key('general'), TB.item().key('security')).build();
        const wrapper = mountTabs(config, { props: { modelValue: 'security' } });

        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('a host-driven modelValue change visits the URL for that tab', async () => {
        const config = TB.tabs().vertical().addTabs(TB.item().key('general'), TB.item().key('security')).build();
        const wrapper = mountTabs(config, { props: { modelValue: 'general' } });

        await wrapper.setProps({ modelValue: 'security' });

        expect(routerVisit).toHaveBeenCalledWith('/settings?tab=security', {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    });

    it('a modelValue naming no tab is refused: no visit, and the resolved key is emitted back', async () => {
        const config = TB.tabs().vertical().addTabs(TB.item().key('general'), TB.item().key('security')).build();
        const wrapper = mountTabs(config, { props: { modelValue: 'general' } });

        await wrapper.setProps({ modelValue: 'nope' });

        expect(routerVisit).not.toHaveBeenCalled();
        expect(routerReplace).not.toHaveBeenCalled();
        expect(routerPush).not.toHaveBeenCalled();
        expect(wrapper.vm.activeTab).toBe('general');
        expect(wrapper.emitted('update:modelValue')).toEqual([['general']]);
    });

    it('a modelValue naming a disabled tab is refused exactly like a missing one', async () => {
        const config = TB.tabs()
            .vertical()
            .addTabs(TB.item().key('general'), TB.item().key('security').disabled(true))
            .build();
        const wrapper = mountTabs(config, { props: { modelValue: 'general' } });

        await wrapper.setProps({ modelValue: 'security' });

        expect(routerVisit).not.toHaveBeenCalled();
        expect(wrapper.vm.activeTab).toBe('general');
        expect(wrapper.emitted('update:modelValue')).toEqual([['general']]);
    });
});

// ── v-model / change (local mode) ────────────────────────────────────────────

describe('SkTabs — v-model (local mode, syncUrl(false))', () => {
    const localConfig = (...keys: string[]) =>
        TB.tabs()
            .vertical()
            .syncUrl(false)
            .addTabs(...keys.map((key) => TB.item().key(key)))
            .build();

    it('a click stays local and emits update:modelValue plus change', async () => {
        const config = localConfig('general', 'security');
        const wrapper = mountTabs(config);

        await wrapper.findAll('.sk-vtab')[1].trigger('click');

        expect(routerVisit).not.toHaveBeenCalled();
        expect(routerReplace).not.toHaveBeenCalled();
        expect(routerPush).not.toHaveBeenCalled();
        expect(wrapper.vm.activeTab).toBe('security');

        // The mount emit aligns a host that passed no modelValue at all.
        expect(wrapper.emitted('update:modelValue')).toEqual([['general'], ['security']]);
        expect(wrapper.emitted('change')).toEqual([
            [{ key: 'security', previousKey: 'general', tab: { key: 'security', label: 'security' } }],
        ]);
    });

    it('ignores ?tab= and starts from modelValue', () => {
        page.url = '/settings?tab=security';
        const config = localConfig('general', 'security', 'sessions');
        const wrapper = mountTabs(config, { props: { modelValue: 'sessions' } });

        expect(wrapper.vm.activeTab).toBe('sessions');
    });

    it('a modelValue naming a disabled tab falls back to the first selectable one', () => {
        const config = TB.tabs()
            .vertical()
            .syncUrl(false)
            .addTabs(TB.item().key('general'), TB.item().key('security').disabled(true))
            .build();
        const wrapper = mountTabs(config, { props: { modelValue: 'security' } });

        expect(wrapper.vm.activeTab).toBe('general');
        expect(wrapper.emitted('update:modelValue')).toEqual([['general']]);
    });

    it('a host-driven modelValue change moves the tab without any router call', async () => {
        const config = localConfig('general', 'security');
        const wrapper = mountTabs(config, { props: { modelValue: 'general' } });

        await wrapper.setProps({ modelValue: 'security' });

        expect(wrapper.vm.activeTab).toBe('security');
        expect(wrapper.find('[data-testid="panel-security"]').exists()).toBe(true);
        expect(routerVisit).not.toHaveBeenCalled();
    });

    it('a host-driven modelValue naming no tab leaves the state put and emits the resolved key back', async () => {
        const config = localConfig('general', 'security');
        const wrapper = mountTabs(config, { props: { modelValue: 'general' } });

        await wrapper.setProps({ modelValue: 'nope' });

        expect(wrapper.vm.activeTab).toBe('general');
        expect(wrapper.find('[data-testid="panel-general"]').exists()).toBe(true);
        expect(wrapper.emitted('update:modelValue')).toEqual([['general']]);
        expect(routerVisit).not.toHaveBeenCalled();
        expect(routerReplace).not.toHaveBeenCalled();
        expect(routerPush).not.toHaveBeenCalled();
    });
});

// ── urlMode / history ────────────────────────────────────────────────────────

describe('SkTabs — urlMode / history', () => {
    it("urlMode('client') rewrites the URL instead of visiting", async () => {
        const config = TB.tabs()
            .vertical()
            .urlMode('client')
            .addTabs(TB.item().key('general'), TB.item().key('security'))
            .build();
        const wrapper = mountTabs(config);

        await wrapper.findAll('.sk-vtab')[1].trigger('click');

        expect(routerReplace).toHaveBeenCalledWith({
            url: '/settings?tab=security',
            preserveState: true,
            preserveScroll: true,
        });
        expect(routerVisit).not.toHaveBeenCalled();
        expect(routerPush).not.toHaveBeenCalled();
    });

    it("history('push') visits with replace: false in server mode", async () => {
        const config = TB.tabs()
            .vertical()
            .history('push')
            .addTabs(TB.item().key('general'), TB.item().key('security'))
            .build();
        const wrapper = mountTabs(config);

        await wrapper.findAll('.sk-vtab')[1].trigger('click');

        expect(routerVisit).toHaveBeenCalledWith('/settings?tab=security', {
            preserveState: true,
            preserveScroll: true,
            replace: false,
        });
    });

    it("history('push') pushes in client mode", async () => {
        const config = TB.tabs()
            .vertical()
            .urlMode('client')
            .history('push')
            .addTabs(TB.item().key('general'), TB.item().key('security'))
            .build();
        const wrapper = mountTabs(config);

        await wrapper.findAll('.sk-vtab')[1].trigger('click');

        expect(routerPush).toHaveBeenCalledWith({
            url: '/settings?tab=security',
            preserveState: true,
            preserveScroll: true,
        });
        expect(routerReplace).not.toHaveBeenCalled();
        expect(routerVisit).not.toHaveBeenCalled();
    });
});

// ── panel modes ──────────────────────────────────────────────────────────────

describe('SkTabs — panel modes', () => {
    it('keepAlive() keeps every vertical panel mounted and hides the inactive ones', () => {
        const mountLog: string[] = [];
        const Probe = mountProbe(mountLog);
        const config = TB.tabs()
            .vertical()
            .keepAlive()
            .addTabs(TB.item().key('general'), TB.item().key('security'))
            .build();
        const wrapper = mountTabs(config, {
            slots: {
                general: () => h(Probe, { id: 'general' }),
                security: () => h(Probe, { id: 'security' }),
            },
        });

        expect([...mountLog].sort()).toEqual(['general', 'security']);

        // v-show sits on the card wrapping each panel, so the inactive one is
        // rendered but display:none — its component state survives a switch.
        const panels = wrapper.findAll('[role="tabpanel"]');
        expect(panels).toHaveLength(2);
        expect((panels[0].element.parentElement as HTMLElement).style.display).toBe('');
        expect((panels[1].element.parentElement as HTMLElement).style.display).toBe('none');
    });

    it('lazy() mounts only the active panel in the horizontal layout', () => {
        const mountLog: string[] = [];
        const Probe = mountProbe(mountLog);
        const config = TB.tabs().lazy().addTabs(TB.item().key('general'), TB.item().key('security')).build();
        mountTabs(config, {
            slots: {
                general: () => h(Probe, { id: 'general' }),
                security: () => h(Probe, { id: 'security' }),
            },
        });

        expect(mountLog).toEqual(['general']);
    });
});

// ── empty slot ───────────────────────────────────────────────────────────────

describe('SkTabs — empty slot', () => {
    /** Every tab gated behind a permission nobody holds. */
    const gatedConfig = (vertical: boolean) => {
        const builder = TB.tabs();
        if (vertical) builder.vertical();
        return builder.addTabs(TB.item().key('general').permission('users.update')).build();
    };

    it('replaces the vertical shell when no tab is visible', () => {
        const wrapper = mountTabs(gatedConfig(true), {
            slots: { empty: () => h('div', { 'data-testid': 'empty' }) },
        });

        expect(wrapper.find('[data-testid="empty"]').exists()).toBe(true);
        expect(wrapper.find('.sk-tabs-vertical').exists()).toBe(false);
        expect(wrapper.find('.sk-vtab').exists()).toBe(false);
    });

    it('replaces the horizontal shell when no tab is visible', () => {
        const wrapper = mountTabs(gatedConfig(false), {
            slots: { empty: () => h('div', { 'data-testid': 'empty' }) },
        });

        expect(wrapper.find('[data-testid="empty"]').exists()).toBe(true);
        expect(wrapper.find('.sk-tabs__panel').exists()).toBe(false);
    });

    it('without the slot the shell renders exactly as before', () => {
        const wrapper = mountTabs(gatedConfig(true));

        expect(wrapper.find('.sk-tabs-vertical').exists()).toBe(true);
        expect(wrapper.find('.sk-vtab-nav').exists()).toBe(true);
    });
});

// ── vertical ARIA ────────────────────────────────────────────────────────────

describe('SkTabs — vertical ARIA', () => {
    it('wires the tablist, its tabs and the active panel together', () => {
        const config = TB.tabs().vertical().addTabs(TB.item().key('general'), TB.item().key('security')).build();
        const wrapper = mountTabs(config);

        const nav = wrapper.get('.sk-vtab-nav');
        expect(nav.attributes('role')).toBe('tablist');
        expect(nav.attributes('aria-orientation')).toBe('vertical');

        const tabs = wrapper.findAll('[role="tab"]');
        expect(tabs).toHaveLength(2);
        expect(tabs[0].attributes('aria-selected')).toBe('true');
        expect(tabs[1].attributes('aria-selected')).toBe('false');
        // Roving tabindex: one stop for the whole list.
        expect(tabs[0].attributes('tabindex')).toBe('0');
        expect(tabs[1].attributes('tabindex')).toBe('-1');

        const panel = wrapper.get('[role="tabpanel"]');
        expect(tabs[0].attributes('aria-controls')).toBe(panel.attributes('id'));
        expect(panel.attributes('aria-labelledby')).toBe(tabs[0].attributes('id'));
        expect(panel.attributes('tabindex')).toBe('0');
    });

    it('marks a disabled tab aria-disabled and leaves the others without it', () => {
        const config = TB.tabs()
            .vertical()
            .addTabs(TB.item().key('general'), TB.item().key('security').disabled(true))
            .build();
        const wrapper = mountTabs(config);

        const tabs = wrapper.findAll('[role="tab"]');
        expect(tabs[1].attributes('aria-disabled')).toBe('true');
        expect(tabs[0].attributes('aria-disabled')).toBeUndefined();
    });

    it('a tab key containing whitespace still yields one valid id per reference', () => {
        const config = TB.tabs()
            .vertical()
            .addTabs(TB.item().key('account settings'), TB.item().key('security'))
            .build();
        const wrapper = mountTabs(config);

        const tab = wrapper.findAll('[role="tab"]')[0];
        const panel = wrapper.get('[role="tabpanel"]');

        // Whitespace inside an id makes aria-controls/aria-labelledby read as TWO
        // id references, neither of which exists.
        expect(tab.attributes('id')).not.toMatch(/\s/);
        expect(panel.attributes('id')).not.toMatch(/\s/);
        expect(tab.attributes('aria-controls')).toBe(panel.attributes('id'));
        expect(panel.attributes('aria-labelledby')).toBe(tab.attributes('id'));
    });
});

// ── vertical keyboard navigation ─────────────────────────────────────────────

describe('SkTabs — vertical keyboard navigation', () => {
    it('ArrowDown/ArrowUp move focus with wrap-around and select nothing', async () => {
        const config = TB.tabs()
            .vertical()
            .addTabs(TB.item().key('general'), TB.item().key('security'), TB.item().key('sessions'))
            .build();
        const wrapper = mountTabs(config, { attachTo: document.body });

        const buttons = wrapper.findAll('.sk-vtab');
        const nav = wrapper.get('.sk-vtab-nav');
        (buttons[0].element as HTMLButtonElement).focus();

        await nav.trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement).toBe(buttons[1].element);

        await nav.trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement).toBe(buttons[2].element);

        await nav.trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement).toBe(buttons[0].element);

        await nav.trigger('keydown', { key: 'ArrowUp' });
        expect(document.activeElement).toBe(buttons[2].element);

        // Manual activation: focus moved, the tab did not.
        expect(wrapper.vm.activeTab).toBe('general');
        expect(routerVisit).not.toHaveBeenCalled();
    });

    it('Home/End reach the first and last enabled tab, and disabled tabs are skipped', async () => {
        const config = TB.tabs()
            .vertical()
            .addTabs(
                TB.item().key('general'),
                TB.item().key('security').disabled(true),
                TB.item().key('sessions'),
            )
            .build();
        const wrapper = mountTabs(config, { attachTo: document.body });

        const buttons = wrapper.findAll('.sk-vtab');
        const nav = wrapper.get('.sk-vtab-nav');
        (buttons[0].element as HTMLButtonElement).focus();

        await nav.trigger('keydown', { key: 'End' });
        expect(document.activeElement).toBe(buttons[2].element);

        await nav.trigger('keydown', { key: 'Home' });
        expect(document.activeElement).toBe(buttons[0].element);

        await nav.trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement).toBe(buttons[2].element);

        expect(routerVisit).not.toHaveBeenCalled();
    });

    it('preventDefault is called for the handled keys only', () => {
        const config = TB.tabs().vertical().addTabs(TB.item().key('general'), TB.item().key('security')).build();
        const wrapper = mountTabs(config, { attachTo: document.body });
        const nav = wrapper.get('.sk-vtab-nav').element;

        const handled = new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true });
        nav.dispatchEvent(handled);
        expect(handled.defaultPrevented).toBe(true);

        const untouched = new KeyboardEvent('keydown', { key: 'a', bubbles: true, cancelable: true });
        nav.dispatchEvent(untouched);
        expect(untouched.defaultPrevented).toBe(false);
    });

    it('the roving tabindex follows the focused tab and stays there once it is selected', async () => {
        const config = TB.tabs()
            .vertical()
            .syncUrl(false)
            .addTabs(TB.item().key('general'), TB.item().key('security'), TB.item().key('sessions'))
            .build();
        const wrapper = mountTabs(config, { attachTo: document.body });

        const buttons = wrapper.findAll('.sk-vtab');
        const nav = wrapper.get('.sk-vtab-nav');
        (buttons[0].element as HTMLButtonElement).focus();

        await nav.trigger('keydown', { key: 'ArrowDown' });

        // Tabbing out and back has to return to where the focus actually is, not
        // to the tab that happens to be selected.
        expect(buttons[1].attributes('tabindex')).toBe('0');
        expect(buttons[0].attributes('tabindex')).toBe('-1');

        await buttons[1].trigger('click');

        expect(wrapper.vm.activeTab).toBe('security');
        expect(buttons[1].attributes('tabindex')).toBe('0');
        expect(buttons[0].attributes('tabindex')).toBe('-1');
    });

    it('a selection change hands the roving tabindex back to the selected tab', async () => {
        const config = TB.tabs()
            .vertical()
            .addTabs(TB.item().key('general'), TB.item().key('security'), TB.item().key('sessions'))
            .build();
        const wrapper = mountTabs(config, { attachTo: document.body });

        const buttons = wrapper.findAll('.sk-vtab');
        const nav = wrapper.get('.sk-vtab-nav');
        (buttons[0].element as HTMLButtonElement).focus();

        await nav.trigger('keydown', { key: 'ArrowDown' });
        expect(buttons[1].attributes('tabindex')).toBe('0');

        page.url = '/settings?tab=sessions';
        await nextTick();

        expect(wrapper.vm.activeTab).toBe('sessions');
        expect(buttons[2].attributes('tabindex')).toBe('0');
        expect(buttons[1].attributes('tabindex')).toBe('-1');
    });

    it('a focused tab that disappears returns the roving tabindex to the active tab', async () => {
        const show = ref(true);
        const config = TB.tabs()
            .vertical()
            .syncUrl(false)
            .addTabs(TB.item().key('general'), TB.item().key('security').visible(() => show.value))
            .build();
        const wrapper = mountTabs(config, { attachTo: document.body });

        const nav = wrapper.get('.sk-vtab-nav');
        (wrapper.findAll('.sk-vtab')[0].element as HTMLButtonElement).focus();

        await nav.trigger('keydown', { key: 'ArrowDown' });
        expect(wrapper.findAll('.sk-vtab')[1].attributes('tabindex')).toBe('0');

        show.value = false;
        await nextTick();

        const remaining = wrapper.findAll('.sk-vtab');
        expect(remaining).toHaveLength(1);
        expect(remaining[0].attributes('tabindex')).toBe('0');
    });

    it('a focused tab that disappears and comes back does not reclaim the tab stop it no longer holds', async () => {
        const show = ref(true);
        const config = TB.tabs()
            .vertical()
            .syncUrl(false)
            .addTabs(TB.item().key('general'), TB.item().key('security').visible(() => show.value))
            .build();
        const wrapper = mountTabs(config, { attachTo: document.body });

        const nav = wrapper.get('.sk-vtab-nav');
        (wrapper.findAll('.sk-vtab')[0].element as HTMLButtonElement).focus();

        await nav.trigger('keydown', { key: 'ArrowDown' });
        expect(wrapper.findAll('.sk-vtab')[1].attributes('tabindex')).toBe('0');

        // Hiding the focused tab drops the browser focus without a blur the
        // component could observe; when the tab returns nothing focuses it any
        // more, so the stop must stay with the active tab.
        show.value = false;
        await nextTick();
        show.value = true;
        await nextTick();

        const buttons = wrapper.findAll('.sk-vtab');
        expect(buttons).toHaveLength(2);
        expect(wrapper.vm.activeTab).toBe('general');
        expect(buttons[0].attributes('tabindex')).toBe('0');
        expect(buttons[1].attributes('tabindex')).toBe('-1');
    });
});
