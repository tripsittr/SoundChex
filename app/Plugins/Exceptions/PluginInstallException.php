<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Plugins\Exceptions;

use RuntimeException;

/**
 * An install could not be completed — a failed download, a checksum mismatch, an
 * incompatible or malformed plugin, or an archive that tried to escape its
 * directory. Its message is safe to show the admin who triggered the install.
 */
class PluginInstallException extends RuntimeException {}
