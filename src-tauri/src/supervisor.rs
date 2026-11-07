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
use std::thread::JoinHandle;
use std::time::Duration;
#[cfg(unix)]
use std::time::Instant;

/// How long a child is given to exit on SIGTERM before it is killed.
///
/// Long enough for php-fpm and Caddy to close their listeners and for a queue
/// worker between jobs to exit; deliberately not long enough to wait out a
/// transcode, which can run for an hour.
///
/// Under runit's seven seconds, and that is the point. Void's shutdown runs
/// `sv force-stop`, which SIGKILLs the service once seven seconds pass; at ten,
/// every reboot killed the supervisor before its own KILL fallback, and the
/// headless children -- each in its own group -- were left holding the ports.
#[cfg(unix)]
const STOP_GRACE: Duration = Duration::from_secs(6);

/// The processes, in start order.
///
/// Two shapes, because PHP ships no php-fpm SAPI on Windows and the
/// Caddy-proxies-to-fpm arrangement simply cannot be built there (S-418):
///
///   - POSIX:   php-fpm, then caddy (the proxy target must exist before Caddy
///              binds), then the queue worker and scheduler.
///   - Windows: frankenphp, which is the web server and PHP in one binary, so
///              there is no proxy and nothing to order it against.
#[cfg(not(windows))]
const PROCESSES: &[&str] = &["php-fpm", "caddy", "queue", "scheduler"];

#[cfg(windows)]
const PROCESSES: &[&str] = &["frankenphp", "queue", "scheduler"];

