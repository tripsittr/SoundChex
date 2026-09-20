<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record of which plugins are installed and enabled (S-264).
 *
 * The plugin's code and manifest live on disk under the plugins directory; this
 * table is the app's own view of them — the enable/disable state (the kill
 * switch), when each was installed, and a copy of the last-seen manifest so the
 * admin list can be rendered without re-scanning every folder on every request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installed_plugins', function (Blueprint $table) {
            $table->id();

            // The manifest id ("acme.discogs"). One row per plugin, ever.
            $table->string('plugin_id')->unique();

            $table->string('name');
            $table->string('version');

            // Off by default is the safe stance: installing a plugin puts its
            // files in place; a human turns it on. Disabling is the kill switch
            // — the loader skips a disabled row and none of its code runs.
            $table->boolean('enabled')->default(false);

            // The folder under the plugins path, kept so the loader can find the
            // plugin even if its id and directory name ever differ.
            $table->string('directory');

            // A snapshot of the manifest at install/last-scan, so the admin list
            // renders from the database rather than re-reading every plugin.json.
            $table->json('manifest')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installed_plugins');
    }
};
