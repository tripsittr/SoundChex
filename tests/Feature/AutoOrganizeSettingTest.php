<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\LibrarySettings;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The admin toggle that decides whether enrichment moves the user's files
 * (#489).
 *
 * It read `config('library.auto_organize')` while the settings page wrote to
 * the database, so `LibrarySettings::autoOrganize()` had no callers at all and
 * switching the toggle off changed nothing. With #489 and #489 live, this was
 * the control a user would reach for to stop files moving, and it was inert.
 */
class AutoOrganizeSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_the_setting_is_read_from_the_admin_toggle_not_only_from_config(): void
    {
        // config still says "organize" — the stored setting must win, which is
        // the whole contract of LibrarySettings::value().
        config()->set('library.auto_organize', true);
        app(SettingsService::class)->set('library_auto_organize', false);

        $this->assertFalse(app(LibrarySettings::class)->autoOrganize());
    }

    public function test_turning_the_toggle_off_leaves_the_file_where_it_is(): void
    {
        config()->set('library.auto_organize', true);
        app(SettingsService::class)->set('library_auto_organize', false);

        $item = $this->music();
        $source = $item->absoluteFilePath();

        app()->call([new EnrichMediaItemJob($item->id), 'handle']);

        $this->assertFileExists($source, 'Auto-organize was off, so nothing should have been moved.');
        $this->assertSame($item->file_path, $item->fresh()->file_path);
    }

    public function test_leaving_the_toggle_on_still_files_the_item(): void
    {
        // The other half: the fix must not quietly disable filing for everyone.
        config()->set('library.auto_organize', true);
        app(SettingsService::class)->set('library_auto_organize', true);

        $item = $this->music();
        $source = $item->absoluteFilePath();

        app()->call([new EnrichMediaItemJob($item->id), 'handle']);

        $this->assertFileDoesNotExist($source);
        $this->assertStringContainsString('media/library/', (string) $item->fresh()->file_path);
    }

    public function test_the_env_default_still_applies_when_nothing_is_stored(): void
    {
        // A fresh install has no stored setting, and LIBRARY_AUTO_ORGANIZE in
        // .env is how the Mac is currently braked.
        config()->set('library.auto_organize', false);

        $this->assertFalse(app(LibrarySettings::class)->autoOrganize());

        config()->set('library.auto_organize', true);

        $this->assertTrue(app(LibrarySettings::class)->autoOrganize());
    }

    /**
     * A music item that is ready to be filed: real tags, exact confidence and
     * a file on the faked disk.
     */
    private function music(): MediaItem
    {
        $path = 'media/unsorted/chicago.mp3';
        Storage::disk('local')->put($path, 'audio');

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'Chicago',
            'file_path' => Storage::disk('local')->path($path),
            'match_confidence' => MatchConfidence::Exact,
            'owned' => true,
        ]);

        $item->musicMetadata()->create([
            'artist' => 'flipturn',
            'album' => 'Heavy Colors',
            'track_number' => 3,
        ]);

        return $item->fresh();
    }
}
