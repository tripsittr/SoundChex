<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\LibraryCsv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Import writes to the catalogue from a file the user supplies, so a bad row
 * must be skipped rather than aborting the run or landing half-formed. Export
 * has to round-trip, because re-importing an export is how a library moves
 * between machines — and it must not duplicate everything when it does.
 */
class LibraryCsvTest extends TestCase
{
    use RefreshDatabase;

    private LibraryCsv $csv;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->csv = app(LibraryCsv::class);
        $this->user = User::factory()->create();
    }

    /* ---------------------------------------------------------- import --- */

    public function test_it_imports_a_row(): void
    {
        $result = $this->import(<<<'CSV'
        title,type,notes
        Backrooms,movie,A film
        CSV);

        $this->assertSame(1, $result['imported']);
        $this->assertSame('Backrooms', MediaItem::unresolved()->first()->title);
        $this->assertSame(MediaItemType::Movie, MediaItem::unresolved()->first()->type);
    }

    public function test_column_order_does_not_matter(): void
    {
        // Matched on header name, so a file from another tool with extra
        // columns in a different order still imports.
        $result = $this->import(<<<'CSV'
        notes,extra_column,type,title
        A film,ignored,movie,Backrooms
        CSV);

        $this->assertSame(1, $result['imported']);
        $this->assertSame('A film', MediaItem::unresolved()->first()->notes);
    }

    public function test_headers_are_matched_case_insensitively(): void
    {
        $result = $this->import(<<<'CSV'
        Title,TYPE
        Backrooms,movie
        CSV);

        $this->assertSame(1, $result['imported']);
    }

    public function test_a_row_without_a_title_or_type_is_skipped_not_fatal(): void
    {
        // One malformed line in a thousand must not lose the other 999.
        $result = $this->import(<<<'CSV'
        title,type
        ,movie
        Backrooms,movie
        Valid Book,book
        Nonsense,not_a_type
        CSV);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(2, $result['skipped']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_reimporting_an_export_does_not_duplicate_the_library(): void
    {
        // The round trip that matters: moving a library between machines.
        $this->import(<<<'CSV'
        title,type
        Backrooms,movie
        CSV);

        $second = $this->import(<<<'CSV'
        title,type
        Backrooms,movie
        CSV);

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, MediaItem::unresolved()->count());
    }

    public function test_the_duplicate_check_ignores_case(): void
    {
        $this->import("title,type\nBackrooms,movie");
        $second = $this->import("title,type\nBACKROOMS,movie");

        $this->assertSame(0, $second['imported']);
    }

    public function test_the_same_title_in_two_media_types_is_not_a_duplicate(): void
    {
        // A novel and its adaptation share a name and are different things.
        $result = $this->import(<<<'CSV'
        title,type
        The Hobbit,book
        The Hobbit,movie
        CSV);

        $this->assertSame(2, $result['imported']);
    }

    public function test_an_empty_file_reports_an_error_rather_than_throwing(): void
    {
        $result = $this->import('');

        $this->assertSame(0, $result['imported']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_an_unreadable_path_reports_an_error(): void
    {
        $result = $this->csv->import('/tmp/does-not-exist-'.uniqid().'.csv', $this->user->id);

        $this->assertSame(0, $result['imported']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_a_header_only_file_imports_nothing(): void
    {
        $result = $this->import('title,type');

        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, MediaItem::unresolved()->count());
    }

    /* ---------------------------------------------------------- export --- */

    public function test_export_includes_a_header_and_the_rows(): void
    {
        $this->import("title,type\nBackrooms,movie");

        $rows = iterator_to_array($this->csv->export());

        $this->assertGreaterThanOrEqual(2, count($rows));
        $this->assertContains('title', array_map('strtolower', $rows[0]));
    }

    public function test_export_can_be_limited_to_one_type(): void
    {
        $this->import("title,type\nBackrooms,movie\nThe Hobbit,book");

        $movies = iterator_to_array($this->csv->export(MediaItemType::Movie));
        $flat = implode(' ', array_map(fn (array $r): string => implode(' ', $r), $movies));

        $this->assertStringContainsString('Backrooms', $flat);
        $this->assertStringNotContainsString('The Hobbit', $flat);
    }

    /* -------------------------------------------------------- helpers --- */

    /** @return array{imported: int, skipped: int, errors: array<int, string>} */
    private function import(string $contents): array
    {
        // Dedented so the heredocs above read naturally; a leading-space
        // header would not match any column.
        $csv = implode("\n", array_map('trim', explode("\n", trim($contents))));

        Storage::disk('local')->put('import.csv', $csv);

        return $this->csv->import(
            Storage::disk('local')->path('import.csv'),
            $this->user->id,
        );
    }
}
