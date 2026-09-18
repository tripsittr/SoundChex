// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

//! Spawns and supervises the bundled server stack (S-151 Step 5).
//!
//! The SoundChex Server app carries the whole runtime — `php`, `php-fpm`,
//! `caddy` and `ffmpeg` — as bundled resources, and this module starts and
//! watches them. It replaces the old approach of copying host launchd plists
//! (`start_service` in `lib.rs`), which was macOS-only and needed a system PHP.
//!
//! Four processes, the same set the OS-service installer manages:
//!   - php-fpm   the application server Caddy proxies to
//!   - caddy     owns the port, serves static, reverse-proxies PHP
//!   - queue     `php artisan queue:work`
//!   - scheduler `php artisan schedule:work`
//!
//! One watcher thread owns all four children. While the supervisor is meant to
//! be running it respawns any that exit (crash recovery, with a short backoff);
//! on stop it kills them and exits. Deliberately not a full init system: the app
//! is one machine's server, and "restart it if it dies, stop it cleanly on quit"
//! is the whole contract.

#![cfg(desktop)]

use std::collections::HashMap;
use std::path::{Path, PathBuf};
use std::process::{Child, Command};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::mpsc::{self, Receiver, Sender};
use std::sync::{Arc, Mutex};
use std::time::Duration;

/// The processes, in start order (php-fpm before caddy so the proxy target
/// exists when Caddy binds).
const PROCESSES: [&str; 4] = ["php-fpm", "caddy", "queue", "scheduler"];

/// Where the bundled binaries and the app live. Resolved once at start.
#[derive(Clone)]
pub struct Layout {
    /// Directory holding the bundled `php`, `php-fpm`, `caddy`, `ffmpeg`.
    pub bin_dir: PathBuf,
    /// The Laravel app root (holds `artisan`, `public/`, `server/`).
    pub app_dir: PathBuf,
    /// A writable directory for the rendered config, pid and logs.
    pub run_dir: PathBuf,
    /// The address Caddy binds, e.g. `:8000`.
    pub listen: String,
}

impl Layout {
    fn exe(&self, name: &str) -> PathBuf {
        let file = if cfg!(windows) {
            format!("{name}.exe")
        } else {
            name.to_string()
        };
        self.bin_dir.join(file)
    }
}

/// Supervisor state held in Tauri's managed state. The heavy lifting runs on a
/// watcher thread; this struct only carries the "should be running" flag, a
/// channel to tell the watcher to stop, and the last known per-process liveness.
#[derive(Default)]
pub struct Supervisor {
    running: Arc<AtomicBool>,
    stopper: Mutex<Option<Sender<()>>>,
    liveness: Arc<Mutex<HashMap<String, bool>>>,
}

/// The public status of the supervised stack.
#[derive(serde::Serialize)]
pub struct Status {
    pub running: bool,
    pub processes: Vec<ProcessStatus>,
}

#[derive(serde::Serialize)]
pub struct ProcessStatus {
    pub name: String,
    pub running: bool,
}

impl Supervisor {
    /// Start the whole stack. Idempotent: a call while already running is a
    /// success no-op.
    pub fn start(&self, layout: Layout) -> Result<(), String> {
        if self.running.swap(true, Ordering::SeqCst) {
            return Ok(());
        }

        render_config(&layout)?;

        let (tx, rx) = mpsc::channel::<()>();
        *self.stopper.lock().map_err(lock_err)? = Some(tx);

        let running = self.running.clone();
        let liveness = self.liveness.clone();
        std::thread::spawn(move || watch(running, liveness, rx, layout));
        Ok(())
    }

    /// Stop the stack: signal the watcher, which kills the children and exits.
    pub fn stop(&self) -> Result<(), String> {
        self.running.store(false, Ordering::SeqCst);
        if let Some(tx) = self.stopper.lock().map_err(lock_err)?.take() {
            let _ = tx.send(());
        }
        if let Ok(mut m) = self.liveness.lock() {
            m.clear();
        }
        Ok(())
    }

    /// A snapshot of what is up.
    pub fn status(&self) -> Status {
        let running = self.running.load(Ordering::SeqCst);
        let live = self.liveness.lock().map(|m| m.clone()).unwrap_or_default();
        let processes = PROCESSES
            .iter()
            .map(|name| ProcessStatus {
                name: name.to_string(),
                running: live.get(*name).copied().unwrap_or(false),
            })
            .collect();
        Status { running, processes }
    }
}

