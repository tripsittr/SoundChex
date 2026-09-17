<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two different notions of "service", deliberately kept apart.
 *
 * `source_service` on the item records where a copy came from — a Netflix
 * original, a Disney disc, a Blu-ray. It's a fact about the user's own file,
 * set by hand, and never changes on its own.
 *
 * `media_availability` records where a title can be streamed right now,
 * fetched from TMDB. Licensing shifts constantly, so it's a cache with a
 * fetched-at stamp rather than a property of the item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            // Slug from config/providers.php, e.g. 'netflix', 'bluray'.
            $table->string('source_service', 48)->nullable()->after('matched_by');
            $table->index('source_service');
        });

        Schema::create('media_availability', function (Blueprint $table) {
            $table->id();

            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();

            $table->string('provider_slug', 48);
            $table->string('provider_name');
            $table->string('logo_url')->nullable();

            // stream | rent | buy — "on Netflix" and "rentable on Apple TV"
            // are different answers to "can I watch this".
            $table->string('offer_type', 16)->default('stream');

            // Availability is per-country, so a row without one is meaningless.
            $table->string('region', 8)->default('US');

            $table->timestamps();

            $table->unique(['media_item_id', 'provider_slug', 'offer_type', 'region'], 'availability_unique');
            $table->index(['provider_slug', 'region']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_availability');

        Schema::table('media_items', function (Blueprint $table) {
            $table->dropIndex(['source_service']);
            $table->dropColumn('source_service');
        });
    }
};
