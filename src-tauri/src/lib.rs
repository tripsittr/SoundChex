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

    builder
        .run(tauri::generate_context!())
        .expect("error while running SoundChex");
}
