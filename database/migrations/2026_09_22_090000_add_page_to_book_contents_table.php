<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The source page a reflowable unit came from (S-298).
 *
 * `position` is reading order; `page` is the physical page in the source PDF, so
 * the reader can show "Page 42" as the reader scrolls. Null for formats without
 * fixed pages (EPUB), which show the chapter instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('book_contents', function (Blueprint $table) {
            $table->unsignedInteger('page')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('book_contents', function (Blueprint $table) {
            $table->dropColumn('page');
        });
    }
};
