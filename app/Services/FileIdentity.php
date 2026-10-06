<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

/**
 * Answers "are these two paths the same file?" — by asking the filesystem.
 *
 * Every part of this app that moves or deletes a file has to decide whether a
 * source and a target are one file or two. Comparing the path *strings* is the
 * obvious implementation and it is wrong, because several ordinary situations
 * give one file two names:
 *
 *  - A case-insensitive volume — the default on macOS and Windows — resolves
 *    `03 Chicago.mp3` and `03 CHICAGO.mp3` to one file.
 *  - A symlinked library root, or a path containing `.` or `..`.
 *  - A hardlink, where two directory entries share one inode by design.
 *
 * Treating one file as two is not a cosmetic bug. `LibraryOrganizer` compared
 * strings, concluded a re-cased target was a different file, hashed both paths,
 * found the hashes equal — *because they were one file* — and deleted "the
 * redundant copy", which was the user's only copy. `DuplicateDetector::merge()`
 * had the same flaw. That is tracker #454, and this class exists so the answer
 * is computed once, correctly, in one place.
 *
 * Device + inode is the filesystem's own notion of identity, so that is what
 * this uses, with a documented fallback for Windows (below).
 */
class FileIdentity
{
    /**
     * Whether two paths name the same file.
     *
     * An identical string is trivially the same file and answerable without
     * touching the disk. Anything else is asked of the filesystem.
     */
    public static function same(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return self::sameInode($a, $b);
    }

    /**
     * Whether two *existing* paths resolve to one inode.
     *
     * Kept separate from `same()` because callers need to tell "the same file
     * under another spelling" (rename it, keep it) apart from "already at the
     * target" (do nothing) — the two have different correct actions.
     *
     * Returns false when either path is missing: a file that is not there is
     * not the same file as one that is.
     */
    public static function sameInode(string $a, string $b): bool
    {
        if (! file_exists($a) || ! file_exists($b)) {
            return false;
        }

        $statA = @stat($a);
        $statB = @stat($b);

        if ($statA === false || $statB === false) {
            return false;
        }

        // Windows' PHP reports inode 0 for every file, so device+inode cannot
        // distinguish anything there and would call every pair identical —
        // catastrophically wrong for a check that gates deletes. Fall back to
        // the resolved real path, which normalises case on a case-insensitive
        // volume and so still catches the case that matters.
        if ((int) $statA['ino'] === 0 || (int) $statB['ino'] === 0) {
            return self::sameRealPath($a, $b);
        }

        return $statA['dev'] === $statB['dev'] && $statA['ino'] === $statB['ino'];
    }

    /**
     * Whether two paths resolve to one canonical path.
     *
     * The Windows fallback, and useful on its own for a path that may contain
     * `.`, `..` or a symlinked parent.
     */
    public static function sameRealPath(string $a, string $b): bool
    {
        $realA = @realpath($a);
        $realB = @realpath($b);

        if ($realA === false || $realB === false) {
            return false;
        }

        // Case-insensitively, because that is the premise of the fallback: on a
        // case-insensitive volume the two spellings are one file even though
        // `realpath()` may hand back the spelling it was given.
        return strcasecmp($realA, $realB) === 0;
    }
}
