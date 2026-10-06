<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Metadata\Sources\Music\Spotify;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A withdrawn endpoint is not a failure worth reporting.
 *
 * Spotify removed `/v1/audio-features` in November 2024 for any app registered
 * after that date: it answers **403 with an empty body**, whatever the
 * credentials. Verified against this install's own working app — the token
 * request and `/v1/search` both return 200, and audio-features returns 403 for a
 * track id that search had just handed back.
 *
 * The user saw the consequence on every music item in the review queue:
 *
 *     No match, but these sources errored: Spotify
 *
 * …on a source that had otherwise worked, for data that is cosmetic. bpm,
 * energy, key and scale name no recording and decide where no file goes. A
 * review queue that cries wolf about an optional extra teaches people to ignore
 * it, which is the opposite of what the queue is for.
 */
class SpotifyAudioFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private MediaItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(SettingsService::class);
        $settings->set('spotify.client_id', 'id', encrypt: true);
        $settings->set('spotify.client_secret', 'secret', encrypt: true);

        $user = User::factory()->create();

        $this->item = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => 'NYE',
            'owned' => true,
        ]);

        $this->item->musicMetadata()->create(['artist' => 'Local Natives']);
        $this->item->refresh();
    }

    public function test_a_withdrawn_endpoint_does_not_report_an_error(): void
    {
        // The reported symptom. A 403 here must not surface as "Spotify
        // errored" on an item whose identification is unaffected by it.
        Http::fake([
            'accounts.spotify.com/*' => Http::response(['access_token' => 'token'], 200),
            'api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => [['id' => 'track123']]]], 200),
            'api.spotify.com/v1/audio-features/*' => Http::response('', 403),
        ]);

        app(Spotify::class)->enrich($this->item);

        // No exception reached the caller, so nothing is recorded against the
        // item. Reaching this line is the assertion.
        $this->assertTrue(true);
    }

    public function test_the_track_id_is_kept_even_when_features_are_refused(): void
    {
        // The search succeeded and that result is worth keeping: it is what
        // links this file to Spotify at all. Saving it only *after* the
        // audio-features call threw the id away on every 403.
        Http::fake([
            'accounts.spotify.com/*' => Http::response(['access_token' => 'token'], 200),
            'api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => [['id' => 'track123']]]], 200),
            'api.spotify.com/v1/audio-features/*' => Http::response('', 403),
        ]);

        app(Spotify::class)->enrich($this->item);

        $this->assertSame('track123', $this->item->fresh()->musicMetadata?->spotify_id);
    }

    public function test_a_real_failure_still_surfaces(): void
    {
        // A 500 is the service being broken, which is worth knowing. Treating
        // every non-200 as "not available" would hide a genuine outage.
        Http::fake([
            'accounts.spotify.com/*' => Http::response(['access_token' => 'token'], 200),
            'api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => [['id' => 'track123']]]], 200),
            'api.spotify.com/v1/audio-features/*' => Http::response('', 500),
        ]);

        $this->expectException(RequestException::class);

        app(Spotify::class)->enrich($this->item);
    }

    public function test_features_are_written_when_the_app_is_allowed_to_read_them(): void
    {
        // An older app still has access, so the happy path must keep working.
        Http::fake([
            'accounts.spotify.com/*' => Http::response(['access_token' => 'token'], 200),
            'api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => [['id' => 'track123']]]], 200),
            'api.spotify.com/v1/audio-features/*' => Http::response([
                'tempo' => 128.4,
                'energy' => 0.82,
                'key' => 7,
                'mode' => 1,
            ], 200),
        ]);

        app(Spotify::class)->enrich($this->item);

        $meta = $this->item->fresh()->musicMetadata;

        $this->assertSame(128.4, (float) $meta->bpm);
        $this->assertSame(82, (int) $meta->energy);
        $this->assertSame('G', $meta->key);
        $this->assertSame('major', $meta->scale);
    }

    public function test_a_partial_body_does_not_overwrite_what_another_source_found(): void
    {
        // A response missing tempo/energy would write nulls over fields
        // MusicBrainz or the file's own tags may have filled.
        $this->item->musicMetadata->forceFill(['bpm' => 120])->save();

        Http::fake([
            'accounts.spotify.com/*' => Http::response(['access_token' => 'token'], 200),
            'api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => [['id' => 'track123']]]], 200),
            'api.spotify.com/v1/audio-features/*' => Http::response(['key' => 3], 200),
        ]);

        app(Spotify::class)->enrich($this->item);

        $this->assertSame(120.0, (float) $this->item->fresh()->musicMetadata->bpm);
    }
}
