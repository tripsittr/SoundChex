<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One unified activity/audit timeline (S-284).
 *
 * Until now the record of what happened was scattered — MetadataVersion for
 * metadata edits, Notification for scan/episode events, DeviceReport for device
 * diagnostics, MediaPlay for playback. This is the single stream the audit-log
 * plugin writes every observed event to: who, what, when, and the details, in
 * one queryable place. The scattered trails stay as they are; this is a timeline
 * on top of the event surface, not a replacement for any of them.
 *
 * The table is owned by a bundled plugin, but it lives in a core migration so
 * the schema is versioned with the app and present on a fresh install — a plugin
 * that is always on should not ship its own migrations to run at an odd time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log_entries', function (Blueprint $table) {
            $table->id();

            // The event's dotted name — `playback.completed`, `media.deleted`.
            // Indexed because filtering by event type is the first thing anyone
            // does with an audit log.
            $table->string('event')->index();

            // A short, human line summarising what happened, built when the entry
            // is written so the timeline reads without re-deriving it per row.
            $table->string('summary');

            // Who, when it can be known. The profile that acted (nullable — a
            // scan or a server health check has no actor), denormalised name so a
            // deleted profile still reads sensibly in the history.
            $table->foreignId('profile_id')->nullable()->index();
            $table->string('actor_name')->nullable();

            // What it was about, when it is an item. Denormalised title for the
            // same reason — the item may be gone (this logs `media.deleted`), and
            // the timeline must still say what left.
            $table->foreignId('subject_id')->nullable()->index();
            $table->string('subject_type')->nullable();
            $table->string('subject_title')->nullable();

            // The event payload, reduced to a small JSON bag of scalars for the
            // detail view and filtering. Never the event objects themselves.
            $table->json('context')->nullable();

            // Its own creation time is the "when". No updated_at — an audit entry
            // is written once and never edited.
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log_entries');
    }
};
