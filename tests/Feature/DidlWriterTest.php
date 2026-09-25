<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Dlna\DidlWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The XML a DLNA client actually parses (S-7).
 *
 * Old TVs and consoles are unforgiving here: malformed XML, a missing
 * `upnp:class` or a MIME type that disagrees with the bytes all surface as a
 * blank list or "unsupported file", with nothing to debug from.
 */
class DidlWriterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function track(string $title, string $file, array $meta = []): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => $file,
            'owned' => true,
        ]);

        $item->musicMetadata()->create($meta);

        return $item->fresh();
    }

    private function xml(MediaItem ...$items): string
    {
        return app(DidlWriter::class)->write([], collect($items), 'music', 'http://10.0.0.5:8200');
    }

    private function parsed(string $xml): \DOMDocument
    {
        $doc = new \DOMDocument;

        $this->assertTrue($doc->loadXML($xml), 'DIDL-Lite must be well-formed XML.');

        return $doc;
    }

    public function test_a_title_with_xml_characters_does_not_break_the_document(): void
    {
        // Titles come from file tags and metadata providers — neither of which
        // this server controls.
        $xml = $this->xml($this->track('Rock & Roll <Part 2>', 'a.mp3'));

        $doc = $this->parsed($xml);

        $this->assertSame(
            'Rock & Roll <Part 2>',
            $doc->getElementsByTagName('title')->item(0)?->textContent,
        );
    }

    public function test_the_mime_type_follows_the_file_that_will_be_served(): void
    {
        // A client told audio/mpeg and handed a FLAC stops with a decode error
        // rather than falling back.
        $xml = $this->xml($this->track('Lossless', 'a.flac'));

        $this->assertStringContainsString('http-get:*:audio/flac:*', $xml);
    }

    public function test_a_converted_copy_decides_the_mime_type(): void
    {
        // Playback serves the converted file, so the client must be told about
        // that one, not the original.
        $item = $this->track('Transcoded', 'a.flac');
        $item->forceFill(['converted_path' => 'converted/a.mp3'])->save();

        $this->assertStringContainsString('http-get:*:audio/mpeg:*', $this->xml($item->fresh()));
    }

    public function test_duration_is_written_in_the_format_the_spec_requires(): void
    {
        $xml = $this->xml($this->track('Long', 'a.mp3', ['duration_ms' => 3_725_000]));

        $this->assertStringContainsString('duration="1:02:05.000"', $xml);
    }

    public function test_an_unknown_duration_is_omitted_rather_than_guessed(): void
    {
        // A zero duration makes some clients refuse to seek at all.
        $xml = $this->xml($this->track('Unknown', 'a.mp3'));

        $this->assertStringNotContainsString('duration=', $xml);
    }

    public function test_every_item_carries_a_upnp_class(): void
    {
        $xml = $this->xml($this->track('A Song', 'a.mp3'));

        $this->assertStringContainsString('object.item.audioItem.musicTrack', $xml);
    }

    public function test_a_container_reports_its_child_count(): void
    {
        $xml = app(DidlWriter::class)->write(
            [['id' => 'music', 'title' => 'Music', 'count' => 42]],
            collect(),
            '0',
            'http://10.0.0.5:8200',
        );

        $this->parsed($xml);
        $this->assertStringContainsString('childCount="42"', $xml);
        $this->assertStringContainsString('object.container.storageFolder', $xml);
    }

    public function test_the_resource_url_points_at_this_server(): void
    {
        $item = $this->track('A Song', 'a.mp3');

        $this->assertStringContainsString(
            "http://10.0.0.5:8200/dlna/media/{$item->id}</res>",
            $this->xml($item),
        );
    }
}
