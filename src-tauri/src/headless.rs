// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

//! `soundchex-server --headless` (or `soundchex-server serve`): the bundled
//! stack with no window.
//!
//! For a machine nobody logs into. The window is the supervisor on a desktop,
//! which means the library stops at the login screen after every reboot; a
//! service manager (runit on HatCOS) can run this instead and keep it up.
//!
//! Deliberately the same code as the window, not a second implementation:
//! the resource directory comes from Tauri's own resolver, the data directory
//! is the one the window uses, and provisioning, config rendering and the
//! supervisor are shared. A headless install and a GUI install of the same user
//! are the same install, which is what lets the window find this one running.
//!
//! In the foreground, logging to stdout/stderr, until SIGTERM/SIGINT/SIGHUP —
//! which is what runit expects of a `run` script's `exec`.

#![cfg(desktop)]

use std::path::{Path, PathBuf};

use crate::{provision, supervisor};

/// What a running headless stack says about itself (pid, listen address), for
/// the window to show. Describes the stack; does not decide whether one runs —
/// `LOCK_FILE` does that.
pub const STATE_FILE: &str = "headless.json";

/// Held with an exclusive `flock` for the whole life of a headless process.
///
/// This is what "a headless stack is running" means. It used to be the pid in
/// `STATE_FILE` being alive, which a power cut or a SIGKILL left behind — and
/// on the next boot pids come out in nearly the same order, so the stale pid
/// was very likely some other live process. Headless then refused to start,
/// runit restarted it every second, and the library stayed down until someone
/// deleted the file. The kernel drops a lock when its holder dies, however it
/// dies, and a reused pid cannot hold it.
pub const LOCK_FILE: &str = "headless.lock";

/// The runit service name HatCOS installs, and the one `sv` is asked about.
pub const RUNIT_SERVICE: &str = "soundchex-server";

const USAGE: &str = "\
Usage: soundchex-server --headless [--listen ADDR] [--data-dir DIR]
       soundchex-server serve      [--listen ADDR] [--data-dir DIR]

Runs the SoundChex server (php-fpm, Caddy, queue worker, scheduler) with no
window, in the foreground, logging to stdout/stderr. SIGTERM, SIGINT or SIGHUP
stops all of it cleanly.

  --listen ADDR    address Caddy binds (default :8000, or $SOUNDCHEX_LISTEN)
  --data-dir DIR   where the app, database and run files live (default: the
                   same directory the SoundChex Server window uses, or
                   $SOUNDCHEX_DATA_DIR)
";

#[derive(Debug, PartialEq)]
pub struct Options {
    pub listen: String,
    pub data_dir: Option<PathBuf>,
}

/// What the command line asked for.
#[derive(Debug, PartialEq)]
pub enum Invocation {
    /// No headless flag: open the window as always.
    Window,
    Headless(Options),
    Help,
    /// A headless invocation that could not be understood.
    Invalid(String),
}

/// Parse argv (without the program name).
///
/// Anything unrecognised is refused rather than ignored once headless was
/// asked for: a typo in a service's run script should fail loudly at the first
/// start, not serve on a port nobody chose.
pub fn parse(args: &[String]) -> Invocation {
    let headless = args
        .iter()
        .any(|a| a == "--headless" || a == "serve");

    if !headless {
        // `soundchex-server --help` is what anyone writing a run script tries
        // first. Read as "open the window", it aborted on GTK with no display
        // — which is how CI's first check of the package failed.
        if args.len() == 1 && (args[0] == "--help" || args[0] == "-h") {
            return Invocation::Help;
        }

        return Invocation::Window;
    }

    let mut listen = std::env::var("SOUNDCHEX_LISTEN")
        .ok()
        .filter(|v| !v.is_empty())
        .unwrap_or_else(|| ":8000".to_string());
    let mut data_dir = std::env::var_os("SOUNDCHEX_DATA_DIR")
        .filter(|v| !v.is_empty())
        .map(PathBuf::from);

    let mut iter = args.iter();

    while let Some(arg) = iter.next() {
        let (key, inline) = match arg.split_once('=') {
            Some((k, v)) if k.starts_with("--") => (k, Some(v.to_string())),
            _ => (arg.as_str(), None),
        };

        match key {
            "--headless" | "serve" => {}
            "-h" | "--help" => return Invocation::Help,
            "--listen" | "--data-dir" => {
                let Some(value) = inline.or_else(|| iter.next().cloned()) else {
                    return Invocation::Invalid(format!("{key} needs a value"));
                };

                if value.is_empty() {
                    return Invocation::Invalid(format!("{key} needs a value"));
                }

                if key == "--listen" {
                    listen = value;
                } else {
                    data_dir = Some(PathBuf::from(value));
                }
            }
            other => return Invocation::Invalid(format!("unknown argument: {other}")),
        }
    }

    if port_of(&listen).is_none() {
        return Invocation::Invalid(format!("--listen {listen:?} has no port"));
    }

    Invocation::Headless(Options { listen, data_dir })
}

