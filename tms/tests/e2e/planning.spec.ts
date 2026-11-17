import { expect, test, type APIRequestContext, type BrowserContext, type Locator, type Page } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

const TRAINING = 'E2E Pâtisserie planning';
const TITLE = 'E2E Pâtisserie – semaine du 1er février 2027';
const CLIENT = 'E2E Boulangerie du Port';
const TRAINEE = { first_name: 'Nora', last_name: 'E2E Planchon' };
const TRAINER = { name: 'Paul Planning-E2E', email: 'paul.planning@e2e.test' };
const PASSWORD = 'Formateur-E2E-2027!';
const viewport = { width: 1400, height: 1000 };

/** A form post as the signed-in user (CSRF from the XSRF-TOKEN cookie). */
async function send(context: BrowserContext, request: APIRequestContext, url: string, data: Record<string, unknown>): Promise<void> {
    const xsrf = (await context.cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
    const response = await request.post(url, {
        data,
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
    });
    expect(response.ok(), `${url}: ${response.status()}`).toBe(true);
}

/** Picks an option of a shadcn (Radix) select. */
async function choose(page: Page, trigger: Locator, option: string | RegExp): Promise<void> {
    await trigger.click();
    await page.getByRole('option', { name: option, exact: typeof option === 'string' }).click();
}

test('planning: a manager generates séances with the assistant, a trainer marks « Tous présents » from « Mon planning »', async ({ browser }) => {
    // A long journey (invitation, catalogue, session, dossier, enrolment, assistant, two users).
    test.setTimeout(180_000);
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({ storageState: ADMIN_STATE, locale: 'fr-FR', timezoneId: 'Europe/Paris', viewport });
    const page = await context.newPage();

    // 1. A trainer: invited with the Formateur role, his account activated (linked to his trainer record).
    await page.goto('/admin/users/invite');
    await page.getByLabel('Nom complet').fill(TRAINER.name);
    await page.getByLabel('Adresse e-mail').fill(TRAINER.email);
    await page.locator('#role').click();
    await page.getByRole('option', { name: 'Formateur', exact: true }).first().click();
    await page.getByRole('button', { name: "Envoyer l'invitation" }).click();
    const link = await page.getByLabel("Lien d'activation").inputValue();
    const guest = await browser.newContext({ locale: 'fr-FR', timezoneId: 'Europe/Paris', viewport });
    const trainer = await guest.newPage();
    await trainer.goto(link.replace(/^https?:\/\/[^/]+/, new URL(page.url()).origin));
    await trainer.getByLabel('Mot de passe', { exact: true }).fill(PASSWORD);
    await trainer.getByLabel('Confirmer le mot de passe').fill(PASSWORD);
    await trainer.getByRole('button', { name: 'Activer mon compte' }).click();

    // 2. A training, a session of one week, one trainee enrolled.
    await page.goto('/catalogue');
    await page.getByRole('link', { name: 'Nouvelle formation' }).click();
    await page.getByLabel('Intitulé *').fill(TRAINING);
    await page.getByLabel('DAS *').click();
    await page.getByRole('option').first().click();
    await page.getByLabel('Durée (heures)').fill('35');
    await page.getByLabel('Tarif entreprise HT par stagiaire (€)').fill('1 000,00');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Formation créée.')).toBeVisible();
    await send(context, page.request, '/crm/organisations', { name: CLIENT });
    await send(context, page.request, '/crm/people', TRAINEE);

    await page.goto('/sessions/create');
    await page.getByLabel('Intitulé (facultatif)').fill(TITLE);
    await choose(page, page.getByLabel('Formation du catalogue *'), new RegExp(TRAINING));
    await page.getByLabel('Nombre de places *').fill('8');
    await page.getByLabel('Début *').fill('2027-02-01');
    await page.getByLabel('Fin *').fill('2027-02-05');
    await choose(page, page.getByLabel('Lieu *'), 'Distanciel');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText(/Session S-\d{4}-\d{4} créée\./)).toBeVisible();
    const sessionUrl = page.url();

    await page.goto('/dossiers/create');
    await page.getByLabel('Entreprise *').fill('Boulangerie du P');
    await page.getByRole('button', { name: CLIENT }).click();
    await choose(page, page.getByLabel('Formation du catalogue *'), new RegExp(TRAINING));
    await expect(page.getByLabel('Prix unitaire HT (€) *')).not.toHaveValue('');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText(/Dossier .* créé\./)).toBeVisible();

    await page.goto(sessionUrl);
    await page.getByRole('button', { name: 'Inscrire des stagiaires' }).click();
    const enrolDialog = page.getByRole('dialog');
    await choose(page, enrolDialog.getByLabel('Dossier *'), new RegExp(CLIENT));
    await enrolDialog.getByLabel('Stagiaires *').fill(TRAINEE.last_name);
    await enrolDialog.getByRole('button', { name: new RegExp(`^${TRAINEE.first_name} ${TRAINEE.last_name}`) }).click();
    await enrolDialog.getByRole('button', { name: 'Inscrire', exact: true }).click();
    await expect(enrolDialog).toBeHidden();

    // 3. The manager: module in séances, assistant (preview, then create), séances on the calendar.
    await page.getByRole('tab', { name: 'Planning' }).click();
    page.once('dialog', (d) => d.accept());
    await page.getByRole('button', { name: 'Passer en séances' }).click();
    await page.getByRole('button', { name: 'Générer les séances' }).click();
    const assistant = page.getByRole('dialog', { name: 'Générer les séances' });
    await choose(page, assistant.getByLabel('Formateur'), new RegExp(TRAINER.name));
    // The whole week of the session, whatever the planned hours of the module.
    await assistant.getByLabel('Date de fin choisie').check();
    await assistant.getByRole('button', { name: 'Aperçu' }).click();
    // Monday to Friday, 9:00–12:30 and 13:30–17:00: 10 séances.
    await expect(assistant.getByRole('region', { name: 'Aperçu' })).toContainText('10 séances à créer');
    await assistant.getByRole('button', { name: 'Créer 10 séances' }).click();
    await expect(assistant).toBeHidden();
    const calendar = page.getByRole('region', { name: 'Calendrier de la session' });
    await expect(calendar.getByRole('button', { name: /^09:00/ })).toHaveCount(5);

    // The general planning shows them too, filtered by trainer.
    await page.goto('/sessions/planning?view=week&date=2027-02-01');
    await expect(page.getByRole('heading', { name: 'Semaine du 01/02/2027 au 07/02/2027' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Planning des séances' }).getByRole('button', { name: new RegExp(TITLE) })).toHaveCount(10);

    // 4. The trainer signs in: « Mon planning », his séance, « Tous présents ».
    await trainer.getByLabel('Adresse e-mail').fill(TRAINER.email);
    await trainer.getByLabel('Mot de passe', { exact: true }).fill(PASSWORD);
    await trainer.getByRole('button', { name: 'Se connecter' }).click();
    await expect(trainer).toHaveURL(/\/mon-planning$/);
    // His menu is « Mon planning » alone.
    await expect(trainer.getByRole('heading', { name: 'Mon planning' })).toBeVisible();
    await expect(trainer.getByRole('link', { name: 'Planning des séances' })).toHaveCount(0);
    await trainer.goto('/mon-planning?view=week&date=2027-02-01');
    const board = trainer.getByRole('region', { name: 'Planning des séances' });
    await expect(board.getByRole('button', { name: new RegExp(TITLE) })).toHaveCount(10);
    await board
        .getByRole('button', { name: /^09:00/ })
        .first()
        .click();
    const attendance = trainer.getByRole('dialog', { name: 'Présences' });
    await expect(attendance.getByText(`${TRAINEE.first_name} ${TRAINEE.last_name}`)).toBeVisible();
    await attendance.getByRole('button', { name: 'Tous présents' }).click();
    await expect(attendance.getByRole('status')).toHaveText('1 stagiaire marqué présent.');
    await expect(
        attendance.getByRole('group', { name: `Présence de ${TRAINEE.first_name} ${TRAINEE.last_name}` }).getByRole('button', { pressed: true }),
    ).toHaveText(/^présent$/i);

    await guest.close();
    await context.close();
});
