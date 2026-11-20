import { expect, test } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

test('mail templates: insert a variable, preview, save, then back to the default', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({ storageState: ADMIN_STATE, locale: 'fr-FR', timezoneId: 'Europe/Paris', viewport: { width: 1400, height: 1000 } });
    const page = await context.newPage();

    await page.goto('/dashboard');
    await page.getByRole('link', { name: "Modèles d'e-mails", exact: true }).first().click();
    await expect(page.getByText('Modèle système').first()).toBeVisible();
    await page.getByRole('link', { name: "Envoi d'un devis" }).click();

    const subject = page.getByLabel('Objet');
    await expect(subject).toHaveValue('Devis {numero_devis} - {societe}');
    await subject.fill('Votre proposition ');
    await page.getByRole('button', { name: /\{numero_devis\}/ }).click();
    await expect(subject).toHaveValue('Votre proposition {numero_devis}');

    await page.getByRole('button', { name: 'Aperçu' }).click();
    await expect(page.getByLabel('Aperçu').getByText('Votre proposition DEV-2026-0042')).toBeVisible();

    await subject.fill('Devis {numero}');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Variable inconnue : {numero}.')).toBeVisible();

    await subject.fill('Votre proposition {numero_devis}');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Modèle enregistré.')).toBeVisible();
    await expect(page.getByText('Personnalisé', { exact: true })).toBeVisible();

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Rétablir le modèle par défaut' }).click();
    await expect(page.getByText('Modèle par défaut rétabli.')).toBeVisible();
    await expect(subject).toHaveValue('Devis {numero_devis} - {societe}');

    await context.close();
});
