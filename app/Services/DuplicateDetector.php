<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Enums\DuplicateMatch;
use App\Enums\DuplicateStatus;
use App\Enums\MediaItemType;
use App\Models\MediaItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Finds and resolves duplicate copies of catalogued files.
 *
 * Two kinds of match:
 *
 *  - **Byte-identical** (any media type). The whole file hashes the same. Such a
 *    copy is redundant on disk and, once re-verified byte-for-byte, safe to
 *    delete automatically — nothing is lost.
 *
 *  - **Same recording, different file** (music only, S-257). The same song
 *    acquired twice — a different bitrate, format, or re-rip — never hashes the
 *    same, so byte detection misses it, which is most real-world music
 *    duplication. Matched by ISRC, MusicBrainz recording id, or AcoustID
 *    fingerprint, and as a fallback by close tags (artist + title + album) with
 *    a near-equal length. These are flagged for **review only**: the files
 *    genuinely differ, so only the user chooses which copy to keep — the
 *    automatic delete never touches them.
 *
 * Nothing here deletes on its own unless the configured action says to, and even
 * then only byte-identical copies; the default is to flag and wait.
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
     * Clears pending flags that point at no original, so they stop clogging the
     * review list and are judged afresh on the next pass.
     *
     * A pending row with a null `duplicate_of_id` is not a duplicate of anything
     * — there is nothing to merge into and nothing to delete. Such rows can be
     * left behind by an original being removed, or by an older flagging path;
     * either way they are unresolvable in the review UI (merge finds no original
     * and refuses, which reads as a confusing "original is missing" skip). This
     * un-flags them, and `check()` will re-evaluate them like any other row.
     *
     * Resolved decisions (Kept / Merged) are never touched — those are the
     * user's, and a Merged row deliberately has no original once collapsed.
     *
     * @return int How many rows were cleared.
     */
    public function clearOrphans(): int
    {
        return MediaItem::query()
            ->where('duplicate_status', DuplicateStatus::Pending)
            ->whereNull('duplicate_of_id')
            ->update([
                'duplicate_status' => null,
                'duplicate_match' => null,
            ]);
    }

    /**
     * Flags an item if an earlier one holds identical bytes, or (for music) is
     * the same recording in a different file.
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

        // Byte-identical first: it's the strongest signal and the only one that
        // may be auto-deleted.
        if ($match = $this->findByteMatch($item)) {
            return $this->flag($item, $match['original'], DuplicateMatch::Bytes, autoDeletable: true);
        }

        // Then the content pass, music only. Same recording, different file.
        if ($item->type === MediaItemType::Music && $this->settings->detectContentDuplicates()) {
            if ($match = $this->findContentMatch($item)) {
                return $this->flag($item, $match['original'], $match['reason'], autoDeletable: false);
            }
        }

        return null;
    }

    /**
     * Records the flag and, when allowed, deletes the redundant copy.
     *
     * @param  bool  $autoDeletable  Whether the "auto" action may delete this —
     *                               true only for byte-identical copies.
     */
    private function flag(MediaItem $item, MediaItem $original, DuplicateMatch $reason, bool $autoDeletable): MediaItem
    {
        $item->forceFill([
            'duplicate_of_id' => $original->id,
            'duplicate_status' => DuplicateStatus::Pending,
            'duplicate_match' => $reason,
            'duplicate_detected_at' => now(),
        ])->saveQuietly();

        if ($autoDeletable && $this->settings->deletesDuplicatesAutomatically()) {
            $this->merge($item);
        }

        return $original;
    }

    /**
     * The earlier item holding byte-identical content, if any.
     *
     * @return array{original: MediaItem}|null
     */
    private function findByteMatch(MediaItem $item): ?array
    {
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

        $original = $this->pickOriginal($candidates);

        return $original ? ['original' => $original] : null;
    }

    /**
     * The best content match for a music item, if any — strongest signal first.
     *
     * @return array{original: MediaItem, reason: DuplicateMatch}|null
     */
    private function findContentMatch(MediaItem $item): ?array
    {
        $meta = $item->musicMetadata;

        if ($meta === null) {
            return null;
        }

        // ISRC → MusicBrainz recording → AcoustID: each a strong identity, tried
        // in descending confidence. The first that matches wins.
        $byId = [
            [DuplicateMatch::Isrc, 'isrc', $meta->isrc],
            [DuplicateMatch::MusicBrainz, 'musicbrainz_recording_id', $meta->musicbrainz_recording_id],
            [DuplicateMatch::AcoustId, 'acoustid', $meta->acoustid],
        ];

        foreach ($byId as [$reason, $column, $value]) {
            if (blank($value)) {
                continue;
            }

            $original = $this->pickOriginal($this->musicCandidates($item)
                ->whereHas('musicMetadata', fn ($q) => $q->where($column, $value))
                ->get());

            if ($original) {
                return ['original' => $original, 'reason' => $reason];
            }
        }

        // Fuzzy fallback: same normalised artist + title (+ album when both have
        // one) and a near-equal length. Title is on the item; artist/album on the
        // metadata row.
        if (blank($item->title) || blank($meta->artist)) {
            return null;
        }

        $tolerance = $this->settings->duplicateDurationToleranceMs();

        $candidates = $this->musicCandidates($item)
            ->whereRaw('LOWER(TRIM(title)) = ?', [$this->normalise($item->title)])
            ->whereHas('musicMetadata', function ($q) use ($meta, $tolerance) {
                $q->whereRaw('LOWER(TRIM(artist)) = ?', [$this->normalise($meta->artist)]);

                // Album must match when this track has one — a single and the
                // album cut of the same song are legitimately separate files.
                if (filled($meta->album)) {
                    $q->whereRaw('LOWER(TRIM(COALESCE(album, ""))) = ?', [$this->normalise($meta->album)]);
                }

                // Length within tolerance, when both sides know their length.
                if ($meta->duration_ms !== null && $tolerance > 0) {
                    $q->where(function ($inner) use ($meta, $tolerance) {
                        $inner->whereNull('duration_ms')
                            ->orWhereBetween('duration_ms', [
                                $meta->duration_ms - $tolerance,
                                $meta->duration_ms + $tolerance,
                            ]);
                    });
                }
            })
            ->get();

        $original = $this->pickOriginal($candidates);

        return $original ? ['original' => $original, 'reason' => DuplicateMatch::Fuzzy] : null;
    }

    /**
     * The base query for music duplicate candidates: other undecided music rows,
     * never ones already sitting under another original (no chains).
     */
    private function musicCandidates(MediaItem $item): Builder
    {
        return MediaItem::query()
            ->where('id', '!=', $item->id)
            ->where('type', MediaItemType::Music)
            ->whereNull('duplicate_of_id')
            ->orderBy('id');
    }

    /**
     * Which of the matched candidates is the copy to keep.
     *
     * A filed copy outranks a loose one, whatever order they arrived in — the
     * organiser put it in `media/library/` deliberately and the rest of the
     * catalogue points into that tree. Otherwise the oldest row. Both rules are
     * stable across runs, so a re-scan flags the same side each time.
     */
    private function pickOriginal(Collection $candidates): ?MediaItem
    {
        return $candidates->first(fn (MediaItem $c) => $this->isFiled($c))
            ?? $candidates->first();
    }

    /** Lower-cased, trimmed — the comparison key for fuzzy tag matching. */
    private function normalise(string $value): string
    {
        return mb_strtolower(trim($value));
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

        // A content match is two *different* files (a FLAC and an MP3 of the
        // same song), so the byte re-compare below would always fail and
        // un-flag the pair. Merging one is a deliberate "keep the other" choice,
        // which goes through resolveKeeping() instead — never this path.
        if ($duplicate->duplicate_match?->isContent()) {
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
                    'duplicate_of_id' => null,
                    'duplicate_status' => null,
                    'content_hash' => null,
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
            'file_path' => $original->file_path,
            'duplicate_status' => DuplicateStatus::Merged,
        ])->saveQuietly();

        return true;
    }

    /**
     * Resolves a *content* duplicate by keeping one copy and deleting the other.
     *
     * Unlike merge(), the two files legitimately differ (different format or
     * bitrate of the same recording), so there is no byte re-compare to gate on —
     * the user has chosen which copy to keep, and the other's file is removed.
     * The losing row is kept (its plays and ratings live on it) and repointed at
     * the surviving file, exactly as merge() does.
     *
     * @param  MediaItem  $duplicate  The flagged row (duplicate_of the original).
     * @param  bool  $keepDuplicate  true keeps the flagged copy and deletes the
     *                               original's file instead; false (default)
     *                               keeps the original.
     * @return bool Whether a file was deleted and the pair resolved.
     */
    public function resolveKeeping(MediaItem $duplicate, bool $keepDuplicate = false): bool
    {
        $original = $duplicate->duplicateOf;

        if ($original === null || ! $duplicate->duplicate_match?->isContent()) {
            return false;
        }

        [$keeper, $loser] = $keepDuplicate ? [$duplicate, $original] : [$original, $duplicate];

        $keeperPath = $keeper->absoluteFilePath();
        $loserPath = $loser->absoluteFilePath();

        // The copy being kept must actually exist, or deleting the other loses
        // the content outright.
        if ($keeperPath === null || ! is_file($keeperPath)) {
            return false;
        }

        // Same file behind both rows — nothing on disk to delete, only a
        // redundant row. (Unlikely for a content match, but cheap to be safe.)
        if ($loserPath === $keeperPath) {
            $duplicate->forceFill(['duplicate_status' => DuplicateStatus::Merged])->saveQuietly();

            return true;
        }

        if ($loserPath !== null && is_file($loserPath) && ! @unlink($loserPath)) {
            return false;
        }

        // The flagged row survives (history/ratings) and points at the keeper.
        // When the original was the loser, the flagged copy *is* the keeper, so
        // it keeps its own path; only its status changes.
        //
        // If the two copies had *different* cover art, the audio merge is sound
        // but the kept cover is uncertain — flag it for a human to verify rather
        // than silently keep whichever copy won on audio quality.
        $duplicate->forceFill([
            'file_path' => $keeper->file_path,
            'duplicate_status' => DuplicateStatus::Merged,
            'needs_cover_review' => $this->coversDiffer($keeper, $loser),
        ])->saveQuietly();

        return true;
    }

    /**
     * Whether two copies carry different cover art.
     *
     * Compares the extracted cover files by content hash. Two copies with the
     * same cover (or both with none) are no cause for review; a genuine
     * difference — one carrying a compilation's art, say — means the kept cover
     * may be the wrong one and a person should look. A remote-URL cover or a
     * missing file is treated as "can't compare", which does not raise the flag:
     * we only flag a difference we can actually see, never a maybe.
     */
    private function coversDiffer(MediaItem $a, MediaItem $b): bool
    {
        $ha = $this->coverHash($a);
        $hb = $this->coverHash($b);

        // Both must be locally hashable for a difference to be meaningful.
        if ($ha === null || $hb === null) {
            return false;
        }

        return $ha !== $hb;
    }

    /** Content hash of a copy's extracted cover file, or null when unavailable. */
    private function coverHash(MediaItem $item): ?string
    {
        $cover = $item->cover_image_url;

        // No cover, or a remote URL (not a file we can read here).
        if (blank($cover) || str_starts_with($cover, 'http')) {
            return null;
        }

        $path = Storage::disk('public')->path(ltrim($cover, '/'));

        if (! is_file($path)) {
            return null;
        }

        $hash = @hash_file('xxh128', $path);

        return $hash === false ? null : $hash;
    }

    /**
     * Resolves a content pair automatically by keeping the higher-quality copy,
     * *only* when one is clearly better — otherwise the pair is left for review.
     *
     * "Clearly better" means a meaningfully higher bitrate, or (bitrate tied) a
     * higher sample rate, or (both tied) more complete tags. A pair where the two
     * copies are effectively equal on all of those is a genuine coin-flip, so it
     * is skipped rather than guessed at — this deletes real files.
     *
     * @return 'resolved'|'tie'|'skipped' resolved = a copy was deleted; tie =
     *                                    left for review because neither is clearly better; skipped = not an
     *                                    eligible content pair, or a file was missing.
     */
    public function resolveKeepingBest(MediaItem $duplicate, bool $breakTies = false): string
    {
        $original = $duplicate->duplicateOf;

        if ($original === null || ! $duplicate->duplicate_match?->isContent()) {
            return 'skipped';
        }

        // If one side's file is already gone — a copy deleted from disk in an
        // earlier round, leaving an orphaned row — the choice is forced: keep the
        // side that still exists. Quality is moot when a copy no longer exists,
        // and without this the pair sticks forever ("a file was missing").
        $dupExists = $this->fileExists($duplicate);
        $origExists = $this->fileExists($original);

        if ($dupExists && ! $origExists) {
            // The flagged copy is the only real file left — keep it.
            return $this->resolveKeeping($duplicate, keepDuplicate: true) ? 'resolved' : 'skipped';
        }

        if ($origExists && ! $dupExists) {
            return $this->resolveKeeping($duplicate, keepDuplicate: false) ? 'resolved' : 'skipped';
        }

        if (! $dupExists && ! $origExists) {
            // Neither file exists — there is nothing to merge. Clear the flag so
            // it leaves the review list rather than failing forever.
            $duplicate->forceFill([
                'duplicate_status' => DuplicateStatus::Merged,
            ])->saveQuietly();

            return 'resolved';
        }

        [$winner] = $this->decideKeeper($original, $duplicate, $breakTies);

        if ($winner === null) {
            return 'tie';
        }

        $ok = $this->resolveKeeping($duplicate, keepDuplicate: $winner->is($duplicate));

        return $ok ? 'resolved' : 'skipped';
    }

    /** Whether a row's file is present on disk. */
    private function fileExists(MediaItem $item): bool
    {
        $path = $item->absoluteFilePath();

        return $path !== null && is_file($path);
    }

    /**
     * Which of two copies to keep on quality, or null when neither is clearly
     * better (a tie the caller should leave for a human).
     *
     * A thin wrapper over decideKeeper() that discards the reason and never
     * breaks ties — kept for callers that only want the quality verdict.
     */
    public function bestCopy(MediaItem $a, MediaItem $b): ?MediaItem
    {
        return $this->decideKeeper($a, $b, breakTies: false)[0];
    }

    /**
     * Decide which copy to keep, and say why.
     *
     * Compared in order, first difference wins:
     *   1. bitrate  — file size ÷ duration; the copies are the same recording so
     *                 near-equal length makes this a fair proxy. A margin avoids
     *                 treating a rounding-level difference as a winner.
     *   2. sample rate
     *   3. tag completeness — album, track number, release year, artist present
     *
     * When all three tie, the copies are indistinguishable on quality. With
     * `$breakTies` the newer row (higher id) wins — a re-download is usually the
     * copy the user meant to keep — and the reason is 'newer'. Without it, the
     * result is [null, 'tie'] so the caller can leave it for a person.
     *
     * @return array{0: MediaItem|null, 1: string} [winner, reason]. Reason is
     *                                             one of bitrate | sample_rate | tags | newer | tie.
     */
    public function decideKeeper(MediaItem $a, MediaItem $b, bool $breakTies = false): array
    {
        // 1. Bitrate (bits per second), when both are measurable.
        $ra = $this->bitrate($a);
        $rb = $this->bitrate($b);

        if ($ra !== null && $rb !== null) {
            // 5% (or ~16 kbps) apart to count as a real difference, not encoder
            // noise between two rips of the same track.
            $margin = max(16000, (int) ($this->maxValue($ra, $rb) * 0.05));

            if (abs($ra - $rb) >= $margin) {
                return [$ra > $rb ? $a : $b, 'bitrate'];
            }
        }

        // 2. Sample rate.
        $sa = (int) ($a->musicMetadata?->sample_rate ?? 0);
        $sb = (int) ($b->musicMetadata?->sample_rate ?? 0);

        if ($sa !== $sb) {
            return [$sa > $sb ? $a : $b, 'sample_rate'];
        }

        // 3. Tag completeness.
        $ca = $this->tagCompleteness($a);
        $cb = $this->tagCompleteness($b);

        if ($ca !== $cb) {
            return [$ca > $cb ? $a : $b, 'tags'];
        }

        // Genuinely equal on quality.
        if (! $breakTies) {
            return [null, 'tie'];
        }

        // Keep the newer row (higher id) — a re-download is usually the one meant.
        return [$a->id >= $b->id ? $a : $b, 'newer'];
    }

    /**
     * A copy's bitrate in bits per second, from its file size and duration, or
     * null when either is unknown.
     */
    private function bitrate(MediaItem $item): ?int
    {
        $bytes = $item->file_size;
        $ms = $item->musicMetadata?->duration_ms;

        if ($bytes === null || $bytes <= 0 || $ms === null || $ms <= 0) {
            return null;
        }

        return (int) ($bytes * 8 / ($ms / 1000));
    }

    /** How many of the meaningful tags a copy has filled in (0–4). */
    private function tagCompleteness(MediaItem $item): int
    {
        $meta = $item->musicMetadata;

        return (int) filled($meta?->album)
            + (int) ($meta?->trackNumber() !== null)
            + (int) filled($meta?->release_year)
            + (int) filled($meta?->artist);
    }

    private function maxValue(int $a, int $b): int
    {
        return $a > $b ? $a : $b;
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
     * Clears the cover-review flag once a person has checked the artwork (S-265).
     */
    public function clearCoverReview(MediaItem $item): void
    {
        $item->forceFill(['needs_cover_review' => false])->saveQuietly();
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
