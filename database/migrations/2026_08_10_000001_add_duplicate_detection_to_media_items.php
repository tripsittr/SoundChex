<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duplicate detection.
 *
 * A content hash is what makes "is this the same file?" answerable without
 * re-reading both files every time. It's stored once at scan time and then
 * compared as a plain indexed lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            // xxh128 of the file's bytes — 32 hex characters. Not a security
            // hash; it just has to be fast over multi-gigabyte video and
            // collision-free in practice.
            $table->string('content_hash', 32)->nullable()->index();

            // Set when this row is a duplicate of another. Nullable because
            // the overwhelming majority of rows aren't.
            $table->foreignId('duplicate_of_id')
                ->nullable()
                ->constrained('media_items')
                // The original going away shouldn't delete the copy that was
                // being kept as a spare; it just stops being a duplicate.
                ->nullOnDelete();

            // Pending review, kept deliberately, or merged away. Null means
            // this row has never been flagged.
            $table->string('duplicate_status')->nullable()->index();

            $table->timestamp('duplicate_detected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicate_of_id');
            $table->dropColumn([
                'content_hash',
                'duplicate_status',
                'duplicate_detected_at',
            ]);
        });
    }
};
