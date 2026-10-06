<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Events\MediaItemReviewFlagged;
use App\Events\MetadataTitleTidied;
use App\Models\MediaItem;
use App\Plugins\Registry;
use App\Services\MusicCredits;
use Illuminate\Support\Facades\Log;

/**
 * The tidying that follows identification: credits, title, album, album flag.
 *
 * Lifted out of `EnrichMediaItemJob` so the pipeline's enrich stage and the old
 * job run the *same* code rather than two implementations that drift (#465).
 * Each method keeps the reasoning it was written with, because each one
 * prevented something specific — the comments are the record of what.
 *
 * Every step is non-fatal by design: the identity and its fields are already
 * saved, and none of these is a reason to withhold a file from the library.
 * What is new is that the caller is told which ones failed.
 */
class PostIdentificationTidying
{
    public function __construct(
        private MusicCredits $credits,
        private AlbumTitleNormalizer $albums,
    ) {}

    /**
     * Records who is credited on a track.
     *
     * After identification, so it reads whatever artist the sources settled on
     * rather than the filename's guess. Music only — books and film write their
     * own credits from their own sources.
     */
    public function writeCredits(MediaItem $item): void
    {
        if ($item->type !== MediaItemType::Music) {
            return;
        }

        $item->refresh()->load('musicMetadata');

        $artist = $item->musicMetadata?->artist;

        if (blank($artist)) {
            return;
        }

        // MusicBrainz writes credits itself when it matches a recording, with
        // each artist's stable MBID (S-38). Those are the better record, so only
        // parse the joined artist string when it did not — otherwise this would
        // detach the id-carrying people and re-attach the same names with no ids.
        if (! $this->hasIdentifiedCredits($item)) {
            $this->credits->fromCreditString($item, $artist);
        }

        // The column browsing groups on. Kept in step here rather than by a
        // separate pass, so a track uploaded today is grouped correctly the
        // moment it is catalogued.
        $primary = $this->credits->primaryFor($artist);

        if ($primary !== null && $item->musicMetadata?->primary_artist !== $primary) {
            $item->musicMetadata->forceFill(['primary_artist' => $primary])->saveQuietly();
        }
    }

    /**
     * Last look at the title before the file is named after it.
     *
     * The title arrives holding all sorts of things it should not — a track's
     * own artist ("Gold - Imagine Dragons"), a case nobody wants. Rather than
     * fix each here, the title goes to the `metadata.title` filter and whatever
     * the filters make of it comes back. The app's own artist-strip is itself
     * one of those filters, shipped as a bundled plugin (Title Tidier, #279).
     *
     * Before filing, because filing names the file after the title: left until
     * afterwards, the bad name is already on disk and the scanner reads it back
     * as a title next time round.
     */
    public function tidyTitle(MediaItem $item): void
    {
        $item->refresh();

        $original = (string) $item->title;

        $title = (string) app(Registry::class)->apply('metadata.title', $original, $item);

        if ($title === '' || $title === $original) {
            return;
        }

        Log::info('Adjusted a title', [
            'item' => $item->id,
            'was' => $original,
            'now' => $title,
        ]);

        $item->forceFill(['title' => $title])->saveQuietly();

        MetadataTitleTidied::dispatch($item, $original, $title);
    }

    /**
     * Adopt the album spelling already prevailing in the library, so a track
     * whose source title-cases "the"/"of" differently doesn't split the album
     * into a capitalization duplicate (S-303). Music only.
     */
    public function normalizeAlbum(MediaItem $item): void
    {
        if ($item->type !== MediaItemType::Music) {
            return;
        }

        $item->refresh()->load('musicMetadata');
        $meta = $item->musicMetadata;

        if ($meta === null || blank($meta->album)) {
            return;
        }

        $canonical = $this->albums->canonicalForAlbum($meta->artist, $meta->album);

        if ($canonical !== null && $canonical !== $meta->album) {
            $meta->forceFill(['album' => $canonical])->saveQuietly();
        }
    }

    /**
     * Sends a music track with no album to the review queue (S-384).
     *
     * Enrichment cannot tell "a single with no album" from "an album tag we
     * failed to read", and it used to call both complete — so 83 tracks sat
     * unflagged until a client grouped them under "Unknown album", which is
     * where the problem got noticed rather than where it happened.
     *
     * A person can tell the two apart in a second, so ask. Dismissing one
     * stamps `reviewed_at`, and that is checked here so a later re-enrichment
     * does not drag it back — the same trap S-302 fixed for match review.
     *
     * After `normalizeAlbum()`, which is what would have filled the album in if
     * anything could.
     *
     * @return bool Whether this item needs a person to look at its album.
     */
    public function needsAlbumReview(MediaItem $item): bool
    {
        if ($item->type !== MediaItemType::Music) {
            return false;
        }

        $item->refresh()->load('musicMetadata');

        // Already judged by a person: their answer stands, either way.
        if ($item->reviewed_at !== null) {
            return false;
        }

        return blank($item->musicMetadata?->album);
    }

    /**
     * Flags a missing album, for the old job's benefit.
     *
     * The pipeline does not call this: its enrich stage returns
     * `needsReview()` instead, which parks the item *and* records the reason,
     * where this only sets a status. Kept so `EnrichMediaItemJob` behaves
     * exactly as it did.
     */
    public function flagMissingAlbum(MediaItem $item): void
    {
        if (! $this->needsAlbumReview($item)) {
            return;
        }

        // Already flagged, for this or another reason — re-flagging would fire
        // the event again on every scan.
        if ($item->processing_status === ProcessingStatus::NeedsReview) {
            return;
        }

        $item->update(['processing_status' => ProcessingStatus::NeedsReview]);

        MediaItemReviewFlagged::dispatch($item, 'missing-album');
    }

    /**
     * Whether the item already carries credits with a MusicBrainz id — i.e.
     * MusicBrainz matched and wrote them. That is the record not to overwrite
     * by re-parsing the joined artist string.
     */
    private function hasIdentifiedCredits(MediaItem $item): bool
    {
        return $item->people()
            ->wherePivotIn('role', [MusicCredits::PRIMARY, MusicCredits::FEATURED])
            ->whereNotNull('musicbrainz_artist_id')
            ->exists();
    }
}
