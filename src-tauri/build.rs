fn main() {
    // Opt the app's own commands into ACL permission generation, producing an
    // `allow-<command>` permission for each. Without this, commands defined in
    // the app crate (not a plugin) have no permission to grant, so they work on
    // the local tauri:// origin but are denied on the remote server origin the
    // webview navigates to — which is why every download silently fell back to
    // IndexedDB. The capability then grants the `allow-*` ones to that origin.
    tauri_build::try_build(
        tauri_build::Attributes::new().app_manifest(
            tauri_build::AppManifest::new().commands(&[
                "free_space",
                "media_save",
                "media_append",
                "media_finalize",
                "media_write_manifest",
                "media_exists",
                "media_path_for",
                "media_remove",
                "media_list",
                "media_manifest",
                "debug_log",
            ]),
        ),
    )
    .expect("failed to run tauri-build");
}
