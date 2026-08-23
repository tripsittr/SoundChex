<?php

namespace App\Services;

/**
 * Trimming that counts characters rather than bytes.
 *
 * `trim($s, " -–—_")` looks obviously right and is quietly destructive. PHP's
 * `trim()` takes a *byte* mask, and `–` and `—` are three bytes each in UTF-8
 * — `E2 80 93` and `E2 80 94`. So the mask is really:
 *
 *     0x20  0x2D  0xE2  0x80  0x93  0x94  0x5F
 *
 * `E2` and `80` are now in it. Every character in the General Punctuation
 * block begins `E2 80`, so the first two bytes of a leading curly quote,
 * ellipsis or bullet are eaten and the third is left stranded:
 *
 *     ’Cause I’m a Man     →  <0x99>Cause I’m a Man       not valid UTF-8
 *     …baby one more time  →  <0xA6>baby one more time    not valid UTF-8
 *     “Heroes”             →  <0x9C>Heroes”               not valid UTF-8
 *
 * The row is then invalid UTF-8 in the database, which breaks JSON encoding
 * downstream — which is how it was found, in a log rather than by anyone
 * looking at a title.
 */
class Titles
{
    /**
     * The characters actually meant by " -–—_." and friends.
     *
     * Listed as characters, matched as characters. Adding to this is safe;
     * adding to a byte mask is not.
     */
    private const TRIMMABLE = [' ', '-', '–', '—', '_', '.', "\t", "\n", "\r", "\0", "\x0B"];

    /** Strips leading and trailing junk without splitting a character in half. */
    public static function trim(?string $value, array $extra = []): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $characters = array_merge(self::TRIMMABLE, $extra);

        // A character class, so the match is per character and never lands
        // mid-sequence. `u` makes the subject UTF-8 rather than bytes.
        $class = implode('', array_map(
            static fn (string $c): string => preg_quote($c, '/'),
            $characters,
        ));

        $trimmed = preg_replace('/^[' . $class . ']+|[' . $class . ']+$/u', '', $value);

        // preg_replace returns null on a malformed subject. Something already
        // corrupt should come back unchanged rather than become an empty
        // string, which would lose the only evidence of what went wrong.
        return $trimmed ?? $value;
    }
}
