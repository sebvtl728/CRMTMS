import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';
const PASSWORD = 'e2e-Formateur-2026!'; // test value, e2e database only
const FORMATEUR_EMAIL = 'formateur@e2e.test';
const ADMIN_OPPORTUNITY = "E2E Opportunité de l'administrateur";
const OWN_OPPORTUNITY = 'E2E Opportunité du formateur';

async function adminPage(browser: import('@playwright/test').Browser, viewport = { width: 1400, height: 1000 }) {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({ storageState: ADMIN_STATE, locale: 'fr-FR', timezoneId: 'Europe/Paris', viewport });

    return { context, page: await context.newPage() };
}

/** A form post as the signed-in user (CSRF from the XSRF-TOKEN cookie); returns the final URL. */
async function send(context: BrowserContext, page: Page, url: string, data: Record<string, unknown>): Promise<string> {
    const xsrf = (await context.cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
    const response = await page.request.post(url, {
        data,
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
    });
    expect(response.ok(), `${url}: ${response.status()}`).toBe(true);

    return response.url();
}

/** Creates an opportunity from the « Opportunités » tab of an entreprise. */
async function createOpportunity(page: Page, organisationUrl: string, subject: string): Promise<void> {
    await page.goto(organisationUrl);
    await page.getByRole('tab', { name: 'Opportunités' }).click();
    await page.getByRole('link', { name: 'Nouvelle opportunité' }).click();
    await page.getByLabel('Objet *').fill(subject);
    await page.getByLabel('DAS *').click();
    await page.getByRole('option').first().click();
    await page.getByLabel('Étape *').click();
    await page.getByRole('option', { name: 'Nouveau', exact: true }).click();
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Opportunité créée.')).toBeVisible();
}

test('roles and rights: grant a right to a role, highlighted as changed, then back to the defaults', async ({ browser }) => {
    const { context, page } = await adminPage(browser);

    await page.goto('/dashboard');
    await page.getByRole('link', { name: 'Rôles et droits', exact: true }).first().click();
    await expect(page).toHaveURL(/\/admin\/roles$/);

    // The administrator's column and the locked rights cannot be changed.
    await expect(page.getByLabel("Exporter vers Excel — Administrateur de l'organisme")).toBeDisabled();
    await expect(page.getByLabel('Gérer les utilisateurs — Direction')).toBeDisabled();
    // The double authentification of the administrator and the direction cannot be switched off.
    await expect(page.getByRole('switch', { name: "Double authentification obligatoire — Administrateur de l'organisme" })).toHaveAttribute(
        'aria-disabled',
        'true',
    );
    await expect(page.getByRole('switch', { name: 'Double authentification obligatoire — Direction' })).toHaveAttribute('aria-disabled', 'true');

    const cell = page.getByLabel('Exporter vers Excel — Assistant');
    await expect(cell).not.toBeChecked();
    await cell.click();
    await expect(page.getByText('Droit « Exporter vers Excel » accordé au rôle Assistant.')).toBeVisible();
    await expect(cell).toBeChecked();
    await expect(page.locator('td[data-changed="true"]')).toHaveCount(1);

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Actions du rôle Assistant' }).click();
    await page.getByRole('menuitem', { name: 'Revenir aux droits par défaut' }).click();
    await expect(page.getByText('Droits par défaut rétablis pour le rôle Assistant.')).toBeVisible();
    await expect(cell).not.toBeChecked();
    await expect(page.locator('td[data-changed="true"]')).toHaveCount(0);

    await context.close();
});

test('custom role: created from Assistant, « Ses fiches » on the opportunities, deletion refused while held', async ({ browser }) => {
    const { context, page } = await adminPage(browser);

    // An opportunity of the administrator, which the new role must not see.
    const organisationUrl = await send(context, page, '/crm/organisations', { name: 'E2E Entreprise des rôles' });
    await createOpportunity(page, organisationUrl, ADMIN_OPPORTUNITY);

    // 1. « Formateur externe » (not the system role « Formateur »), starting from the rights of « Assistant ».
    await page.goto('/admin/roles');
    await page.getByRole('button', { name: 'Nouveau rôle' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Libellé').fill('Formateur externe');
    await dialog.getByLabel('Partir des droits de').click();
    await expect(page.getByRole('option', { name: 'Aucun droit' })).toBeVisible();
    await page.getByRole('option', { name: 'Assistant', exact: true }).click();
    await dialog.getByRole('checkbox', { name: 'Double authentification obligatoire' }).click(); // not needed for this test account
    await dialog.getByRole('button', { name: 'Créer le rôle' }).click();
    await expect(page.getByText('Rôle « Formateur externe » créé.')).toBeVisible();
    await expect(page.getByRole('columnheader', { name: /^Formateur externe/ })).toContainText('0 utilisateur');

    // 2. The same name is refused, with the server's message.
    await page.getByRole('button', { name: 'Nouveau rôle' }).click();
    await dialog.getByLabel('Libellé').fill('formateur EXTERNE');
    await dialog.getByRole('button', { name: 'Créer le rôle' }).click();
    await expect(dialog.getByRole('alert')).toHaveText('Un rôle porte déjà ce libellé.');
    await dialog.getByRole('button', { name: 'Annuler' }).click();

    // 3. « Consulter les opportunités » restricted to « Ses fiches ».
    const scope = page.getByLabel('Périmètre de Consulter les opportunités — Formateur externe');
    await expect(scope).toHaveText("Tout l'organisme");
    await scope.click();
    await page.getByRole('option', { name: 'Ses fiches', exact: true }).click();
    await expect(page.getByText('Périmètre de « Consulter les opportunités » pour le rôle Formateur externe : Ses fiches.')).toBeVisible();
    await expect(scope).toHaveText('Ses fiches');
    // A right that is not granted has no usable scope.
    await expect(page.getByLabel('Périmètre de Supprimer des opportunités — Formateur externe')).toBeDisabled();

    // 4. Invite a user with this role and sign him in.
    await page.goto('/admin/users/invite');
    await page.getByLabel('Nom complet').fill('Fanny Formateur');
    await page.getByLabel('Adresse e-mail').fill(FORMATEUR_EMAIL);
    await page.locator('#role').click();
    await page.getByRole('option', { name: 'Formateur externe', exact: true }).click();
    await page.getByRole('button', { name: "Envoyer l'invitation" }).click();
    const link = await page.getByLabel("Lien d'activation").inputValue();

    const guest = await browser.newContext({ locale: 'fr-FR', timezoneId: 'Europe/Paris', viewport: { width: 1400, height: 1000 } });
    const user = await guest.newPage();
    await user.goto(link.replace(/^https?:\/\/[^/]+/, new URL(page.url()).origin));
    await user.getByLabel('Mot de passe', { exact: true }).fill(PASSWORD);
    await user.getByLabel('Confirmer le mot de passe').fill(PASSWORD);
    await user.getByRole('button', { name: 'Activer mon compte' }).click();
    await user.getByLabel('Adresse e-mail').fill(FORMATEUR_EMAIL);
    await user.getByLabel('Mot de passe', { exact: true }).fill(PASSWORD);
    await user.getByRole('button', { name: 'Se connecter' }).click();
    await expect(user).toHaveURL(/\/dashboard$/);

    // 5. He sees his own opportunity only, not the administrator's.
    await createOpportunity(user, organisationUrl, OWN_OPPORTUNITY);
    await user.goto('/crm/opportunities');
    await expect(user.getByText(OWN_OPPORTUNITY).first()).toBeVisible();
    await expect(user.getByText(ADMIN_OPPORTUNITY)).toHaveCount(0);
    await guest.close();

    // 6. The role is held by a user: deletion refused, the server's message is shown.
    await page.goto('/admin/roles');
    await expect(page.getByRole('columnheader', { name: /^Formateur externe/ })).toContainText('1 utilisateur');
    page.once('dialog', (d) => d.accept());
    await page.getByRole('button', { name: 'Actions du rôle Formateur externe' }).click();
    await page.getByRole('menuitem', { name: 'Supprimer' }).click();
    await expect(page.getByRole('alert')).toContainText('Ce rôle est attribué à 1 utilisateur(s) ou invitation(s)');
    await expect(page.getByRole('columnheader', { name: /^Formateur externe/ })).toBeVisible();

    await context.close();
});

test('roles screen on a phone: only the matrix scrolls sideways', async ({ browser }) => {
    const { context, page } = await adminPage(browser, { width: 375, height: 800 });
    await page.goto('/admin/roles');
    await expect(page.getByRole('button', { name: 'Nouveau rôle' })).toBeVisible();

    const widths = await page.evaluate(() => ({ page: document.documentElement.scrollWidth, window: window.innerWidth }));
    expect(widths.page).toBeLessThanOrEqual(widths.window);

    await context.close();
});
