<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Services\RuntimeHealth;
use Illuminate\Console\Command;

/**
 * Asserts the PHP runtime has every extension SoundChex needs, naming what is
 * missing and what it breaks.
 *
 * Meant to run at install/boot of the bundled server (and by hand on a
 * hand-rolled host): a wrong PHP build disables features silently, so this
 * turns "tags mysteriously don't read" into "ext-exif is missing". Exits
 * non-zero when a *required* extension is absent so a supervisor can refuse to
 * start; recommended-but-absent extensions are reported as warnings only.
 */
class CheckExtensions extends Command
{
    protected $signature = 'server:check-extensions {--quiet-ok : Print nothing when everything is present}';

    protected $description = 'Verify the PHP runtime has every extension SoundChex needs';

    public function handle(RuntimeHealth $health): int
    {
        $missingRequired = $health->missingRequired();
        $missingRecommended = $health->missingRecommended();

        if ($missingRequired === []) {
            if (! $this->option('quiet-ok')) {
                $this->info('All required PHP extensions are present ('.count(RuntimeHealth::REQUIRED).' checked).');

                if ($missingRecommended !== []) {
                    $this->newLine();
                    $this->warn('Recommended extensions not loaded (the app still runs):');
                    foreach ($missingRecommended as $ext => $why) {
                        $this->line("  · <fg=yellow>{$ext}</> — {$why}");
                    }
                }
            }

            return self::SUCCESS;
        }

        $this->error('Missing required PHP extensions — SoundChex will not work correctly:');
        foreach ($missingRequired as $ext => $why) {
            $this->line("  ✗ <fg=red>{$ext}</> — {$why}");
        }

        if ($missingRecommended !== []) {
            $this->newLine();
            $this->warn('Also missing (recommended, non-fatal):');
            foreach ($missingRecommended as $ext => $why) {
                $this->line("  · <fg=yellow>{$ext}</> — {$why}");
            }
        }

        $this->newLine();
        $this->line('Rebuild PHP with these extensions, or install the bundled SoundChex server runtime.');

        return self::FAILURE;
    }
}
