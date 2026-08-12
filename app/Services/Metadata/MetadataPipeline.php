<?php

namespace App\Services\Metadata;

use App\Models\MediaItem;
use App\Services\Metadata\Contracts\MetadataSource;
use App\Services\SettingsService;
use Illuminate\Support\Facades\App;

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

        usort($supported, fn (MetadataSource $a, MetadataSource $b) => $a->priority() <=> $b->priority());

        return array_values($supported);
    }
}
