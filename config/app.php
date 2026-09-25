<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Project links (transparency footer)
    |--------------------------------------------------------------------------
    |
    | Where the served app's footer points for the project's public
    | information — source, licence and policies.
    |
    | `repository` is also the AGPL §13 source offer: an operator running a
    | modified build must point it at *their* fork, because their users are
    | owed their source, not ours (S-401).
    |
    */

    'links' => [
        'website' => env('SOUNDCHEX_WEBSITE_URL', 'https://soundchex.app'),
        'repository' => env('SOUNDCHEX_REPO_URL', 'https://github.com/tripsittr/SoundChex'),
    ],

    /*
    |--------------------------------------------------------------------------
    | This build (S-401, S-402)
    |--------------------------------------------------------------------------
    |
    | Written at build time by `scripts/stamp-release.mjs`, so a packaged app
    | knows what it is. A checkout that was never built through that script
    | reports a dev version, which is honest rather than misleading.
    |
    | `commit` is what makes the source offer *corresponding*: builds ship
    | from `main` between releases, so two servers can report the same version
    | and be running different code. `modified` is set when the tree was dirty
    | at build time — a build nobody else can reproduce from a public commit.
    |
    */

    'version' => env('APP_VERSION', '0.0.0-dev'),
    'commit' => env('APP_COMMIT'),
    'source_url' => env('SOUNDCHEX_REPO_URL', 'https://github.com/tripsittr/SoundChex'),
    'source_modified' => env('APP_SOURCE_MODIFIED', false),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Configured Application URL
    |--------------------------------------------------------------------------
    |
    | The address this server was configured with, kept separate because the
    | SetAppUrl middleware replaces 'url' above with whichever of this server's
    | several addresses the current request arrived on. Anything needing the
    | server's own stable address reads this instead — OAuth redirect URIs
    | above all (S-322), since a service matches the redirect_uri against the
    | single one registered for the app and it cannot move with the request.
    |
    */

    'configured_url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    'dev_login_autofill' => [
        'enabled' => (bool) env('DEV_LOGIN_AUTOFILL', false),
        'email' => env('DEV_LOGIN_EMAIL', 'test@test.com'),
        'password' => env('DEV_LOGIN_PASSWORD', 'password'),
    ],

];
