<?php

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\MusicCredits;
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
