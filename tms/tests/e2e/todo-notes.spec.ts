import { expect, test, type APIRequestContext, type BrowserContext } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

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

test('an action carries a motif and a suite à donner: typed, shown in « À faire », edited', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({ storageState: ADMIN_STATE, locale: 'fr-FR', timezoneId: 'Europe/Paris' });
    const page = await context.newPage();

    await page.goto('/crm/todo');
    const organisationUrl = await send(context, page.request, '/crm/organisations', { name: 'E2E Brasserie des Halles' });

    // « + Action » from the fiche: an action to do today, with its motif and its suite à donner.
    await page.goto(organisationUrl);
    await page.getByRole('button', { name: 'Action', exact: true }).first().click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: 'Nouvelle action' })).toBeVisible();
    await dialog.getByLabel('Objet', { exact: true }).fill('E2E Appeler la brasserie');
    await dialog.getByLabel('Motif', { exact: true }).fill('Relancer le devis HACCP');
    await dialog.getByLabel('Fait maintenant').click();
    await dialog.getByLabel('À faire le *').fill(`${today()}T23:00`);
    await dialog.getByLabel('Suite à donner', { exact: true }).fill('Envoyer le programme détaillé');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(dialog).toBeHidden();

    // Shown under the subject in « À faire ».
    await page.goto('/crm/todo');
    const row = page.locator('li', { hasText: 'E2E Appeler la brasserie' });
    await expect(row).toContainText('Motif : Relancer le devis HACCP');
    await expect(row).toContainText('Suite à donner : Envoyer le programme détaillé');

    // Edited from the row: the dialog is prefilled, the new texts are saved.
    await row.getByRole('button', { name: 'Modifier l’action E2E Appeler la brasserie' }).click();
    await expect(dialog.getByLabel('Motif', { exact: true })).toHaveValue('Relancer le devis HACCP');
    await expect(dialog.getByLabel('Suite à donner', { exact: true })).toHaveValue('Envoyer le programme détaillé');
    await dialog.getByLabel('Motif', { exact: true }).fill('Présenter la session de mars');
    await dialog.getByLabel('Suite à donner', { exact: true }).fill('Réserver deux places');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByText('Action modifiée.')).toBeVisible();

    await page.reload();
    const edited = page.locator('li', { hasText: 'E2E Appeler la brasserie' });
    await expect(edited).toContainText('Motif : Présenter la session de mars');
    await expect(edited).toContainText('Suite à donner : Réserver deux places');

    // And in the history of the fiche.
    await page.goto(organisationUrl);
    await page.getByRole('tab', { name: 'Historique' }).click();
    await expect(page.getByText('Motif : Présenter la session de mars')).toBeVisible();

    await context.close();
});
