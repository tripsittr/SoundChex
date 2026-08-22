<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room for an artist profile.
 *
 * `people` has carried a name, a TMDB id, a MusicBrainz id and a headshot
 * since it was built for film. An artist page needs more than a name to be
 * worth visiting, and MusicBrainz gives all of it away without an API key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            // Person or Group. Changes what the page can sensibly say: a band
            // has members and no birthday.
            $table->string('artist_type')->nullable()->after('musicbrainz_artist_id');

            $table->string('country', 2)->nullable()->after('artist_type');

            // Formed and disbanded, or born and died. Stored as given rather
            // than as dates: MusicBrainz returns a year alone as often as a
            // full date, and widening it to 1 January would be inventing one.
            $table->string('began')->nullable()->after('country');
            $table->string('ended')->nullable()->after('began');

            // A short line disambiguating same-named artists — MusicBrainz's
            // own, and often the most useful sentence on the page.
            $table->string('disambiguation')->nullable()->after('ended');

            $table->text('biography')->nullable()->after('disambiguation');

            // Where the image came from, kept so it can be re-fetched or
            // attributed. The file itself is stored locally rather than
            // hotlinked from Wikimedia.
            $table->string('image_source')->nullable()->after('headshot_url');

            // When the profile was last looked up, so a refresh knows what is
            // stale and a failed lookup is not retried on every page view.
            $table->timestamp('profile_synced_at')->nullable()->after('image_source');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn([
                'artist_type', 'country', 'began', 'ended',
                'disambiguation', 'biography', 'image_source', 'profile_synced_at',
            ]);
        });
    }
};
