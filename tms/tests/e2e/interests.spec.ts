import { expect, test, type APIRequestContext, type BrowserContext } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

const TRAINING = 'E2E Accords mets et vins';
const ENTREPRISE = 'E2E Brasserie des Halles';

// 1×1 PNG (a real, decodable image: the server checks the content, not only the name).
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64');

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

test('brand of the organisme in the sidebar, then trainings of interest on a fiche, a filter and a new opportunity', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({
        storageState: ADMIN_STATE,
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris',
        viewport: { width: 1400, height: 1000 },
    });
    const page = await context.newPage();

    // 1. The sidebar and the tab title carry the name of the default company (« Par défaut » in « Sociétés »).
    await page.goto('/admin/companies');
    const defaultRow = page.getByRole('row').filter({ hasText: 'Par défaut' });
    const companyName = (await defaultRow.getByRole('cell').first().innerText()).replace('Par défaut', '').trim();
    expect(companyName).not.toBe('');
    await page.goto('/dashboard');
    await expect(page.getByTestId('brand-name').first()).toHaveText(companyName);
    await expect(page).toHaveTitle(new RegExp(`${companyName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`));
    await expect(page.getByTestId('brand-logo')).toHaveCount(0);

    // With a logo (Administration > Sociétés > Devis), the sidebar shows it, served by an authenticated route.
    await page.goto('/admin/companies');
    await defaultRow.getByRole('link', { name: 'Modifier' }).click();
    await page.locator('#logo').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: PNG });
    await expect(page.getByAltText('Logo actuel')).toBeVisible();
    await page.goto('/dashboard');
    const logo = page.getByTestId('brand-logo').first();
    await expect(logo).toBeVisible();
    await expect.poll(() => logo.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth)).toBe(1);

    // 2. A training of the catalogue, and an entreprise interested in it.
    await page.goto('/catalogue/create');
    await page.getByLabel('Intitulé *').fill(TRAINING);
    await page.getByLabel('DAS *').click();
    await page.getByRole('option').first().click();
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Formation créée.')).toBeVisible();

    const organisationUrl = await send(context, page.request, '/crm/organisations', { name: ENTREPRISE });
    await page.goto(organisationUrl);
    const block = page.getByTestId('interest-trainings');
    await expect(block.getByText("Aucune formation d'intérêt.")).toBeVisible();
    await block.getByRole('button', { name: 'Modifier' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Rechercher une formation').fill('mets et vins');
    await dialog.getByLabel(TRAINING).check();
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByText("Formations d'intérêt enregistrées.")).toBeVisible();
    await expect(block.getByText(TRAINING)).toBeVisible();

    // 3. The list filter « Formation d'intérêt ».
    await page.goto('/crm/organisations');
    await page.getByLabel("Formation d'intérêt").click();
    await page.getByRole('option', { name: TRAINING }).click();
    await expect(page).toHaveURL(/training=/);
    await expect(page.getByRole('link', { name: ENTREPRISE }).first()).toBeVisible();

    // 4. A new opportunity from the fiche proposes the training (editable).
    await page.goto(organisationUrl);
    await page.getByRole('tab', { name: 'Opportunités' }).click();
    await page.getByRole('link', { name: 'Nouvelle opportunité' }).click();
    await expect(page.getByRole('heading', { name: 'Nouvelle opportunité' })).toBeVisible();
    await expect(page.getByRole('button', { name: new RegExp(`Retirer ${TRAINING}`) })).toBeVisible();

    await context.close();
});
