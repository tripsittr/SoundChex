<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Versions;

use App\Enums\VersionVerdict;
use App\Models\MediaItem;
use App\Services\FileIdentity;
use Illuminate\Database\Eloquent\Collection;

/**
 * Decides whether two copies are versions to keep or a duplicate to resolve
 * (#457, #475, #476).
 *
 * The user's rule, which is the whole design: *"If Spotify has 15 versions of a
 * song for an artist, we should too."* So **keep both is the default**, and the
 * burden of proof is on calling something a duplicate.
 *
 * Only `same work + same edition + same quality tier` is a duplicate.
 * Everything else — a remaster, a single edit, a live take, a 4K alongside a
 * 1080p — is a version, stays on disk, and stays browsable in its own release.
 * Where the edition cannot be determined with confidence the answer is
 * **version**, which errs toward keeping and matches rule 2's "default to
 * doing nothing".
 */
class VersionGrouper
{
    public function __construct(
        private WorkKey $workKey,
        private EditionKey $editionKey,
    ) {}

    /**
     * Writes an item's work and edition keys.
     *
     * Idempotent, and quiet: this is derived data, so it must not dirty
     * `updated_at` or fire model events that would make a re-run look like a
     * change to the item.
     */
    public function classify(MediaItem $item): MediaItem
    {
        $item->forceFill([
            'work_key' => $this->workKey->for($item),
            'edition' => $this->editionKey->for($item),
        ])->saveQuietly();

        return $item;
    }

    /**
     * What these two copies are to each other.
     *
     * Ordered so the cheapest and most certain answers come first, and so no
     * later rule can override a more specific earlier one.
     */
    public function compare(MediaItem $a, MediaItem $b): VersionVerdict
    {
        $pathA = $a->absoluteFilePath();
        $pathB = $b->absoluteFilePath();

        // One file, two rows. Not a disk question at all, and answered by
        // identity rather than string comparison for the reason #454 exists.
        if ($pathA !== null && $pathB !== null && FileIdentity::same($pathA, $pathB)) {
            return VersionVerdict::SameFile;
        }

        // Byte-identical, two files. A matching hash outranks edition: two
        // files with the same bytes are the same edition whatever their titles
        // say.
        //
        // But only when the sizes agree too. **2,298 pairs in this library
        // share a hash while differing in size**, which is impossible for
        // genuinely identical files and means one hash is stale -- the S-346
        // condition, where a path was rewritten without clearing the
        // fingerprint. Trusting the hash alone called a 5,425,567-byte file
        // and a 5,381,369-byte file identical copies of each other.
        //
        // A size mismatch does not make them unrelated; it makes the hash
        // unusable, so the comparison falls through to work and edition like
        // any other pair.
        if ($this->bytesAgree($a, $b)) {
            return VersionVerdict::IdenticalCopy;
        }

        $workA = $a->work_key;
        $workB = $b->work_key;

        // Nothing identifies at least one of them, so there is no work to
        // share. Unrelated is the honest answer -- guessing from titles is
        // what produced the false duplicates in the first place.
        if (blank($workA) || blank($workB) || $workA !== $workB) {
            return VersionVerdict::Unrelated;
        }

        // Same work, different edition: the case this model exists for.
        if (! $this->sameEdition($a, $b)) {
            return VersionVerdict::Version;
        }

        // Same work and edition. Only now can quality separate them.
        return $this->sameQualityTier($a, $b)
            ? VersionVerdict::Duplicate
            : VersionVerdict::QualityVariant;
    }

    /**
     * Every copy of one work, including the item itself.
     *
     * The query behind "also available as" on an item page: all versions
     * visible, none hidden behind a picker, which is what the user asked for
     * (#475).
     *
     * @return Collection<int, MediaItem>
     */
    public function versionsOf(MediaItem $item): Collection
    {
        if (blank($item->work_key)) {
            return new Collection([$item]);
        }

        return MediaItem::withoutGlobalScopes()
            ->where('work_key', $item->work_key)
            ->with(['musicMetadata', 'movieMetadata', 'probe'])
            ->orderByDesc('is_primary_version')
            ->orderBy('id')
            ->get();
    }

    /**
     * The siblings of an item — every other version of the same work.
     *
     * @return Collection<int, MediaItem>
     */
    public function siblingsOf(MediaItem $item): Collection
    {
        return $this->versionsOf($item)->reject(fn (MediaItem $other): bool => $other->id === $item->id);
    }

