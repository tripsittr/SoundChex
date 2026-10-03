// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

//! First-run provisioning for the packaged Server app.
//!
//! The Server app bundles a PHP runtime and, since this module, the application
//! itself — as one `app-payload.zip` resource, because `vendor/` alone is over
//! 32,000 files and Tauri writes an installer entry per resource file.
//!
//! Before this, the installer carried a runtime and nothing to serve: no
//! `artisan`, no `vendor/`, no `public/`. It worked only where a checkout
//! already sat on the machine, which is why the one install path that existed
//! was a macOS script copying a locally built `.app` next to a developer's own
//! repository.
//!
//! The payload is unpacked into the app data directory rather than served from
//! the resource directory, because an installed program directory is read-only
//! while Laravel writes to `storage/`, `bootstrap/cache/`, `.env` and the SQLite
//! file on ordinary requests. Unpacking also means the framework needs no path
//! overrides, so nothing about how the application boots differs between a
//! packaged install and a checkout — which is the reason this touches no PHP.

use std::io::Read;
use std::path::{Path, PathBuf};
use std::process::Command;

/// What provisioning is doing, reported to the UI as it goes.
pub enum Progress<'a> {
    /// Unpacking: how many of the payload's entries are done.
    Unpacking { done: usize, total: usize },
    /// A named step with no useful proportion to report.
    Step(&'a str),
}

/// The bundled payload and the id naming its exact contents, if this build has
/// them. A build without them is a pre-payload bundle: provisioning is skipped
/// and its behaviour is unchanged.
pub fn payload(resource_dir: &Path) -> Option<(PathBuf, PathBuf)> {
    let archive = resource_dir.join("app-payload.zip");
    let id = resource_dir.join("app-payload.id");

    if archive.is_file() && id.is_file() {
        Some((archive, id))
    } else {
        None
    }
}

/// Make `app_dir` into a runnable checkout: unpack the payload if it is absent
/// or out of date, create the directories Laravel writes to, write an `.env`
/// carrying this install's real paths, and migrate.
///
/// Safe to call on every start — the expensive step is guarded by the payload
/// id, so a second start does the cheap checks and stops.
///
/// `progress` is called as the work advances. It is not decoration: unpacking
/// takes tens of seconds, and the first version reported nothing at all, so the
/// window sat on "Starting…" long enough to look hung. Closing it there left a
/// half-unpacked directory and no explanation.
pub fn ensure(
    resource_dir: &Path,
    app_dir: &Path,
    bin_dir: &Path,
    run_dir: &Path,
    progress: &dyn Fn(Progress),
) -> Result<(), String> {
    let Some((archive, id_file)) = payload(resource_dir) else {
        return Ok(());
    };

    let want = std::fs::read_to_string(&id_file)
        .map_err(|e| format!("cannot read the payload id: {e}"))?
        .trim()
        .to_string();

    let marker = app_dir.join(".soundchex-payload");
    let have = std::fs::read_to_string(&marker)
        .unwrap_or_default()
        .trim()
        .to_string();

    let php = Php::new(bin_dir, run_dir)?;

    if have != want {
        // Resuming and upgrading look the same on disk — a directory holding some
        // of the right files — but must behave differently. An interrupted
        // attempt at *this* payload can keep what it already wrote; an upgrade
        // must replace everything, including a file whose size did not change.
        // So the payload being attempted is recorded before the work starts.
        let attempt = app_dir.join(".soundchex-payload-partial");

        let resuming = std::fs::read_to_string(&attempt)
            .map(|previous| previous.trim() == want)
            .unwrap_or(false);

        std::fs::create_dir_all(app_dir)
            .map_err(|e| format!("cannot create {}: {e}", app_dir.display()))?;
        std::fs::write(&attempt, format!("{want}\n"))
            .map_err(|e| format!("cannot record the attempt: {e}"))?;

        unpack(&archive, app_dir, progress, resuming)?;

        // Written only after a complete unpack, so an interrupted one is retried
        // rather than mistaken for a finished install.
        std::fs::write(&marker, format!("{want}\n"))
            .map_err(|e| format!("cannot record the payload id: {e}"))?;

        let _ = std::fs::remove_file(&attempt);
    }

    writable_dirs(app_dir)?;

    // Only on a genuine upgrade. On a first install there is nothing to clear
    // and no database yet, so this ran before one existed and logged a failure
    // that looked like the cause of everything after it.
    if !have.is_empty() && have != want && app_dir.join("artisan").is_file() {
        // The previous version's compiled config, routes and views describe code
        // that has just been replaced, and Laravel would go on using them.
        // Advisory: there is nothing to clear on a first install.
        progress(Progress::Step("Clearing caches from the previous version"));
        let _ = php.artisan(app_dir, &["optimize:clear"]);
    }

    progress(Progress::Step("Writing configuration"));
    environment(app_dir)?;
    key(&php, app_dir)?;

    progress(Progress::Step("Preparing the database"));
    database(&php, app_dir)?;
    public_storage_link(&php, app_dir)?;

    // Sets APP_URL to an address of this machine, so links the server generates
    // work from another device on the network instead of pointing at localhost.
    // Advisory: a server that cannot guess its own address still serves.
    let _ = php.artisan(app_dir, &["server:detect-address"]);

    Ok(())
}

