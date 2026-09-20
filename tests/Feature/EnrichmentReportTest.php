<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Metadata\Contracts\MetadataSource;
use App\Services\Metadata\MetadataPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pipeline records what each source did, so the Review hub can say *why* an
 * item needs a look (S-277). The account is inferred from the item's match
 * confidence before and after each source — the sources are not changed.
 */
class EnrichmentReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_source_that_matched_and_one_that_did_not(): void
    {
        config(['metadata_sources.music' => [MatchingSource::class, IdleSource::class]]);

        $item = $this->track();
        app(MetadataPipeline::class)->run($item);

        $report = $item->fresh()->enrichment_report;

        $this->assertNotNull($report);
        $this->assertArrayHasKey('sources', $report);

        $byName = collect($report['sources'])->keyBy('name');

        $this->assertSame('matched', $byName['Matching Source']['outcome']);
        $this->assertSame('exact', $byName['Matching Source']['confidence']);
        $this->assertSame('no_match', $byName['Idle Source']['outcome']);
    }

    public function test_it_records_an_errored_source_without_failing_the_run(): void
    {
        config(['metadata_sources.music' => [ExplodingSource::class]]);

        $item = $this->track();
        app(MetadataPipeline::class)->run($item);

        $line = collect($item->fresh()->enrichment_report['sources'])
            ->firstWhere('name', 'Exploding Source');

        $this->assertSame('error', $line['outcome']);
        $this->assertStringContainsString('boom', $line['note']);
    }

    public function test_it_flags_a_source_skipped_for_a_missing_key(): void
    {
        config(['metadata_sources.music' => [KeyedSource::class]]);

        $item = $this->track();
        app(MetadataPipeline::class)->run($item);

        $line = collect($item->fresh()->enrichment_report['sources'])
            ->firstWhere('name', 'Keyed Source');

        $this->assertSame('skipped_no_key', $line['outcome']);
    }

    public function test_it_explains_why_a_needs_review_item_needs_review(): void
    {
        config(['metadata_sources.music' => [FlaggingSource::class]]);

        $item = $this->track();
        app(MetadataPipeline::class)->run($item);

        $this->assertNotNull($item->fresh()->enrichment_report['review_reason']);
    }

    private function track(): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => 'A Track',
            'file_path' => '/tmp/track.mp3',
            'owned' => true,
        ]);

        $item->musicMetadata()->create(['artist' => 'An Artist']);

        return $item;
    }
}

/* ---------------------------------------------------------- fake sources --- */

class MatchingSource implements MetadataSource
{
    public function supports(MediaItem $item): bool
    {
        return true;
    }

    public function enrich(MediaItem $item): void
    {
        $item->match_confidence = MatchConfidence::Exact;
    }

    public function priority(): int
    {
        return 1;
    }

    public function name(): string
    {
        return 'Matching Source';
    }

    public function requiredSettings(): array
    {
        return [];
    }
}

class IdleSource implements MetadataSource
{
    public function supports(MediaItem $item): bool
    {
        return true;
    }

    public function enrich(MediaItem $item): void
    {
        // Contributes nothing.
    }

    public function priority(): int
    {
        return 2;
    }

    public function name(): string
    {
        return 'Idle Source';
    }

    public function requiredSettings(): array
    {
        return [];
    }
}

class ExplodingSource implements MetadataSource
{
    public function supports(MediaItem $item): bool
    {
        return true;
    }

    public function enrich(MediaItem $item): void
    {
        throw new \RuntimeException('boom');
    }

    public function priority(): int
    {
        return 1;
    }

    public function name(): string
    {
        return 'Exploding Source';
    }

    public function requiredSettings(): array
    {
        return [];
    }
}

class KeyedSource implements MetadataSource
{
    public function supports(MediaItem $item): bool
    {
        return false; // no key configured
    }

    public function enrich(MediaItem $item): void {}

    public function priority(): int
    {
        return 1;
    }

    public function name(): string
    {
        return 'Keyed Source';
    }

    public function requiredSettings(): array
    {
        return ['keyed_source_api_key' => 'Keyed Source API Key'];
    }
}

class FlaggingSource implements MetadataSource
{
    public function supports(MediaItem $item): bool
    {
        return true;
    }

    public function enrich(MediaItem $item): void
    {
        $item->processing_status = ProcessingStatus::NeedsReview;
    }

    public function priority(): int
    {
        return 1;
    }

    public function name(): string
    {
        return 'Flagging Source';
    }

    public function requiredSettings(): array
    {
        return [];
    }
}
