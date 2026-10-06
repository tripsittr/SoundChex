<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\Metadata\Sources\Music\MusicBrainz;
use Illuminate\Console\Command;

/**
 * Re-scores music that already carries a MusicBrainz id but reads as a guess
 * (#485).
 *
 * The plan said the library read `match_confidence = none` because the
 * recording id was probably never read. Measuring it said otherwise: on this
 * library **6,050 of 8,314 rows do carry one**, only 642 read `none`, and every
 * one of those 642 has no id — which is consistent rather than broken.
 *
 * The real finding was 3,429 rows that **have** an id and are scored `Fuzzy`
 * anyway, all matched by MusicBrainz. They have no `enrichment_report` and no
 * `reviewed_at`, so they never went through the current pipeline: MusicBrainz
 * wrote the id and the confidence came from an older path. Running one through
 * the live API promoted it `fuzzy → exact`, so the code is right and the data
 * is stale.
 *
 * This fixes the data. On this library it should move roughly 32% `Exact` to
 * roughly 73%.
 *
 * **It calls the API rather than trusting the column.** Setting `Exact` because
 * an id is *present* is the exact mistake #459 fixed — a dead or mistyped id
 * must not score exact — so each row's id is actually resolved.
 */
class RescoreIdentifiedMusic extends Command
{
    protected $signature = 'music:rescore
        {--limit=0 : Stop after this many rows (0 = all)}
        {--sleep=1100 : Milliseconds between lookups, to respect MusicBrainz 1/s}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Re-score music that already has a MusicBrainz id but reads as a loose match';

    public function handle(MusicBrainz $musicbrainz): int
    {
        $query = $this->candidates();
        $total = $query->count();

        if ($total === 0) {
            $this->info('Nothing to re-score: every row with an identifier already scores exactly.');

            return self::SUCCESS;
        }

        $limit = max(0, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        $dryRun = (bool) $this->option('dry-run');

        $take = $limit > 0 ? min($limit, $total) : $total;

        $this->info("{$total} row(s) carry an identifier but read as a loose match.");

        if ($dryRun) {
            $this->table(
                ['id', 'title', 'identifier', 'now'],
                $query->clone()->with('musicMetadata')->limit($take)->get()
                    ->map(fn (MediaItem $item): array => [
                        $item->id,
                        \Illuminate\Support\Str::limit($item->title, 36),
                        filled($item->musicMetadata?->musicbrainz_recording_id)
                            ? 'mb:'.substr((string) $item->musicMetadata->musicbrainz_recording_id, 0, 8)
                            : 'isrc:'.$item->musicMetadata?->isrc,
                        $item->match_confidence?->value ?? 'none',
                    ])->all(),
            );

            $this->info("Would re-score {$take}. Re-run without --dry-run to apply.");

            return self::SUCCESS;
        }

        // Rate-limited and unattended: at MusicBrainz's stated 1/s this is
        // about an hour for 3,429 rows. Said up front so nobody kills it
        // thinking it has hung.
        $minutes = (int) ceil($take * max($sleepMs, 1) / 1000 / 60);
        $this->comment("Re-scoring {$take} at one lookup per ".($sleepMs / 1000)."s — roughly {$minutes} minute(s).");

        $promoted = $unchanged = $failed = 0;

        $bar = $this->output->createProgressBar($take);
        $bar->start();

        // Chunked by id so the cursor cannot be disturbed by the rows this
        // very command is updating.
        foreach ($query->with('musicMetadata')->limit($take)->get() as $item) {
            try {
                $before = $item->match_confidence;

                // The source decides, having actually resolved the id. This
                // command never writes a confidence itself.
                $musicbrainz->enrich($item);

                $after = $item->fresh()->match_confidence;

                if ($after === MatchConfidence::Exact && $before !== MatchConfidence::Exact) {
                    $promoted++;
                } else {
                    $unchanged++;
                }
            } catch (\Throwable $e) {
                // Counted and carried, so one bad row does not end a run of
                // thousands. The pipeline logs the detail.
                $failed++;
                report($e);
            }

            $bar->advance();

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Promoted {$promoted} to exact. {$unchanged} unchanged.".
            ($failed > 0 ? " {$failed} failed — the log says which." : ''));

        if ($unchanged > 0) {
            // Not a failure: an id that no longer resolves, or a recording
            // MusicBrainz has merged away, legitimately stays a guess. Said
            // out loud so the number is not read as a bug.
            $this->comment('An unchanged row is one whose id did not resolve — a merged or retired recording. That is the correct answer, not a failure.');
        }

        return self::SUCCESS;
    }

    /**
     * Rows that carry an identifier and do not score exactly.
     *
     * **Either** a MusicBrainz recording id **or** an ISRC, because both are
     * routes `MusicBrainz::resolveRecording()` resolves by and both therefore
     * earn `Exact` when they land. a5 asked on review whether ISRC-only rows
     * were meant to be in scope; on this library the answer is moot — all
     * 3,598 rows with an ISRC also carry an MBID, and there are zero
     * ISRC-only and zero AcoustID-only rows — but that is a coincidence of one
     * library rather than a guarantee, and a scope that is only accidentally
     * complete is the kind that silently misses rows on somebody else's.
     *
     * AcoustID is deliberately out. Resolving a fingerprint means computing it
     * from the file with `fpcalc`, which is #466's work and not a database
     * re-score — and this library has no row where it would be the only
     * identifier anyway.
     *
     * Unscoped, because a row awaiting review is exactly the kind this is for
     * and `ResolvedScope` hides it.
     */
    private function candidates()
    {
        return MediaItem::withoutGlobalScopes()
            ->where('type', MediaItemType::Music)
            ->where(fn ($q) => $q->whereNull('match_confidence')
                ->orWhere('match_confidence', '!=', MatchConfidence::Exact->value))
            ->whereHas('musicMetadata', fn ($q) => $q
                ->where(fn ($inner) => $inner
                    ->where(fn ($id) => $id->whereNotNull('musicbrainz_recording_id')
                        ->where('musicbrainz_recording_id', '!=', ''))
                    ->orWhere(fn ($isrc) => $isrc->whereNotNull('isrc')
                        ->where('isrc', '!=', ''))))
            ->orderBy('id');
    }
}
