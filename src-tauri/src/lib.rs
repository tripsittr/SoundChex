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

/// Boots the app. Shared by desktop (`main.rs`) and the mobile entrypoints.
#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    let builder = tauri::Builder::default()
        // Notifications are desktop and mobile both, so this is unconditional.
        .plugin(tauri_plugin_notification::init());

    // Desktop only. iOS and Android install through their own mechanisms, and
    // the plugin has no implementation there.
    #[cfg(desktop)]
    let builder = builder
        .plugin(tauri_plugin_updater::Builder::new().build())
        // So the host application can start a stopped server. The admin panel
        // manages services too, but it cannot be reached when the thing serving
        // it is down.
        .invoke_handler(tauri::generate_handler![start_service, service_running]);

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
