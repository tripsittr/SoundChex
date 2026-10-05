<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/**
 * Pack the Laravel application into src-tauri/app-payload.zip, which the
 * Server app bundles as a single resource and unpacks on first run.
 *
 * Why an archive rather than Tauri resource entries: vendor/ alone is over
 * 32,000 files, and Tauri writes one installer line per resource file. A
 * 32,000-line installer script is slow to generate on Windows and hostile to
 * every packager. One file is also exactly what first-run provisioning wants,
 * since the application has to be copied somewhere writable anyway --
 * bootstrap/cache, storage/ and the SQLite file are all written at runtime and
 * an installed program directory is read-only.
 *
 * Run by `beforeBundleCommand` in tauri.server.conf.json. PHP does the work
 * because this project cannot be built without PHP, and ZipArchive behaves the
 * same on macOS, Linux and Windows -- unlike the shell, where the Windows
 * runner has no `zip` and Compress-Archive takes minutes over a tree this size.
 */
$root = dirname(__DIR__);
$out = $root.'/src-tauri/app-payload.zip';

/**
 * What the application needs in order to run. Anything not named here is not
 * shipped, which is the safe direction: a missing file is a loud error on
 * first run, an unexpected one may be somebody's library.
 */
$include = [
    'artisan',
    'composer.json',
    'composer.lock',
    '.env.example',
    'app',
    'bootstrap/app.php',
    'bootstrap/providers.php',
    'config',
    'database/factories',
    'database/migrations',
    'database/seeders',
    'public',
    'resources',
    'routes',
    'vendor',
];

/**
 * Excluded even where it sits inside an included directory.
 *
 * `database/*.sqlite` is the one that matters: that file is the user's library
 * on the machine this is built from. It is also excluded structurally above
 * (database/ is not included wholesale), so this is the second lock on the
 * same door.
 */
$excludePatterns = [
    '#^storage/#',
    '#^bootstrap/cache/#',
    '#^database/.*\.sqlite(-shm|-wal)?$#',
    '#(^|/)\.env$#',
    '#(^|/)\.git(/|$)#',
    '#(^|/)node_modules(/|$)#',
    '#(^|/)\.DS_Store$#',
    '#^public/storage(/|$)#',   // a symlink to storage/app/public, remade at run time
    '#^public/hot$#',           // vite dev server marker
    '#(^|/)tests(/|$)#',

    // A vendor inside vendor is never something Composer produces -- it is a
    // stray copy. One of these went unnoticed here and doubled the payload:
    // 16,223 extra files, which doubled the first-run unpack the user waits
    // through. Excluded rather than trusted, and reported below.
    '#^vendor/vendor(/|$)#',
];

function excluded(string $relative, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $relative) === 1) {
            return true;
        }
    }

    return false;
}

/** @return list<string> repo-relative paths of real files */
function collect(string $root, string $entry, array $excludePatterns): array
{
    $absolute = $root.'/'.$entry;

    if (! file_exists($absolute)) {
        fwrite(STDERR, "missing from the checkout: {$entry}\n");
        exit(1);
    }

    if (is_file($absolute)) {
        return excluded($entry, $excludePatterns) ? [] : [$entry];
    }

    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        // Symlinks are not followed and not recorded: the only one in the
        // tree is public/storage, which first-run provisioning recreates
        // pointing at the installed location.
        if ($file->isLink() || ! $file->isFile()) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        if (! excluded($relative, $excludePatterns)) {
            $files[] = $relative;
        }
    }

    return $files;
}

$files = [];

foreach ($include as $entry) {
    $files = array_merge($files, collect($root, $entry, $excludePatterns));
}

sort($files);

// Nothing below this line should be able to ship a database. Checked rather
// than trusted, because the cost of being wrong is publishing someone's
// library in an installer.
foreach ($files as $relative) {
    if (preg_match('#\.sqlite(-shm|-wal)?$#', $relative) === 1) {
        fwrite(STDERR, "refusing to package a database file: {$relative}\n");
        exit(1);
    }
}

if (is_dir($root.'/vendor/phpunit')) {
    fwrite(STDERR, "warning: vendor/ contains dev dependencies; a release should run composer install --no-dev\n");
}

// Excluded above, but say so: it is invisible in a file count and the checkout
// it sits in is probably wrong in other ways too.
if (is_dir($root.'/vendor/vendor')) {
    fwrite(STDERR, "warning: vendor/vendor exists and was excluded -- a stray copy of vendor/, safe to delete\n");
}

@unlink($out);

$zip = new ZipArchive;

if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "could not create {$out}\n");
    exit(1);
}

foreach ($files as $relative) {
    $zip->addFile($root.'/'.$relative, $relative);
}

if (! $zip->close()) {
    fwrite(STDERR, "could not write {$out}\n");
    exit(1);
}

/*
 * A hash of the finished archive, bundled beside it as its own small resource.
 *
 * First-run provisioning stores this in the unpacked directory and re-extracts
 * when it differs, which is how an upgrade replaces the code. Hashing the
 * archive rather than the file list is deliberate: an edit that changes no
 * filename would otherwise look identical, and the upgrade would silently keep
 * running the old code. Reading the hash costs nothing at start-up, which is
 * why it is computed here and not there.
 */
file_put_contents($root.'/src-tauri/app-payload.id', hash_file('sha256', $out)."\n");

printf(
    "app-payload.zip: %d files, %.1f MB\n",
    count($files),
    filesize($out) / 1024 / 1024
);
