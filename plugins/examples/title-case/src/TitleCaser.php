<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\TitleCase;

/**
 * Applies a chosen case style to a title.
 *
 * Kept separate from the plugin so the case rules are testable without booting
 * the plugin system. Title Case is the interesting one — it capitalises the
 * words that matter and leaves the small joining words (a, of, the) lowercase
 * unless they lead, the convention a title actually follows.
 */
class TitleCaser
{
    public const TITLE = 'title';       // The Quick Brown Fox

    public const SENTENCE = 'sentence';  // The quick brown fox

    public const UPPER = 'upper';        // THE QUICK BROWN FOX

    public const LOWER = 'lower';        // the quick brown fox

    public const START = 'start';        // The Quick Brown Fox (every word)

    /** The styles the plugin offers, for the settings UI and validation. */
    public const STYLES = [
        self::TITLE => 'Title Case',
        self::SENTENCE => 'Sentence case',
        self::UPPER => 'UPPERCASE',
        self::LOWER => 'lowercase',
        self::START => 'Start Case',
    ];

    /** Words left lowercase inside a title, unless they are the first word. */
    private const MINOR_WORDS = [
        'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'into',
        'nor', 'of', 'on', 'onto', 'or', 'over', 'the', 'to', 'with', 'vs',
    ];

    public function apply(string $title, string $style): string
    {
        $title = trim($title);

        if ($title === '') {
            return $title;
        }

        return match ($style) {
            self::UPPER => mb_strtoupper($title),
            self::LOWER => mb_strtolower($title),
            self::SENTENCE => $this->sentence($title),
            self::START => $this->start($title),
            default => $this->title($title),
        };
    }

    /** First letter of the whole string upper, the rest lower. */
    private function sentence(string $title): string
    {
        $lower = mb_strtolower($title);

        return mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1);
    }

    /** Every word capitalised. */
    private function start(string $title): string
    {
        return $this->mapWords($title, fn (string $word): string => $this->ucfirst($word));
    }

    /** Every word capitalised except minor words that do not lead. */
    private function title(string $title): string
    {
        $index = 0;

        return $this->mapWords($title, function (string $word) use (&$index): string {
            $first = $index++ === 0;
            $lower = mb_strtolower($word);

            return ! $first && in_array($lower, self::MINOR_WORDS, true)
                ? $lower
                : $this->ucfirst($word);
        });
    }

    /**
     * Applies a per-word transform while keeping the original whitespace between
     * words, so "  A  B " does not collapse to "A B".
     */
    private function mapWords(string $title, callable $transform): string
    {
        return preg_replace_callback('/[^\s]+/u', fn (array $m): string => $transform($m[0]), $title) ?? $title;
    }

    /** Upper-cases the first character, lower-cases the rest — Unicode-safe. */
    private function ucfirst(string $word): string
    {
        return mb_strtoupper(mb_substr($word, 0, 1)).mb_strtolower(mb_substr($word, 1));
    }
}
