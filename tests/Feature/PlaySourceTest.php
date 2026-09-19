<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A play records the surface it was started from (S-120), taken from the
 * stream request's `from` param and validated against the known set.
 */
class PlaySourceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profile $profile;

    private MediaItem $track;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->profile = Profile::create(['user_id' => $this->user->id, 'name' => 'Owner']);

        // A real file so the stream endpoint responds rather than 404ing.
        $this->file = tempnam(sys_get_temp_dir(), 'src').'.mp3';
        file_put_contents($this->file, 'audio');

        $this->track = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'A Song',
            'file_path' => $this->file,
            'owned' => true,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function stream(string $query = '')
    {
        return $this->actingAs($this->user)
            ->withSession(['profile_id' => $this->profile->id, 'profile_unlocked_at' => now()->timestamp])
            ->get("/app/item/{$this->track->id}/stream{$query}");
    }

    private function source(): ?string
    {
        return $this->track->plays()->latest('id')->first()?->source;
    }

    public function test_a_known_source_is_recorded(): void
    {
        $this->stream('?from=album')->assertOk();

        $this->assertSame('album', $this->source());
    }

    public function test_an_unknown_source_records_null(): void
    {
        $this->stream('?from=totally-made-up')->assertOk();

        $this->assertNull($this->source());
    }

    public function test_no_source_records_null(): void
    {
        $this->stream()->assertOk();

        $this->assertNull($this->source());
    }
}
