import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';

// Named to run after admin.spec.ts and dossier.spec.ts (files run in alphabetical order) (same stack, one worker): reuses the administrator
// session and the dossier of « E2E Imprimerie des Alpes » (company contractor, an intra session).
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';
const CLIENT = 'E2E Imprimerie des Alpes';

/** The last match of $pattern in the e-mails of the e2e stack (MAIL_MAILER=log; quoted-printable soft breaks removed). */
function lastInMails(pattern: RegExp): string {
    const log = execFileSync('docker', ['compose', '-f', 'compose.yaml', '-f', 'compose.e2e.yaml', 'exec', '-T', 'app-e2e', 'cat', 'storage/logs/laravel.log'], {
        encoding: 'utf8',
        maxBuffer: 64 * 1024 * 1024,
    }).replace(/=\r?\n/g, '');
    const all = [...log.matchAll(new RegExp(pattern.source, 'g'))];
    expect(all.length, `nothing like ${pattern} in the e-mails`).toBeGreaterThan(0);

    return all[all.length - 1][1];
}

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

    // Online signature: the contact receives a link, asks for a code, signs.
    await page.getByRole('button', { name: 'Demander la signature' }).click();
    const sign = page.getByRole('dialog');
    await sign.getByLabel('Adresse du signataire').fill('rh@imprimerie-des-alpes.test');
    await sign.getByRole('button', { name: 'Envoyer le lien' }).click();
    await expect(page.getByText(/Lien de signature envoyé pour DOC-/)).toBeVisible();
    const token = lastInMails(/\/signature\/([A-Za-z0-9]{40})/);
    const signer = await browser.newContext({ locale: 'fr-FR', timezoneId: 'Europe/Paris' });
    const signerPage = await signer.newPage();
    await signerPage.goto(`/signature/${token}`);
    await signerPage.getByLabel("J'ai lu le document et j'accepte de le signer électroniquement.").check();
    await signerPage.getByRole('button', { name: 'Recevoir mon code par e-mail' }).click();
    await expect(signerPage.getByText(/Code envoyé à/)).toBeVisible();
    await signerPage.getByLabel('Code à 6 chiffres').fill(lastInMails(/code de signature[^0-9]*(\d{6})/i));
    await signerPage.getByRole('button', { name: 'Signer' }).click();
    await expect(signerPage.getByText(/Document signé le/)).toBeVisible();
    await signer.close();
    await page.reload();
    await expect(page.getByText(/Signé en ligne le/)).toBeVisible();
    await expect(page.getByRole('link', { name: 'PDF signé' })).toBeVisible();

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
