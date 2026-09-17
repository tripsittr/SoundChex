<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\Transfer;
use App\Models\TransferItem;
use App\Services\TransferReceiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Where a transferred file is allowed to land.
 *
 * The receiver wrote whatever path the source sent, joined onto its own
 * storage root — so it trusted a remote machine with the location of a file on
 * this one. A real transfer sent `/Users/…/storage/app/private/media/…` and it
 * began rebuilding the sender's filesystem inside the media folder, 31 files
 * in before it was noticed.
 *
 * The refusals matter more than the successes here: this decides where bytes
 * from another machine are written.
 */
class TransferPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_relative_path_is_taken_as_it_is(): void
    {
        $this->assertSame(
            'media/library/Music/Bon Iver/i.mp3',
            $this->pathFor('media/library/Music/Bon Iver/i.mp3'),
        );
    }

    public function test_an_absolute_path_is_reduced_to_the_media_root(): void
    {
        // What a source running older code sends. The media root is the part
        // that means anything on this machine.
        $this->assertSame(
            'media/unsorted/TiHKAL.pdf',
            $this->pathFor('/Users/tripsittr/Documents/GitHub/SoundChex/storage/app/private/media/unsorted/TiHKAL.pdf'),
        );
    }

    public function test_a_windows_absolute_path_is_reduced_too(): void
    {
        $this->assertSame(
            'media/library/Films/Backrooms.mkv',
            $this->pathFor('C:\\Users\\blaze\\SoundChex\\storage\\app\\private\\media\\library\\Films\\Backrooms.mkv'),
        );
    }

    public static function unusable(): array
    {
        return [
            'walks out of the media root' => ['media/../../../Windows/System32/drivers/etc/hosts'],
            'walks out from the start' => ['../../../etc/passwd'],
            'absolute with no media root' => ['/etc/passwd'],
            'windows absolute with no media root' => ['C:\\Windows\\System32\\calc.exe'],
            'a UNC share' => ['//attacker/share/payload.exe'],
            'nothing at all' => [''],
        ];
    }

    /** @dataProvider unusable */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusable')]
    public function test_a_path_it_cannot_place_is_refused(string $path): void
    {
        $this->assertNull(
            $this->pathFor($path),
            "Expected [{$path}] to be refused rather than written somewhere.",
        );
    }

    public function test_a_refused_item_is_left_out_rather_than_guessed_at(): void
    {
        // The whole manifest is not abandoned for one bad entry, and the bad
        // entry is not fetched to a guessed location either.
        Http::fake([
            '*/transfer/manifest*' => Http::response([
                'page' => 1,
                'per_page' => 500,
                'total' => 2,
                'items' => [
                    ['id' => 1, 'path' => 'media/library/good.mp3', 'bytes' => 10, 'hash' => null],
                    ['id' => 2, 'path' => '../../../etc/passwd', 'bytes' => 10, 'hash' => null],
                ],
            ]),
        ]);

        $transfer = Transfer::create([
            'source_url' => 'https://other.example',
            'token' => 'test-token',
            'wants' => ['files'],
            'state' => Transfer::RUNNING,
        ]);

        $this->assertTrue(app(TransferReceiver::class)->buildManifest($transfer));

        $paths = TransferItem::where('transfer_id', $transfer->id)->pluck('path')->all();

        $this->assertSame(['media/library/good.mp3'], $paths);
    }

    public function test_an_imported_catalogue_has_its_paths_rewritten(): void
    {
        // Without this the transfer is pointless: every row points at
        // /Users/… , absoluteFilePath() returns null for all of them on this
        // machine, and the catalogue finds nothing however many files arrive.
        $user = \App\Models\User::factory()->create();

        $absolute = \App\Models\MediaItem::create([
            'user_id' => $user->id,
            'type' => \App\Enums\MediaItemType::Movie,
            'title' => 'From the other machine',
            'file_path' => '/Users/tripsittr/Documents/GitHub/SoundChex/storage/app/private/media/library/Film.mkv',
        ]);

        $alreadyRelative = \App\Models\MediaItem::create([
            'user_id' => $user->id,
            'type' => \App\Enums\MediaItemType::Movie,
            'title' => 'Already ours',
            'file_path' => 'media/library/Ours.mkv',
        ]);

        $this->assertSame(1, app(TransferReceiver::class)->makeCataloguePathsRelative());

        $this->assertSame('media/library/Film.mkv', $absolute->fresh()->file_path);

        // A catalogue that is already this machine's shape is left alone.
        $this->assertSame('media/library/Ours.mkv', $alreadyRelative->fresh()->file_path);
    }

    /**
     * Runs a path through the manifest, which is the only way in.
     *
     * Through `buildManifest()` rather than by calling the private method, so
     * this exercises the route a real manifest takes.
     */
    private function pathFor(string $path): ?string
    {
        Http::fake([
            '*/transfer/manifest*' => Http::response([
                'page' => 1,
                'per_page' => 500,
                'total' => 1,
                'items' => [['id' => 7, 'path' => $path, 'bytes' => 10, 'hash' => null]],
            ]),
        ]);

        $transfer = Transfer::create([
            'source_url' => 'https://other.example',
            'token' => 'test-token',
            'wants' => ['files'],
            'state' => Transfer::RUNNING,
        ]);

        app(TransferReceiver::class)->buildManifest($transfer);

        return TransferItem::where('transfer_id', $transfer->id)->value('path');
    }
    public function test_a_complete_part_file_is_placed_rather_than_refetched(): void
    {
        // Driven through fetch(), not verifyAndPlace(), because the fix lives
        // in fetch(). Asserting on verifyAndPlace alone passed with the fix
        // removed — the test exercised code that was never broken.
        //
        // The fake answers any file request with 416, which is what a real
        // server returns for a range starting past the end. So if fetch()
        // re-requests instead of placing what is already there, this fails.
        Http::fake([
            '*/transfer/file/*' => Http::response('range not satisfiable', 416),
        ]);

        $transfer = Transfer::create([
            'source_url' => 'https://other.example',
            'token' => 'test-token',
            'wants' => ['files'],
            'state' => Transfer::RUNNING,
        ]);

        $item = TransferItem::create([
            'transfer_id' => $transfer->id,
            'remote_id' => 7,
            'path' => 'complete-part.mp3',
            'state' => TransferItem::PENDING,
            'expected_bytes' => 10,
            'expected_hash' => hash(TransferReceiver::HASH, 'abcdefghij'),
        ]);

        $destination = Storage::path('complete-part.mp3');
        @mkdir(dirname($destination), 0775, true);
        file_put_contents($destination . '.part', 'abcdefghij');

        $this->assertTrue(app(TransferReceiver::class)->fetch($item->fresh()));
        $this->assertFileExists($destination);
        $this->assertFileDoesNotExist($destination . '.part');

        @unlink($destination);
    }


}
