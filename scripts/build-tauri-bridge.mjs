#!/usr/bin/env node
/**
 * Builds the Tauri API bridge injected into the mobile webview's every frame.
 *
 * The app is served from a remote origin, and Tauri v2 does not inject
 * `window.__TAURI__` into remote pages — `remote.urls` in the capability only
 * authorises the commands, it does not put the bridge on the page. So without
 * this the web app had no `invoke` and every download fell back to IndexedDB.
 * This is the IIFE build of @tauri-apps/api's core, assigned to
 * `window.__TAURI__`, injected from lib.rs via initialization_script_for_all_frames.
 *
 * Uses Vite's build API (a direct dependency; esbuild is not). Entry and output
 * both sit under node_modules/.tauri-bridge so module resolution works (the
 * project's node_modules is in scope) and nothing lands in src-tauri/assets but
 * the one file we copy in.
 */
import { build } from 'vite';
import { mkdirSync, writeFileSync, rmSync, copyFileSync } from 'node:fs';
import { join } from 'node:path';

const workDir = 'node_modules/.tauri-bridge';
const entryPath = join(workDir, 'entry.js');

rmSync(workDir, { recursive: true, force: true });
mkdirSync(workDir, { recursive: true });

writeFileSync(entryPath, `
import * as core from '@tauri-apps/api/core';

if (typeof window !== 'undefined') {
    const prev = window.__TAURI__ || {};
    window.__TAURI__ = Object.assign({}, prev, {
        core: Object.assign({}, prev.core, core),
    });
}
`);

try {
    await build({
        configFile: false,
        logLevel: 'error',
        build: {
            lib: {
                entry: entryPath,
                formats: ['iife'],
                name: '__tauriBridge',
                fileName: () => 'tauri-bridge.iife.js',
            },
            outDir: workDir,
            emptyOutDir: false,
            minify: true,
        },
    });

    mkdirSync('src-tauri/assets', { recursive: true });
    copyFileSync(join(workDir, 'tauri-bridge.iife.js'), 'src-tauri/assets/tauri-bridge.iife.js');

    console.log('  built src-tauri/assets/tauri-bridge.iife.js');
} finally {
    rmSync(workDir, { recursive: true, force: true });
}
