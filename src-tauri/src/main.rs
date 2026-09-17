// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

// Desktop entrypoint. The Windows attribute keeps a console window from
// appearing behind the app in release builds.
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

fn main() {
    soundchex_lib::run()
}
