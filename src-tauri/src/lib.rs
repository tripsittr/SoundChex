// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

//! The SoundChex shell.
//!
//! Deliberately thin. The application is the Laravel server — this wraps it in
//! a native window so desktop and phone get a launcher icon, an app-switcher
//! entry, and storage that the browser will not evict. Everything the user
//! sees is served over HTTPS from the host in `tauri.conf.json`.
//!
//! Keeping it thin is the point: there is one frontend, already tested, and no
//! second implementation to drift out of step with it.

/// Where the services are defined, so the host application can start them.
///
/// The admin panel manages these too, and does it better — it can report queue
/// depth and when the scheduler last ran. But it is served *by* the web server,
/// so when that is down the one page that could start it is unreachable. This
/// exists for exactly that case and no other.
#[cfg(desktop)]
const AGENTS: [(&str, &str); 3] = [
    ("serve", "com.soundchex.serve"),
    ("queue", "com.soundchex.queue"),
    ("scheduler", "com.soundchex.scheduler"),
];

/// Installs a launchd agent and loads it.
///
/// Copies the plist from the repository into ~/Library/LaunchAgents and loads
/// it, so the service starts now and again after a reboot. Unloads first,
/// because installing over a loaded agent leaves launchd running the previous
/// definition.
#[cfg(desktop)]
#[tauri::command]
fn start_service(key: String, repo: String) -> Result<String, String> {
    let label = AGENTS
        .iter()
        .find(|(name, _)| *name == key)
        .map(|(_, label)| *label)
        .ok_or_else(|| format!("unknown service: {key}"))?;

    let home = std::env::var("HOME").map_err(|_| "no home directory".to_string())?;
    let agents = format!("{home}/Library/LaunchAgents");
    let target = format!("{agents}/{label}.plist");
    let source = format!("{repo}/Documentation & Planning/{label}.plist");

    if !std::path::Path::new(&source).exists() {
        return Err(format!("no plist at {source}"));
    }

    std::fs::create_dir_all(&agents).map_err(|e| e.to_string())?;

    // Ignored: unloading something that is not loaded is not an error, and
    // launchctl says so noisily either way.
    let _ = std::process::Command::new("launchctl")
        .args(["unload", &target])
        .output();

    std::fs::copy(&source, &target).map_err(|e| e.to_string())?;

    let output = std::process::Command::new("launchctl")
        .args(["load", &target])
        .output()
        .map_err(|e| e.to_string())?;

    if output.status.success() {
        Ok(format!("{label} started"))
    } else {
        Err(String::from_utf8_lossy(&output.stderr).trim().to_string())
    }
}

/// The bundled-server supervisor (S-151 Step 5). The Server app carries the
/// runtime as resources and spawns it directly, rather than loading host plists.
#[cfg(desktop)]
mod supervisor;

/// Resolve the bundled runtime layout from the app's resource dir.
///
/// Tauri unpacks `bundle.resources` under the resource directory, so the runtime
/// bin/ and the app's `server/templates` are both found relative to it. `app_dir`
/// is where `artisan` lives; in a packaged app that is the resource dir, and in
/// `tauri dev` it is the repo root two levels up from `src-tauri`.
#[cfg(desktop)]
fn resolve_layout(app: &tauri::AppHandle, listen: String) -> Result<supervisor::Layout, String> {
    use tauri::Manager;

    let resource_dir = app
        .path()
        .resource_dir()
        .map_err(|e| format!("no resource dir: {e}"))?;

    // The bundled runtime lives under resources/runtime/bin; in dev, fall back to
    // a RUNTIME_DIR env pointing at a locally-built bundle.
    let bin_dir = std::env::var("SOUNDCHEX_RUNTIME_BIN")
        .map(std::path::PathBuf::from)
        .unwrap_or_else(|_| resource_dir.join("runtime").join("bin"));

    // The app root: packaged, the resource dir holds artisan; in dev, the repo.
    let app_dir = if resource_dir.join("artisan").exists() {
        resource_dir.clone()
    } else {
        std::env::var("SOUNDCHEX_APP_DIR")
            .map(std::path::PathBuf::from)
            .unwrap_or_else(|_| resource_dir.clone())
    };

    let run_dir = app
        .path()
        .app_data_dir()
        .map_err(|e| format!("no app data dir: {e}"))?
        .join("server");

    Ok(supervisor::Layout {
        bin_dir,
        app_dir,
        run_dir,
        listen,
    })
}

/// Start the bundled server stack (Server app).
#[cfg(desktop)]
#[tauri::command]
fn server_start(
    app: tauri::AppHandle,
    sup: tauri::State<'_, supervisor::Supervisor>,
    listen: Option<String>,
) -> Result<(), String> {
    let layout = resolve_layout(&app, listen.unwrap_or_else(|| ":8000".into()))?;
    sup.start(layout)
}

