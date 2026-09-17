<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('organization_user')) {
            Schema::create('organization_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['organization_id', 'user_id']);
            });
        }

        // Backfill existing one-to-one assignments into pivot memberships.
        if (! Schema::hasColumn('users', 'organization_id')) {
            return;
        }

        $users = DB::table('users')
            ->whereNotNull('organization_id')
            ->orderBy('id')
            ->get(['id', 'organization_id']);

        if ($users->isNotEmpty()) {
            $now = now();

            $rows = $users
                ->map(fn ($user): array => [
                    'organization_id' => (int) $user->organization_id,
                    'user_id' => (int) $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            DB::table('organization_user')->upsert(
                $rows,
                ['organization_id', 'user_id'],
                ['updated_at'],
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_user');
    }
};
