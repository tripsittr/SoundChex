<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Plugins;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Compiles a plugin's stylesheet with the bundled Tailwind CLI (S-350).
 *
 * A plugin's Blade cannot rely on the app's Tailwind. The app's CSS is built
 * ahead of release and a catalogue-installed plugin lives outside the repo, on
 * the user's machine, so its markup never existed when that bundle was
 * compiled — utility classes written in a plugin simply have no rule, and
 * nothing errors to say so. Plugins were therefore hand-writing CSS.
 *
 * This compiles the plugin's own stylesheet instead, when the plugin is
 * enabled. The author writes ordinary Tailwind; the CLI scans that plugin's
 * views and emits only what they use. Measured at well under a tenth of a
 * second for a real plugin, because it is scanning one directory rather than an
 * application.
 *
 * The result is written inside the plugin's directory as `dist/plugin.css` and
 * served by the loader. Compilation is best-effort by design: a plugin whose
 * stylesheet cannot be built keeps whatever CSS it ships, which is exactly the
 * behaviour that existed before this did.
 */
class StyleCompiler
{
    /** Where a compiled stylesheet is written, relative to the plugin root. */
    public const OUTPUT = 'dist/plugin.css';

    /** The stylesheet a plugin may provide to control its own build. */
    public const SOURCE = 'resources/css/plugin.css';

    /**
     * Compile one plugin's stylesheet.
     *
     * @return string|null The absolute path written, or null when nothing was
     *                     compiled (no views, no CLI, or the build failed).
     */
    public function compile(string $pluginDirectory, string $pluginId): ?string
    {
        if (! config('plugin-styles.enabled', true)) {
            return null;
        }

        $views = $pluginDirectory.'/resources/views';

        if (! is_dir($views)) {
            return null; // Nothing to scan: not every plugin has a UI.
        }

        $binary = (string) config('plugin-styles.binary');

        if (! $this->binaryUsable($binary)) {
            Log::info('plugin-styles: no Tailwind CLI available; plugin keeps its own CSS', [
                'plugin' => $pluginId,
                'looked_for' => $binary,
            ]);

            return null;
        }

        $input = $this->inputFor($pluginDirectory, $pluginId);

        if ($input === null) {
            return null;
        }

        $output = $pluginDirectory.'/'.self::OUTPUT;

        if (! $this->ensureDirectory(dirname($output))) {
            return null;
        }

        return $this->run($binary, $input, $output, $pluginDirectory, $pluginId);
    }

    /**
     * Whether a compiled stylesheet exists for a plugin, and where.
     */
    public function compiledPath(string $pluginDirectory): ?string
    {
        $path = $pluginDirectory.'/'.self::OUTPUT;

        return is_file($path) ? $path : null;
    }

    /**
     * Remove a plugin's compiled stylesheet — on disable, so a disabled plugin
     * leaves nothing of itself behind in the page.
     */
    public function clear(string $pluginDirectory): void
    {
        $path = $pluginDirectory.'/'.self::OUTPUT;

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * The stylesheet to compile from.
     *
     * A plugin may ship its own at `resources/css/plugin.css` — the way to add
     * `@theme`, custom rules, or extra `@source` paths. Otherwise a minimal one
     * is generated: import Tailwind, scan this plugin's views, and make the
     * app's design tokens available so a plugin can match the product's palette
     * rather than inventing its own.
     */
    private function inputFor(string $pluginDirectory, string $pluginId): ?string
    {
        $own = $pluginDirectory.'/'.self::SOURCE;

        if (is_file($own)) {
            return $own;
        }

        $generated = $pluginDirectory.'/dist/plugin.in.css';

        if (! $this->ensureDirectory(dirname($generated))) {
            return null;
        }

        $tokens = resource_path('css/tokens.css');

        $css = "@import 'tailwindcss';\n";

        if (is_file($tokens)) {
            $css .= "@import '".$this->escapeCssPath($tokens)."';\n";
        }

        $css .= "@source '".$this->escapeCssPath($pluginDirectory.'/resources/views')."';\n";
        $css .= $this->themeBlock();

        if (@file_put_contents($generated, $css) === false) {
            Log::warning('plugin-styles: could not write the generated stylesheet', [
                'plugin' => $pluginId,
                'path' => $generated,
            ]);

            return null;
        }

        return $generated;
    }

    /**
     * Maps the app's design tokens onto Tailwind colour utilities, so a plugin
     * can write `bg-sc-base-900` and get the product's palette.
     *
     * Only the tokens a plugin has any business using: surfaces, text, and the
     * accent. Deliberately not every token — a plugin reaching for the whole
     * internal palette is how a UI ends up looking like a different product.
     */
    private function themeBlock(): string
    {
        // Exactly the tokens `resources/css/tokens.css` defines — a name that
        // is not there produces a utility resolving to nothing, which is worse
        // than not offering it.
        $map = [
            'base-900', 'base-800', 'base-700', 'base-600', 'base-500',
            'ink-100', 'ink-300', 'ink-500',
            'accent', 'accent-hot',
        ];

        $lines = array_map(
            static fn (string $token): string => "  --color-sc-{$token}: var(--sc-{$token});",
            $map,
        );

        return "@theme {\n".implode("\n", $lines)."\n}\n";
    }

    /**
     * Runs the CLI. Failure is logged and swallowed: a plugin that cannot build
     * its stylesheet still loads, with whatever CSS it ships.
     */
    private function run(
        string $binary,
        string $input,
        string $output,
        string $cwd,
        string $pluginId,
    ): ?string {
        // Arguments are passed as an array, never a shell string — the paths
        // include a plugin-controlled directory name.
        $process = new Process(
            [$binary, '--input', $input, '--output', $output, '--minify'],
            $cwd,
            null,
            null,
            (float) config('plugin-styles.timeout', 10),
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            Log::warning('plugin-styles: compile timed out', ['plugin' => $pluginId]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('plugin-styles: compile could not start', [
                'plugin' => $pluginId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $process->isSuccessful() || ! is_file($output)) {
            Log::warning('plugin-styles: compile failed', [
                'plugin' => $pluginId,
                'exit' => $process->getExitCode(),
                // Tailwind reports errors on stderr; keep it short but useful.
                'error' => mb_substr(trim($process->getErrorOutput()), 0, 500),
            ]);

            return null;
        }

        Log::info('plugin-styles: compiled', [
            'plugin' => $pluginId,
            'bytes' => filesize($output) ?: 0,
        ]);

        return $output;
    }

    /** Whether the configured CLI can actually be run. */
    private function binaryUsable(string $binary): bool
    {
        if ($binary === '') {
            return false;
        }

        // An absolute path must exist and be executable; a bare name is left to
        // the process layer to resolve on PATH.
        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return is_file($binary) && is_executable($binary);
        }

        return true;
    }

    private function ensureDirectory(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }

        if (! @mkdir($path, 0o775, true) && ! is_dir($path)) {
            Log::warning('plugin-styles: could not create the output directory', ['path' => $path]);

            return false;
        }

        return true;
    }

    /** Escapes a path for use inside a CSS at-rule string. */
    private function escapeCssPath(string $path): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $path);
    }
}
