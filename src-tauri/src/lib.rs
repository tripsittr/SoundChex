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

    // Biometric has no desktop implementation. Registering it behind the same
    // cfg as the dependency keeps one entrypoint for every platform rather than
    // forking run() per target.
    #[cfg(any(target_os = "ios", target_os = "android"))]
    let builder = builder.plugin(tauri_plugin_biometric::init());

    builder
        .run(tauri::generate_context!())
        .expect("error while running SoundChex");
}