/// Stop the bundled server stack.
#[cfg(desktop)]
#[tauri::command]
fn server_stop(sup: tauri::State<'_, supervisor::Supervisor>) -> Result<(), String> {
    sup.stop()
}

/// What the supervised stack is doing.
#[cfg(desktop)]
#[tauri::command]
fn server_status(sup: tauri::State<'_, supervisor::Supervisor>) -> supervisor::Status {
    sup.status()
}

/// Whether an agent is currently loaded.
#[cfg(desktop)]
#[tauri::command]
fn service_running(key: String) -> bool {
    let Some(label) = AGENTS
        .iter()
        .find(|(name, _)| *name == key)
        .map(|(_, label)| *label)
    else {
        return false;
    };

    std::process::Command::new("launchctl")
        .args(["list", label])
        .output()
        .map(|output| output.status.success())
        .unwrap_or(false)
}

/// Bytes available to us on the volume that holds `path`.
///
/// The offline system has to know real free disk space to decide whether a
/// download fits — and the browser cannot tell it. `navigator.storage.estimate()`
/// returns the *quota*, a slice the engine set aside, which on iOS is bounded
/// near the 1 GB IndexedDB cap and is unrelated to the tens of gigabytes a
/// native download can actually use. So this reports the disk, not the quota.
///
/// There is no Tauri plugin for this. `statvfs` is POSIX and present on macOS,
/// iOS, Linux and Android, which is every platform that matters here; it is one
/// `extern "C"` call. `f_bavail` is the blocks available to a non-root process
/// (not `f_bfree`, which counts blocks reserved for root that we cannot use),
/// and `f_frsize` is the fragment size those blocks are measured in.
///
/// Returns bytes rather than a struct on purpose. Two struct layouts returned
/// a nonsense trillion-gigabyte figure before the third was right — a wrong
/// reading here would refuse every download or approve every one, silently — so
/// the seam is a single integer, and the test asserts it against `df` on each
/// platform rather than against itself.
#[tauri::command]
fn free_space(path: String) -> Result<u64, String> {
    #[cfg(unix)]
    {
        use std::ffi::CString;
        use std::mem::MaybeUninit;

        let c_path = CString::new(path.as_str())
            .map_err(|_| "path contains a null byte".to_string())?;

        // SAFETY: `statvfs` fills the struct or returns non-zero; we read it
        // only on success, and `c_path` outlives the call.
        let stat = unsafe {
            let mut stat = MaybeUninit::<libc::statvfs>::uninit();

            if libc::statvfs(c_path.as_ptr(), stat.as_mut_ptr()) != 0 {
                return Err(format!(
                    "statvfs failed for {path}: {}",
                    std::io::Error::last_os_error()
                ));
            }

            stat.assume_init()
        };

        // f_bavail and f_frsize are both u64 on the platforms we target, but
        // cast explicitly so a narrower libc definition cannot overflow the
        // multiply silently.
        let available = (stat.f_bavail as u64).saturating_mul(stat.f_frsize as u64);

        Ok(available)
    }

    #[cfg(not(unix))]
    {
        // Windows: GetDiskFreeSpaceExW. Kept behind cfg so the Unix build does
        // not pull it in; the interface is the same u64 of bytes.
        use std::os::windows::ffi::OsStrExt;

        let wide: Vec<u16> = std::ffi::OsStr::new(&path)
            .encode_wide()
            .chain(std::iter::once(0))
            .collect();

        let mut free_bytes: u64 = 0;

        // SAFETY: `wide` is null-terminated and outlives the call; we pass null
        // for the totals we do not need.
        let ok = unsafe {
            windows_sys::Win32::Storage::FileSystem::GetDiskFreeSpaceExW(
                wide.as_ptr(),
                &mut free_bytes,
                std::ptr::null_mut(),
                std::ptr::null_mut(),
            )
        };

        if ok == 0 {
            return Err(format!("GetDiskFreeSpaceExW failed for {path}"));
        }

        Ok(free_bytes)
    }
}

/// The directory downloaded media lives in, created if absent.
///
/// `Library/Application Support/<bundle>/media/` on iOS, via Tauri's
/// `app_data_dir` — not `Caches/` (the OS purges it under pressure, taking
/// downloads with it) and not `Documents/` (surfaced in the Files app, which
/// invites the user or the OS to move a file the app is still tracking).
///
/// A single directory of flat files keyed by id: no nesting to reason about,
/// and `remove`/`exists` are one `std::fs` call each.
#[cfg(mobile)]
fn media_dir(app: &tauri::AppHandle) -> Result<std::path::PathBuf, String> {
    use tauri::Manager;

    let dir = app
        .path()
        .app_data_dir()
        .map_err(|e| format!("no app data dir: {e}"))?
        .join("media");

    std::fs::create_dir_all(&dir).map_err(|e| format!("could not create {dir:?}: {e}"))?;

    Ok(dir)
}


