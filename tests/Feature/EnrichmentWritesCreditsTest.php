<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use App\Models\User;
use App\Plugins\Registry;
use App\Services\MusicCredits;
use App\Services\TitleTidier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A track added tomorrow has to be grouped like the ones backfilled today.
 *
 * Without this the backfill is a one-off: every upload afterwards would land
 * with no credits and no primary artist, and the artist pages would drift back
 * out of date one file at a time.
 */
class EnrichmentWritesCreditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_newly_enriched_track_gets_its_credits(): void
    {
        $item = $this->track('Alan Jackson, Jimmy Buffett');

        app(EnrichMediaItemJob::class, ['mediaItemId' => $item->id])
            ->handle(...$this->dependencies());

        $item->refresh();

        $this->assertSame('Alan Jackson', $item->musicMetadata->primary_artist);
        $this->assertSame(
            'Alan Jackson',
            $item->people()->wherePivot('role', MusicCredits::PRIMARY)->value('name'),
        );
        $this->assertSame(
            'Jimmy Buffett',
            $item->people()->wherePivot('role', MusicCredits::FEATURED)->value('name'),
        );
    }

    public function test_re_enriching_does_not_duplicate_credits(): void
    {
        // Enrichment re-runs on every scan of a watched folder.
        $item = $this->track('Avicii, Nicky Romero');

        foreach (range(1, 3) as $ignored) {
            app(EnrichMediaItemJob::class, ['mediaItemId' => $item->id])
                ->handle(...$this->dependencies());
        }

        $this->assertSame(2, $item->fresh()->people()->count());
    }

    public function test_a_film_is_left_to_its_own_credit_sources(): void
    {
        // The same table holds actors and directors. Music's writer must not
        // reach into an item it knows nothing about.
        $film = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Movie,
            'title' => 'A Film',
            'file_path' => '/tmp/film.mkv',
            'owned' => true,
        ]);

        app(EnrichMediaItemJob::class, ['mediaItemId' => $film->id])
            ->handle(...$this->dependencies());

        $this->assertSame(0, $film->fresh()->people()
            ->wherePivotIn('role', [MusicCredits::PRIMARY, MusicCredits::FEATURED])
            ->count());
    }

    /**
     * Registers the title cleanup the same way the shipped Title Tidier plugin
     * does (S-361).
     *
     * The job does not tidy titles itself — it hands the title to the
     * `metadata.title` filter chain, and the app's own cleanup is one of those
     * filters, shipped as a bundled plugin. Plugins live in a per-user data
     * directory outside the repo, so the loader finds nothing here; without
     * this the chain is empty and every tidying assertion below passes
     * vacuously on a no-op.
     */
    private function registerTitleTidier(): void
    {
        app(Registry::class)->filter(
            'metadata.title',
            fn (string $title, MediaItem $item): string => $item->type !== MediaItemType::Music
                ? $title
                : (app(TitleTidier::class)->strip($title, [
                    $item->musicMetadata?->artist,
                    $item->musicMetadata?->primary_artist,
                ]) ?? $title),
        );
    }

    public function test_a_title_holding_its_artist_is_tidied_before_filing(): void
    {
        $this->registerTitleTidier();

        // The case FileTagger cannot catch: the file's *tag* is
        // "Gold - Imagine Dragons", so there is no disagreement to promote
        // over. Checked at the end of enrichment instead, before filing names
        // the file after the title.
        $item = $this->track('Imagine Dragons');
        $item->forceFill(['title' => 'Gold - Imagine Dragons'])->save();

        app(EnrichMediaItemJob::class, ['mediaItemId' => $item->id])
            ->handle(...$this->dependencies());

        $this->assertSame('Gold', $item->fresh()->title);
    }

    public function test_a_hyphen_belonging_to_the_title_survives_enrichment(): void
    {
        $this->registerTitleTidier();
        $item = $this->track('Benny Goodman');
        $item->forceFill(['title' => 'Sing - Sing - Sing'])->save();

        app(EnrichMediaItemJob::class, ['mediaItemId' => $item->id])
            ->handle(...$this->dependencies());

        $this->assertSame('Sing - Sing - Sing', $item->fresh()->title);
    }

    public function test_a_title_that_is_only_an_artist_is_left_alone(): void
    {
        $this->registerTitleTidier();
        // Stripping would leave nothing, and a track with no title is worse
        // than one with a clumsy title.
        $item = $this->track('Imagine Dragons');
        $item->forceFill(['title' => ' - Imagine Dragons'])->save();

        app(EnrichMediaItemJob::class, ['mediaItemId' => $item->id])
            ->handle(...$this->dependencies());

        $this->assertNotSame('', $item->fresh()->title);
    }

    /* ------------------------------------------- missing album (S-384) --- */

    public function test_a_track_with_no_album_is_sent_for_review(): void
    {
        // Enrichment cannot tell "a single with no album" from "an album tag
        // we failed to read", and used to call both complete — so 83 tracks
        // sat unflagged until a client grouped them under "Unknown album".
        $item = $this->track('Some Artist');

        app(EnrichMediaItemJob::class, ['mediaItemId' => $item->id])
            ->handle(...$this->dependencies());

        $this->assertSame(ProcessingStatus::NeedsReview, $item->fresh()->processing_status);
    }

    public function test_a_track_with_an_album_completes_normally(): void
    {
        $item = $this->track('Some Artist');
        $item->musicMetadata->forceFill(['album' => 'A Record'])->save();

        app(EnrichMediaItemJob::class, ['mediaItemId' => $item->id])
            ->handle(...$this->dependencies());

        $this->assertSame(ProcessingStatus::Complete, $item->fresh()->processing_status);
    }

    public function test_a_track_a_person_already_judged_is_left_alone(): void
    {
        // Dismissing "this really is a single" must stick, or the queue fills
        // with the same tracks after every scan — the trap S-302 fixed for
        // match review.
        $item = $this->track('Some Artist');
        $item->forceFill(['reviewed_at' => now()])->save();

        app(EnrichMediaItemJob::class, ['mediaItemId' => $item->id])
            ->handle(...$this->dependencies());

        $this->assertSame(ProcessingStatus::Complete, $item->fresh()->processing_status);
    }

    /** @return array<int, object> */
    private function dependencies(): array
    {
        return [
            app(\App\Services\Metadata\MetadataPipeline::class),
            app(\App\Services\LibraryOrganizer::class),
            app(\App\Services\MetadataHistory::class),
        ];
    }

    private function track(string $artist): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => 'A Song',
            'file_path' => '/tmp/song.mp3',
            'owned' => true,
        ]);

        $item->musicMetadata()->create(['artist' => $artist]);

        return $item->fresh();
    }
}
