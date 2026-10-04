<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Filament\Resources\Movies\Pages\ListMovies;
use App\Jobs\DetectDuplicatesJob;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\QueueInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Every heavy operation queues, and starting one gave a notification and then
 * silence. Whether thousands of jobs were moving, stuck behind a stopped
 * worker, or failing one at a time was answerable only from the `jobs` table.
 */
class QueueActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private static int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function queueRow(string $displayName, array $overrides = []): void
    {
        DB::table('jobs')->insert(array_merge([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => $displayName, 'data' => []]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ], $overrides));
    }

    public function test_it_groups_pending_jobs_by_kind(): void
    {
        $this->queueRow('App\Jobs\EnrichMediaItemJob');
        $this->queueRow('App\Jobs\EnrichMediaItemJob');
        $this->queueRow('App\Jobs\DetectDuplicatesJob');

        $pending = app(QueueInspector::class)->pending();

        $enrich = $pending->firstWhere('job', 'EnrichMediaItemJob');

        $this->assertSame(2, $enrich['queued']);
        $this->assertSame(1, $pending->firstWhere('job', 'DetectDuplicatesJob')['queued']);
    }

    public function test_a_reserved_job_counts_as_running(): void
    {
        $this->queueRow('App\Jobs\EnrichMediaItemJob');
        $this->queueRow('App\Jobs\EnrichMediaItemJob', ['reserved_at' => now()->timestamp]);

        $row = app(QueueInspector::class)->pending()->firstWhere('job', 'EnrichMediaItemJob');

        $this->assertSame(2, $row['queued']);
        $this->assertSame(1, $row['running'], 'A reserved job is one a worker has in hand.');
    }

    /**
     * Twenty copies of "database is locked" is one problem, not twenty, and a
     * list of twenty identical rows hides whatever else failed once.
     */
    public function test_failures_are_grouped_by_reason(): void
    {
        foreach (range(1, 3) as $i) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode(['displayName' => 'App\Jobs\EnrichMediaItemJob']),
                'exception' => "PDOException: database is locked in C:\\app\\file.php:612\n#0 ...",
                'failed_at' => now(),
            ]);
        }

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\Jobs\EnrichMediaItemJob']),
            'exception' => "RuntimeException: something else entirely\n#0 ...",
            'failed_at' => now(),
        ]);

        $failed = app(QueueInspector::class)->failed();

        $this->assertCount(2, $failed, 'Two distinct reasons, not four rows.');
        $this->assertSame(3, $failed->first()['count']);
    }

    /* ------------------------------------------- the actions that fill it -- */

    private function movie(array $attributes = []): MediaItem
    {
        self::$n++;

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'Film '.self::$n,
            'file_path' => 'media/library/Movies/film-'.self::$n.'.mkv',
            'owned' => true,
        ]);

        if ($attributes !== []) {
            MediaItem::unresolved()->whereKey($item->getKey())->update($attributes);
        }

        return MediaItem::unresolved()->findOrFail($item->getKey());
    }

    public function test_re_enrich_queues_one_job_per_item(): void
    {
        Queue::fake();

        $this->movie();
        $this->movie();

        $page = new ListMovies;
        $scope = new ReflectionMethod(ListMovies::class, 'maintenanceScope');
        $queue = new ReflectionMethod(ListMovies::class, 'queueEnrichment');

        $queue->invoke($page, $scope->invoke($page, false), 'enrichment');

        Queue::assertPushed(EnrichMediaItemJob::class, 2);
    }

    public function test_covers_only_queues_items_without_one(): void
    {
        Queue::fake();

        $this->movie(['cover_image_url' => 'artwork/a.jpg']);
        $this->movie();

        $page = new ListMovies;
        $scope = new ReflectionMethod(ListMovies::class, 'maintenanceScope');
        $queue = new ReflectionMethod(ListMovies::class, 'queueEnrichment');

        $queue->invoke($page, $scope->invoke($page, true), 'covers');

        Queue::assertPushed(EnrichMediaItemJob::class, 1);
    }

    /**
     * The sweep must match the list it was started from, or the Movies page
     * quietly rehashes the whole library.
     */
    public function test_the_duplicate_search_is_scoped_to_the_page_type(): void
    {
        $method = new ReflectionMethod(ListMovies::class, 'maintenanceType');

        $this->assertSame(MediaItemType::Movie->value, $method->invoke(null));
    }

    public function test_the_duplicate_job_honours_that_type(): void
    {
        Queue::fake();

        DetectDuplicatesJob::dispatch(MediaItemType::Movie->value);

        Queue::assertPushed(
            DetectDuplicatesJob::class,
            fn (DetectDuplicatesJob $job): bool => $job->type === MediaItemType::Movie->value,
        );
    }

    public function test_the_scheduled_sweep_still_covers_everything(): void
    {
        Queue::fake();

        DetectDuplicatesJob::dispatch();

        Queue::assertPushed(
            DetectDuplicatesJob::class,
            fn (DetectDuplicatesJob $job): bool => $job->type === null,
        );
    }
}
