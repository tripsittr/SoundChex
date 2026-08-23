<?php

namespace Tests\Feature;

use App\Models\Transfer;
use App\Services\TransferReceiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Replacing this machine's catalogue with another's.
 *
 * The most destructive thing in the application: it replaces the database
 * holding play history, playlists and profiles — none of which rescanning can
 * rebuild. So the failures worth testing are the ones that would replace it
 * with something wrong.
 *
 * Most of these run against a scratch database file rather than `:memory:`.
 * On `:memory:` the import stops at "no database file to replace" before it
 * reaches anything else, which is how the refusal below used to pass without
 * ever running the check it was named for.
 */
class TransferDatabaseImportTest extends TestCase
{
    use RefreshDatabase;

    /** A scratch storage root, so nothing here writes into the real one. */
    private string $storage;

    /** A scratch catalogue, standing in for the one that would be replaced. */
    private string $catalogue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir() . '/soundchex-transfer-' . bin2hex(random_bytes(6));

        mkdir($this->storage . '/app/backups', 0775, true);

        $this->app->useStoragePath($this->storage);

        $this->catalogue = $this->storage . '/scratch-catalogue.sqlite';

        file_put_contents($this->catalogue, 'SQLite format 3' . "\0" . 'the existing catalogue');
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->storage);

        parent::tearDown();
    }

    public function test_it_refuses_anything_that_is_not_a_database(): void
    {
        // The realistic version of this is a login page: a token that expired
        // gets HTML back with a 200, and replacing a catalogue with HTML would
        // be discovered only afterwards.
        Http::fake(['*/transfer/database*' => Http::response(gzencode('<html>Please sign in</html>'))]);

        $this->useScratchCatalogue();

        $transfer = $this->transfer();

        $this->assertFalse(app(TransferReceiver::class)->importDatabase($transfer));

        $this->assertStringContainsString('not a database', $transfer->fresh()->last_error);

        // The existing catalogue is untouched, which is the whole point.
        $this->assertStringContainsString('the existing catalogue', file_get_contents($this->catalogue));
    }

    public function test_a_real_catalogue_is_put_in_place_and_the_old_one_kept(): void
    {
        // The success path, so the refusal above is known to be a refusal
        // rather than the import never working at all.
        $arriving = 'SQLite format 3' . "\0" . 'the catalogue from the other machine';

        Http::fake(['*/transfer/database*' => Http::response(gzencode($arriving))]);

        $this->useScratchCatalogue();

        $transfer = $this->transfer();

        $this->assertTrue(app(TransferReceiver::class)->importDatabase($transfer));
        $this->assertSame($arriving, file_get_contents($this->catalogue));

        // Play history, playlists and profiles are not rebuildable, so the
        // one that was replaced is still on disk.
        $backups = glob($this->storage . '/app/backups/before-transfer-*.sqlite');

        $this->assertNotEmpty($backups, 'The replaced catalogue was not backed up.');
        $this->assertStringContainsString('the existing catalogue', file_get_contents($backups[0]));
    }

    public function test_it_replaces_a_catalogue_something_else_holds_open(): void
    {
        // The failure this covers: `rename()` over a file another process has
        // open is refused on Windows with "Access is denied", and the
        // catalogue is held open by the app, the scheduler and the queue
        // worker running this. The arrived catalogue downloaded, unpacked and
        // verified, and then could not be put in place.
        $arriving = 'SQLite format 3' . "\0" . 'the catalogue from the other machine';

        Http::fake(['*/transfer/database*' => Http::response(gzencode($arriving))]);

        $this->useScratchCatalogue();

        // Stands in for every process that has the live catalogue open.
        $holder = fopen($this->catalogue, 'rb');

        $this->assertTrue(app(TransferReceiver::class)->importDatabase($this->transfer()));

        fclose($holder);

        $this->assertSame($arriving, file_get_contents($this->catalogue));
    }

    public function test_the_old_write_ahead_log_does_not_survive_the_swap(): void
    {
        // It belongs to the catalogue that was just replaced. Several megabytes
        // of another database's pending writes sitting beside a fresh file is
        // not something to leave and hope is ignored.
        Http::fake(['*/transfer/database*' => Http::response(gzencode('SQLite format 3' . "\0" . 'new'))]);

        $this->useScratchCatalogue();

        file_put_contents($this->catalogue . '-wal', 'pages from the old catalogue');
        file_put_contents($this->catalogue . '-shm', 'shared memory index');

        $this->assertTrue(app(TransferReceiver::class)->importDatabase($this->transfer()));

        $this->assertFileDoesNotExist($this->catalogue . '-wal');
        $this->assertFileDoesNotExist($this->catalogue . '-shm');
    }

    public function test_a_catalogue_that_cannot_be_placed_is_kept_for_the_retry(): void
    {
        // Deleting it meant fetching the whole catalogue again to retry a
        // rename. What arrived was correct — it is the swap that failed.
        Http::fake(['*/transfer/database*' => Http::response(gzencode('SQLite format 3' . "\0" . 'new'))]);

        $this->useScratchCatalogue();

        // A directory cannot be opened for writing or renamed over, so both
        // routes fail and the staged copy is all that is left.
        @unlink($this->catalogue);
        mkdir($this->catalogue);

        $transfer = $this->transfer();

        $this->assertFalse(app(TransferReceiver::class)->importDatabase($transfer));

        $this->assertFileExists($this->catalogue . '.incoming');
        $this->assertStringContainsString('could not be put in place', $transfer->fresh()->last_error);

        rmdir($this->catalogue);
    }

    public function test_it_refuses_when_there_is_no_database_file_to_replace(): void
    {
        // The tests run on :memory:, and the import reads the path from the
        // connection rather than assuming database_path('database.sqlite').
        //
        // That assumption is why this test exists: it ignored the connection
        // entirely, so running the suite wrote to the real library's database
        // and destroyed it. A hardcoded path is not a detail when the thing at
        // the end of it is the only copy.
        Http::fake(['*/transfer/database*' => Http::response(gzencode('anything'))]);

        $transfer = $this->transfer();

        $this->assertFalse(app(TransferReceiver::class)->importDatabase($transfer));
        $this->assertStringContainsString('no database file', $transfer->fresh()->last_error);
    }

    public function test_a_failed_download_replaces_nothing(): void
    {
        Http::fake(['*/transfer/database*' => Http::response('', 403)]);

        $transfer = $this->transfer();

        $this->assertFalse(app(TransferReceiver::class)->importDatabase($transfer));
        $this->assertStringContainsString('403', $transfer->fresh()->last_error);
    }

    public function test_a_failure_records_what_the_server_said(): void
    {
        // The status code on its own sent someone to the other machine's log
        // to find out what "500" meant. The body had said, and was discarded.
        Http::fake([
            '*/transfer/database*' => Http::response(
                json_encode(['message' => 'gzopen(): could not make seekable']),
                500,
            ),
        ]);

        $transfer = $this->transfer();

        $this->assertFalse(app(TransferReceiver::class)->importDatabase($transfer));

        $error = $transfer->fresh()->last_error;

        $this->assertStringContainsString('500', $error);
        $this->assertStringContainsString('could not make seekable', $error);
    }

    public function test_each_transfer_downloads_into_its_own_file(): void
    {
        // One shared name meant a single archive left behind — or left in a
        // delete-pending state by an unclosed handle — failed every transfer
        // after it with a permission error, before it had asked the source
        // for anything.
        $receiver = app(TransferReceiver::class);

        $this->assertNotSame(
            $receiver->archivePath($this->transfer()),
            $receiver->archivePath($this->transfer()),
        );
    }

    public function test_a_failed_download_leaves_no_archive_behind(): void
    {
        Http::fake(['*/transfer/database*' => Http::response('nope', 500)]);

        $transfer = $this->transfer();

        $this->assertFalse(app(TransferReceiver::class)->importDatabase($transfer));

        $this->assertFileDoesNotExist(app(TransferReceiver::class)->archivePath($transfer));
    }

    /**
     * Points the connection's configured path at the scratch catalogue.
     *
     * Config only: the connection Eloquent is already using stays `:memory:`,
     * so the harness is untouched while the code under test reads a real file
     * — which is the only way to reach the checks that come after it.
     */
    private function useScratchCatalogue(): void
    {
        config(['database.connections.' . config('database.default') . '.database' => $this->catalogue]);
    }

    private function transfer(): Transfer
    {
        return Transfer::create([
            'source_url' => 'https://other.example',
            'token' => 'test-token',
            'wants' => ['metadata'],
            'state' => Transfer::RUNNING,
        ]);
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
