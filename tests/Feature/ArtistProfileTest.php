<?php

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use App\Services\ArtistProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Who an artist is, on their own page.
 *
 * Everything here comes from MusicBrainz and Wikipedia, and most of a
 * self-hosted library will match neither. The page has to be as correct with
 * nothing as with everything, and a wrong artist is worse than no artist —
 * it puts someone else's photograph and life on your record.
 */
class ArtistProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_a_profile_when_there_is_one(): void
    {
        $this->track('Avicii');

        Person::create([
            'name' => 'Avicii',
            'artist_type' => 'Person',
            'country' => 'SE',
            'began' => '1989-09-08',
            'ended' => '2018-04-20',
            'biography' => 'Tim Bergling, known professionally as Avicii, was a Swedish DJ.',
            'headshot_url' => '/storage/artists/1.jpg',
            'profile_synced_at' => now(),
        ]);

        $this->actingAs($this->owner())
            ->get('/app/artist?name=Avicii')
            ->assertOk()
            ->assertSee('Avicii')
            ->assertSee('Swedish DJ', false)
            ->assertSee('1989')
            ->assertSee('/storage/artists/1.jpg');
    }

    public function test_the_page_is_the_same_shape_without_one(): void
    {
        // The common case, and the one that must not break.
        $this->track('Some Local Band');

        $this->actingAs($this->owner())
            ->get('/app/artist?name=' . urlencode('Some Local Band'))
            ->assertOk()
            ->assertSee('Some Local Band')
            ->assertDontSee('storage/artists');
    }

    public function test_a_band_is_labelled_a_band(): void
    {
        $this->track('Local Natives');

        Person::create([
            'name' => 'Local Natives',
            'artist_type' => 'Group',
            'profile_synced_at' => now(),
        ]);

        $this->actingAs($this->owner())
            ->get('/app/artist?name=' . urlencode('Local Natives'))
            ->assertOk()
            ->assertSee('Band');
    }

    /* ------------------------------------------------------ the lookup --- */

    public function test_it_refuses_an_ambiguous_name_rather_than_guessing(): void
    {
        // A wrong artist attached to a page is worse than an empty one.
        Http::fake([
            'musicbrainz.org/ws/2/artist*' => Http::response([
                'artists' => [
                    ['id' => 'wrong-one', 'name' => 'Nomad', 'score' => 70],
                ],
            ]),
        ]);

        $person = Person::create(['name' => 'Nomad']);

        $this->assertFalse(app(ArtistProfiles::class)->fetch($person));
        $this->assertNull($person->fresh()->musicbrainz_artist_id);
    }

    public function test_a_fruitless_lookup_is_not_repeated_every_run(): void
    {
        Http::fake(['musicbrainz.org/*' => Http::response(['artists' => []])]);

        $person = Person::create(['name' => 'Nobody']);

        app(ArtistProfiles::class)->fetch($person);

        // Stamped even though nothing was found, so the next run skips it.
        $this->assertNotNull($person->fresh()->profile_synced_at);
        $this->assertFalse(app(ArtistProfiles::class)->isStale($person->fresh()));
    }

    public function test_a_recent_profile_is_left_alone(): void
    {
        Http::fake();

        $person = Person::create([
            'name' => 'Avicii',
            'profile_synced_at' => now()->subDay(),
        ]);

        $this->assertFalse(app(ArtistProfiles::class)->fetch($person));

        Http::assertNothingSent();
    }

    /* --------------------------------------------------------- helpers --- */

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function track(string $artist): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->owner()->id,
            'type' => MediaItemType::Music,
            'title' => 'A Song',
            'file_path' => '/tmp/song.mp3',
            'owned' => true,
        ]);

        $item->musicMetadata()->create([
            'artist' => $artist,
            'primary_artist' => $artist,
            'album' => 'An Album',
        ]);

        return $item;
    }
}
