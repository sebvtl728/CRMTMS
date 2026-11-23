import { expect, test, type APIRequestContext, type BrowserContext, type Locator, type Page } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved,
// and the issuing company « CAP FORMATION E2E » it created with the quote prefix « CF ».
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

const TRAINING = 'E2E Hygiène HACCP 2 jours';
const ENTREPRISE = 'E2E Boulangerie du Lac';
const OPPORTUNITY = 'E2E HACCP pour l’équipe du fournil';
const RECIPIENT = 'direction@boulangerie-du-lac.test';
const SIGNED_PDF = '%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n';

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

/** The current year in Paris (the quote number uses it). */
function parisYear(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Paris', year: 'numeric' }).format(new Date());
}

test('quotes: training in the catalogue, amount of the opportunity, quote, pdf, sending, acceptance and success rate', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({
        storageState: ADMIN_STATE,
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris',
        viewport: { width: 1400, height: 1000 },
    });
    const page = await context.newPage();

    // 1. The administrator adds a training to the catalogue (1 200 € per trainee for an entreprise).
    await page.goto('/catalogue');
    await page.getByRole('link', { name: 'Nouvelle formation' }).click();
    await expect(page.getByRole('heading', { name: 'Nouvelle formation' })).toBeVisible();
    await page.getByLabel('Intitulé *').fill(TRAINING);
    await page.getByLabel('Code', { exact: true }).fill('E2E-HACCP');
    await page.getByLabel('DAS *').click();
    await page.getByRole('option').first().click();
    await page.getByLabel('Durée (heures)').fill('14');
    await page.getByLabel('Tarif entreprise HT par stagiaire (€)').fill('1 200,00');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page).toHaveURL(/\/catalogue$/);
    await expect(page.getByText('Formation créée.')).toBeVisible();
    await expect(page.getByText(TRAINING).first()).toBeVisible();

    // 2. An opportunity « Étude » on an entreprise, with that training.
    const organisationUrl = await send(context, page.request, '/crm/organisations', { name: ENTREPRISE });
    await page.goto(organisationUrl);
    await page.getByRole('tab', { name: 'Opportunités' }).click();
    await page.getByRole('link', { name: 'Nouvelle opportunité' }).click();
    await expect(page.getByRole('heading', { name: 'Nouvelle opportunité' })).toBeVisible();
    await page.getByLabel('Objet *').fill(OPPORTUNITY);
    await choose(page, page.getByLabel('Ajouter une formation'), new RegExp(TRAINING));
    await choose(page, page.getByLabel('Étape *'), 'Étude');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Opportunité créée.')).toBeVisible();
    const opportunityUrl = page.url();

    // « Calculer le montant proposé »: 2 trainees × 1 200 €, carried into the estimated amount.
    await page.getByRole('link', { name: 'Modifier' }).click();
    await page.getByLabel('Quantité').fill('2');
    await page.getByRole('button', { name: 'Calculer le montant proposé' }).click();
    await expect(page.getByText(/Montant proposé : 2\s400,00\s€/)).toBeVisible();
    await page.getByRole('button', { name: 'Reporter dans le montant estimé' }).click();
    await expect(page.getByLabel('Montant estimé HT (€)')).toHaveValue(/^2\s400,00$/);
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Opportunité enregistrée.')).toBeVisible();
    await expect(page.getByText(/Montant proposé par les formations : 2\s400,00\s€/)).toBeVisible();

    // 3. A quote from the opportunity, prefilled with the training line.
    await page.getByRole('link', { name: 'Nouveau devis' }).click();
    await expect(page.getByRole('heading', { name: 'Nouveau devis' })).toBeVisible();
    await choose(page, page.getByLabel('Société émettrice *'), 'CAP FORMATION E2E');
    await page.getByRole('button', { name: 'Enregistrer le brouillon' }).click();
    // Other specs may have numbered quotes before (dossier.spec.ts): the next number of the year.
    const heading = page.getByRole('heading', { name: new RegExp(`^Devis CF-${parisYear()}-\\d{4}$`) });
    await expect(heading).toBeVisible();
    const number = (await heading.innerText()).replace('Devis ', '');
    await expect(page.getByText(`Devis ${number} créé.`)).toBeVisible();
    await expect(page.getByText('Brouillon', { exact: true })).toBeVisible();
    await expect(page.getByText(TRAINING).first()).toBeVisible();
    await expect(page.getByText(/2\s880,00\s€/).first()).toBeVisible(); // TTC: 2 400 € HT + 20 %

    // 4. PDF preview: a real PDF rendered by Gotenberg.
    const pdfHref = await page.getByRole('link', { name: 'Aperçu PDF' }).getAttribute('href');
    const pdf = await page.request.get(pdfHref!);
    expect(pdf.status()).toBe(200);
    expect(pdf.headers()['content-type']).toBe('application/pdf');
    expect((await pdf.body()).subarray(0, 5).toString()).toBe('%PDF-');

    // 5. Sending (test mailer of the e2e stack: the message is written to the log): the quote is « Envoyé ».
    await page.getByRole('button', { name: 'Envoyer', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: `Envoyer le devis ${number}` })).toBeVisible();
    await expect(dialog.getByText("Aucun serveur d'envoi n'est configuré", { exact: false })).toBeVisible();
    // Brochures: the box is ticked by default (nothing attached to this quote's trainings here).
    await expect(dialog.getByRole('checkbox', { name: 'Joindre les plaquettes' })).toBeChecked();
    await dialog.getByLabel('Destinataire *').fill(RECIPIENT);
    await dialog.getByRole('button', { name: 'Envoyer', exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByText(`Devis ${number} envoyé.`)).toBeVisible();
    await expect(page.getByText('Envoyé', { exact: true })).toBeVisible();
    await expect(page.getByRole('alert').filter({ hasText: "L'e-mail n'est pas parti" })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'PDF', exact: true })).toBeVisible();

    // The opportunity moved forward to « Proposition », the stage of a sent quote.
    await page.goto(opportunityUrl);
    await expect(page.getByText('Proposition', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('Étude → Proposition')).toBeVisible();

    // 6. Accepted: the opportunity is proposed as « Gagné » with the quote's amount.
    await page.getByRole('link', { name: number }).click();
    await page.getByRole('button', { name: 'Accepté', exact: true }).click();
    const answer = page.getByRole('dialog');
    await expect(answer.getByRole('heading', { name: `Devis ${number} accepté` })).toBeVisible();
    // With the signed copy sent back by the customer (optional).
    await answer.getByLabel('Devis signé (facultatif)').setInputFiles({ name: 'devis-signe.pdf', mimeType: 'application/pdf', buffer: Buffer.from(SIGNED_PDF) });
    await answer.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText(`Devis ${number} accepté.`)).toBeVisible();
    const outcome = page.getByRole('dialog');
    await expect(outcome.getByRole('heading', { name: "Passer l'opportunité en « Gagné » ?" })).toBeVisible();
    await expect(outcome.getByLabel(/Mettre le montant de l'opportunité à jour \(2\s400,00\s€ HT\)/)).toBeChecked();
    await outcome.getByRole('button', { name: 'Passer en « Gagné »' }).click();
    await expect(outcome).toBeHidden();
    // The signed copy is on the quote, served inline to signed-in users.
    await expect(page.getByRole('link', { name: 'devis-signe.pdf' })).toBeVisible();
    const signed = await page.request.get(`${page.url().split('?')[0]}/signe`);
    expect(signed.status()).toBe(200);
    expect(signed.headers()['content-type']).toBe('application/pdf');
    await page.goto(opportunityUrl);
    await expect(page.getByText('Gagné', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('Proposition → Gagné')).toBeVisible();

    // 7. The list of quotes of this entreprise (other specs send quotes too): the quote, accepted,
    // and a success rate of 100 % this month.
    await page.goto(`/crm/quotes?${new URLSearchParams({ q: ENTREPRISE })}`);
    await expect(page.getByRole('link', { name: number }).first()).toBeVisible();
    const rate = page.getByRole('region', { name: 'Taux de réussite' });
    await expect(rate).toContainText(/Taux de réussite · \S+ \d{4}/);
    await expect(rate).toContainText(/100\s%/);
    await expect(rate).toContainText('1 envoyé, 1 accepté');

    await context.close();
});
