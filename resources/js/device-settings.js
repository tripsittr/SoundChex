/**
 * The settings that need the native shell.
 *
 * Biometric unlock and notifications are the two preferences that cannot be
 * honoured by a web page alone: one needs Face ID, the other needs the OS to
 * grant permission. Both are plugins in the Tauri shell and absent in a
 * browser, so this hides what it cannot deliver rather than offering a toggle
 * that does nothing.
 */
/**
 * Whether this page is running inside the app shell.
 *
 * Both globals are checked. __TAURI_INTERNALS__ is the IPC bridge and
 * __TAURI__ is the convenience API exposed by withGlobalTauri — which one is
 * present depends on configuration, and checking only the internals reported
 * "this is a browser" inside the app, so the settings page told the user Face
 * ID was a browser limitation while they were holding the phone.
 */
const isTauri = () => typeof window !== 'undefined'
    && (window.__TAURI_INTERNALS__ !== undefined || window.__TAURI__ !== undefined);



/**
 * Notification permission, requested only when someone asks for it.
 *
 * iOS shows its allow-or-deny prompt once per install. Asking on first launch,
 * before anyone has expressed interest, is how an app gets denied permanently —
 * so this runs from the settings toggle and nowhere else.
 */
export async function requestNotificationPermission() {
    if (!isTauri()) return { granted: false, reason: 'not the app' };

    try {
        const plugin = await import('@tauri-apps/plugin-notification');

        if (await plugin.isPermissionGranted()) return { granted: true };

        const result = await plugin.requestPermission();

        return { granted: result === 'granted', reason: result };
    } catch (error) {
        return { granted: false, reason: String(error?.message ?? error) };
    }
}

/**
 * Sends a notification, if this profile wants one.
 *
 * Silent when the preference is off or permission was never granted: a caller
 * should be able to report an event without first checking whether it is
 * allowed to.
 */
export async function notify(title, body) {
    if (!isTauri()) return false;

    try {
        const plugin = await import('@tauri-apps/plugin-notification');

        if (!await plugin.isPermissionGranted()) return false;

        plugin.sendNotification({ title, body });

        return true;
    } catch {
        return false;
    }
}


/**
 * Wires the settings page to the device.
 *
 * Runs on every page, and does nothing where the markup is absent.
 */
export function bindDeviceSettings() {
    bindNotifications();
}

function bindNotifications() {
    const master = document.querySelector('[data-notification-master]');

    if (!master) return;

    const note = document.querySelector('[data-notification-note]');
    const detail = document.querySelector('[data-notification-detail]');

    const reflect = (enabled) => {
        detail?.classList.toggle('opacity-50', !enabled);
    };

    master.addEventListener('change', async (event) => {
        reflect(event.target.checked);

        if (!event.target.checked) return;

        if (!isTauri()) {
            if (note) {
                note.textContent = 'Notifications are sent by the SoundChex app. This browser cannot show them.';
            }

            return;
        }

        // The OS prompt, shown here rather than at launch.
        const { granted, reason } = await requestNotificationPermission();

        if (granted) {
            if (note) note.textContent = 'Notifications are allowed on this device.';

            return;
        }

        event.target.checked = false;
        reflect(false);

        if (note) {
            note.textContent = reason === 'denied'
                ? 'Your device denied notifications. Turn them on for SoundChex in Settings to change that.'
                : 'Notifications were not allowed on this device.';
        }
    });
}

window.soundchexDevice = { notify, requestNotificationPermission };
