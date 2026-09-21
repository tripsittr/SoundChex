<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Fails if any Composer dependency's licence forbids distributing SoundChex
 * under the AGPL (S-5).
 *
 * The audit (Documentation & Planning/LicenseAudit.md) confirmed the tree is
 * clean; this is the *gate* that keeps it clean — run in CI and before a
 * release, so a future dependency with an incompatible licence (SSPL, BUSL,
 * GPL-2.0-only with no permissive alternative, proprietary) is caught rather
 * than shipped.
 *
 * The rule is per package, not per licence string: a package is fine when it
 * offers *at least one* AGPL-compatible licence, even if it also lists an
 * incompatible one — most GPL-flagged packages here are multi-licensed and also
 * offer BSD or LGPL. It fails only when a package offers *nothing* compatible.
 *
 * Cargo and npm are gated separately (deny.toml + the CI workflow); this covers
 * the Composer tree, which is the one an artisan command can read directly.
 */
class CheckLicenses extends Command
{
    protected $signature = 'licenses:check {--json : Machine-readable output}';

    protected $description = 'Fail if a Composer dependency forbids AGPL distribution';

    /**
     * Licences under which we may distribute an AGPL work. A package offering
     * any of these passes. Permissive, weak-copyleft, and GPL/AGPL itself — the
     * families the audit accepted.
     */
    private const COMPATIBLE = [
        'MIT', '0BSD', 'BSD-2-Clause', 'BSD-3-Clause', 'BSD-4-Clause',
        'Apache-2.0', 'ISC', 'Unlicense', 'CC0-1.0', 'Zlib', 'WTFPL',
        'MPL-2.0', 'LGPL-2.1-only', 'LGPL-2.1-or-later', 'LGPL-3.0-only',
        'LGPL-3.0-or-later', 'GPL-2.0-or-later', 'GPL-3.0-only',
        'GPL-3.0-or-later', 'AGPL-3.0-only', 'AGPL-3.0-or-later',
        'GPL-1.0-or-later', // upgradeable to GPL-3, AGPL-compatible
        'Python-2.0', 'PHP-3.01',
    ];

    public function handle(): int
    {
        $result = Process::run('composer licenses --format=json');

        if (! $result->successful()) {
            $this->error('Could not run `composer licenses`.');

            return self::FAILURE;
        }

        $data = json_decode($result->output(), true);
        $dependencies = $data['dependencies'] ?? [];

        $offenders = [];

        foreach ($dependencies as $package => $info) {
            $licenses = array_map('trim', (array) ($info['license'] ?? []));

            // No licence at all is a fail — an unlicensed package is "all rights
            // reserved" and cannot be redistributed.
            if ($licenses === [] || $licenses === ['']) {
                $offenders[$package] = ['none'];

                continue;
            }

            if (! $this->hasCompatible($licenses)) {
                $offenders[$package] = $licenses;
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode(['offenders' => $offenders], JSON_PRETTY_PRINT));
        }

        if ($offenders !== []) {
            $this->error(count($offenders).' dependency licence(s) are incompatible with AGPL distribution:');

            foreach ($offenders as $package => $licenses) {
                $this->line("  {$package} — ".implode(', ', $licenses));
            }

            $this->newLine();
            $this->line('Each offers no AGPL-compatible licence. Replace it, or (if it genuinely');
            $this->line('is compatible) add its licence id to CheckLicenses::COMPATIBLE.');

            return self::FAILURE;
        }

        $this->info(count($dependencies).' Composer dependencies checked — all AGPL-compatible.');

        return self::SUCCESS;
    }

    /**
     * Whether any of a package's offered licences is one we may distribute under.
     *
     * @param  array<int, string>  $licenses
     */
    private function hasCompatible(array $licenses): bool
    {
        foreach ($licenses as $license) {
            // Normalise "(MIT OR Apache-2.0)" and "MIT AND BSD-3-Clause" into
            // the individual ids an SPDX expression is built from.
            foreach (preg_split('/\s+(?:OR|AND)\s+|[()]/', $license) as $part) {
                if (in_array(trim($part), self::COMPATIBLE, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
