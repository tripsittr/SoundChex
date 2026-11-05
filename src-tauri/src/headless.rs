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

/// What the window reads to know a headless stack owns the port. Written into
/// the run directory on start and removed on a clean stop; a stale one (after
/// a crash or a SIGKILL) is recognised by its pid no longer being alive.
pub const STATE_FILE: &str = "headless.json";

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
    layout.prefix_output = true;

    println!("soundchex-server: resources {}", resource_dir.display());
    println!("soundchex-server: data      {}", data_dir.display());
    println!("soundchex-server: app       {}", layout.app_dir.display());

    refuse_if_already_serving(&layout)?;

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
        println!("soundchex-server: stopped before starting");
        return Ok(());
    }

    let sup = supervisor::Supervisor::default();
    sup.start(layout.clone())?;

    let state = layout.run_dir.join(STATE_FILE);
    write_state(&state, &options.listen);

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
fn refuse_if_already_serving(layout: &supervisor::Layout) -> Result<(), String> {
    if let Some(pid) = live_headless_pid(&layout.run_dir) {
        return Err(format!(
            "already running headless (pid {pid}); stop that one first"
        ));
    }

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

    // Advisory: the server works without it, the window just cannot tell who
    // owns the port. Logged, because that is the thing that would be confusing.
    if let Err(e) = std::fs::write(path, body.to_string()) {
        eprintln!("soundchex-server: could not write {}: {e}", path.display());
    }
}

/// The pid in the run directory's state file, if that process is alive.
pub fn live_headless_pid(run_dir: &Path) -> Option<u32> {
    let text = std::fs::read_to_string(run_dir.join(STATE_FILE)).ok()?;
    let value: serde_json::Value = serde_json::from_str(&text).ok()?;
    let pid = u32::try_from(value.get("pid")?.as_u64()?).ok()?;

    (pid != std::process::id() && alive(pid)).then_some(pid)
}

#[cfg(unix)]
pub fn alive(pid: u32) -> bool {
    // SAFETY: signal 0 checks existence and permission without delivering
    // anything. EPERM means it exists but belongs to someone else.
    let result = unsafe { libc::kill(pid as libc::pid_t, 0) };

    result == 0 || std::io::Error::last_os_error().raw_os_error() == Some(libc::EPERM)
}

#[cfg(not(unix))]
pub fn alive(_pid: u32) -> bool {
    false
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
    }

    #[test]
    fn ports_are_read_from_every_listen_shape() {
        assert_eq!(port_of(":8000"), Some(8000));
        assert_eq!(port_of("0.0.0.0:8443"), Some(8443));
        assert_eq!(port_of("[::1]:8000"), Some(8000));
        assert_eq!(port_of("8000"), None);
    }

    #[cfg(unix)]
    #[test]
    fn a_stale_state_file_is_not_a_running_server() {
        let dir = std::env::temp_dir().join(format!("scx-headless-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();

        // No file.
        assert_eq!(live_headless_pid(&dir), None);

        // A pid that cannot exist (beyond pid_max on Linux and macOS).
        std::fs::write(dir.join(STATE_FILE), r#"{"pid": 4000000000}"#).unwrap();
        assert_eq!(live_headless_pid(&dir), None);

        // Garbage.
        std::fs::write(dir.join(STATE_FILE), "not json").unwrap();
        assert_eq!(live_headless_pid(&dir), None);

        // A live process that is not us: our parent (cargo test's runner).
        let parent = std::os::unix::process::parent_id();
        std::fs::write(dir.join(STATE_FILE), format!(r#"{{"pid": {parent}}}"#)).unwrap();
        assert_eq!(live_headless_pid(&dir), Some(parent));

        let _ = std::fs::remove_dir_all(&dir);
    }
}
