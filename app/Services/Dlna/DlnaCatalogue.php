<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Dlna;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\ContentGate;
use Illuminate\Support\Collection;

/**
 * The library as a DLNA browse tree (S-7).
 *
 * DLNA clients navigate containers by id, starting at "0", and every response
 * is DIDL-Lite XML. The tree is deliberately shallow — Music / Movies / Shows,
 * then the items — because the devices this exists for are old TVs, consoles
 * and receivers whose browsers are slow and whose remotes are worse. A deep
 * tree is a worse experience on exactly the hardware that needs this.
 *
 * Everything is gated through `ContentGate::applyFor()` with the configured
 * profile. A TV cannot say who is watching, so one profile stands for every
 * device on the network.
 */
class DlnaCatalogue
{
    /** The root container every client starts at. */
    public const ROOT = '0';

    /** @var array<string, array{title: string, type: MediaItemType}> */
    private const SECTIONS = [
        'music' => ['title' => 'Music', 'type' => MediaItemType::Music],
        'movies' => ['title' => 'Movies', 'type' => MediaItemType::Movie],
        'shows' => ['title' => 'TV Shows', 'type' => MediaItemType::Show],
    ];

    public function __construct(
        private ContentGate $gate,
        private DlnaSettings $settings,
    ) {}

    /**
     * The children of a container.
     *
     * @return array{containers: array<int, array{id: string, title: string, count: int}>, items: Collection<int, MediaItem>, total: int}
     */
    public function browse(string $containerId, int $offset = 0, int $limit = 200): array
    {
        if ($containerId === self::ROOT) {
            return [
                'containers' => $this->sections(),
                'items' => collect(),
                'total' => count(self::SECTIONS),
            ];
        }

        $section = self::SECTIONS[$containerId] ?? null;

        if ($section === null) {
            return ['containers' => [], 'items' => collect(), 'total' => 0];
        }

        $query = $this->itemsOfType($section['type']);
        $total = (clone $query)->count();

        return [
            'containers' => [],
            'items' => $query
                // A stable order: a client that browses in pages must not see
                // the same track twice because the order shifted between them.
                ->orderBy('title')
                ->orderBy('id')
                ->skip($offset)
                ->take($limit)
                ->get(),
            'total' => $total,
        ];
    }

    /** One item, if the configured profile may see it. */
    public function find(int $id): ?MediaItem
    {
        return $this->itemsOfType(null)->whereKey($id)->first();
    }

    /** The top-level sections, each with its gated count. */
    private function sections(): array
    {
        $out = [];

        foreach (self::SECTIONS as $id => $section) {
            $count = $this->itemsOfType($section['type'])->count();

            // An empty section is left out rather than shown empty: on a TV
            // remote, opening a folder to find nothing costs several button
            // presses to discover.
            if ($count === 0) {
                continue;
            }

            $out[] = ['id' => $id, 'title' => $section['title'], 'count' => $count];
        }

        return $out;
    }

    /** Playable items of a type, gated to the configured profile. */
    private function itemsOfType(?MediaItemType $type)
    {
        $query = MediaItem::query()
            // Nothing is listed that cannot actually be played: a DLNA client
            // given a dead URL reports a device error, not a missing file.
            ->whereNotNull('file_path');

        if ($type !== null) {
            $query->where('type', $type->value);
        }

        return $this->gate->applyFor($query, $this->settings->profile());
    }
}
