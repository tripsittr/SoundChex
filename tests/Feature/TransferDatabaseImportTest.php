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

        // Refused, and this instance is still readable — which is the point.
        $this->assertNotNull(Transfer::find($transfer->id));
        $this->assertNotNull($transfer->fresh()->last_error);
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
