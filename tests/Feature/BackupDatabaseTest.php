<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `db:backup` (S-359).
 *
 * The command vacuums the database to an uncompressed snapshot, gzips it, and
 * deletes the original. The interesting cases are the ones where that sequence
 * does not complete: a stranded `.sqlite` is a full-size copy of the database
 * that `prune()` — which globs `*.sqlite.gz` — can never reap, so on a daily
 * schedule it accumulates until the disk fills.
 */
class BackupDatabaseTest extends TestCase
{
    // No RefreshDatabase: it wraps each test in a transaction, and VACUUM
    // cannot run inside one. These tests snapshot their own throwaway file,
    // so the application schema is irrelevant to them.
    private string $directory;

    private string $databaseFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/backups');
        @mkdir($this->directory, 0o775, true);

        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        // The suite runs on :memory:, which the command refuses by design — it
        // can only snapshot a file. Point it at a real one holding a trivial
        // schema, which is all VACUUM INTO needs.
        $this->databaseFile = storage_path('framework/testing/backup-source.sqlite');
        @unlink($this->databaseFile);
        touch($this->databaseFile);

        config(['database.default' => 'backup_testing']);
        config(['database.connections.backup_testing' => [
            'driver' => 'sqlite',
            'database' => $this->databaseFile,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        \Illuminate\Support\Facades\DB::connection('backup_testing')
            ->statement('CREATE TABLE IF NOT EXISTS probe (id INTEGER PRIMARY KEY)');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
        @unlink($this->databaseFile);

        parent::tearDown();
    }

    public function test_it_writes_a_compressed_backup_and_removes_the_snapshot(): void
    {
        $this->artisan('db:backup', ['--path' => $this->directory])->assertSuccessful();

        $this->assertCount(1, glob($this->directory.'/soundchex-*.sqlite.gz') ?: []);
        $this->assertSame(
            [],
            glob($this->directory.'/soundchex-*.sqlite') ?: [],
            'the uncompressed snapshot must not survive a successful run',
        );
    }

    public function test_it_keeps_only_the_requested_number(): void
    {
        foreach (['2020-01-01_000000', '2020-01-02_000000', '2020-01-03_000000'] as $stamp) {
            file_put_contents($this->directory."/soundchex-{$stamp}.sqlite.gz", 'x');
        }

        $this->artisan('db:backup', ['--path' => $this->directory, '--keep' => 2])->assertSuccessful();

        $this->assertCount(
            2,
            glob($this->directory.'/soundchex-*.sqlite.gz') ?: [],
            'the newest two survive: the three stubs plus this run, minus the oldest two',
        );
    }

    public function test_it_sweeps_an_uncompressed_snapshot_a_failed_run_left_behind(): void
    {
        // What a half-finished run leaves: a full-size .sqlite with no .gz
        // beside it. `prune()` globs *.sqlite.gz, so before S-359 this was
        // permanent.
        $orphan = $this->directory.'/soundchex-2020-01-01_000000.sqlite';
        file_put_contents($orphan, str_repeat('x', 1024));
        touch($orphan, time() - 7200);

        $this->artisan('db:backup', ['--path' => $this->directory])->assertSuccessful();

        $this->assertFileDoesNotExist($orphan, 'a stranded snapshot should be reaped');
    }

    public function test_it_leaves_a_snapshot_that_may_belong_to_a_running_backup(): void
    {
        // Same shape, but recent — another process may be mid-run, between
        // writing the snapshot and compressing it. Deleting it would break a
        // backup in flight.
        $recent = $this->directory.'/soundchex-2020-01-02_000000.sqlite';
        file_put_contents($recent, 'x');

        $this->artisan('db:backup', ['--path' => $this->directory])->assertSuccessful();

        $this->assertFileExists($recent, 'a snapshot written moments ago may be a run in progress');
    }

    public function test_it_leaves_hand_named_snapshots_alone(): void
    {
        // Deliberate safety nets taken before a migration. Not this command's
        // to delete, whatever their age.
        $manual = $this->directory.'/pre-migration-20200101-000000.sqlite';
        file_put_contents($manual, 'x');
        touch($manual, time() - 86400);

        $this->artisan('db:backup', ['--path' => $this->directory])->assertSuccessful();

        $this->assertFileExists($manual, "a hand-named snapshot is somebody's deliberate safety net");
    }
}
