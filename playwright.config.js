import { defineConfig, devices } from '@playwright/test';

const PORT = 8111;

/**
 * Browser tests run against an isolated app: its own database, its own storage
 * root, and entirely generated media. `tests/e2e/bootstrap.sh` builds it and
 * refuses to run unless both paths are scratch paths, so a misconfiguration
 * stops rather than touching the real library.
 */
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    workers: 1,
    reporter: process.env.CI ? 'line' : [['list']],
    use: {
        baseURL: `http://127.0.0.1:${PORT}`,
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        { name: 'mobile', use: { ...devices['iPhone 13'] }, testMatch: /mobile\.spec\.js/ },
    ],
    webServer: {
        // --env=e2e is what keeps this off the real database.
        command: `php artisan --env=e2e serve --host=127.0.0.1 --port=${PORT}`,
        url: `http://127.0.0.1:${PORT}/login`,
        reuseExistingServer: !process.env.CI,
        timeout: 60_000,
    },
});
