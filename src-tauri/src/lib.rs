//! The SoundChex shell.
//!
//! Deliberately thin. The application is the Laravel server — this wraps it in
//! a native window so desktop and phone get a launcher icon, an app-switcher
//! entry, and storage that the browser will not evict. Everything the user
//! sees is served over HTTPS from the host in `tauri.conf.json`.
//!
//! Keeping it thin is the point: there is one frontend, already tested, and no
//! second implementation to drift out of step with it.

/// Boots the app. Shared by desktop (`main.rs`) and the mobile entrypoints.
#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    let builder = tauri::Builder::default()
        // Notifications are desktop and mobile both, so this is unconditional.
        .plugin(tauri_plugin_notification::init());

    // Desktop only. iOS and Android install through their own mechanisms, and
    // the plugin has no implementation there.
    #[cfg(desktop)]
    let builder = builder.plugin(tauri_plugin_updater::Builder::new().build());

    builder
        .run(tauri::generate_context!())
        .expect("error while running SoundChex");
}