/// The bundled PHP, and on Windows the ini that makes it usable.
struct Php {
    exe: PathBuf,
    ini: Option<PathBuf>,
}

impl Php {
    fn new(bin_dir: &Path, run_dir: &Path) -> Result<Self, String> {
        let exe = if cfg!(windows) {
            bin_dir.join("php.exe")
        } else {
            bin_dir.join("php")
        };

        // The same ini the supervisor gives the server, written by the same
        // function. Provisioning runs first and migrates, so it needs
        // `pdo_sqlite` before the supervisor has written anything.
        let ini = if cfg!(windows) {
            Some(crate::supervisor::write_windows_php_ini(bin_dir, run_dir)?)
        } else {
            None
        };

        Ok(Self { exe, ini })
    }

    /// Run an artisan command from the app directory.
    ///
    /// Output is captured rather than inherited: these run with no console
    /// attached, so anything written to a terminal goes nowhere and a failure
    /// has to carry its own explanation or it arrives as a bare exit code.
    fn artisan(&self, app_dir: &Path, args: &[&str]) -> Result<String, String> {
        let mut command = Command::new(&self.exe);

        command.arg(app_dir.join("artisan")).args(args);
        command.current_dir(app_dir);
        crate::supervisor::hide_console(&mut command);

        if let Some(ini) = &self.ini {
            command.env("PHPRC", ini);
        }

        let output = command
            .output()
            .map_err(|e| format!("cannot run php artisan {}: {e}", args.join(" ")))?;

        if output.status.success() {
            return Ok(String::from_utf8_lossy(&output.stdout).into_owned());
        }

        let stderr = String::from_utf8_lossy(&output.stderr);
        let stdout = String::from_utf8_lossy(&output.stdout);
        let detail = if stderr.trim().is_empty() { stdout } else { stderr };

        Err(format!(
            "php artisan {} failed: {}",
            args.join(" "),
            detail.trim()
        ))
    }
}

