import { expect, test } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

const pdf = (marker: string) => Buffer.from(`%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n% ${marker}\n%%EOF\n`);

test('brochure library: deposit, rename, replace the file (new version), download', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({ storageState: ADMIN_STATE, locale: 'fr-FR', viewport: { width: 1400, height: 1000 } });
    const page = await context.newPage();

    // 1. Deposit.
    await page.goto('/catalogue/brochures/create');
    await page.locator('#title').fill('E2E Plaquette hygiène');
    await page.locator('#file').setInputFiles({ name: 'hygiene-v1.pdf', mimeType: 'application/pdf', buffer: pdf('v1') });
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Plaquette ajoutée.')).toBeVisible();
    const row = page.getByRole('row').filter({ hasText: 'E2E Plaquette hygiène' });
    await expect(row).toContainText('hygiene-v1.pdf');

    // 2. A file that is not a PDF is refused on screen.
    await page.goto('/catalogue/brochures/create');
    await page.locator('#title').fill('E2E Piège');
    await page.locator('#file').setInputFiles({ name: 'piege.pdf', mimeType: 'application/pdf', buffer: Buffer.from('MZ not a pdf') });
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('La plaquette doit être un fichier PDF.')).toBeVisible();

    // 3. Edit the title only (the update is a spoofed PUT).
    await page.goto('/catalogue/brochures');
    await row.getByRole('link', { name: 'Modifier' }).click();
    await page.locator('#title').fill('E2E Plaquette hygiène 2027');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Plaquette enregistrée.')).toBeVisible();
    const renamed = page.getByRole('row').filter({ hasText: 'E2E Plaquette hygiène 2027' });
    await expect(renamed).toContainText('hygiene-v1.pdf');

    // 4. Replace the file: a second version, both listed.
    await renamed.getByRole('link', { name: 'Modifier' }).click();
    await page.locator('#file').setInputFiles({ name: 'hygiene-v2.pdf', mimeType: 'application/pdf', buffer: pdf('v2') });
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Plaquette enregistrée.')).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: 'E2E Plaquette hygiène 2027' })).toContainText('hygiene-v2.pdf');
    await page.getByRole('row').filter({ hasText: 'E2E Plaquette hygiène 2027' }).getByRole('link', { name: 'Modifier' }).click();
    const versions = page.getByRole('region', { name: 'Versions' });
    await expect(versions.getByRole('link')).toHaveCount(2);
    await expect(versions).toContainText('hygiene-v1.pdf');
    await expect(versions).toContainText('version actuelle');

    // 5. Downloads: the current file and the previous version, under their original names.
    const old = await context.request.get(await versions.getByRole('link', { name: 'hygiene-v1.pdf' }).getAttribute('href') as string);
    expect(old.status()).toBe(200);
    expect(old.headers()['content-disposition']).toContain('hygiene-v1.pdf');
    expect((await old.body()).toString()).toContain('% v1');

    await page.goto('/catalogue/brochures');
    const download = page.waitForEvent('download');
    await page.getByRole('row').filter({ hasText: 'E2E Plaquette hygiène 2027' }).getByRole('link', { name: 'Télécharger' }).click();
    const file = await download;
    expect(file.suggestedFilename()).toBe('hygiene-v2.pdf');
    await context.close();
});
