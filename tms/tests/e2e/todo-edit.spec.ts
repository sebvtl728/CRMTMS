import { expect, test, type APIRequestContext, type BrowserContext } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

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

test('« À faire »: an action is edited (subject, due date) from its row', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({ storageState: ADMIN_STATE, locale: 'fr-FR', timezoneId: 'Europe/Paris' });
    const page = await context.newPage();

    // An action to do today at 7:00 on a new entreprise.
    await page.goto('/crm/todo');
    const organisationUrl = await send(context, page.request, '/crm/organisations', { name: 'E2E Auberge à modifier' });
    const choices = await (await page.request.get('/crm/activities/options', { headers: { Accept: 'application/json' } })).json();
    const call = (choices.types as Option[]).find((t) => t.code === 'appel')!;
    await send(context, page.request, '/crm/activities', {
        type_id: call.value,
        organisation_id: organisationUrl.split('/').pop(),
        subject: 'E2E Rappeler le gérant',
        done: false,
        due_at: `${today()}T07:00`,
    });

    await page.goto('/crm/todo');
    const row = page.locator('li', { hasText: 'E2E Rappeler le gérant' });
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: 'Modifier l’action E2E Rappeler le gérant' }).click();

    // The dialog is prefilled with the action.
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: 'Modifier l’action' })).toBeVisible();
    await expect(dialog.getByLabel('Objet *')).toHaveValue('E2E Rappeler le gérant');
    await expect(dialog.getByLabel('À faire le *')).toHaveValue(`${today()}T07:00`);
    await expect(dialog.getByText('E2E Auberge à modifier')).toBeVisible();

    // A blank subject is refused in French.
    await dialog.getByLabel('Objet *').fill('');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(dialog.getByText("Indiquez l'objet de l'action.")).toBeVisible();

    await dialog.getByLabel('Objet *').fill('E2E Rappeler la gérante');
    await dialog.getByLabel('À faire le *').fill(`${today()}T23:30`);
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(dialog).toBeHidden();

    await expect(page.getByText('Action modifiée.')).toBeVisible();
    const edited = page.locator('li', { hasText: 'E2E Rappeler la gérante' });
    await expect(edited).toContainText('23:30');
    await expect(page.getByText('E2E Rappeler le gérant', { exact: true })).toHaveCount(0);

    // Saved: still there after a reload, and edited again from the fiche's history.
    await page.reload();
    await expect(page.locator('li', { hasText: 'E2E Rappeler la gérante' })).toContainText('23:30');
    await page.goto(organisationUrl);
    await page.getByRole('tab', { name: 'Historique' }).click();
    await page.getByRole('button', { name: 'Modifier l’action E2E Rappeler la gérante' }).click();
    await expect(dialog.getByLabel('Objet *')).toHaveValue('E2E Rappeler la gérante');
    await dialog.getByLabel('Objet *').fill('E2E Rappeler la gérante (fiche)');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByText('E2E Rappeler la gérante (fiche)')).toBeVisible();

    await context.close();
});
