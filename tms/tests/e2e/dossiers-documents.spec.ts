import { expect, test } from '@playwright/test';
import { existsSync } from 'node:fs';

// Named to run after admin.spec.ts and dossier.spec.ts (files run in alphabetical order) (same stack, one worker): reuses the administrator
// session and the dossier of « E2E Imprimerie des Alpes » (company contractor, an intra session).
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';
const CLIENT = 'E2E Imprimerie des Alpes';

test('convention: issued from the dossier, previewed, sent, then cancelled with a reason', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({ storageState: ADMIN_STATE, locale: 'fr-FR', timezoneId: 'Europe/Paris', viewport: { width: 1400, height: 1000 } });
    const page = await context.newPage();

    // The organisme's own text of an article.
    await page.goto('/admin/document-texts');
    await expect(page.getByRole('heading', { name: 'Modèles de documents' })).toBeVisible();
    const litiges = page.getByLabel('Litiges', { exact: true });
    await litiges.fill('Tribunal de commerce de Grenoble pour {contractant}.');
    await litiges.locator('xpath=ancestor::form').getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Texte enregistré.')).toBeVisible();

    // The dossier of the client.
    await page.goto('/dossiers');
    await page.getByRole('row', { name: new RegExp(CLIENT) }).getByRole('link', { name: /^D-\d{4}-\d{4}$/ }).click();
    await expect(page.getByRole('heading', { name: /^Dossier / })).toBeVisible();

    await page.getByRole('button', { name: 'Émettre : convention de formation' }).click();
    await expect(page.getByText(/Convention de formation DOC-\d{4}-\d{5} émise\./)).toBeVisible();
    await expect(page.getByText('Émis', { exact: true })).toBeVisible();

    // « Aperçu » opens the archived PDF inline in a new tab (headless Chromium downloads it instead: checked by a request).
    const previewLink = page.getByRole('link', { name: 'Aperçu' });
    await expect(previewLink).toHaveAttribute('target', '_blank');
    const href = (await previewLink.getAttribute('href')) ?? '';
    expect(href).toMatch(/^\/documents\/[0-9a-f-]+\/pdf\?apercu=1$/);
    const pdf = await page.request.get(href);
    expect(pdf.headers()['content-type']).toBe('application/pdf');
    expect(pdf.headers()['content-disposition']).toMatch(/^inline; filename="DOC-\d{4}-\d{5}\.pdf"$/);
    expect((await pdf.body()).subarray(0, 5).toString()).toBe('%PDF-');

    await page.getByRole('button', { name: 'Envoyer', exact: true }).click();
    const mail = page.getByRole('dialog');
    await mail.getByLabel('Destinataire').fill('rh@imprimerie-des-alpes.test');
    await mail.getByRole('button', { name: 'Envoyer', exact: true }).click();
    await expect(page.getByText(/Envoi de DOC-\d{4}-\d{5} en cours\./)).toBeVisible();
    await page.reload();
    await expect(page.getByText(/envoyé le/)).toBeVisible();

    await page.getByRole('button', { name: 'Annuler', exact: true }).click();
    const cancel = page.getByRole('dialog');
    await cancel.getByLabel('Motif de l’annulation').fill('Mauvais nombre de stagiaires');
    await cancel.getByRole('button', { name: 'Annuler le document' }).click();
    await expect(page.getByText('Motif de l’annulation : Mauvais nombre de stagiaires')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Émettre : convention de formation' })).toBeVisible();

    // The convocations of the session, for every trainee at once.
    const sessionHref = (await page.getByRole('link', { name: 'Voir la session' }).first().getAttribute('href')) ?? '';
    await page.goto(`${sessionHref}?etape=stagiaires`);
    const documents = page.getByRole('region', { name: 'Documents' });
    await expect(documents).toBeVisible();
    await documents.getByRole('button', { name: 'Émettre pour tous' }).first().click();
    await expect(page.getByText('2 documents émis.')).toBeVisible();
    await expect(documents.getByText(/DOC-\d{4}-\d{5}/)).toHaveCount(2);
    await documents.getByRole('button', { name: 'Envoyer à tous' }).first().click();
    await expect(page.getByText(/envoi(s)? en cours|sans adresse e-mail|Aucun envoi/)).toBeVisible();

    await context.close();
});
