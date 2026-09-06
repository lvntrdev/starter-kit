import { test, expect } from '@playwright/test';

/**
 * Critical-path admin smoke test.
 *
 * Scope: login → create user → assign a non-admin role → change one
 * reversible settings field → logout. This is deliberately narrow — see
 * plan-docs/2026-09-06-playwright-e2e-smoke.md for what is explicitly out of
 * scope (full page coverage, cross-browser, visual regression).
 *
 * All steps share a single authenticated browser context/page (one `test()`,
 * sequential `test.step()` blocks) rather than being split into independent
 * tests, so a later step can rely on state a prior step created (the user
 * created in step 2 is the one edited in step 3).
 *
 * Credentials match scripts/e2e/fixtures/E2eAdminSeeder.php's defaults,
 * overridable via the same env vars the seeder reads.
 */
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.test';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-test-password-only';

// Strong enough to satisfy Password::defaults() (min 10, mixed case, numbers, symbols).
const NEW_USER_PASSWORD = 'E2eSmoke!Test987';

test('login, create user, assign role, update settings, logout', async ({ page }) => {
    const unique = Date.now();
    const newUserEmail = `e2e-smoke-user-${unique}@example.test`;
    const newUserFirstName = 'Smoke';
    const newUserLastName = `User${unique}`;

    await test.step('log in with the seeded admin', async () => {
        await page.goto('/login');

        // Email uses PrimeVue InputText, whose root element IS the <input>,
        // so the `id`/`for` pairing resolves cleanly for getByLabel.
        await page.getByLabel('Email Address').fill(ADMIN_EMAIL);

        // Password uses PrimeVue's <Password> component. Any prop not declared
        // by BasePassword (id, autocomplete, class, ...) falls through to the
        // component's *wrapper* div, not the inner <input> — Login.vue sets
        // `id="password"` and `autocomplete="current-password"` directly on
        // <Password>, so neither reaches the real input. `for="password"` on
        // the <label> therefore doesn't resolve to a focusable control, and
        // an autocomplete-attribute selector matches nothing either. `type`
        // is the one attribute PrimeVue binds directly onto the inner
        // <input> (`:type="inputType"`), so select on that instead.
        await page.locator('input[type="password"]').fill(ADMIN_PASSWORD);

        await page.getByRole('button', { name: 'Sign In' }).click();

        await expect(page).not.toHaveURL(/\/login/);
    });

    await test.step('create a uniquely-named user and confirm it lists', async () => {
        await page.goto('/users');

        // Exactly one "Create User" trigger is visible at a time regardless
        // of active theme (datatable toolbar button in `aura`, page-action
        // header button otherwise) — both use the same translated label.
        await page.getByRole('button', { name: 'Create User' }).click();

        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();

        await dialog.getByLabel('First Name').fill(newUserFirstName);
        await dialog.getByLabel('Last Name').fill(newUserLastName);
        await dialog.getByLabel('Email').fill(newUserEmail);

        // Give it the Admin role here so the next step's role change to the
        // non-admin "User" role is a real, observable transition rather than
        // a no-op re-selection of whatever the form defaulted to.
        // `getByLabel('Role')` won't match: PrimeVue's Select sets its own
        // aria-label (mirroring the placeholder/value) on the combobox, which
        // wins over the external `<label for>` per the accessible-name
        // algorithm even though the id/for pairing is correct. Target the
        // real control via FormBuilder's stable `<key>__control` id instead.
        await dialog.locator('#role__control').click();
        await page.getByRole('option', { name: 'Admin', exact: true }).click();

        // Plain <InputText> here (no PrimeVue <Password> wrapper — this form doesn't
        // set `.feedback()`), so the field's stable id IS the field key directly. The
        // <label> also renders a "required" indicator as sibling text, which becomes
        // part of the accessible name ("Password required"), so an exact getByLabel
        // match on "Password" alone doesn't work — select by id instead.
        await dialog.locator('#password').fill(NEW_USER_PASSWORD);
        await dialog.locator('#password_confirmation').fill(NEW_USER_PASSWORD);

        await dialog.getByRole('button', { name: 'Save' }).click();

        await expect(page.getByText('User created successfully.')).toBeVisible();
        await expect(dialog).toBeHidden();

        await expect(page.getByText(newUserEmail)).toBeVisible();
    });

    await test.step('assign that user a non-admin role and confirm it persisted', async () => {
        const row = page.getByRole('row', { name: new RegExp(newUserEmail) });
        await expect(row).toBeVisible();

        await row.getByRole('button', { name: 'Edit' }).click();

        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();

        // See the create-user step above for why `getByLabel('Role')` doesn't work here.
        await dialog.locator('#role__control').click();
        await page.getByRole('option', { name: 'User', exact: true }).click();

        await dialog.getByRole('button', { name: 'Update' }).click();

        await expect(page.getByText('User updated successfully.')).toBeVisible();
        await expect(dialog).toBeHidden();

        const updatedRow = page.getByRole('row', { name: new RegExp(newUserEmail) });
        await expect(updatedRow.getByText('User', { exact: true })).toBeVisible();
    });

    await test.step('change one harmless reversible settings field and confirm the success flash', async () => {
        await page.goto('/settings');

        // "General" is the first tab and active by default; click defensively
        // in case that default ever changes.
        const generalTab = page.getByRole('tab', { name: 'General' });
        if (await generalTab.isVisible().catch(() => false)) {
            await generalTab.click();
        }

        const taglineValue = `E2E smoke run ${unique}`;
        await page.getByLabel('Tagline / Description').fill(taglineValue);

        await page.getByRole('button', { name: 'Update' }).click();

        await expect(page.getByText('General settings updated.')).toBeVisible();
    });

    await test.step('log out and confirm the redirect to /login', async () => {
        // The header user-menu trigger shows the logged-in admin's full name
        // (seeded as first_name "E2E" / last_name "Admin" by E2eAdminSeeder).
        await page.getByRole('button', { name: 'E2E Admin' }).click();
        await page.getByRole('menuitem', { name: 'Logout' }).click();

        await expect(page).toHaveURL(/\/login/);
    });
});
