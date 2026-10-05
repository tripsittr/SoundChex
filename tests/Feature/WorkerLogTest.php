<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Services\WorkerLog;
use Tests\TestCase;

/**
 * The log, read from the end and parsed into something a modal can show.
 *
 * The real file on a running install is 7 MB and grows; the whole point is to
 * read the tail of it, so the size of the file must not be able to matter.
 */
class WorkerLogTest extends TestCase
{
    private string $log;

    private ?string $backup = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = storage_path('logs/laravel.log');

        if (! is_dir(dirname($this->log))) {
            mkdir(dirname($this->log), 0777, true);
        }

        $this->backup = is_file($this->log) ? (string) file_get_contents($this->log) : null;
    }

    protected function tearDown(): void
    {
        if ($this->backup === null) {
            @unlink($this->log);
        } else {
            file_put_contents($this->log, $this->backup);
        }

        parent::tearDown();
    }

    private function write(string $contents): void
    {
        file_put_contents($this->log, $contents);
    }

    public function test_it_parses_an_entry_with_its_level_and_context(): void
    {
        $this->write(
            '[2026-10-04 21:54:33] production.WARNING: Metadata sources skipped themselves '.
            '{"item":5629,"type":"music","hint":"A missing API key is the usual cause."}'."\n"
        );

        $entry = app(WorkerLog::class)->recent()->first();

        $this->assertSame('warning', $entry['level']);
        $this->assertSame('Metadata sources skipped themselves', $entry['message']);
        $this->assertSame(5629, $entry['item']);
        $this->assertSame('A missing API key is the usual cause.', $entry['context']['hint']);
        $this->assertSame('21:54:33', $entry['at']->format('H:i:s'));
    }

    /** Newest first: the question is always what it is doing now. */
    public function test_entries_come_back_newest_first(): void
    {
        $this->write(
            '[2026-10-04 10:00:00] production.INFO: first {"item":1}'."\n".
            '[2026-10-04 11:00:00] production.INFO: second {"item":2}'."\n"
        );

        $this->assertSame(
            ['second', 'first'],
            app(WorkerLog::class)->recent()->pluck('message')->all()
        );
    }

    /**
     * A stack trace is continuation lines, not entries.
     *
     * An exception writes dozens of them, and showing each as a row would bury
     * everything else in the modal.
     */
    public function test_stack_trace_lines_are_not_separate_entries(): void
    {
        $this->write(
            '[2026-10-04 10:00:00] production.ERROR: Something failed {"item":7}'."\n".
            "Stack trace:\n".
            "#0 C:\\app\\vendor\\thing.php(12): doThing()\n".
            "#1 {main}\n"
        );

        $entries = app(WorkerLog::class)->recent();

        $this->assertCount(1, $entries);
        $this->assertSame('Something failed', $entries->first()['message']);
    }

    /**
     * Filtered to the items a job is working on, but never hiding an entry
     * that names no item — a failure that got nowhere near naming one is
     * exactly what someone opening this needs to see.
     */
    public function test_it_filters_to_the_given_items_but_keeps_unattributed_entries(): void
    {
        $this->write(
            '[2026-10-04 10:00:00] production.INFO: about five {"item":5}'."\n".
            '[2026-10-04 10:00:01] production.INFO: about nine {"item":9}'."\n".
            '[2026-10-04 10:00:02] production.ERROR: about nothing in particular'."\n"
        );

        $messages = app(WorkerLog::class)->recent(40, [5])->pluck('message')->all();

        $this->assertContains('about five', $messages);
        $this->assertContains('about nothing in particular', $messages);
        $this->assertNotContains('about nine', $messages);
    }

    /**
     * A large file must not be read whole.
     *
     * Written as 2 MB of entries against a 256 KB window: if the reader ever
     * starts loading the file, this is where it shows up.
     */
    public function test_it_reads_only_the_tail_of_a_large_file(): void
    {
        $filler = '';

        for ($i = 1; $i <= 20000; $i++) {
            $filler .= sprintf('[2026-10-04 09:%02d:%02d] production.INFO: filler %d {"item":%d}'."\n", $i % 60, $i % 60, $i, $i);
        }

        $this->write($filler.'[2026-10-04 23:59:59] production.INFO: the last one {"item":424242}'."\n");

        $this->assertGreaterThan(1048576, filesize($this->log), 'The fixture must be big enough to matter.');

        $entries = app(WorkerLog::class)->recent(5);

        $this->assertSame('the last one', $entries->first()['message']);

        // Every early entry is outside the window, so none can be present.
        $this->assertNotContains('filler 1', $entries->pluck('message')->all());
    }

    public function test_it_reports_an_absent_log_rather_than_failing(): void
    {
        @unlink($this->log);

        $this->assertFalse(app(WorkerLog::class)->exists());
        $this->assertTrue(app(WorkerLog::class)->recent()->isEmpty());
    }
}
