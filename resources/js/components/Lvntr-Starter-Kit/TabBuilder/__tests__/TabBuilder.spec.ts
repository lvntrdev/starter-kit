import { afterEach, describe, it, expect, vi } from 'vitest';
import { TB } from '../core';

// ── TabBuilder chain — tab item permission gating ─────────────────────────────

describe('TB.item() — permission', () => {
    it('a single permission is stored as a plain string', () => {
        const tab = TB.item().key('security').permission('users.update').build();

        expect(tab.permission).toBe('users.update');
    });

    it('multiple permissions are stored as an array', () => {
        const tab = TB.item().key('security').permission('users.update', 'users.delete').build();

        expect(tab.permission).toEqual(['users.update', 'users.delete']);
    });

    it('is undefined when not set (no gating by default)', () => {
        const tab = TB.item().key('general').build();

        expect(tab.permission).toBeUndefined();
    });

    it('label falls back to the key when not set', () => {
        const tab = TB.item().key('general').build();

        expect(tab.label).toBe('general');
    });

    it('throws without a key', () => {
        expect(() => TB.item().label('No key').build()).toThrow('Tab item must have a key');
    });

    it('throws on a whitespace-only key', () => {
        expect(() => TB.item().key('   ').label('Blank').build()).toThrow('Tab item must have a key');
    });
});

describe('TB.item() — build() returns an independent copy', () => {
    it('two builds of the same item builder are separate objects', () => {
        const item = TB.item().key('general');

        const first = item.build();
        const second = item.build();

        expect(first).not.toBe(second);
        expect(first).toEqual(second);
    });

    it('mutating one built item leaves the other untouched', () => {
        const item = TB.item().key('general');
        const first = item.build();
        const second = item.build();

        first.label = 'Mutated';

        expect(second.label).toBe('general');
    });

    it('the same item builder used in two tab configs yields independent items', () => {
        const item = TB.item().key('general');
        const one = TB.tabs().addTabs(item).build();
        const two = TB.tabs().addTabs(item).build();

        expect(one.tabs[0]).not.toBe(two.tabs[0]);

        one.tabs[0].label = 'Mutated';

        expect(two.tabs[0].label).toBe('general');
    });

    it('mutating one built item\'s permission array leaves the other untouched', () => {
        const item = TB.item().key('security').permission('users.read', 'users.update');
        const first = item.build();
        const second = item.build();

        (first.permission as string[]).push('users.delete');

        expect(second.permission).toEqual(['users.read', 'users.update']);
    });
});

describe('TB.tabs() — requires at least one tab', () => {
    it('throws when built with no tabs', () => {
        expect(() => TB.tabs().build()).toThrow('TabBuilder must have at least one tab');
    });

    it('addTabs() carries built tab configs (incl. permission) through', () => {
        const cfg = TB.tabs().addTabs(TB.item().key('security').permission('users.update')).build();

        expect(cfg.tabs).toHaveLength(1);
        expect(cfg.tabs[0].permission).toBe('users.update');
    });
});

describe('TB.tabs() — duplicate keys', () => {
    afterEach(() => {
        vi.unstubAllEnvs();
        vi.restoreAllMocks();
    });

    it('throws in development, naming the duplicated key', () => {
        const build = () => TB.tabs().addTabs(TB.item().key('general'), TB.item().key('general')).build();

        expect(build).toThrow('duplicate tab keys');
        expect(build).toThrow('"general"');
    });

    it('reports every duplicated key once', () => {
        const build = () =>
            TB.tabs()
                .addTabs(TB.item().key('a'), TB.item().key('a'), TB.item().key('a'), TB.item().key('b'), TB.item().key('b'))
                .build();

        expect(build).toThrow('"a", "b"');
    });

    it('outside development it logs once and returns the config unchanged (no dedupe)', () => {
        vi.stubEnv('DEV', false);
        const spy = vi.spyOn(console, 'error').mockImplementation(() => {});

        const cfg = TB.tabs().addTabs(TB.item().key('general'), TB.item().key('general')).build();

        expect(spy).toHaveBeenCalledTimes(1);
        expect(spy.mock.calls[0][0]).toContain('"general"');
        expect(cfg.tabs).toHaveLength(2);
        expect(cfg.tabs.map((t) => t.key)).toEqual(['general', 'general']);
    });

    it('a clean config never logs', () => {
        vi.stubEnv('DEV', false);
        const spy = vi.spyOn(console, 'error').mockImplementation(() => {});

        TB.tabs().addTabs(TB.item().key('a'), TB.item().key('b')).build();

        expect(spy).not.toHaveBeenCalled();
    });
});

