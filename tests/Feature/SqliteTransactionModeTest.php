<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Why write transactions must begin IMMEDIATE.
 *
 * "database is locked" was the commonest failure in this app's queue, and a
 * 120-second `busy_timeout` did nothing for it, which made no sense until the
 * stack trace pointed at Laravel's own `DatabaseQueue::pop()`: begin a
 * transaction, select the next job, update it to reserved.
 *
 * That is the shape that breaks. A DEFERRED transaction takes no lock at BEGIN;
 * the SELECT fixes a read snapshot, and the UPDATE then asks to become a
 * writer. If anything else has committed in between, the snapshot is stale and
 * SQLite refuses the upgrade *at once* — waiting cannot make a stale read
 * valid, so `busy_timeout` is never consulted. IMMEDIATE takes the write lock
 * at BEGIN instead, so there is no upgrade to refuse, and a contended lock
 * becomes something a timeout can actually wait out.
 *
 * These tests drive sqlite directly. Going through the framework would prove
 * the configuration is passed along; going through PDO proves the thing the
 * configuration is for.
 */
class SqliteTransactionModeTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'sqlite-mode-').'.sqlite';

        $setup = $this->connect();
        $setup->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, reserved_at INTEGER NULL)');
        $setup->exec('INSERT INTO jobs (id, reserved_at) VALUES (1, NULL)');
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->file.$suffix);
        }

        parent::tearDown();
    }

    private function connect(int $busyTimeoutMs = 2000): PDO
    {
        $pdo = new PDO('sqlite:'.$this->file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = '.$busyTimeoutMs);

        return $pdo;
    }

    /**
     * The failure, reproduced: a deferred reader-then-writer loses, and the
     * generous busy_timeout above is not consulted at all.
     */
    public function test_a_deferred_transaction_fails_to_upgrade_after_another_write(): void
    {
        $worker = $this->connect();
        $other = $this->connect();

        // What pop() does: begin, then read.
        $worker->exec('BEGIN DEFERRED TRANSACTION');
        $worker->query('SELECT id FROM jobs WHERE reserved_at IS NULL')->fetchAll();

        // What the scheduler or the web process does meanwhile.
        $other->exec('INSERT INTO jobs (id, reserved_at) VALUES (2, NULL)');

        // And what pop() does next.
        $started = microtime(true);

        try {
            $worker->exec('UPDATE jobs SET reserved_at = 1 WHERE id = 1');
            $worker->exec('COMMIT');

            $this->fail('The upgrade was expected to fail; this test no longer demonstrates anything.');
        } catch (PDOException $e) {
            $this->assertStringContainsString('database is locked', $e->getMessage());

            // The point: it failed at once. A two-second timeout was available
            // and SQLite did not use a moment of it, because no amount of
            // waiting can refresh a snapshot that has already been read.
            $this->assertLessThan(
                1.0,
                microtime(true) - $started,
                'It failed slowly, so busy_timeout was consulted and this is a different problem.'
            );
        }

        $worker->exec('ROLLBACK');
    }

    /**
     * The fix: the same sequence, holding the write lock from the start.
     *
     * The second connection is now the one that has to wait — given a short
     * timeout here so the test does not sit around, where production gives it
     * two minutes. That is the whole change: the transaction that got there
     * first finishes its work instead of dying for having read too early.
     */
    public function test_an_immediate_transaction_completes_the_same_sequence(): void
    {
        $worker = $this->connect();
        $other = $this->connect(50);

        $worker->exec('BEGIN IMMEDIATE TRANSACTION');
        $worker->query('SELECT id FROM jobs WHERE reserved_at IS NULL')->fetchAll();

        try {
            $other->exec('INSERT INTO jobs (id, reserved_at) VALUES (2, NULL)');
            $this->fail('The other writer was expected to be held off while the lock is held.');
        } catch (PDOException $e) {
            $this->assertStringContainsString('database is locked', $e->getMessage());
        }

        // The worker is unaffected and finishes.
        $worker->exec('UPDATE jobs SET reserved_at = 1 WHERE id = 1');
        $worker->exec('COMMIT');

        $this->assertSame(
            1,
            (int) $this->connect()->query('SELECT reserved_at FROM jobs WHERE id = 1')->fetchColumn(),
            'The job should have been reserved.'
        );
    }

    /**
     * And the app is configured that way.
     *
     * Honoured by Laravel only on PHP 8.4 and above; the bundled runtime is
     * 8.5, so a version below that here would mean the suite is not testing
     * what ships.
     */
    public function test_the_app_begins_sqlite_transactions_immediate(): void
    {
        $this->assertSame('IMMEDIATE', config('database.connections.sqlite.transaction_mode'));

        $this->assertTrue(
            version_compare(PHP_VERSION, '8.4.0', '>='),
            'Laravel ignores transaction_mode below PHP 8.4, so this would be configured and inert.'
        );
    }
}
