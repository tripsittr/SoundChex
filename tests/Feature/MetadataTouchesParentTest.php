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
 * A metadata change has to reach the devices (S-386).
 *
 * The delta sync asks for items whose `media_items.updated_at` has moved, but
 * almost everything a person edits lives in a child row. Without the parent
 * being touched the delta returned nothing, and a corrected album stayed wrong
 * on the phone until the app was reinstalled — which is exactly how it was
 * found.
 */
class MetadataTouchesParentTest extends TestCase
{
    use RefreshDatabase;

    private function track(): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => 'A Song',
            'file_path' => '/tmp/a-song.mp3',
            'owned' => true,
        ]);

        $item->musicMetadata()->create(['artist' => 'An Artist', 'album' => 'A Record']);

        return $item->fresh();
    }

    public function test_changing_an_album_bumps_the_item(): void
    {
        $item = $this->track();

        // Backdated, so the difference cannot come from the clock.
        $item->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
        $before = $item->fresh()->updated_at;

        $item->musicMetadata->forceFill(['album' => 'A Different Record'])->save();

        $this->assertTrue(
            $item->fresh()->updated_at->greaterThan($before),
            'A metadata change must move the parent, or the delta sync never reports it.',
        );
    }

    public function test_the_delta_endpoint_returns_a_metadata_only_change(): void
    {
        // The behaviour that actually matters: the device asks what changed
        // since it last synced, and gets the track back.
        $item = $this->track();
        $since = now()->subMinute();

        $item->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
        $item->musicMetadata->forceFill(['album' => 'Corrected'])->save();

        $profile = \App\Models\Profile::create([
            'user_id' => $item->user_id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($item->user, ['profile:'.$profile->id]);
        app(\App\Services\CurrentProfile::class)->switchTo($profile->id);

        $this->getJson(route('api.library.delta', ['since' => $since->toIso8601String()]))
            ->assertOk()
            ->assertJsonPath('items.0.id', $item->id);
    }

    public function test_saving_an_unchanged_value_does_not_bump_the_item(): void
    {
        // Or a rescan that finds nothing new would hand every device the whole
        // library again.
        $item = $this->track();
        $item->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
        $before = $item->fresh()->updated_at;

        $item->musicMetadata->forceFill(['album' => 'A Record'])->save();

        $this->assertEquals($before, $item->fresh()->updated_at);
    }
}
