import { defineConfig, devices } from '@playwright/test';

// End-to-end tests run against the dedicated e2e stack (compose.e2e.yaml, port 8081),
// whose database is recreated by tests/e2e/prepare.sh before each run.
export default defineConfig({
    testDir: './tests/e2e',
    globalSetup: './tests/e2e/global-setup.ts',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list']],
    timeout: 90_000,
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8081',
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris',
        trace: 'retain-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
