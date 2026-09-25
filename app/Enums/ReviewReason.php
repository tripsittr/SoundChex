<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Enums;

/**
 * Why someone sent an item back for review (S-398).
 *
 * The reason is the whole value of the report. "Something is wrong with this"
 * tells an admin to go looking; "the cover is wrong" tells them where to look
 * and what to fix, and lets the panel group a hundred reports into the three
 * jobs they actually are.
 *
 * Deliberately short. A list of twenty reasons is a list nobody reads, and the
 * free-text note carries whatever the five categories cannot.
 */
enum ReviewReason: string
{
    case Metadata = 'metadata';
    case File = 'file';
    case Cover = 'cover';
    case Duplicate = 'duplicate';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Metadata => 'Wrong metadata',
            self::File => 'File problem',
            self::Cover => 'Wrong cover',
            self::Duplicate => 'Duplicate',
            self::Other => 'Something else',
        };
    }

    /**
     * The line under the label in the picker.
     *
     * Written so someone choosing in a hurry picks the right one: each says
     * what it covers, in the words a person would use about their own library
     * rather than the words the schema uses.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Metadata => 'Wrong title, artist, album, year or genre',
            self::File => 'Wrong file, bad quality, or it will not play',
            self::Cover => 'Missing artwork, or artwork from something else',
            self::Duplicate => 'This is already in the library',
            self::Other => 'Anything the other reasons do not cover',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Metadata => 'warning',
            self::File => 'danger',
            self::Cover => 'info',
            self::Duplicate => 'gray',
            self::Other => 'gray',
        };
    }

    /** @return array<string, string> value => label, for a select. */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
