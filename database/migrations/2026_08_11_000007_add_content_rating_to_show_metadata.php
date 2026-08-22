<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TV certification, so a kids profile can filter shows.
 *
 * Movies carry `mpaa_rating`; shows had nowhere to record TV-Y through TV-MA,
 * which meant a rating cap silently let every series through — the one place a
 * content limit most needs to work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('show_metadata', function (Blueprint $table) {
            $table->string('content_rating', 12)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('show_metadata', function (Blueprint $table) {
            $table->dropColumn('content_rating');
        });
    }
};
