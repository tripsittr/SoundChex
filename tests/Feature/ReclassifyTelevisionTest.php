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

    public function test_it_recatalogues_a_film_that_holds_no_video(): void
    {
        $file = $this->realFile('1044. Lights Out - Royal Blood.mp4');

        $item = MediaItem::create([
            'user_id' => $this->owner()->id,
            'type' => MediaItemType::Movie,
            'title' => 'Lights Out - Royal Blood',
            'file_path' => $file,
        ]);

        \Illuminate\Support\Facades\Process::fake([
            '*' => \Illuminate\Support\Facades\Process::result(
                output: json_encode(['streams' => [['codec_type' => 'audio', 'codec_name' => 'aac']]]),
            ),
        ]);

        $this->artisan('library:reclassify --apply')->assertSuccessful();

        $this->assertSame(MediaItemType::Music, $item->fresh()->type);
    }

    public function test_a_film_whose_file_is_not_here_is_left_alone(): void
    {
        // Most of the catalogue during a half-finished transfer. Treating
        // "cannot check" as "not a film" would empty the film list of
        // everything that has not arrived yet.
        $item = MediaItem::create([
            'user_id' => $this->owner()->id,
            'type' => MediaItemType::Movie,
            'title' => 'Not here yet',
            'file_path' => 'media/library/Films/NotHereYet.mp4',
        ]);

        \Illuminate\Support\Facades\Process::fake();

        $this->artisan('library:reclassify --apply')
            ->expectsOutputToContain('could not be checked')
            ->assertSuccessful();

        $this->assertSame(MediaItemType::Movie, $item->fresh()->type);

        // And did not spend a process launch on a file it does not have.
        \Illuminate\Support\Facades\Process::assertNothingRan();
    }

    public function test_a_film_is_left_alone_when_ffprobe_cannot_answer(): void
    {
        // The file is here, so the check is reached — and ffprobe fails, which
        // is what happens on a machine that has not got it installed. Reading
        // that as "no video" would recatalogue every film in the library as
        // music in one run.
        $item = MediaItem::create([
            'user_id' => $this->owner()->id,
            'type' => MediaItemType::Movie,
            'title' => 'Elf',
            'file_path' => $this->realFile('Elf 2003.mp4'),
        ]);

        \Illuminate\Support\Facades\Process::fake([
            '*' => \Illuminate\Support\Facades\Process::result(output: '', errorOutput: 'not found', exitCode: 1),
        ]);

        $this->artisan('library:reclassify --apply')
            ->expectsOutputToContain('could not be checked')
            ->assertSuccessful();

        $this->assertSame(MediaItemType::Movie, $item->fresh()->type);
    }

    public function test_a_film_with_video_is_left_alone(): void
    {
        $item = MediaItem::create([
            'user_id' => $this->owner()->id,
            'type' => MediaItemType::Movie,
            'title' => 'Backrooms',
            'file_path' => $this->realFile('Backrooms 2026.mp4'),
        ]);

        \Illuminate\Support\Facades\Process::fake([
            '*' => \Illuminate\Support\Facades\Process::result(
                output: json_encode(['streams' => [['codec_type' => 'video', 'codec_name' => 'h264']]]),
            ),
        ]);

        $this->artisan('library:reclassify --apply')->assertSuccessful();

        $this->assertSame(MediaItemType::Movie, $item->fresh()->type);
    }

    /** A real file under the faked disk, because the probe refuses absent ones. */
    private function realFile(string $name): string
    {
        $relative = 'media/unsorted/' . $name;
        $full = \Illuminate\Support\Facades\Storage::path($relative);

        @mkdir(dirname($full), 0755, true);
        file_put_contents($full, 'x');

        $this->scratch[] = $full;

        return $relative;
    }

    /** @var array<int, string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $path) {
            @unlink($path);
        }

        parent::tearDown();
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
