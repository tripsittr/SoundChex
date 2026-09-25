<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Console\Commands\DlnaServe;
use App\Models\Profile;
use App\Models\User;
use App\Services\Dlna\DlnaSettings;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The SSDP messages devices actually parse (S-7).
 *
 * Tested through reflection rather than by binding port 1900: a test suite
 * that opens a multicast socket is a test suite that fails on a locked-down
 * CI box, and fights any real media server on the developer's own network.
 * The message format is the part that has to be right — devices parse these
 * strictly and a malformed header means the server simply never appears.
 */
class DlnaDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private DlnaSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id, 'name' => 'Owner', 'is_owner' => true]);

        $store = app(SettingsService::class);
        $store->set(DlnaSettings::ENABLED, true);
        $store->set(DlnaSettings::PROFILE, $profile->id);

        $this->settings = app(DlnaSettings::class);
    }

    /** Named `invoke`, not `call`: TestCase already has a `call()`. */
    private function invoke(string $method, array $args = []): mixed
    {
        $reflected = new ReflectionMethod(DlnaServe::class, $method);

        return $reflected->invokeArgs(app(DlnaServe::class), $args);
    }

    private function searchResponse(string $target = 'urn:schemas-upnp-org:device:MediaServer:1'): string
    {
        return $this->invoke('response', [$this->settings, 'http://192.168.1.5:8200', $target]);
    }

    public function test_a_search_reply_carries_the_headers_a_device_needs(): void
    {
        $response = $this->searchResponse();

        $this->assertStringStartsWith("HTTP/1.1 200 OK\r\n", $response);
        // LOCATION is how the device finds the description; without it the
        // reply is well-formed and useless.
        $this->assertStringContainsString("LOCATION: http://192.168.1.5:8200/dlna/device.xml\r\n", $response);
        $this->assertStringContainsString('USN: uuid:'.$this->settings->uuid(), $response);
        $this->assertStringContainsString('ST: urn:schemas-upnp-org:device:MediaServer:1', $response);
    }

    public function test_messages_end_with_a_blank_line(): void
    {
        // Devices that parse strictly drop a message without the terminator.
        $this->assertStringEndsWith("\r\n\r\n", $this->searchResponse());
    }

    public function test_it_answers_the_searches_that_are_looking_for_a_media_server(): void
    {
        foreach ([
            'ssdp:all',
            'upnp:rootdevice',
            'urn:schemas-upnp-org:device:MediaServer:1',
            'urn:schemas-upnp-org:service:ContentDirectory:1',
        ] as $target) {
            $this->assertTrue($this->invoke('wantsUs', [$target]), "should answer {$target}");
        }
    }

    public function test_it_ignores_searches_meant_for_other_kinds_of_device(): void
    {
        // A LAN is full of these — printers, routers, lightbulbs. Answering
        // them is noise every other device has to parse.
        foreach ([
            'urn:schemas-upnp-org:device:InternetGatewayDevice:1',
            'urn:schemas-upnp-org:device:Printer:1',
            '',
        ] as $target) {
            $this->assertFalse($this->invoke('wantsUs', [$target]), "should ignore {$target}");
        }
    }

    public function test_the_search_target_is_read_from_the_request(): void
    {
        $request = "M-SEARCH * HTTP/1.1\r\nHOST: 239.255.255.250:1900\r\n"
            ."MAN: \"ssdp:discover\"\r\nMX: 2\r\n"
            ."ST: urn:schemas-upnp-org:device:MediaServer:1\r\n\r\n";

        $this->assertSame(
            'urn:schemas-upnp-org:device:MediaServer:1',
            $this->invoke('searchTarget', [$request]),
        );
    }

    public function test_a_request_without_a_search_target_yields_nothing_rather_than_matching(): void
    {
        $this->assertSame('', $this->invoke('searchTarget', ["M-SEARCH * HTTP/1.1\r\nHOST: x\r\n\r\n"]));
        $this->assertFalse($this->invoke('wantsUs', ['']));
    }
}
