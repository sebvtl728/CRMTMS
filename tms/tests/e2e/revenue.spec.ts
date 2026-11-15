import { expect, test, type APIRequestContext, type BrowserContext, type Locator, type Page } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved and the
// issuing company « CAP FORMATION E2E ». Everything is created in the e2e database only.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

const DAS = 'Hôtellerie-restauration'; // not the first of the list: quotes.spec.ts signs 2 400 € in that one
const ENTREPRISE = 'E2E Atelier du Chiffre';
const OPPORTUNITY = 'E2E Audit des supports';
const RECIPIENT = 'achats@atelier-du-chiffre.test';

async function send(context: BrowserContext, request: APIRequestContext, url: string, data: Record<string, unknown>): Promise<string> {
    const xsrf = (await context.cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
    const response = await request.post(url, {
        data,
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
    });
    expect(response.ok(), `${url}: ${response.status()}`).toBe(true);

    return response.url();
}

async function choose(page: Page, trigger: Locator, option: string | RegExp): Promise<void> {
    await trigger.click();
    await page.getByRole('option', { name: option, exact: typeof option === 'string' }).click();
}

test('revenue: a target of 10 000 € and an accepted quote of 2 500 € HT make 25 % of the DAS target', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({ storageState: ADMIN_STATE, locale: 'fr-FR', timezoneId: 'Europe/Paris', viewport: { width: 1400, height: 1000 } });
    const page = await context.newPage();

    // 1. The direction sets the target of the DAS for the current month.
    await page.goto('/crm/revenue');
    await expect(page.getByRole('heading', { name: "Chiffre d'affaires" })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Mois' }).or(page.getByRole('link', { name: 'Mois' }))).toBeVisible();
    await page.getByRole('button', { name: 'Objectifs' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel(DAS, { exact: true }).fill('10 000');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(dialog).toBeHidden();
    const row = page.getByRole('row').filter({ has: page.getByRole('button', { name: DAS, exact: true }) });
    await expect(row).toContainText(/10\s000\s€/);

    // 2. An opportunity of that DAS and a quote of 2 500 € HT, sent then accepted.
    const organisationUrl = await send(context, page.request, '/crm/organisations', { name: ENTREPRISE });
    await page.goto(organisationUrl);
    await page.getByRole('tab', { name: 'Opportunités' }).click();
    await page.getByRole('link', { name: 'Nouvelle opportunité' }).click();
    await page.getByLabel('Objet *').fill(OPPORTUNITY);
    await choose(page, page.getByLabel('DAS *'), DAS);
    await choose(page, page.getByLabel('Étape *'), 'Nouveau');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Opportunité créée.')).toBeVisible();

    await page.getByRole('link', { name: 'Nouveau devis' }).click();
    await choose(page, page.getByLabel('Société émettrice *'), 'CAP FORMATION E2E');
    await page.getByRole('button', { name: 'Ajouter une ligne' }).click();
    const line = page.getByRole('group', { name: 'Ligne 1' });
    await line.getByLabel('Libellé *').fill('Audit des supports');
    await line.getByLabel('Quantité *').fill('1');
    await line.getByLabel('Prix unitaire HT (€) *').fill('2 500,00');
    await page.getByRole('button', { name: 'Enregistrer le brouillon' }).click();
    const heading = page.getByRole('heading', { name: /^Devis CF-\d{4}-\d{4}$/ });
    await expect(heading).toBeVisible();
    const number = ((await heading.textContent()) ?? '').replace('Devis ', '').trim();

    await page.getByRole('button', { name: 'Envoyer', exact: true }).click();
    const sending = page.getByRole('dialog');
    await sending.getByLabel('Destinataire *').fill(RECIPIENT);
    await sending.getByRole('button', { name: 'Envoyer', exact: true }).click();
    await expect(page.getByText(`Devis ${number} envoyé.`)).toBeVisible();

    await page.getByRole('button', { name: 'Accepté', exact: true }).click();
    const answer = page.getByRole('dialog');
    await answer.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText(`Devis ${number} accepté.`)).toBeVisible();
    // The opportunity becomes « Gagné » too: the quote alone counts, never twice.
    await page.getByRole('dialog').getByRole('button', { name: 'Passer en « Gagné »' }).click();
    await expect(page.getByRole('dialog')).toBeHidden();

    // 3. The dashboard of the month: 2 500 € reached, 25 % of the target.
    await page.goto('/crm/revenue');
    const das = page.getByRole('row').filter({ has: page.getByRole('button', { name: DAS, exact: true }) });
    await expect(das).toContainText(/2\s500\s€/);
    await expect(das).toContainText(/10\s000\s€/);
    await expect(das).toContainText(/25\s%/);
    await expect(das.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '25');

    // The detail panel lists the quote with its amount to the cent, linked to its page.
    await page.getByRole('button', { name: DAS, exact: true }).click();
    const panel = page.getByRole('dialog');
    await expect(panel.getByRole('link', { name: new RegExp(number) })).toBeVisible();
    await expect(panel).toContainText(/2\s500,00\s€/);
    await panel.getByRole('link', { name: new RegExp(number) }).click();
    await expect(page.getByRole('heading', { name: `Devis ${number}` })).toBeVisible();

    // Previous month: nothing signed there, the target of November stays in November.
    await page.goto('/crm/revenue');
    await page.getByRole('link', { name: 'Période précédente' }).click();
    await expect(page).toHaveURL(/key=\d{4}-\d{2}/);
    await expect(page.getByRole('row').filter({ has: page.getByRole('button', { name: DAS, exact: true }) })).not.toContainText(/2\s500\s€/);

    // On a phone the table scrolls inside its frame, not the page.
    await page.setViewportSize({ width: 375, height: 800 });
    await page.goto('/crm/revenue');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

    await context.close();
});