/// The port of a Caddy listen address — `:8000`, `0.0.0.0:8000`, `[::1]:8000`.
pub fn port_of(listen: &str) -> Option<u16> {
    listen.rsplit_once(':')?.1.parse().ok().filter(|p| *p != 0)
}

/// The directory Tauri's `app_data_dir()` resolves to, without a running app.
///
/// Tauri uses `dirs::data_dir()` joined with the bundle identifier; this is
/// the same rule, written out because headless mode must not start the GUI
/// toolkit (there is no display under runit) and so has no `AppHandle` to ask.
/// HOME comes from the passwd entry when the environment has none, which is
/// the usual state of a process started by a service manager.
pub fn default_data_dir(identifier: &str) -> Result<PathBuf, String> {
    let home = std::env::var_os("HOME")
        .filter(|h| !h.is_empty())
        .map(PathBuf::from)
        .or_else(supervisor::current_user_home)
        .ok_or("no home directory for this user; pass --data-dir")?;

    let base = if cfg!(target_os = "macos") {
        home.join("Library").join("Application Support")
    } else {
        // XDG says a relative value is invalid and must be ignored.
        std::env::var_os("XDG_DATA_HOME")
            .map(PathBuf::from)
            .filter(|p| p.is_absolute())
            .unwrap_or_else(|| home.join(".local").join("share"))
    };

    Ok(base.join(identifier))
}

/// Run headless. Returns the process exit code.
pub fn run(options: Options, context: &tauri::Context<tauri::Wry>) -> i32 {
    match serve(options, context) {
        Ok(()) => 0,
        Err(error) => {
            eprintln!("soundchex-server: {error}");
            1
        }
    }
}

fn serve(options: Options, context: &tauri::Context<tauri::Wry>) -> Result<(), String> {
    let identifier = &context.config().identifier;

    // The client and the server are one crate; only the server bundle carries
    // a runtime. Running the client headless would get as far as a missing
    // template and fail with a message about that instead.
    if identifier != "net.soundchex.server" {
        return Err("headless mode is part of SoundChex Server, not the client app".into());
    }

    let resource_dir = supervisor::plain(
        tauri::utils::platform::resource_dir(context.package_info(), &tauri::Env::default())
            .map_err(|e| format!("no resource dir: {e}"))?,
    );

    let data_dir = match options.data_dir {
        Some(dir) => dir,
        None => default_data_dir(identifier)?,
    };

    let mut layout = crate::layout_from(&resource_dir, &data_dir, options.listen.clone());
    layout.headless = true;

    println!("soundchex-server: resources {}", resource_dir.display());
    println!("soundchex-server: data      {}", data_dir.display());
    println!("soundchex-server: app       {}", layout.app_dir.display());

    // Claimed before anything else, and before provisioning: a window opened
    // during a first-run unpack (tens of seconds) must already see this stack,
    // or it provisions the same directory and starts a second one.
    let _ownership = claim(&layout.run_dir)?;

    refuse_if_ports_taken(&layout)?;

    let state = layout.run_dir.join(STATE_FILE);
    write_state(&state, &options.listen);

    // Signals first, so a TERM during a first-run unpack (tens of seconds) is
    // noticed as soon as it finishes rather than killing it half way.
    signals::install();

    provision::ensure(
        &resource_dir,
        &layout.app_dir,
        &layout.bin_dir,
        &layout.run_dir,
        &|progress| match progress {
            provision::Progress::Unpacking { done, total } => {
                // Every 5% — a line per file would be thousands.
                if total > 0 && (done == total || done % (total / 20).max(1) == 0) {
                    println!("soundchex-server: unpacking {done}/{total}");
                }
            }
            provision::Progress::Step(label) => println!("soundchex-server: {label}"),
        },
    )?;

    if signals::stopping() {
        let _ = std::fs::remove_file(&state);
        println!("soundchex-server: stopped before starting");
        return Ok(());
    }

    let sup = supervisor::Supervisor::default();
    sup.start(layout.clone())?;

    println!(
        "soundchex-server: serving on {} (pid {})",
        options.listen,
        std::process::id()
    );

    while !signals::stopping() {
        std::thread::sleep(std::time::Duration::from_millis(200));
    }

    println!("soundchex-server: stopping");

    let stopped = sup.stop_and_wait();
    let _ = std::fs::remove_file(&state);

    stopped?;

    println!("soundchex-server: stopped");

    Ok(())
}

