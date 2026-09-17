<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/*
|--------------------------------------------------------------------------
| User Types
|--------------------------------------------------------------------------
|
| The catalog uses a flat three-role model. `owner` and `admin` manage the
| library; `member` browses and contributes to it.
|
| `super_admin` is a server-level role, kept for the account that installed
| SoundChex. Everyday administration is `owner` and `admin`.
|
*/

return [
    'options' => [
        'Administration' => [
            'super_admin' => 'Super Admin',
        ],
        'Members' => [
            'owner' => 'Owner',
            'admin' => 'Admin',
            'member' => 'Member',
            // Reaches the admin panel but sees only the upload page. Exists so
            // adding a file does not require handing over user management,
            // settings and every delete action.
            'uploader' => 'Uploader',
        ],
    ],
];
