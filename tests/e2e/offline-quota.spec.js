import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

/**
 * A write that runs out of room must say so.
 *
 * This is the bug the rebuild exists for (S-107). On the device, IndexedDB is
 * capped near 1 GB, and the failure a full store throws — `QuotaExceededError`
 * — has to reach the user as "this did not fit", not vanish. A download that
 * silently fails looks to the app exactly like one that succeeded: the button
 * settles, and the file is not there.
 *
 * The test drives the same object store the downloads module writes to
 * (`soundchex-downloads` / `blobs`), so it exercises the real store rather
 * than a stand-in. WebKit will not reproduce iOS's exact byte ceiling, but it
 * reproduces the contract: a `put` of blobs past the quota rejects the
 * transaction, and the question the rebuild answers is whether that rejection
 * becomes a reported failure or an unhandled one nobody sees.
 *
 * Runs under `mobile-offline` (WebKit) and `desktop` (Chromium). It may pass
 * on Chromium, whose quota is large, and reproduce the ceiling on WebKit —
 * which is exactly the cross-engine signal Step 0 is buying.
 */
test.describe('a store that runs out of room reports it', () => {
    test('writing blobs past the quota rejects rather than resolving silently', async ({ page }) => {
        await signIn(page);
        await page.goto('/app/music');

        const outcome = await page.evaluate(async () => {
            const DB_NAME = 'soundchex-downloads';
            const STORE = 'blobs';

            function open() {
                return new Promise((resolve, reject) => {
                    const req = indexedDB.open(DB_NAME);
                    req.onupgradeneeded = () => {
                        const db = req.result;
                        if (!db.objectStoreNames.contains(STORE)) {
                            db.createObjectStore(STORE);
                        }
                    };
                    req.onsuccess = () => resolve(req.result);
                    req.onerror = () => reject(req.error);
                });
            }

            function put(db, key, value) {
                return new Promise((resolve, reject) => {
                    const tx = db.transaction(STORE, 'readwrite');
                    tx.objectStore(STORE).put(value, key);
                    tx.oncomplete = () => resolve();
                    // The transaction, not just the request: a quota failure
                    // aborts the transaction, and that is the signal a real
                    // download write has to notice.
                    tx.onabort = () => reject(tx.error ?? new Error('aborted'));
                    tx.onerror = () => reject(tx.error ?? new Error('error'));
                });
            }

            let db;
            try {
                db = await open();
            } catch (error) {
                return { reachable: false, message: String(error) };
            }

            // 8 MiB per blob, up to ~4 GiB total — past any browser quota.
            const chunk = new Blob([new Uint8Array(8 * 1024 * 1024)], { type: 'audio/mpeg' });
            let wrote = 0;

            try {
                for (let i = 0; i < 512; i++) {
                    await put(db, `quota-probe-${i}`, chunk);
                    wrote++;
                }

                return { reachable: true, rejected: false, wrote };
            } catch (error) {
                return {
                    reachable: true,
                    rejected: true,
                    wrote,
                    name: error?.name ?? '',
                    message: String(error?.message ?? error).slice(0, 120),
                };
            } finally {
                // Leave the store as we found it.
                try {
                    await new Promise((resolve) => {
                        const tx = db.transaction(STORE, 'readwrite');
                        const store = tx.objectStore(STORE);
                        for (let i = 0; i < wrote + 1; i++) store.delete(`quota-probe-${i}`);
                        tx.oncomplete = resolve;
                        tx.onabort = resolve;
                    });
                } catch {
                    // Best effort cleanup.
                }
                db.close();
            }
        });

        test.skip(outcome.reachable === false, 'IndexedDB not reachable: ' + outcome.message);

        // The write must fail — a quota is finite. If 512 eight-MiB blobs
        // (4 GiB) all "succeeded", the engine is not enforcing a ceiling here
        // and the premise does not hold on this engine; on WebKit it should.
        expect(
            outcome.rejected,
            `wrote ${outcome.wrote} blobs (~${outcome.wrote * 8} MiB) with no rejection — no quota was enforced`,
        ).toBe(true);

        // And the failure names the quota, so a caller can tell "did not fit"
        // from a network blip or a bug.
        expect(outcome.name, `rejected with ${outcome.name}: ${outcome.message}`).toMatch(/Quota/i);
    });
});
