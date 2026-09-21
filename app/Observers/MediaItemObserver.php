<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Observers;

use App\Events\MediaItemDeleted;
use App\Models\MediaItem;

/**
 * Fires `media.deleted` from the model, so every removal path emits it.
 *
 * An item can leave the library from the Filament admin, a duplicate merge, a
 * transfer that moves it away, or a future bulk tool. Hanging the event off the
 * model catches all of them at once.
 *
 * There is deliberately no `created` handler: a row appearing is
 * `media.catalogued` (fired by the scanner the instant a file is seen), and the
 * "settled, kept item" milestone is `media.added`, fired from EnrichMediaItemJob
 * once enrichment has finished and could no longer reject it. Firing `media.added`
 * on model-create would collapse three distinct lifecycle points into one.
 */
class MediaItemObserver
{
    public function deleted(MediaItem $item): void
    {
        MediaItemDeleted::dispatch($item);
    }
}
