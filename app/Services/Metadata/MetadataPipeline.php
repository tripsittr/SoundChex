<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata;

use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Plugins\Registry;
use App\Services\Metadata\Contracts\MetadataSource;
use App\Services\SettingsService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

class MetadataPipeline
{
    public function __construct(
        private SettingsService $settings,
        private Registry $plugins,
    ) {}

    /**
     * Run all registered, supported sources against the item in priority order.
     *
     * As it goes it records what each source did — ran and matched, ran and
     * found nothing, or errored — plus the ones that skipped themselves and why.
     * That record is written to the item as `enrichment_report`, so the Review
     * hub can explain why an item needs a look rather than only that it does.
     * The sources themselves are untouched: the outcome is inferred from the
     * item's confidence before and after each call, which every source already
     * sets, rather than a new return value each would have to opt into.
     */
    public function run(MediaItem $item): void
    {
        $report = $this->collectRun($item);

        // Set by the pipeline, not a form. Quietly, so a report write cannot
        // itself dirty `updated_at` or trip model events mid-enrichment.
        $item->forceFill(['enrichment_report' => $report])->saveQuietly();
    }

    /**
     * Runs each supported source and returns the structured account of the run.
     *
     * @return array{ran_at: string, review_reason: string|null, sources: array<int, array<string, mixed>>}
     */
    private function collectRun(MediaItem $item): array
    {
        $sources = $this->sourcesFor($item);
        $lines = [];

        foreach ($sources as $source) {
            $before = $item->match_confidence?->value;

            try {
                $source->enrich($item);
                $lines[] = $this->describeRun($source, $item, $before);
            } catch (\Throwable $e) {
                report($e);
                $lines[] = [
                    'name' => $source->name(),
                    'outcome' => 'error',
                    'note' => class_basename($e).': '.$e->getMessage(),
                ];
            }
        }

        foreach ($this->skippedFor($item, $sources) as $line) {
            $lines[] = $line;
        }

        return [
            'ran_at' => now()->toIso8601String(),
            'review_reason' => $this->reviewReason($item, $lines),
            'sources' => $lines,
        ];
    }

    /**
     * What one source did, judged by the item's match confidence before and
     * after it ran.
     *
     * @return array<string, mixed>
     */
    private function describeRun(MetadataSource $source, MediaItem $item, ?string $before): array
    {
        // The in-memory item: sources mutate it directly, and a source's write is
        // the thing being measured, so a reload here would miss changes not yet
        // persisted and cost a query per source besides.
        $after = $item->match_confidence?->value;

        // A source that raised confidence (none → fuzzy/exact, or fuzzy → exact)
        // contributed a match; one that left it where it was found nothing to add.
        $improved = $after !== $before && $after !== null && $after !== 'none';

        return [
            'name' => $source->name(),
            'outcome' => $improved ? 'matched' : 'no_match',
            'confidence' => $after,
        ];
    }

    /**
     * The sources that could have run but declined, tagged with the likely
     * reason — a missing key vs. simply nothing to say about this item.
     *
     * @param  MetadataSource[]  $ran  the supported sources that were run
     * @return array<int, array<string, mixed>>
     */
    private function skippedFor(MediaItem $item, array $ran): array
    {
        $registered = $this->registeredClasses($item);
        $ranClasses = array_map(fn (MetadataSource $s) => $s::class, $ran);

        $lines = [];

        foreach ($registered as $class) {
            if (! class_exists($class) || in_array($class, $ranClasses, true)) {
                continue;
            }

            $source = App::make($class);
            $missingKey = $source->requiredSettings() !== [] && ! $source->supports($item);

            $lines[] = [
                'name' => $source->name(),
                'outcome' => $missingKey ? 'skipped_no_key' : 'skipped',
                'note' => $missingKey
                    ? 'Needs a key that is not configured.'
                    : 'Nothing to add for this item.',
            ];
        }

        return $lines;
    }

    /**
     * A one-line human reason this item is worth reviewing, or null when the run
     * left nothing to look at.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function reviewReason(MediaItem $item, array $lines): ?string
    {
        if ($item->processing_status === ProcessingStatus::NeedsReview) {
            // A source that flagged the item for a specific reason (a matched
            // recording on a doubtful release) names it; prefer that over the
            // generic guesses below.
            if (filled($item->reviewReasonHint)) {
                return $item->reviewReasonHint;
            }

            $matched = array_filter($lines, fn (array $l) => ($l['outcome'] ?? null) === 'matched');

            return $matched === []
                ? 'No source could identify this confidently.'
                : 'A source found more than one likely match and left it for a human.';
        }

        if (($item->match_confidence?->value ?? 'none') === 'none') {
            return 'Enriched, but nothing matched — the file may be untagged or obscure.';
        }

        if ($item->match_confidence?->value === 'fuzzy') {
            return 'Matched by similarity, not an exact identifier — worth a glance.';
        }

        return null;
    }

    /**
     * Every source class registered for an item's type — the built-ins from
     * config plus any a plugin has contributed (S-264 Phase 2).
     *
     * A plugin source is an ordinary MetadataSource: it joins the same list and
     * is ordered by its own priority() alongside the built-ins, so nothing
     * downstream can tell a plugin source from a core one. One place builds the
     * list so `sourcesFor()` and the skip report can never disagree about what
     * was registered.
     *
     * @return array<int, string>
     */
    private function registeredClasses(MediaItem $item): array
    {
        return array_merge(
            config('metadata_sources.'.$item->type->value, []),
            array_column($this->plugins->metadataSourcesFor($item->type->value), 'class'),
        );
    }

    /** @return MetadataSource[] */
    public function sourcesFor(MediaItem $item): array
    {
        // Sources are registered ahead of being written. Skip any that don't
        // exist yet rather than failing the whole run.
        $existing = array_filter($this->registeredClasses($item), fn (string $class) => class_exists($class));

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
