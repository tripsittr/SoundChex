<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Plugins\Exceptions;

use RuntimeException;

/**
 * A `plugin.json` that cannot be trusted enough to load the plugin — missing a
 * required field, malformed JSON, or an id that is not a usable slug.
 */
class InvalidManifestException extends RuntimeException {}