/// The on-disk path for one media id, refusing anything that could escape the
/// media directory.
///
/// The id comes from the web layer, so it is untrusted. A `..` or a slash in it
/// would let a download write outside the sandbox — so the id must be a single
/// plain path component and nothing else. Verified by comparing the id to its
/// own file-name form.
#[cfg(mobile)]
fn media_path(app: &tauri::AppHandle, id: &str) -> Result<std::path::PathBuf, String> {
    safe_media_path(&media_dir(app)?, id)
}

/// Joins `id` under `dir`, refusing anything that could escape it.
///
/// Pure and Tauri-free so the traversal defence can be tested directly rather
/// than only through the command. The id is a single plain path component and
/// nothing else — no separator, no `..`, no `.` — and the joined path's parent
/// must still be `dir`, which catches anything the character check missed.
///
/// Not gated on `mobile`, so the test suite (which builds for the host) can
/// reach it; it is tiny and pulls in nothing platform-specific. `allow(dead_code)`
/// because on a non-mobile, non-test build its only caller (`media_path`) is
/// compiled out, but the function must still exist for the tests.
#[allow(dead_code)]
fn safe_media_path(dir: &std::path::Path, id: &str) -> Result<std::path::PathBuf, String> {
    if id.is_empty() || id.contains('/') || id.contains('\\') || id == ".." || id == "." {
        return Err(format!("unsafe media id: {id:?}"));
    }

    let path = dir.join(id);

    match path.parent() {
        Some(parent) if parent == dir => Ok(path),
        _ => Err(format!("media id escapes the media directory: {id:?}")),
    }
}

/// Marks a file so the OS does not back it up or sync it to iCloud.
///
/// Downloaded media is a local cache of the server's library, not the user's
/// own data — backing up tens of gigabytes of it would be wrong, and iCloud
/// may refuse or evict it. On Apple platforms this is the `com.apple.metadata:`
/// extended attribute the `NSURLIsExcludedFromBackupKey` sets; a single
/// `setxattr` with the documented value has the same effect without linking
/// Foundation.
///
/// Gated on `mobile` as well as Apple, because its only caller (`media_save`)
/// is mobile-only — on desktop macOS the attribute exists but nothing writes
/// downloads there, so compiling it would be dead code.
#[cfg(all(target_vendor = "apple", mobile))]
fn exclude_from_backup(path: &std::path::Path) {
    use std::os::unix::ffi::OsStrExt;

    let c_path = match std::ffi::CString::new(path.as_os_str().as_bytes()) {
        Ok(p) => p,
        Err(_) => return,
    };

    let name = c"com.apple.metadata:com_apple_backup_excludeItem";
    // The value Time Machine and iCloud check for is this exact string.
    let value = b"com.apple.backupd";

    // SAFETY: all pointers are valid for the length given; a failure is
    // non-fatal (the file is stored either way), so the result is ignored.
    unsafe {
        libc::setxattr(
            c_path.as_ptr(),
            name.as_ptr(),
            value.as_ptr() as *const libc::c_void,
            value.len(),
            0,
            0,
        );
    }
}

/// The manifest sidecar path for an id: the bytes file with `.meta` appended.
///
/// The store is self-describing — each download's title, kind, artwork and size
/// live in a JSON file *next to the bytes*, not in IndexedDB. So the download
/// list is read straight off disk and survives anything that clears browser
/// storage, and the shell can see downloads across the origin boundary that
/// used to need an iframe probe.
#[cfg(mobile)]
fn manifest_path(app: &tauri::AppHandle, id: &str) -> Result<std::path::PathBuf, String> {
    let bytes = media_path(app, id)?;
    let mut name = bytes.file_name().unwrap_or_default().to_os_string();
    name.push(".meta");

    Ok(bytes.with_file_name(name))
}

/// Writes bytes to a media file in one shot and excludes it from backup.
///
/// Written to a temporary name and renamed into place, so a crash mid-write
/// never leaves a half-file that reads as a complete download. Kept for small
/// files (a track, artwork); large files stream through `media_append` /
/// `media_finalize` instead, so a film never has to sit in memory as one buffer
/// on both sides of the IPC bridge.
///
/// Returns the absolute path, which is what the asset protocol serves back.
#[cfg(mobile)]
#[tauri::command]
fn media_save(app: tauri::AppHandle, id: String, bytes: Vec<u8>) -> Result<String, String> {
    let path = media_path(&app, &id)?;
    let tmp = path.with_extension("part");

    std::fs::write(&tmp, &bytes).map_err(|e| format!("write failed: {e}"))?;
    std::fs::rename(&tmp, &path).map_err(|e| format!("rename failed: {e}"))?;

    #[cfg(target_vendor = "apple")]
    exclude_from_backup(&path);

    Ok(path.to_string_lossy().into_owned())
}

