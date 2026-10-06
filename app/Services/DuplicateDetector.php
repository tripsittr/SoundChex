<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Enums\DuplicateMatch;
use App\Enums\DuplicateStatus;
use App\Enums\MediaItemType;
use App\Events\DuplicateDetected;
use App\Events\DuplicateMerged;
use App\Events\DuplicateResolved;
use App\Models\DuplicateDecision;
use App\Models\MediaItem;
use App\Models\MusicMetadata;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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
    /**
     * The algorithm every content hash in the app is stored in.
     *
     * Canonical here because this is what writes `content_hash`. Anything that
     * verifies or compares those hashes must use this same constant —
     * TransferReceiver and LibraryOrganizer both do. Hashing with a different
     * algorithm would make every file look changed: the transfer would call
     * each arrival corrupt and delete it, and the organiser would stop
     * recognising a file as its own duplicate.
     */
    public const HASH = 'xxh128';

    /**
     * How far two copies of the same song may differ in length before the
     * looser pass stops offering them as probably-the-same (S-339).
     *
     * Twelve seconds covers a remaster, a different fade, or a tacked-on count
     * in — the cases that were being missed. It deliberately stops short of a
     * radio edit, which typically trims thirty seconds or more and is a
     * genuinely different cut, not a duplicate.
     */
    private const LIKELY_DURATION_LIMIT_MS = 12_000;

    public function __construct(
        private LibrarySettings $settings,
        private MediaTrash $trash,
    ) {}

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

        $hash = @hash_file(self::HASH, $absolutePath);

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
        return MediaItem::unresolved()
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

        // A reviewed file is still searched. The decision was about a pair, and
        // it is remembered as one in `duplicate_decisions` — so the candidate
        // queries below pass over the items this one has already been ruled
        // against and consider everything else. Skipping the whole file here, as
        // this used to, meant nothing added afterwards was ever compared to it.

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

        // And the same for film and television, which had no content pass at all.
        //
        // Only bytes were ever compared for video, and a second copy of a film
        // almost never matches byte for byte: it is a different rip, a different
        // release, or a download that stopped early. Two rows both titled "War
        // Dogs", both resolved to TMDB 308266, one of them 18 MB against the
        // other's 1.8 GB, sat in the library undetected -- and so did every
        // Simpsons episode that had been fetched twice.
        if ($this->settings->detectContentDuplicates()
            && in_array($item->type, [MediaItemType::Movie, MediaItemType::Show], true)) {
            if ($match = $this->findVideoMatch($item)) {
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
        // Which of the two is the "original" is decided here, over the pair,
        // rather than by whichever happened to be checked second.
        //
        // pickOriginal() ranked the *candidates* and never the item being
        // checked, so checking an older filed row against a newer loose copy
        // made the filed row the duplicate -- and under duplicate_action=auto
        // the filed copy is the one deleted (#461). Done once here because
        // every detection funnels through this method, where the eight
        // pickOriginal() call sites would each need it.
        if ($this->isFiled($item) && ! $this->isFiled($original)) {
            [$item, $original] = [$original, $item];
        }

        $item->forceFill([
            'duplicate_of_id' => $original->id,
            'duplicate_status' => DuplicateStatus::Pending,
            'duplicate_match' => $reason,
            'duplicate_detected_at' => now(),
        ])->saveQuietly();

        // Every detection funnels through here, byte and content alike (S-276).
        DuplicateDetected::dispatch($item, $original);

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

        $candidates = $this->eligibleOriginals($item)
            ->where('content_hash', $hash)
            // Same type only: a cover image and an audio file could in
            // principle collide, and merging across types would be wrong.
            ->where('type', $item->type)
            ->orderBy('id')
            ->get();

        $original = $this->pickOriginal($candidates);

        return $original ? ['original' => $original] : null;
    }

    /**
     * The same film or episode in a different file, if any.
     *
     * Never auto-deletable, like the music content pass: the files genuinely
     * differ, so only the user can say which copy to keep. A 1.8 GB rip and an
     * 18 MB stub are the same film, and which one to throw away is obvious to a
     * person and not to this code.
     *
     * @return array{original: MediaItem, reason: DuplicateMatch}|null
     */
    private function findVideoMatch(MediaItem $item): ?array
    {
        return $item->type === MediaItemType::Show
            ? $this->findEpisodeMatch($item)
            : $this->findFilmMatch($item);
    }

    /**
     * Another copy of the same film.
     *
     * TMDB's id first -- it identifies the work, so it is as strong a signal as
     * an ISRC. Failing that, title *and* year together: two different films do
     * share a title, which is why the year is required rather than preferred,
     * and why this one is only ever offered for review.
     *
     * @return array{original: MediaItem, reason: DuplicateMatch}|null
     */
    private function findFilmMatch(MediaItem $item): ?array
    {
        $meta = $item->movieMetadata;

        if (filled($meta?->tmdb_id)) {
            $original = $this->pickOriginal($this->videoCandidates($item)
                ->whereHas('movieMetadata', fn ($q) => $q->where('tmdb_id', $meta->tmdb_id))
                ->get());

            if ($original) {
                return ['original' => $original, 'reason' => DuplicateMatch::Tmdb];
            }
        }

        if (blank($item->title) || blank($meta?->release_year)) {
            return null;
        }

        $original = $this->pickOriginal($this->videoCandidates($item)
            ->whereRaw('LOWER(TRIM(title)) = ?', [$this->normalise((string) $item->title)])
            ->whereHas('movieMetadata', fn ($q) => $q->where('release_year', $meta->release_year))
            ->get());

        return $original ? ['original' => $original, 'reason' => DuplicateMatch::SameTitle] : null;
    }

    /**
     * Another copy of the same episode.
     *
     * Season and episode number within the same series, which is the strongest
     * identity an episode has -- stronger than its title, because series reuse
     * titles across seasons and numbering does not.
     *
     * "The same series" means the same parent row where both have one, falling
     * back to the series' own TMDB id. Matching on numbering alone would pair
     * every S01E01 in the library with every other.
     *
     * @return array{original: MediaItem, reason: DuplicateMatch}|null
     */
    private function findEpisodeMatch(MediaItem $item): ?array
    {
        $meta = $item->showMetadata;

        if ($meta === null) {
            return null;
        }

        $sameSeries = function ($query) use ($item, $meta): void {
            if ($item->parent_id !== null) {
                $query->where('parent_id', $item->parent_id);

                return;
            }

            // No parent on either side: the series' own id is the next best
            // thing. Without even that there is nothing to scope by, and a
            // library-wide match on "S06E01" would be nonsense.
            if (filled($meta->tmdb_id)) {
                $query->whereHas('showMetadata', fn ($q) => $q->where('tmdb_id', $meta->tmdb_id));

                return;
            }

            $query->whereRaw('1 = 0');
        };

        if ($meta->season_number !== null && $meta->episode_number !== null) {
            $original = $this->pickOriginal($this->videoCandidates($item)
                ->where($sameSeries)
                ->whereHas('showMetadata', fn ($q) => $q
                    ->where('season_number', $meta->season_number)
                    ->where('episode_number', $meta->episode_number))
                ->get());

            if ($original) {
                return ['original' => $original, 'reason' => DuplicateMatch::Episode];
            }
        }

        // Unnumbered: the title within the one series. Distinctive enough there,
        // and worthless across the library, which is why it stays scoped.
        if (blank($item->title) || $item->parent_id === null) {
            return null;
        }

        $original = $this->pickOriginal($this->videoCandidates($item)
            ->where($sameSeries)
            ->whereRaw('LOWER(TRIM(title)) = ?', [$this->normalise((string) $item->title)])
            ->get());

        return $original ? ['original' => $original, 'reason' => DuplicateMatch::SameTitle] : null;
    }

    /** Eligible originals of the same media type, oldest first. */
    private function videoCandidates(MediaItem $item): Builder
    {
        return $this->eligibleOriginals($item)
            ->where('type', $item->type)
            ->orderBy('id');
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

        // Fuzzy fallback: same normalised *primary* artist + title (+ album when
        // both have one) and a near-equal length. Title is on the item;
        // artist/album on the metadata row.
        //
        // Matching on the primary artist rather than the full credit is what lets
        // a track by "Artist" and one by "Artist, Someone" — the same song, one
        // tagged with a feature — pair up. The raw credit would keep them apart.
        $matchArtist = $this->normalise($meta->primary_artist ?: (string) $meta->artist);

        if (blank($item->title) || $matchArtist === '') {
            return null;
        }

        $tolerance = $this->settings->duplicateDurationToleranceMs();

        $candidates = $this->sameTitleAs($item)
            ->whereHas('musicMetadata', function ($q) use ($meta, $tolerance, $matchArtist) {
                $this->whereSameArtist($q, $matchArtist);

                // Album must match when this track has one — a single and the
                // album cut of the same song are legitimately separate files.
                if (filled($meta->album)) {
                    $q->whereRaw('LOWER(TRIM(COALESCE(album, ""))) = ?', [$this->normalise($meta->album)]);
                }

                // Length within tolerance, when both sides know their length.
                if ($tolerance > 0) {
                    $this->whereDurationWithin($q, $meta->duration_ms, $tolerance);
                }
            })
            ->get();

        $original = $this->pickOriginal($candidates);

        if ($original) {
            return ['original' => $original, 'reason' => DuplicateMatch::Fuzzy];
        }

        return $this->findLikelyMatch($item, $meta, $matchArtist);
    }

    /**
     * Same artist and title, but a different release or length (S-339).
     *
     * The strict pass above requires an equal album and a length within
     * tolerance. That is the right bar for a merge and too high for finding
     * everything worth a look: on this library it missed 78 groups the owner
     * could see were duplicates — a greatest-hits copy against the original
     * album, a remaster a few seconds longer. Those are the same song to a
     * listener.
     *
     * So they are flagged, but as `Likely`, which is never auto-merged: a
     * different album *and* a very different length really can be a separate
     * recording — a live cut, an edit — and that is a judgement for a person.
     */
    private function findLikelyMatch(MediaItem $item, MusicMetadata $meta, string $matchArtist): ?array
    {
        // A length this far apart is a different performance, not a different
        // master, so it is not offered at all.
        $limit = self::LIKELY_DURATION_LIMIT_MS;

        $candidates = $this->sameTitleAs($item)
            ->whereHas('musicMetadata', function ($q) use ($meta, $matchArtist, $limit) {
                $this->whereSameArtist($q, $matchArtist);

                // The album is deliberately not compared here — differing on it
                // is the common case this pass exists to catch. The length is,
                // but loosely, and only when both sides know it.
                $this->whereDurationWithin($q, $meta->duration_ms, $limit);
            })
            ->get();

        $original = $this->pickOriginal($candidates);

        return $original ? ['original' => $original, 'reason' => DuplicateMatch::Likely] : null;
    }

    /**
     * The base query for music duplicate candidates: other undecided music rows,
     * never ones already sitting under another original (no chains).
     */
    private function musicCandidates(MediaItem $item): Builder
    {
        return $this->eligibleOriginals($item)
            ->where('type', MediaItemType::Music)
            ->orderBy('id');
    }

    /**
     * Items that may be called the original of this one.
     *
     * Excludes the pairs already ruled on, which is what lets a reviewed file
     * keep being searched: the question that was answered stays answered, and
     * every other question is still asked.
     *
     * A copy still awaiting a decision is not eligible — a duplicate becoming
     * somebody else's original makes a chain, and chains are confusing to
     * review. One that was *kept* is eligible: the user said both files are
     * worth having, so a third copy should be flagged against it like any other
     * file in the library. A merged row has had its file deleted and so cannot
     * be anyone's original.
     */
    private function eligibleOriginals(MediaItem $item): Builder
    {
        return MediaItem::unresolved()
            ->where('id', '!=', $item->id)
            ->whereNotIn('id', DuplicateDecision::partnersOf($item->id))
            ->where(function ($query) {
                $query->whereNull('duplicate_of_id')
                    ->orWhere('duplicate_status', DuplicateStatus::Kept->value);
            });
    }

    /** Music candidates whose title matches this item's, normalised. */
    private function sameTitleAs(MediaItem $item): Builder
    {
        return $this->musicCandidates($item)
            ->whereRaw('LOWER(TRIM(title)) = ?', [$this->normalise((string) $item->title)]);
    }

    /**
     * Constrains a metadata query to one normalised primary artist.
     *
     * Shared by the strict and the looser pass so the two cannot disagree on
     * what counts as the same artist. Compares on the primary artist, falling
     * back to the raw credit for rows that have no primary set yet.
     */
    private function whereSameArtist(Builder $query, string $artist): void
    {
        $query->whereRaw('LOWER(TRIM(COALESCE(NULLIF(primary_artist, ""), artist))) = ?', [$artist]);
    }

    /**
     * Constrains a metadata query to lengths within `$window` of `$durationMs`.
     *
     * A row that does not know its own length is kept rather than excluded:
     * an untagged duration is missing information, not evidence of a
     * different recording. Nothing is constrained when this side's length is
     * unknown either, for the same reason.
     */
    private function whereDurationWithin(Builder $query, ?int $durationMs, int $window): void
    {
        if ($durationMs === null) {
            return;
        }

        $query->where(function ($inner) use ($durationMs, $window) {
            $inner->whereNull('duration_ms')
                ->orWhereBetween('duration_ms', [$durationMs - $window, $durationMs + $window]);
        });
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

        // "Report" means list them and never act, including from the review
        // screen -- which is what the settings page promises and what only the
        // automatic sweep honoured (#461).
        if (! $this->settings->mayResolveDuplicates()) {
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
        // path twice, or a case-only rename leaves two spellings of one name.
        // There is no redundant copy to delete here, only a redundant row, so
        // the file must be left completely alone.
        //
        // Compared by identity rather than by string: a string compare missed
        // the two-spellings case, fell through to the byte compare below, which
        // hashed the same file twice and agreed it was a duplicate, and deleted
        // the user's only copy (#454).
        if ($duplicatePath !== null && FileIdentity::same($duplicatePath, $originalPath)) {
            $duplicate->forceFill([
                'duplicate_status' => DuplicateStatus::Merged,
            ])->saveQuietly();

            $this->remember($duplicate, DuplicateStatus::Merged);

            DuplicateMerged::dispatch($duplicate, $original);

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

            if (! $this->deleteFile($duplicatePath)) {
                return false;
            }
        }

        // Playback history and ratings live on the row, so the row is kept and
        // repointed at the surviving file rather than deleted.
        $duplicate->forceFill([
            'file_path' => $original->file_path,
            'duplicate_status' => DuplicateStatus::Merged,
        ])->saveQuietly();

        $this->remember($duplicate, DuplicateStatus::Merged);

        DuplicateMerged::dispatch($duplicate, $original);

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

        if (! $this->settings->mayResolveDuplicates()) {
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
        //
        // By identity, not by string: two spellings of one path would otherwise
        // reach the delete below and remove the only copy (#454).
        if ($loserPath !== null && FileIdentity::same($loserPath, $keeperPath)) {
            $duplicate->forceFill(['duplicate_status' => DuplicateStatus::Merged])->saveQuietly();

            $this->remember($duplicate, DuplicateStatus::Merged);

            DuplicateResolved::dispatch($duplicate);

            return true;
        }

        if ($loserPath !== null && is_file($loserPath) && ! $this->deleteFile($loserPath)) {
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

        // The loser's file is gone, so the loser's row must stop claiming to
        // describe it -- and when the user kept the flagged copy, the loser is
        // the *original*, whose row this method never touched. That left a row
        // with a dead path, no status, its plays and playlist entries pointing
        // at nothing, and any other duplicate flagged against it unresolvable:
        // merge found no original and refused, which reads as a confusing
        // "original is missing" skip (#461).
        //
        // Both rows end up pointing at the surviving file, which is what
        // merge() has always done for the copy it deletes.
        if ($loser->isNot($duplicate)) {
            $loser->forceFill([
                'file_path' => $keeper->file_path,
                'content_hash' => null,
                'duplicate_of_id' => $keeper->id,
                'duplicate_status' => DuplicateStatus::Merged,
            ])->saveQuietly();
        }

        $this->remember($duplicate, DuplicateStatus::Merged);

        DuplicateResolved::dispatch($duplicate);

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

        $hash = @hash_file(self::HASH, $path);

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

            $this->remember($duplicate, DuplicateStatus::Merged);

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
     * Which of two video copies to keep: the bigger file.
     *
     * Crude and nearly always right. Two copies of one film or episode differ
     * by resolution, bitrate or completeness, and all three show up as size.
     * The cases this is for are not close calls -- 2 MB against 41 MB is a
     * download that stopped early, and 18 MB against 1.8 GB is a sample.
     *
     * A 10% margin, so two rips that differ by container overhead are still a
     * tie and go to a person rather than being decided on a rounding error.
     *
     * @return array{0: MediaItem|null, 1: string}
     */
    private function decideVideoKeeper(MediaItem $a, MediaItem $b, bool $breakTies): array
    {
        $sa = $this->sizeOf($a);
        $sb = $this->sizeOf($b);

        if ($sa !== null && $sb !== null && $sa > 0 && $sb > 0) {
            $margin = (int) (max($sa, $sb) * 0.10);

            if (abs($sa - $sb) > $margin) {
                return [$sa > $sb ? $a : $b, 'file_size'];
            }
        }

        // One file gone and the other present: keep the one that exists.
        if ($sa === null && $sb !== null) {
            return [$b, 'only_copy_present'];
        }

        if ($sb === null && $sa !== null) {
            return [$a, 'only_copy_present'];
        }

        // Too close to call. Deliberately not broken by row id even when asked:
        // for video that is an arbitrary choice between two files someone cares
        // about, and the whole point of a review list is that it gets reviewed.
        return [null, 'tie'];
    }

    /** A row's file size, or null when the file is not there. */
    private function sizeOf(MediaItem $item): ?int
    {
        $path = $item->absoluteFilePath();

        if ($path === null || ! is_file($path)) {
            return null;
        }

        $size = @filesize($path);

        return $size === false ? null : $size;
    }

    /**
     * Removes a redundant copy — by moving it to the trash, not unlinking it.
     *
     * Two things live in MediaTrash now, and both were learned here. Deletes go
     * to `library.trash_root` for `trash_days` so a wrong merge is recoverable
     * (#464). And the read-only attribute is cleared first: `unlink()` will not
     * remove a read-only file on Windows, and 285 of the 2,843 files in one real
     * library carry that attribute — arrived with it, from a copy off another
     * machine. Every merge of one of those failed, the row stayed pending, and
     * the duplicate came back at the next sweep: "I merged it and they came
     * back". A move is refused for the same reason, so the same `chmod` applies.
     */
    private function deleteFile(string $path): bool
    {
        // Moved to the trash rather than unlinked, so a wrong merge is
        // recoverable for `library.trash_days` (#464). MediaTrash handles the
        // read-only case Windows otherwise refuses, and logs its own failures
        // with the path -- the alternative being a toast that reports a missing
        // file for something present and locked.
        return $this->trash->discard($path, reason: 'duplicate resolved') !== null;
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
        // Film and television first, because none of the music tests below say
        // anything about them: bitrate and sample rate come from
        // `musicMetadata`, which a film does not have, and tag completeness is
        // music tags. Every video pair therefore came out a tie, and a tie
        // broken by row id is not a quality decision -- it deleted a 43 MB
        // episode to keep a 17 MB one, and a 41 MB episode to keep 2 MB.
        if ($a->type !== MediaItemType::Music) {
            return $this->decideVideoKeeper($a, $b, $breakTies);
        }

        // 1. Bitrate (bits per second), when both are measurable.
        $ra = $this->bitrate($a);
        $rb = $this->bitrate($b);

        if ($ra !== null && $rb !== null) {
            // 5% (or ~16 kbps) apart to count as a real difference, not encoder
            // noise between two rips of the same track.
            $margin = max(16000, (int) (max($ra, $rb) * 0.05));

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

    /**
     * Marks a pair as deliberately kept, so it stops being offered for review.
     */
    public function keepBoth(MediaItem $duplicate): void
    {
        $duplicate->forceFill([
            'duplicate_status' => DuplicateStatus::Kept,
        ])->saveQuietly();

        $this->remember($duplicate, DuplicateStatus::Kept);
    }

    /**
     * Record that this pair has been ruled on.
     *
     * Without this the next sweep asks again, because the status on the row is
     * overwritten the moment the file matches something else. The pair is what
     * was decided, so the pair is what is stored.
     */
    private function remember(MediaItem $duplicate, DuplicateStatus $decision): void
    {
        if ($duplicate->duplicate_of_id === null) {
            return;
        }

        DuplicateDecision::record($duplicate->id, (int) $duplicate->duplicate_of_id, $decision);
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

        $hashA = @hash_file(self::HASH, $a);
        $hashB = @hash_file(self::HASH, $b);

        return $hashA !== false && $hashA === $hashB;
    }
}
