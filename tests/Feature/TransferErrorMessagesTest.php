<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

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
 * was the first thing a real transfer between two machines hit.
 *
 * It used to point at php.ini: Windows PHP shipped with no CA bundle. The app
 * now supplies one globally (S-75), so this error no longer means a missing
 * bundle — it means the remote's certificate is genuinely unverifiable, and
 * the message says so instead of sending people to edit a file that is fine.
 */
class TransferErrorMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_certificate_failure_says_what_to_fix(): void
    {
        $this->assertMessage(
            'cURL error 60: SSL certificate problem: unable to get local issuer certificate',
            'could not verify',
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

    public function test_it_waits_longer_than_the_default_for_a_relayed_connection(): void
    {
        // Three failures in one real transfer were "Connection timed out after
        // 10014 milliseconds" — the ten-second default — two of them while the
        // source was up and 31 other files were arriving. A relayed tailnet is
        // slower to connect than a direct one.
        // Asserted on the option rather than on behaviour: observing it
        // otherwise needs a server that accepts slowly, which a test cannot
        // conjure and a fake cannot represent.
        $http = new \ReflectionMethod(TransferReceiver::class, 'http');
        $http->setAccessible(true);

        $pending = $http->invoke(app(TransferReceiver::class));

        $options = new \ReflectionProperty($pending, 'options');
        $options->setAccessible(true);

        $this->assertSame(
            30,
            $options->getValue($pending)['connect_timeout'] ?? null,
            'The transfer client is back on the ten-second default.',
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
