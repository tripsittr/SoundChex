<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use Dotenv\Parser\Parser;
use Tests\TestCase;

/**
 * `.env.example` is not just documentation: it is the file every install starts
 * from. `install-headless.sh` copies it and runs `key:generate`, and the Server
 * app's first-run provisioning does the same.
 *
 * So an unparseable line there is not a cosmetic problem — it stops a fresh
 * install dead. `ARR_CONFIG_ROOT` was an unquoted path containing spaces
 * ("Application Support"), which makes dotenv reject the whole file with
 * "The environment file is invalid!", naming nothing.
 */
class EnvExampleTest extends TestCase
{
    public function test_env_example_can_be_parsed_by_dotenv(): void
    {
        $path = base_path('.env.example');

        $this->assertFileExists($path, '.env.example is shipped to every install and must exist.');

        $contents = file_get_contents($path);

        // The same parser Laravel boots with. It throws on a malformed line,
        // which is the failure this test exists to catch.
        (new Parser)->parse($contents);

        $this->addToAssertionCount(1);
    }

    /**
     * The specific shape that broke it, checked directly so the reason survives
     * even if the parser's error message changes.
     */
    public function test_env_example_quotes_every_value_containing_a_space(): void
    {
        $unquoted = [];

        foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) as $number => $line) {
            if (! preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $matches)) {
                continue;
            }

            $value = $matches[2];

            if (preg_match('/\s/', $value) && ! preg_match('/^([\'"]).*\1$/', $value)) {
                $unquoted[] = ($number + 1).': '.$line;
            }
        }

        $this->assertSame(
            [],
            $unquoted,
            "These values contain whitespace and are not quoted, so dotenv rejects the file:\n".implode("\n", $unquoted)
        );
    }
}
