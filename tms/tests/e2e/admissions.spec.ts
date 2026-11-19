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
    const context = await browser.newContext({
        storageState: ADMIN_STATE,
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris',
        viewport: { width: 1400, height: 1000 },
    });
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
    // A positioning test of that training in the library.
    await page.goto('/catalogue/brochures/create?type=positioning_test');
    await expect(page.getByRole('heading', { name: 'Nouveau test de positionnement' })).toBeVisible();
    await page.getByLabel('Titre *').fill(`Test ${TRAINING}`);
    await page.locator('#file').setInputFiles({
        name: 'test.pdf',
        mimeType: 'application/pdf',
        buffer: Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n'),
    });
    await page.getByLabel('Filtrer les formations').fill(TRAINING);
    await page.getByRole('checkbox', { name: TRAINING }).click();
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Test de positionnement ajouté.')).toBeVisible();
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

    // The cards open one after the other: the test (training saved), then the interview once the test is back.
    await expect(page.getByRole('region', { name: 'Test de positionnement' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Entretien' })).toHaveCount(0);

    // The test of the training is offered and sent to the candidate with a personal link.
    const positioning = page.getByRole('region', { name: 'Test de positionnement' });
    await expect(positioning.getByRole('link', { name: `Test ${TRAINING}` })).toBeVisible();
    // A link to copy, without any e-mail; the candidate opens it.
    await positioning.getByRole('button', { name: 'Générer le lien' }).click();
    await expect(page.getByText('Lien du test généré : copiez-le et transmettez-le au candidat.')).toBeVisible();
    const link = await positioning.getByLabel(/Lien du candidat/).inputValue();
    expect(link).toMatch(/\/test-positionnement\/[0-9a-f]{64}$/);
    const candidatePage = await context.newPage();
    await candidatePage.goto(link);
    await expect(candidatePage.getByRole('link', { name: `Test ${TRAINING}` })).toBeVisible();
    await candidatePage.close();
    // Or by e-mail (a new link replaces the previous one).
    await positioning.getByRole('button', { name: 'Envoyer par e-mail' }).click();
    await page.getByRole('dialog').getByLabel('Destinataire *').fill('candidat@example.test');
    await page.getByRole('dialog').getByRole('button', { name: 'Envoyer' }).click();
    await expect(page.getByText("Test de positionnement en cours d'envoi.")).toBeVisible();
    await expect(positioning.getByText(/Actif jusqu'au/)).toBeVisible();
    await page.getByLabel('Reçu le').fill('2026-11-18');
    await page.getByRole('region', { name: 'Test de positionnement' }).getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByRole('region', { name: 'Entretien' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Alternance' })).toHaveCount(0);

    // The path (« Test envoyé » set by the sending): test received, interview.
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

    // Admitted (the next step is offered on the page), then the dossier with the employer as contractant.
    await page.getByRole('button', { name: "Passer à l'étape « Admis »" }).click();
    await expect(page.getByText('Étape : Admis.')).toBeVisible();
    await page.getByRole('button', { name: 'Créer le dossier' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText(EMPLOYER)).toBeVisible();
    await dialog.getByRole('button', { name: 'Créer le dossier' }).click();
    await expect(page.getByText(/est alternant\./)).toBeVisible();
    await expect(page).toHaveURL(/\/dossiers\//);

    // In the « Alternants » tab.
    await page.goto('/admissions/candidatures?onglet=alternants');
    await expect(page.getByRole('cell', { name: new RegExp(CANDIDATE.last_name) })).toBeVisible();

    // A link that does not exist: the candidate's page says it plainly.
    await page.goto(`/test-positionnement/${'a'.repeat(64)}`);
    await expect(page.getByRole('heading', { name: 'Lien invalide ou expiré' })).toBeVisible();

    await context.close();
});
