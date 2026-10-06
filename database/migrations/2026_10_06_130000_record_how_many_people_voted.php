<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many people voted, beside what they voted (#511).
 *
 * OMDb returns `imdbVotes` on every lookup and nothing stored it. A score
 * without a count is much weaker than it looks: **9.3** could be three people
 * or three million, and IMDb never shows one without the other for that reason.
 *
 * An unsigned integer rather than the string OMDb sends — it arrives formatted
 * as "3,235,958", which is a presentation decision the client should make
 * against its own locale rather than one baked into the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movie_metadata', function (Blueprint $table): void {
            $table->unsignedInteger('imdb_votes')->nullable()->after('imdb_rating');
        });

        Schema::table('show_metadata', function (Blueprint $table): void {
            $table->unsignedInteger('imdb_votes')->nullable()->after('imdb_rating');
        });
    }

    public function down(): void
    {
        Schema::table('movie_metadata', function (Blueprint $table): void {
            $table->dropColumn('imdb_votes');
        });

        Schema::table('show_metadata', function (Blueprint $table): void {
            $table->dropColumn('imdb_votes');
        });
    }
};
