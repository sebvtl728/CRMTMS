import { expect, test } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { generate } from 'otplib';

// The signed-in administrator session, reused by the following specs (crm.spec.ts).
export const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

// The administrator chooses this password through the activation link (test value, e2e database only).
const PASSWORD = 'e2e-Passphrase-2026!';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'admin@e2e.test';
const INVITED_EMAIL = 'commercial@e2e.test';

test('admin first run: activate, enrol 2FA, create SCOP company, invite user', async ({ page, baseURL }) => {
    const activationUrl = process.env.E2E_ACTIVATION_URL;
    expect(activationUrl, 'E2E_ACTIVATION_URL is set by global-setup').toBeTruthy();

    // 1. Activation link: choose a password.
    await page.goto(activationUrl!.replace(/^https?:\/\/[^/]+/, baseURL!));
    await expect(page.getByRole('heading', { name: 'Activation de votre compte' })).toBeVisible();
    await page.getByLabel('Mot de passe', { exact: true }).fill(PASSWORD);
    await page.getByLabel('Confirmer le mot de passe').fill(PASSWORD);
    await page.getByRole('button', { name: 'Activer mon compte' }).click();

    // 2. Login: an administrator without 2FA is sent to the enrolment page.
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByText('Votre compte est activé')).toBeVisible();
    await page.getByLabel('Adresse e-mail').fill(ADMIN_EMAIL);
    await page.getByLabel('Mot de passe', { exact: true }).fill(PASSWORD);
    await page.getByRole('button', { name: 'Se connecter' }).click();

    await expect(page).toHaveURL(/\/two-factor\/setup$/);
    const secret = (await page.locator('code').first().innerText()).replace(/\s+/g, '');
    expect(secret).toMatch(/^[A-Z2-7]{16,}$/);
    await page.getByLabel('Code de vérification').fill(await generate({ secret }));
    await page.getByRole('button', { name: 'Activer', exact: true }).click();

    await expect(page.getByText('Conservez ces codes de récupération')).toBeVisible();
    await page.getByRole('link', { name: 'Continuer' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    mkdirSync('tests/e2e/.auth', { recursive: true });
    await page.context().storageState({ path: ADMIN_STATE });

    // 3. The admin menu shows every administration entry.
    const nav = page.getByRole('navigation').or(page.locator('[data-sidebar="sidebar"]'));
    for (const entry of ['Accueil', 'Sociétés', 'Utilisateurs', 'Référentiels', 'Connecteurs', "Journal d'audit", 'Sécurité']) {
        await expect(nav.getByRole('link', { name: entry, exact: true }).first()).toBeVisible();
    }

    // 4. Create a SCOP company: the legal mention is built from the form.
    await page.goto('/admin/companies/create');
    await page.getByLabel('Raison sociale').fill('CAP FORMATION E2E');
    await page.locator('#legal_form').click();
    await page.getByRole('option', { name: 'SCOP', exact: true }).click();
    await page.locator('#scop_underlying_form').click();
    await page.getByRole('option', { name: 'SARL', exact: true }).click();
    await page.getByLabel('Capital variable').click();
    await page.getByLabel('SIRET').fill('73282932000074');
    await page.getByLabel('Ville du RCS').fill('Lyon');
    // Its own quote prefix: « DEV » is already the installer's placeholder company's (one company per prefix).
    await page.getByLabel('Préfixe de numérotation').fill('CF');
    await page.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(page).toHaveURL(/\/admin\/companies$/);
    await page.getByRole('row', { name: /CAP FORMATION E2E/ }).getByRole('link', { name: 'Modifier' }).click();
    await expect(page.getByText('SCOP SARL à capital variable').first()).toBeVisible();

    // 5. Invite a user: listed as pending.
    await page.goto('/admin/users/invite');
    await page.getByLabel('Nom complet').fill('Camille Commercial');
    await page.getByLabel('Adresse e-mail').fill(INVITED_EMAIL);
    await page.locator('#role').click();
    await page.getByRole('option', { name: 'Commercial', exact: true }).click();
    await page.getByRole('button', { name: "Envoyer l'invitation" }).click();

    await expect(page).toHaveURL(/\/admin\/users$/);
    // The e2e stack has no mail delivery (MAIL_MAILER=log): the link is shown to copy instead.
    await expect(page.getByText(`Invitation créée pour ${INVITED_EMAIL}, mais aucun e-mail n'a été envoyé`, { exact: false })).toBeVisible();
    await expect(page.getByLabel("Lien d'activation")).toHaveValue(/\/activation\/[0-9a-f]{128}$/);
    await expect(page.getByRole('row', { name: new RegExp(INVITED_EMAIL) })).toBeVisible();

    // 6. Security settings: change the idle timeout.
    await page.getByRole('link', { name: 'Sécurité', exact: true }).first().click();
    await expect(page).toHaveURL(/\/admin\/security$/);
    await page.getByLabel('Déconnexion après inactivité (minutes)').fill('30');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Paramètres de sécurité enregistrés.')).toBeVisible();

    // 7. The audit journal lists the invitation and the settings change.
    await page.getByRole('link', { name: "Journal d'audit", exact: true }).first().click();
    await expect(page).toHaveURL(/\/admin\/audit$/);
    const invitation = page.getByRole('row', { name: /Échec d'envoi de l'invitation/ });
    await expect(invitation).toBeVisible();
    await expect(invitation).toContainText(INVITED_EMAIL);
    await expect(invitation).toContainText('Admin E2E');
    await expect(page.getByRole('row', { name: /Paramètres de sécurité modifiés/ })).toBeVisible();
});
