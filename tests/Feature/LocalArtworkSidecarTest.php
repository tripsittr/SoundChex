<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\LibraryScanner;
use App\Services\LocalArtwork;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cover art the files brought with them.
 *
 * A library copied from Emby or Jellyfin carries its posters: a poster.jpg in
 * each film's folder, an image named after the file. These were ignored — only
 * the media file was catalogued, and the cover waited on an API fetch that may
 * never have had a key. Now the local one is used first.
 *
 * The delicate part is not attaching a generic poster.jpg to the wrong film.
 * In a per-film folder it is unambiguous; in a flat inbox where many films and
 * loose images share one directory, it belongs to nothing, and that distinction
 * is what most of these tests guard.
 */
class LocalArtworkSidecarTest extends TestCase
{
    use RefreshDatabase;

    private string $watched;

    private static int $n = 0;

    /** A real, minimal PNG, so getimagesizefromstring accepts it without GD. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->watched = Storage::disk('local')->path('watched');
        @mkdir($this->watched, 0755, true);

        config()->set('library.watch_folders', [$this->watched]);
        app(SettingsService::class)->set('library_scan_storage', false);
        // Freshly written test files are younger than the settle window, so
        // without this the scan skips them as still-being-copied.
        app(SettingsService::class)->set('library_settle_seconds', 0);

        // catalog() files the rows under a user; a scan needs one to exist.
        User::factory()->create();
    }

    private function png(): string
    {
        return base64_decode(self::PNG);
    }

    /* --------------------------------------------------- the service --- */

    public function test_a_poster_in_a_films_own_folder_is_used(): void
    {
        $dir = $this->watched.'/War Dogs (2016)';
        @mkdir($dir, 0755, true);
        file_put_contents($dir.'/War Dogs (2016).mkv', 'video bytes');
        file_put_contents($dir.'/poster.jpg', $this->png());

        $item = $this->film('War Dogs', $dir.'/War Dogs (2016).mkv');

        $cover = app(LocalArtwork::class)->discover($item);

        $this->assertNotNull($cover, 'A poster.jpg in a dedicated folder should be found.');
        $this->assertStringStartsWith('artwork/local/', $cover);
        $this->assertTrue(Storage::disk('public')->exists($cover), 'The cover should be copied to the public disk.');
    }

    public function test_an_image_named_after_the_file_is_used_anywhere(): void
    {
        // At the top of the watched folder — the flat inbox — where a generic
        // name would be refused, a named one is still safe.
        file_put_contents($this->watched.'/Heat (1995).mkv', 'video');
        file_put_contents($this->watched.'/Heat (1995).jpg', $this->png());

        $item = $this->film('Heat', $this->watched.'/Heat (1995).mkv');

        $this->assertNotNull(app(LocalArtwork::class)->discover($item));
    }

    public function test_a_named_poster_suffix_is_used(): void
    {
        $dir = $this->watched.'/Sub';
        @mkdir($dir, 0755, true);
        file_put_contents($dir.'/The Mummy (1999).mp4', 'v');
        file_put_contents($dir.'/The Mummy (1999)-poster.png', $this->png());

        $item = $this->film('The Mummy', $dir.'/The Mummy (1999).mp4');

        $this->assertNotNull(app(LocalArtwork::class)->discover($item));
    }

    /**
     * The guard that matters: a generic poster at the flat top level belongs to
     * no particular film and must not be attached to one.
     */
    public function test_a_generic_poster_at_the_inbox_top_is_ignored(): void
    {
        file_put_contents($this->watched.'/Backrooms (2026).mp4', 'v');
        file_put_contents($this->watched.'/poster.jpg', $this->png());

        $item = $this->film('Backrooms', $this->watched.'/Backrooms (2026).mp4');

        $this->assertNull(
            app(LocalArtwork::class)->discover($item),
            'A loose poster.jpg in a shared inbox must not be claimed by a random film.'
        );
    }

