<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Unit;

use App\Services\ArtistCredits;
use PHPUnit\Framework\TestCase;

/**
 * Deciding where a track belongs by reading a free-text credit.
 *
 * The successes are easy and the exceptions are the whole job: a rule that
 * splits on every comma turns one artist into two and files half their work
 * under a suffix.
 */
class ArtistCreditsTest extends TestCase
{
    private ArtistCredits $credits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credits = new ArtistCredits();
    }

    /* ------------------------------------------------- the ordinary case -- */

    public function test_it_takes_the_first_of_a_comma_separated_credit(): void
    {
        $this->assertSame('Alan Jackson', $this->credits->primary('Alan Jackson, Jimmy Buffett'));
        $this->assertSame('Avicii', $this->credits->primary('Avicii, Nicky Romero'));
        $this->assertSame('America', $this->credits->primary('America, George Martin'));
    }

    public function test_it_handles_a_slash_as_well_as_a_comma(): void
    {
        // The library uses both, and one artist uses only slashes.
        $this->assertSame('$uicideboy$', $this->credits->primary('$uicideboy$/Maxo Cream'));
        $this->assertSame('$uicideboy$', $this->credits->primary('$uicideboy$/Shakewell'));
    }

    public function test_a_single_artist_is_returned_unchanged(): void
    {
        $this->assertSame('Avicii', $this->credits->primary('Avicii'));
        $this->assertSame('American Authors', $this->credits->primary('American Authors'));
    }

    /* ------------------------------------------------------ the traps ----- */

    public function test_a_generational_suffix_is_not_a_second_artist(): void
    {
        // In the library. Splitting on the comma files half of Hank Williams
        // under "Jr.".
        $this->assertSame('Hank Williams, Jr.', $this->credits->primary('Hank Williams, Jr.'));
        $this->assertSame('Sammy Davis, Jr', $this->credits->primary('Sammy Davis, Jr'));
        $this->assertSame('Harry Connick, Jr.', $this->credits->primary('Harry Connick, Jr.'));
    }

    public function test_a_suffix_in_the_middle_of_a_credit_stays_attached(): void
    {
        // A real credit in the library. Splitting it naively files his work
        // under two artists — "Hank Williams, Jr." for one track and
        // "Hank Williams" for another.
        $this->assertSame(
            'Hank Williams, Jr.',
            $this->credits->primary('Hank Williams, Jr., Reba McEntire, Willie Nelson, Tom Petty'),
        );

        $this->assertSame(
            ['Hank Williams, Jr.', 'Reba McEntire', 'Willie Nelson'],
            $this->credits->all('Hank Williams, Jr., Reba McEntire, Willie Nelson'),
        );
    }

    public function test_a_band_whose_name_contains_a_comma_stays_whole(): void
    {
        // Not in the library today. That is the point: the rule has to be
        // right before the record arrives, not after someone notices.
        $this->assertSame('Earth, Wind & Fire', $this->credits->primary('Earth, Wind & Fire'));
        $this->assertSame('Crosby, Stills & Nash', $this->credits->primary('Crosby, Stills & Nash'));
        $this->assertSame('Tyler, The Creator', $this->credits->primary('Tyler, The Creator'));
    }

    public function test_a_band_with_a_comma_stays_whole_when_it_collaborates(): void
    {
        // The first version of the guard only matched the whole string, so the
        // band was protected alone and split the moment anyone joined them —
        // "Earth, Wind & Fire, Santana" filed the track under "Earth".
        $this->assertSame(
            'Earth, Wind & Fire',
            $this->credits->primary('Earth, Wind & Fire, Santana'),
        );

        $this->assertSame(
            ['Earth, Wind & Fire', 'Santana'],
            $this->credits->all('Earth, Wind & Fire, Santana'),
        );

        $this->assertSame(
            'Tyler, The Creator',
            $this->credits->primary('Tyler, The Creator/Frank Ocean'),
        );
    }

    public function test_a_known_name_billed_second_is_not_shattered(): void
    {
        // The guard first protected only a prefix. Billed second, the band was
        // split into two artists who do not exist — "Earth" and "Wind & Fire" —
        // and primary() still looked right, so only all() showed the damage.
        $this->assertSame(
            ['Santana', 'Earth, Wind & Fire'],
            $this->credits->all('Santana, Earth, Wind & Fire'),
        );

        $this->assertSame(
            ['Florence + The Machine', 'Earth, Wind & Fire'],
            $this->credits->all('Florence + The Machine, Earth, Wind & Fire'),
        );
    }

    public function test_a_suffix_attaches_to_its_own_name_not_the_one_before(): void
    {
        // "Willie Nelson, Hank Williams, Jr." glued both names into one person
        // when the suffix rule ran against the wrong fragment.
        $this->assertSame(
            ['Willie Nelson', 'Hank Williams, Jr.'],
            $this->credits->all('Willie Nelson, Hank Williams, Jr.'),
        );

        $this->assertSame(
            ['Reba McEntire', 'Hank Williams, Jr.', 'Tom Petty'],
            $this->credits->all('Reba McEntire, Hank Williams, Jr., Tom Petty'),
        );
    }

    public function test_the_longest_matching_name_wins(): void
    {
        // Both "Crosby, Stills & Nash" and "Crosby, Stills, Nash & Young" are
        // on the list, and the shorter one is a prefix of neither — but the
        // credit has to resolve to the band that actually made the record.
        $this->assertSame(
            'Crosby, Stills, Nash & Young',
            $this->credits->primary('Crosby, Stills, Nash & Young, Neil Young'),
        );
    }

    public function test_a_known_name_is_not_matched_inside_a_longer_one(): void
    {
        // "America" must not swallow "American Authors", which is in the
        // library and is a different band entirely.
        $this->assertSame('American Authors', $this->credits->primary('American Authors'));
        $this->assertSame('America', $this->credits->primary('America, George Martin'));
    }

    public function test_a_known_name_is_normalised_to_its_canonical_spelling(): void
    {
        // Matching ignores case, and the list's spelling is the one kept — so
        // a badly-tagged file groups with the correctly-tagged ones rather
        // than becoming a second artist with the same name.
        $this->assertSame('Earth, Wind & Fire', $this->credits->primary('earth, wind & fire'));
        $this->assertSame('Earth, Wind & Fire', $this->credits->primary('EARTH, WIND & FIRE, Santana'));
    }

    /* ---------------------------------------------------- odd input ------- */

    public function test_a_blank_credit_has_no_primary(): void
    {
        // Null rather than '' so a caller can fall back to the raw column.
        $this->assertNull($this->credits->primary(null));
        $this->assertNull($this->credits->primary(''));
        $this->assertNull($this->credits->primary('   '));
    }

    public function test_a_credit_that_begins_with_a_separator_does_not_vanish(): void
    {
        // A leading separator is malformed, and the name after it is the real
        // artist. Recovering it beats preserving the punctuation, and either
        // beats reducing the whole thing to an empty string, which would
        // silently unfile the track.
        $this->assertSame('Pouya', $this->credits->primary(', Pouya'));
        $this->assertNotSame('', $this->credits->primary(', Pouya'));
    }

    /* ------------------------------------------------------ everyone ------ */

    public function test_it_lists_every_credited_artist_primary_first(): void
    {
        $this->assertSame(
            ['$uicideboy$', 'Pouya'],
            $this->credits->all('$uicideboy$, Pouya'),
        );

        $this->assertSame(
            ['Pouya', 'Germ', '$uicideboy$', 'Sdotbraddy'],
            $this->credits->all('Pouya, Germ, $uicideboy$, Sdotbraddy'),
        );
    }

    public function test_an_indivisible_name_lists_as_one_artist(): void
    {
        $this->assertSame(['Earth, Wind & Fire'], $this->credits->all('Earth, Wind & Fire'));
        $this->assertSame(['Hank Williams, Jr.'], $this->credits->all('Hank Williams, Jr.'));
    }

    public function test_it_knows_a_collaboration_from_a_solo_credit(): void
    {
        $this->assertTrue($this->credits->isCollaboration('Alan Jackson, Jimmy Buffett'));
        $this->assertTrue($this->credits->isCollaboration('$uicideboy$/Maxo Cream'));

        $this->assertFalse($this->credits->isCollaboration('Avicii'));
        $this->assertFalse($this->credits->isCollaboration('Hank Williams, Jr.'));
        $this->assertFalse($this->credits->isCollaboration('Earth, Wind & Fire'));
        $this->assertFalse($this->credits->isCollaboration(null));
    }
}
