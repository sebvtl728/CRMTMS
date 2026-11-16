import { expect, test, type APIRequestContext, type BrowserContext, type Locator, type Page } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

const TRAINING = 'E2E Commis de cuisine';
const TITLE = 'E2E Commis de cuisine – entrée du 11 janvier 2027';
const CLIENT = 'E2E Traiteur des Halles';
const PEOPLE = [
    { first_name: 'Lina', last_name: 'E2E Moreau' },
    { first_name: 'Hugo', last_name: 'E2E Faure' },
    { first_name: 'Inès', last_name: 'E2E Roux' },
    { first_name: 'Tom', last_name: 'E2E Blanc' },
];

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

/** Adds a person found by the search of the enrolment dialog. */
async function addFound(dialog: Locator, person: { first_name: string; last_name: string }): Promise<void> {
    await dialog.getByLabel('Stagiaires *').fill(person.last_name);
    await dialog.getByRole('button', { name: new RegExp(`^${person.first_name} ${person.last_name}`) }).click();
    await expect(dialog.getByRole('list', { name: 'Stagiaires à inscrire' }).getByText(`${person.first_name} ${person.last_name}`)).toBeVisible();
}

test('sessions: a titled inter session, a group of four enrolled at once (one created), a later wave on the waiting list, hours per trainee', async ({
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

    // A training of 35 h at 1 000 € per trainee; the client and three people in the CRM.
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
    for (const person of PEOPLE.slice(0, 3)) {
        await send(context, page.request, '/crm/people', person);
    }

    // The session, named, inter with 4 places.
    await page.goto('/sessions/create');
    await page.getByLabel('Intitulé (facultatif)').fill(TITLE);
    await choose(page, page.getByLabel('Formation du catalogue *'), new RegExp(TRAINING));
    await page.getByLabel('Nombre de places *').fill('4');
    await page.getByLabel('Début *').fill('2027-01-11');
    await page.getByLabel('Fin *').fill('2027-03-05');
    await choose(page, page.getByLabel('Lieu *'), 'Distanciel');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText(/Session S-\d{4}-\d{4} créée\./)).toBeVisible();
    await expect(page.getByRole('heading', { name: TITLE })).toBeVisible();
    const sessionUrl = page.url();

    // The list finds it by its title.
    await page.goto(`/sessions?q=${encodeURIComponent('entrée du 11 janvier')}`);
    await expect(page.getByText(TITLE).first()).toBeVisible();

    // A dossier of the client for this training.
    await page.goto('/dossiers/create');
    await page.getByLabel('Entreprise *').fill('Traiteur des Hal');
    await page.getByRole('button', { name: CLIENT }).click();
    await choose(page, page.getByLabel('Formation du catalogue *'), new RegExp(TRAINING));
    await expect(page.getByLabel('Prix unitaire HT (€) *')).not.toHaveValue('');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText(/Dossier .* créé\./)).toBeVisible();

    // The group: three found in the CRM, one created on the spot, one common entry day.
    await page.goto(sessionUrl);
    await page.getByRole('button', { name: 'Inscrire des stagiaires' }).click();
    const dialog = page.getByRole('dialog');
    await choose(page, dialog.getByLabel('Dossier *'), new RegExp(CLIENT));
    await dialog.getByLabel("Date d'entrée commune").fill('2027-01-11');
    for (const person of PEOPLE.slice(0, 3)) {
        await addFound(dialog, person);
    }
    await dialog.getByRole('button', { name: 'Créer un stagiaire' }).click();
    await dialog.getByLabel('Prénom').fill(PEOPLE[3].first_name);
    await dialog.getByLabel('Nom *').fill(PEOPLE[3].last_name);
    await dialog.getByRole('button', { name: 'Ajouter' }).click();
    await expect(dialog.getByText(`${PEOPLE[3].first_name} ${PEOPLE[3].last_name} (à créer)`)).toBeVisible();
    // Removing one from the group before sending, then adding him back.
    await dialog.getByRole('button', { name: new RegExp(`^Retirer ${PEOPLE[2].first_name} ${PEOPLE[2].last_name}`) }).click();
    await expect(dialog.getByRole('list', { name: 'Stagiaires à inscrire' }).getByText(`${PEOPLE[2].first_name} ${PEOPLE[2].last_name}`)).toHaveCount(
        0,
    );
    await addFound(dialog, PEOPLE[2]);
    await dialog.getByRole('button', { name: 'Inscrire les 4 stagiaires' }).click();
    await expect(page.getByText('4 stagiaires inscrits.')).toBeVisible();
    await expect(dialog).toBeHidden();
    const enrolled = page.getByRole('region', { name: 'Inscrits' });
    await expect(enrolled.getByRole('heading', { name: /Inscrits · 4 \/ 4 places/ })).toBeVisible();
    for (const person of PEOPLE) {
        await expect(enrolled.getByText(`${person.first_name} ${person.last_name}`)).toBeVisible();
    }
    await expect(enrolled.getByText('entrée le 11/01/2027').first()).toBeVisible();

    // A second wave a week later: the session is full, the newcomer waits.
    await send(context, page.request, '/crm/people', { first_name: 'Zoé', last_name: 'E2E Perrin' });
    await page.getByRole('button', { name: 'Inscrire des stagiaires' }).click();
    await choose(page, dialog.getByLabel('Dossier *'), new RegExp(CLIENT));
    await dialog.getByLabel("Date d'entrée commune").fill('2027-01-18');
    await addFound(dialog, { first_name: 'Zoé', last_name: 'E2E Perrin' });
    await dialog.getByRole('button', { name: 'Inscrire', exact: true }).click();
    await expect(page.getByText("1 stagiaire en liste d'attente.")).toBeVisible();
    const waiting = page.getByRole('region', { name: "Liste d'attente" });
    await expect(waiting.getByText('Zoé E2E Perrin')).toBeVisible();
    await expect(waiting.getByText('entrée le 18/01/2027')).toBeVisible();

    // Hours per trainee: bounded by the session's, then changed.
    const row = enrolled.getByRole('listitem').filter({ hasText: `${PEOPLE[0].first_name} ${PEOPLE[0].last_name}` });
    await expect(row.getByText('35,00 h prévues')).toBeVisible();
    await row.getByRole('button', { name: 'Modifier' }).click();
    await dialog.getByLabel('Heures prévues').fill('40');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(dialog.getByText('Les heures prévues ne peuvent pas dépasser celles de la session (35,00 h).')).toBeVisible();
    await dialog.getByLabel('Heures prévues').fill('28');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Inscription enregistrée.')).toBeVisible();
    await expect(row.getByText('28,00 h prévues')).toBeVisible();

    await context.close();
});
