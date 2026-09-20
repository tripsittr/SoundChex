<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\Example;

/**
 * A stand-in metadata source the example plugin registers. It exists only to be
 * a class the loader autoloads at runtime — proof the plugin's own namespace was
 * wired into Composer without a dump-autoload.
 */
class ExampleSource
{
    public function name(): string
    {
        return 'Example Source';
    }
}