/// Appends one chunk to a media file being streamed to disk.
///
/// A film is gigabytes; serialising it as a single byte array through the IPC
/// bridge would need it whole in memory twice (JS array and Rust `Vec`), which
/// is exactly what the old single-shot `media_save` did and what made large
/// downloads impossible on the phone. So the JS side reads the response body a
/// chunk at a time and appends each here, and nothing larger than one chunk is
/// ever resident.
///
/// The first call (when no `.part` exists) truncates; every later one appends.
/// `media_finalize` renames the finished `.part` into place.
#[cfg(mobile)]
#[tauri::command]
fn media_append(app: tauri::AppHandle, id: String, chunk: Vec<u8>) -> Result<(), String> {
    use std::io::Write;

    let path = media_path(&app, &id)?;
    let tmp = path.with_extension("part");

    let mut file = std::fs::OpenOptions::new()
        .create(true)
        .append(true)
        .open(&tmp)
        .map_err(|e| format!("open for append failed: {e}"))?;

    file.write_all(&chunk).map_err(|e| format!("append failed: {e}"))?;

    Ok(())
}

/// Finishes a streamed download: renames the `.part` into place, writes its
/// manifest, and excludes both from backup.
///
/// `meta_json` is the manifest the download list is built from — title, kind,
/// artwork id, size and the rest — stored verbatim beside the bytes. Written
/// only after the rename, so a manifest on disk always implies finished bytes.
///
/// Returns the absolute path the asset protocol serves.
#[cfg(mobile)]
#[tauri::command]
fn media_finalize(app: tauri::AppHandle, id: String, meta_json: String) -> Result<String, String> {
    let path = media_path(&app, &id)?;
    let tmp = path.with_extension("part");

    if !tmp.is_file() {
        return Err(format!("nothing streamed for {id:?}"));
    }

    std::fs::rename(&tmp, &path).map_err(|e| format!("rename failed: {e}"))?;

    let manifest = manifest_path(&app, &id)?;
    std::fs::write(&manifest, meta_json.as_bytes())
        .map_err(|e| format!("manifest write failed: {e}"))?;

    #[cfg(target_vendor = "apple")]
    {
        exclude_from_backup(&path);
        exclude_from_backup(&manifest);
    }

    Ok(path.to_string_lossy().into_owned())
}

/// Writes just the manifest for an id whose bytes are already stored.
///
/// The single-shot `media_save` path (music, artwork) stores bytes without a
/// manifest; this attaches one so those downloads list the same way streamed
/// ones do. Separate from `media_save` so the byte write stays a pure,
/// well-tested primitive.
#[cfg(mobile)]
#[tauri::command]
fn media_write_manifest(app: tauri::AppHandle, id: String, meta_json: String) -> Result<(), String> {
    let manifest = manifest_path(&app, &id)?;

    std::fs::write(&manifest, meta_json.as_bytes())
        .map_err(|e| format!("manifest write failed: {e}"))?;

    #[cfg(target_vendor = "apple")]
    exclude_from_backup(&manifest);

    Ok(())
}

/// Whether a media file is genuinely on disk (and non-empty).
#[cfg(mobile)]
#[tauri::command]
fn media_exists(app: tauri::AppHandle, id: String) -> Result<bool, String> {
    let path = media_path(&app, &id)?;

    Ok(std::fs::metadata(&path).map(|m| m.len() > 0).unwrap_or(false))
}

/// The absolute path for a stored id, or null when it is not there.
#[cfg(mobile)]
#[tauri::command]
fn media_path_for(app: tauri::AppHandle, id: String) -> Result<Option<String>, String> {
    let path = media_path(&app, &id)?;

    Ok(if path.is_file() {
        Some(path.to_string_lossy().into_owned())
    } else {
        None
    })
}

/// Deletes a media file and its manifest. Absent is success.
#[cfg(mobile)]
#[tauri::command]
fn media_remove(app: tauri::AppHandle, id: String) -> Result<(), String> {
    let path = media_path(&app, &id)?;

    // The manifest and any stray `.part` go too, so nothing is orphaned.
    let _ = std::fs::remove_file(manifest_path(&app, &id)?);
    let _ = std::fs::remove_file(path.with_extension("part"));

    match std::fs::remove_file(&path) {
        Ok(()) => Ok(()),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(e) => Err(format!("remove failed: {e}")),
    }
}

/// Every stored download, as `(id, size, manifest_json)`.
///
/// The list is read straight from disk: one entry per bytes file, its size, and
/// the manifest sidecar's contents (empty string when a download predates
/// manifests or is a bare artwork blob). This is what makes the store
/// self-describing — the download list needs no IndexedDB and is correct
/// offline and across the origin boundary.
#[cfg(mobile)]
#[tauri::command]
fn media_list(app: tauri::AppHandle) -> Result<Vec<(String, u64, String)>, String> {
    let dir = media_dir(&app)?;
    let mut out = Vec::new();

    for entry in std::fs::read_dir(&dir).map_err(|e| format!("read_dir failed: {e}"))? {
        let entry = entry.map_err(|e| e.to_string())?;
        let name = entry.file_name().to_string_lossy().into_owned();

        // Bytes files only: skip half-written `.part` and manifest `.meta`
        // sidecars, which are read as a download's metadata, not as downloads.
        if name.ends_with(".part") || name.ends_with(".meta") {
            continue;
        }

        let size = entry.metadata().map(|m| m.len()).unwrap_or(0);

        // The manifest beside it, if any. Read best-effort: a missing or
        // unreadable manifest lists the download with empty metadata rather
        // than dropping a real file from the list.
        let manifest = manifest_path(&app, &name)
            .ok()
            .and_then(|p| std::fs::read_to_string(p).ok())
            .unwrap_or_default();

        out.push((name, size, manifest));
    }

    Ok(out)
}

