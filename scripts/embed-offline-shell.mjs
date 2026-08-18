#!/usr/bin/env node
/**
 * Copies the offline shell into the app bundle.
 *
 * The Tauri app previously shipped nothing but a connect screen: every screen,
 * including the offline shell, was served by the Laravel server and reached the
 * device only through the service worker cache. That cache is not a guarantee —
 * a fresh install has never populated it, and iOS evicts it from apps that have
 * not been opened recently, which is exactly when someone reaches for a library
 * they downloaded for that situation.
 *
 * So the assets the shell needs are copied into `public/tauri/`, which Tauri
 * ships verbatim. ~35KB of JavaScript and the stylesheet, against a 4.4MB app.
 *
 * Run after `vite build`, because it reads the manifest that build produces.
 */
import { createHash } from 'node:crypto';
import { copyFileSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';

const root = process.cwd();
const buildDir = join(root, 'public/build');
const outDir = join(root, 'public/tauri/offline');

/** Entry points the offline shell needs, by their source path in the manifest. */
const NEEDED = [
    'resources/js/library/index.js',
    'resources/css/media-center.css',
];

const manifest = JSON.parse(readFileSync(join(buildDir, 'manifest.json'), 'utf8'));

// Resolved transitively: an entry's imports are separate files, and copying
// only the entry leaves it importing something that is not there.
function collect(key, seen = new Set()) {
    const entry = manifest[key];

    if (!entry || seen.has(key)) return seen;

    seen.add(key);

    for (const imported of entry.imports ?? []) collect(imported, seen);
    for (const css of entry.css ?? []) seen.add(`css:${css}`);

    return seen;
}

const keys = new Set();

for (const needed of NEEDED) {
    for (const key of collect(needed)) keys.add(key);
}

rmSync(outDir, { recursive: true, force: true });
mkdirSync(outDir, { recursive: true });

const copied = [];

for (const key of keys) {
    const file = key.startsWith('css:') ? key.slice(4) : manifest[key]?.file;

    if (!file) continue;

    const target = join(outDir, file);

    mkdirSync(dirname(target), { recursive: true });
    copyFileSync(join(buildDir, file), target);
    copied.push(file);
}

// A manifest of its own, so the shell resolves content-hashed names from a
// local file rather than a network request that is the very thing failing.
const local = {};

for (const needed of NEEDED) {
    if (manifest[needed]) local[needed] = { file: manifest[needed].file };
}

writeFileSync(join(outDir, 'manifest.json'), JSON.stringify(local, null, 2));

// Stamp the service worker with this build, so its activate handler has an
// old cache key to delete. Without it the version stayed 'v1' forever and
// nothing was ever evicted — a stale stylesheet served alongside fresh HTML,
// which is how a page ends up referencing classes its CSS does not have.
const swPath = join(root, 'public/sw.js');
const stamp = createHash('sha1')
    .update(JSON.stringify(manifest))
    .digest('hex')
    .slice(0, 12);

writeFileSync(
    swPath,
    readFileSync(swPath, 'utf8').replace(/const VERSION = '[^']*';/, `const VERSION = '${stamp}';`),
);

// Refuse to finish having produced nothing. Tauri copies frontendDist into
// the bundle verbatim, so an empty directory ships as an app with no connect
// screen and no offline bundle — which it does without complaint, and which is
// only discoverable by unzipping the IPA. Failing here makes that a build
// error rather than a broken install.
if (copied.length === 0) {
    console.error('embed-offline-shell: nothing was embedded — refusing to leave an empty shell.');
    process.exit(1);
}

if (!existsSync(join(root, 'public/tauri/index.html'))) {
    console.error('embed-offline-shell: public/tauri/index.html is missing — the app would have no connect screen.');
    process.exit(1);
}

console.log(`  embedded ${copied.length} files, service worker stamped ${stamp}`);
