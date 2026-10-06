<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pipeline's own state, and a journal of every file move (#465).
 *
 * `processing_status` stays exactly as it is: clients read it, and it remains
 * the visibility summary. These columns record *how far* a file got and *what
 * is happening to it*, which `processing_status` cannot express — it has one
 * value for "somewhere in a ten-stage pipeline" and none for "parked for a
 * person".
 *
 * Existing rows are backfilled by status rather than left null, so the sweeper
 * does not read the whole library as un-started work on the first run after
 * this deploys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table): void {
            // Nullable rather than defaulted: a row that has never entered the
            // pipeline is a real state, distinct from one parked at the first
            // stage, and the backfill below decides which existing rows are
            // which.
            $table->string('pipeline_stage')->nullable()->after('processing_status');
            $table->string('pipeline_state')->nullable()->after('pipeline_stage');
            $table->unsignedSmallInteger('pipeline_attempts')->default(0)->after('pipeline_state');
            $table->text('pipeline_error')->nullable()->after('pipeline_attempts');
            $table->timestamp('pipeline_updated_at')->nullable()->after('pipeline_error');

            // The sweeper's query: everything stuck or lost, oldest first.
            $table->index(['pipeline_state', 'pipeline_updated_at'], 'media_items_pipeline_sweep_index');
            $table->index(['pipeline_stage', 'pipeline_state'], 'media_items_pipeline_stage_index');
        });

        Schema::create('file_moves', function (Blueprint $table): void {
            $table->id();

            // Nullable: a move can outlive its item (a trashed file whose row
            // was then removed), and the journal is the record of what happened
            // on disk regardless of what the catalogue now says.
            $table->foreignId('media_item_id')->nullable()->constrained()->nullOnDelete();

            // Groups the moves of one operation, so an undo reverses all of it
            // -- a film plus its three sidecars, or a batch of 500 from a
            // reprocess run.
            $table->uuid('batch_id')->nullable()->index();

            $table->string('kind');
            $table->text('from_path');
            $table->text('to_path')->nullable();

            // Recorded BEFORE the move, which is what makes recovery
            // unambiguous: the reconciler can tell "the target is the file we
            // moved" from "the target is something else that was already
            // there".
            $table->unsignedBigInteger('from_device')->nullable();
            $table->unsignedBigInteger('from_inode')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('hash_before')->nullable();
            $table->string('hash_after')->nullable();

            $table->string('state')->default('planned');
            $table->text('error')->nullable();

            $table->timestamps();
            $table->timestamp('completed_at')->nullable();

            // The reconciler's query: anything left mid-move.
            $table->index(['state', 'created_at']);
        });

        $this->backfill();
    }

    /**
     * Gives every existing row a stage consistent with the status it already
     * has.
     *
     * Without this the sweeper would treat 8,000 settled items as never having
     * started and re-run the whole pipeline over them on its first tick.
     *
     * `complete` items are `Published`/`Done` -- they are in the library and
     * nothing is owed. `needs_review` items are parked at whichever stage
     * their review implies, and `Identified`/`Waiting` is the honest guess:
     * something was found and a person was asked. `failed` keeps its error.
     * `pending` and `processing` are the ones that were genuinely stranded, and
     * they start again from the beginning.
     */
    private function backfill(): void
    {
        $map = [
            'complete' => ['published', 'done'],
            'needs_review' => ['identified', 'waiting'],
            'failed' => ['identified', 'failed'],
            'pending' => ['catalogued', 'queued'],
            'processing' => ['catalogued', 'queued'],
        ];

        foreach ($map as $status => [$stage, $state]) {
            \DB::table('media_items')
                ->where('processing_status', $status)
                ->update([
                    'pipeline_stage' => $stage,
                    'pipeline_state' => $state,
                    // Not now(): a backfilled row has not been touched by the
                    // pipeline, and stamping it now would make every stuck
                    // item look freshly updated for one timeout window.
                    'pipeline_updated_at' => null,
                ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('file_moves');

        Schema::table('media_items', function (Blueprint $table): void {
            $table->dropIndex('media_items_pipeline_sweep_index');
            $table->dropIndex('media_items_pipeline_stage_index');
            $table->dropColumn([
                'pipeline_stage',
                'pipeline_state',
                'pipeline_attempts',
                'pipeline_error',
                'pipeline_updated_at',
            ]);
        });
    }
};