/// Extract the payload over `app_dir`. Anything not in the archive is left
/// alone, which is how `storage/`, the SQLite file and `.env` survive an
/// upgrade.
///
///
/// With `resuming`, an entry already on disk at the archive's own size is left
/// alone — it was written by an earlier attempt at this same payload, so it is
/// already correct, and skipping it turns a restarted unpack into seconds.
///
/// Without it, every entry is rewritten. That is what an upgrade needs: a file
/// whose contents changed but whose size did not is otherwise never replaced,
/// and the new version quietly runs some of the old code.
fn unpack(
    archive: &Path,
    app_dir: &Path,
    progress: &dyn Fn(Progress),
    resuming: bool,
) -> Result<(), String> {
    let file =
        std::fs::File::open(archive).map_err(|e| format!("cannot open the payload: {e}"))?;

    let mut zip = zip::ZipArchive::new(file)
        .map_err(|e| format!("the payload is not a readable archive: {e}"))?;

    std::fs::create_dir_all(app_dir)
        .map_err(|e| format!("cannot create {}: {e}", app_dir.display()))?;

    let total = zip.len();

    for i in 0..total {
        // Often enough to look alive, rarely enough not to flood the webview.
        if i % 200 == 0 {
            progress(Progress::Unpacking { done: i, total });
        }

        let mut entry = zip
            .by_index(i)
            .map_err(|e| format!("cannot read payload entry {i}: {e}"))?;

        // `enclosed_name` refuses absolute paths and any `..` component, so a
        // malformed archive cannot write outside the app directory.
        let Some(relative) = entry.enclosed_name() else {
            return Err(format!("payload entry {i} has an unsafe path"));
        };

        let target = app_dir.join(&relative);

        if entry.is_dir() {
            std::fs::create_dir_all(&target)
                .map_err(|e| format!("cannot create {}: {e}", target.display()))?;
            continue;
        }

        if resuming {
            if let Ok(existing) = std::fs::metadata(&target) {
                if existing.is_file() && existing.len() == entry.size() {
                    continue;
                }
            }
        }

        if let Some(parent) = target.parent() {
            std::fs::create_dir_all(parent)
                .map_err(|e| format!("cannot create {}: {e}", parent.display()))?;
        }

        let mut bytes = Vec::with_capacity(entry.size() as usize);
        entry
            .read_to_end(&mut bytes)
            .map_err(|e| format!("cannot read {}: {e}", relative.display()))?;

        std::fs::write(&target, &bytes)
            .map_err(|e| format!("cannot write {}: {e}", target.display()))?;

        // Anything marked executable in the archive stays executable once
        // unpacked. No-op on Windows, which has no mode bits.
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;

            if let Some(mode) = entry.unix_mode() {
                let _ = std::fs::set_permissions(&target, std::fs::Permissions::from_mode(mode));
            }
        }
    }

    progress(Progress::Unpacking { done: total, total });

    Ok(())
}

/// The directories Laravel writes to. Not in the payload: shipping them would
/// mean shipping whatever the build machine happened to have in them.
fn writable_dirs(app_dir: &Path) -> Result<(), String> {
    for relative in [
        "bootstrap/cache",
        "database",
        "storage/app/private",
        "storage/app/public",
        "storage/framework/cache/data",
        "storage/framework/sessions",
        "storage/framework/views",
        "storage/logs",
    ] {
        let path = app_dir.join(relative);

        std::fs::create_dir_all(&path)
            .map_err(|e| format!("cannot create {}: {e}", path.display()))?;
    }

    Ok(())
}

/// Write the `.env` this install needs, from the shipped example.
///
/// Every value set here is one that cannot be known before install: the
/// database is an absolute path inside the app data directory, and the shipped
/// defaults are a developer's — `APP_DEBUG=true` publishes stack traces to
/// anyone who can reach the server, which for this app is the local network.
fn environment(app_dir: &Path) -> Result<(), String> {
    let env_path = app_dir.join(".env");

    if !env_path.is_file() {
        let example = app_dir.join(".env.example");

        let contents = std::fs::read_to_string(&example)
            .map_err(|e| format!("no .env.example in the payload: {e}"))?;

        std::fs::write(&env_path, contents).map_err(|e| format!("cannot write .env: {e}"))?;
    }

    let database = app_dir.join("database").join("database.sqlite");

    set_env(
        &env_path,
        &[
            ("APP_ENV", "production"),
            ("APP_DEBUG", "false"),
            ("DB_CONNECTION", "sqlite"),
            ("DB_DATABASE", &database.to_string_lossy()),
        ],
    )
}

/// Set keys in a `.env`, replacing a line that assigns the key and appending one
/// that does not exist. Deliberately not a general parser: it only has to handle
/// the file it just wrote from the shipped example.
fn set_env(env_path: &Path, pairs: &[(&str, &str)]) -> Result<(), String> {
    let contents =
        std::fs::read_to_string(env_path).map_err(|e| format!("cannot read .env: {e}"))?;

    let mut lines: Vec<String> = contents.lines().map(str::to_string).collect();

    for (key, value) in pairs {
        // Quoting a Windows path here is neither optional nor obvious.
        //
        // An unquoted value cannot contain whitespace — dotenv rejects the
        // whole file, so an install under `C:\Users\Someone Else\…` would never
        // start. But a *double-quoted* value has its escape sequences
        // processed, so the raw path `"C:\Temp\app\database.sqlite"` fails just
        // as hard, on `\T` as an unknown escape. Measured against the parser
        // rather than reasoned about: both forms were tried.
        //
        // Single quotes would sidestep the escaping, but cannot hold an
        // apostrophe, and people called O'Brien have home directories.
        let needs_quoting = value.is_empty()
            || value.contains(|c: char| c.is_whitespace())
            || value.contains('\\')
            || value.contains('"')
            || value.contains('#');

        let assignment = if needs_quoting {
            // Backslashes first: escaping the quotes afterwards inserts a
            // backslash that must not itself be doubled.
            let escaped = value.replace('\\', "\\\\").replace('"', "\\\"");

            format!("{key}=\"{escaped}\"")
        } else {
            format!("{key}={value}")
        };

        let prefix = format!("{key}=");

        match lines.iter().position(|line| line.starts_with(&prefix)) {
            Some(at) => lines[at] = assignment,
            None => lines.push(assignment),
        }
    }

    std::fs::write(env_path, lines.join("\n") + "\n")
        .map_err(|e| format!("cannot write .env: {e}"))
}