/// One download's manifest JSON, or empty string if it has none.
#[cfg(mobile)]
#[tauri::command]
fn media_manifest(app: tauri::AppHandle, id: String) -> Result<String, String> {
    Ok(std::fs::read_to_string(manifest_path(&app, &id)?).unwrap_or_default())
}

/// The `@tauri-apps/api` bridge, injected into every frame.
///
/// The reason native storage did nothing on the phone: the app is served from a
/// *remote* origin (the user's `.ts.net` server), and Tauri v2 does not inject
/// `window.__TAURI__` into remote pages — `remote.urls` in the capability only
/// *authorises* the commands, it does not put the bridge on the page. So the web
/// app had no `invoke` to call and every download silently fell back to
/// IndexedDB. This is the IIFE build of the API, injected on all frames so it
/// reaches the page after `window.location.replace` sends the webview to the
/// server. Built by `scripts/build-tauri-bridge.mjs`.
#[cfg(mobile)]
const TAURI_BRIDGE: &str = include_str!("../assets/tauri-bridge.iife.js");

/// Boots the app. Shared by desktop (`main.rs`) and the mobile entrypoints.
#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    let builder = tauri::Builder::default()
        // Notifications are desktop and mobile both, so this is unconditional.
        .plugin(tauri_plugin_notification::init())
        // Opens external links in the user's own browser. A webview has no
        // tabs, so `target="_blank"` does nothing at all here — every link out
        // of the app is silently dead without this, including the Integrations
        // page's buttons for Radarr, Sonarr and Lidarr.
        .plugin(tauri_plugin_opener::init());

    // The main window is built here rather than from `tauri.conf.json`, because
    // an init script cannot be added to a config-created window after the fact —
    // and injecting the Tauri bridge on all frames is the whole point on mobile,
    // where the app is served from a remote origin that otherwise has no
    // `window.__TAURI__`. Desktop builds the same window without the script.
    let builder = builder.setup(|app| {
        use tauri::{WebviewUrl, WebviewWindowBuilder};

        let mut win = WebviewWindowBuilder::new(app, "main", WebviewUrl::App("index.html".into()))
            .title("SoundChex");

        #[cfg(desktop)]
        {
            win = win
                .inner_size(1280.0, 820.0)
                .min_inner_size(380.0, 560.0)
                .resizable(true);
        }

        #[cfg(mobile)]
        {
            win = win.initialization_script_for_all_frames(TAURI_BRIDGE);
        }

        win.build()?;

        Ok(())
    });

    // Commands. `free_space` is on every platform — the phone most of all,
    // where the offline system decides whether a download fits and the browser
    // cannot answer. The service commands are desktop-only, so the handler is
    // registered per platform: `invoke_handler` can be called only once, so it
    // cannot be one list with a conditional tail.
    #[cfg(desktop)]
    let builder = builder
        // iOS and Android install through their own mechanisms, and the updater
        // plugin has no implementation there.
        .plugin(tauri_plugin_updater::Builder::new().build())
        // The bundled-server supervisor (Server app). Shared state so start/stop/
        // status all address the one running stack.
        .manage(supervisor::Supervisor::default())
        // `start_service`/`service_running` let the host start a stopped server
        // when the admin panel that manages them is itself unreachable.
        // `server_*` drive the bundled runtime the Server app ships (S-151 Step 5).
        .invoke_handler(tauri::generate_handler![
            free_space,
            start_service,
            service_running,
            server_start,
            server_stop,
            server_status
        ]);

    // Mobile: free_space plus the native media store (Step 2 of the offline
    // rebuild). The store lives here rather than in JavaScript because only the
    // native side can write real files outside the ~1 GB IndexedDB ceiling and
    // mark them excluded from backup.
    #[cfg(not(desktop))]
    let builder = builder.invoke_handler(tauri::generate_handler![
        free_space,
        media_save,
        media_append,
        media_finalize,
        media_write_manifest,
        media_exists,
        media_path_for,
        media_remove,
        media_list,
        media_manifest
    ]);

    // A File menu with Refresh in it, on desktop.
    //
    // The app is a window onto a served web app, so a stale page is a real and
    // ordinary state — a deploy lands, or the connection dropped and came back
    // — and there is no browser chrome to reload from. Cmd-R is the reflex
    // everyone already has; without this it does nothing at all.
    #[cfg(desktop)]
    let builder = builder
        .menu(build_menu)
        .on_menu_event(handle_menu_event);

    builder
        .run(tauri::generate_context!())
        .expect("error while running SoundChex");
}

