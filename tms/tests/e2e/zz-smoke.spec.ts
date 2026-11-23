import { expect, test, type Page } from '@playwright/test';
import { existsSync } from 'node:fs';

// Runs last (after the other specs filled the e2e organisme): the administrator opens every page of
// the menu, then the first record of each list and its edit page, and nothing may be broken there:
// no server error, no JavaScript error, no « undefined », « NaN » or « Invalid Date » on screen.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json';

// Lists whose first record (and its « Modifier » page) are opened too.
const DETAIL_LISTS = [
    '/crm/organisations',
    '/crm/people',
    '/crm/opportunities',
    '/crm/quotes',
    '/catalogue',
    '/sessions',
    '/dossiers',
    '/admissions/candidatures',
    '/finance/invoices',
    '/trainers',
];

const BROKEN_TEXT = /\b(undefined|NaN|Invalid Date|\[object Object\])\b/;

/** The page is loaded and its first requests are back; never waits forever on a page that polls. */
async function settle(page: Page): Promise<void> {
    await page.waitForLoadState('networkidle', { timeout: 5_000 }).catch(() => undefined);
}

async function check(page: Page, url: string, problems: string[]): Promise<void> {
    const errors: string[] = [];
    const onConsole = (m: { type(): string; text(): string }) => {
        if (m.type() === 'error') errors.push(m.text());
    };
    const onPageError = (e: Error) => errors.push(e.message);
    page.on('console', onConsole);
    page.on('pageerror', onPageError);
    const response = await page.goto(url);
    await settle(page);
    const status = response?.status() ?? 0;
    if (status >= 500) problems.push(`${url} : HTTP ${status}`);
    const text = await page.locator('body').innerText();
    const broken = text.match(BROKEN_TEXT);
    if (broken) problems.push(`${url} : texte cassé « ${broken[0]} »`);
    for (const error of errors) problems.push(`${url} : ${error.slice(0, 200)}`);
    page.off('console', onConsole);
    page.off('pageerror', onPageError);
}

test('every page of the menu and the first record of each list open without error', async ({ browser }) => {
    test.setTimeout(600_000);
    expect(existsSync(ADMIN_STATE), 'run admin.spec.ts first (it signs the administrator in)').toBe(true);
    const context = await browser.newContext({ storageState: ADMIN_STATE, locale: 'fr-FR', timezoneId: 'Europe/Paris' });
    const page = await context.newPage();
    const problems: string[] = [];

    await page.goto('/dashboard');
    const menu = await page
        .locator('[data-sidebar="menu-button"] a, a[data-sidebar="menu-button"]')
        .evaluateAll((links) => links.map((a) => (a as HTMLAnchorElement).getAttribute('href') ?? ''));
    const pages = [...new Set(menu.filter((href) => href.startsWith('/') && !href.startsWith('/logout')))];
    expect(pages.length).toBeGreaterThan(20);

    for (const url of pages) {
        await check(page, url, problems);
    }

    for (const list of DETAIL_LISTS) {
        await page.goto(list);
        await settle(page);
        const first = await page
            .locator('main table tbody tr a[href^="/"], main ul li a[href^="/"]')
            .evaluateAll((links, base) => links.map((a) => (a as HTMLAnchorElement).getAttribute('href') ?? '').find((h) => h.startsWith(base + '/') && !/\/(create|new|export)/.test(h)), list);
        if (!first) continue;
        await check(page, first, problems);
        const editLink = page.getByRole('link', { name: 'Modifier', exact: true });
        const edit = (await editLink.count()) > 0 ? await editLink.first().getAttribute('href') : null;
        if (edit && edit.startsWith('/')) await check(page, edit, problems);
    }

    expect(problems, problems.join('\n')).toEqual([]);
    await context.close();
});