/// The processes this layout runs: the platform's set, plus DLNA discovery
/// when asked for.
///
/// DLNA is opt-in rather than always on. A host that already runs
/// `dlna:serve` itself (HatCOS does) would otherwise get a second copy, and two
/// servers answering every search under one UUID confuse the devices they are
/// meant to help. It costs little left running: it only advertises while DLNA
/// is switched on in Settings.
fn processes(layout: &Layout) -> Vec<&'static str> {
    let mut names = PROCESSES.to_vec();

    if layout.dlna {
        names.push("dlna");
    }

    names
}

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
    /// Headless mode: run under a service manager rather than the window.
    ///
    /// Each child's output is piped through a `[name]` prefix (four processes
    /// writing to one stdout are unreadable in a service log otherwise), each
    /// child leads its own process group so a stop reaches what it spawned,
    /// and on Linux each is told to exit if the supervisor dies.
    ///
    /// Off for the window, which keeps its children in its own group: there a
    /// Ctrl-C on `tauri dev` reaches all of them, as it always has, and its
    /// output is not changed.
    pub headless: bool,
    /// Where php-fpm listens and Caddy proxies to. `127.0.0.1:9100` unless
    /// `SOUNDCHEX_FPM` says otherwise, so a second stack (a test, or a
    /// headless one beside a window on another port) need not collide with the
    /// first on a port nobody sees.
    pub fpm: String,
    /// Also run `artisan dlna:serve` (see `processes`).
    pub dlna: bool,
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
    /// The watcher thread, so a caller that must not exit before the children
    /// do (headless mode, on SIGTERM) can wait for it.
    watcher: Mutex<Option<JoinHandle<()>>>,
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
        let handle = std::thread::spawn(move || watch(running, liveness, rx, layout));
        *self.watcher.lock().map_err(lock_err)? = Some(handle);
        Ok(())
    }

    /// Stop, and block until every child has exited.
    ///
    /// `stop` only signals the watcher, which is right for a window that stays
    /// open. A headless process that returned from `main` straight after it
    /// would take the watcher down mid-shutdown and leave the children behind.
    pub fn stop_and_wait(&self) -> Result<(), String> {
        self.stop()?;

        if let Some(handle) = self.watcher.lock().map_err(lock_err)?.take() {
            handle
                .join()
                .map_err(|_| "the supervisor thread panicked while stopping".to_string())?;
        }

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
        // DLNA only when it is one of this run's processes, which the
        // watcher records in `liveness` as soon as it starts.
        let processes = PROCESSES
            .iter()
            .chain(live.contains_key("dlna").then_some(&"dlna"))
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
    let names = processes(&layout);

    // Initial start, in order.
    for name in names.iter().copied() {
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
        for name in names.iter().copied() {
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

    let all: Vec<(&str, Child)> = children.drain().collect();

    shutdown(all, layout.headless);

    if let Ok(mut m) = liveness.lock() {
        m.clear();
    }
}

/// End every child: SIGTERM, a grace period, then SIGKILL for whatever is
/// left. All are signalled together; nothing here depends on an order.
///
/// This used to be `Child::kill`, which is SIGKILL — fine for a window being
/// closed, wrong for a service. php-fpm and Caddy close their listeners on
/// TERM, a queue worker finishes the job in hand, and the scheduler exits
/// between ticks. KILL skips all of that, and on the queue it also skipped the
/// workers `queue:workers` had spawned, which are its children rather than
/// ours and were left running against the SQLite file.
///
/// Headless, the signal goes to each child's process *group*, which `spawn`
/// made it the leader of, so a child's own children are reached without it
/// having to forward anything -- and the wait lasts until the whole group is
/// gone, not just its leader, so a worker outliving `queue:workers` is still
/// killed at the deadline. The window signals the child itself: its children
/// share the window's group, and `queue:workers` stops its own pool on TERM.
#[cfg(unix)]
fn shutdown(mut children: Vec<(&str, Child)>, groups: bool) {
    // pid, or -pid for the group it leads. Recorded before anything exits:
    // a reaped leader's group can still have members to kill.
    let targets: Vec<(&str, libc::pid_t)> = children
        .iter()
        .map(|(name, child)| {
            let pid = child.id() as libc::pid_t;
            (*name, if groups { -pid } else { pid })
        })
        .collect();

    for (name, target) in &targets {
        signal(*target, libc::SIGTERM, name);
    }

    let deadline = Instant::now() + STOP_GRACE;

    loop {
        // Reap what has exited: an unreaped leader is a zombie that keeps its
        // group, and the probe below, alive.
        children.retain_mut(|(_, child)| !matches!(child.try_wait(), Ok(Some(_))));

        let remaining = targets.iter().any(|(_, target)| exists(*target));

        if !remaining && children.is_empty() {
            return;
        }

        if Instant::now() >= deadline {
            break;
        }

        std::thread::sleep(Duration::from_millis(100));
    }

    for (name, target) in &targets {
        if exists(*target) {
            eprintln!(
                "soundchex-supervisor: {name} did not exit within {}s of SIGTERM; killing it",
                STOP_GRACE.as_secs()
            );
            signal(*target, libc::SIGKILL, name);
        }
    }

    for (_, child) in &mut children {
        let _ = child.wait();
    }
}

/// Whether a pid (or, negative, a process group) still has anything in it.
#[cfg(unix)]
fn exists(target: libc::pid_t) -> bool {
    // SAFETY: signal 0 delivers nothing; it only checks.
    unsafe { libc::kill(target, 0) == 0 }
}

/// Windows has no TERM to send. The job object already takes the children's
/// own children with them, so a kill is both the only option and sufficient.
#[cfg(not(unix))]
fn shutdown(children: Vec<(&str, Child)>, _groups: bool) {
    for (_, mut child) in children {
        let _ = child.kill();
        let _ = child.wait();
    }
}

#[cfg(unix)]
fn signal(target: libc::pid_t, signal: libc::c_int, name: &str) {
    // SAFETY: `kill` takes plain integers. A negative target addresses the
    // group led by that pid, which `spawn` created; one that has already gone
    // returns ESRCH, which is the outcome being asked for.
    let sent = unsafe { libc::kill(target, signal) };

    if sent != 0 {
        let error = std::io::Error::last_os_error();

        if error.raw_os_error() != Some(libc::ESRCH) {
            eprintln!("soundchex-supervisor: could not signal {name} ({target}): {error}");
        }
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
        // Windows: one binary that serves HTTP and executes PHP itself, so
        // it takes the Caddyfile's job as well as php-fpm's (S-418).
        //
        // `php-server` is FrankenPHP's batteries-included mode: it serves a
        // document root and runs PHP in one process. The working directory is
        // set to the app root below, and `--root` points at `public/` — the
        // only directory a web server should be able to reach.
        "frankenphp" => {
            let mut c = Command::new(layout.exe("frankenphp"));
            c.arg("php-server")
                .arg("--root")
                .arg(layout.app_dir.join("public"))
                .arg("--listen")
                .arg(&layout.listen);
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
                // `queue:workers`, not `queue:work`: it runs as many workers
                // as the panel's concurrency setting asks for and re-reads it
                // on a timer, so changing the number does not mean rebuilding
                // this binary (#509).
                .arg("queue:workers")
                // Every queue the pipeline uses, in priority order (#465).
                // A bare `queue:work` serves `default` only, so the io, cpu
                // and net stages would sit unprocessed forever -- the worker
                // would look healthy and nothing would move.
                //
                // Ordered so the cheap work is never stuck behind the
                // expensive: `default` holds the transitions, `net` waits on
                // other people's servers, `cpu` reads files, and `io` hashes
                // and moves bytes, which is the slowest.
                .arg("--queue=default,net,cpu,io")
                .arg("--timeout=21900");
            c
        }
        "scheduler" => {
            let mut c = Command::new(layout.exe("php"));
            c.arg(layout.app_dir.join("artisan")).arg("schedule:work");
            c
        }
        // The port it advertises comes from SOUNDCHEX_LISTEN, set below for
        // every child, so it always names the port Caddy is on.
        "dlna" => {
            let mut c = Command::new(layout.exe("php"));
            c.arg(layout.app_dir.join("artisan")).arg("dlna:serve");
            c
        }
        other => {
            return Err(std::io::Error::new(
                std::io::ErrorKind::InvalidInput,
                format!("unknown process {other}"),
            ))
        }
    };
    hide_console(&mut cmd);
    cmd.current_dir(&layout.app_dir);

    // The bundled binaries first on PATH. `config/transcode.php` finds ffmpeg
    // beside PHP on its own, but subtitle import, OCR and fingerprinting look a
    // command up by bare name — and under a service manager PATH is minimal
    // (runit hands a service little more than /usr/bin:/bin), so the bundled
    // ffmpeg was invisible to them and they reported it missing.
    cmd.env("PATH", path_with(&layout.bin_dir));

    // Headless: each child leads its own process group, so stopping reaches
    // whatever it spawned (see `shutdown`), and a Ctrl-C in the terminal goes
    // to the supervisor alone, which then stops them itself.
    //
    // That also means a supervisor killed outright -- `sv force-stop`, the OOM
    // killer -- no longer takes its children with it, and they would keep the
    // ports. On Linux the kernel can be asked to send each child TERM when its
    // parent dies, which closes that gap. macOS has no equivalent, and is not
    // where headless runs as a service.
    #[cfg(unix)]
    if layout.headless {
        use std::os::unix::process::CommandExt;

        cmd.process_group(0);

        #[cfg(target_os = "linux")]
        // SAFETY: runs in the child between fork and exec, and calls only
        // prctl/getppid, which are async-signal-safe.
        unsafe {
            cmd.pre_exec(|| {
                if libc::prctl(libc::PR_SET_PDEATHSIG, libc::SIGTERM) != 0 {
                    return Err(std::io::Error::last_os_error());
                }

                // The parent may have died between fork and prctl; the
                // signal would then never come.
                if libc::getppid() == 1 {
                    return Err(std::io::Error::other("supervisor exited during spawn"));
                }

                Ok(())
            });
        }
    }

    if layout.headless {
        cmd.stdout(std::process::Stdio::piped());
        cmd.stderr(std::process::Stdio::piped());
    }
    cmd.env("SOUNDCHEX_ROOT", layout.app_dir.join("public"));
    cmd.env("SOUNDCHEX_FPM", &layout.fpm);
    cmd.env("SOUNDCHEX_LISTEN", &layout.listen);
    cmd.env("SOUNDCHEX_RUN", &layout.run_dir);
    cmd.env("SOUNDCHEX_LOG", layout.run_dir.join("caddy-access.log"));

    // Where render_windows_php_ini put the ini. PHP has no other way to find
    // it: the install directory is read-only, so it cannot sit beside the
    // binary. Set on Windows only — POSIX ships a static PHP that needs none,
    // and pointing it at a file that is never written would change a platform
    // that already works.
    if cfg!(windows) {
        cmd.env("PHPRC", layout.run_dir.join("php.ini"));
    }

    let mut child = cmd.spawn()?;

    // Into the job before anything else, so a crash between here and the
    // watcher's bookkeeping still cannot leave it behind.
    #[cfg(windows)]
    tree::adopt(&child);

    if layout.headless {
        if let Some(out) = child.stdout.take() {
            relay(name, out, false);
        }
        if let Some(err) = child.stderr.take() {
            relay(name, err, true);
        }
    }

    Ok(child)
}

/// Copy a child's output to ours a line at a time, tagged with its name.
///
/// The thread ends when the child closes the pipe, which it does on exit.
fn relay(name: &str, source: impl std::io::Read + Send + 'static, to_stderr: bool) {
    use std::io::{BufRead, Write};

    let tag = format!("[{name}] ");

    std::thread::spawn(move || {
        let reader = std::io::BufReader::new(source);

        // `split` rather than `lines`: a child writing something that is not
        // UTF-8 would end `lines` at the first bad byte and its later output
        // would be lost, along with the one line that explained the failure.
        for line in reader.split(b'\n').map_while(Result::ok) {
            let text = String::from_utf8_lossy(&line);

            // Written through a lock per line so two children cannot interleave
            // inside one line.
            if to_stderr {
                let _ = writeln!(std::io::stderr().lock(), "{tag}{text}");
            } else {
                let _ = writeln!(std::io::stdout().lock(), "{tag}{text}");
            }
        }
    });
}

/// PATH with `bin_dir` in front, keeping whatever the parent had.
fn path_with(bin_dir: &Path) -> std::ffi::OsString {
    let mut dirs = vec![bin_dir.to_path_buf()];

    if let Some(existing) = std::env::var_os("PATH") {
        dirs.extend(std::env::split_paths(&existing));
    } else if cfg!(unix) {
        // Nothing inherited at all: give the system directories rather than
        // only the bundle, or `sh`, `which` and every OCR tool vanish.
        dirs.extend(["/usr/local/bin", "/usr/bin", "/bin"].map(PathBuf::from));
    }

    std::env::join_paths(dirs).unwrap_or_else(|_| bin_dir.as_os_str().to_os_string())
}

/// Write the Caddyfile and php-fpm.conf into the run dir from the bundled
/// templates, substituting placeholders, so a fresh install has valid config.
fn render_config(layout: &Layout) -> Result<(), String> {
    std::fs::create_dir_all(&layout.run_dir).map_err(|e| e.to_string())?;

    // Windows serves through FrankenPHP, which needs neither the Caddyfile nor
    // an fpm pool: it is the web server and PHP in one, configured by its
    // arguments rather than by those files (S-418). It does need a php.ini,
    // which is the one piece of config Windows does not get for free.
    if cfg!(windows) {
        return write_windows_php_ini(&layout.bin_dir, &layout.run_dir).map(|_| ());
    }

    // A checkout's `server/templates` first, then the bundle's own
    // `runtime/templates` (beside `runtime/bin`). Only the first was ever
    // read, and the unpacked app payload does not carry `server/` -- so a
    // packaged install not pointed at a checkout failed every start with
    // "missing template", window and headless alike.
    //
    // The checkout wins where there is one: it is the current copy, and a
    // locally built app's `runtime/templates` is whatever was staged when it
    // was built. Preferring that served a September Caddyfile with an
    // unquoted root -- broken by the space in "SoundChex App" -- and the old
    // 25 MB upload cap.
    let checkout = layout.app_dir.join("server").join("templates");
    let templates = if checkout.join("Caddyfile").is_file() {
        checkout
    } else {
        layout
            .bin_dir
            .parent()
            .map(|runtime| runtime.join("templates"))
            .unwrap_or(checkout)
    };
    let caddy_tpl = read_template(&templates.join("Caddyfile"))?;
    let fpm_tpl = read_template(&templates.join("php-fpm.conf"))?;

    let public = layout.app_dir.join("public");
    let (user, group) = fpm_identity();

    let caddy = caddy_tpl
        .replace("{$SOUNDCHEX_ROOT}", &public.to_string_lossy())
        .replace("{$SOUNDCHEX_FPM}", &layout.fpm)
        .replace("{$SOUNDCHEX_LISTEN}", &layout.listen)
        .replace(
            "{$SOUNDCHEX_LOG}",
            &layout.run_dir.join("caddy-access.log").to_string_lossy(),
        );

    let fpm = fpm_tpl
        .replace("{$SOUNDCHEX_RUN}", &layout.run_dir.to_string_lossy())
        .replace("{$SOUNDCHEX_FPM}", &layout.fpm)
        .replace("{$SOUNDCHEX_USER}", &user)
        .replace("{$SOUNDCHEX_GROUP}", &group);

    std::fs::write(layout.run_dir.join("Caddyfile"), caddy).map_err(|e| e.to_string())?;
    std::fs::write(layout.run_dir.join("php-fpm.conf"), fpm).map_err(|e| e.to_string())?;
    Ok(())
}

/// Keep a spawned process from opening a console window.
///
/// Windows gives a console to any child of a GUI process that does not say
/// otherwise, so starting the server put a black window on screen for
/// frankenphp, for each of the two workers, and for every artisan command
/// provisioning ran. They outlive nothing and close nothing: they sit there for
/// as long as the server is up, which is the point of a background service.
///
/// Nothing on POSIX, which has no such notion.
/// Keep the children alive no longer than the app, whatever kills the app.
///
/// `stop()` kills them on a clean quit, but that path runs on the watcher
/// thread, and a force-kill or a crash of the app takes the thread with it —
/// leaving `queue:work` and `schedule:work` running with nothing to stop them.
/// The next launch then spawned a second pair, and two workers competed for one
/// SQLite file; "database is locked" is already the commonest cause of a failed
/// job here, and the worker runs with `--tries=1`, so a lost lock loses the job.
///
/// A Windows job object with `KILL_ON_JOB_CLOSE` moves the guarantee into the
/// kernel: the job's last handle closes when the process holding it exits, by
/// any means, and every process assigned to it is terminated. Nothing on the
/// POSIX side changes — there the equivalent is a process group, and that stack
/// is working.
#[cfg(windows)]
mod tree {
    use std::process::Child;
    use std::sync::OnceLock;
    use windows_sys::Win32::Foundation::HANDLE;
    use windows_sys::Win32::System::JobObjects::{
        AssignProcessToJobObject, CreateJobObjectW, JobObjectExtendedLimitInformation,
        SetInformationJobObject, JOBOBJECT_EXTENDED_LIMIT_INFORMATION,
        JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE,
    };

    /// A raw HANDLE is just an isize, which Rust will not share between threads
    /// on its own account. The handle is created once and only ever read, and
    /// it is deliberately never closed: the process exiting is what closes it,
    /// and that close is the signal that kills the children.
    struct Job(HANDLE);

    unsafe impl Send for Job {}
    unsafe impl Sync for Job {}

    static JOB: OnceLock<Option<Job>> = OnceLock::new();

    fn job() -> Option<HANDLE> {
        JOB.get_or_init(|| {
            // SAFETY: both calls are given a correctly sized, zeroed struct and
            // a handle this function owns; failures come back as null or 0 and
            // are handled rather than unwrapped.
            unsafe {
                let handle = CreateJobObjectW(std::ptr::null(), std::ptr::null());

                if handle.is_null() {
                    eprintln!("soundchex-supervisor: CreateJobObject failed; children may outlive the app");
                    return None;
                }

                let mut limits: JOBOBJECT_EXTENDED_LIMIT_INFORMATION = std::mem::zeroed();
                limits.BasicLimitInformation.LimitFlags = JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;

                let set = SetInformationJobObject(
                    handle,
                    JobObjectExtendedLimitInformation,
                    &limits as *const _ as *const std::ffi::c_void,
                    std::mem::size_of::<JOBOBJECT_EXTENDED_LIMIT_INFORMATION>() as u32,
                );

                if set == 0 {
                    eprintln!("soundchex-supervisor: SetInformationJobObject failed; children may outlive the app");
                    return None;
                }

                Some(Job(handle))
            }
        })
        .as_ref()
        .map(|j| j.0)
    }

    /// Put a freshly spawned child in the job. Best effort: a child that cannot
    /// be assigned still runs, and the supervisor still kills it on a clean
    /// stop — only the crash case is weaker, which is where it was before.
    pub fn adopt(child: &Child) {
        let Some(job) = job() else {
            return;
        };

        use std::os::windows::io::AsRawHandle;

        // SAFETY: the handle belongs to `child`, which outlives this call.
        let assigned = unsafe { AssignProcessToJobObject(job, child.as_raw_handle() as HANDLE) };

        if assigned == 0 {
            eprintln!(
                "soundchex-supervisor: could not assign pid {} to the job; it may outlive the app",
                child.id()
            );
        }
    }
}

pub fn hide_console(command: &mut Command) {
    #[cfg(windows)]
    {
        use std::os::windows::process::CommandExt;

        // CREATE_NO_WINDOW. Not pulled from winapi for one constant.
        const CREATE_NO_WINDOW: u32 = 0x0800_0000;

        command.creation_flags(CREATE_NO_WINDOW);
    }

    #[cfg(not(windows))]
    {
        let _ = command;
    }
}

/// Strip Windows' extended-length prefix from a path.
///
/// `AppHandle::path().resource_dir()` hands back a verbatim path -- `\\?\C:\...`
/// -- which is fine for Rust's own file APIs and wrong for anything that passes
/// the string to another program. PHP could not read an `extension_dir` written
/// that way: it looked for `\?\C:\...`, found nothing, loaded no extension at
/// all, and every database call failed with "could not find driver".
pub fn plain(path: PathBuf) -> PathBuf {
    let text = path.to_string_lossy();

    if let Some(rest) = text.strip_prefix(r"\\?\UNC\") {
        return PathBuf::from(format!(r"\\{rest}"));
    }

    match text.strip_prefix(r"\\?\") {
        Some(rest) => PathBuf::from(rest),
        None => path.clone(),
    }
}

/// A path as PHP's ini parser will read it.
///
/// Forward slashes, which Windows PHP accepts, because a double-quoted ini value
/// processes `\\` and `\"` -- so a native path is altered on the way in and a
/// path ending in a separator would swallow the closing quote.
fn ini_path(path: &Path) -> String {
    plain(path.to_path_buf()).to_string_lossy().replace('\\', "/")
}

/// Write a `php.ini` for the bundled Windows runtime.
///
/// FrankenPHP's Windows build is a stock *dynamic* PHP: its extensions are
/// DLLs in `bin/ext` that load only if an ini says so. Nothing wrote one, so
/// `extension_dir` kept its compiled-in default of `C:\php\ext`, no dynamic
/// extension loaded, and every request that reached the database failed with
/// "could not find driver" — which is every request, since the session, cache
/// and queue all live in SQLite.
///
/// Generated at start rather than shipped in the bundle because the path is
/// only known once installed: an ini written at build time carries the build
/// machine's directories, and the install directory is not writable anyway.
/// `spawn` points PHP at the returned path with `PHPRC`, and so does
/// first-run provisioning, whose `artisan migrate` needs `pdo_sqlite` just as
/// much as a request does.
///
/// POSIX does not come here — its PHP is a static build from static-php-cli
/// with the extensions compiled in, and needs no ini at all.
pub fn write_windows_php_ini(bin_dir: &Path, dir: &Path) -> Result<PathBuf, String> {
    // Naming a statically built-in extension is a warning PHP prints and
    // continues past; omitting a needed dynamic one is fatal at the first
    // query. So this lists everything the app requires and tolerates overlap.
    const EXTENSIONS: &[&str] = &[
        "curl",
        "exif",
        "fileinfo",
        "gd",
        "intl",
        "mbstring",
        "openssl",
        "pdo_sqlite",
        // DLNA discovery holds a UDP multicast socket.
        "sockets",
        "sodium",
        "sqlite3",
        "zip",
    ];

    let mut ini = String::from(
        "; Written by SoundChex Server each time it starts. Edits are lost.\n\n",
    );

    ini.push_str(&format!(
        "extension_dir = \"{}\"\n",
        ini_path(&bin_dir.join("ext"))
    ));

    for extension in EXTENSIONS {
        ini.push_str(&format!("extension = {extension}\n"));
    }

    // Sized for media. Without any ini at all Windows used PHP's own defaults —
    // 2M per file, 8M per request — which stops a film before it starts.
    //
    // `post_max_size = 0` is PHP's documented "no limit". `BulkUpload` reads
    // both of these back through `ini_get` to decide what it will accept, and
    // treats 0 as unlimited, so the upload form follows this file rather than
    // needing its own number.
    //
    // PHP buffers an entire upload into `upload_tmp_dir` before any code runs,
    // so this needs as much free space as the largest file. It is put beside
    // the server's other working files to be findable and clearable, rather
    // than left in the system temp directory where nobody would look.
    ini.push_str("\nupload_max_filesize = 512G\n");
    ini.push_str("post_max_size = 0\n");
    ini.push_str("memory_limit = 1G\n");
    ini.push_str("max_file_uploads = 500\n");

    // An upload of several gigabytes takes longer than any default allows.
    // `max_input_time` governs reading the body, which is where the time goes.
    ini.push_str("max_input_time = -1\n");
    ini.push_str("max_execution_time = 0\n");

    let uploads = dir.join("uploads");
    let _ = std::fs::create_dir_all(&uploads);
    ini.push_str(&format!("upload_tmp_dir = \"{}\"\n", ini_path(&uploads)));

    // The CA bundle sits beside the binaries by convention (package-runtime.sh
    // puts it there). Without it every outbound HTTPS call fails verification,
    // which on this app means artwork and metadata silently stop arriving.
    let cacert = bin_dir.join("cacert.pem");

    if cacert.exists() {
        ini.push_str(&format!("\ncurl.cainfo = \"{}\"\n", ini_path(&cacert)));
        ini.push_str(&format!("openssl.cafile = \"{}\"\n", ini_path(&cacert)));
    }

    std::fs::create_dir_all(dir).map_err(|e| e.to_string())?;

    let path = dir.join("php.ini");

    std::fs::write(&path, ini).map_err(|e| e.to_string())?;

    Ok(path)
}

fn read_template(path: &Path) -> Result<String, String> {
    std::fs::read_to_string(path).map_err(|e| format!("missing template {}: {e}", path.display()))
}

/// The user/group php-fpm's pool runs as. When the app runs unprivileged (the
/// normal desktop case) these are ignored by php-fpm, so the current user is a
/// safe default that also satisfies a privileged launch.
fn fpm_identity() -> (String, String) {
    // A service is often started with no USER at all (runit's `chpst -u` sets
    // the uid and nothing else), so ask the passwd database before falling
    // back to `nobody`.
    let user = std::env::var("USER")
        .ok()
        .filter(|u| !u.is_empty())
        .or_else(current_user_name)
        .unwrap_or_else(|| "nobody".into());
    let group = if cfg!(target_os = "macos") {
        "staff".into()
    } else {
        "nogroup".into()
    };
    (user, group)
}

/// The login name of the current uid, from the passwd database.
#[cfg(unix)]
pub fn current_user_name() -> Option<String> {
    passwd_field(|entry| entry.pw_name)
}

/// The home directory of the current uid, from the passwd database — set even
/// when the environment is not, which is the usual case under a service
/// manager.
#[cfg(unix)]
pub fn current_user_home() -> Option<PathBuf> {
    passwd_field(|entry| entry.pw_dir).map(PathBuf::from)
}

#[cfg(unix)]
fn passwd_field(pick: impl Fn(&libc::passwd) -> *mut libc::c_char) -> Option<String> {
    // SAFETY: `getpwuid` returns null or a pointer to static storage valid
    // until the next call; the field is copied out before returning.
    unsafe {
        let entry = libc::getpwuid(libc::getuid());

        if entry.is_null() {
            return None;
        }

        let field = pick(&*entry);

        if field.is_null() {
            return None;
        }

        let text = std::ffi::CStr::from_ptr(field).to_string_lossy().into_owned();

        (!text.is_empty()).then_some(text)
    }
}

#[cfg(not(unix))]
fn current_user_name() -> Option<String> {
    None
}

/// Windows has no passwd database; headless mode is refused there before this
/// is asked, but the signature has to exist for it to compile.
#[cfg(not(unix))]
pub fn current_user_home() -> Option<PathBuf> {
    None
}

fn lock_err<T>(_: T) -> String {
    "supervisor lock poisoned".into()
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The bug this exists to stop coming back: a verbatim resource path written
    /// into php.ini made PHP load no extensions, so every request died with
    /// "could not find driver" and nothing said why.
    /// Windows had no ini at all, so it used PHP's 2M-per-file default. This
    /// server takes multi-gigabyte media.
    #[test]
    fn the_generated_ini_allows_large_uploads() {
        let dir = std::env::temp_dir().join(format!("scx-ini-{}", std::process::id()));
        let _ = std::fs::create_dir_all(&dir);

        let path = write_windows_php_ini(&dir.join("bin"), &dir).unwrap();
        let ini = std::fs::read_to_string(path).unwrap();

        assert!(ini.contains("upload_max_filesize = 512G"), "{ini}");
        // PHP's documented "no limit", which BulkUpload reads back as unlimited.
        assert!(ini.contains("post_max_size = 0"), "{ini}");
        assert!(ini.contains("max_input_time = -1"), "{ini}");
        assert!(ini.contains("upload_tmp_dir"), "{ini}");

        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn ini_path_drops_the_verbatim_prefix_and_uses_forward_slashes() {
        let path = PathBuf::from(r"\\?\C:\Temp\scx\runtime\bin\ext");

        assert_eq!(ini_path(&path), "C:/Temp/scx/runtime/bin/ext");
    }

    #[test]
    fn ini_path_leaves_an_ordinary_path_alone_but_for_separators() {
        let path = PathBuf::from(r"C:\Users\Someone Else\ext");

        assert_eq!(ini_path(&path), "C:/Users/Someone Else/ext");
    }

    #[test]
    fn plain_keeps_a_unc_share_reachable() {
        let path = PathBuf::from(r"\\?\UNC\server\share\bin");

        assert_eq!(plain(path), PathBuf::from(r"\\server\share\bin"));
    }

    #[test]
    fn plain_is_a_no_op_on_a_normal_path() {
        let path = PathBuf::from(r"C:\Temp\bin");

        assert_eq!(plain(path.clone()), path);
    }
}
