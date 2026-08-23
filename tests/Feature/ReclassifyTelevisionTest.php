<?php

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Repairing items already catalogued as films that are television.
 *
 * The scanner skips files it has seen, so correcting the classification
 * corrects nothing already in the library. In the real one that was 50 of 159
 * films — 48 season-zero specials and two `S06X01` files.
 *
 * It reports and changes nothing unless asked, because it rewrites rows in the
 * only copy of a real catalogue.
 */
class ReclassifyTelevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_changes_nothing_without_apply(): void
    {
        $item = $this->film('The Simpsons S00E07 The Krusty Ad.mkv');

        $this->artisan('library:reclassify')
            ->expectsOutputToContain('1 item(s) catalogued as films are television')
            ->assertSuccessful();

        // Still a film, because nothing was applied. A repair that runs by
        // default is a repair nobody chose.
        $this->assertSame(MediaItemType::Movie, $item->fresh()->type);
    }

    public function test_apply_corrects_the_type_and_the_title(): void
    {
        $item = $this->film('The Simpsons S00E07 The Krusty Ad.mkv', 'The Simpsons');

        $this->artisan('library:reclassify --apply')->assertSuccessful();

        $item->refresh();

        $this->assertSame(MediaItemType::Show, $item->type);

        // All 48 specials shared the bare series name, so the library held 48
        // rows called "The Simpsons" with nothing to tell them apart.
        $this->assertSame('The Simpsons S00E07', $item->title);
    }

    public function test_it_corrects_the_season_prefixed_x_form(): void
    {
        $item = $this->film('The Simpsons S06X01 Homer and Bart.mkv');

        $this->artisan('library:reclassify --apply')->assertSuccessful();

        $this->assertSame(MediaItemType::Show, $item->fresh()->type);
        $this->assertSame('The Simpsons S06E01', $item->fresh()->title);
    }

    public function test_it_leaves_real_films_alone(): void
    {
        // The expensive direction to get wrong: a film retyped as a show is a
        // film that disappears out of the films.
        $film = $this->film('Dumb And Dumber 1994 1080p BluRay HEVC x265 5.1 BONE.mkv', 'Dumb And Dumber');
        $another = $this->film('Blade Runner 2049.mkv', 'Blade Runner 2049');

        $this->artisan('library:reclassify --apply')
            ->expectsOutputToContain('Nothing catalogued as a film looks like television')
            ->assertSuccessful();

        $this->assertSame(MediaItemType::Movie, $film->fresh()->type);
        $this->assertSame(MediaItemType::Movie, $another->fresh()->type);
        $this->assertSame('Dumb And Dumber', $film->fresh()->title);
    }

    public function test_it_leaves_things_that_are_already_shows_alone(): void
    {
        $show = MediaItem::create([
            'user_id' => $this->owner()->id,
            'type' => MediaItemType::Show,
            'title' => 'The Bear S01E01',
            'file_path' => 'media/unsorted/The.Bear.S01E01.mkv',
        ]);

        $this->artisan('library:reclassify --apply')->assertSuccessful();

        $this->assertSame('The Bear S01E01', $show->fresh()->title);
    }

    private function owner(): User
    {
        return $this->user ??= User::factory()->create();
    }

    private ?User $user = null;

    private function film(string $filename, ?string $title = null): MediaItem
    {
        return MediaItem::create([
            'user_id' => $this->owner()->id,
            'type' => MediaItemType::Movie,
            'title' => $title ?? pathinfo($filename, PATHINFO_FILENAME),
            'file_path' => 'media/unsorted/' . $filename,
        ]);
    }
}
