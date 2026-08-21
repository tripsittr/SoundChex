<?php

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

    public function test_a_band_whose_name_contains_a_comma_stays_whole(): void
    {
        // Not in the library today. That is the point: the rule has to be
        // right before the record arrives, not after someone notices.
        $this->assertSame('Earth, Wind & Fire', $this->credits->primary('Earth, Wind & Fire'));
        $this->assertSame('Crosby, Stills & Nash', $this->credits->primary('Crosby, Stills & Nash'));
        $this->assertSame('Tyler, The Creator', $this->credits->primary('Tyler, The Creator'));
    }

    public function test_the_exception_list_ignores_case(): void
    {
        $this->assertSame('earth, wind & fire', $this->credits->primary('earth, wind & fire'));
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
        // Reducing this to an empty string would silently unfile the track.
        $this->assertSame(', Pouya', $this->credits->primary(', Pouya'));
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
