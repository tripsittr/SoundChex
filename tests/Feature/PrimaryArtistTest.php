<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Http\Resources\MediaItemResource;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Featured-artist tracks group under the primary artist (S-269).
 */
class PrimaryArtistTest extends TestCase
{
    use RefreshDatabase;

    private function track(string $artist, ?string $primary = null): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => 'Song',
            'owned' => true,
        ]);
        $item->musicMetadata()->create(['artist' => $artist, 'primary_artist' => $primary]);

        return $item->fresh();
    }

    /* ------------------------------------------------------- backfill --- */

    public function test_backfill_derives_the_primary_artist(): void
    {
        $a = $this->track('$uicideboy$, Pouya');
        $b = $this->track('$uicideboy$/Maxo Cream');

        $this->artisan('music:backfill-primary-artist')->assertSuccessful();

        $this->assertSame('$uicideboy$', $a->musicMetadata->fresh()->primary_artist);
        $this->assertSame('$uicideboy$', $b->musicMetadata->fresh()->primary_artist);
    }

    public function test_backfill_keeps_indivisible_names_whole(): void
    {
        // Names whose own comma/ampersand must not be treated as a feature.
        $tyler = $this->track('Tyler, The Creator');
        $ewf = $this->track('Earth, Wind & Fire');

        $this->artisan('music:backfill-primary-artist')->assertSuccessful();

        $this->assertSame('Tyler, The Creator', $tyler->musicMetadata->fresh()->primary_artist);
        $this->assertSame('Earth, Wind & Fire', $ewf->musicMetadata->fresh()->primary_artist);
    }

    public function test_backfill_only_fills_blanks_by_default(): void
    {
        // A row with a deliberately-set primary_artist is left alone.
        $set = $this->track('Some, Credit', primary: 'A Chosen Primary');

        $this->artisan('music:backfill-primary-artist')->assertSuccessful();

        $this->assertSame('A Chosen Primary', $set->musicMetadata->fresh()->primary_artist);
    }

    public function test_all_recomputes_every_row(): void
    {
        $set = $this->track('$uicideboy$, Pouya', primary: 'Wrong');

        $this->artisan('music:backfill-primary-artist', ['--all' => true])->assertSuccessful();

        $this->assertSame('$uicideboy$', $set->musicMetadata->fresh()->primary_artist);
    }

    /* ------------------------------------------------------------ api --- */

    public function test_the_api_exposes_primary_artist(): void
    {
        $item = $this->track('$uicideboy$, Pouya', primary: '$uicideboy$');

        $data = (new MediaItemResource($item->fresh()))
            ->toArray(request());

        $this->assertSame('$uicideboy$', $data['meta']['primary_artist']);
        // The full credit is still available for display.
        $this->assertSame('$uicideboy$, Pouya', $data['meta']['artist']);
    }

    public function test_the_api_falls_back_to_the_full_credit(): void
    {
        // No derived primary yet — the client still gets a value to group on.
        $item = $this->track('Solo Artist');
        $item->musicMetadata->forceFill(['primary_artist' => null])->saveQuietly();

        $data = (new MediaItemResource($item->fresh()))
            ->toArray(request());

        $this->assertSame('Solo Artist', $data['meta']['primary_artist']);
    }
}