/// The application menu, which is the stock one plus Refresh.
///
/// Rebuilt rather than mutated: `Menu::default()` composes its submenus inline
/// and hands back a finished menu, so there is no File submenu to reach into
/// afterwards. This mirrors it and adds the one item — which means the rest of
/// the menu is ours to keep in step if Tauri's default changes, and that is
/// the trade for having Refresh at all.
#[cfg(desktop)]
fn build_menu(app: &tauri::AppHandle) -> tauri::Result<tauri::menu::Menu<tauri::Wry>> {
    use tauri::menu::{AboutMetadata, Menu, MenuItem, PredefinedMenuItem, Submenu};

    let about = AboutMetadata::default();

    // Cmd-R on macOS, Ctrl-R elsewhere — the accelerator every browser uses,
    // because this window is one in every respect except the chrome.
    let refresh = MenuItem::with_id(app, "refresh", "Refresh", true, Some("CmdOrCtrl+R"))?;

    // The one that actually unsticks a stale page. A plain reload still goes
    // through the service worker, which caches build output aggressively — so
    // after a deploy the page can come back with the JS it already had. This
    // clears the caches first and then reloads.
    let hard_refresh = MenuItem::with_id(
        app,
        "hard-refresh",
        "Hard Refresh",
        true,
        Some("CmdOrCtrl+Shift+R"),
    )?;

    // Re-runs the address race. Three routes are known and the fastest wins,
    // but the winner is chosen when the app starts — moving between wifi and
    // the tailnet leaves it on a route that is merely still working.
    let reconnect = MenuItem::with_id(app, "reconnect", "Reconnect to Server", true, None::<&str>)?;

    // For pairing another device, which otherwise means reading an IP off a
    // screen and typing it into a phone.
    let copy_address = MenuItem::with_id(app, "copy-address", "Copy Server Address", true, None::<&str>)?;

    let file = Submenu::with_items(
        app,
        "File",
        true,
        &[
            &refresh,
            &hard_refresh,
            &PredefinedMenuItem::separator(app)?,
            &reconnect,
            &copy_address,
            &PredefinedMenuItem::separator(app)?,
            &PredefinedMenuItem::close_window(app, None)?,
        ],
    )?;

    // Two destinations reached constantly, and no address bar to type into.
    let view = Submenu::with_items(
        app,
        "View",
        true,
        &[
            &MenuItem::with_id(app, "go-library", "Library", true, Some("CmdOrCtrl+1"))?,
            &MenuItem::with_id(app, "go-admin", "Admin", true, Some("CmdOrCtrl+2"))?,
        ],
    )?;

    // Where to look when something has gone wrong. The serve log reached
    // 4.6 MB before anyone read it, because finding it meant knowing it was in
    // ~/Library/Logs rather than anywhere near the application.
    let help = Submenu::with_items(
        app,
        "Help",
        true,
        &[
            &MenuItem::with_id(app, "open-logs", "Open Logs Folder", true, None::<&str>)?,
            &MenuItem::with_id(app, "open-database", "Open Database Folder", true, None::<&str>)?,
            &PredefinedMenuItem::separator(app)?,
            &MenuItem::with_id(app, "restart-services", "Restart Services", true, None::<&str>)?,
        ],
    )?;

    let edit = Submenu::with_items(
        app,
        "Edit",
        true,
        &[
            &PredefinedMenuItem::undo(app, None)?,
            &PredefinedMenuItem::redo(app, None)?,
            &PredefinedMenuItem::separator(app)?,
            &PredefinedMenuItem::cut(app, None)?,
            &PredefinedMenuItem::copy(app, None)?,
            &PredefinedMenuItem::paste(app, None)?,
            &PredefinedMenuItem::select_all(app, None)?,
        ],
    )?;

    let window = Submenu::with_items(
        app,
        "Window",
        true,
        &[
            &PredefinedMenuItem::minimize(app, None)?,
            &PredefinedMenuItem::maximize(app, None)?,
            &PredefinedMenuItem::separator(app)?,
            &PredefinedMenuItem::close_window(app, None)?,
        ],
    )?;

    // The application menu is macOS's own, and is where About and Quit live
    // there. Windows and Linux put them in File, which the stock menu already
    // handles by simply not having this submenu.
    #[cfg(target_os = "macos")]
    let menu = {
        let app_menu = Submenu::with_items(
            app,
            "SoundChex",
            true,
            &[
                &PredefinedMenuItem::about(app, None, Some(about))?,
                &PredefinedMenuItem::separator(app)?,
                &PredefinedMenuItem::services(app, None)?,
                &PredefinedMenuItem::separator(app)?,
                &PredefinedMenuItem::hide(app, None)?,
                &PredefinedMenuItem::hide_others(app, None)?,
                &PredefinedMenuItem::separator(app)?,
                &PredefinedMenuItem::quit(app, None)?,
            ],
        )?;

        Menu::with_items(app, &[&app_menu, &file, &edit, &view, &window, &help])?
    };

    #[cfg(not(target_os = "macos"))]
    let menu = {
        // About lives in Help on Windows and Linux, where macOS puts it in the
        // application menu that does not exist here.
        let help = help.append(&PredefinedMenuItem::about(app, None, Some(about))?).map(|_| help)?;

        Menu::with_items(app, &[&file, &edit, &view, &window, &help])?
    };

    Ok(menu)
}

