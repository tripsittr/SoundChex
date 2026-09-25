<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\LyricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lyrics, plain and time-synced (S-300). Sources are tried embedded-first, then a
 * provider (LRCLIB); either can yield synced LRC or plain words.
 */
class LyricsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_a_dot_lrc_sidecar_is_read_as_synced_lyrics(): void
    {
        // A .lrc next to the track is authoritative and needs no network.
        [$item, $dir] = $this->trackWithFile('song.mp3');
        file_put_contents(
            $dir.'/song.lrc',
            "[ar:Tester]\n[00:12.00] First line\n[00:15.50] Second line\n"
        );

        $payload = app(LyricsService::class)->lyricsPayloadFor($item->fresh());

        $this->assertNotNull($payload['synced']);
        $this->assertStringContainsString('[00:12.00] First line', $payload['synced']);
        // Plain is the same words with the timestamps stripped.
        $this->assertSame("First line\nSecond line", $payload['plain']);
    }

    public function test_the_provider_is_used_only_when_the_file_carries_nothing(): void
    {
        [$item] = $this->trackWithFile('bare.mp3'); // no sidecar, no tags
        $item->musicMetadata()->update(['artist' => 'Artist', 'album' => 'Album']);

        Http::fake([
            'lrclib.net/api/get*' => Http::response([
                'plainLyrics' => "La la la",
                'syncedLyrics' => "[00:01.00] La la la",
            ], 200),
        ]);

        $payload = app(LyricsService::class)->lyricsPayloadFor($item->fresh());

        $this->assertSame("[00:01.00] La la la", $payload['synced']);
        $this->assertSame('La la la', $payload['plain']);
    }

    public function test_the_api_returns_both_plain_and_synced(): void
    {
        [$item] = $this->trackWithFile('api.mp3');
        $item->musicMetadata()->update([
            'lyrics' => "Hello\nWorld",
            'lyrics_synced' => "[00:00.00] Hello\n[00:02.00] World",
        ]);

        $user = $item->user;
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->getJson(route('api.items.lyrics', $item))
            ->assertOk()
            ->assertJsonPath('lyrics', "Hello\nWorld")
            ->assertJsonPath('synced', "[00:00.00] Hello\n[00:02.00] World");
    }

    /**
     * A music item with a real file on disk and empty music metadata.
     *
     * @return array{0: MediaItem, 1: string}
     */
    /* ------------------------------------------------- the web route --- */

    public function test_the_web_route_serves_lyrics_to_a_signed_in_listener(): void
    {
        // The player has a session, not a token, so it cannot call the API
        // route at all — hence a second one (S-301).
        [$item, $dir] = $this->trackWithFile('song.mp3');
        file_put_contents($dir.'/song.lrc', "[00:12.00] First line\n[00:15.50] Second line\n");

        $this->actingAs($item->user);

        $this->getJson(route('media.lyrics', $item))
            ->assertOk()
            ->assertJsonStructure(['lyrics', 'synced']);
    }

    public function test_the_web_route_is_closed_to_a_stranger(): void
    {
        [$item] = $this->trackWithFile('song.mp3');

        $this->getJson(route('media.lyrics', $item))->assertRedirect();
    }

    public function test_a_track_with_no_lyrics_answers_with_nulls_rather_than_an_error(): void
    {
        // A song with no words is a normal answer; the panel hides its own
        // button rather than showing an error.
        [$item] = $this->trackWithFile('song.mp3');

        $this->actingAs($item->user);

        $this->getJson(route('media.lyrics', $item))
            ->assertOk()
            ->assertJsonPath('lyrics', null)
            ->assertJsonPath('synced', null);
    }

    private function trackWithFile(string $name): array
    {
        $dir = sys_get_temp_dir().'/lyrics-test-'.uniqid();
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/'.$name, 'not a real audio payload');

        $user = User::factory()->create();
        $item = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => 'A Song',
            'file_path' => $dir.'/'.$name,
            'owned' => true,
        ]);
        $item->musicMetadata()->create([]);

        return [$item, $dir];
    }
}
