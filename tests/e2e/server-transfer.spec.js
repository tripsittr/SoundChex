import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * Moving a library between machines.
 *
 * The approval is the security boundary — not the password on the page, and
 * not the token. Nothing can be read from a server until a person there says
 * so, which is what these check.
 */
test.describe('server transfer', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
    });

    test('the page offers what to bring across', async ({ page }) => {
        await page.goto('/admin/server-transfer');

        await expect(page.getByText('The catalogue').first()).toBeVisible();
        await expect(page.getByText('The media files').first()).toBeVisible();
        await expect(page.getByText('Profiles and history').first()).toBeVisible();
    });

    test('a request from another server appears with its code', async ({ page }) => {
        // The code is how someone knows the request in front of them is the
        // one that was just started, rather than another arriving at the same
        // moment.
        const request = await page.evaluate(async () => {
            const response = await fetch('/api/v1/transfer/requests', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    device_name: 'Windows Box',
                    platform: 'Windows 11',
                    wants: ['metadata', 'files'],
                }),
            });

            return response.json();
        });

        await page.goto('/admin/server-transfer');

        await expect(page.getByText('Windows Box').first()).toBeVisible();
        await expect(page.getByText(request.code).first()).toBeVisible();
        await expect(page.getByText(/the catalogue, the media files/i).first()).toBeVisible();
    });

    test('approving needs the password', async ({ page }) => {
        await page.evaluate(() => fetch('/api/v1/transfer/requests', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ device_name: 'Windows Box', wants: ['metadata'] }),
        }));

        await page.goto('/admin/server-transfer');

        // Approve without typing one.
        await page.getByRole('button', { name: 'Approve' }).first().click();
        await page.waitForTimeout(1200);

        await expect(page.getByText(/password is not right/i).first()).toBeVisible();

        // And it is still pending, not approved.
        await expect(page.getByRole('button', { name: 'Approve' }).first()).toBeVisible();
    });
});