/// Navigates to a path on the *server*, not the current origin.
///
/// The client app is served by the server, so a bare path is already right
/// there. The server app is not: it loads a local `server.html` whose origin
/// is `tauri://localhost`, and a bare '/app' would resolve inside the bundle.
/// That page declares the real address as `ORIGIN`.
#[cfg(desktop)]
const SERVER_NAVIGATE: &str = "(() => { \
    const base = (typeof ORIGIN === 'string' && ORIGIN) || window.location.origin; \
    window.location.assign(base + '__PATH__'); \
})()";

/// What the menu items do.
///
/// Everything here is either a webview evaluation or an OS call — nothing
/// reaches into the application, because the application is the Laravel server
/// and the page can already reach it. The menu exists for the cases where the
/// page *cannot*, or where there is no browser chrome to do the obvious thing.
#[cfg(desktop)]
fn handle_menu_event(app: &tauri::AppHandle, event: tauri::menu::MenuEvent) {
    use tauri::Manager as _;

    // Every window rather than the focused one: the server app opens a second
    // window, and refreshing the one in front leaves the other on a page from
    // before the deploy.
    let eval_all = |script: &str| {
        for (_label, window) in app.webview_windows() {
            let _ = window.eval(script);
        }
    };

    match event.id().as_ref() {
        // `location.reload()` rather than re-navigating: it keeps the page's
        // scroll position, which is what a browser's own refresh does.
        "refresh" => eval_all("window.location.reload()"),

        // Caches cleared first, then reloaded. A plain reload still goes
        // through the service worker, which caches build output — so after a
        // deploy the page comes back with the JavaScript it already had. The
        // worker is unregistered too, or a running one repopulates the caches
        // from itself on the next fetch.
        "hard-refresh" => eval_all(
            "(async () => { \
                try { \
                    if (window.caches) { \
                        const keys = await caches.keys(); \
                        await Promise.all(keys.map((k) => caches.delete(k))); \
                    } \
                    if (navigator.serviceWorker) { \
                        const regs = await navigator.serviceWorker.getRegistrations(); \
                        await Promise.all(regs.map((r) => r.unregister())); \
                    } \
                } catch (error) { \
                    console.warn('Hard refresh could not clear caches', error); \
                } \
                window.location.reload(); \
            })()",
        ),

        // Re-runs the address race rather than asking whether the current
        // address still answers — staying on a working-but-slow relay is the
        // failure this prevents.
        "reconnect" => eval_all(
            "(async () => { \
                const failover = window.soundchexLibrary && window.soundchexLibrary.failover; \
                if (!failover) { window.location.reload(); return; } \
                const result = await failover.check(); \
                console.info('Reconnect:', (result && result.status) || 'unknown'); \
            })()",
        ),

        // The address another device would need.
        //
        // `location.origin` is right for the client, which is served by the
        // server — but the *server* app loads a local `server.html`, where the
        // origin is `tauri://localhost` and copying it would hand someone an
        // address that means nothing off this machine. That page declares the
        // real one as `ORIGIN`, so prefer it wherever it exists.
        "copy-address" => eval_all(
            "(async () => { \
                const address = (typeof ORIGIN === 'string' && ORIGIN) \
                    || (window.soundchexHost || window.location.origin); \
                try { await navigator.clipboard.writeText(address); } \
                catch { window.prompt('Server address', address); } \
            })()",
        ),

        // Resolved against the server rather than the current page, for the
        // same reason: on the server app a bare '/app' is a path inside the
        // Tauri bundle, which does not exist.
        "go-library" => eval_all(SERVER_NAVIGATE.replace("__PATH__", "/app").as_str()),
        "go-admin" => eval_all(SERVER_NAVIGATE.replace("__PATH__", "/admin").as_str()),

        "open-logs" => open_path(&logs_directory()),
        "open-database" => open_path(&database_directory()),

        "restart-services" => restart_services(app),

        _ => {}
    }
}

