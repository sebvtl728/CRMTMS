import { expect, test, type APIRequestContext, type BrowserContext, type Locator, type Page } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved,
// and the issuing company « CAP FORMATION E2E » it created.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

const TRAINING = 'E2E Gestes et postures';
const CLIENT = 'E2E Imprimerie des Alpes';
const OPPORTUNITY = 'E2E Gestes et postures pour l’atelier';
const RECIPIENT = 'rh@imprimerie-des-alpes.test';
const EMPLOYEE = { first_name: 'Camille', last_name: 'E2E Rousseau' };

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

test('dossier from an accepted quote: summary, new intra session, trainees found and created, dossier funded', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({
        storageState: ADMIN_STATE,
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris',
        viewport: { width: 1400, height: 1000 },
    });
    const page = await context.newPage();

    // A training of 900 € per trainee; the client and one of its people in the CRM.
    await page.goto('/catalogue');
    await page.getByRole('link', { name: 'Nouvelle formation' }).click();
    await page.getByLabel('Intitulé *').fill(TRAINING);
    await page.getByLabel('DAS *').click();
    await page.getByRole('option').first().click();
    await page.getByLabel('Durée totale (heures)').fill('7');
    await page.getByLabel('Tarif entreprise HT par stagiaire (€)').fill('900,00');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Formation créée.')).toBeVisible();
    const organisationUrl = await send(context, page.request, '/crm/organisations', { name: CLIENT });
    await send(context, page.request, '/crm/people', EMPLOYEE);

    // An opportunity with that training, its quote (2 trainees), sent and accepted.
    await page.goto(organisationUrl);
    await page.getByRole('tab', { name: 'Opportunités' }).click();
    await page.getByRole('link', { name: 'Nouvelle opportunité' }).click();
    await page.getByLabel('Objet *').fill(OPPORTUNITY);
    await choose(page, page.getByLabel('Ajouter une formation'), new RegExp(TRAINING));
    await page.getByLabel('Quantité').fill('2');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Opportunité créée.')).toBeVisible();
    await page.getByRole('link', { name: 'Nouveau devis' }).click();
    await choose(page, page.getByLabel('Société émettrice *'), 'CAP FORMATION E2E');
    await page.getByRole('button', { name: 'Enregistrer le brouillon' }).click();
    const heading = page.getByRole('heading', { name: /^Devis / });
    await expect(heading).toBeVisible();
    const number = (await heading.innerText()).replace('Devis ', '');
    // A draft is not transformed.
    await expect(page.getByRole('link', { name: 'Créer le dossier' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Envoyer', exact: true }).click();
    const mail = page.getByRole('dialog');
    await mail.getByLabel('Destinataire *').fill(RECIPIENT);
    await mail.getByRole('button', { name: 'Envoyer', exact: true }).click();
    await expect(page.getByText(`Devis ${number} envoyé.`)).toBeVisible();
    await page.getByRole('button', { name: 'Accepté', exact: true }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText(`Devis ${number} accepté.`)).toBeVisible();
    const outcome = page.getByRole('dialog');
    await outcome.getByRole('button', { name: 'Passer en « Gagné »' }).click();
    await expect(outcome).toBeHidden();
    const quoteUrl = page.url();

    // The assistant: 1) summary of the quote.
    await page.getByRole('link', { name: 'Créer le dossier' }).click();
    await expect(page.getByRole('heading', { name: `Créer le dossier du devis ${number}` })).toBeVisible();
    const summary = page.getByRole('region', { name: 'Récapitulatif' });
    await expect(summary.getByText(CLIENT)).toBeVisible();
    await expect(summary.getByText(/1\s800,00\s€/).first()).toBeVisible();
    await page.getByRole('button', { name: 'Suivant' }).click();

    // 2) No session of this training yet: a new intra session for the client, priced per trainee.
    const sessions = page.getByRole('region', { name: 'Sessions' });
    await expect(sessions.getByRole('radio', { name: 'Nouvelle session' })).toBeChecked();
    await expect(sessions.getByRole('radio', { name: `Intra (${CLIENT})` })).toBeChecked();
    await expect(sessions.getByLabel('Mode de prix')).toHaveText('Prix par stagiaire');
    await sessions.getByLabel('Début *').fill('2027-01-11');
    await expect(sessions.getByLabel('Fin *')).toHaveValue('2027-01-11');
    await page.getByRole('button', { name: 'Suivant' }).click();

    // 3) Trainees: one found in the CRM (picked with Enter), one created on the spot.
    const trainees = page.getByRole('region', { name: 'Stagiaires' });
    await trainees.getByLabel('Ajouter un stagiaire du CRM').fill('Rousseau');
    await expect(trainees.getByRole('button', { name: `${EMPLOYEE.first_name} ${EMPLOYEE.last_name}` })).toBeVisible();
    await trainees.getByLabel('Ajouter un stagiaire du CRM').press('Enter');
    await expect(trainees.getByText(`${EMPLOYEE.first_name} ${EMPLOYEE.last_name}`)).toBeVisible();
    await trainees.getByRole('button', { name: 'Créer un stagiaire' }).click();
    await trainees.getByLabel('Prénom').fill('Noé');
    await trainees.getByLabel('Nom *').fill('E2E Garnier');
    // Enter in a field adds the person (never submits the assistant).
    await trainees.getByLabel('Nom *').press('Enter');
    await expect(trainees.getByText('Noé E2E Garnier (à créer)')).toBeVisible();
    await page.getByRole('button', { name: 'Créer le dossier' }).click();

    // The dossier: its two trainees on the new session, the entreprise funding the whole price.
    await expect(page.getByText(/Dossier D-\d{4}-\d{4} créé\./)).toBeVisible();
    await expect(page.getByRole('heading', { name: /^Dossier D-\d{4}-\d{4}/ })).toBeVisible();
    await expect(page.getByText(`${EMPLOYEE.first_name} ${EMPLOYEE.last_name}`).first()).toBeVisible();
    await expect(page.getByText('Noé E2E Garnier').first()).toBeVisible();
    const fundings = page.getByRole('region', { name: 'Financements' });
    await expect(fundings.getByRole('link', { name: CLIENT })).toBeVisible();
    await expect(fundings.getByText('À demander')).toBeVisible();
    await expect(fundings.getByText(/Reste à financer : 0,00\s€/)).toBeVisible();
    // Its history: creation, the two enrolments, the initial funding.
    const history = page.getByRole('region', { name: 'Historique' });
    await expect(history.getByText('Dossier créé')).toBeVisible();
    await expect(history.getByText(new RegExp(`^${EMPLOYEE.first_name} ${EMPLOYEE.last_name} - Inscription à la session S-`))).toBeVisible();
    await expect(history.getByText(new RegExp(`^Financement ajouté : ${CLIENT} \\(Entreprise\\)`))).toBeVisible();
    const dossierUrl = page.url();

    // The list shows what is left to fund (nothing: the entreprise funds the whole price).
    await page.goto(`${new URL(dossierUrl).origin}/dossiers`);
    await expect(page.getByRole('columnheader', { name: 'Reste à financer' })).toBeVisible();
    await page.goto(dossierUrl);

    // Once only: the quote now links to its dossier, the assistant says it exists.
    await page.goto(quoteUrl);
    await page.getByRole('link', { name: /^Voir le dossier D-/ }).click();
    await expect(page).toHaveURL(dossierUrl);
    await page.goBack();
    await page.goto(`${new URL(dossierUrl).origin}/dossiers/from-quote/${quoteUrl.split('/').pop()}`);
    await expect(page.getByText('Un dossier existe déjà pour ce devis.')).toBeVisible();

    await context.close();
});
