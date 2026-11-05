<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Services\HostServices;
use Tests\TestCase;

/**
 * The Services page on a runit host (HatCOS), where the whole stack is one
 * service — `soundchex-server --headless` — driven with `sv`.
 *
 * `sv` is never run for real: a stand-in answers with the text runit prints,
 * so these pass on macOS and Windows and cannot stop a server by accident. The
 * parse is the risky part — a misread "down" shown as "running" is the silent
 * failure this page exists to prevent.
 */
class HostServicesRunitTest extends TestCase
{
    /**
     * A HostServices that believes it is on Linux with runit, and answers `sv`
     * from a script of replies.
     *
     * @param  array<int, array{0: int, 1: string}>  $replies  in call order; the last repeats
     */
    private function host(array $replies, ?array &$calls = null): HostServices
    {
        $calls = [];

        return new class($replies, $calls) extends HostServices
        {
            public function __construct(private array $replies, private array &$calls) {}

            public function manager(): ?string
            {
                // The real check wants PHP_OS_FAMILY === 'Linux'; the rest of
                // it — is there such a service — is what is under test.
                return $this->runitPresent() && $this->runitStatus() !== null ? 'runit' : null;
            }

            protected function runitPresent(): bool
            {
                return true;
            }

            protected function sv(array $arguments): array
            {
                $this->calls[] = implode(' ', $arguments);

                return count($this->replies) > 1 ? array_shift($this->replies) : $this->replies[0];
            }
        };
    }

    public function test_a_running_service_is_one_row_with_its_pid(): void
    {
        $host = $this->host([[0, 'run: soundchex-server: (pid 1234) 56s; run: log: (pid 1233) 56s']]);

        $services = $host->all();

        $this->assertSame(['server'], array_keys($services));
        $this->assertTrue($services['server']['installed']);
        $this->assertTrue($services['server']['running']);
        // The service's pid, not the log service's after the semicolon.
        $this->assertStringContainsString('pid 1234', $services['server']['detail']);
        $this->assertTrue($host->supported());
    }

    public function test_a_stopped_service_reads_as_stopped(): void
    {
        $services = $this->host([[0, 'down: soundchex-server: 12s, normally up']])->all();

        $this->assertTrue($services['server']['installed']);
        $this->assertFalse($services['server']['running']);
    }

    /** runit without the service: nothing to manage, so no buttons. */
    public function test_runit_without_the_service_is_not_managed(): void
    {
        $host = $this->host([[1, 'fail: soundchex-server: unable to change to service directory: file does not exist']]);

        $this->assertNull($host->runitStatus());
        $this->assertNull($host->manager());
        $this->assertFalse($host->supported());
    }

    /** Present but not this user's to control: shown with the reason. */
    public function test_no_permission_on_supervise_is_explained(): void
    {
        $host = $this->host([[1, 'warning: soundchex-server: unable to open supervise/ok: access denied']]);

        $status = $host->runitStatus();

        $this->assertSame('unknown', $status['state']);
        $this->assertFalse($status['controllable']);
        $this->assertStringContainsString('access denied', $host->all()['server']['detail']);
    }

    public function test_start_and_stop_go_through_sv_and_are_confirmed(): void
    {
        $host = $this->host([
            [0, 'down: soundchex-server: 3s, normally up'],     // manager()
            [0, 'ok: run: soundchex-server: (pid 99) 0s'],      // sv up
            [0, 'run: soundchex-server: (pid 99) 1s'],          // read back
        ], $calls);

        $this->assertTrue($host->start('server'));
        $this->assertSame(['status soundchex-server', 'up soundchex-server', 'status soundchex-server'], $calls);

        $host = $this->host([
            [0, 'run: soundchex-server: (pid 99) 9s'],
            [0, 'ok: down: soundchex-server: 0s, normally up'],
            [0, 'down: soundchex-server: 0s, normally up'],
        ], $calls);

        $this->assertTrue($host->stop('server'));
        $this->assertSame('down soundchex-server', $calls[1]);
    }

    /**
     * sv exits zero for a request it only accepted. A stop that left the
     * service running must not be reported as done.
     */
    public function test_a_control_that_did_not_take_is_a_failure_with_the_reason(): void
    {
        $host = $this->host([
            [0, 'run: soundchex-server: (pid 99) 9s'],
            [0, 'timeout: run: soundchex-server: (pid 99) 16s'],
            [0, 'run: soundchex-server: (pid 99) 16s'],
        ]);

        $this->assertFalse($host->stop('server'));
        $this->assertStringContainsString('timeout', $host->lastError);
    }

    public function test_a_refused_control_reports_what_sv_said(): void
    {
        $host = $this->host([
            [0, 'run: soundchex-server: (pid 99) 9s'],
            [1, 'fail: soundchex-server: unable to open supervise/control: access denied'],
        ]);

        $this->assertFalse($host->stop('server'));
        $this->assertStringContainsString('access denied', $host->lastError);
    }

    public function test_only_the_one_service_can_be_addressed(): void
    {
        $host = $this->host([[0, 'run: soundchex-server: (pid 1) 1s']], $calls);

        $this->assertFalse($host->start('queue'));
        $this->assertFalse($host->stop('serve'));
        $this->assertFalse($host->install('server'));
        $this->assertNotNull($host->lastError);
        $this->assertNotContains('up soundchex-server', $calls);
        $this->assertNotContains('down soundchex-server', $calls);
    }

    public function test_the_service_name_is_configurable(): void
    {
        config(['soundchex.runit.service' => 'media']);

        $this->host([[0, 'run: media: (pid 5) 1s']], $calls)->all();

        $this->assertSame('status media', $calls[0]);
    }

    public function test_an_unreadable_log_says_where_it_should_be(): void
    {
        config(['soundchex.runit.log' => '/nonexistent/soundchex/current']);

        $log = $this->host([[0, 'run: soundchex-server: (pid 1) 1s']])->log('server');

        $this->assertStringContainsString('/nonexistent/soundchex/current', $log);
    }

    public function test_a_readable_log_is_tailed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scx-runit-log');
        file_put_contents($path, implode("\n", array_map(fn ($i) => "line {$i}", range(1, 100)))."\n");
        config(['soundchex.runit.log' => $path]);

        try {
            $log = $this->host([[0, 'run: soundchex-server: (pid 1) 1s']])->log('server', 5);

            $this->assertStringEndsWith('line 100', $log);
            $this->assertStringNotContainsString('line 90', $log);
        } finally {
            @unlink($path);
        }
    }
}
