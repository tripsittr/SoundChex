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
 * The backfill for titles filed while the cleanup was off (S-365, S-361).
 */
class TidyExistingTitlesTest extends TestCase
{
    use RefreshDatabase;

    private function track(string $title, string $artist): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => '/tmp/'.md5($title).'.mp3',
            'owned' => true,
        ]);

        $item->musicMetadata()->create([
            'artist' => $artist,
            'primary_artist' => explode('/', $artist)[0],
        ]);

        return $item->fresh();
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $item = $this->track('$uicideboy$, Germ - Here We Go Again', '$uicideboy$/Germ');

        $this->artisan('music:tidy-titles')->assertSuccessful();

        $this->assertSame('$uicideboy$, Germ - Here We Go Again', $item->fresh()->title);
    }

    public function test_force_strips_the_credit(): void
    {
        $item = $this->track('$uicideboy$, Germ - Here We Go Again', '$uicideboy$/Germ');

        $this->artisan('music:tidy-titles --force')->assertSuccessful();

        $this->assertSame('Here We Go Again', $item->fresh()->title);
    }

    public function test_a_clean_title_is_left_alone(): void
    {
        $item = $this->track('Here We Go Again', '$uicideboy$/Germ');

        $this->artisan('music:tidy-titles --force')->assertSuccessful();

        $this->assertSame('Here We Go Again', $item->fresh()->title);
    }

    public function test_a_title_is_never_replaced_by_its_own_credit(): void
    {
        // "Adiemus" is both the title and one of the names in the credit, so
        // the prefix rule matches and would keep the artist list and throw the
        // title away. Whatever else happens, the credit must not become the
        // title — here the suffix rule takes the line instead and the real
        // title survives.
        $item = $this->track(
            'Adiemus - Karl Jenkins, Adiemus, Mary Carewe',
            'Karl Jenkins/Adiemus/Mary Carewe',
        );

        $this->artisan('music:tidy-titles --force')->assertSuccessful();

        $this->assertSame('Adiemus', $item->fresh()->title);
    }
}
