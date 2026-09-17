<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Enums;

enum MediaTagSource: string
{
    case Api = 'api';
    case File = 'file';
    case Manual = 'manual';
}
