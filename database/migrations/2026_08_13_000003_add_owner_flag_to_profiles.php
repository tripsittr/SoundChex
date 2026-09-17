<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the household owner.
 *
 * The household shares one login, so the account cannot decide capability —
 * everyone signing in would hold the same rights. The owner profile is the one
 * that grants rights to the others, and it short-circuits every permission
 * check so a household can never end up with nobody able to administer it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->boolean('is_owner')->default(false)->after('is_kids');
        });

        // The first profile on each account is its owner: it was created with
        // the account, before anyone could have made another.
        foreach (DB::table('profiles')->select('user_id')->distinct()->pluck('user_id') as $userId) {
            $first = DB::table('profiles')
                ->where('user_id', $userId)
                ->orderBy('id')
                ->value('id');

            if ($first !== null) {
                DB::table('profiles')->where('id', $first)->update(['is_owner' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('is_owner');
        });
    }
};
