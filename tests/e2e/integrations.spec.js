import { test } from '@playwright/test';
import { signIn } from './helpers.js';

test('click the real open control', async ({ page, context }) => {
    test.setTimeout(90000);
    const log = [];
    page.on('console', (m) => log.push(`[${m.type()}] ${m.text().slice(0, 120)}`));
    page.on('pageerror', (e) => log.push(`[pageerror] ${e.message}`));

    await signIn(page);
    await page.goto('/app', { waitUntil: 'networkidle' });
    await page.goto('/admin/integrations', { waitUntil: 'networkidle' });

    const row = page.locator('li').filter({ hasText: 'Lidarr' }).first();
    await row.getByRole('button', { name: /Manage|Set up/ }).click();
    await page.waitForTimeout(1500);

    // Dump every anchor and button inside the modal.
    const inside = await page.evaluate(() => {
        const modal = document.querySelector('.fi-modal-window');
        if (!modal) return { modal: false };
        return {
            modal: true,
            anchors: [...modal.querySelectorAll('a')].map(a => ({ text: a.textContent.trim().slice(0,30), href: a.getAttribute('href'), target: a.getAttribute('target') })),
            buttons: [...modal.querySelectorAll('button')].map(b => b.textContent.trim().slice(0,30)),
        };
    });
    log.push('MODAL CONTENTS: ' + JSON.stringify(inside, null, 1));

    console.log('=== DIAG ===\n' + log.join('\n'));
});
