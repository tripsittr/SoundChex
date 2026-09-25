<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models\Scopes;

use App\Enums\ProcessingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides anything unresolved from the library (S-396).
 *
 * The rule: if an item is not certain, or is waiting for someone to look at
 * it, it does not appear in the library until that is settled. It is still in
 * the database and still in the admin panel — hidden, not deleted, so a
 * re-scan or a review can bring it back without anything having been lost.
 *
 * ## Why a global scope, and not `ContentGate`
 *
 * `ContentGate` looks like the obvious home: it is already the one call every
 * browse and search query makes, and its own comment warns that adding a gate
 * to eight query sites is how it ends up applied in seven. That warning is
 * exactly why this is not there. An audit of the read paths found nine
 * user-facing queries that never reach `ContentGate` — the "more like this"
 * rail, the watchlist rail, the nav counts, the genre counts, `albumQueue()`
 * (the play queue), the playlist cover mosaics, three Blade tile lookups —
 * plus every route-model-bound `MediaItem $item`, which is how streaming,
 * reading, subtitles and progress all resolve their item.
 *
 * A global scope is the only construct that covers a `findOrFail` in a route
 * binding. Putting it anywhere else would leave a hidden item unlistable but
 * still streamable by id, which is not hidden at all.
 *
 * ## What this costs
 *
 * Everything that legitimately needs to see unresolved items must now say so.
 * That is the admin panel, the maintenance commands and jobs, the transfer
 * endpoints and the health counts. They opt out with `MediaItem::unresolved()`
 * — see the model — and forgetting one shows up as an admin screen that has
 * gone quietly empty rather than as leaked data. That is the safer direction
 * for the mistake to run in.
 *
 * ## What counts as unresolved
 *
 * - `processing_status` of `needs_review`, `pending`, `processing` or `failed`.
 *   Only `complete` is certain.
 * - `file_missing`, the flag the scanner sets when a catalogued file is gone
 *   from disk (S-389). Whether a file exists is a disk question and cannot be
 *   asked in SQL, so the scan answers it and this reads the answer.
 */
class ResolvedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $table = $model->getTable();

        $builder
            ->where($table.'.processing_status', ProcessingStatus::Complete->value)
            ->where($table.'.file_missing', false);
    }
}
