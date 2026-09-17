<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/**
 * Book asset extraction.
 *
 * A PDF carries more than its text: illustrations, maps, plates, and the
 * outline its publisher built for navigation. All of it is already in the
 * file; this is what makes it reachable from the reader.
 *
 * Uses Poppler, the same dependency OCR needs:
 *
 *   macOS    brew install poppler
 *   Debian   apt install poppler-utils
 */

return [
    'pdfimages_path' => env('BOOKS_PDFIMAGES_PATH', 'pdfimages'),
    'pdftohtml_path' => env('BOOKS_PDFTOHTML_PATH', 'pdftohtml'),

    /*
    | Where extracted illustrations are written, relative to the private disk.
    | Excluded from the library scanner — an extracted plate is not a media
    | file to catalogue.
    */

    'asset_path' => env('BOOKS_ASSET_PATH', 'media/book-assets'),

    /*
    | Pull assets in automatically when a book is catalogued. Local work on a
    | file the user already has, so there's no reason not to.
    */

    'auto_extract' => (bool) env('BOOKS_AUTO_EXTRACT', true),
];
