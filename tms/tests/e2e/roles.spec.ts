import { expect, test } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

test('roles and rights: grant a right to a role, highlighted as changed, then back to the defaults', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({ storageState: ADMIN_STATE, locale: 'fr-FR', timezoneId: 'Europe/Paris', viewport: { width: 1400, height: 1000 } });
    const page = await context.newPage();

    await page.goto('/dashboard');
    await page.getByRole('link', { name: 'Rôles et droits', exact: true }).first().click();
    await expect(page).toHaveURL(/\/admin\/roles$/);

    // The administrator's column and the locked rights cannot be changed.
    await expect(page.getByLabel("Exporter vers Excel — Administrateur de l'organisme")).toBeDisabled();
    await expect(page.getByLabel('Gérer les utilisateurs — Direction')).toBeDisabled();

    const cell = page.getByLabel('Exporter vers Excel — Assistant');
    await expect(cell).not.toBeChecked();
    await cell.click();
    await expect(page.getByText('Droit « Exporter vers Excel » accordé au rôle Assistant.')).toBeVisible();
    await expect(cell).toBeChecked();
    await expect(page.locator('td[data-changed="true"]')).toHaveCount(1);

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('columnheader', { name: /^Assistant/ }).getByRole('button', { name: 'Rétablir les droits par défaut' }).click();
    await expect(page.getByText('Droits par défaut rétablis pour le rôle Assistant.')).toBeVisible();
    await expect(cell).not.toBeChecked();
    await expect(page.locator('td[data-changed="true"]')).toHaveCount(0);

    await context.close();
});
