import { expect, test, type APIRequestContext, type BrowserContext, type Locator, type Page } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

const TRAINING = 'E2E Secrétariat comptable';
const TEST = 'E2E Test tertiaire';
const CANDIDATE = { first_name: 'Nora', last_name: 'E2E Petit' };

async function send(context: BrowserContext, request: APIRequestContext, url: string, data: Record<string, unknown>): Promise<string> {
    const xsrf = (await context.cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
    const response = await request.post(url, {
        data,
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
    });
    expect(response.ok(), `${url}: ${response.status()}`).toBe(true);

    return response.url();
}

async function choose(page: Page, trigger: Locator, option: string): Promise<void> {
    await trigger.click();
    await page.getByRole('option', { name: option, exact: true }).click();
}

test('an online positioning test: written, published, passed on a computer, graded on the candidature', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({
        storageState: ADMIN_STATE,
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris',
        viewport: { width: 1400, height: 1000 },
    });
    const page = await context.newPage();

    // A training and a candidate for it.
    await page.goto('/catalogue');
    await page.getByRole('link', { name: 'Nouvelle formation' }).click();
    await page.getByLabel('Intitulé *').fill(TRAINING);
    await page.getByLabel('DAS *').click();
    await page.getByRole('option').first().click();
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Formation créée.')).toBeVisible();
    const personUrl = await send(context, page.request, '/crm/people', CANDIDATE);
    await page.goto(personUrl);
    await page.getByRole('tab', { name: 'Candidatures' }).click();
    await page.getByRole('link', { name: 'Ouvrir une candidature' }).click();
    await choose(page, page.getByLabel('Formation visée'), TRAINING);
    await page.getByRole('button', { name: 'Ouvrir la candidature' }).click();
    await expect(page.getByText('Candidature créée.')).toBeVisible();
    const candidatureUrl = page.url();

    // The online test: one true/false question worth 2 points, for that training; published.
    await page.goto('/admissions/online-tests');
    await page.getByLabel('Titre du nouveau test').fill(TEST);
    await page.getByRole('button', { name: 'Nouveau test' }).click();
    await expect(page.getByText('Test créé : ajoutez ses sections et ses questions.')).toBeVisible();
    await page.getByRole('checkbox', { name: TRAINING }).check();
    await page.getByRole('button', { name: 'Ajouter une section' }).click();
    const section = page.getByRole('region', { name: 'Section 1' });
    await section.getByPlaceholder('Ex. Mathématiques commerciales').fill('Mathématiques');
    await section.getByLabel('Type').selectOption('true_false');
    await section.getByLabel('Question *').fill('1 h 45 min = 1,75 h');
    await section.getByLabel('Points').fill('2');
    await section.getByLabel('Vrai', { exact: true }).check();
    await page.getByRole('button', { name: 'Publier' }).click();
    await expect(page.getByText('Test publié (version 1).')).toBeVisible();

    // The candidature offers it; the link leads to it.
    await page.goto(candidatureUrl);
    const positioning = page.getByRole('region', { name: 'Test de positionnement' });
    await expect(positioning.getByText(`En ligne : ${TEST}`).first()).toBeVisible();
    await positioning.getByRole('button', { name: 'Générer le lien' }).click();
    await expect(page.getByText('Lien du test généré : copiez-le et transmettez-le au candidat.')).toBeVisible();
    const link = await positioning.getByLabel(/Lien du candidat/).inputValue();

    // On a phone: refused.
    const phone = await browser.newContext({ viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, locale: 'fr-FR' });
    const phonePage = await phone.newPage();
    await phonePage.goto(link);
    await expect(phonePage.getByText('Ouvrez ce lien sur un ordinateur.')).toBeVisible();
    await phone.close();

    // On a computer: started, answered, sent.
    const computer = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fr-FR' });
    const candidate = await computer.newPage();
    await candidate.goto(link);
    await candidate.getByRole('button', { name: 'Commencer' }).click();
    await candidate.getByLabel('Vrai').check();
    candidate.once('dialog', (dialog) => void dialog.accept());
    await candidate.getByRole('button', { name: 'Envoyer mes réponses' }).click();
    await expect(candidate.getByText('Merci, vos réponses ont bien été envoyées.')).toBeVisible();
    await computer.close();

    // Back on the candidature: the score.
    await page.reload();
    await expect(positioning.getByText('2 / 2').first()).toBeVisible();

    await context.close();
});
