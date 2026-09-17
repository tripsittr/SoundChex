<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Music credits, in the table that already holds credits.
 *
 * `media_item_person` has carried author, actor, director, producer and writer
 * since books and film were built. `role` is a plain varchar, so music roles
 * need nothing added — what is missing is a guard against writing the same
 * credit twice, and somewhere to keep the primary artist for grouping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('music_metadata', function (Blueprint $table) {
            // Denormalised from the credit with role `primary_artist`.
            //
            // The credits are the truth; this is the index. Browsing groups and
            // paginates in SQL, and a join per artist row does not paginate
            // well across 1,438 tracks.
            //
            // Deliberately not `artist`: LibraryOrganizer builds Artist/Album/
            // from that column, and rewriting it would move files on disk.
            $table->string('primary_artist')->nullable()->after('artist');
            $table->index('primary_artist');
        });

        Schema::table('media_item_person', function (Blueprint $table) {
            // One credit per person per role per item. Enrichment re-runs on
            // every scan, and without this each run would attach another copy
            // of every credit it already wrote.
            $table->unique(['media_item_id', 'person_id', 'role'], 'media_item_person_unique');
        });
    }

    public function down(): void
    {
        Schema::table('music_metadata', function (Blueprint $table) {
            $table->dropIndex(['primary_artist']);
            $table->dropColumn('primary_artist');
        });

        Schema::table('media_item_person', function (Blueprint $table) {
            $table->dropUnique('media_item_person_unique');
        });
    }
};
