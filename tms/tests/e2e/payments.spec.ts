import { expect, test, type APIRequestContext, type BrowserContext, type Locator, type Page } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved,
// and the issuing company « CAP FORMATION E2E » it created.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

const CLIENT = 'E2E Boulangerie du Marché';

async function xsrf(context: BrowserContext): Promise<string> {
    return decodeURIComponent((await context.cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
}

/** A form request as the signed-in user; returns the final URL. */
async function send(context: BrowserContext, request: APIRequestContext, method: 'post' | 'put', url: string, data: Record<string, unknown>): Promise<string> {
    const response = await request[method](url, {
        data,
        headers: { 'X-XSRF-TOKEN': await xsrf(context), 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
    });
    expect(response.ok(), `${url}: ${response.status()}`).toBe(true);

    return response.url();
}

/** The Inertia props of the page shown. */
async function props(page: Page): Promise<Record<string, any>> {
    return page.evaluate(() => JSON.parse(document.getElementById('app')?.dataset.page ?? '{}').props);
}

async function choose(page: Page, trigger: Locator, option: string): Promise<void> {
    await trigger.click();
    await page.getByRole('option', { name: option, exact: true }).click();
}

test('a validated invoice is paid from its page and becomes « Soldée »', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({ storageState: ADMIN_STATE, locale: 'fr-FR', timezoneId: 'Europe/Paris', viewport: { width: 1400, height: 1000 } });
    const page = await context.newPage();

    // A client, a dossier of one fee line, a draft invoice of 120 € made by hand.
    const clientUrl = await send(context, page.request, 'post', '/crm/organisations', { name: CLIENT, email: 'compta@boulangerie-du-marche.test' });
    const clientId = clientUrl.split('/').pop() ?? '';
    await page.goto('/dossiers/create');
    const form = await props(page);
    const dossierUrl = await send(context, page.request, 'post', '/dossiers', {
        company_id: form.defaults.company_id,
        account_organisation_id: clientId,
        das_id: form.options.das[0].value,
        lines: [{ kind: 'fee', label: 'Frais de dossier E2E', quantity: '1', unit: 'forfait', unit_price: '120', discount_percent: '' }],
    });
    const dossierId = new URL(dossierUrl).pathname.split('/').pop() ?? '';
    const draftUrl = await send(context, page.request, 'post', `/finance/dossiers/${dossierId}/invoices`, { recipient: 'contractor' });
    await page.goto(draftUrl);
    const draft = await props(page);
    await send(context, page.request, 'put', new URL(draftUrl).pathname, {
        notes: null,
        lines: [
            ...draft.invoice.lines.map((l: Record<string, string>) => ({ id: l.id, label: l.label, quantity: l.quantity, unit: l.unit, unit_price: '0', vat_rate: l.vat_rate })),
            { label: 'Frais de dossier E2E', quantity: '1', unit: 'forfait', unit_price: '120,00', vat_rate: '0' },
        ],
    });

    // Validated on its page: « Non réglée », then paid from the « Règlements » block.
    await page.goto(draftUrl);
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: /^Valider/ }).click();
    await expect(page.getByText(/Facture validée : /)).toBeVisible();
    const payments = page.getByRole('region', { name: 'Règlements' });
    await expect(payments.getByText('Non réglée')).toBeVisible();
    await payments.getByRole('link', { name: 'Enregistrer un règlement' }).click();

    await expect(page.getByRole('heading', { name: 'Nouveau règlement' })).toBeVisible();
    await expect(page.getByText(CLIENT)).toBeVisible();
    await expect(page.getByLabel('Montant (€) *')).toHaveValue('120,00');
    await choose(page, page.getByLabel('Mode de règlement *'), 'Virement');
    await page.getByLabel(/Référence/).fill('VIR E2E 42');
    await page.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(page.getByText('Règlement enregistré.')).toBeVisible();
    await expect(page.getByRole('region', { name: 'Règlements' }).getByText('Soldée')).toBeVisible();

    // The payment in Finance › Règlements; the invoice in the list, nothing left.
    await page.goto('/finance/payments');
    await expect(page.getByRole('cell', { name: 'VIR E2E 42' })).toBeVisible();
    await page.goto('/finance/invoices?payment=open');
    await expect(page.getByRole('cell', { name: CLIENT })).toHaveCount(0);

    await context.close();
});
