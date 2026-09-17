<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use App\Services\MusicCredits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Credits are the record of who made a track, so the failures that matter are
 * the ones that quietly lose or duplicate a person.
 *
 * Enrichment re-runs on every scan. A writer that appends rather than replaces
 * would grow a credit list forever, and one that matches on name alone would
 * merge two artists who happen to share one.
 */
class MusicCreditsTest extends TestCase
{
    use RefreshDatabase;

    private MusicCredits $credits;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credits = app(MusicCredits::class);
        $this->user = User::factory()->create();
    }

    /* ------------------------------------------------ from a credit string */

    public function test_the_first_name_is_the_primary_and_the_rest_are_featured(): void
    {
        $item = $this->track();

        $this->credits->fromCreditString($item, '$uicideboy$, Pouya');

        $this->assertSame('$uicideboy$', $this->roleOf($item, MusicCredits::PRIMARY));
        $this->assertSame(['Pouya'], $this->namesFor($item, MusicCredits::FEATURED));
    }

    public function test_billing_order_survives(): void
    {
        $item = $this->track();

        // A different order is a different record, so the position is data.
        $this->credits->fromCreditString($item, 'Pouya, Germ, $uicideboy$, Sdotbraddy');

        $ordered = $item->people()->orderByPivot('sort_order')->pluck('name')->all();

        $this->assertSame(['Pouya', 'Germ', '$uicideboy$', 'Sdotbraddy'], $ordered);
    }

    public function test_a_name_that_contains_a_comma_stays_one_person(): void
    {
        $item = $this->track();

        $this->credits->fromCreditString($item, 'Hank Williams, Jr.');

        $this->assertSame('Hank Williams, Jr.', $this->roleOf($item, MusicCredits::PRIMARY));
        $this->assertSame([], $this->namesFor($item, MusicCredits::FEATURED));
    }

    public function test_a_blank_credit_writes_nothing(): void
    {
        $item = $this->track();

        $this->assertSame([], $this->credits->fromCreditString($item, null));
        $this->assertSame(0, $item->people()->count());
    }

    /* -------------------------------------------------- from MusicBrainz -- */

    public function test_musicbrainz_credits_carry_the_artist_id(): void
    {
        $item = $this->track();

        $this->credits->fromMusicBrainz($item, [
            ['artist' => ['name' => 'Ev0lution', 'id' => 'mbid-one']],
            ['artist' => ['name' => 'Erik Neugebauer', 'id' => 'mbid-two']],
        ]);

        $this->assertSame('Ev0lution', $this->roleOf($item, MusicCredits::PRIMARY));
        $this->assertSame('mbid-one', Person::where('name', 'Ev0lution')->value('musicbrainz_artist_id'));
    }

    public function test_two_artists_sharing_a_name_stay_separate(): void
    {
        // Matching on name alone would merge them, and one artist's work would
        // appear on the other's page.
        Person::create(['name' => 'Nomad', 'musicbrainz_artist_id' => 'mbid-first']);

        $this->credits->fromMusicBrainz($this->track(), [
            ['artist' => ['name' => 'Nomad', 'id' => 'mbid-second']],
        ]);

        $this->assertSame(2, Person::where('name', 'Nomad')->count());
    }

    public function test_an_id_learned_later_attaches_to_the_existing_person(): void
    {
        // The string path creates a person with no id. MusicBrainz matching the
        // same track afterwards should fill it in, not make a second row.
        $item = $this->track();

        $this->credits->fromCreditString($item, 'Avicii');
        $this->credits->fromMusicBrainz($item, [
            ['artist' => ['name' => 'Avicii', 'id' => 'mbid-avicii']],
        ]);

        $this->assertSame(1, Person::where('name', 'Avicii')->count());
        $this->assertSame('mbid-avicii', Person::where('name', 'Avicii')->value('musicbrainz_artist_id'));
    }

    /* ------------------------------------------------------- re-running --- */

    public function test_writing_twice_does_not_duplicate_a_credit(): void
    {
        $item = $this->track();

        $this->credits->fromCreditString($item, 'Alan Jackson, Jimmy Buffett');
        $this->credits->fromCreditString($item, 'Alan Jackson, Jimmy Buffett');

        $this->assertSame(2, $item->people()->count());
    }

    public function test_a_corrected_credit_removes_the_artist_it_was_wrong_about(): void
    {
        $item = $this->track();

        $this->credits->fromCreditString($item, 'Alan Jackson, Wrong Person');
        $this->credits->fromCreditString($item, 'Alan Jackson, Jimmy Buffett');

        $names = $item->people()->pluck('name')->all();

        $this->assertNotContains('Wrong Person', $names);
        $this->assertContains('Jimmy Buffett', $names);
    }

    public function test_a_dry_run_writes_no_credits(): void
    {
        $item = $this->track();

        $names = $this->credits->fromCreditString($item, 'Avicii, Nicky Romero', dryRun: true);

        // It reports what it would write...
        $this->assertSame(['Avicii', 'Nicky Romero'], $names);

        // ...and writes none of it.
        $this->assertSame(0, $item->people()->count());
        $this->assertSame(0, Person::count());
    }

    public function test_music_credits_leave_other_roles_alone(): void
    {
        // The same table holds authors and directors. Rewriting a track's
        // artists must not detach anything else attached to the item.
        $item = $this->track();
        $composer = Person::create(['name' => 'A Composer']);
        $item->people()->attach($composer->id, ['role' => 'composer', 'sort_order' => 0]);

        $this->credits->fromCreditString($item, 'Avicii');

        $this->assertSame(1, $item->people()->wherePivot('role', 'composer')->count());
    }

    /* ----------------------------------------------------------- helpers -- */

    private function track(): MediaItem
    {
        return MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'A Song',
            'file_path' => '/tmp/song.mp3',
            'owned' => true,
        ]);
    }

    private function roleOf(MediaItem $item, string $role): ?string
    {
        return $item->people()->wherePivot('role', $role)->value('name');
    }

    /** @return list<string> */
    private function namesFor(MediaItem $item, string $role): array
    {
        return $item->people()->wherePivot('role', $role)->orderByPivot('sort_order')->pluck('name')->all();
    }
}
