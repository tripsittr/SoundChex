import { test } from '@playwright/test';
import { execSync } from 'node:child_process';

/**
 * Rebuilds the fixture library after the upload tests.
 *
 * Those tests add real rows and real files — tiny fakes with no decodable
 * audio. Left behind, the next run's playback tests pick one up and fail with
 * a media error that reads like a player bug rather than test pollution.
 */
test('restore the seeded library', () => {
    execSync('bash tests/e2e/bootstrap.sh', { stdio: 'ignore' });
});
