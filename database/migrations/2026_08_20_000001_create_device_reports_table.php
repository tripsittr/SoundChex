<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a device says went wrong.
 *
 * A phone has no console anyone can reach, so a failed navigation is a white
 * flash and nothing else — and asking someone to read a diagnostic panel aloud
 * is a poor substitute for the server having the log. These arrive on their own.
 *
 * Deliberately small: what happened, where, and which build. No page contents,
 * no titles, nothing about what is being watched — a crash report should not
 * become a record of someone's viewing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_reports', function (Blueprint $table) {
            $table->id();

            // Which device, without identifying a person: a random id the
            // client generates and keeps. Enough to tell one phone's reports
            // from another's when both are misbehaving.
            $table->string('device', 40)->index();

            $table->string('platform', 40)->nullable();

            // The build the device was running, so a report can be matched to
            // the code that produced it.
            $table->string('build', 40)->nullable();

            // Where it was connected, which is the difference between a fast
            // route and a relay and has explained more than one failure.
            $table->string('origin')->nullable();

            /** @var array<int, array{kind: string, at: int, path: string, detail: array}> */
            $table->json('events');

            $table->timestamps();

            // Every read is "what has been reported recently".
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_reports');
    }
};
