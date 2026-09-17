<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('music_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('artist')->nullable();
            $table->string('album')->nullable();
            $table->unsignedSmallInteger('track_number')->nullable();
            $table->unsignedSmallInteger('disc_number')->nullable();
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->string('label')->nullable();
            $table->float('bpm')->nullable();
            $table->string('key', 5)->nullable(); // C, C#, Db, D…
            $table->string('scale', 10)->nullable(); // major | minor
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('sample_rate')->nullable();
            $table->unsignedTinyInteger('bit_depth')->nullable();
            $table->string('format', 10)->nullable(); // mp3, flac, wav, aiff…
            $table->string('isrc', 12)->nullable();
            $table->string('musicbrainz_recording_id', 36)->nullable();
            $table->string('musicbrainz_release_id', 36)->nullable();
            $table->string('acoustid', 36)->nullable();
            $table->string('spotify_id', 32)->nullable();
            $table->string('discogs_release_id')->nullable();
            $table->unsignedTinyInteger('energy')->nullable(); // 0–100, from Spotify
            $table->timestamps();
        });

        Schema::create('movie_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('director')->nullable();
            $table->string('studio')->nullable();
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->unsignedSmallInteger('runtime_minutes')->nullable();
            $table->unsignedInteger('tmdb_id')->nullable()->index();
            $table->string('imdb_id', 12)->nullable();
            $table->string('language', 10)->nullable();
            $table->string('country', 5)->nullable();
            $table->string('mpaa_rating', 10)->nullable(); // G, PG, PG-13, R, NC-17
            $table->text('tagline')->nullable();
            $table->decimal('imdb_rating', 3, 1)->nullable();
            $table->decimal('rt_score', 5, 2)->nullable(); // Rotten Tomatoes %
            $table->timestamps();
        });

        Schema::create('show_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('creator')->nullable();
            $table->string('network')->nullable();
            $table->unsignedSmallInteger('first_air_year')->nullable();
            $table->unsignedSmallInteger('last_air_year')->nullable();
            $table->unsignedInteger('tmdb_id')->nullable()->index();
            $table->unsignedInteger('tvdb_id')->nullable();
            $table->unsignedInteger('tvmaze_id')->nullable();
            $table->unsignedTinyInteger('season_count')->nullable();
            $table->unsignedSmallInteger('episode_count')->nullable();
            $table->string('status', 20)->nullable(); // ongoing | ended | cancelled
            $table->string('language', 10)->nullable();
            $table->timestamps();
        });

        Schema::create('book_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('author')->nullable();
            $table->string('publisher')->nullable();
            $table->unsignedSmallInteger('publish_year')->nullable();
            $table->string('isbn_10', 10)->nullable();
            $table->string('isbn_13', 13)->nullable();
            $table->string('open_library_id', 20)->nullable();
            $table->string('google_books_id', 20)->nullable();
            $table->unsignedSmallInteger('pages')->nullable();
            $table->string('language', 10)->nullable();
            $table->string('series_name')->nullable();
            $table->unsignedTinyInteger('series_position')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_metadata');
        Schema::dropIfExists('show_metadata');
        Schema::dropIfExists('movie_metadata');
        Schema::dropIfExists('music_metadata');
    }
};
