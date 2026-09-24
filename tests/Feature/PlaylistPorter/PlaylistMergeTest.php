<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature\PlaylistPorter;

use App\Enums\MediaItemType;
use App\Models\Collection;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SoundChex\PlaylistPorter\Models\PlaylistImport;
use SoundChex\PlaylistPorter\Services\PlaylistImportService;
use Tests\TestCase;

/**
 * Importing the same playlist twice (S-325).
 *
 * The normal case, not an edge one: a service playlist changes and you bring
 * it in again. Before this, the second import silently made a second playlist
 * with the same name and nothing said which was which.
 */
class PlaylistMergeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // The plugin's classes are not in the app's autoloader — the loader
        // registers them at runtime and plugins do not load in tests — so the
        // source is required directly, the way the other porter tests do it.
        $root = dirname(base_path()).'/Plugins/soundchex-playlist-porter';

        if (! is_dir($root)) {
            $this->markTestSkipped('Playlist Porter plugin source not available.');
        }

        foreach ([
            '/src/Models/PlaylistImport.php',
            '/src/Services/ImportedTrack.php',
            '/src/Services/TrackMatch.php',
            '/src/Services/TrackMatcher.php',
            '/src/Services/Parsers/PlaylistFileParser.php',
            '/src/Services/Parsers/M3uParser.php',
            '/src/Services/Parsers/CsvParser.php',
            '/src/Services/Parsers/XspfParser.php',
            '/src/Services/PlaylistImportService.php',
        ] as $file) {
            if (is_file($root.$file)) {
                require_once $root.$file;
            }
        }

        // The plugin owns its own tables; nothing runs its migrations in the
        // app's test database, so run them here.
        foreach (glob($root.'/database/migrations/*.php') ?: [] as $migration) {
            (require $migration)->up();
        }

        $this->user = User::factory()->create();
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

    private function import(): PlaylistImport
    {
        return PlaylistImport::create([
            'user_id' => $this->user->id,
            'source' => 'file',
            'source_format' => 'm3u',
            'name' => 'Road Trip',
            'status' => PlaylistImport::STATUS_PENDING,
            'total_tracks' => 0,
        ]);
    }

    /** @return array<int, \SoundChex\PlaylistPorter\Services\ImportedTrack> */
    private function parse(PlaylistImportService $service, string $m3u): array
    {
        return $service->parseFile($m3u, 'm3u')['tracks'];
    }

    private function m3u(string ...$lines): string
    {
        $out = "#EXTM3U\n#PLAYLIST:Road Trip\n";

        foreach ($lines as $line) {
            $out .= "#EXTINF:200,{$line}\n/music/x.mp3\n";
        }

        return $out;
    }

    public function test_it_finds_an_existing_playlist_by_name(): void
    {
        $service = app(PlaylistImportService::class);
        $existing = Collection::create(['user_id' => $this->user->id, 'name' => 'Road Trip']);

        $this->assertSame(
            $existing->id,
            $service->existingPlaylist($this->user->id, 'Road Trip')?->id,
        );
    }

    public function test_the_name_match_ignores_case_and_surrounding_space(): void
    {
        // "Road Trip" and "road trip" are the same playlist to a person.
        $service = app(PlaylistImportService::class);
        $existing = Collection::create(['user_id' => $this->user->id, 'name' => 'Road Trip']);

        $this->assertSame(
            $existing->id,
            $service->existingPlaylist($this->user->id, '  road trip ')?->id,
        );
    }

    public function test_another_users_playlist_is_never_offered(): void
    {
        $service = app(PlaylistImportService::class);
        $other = User::factory()->create();
        Collection::create(['user_id' => $other->id, 'name' => 'Road Trip']);

        $this->assertNull($service->existingPlaylist($this->user->id, 'Road Trip'));
    }

    public function test_merging_adds_to_the_playlist_instead_of_making_another(): void
    {
        $service = app(PlaylistImportService::class);
        $this->track('Bohemian Rhapsody', 'Queen');
        $this->track('Under Pressure', 'Queen');

        // First import makes the playlist.
        $service->run($this->import(), $this->parse($service, $this->m3u('Queen - Bohemian Rhapsody')), 'Road Trip');

        $playlist = $service->existingPlaylist($this->user->id, 'Road Trip');
        $this->assertNotNull($playlist);
        $this->assertSame(1, $playlist->mediaItems()->count());

        // Second import merges into it.
        $service->run($this->import(), $this->parse($service, $this->m3u('Queen - Under Pressure')), 'Road Trip', $playlist);

        $this->assertSame(1, Collection::where('name', 'Road Trip')->count(), 'Merging must not make a second playlist.');
        $this->assertSame(2, $playlist->fresh()->mediaItems()->count());
    }

    public function test_merging_the_same_tracks_twice_does_not_double_them(): void
    {
        $service = app(PlaylistImportService::class);
        $this->track('Bohemian Rhapsody', 'Queen');

        $service->run($this->import(), $this->parse($service, $this->m3u('Queen - Bohemian Rhapsody')), 'Road Trip');

        $playlist = $service->existingPlaylist($this->user->id, 'Road Trip');
        $service->run($this->import(), $this->parse($service, $this->m3u('Queen - Bohemian Rhapsody')), 'Road Trip', $playlist);

        $this->assertSame(1, $playlist->fresh()->mediaItems()->count());
    }

    public function test_merged_tracks_are_appended_not_interleaved(): void
    {
        $service = app(PlaylistImportService::class);
        $this->track('Bohemian Rhapsody', 'Queen');
        $this->track('Under Pressure', 'Queen');

        $service->run($this->import(), $this->parse($service, $this->m3u('Queen - Bohemian Rhapsody')), 'Road Trip');

        $playlist = $service->existingPlaylist($this->user->id, 'Road Trip');
        $service->run($this->import(), $this->parse($service, $this->m3u('Queen - Under Pressure')), 'Road Trip', $playlist);

        // The pivot values themselves, not the rendered order: with sort_order
        // restarted at zero both rows are 0, ordering is then ambiguous, and
        // the database is free to hand them back in insertion order — which
        // looks correct while being wrong.
        $positions = $playlist->fresh()->mediaItems()
            ->orderByPivot('sort_order')
            ->get()
            ->mapWithKeys(fn ($item) => [$item->title => $item->pivot->sort_order])
            ->all();

        $this->assertSame(
            ['Bohemian Rhapsody' => 0, 'Under Pressure' => 1],
            $positions,
            'Merged tracks must continue the existing numbering, not restart it.',
        );
    }

    public function test_without_a_merge_target_a_second_playlist_is_still_made(): void
    {
        // "Keep both" must remain possible — it is the old behaviour, now chosen.
        $service = app(PlaylistImportService::class);
        $this->track('Bohemian Rhapsody', 'Queen');

        $service->run($this->import(), $this->parse($service, $this->m3u('Queen - Bohemian Rhapsody')), 'Road Trip');
        $service->run($this->import(), $this->parse($service, $this->m3u('Queen - Bohemian Rhapsody')), 'Road Trip');

        $this->assertSame(2, Collection::where('name', 'Road Trip')->count());
    }
}
