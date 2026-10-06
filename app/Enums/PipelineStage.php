<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Enums;

/**
 * How far a file has got through the library pipeline (#489).
 *
 * The problem this exists for: enrichment was one job that identified, tidied,
 * normalised, embedded a cover and filed the file, all inside one `handle()`.
 * A failure anywhere marked the whole item `failed`, a success anywhere could
 * not be resumed from, and nothing recorded which step an item had reached — so
 * a crash between cataloguing a row and dispatching its job hid that row
 * forever, because `ResolvedScope` hides everything that is not `complete`.
 *
 * Each stage is one job, each job is idempotent, and each writes the next stage
 * in the same transaction as its own results. A crash resumes at the stage that
 * did not commit.
 *
 * The order is the declaration order, and `next()` depends on it.
 */
enum PipelineStage: string
{
    /** The row exists and records where the file is. */
    case Catalogued = 'catalogued';

    /** Technical facts read from the file itself (#489 fills this out). */
    case Probed = 'probed';

    /** `content_hash` written. Its own stage because hashing a 40 GB remux is slow. */
    case Hashed = 'hashed';

    /** An identity chosen, with a confidence. */
    case Identified = 'identified';

    /** Fields filled for that identity. */
    case Enriched = 'enriched';

    /** Compared against the library for duplicates and versions. */
    case Deduped = 'deduped';

    /** Quality checked (#489). */
    case Checked = 'checked';

    /** A target path computed and journalled, not yet acted on. */
    case Planned = 'planned';

    /** The file is where the plan said. */
    case Filed = 'filed';

    /** Visible in the library; events fired. */
    case Published = 'published';

    /**
     * The stage after this one, or null at the end.
     *
     * Derived from the declaration order rather than written out again, so
     * adding a stage in the right place is the whole change.
     */
    public function next(): ?self
    {
        $cases = self::cases();

        foreach ($cases as $index => $case) {
            if ($case === $this) {
                return $cases[$index + 1] ?? null;
            }
        }

        return null;
    }

    /** Whether this stage comes before another in the pipeline. */
    public function isBefore(self $other): bool
    {
        return $this->position() < $other->position();
    }

    public function position(): int
    {
        foreach (self::cases() as $index => $case) {
            if ($case === $this) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * How long this stage may run before the sweeper calls it stuck.
     *
     * Generous where the work is genuinely slow — hashing and filing move
     * bytes, identification waits on other people's servers — and short where
     * a stage only touches the database. A stage that exceeds its timeout is
     * requeued, not failed, so the cost of being wrong is one repeat.
     */
    public function timeoutSeconds(): int
    {
        return match ($this) {
            self::Catalogued => 60,
            self::Probed => 600,
            self::Hashed => 3600,
            self::Identified, self::Enriched => 900,
            self::Deduped => 600,
            self::Checked => 3600,
            self::Planned => 120,
            self::Filed => 3600,
            self::Published => 120,
        };
    }

    /**
     * Which queue this stage's work belongs on.
     *
     * Three, so that hashing a film cannot block identification behind it and
     * a rate-limited provider cannot hold up the disk: `io` moves bytes, `cpu`
     * reads files, `net` talks to other people's servers.
     */
    public function queue(): string
    {
        return match ($this) {
            self::Hashed, self::Filed, self::Checked => 'io',
            self::Probed => 'cpu',
            self::Identified, self::Enriched => 'net',
            default => 'default',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Catalogued => 'Catalogued',
            self::Probed => 'Probed',
            self::Hashed => 'Hashed',
            self::Identified => 'Identified',
            self::Enriched => 'Enriched',
            self::Deduped => 'Duplicate-checked',
            self::Checked => 'Quality-checked',
            self::Planned => 'Planned',
            self::Filed => 'Filed',
            self::Published => 'Published',
        };
    }
}
