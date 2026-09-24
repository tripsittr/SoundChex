<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Services\TitleTidier;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Stripping an artist a title carries (S-272), and — just as important — not
 * mangling a title that legitimately is or contains the artist.
 */
class TitleTidierTest extends TestCase
{
    private TitleTidier $tidier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tidier = new TitleTidier;
    }

    /** @return array<string, array{string, array<int, string>, ?string}> */
    public static function cases(): array
    {
        return [
            // Prefix form — the reported bug.
            'artist prefix' => ['$uicideboy$ - Converting', ['$uicideboy$'], 'Converting'],
            'prefix keeps inner dashes' => ['$uicideboy$ - Kill Yourself, Pt II', ['$uicideboy$'], 'Kill Yourself, Pt II'],
            'full credit prefix strips first' => ['$uicideboy$, Pouya - Song', ['$uicideboy$, Pouya', '$uicideboy$'], 'Song'],

            // Suffix form — the existing behaviour, preserved.
            'artist suffix' => ['Gold - Imagine Dragons', ['Imagine Dragons'], 'Gold'],

            // En/em dashes.
            'en dash' => ['Radiohead – Creep', ['Radiohead'], 'Creep'],

            // A tagger that wrote the whole credit into the title: neither
            // name alone is the prefix, the joined pair is (S-365).
            'joined by a comma' => [
                '$uicideboy$, Maxo Cream - Pictures (feat Maxo Cream)',
                ['$uicideboy$', 'Maxo Cream'],
                'Pictures (feat Maxo Cream)',
            ],
            'joined by an ampersand' => [
                'Run The Jewels & Zack de la Rocha - Close Your Eyes',
                ['Run The Jewels', 'Zack de la Rocha'],
                'Close Your Eyes',
            ],
            'joined by feat.' => [
                'Gorillaz feat. De La Soul - Feel Good Inc',
                ['Gorillaz', 'De La Soul'],
                'Feel Good Inc',
            ],

            // The tagger recorded the credit slash-separated but wrote it into
            // the title comma-separated — the real library case (S-365).
            'credit split by a slash, title by a comma' => [
                '$uicideboy$, Maxo Cream - Pictures (feat Maxo Cream)',
                ['$uicideboy$/Maxo Cream', '$uicideboy$'],
                'Pictures (feat Maxo Cream)',
            ],
            'one name of a split credit is the whole prefix' => [
                '$uicideboy$ - Venom',
                ['$uicideboy$/Shakewell', '$uicideboy$'],
                'Venom',
            ],

            // Underscores and spaces are one separator to a tagger (S-365).
            'underscored artist, spaced title' => [
                'Plague tsc - Creature From The Crypt',
                ['Plague_tsc'],
                'Creature From The Crypt',
            ],
            'spaced artist, underscored title' => [
                'Plague_tsc - Creature From The Crypt',
                ['Plague tsc'],
                'Creature From The Crypt',
            ],

            // MUST NOT touch these:
            'title is the artist' => ['Neon Trees', ['Neon Trees'], null],
            'title contains the artist' => ['In A Big Country', ['Big Country'], null],
            'no separator' => ['Big Country Song', ['Big Country'], null],
            'artist not at an edge' => ['A Song by $uicideboy$ live', ['$uicideboy$'], null],
            'empty remainder' => ['$uicideboy$ - ', ['$uicideboy$'], null],

            // The artist list names a band that is also the title. Stripping the
            // prefix would keep the credit and throw the title away, so the
            // remainder being nothing but artist names vetoes the strip. The
            // tagger's "Jody K. Jenkins" against the title's "Jody K Jenkins"
            // must still count as the same person (S-365).
            'remainder is only the credit' => [
                'Adiemus - Karl Jenkins, Jody K Jenkins, Adiemus, Mary Carewe',
                ['Karl Jenkins, Jody K. Jenkins, Adiemus, Mary Carewe', 'Karl Jenkins'],
                null,
            ],

            // Splitting a credit must not make a common word strippable: "and"
            // is glue, not a name, and a title is not its own artist (S-365).
            'split name that is the whole title' => [
                'Shakewell',
                ['$uicideboy$/Shakewell'],
                null,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $artists
     */
    #[DataProvider('cases')]
    public function test_strip(string $title, array $artists, ?string $expected): void
    {
        $this->assertSame($expected, $this->tidier->strip($title, $artists));
    }

    public function test_it_ignores_blank_artists(): void
    {
        $this->assertNull($this->tidier->strip('Some Title', [null, '', '  ']));
    }
}
