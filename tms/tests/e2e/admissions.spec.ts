import { expect, test, type APIRequestContext, type BrowserContext, type Locator, type Page } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved,
// and the issuing company « CAP FORMATION E2E » it created.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

const TRAINING = 'E2E CAP Cuisine en apprentissage';
const EMPLOYER = 'E2E Brasserie du Port';
const CANDIDATE = { first_name: 'Inès', last_name: 'E2E Moreau' };

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

async function moveTo(page: Page, stage: string): Promise<void> {
    await page.getByRole('button', { name: "Changer l'étape" }).click();
    const dialog = page.getByRole('dialog');
    await choose(page, dialog.getByLabel('Étape'), stage);
    await dialog.getByRole('button', { name: 'Valider' }).click();
    await expect(page.getByText(`Étape : ${stage}.`)).toBeVisible();
}

test('an interlocuteur becomes a candidate, signs an apprenticeship and becomes an alternant', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({ storageState: ADMIN_STATE, locale: 'fr-FR', timezoneId: 'Europe/Paris', viewport: { width: 1400, height: 1000 } });
    const page = await context.newPage();

    // A training with its RNCP code; the employer and the person in the CRM.
    await page.goto('/catalogue');
    await page.getByRole('link', { name: 'Nouvelle formation' }).click();
    await page.getByLabel('Intitulé *').fill(TRAINING);
    await page.getByLabel('DAS *').click();
    await page.getByRole('option').first().click();
    await page.getByLabel('Code RNCP').fill('rncp 36004');
    await page.getByLabel('Tarif entreprise HT par stagiaire (€)').fill('6000,00');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Formation créée.')).toBeVisible();
    await send(context, page.request, '/crm/organisations', { name: EMPLOYER });
    const personUrl = await send(context, page.request, '/crm/people', CANDIDATE);

    // « Interlocuteurs » in the menu; the CRM fiche opens a candidature.
    await expect(page.getByRole('link', { name: 'Interlocuteurs' })).toBeVisible();
    await page.goto(personUrl);
    await page.getByRole('tab', { name: 'Candidatures' }).click();
    await page.getByRole('link', { name: 'Ouvrir une candidature' }).click();
    await expect(page.getByRole('heading', { name: 'Nouvelle candidature' })).toBeVisible();
    await expect(page.getByText(`${CANDIDATE.first_name} ${CANDIDATE.last_name}`, { exact: true })).toBeVisible();
    await choose(page, page.getByLabel('Formation visée'), TRAINING);
    await page.getByRole('button', { name: 'Ouvrir la candidature' }).click();
    await expect(page.getByText('Candidature créée.')).toBeVisible();
    await expect(page.getByLabel('Code RNCP')).toHaveValue('RNCP36004');

    // The path: test sent, test received, interview.
    await moveTo(page, 'Test envoyé');
    await moveTo(page, 'Test reçu');
    await moveTo(page, 'Entretien');

    // The alternance pathway, then the apprenticeship contract.
    await choose(page, page.getByLabel('Parcours'), 'Alternance');
    await page.getByRole('button', { name: 'Enregistrer' }).first().click();
    await expect(page.getByText('Candidature enregistrée.')).toBeVisible();
    await page.getByLabel("Entreprise d'accueil").fill('Brasserie du Port');
    await page.getByRole('button', { name: EMPLOYER }).click();
    await page.getByLabel('Début du contrat').fill('2027-01-04');
    await page.getByLabel('Fin du contrat').fill('2028-06-30');
    await page.getByRole('button', { name: 'Enregistrer le contrat' }).click();
    await expect(page.getByText('Contrat enregistré.')).toBeVisible();

    // Admitted, then the dossier with the employer as contractant.
    await moveTo(page, 'Admis');
    await page.getByRole('button', { name: 'Créer le dossier' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText(EMPLOYER)).toBeVisible();
    await dialog.getByRole('button', { name: 'Créer le dossier' }).click();
    await expect(page.getByText(/est alternant\./)).toBeVisible();
    await expect(page).toHaveURL(/\/dossiers\//);

    // In the « Alternants » tab.
    await page.goto('/admissions/candidatures?onglet=alternants');
    await expect(page.getByRole('cell', { name: new RegExp(CANDIDATE.last_name) })).toBeVisible();

    await context.close();
});
