<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata;

use App\Models\MediaItem;
use App\Services\Metadata\Contracts\MetadataSource;
use App\Services\SettingsService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

class MetadataPipeline
{
    public function __construct(private SettingsService $settings) {}

    /**
     * Run all registered, supported sources against the item in priority order.
     */
    public function run(MediaItem $item): void
    {
        $sources = $this->sourcesFor($item);

        foreach ($sources as $source) {
            try {
                $source->enrich($item);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** @return MetadataSource[] */
    public function sourcesFor(MediaItem $item): array
    {
        $registered = config('metadata_sources.' . $item->type->value, []);

        // Sources are registered ahead of being written. Skip any that don't
        // exist yet rather than failing the whole run.
        $existing = array_filter($registered, fn (string $class) => class_exists($class));

        $sources = array_map(fn (string $class) => App::make($class), $existing);

        $supported = array_filter($sources, fn (MetadataSource $s) => $s->supports($item));

        // A source declines for two very different reasons: it has nothing to
        // say about this media type, which is routine, or it is missing the
        // credential it needs, which is a configuration fault that otherwise
        // shows up nowhere. Both look identical from here, so the ones that
        // could have run and did not are worth a line in the log.
        //
        // This is how every film in the library came to have no year: the TMDB
        // key was lost with a database rebuild, the source quietly reported
        // itself unsupported, and each item completed "enriched" with nothing.
        // Nobody noticed until a filer refused to file one.
        $this->warnAboutSilentSkips($item, $sources, $supported);

        usort($supported, fn (MetadataSource $a, MetadataSource $b) => $a->priority() <=> $b->priority());

        return array_values($supported);
    }

    /**
     * Logs sources that handle this media type but declined to run.
     *
     * @param  MetadataSource[]  $sources
     * @param  MetadataSource[]  $supported
     */
    private function warnAboutSilentSkips(MediaItem $item, array $sources, array $supported): void
    {
        $skipped = array_diff(
            array_map(fn (MetadataSource $s) => $s::class, $sources),
            array_map(fn (MetadataSource $s) => $s::class, $supported),
        );

        if ($skipped === []) {
            return;
        }

        Log::warning('Metadata sources skipped themselves', [
            'item' => $item->id,
            'type' => $item->type->value,
            'skipped' => array_values(array_map(fn (string $c) => class_basename($c), $skipped)),
            'hint' => 'A source registered for this type declined to run. A missing API key is the usual cause.',
        ]);
    }
}
