<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

/**
 * Library behaviour that's editable from the admin panel.
 *
 * Every value falls back to config/library.php, so a fresh install behaves
 * correctly before anyone opens the settings page, and an admin override is
 * just a row in the settings table rather than an .env edit and a redeploy.
 *
 * Reads go through SettingsService, which caches — these are consulted inside
 * scan loops that run over thousands of files.
 */
class LibrarySettings
{
    /**
     * Setting key => config fallback. Also the schema the settings page builds
     * itself from, so adding a tunable here surfaces it in the UI.
     */
    private const KEYS = [
        'library_detect_duplicates' => 'library.detect_duplicates',
        'library_detect_content_duplicates' => 'library.detect_content_duplicates',
        'library_duplicate_duration_tolerance' => 'library.duplicate_duration_tolerance',
        'library_duplicate_action' => 'library.duplicate_action',
        'library_hash_max_megabytes' => 'library.hash_max_megabytes',
        'library_auto_organize' => 'library.auto_organize',
        'library_scan_storage' => 'library.scan_storage',
        'library_scan_interval' => 'library.scan_interval_minutes',
        'library_settle_seconds' => 'library.settle_seconds',
        'ocr_language' => 'ocr.language',
        'ocr_on_demand' => 'ocr.on_demand',
    ];

    public function __construct(private SettingsService $settings) {}

    public function detectDuplicates(): bool
    {
        return (bool) $this->value('library_detect_duplicates');
    }

    /**
     * Also flag same-recording-different-file music (S-257). Independent of the
     * byte-hash pass, but only meaningful when detection is on at all.
     */
    public function detectContentDuplicates(): bool
    {
        return $this->detectDuplicates()
            && (bool) $this->value('library_detect_content_duplicates');
    }

    /** Seconds of length difference still counted as the same track (fuzzy). */
    public function duplicateDurationToleranceMs(): int
    {
        return max(0, (int) $this->value('library_duplicate_duration_tolerance')) * 1000;
    }

    /** One of: review | auto | report. */
    public function duplicateAction(): string
    {
        $action = (string) $this->value('library_duplicate_action');

        // A hand-edited or stale value must not be read as permission to
        // delete files, so anything unrecognised falls back to review.
        return in_array($action, ['review', 'auto', 'report'], true)
            ? $action
            : 'review';
    }

    /** Deletes redundant copies without asking. */
    public function deletesDuplicatesAutomatically(): bool
    {
        return $this->duplicateAction() === 'auto';
    }

    /** Bytes above which a file isn't hashed. Zero means no limit. */
    public function hashLimitBytes(): int
    {
        return max(0, (int) $this->value('library_hash_max_megabytes')) * 1024 * 1024;
    }

    public function autoOrganize(): bool
    {
        return (bool) $this->value('library_auto_organize');
    }

    public function scanStorage(): bool
    {
        return (bool) $this->value('library_scan_storage');
    }

    public function scanIntervalMinutes(): int
    {
        // A zero or negative interval would schedule a scan every minute of
        // every hour, so the floor is enforced here rather than trusted.
        return max(1, (int) $this->value('library_scan_interval'));
    }

    public function settleSeconds(): int
    {
        return max(0, (int) $this->value('library_settle_seconds'));
    }

    /**
     * Tesseract language code, e.g. "eng" or "eng+fra".
     *
     * Validated against the shell-safe character set because it is passed as a
     * process argument. A malformed value falls back rather than being run.
     */
    public function ocrLanguage(): string
    {
        $language = (string) $this->value('ocr_language');

        return preg_match('/^[a-z]{3}(\+[a-z]{3})*$/', $language) === 1
            ? $language
            : 'eng';
    }

    /** Recognise a scanned page as soon as a reader opens it. */
    public function ocrOnDemand(): bool
    {
        return (bool) $this->value('ocr_on_demand');
    }

    /**
     * Current values for every editable key, for the settings form.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $values = [];

        foreach (array_keys(self::KEYS) as $key) {
            $values[$key] = $this->value($key);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::KEYS)) {
                continue;
            }

            $this->settings->set($key, $value);
        }
    }

    private function value(string $key): mixed
    {
        $fallback = config(self::KEYS[$key]);
        $stored = $this->settings->get($key);

        // A stored value is authoritative even when it's falsy — an admin who
        // switched a toggle off means off, and `?:` would silently reinstate
        // the config default.
        return $stored === null ? $fallback : $this->cast($stored, $fallback);
    }

    /**
     * Settings round-trip through the database as strings; the callers above
     * expect the same shape config would have handed them.
     */
    private function cast(mixed $stored, mixed $fallback): mixed
    {
        if (is_bool($fallback)) {
            return filter_var($stored, FILTER_VALIDATE_BOOLEAN);
        }

        if (is_int($fallback)) {
            return (int) $stored;
        }

        return $stored;
    }
}
