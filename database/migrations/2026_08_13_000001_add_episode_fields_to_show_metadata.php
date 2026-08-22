<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-episode identity for television.
 *
 * `show_metadata` carried series totals — how many seasons, how many episodes
 * — but nothing saying *which* episode a file is. So every episode of a series
 * resolved to the same path and collided: the second became "Show (2).mkv",
 * numbered by import order rather than episode order.
 *
 * An episode is a MediaItem in its own right, since it has its own file,
 * playback position and subtitles. `parent_id` ties it to the series, so a
 * show is one row with many children rather than ten unrelated rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('show_metadata', function (Blueprint $table) {
            // Null on a series row, set on an episode. That difference is what
            // distinguishes the two.
            $table->unsignedSmallInteger('season_number')->nullable()->after('episode_count');
            $table->unsignedSmallInteger('episode_number')->nullable()->after('season_number');
            $table->string('episode_title')->nullable()->after('episode_number');
            $table->date('episode_air_date')->nullable()->after('episode_title');

            $table->index(['season_number', 'episode_number']);
        });

        Schema::table('media_items', function (Blueprint $table) {
            // The series this episode belongs to. Deleting a series takes its
            // episodes, which is what someone removing a show intends.
            $table->foreignId('parent_id')
                ->nullable()
                ->after('user_id')
                ->constrained('media_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });

        Schema::table('show_metadata', function (Blueprint $table) {
            $table->dropIndex(['season_number', 'episode_number']);
            $table->dropColumn([
                'season_number',
                'episode_number',
                'episode_title',
                'episode_air_date',
            ]);
        });
    }
};
