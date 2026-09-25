<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Dlna;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use Illuminate\Support\Collection;

/**
 * DIDL-Lite: the XML a DLNA client reads a browse result from (S-7).
 *
 * The format is fixed by the UPnP ContentDirectory spec and old devices are
 * unforgiving of deviation, so this writes the conservative shape: the four
 * standard namespaces, `upnp:class` on everything, and a single `res` element
 * per item carrying the URL and a `protocolInfo` string.
 *
 * Note the double encoding. A Browse response is a SOAP envelope whose
 * `Result` element contains this document *as text*, so the whole thing is
 * escaped again by the caller. That is not a mistake in either layer — it is
 * what the spec asks for, and clients reject a Result that holds real XML.
 */
class DidlWriter
{
    /**
     * @param  array<int, array{id: string, title: string, count: int}>  $containers
     * @param  Collection<int, MediaItem>  $items
     */
    public function write(array $containers, Collection $items, string $parentId, string $baseUrl): string
    {
        $xml = '<DIDL-Lite xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/"'
            .' xmlns:dc="http://purl.org/dc/elements/1.1/"'
            .' xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/"'
            .' xmlns:dlna="urn:schemas-dlna-org:metadata-1-0/">';

        foreach ($containers as $container) {
            $xml .= $this->container($container, $parentId);
        }

        foreach ($items as $item) {
            $xml .= $this->item($item, $parentId, $baseUrl);
        }

        return $xml.'</DIDL-Lite>';
    }

    /** @param array{id: string, title: string, count: int} $container */
    private function container(array $container, string $parentId): string
    {
        return sprintf(
            '<container id="%s" parentID="%s" restricted="1" childCount="%d">'
                .'<dc:title>%s</dc:title>'
                .'<upnp:class>object.container.storageFolder</upnp:class>'
                .'</container>',
            $this->attr($container['id']),
            $this->attr($parentId),
            $container['count'],
            $this->text($container['title']),
        );
    }

    private function item(MediaItem $item, string $parentId, string $baseUrl): string
    {
        $class = match ($item->type) {
            MediaItemType::Music => 'object.item.audioItem.musicTrack',
            MediaItemType::Movie => 'object.item.videoItem.movie',
            MediaItemType::Show => 'object.item.videoItem.videoBroadcast',
            default => 'object.item',
        };

        $xml = sprintf(
            '<item id="%d" parentID="%s" restricted="1">'
                .'<dc:title>%s</dc:title>'
                .'<upnp:class>%s</upnp:class>',
            $item->id,
            $this->attr($parentId),
            $this->text((string) $item->title),
            $class,
        );

        if ($item->type === MediaItemType::Music) {
            $artist = (string) ($item->musicMetadata?->artist ?? '');
            $album = (string) ($item->musicMetadata?->album ?? '');

            if ($artist !== '') {
                $xml .= '<upnp:artist>'.$this->text($artist).'</upnp:artist>'
                    .'<dc:creator>'.$this->text($artist).'</dc:creator>';
            }

            if ($album !== '') {
                $xml .= '<upnp:album>'.$this->text($album).'</upnp:album>';
            }
        }

        return $xml.$this->resource($item, $baseUrl).'</item>';
    }

    /** The playable URL, with the `protocolInfo` a client matches against. */
    private function resource(MediaItem $item, string $baseUrl): string
    {
        $mime = $this->mimeType($item);
        $duration = $this->duration($item);

        $attributes = sprintf('protocolInfo="http-get:*:%s:*"', $this->attr($mime));

        if ($duration !== null) {
            $attributes .= sprintf(' duration="%s"', $duration);
        }

        return sprintf(
            '<res %s>%s</res>',
            $attributes,
            $this->text(rtrim($baseUrl, '/').'/dlna/media/'.$item->id),
        );
    }

    /**
     * The MIME type a client is told to expect.
     *
     * From the extension of the file that will actually be served — the
     * converted copy when there is one — because a client that is told
     * audio/mpeg and handed a FLAC stops with a decode error rather than
     * falling back.
     */
    private function mimeType(MediaItem $item): string
    {
        $path = filled($item->converted_path) ? $item->converted_path : (string) $item->file_path;
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'mp3' => 'audio/mpeg',
            'm4a', 'aac' => 'audio/mp4',
            'flac' => 'audio/flac',
            'ogg', 'oga' => 'audio/ogg',
            'opus' => 'audio/opus',
            'wav' => 'audio/wav',
            'mp4', 'm4v' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            default => $item->type === MediaItemType::Music
                ? 'audio/mpeg'
                : 'video/mp4',
        };
    }

    /** `H:MM:SS.mmm`, the only duration format the spec accepts. */
    private function duration(MediaItem $item): ?string
    {
        $ms = $item->musicMetadata?->duration_ms;

        if (! is_numeric($ms) || $ms <= 0) {
            return null;
        }

        $total = (int) floor($ms / 1000);

        return sprintf(
            '%d:%02d:%02d.000',
            intdiv($total, 3600),
            intdiv($total % 3600, 60),
            $total % 60,
        );
    }

    /** Escapes text content. */
    private function text(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Escapes an attribute value. */
    private function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
