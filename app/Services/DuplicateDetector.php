<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Enums\DuplicateStatus;
use App\Models\MediaItem;

/**
 * Finds and resolves byte-identical copies of catalogued files.
 *
 * Detection is exact-hash only. Two files count as the same when their bytes
 * are the same — a remaster, a different bitrate, or a re-encode is a
 * different file and is never flagged, so a confirmed duplicate can be deleted
 * without losing anything.
 *
 * Nothing here deletes on its own unless the configured action says to; the
 * default is to flag and wait.
 */
class DuplicateDetector
{
    public function __construct(private LibrarySettings $settings) {}

    /**
     * Hashes a file, cheaply and safely.
     *
     * Returns null when the file is unreadable or larger than the configured
     * limit — an un-hashed file simply never participates in duplicate
     * detection, which is the safe outcome.
     */
    public function hash(string $absolutePath): ?string
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return null;
        }

        $limit = $this->settings->hashLimitBytes();
        $size = @filesize($absolutePath);

        if ($size === false || ($limit > 0 && $size > $limit)) {
            return null;
        }

        $hash = @hash_file('xxh128', $absolutePath);

        return $hash === false ? null : $hash;
    }

    /**
     * Stores an item's content hash if it doesn't have one.
     *
     * @return string|null The hash, or null when the file couldn't be hashed.
     */
    public function ensureHashed(MediaItem $item): ?string
    {
        if (filled($item->content_hash)) {
            return $item->content_hash;
        }

        $path = $item->absoluteFilePath();

        if ($path === null) {
            return null;
        }

        $hash = $this->hash($path);

        if ($hash === null) {
            return null;
        }

        $item->forceFill(['content_hash' => $hash])->saveQuietly();

        return $hash;
    }

    /**
     * Flags an item if an earlier one holds identical bytes.
     *
     * A filed copy is treated as the original, and the oldest row only when
     * none of them is filed. There *is* a better criterion than arrival order:
     * the organiser put a copy in `media/library/` deliberately and the rest of
     * the catalogue points into that tree, so it is the copy everything else
     * expects to still be there. Both rules are stable across runs.
     *
     * @return MediaItem|null The original, when this item is a duplicate.
     */
    public function check(MediaItem $item): ?MediaItem
    {
        if (! $this->settings->detectDuplicates()) {
            return null;
        }

        // A decision the user already made is final — re-flagging a pair they
        // chose to keep would refill the review list forever.
        if ($item->duplicate_status?->isResolved()) {
            return null;
        }

        $hash = $this->ensureHashed($item);

        if ($hash === null) {
            return null;
        }

        $candidates = MediaItem::query()
            ->where('content_hash', $hash)
            ->where('id', '!=', $item->id)
            // Same type only: a cover image and an audio file could in
            // principle collide, and merging across types would be wrong.
            ->where('type', $item->type)
            // Chains are confusing to review, so a duplicate never becomes
            // somebody else's original.
            ->whereNull('duplicate_of_id')
            ->orderBy('id')
            ->get();

        // A filed copy outranks a loose one, whatever order they arrived in.
        //
        // The oldest row used to win outright, which decides which real file
        // gets deleted — and a loose copy catalogued first would survive while
        // the one the organiser had deliberately filed was removed. The
        // catalogue's other rows point into the filed tree, so that is the
        // copy everything else expects to still be there.
        $original = $candidates->first(fn (MediaItem $c) => $this->isFiled($c))
            ?? $candidates->first();

        if ($original === null) {
            return null;
        }

        $item->forceFill([
            'duplicate_of_id'       => $original->id,
            'duplicate_status'      => DuplicateStatus::Pending,
            'duplicate_detected_at' => now(),
        ])->saveQuietly();

        if ($this->settings->deletesDuplicatesAutomatically()) {
            $this->merge($item);
        }

        return $original;
    }

    /**
     * Deletes the duplicate's file and marks the row merged.
     *
     * The bytes are re-compared immediately before deleting. The hash may have
     * been recorded days ago and the file edited or replaced since; this is
     * the only irreversible step in the feature, so it verifies rather than
     * trusts stored state.
     */
    public function merge(MediaItem $duplicate): bool
    {
        $original = $duplicate->duplicateOf;

        if ($original === null) {
            return false;
        }

        $duplicatePath = $duplicate->absoluteFilePath();
        $originalPath = $original->absoluteFilePath();

        // Without a surviving original there is nothing to fall back to, so
        // deleting the copy would lose the content outright.
        if ($originalPath === null || ! is_file($originalPath)) {
            return false;
        }

        // Two rows can point at one file — a re-import catalogues the same
        // path twice. There is no redundant copy to delete here, only a
        // redundant row, so the file must be left completely alone.
        if ($duplicatePath === $originalPath) {
            $duplicate->forceFill([
                'duplicate_status' => DuplicateStatus::Merged,
            ])->saveQuietly();

            return true;
        }

        if ($duplicatePath !== null && is_file($duplicatePath)) {
            if (! $this->identical($duplicatePath, $originalPath)) {
                // Contents diverged since detection. Not a duplicate any more.
                $duplicate->forceFill([
                    'duplicate_of_id'  => null,
                    'duplicate_status' => null,
                    'content_hash'     => null,
                ])->saveQuietly();

                return false;
            }

            if (! @unlink($duplicatePath)) {
                return false;
            }
        }

        // Playback history and ratings live on the row, so the row is kept and
        // repointed at the surviving file rather than deleted.
        $duplicate->forceFill([
            'file_path'        => $original->file_path,
            'duplicate_status' => DuplicateStatus::Merged,
        ])->saveQuietly();

        return true;
    }

    /**
     * Marks a pair as deliberately kept, so it stops being offered for review.
     */
    public function keepBoth(MediaItem $duplicate): void
    {
        $duplicate->forceFill([
            'duplicate_status' => DuplicateStatus::Kept,
        ])->saveQuietly();
    }

    /**
     * Whether this copy sits in the filed tree rather than loose.
     *
     * Both separators, because the organiser writes `media\library\…` on
     * Windows and `media/library/…` everywhere else — see S-86. Matching only
     * one would quietly make every Windows copy look loose, and on that
     * machine the preference above would do nothing at all.
     */
    private function isFiled(MediaItem $item): bool
    {
        $path = str_replace('\\', '/', (string) $item->file_path);

        return str_contains($path, 'media/library/');
    }

    /**
     * Byte-for-byte comparison, guarded by size first.
     */
    private function identical(string $a, string $b): bool
    {
        $sizeA = @filesize($a);
        $sizeB = @filesize($b);

        if ($sizeA === false || $sizeB === false || $sizeA !== $sizeB) {
            return false;
        }

        $hashA = @hash_file('xxh128', $a);
        $hashB = @hash_file('xxh128', $b);

        return $hashA !== false && $hashA === $hashB;
    }
}
