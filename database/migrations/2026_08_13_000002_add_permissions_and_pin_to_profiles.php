<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capability per person, not per household.
 *
 * A household shares one account, so permission on the User meant everyone on
 * it had identical rights. What was wanted was "Syd can add files, the kids
 * cannot" — which is a property of the person, and the person is the profile.
 *
 * A PIN comes with it out of necessity. Switching profiles is one click and
 * always has been; without a PIN, a profile granted extra rights could be
 * entered by anyone on the account, and the permission would be a label rather
 * than a boundary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_permissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['profile_id', 'permission_id']);
        });

        Schema::table('profiles', function (Blueprint $table) {
            // Hashed, never stored in the clear — it guards real capability,
            // so it is treated as a credential rather than a preference.
            $table->string('pin_hash')->nullable()->after('is_kids');

            // Failed attempts, so a four-digit PIN cannot be brute-forced by
            // someone with the phone in their hand.
            $table->unsignedTinyInteger('pin_attempts')->default(0)->after('pin_hash');
            $table->timestamp('pin_locked_until')->nullable()->after('pin_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['pin_hash', 'pin_attempts', 'pin_locked_until']);
        });

        Schema::dropIfExists('profile_permissions');
    }
};
