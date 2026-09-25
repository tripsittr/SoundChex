#!/usr/bin/env node
/**
 * Stamps this build with the version and the commit it came from (S-401).
 *
 * Until this existed, a running SoundChex could not say what it was:
 * `tauri.conf.json` was pinned at 0.1.0 and the repository had no tags. That
 * is a licence problem as much as an untidiness. AGPL §13 asks a
 * network-hosted build to offer its users *its own* corresponding source, and
 * a link to `main` is not that — builds ship from `main` between releases, so
 * two servers can report the same version and be running different code.
 *
 * So the version comes from one place (`package.json`), and the commit is
 * recorded alongside it. Together they name exactly one tree.
 *
 * Writes:
 *   - `src-tauri/tauri.conf.json`  — the version Tauri packages and the
 *     updater compares against.
 *   - `src-tauri/Cargo.toml`       — kept in step so the crate and the bundle
 *     never disagree.
 *   - `.env`                       — APP_VERSION / APP_COMMIT /
 *     APP_SOURCE_MODIFIED for the PHP side to read.
 *
 * Run by `npm run build` and by the release scripts, so a packaged app is
 * always stamped and a plain checkout is honestly unstamped.
 */

import { execSync } from 'node:child_process';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

function git(command) {
    try {
        return execSync(`git ${command}`, { cwd: root, stdio: ['ignore', 'pipe', 'ignore'] })
            .toString()
            .trim();
    } catch {
        // A tarball with no .git, or git not installed. Not fatal: the build
        // should still produce something, it just cannot claim a commit.
        return '';
    }
}

/** The version every artefact takes. One source, so they cannot drift. */
function version() {
    const pkg = JSON.parse(readFileSync(resolve(root, 'package.json'), 'utf8'));

    if (!pkg.version) {
        throw new Error('package.json has no "version" — the build cannot name itself.');
    }

    return pkg.version;
}

/**
 * Whether the working tree differs from the commit.
 *
 * A dirty build is one nobody else can reproduce from a public commit, so the
 * About page says so rather than linking a tree that is not what is running.
 */
function isDirty() {
    return git('status --porcelain') !== '';
}

function stampTauriConf(nextVersion) {
    const path = resolve(root, 'src-tauri/tauri.conf.json');
    const conf = JSON.parse(readFileSync(path, 'utf8'));

    if (conf.version === nextVersion) return false;

    conf.version = nextVersion;
    writeFileSync(path, `${JSON.stringify(conf, null, 2)}\n`);

    return true;
}

function stampCargo(nextVersion) {
    const path = resolve(root, 'src-tauri/Cargo.toml');
    const cargo = readFileSync(path, 'utf8');

    // Only the package's own version, which is the first `version =` under
    // [package] — a blunt global replace would rewrite every dependency pin.
    const stamped = cargo.replace(
        /^(\[package\][\s\S]*?^version\s*=\s*)"[^"]*"/m,
        `$1"${nextVersion}"`,
    );

    if (stamped === cargo) return false;

    writeFileSync(path, stamped);

    return true;
}

/**
 * Sets a key in `.env`, adding it if absent.
 *
 * Rewrites in place rather than appending blindly, so running the build twice
 * does not leave two APP_VERSION lines with the loser winning.
 */
function setEnv(lines, key, value) {
    const index = lines.findIndex((line) => line.startsWith(`${key}=`));
    const entry = `${key}=${value}`;

    if (index === -1) {
        lines.push(entry);
    } else {
        lines[index] = entry;
    }

    return lines;
}

function stampEnv(nextVersion, commit, dirty) {
    const path = resolve(root, '.env');

    if (!existsSync(path)) {
        console.log('  no .env — skipping the PHP-side stamp.');

        return;
    }

    let lines = readFileSync(path, 'utf8').split('\n');

    lines = setEnv(lines, 'APP_VERSION', nextVersion);
    lines = setEnv(lines, 'APP_COMMIT', commit || '');
    lines = setEnv(lines, 'APP_SOURCE_MODIFIED', dirty ? 'true' : 'false');

    writeFileSync(path, lines.join('\n'));
}

const nextVersion = version();
const commit = git('rev-parse --short HEAD');
const dirty = isDirty();

const changed = [
    stampTauriConf(nextVersion) && 'tauri.conf.json',
    stampCargo(nextVersion) && 'Cargo.toml',
].filter(Boolean);

stampEnv(nextVersion, commit, dirty);

const described = commit
    ? `${nextVersion} (${commit}${dirty ? ', modified' : ''})`
    : `${nextVersion} (no commit — not a git checkout)`;

console.log(`  stamped ${described}${changed.length ? ` → ${changed.join(', ')}` : ''}`);
