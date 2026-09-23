<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers;

use App\Models\InstalledPlugin;
use App\Plugins\StyleCompiler;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a plugin's compiled stylesheet (S-350).
 *
 * The file lives in the plugin's own directory, outside the document root —
 * plugins are installed to the user's application-support directory, which is
 * deliberately not web-served. This hands it out for the one plugin named, and
 * only when that plugin is enabled.
 */
class PluginStyleController extends Controller
{
    public function __invoke(string $plugin, StyleCompiler $compiler): Response
    {
        // The id comes from the URL, so it is matched against the install table
        // rather than used to build a path. A disabled plugin serves nothing,
        // the same as it contributes nothing else.
        $installed = InstalledPlugin::query()
            ->where('plugin_id', $plugin)
            ->where('enabled', true)
            ->first();

        abort_if($installed === null, 404);

        $base = (string) config('soundchex.plugins.path');
        $directory = $base.DIRECTORY_SEPARATOR.$installed->directory;

        // `directory` is stored by the loader as a single path segment; refuse
        // anything that has since become a traversal.
        abort_if(
            $base === ''
                || str_contains((string) $installed->directory, DIRECTORY_SEPARATOR)
                || ! is_dir($directory),
            404,
        );

        $path = $compiler->compiledPath($directory);

        abort_if($path === null, 404);

        $response = (new BinaryFileResponse($path))
            ->setPrivate()
            // The file changes only when the plugin is enabled or updated, and
            // its mtime moves when it does, so a conditional request is enough.
            ->setAutoLastModified()
            ->setAutoEtag();

        // Without this the file is served as text/plain and the browser refuses
        // to apply it — the stylesheet loads and does nothing.
        $response->headers->set('Content-Type', 'text/css; charset=utf-8');

        return $response;
    }
}
