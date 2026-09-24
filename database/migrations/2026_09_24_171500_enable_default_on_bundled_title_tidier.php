<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Turns the bundled Title Tidier on where it was never deliberately turned off
 * (S-361).
 *
 * S-321 made every plugin opt-in, and the app's own title cleanup went with it:
 * the `metadata.title` filter chain has been empty on every install since, so
 * `EnrichMediaItemJob::tidyTitle()` has been a no-op and titles kept their
 * artist credits. The manifest now carries `enabledByDefault`, but that is only
 * read when the install row is created — existing installs already have a row
 * saying disabled, so they would never pick the default up.
 *
 * Only rows never modified since they were created are touched: an untouched
 * row is the default nobody chose, while a row whose `updated_at` has moved is
 * an operator decision this migration has no business overriding.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('installed_plugins')
            ->where('plugin_id', 'soundchex.title-tidier')
            ->where('enabled', false)
            ->whereColumn('created_at', '=', 'updated_at')
            ->update(['enabled' => true]);
    }

    public function down(): void
    {
        // Deliberately irreversible: by the time this rolls back the operator
        // may have chosen to keep the tidier on, and turning it off again would
        // discard that choice rather than restore a prior state.
    }
};
