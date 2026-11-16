import { expect, test, type APIRequestContext, type BrowserContext, type Locator, type Page } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

const TRAINING = 'E2E Sauveteur secouriste du travail';
const CLIENT = 'E2E Menuiserie des Coteaux';
const FUNDER = 'E2E OPCO Construction';

/** A form post as the signed-in user (CSRF from the XSRF-TOKEN cookie); returns the final URL. */
async function send(context: BrowserContext, request: APIRequestContext, url: string, data: Record<string, unknown>): Promise<string> {
    const xsrf = (await context.cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
    const response = await request.post(url, {
        data,
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
    });
    expect(response.ok(), `${url}: ${response.status()}`).toBe(true);

    return response.url();
}

/** Picks an option of a shadcn (Radix) select. */
async function choose(page: Page, trigger: Locator, option: string | RegExp): Promise<void> {
    await trigger.click();
    await page.getByRole('option', { name: option, exact: typeof option === 'string' }).click();
}

test('fundings: dossier with an entreprise picked in the search, funding by an entreprise picked with Enter, remaining amount', async ({
    browser,
}) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({
        storageState: ADMIN_STATE,
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris',
        viewport: { width: 1400, height: 1000 },
    });
    const page = await context.newPage();

    // A training of 1 800 € per trainee for an entreprise; the client and the funder in the CRM.
    await page.goto('/catalogue');
    await page.getByRole('link', { name: 'Nouvelle formation' }).click();
    await page.getByLabel('Intitulé *').fill(TRAINING);
    await page.getByLabel('DAS *').click();
    await page.getByRole('option').first().click();
    await page.getByLabel('Tarif entreprise HT par stagiaire (€)').fill('1 800,00');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Formation créée.')).toBeVisible();
    await send(context, page.request, '/crm/organisations', { name: CLIENT });
    await send(context, page.request, '/crm/organisations', { name: FUNDER });

    // The dossier: contractant picked in the search, one training line (1 × 1 800 €).
    await page.goto('/dossiers/create');
    await page.getByLabel('Entreprise *').fill('Menuiserie des Co');
    await page.getByRole('button', { name: CLIENT }).click();
    await expect(page.getByText(CLIENT)).toBeVisible();
    await choose(page, page.getByLabel('Formation du catalogue *'), new RegExp(TRAINING));
    await expect(page.getByLabel('Prix unitaire HT (€) *')).not.toHaveValue('');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText(/Dossier .* créé\./)).toBeVisible();
    await expect(page.getByText(/Reste à financer : 1\s800,00\s€/)).toBeVisible();

    // A funding by an entreprise, 900,00 €. The name typed in the search is not yet a choice:
    // « Enregistrer » sends nothing and says so; Enter picks the result of that name (it no longer
    // submits the dialog with no funder, the « Choisissez l'entreprise ou la personne qui finance. » bug).
    let posted = 0;
    page.on('request', (r) => {
        if (r.method() === 'POST' && r.url().includes('/fundings')) posted++;
    });
    await page.getByRole('button', { name: 'Ajouter un financement' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Financeur *').fill(FUNDER);
    await expect(dialog.getByRole('button', { name: FUNDER })).toBeVisible();
    await expect(dialog.getByText('Cliquez sur un résultat pour le choisir.')).toBeVisible();
    await choose(page, dialog.getByLabel('Type de financement *'), 'OPCO');
    await dialog.getByLabel('Montant HT (€) *').fill('900,00');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(dialog.getByText('Choisissez le financeur : cliquez sur un résultat de la recherche.')).toBeVisible();
    await dialog.getByLabel('Financeur *').press('Enter');
    await expect(dialog.getByRole('button', { name: 'Changer' })).toBeVisible();
    await expect(dialog.getByText(FUNDER)).toBeVisible();
    expect(posted).toBe(0);
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(page.getByText('Financement ajouté.')).toBeVisible();
    await expect(dialog).toBeHidden();
    const fundings = page.getByRole('region', { name: 'Financements' });
    await expect(fundings.getByRole('link', { name: FUNDER })).toBeVisible();
    await expect(fundings.getByText(/Reste à financer : 900,00\s€/)).toBeVisible();

    await context.close();
});
