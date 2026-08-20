import { test } from '@playwright/test';
import { signIn } from './helpers.js';
test('report survives teardown', async ({ page }) => {
    const posted = [];
    page.on('request', (r) => { if (r.url().includes('device-reports')) posted.push(r.method()); });

    await signIn(page);
    await page.goto('/app/music');
    await page.waitForFunction(() => window.soundchexLibrary !== undefined, null, { timeout: 20000 });

    // Recorded and then immediately navigated away from, which is the real case.
    await page.evaluate(() => window.soundchexDiagnostics.record('served-offline-page', { wanted: '/app/albums' }));
    await page.goto('/app');
    await page.waitForTimeout(1500);

    console.log('SENT: ' + JSON.stringify(posted));
});
