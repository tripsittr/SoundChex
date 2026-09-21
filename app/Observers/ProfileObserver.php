<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Observers;

use App\Events\ProfileCreated;
use App\Events\ProfileDeleted;
use App\Models\Profile;

/**
 * Fires the profile lifecycle events from the model itself.
 *
 * Profiles are created and removed from several places — the web picker, the
 * Filament admin, and the on-demand default that CurrentProfile mints for a
 * fresh account. Hanging the events off the model rather than each call site
 * means every one of those paths emits them, and a new path added later gets
 * them for free.
 */
class ProfileObserver
{
    public function created(Profile $profile): void
    {
        ProfileCreated::dispatch($profile);
    }

    public function deleted(Profile $profile): void
    {
        ProfileDeleted::dispatch($profile);
    }
}
