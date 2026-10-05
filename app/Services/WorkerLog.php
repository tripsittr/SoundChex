<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The tail of the application log, parsed.
 *
 * The queue is where almost everything in this app actually happens, and the
 * log is the only place that says what happened. Reading it meant opening a
 * 7 MB file on the server — which is not something the person running a media
 * server on their own machine should have to do to find out why a thousand
 * albums came back as "needs review".
 *
 * Read from the end, never the start. The file grows without bound and is
 * routinely tens of megabytes; loading it to show forty lines would be a
 * denial of service against ourselves.
 */
class WorkerLog
{
    /** How much of the end of the file to read. Enough for a few hundred entries. */
    private const TAIL_BYTES = 262144;

    /**
     * Recent log entries, newest first.
     *
     * @param  list<int>  $itemIds  Keep only entries about these media items,
     *                              when given. An entry that names no item at
     *                              all is always kept — a failure that never
     *                              got as far as naming one is exactly what
     *                              someone reading this needs to see.
     * @return Collection<int, array{at: Carbon|null, level: string, message: string, item: int|null, context: array<string, mixed>}>
     */
    public function recent(int $limit = 40, array $itemIds = []): Collection
    {
        $entries = $this->parse($this->tail());

        if ($itemIds !== []) {
            $wanted = array_flip($itemIds);

            $entries = $entries->filter(
                fn (array $entry): bool => $entry['item'] === null || isset($wanted[$entry['item']])
            );
        }

        return $entries->take($limit)->values();
    }

    /** Whether there is a log to read at all. */
    public function exists(): bool
    {
        return is_file($this->path()) && filesize($this->path()) > 0;
    }

    public function path(): string
    {
        return storage_path('logs/laravel.log');
    }

    /** The last TAIL_BYTES of the log, or less if it is shorter. */
    private function tail(): string
    {
        $path = $this->path();

        if (! is_file($path)) {
            return '';
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            $size = (int) filesize($path);
            $offset = max(0, $size - self::TAIL_BYTES);

            if ($offset > 0) {
                fseek($handle, $offset);
                // The first line is almost certainly cut in half; drop it.
                fgets($handle);
            }

            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Turn log text into entries, newest first.
     *
     * Only lines that open a new entry are kept. A stack trace is dozens of
     * continuation lines belonging to the entry above it, and showing them as
     * separate rows would bury everything else.
     *
     * @return Collection<int, array{at: Carbon|null, level: string, message: string, item: int|null, context: array<string, mixed>}>
     */
    private function parse(string $text): Collection
    {
        $entries = [];

        foreach (explode("\n", $text) as $line) {
            if (preg_match('~^\[(?<at>[\d-]{10} [\d:]{8})\] \w+\.(?<level>[A-Z]+): (?<rest>.*)$~', $line, $m) !== 1) {
                continue;
            }

            [$message, $context] = $this->split($m['rest']);

            $entries[] = [
                'at' => $this->time($m['at']),
                'level' => strtolower($m['level']),
                'message' => $message,
                // Most of this app's log entries name the item they are about,
                // which is what lets a job's own history be picked out of the
                // stream rather than showing everything the server did.
                'item' => isset($context['item']) && is_numeric($context['item']) ? (int) $context['item'] : null,
                'context' => $context,
            ];
        }

        return collect(array_reverse($entries));
    }

    /**
     * Separate the message from its JSON context.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function split(string $rest): array
    {
        $at = strpos($rest, ' {');

        if ($at === false) {
            return [trim($rest), []];
        }

        $decoded = json_decode(trim(substr($rest, $at)), true);

        return is_array($decoded)
            ? [trim(substr($rest, 0, $at)), $decoded]
            : [trim($rest), []];
    }

    private function time(string $raw): ?Carbon
    {
        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
