<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature\Api;

use App\Enums\MediaItemType;
use App\Http\Resources\MediaItemResource;
use App\Models\MediaItem;
use App\Models\MediaPlay;
use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When this profile last played something (S-391).
 *
 * Drives the "recently played" sort on the artist page. Per profile, because
 * two people sharing a login have different recent listening — and read from
 * the loaded relation, because a query per row would make every listing pay
 * for it.
 */
class LastPlayedAtTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);

        app(CurrentProfile::class)->switchTo($this->profile->id);
    }

    private function track(): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'A Song',
            'file_path' => '/tmp/a-song.mp3',
            'owned' => true,
        ]);

        $item->musicMetadata()->create(['artist' => 'An Artist']);

        return $item->fresh();
    }

    private function play(MediaItem $item, ?Profile $profile = null, int $daysAgo = 0): void
    {
        $play = MediaPlay::create([
            'media_item_id' => $item->id,
            'user_id' => $this->user->id,
            'profile_id' => ($profile ?? $this->profile)->id,
            'position_seconds' => 0,
        ]);

        if ($daysAgo > 0) {
            // created_at is not fillable, so it has to be forced — passing it
            // to create() is silently dropped.
            $play->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();
        }
    }

    /**
     * The field as a device actually receives it.
     *
     * Through a real request rather than the resource directly: the profile
     * is resolved from the token, and building the resource by hand leaves
     * `CurrentProfile` unresolved — which reports null for everything and
     * makes the test agree with itself rather than with the app.
     */
    private function field(MediaItem $item): ?string
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->user, ['profile:'.$this->profile->id]);

        return $this->getJson(route('api.library'))
            ->json('items.0.last_played_at');
    }

    public function test_it_reports_when_the_profile_last_played_it(): void
    {
        $item = $this->track();
        $this->play($item);

        $this->assertNotNull($this->field($item));
    }

    public function test_something_never_played_reports_nothing(): void
    {
        $this->assertNull($this->field($this->track()));
    }

    public function test_it_reports_the_newest_play_not_the_first(): void
    {
        $item = $this->track();
        $this->play($item, daysAgo: 30);
        $this->play($item);

        $this->assertTrue(
            now()->diffInHours(\Carbon\Carbon::parse($this->field($item))) < 1,
            'A track played twice should report the more recent play.',
        );
    }

    public function test_another_profiles_play_does_not_count(): void
    {
        // Two people sharing a login have different recent listening.
        $item = $this->track();
        $theirs = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Someone Else',
            'is_owner' => false,
        ]);

        $this->play($item, profile: $theirs);

        $this->assertNull($this->field($item));
    }

    public function test_an_unloaded_relation_reports_nothing_rather_than_querying(): void
    {
        // The guard that keeps a 200-row listing from becoming 200 queries.
        $item = $this->track();
        $this->play($item);

        app(CurrentProfile::class)->switchTo($this->profile->id);

        $bare = MediaItem::find($item->id);

        $this->assertNull((new MediaItemResource($bare))->toArray(request())['last_played_at']);
    }
}
