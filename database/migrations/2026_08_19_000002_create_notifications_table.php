<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Events worth telling someone about.
 *
 * Written by the server when something happens — a new episode filed, a scan
 * finished — and collected by each device when it next asks. A row per event
 * rather than per recipient: every profile that may see the item sees the same
 * event, and fanning out at write time would mean rewriting history whenever a
 * profile's permissions changed.
 *
 * This is not push. A device only learns of these while the app is open and
 * asking; reaching a phone in someone's pocket needs Apple's service, which is
 * a separate piece of infrastructure entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            // What happened, as a stable machine-readable key: the client
            // decides whether this profile wants it, and matching on display
            // text would break the moment the wording changed.
            $table->string('type', 40)->index();

            $table->string('title');
            $table->string('body', 500)->nullable();

            // What it is about, so tapping the notification can open it. Null
            // for events with no single subject, such as a scan summary.
            $table->foreignId('media_item_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // Every read is "what has happened since I last asked".
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
