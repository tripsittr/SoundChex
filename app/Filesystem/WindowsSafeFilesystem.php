<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filesystem;

use Illuminate\Filesystem\Filesystem;

/**
 * Makes `replace()` work on Windows.
 *
 * Laravel writes a file "atomically" by putting the content in a sibling temp
 * file and renaming it over the target. On POSIX that is genuinely atomic and
 * exactly right. On Windows, `rename()` is refused whenever anything else has
 * the target open — and under FrankenPHP, which serves requests on threads,
 * two requests compiling the same Blade view at the same moment do precisely
 * that.
 *
 * The loser of that race died with
 * `rename(...\views\c86C1D1.tmp, ...\c86042f8....php): Access is denied`, and
 * the panel showed "There was an error while attempting to load this page".
 * Reloading usually worked, because the winner had finished by then, which is
 * what made it look intermittent.
 *
 * Precompiling views does not solve it: Livewire and Filament render component
 * views that `view:cache` never sees, so compilation keeps happening during
 * requests — 315 compiled files against 246 cached ones on this install.
 *
 * On Windows this writes in place under an exclusive lock instead. That lock is
 * what the rename was providing: concurrent writers are serialised, and a
 * reader can no longer catch a half-written file. It gives up the guarantee
 * that the file is replaced in one indivisible step, which Windows was not
 * honouring here anyway — it was failing outright.
 *
 * POSIX keeps the original behaviour untouched.
 */
class WindowsSafeFilesystem extends Filesystem
{
    public function replace($path, $content, $mode = null): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            parent::replace($path, $content, $mode);

            return;
        }

        $this->ensureDirectoryExists(dirname($path));

        // LOCK_EX serialises two threads writing the same path, and the write
        // is a single call with the whole content, so there is no window where
        // the file exists but is short.
        file_put_contents($path, $content, LOCK_EX);

        if ($mode !== null) {
            @chmod($path, $mode);
        }
    }
}
