<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The secret that proves a poller is the machine that asked.
 *
 * `show()` handed the approved token to anyone who asked for the right id,
 * unauthenticated, and ids are sequential from 1. The endpoint has to stay
 * open — collecting the token is *how* a receiver authenticates — so the
 * receiver instead proves itself with a secret it generated before there was
 * anything to steal.
 *
 * Hashed, not stored in the clear: the plain value exists only on the machine
 * that made it up, so this column is worth nothing to anyone who reads the
 * database.
 *
 * Nullable on purpose. A request created before this existed has no claim and
 * must keep working, or deploying the fix would kill a transfer already
 * running — 786 files in, at the time of writing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_requests', function (Blueprint $table): void {
            $table->string('claim_hash')->nullable();
        });

        // The receiver's own copy, in the clear, because this is the machine
        // that made it up and has to present it back.
        Schema::table('transfers', function (Blueprint $table): void {
            $table->string('claim')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transfer_requests', function (Blueprint $table): void {
            $table->dropColumn('claim_hash');
        });

        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropColumn('claim');
        });
    }
};
