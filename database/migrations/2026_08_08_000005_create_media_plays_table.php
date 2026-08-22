<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per play/view/read event.
 *
 * Kept as an event log rather than a counter column so the dashboard can answer
 * "what was played this week" and "who played it", not just a lifetime total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_plays', function (Blueprint $table) {
            $table->id();

            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // How far in the user got, when we can tell. Null for media where
            // progress isn't tracked (a book marked read, say).
            $table->unsignedInteger('position_seconds')->nullable();
            $table->boolean('completed')->default(false);

            $table->timestamps();

            // Dashboard queries filter by org and order by recency.
            $table->index(['organization_id', 'created_at']);
            $table->index(['media_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_plays');
    }
};
