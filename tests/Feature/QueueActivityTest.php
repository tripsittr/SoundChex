<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Filament\Resources\Movies\Pages\ListMovies;
use App\Filament\Widgets\QueueActivity;
use App\Jobs\DetectDuplicatesJob;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\QueueInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
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

    private function failedRow(string $displayName, string $exception): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => $displayName]),
            'exception' => $exception.'
#0 ...',
            'failed_at' => now(),
        ]);
    }

    /**
     * The widget shipped with a Blade parse error and nothing noticed, because
     * every test here called the inspector and none of them rendered the view.
     * The dashboard said "There was an error while attempting to load this
     * page" and the reason was in the log. This renders it, both ways: with a
     * queue and without one, since the two take different branches.
     */
    public function test_the_widget_renders_with_an_empty_queue(): void
    {
        $this->actingAs($this->user);

        Livewire::test(QueueActivity::class)
            ->assertOk()
            ->assertSee('Background work')
            ->assertSee('The queue is empty')
            ->assertDontSee('@if');
    }

    public function test_the_widget_renders_with_pending_and_failed_work(): void
    {
        $this->queueRow('App\Jobs\EnrichMediaItemJob');
        $this->failedRow('App\Jobs\EnrichMediaItemJob', 'database is locked');

        $this->actingAs($this->user);

        Livewire::test(QueueActivity::class)
            ->assertOk()
            ->assertSee('waiting')
            ->assertSee('failed')
            ->assertSee('EnrichMediaItemJob')
            // The stray endif came from an @if that stayed literal text; if it
            // ever does again, the directive itself reaches the page.
            ->assertDontSee('@if')
            ->assertDontSee('@endif');
    }

    /**
     * The modal behind a row names the item the worker has in hand.
     *
     * The table could say five thousand jobs were waiting and nothing more,
     * which is the question it provokes rather than an answer to it.
     */
    public function test_the_detail_names_the_running_item_and_what_is_next(): void
    {
        $running = $this->track('Kind of Blue');
        $next = $this->track('A Love Supreme');

        $this->enrichRow($running->id, ['reserved_at' => now()->timestamp, 'attempts' => 1]);
        $this->enrichRow($next->id);

        $detail = app(QueueInspector::class)->detail('EnrichMediaItemJob');

        $this->assertCount(1, $detail['running']);
        $this->assertSame('Kind of Blue', $detail['running'][0]['target']);
        $this->assertSame($running->id, $detail['running'][0]['item']);

        $this->assertSame(['A Love Supreme'], array_column($detail['upcoming'], 'target'));
    }

    /** A sweep is about no single item, and should say so rather than read as blank. */
    public function test_a_whole_library_sweep_describes_itself(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\Jobs\DetectDuplicatesJob',
                'data' => ['command' => serialize(new DetectDuplicatesJob)],
            ]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $detail = app(QueueInspector::class)->detail('DetectDuplicatesJob');

        $this->assertSame('Whole library', $detail['upcoming'][0]['target']);
    }

    /** An item deleted since the job was queued must not break the modal. */
    public function test_a_job_whose_item_is_gone_still_describes_itself(): void
    {
        $this->enrichRow(999999);

        $detail = app(QueueInspector::class)->detail('EnrichMediaItemJob');

        $this->assertStringContainsString('no longer in the library', $detail['upcoming'][0]['target']);
    }

    /**
     * The row's button mounts the action with that row's job as its argument.
     *
     * Only the wiring: Livewire 4 renders an action's modal into a partial,
     * which the test harness does not include in the component's HTML, so the
     * contents are checked below instead of asserted against this render.
     */
    public function test_a_row_mounts_the_action_for_its_own_job(): void
    {
        $item = $this->track('Bitches Brew');
        $this->enrichRow($item->id, ['reserved_at' => now()->timestamp, 'attempts' => 1]);

        $this->actingAs($this->user);

        $widget = Livewire::test(QueueActivity::class)
            ->assertSee('EnrichMediaItemJob')
            ->mountAction('inspect', ['job' => 'EnrichMediaItemJob'])
            ->assertActionMounted('inspect');

        $this->assertSame(
            ['job' => 'EnrichMediaItemJob'],
            $widget->instance()->mountedActions[0]['arguments'],
            'The row must pass its own job, or every row opens the same modal.'
        );
    }

    /**
     * And the modal's own view renders, naming the item in hand.
     *
     * Rendered directly rather than through the component, because the data
     * being right is not the same as the view working — a widget whose data
     * was right and whose Blade was broken is how this dashboard shipped dead.
     */
    public function test_the_modal_view_names_the_item_in_hand(): void
    {
        $item = $this->track('Bitches Brew');
        $this->enrichRow($item->id, ['reserved_at' => now()->timestamp, 'attempts' => 1]);

        $this->actingAs($this->user);

        $widget = Livewire::test(QueueActivity::class)
            ->mountAction('inspect', ['job' => 'EnrichMediaItemJob'])
            ->instance();

        $html = (string) $widget->getMountedAction()->getModalContent()?->render();

        $this->assertStringContainsString('Bitches Brew', $html);
        $this->assertStringContainsString('In hand now', $html);
        $this->assertStringContainsString('Running', $html);

        // A directive reaching the page is this view's known failure mode.
        $this->assertStringNotContainsString('@if', $html);
        $this->assertStringNotContainsString('@endif', $html);
    }

    private function track(string $title): MediaItem
    {
        self::$n++;

        return MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => 'media/library/Music/track-'.self::$n.'.flac',
            'owned' => true,
        ]);
    }

    private function enrichRow(int $itemId, array $overrides = []): void
    {
        DB::table('jobs')->insert(array_merge([
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\Jobs\EnrichMediaItemJob',
                'data' => ['command' => serialize(new EnrichMediaItemJob($itemId))],
            ]),
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
