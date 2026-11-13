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

/** A valid SIRET (Luhn) for a 13-digit prefix: the e2e data never collides with the import test's. */
function siretFor(prefix: string): string {
    for (let check = 0; check <= 9; check++) {
        const digits = `${prefix}${check}`;
        let sum = 0;
        for (let i = 0; i < 14; i++) {
            let d = Number(digits[13 - i]);
            if (i % 2 === 1) {
                d *= 2;
                if (d > 9) d -= 9;
            }
            sum += d;
        }
        if (sum % 10 === 0) return digits;
    }
    throw new Error('unreachable');
}

const SIRET = siretFor('9988776655443');
const EMAIL = 'contact@lumiere-e2e.test';

test('fiche journey: create, interlocuteur, note, duplicate warning, merge, search, export', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({ storageState: ADMIN_STATE, locale: 'fr-FR', timezoneId: 'Europe/Paris', acceptDownloads: true });
    const page = await context.newPage();

    // Dashboard shortcuts.
    await page.goto('/dashboard');
    await expect(page.getByRole('link', { name: 'Nouvelle entreprise' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Nouveau particulier' })).toBeVisible();
    await expect(page.getByRole('button', { name: /^Rechercher( Ctrl K)?$/ })).toBeVisible();
    await page.getByRole('link', { name: 'Nouvelle entreprise' }).click();

    // 1. Create an entreprise. The directory (Sirene) is not configured on this stack: the failure is told, the form stays usable.
    await expect(page.getByRole('heading', { name: 'Nouvelle entreprise' })).toBeVisible();
    await page.getByLabel('Raison sociale *').fill('E2E Café Lumière');
    await page.getByLabel('SIRET', { exact: true }).fill(SIRET);
    await page.getByRole('button', { name: 'Compléter depuis le SIRET' }).click();
    await expect(page.locator('[aria-live="polite"] p')).toBeVisible();
    await page.getByLabel('E-mail', { exact: true }).fill(EMAIL);
    await page.getByLabel('Ville', { exact: true }).fill('Annecy');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Entreprise créée.')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'E2E Café Lumière' }).first()).toBeVisible();
    const keptUrl = page.url();

    // 2. Add an interlocuteur (new person).
    await page.getByRole('tab', { name: 'Interlocuteurs' }).click();
    await page.getByRole('button', { name: 'Ajouter un interlocuteur' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Prénom').fill('Zoé');
    await dialog.getByLabel('Nom *').fill('Martin E2E');
    await dialog.getByLabel('Fonction').fill('Gérante');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Martin E2E').first()).toBeVisible();

    // 3. Add a note: it appears in the Historique.
    await page.getByRole('tab', { name: 'Historique' }).click();
    await page.getByLabel('Ajouter une note').fill('Appel de présentation, rappeler en janvier.');
    await page.getByRole('button', { name: 'Ajouter la note' }).click();
    await expect(page.getByRole('tabpanel', { name: 'Historique' }).getByText('Appel de présentation, rappeler en janvier.')).toBeVisible();

    // 4. A near-duplicate (same e-mail): warning, then « Créer puis fusionner ».
    await page.goto('/crm/organisations/create');
    await page.getByLabel('Raison sociale *').fill('E2E Cafe Lumiere SARL');
    await page.getByLabel('E-mail', { exact: true }).fill(EMAIL);
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    const warning = page.getByRole('alert').filter({ hasText: 'Fiches similaires' });
    await expect(warning).toBeVisible();
    await expect(warning).toContainText('E2E Café Lumière');
    await warning.getByRole('button', { name: 'Créer puis fusionner' }).click();

    // 5. Merge screen: the kept fiche is the existing one; the merge is final, after a confirmation.
    await expect(page.getByRole('heading', { name: 'Fusionner deux fiches' })).toBeVisible();
    await page.getByRole('button', { name: 'Fusionner', exact: true }).click();
    await expect(page.getByRole('dialog')).toContainText('Cette fusion est définitive');
    await page.getByRole('button', { name: 'Fusionner définitivement' }).click();
    await expect(page).toHaveURL(keptUrl);
    await page.getByRole('tab', { name: 'Interlocuteurs' }).click();
    await expect(page.getByText('Martin E2E').first()).toBeVisible();

    // 6. Global search (button of the dashboard, accent-insensitive).
    await page.goto('/dashboard');
    await expect(page.getByRole('link', { name: /E2E Café Lumière/ })).toBeVisible(); // « Dernières fiches consultées »
    await page.getByRole('button', { name: /^Rechercher( Ctrl K)?$/ }).click();
    await page.getByPlaceholder('Entreprise, personne, SIRET, e-mail, téléphone…').fill('lumiere');
    await expect(page.getByRole('dialog').getByText('E2E Café Lumière')).toBeVisible();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(keptUrl);

    // 7. Export: the pre-flight allows it, the download is an .xlsx.
    await page.goto('/crm/organisations?q=Lumi%C3%A8re');
    await expect(page.getByRole('link', { name: 'E2E Café Lumière' }).first()).toBeVisible();
    const check = await page.request.get('/crm/organisations/export/check?q=Lumi%C3%A8re', { headers: { Accept: 'application/json' } });
    expect((await check.json()).allowed).toBe(true);
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Exporter (.xlsx)' }).click()]);
    expect(download.suggestedFilename()).toMatch(/\.xlsx$/);

    await context.close();
});

test('phone width: the fiche is readable and the note is reachable', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({
        storageState: ADMIN_STATE,
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris',
        viewport: { width: 375, height: 812 },
        isMobile: true,
        hasTouch: true,
    });
    const page = await context.newPage();

    await page.goto('/crm/organisations?q=Lumi%C3%A8re');
    await page.getByRole('link', { name: 'E2E Café Lumière' }).first().click();
    await expect(page.getByRole('heading', { name: 'E2E Café Lumière' }).first()).toBeVisible();

    // No horizontal scroll: the fiche fits in one column.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);

    await page.getByRole('tab', { name: 'Historique' }).click();
    const note = page.getByLabel('Ajouter une note');
    await note.scrollIntoViewIfNeeded();
    await expect(note).toBeInViewport();
    await note.fill('Note saisie depuis le téléphone.');
    await page.getByRole('button', { name: 'Ajouter la note' }).click();
    await expect(page.getByText('Note saisie depuis le téléphone.')).toBeVisible();

    await context.close();
});
