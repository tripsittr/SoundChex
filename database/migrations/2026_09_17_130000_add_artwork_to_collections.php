<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A playlist can have its own cover image (Spotify/Apple style). Stored as a
 * path under the public disk, like item artwork; nullable, because most
 * playlists never get one and fall back to a mosaic of their tracks' covers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->string('artwork_path')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn('artwork_path');
        });
    }
};
