<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Repairing what the byte-mask trim cut in half.
 *
 * The corruption is not reversible — the bytes that said which character it
 * was are gone — so the title is re-derived from the filename, which the trim
 * never touched.
 */
class RepairCorruptTitlesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_without_writing_by_default(): void
    {
        $item = $this->corrupt();

        $this->artisan('library:repair-corrupt-titles')->assertSuccessful();

        $this->assertFalse(
            mb_check_encoding($item->fresh()->title, 'UTF-8'),
            'a dry run must not write',
        );
    }

    public function test_apply_rebuilds_the_title_from_the_filename(): void
    {
        $item = $this->corrupt();

        $this->artisan('library:repair-corrupt-titles --apply')->assertSuccessful();

        $repaired = $item->fresh()->title;

        $this->assertTrue(mb_check_encoding($repaired, 'UTF-8'), 'the repaired title must be valid UTF-8');
        $this->assertSame('’Cause I’m a Man', $repaired);
    }

    public function test_a_row_with_no_filename_is_left_alone(): void
    {
        // Nothing to re-derive from, so guessing would be worse than leaving
        // the evidence of what went wrong.
        $user = User::factory()->create();

        $item = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => "\x99Cause I\xE2\x80\x99m a Man",
            'processing_status' => 'complete',
        ]);

        $this->artisan('library:repair-corrupt-titles --apply')->assertSuccessful();

        $this->assertFalse(mb_check_encoding($item->fresh()->title, 'UTF-8'));
    }

    public function test_a_clean_library_reports_nothing(): void
    {
        $user = User::factory()->create();

        MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => 'Perfectly ordinary',
            'file_path' => 'media/library/Music/A/B/01 Perfectly ordinary.mp3',
            'processing_status' => 'complete',
        ]);

        $this->artisan('library:repair-corrupt-titles')
            ->expectsOutputToContain('No corrupt text found.')
            ->assertSuccessful();
    }

    private function corrupt(): MediaItem
    {
        $user = User::factory()->create();

        // What the byte mask actually left: the first two bytes of the curly
        // quote eaten, the third stranded.
        return MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => "\x99Cause I\xE2\x80\x99m a Man",
            'file_path' => 'media/library/Music/Alabama Shakes/Sound & Color/06 ’Cause I’m a Man.mp3',
            'processing_status' => 'complete',
        ]);
    }
}
