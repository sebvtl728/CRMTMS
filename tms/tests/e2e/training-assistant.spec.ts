import { expect, test } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs after admin.spec.ts (same stack, one worker): reuses the administrator session it saved.
// The e2e stack answers with an offline AI (TMS_E2E_FAKE_AI, tests/Support/E2eAiClient): no network call.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';
const PROPOSED_TITLE = 'Accueil client en hôtellerie (proposition IA)';
const MY_TITLE = 'E2E Formation accueil (titre saisi)';

test('AI assistant: request → proposal → apply (filled fields kept unless checked) → save', async ({ browser }) => {
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({ storageState: ADMIN_STATE, locale: 'fr-FR', viewport: { width: 1400, height: 1000 } });
    const page = await context.newPage();
    const assistant = page.getByRole('region', { name: 'Assistant IA' });

    // 1. No AI connector yet: the assistant says where to configure it.
    await page.goto('/catalogue/create');
    await expect(assistant).toContainText('Configurez l’intelligence artificielle dans Administration → Connecteurs.');
    await expect(assistant.getByRole('button', { name: 'Remplir avec l’IA' })).toHaveCount(0);

    // 2. The administrator activates the connector (Anthropic, a test key: the e2e AI is offline).
    await page.goto('/admin/connectors/ai');
    await page.locator('#enabled').check();
    await page.locator('#secret-api_key').fill('sk-e2e-' + 'offline-test-key');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Configuration enregistrée.')).toBeVisible();

    // 3. A new training: the title and the prerequisites are already typed.
    await page.goto('/catalogue/create');
    await page.locator('#title').fill(MY_TITLE);
    await page.locator('#prerequisites').fill('Savoir lire le français.');

    await assistant.getByRole('button', { name: 'Remplir avec l’IA' }).click();
    await assistant.getByRole('radio', { name: 'Depuis une demande' }).click();
    await assistant.getByLabel('Votre demande').fill('Une formation de deux jours à l’accueil client pour des réceptionnistes d’hôtel.');
    await assistant.getByRole('button', { name: 'Proposer' }).click();

    // 4. The proposal, next to the current values: boxes checked for empty fields, unchecked for filled ones.
    const proposal = page.getByRole('region', { name: 'Proposition de l’IA' });
    await expect(proposal).toBeVisible();
    const title = proposal.getByRole('group', { name: 'Intitulé' });
    await expect(title).toContainText(MY_TITLE);
    await expect(title).toContainText(PROPOSED_TITLE);
    await expect(proposal.getByRole('checkbox', { name: 'Garder la proposition : Intitulé' })).not.toBeChecked();
    await expect(proposal.getByRole('checkbox', { name: 'Garder la proposition : Prérequis' })).not.toBeChecked();
    await expect(proposal.getByRole('checkbox', { name: 'Garder la proposition : Description courte' })).toBeChecked();
    await expect(proposal.getByRole('checkbox', { name: 'Garder la proposition : DAS' })).toBeChecked();
    await expect(proposal.getByRole('checkbox', { name: 'Garder la proposition : Objectifs pédagogiques' })).toBeChecked();
    await expect(proposal.getByRole('checkbox', { name: 'Garder la proposition : Module 1' })).toBeChecked();
    await expect(proposal.getByRole('group', { name: 'Module 1' })).toContainText('Les codes de l’accueil · 7 h · Présentiel');

    // The user keeps the proposed prerequisites (filled field, box checked), not the title; drops the methods (empty, box unchecked).
    await proposal.getByRole('checkbox', { name: 'Garder la proposition : Prérequis' }).click();
    await proposal.getByRole('checkbox', { name: 'Garder la proposition : Méthodes et moyens pédagogiques' }).click();
    await proposal.getByRole('button', { name: 'Appliquer' }).click();
    await expect(assistant.getByText('Proposition appliquée : relisez la fiche puis enregistrez.')).toBeVisible();

    // 5. The form: empty fields filled, the typed title untouched, the checked filled field replaced.
    await expect(page.locator('#title')).toHaveValue(MY_TITLE);
    await expect(page.locator('#prerequisites')).toHaveValue('Aucun prérequis.');
    await expect(page.locator('#description')).toHaveValue('Réussir l’accueil des clients à la réception d’un hôtel.');
    await expect(page.locator('#methods')).toHaveValue('');
    await expect(page.locator('#duration_hours')).toHaveValue('14');
    await expect(page.locator('#das_id')).toContainText('Hôtellerie-restauration');
    await expect(page.getByLabel('Objectif 1', { exact: true })).toHaveValue('Accueillir un client avec professionnalisme');
    await expect(page.locator('#module-title-0')).toHaveValue('Les codes de l’accueil');
    await expect(page.locator('#module-title-1')).toHaveValue('Gérer les réclamations');

    // 6. Nothing was saved by the AI: the usual « Enregistrer » saves the sheet.
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText('Formation créée.')).toBeVisible();
    await page.getByRole('row').filter({ hasText: MY_TITLE }).getByRole('link', { name: 'Modifier' }).click();
    await expect(page.locator('#title')).toHaveValue(MY_TITLE);
    await expect(page.locator('#prerequisites')).toHaveValue('Aucun prérequis.');
    await expect(page.locator('#module-title-0')).toHaveValue('Les codes de l’accueil');
    await expect(page.locator('#module-title-1')).toHaveValue('Gérer les réclamations');

    // 7. On the edit page, a filled sheet: nothing replaced without a checked box.
    await assistant.getByRole('button', { name: 'Remplir avec l’IA' }).click();
    await assistant.getByRole('radio', { name: 'Depuis une demande' }).click();
    await assistant.getByLabel('Votre demande').fill('Même formation.');
    await assistant.getByRole('button', { name: 'Proposer' }).click();
    await expect(proposal.getByRole('checkbox', { name: 'Garder la proposition : Module 1' })).not.toBeChecked();
    await expect(proposal.getByRole('checkbox', { name: 'Garder la proposition : Description courte' })).not.toBeChecked();
    await proposal.getByRole('button', { name: 'Appliquer' }).click();
    await expect(page.locator('#title')).toHaveValue(MY_TITLE);
    await expect(page.locator('#module-title-0')).toHaveValue('Les codes de l’accueil');
    await expect(page.locator('#methods')).toHaveValue('Mises en situation filmées.');

    await context.close();
});
