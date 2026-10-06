<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\MediaProbe;
use App\Models\User;
use App\Services\Streaming\HlsPlaylist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seeking in a transcoded stream should not re-buffer.
 *
 * ffmpeg writes its playlist as it encodes, so a playlist read moments after
 * the start lists only the handful of segments that exist — and that is all
 * the player believes the film to be. Seeking past them stalled waiting for
 * the encoder, and seeking *backwards* re-buffered too, because each reload
 * handed the player a different, longer playlist to reconcile.
 *
 * A VOD playlist is derivable from two numbers that are both known before a
 * frame is encoded: how long the film is, and how long a segment is.
 */
class HlsSeekableTest extends TestCase
{
    use RefreshDatabase;

    private function film(?int $durationMs): MediaItem
    {
        $item = MediaItem::unresolved()->create([
            'user_id' => User::factory()->create()->id,
            'title' => 'A Film',
            'type' => MediaItemType::Movie,
            'file_path' => 'films/a.mp4',
            'processing_status' => ProcessingStatus::Complete,
        ]);

        if ($durationMs !== null) {
            MediaProbe::create([
                'media_item_id' => $item->id,
                'probed_at' => now(),
                'duration_ms' => $durationMs,
                'video_codec' => 'h264',
            ]);
        }

        return $item->fresh()->load('probe');
    }

    private function playlist(MediaItem $item): ?string
    {
        return app(HlsPlaylist::class)->forItem($item, 'session', fn (string $f): string => "/seg/{$f}");
    }

    public function test_the_playlist_covers_the_whole_film(): void
    {
        // 95 seconds at 6-second segments: 16 segments, the last one short.
        $body = (string) $this->playlist($this->film(95_000));

        $segments = preg_match_all('#^/seg/seg\d{3}\.ts$#m', $body);

        $this->assertSame(16, $segments);
    }

    /**
     * The advertised durations have to add up to the real length, or the
     * player seeks past the end of a film whose final segment is short.
     */
    public function test_the_advertised_duration_matches_the_film(): void
    {
        $body = (string) $this->playlist($this->film(95_000));

        preg_match_all('/#EXTINF:([\d.]+),/', $body, $matches);

        $total = array_sum(array_map('floatval', $matches[1]));

        $this->assertEqualsWithDelta(95.0, $total, 0.001);
    }

    public function test_the_last_segment_is_short_where_the_film_does_not_divide_evenly(): void
    {
        $body = (string) $this->playlist($this->film(95_000));

        preg_match_all('/#EXTINF:([\d.]+),/', $body, $matches);

        $last = (float) end($matches[1]);

        $this->assertEqualsWithDelta(5.0, $last, 0.001);
    }

    public function test_a_film_that_divides_evenly_has_no_short_segment(): void
    {
        $body = (string) $this->playlist($this->film(60_000));

        preg_match_all('/#EXTINF:([\d.]+),/', $body, $matches);

        $this->assertCount(10, $matches[1]);
        $this->assertEqualsWithDelta(6.0, (float) end($matches[1]), 0.001);
    }

    /**
     * It has to be a VOD playlist and it has to end, or the player treats it
     * as live and refuses to seek at all.
     */
    public function test_it_is_a_finished_vod_playlist(): void
    {
        $body = (string) $this->playlist($this->film(60_000));

        $this->assertStringContainsString('#EXT-X-PLAYLIST-TYPE:VOD', $body);
        $this->assertStringContainsString('#EXT-X-ENDLIST', $body);
    }

    /**
     * A playlist whose duration is wrong is worse than none: the player
     * trusts it and seeks into nothing. An unmeasured file falls back to
     * ffmpeg's own partial playlist instead.
     */
    public function test_an_unmeasured_film_gets_no_synthetic_playlist(): void
    {
        $this->assertNull($this->playlist($this->film(null)));
    }

    public function test_a_zero_duration_gets_no_synthetic_playlist(): void
    {
        $this->assertNull($this->playlist($this->film(0)));
    }
}