/// Two stacks on one port would fight forever: the second Caddy cannot bind,
/// exits, and its supervisor respawns it every two seconds. Say so once and
/// exit instead, which a service manager reports and a person can act on.
fn refuse_if_ports_taken(layout: &supervisor::Layout) -> Result<(), String> {
    let port = port_of(&layout.listen).ok_or("listen address has no port")?;

    if std::net::TcpListener::bind(("0.0.0.0", port)).is_err() {
        return Err(format!(
            "port {port} is already in use — is the SoundChex Server window running the server?"
        ));
    }

    // php-fpm's port too. Taken by another stack, Caddy here would proxy to
    // *that* php-fpm — serving this install's files through another's
    // workers — while this one's php-fpm failed and respawned in a loop.
    if std::net::TcpListener::bind(layout.fpm.as_str()).is_err() {
        return Err(format!(
            "php-fpm's address {} is already in use by another SoundChex stack; set SOUNDCHEX_FPM to run beside it",
            layout.fpm
        ));
    }

    Ok(())
}

fn write_state(path: &Path, listen: &str) {
    let started = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0);

    let body = serde_json::json!({
        "pid": std::process::id(),
        "listen": listen,
        "started": started,
    });

    // Written beside and renamed over, so the window never reads half a file.
    // Advisory: the server works without it, the window just cannot say which
    // pid it is. Logged, because that is the thing that would be confusing.
    let partial = path.with_extension("json.partial");

    let written = std::fs::write(&partial, body.to_string())
        .and_then(|()| std::fs::rename(&partial, path));

    if let Err(e) = written {
        eprintln!("soundchex-server: could not write {}: {e}", path.display());
    }
}

/// Proof of being the headless stack for a run directory. Dropping it (the
/// process ending, by any means) releases the lock.
pub struct Ownership {
    _file: std::fs::File,
}

/// Take the run directory's lock, or say who has it.
///
/// Retried for a second rather than tried once: the window probes the same
/// lock to ask whether one is held, and a start that happened to land in that
/// instant would otherwise refuse with nothing actually running.
pub fn claim(run_dir: &Path) -> Result<Ownership, String> {
    std::fs::create_dir_all(run_dir)
        .map_err(|e| format!("cannot create {}: {e}", run_dir.display()))?;

    let path = run_dir.join(LOCK_FILE);
    let file = std::fs::OpenOptions::new()
        .create(true)
        .truncate(false)
        .write(true)
        .open(&path)
        .map_err(|e| format!("cannot open {}: {e}", path.display()))?;

    for _ in 0..10 {
        match lock::try_exclusive(&file) {
            Ok(true) => return Ok(Ownership { _file: file }),
            Ok(false) => std::thread::sleep(std::time::Duration::from_millis(100)),
            Err(e) => return Err(format!("cannot lock {}: {e}", path.display())),
        }
    }

    Err(match recorded_pid(run_dir) {
        Some(pid) => format!("already running headless (pid {pid}); stop that one first"),
        None => "already running headless; stop that one first".to_string(),
    })
}

/// The pid of the headless stack running on this run directory, if one is.
///
/// Running means the lock is held — not that a recorded pid is alive, which
/// is what survived reboots and wedged the service. The pid comes from the
/// state file the holder writes straight after taking the lock, so it is the
/// holder's own.
pub fn live_headless_pid(run_dir: &Path) -> Option<u32> {
    if !lock::held(&run_dir.join(LOCK_FILE)) {
        return None;
    }

    recorded_pid(run_dir)
}

