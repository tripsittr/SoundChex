<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The permission that lets a profile into the management panel at all.
 *
 * The panel had no floor: `canAccessPanel()` returned true for everyone and
 * every screen refused individually. That left two problems. A member or
 * uploader could reach the panel chrome and be bounced from each page one at a
 * time, and — worse — most screens asked for per-page permissions that were
 * never created (`Access:MetadataSettings`, `ViewAny:Music`), so they were
 * owner-only by accident rather than by decision.
 *
 * This is the content tier. A library administrator curates the catalogue:
 * metadata, uploads, library settings, statistics, and the media resources.
 * It sits below `Access:ServerAdministration` (the machine: services,
 * transfers, network, acquisition) and above members and uploaders, who are
 * now blocked from the panel entirely.
 *
 * Granted per profile, through the checklist on the profile screen — not
 * through a role. This app's permission unit is the profile: `Profile::can()`
 * reads the `profile_permissions` relation directly and never consults roles,
 * so a grant to the `admin` role would admit nobody and only look like it did.
 * The owner short-circuits and is not granted it explicitly, as with server
 * administration.
 */
return new class extends Migration
{
    private const NAME = 'Access:LibraryAdministration';

    public function up(): void
    {
        $exists = DB::table('permissions')->where('name', self::NAME)->exists();

        if ($exists) {
            return;
        }

        DB::table('permissions')->insert([
            'name' => self::NAME,
            // The guard every other permission uses. A mismatch here is
            // invisible: the row exists and no check ever matches it.
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Not granted to any role or profile here. `Profile::can()` reads the
        // profile_permissions relation, so access is assigned per profile
        // through the checklist on the profile screen — and the owner reaches
        // everything through the short-circuit regardless.
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', self::NAME)->value('id');

        if ($id === null) {
            return;
        }

        DB::table('profile_permissions')->where('permission_id', $id)->delete();
        DB::table('role_has_permissions')->where('permission_id', $id)->delete();
        DB::table('model_has_permissions')->where('permission_id', $id)->delete();

        DB::table('permissions')->where('id', $id)->delete();
    }
};
