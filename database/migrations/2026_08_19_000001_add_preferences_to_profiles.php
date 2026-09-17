<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-profile preferences.
 *
 * The `settings` table is the server's own configuration — one row per key, for
 * the whole installation. These are the opposite: they belong to whoever is
 * watching, and two profiles on one device should not share them.
 *
 * One JSON column rather than a column per preference. These are read together,
 * written together, and never queried on individually; a column per toggle
 * would mean a migration for every new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->json('preferences')->nullable()->after('is_owner');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
