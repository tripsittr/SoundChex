<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The token a receiver is given once its request is approved.
 *
 * Sanctum shows a plain-text token once and stores only a hash, which is right
 * for a token a person copies. This one is collected by a machine polling an
 * endpoint, and a receiver that loses it between the poll and the transfer
 * would otherwise need a person to approve the whole thing again.
 *
 * So it is kept here, on the machine that issued it, until the request ends —
 * at which point revoking deletes both the Sanctum row and this copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_requests', function (Blueprint $table) {
            $table->text('plain_token')->nullable()->after('token_id');
        });
    }

    public function down(): void
    {
        Schema::table('transfer_requests', function (Blueprint $table) {
            $table->dropColumn('plain_token');
        });
    }
};