fn recorded_pid(run_dir: &Path) -> Option<u32> {
    let text = std::fs::read_to_string(run_dir.join(STATE_FILE)).ok()?;
    let value: serde_json::Value = serde_json::from_str(&text).ok()?;

    u32::try_from(value.get("pid")?.as_u64()?).ok()
}

#[cfg(unix)]
mod lock {
    use std::os::unix::io::AsRawFd;

    /// `Ok(false)` when someone else holds it.
    pub fn try_exclusive(file: &std::fs::File) -> std::io::Result<bool> {
        // SAFETY: a valid descriptor owned by `file` for the call.
        if unsafe { libc::flock(file.as_raw_fd(), libc::LOCK_EX | libc::LOCK_NB) } == 0 {
            return Ok(true);
        }

        let error = std::io::Error::last_os_error();

        if error.raw_os_error() == Some(libc::EWOULDBLOCK) {
            Ok(false)
        } else {
            Err(error)
        }
    }

    /// Whether another open file description holds the lock. Probes by trying
    /// to take it; a probe that succeeds releases it at once by closing.
    pub fn held(path: &std::path::Path) -> bool {
        let Ok(file) = std::fs::File::open(path) else {
            return false;
        };

        matches!(try_exclusive(&file), Ok(false))
    }
}

/// Headless is refused on Windows before any of this is reached.
#[cfg(not(unix))]
mod lock {
    pub fn try_exclusive(_file: &std::fs::File) -> std::io::Result<bool> {
        Err(std::io::Error::other("headless mode needs a POSIX system"))
    }

    pub fn held(_path: &std::path::Path) -> bool {
        false
    }
}

/// SIGTERM, SIGINT and SIGHUP all mean "stop cleanly".
///
/// A handler that only sets a flag, polled by the main loop: nothing else is
/// safe to do inside a signal handler. Installed with `sigaction` rather than
/// blocking the signals and waiting on them, because a blocked mask is
/// inherited across exec — every child would start with TERM blocked and
/// could not then be stopped.
#[cfg(unix)]
mod signals {
    use std::sync::atomic::{AtomicBool, Ordering};

    static STOP: AtomicBool = AtomicBool::new(false);

    extern "C" fn on_signal(_: libc::c_int) {
        STOP.store(true, Ordering::SeqCst);
    }

    pub fn install() {
        for signal in [libc::SIGTERM, libc::SIGINT, libc::SIGHUP] {
            // SAFETY: a zeroed sigaction with only the handler set is valid;
            // the handler touches nothing but an atomic.
            unsafe {
                let mut action: libc::sigaction = std::mem::zeroed();
                action.sa_sigaction = on_signal as extern "C" fn(libc::c_int) as usize;
                libc::sigemptyset(&mut action.sa_mask);
                action.sa_flags = libc::SA_RESTART;

                if libc::sigaction(signal, &action, std::ptr::null_mut()) != 0 {
                    eprintln!(
                        "soundchex-server: could not handle signal {signal}: {}",
                        std::io::Error::last_os_error()
                    );
                }
            }
        }
    }

    pub fn stopping() -> bool {
        STOP.load(Ordering::SeqCst)
    }
}

/// Windows has no SIGTERM, and the Server app there starts with Windows through
/// the Run key instead. Headless is refused before it gets this far.
#[cfg(not(unix))]
mod signals {
    pub fn install() {}

    pub fn stopping() -> bool {
        true
    }
}

pub fn usage() -> &'static str {
    USAGE
}

#[cfg(test)]
mod tests {
    use super::*;

    fn args(list: &[&str]) -> Vec<String> {
        list.iter().map(|s| s.to_string()).collect()
    }

    #[test]
    fn no_flag_opens_the_window() {
        assert_eq!(parse(&args(&[])), Invocation::Window);
        // A path or flag a desktop launcher passes must not be read as headless.
        assert_eq!(parse(&args(&["--some-launcher-flag"])), Invocation::Window);
    }

    #[test]
    fn both_spellings_start_headless() {
        for spelling in [&["--headless"][..], &["serve"][..]] {
            match parse(&args(spelling)) {
                Invocation::Headless(o) => assert!(port_of(&o.listen).is_some()),
                other => panic!("{spelling:?} gave {other:?}"),
            }
        }
    }