    public function test_a_folder_jpg_is_used_for_an_album(): void
    {
        $dir = $this->watched.'/Vampire Weekend/Modern Vampires';
        @mkdir($dir, 0755, true);
        file_put_contents($dir.'/01 A-Punk.mp3', 'audio');
        file_put_contents($dir.'/folder.jpg', $this->png());

        $item = $this->track('A-Punk', $dir.'/01 A-Punk.mp3');

        $this->assertNotNull(app(LocalArtwork::class)->discover($item));
    }

    public function test_a_file_that_only_looks_like_an_image_is_rejected(): void
    {
        $dir = $this->watched.'/Fake';
        @mkdir($dir, 0755, true);
        file_put_contents($dir.'/movie.mkv', 'v');
        // A .jpg that is actually text, as a mislabelled download can be.
        file_put_contents($dir.'/poster.jpg', 'this is not an image');

        $item = $this->film('Fake', $dir.'/movie.mkv');

        $this->assertNull(app(LocalArtwork::class)->discover($item));
    }

    public function test_it_is_disabled_by_config(): void
    {
        config()->set('library.artwork_sidecars.enabled', false);

        $dir = $this->watched.'/Off';
        @mkdir($dir, 0755, true);
        file_put_contents($dir.'/movie.mkv', 'v');
        file_put_contents($dir.'/poster.jpg', $this->png());

        $item = $this->film('Off', $dir.'/movie.mkv');

        $this->assertNull(app(LocalArtwork::class)->discover($item));
    }

    /* ------------------------------------------- through a real scan --- */

    public function test_a_scan_sets_the_cover_from_a_sidecar(): void
    {
        $dir = $this->watched.'/Inception (2010)';
        @mkdir($dir, 0755, true);
        file_put_contents($dir.'/Inception (2010).mkv', 'video bytes');
        file_put_contents($dir.'/poster.jpg', $this->png());

        app(LibraryScanner::class)->scan(enrich: false);

        $item = MediaItem::unresolved()->where('type', MediaItemType::Movie)->first();

        $this->assertNotNull($item);
        $this->assertNotNull($item->cover_image_url, 'The scan should have set a cover from the sidecar.');
        $this->assertStringStartsWith('artwork/local/', $item->cover_image_url);
        $this->assertTrue(Storage::disk('public')->exists($item->cover_image_url));
    }

    public function test_a_scan_does_not_overwrite_a_cover_already_set(): void
    {
        $dir = $this->watched.'/Dune (2021)';
        @mkdir($dir, 0755, true);
        file_put_contents($dir.'/Dune (2021).mkv', 'video');
        file_put_contents($dir.'/poster.jpg', $this->png());

        app(LibraryScanner::class)->scan(enrich: false);
        $item = MediaItem::unresolved()->where('type', MediaItemType::Movie)->first();
        $this->assertNotNull($item->cover_image_url);

        // Pretend enrichment later set a remote cover; a re-scan must leave it.
        $item->forceFill(['cover_image_url' => 'https://image.tmdb.org/t/p/w500/dune.jpg'])->saveQuietly();

        app(LibraryScanner::class)->scan(enrich: false);

        $this->assertSame(
            'https://image.tmdb.org/t/p/w500/dune.jpg',
            $item->fresh()->cover_image_url
        );
    }

    /* ----------------------------------------------------------- setup --- */

    private function film(string $title, string $absolutePath): MediaItem
    {
        return $this->row($title, MediaItemType::Movie, $absolutePath);
    }

    private function track(string $title, string $absolutePath): MediaItem
    {
        return $this->row($title, MediaItemType::Music, $absolutePath);
    }

    private function row(string $title, MediaItemType $type, string $absolutePath): MediaItem
    {
        self::$n++;

        return MediaItem::create([
            'user_id' => (User::first() ?? User::factory()->create())->id,
            'type' => $type,
            'title' => $title,
            // An absolute path, which is how the folder importer registers files
            // that live where they already are.
            'file_path' => $absolutePath,
            'owned' => true,
        ]);
    }
}
