import { expect, test, type APIRequestContext, type BrowserContext, type Page } from '@playwright/test';
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

/** Props of the Inertia page currently shown. */
async function props(page: Page): Promise<Record<string, unknown>> {
    return page.evaluate(() => JSON.parse(document.getElementById('app')!.dataset.page!).props);
}

test('phone: meeting report in fewer than 6 interactions, then seen in the planning', async ({ browser }) => {
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

    // Data: an entreprise, its opportunity (« Nouveau ») and a meeting today at 8:00, to do.
    await page.goto('/crm/opportunities/create');
    const options = (await props(page)).options as { das: Option[]; stage: Option[] };
    const organisationUrl = await send(context, page.request, '/crm/organisations', { name: 'E2E Brasserie du Port' });
    const organisationId = organisationUrl.split('/').pop()!;
    const opportunityUrl = await send(context, page.request, '/crm/opportunities', {
        title: 'E2E Formation HACCP',
        organisation_id: organisationId,
        das_id: options.das[0].value,
        stage_id: options.stage.find((s) => s.label === 'Nouveau')!.value,
    });
    const choices = await (await page.request.get('/crm/activities/options', { headers: { Accept: 'application/json' } })).json();
    const meetingType = (choices.types as Option[]).find((t) => t.code === 'rendez-vous-physique')!;
    await send(context, page.request, '/crm/activities', {
        type_id: meetingType.value,
        organisation_id: organisationId,
        opportunity_id: opportunityUrl.split('/').pop(),
        done: false,
        due_at: `${today()}T08:00`,
        duration_minutes: 60,
        location: 'Quai des E2E',
    });

    // The planning, one day at a time on the phone: the meeting of today, with its « Compte rendu » button.
    await page.goto('/crm/planning');
    const day = page.getByRole('region', { name: 'Jour' });
    await expect(day.getByText('E2E Brasserie du Port')).toBeVisible();
    await expect(day.getByRole('link', { name: 'Compte rendu' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);

    // The report: the only meeting of the day is preselected.
    await page.goto('/crm/report');
    await expect(page.getByRole('button', { name: /E2E Brasserie du Port/ })).toHaveAttribute('aria-pressed', 'true');
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);

    let interactions = 0;
    const step = async (action: () => Promise<unknown>) => {
        interactions++;
        await action();
    };
    await step(() => page.getByRole('button', { name: 'Réussi', exact: true }).click());
    await step(() => page.getByLabel('Compte rendu', { exact: true }).fill('Besoin confirmé : 6 cuisiniers en février.'));
    await step(() => page.getByLabel('Étape').selectOption({ label: 'Proposition (60 %)' }));
    await step(() => page.getByRole('button', { name: 'Dans 1 semaine' }).click());
    await step(() => page.getByRole('button', { name: 'Enregistrer le compte rendu' }).click());

    await expect(page.getByRole('status')).toHaveText('Compte rendu enregistré, prochaine relance prévue.');
    expect(interactions).toBeLessThan(6);
    await expect(page.getByText("Aucun rendez-vous à compléter aujourd'hui.")).toBeVisible();

    // One request did it all: the opportunity is at « Proposition », the meeting is done, the follow-up planned.
    await page.goto(opportunityUrl);
    await expect(page.getByText('Proposition').first()).toBeVisible();
    await expect(page.getByText('Besoin confirmé : 6 cuisiniers en février.').first()).toBeVisible();
    await expect(page.getByText(/Rappel – E2E Brasserie du Port/).first()).toBeVisible();
    await page.goto('/crm/planning');
    await expect(day.getByText('Fait', { exact: true })).toBeVisible();

    await context.close();
});
