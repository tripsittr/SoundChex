<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plugin repositories — the catalog URLs an admin browses to install from
 * (S-264 Phase 4b), the Emby/Jellyfin repository model.
 *
 * A repository is a URL serving a JSON manifest of plugins. One may be the
 * curated official one; others are added at the admin's own risk, which the UI
 * says plainly. Nothing is trusted by being listed here — the URL is a place to
 * fetch a catalog, and every install still passes the checksum and compatibility
 * gates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_repositories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url')->unique();

            // The curated first-party repository is marked so the UI can show it
            // as trusted and third-party ones as add-at-your-own-risk.
            $table->boolean('official')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_repositories');
    }
};
