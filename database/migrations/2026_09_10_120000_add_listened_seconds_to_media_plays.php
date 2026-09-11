<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long was actually listened, as distinct from where playback got to.
 *
 * `position_seconds` is a resume bookmark: it is overwritten on every save, so
 * a track played to 3:00 twice is indistinguishable from one played once. It
 * answers "where do I start again", not "how long did this hold your
 * attention", and the statistics page needs the second question answered.
 *
 * Accumulated rather than derived, because the alternative — plays × the
 * track's duration — counts a ten-second skip as a full listen, which is
 * exactly the number a statistics page must not quietly get wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_plays', function (Blueprint $table): void {
            // Nullable rather than defaulted to zero: null means "this row
            // predates the column and we genuinely do not know", which a chart
            // can exclude. A zero would claim it was played for no time at
            // all, which is a different and false statement — and with 505
            // existing rows, guessing would poison the first month of data.
            $table->unsignedInteger('listened_seconds')->nullable()->after('position_seconds');

            // The statistics page groups by profile over a date range, and
            // every one of those queries filters on exactly this pair.
            $table->index(['profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('media_plays', function (Blueprint $table): void {
            $table->dropIndex(['profile_id', 'created_at']);
            $table->dropColumn('listened_seconds');
        });
    }
};
