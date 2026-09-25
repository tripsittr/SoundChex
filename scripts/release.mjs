#!/usr/bin/env node
/**
 * Cuts a release: bumps the version, stamps it everywhere, and tags it (S-402).
 *
 * The desktop app had no tags at all, which is why it could not name the
 * source it was running (S-401). Tagging by hand is the kind of step that gets
 * skipped, and a skipped tag is a build whose §13 offer points at nothing in
 * particular — so it is one command that does the whole thing or refuses.
 *
 *   npm run release -- minor      # 0.2.0 → 0.3.0, needs a name
 *   npm run release -- patch      # 0.2.0 → 0.2.1, keeps the name
 *   npm run release -- 0.4.0      # an explicit version
 *
 * It refuses on a dirty tree, on a minor with no name in `AppRelease::NAMES`,
 * and on a version that is already tagged. Each of those produces a release
 * nobody can trace back to source, which is the thing this exists to prevent.
 */

import { execSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

function git(command, options = {}) {
    return execSync(`git ${command}`, { cwd: root, stdio: ['ignore', 'pipe', 'pipe'], ...options })
        .toString()
        .trim();
}

function fail(message) {
    console.error(`\n  ${message}\n`);
    process.exit(1);
}

const pkgPath = resolve(root, 'package.json');
const pkg = JSON.parse(readFileSync(pkgPath, 'utf8'));
const current = pkg.version;

const argument = process.argv[2];

if (!argument) {
    fail('Usage: npm run release -- <major|minor|patch|x.y.z>');
}

function next(version, kind) {
    const [major, minor, patch] = version.split('.').map(Number);

    switch (kind) {
        case 'major': return `${major + 1}.0.0`;
        case 'minor': return `${major}.${minor + 1}.0`;
        case 'patch': return `${major}.${minor}.${patch + 1}`;
        default:
            if (!/^\d+\.\d+\.\d+$/.test(kind)) {
                fail(`"${kind}" is not major, minor, patch or a x.y.z version.`);
            }

            return kind;
    }
}

const version = next(current, argument);

// A release built from a dirty tree cannot be reproduced from its own tag,
// which makes the tag a lie about what shipped.
if (git('status --porcelain') !== '') {
    fail('The working tree has uncommitted changes. Commit or stash them first.');
}

if (git('tag --list').split('\n').includes(`v${version}`)) {
    fail(`v${version} is already tagged.`);
}

/**
 * Every shipped minor must have a name.
 *
 * The phone shipped nine minors whose names lived only in the changelog, so
 * Settings showed a bare number while the release notes called them something
 * else (S-380). Checking here is what stops that happening twice.
 */
const releaseSource = readFileSync(resolve(root, 'app/Support/AppRelease.php'), 'utf8');
const minor = version.split('.').slice(0, 2).join('.');

if (!releaseSource.includes(`'${minor}' =>`)) {
    fail(
        `Version ${version} has no release name.\n`
        + `  Add a '${minor}' => 'Some Film Term' row to AppRelease::NAMES\n`
        + '  (desktop names are film terms; the phone uses music terms),\n'
        + '  and record it in docs/Versioning.md.',
    );
}

pkg.version = version;
writeFileSync(pkgPath, `${JSON.stringify(pkg, null, 4)}\n`);

execSync('node scripts/stamp-release.mjs', { cwd: root, stdio: 'inherit' });

git(`add package.json src-tauri/tauri.conf.json src-tauri/Cargo.toml`);
git(`commit -m "Release ${version}"`);
git(`tag -a v${version} -m "${version}"`);

console.log(`\n  Tagged v${version}. Push it with:\n`);
console.log('    git push && git push --tags\n');
