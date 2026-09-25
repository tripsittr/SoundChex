<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\Dlna\DlnaSettings;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The DLNA endpoints (S-7).
 *
 * These are the only unauthenticated routes in the app that serve library
 * content, because the protocol has no way to log in. What stands in for that
 * is here: off by default, and local callers only.
 */
class DlnaServerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);
    }

    private function enable(?Profile $profile = null): void
    {
        $settings = app(SettingsService::class);
        $settings->set(DlnaSettings::ENABLED, true);
        $settings->set(DlnaSettings::PROFILE, ($profile ?? $this->profile)->id);
    }

    private function track(string $title): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => $this->fixture('music/'.str($title)->slug().'.mp3', 'bytes'),
            'owned' => true,
        ]);

        $item->musicMetadata()->create(['artist' => 'An Artist']);

        return $item->fresh();
    }

    /** A Browse request shaped the way a real client sends one. */
    private function browse(string $objectId = '0'): \Illuminate\Testing\TestResponse
    {
        $soap = '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<s:Body><u:Browse xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1">'
            ."<ObjectID>{$objectId}</ObjectID><BrowseFlag>BrowseDirectChildren</BrowseFlag>"
            .'<Filter>*</Filter><StartingIndex>0</StartingIndex><RequestedCount>50</RequestedCount>'
            .'<SortCriteria></SortCriteria></u:Browse></s:Body></s:Envelope>';

        return $this->call('POST', '/dlna/control', [], [], [], [
            'CONTENT_TYPE' => 'text/xml; charset="utf-8"',
            'HTTP_SOAPACTION' => '"urn:schemas-upnp-org:service:ContentDirectory:1#Browse"',
            'REMOTE_ADDR' => '192.168.1.40',
        ], $soap);
    }

    /* ------------------------------------------------------------ gates --- */

    public function test_every_route_is_absent_until_dlna_is_switched_on(): void
    {
        // 404 rather than 403: a server not offering DLNA should look like it
        // has none, not like it has one that refused.
        $this->get('/dlna/device.xml')->assertNotFound();
        $this->browse()->assertNotFound();
    }

    public function test_switched_on_without_a_profile_still_serves_nothing(): void
    {
        // An unset profile must not be the permissive case.
        app(SettingsService::class)->set(DlnaSettings::ENABLED, true);

        $this->get('/dlna/device.xml')->assertNotFound();
    }

    public function test_a_caller_from_outside_the_lan_is_refused(): void
    {
        // SSDP never leaves the LAN, but these are ordinary routes on the same
        // server that answers the public address.
        $this->enable();

        $this->call('GET', '/dlna/device.xml', [], [], [], ['REMOTE_ADDR' => '203.0.113.9'])
            ->assertNotFound();
    }

    public function test_a_caller_on_the_lan_is_served(): void
    {
        $this->enable();

        $this->call('GET', '/dlna/device.xml', [], [], [], ['REMOTE_ADDR' => '192.168.1.40'])
            ->assertOk();
    }

    /* ----------------------------------------------------------- device --- */

    public function test_the_device_description_is_valid_xml_with_a_stable_udn(): void
    {
        $this->enable();

        $first = $this->call('GET', '/dlna/device.xml', [], [], [], ['REMOTE_ADDR' => '192.168.1.40']);
        $first->assertOk();

        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($first->getContent()));
        $this->assertStringContainsString('urn:schemas-upnp-org:device:MediaServer:1', $first->getContent());

        // A UDN that changed on restart leaves the TV's list full of ghosts.
        $second = $this->call('GET', '/dlna/device.xml', [], [], [], ['REMOTE_ADDR' => '192.168.1.40']);

        $this->assertSame($this->udn($first->getContent()), $this->udn($second->getContent()));
    }

    /** The UDN out of a device description. */
    private function udn(string $xml): ?string
    {
        $doc = new \DOMDocument;
        $doc->loadXML($xml);

        return $doc->getElementsByTagName('UDN')->item(0)?->textContent;
    }

    /* ----------------------------------------------------------- browse --- */

    public function test_browsing_the_root_returns_sections_as_escaped_didl(): void
    {
        $this->enable();
        $this->track('A Song');

        $response = $this->browse('0');

        $response->assertOk();

        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($response->getContent()), 'The SOAP envelope must be well-formed.');

        // The Result holds the DIDL as *text* — clients reject real XML there.
        $result = $doc->getElementsByTagName('Result')->item(0)?->textContent ?? '';
        $this->assertStringContainsString('<DIDL-Lite', $result);
        $this->assertStringContainsString('Music', $result);
    }

    public function test_browsing_a_section_lists_its_items(): void
    {
        $this->enable();
        $this->track('A Song');

        $doc = new \DOMDocument;
        $doc->loadXML($this->browse('music')->getContent());

        $this->assertSame('1', $doc->getElementsByTagName('TotalMatches')->item(0)?->textContent);
        $this->assertStringContainsString(
            'A Song',
            $doc->getElementsByTagName('Result')->item(0)?->textContent ?? '',
        );
    }

    public function test_an_unimplemented_action_returns_a_upnp_fault(): void
    {
        // Clients fall back to browsing when Search is absent; a malformed
        // answer would be worse than a clean refusal.
        $this->enable();

        $soap = '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<s:Body><u:Search xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1" />'
            .'</s:Body></s:Envelope>';

        $response = $this->call('POST', '/dlna/control', [], [], [], [
            'CONTENT_TYPE' => 'text/xml',
            'REMOTE_ADDR' => '192.168.1.40',
        ], $soap);

        $response->assertStatus(500);
        $this->assertStringContainsString('UPnPError', $response->getContent());
    }

    /* ------------------------------------------------------------ media --- */

    public function test_the_media_route_serves_a_playable_item(): void
    {
        $this->enable();
        $item = $this->track('A Song');

        $this->call('GET', "/dlna/media/{$item->id}", [], [], [], ['REMOTE_ADDR' => '192.168.1.40'])
            ->assertOk();
    }

    public function test_the_media_route_refuses_an_item_the_profile_cannot_see(): void
    {
        // Browsing is only half of it: an id remembered from another session
        // must not be fetchable.
        $blocked = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'Grown Up',
            'file_path' => $this->fixture('movies/grown-up.mp4', 'bytes'),
            'owned' => true,
        ]);
        $blocked->movieMetadata()->create(['mpaa_rating' => 'R']);

        $kid = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Kid',
            'is_owner' => false,
            'max_rating' => 'PG',
        ]);

        $this->enable($kid);

        $this->call('GET', "/dlna/media/{$blocked->id}", [], [], [], ['REMOTE_ADDR' => '192.168.1.40'])
            ->assertNotFound();
    }
}
