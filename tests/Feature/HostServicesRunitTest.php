<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Filament\Pages\Services;
use App\Models\Profile;
use App\Models\User;
use App\Services\HostServices;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
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
    use RefreshDatabase;

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

    /** A control that failed is logged with what sv said (AGENTS.md rule 7). */
    public function test_a_refused_control_is_logged(): void
    {
        Log::spy();

        $host = $this->host([
            [0, 'run: soundchex-server: (pid 99) 9s'],
            [1, 'fail: soundchex-server: unable to open supervise/control: access denied'],
        ]);

        $host->stop('server');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_starts_with($message, 'runit:')
                && $context['action'] === 'down'
                && str_contains($context['output'], 'access denied'))
            ->once();
    }

    /**
     * The runit branch of the view compiles and renders: the one service, the
     * runit wording, and the warning before a stop that takes this page down.
     * No browser test covers the Services page, so this is where a Blade
     * mistake in that branch would show.
     */
    public function test_the_page_renders_the_runit_service(): void
    {
        $user = User::factory()->create();
        Profile::create(['user_id' => $user->id, 'name' => 'Owner', 'is_owner' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');

        $this->app->bind(HostServices::class, fn () => new class extends HostServices
        {
            public function manager(): ?string
            {
                return 'runit';
            }

            protected function sv(array $arguments): array
            {
                return [0, 'run: soundchex-server: (pid 7) 3s'];
            }
        });

        Livewire::test(Services::class)
            ->assertSet('manager', 'runit')
            ->assertSee('SoundChex Server (soundchex-server)')
            ->assertSee('Kept alive by runit')
            ->assertSeeHtml('wire:confirm="This stops the server serving this page.')
            ->assertDontSee('Install all');
    }

    /**
     * The page says why a stop failed. It used to ask the container for a
     * second HostServices to read `lastError` from — a fresh instance, so the
     * reason was always empty and the notification said nothing.
     */
    public function test_the_page_reports_why_a_stop_failed(): void
    {
        $user = User::factory()->create();
        Profile::create(['user_id' => $user->id, 'name' => 'Owner', 'is_owner' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');

        // A new instance per resolve, as the container really does it.
        $this->app->bind(HostServices::class, fn () => new class extends HostServices
        {
            public function manager(): ?string
            {
                return 'runit';
            }

            protected function sv(array $arguments): array
            {
                return $arguments[0] === 'status'
                    ? [0, 'run: soundchex-server: (pid 7) 3s']
                    : [1, 'fail: soundchex-server: unable to open supervise/control: access denied'];
            }
        });

        Livewire::test(Services::class)->call('stopService', 'server');

        // Filament moves notifications to `claimed_notifications` once a
        // Livewire request has rendered them, so both are read.
        $bodies = collect(session('filament.notifications', []))
            ->merge(session('filament.claimed_notifications', []))
            ->pluck('body')
            ->filter()
            ->implode(' ');

        $this->assertStringContainsString('access denied', $bodies);
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