    /**
     * Picks which version an ambiguous "play this" resolves to.
     *
     * It decides nothing about what is *browsable*: every version stays
     * visible in its own release. This only answers a playlist entry or a
     * shuffle that names the work rather than a file.
     *
     * The plain release wins, because that is what somebody naming a song
     * usually means — not the live take or the radio edit. Among equals the
     * better quality wins, then the earlier row, so the answer is stable.
     */
    public function electPrimary(MediaItem $item): void
    {
        $versions = $this->versionsOf($item);

        if ($versions->count() < 2) {
            // A work with one copy: that copy is the primary, and saying so
            // keeps the column meaningful rather than only set when there is
            // competition.
            $item->forceFill(['is_primary_version' => true])->saveQuietly();

            return;
        }

        $winner = $versions
            ->sortBy([
                // Plain release first: edition null sorts before any edition.
                fn (MediaItem $m): int => $m->edition === null ? 0 : 1,
                // Then the better copy.
                fn (MediaItem $m): int => -$this->qualityScore($m),
                // Then the earliest, so the answer never flickers.
                fn (MediaItem $m): int => $m->id,
            ])
            ->first();

        foreach ($versions as $version) {
            $isPrimary = $version->id === $winner->id;

            if ((bool) $version->is_primary_version !== $isPrimary) {
                $version->forceFill(['is_primary_version' => $isPrimary])->saveQuietly();
            }
        }
    }

    /**
     * Whether two rows really do hold the same bytes.
     *
     * Both hashes present and equal, **and** the sizes agree. A stale hash is
     * common enough here to be the default assumption rather than an edge
     * case: 2,298 pairs in this library share a hash across different sizes.
     *
     * Sizes are only compared when both are known -- an unknown size is not
     * evidence of disagreement.
     */
    private function bytesAgree(MediaItem $a, MediaItem $b): bool
    {
        if (blank($a->content_hash) || $a->content_hash !== $b->content_hash) {
            return false;
        }

        if ($a->file_size === null || $b->file_size === null) {
            return true;
        }

        return (int) $a->file_size === (int) $b->file_size;
    }

    /**
     * Whether two copies are the same edition.
     *
     * Null edition means the plain release, so two nulls match. An edition
     * that could not be determined is treated as *different* by the caller,
     * which errs toward keeping both.
     */
    private function sameEdition(MediaItem $a, MediaItem $b): bool
    {
        return $a->edition === $b->edition;
    }

    /**
     * Whether two copies are at the same quality tier.
     *
     * Tiers rather than exact numbers: a 1920×1080 and a 1920×1038 copy are
     * both "1080p" to anyone choosing between them, and a cropped aspect ratio
     * is not a lower tier.
     *
     * Without probes on both sides the answer is "yes, same tier" — which
     * routes an otherwise-identical pair to `Duplicate` and so to a person,
     * rather than silently calling it a quality variant nobody is told about.
     */
    private function sameQualityTier(MediaItem $a, MediaItem $b): bool
    {
        $probeA = $a->probe;
        $probeB = $b->probe;

        if ($probeA === null || $probeB === null) {
            return true;
        }

        if ($probeA->isVideo() || $probeB->isVideo()) {
            return $probeA->resolutionLabel() === $probeB->resolutionLabel();
        }

        return $this->audioTier($a) === $this->audioTier($b);
    }

    /**
     * An audio quality tier: lossless depth, or a lossy bitrate band.
     *
     * Banded rather than exact, because 320 and 319 kb/s are the same thing to
     * a listener and treating them as different tiers would call every pair a
     * quality variant.
     */
    private function audioTier(MediaItem $item): string
    {
        $probe = $item->probe;
        $stream = $probe?->audio_streams[0] ?? null;

        if ($stream === null) {
            return 'unknown';
        }

        $codec = strtolower((string) ($stream['codec'] ?? ''));

        if (in_array($codec, ['flac', 'alac', 'wav', 'aiff', 'ape'], true)) {
            return 'lossless_'.((int) ($stream['bit_depth'] ?? 16));
        }

        $kbps = (int) round(((int) ($stream['bitrate'] ?? 0)) / 1000);

        return match (true) {
            $kbps >= 300 => 'lossy_320',
            $kbps >= 240 => 'lossy_256',
            $kbps >= 180 => 'lossy_192',
            $kbps >= 120 => 'lossy_128',
            $kbps > 0 => 'lossy_low',
            default => 'unknown',
        };
    }

    /** A rough ordering of copies, for electing a primary. */
    private function qualityScore(MediaItem $item): int
    {
        $probe = $item->probe;

        if ($probe === null) {
            return 0;
        }

        if ($probe->isVideo()) {
            return (int) ($probe->height ?? 0);
        }

        return str_starts_with($this->audioTier($item), 'lossless')
            ? 10_000
            : (int) round(((int) ($probe->audio_streams[0]['bitrate'] ?? 0)) / 1000);
    }
}
