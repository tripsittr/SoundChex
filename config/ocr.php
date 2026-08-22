<?php

/**
 * Optical character recognition for scanned books.
 *
 * Recognition runs on this machine — nothing is uploaded anywhere. It needs
 * Tesseract and Poppler, both of which are single installs:
 *
 *   macOS    brew install tesseract poppler
 *   Debian   apt install tesseract-ocr poppler-utils
 *
 * Extra languages are separate packages (tesseract-lang on brew,
 * tesseract-ocr-deu and friends on Debian).
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Binaries
    |--------------------------------------------------------------------------
    |
    | Left as bare names so they resolve on PATH. Set an absolute path if the
    | queue worker runs with a different environment to your shell — a common
    | difference between a login shell and a system service.
    |
    */

    'tesseract_path' => env('OCR_TESSERACT_PATH', 'tesseract'),
    'pdftoppm_path' => env('OCR_PDFTOPPM_PATH', 'pdftoppm'),
    'pdftotext_path' => env('OCR_PDFTOTEXT_PATH', 'pdftotext'),
    'pdfinfo_path' => env('OCR_PDFINFO_PATH', 'pdfinfo'),

    /*
    |--------------------------------------------------------------------------
    | Language
    |--------------------------------------------------------------------------
    |
    | Tesseract language code. Several can be combined with a plus for a book
    | that mixes them, e.g. "eng+fra". Overridable in the admin panel.
    |
    */

    'language' => env('OCR_LANGUAGE', 'eng'),

    /*
    |--------------------------------------------------------------------------
    | Recognise On Demand
    |--------------------------------------------------------------------------
    |
    | When a reader opens a page with no text, recognise just that page in the
    | background so it becomes selectable a moment later. The alternative is
    | waiting for a whole-book pass that may never have been started.
    |
    */

    'on_demand' => (bool) env('OCR_ON_DEMAND', true),

    /*
    |--------------------------------------------------------------------------
    | Pages Either Side
    |--------------------------------------------------------------------------
    |
    | With on-demand recognition, also do this many pages ahead and behind, so
    | paging forward through a scan doesn't stall on every turn.
    |
    */

    'prefetch_pages' => (int) env('OCR_PREFETCH_PAGES', 2),
];
