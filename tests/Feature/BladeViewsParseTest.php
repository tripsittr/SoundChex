<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Every Blade template must compile to PHP that actually parses.
 *
 * `view:cache` does not check this. It runs the Blade compiler and writes the
 * result, and invalid PHP is written out as happily as valid — the failure only
 * appears when someone opens the page, as "There was an error while attempting
 * to load this page" with a ParseError buried in the log.
 *
 * That is how a broken dashboard widget shipped: `waiting@if ($failed > 0)`.
 * Blade matches a directive only at a non-word boundary, so an `@if` glued to
 * the preceding word stays literal text while its `@endif` compiles anyway,
 * leaving a stray `endif` the compiler is happy to write out.
 */
class BladeViewsParseTest extends TestCase
{
    public function test_every_view_compiles_to_parseable_php(): void
    {
        $broken = [];

        foreach ($this->viewFiles() as $path) {
            $compiled = Blade::compileString((string) file_get_contents($path));

            if (($error = $this->syntaxError($compiled)) !== null) {
                $broken[] = $this->relative($path).' — '.$error;
            }
        }

        $this->assertSame([], $broken, "These views compile to PHP that will not parse:\n".implode("\n", $broken));
    }

    /**
     * The syntax error in a compiled view, or null when it parses.
     *
     * `php -l` on a temp file rather than `eval()` in a never-taken branch:
     * a compiled view can carry a top-level `use` import (from `@use`), which
     * is illegal inside a block and made the in-process check report failures
     * for three perfectly good views. A separate process also cannot execute
     * anything by accident.
     */
    private function syntaxError(string $compiled): ?string
    {
        $file = tempnam(sys_get_temp_dir(), 'blade-').'.php';
        file_put_contents($file, $compiled);

        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $output, $status);

        @unlink($file);

        if ($status === 0) {
            return null;
        }

        $message = implode(' ', $output);

        // The temp path is noise; the message is the useful part.
        return trim((string) preg_replace('~ in .*$~', '', $message));
    }

    /** @return list<string> */
    private function viewFiles(): array
    {
        $root = resource_path('views');

        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        $this->assertNotEmpty($files, 'No views found to check.');

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $path));
    }
}
