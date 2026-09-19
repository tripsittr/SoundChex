<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records where a play came from (S-120).
 *
 * A play stamped only the item, user and profile — never the surface it was
 * started from (an album, an artist page, a playlist, search, the home rails).
 * That is exactly the signal "you play this artist mostly from search" needs.
 * Nullable, because older rows have no source and a play from an unknown surface
 * (a direct link, an old client) records none rather than a wrong one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_plays', function (Blueprint $table) {
            $table->string('source', 32)->nullable()->after('profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_plays', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
