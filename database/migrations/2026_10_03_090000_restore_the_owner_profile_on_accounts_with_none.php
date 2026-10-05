<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives back the owner profile that a fresh install never got.
 *
 * `add_owner_flag_to_profiles` marks the first profile on each account as its
 * owner, and that is the right rule — but it can only mark profiles that exist
 * when it runs. On a new install migrations run before anyone has registered,
 * so there was nothing to mark, and the profile created at first use was not
 * marked either.
 *
 * The result was an account holding the `owner` role whose profile said
 * otherwise. `User::canAccessPanel` asks the profile, so the person who set the
 * server up was refused the admin panel and sent to Filament's login — while
 * already signed in, which makes it look like the session is not shared.
 *
 * Only accounts with no owner profile at all are touched. An install that has
 * deliberately moved ownership keeps its arrangement.
 */
return new class extends Migration
{
    public function up(): void
    {
        $accounts = DB::table('profiles')->select('user_id')->distinct()->pluck('user_id');

        foreach ($accounts as $userId) {
            $hasOwner = DB::table('profiles')
                ->where('user_id', $userId)
                ->where('is_owner', true)
                ->exists();

            if ($hasOwner) {
                continue;
            }

            // The same choice the original migration makes: oldest first, and
            // the account's default ahead of it if one is set.
            $first = DB::table('profiles')
                ->where('user_id', $userId)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->value('id');

            if ($first !== null) {
                DB::table('profiles')->where('id', $first)->update(['is_owner' => true]);
            }
        }
    }

    /**
     * Not reversed. Clearing the flag again would lock the owner out of their
     * own library, and there is no record of which accounts this touched.
     */
    public function down(): void
    {
        //
    }
};
