<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\PipelineStage;
use Tests\TestCase;

/**
 * Every queue the pipeline uses is actually served (#496).
 *
 * Phase 2 gave each stage its own queue and updated the Rust supervisor to pass
 * them. The launchd and systemd templates were missed, and a bare `queue:work`
 * serves `default` only — so on a real install 36 `RunPipelineStageJob`s landed
 * on `net` and sat there for three hours behind a worker that reported itself
 * healthy.
 *
 * That is the shape of bug a test is for: nothing crashed, nothing logged, and
 * the dashboard's own wording ("the worker may be idle between jobs, or
 * stopped") pointed away from the cause. Adding a queue to `PipelineStage`
 * without teaching the supervisors about it now fails here instead.
 */
class SupervisorQueuesTest extends TestCase
{
    /** The three places a worker is launched. All must agree. */
    private const LAUNCHERS = [
        'src-tauri/src/supervisor.rs',
        'server/supervisor/launchd/com.soundchex.queue.plist.template',
        'server/supervisor/systemd/soundchex-queue.service.template',
    ];

    public function test_every_launcher_serves_every_queue_the_pipeline_uses(): void
    {
        $needed = $this->queuesInUse();

        $this->assertContains('net', $needed, 'Sanity: the pipeline should use a net queue.');

        foreach (self::LAUNCHERS as $relative) {
            $served = $this->queuesServedBy($relative);

            foreach ($needed as $queue) {
                $this->assertContains(
                    $queue,
                    $served,
                    "{$relative} does not serve the '{$queue}' queue, so those stages would never run.",
                );
            }
        }
    }

    public function test_no_launcher_starts_a_bare_worker(): void
    {
        // The actual defect: `queue:work` with no --queue serves `default`
        // only, and looks completely healthy while doing it.
        foreach (self::LAUNCHERS as $relative) {
            $this->assertNotEmpty(
                $this->queuesServedBy($relative),
                "{$relative} starts a bare queue:work, which serves only the default queue.",
            );
        }
    }

    public function test_the_launchers_agree_with_each_other(): void
    {
        // Three copies of one list is three chances to update two of them. The
        // same install can be run under launchd, systemd or the app's own
        // supervisor, and they must not behave differently.
        $lists = [];

        foreach (self::LAUNCHERS as $relative) {
            $queues = $this->queuesServedBy($relative);
            sort($queues);
            $lists[$relative] = $queues;
        }

        $first = array_key_first($lists);

        foreach ($lists as $relative => $queues) {
            $this->assertSame(
                $lists[$first],
                $queues,
                "{$relative} serves a different set of queues from {$first}.",
            );
        }
    }

    public function test_the_cheapest_queue_is_served_first(): void
    {
        // Order is priority in `queue:work`. `default` holds the quick stage
        // transitions; io hashes and moves bytes. Putting io first would let a
        // single large file block every transition behind it.
        foreach (self::LAUNCHERS as $relative) {
            $served = $this->queuesServedBy($relative);

            $this->assertSame(
                'default',
                $served[0] ?? null,
                "{$relative} does not serve 'default' first, so quick transitions queue behind slow work.",
            );
        }
    }

    /**
     * Every distinct queue name the pipeline routes work to.
     *
     * Read from the enum rather than hardcoded, which is the point: a new
     * stage on a new queue makes this test fail until a supervisor serves it.
     *
     * @return array<int, string>
     */
    private function queuesInUse(): array
    {
        $queues = array_map(
            fn (PipelineStage $stage): string => $stage->queue(),
            PipelineStage::cases(),
        );

        return array_values(array_unique($queues));
    }

    /**
     * The queues a launcher passes to `queue:work`, in order.
     *
     * @return array<int, string>
     */
    private function queuesServedBy(string $relative): array
    {
        $path = base_path($relative);

        $this->assertFileExists($path, "A worker launcher is missing: {$relative}");

        $contents = (string) file_get_contents($path);

        // Matches the flag however the file spells it -- a Rust `.arg("...")`,
        // a plist `<string>`, or a systemd ExecStart -- because the three
        // formats differ and the invariant does not.
        if (preg_match('/--queue=([a-z0-9,_-]+)/i', $contents, $match) !== 1) {
            return [];
        }

        return array_values(array_filter(explode(',', $match[1])));
    }
}
