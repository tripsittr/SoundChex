<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\FileMoveKind;
use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\FileMoveJournal;
use App\Services\MediaTrash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The integrity check around a bulk reprocess (#470).
 *
 * a5's review of #280: *"the manifest safety net is untested… the dangerous
 * failure is a **false negative** — a genuinely lost file reported as
 * accounted-for — which would hide exactly the loss the manifest exists to
 * catch. An untested safety net is the kind this phase is meant to end."*
 *
 * Correct, and the strongest note of the rebuild. These exercise **every
 * branch** of the classification an operator is about to trust with 8,330
 * files:
 *
 *  - a deleted file → **LOST**, and a non-zero exit
 *  - a moved file with the same bytes → **accounted for**
 *  - a trashed file with a journal row → **recoverable**
 *  - altered contents → **changed**, not silently fine
 *  - a file already missing when the manifest was written → **not** blamed on
 *    the reprocess
 */
class LibraryManifestTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $manifest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->manifest = sys_get_temp_dir().'/sc-manifest-'.bin2hex(random_bytes(6)).'.tsv';
    }

    protected function tearDown(): void
    {
        @unlink($this->manifest);

        parent::tearDown();
    }

    /* -------------------------------------------------------- writing --- */

    public function test_it_records_path_size_and_hash_for_every_file(): void
    {
        $this->fileAt('media/library/a.mp3', 'the audio');
        $this->fileAt('media/library/b.mp3', 'more audio');

        $this->write();

        $lines = $this->records();

        $this->assertCount(2, $lines);
        $this->assertNotSame('', $lines[0]['hash']);
        $this->assertSame(strlen('the audio'), $lines[0]['size']);
    }

    public function test_it_names_the_algorithm_so_the_file_explains_itself(): void
    {
        // A manifest read in a year's time has to say what its hashes are.
        $this->fileAt('media/library/a.mp3', 'audio');

        $this->write();

        $this->assertStringContainsString('xxh128', (string) file_get_contents($this->manifest));
    }

    public function test_a_file_already_missing_is_recorded_as_missing_not_omitted(): void
    {
        // The distinction that stops a pre-existing problem looking like one
        // the reprocess caused.
        $this->rowWithoutFile('media/library/never-existed.mp3');

        $this->write();

        $records = $this->records();

        $this->assertCount(1, $records);
        $this->assertSame('MISSING', $records[0]['hash']);
    }

    public function test_the_limit_is_respected(): void
    {
        // lazyById() re-chunks by id and DISCARDS limit(), so a run asked for
        // one record wrote all of them until this was counted by hand.
        $this->fileAt('media/library/a.mp3', 'a');
        $this->fileAt('media/library/b.mp3', 'b');
        $this->fileAt('media/library/c.mp3', 'c');

        $this->artisan('library:manifest', ['--out' => $this->manifest, '--limit' => 1])
            ->assertSuccessful();

        $this->assertCount(1, $this->records());
    }

    /* ------------------------------------------------------- verifying -- */

    public function test_an_untouched_library_verifies(): void
    {
        $this->fileAt('media/library/a.mp3', 'the audio');

        $this->write();

        $this->artisan('library:verify-manifest', ['manifest' => $this->manifest])
            ->expectsOutputToContain('Every recorded file is accounted for')
            ->assertSuccessful();
    }

    public function test_a_deleted_file_is_reported_as_lost(): void
    {
        // The case the whole thing exists for, and the one a false negative
        // would hide.
        [$item, $path] = $this->fileAt('media/library/a.mp3', 'the audio');

        $this->write();

        unlink($path);

        $this->artisan('library:verify-manifest', ['manifest' => $this->manifest])
            ->expectsOutputToContain('LOST')
            ->assertFailed();
    }

    public function test_a_lost_file_makes_the_command_exit_non_zero(): void
    {
        // So it can gate a batch in a script. The plan says verify after each
        // batch of 500, and a check nobody acts on is not a check.
        [, $path] = $this->fileAt('media/library/a.mp3', 'the audio');

        $this->write();
        unlink($path);

        $this->artisan('library:verify-manifest', ['manifest' => $this->manifest])->assertExitCode(1);
    }

    public function test_a_moved_file_with_the_same_bytes_is_accounted_for(): void
    {
        // What a successful reprocess looks like: the file is somewhere else,
        // the row knows, and the bytes are identical.
        [$item, $path] = $this->fileAt('media/library/old/a.mp3', 'the audio');

        $this->write();

        $target = Storage::disk('local')->path('media/library/new/a.mp3');
        @mkdir(dirname($target), 0775, true);

        app(FileMoveJournal::class)->move($item, $path, $target);

        $this->artisan('library:verify-manifest', ['manifest' => $this->manifest])
            ->expectsOutputToContain('Every recorded file is accounted for')
            ->assertSuccessful();
    }

    public function test_a_trashed_file_is_recoverable_not_lost(): void
    {
        // Deliberately removed, with a journal row proving it. Reporting that
        // as a loss would make every resolved duplicate look like a disaster.
        [$item, $path] = $this->fileAt('media/library/a.mp3', 'the audio');

        $this->write();

        app(FileMoveJournal::class)->trash($item, $path, 'duplicate resolved');

        $this->artisan('library:verify-manifest', ['manifest' => $this->manifest])
            ->expectsOutputToContain('Every recorded file is accounted for')
            ->assertSuccessful();
    }

    public function test_a_file_removed_without_a_journal_row_is_still_lost(): void
    {
        // The other half of the trash case: "gone" only counts as deliberate
        // when something recorded it. Otherwise the trash would become an
        // excuse for any disappearance.
        [$item, $path] = $this->fileAt('media/library/a.mp3', 'the audio');

        $this->write();

        // Moved to the trash by hand, with nothing journalled.
        app(MediaTrash::class)->discard($path);

        $this->artisan('library:verify-manifest', ['manifest' => $this->manifest])
            ->expectsOutputToContain('LOST')
            ->assertFailed();
    }

    public function test_altered_contents_are_reported_as_changed(): void
    {
        // Present but different. Distinguished from lost because a re-tag or a
        // cover embed legitimately changes the bytes -- and silently calling
        // that "fine" would hide a real corruption.
        [, $path] = $this->fileAt('media/library/a.mp3', 'the audio');

        $this->write();

        file_put_contents($path, 'something else entirely');

        $this->artisan('library:verify-manifest', ['manifest' => $this->manifest])
            ->expectsOutputToContain('Contents changed')
            ->assertFailed();
    }

    public function test_no_hash_skips_the_content_comparison(): void
    {
        // For a video library where hashing every file costs an hour. Size
        // still has to match, so a truncated file is still caught.
        [, $path] = $this->fileAt('media/library/a.mp3', 'the audio');

        $this->write();

        // Same length, different bytes -- invisible without a hash, which is
        // the trade --no-hash makes explicit.
        file_put_contents($path, 'THE AUDIO');

        $this->artisan('library:verify-manifest', ['manifest' => $this->manifest, '--no-hash' => true])
            ->assertSuccessful();
    }

    public function test_a_file_already_missing_is_not_blamed_on_the_reprocess(): void
    {
        // Recorded as MISSING when written, so afterwards it is "was already
        // missing" rather than "lost". Conflating them would make every
        // pre-existing gap look like damage.
        $this->rowWithoutFile('media/library/never-existed.mp3');

        $this->write();

        $this->artisan('library:verify-manifest', ['manifest' => $this->manifest])
            ->expectsOutputToContain('Was already missing before')
            ->assertSuccessful();
    }

    public function test_verifying_a_manifest_that_is_not_there_fails_clearly(): void
    {
        $this->artisan('library:verify-manifest', ['manifest' => '/nonexistent/manifest.tsv'])
            ->expectsOutputToContain('No manifest at')
            ->assertFailed();
    }

    public function test_a_manifest_with_no_records_fails_rather_than_passing_vacuously(): void
    {
        // An empty manifest verifying successfully would be the worst kind of
        // false negative: everything is accounted for because nothing was
        // recorded.
        file_put_contents($this->manifest, "# SoundChex library manifest\n# nothing here\n");

        $this->artisan('library:verify-manifest', ['manifest' => $this->manifest])
            ->expectsOutputToContain('no records')
            ->assertFailed();
    }

    public function test_the_reader_rejoins_a_path_split_across_fields(): void
    {
        // The manifest is tab-separated, so the reader re-joins everything
        // after the third field rather than taking only the fourth. Tested at
        // the reader rather than by writing such a file: Laravel's storage
        // layer refuses a path containing a tab outright ("Corrupted path
        // detected"), so the round trip cannot be exercised end to end -- but
        // the parser still has to be right, because a manifest can be written
        // on a filesystem that allowed it and verified here.
        file_put_contents($this->manifest, implode("\n", [
            '# SoundChex library manifest',
            "# id\tsize\thash\tpath",
            "1\t9\tabc123\tmedia/library/odd\tname.mp3",
        ])."\n");

        $method = new \ReflectionMethod(\App\Console\Commands\VerifyLibraryManifest::class, 'read');
        $rows = $method->invoke(app(\App\Console\Commands\VerifyLibraryManifest::class), $this->manifest);

        $this->assertCount(1, $rows);
        $this->assertSame(
            "media/library/odd\tname.mp3",
            $rows[0]['path'],
            'A tab inside a path must not truncate it, or the file reads as a different one and reports LOST.',
        );
    }

    /* -------------------------------------------------------- helpers --- */

    private function write(): void
    {
        $this->artisan('library:manifest', ['--out' => $this->manifest])->assertSuccessful();
    }

    /** @return array<int, array{id: int, size: ?int, hash: string, path: string}> */
    private function records(): array
    {
        $records = [];

        foreach ((array) file($this->manifest, FILE_IGNORE_NEW_LINES) as $line) {
            if (! is_string($line) || $line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode("\t", $line);

            $records[] = [
                'id' => (int) $parts[0],
                'size' => ($parts[1] ?? '') === '' ? null : (int) $parts[1],
                'hash' => $parts[2] ?? '',
                'path' => implode("\t", array_slice($parts, 3)),
            ];
        }

        return $records;
    }

    /** @return array{0: MediaItem, 1: string} */
    private function fileAt(string $relative, string $contents): array
    {
        Storage::disk('local')->put($relative, $contents);
        $absolute = Storage::disk('local')->path($relative);

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => pathinfo($relative, PATHINFO_FILENAME),
            'file_path' => $absolute,
            'owned' => true,
        ]);

        return [$item->fresh(), $absolute];
    }

    private function rowWithoutFile(string $relative): MediaItem
    {
        return MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'Gone',
            'file_path' => Storage::disk('local')->path($relative),
            'owned' => true,
        ])->fresh();
    }
}
