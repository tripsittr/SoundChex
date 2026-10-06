<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;

/**
 * One spelling for the Spotify credentials (#490).
 *
 * Two parts of the app asked for the same thing under different names. The
 * Playlist Porter plugin registered `spotify.client_id` and
 * `spotify.client_secret` and holds a working sign-in against them; the
 * Integrations page separately invented `spotify_client_id` and
 * `spotify_client_secret` and read them nowhere else.
 *
 * The result on a real install: a `spotify_client_secret` holding a value that
 * is **not** the secret actually in use, no `spotify_client_id` at all, and an
 * Integrations page reporting Spotify as set up while the source it configures
 * could not authenticate. Filling the page's fields in did nothing, because
 * nothing read them.
 *
 * The dotted pair wins -- it is the one with a live token against it -- and the
 * underscored pair is carried over only where it has something the dotted one
 * lacks. That ordering matters: copying unconditionally would overwrite the
 * credential that currently works with the stale one.
 */
return new class extends Migration
{
    /** The underscored key, and the dotted one it belongs under. */
    private const MOVES = [
        'spotify_client_id' => 'spotify.client_id',
        'spotify_client_secret' => 'spotify.client_secret',
    ];

    public function up(): void
    {
        foreach (self::MOVES as $old => $new) {
            $stale = Setting::where('key', $old)->first();

            if ($stale === null) {
                continue;
            }

            $live = Setting::where('key', $new)->first();

            // Only adopted when the real key is empty. A value that is already
            // working is never replaced by one that was never read -- on this
            // install the two secrets differ, and preferring the stale one
            // would break the connection this migration is meant to preserve.
            if ($live === null || blank($live->value)) {
                app(SettingsService::class)
                    ->set($new, (string) $stale->value, encrypt: true);
            }

            $stale->delete();
        }
    }

    /**
     * Deliberately irreversible.
     *
     * Rolling back would mean re-creating a key that nothing reads, which is
     * the defect rather than the state worth returning to.
     */
    public function down(): void
    {
        //
    }
};
