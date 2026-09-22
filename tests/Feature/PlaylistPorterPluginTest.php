<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\Collection;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Plugins\Registry;
use App\Services\CurrentProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use SoundChex\PlaylistPorter\Filament\ImportPlaylist;
use Tests\TestCase;

/**
 * The bundled Playlist Porter plugin (S-311): the desktop/server face of playlist
 * porting. It registers an Import Playlist admin page that drives the shared
 * porting engine (S-310).
 */
class PlaylistPorterPluginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['soundchex.plugins.enabled' => true]);

        $this->user = User::factory()->create();
        $owner = Profile::create(['user_id' => $this->user->id, 'name' => 'Owner', 'is_owner' => true]);
        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($owner->id);
        Filament::setCurrentPanel('admin');
    }

    public function test_the_plugin_registers_its_page_through_the_admin_page_seam(): void
    {
        $this->assertContains(ImportPlaylist::class, app(Registry::class)->adminPageClasses());
    }

    public function test_a_capped_profile_cannot_reach_the_page(): void
    {
        $kid = Profile::create([
            'user_id' => $this->user->id, 'name' => 'Kid', 'is_owner' => false, 'max_rating' => 'PG',
        ]);
        app(CurrentProfile::class)->switchTo($kid->id);

        $this->assertFalse(ImportPlaylist::canAccess());
    }

    public function test_importing_a_file_creates_a_playlist_of_matched_tracks(): void
    {
        $this->track('Bohemian Rhapsody', 'Queen');
        $this->track('Under Pressure', 'Queen');

        $m3u = "#EXTM3U\n#PLAYLIST:My Mix\n"
            ."#EXTINF:355,Queen - Bohemian Rhapsody\n/a.mp3\n"
            ."#EXTINF:246,Queen - Under Pressure\n/b.mp3\n"
            ."#EXTINF:200,Nobody - Missing\n/c.mp3\n";

        Livewire::test(ImportPlaylist::class)
            ->set('file', UploadedFile::fake()->createWithContent('mix.m3u', $m3u))
            ->call('import')
            ->assertHasNoErrors();

        $playlist = Collection::where('name', 'My Mix')->first();
        $this->assertNotNull($playlist);
        $this->assertSame(2, $playlist->mediaItems()->count());
    }

    public function test_an_unmatched_track_can_be_resolved_from_the_page(): void
    {
        $this->track('Known', 'Artist');
        $chosen = $this->track('Chosen Match', 'Artist');

        $m3u = "#EXTM3U\n#EXTINF:100,Artist - Known\n/a.mp3\n#EXTINF:100,Ghost - Missing\n/b.mp3\n";

        $component = Livewire::test(ImportPlaylist::class)
            ->set('file', UploadedFile::fake()->createWithContent('p.m3u', $m3u))
            ->call('import')
            ->assertHasNoErrors();

        // One track was unmatched; resolve it to the chosen library item.
        $component->set('resolveTo.0', (string) $chosen->id)
            ->call('resolve', 0)
            ->assertHasNoErrors();

        $importId = $component->get('importId');
        $playlist = \App\Models\PlaylistImport::find($importId)->collection;
        $this->assertSame(2, $playlist->mediaItems()->count());
    }

    private function track(string $title, string $artist): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => 'music/'.str($title)->slug().'.mp3',
            'owned' => true,
        ]);
        $item->musicMetadata()->create(['artist' => $artist, 'primary_artist' => $artist]);

        return $item->fresh();
    }
}
