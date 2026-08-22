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
 */
class TransferDatabaseImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_anything_that_is_not_a_database(): void
    {
        // The realistic version of this is a login page: a token that expired
        // gets HTML back with a 200, and replacing a catalogue with HTML would
        // be discovered only afterwards.
        Http::fake(['*/transfer/database*' => Http::response(gzencode('<html>Please sign in</html>'))]);

        $transfer = $this->transfer();

        $this->assertFalse(app(TransferReceiver::class)->importDatabase($transfer));

        $this->assertStringContainsString('not a database', $transfer->fresh()->last_error);

        // Still readable, which is the point.
        $this->assertNotNull(Transfer::find($transfer->id));
    }

    public function test_it_backs_up_before_replacing_anything(): void
    {
        Http::fake(['*/transfer/database*' => Http::response(gzencode('<html>nope</html>'))]);

        app(TransferReceiver::class)->importDatabase($this->transfer());

        // Taken before the download, so even a failed import leaves one.
        $backups = glob(storage_path('app/backups/before-transfer-*.sqlite'));

        $this->assertNotEmpty($backups, 'No backup was taken before replacing the catalogue.');

        foreach ($backups as $backup) {
            @unlink($backup);
        }
    }

    public function test_a_failed_download_replaces_nothing(): void
    {
        Http::fake(['*/transfer/database*' => Http::response('', 403)]);

        $transfer = $this->transfer();

        $this->assertFalse(app(TransferReceiver::class)->importDatabase($transfer));
        $this->assertStringContainsString('403', $transfer->fresh()->last_error);
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
}