/// The watcher thread: owns every child, respawns any that die while running,
/// kills them all on stop.
fn watch(
    running: Arc<AtomicBool>,
    liveness: Arc<Mutex<HashMap<String, bool>>>,
    stop: Receiver<()>,
    layout: Layout,
) {
    let mut children: HashMap<&str, Child> = HashMap::new();

    // Initial start, in order.
    for name in PROCESSES {
        match spawn(name, &layout) {
            Ok(child) => {
                children.insert(name, child);
                set_live(&liveness, name, true);
            }
            Err(e) => {
                eprintln!("soundchex-supervisor: failed to start {name}: {e}");
                set_live(&liveness, name, false);
            }
        }
    }

    loop {
        // A stop signal, or a 2s tick, whichever comes first.
        match stop.recv_timeout(Duration::from_secs(2)) {
            Ok(()) => break,
            Err(mpsc::RecvTimeoutError::Disconnected) => break,
            Err(mpsc::RecvTimeoutError::Timeout) => {}
        }

        if !running.load(Ordering::SeqCst) {
            break;
        }

        // Respawn anything that has exited.
        for name in PROCESSES {
            let exited = match children.get_mut(name) {
                Some(c) => matches!(c.try_wait(), Ok(Some(_))),
                None => true,
            };
            if exited {
                set_live(&liveness, name, false);
                match spawn(name, &layout) {
                    Ok(child) => {
                        children.insert(name, child);
                        set_live(&liveness, name, true);
                    }
                    Err(e) => eprintln!("soundchex-supervisor: respawn {name} failed: {e}"),
                }
            } else {
                set_live(&liveness, name, true);
            }
        }
    }

    // Stopping: kill everything.
    for (_, mut child) in children.drain() {
        let _ = child.kill();
        let _ = child.wait();
    }
    if let Ok(mut m) = liveness.lock() {
        m.clear();
    }
}

fn set_live(liveness: &Arc<Mutex<HashMap<String, bool>>>, name: &str, up: bool) {
    if let Ok(mut m) = liveness.lock() {
        m.insert(name.to_string(), up);
    }
}

/// Spawn one named process from the layout.
fn spawn(name: &str, layout: &Layout) -> std::io::Result<Child> {
    let mut cmd = match name {
        "php-fpm" => {
            let mut c = Command::new(layout.exe("php-fpm"));
            c.arg("--fpm-config")
                .arg(layout.run_dir.join("php-fpm.conf"))
                .arg("--nodaemonize");
            c
        }
        "caddy" => {
            let mut c = Command::new(layout.exe("caddy"));
            c.arg("run")
                .arg("--config")
                .arg(layout.run_dir.join("Caddyfile"))
                .arg("--adapter")
                .arg("caddyfile");
            c
        }
        "queue" => {
            let mut c = Command::new(layout.exe("php"));
            c.arg(layout.app_dir.join("artisan"))
                .arg("queue:work")
                .arg("--tries=1")
                .arg("--timeout=21900");
            c
        }
        "scheduler" => {
            let mut c = Command::new(layout.exe("php"));
            c.arg(layout.app_dir.join("artisan")).arg("schedule:work");
            c
        }
        other => {
            return Err(std::io::Error::new(
                std::io::ErrorKind::InvalidInput,
                format!("unknown process {other}"),
            ))
        }
    };
    cmd.current_dir(&layout.app_dir);
    cmd.env("SOUNDCHEX_ROOT", layout.app_dir.join("public"));
    cmd.env("SOUNDCHEX_FPM", "127.0.0.1:9100");
    cmd.env("SOUNDCHEX_LISTEN", &layout.listen);
    cmd.env("SOUNDCHEX_RUN", &layout.run_dir);
    cmd.env("SOUNDCHEX_LOG", layout.run_dir.join("caddy-access.log"));
    cmd.spawn()
}

/// Write the Caddyfile and php-fpm.conf into the run dir from the bundled
/// templates, substituting placeholders, so a fresh install has valid config.
fn render_config(layout: &Layout) -> Result<(), String> {
    std::fs::create_dir_all(&layout.run_dir).map_err(|e| e.to_string())?;

    let templates = layout.app_dir.join("server").join("templates");
    let caddy_tpl = read_template(&templates.join("Caddyfile"))?;
    let fpm_tpl = read_template(&templates.join("php-fpm.conf"))?;

    let public = layout.app_dir.join("public");
    let (user, group) = fpm_identity();

    let caddy = caddy_tpl
        .replace("{$SOUNDCHEX_ROOT}", &public.to_string_lossy())
        .replace("{$SOUNDCHEX_FPM}", "127.0.0.1:9100")
        .replace("{$SOUNDCHEX_LISTEN}", &layout.listen)
        .replace(
            "{$SOUNDCHEX_LOG}",
            &layout.run_dir.join("caddy-access.log").to_string_lossy(),
        );

    let fpm = fpm_tpl
        .replace("{$SOUNDCHEX_RUN}", &layout.run_dir.to_string_lossy())
        .replace("{$SOUNDCHEX_FPM}", "127.0.0.1:9100")
        .replace("{$SOUNDCHEX_USER}", &user)
        .replace("{$SOUNDCHEX_GROUP}", &group);

    std::fs::write(layout.run_dir.join("Caddyfile"), caddy).map_err(|e| e.to_string())?;
    std::fs::write(layout.run_dir.join("php-fpm.conf"), fpm).map_err(|e| e.to_string())?;
    Ok(())
}

fn read_template(path: &Path) -> Result<String, String> {
    std::fs::read_to_string(path).map_err(|e| format!("missing template {}: {e}", path.display()))
}

/// The user/group php-fpm's pool runs as. When the app runs unprivileged (the
/// normal desktop case) these are ignored by php-fpm, so the current user is a
/// safe default that also satisfies a privileged launch.
fn fpm_identity() -> (String, String) {
    let user = std::env::var("USER").unwrap_or_else(|_| "nobody".into());
    let group = if cfg!(target_os = "macos") {
        "staff".into()
    } else {
        "nogroup".into()
    };
    (user, group)
}

fn lock_err<T>(_: T) -> String {
    "supervisor lock poisoned".into()
}
