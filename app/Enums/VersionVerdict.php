<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Enums;

/**
 * What two copies of one work actually are (#457, #476).
 *
 * The user's rule: *"If Spotify has 15 versions of a song for an artist, we
 * should too."* So the default answer is **keep both**, and the burden of proof
 * is on calling something a duplicate rather than on keeping it.
 *
 * Before this the only verdicts available were "duplicate" or "not", which is
 * why ~29% of identifier-matched pairs on this library were the same recording
 * on a different release and the owner was asked to adjudicate pairs that both
 * belonged.
 */
enum VersionVerdict: string
{
    /** The same file reached twice. One row is redundant; the file is not. */
    case SameFile = 'same_file';

    /**
     * Byte-identical, two files. Safe to resolve after a re-hash.
     */
    case IdenticalCopy = 'identical_copy';

    /**
     * Same work, same edition, same quality. The only real duplicate.
     *
     * Still a review item rather than an automatic delete, because which copy
     * to keep depends on things the code cannot see — which drive has room,
     * which one a playlist points at.
     */
    case Duplicate = 'duplicate';

    /**
     * Same work and edition, different quality. A better or worse copy.
     *
     * Worth telling the user about so they can drop the worse one, but it is
     * their call: a 1080p copy that plays on the TV in the kitchen is not made
     * useless by a 4K one existing.
     */
    case QualityVariant = 'quality_variant';

    /**
     * Same work, different edition. **Keep both.**
     *
     * The remaster and the original, the single edit and the album cut, the
     * acoustic take and the studio version. This is the verdict the whole
     * model exists to produce, and it is never a review item.
     */
    case Version = 'version';

    /** Different works that merely look alike. Not related at all. */
    case Unrelated = 'unrelated';

    /**
     * Whether a person should be asked about this pair.
     *
     * Versions and quality variants are *information*, not questions. Putting
     * them in the review queue is what made the queue feel like busywork.
     */
    public function needsReview(): bool
    {
        return match ($this) {
            self::Duplicate, self::IdenticalCopy => true,
            default => false,
        };
    }

    /** Whether both copies stay on disk, whatever else happens. */
    public function keepsBoth(): bool
    {
        return match ($this) {
            self::Version, self::QualityVariant, self::Unrelated => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SameFile => 'The same file, catalogued twice',
            self::IdenticalCopy => 'An identical copy',
            self::Duplicate => 'A duplicate',
            self::QualityVariant => 'The same version at a different quality',
            self::Version => 'A different version',
            self::Unrelated => 'Not the same thing',
        };
    }

    /**
     * What to tell the user, in a sentence.
     *
     * Written for the review screen, which states the question in words rather
     * than leaving a person to infer it from a table row (#480).
     */
    public function explanation(): string
    {
        return match ($this) {
            self::SameFile => 'Two catalogue entries point at one file. Removing the spare entry touches nothing on disk.',
            self::IdenticalCopy => 'Two files with identical contents. Removing one loses nothing; it goes to the trash either way.',
            self::Duplicate => 'The same recording, the same version, the same quality. One copy is redundant.',
            self::QualityVariant => 'The same version at different qualities. Keeping both is fine; the better one plays by default.',
            self::Version => 'Different versions of the same work — a remaster, an edit, a live take. Both are worth keeping, and each appears in its own release.',
            self::Unrelated => 'These only look alike. They are different recordings.',
        };
    }
}
