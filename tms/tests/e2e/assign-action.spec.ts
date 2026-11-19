import { expect, test, type APIRequestContext, type BrowserContext } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';
const PASSWORD = 'e2e-Binome-2026!'; // test value, e2e database only
const COLLEAGUE_EMAIL = 'binome@e2e.test';
const COLLEAGUE = 'Bruno Binôme';

interface Option {
    value: string;
    label: string;
    code?: string;
}

/** Today in Paris, YYYY-MM-DD. */
function today(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Paris' }).format(new Date());
}

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

test('« Attribuer à » and « Confier à… »: the colleague finds the actions in his « À faire »', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({ storageState: ADMIN_STATE, locale: 'fr-FR', timezoneId: 'Europe/Paris' });
    const page = await context.newPage();

    // A colleague (commercial) is invited and signs in.
    await page.goto('/admin/users/invite');
    await page.getByLabel('Nom complet').fill(COLLEAGUE);
    await page.getByLabel('Adresse e-mail').fill(COLLEAGUE_EMAIL);
    await page.locator('#role').click();
    await page.getByRole('option', { name: 'Commercial', exact: true }).click();
    await page.getByRole('button', { name: "Envoyer l'invitation" }).click();
    const link = await page.getByLabel("Lien d'activation").inputValue();

    const guest = await browser.newContext({ locale: 'fr-FR', timezoneId: 'Europe/Paris' });
    const colleague = await guest.newPage();
    await colleague.goto(link.replace(/^https?:\/\/[^/]+/, new URL(page.url()).origin));
    await colleague.getByLabel('Mot de passe', { exact: true }).fill(PASSWORD);
    await colleague.getByLabel('Confirmer le mot de passe').fill(PASSWORD);
    await colleague.getByRole('button', { name: 'Activer mon compte' }).click();
    await colleague.getByLabel('Adresse e-mail').fill(COLLEAGUE_EMAIL);
    await colleague.getByLabel('Mot de passe', { exact: true }).fill(PASSWORD);
    await colleague.getByRole('button', { name: 'Se connecter' }).click();
    await expect(colleague).toHaveURL(/\/dashboard$/);

    // 1. The administrator creates an action to do and gives it to the colleague (« Attribuer à »).
    const organisationUrl = await send(context, page.request, '/crm/organisations', { name: 'E2E Brasserie confiée' });
    await page.goto(organisationUrl);
    await page.getByRole('button', { name: 'Action', exact: true }).first().click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: 'Nouvelle action' })).toBeVisible();
    await dialog.getByLabel('Objet').fill('E2E Rappeler le brasseur');
    await dialog.getByLabel('Fait maintenant').click();
    await dialog.getByLabel('À faire le *').fill(`${today()}T23:00`);
    // « Attribuer à » is a multiple choice (the first ticked is responsible): the colleague only.
    await dialog.getByLabel('Attribuer à').click();
    await page.getByRole('menuitemcheckbox', { name: 'Admin E2E' }).click();
    await page.getByRole('menuitemcheckbox', { name: COLLEAGUE }).click();
    await page.keyboard.press('Escape');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(dialog).toBeHidden();

    await colleague.goto('/crm/todo');
    await expect(colleague.locator('li', { hasText: 'E2E Rappeler le brasseur' })).toBeVisible();

    // 2. « Confier à… » on one of his own actions: it leaves his « À faire » and joins the colleague's.
    const choices = await (await page.request.get('/crm/activities/options', { headers: { Accept: 'application/json' } })).json();
    const call = (choices.types as Option[]).find((t) => t.code === 'appel')!;
    await send(context, page.request, '/crm/activities', {
        type_id: call.value,
        organisation_id: organisationUrl.split('/').pop(),
        subject: 'E2E Relancer la brasserie',
        purpose: 'Proposer la formation HACCP',
        done: false,
        due_at: `${today()}T23:30`,
    });
    await page.goto('/crm/todo');
    const row = page.locator('li', { hasText: 'E2E Relancer la brasserie' });
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: 'Confier l’action E2E Relancer la brasserie' }).click();
    await expect(dialog.getByRole('heading', { name: 'Confier l’action' })).toBeVisible();
    await dialog.getByLabel('Confier à *').click();
    await page.getByRole('option', { name: COLLEAGUE, exact: true }).click();
    await dialog.getByLabel('Message (facultatif)').fill('Tu connais bien le gérant.');
    await dialog.getByRole('button', { name: 'Confier', exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByText(`Action confiée à ${COLLEAGUE}.`)).toBeVisible();
    await expect(page.locator('li', { hasText: 'E2E Relancer la brasserie' })).toHaveCount(0);

    await colleague.reload();
    await expect(colleague.locator('li', { hasText: 'E2E Relancer la brasserie' })).toBeVisible();
    await expect(colleague.locator('li', { hasText: 'E2E Rappeler le brasseur' })).toBeVisible();

    // The fiche's history tells who handed what.
    await page.goto(organisationUrl);
    await page.getByRole('tab', { name: 'Historique' }).click();
    await expect(page.getByText(`Action confiée à ${COLLEAGUE} par Admin E2E`).first()).toBeVisible();

    await guest.close();
    await context.close();
});
