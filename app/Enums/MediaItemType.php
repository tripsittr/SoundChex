<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Enums;

enum MediaItemType: string
{
    case Music = 'music';
    case Movie = 'movie';
    case Show = 'show';
    case Book = 'book';

    public function label(): string
    {
        return match ($this) {
            self::Music => 'Music',
            self::Movie => 'Movie',
            self::Show => 'TV Show',
            self::Book => 'Book',
        };
    }
}