/// Generate `APP_KEY` if the shipped example left it blank. Without it every
/// encrypted cookie and session fails, which presents as being unable to log in
/// rather than as a missing key.
fn key(php: &Php, app_dir: &Path) -> Result<(), String> {
    let env_path = app_dir.join(".env");

    let contents =
        std::fs::read_to_string(&env_path).map_err(|e| format!("cannot read .env: {e}"))?;

    let has_key = contents
        .lines()
        .any(|line| line.starts_with("APP_KEY=") && line.trim_end() != "APP_KEY=");

    if has_key {
        return Ok(());
    }

    php.artisan(app_dir, &["key:generate", "--force"]).map(|_| ())
}

/// Create the SQLite file if absent, then bring the schema up to date.
///
/// Created here rather than left to the migrator: Laravel asks before creating a
/// missing SQLite database, and a prompt in a spawned process with no console is
/// a hang with nothing to explain it.
fn database(php: &Php, app_dir: &Path) -> Result<(), String> {
    let database = app_dir.join("database").join("database.sqlite");

    if !database.is_file() {
        std::fs::write(&database, [])
            .map_err(|e| format!("cannot create the database file: {e}"))?;
    }

    php.artisan(app_dir, &["migrate", "--force"]).map(|_| ())
}

/// Point `public/storage` at `storage/app/public` so stored images are served.
///
/// `storage:link` makes a symbolic link, which on Windows needs administrator
/// rights or developer mode — neither of which a library on someone's desktop
/// can assume. A directory junction needs no privileges and is followed by both
/// Explorer and PHP.
fn public_storage_link(php: &Php, app_dir: &Path) -> Result<(), String> {
    let link = app_dir.join("public").join("storage");

    if link.exists() {
        return Ok(());
    }

    let target = app_dir.join("storage").join("app").join("public");

    if cfg!(windows) {
        let mut command = Command::new("cmd");

        command
            .arg("/C")
            .arg("mklink")
            .arg("/J")
            .arg(&link)
            .arg(&target);

        crate::supervisor::hide_console(&mut command);

        let status = command
            .status()
            .map_err(|e| format!("cannot run mklink: {e}"))?;

        if !status.success() {
            return Err("could not create the public/storage junction".into());
        }

        return Ok(());
    }

    php.artisan(app_dir, &["storage:link"]).map(|_| ())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::Write;
    use std::sync::atomic::{AtomicU32, Ordering};

    static COUNTER: AtomicU32 = AtomicU32::new(0);

    /// A unique empty directory. No `tempfile` dependency for a handful of
    /// tests; the directory is left behind on failure, which is useful.
    fn scratch(tag: &str) -> PathBuf {
        let n = COUNTER.fetch_add(1, Ordering::SeqCst);
        let dir = std::env::temp_dir().join(format!("scx-provision-{tag}-{}-{n}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        dir
    }

    /// Build a zip holding the given (path, contents) entries, stored uncompressed.
    fn zip_with(dir: &Path, entries: &[(&str, &str)]) -> PathBuf {
        let path = dir.join("payload.zip");
        let file = std::fs::File::create(&path).unwrap();
        let mut writer = zip::ZipWriter::new(file);
        let options: zip::write::FileOptions<'_, ()> =
            zip::write::FileOptions::default().compression_method(zip::CompressionMethod::Stored);

        for (name, contents) in entries {
            writer.start_file(*name, options).unwrap();
            writer.write_all(contents.as_bytes()).unwrap();
        }

        writer.finish().unwrap();

        path
    }

    #[test]
    fn unpack_writes_nested_files() {
        let dir = scratch("nested");
        let archive = zip_with(&dir, &[("artisan", "#!/usr/bin/env php"), ("app/Models/User.php", "<?php")]);
        let app = dir.join("app-dir");

        unpack(&archive, &app, &|_| {}, false).unwrap();

        assert_eq!(std::fs::read_to_string(app.join("artisan")).unwrap(), "#!/usr/bin/env php");
        assert_eq!(std::fs::read_to_string(app.join("app/Models/User.php")).unwrap(), "<?php");
    }

    /// The upgrade path. An unpack replaces code and must not touch the library
    /// database, the `.env` or anything under `storage/` — none of which the
    /// payload contains, so the guarantee is that unpack only writes entries.
    #[test]
    fn unpack_leaves_state_that_is_not_in_the_payload() {
        let dir = scratch("upgrade");
        let app = dir.join("app-dir");

        std::fs::create_dir_all(app.join("database")).unwrap();
        std::fs::create_dir_all(app.join("storage/app/public")).unwrap();
        std::fs::write(app.join("database/database.sqlite"), b"the library").unwrap();
        std::fs::write(app.join("storage/app/public/cover.jpg"), b"artwork").unwrap();
        std::fs::write(app.join(".env"), "APP_KEY=base64:kept\n").unwrap();
        std::fs::write(app.join("artisan"), "old").unwrap();

        let archive = zip_with(&dir, &[("artisan", "new"), ("app/New.php", "<?php")]);

        unpack(&archive, &app, &|_| {}, false).unwrap();

        assert_eq!(std::fs::read_to_string(app.join("artisan")).unwrap(), "new");
        assert_eq!(std::fs::read_to_string(app.join("database/database.sqlite")).unwrap(), "the library");
        assert_eq!(std::fs::read_to_string(app.join("storage/app/public/cover.jpg")).unwrap(), "artwork");
        assert_eq!(std::fs::read_to_string(app.join(".env")).unwrap(), "APP_KEY=base64:kept\n");
    }

    /// A payload naming `../something` must not be able to write outside the
    /// app directory. The archive here is hand-made; a real one never does this.
    #[test]
    fn unpack_refuses_an_entry_that_escapes_the_app_dir() {
        let dir = scratch("escape");
        let archive = zip_with(&dir, &[("../escaped.txt", "should never be written")]);
        let app = dir.join("app-dir");

        let error = unpack(&archive, &app, &|_| {}, false).unwrap_err();

        assert!(error.contains("unsafe path"), "unexpected error: {error}");
        assert!(!dir.join("escaped.txt").exists(), "the entry escaped the app dir");
    }

    #[test]
    fn set_env_replaces_an_existing_key_and_appends_a_missing_one() {
        let dir = scratch("env");
        let env_path = dir.join(".env");
        std::fs::write(&env_path, "APP_ENV=local\nAPP_DEBUG=true\n").unwrap();

        set_env(&env_path, &[("APP_ENV", "production"), ("DB_CONNECTION", "sqlite")]).unwrap();

        let written = std::fs::read_to_string(&env_path).unwrap();
        let lines: Vec<&str> = written.lines().collect();

        assert!(lines.contains(&"APP_ENV=production"), "{written}");
        assert!(lines.contains(&"APP_DEBUG=true"), "{written}");
        assert!(lines.contains(&"DB_CONNECTION=sqlite"), "{written}");
        assert!(!lines.contains(&"APP_ENV=local"), "the old value survived: {written}");
    }

    /// A Windows database path needs quoting for its spaces *and* escaping for
    /// its backslashes.
    ///
    /// This test first asserted the unescaped form, which is what the code then
    /// wrote — and dotenv refused the file with "unexpected escape sequence",
    /// taking every install down at `key:generate`. The expected value below is
    /// what the parser actually accepts, checked against it directly.
    #[test]
    fn set_env_escapes_backslashes_in_a_quoted_windows_path() {
        let dir = scratch("quote");
        let env_path = dir.join(".env");
        std::fs::write(&env_path, "DB_DATABASE=\n").unwrap();

        set_env(&env_path, &[("DB_DATABASE", r"C:\Users\Someone Else\AppData\db.sqlite")]).unwrap();

        let written = std::fs::read_to_string(&env_path).unwrap();

        assert!(
            written.contains(r#"DB_DATABASE="C:\\Users\\Someone Else\\AppData\\db.sqlite""#),
            "backslashes must be doubled inside double quotes: {written}"
        );
    }

    /// A value with no whitespace, backslash, quote or comment marker is left
    /// bare, so the file stays readable rather than quoting everything.
    #[test]
    fn set_env_leaves_a_plain_value_unquoted() {
        let dir = scratch("plain");
        let env_path = dir.join(".env");
        std::fs::write(&env_path, "APP_ENV=local\n").unwrap();

        set_env(&env_path, &[("APP_ENV", "production")]).unwrap();

        assert_eq!(std::fs::read_to_string(&env_path).unwrap(), "APP_ENV=production\n");
    }

    #[test]
    fn writable_dirs_creates_what_laravel_writes_to() {
        let dir = scratch("writable");

        writable_dirs(&dir).unwrap();

        for expected in [
            "bootstrap/cache",
            "database",
            "storage/app/public",
            "storage/framework/sessions",
            "storage/framework/views",
            "storage/logs",
        ] {
            assert!(dir.join(expected).is_dir(), "missing {expected}");
        }
    }

    /// An upgrade must replace a file whose contents changed but whose size did
    /// not. The resumability shortcut originally skipped exactly this case, and
    /// `unpack_leaves_state_that_is_not_in_the_payload` caught it: three bytes
    /// of old code survived an unpack that was supposed to replace them.
    #[test]
    fn unpack_replaces_a_same_size_file_when_not_resuming() {
        let dir = scratch("same-size");
        let app = dir.join("app-dir");
        std::fs::create_dir_all(&app).unwrap();
        std::fs::write(app.join("artisan"), "old").unwrap();

        let archive = zip_with(&dir, &[("artisan", "new")]);

        unpack(&archive, &app, &|_| {}, false).unwrap();

        assert_eq!(std::fs::read_to_string(app.join("artisan")).unwrap(), "new");
    }

    /// Resuming the same payload keeps what an earlier attempt already wrote,
    /// which is what makes a restarted first run quick instead of starting over.
    #[test]
    fn unpack_keeps_a_same_size_file_when_resuming() {
        let dir = scratch("resume");
        let app = dir.join("app-dir");
        std::fs::create_dir_all(&app).unwrap();
        std::fs::write(app.join("artisan"), "old").unwrap();

        let archive = zip_with(&dir, &[("artisan", "new")]);

        unpack(&archive, &app, &|_| {}, true).unwrap();

        assert_eq!(
            std::fs::read_to_string(app.join("artisan")).unwrap(),
            "old",
            "a resume should not rewrite what is already the right size"
        );
    }

    /// Progress has to reach the end, or a UI driven by it stops short of 100%
    /// and looks stuck at the moment it actually finished.
    #[test]
    fn unpack_reports_progress_through_to_the_total() {
        use std::cell::RefCell;

        let dir = scratch("progress");
        let app = dir.join("app-dir");
        let archive = zip_with(&dir, &[("a.php", "1"), ("b.php", "2"), ("c.php", "3")]);

        let seen = RefCell::new(Vec::new());

        unpack(&archive, &app, &|progress| {
            if let Progress::Unpacking { done, total } = progress {
                seen.borrow_mut().push((done, total));
            }
        }, false)
        .unwrap();

        let seen = seen.into_inner();

        assert_eq!(seen.first(), Some(&(0usize, 3usize)), "{seen:?}");
        assert_eq!(seen.last(), Some(&(3usize, 3usize)), "{seen:?}");
    }

    /// A build with no payload is a pre-payload bundle, and provisioning must do
    /// nothing at all rather than fail.
    #[test]
    fn ensure_is_a_no_op_without_a_payload() {
        let resources = scratch("no-payload");
        let app = scratch("no-payload-app");

        ensure(&resources, &app, &resources, &resources, &|_| {}).unwrap();

        assert!(payload(&resources).is_none());
        assert!(!app.join("artisan").exists());
    }
}
