<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the receiver's progress is kept, on the source.
 *
 * The machine doing the copying is the only one that knows how far it has got,
 * and the machine being copied has no way to ask. Both of us guessed instead:
 * one read byte counters and called a running transfer stalled, twice; the
 * other reported a queue count it had just changed by hand.
 *
 * The receiver posts these; the source only reads them. Nothing here is
 * trusted for anything but display — a receiver could report whatever it
 * liked, and the worst it can do is lie about its own progress.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_requests', function (Blueprint $table): void {
            $table->unsignedInteger('items_total')->nullable();
            $table->unsignedInteger('items_complete')->nullable();
            $table->unsignedInteger('items_failed')->nullable();
            $table->unsignedInteger('items_skipped')->nullable();
            $table->unsignedInteger('items_pending')->nullable();

            // Bytes rather than GB: rounding a 46.3 GB copy to one decimal
            // place hides an hour of work.
            $table->unsignedBigInteger('bytes_complete')->nullable();
            $table->unsignedBigInteger('bytes_total')->nullable();

            $table->string('progress_state')->nullable();
            $table->string('progress_note')->nullable();

            // Separate from `updated_at`, which moves for reasons that have
            // nothing to do with the copy. Silence here is the signal that
            // matters: a report that has not arrived for ten minutes means
            // something stopped, and that is exactly what neither machine
            // could see before.
            $table->timestamp('progress_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transfer_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'items_total',
                'items_complete',
                'items_failed',
                'items_skipped',
                'items_pending',
                'bytes_complete',
                'bytes_total',
                'progress_state',
                'progress_note',
                'progress_at',
            ]);
        });
    }
};
