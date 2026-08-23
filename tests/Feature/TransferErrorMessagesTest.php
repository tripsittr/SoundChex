<?php

namespace Tests\Feature;

use App\Models\Transfer;
use App\Services\TransferReceiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What a failed transfer tells the person who started it.
 *
 * cURL's messages are accurate and useless. "error 60: unable to get local
 * issuer certificate" is exactly right and says nothing about what to do — it
 * was the first thing a real transfer between two machines hit, and it reads
 * as a network problem when it is a missing file on the machine asking.
 */
class TransferErrorMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_certificate_failure_says_what_to_fix(): void
    {
        $this->assertMessage(
            'cURL error 60: SSL certificate problem: unable to get local issuer certificate',
            'CA bundle',
        );
    }

    public function test_an_unresolvable_address_says_so(): void
    {
        $this->assertMessage('cURL error 6: Could not resolve host: nope.example', 'does not resolve');
    }

    public function test_a_refused_connection_says_so(): void
    {
        $this->assertMessage('cURL error 7: Failed to connect to host port 443', 'refused the connection');
    }

    public function test_a_timeout_says_so(): void
    {
        $this->assertMessage('cURL error 28: Operation timed out after 30000 ms', 'did not answer in time');
    }

    public function test_a_file_that_cannot_be_written_is_not_reported_as_a_network_problem(): void
    {
        // It reads like one and is not. On Windows a file unlinked while
        // something still holds it open keeps its name in a delete-pending
        // state, and every later open of that name is refused — so a transfer
        // fails here having never left the machine.
        $this->assertMessage(
            'fopen(storage/app/transfer-incoming.sqlite.gz): Failed to open stream: Permission denied',
            'still holding the previous one open',
        );
    }

    public function test_anything_unrecognised_is_passed_through(): void
    {
        // Better a raw message than a wrong guess at what it means.
        $this->assertMessage('cURL error 99: something new', 'something new');
    }

    private function assertMessage(string $thrown, string $expected): void
    {
        Http::fake(fn () => throw new ConnectionException($thrown));

        $transfer = Transfer::create([
            'source_url' => 'https://other.example',
            'wants' => ['metadata'],
            'state' => Transfer::REQUESTED,
        ]);

        $this->assertFalse(app(TransferReceiver::class)->request($transfer));
        $this->assertStringContainsString($expected, $transfer->fresh()->last_error);
    }
}
