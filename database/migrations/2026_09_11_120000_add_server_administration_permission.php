<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The permission that separates administering the library from administering
 * the machine underneath it.
 *
 * Most of the admin panel is about content — music, films, profiles, settings,
 * statistics — and belongs to anyone trusted to run the household's library.
 * Four pages are not: `Services` can stop the web server that is serving the
 * page you are reading it on, `ServerTransfer` copies tens of gigabytes
 * between machines and deletes on failure, `Network` decides which addresses
 * clients race, and `DeviceReports` is diagnostics.
 *
 * Created here rather than left to Shield's generator, because a permission
 * that does not exist cannot be granted — the pages would be reachable only by
 * the owner short-circuit, and every other profile would be refused with no
 * way to fix it short of a database edit.
 */
return new class extends Migration
{
    private const NAME = 'Access:ServerAdministration';

    public function up(): void
    {
        $exists = DB::table('permissions')->where('name', self::NAME)->exists();

        if ($exists) {
            return;
        }

        DB::table('permissions')->insert([
            'name' => self::NAME,
            // The guard every other permission in this table uses. A mismatch
            // here is invisible: the row exists, and no check ever matches it.
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Existing owner profiles are not granted it explicitly — `can()`
        // short-circuits for the owner, and writing a row would suggest the
        // permission is what grants them access when it is not. A household
        // that could revoke its own administrator's access to the services
        // page would need database surgery to recover.
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', self::NAME)->value('id');

        if ($id === null) {
            return;
        }

        // The grants first: a foreign key would refuse the delete otherwise,
        // and leaving them would orphan rows pointing at nothing.
        DB::table('profile_permissions')->where('permission_id', $id)->delete();
        DB::table('role_has_permissions')->where('permission_id', $id)->delete();
        DB::table('model_has_permissions')->where('permission_id', $id)->delete();

        DB::table('permissions')->where('id', $id)->delete();
    }
};
