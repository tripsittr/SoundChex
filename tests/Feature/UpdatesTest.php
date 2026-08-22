<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where the desktop apps look for a new version.
 *
 * Served by the library itself: a self-hosted application has no reason to ask
 * a third party whether it has an update. Unauthenticated, because the updater
 * runs before anyone signs in — the signature is what makes a served bundle
 * trustworthy, not the request being authorised.
 */
class UpdatesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $directory = storage_path('app/updates/darwin/aarch64');

        foreach (glob($directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function publish(string $version, string $file = 'SoundChex.app.tar.gz'): string
    {
        $directory = storage_path('app/updates/darwin/aarch64');

        @mkdir($directory, 0755, true);
        file_put_contents($directory . '/' . $file, 'bundle');
        file_put_contents($directory . '/' . $file . '.sig', 'a-signature');
        file_put_contents($directory . '/latest.json', json_encode([
            'version' => $version,
            'notes' => 'Something changed.',
            'pub_date' => now()->toIso8601String(),
            'file' => $file,
        ]));

        return $directory;
    }

    public function test_no_release_means_up_to_date(): void
    {
        // 204, not 404: a 404 reads as a broken endpoint and gets retried.
        $this->get('/api/v1/updates/darwin/aarch64/0.1.0')->assertNoContent();
    }

    public function test_an_older_release_is_not_offered(): void
    {
        $this->publish('0.1.0');

        $this->get('/api/v1/updates/darwin/aarch64/0.2.0')->assertNoContent();
    }

    public function test_the_same_version_is_not_offered(): void
    {
        $this->publish('0.1.0');

        $this->get('/api/v1/updates/darwin/aarch64/0.1.0')->assertNoContent();
    }

    public function test_a_newer_release_is_offered_with_its_signature(): void
    {
        $this->publish('0.2.0');

        $this->get('/api/v1/updates/darwin/aarch64/0.1.0')
            ->assertOk()
            ->assertJsonPath('version', '0.2.0')
            // Without this the client refuses the update, which is the point:
            // the endpoint is reachable by anything on the tailnet.
            ->assertJsonPath('signature', 'a-signature')
            ->assertJsonStructure(['version', 'notes', 'pub_date', 'url', 'signature']);
    }

    public function test_a_path_cannot_climb_out_of_the_updates_directory(): void
    {
        // The segments come from the URL. Refused either way — the router
        // rejects encoded slashes outright, and anything reaching the
        // controller has the offending characters stripped — so this asserts
        // that no release is served rather than which layer said no.
        $response = $this->get('/api/v1/updates/..%2F..%2F..%2Fetc/aarch64/0.1.0');

        // Refused either way: the router rejects encoded slashes outright, and
        // anything reaching the controller has the offending characters
        // stripped. What matters is that no release is described.
        $this->assertContains($response->status(), [204, 404]);
        $this->assertStringNotContainsString('signature', (string) $response->getContent());
    }

    public function test_a_traversing_segment_is_stripped_rather_than_followed(): void
    {
        $this->publish('0.2.0');

        // "darwin.." is sanitised to "darwin" rather than resolving to its
        // parent, so this serves the legitimate release instead of climbing
        // out of the directory.
        $this->get('/api/v1/updates/darwin../aarch64/0.1.0')
            ->assertOk()
            ->assertJsonPath('version', '0.2.0');
    }

    public function test_a_download_cannot_escape_its_directory(): void
    {
        $this->publish('0.2.0');

        // Called directly rather than through the router, which rejects
        // encoded slashes on its own — so a route test passes whether or not
        // the controller defends itself, and this file is the one thing here a
        // client executes.
        // A real file one level up, so the guard is what refuses it rather
        // than the target happening not to exist.
        $secret = storage_path('app/updates/darwin/secret.txt');
        file_put_contents($secret, 'not for clients');

        try {
            $controller = new \App\Http\Controllers\Api\UpdateController();

            $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

            $controller->download('darwin', 'aarch64', '../secret.txt');
        } finally {
            @unlink($secret);
        }
    }

    public function test_downloading_something_that_is_not_there_is_a_404(): void
    {
        $this->get('/api/v1/updates/darwin/aarch64/download/nothing.tar.gz')->assertNotFound();
    }
}