/// Where the launchd agents write.
///
/// Not the repository's `storage/logs`: macOS gates `~/Documents` behind TCC,
/// and an agent told to write a log there is killed before its program runs —
/// so the logs live here, which is precisely why they are hard to find.
#[cfg(desktop)]
fn logs_directory() -> std::path::PathBuf {
    let home = std::env::var("HOME").unwrap_or_default();

    if cfg!(target_os = "macos") {
        std::path::PathBuf::from(home).join("Library/Logs/SoundChex")
    } else {
        std::path::PathBuf::from(home).join(".local/share/soundchex/logs")
    }
}

/// The application's database directory.
///
/// From `SOUNDCHEX_REPO` where the server app set it, falling back to the
/// working directory. Being wrong here opens the wrong folder rather than
/// losing anything, which is the right way round for a convenience.
#[cfg(desktop)]
fn database_directory() -> std::path::PathBuf {
    std::env::var("SOUNDCHEX_REPO")
        .map(std::path::PathBuf::from)
        .unwrap_or_else(|_| std::env::current_dir().unwrap_or_default())
        .join("database")
}

/// Opens a folder in the platform's file manager.
#[cfg(desktop)]
fn open_path(path: &std::path::Path) {
    if !path.exists() {
        return;
    }

    let command = if cfg!(target_os = "macos") {
        "open"
    } else if cfg!(target_os = "windows") {
        "explorer"
    } else {
        "xdg-open"
    };

    let _ = std::process::Command::new(command).arg(path).spawn();
}

/// Restarts every service, for when the server is down.
///
/// The admin panel manages these and does it better — but it is served *by*
/// the thing being restarted, so when that is down the one page that could fix
/// it is unreachable. That case is the reason this app exists.
#[cfg(desktop)]
fn restart_services(app: &tauri::AppHandle) {
    use tauri::Manager as _;

    let repo = std::env::var("SOUNDCHEX_REPO").unwrap_or_default();
    let mut failures = Vec::new();

    for (key, _label) in AGENTS {
        if let Err(error) = start_service(key.to_string(), repo.clone()) {
            failures.push(format!("{key}: {error}"));
        }
    }

    // Reported into the page's console rather than swallowed: a restart that
    // silently did nothing is the failure mode this is meant to fix.
    let message = if failures.is_empty() {
        "console.info('Services restarted from the menu')".to_string()
    } else {
        format!(
            "console.error('Some services did not restart: {}')",
            failures.join(", ").replace('\'', "")
        )
    };

    for (_label, window) in app.webview_windows() {
        let _ = window.eval(&message);
    }
}

#[cfg(all(test, unix))]
mod tests {
    use super::free_space;

    /// The number must match what `df` reports, not merely be non-zero.
    ///
    /// A plausible-but-wrong figure is the dangerous failure: it would refuse
    /// every download or approve every one, and look fine doing it. So this
    /// asserts against `df -k /` — the same volume, the same moment — rather
    /// than against the function's own arithmetic.
    #[test]
    fn free_space_matches_df() {
        let ours = free_space("/".to_string()).expect("statvfs on /");

        let output = std::process::Command::new("df")
            .args(["-k", "/"])
            .output()
            .expect("run df");

        let text = String::from_utf8_lossy(&output.stdout);
        // Second line, fourth column is available 1K-blocks on macOS and Linux.
        let avail_kib: u64 = text
            .lines()
            .nth(1)
            .and_then(|line| line.split_whitespace().nth(3))
            .and_then(|field| field.parse().ok())
            .expect("parse df available blocks");

        let df_bytes = avail_kib * 1024;

        // Free space moves between the two calls, so allow a small drift rather
        // than demanding the exact byte — 64 MiB is generous for the gap and
        // still catches a wrong struct layout, which is off by gigabytes.
        let drift = ours.abs_diff(df_bytes);
        assert!(
            drift < 64 * 1024 * 1024,
            "free_space {ours} vs df {df_bytes} (drift {drift}) — layout likely wrong"
        );
    }

    #[test]
    fn free_space_rejects_a_bad_path() {
        assert!(free_space("/no/such/path/at/all".to_string()).is_err());
    }

    use super::safe_media_path;
    use std::path::Path;

    #[test]
    fn safe_media_path_accepts_a_plain_id() {
        let dir = Path::new("/tmp/media");
        let path = safe_media_path(dir, "12345").expect("plain id");

        assert_eq!(path, dir.join("12345"));
    }

    #[test]
    fn safe_media_path_refuses_traversal() {
        let dir = Path::new("/tmp/media");

        // Every shape that could write outside the media directory.
        for bad in ["..", ".", "../secret", "a/b", "a\\b", "/etc/passwd", ""] {
            assert!(
                safe_media_path(dir, bad).is_err(),
                "expected {bad:?} to be refused",
            );
        }
    }

    #[test]
    fn safe_media_path_keeps_the_parent_inside_the_dir() {
        // A benign-looking id that still resolves within the directory is fine;
        // the parent check is what guarantees it.
        let dir = Path::new("/tmp/media");
        let path = safe_media_path(dir, "film-2024.mp4").expect("dotted id");

        assert_eq!(path.parent(), Some(dir));
    }
}
