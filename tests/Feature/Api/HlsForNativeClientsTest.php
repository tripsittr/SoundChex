<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature\Api;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Http\Controllers\HlsController;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Streaming\HlsSegmenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Serving a transcoded stream to a client that authenticates with a token.
 *
 * The bug this closes: `AVURLAssetHTTPHeaderFieldsKey` applies to the
 * **playlist** request, and AVFoundation does not propagate those headers to
 * the `.ts` segment fetches. So a segment arrived with no credentials at all,
 * the web auth middleware answered **302 → /login**, AVPlayer followed the
 * redirect and decoded HTML as video.
 *
 * The visible result was a black picture with a running clock and a correct
 * duration — because the playlist *had* carried the header and supplied it.
 *
 * The fix is a segment URL that carries its own credential. A signature is the
 * right shape: unguessable, scoped to one session and file, and expiring. The
 * session id cannot do that job — it is a deterministic hash of (item, height,
 * start), so anyone who knows the item can compute it.
 */
class HlsForNativeClientsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('phone')->plainTextToken;
    }

    /** The headers a native player sends with the *playlist* request. */
    private function playerHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    private function film(): MediaItem
    {
        return MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => 'A Film',
            'type' => MediaItemType::Movie,
            'file_path' => '/nonexistent/a.mkv',
            'processing_status' => ProcessingStatus::Complete,
        ])->fresh();
    }

    /* ------------------------------------------------------- the route --- */

    /**
     * A segment fetched with **no credentials whatsoever**, which is exactly
     * what AVFoundation does, must not be bounced to a login page.
     *
     * 404 is the right answer for a signature that is absent or wrong: it
     * neither serves the file nor tells an unauthenticated caller that the
     * session exists.
     */
    public function test_an_unsigned_segment_is_refused_without_a_redirect(): void
    {
        $response = $this->get('/api/v1/hls/'.str_repeat('a', 32).'/seg000.ts');

        $this->assertNotSame(
            302,
            $response->getStatusCode(),
            'A redirect is what broke this: AVPlayer follows it and decodes the login page as video.',
        );

        $response->assertForbidden();
    }

    /**
     * A tampered signature is refused. Without this the "signature" would be
     * decoration — the whole point is that it cannot be forged.
     */
    public function test_a_tampered_signature_is_refused(): void
    {
        $url = URL::temporarySignedRoute(
            'api.hls.segment',
            now()->addHour(),
            ['session' => str_repeat('a', 32), 'file' => 'seg000.ts'],
        );

        // Ask for a different segment than the one that was signed.
        $tampered = str_replace('seg000.ts', 'seg001.ts', $url);

        $this->get(parse_url($tampered, PHP_URL_PATH).'?'.parse_url($tampered, PHP_URL_QUERY))
            ->assertForbidden();
    }

    /**
     * An expired link stops working, so a URL that leaks into a log or a
     * crash report is not useful indefinitely.
     */
    public function test_an_expired_signature_is_refused(): void
    {
        $url = URL::temporarySignedRoute(
            'api.hls.segment',
            now()->subMinute(),
            ['session' => str_repeat('a', 32), 'file' => 'seg000.ts'],
        );

        $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))
            ->assertForbidden();
    }

    /**
     * A correctly signed request reaches the controller.
     *
     * 404 rather than 200 because no encode exists in a test — but it is the
     * *controller's* 404 for a missing file, not the middleware's refusal,
     * which is the distinction that matters: the signature was accepted.
     */
    public function test_a_valid_signature_reaches_the_controller(): void
    {
        $url = URL::temporarySignedRoute(
            'api.hls.segment',
            now()->addHour(),
            ['session' => str_repeat('a', 32), 'file' => 'seg000.ts'],
        );

        $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))
            ->assertNotFound();
    }

    /* ------------------------------------------------------ the answer --- */

    /**
     * The playback decision must point a token client at the **API** playlist.
     *
     * The web one is behind session auth and so are its segments, so handing
     * it over is handing over a URL the player cannot use.
     */
    public function test_an_unplayable_file_is_sent_to_the_api_playlist(): void
    {
        $film = $this->film();

        $url = (string) $this->withHeaders($this->playerHeaders())
            ->getJson("/api/v1/items/{$film->id}/playback")
            ->json('url');

        $this->assertStringContainsString('/api/v1/items/', $url);
        $this->assertStringNotContainsString('/app/item/', $url);
    }

    /**
     * The playlist route itself needs a token, so the stream is not public.
     */
    public function test_the_playlist_requires_authentication(): void
    {
        $film = $this->film();

        $this->getJson("/api/v1/items/{$film->id}/hls.m3u8")->assertUnauthorized();
    }

    /**
     * The segments named in an API playlist must be signed.
     *
     * Asserted at the rewriting step rather than by running ffmpeg: starting
     * a real encode in a test means a real video file and a real transcode,
     * which tests the machine. What has to be right here is which URL shape
     * is written into the playlist.
     */
    public function test_an_api_playlist_names_signed_segments(): void
    {
        $controller = app(HlsController::class);

        $method = new \ReflectionMethod($controller, 'signsSegments');

        $apiRequest = Request::create('/api/v1/items/1/hls.m3u8');
        $webRequest = Request::create('/app/item/1/hls.m3u8');

        $this->assertTrue(
            $method->invoke($controller, $apiRequest),
            'A token client needs segment URLs that carry their own credential.',
        );

        $this->assertFalse(
            $method->invoke($controller, $webRequest),
            'A browser sends its cookie on every segment, so signing would be noise.',
        );
    }

    /**
     * The session id is not a secret and must never be treated as one.
     *
     * It is a deterministic hash of (item, updated_at, height, start), so
     * anyone who knows the item can compute it. This is written down as a
     * test because the tempting shortcut — "the session id is random enough,
     * skip the signature" — is wrong in a way that is invisible by reading.
     */
    public function test_the_session_id_is_derivable_and_so_cannot_be_a_credential(): void
    {
        $film = $this->film();

        $segmenter = app(HlsSegmenter::class);
        $method = new \ReflectionMethod($segmenter, 'sessionId');

        $first = $method->invoke($segmenter, $film, 1080, 0.0);
        $second = $method->invoke($segmenter, $film, 1080, 0.0);

        $this->assertSame($first, $second, 'The id is stable, so it is guessable.');
    }
}
