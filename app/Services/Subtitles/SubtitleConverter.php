<?php

namespace App\Services\Subtitles;

/**
 * Converts subtitle formats to WebVTT.
 *
 * Browsers play WebVTT and nothing else, so every track becomes one on import.
 * SRT and WebVTT are close relatives and convert cleanly in PHP; ASS/SSA carry
 * positioning and styling that would need a full parser, so those are handed
 * to ffmpeg instead.
 */
class SubtitleConverter
{
    /**
     * SubRip to WebVTT.
     *
     * The differences are small but all of them matter: WebVTT needs a header,
     * uses a dot for decimal seconds where SRT uses a comma, and treats a few
     * characters as markup that have to be escaped.
     */
    public function srtToVtt(string $srt): string
    {
        // A byte-order mark would end up inside the first cue's text.
        $srt = preg_replace('/^\xEF\xBB\xBF/', '', $srt) ?? $srt;

        $srt = str_replace(["\r\n", "\r"], "\n", $srt);

        $lines = explode("\n", $srt);
        $output = ["WEBVTT", ""];

        $inCue = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // A bare number on its own line is an SRT cue index. WebVTT allows
            // identifiers but they serve no purpose here, so they're dropped.
            if (! $inCue && preg_match('/^\d+$/', $trimmed)) {
                continue;
            }

            if (preg_match('/^(\S+)\s+-->\s+(\S+)(.*)$/', $trimmed, $match)) {
                $output[] = $this->normalizeTimestamp($match[1])
                    . ' --> '
                    . $this->normalizeTimestamp($match[2])
                    . $this->cueSettings($match[3]);

                $inCue = true;

                continue;
            }

            if ($trimmed === '') {
                $inCue = false;
                $output[] = '';

                continue;
            }

            $output[] = $inCue ? $this->escapeCueText($line) : $line;
        }

        return implode("\n", $output) . "\n";
    }

    /**
     * Whether this content already looks like WebVTT.
     */
    public function isVtt(string $content): bool
    {
        return str_starts_with(ltrim($content, "\xEF\xBB\xBF \t\n\r"), 'WEBVTT');
    }

    /**
     * Ensures a WebVTT file has its required header.
     *
     * Some tools emit cues with no header at all, which browsers reject
     * outright rather than tolerating.
     */
    public function ensureVttHeader(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        return $this->isVtt($content)
            ? $content
            : "WEBVTT\n\n" . ltrim($content);
    }

    /**
     * Counts cues, as a cheap sanity check that a track isn't empty.
     */
    public function countCues(string $vtt): int
    {
        return preg_match_all('/^\S+\s+-->\s+\S+/m', $vtt) ?: 0;
    }

    /**
     * SRT writes 00:00:01,500; WebVTT wants 00:00:01.500.
     *
     * Also expands the MM:SS.mmm form some tools emit, which WebVTT permits
     * but which is safer normalised to full hours.
     */
    private function normalizeTimestamp(string $timestamp): string
    {
        $timestamp = str_replace(',', '.', trim($timestamp));

        // Pad a missing hours component: "01:30.000" → "00:01:30.000".
        if (preg_match('/^(\d{1,2}):(\d{2}\.\d{1,3})$/', $timestamp, $match)) {
            return '00:' . str_pad($match[1], 2, '0', STR_PAD_LEFT) . ':' . $match[2];
        }

        // Pad single-digit hours, which some tools emit.
        if (preg_match('/^(\d):(\d{2}):(\d{2}\.\d{1,3})$/', $timestamp, $match)) {
            return '0' . $match[1] . ':' . $match[2] . ':' . $match[3];
        }

        return $timestamp;
    }

    /**
     * Carries through positioning that appears after the timestamps.
     *
     * SRT has coordinate extensions (X1:.. Y1:..) that WebVTT doesn't
     * understand, so anything unrecognised is dropped rather than emitted as
     * invalid cue settings.
     */
    private function cueSettings(string $trailing): string
    {
        $trailing = trim($trailing);

        if ($trailing === '') {
            return '';
        }

        $allowed = [];

        foreach (preg_split('/\s+/', $trailing) ?: [] as $setting) {
            if (preg_match('/^(line|position|size|align|vertical|region):\S+$/', $setting)) {
                $allowed[] = $setting;
            }
        }

        return $allowed === [] ? '' : ' ' . implode(' ', $allowed);
    }

    /**
     * Escapes characters WebVTT would otherwise read as markup.
     *
     * The tag subset WebVTT does support (<i>, <b>, <u>, <c>, <v>) is common
     * in real subtitles and is preserved; everything else is escaped so a
     * stray angle bracket can't swallow the rest of a line.
     */
    private function escapeCueText(string $line): string
    {
        $placeholders = [];

        // Park the supported tags, escape what's left, then restore them.
        $line = preg_replace_callback(
            '#</?(?:i|b|u|c|v|ruby|rt|lang)(?:[ .][^>]*)?>#i',
            function (array $match) use (&$placeholders): string {
                $token = "\x00" . count($placeholders) . "\x00";
                $placeholders[$token] = $match[0];

                return $token;
            },
            $line,
        ) ?? $line;

        $line = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $line);

        return strtr($line, $placeholders);
    }
}
