<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the catalogue has already been imported for this transfer.
 *
 * Importing replaces the whole database, so doing it twice would undo the rows
 * recording what arrived in between. The job re-runs itself every ten seconds
 * while files transfer, which is more than enough chances to do it twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->boolean('metadata_imported')->default(false)->after('wants');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn('metadata_imported');
        });
    }
};