    #[test]
    fn listen_and_data_dir_take_either_form() {
        let separate = parse(&args(&["serve", "--listen", ":9000", "--data-dir", "/srv/sc"]));
        let inline = parse(&args(&["--headless", "--listen=:9000", "--data-dir=/srv/sc"]));

        let want = Invocation::Headless(Options {
            listen: ":9000".into(),
            data_dir: Some(PathBuf::from("/srv/sc")),
        });

        assert_eq!(separate, want);
        assert_eq!(inline, want);
    }

    /// A typo in a run script should stop the service at its first start, not
    /// serve somewhere nobody chose.
    #[test]
    fn mistakes_are_refused() {
        assert!(matches!(parse(&args(&["serve", "--port", "1"])), Invocation::Invalid(_)));
        assert!(matches!(parse(&args(&["serve", "--listen"])), Invocation::Invalid(_)));
        assert!(matches!(parse(&args(&["serve", "--listen="])), Invocation::Invalid(_)));
        assert!(matches!(parse(&args(&["serve", "--listen", "localhost"])), Invocation::Invalid(_)));
        assert!(matches!(parse(&args(&["serve", "--listen", ":0"])), Invocation::Invalid(_)));
    }

    #[test]
    fn help_is_help() {
        assert_eq!(parse(&args(&["serve", "--help"])), Invocation::Help);
        // On its own too: it must never reach the window, which needs a display.
        assert_eq!(parse(&args(&["--help"])), Invocation::Help);
        assert_eq!(parse(&args(&["-h"])), Invocation::Help);
    }

    #[test]
    fn ports_are_read_from_every_listen_shape() {
        assert_eq!(port_of(":8000"), Some(8000));
        assert_eq!(port_of("0.0.0.0:8443"), Some(8443));
        assert_eq!(port_of("[::1]:8000"), Some(8000));
        assert_eq!(port_of("8000"), None);
    }

    #[cfg(unix)]
    fn scratch(tag: &str) -> PathBuf {
        let dir = std::env::temp_dir().join(format!("scx-headless-{tag}-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        dir
    }

    /// The bug the lock exists for: a state file left by a power cut, naming
    /// a pid that is alive again after reboot as something else entirely. It
    /// wedged the service; with nobody holding the lock it must mean nothing.
    #[cfg(unix)]
    #[test]
    fn a_leftover_state_file_naming_a_live_pid_is_not_a_running_server() {
        let dir = scratch("stale");

        // Our parent: certainly alive, certainly not a headless server.
        let parent = std::os::unix::process::parent_id();
        std::fs::write(dir.join(STATE_FILE), format!(r#"{{"pid": {parent}}}"#)).unwrap();
        std::fs::write(dir.join(LOCK_FILE), "").unwrap();

        assert_eq!(live_headless_pid(&dir), None);

        // And a new start is not refused because of it.
        let ownership = claim(&dir).expect("a leftover file must not block a start");
        drop(ownership);

        let _ = std::fs::remove_dir_all(&dir);
    }

    #[cfg(unix)]
    #[test]
    fn a_held_lock_is_a_running_server_and_refuses_a_second() {
        let dir = scratch("held");

        let ownership = claim(&dir).expect("first claim");
        write_state(&dir.join(STATE_FILE), ":8000");

        assert_eq!(live_headless_pid(&dir), Some(std::process::id()));

        let refused = claim(&dir).err().expect("a second claim must be refused");
        assert!(refused.contains(&std::process::id().to_string()), "{refused}");

        // Released with its holder, by any means: here, by being dropped.
        drop(ownership);
        assert_eq!(live_headless_pid(&dir), None);
        assert!(claim(&dir).is_ok());

        let _ = std::fs::remove_dir_all(&dir);
    }

    #[cfg(unix)]
    #[test]
    fn nothing_on_disk_is_nothing_running() {
        let dir = scratch("empty");

        assert_eq!(live_headless_pid(&dir), None);

        std::fs::write(dir.join(STATE_FILE), "not json").unwrap();
        assert_eq!(live_headless_pid(&dir), None);

        let _ = std::fs::remove_dir_all(&dir);
    }
}
