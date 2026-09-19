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

            // MUST NOT touch these:
            'title is the artist' => ['Neon Trees', ['Neon Trees'], null],
            'title contains the artist' => ['In A Big Country', ['Big Country'], null],
            'no separator' => ['Big Country Song', ['Big Country'], null],
            'artist not at an edge' => ['A Song by $uicideboy$ live', ['$uicideboy$'], null],
            'empty remainder' => ['$uicideboy$ - ', ['$uicideboy$'], null],
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
