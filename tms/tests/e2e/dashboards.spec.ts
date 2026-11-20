import { expect, test } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

test('« Tableaux de bord »: the week and month views, a period, the list behind a card, the CSV export', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({ storageState: ADMIN_STATE, locale: 'fr-FR', timezoneId: 'Europe/Paris' });
    const page = await context.newPage();

    await page.goto('/dashboard');
    await page.getByRole('link', { name: 'Tableaux de bord' }).first().click();
    await expect(page).toHaveURL(/\/crm\/dashboards/);
    await expect(page.getByRole('heading', { name: 'Tableaux de bord' })).toBeVisible();
    await expect(page.getByTestId('indicator-crm.calls')).toContainText('Appels faits');
    await expect(page.getByTestId('indicator-crm.calls')).toContainText('Actions de type appel faites dans la période');

    // The month view, last month.
    await page.getByRole('link', { name: 'Mois / direction' }).click();
    await expect(page.getByTestId('indicator-crm.das_revenue')).toBeVisible();
    await expect(page.getByTestId('indicator-sessions.retention')).toContainText('Taux de rétention');
    await page.getByLabel('Période').selectOption('last_month');
    await page.getByRole('button', { name: 'Appliquer' }).click();
    await expect(page).toHaveURL(/preset=last_month/);

    // The list behind a card.
    await page.getByTestId('indicator-crm.forecast').getByRole('link', { name: 'Voir le détail' }).click();
    await expect(page).toHaveURL(/\/crm\/opportunities\?.*close_from=/);

    // The CSV export of the cards.
    await page.goBack();
    const csv = await context.request.get('/crm/dashboards/export.csv?tab=month');
    expect(csv.ok()).toBe(true);
    expect(await csv.text()).toContain('Indicateur;Formule;Période;Valeur;Période précédente;Objectif');

    await context.close();
});
