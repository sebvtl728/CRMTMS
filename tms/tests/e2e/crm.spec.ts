import { expect, test } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

const CSV = ['Raison sociale;SIRET;Ville', 'E2E Hôtel du Lac;73282932000074;Annecy', 'E2E Boulangerie Zoé;44234567800019;Lyon', 'E2E Café des Arts;;Paris', ''].join('\r\n');

test('import a 3-row csv: wizard, fiche in the list, undo', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({ storageState: ADMIN_STATE, locale: 'fr-FR', timezoneId: 'Europe/Paris' });
    const page = await context.newPage();

    // The « Importer » link of the list opens the wizard.
    await page.goto('/crm/organisations');
    await page.getByRole('link', { name: 'Importer' }).click();
    await expect(page.getByRole('heading', { name: 'Déposer le fichier' })).toBeVisible();

    // 1. File + target.
    await page.getByLabel(/^Entreprises/).check();
    await page.locator('#file').setInputFiles({ name: 'clients-e2e.csv', mimeType: 'text/csv', buffer: Buffer.from(CSV, 'utf8') });
    await page.getByRole('button', { name: 'Continuer : associer les colonnes' }).click();

    // 2. Columns: guessed from the headers, required field satisfied.
    await expect(page.getByRole('heading', { name: 'Associer les colonnes' })).toBeVisible();
    await expect(page.getByLabel('Raison sociale', { exact: true })).toHaveValue('name');
    await expect(page.getByLabel('SIRET', { exact: true })).toHaveValue('siret');
    await expect(page.getByLabel('Ville', { exact: true })).toHaveValue('city');
    await page.getByRole('button', { name: 'Continuer : analyser le fichier' }).click();

    // 4. Simulation (nothing unknown, so no values step): three fiches to create, nothing written yet.
    await expect(page.getByRole('heading', { name: 'Vérifier la simulation' })).toBeVisible();
    await expect(page.getByLabel('Résultat de la simulation').getByText('Fiches créées').locator('xpath=preceding-sibling::p')).toHaveText('3');
    await page.goto('/crm/organisations');
    await expect(page.getByText('E2E Hôtel du Lac')).toHaveCount(0);

    // 5. Run (the e2e queue is synchronous): straight to the report.
    await page.goto('/crm/import');
    await page.getByRole('link', { name: 'clients-e2e.csv' }).click();
    await page.getByRole('button', { name: "Lancer l'import" }).click();
    await page.getByRole('dialog').getByRole('button', { name: "Lancer l'import" }).click();

    // 6. Report.
    await expect(page.getByRole('heading', { name: "Rapport d'import" })).toBeVisible();
    await expect(page.getByText('Terminé')).toBeVisible();
    await expect(page.getByLabel('Résultat').getByText('Fiches créées').locator('xpath=preceding-sibling::p')).toHaveText('3');
    await expect(page.getByRole('link', { name: 'Télécharger le rapport (Excel)' })).toBeVisible();

    // The fiches are in the list.
    await page.goto('/crm/organisations?q=E2E');
    await expect(page.getByRole('link', { name: 'E2E Hôtel du Lac' }).first()).toBeVisible();

    // History: undo with a confirmation that says how many fiches go.
    await page.goto('/crm/import');
    const row = page.getByRole('row', { name: /clients-e2e\.csv/ });
    await expect(row).toContainText('Terminé');
    await row.getByRole('button', { name: "Annuler l'import" }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText('3 fiches seront supprimées');
    await expect(dialog).toContainText('modifiées depuis l\'import sont conservées');
    await dialog.getByRole('button', { name: "Annuler l'import" }).click();

    await expect(page.getByRole('heading', { name: "Rapport d'import" })).toBeVisible();
    await expect(page.getByText('Import annulé')).toBeVisible();

    await page.goto('/crm/organisations?q=E2E');
    await expect(page.getByText('E2E Hôtel du Lac')).toHaveCount(0);

    // Phone width: the wizard asks for a computer, the history stays readable.
    await page.setViewportSize({ width: 375, height: 800 });
    await page.goto('/crm/import/new');
    await expect(page.getByText("L'import se fait depuis un ordinateur.")).toBeVisible();
    await page.goto('/crm/import');
    await expect(page.locator('li').getByText('clients-e2e.csv')).toBeVisible(); // cards, not the table

    await context.close();
});
