<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to put the awards and the critic scores (#503, #504).
 *
 * `imdb_rating` and `rt_score` have existed on `movie_metadata` since it was
 * created and **nothing ever wrote them** — both are null on every row, because
 * the source that carries them (OMDb) was never implemented and its key was one
 * of the seven the integrations audit found that no code reads.
 *
 * Awards had nowhere to go at all: no column on `media_items`, `movie_metadata`
 * or `show_metadata`. OMDb returns it as a sentence — "Nominated for 7 Oscars.
 * 21 wins & 43 nominations total" — so it is stored as text rather than parsed
 * into counts. Parsing it would invent structure the source does not have, and
 * the sentence is what a detail page shows anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movie_metadata', function (Blueprint $table): void {
            $table->string('awards')->nullable()->after('rt_score');

            // Metacritic, which OMDb returns beside the other two. Stored
            // because a detail page showing two scores and not the third looks
            // like something failed rather than like a choice.
            $table->unsignedTinyInteger('metascore')->nullable()->after('awards');
        });

        // Shows get the same three. OMDb answers for a series by its IMDb id,
        // and a show with no ratings beside a film that has them would read as
        // a bug in the page rather than a gap in the data.
        Schema::table('show_metadata', function (Blueprint $table): void {
            $table->decimal('imdb_rating', 3, 1)->nullable()->after('tvmaze_id');
            $table->unsignedTinyInteger('rt_score')->nullable()->after('imdb_rating');
            $table->string('awards')->nullable()->after('rt_score');
            $table->unsignedTinyInteger('metascore')->nullable()->after('awards');
            $table->string('imdb_id')->nullable()->after('tvmaze_id');
        });
    }

    public function down(): void
    {
        Schema::table('movie_metadata', function (Blueprint $table): void {
            $table->dropColumn(['awards', 'metascore']);
        });

        Schema::table('show_metadata', function (Blueprint $table): void {
            $table->dropColumn(['imdb_rating', 'rt_score', 'awards', 'metascore', 'imdb_id']);
        });
    }
};
