import { expect, test } from '@playwright/test';
import { ACCOUNT, signIn } from './helpers.js';

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

    test('a transfer can be cancelled and then cleared from the list', async ({ page }) => {
        // Cancelling is not pausing: pausing leaves the request approved and
        // the token live on the other machine.
        //
        // The source has to be a server that answers. An address that refuses
        // the connection leaves the transfer `failed`, and Cancel is
        // deliberately not offered then — a finished transfer has nothing
        // left to stop at the other end.
        //
        // 8198 is a stub server this suite runs, standing in for
        // the other machine: it returns a canned request id, which is all
        // asking needs. This server cannot ask itself — `artisan serve` is
        // single threaded, so the call would wait on the process already
        // serving the page it came from.
        const source = 'http://127.0.0.1:8198';

        await page.goto('/admin/server-transfer');

        await page.fill('input[wire\\:model="sourceUrl"]', source);
        await page.fill('input[type="password"]', ACCOUNT.password);
        await page.getByRole('button', { name: /^Ask/ }).first().click();

        // Scoped to this transfer's own row, by id rather than by address.
        // Transfers are not cleaned up between runs, so matching on the
        // address finds rows from earlier runs too — and those are `cancelled`
        // or `failed`, states that offer none of the buttons below.
        //
        // The list is newest first, so the row just created is the first one.
        // Reading its key pins the rest of the test to that id.
        const newest = page.locator('[wire\\:key^="transfer-"]').first();
        await expect(newest).toBeVisible({ timeout: 15000 });

        const key = await newest.getAttribute('wire:key');
        const row = page.locator(`[wire\\:key="${key}"]`);

        await expect(row).toContainText(source);
        await expect(row.getByText('requested')).toBeVisible();

        // Both confirm with a second click rather than a native dialog — the
        // first click only offers the second, which is what makes this
        // testable without handling a browser prompt.
        await row.getByRole('button', { name: 'Cancel', exact: true }).click();
        await row.getByRole('button', { name: 'Stop it on both machines' }).click();

        await expect(row.getByText(/cancelled/i)).toBeVisible({ timeout: 15000 });

        await row.getByRole('button', { name: 'Delete', exact: true }).click();
        await row.getByRole('button', { name: 'Remove it' }).click();

        // Gone from the list rather than merely marked, which is what
        // "clean it up" has to mean. This row, not every row that ever
        // mentioned the address.
        await expect(row).toHaveCount(0, { timeout: 15000 });
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