describe('TB.tabs() — build() snapshots the config', () => {
    it('a later addTabs() does not reach an earlier build', () => {
        const builder = TB.tabs().addTabs(TB.item().key('a'));

        const first = builder.build();
        builder.addTabs(TB.item().key('b'));
        const second = builder.build();

        expect(first.tabs).toHaveLength(1);
        expect(second.tabs).toHaveLength(2);
        expect(first.tabs).not.toBe(second.tabs);
    });

    it('mutating a built config does not reach a later build', () => {
        const builder = TB.tabs().addTabs(TB.item().key('a'));

        const first = builder.build();
        first.tabs[0].label = 'Mutated';
        first.tabs.push({ key: 'injected', label: 'Injected' });
        first.layout = 'vertical';

        const second = builder.build();

        expect(second.tabs).toHaveLength(1);
        expect(second.tabs[0].label).toBe('a');
        expect(second.layout).toBe('horizontal');
    });

    it('mutating a built permission array does not re-gate a later build', () => {
        const builder = TB.tabs().addTabs(TB.item().key('a').permission('users.read', 'users.update'));

        const first = builder.build();
        (first.tabs[0].permission as string[]).push('users.delete');

        const second = builder.build();

        expect(second.tabs[0].permission).toEqual(['users.read', 'users.update']);
    });

    it('mutating a built role array does not reach the sibling build', () => {
        const builder = TB.tabs().addTabs(TB.item().key('a').role('admin', 'editor'));

        const first = builder.build();
        const second = builder.build();

        (second.tabs[0].role as string[]).push('viewer');

        expect(first.tabs[0].role).toEqual(['admin', 'editor']);
    });
});

describe('TB.tabs() — panel / history / url options', () => {
    const oneTab = () => TB.item().key('a');

    it('lazy() sets panels to "active" and lazy(false) clears it', () => {
        expect(TB.tabs().addTabs(oneTab()).lazy().build().panels).toBe('active');

        const cleared = TB.tabs().addTabs(oneTab()).lazy().lazy(false).build();

        expect(cleared.panels).toBeUndefined();
        expect('panels' in cleared).toBe(false);
    });

    it('keepAlive() sets panels to "all" and keepAlive(false) clears it', () => {
        expect(TB.tabs().addTabs(oneTab()).keepAlive().build().panels).toBe('all');

        const cleared = TB.tabs().addTabs(oneTab()).keepAlive().keepAlive(false).build();

        expect(cleared.panels).toBeUndefined();
        expect('panels' in cleared).toBe(false);
    });

    it('the last panel setting wins', () => {
        expect(TB.tabs().addTabs(oneTab()).lazy().keepAlive().build().panels).toBe('all');
        expect(TB.tabs().addTabs(oneTab()).keepAlive().lazy().build().panels).toBe('active');
    });

    it('history(), urlMode() and syncUrl() store their values', () => {
        const cfg = TB.tabs().addTabs(oneTab()).history('push').urlMode('client').syncUrl(false).build();

        expect(cfg.history).toBe('push');
        expect(cfg.urlMode).toBe('client');
        expect(cfg.syncUrl).toBe(false);
    });

    it('all four are undefined by default (today\'s behaviour untouched)', () => {
        const cfg = TB.tabs().addTabs(oneTab()).build();

        expect(cfg.panels).toBeUndefined();
        expect(cfg.history).toBeUndefined();
        expect(cfg.urlMode).toBeUndefined();
        expect(cfg.syncUrl).toBeUndefined();
    });
});
