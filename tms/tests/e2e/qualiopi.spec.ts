import { expect, test } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

test('Qualiopi: the 33 indicators, an indicator kept up to date with a proof, and the audit export', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({ storageState: ADMIN_STATE, locale: 'fr-FR', timezoneId: 'Europe/Paris', acceptDownloads: true });
    const page = await context.newPage();

    // From the menu « Qualité › Qualiopi ».
    await page.goto('/dashboard');
    await page.getByRole('link', { name: 'Qualiopi', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Qualiopi' })).toBeVisible();
    await expect(page.getByText('Critère 7')).toBeVisible();
    await expect(page.getByRole('link', { name: /^33/ })).toBeVisible();

    // Indicator 8: its automatic measure, its state and a proof.
    await page.getByRole('link', { name: /^8\s/ }).click();
    await expect(page.getByRole('heading', { name: /Indicateur 8/ })).toBeVisible();
    await expect(page.getByText('Candidatures avec test de positionnement reçu')).toBeVisible();
    await page.getByLabel('Statut').selectOption({ label: 'En cours' });
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Indicateur 8 enregistré.')).toBeVisible();

    await page.getByLabel('Type').selectOption({ label: 'Note' });
    await page.getByLabel('Titre').fill('Procédure de positionnement');
    await page.getByLabel('Note', { exact: true }).fill('Test obligatoire avant l’entretien, reçu dans le TMS.');
    await page.getByRole('button', { name: 'Ajouter la preuve' }).click();
    await expect(page.getByText('Preuve ajoutée.')).toBeVisible();
    await expect(page.getByText('Test obligatoire avant l’entretien, reçu dans le TMS.')).toBeVisible();

    // The list filters by status; the audit file downloads as a ZIP.
    await page.getByRole('link', { name: /Retour aux 33 indicateurs/ }).click();
    await page.getByRole('button', { name: 'En cours', exact: true }).click();
    await expect(page.getByRole('link', { name: /^8\s/ })).toBeVisible();
    await expect(page.getByRole('link', { name: /^12\s/ })).toHaveCount(0);

    const download = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Exporter le dossier d’audit' }).click();
    expect((await download).suggestedFilename()).toMatch(/^dossier-qualiopi-\d{4}-\d{2}-\d{2}\.zip$/);

    await context.close();
});
