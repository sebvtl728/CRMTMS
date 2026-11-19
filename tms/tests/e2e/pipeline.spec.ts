import { expect, test, type APIRequestContext, type BrowserContext, type Locator, type Page } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

const ENTREPRISE = 'E2E Traiteur des Alpes';
const OPPORTUNITY = 'E2E Formation hygiène alimentaire';

/** A day in Paris, YYYY-MM-DD, `offset` days from today. */
function parisDay(offset = 0): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Paris' }).format(new Date(Date.now() + offset * 86_400_000));
}

/** ISO week « 2026-W47 » of a YYYY-MM-DD day. */
function isoWeek(day: string): string {
    const date = new Date(`${day}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + 4 - (date.getUTCDay() || 7)); // Thursday of the week
    const yearStart = Date.UTC(date.getUTCFullYear(), 0, 1);
    const week = Math.ceil(((date.getTime() - yearStart) / 86_400_000 + 1) / 7);

    return `${date.getUTCFullYear()}-W${String(week).padStart(2, '0')}`;
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

/** Picks an option of a shadcn (Radix) select. */
async function choose(page: Page, trigger: Locator, option: string): Promise<void> {
    await trigger.click();
    await page.getByRole('option', { name: option, exact: true }).click();
}

/** Drags a kanban card by its grip onto a column, with the mouse (the sensor starts after 5 px). */
async function drag(page: Page, title: string, column: string): Promise<void> {
    const grip = page.getByRole('button', { name: `Déplacer « ${title} »` });
    const target = page.getByRole('region', { name: column, exact: true });
    const from = (await grip.boundingBox())!;
    const to = (await target.boundingBox())!;
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(from.x + 20, from.y + 20, { steps: 5 });
    await page.mouse.move(to.x + to.width / 2, to.y + 80, { steps: 20 });
    await page.mouse.up();
}

test('pipeline: opportunity from an entreprise, kanban, loss reason, quick call, follow-up in « À faire » and planning', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    // Wide enough for the seven columns of the CAP FORMATION pipeline side by side.
    const context = await browser.newContext({
        storageState: ADMIN_STATE,
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris',
        viewport: { width: 2300, height: 1000 },
    });
    const page = await context.newPage();

    // The « Commercial » menu, in its order.
    await page.goto('/dashboard');
    const menu = page.locator('[data-sidebar="group"]').filter({ has: page.getByText('Commercial', { exact: true }) });
    await expect(menu.locator('[data-sidebar="menu-button"]')).toHaveText([
        'Pipeline',
        'Opportunités',
        'Devis',
        "Chiffre d'affaires",
        'À faire',
        'Planning',
        'Compte rendu de RDV',
        'Catalogue',
        'Entreprises',
        'Interlocuteurs',
        'Imports',
        'RGPD',
    ]);
    // « Candidats » opens the « Formation » menu, above « Dossiers ».
    const training = page.locator('[data-sidebar="group"]').filter({ has: page.getByText('Formation', { exact: true }) });
    await expect(training.locator('[data-sidebar="menu-button"]').first()).toHaveText('Candidats');
    await expect(training.locator('[data-sidebar="menu-button"]').nth(1)).toHaveText('Dossiers');

    // 1. An opportunity from the entreprise's « Opportunités » tab, closing this month.
    const organisationUrl = await send(context, page.request, '/crm/organisations', { name: ENTREPRISE });
    await page.goto(organisationUrl);
    await page.getByRole('tab', { name: 'Opportunités' }).click();
    await page.getByRole('link', { name: 'Nouvelle opportunité' }).click();
    await expect(page.getByRole('heading', { name: 'Nouvelle opportunité' })).toBeVisible();
    await page.getByLabel('Objet *').fill(OPPORTUNITY);
    await page.getByLabel('DAS *').click();
    await page.getByRole('option').first().click();
    await choose(page, page.getByLabel('Étape *'), 'Nouveau');
    await page.getByLabel('Montant estimé (€)').fill('10 000');
    await page.getByLabel('Clôture prévue').fill(parisDay());
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Opportunité créée.')).toBeVisible();
    const opportunityUrl = page.url();

    // The dashboard: weighted pipeline of the month (10 000 € at 10 %).
    await page.goto('/dashboard');
    const pipeline = page.getByRole('region', { name: 'Pipeline pondéré du mois' });
    await expect(pipeline).toContainText(/1\s000,00\s€/);
    // The cards of the month: actions created and done, meetings fixed.
    const stats = page.getByRole('region', { name: 'Actions et rendez-vous du mois' });
    await expect(stats).toContainText('Actions');
    await expect(stats).toContainText('Rendez-vous pris');

    // 2. Kanban: drag it to « Proposition » (60 %).
    await page.goto('/crm/opportunities/kanban');
    await Promise.all([
        page.waitForResponse((r) => r.url().endsWith('/stage') && r.request().method() === 'POST'),
        drag(page, OPPORTUNITY, 'Proposition'),
    ]);
    await expect(page.getByRole('region', { name: 'Proposition', exact: true }).getByText(OPPORTUNITY)).toBeVisible();
    await page.reload();
    await expect(page.getByRole('region', { name: 'Proposition', exact: true }).getByText(OPPORTUNITY)).toBeVisible();

    // 3. « Perdu » asks for the reason: refused without it, accepted with it.
    await drag(page, OPPORTUNITY, 'Perdu');
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: "Changer d'étape" })).toBeVisible();
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(dialog.getByText('Le motif de perte est obligatoire.')).toBeVisible();
    // The card stays in « Proposition » (the open dialog hides the board from the accessibility tree).
    await expect(page.locator('section[aria-label="Proposition"]').getByText(OPPORTUNITY).first()).toBeVisible();
    await choose(page, dialog.getByLabel('Motif de perte *'), 'Prix');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByRole('region', { name: 'Perdu', exact: true }).getByText(OPPORTUNITY)).toBeVisible();
    await page.goto(opportunityUrl);
    await expect(page.getByText('Prix').first()).toBeVisible();

    // 4. Quick entry from the entreprise: a call done now, next call tomorrow.
    await page.goto(organisationUrl);
    await page.locator('header').getByRole('button', { name: 'Action', exact: true }).click();
    const quick = page.getByRole('dialog');
    await expect(quick.getByRole('heading', { name: 'Nouvelle action' })).toBeVisible();
    await expect(quick.getByRole('listitem').filter({ hasText: ENTREPRISE })).toContainText('Entreprise :');
    await quick.getByRole('group', { name: 'Type *' }).getByRole('button', { name: 'Appel', exact: true }).click();
    await quick.getByRole('button', { name: 'Demain' }).click();
    await choose(page, quick.getByLabel('Type', { exact: true }), 'Appel');
    // « Attribuer à » takes one or several people: the author is checked.
    await quick.getByLabel('Attribuer à').click();
    await expect(page.getByRole('menuitemcheckbox').first()).toBeVisible();
    await expect(page.getByRole('menuitemcheckbox', { checked: true })).toHaveCount(1);
    await page.keyboard.press('Escape');
    await quick.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(quick).toBeHidden();
    await page.getByRole('tab', { name: 'Historique' }).click();
    await expect(page.getByRole('tabpanel', { name: 'Historique' }).getByText(`Appel – ${ENTREPRISE}`).first()).toBeVisible();

    // 5. The follow-up in « À faire » (the list stops on Sunday evening) and in the planning of its week.
    const tomorrow = parisDay(1);
    const sameWeek = isoWeek(tomorrow) === isoWeek(parisDay());
    await page.goto('/crm/todo');
    const week = page.getByRole('region', { name: /^Cette semaine/ });
    const sections = page.getByRole('region', { name: /^(En retard|Aujourd'hui|Cette semaine)/ });
    await expect(sections).toHaveCount(3);
    if (sameWeek) {
        await expect(week.getByText(ENTREPRISE).first()).toBeVisible();
    } else {
        // Sunday: tomorrow is next week, so the follow-up is in no section of « À faire » (deterministic, not a skip).
        await expect(sections.getByText(ENTREPRISE)).toHaveCount(0);
    }
    await page.goto(`/crm/planning?week=${isoWeek(tomorrow)}`);
    await expect(page.getByRole('region', { name: new RegExp(`^\\S+ ${Number(tomorrow.slice(8))} `) }).getByText(ENTREPRISE)).toBeVisible();

    await context.close();
});

test('phone: meeting report on a fiche without a planned meeting, 375 px wide', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({
        storageState: ADMIN_STATE,
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris',
        viewport: { width: 375, height: 812 },
        isMobile: true,
        hasTouch: true,
    });
    const page = await context.newPage();

    // Reached from the menu (the sidebar is a sheet on the phone).
    await page.goto('/dashboard');
    await page.getByRole('button', { name: 'Toggle Sidebar' }).click();
    await page.getByRole('link', { name: 'Compte rendu de RDV' }).click();
    await expect(page).toHaveURL(/\/crm\/report$/);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);

    // Let the visit settle (the menu link is prefetched, then visited): text typed earlier was sometimes lost.
    await page.waitForLoadState('networkidle');
    await page.getByRole('searchbox', { name: 'Rechercher une entreprise ou une personne' }).fill('Traiteur des Alpes');
    await page.getByRole('button', { name: new RegExp(`^${ENTREPRISE}`) }).click({ timeout: 15_000 });
    await page.getByRole('button', { name: 'Réussi', exact: true }).click();
    await page.getByLabel('Compte rendu', { exact: true }).fill('Rencontre sur place : le chef veut une session en mars.');
    await page.getByRole('button', { name: 'Enregistrer le compte rendu' }).click();
    await expect(page.getByRole('status')).toHaveText('Compte rendu enregistré.');
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);

    // On the fiche, readable at this width.
    await page.goto('/crm/organisations?q=Traiteur');
    await page.getByRole('link', { name: ENTREPRISE }).first().click();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
    await page.getByRole('tab', { name: 'Historique' }).click();
    await expect(page.getByText('Rencontre sur place : le chef veut une session en mars.').first()).toBeVisible();

    await context.close();
});
