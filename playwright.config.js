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
        {
            name: 'desktop',
            use: { ...devices['Desktop Chrome'] },
            // Upload tests add undecodable fixture files to the shared library,
            // and a playback test that picks one up fails with a media error
            // that looks like a player bug. Running them last, then tearing
            // down, keeps that contained.
            testIgnore: /(upload|embedded-shell)\.spec\.js/,
        },
        {
            // The app shell served on its own origin, standing in for
            // tauri://localhost. It needs a static server of its own, because
            // the whole point is that it works without the Laravel one.
            name: 'shell',
            use: { ...devices['Desktop Chrome'] },
            testMatch: /embedded-shell\.spec\.js/,
        },
        {
            name: 'uploads',
            use: { ...devices['Desktop Chrome'] },
            testMatch: /upload\.spec\.js/,
            teardown: 'reseed',
        },
        {
            name: 'reseed',
            testMatch: /reseed\.teardown\.js/,
        },
        {
            name: 'mobile',
            // Real WebKit — `devices['iPhone 13']` carries
            // `defaultBrowserType: 'webkit'`, which is the engine these tests
            // exist to cover.
            //
            // Service workers off. A worker controls this origin and answers
            // requests the page made, and those never reach `page.route()` or
            // even `context.route()` — so a test that aborts `/app/downloadable`
            // watched the listing succeed anyway and `requestfinished` fire for
            // a request its own handler had never seen. Nothing in this
            // project's specs tests the worker itself; `service-worker.spec.js`
            // runs on desktop, where it stays enabled.
            use: { ...devices['iPhone 13'], serviceWorkers: 'block' },
            testMatch: /(mobile|phone|phone-dl|download-queue|downloaded-filter|downloads-remove|downloads-batch|mobile-touch|connection-toast|download-logging|library-refresh|server-transfer|player-session|failover)\.spec\.js/,
        },
    ],
    // Two servers: the Laravel app, and a static one for the Tauri shell.
    // The shell's tests prove it works *without* the Laravel one, so it cannot
    // be served by it.
    webServer: [{
        // --env=e2e is what keeps this off the real database.
        command: `php artisan --env=e2e serve --host=127.0.0.1 --port=${PORT}`,
        url: `http://127.0.0.1:${PORT}/login`,
        reuseExistingServer: !process.env.CI,
        timeout: 60_000,
    }, {
        command: 'npx --yes http-server public/tauri -p 8199 --silent',
        url: 'http://127.0.0.1:8199/index.html',
        reuseExistingServer: !process.env.CI,
        timeout: 30_000,
    }, {
        // Stands in for the other machine in a transfer. See the file itself
        // for why this server cannot play that part.
        command: 'node tests/e2e/stub-source-server.js',
        url: 'http://127.0.0.1:8198/',
        reuseExistingServer: !process.env.CI,
        timeout: 30_000,
    }],
});
