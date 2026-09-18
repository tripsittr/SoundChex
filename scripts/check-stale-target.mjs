// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

// Guards against S-127: a repo move bakes the OLD absolute path into cargo's
// `src-tauri/target` cache, and each target directory only breaks when it is
// next built — surfacing as a misleading Tauri error that names a permissions
// file (`... app_hide.toml: No such file`), not a stale cache. Anyone hitting it
// reads it as a plugin problem and loses an afternoon.
//
// This runs before a build: it looks at cargo's own recorded paths and, if any
// point at a directory that no longer exists (i.e. the repo has moved since that
// target was built), it says exactly that and names the target to delete —
// rather than letting the build fail with someone else's error message.

import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const targetDir = resolve(here, '..', 'src-tauri', 'target');

if (!existsSync(targetDir)) {
  // No cache yet — nothing to be stale. A clean build.
  process.exit(0);
}

/**
 * Cargo records the absolute path it was built at in `.rustc_info.json` at the
 * target root, and in per-target `CACHEDIR.TAG`/fingerprint metadata. The most
 * reliable single signal is `.rustc_info.json`, which embeds the rustc and the
 * build path. We scan it plus each per-target subdir's fingerprint dir for an
 * absolute /Users/... path whose directory no longer exists.
 */
const staleTargets = new Set();

function pathsIn(file) {
  try {
    const text = readFileSync(file, 'utf8');
    // Absolute macOS/Linux paths that mention this project by name, so we don't
    // flag unrelated system paths.
    const matches = text.match(/\/(?:Users|home)\/[^"'\s]*SoundChex[^"'\s]*/g) || [];
    return matches;
  } catch {
    return [];
  }
}

// The build path SoundChex should be at, now.
const projectRoot = resolve(here, '..');

// Scan the target root's rustc info and each immediate subdirectory.
const candidates = [join(targetDir, '.rustc_info.json')];
try {
  for (const entry of readdirSync(targetDir, { withFileTypes: true })) {
    if (entry.isDirectory()) {
      candidates.push(join(targetDir, entry.name, '.fingerprint'));
    }
  }
} catch {
  /* target dir unreadable — let the real build report it */
}

for (const candidate of candidates) {
  if (!existsSync(candidate)) continue;

  // For a fingerprint directory, scan a couple of its files rather than all —
  // one stale reference is enough to condemn the whole target.
  let files = [candidate];
  try {
    const stat = readdirSync(candidate, { withFileTypes: true });
    files = stat
      .filter((e) => e.isFile())
      .slice(0, 8)
      .map((e) => join(candidate, e.name));
  } catch {
    /* candidate is a file, not a dir — scan it directly */
  }

  for (const file of files) {
    for (const p of pathsIn(file)) {
      // A recorded path that is neither the current project root nor an existing
      // directory means this target was built somewhere that no longer exists.
      const dir = p.split('/src-tauri')[0];
      if (dir && dir !== projectRoot && !existsSync(dir)) {
        // Name the target directory (the immediate child of target/), which is
        // what the user should delete.
        const rel = candidate.replace(targetDir + '/', '');
        staleTargets.add(rel.split('/')[0] || rel);
      }
    }
  }
}

if (staleTargets.size > 0) {
  const targets = [...staleTargets];
  console.error('');
  console.error('  ✗ Stale cargo build cache (S-127)');
  console.error('');
  console.error('    src-tauri/target holds a build from a path that no longer');
  console.error('    exists (the repo was moved). Cargo will fail with a');
  console.error('    misleading Tauri error naming a permissions file, e.g.');
  console.error('      "failed to read plugin permissions: … app_hide.toml: No such file"');
  console.error('    That is NOT a plugin problem — it is this stale cache.');
  console.error('');
  console.error('    Delete the affected target(s) and rebuild:');
  for (const t of targets) {
    console.error(`      rm -rf "src-tauri/target/${t}"`);
  }
  console.error('');
  process.exit(1);
}

process.exit(0);
