<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Book-level OCR state, so the admin panel can show progress without counting
 * page rows on every table render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            // null | pending | running | complete | failed
            $table->string('ocr_status')->nullable()->index();
            $table->unsignedTinyInteger('ocr_percent')->default(0);

            // Total pages, cached from the PDF so the reader and the progress
            // bar don't each have to open the file to find out.
            $table->unsignedInteger('page_count')->nullable();

            // How many pages had no usable embedded text. Zero means the book
            // is fully digital and OCR would be pointless.
            $table->unsignedInteger('scanned_page_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropColumn(['ocr_status', 'ocr_percent', 'page_count', 'scanned_page_count']);
        });
    }
};
