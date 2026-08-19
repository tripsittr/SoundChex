/**
 * The settings that need the native shell.
 *
 * Biometric unlock and notifications are the two preferences that cannot be
 * honoured by a web page alone: one needs Face ID, the other needs the OS to
 * grant permission. Both are plugins in the Tauri shell and absent in a
 * browser, so this hides what it cannot deliver rather than offering a toggle
 * that does nothing.
 */
const isTauri = () => typeof window !== 'undefined' && window.__TAURI_INTERNALS__ !== undefined;

/**
 * Face ID, Touch ID, or a fingerprint reader — whatever this device has.
 *
 * The plugin reports both whether hardware exists and whether anything is
 * enrolled. A phone with Face ID hardware but no face registered cannot
 * authenticate, and offering the toggle there would produce a setting that
 * fails every time it is used.
 */
async function biometricStatus() {
    if (!isTauri()) return { available: false, reason: 'not the app' };

    try {
        const { checkStatus } = await import('@tauri-apps/plugin-biometric');
        const status = await checkStatus();

        return {
            available: status.isAvailable === true,
            type: status.biometryType ?? null,
            reason: status.error ?? null,
        };
    } catch (error) {
        return { available: false, reason: String(error?.message ?? error) };
    }
}

/**
 * Asks for a biometric check.
 *
 * Resolves true only on a successful authentication. Every other outcome —
 * cancelled, unavailable, too many failed attempts — is false, so a caller
 * cannot mistake an error for a pass.
 */
export async function authenticate(reason = 'Unlock SoundChex') {
    if (!isTauri()) return false;

    try {
        const { authenticate: prompt } = await import('@tauri-apps/plugin-biometric');

        await prompt(reason, {
            // No silent fallback to a device passcode: the point is to confirm
            // this person, and a passcode anyone watching could have seen typed
            // is a weaker claim than the one being asked for.
            allowDeviceCredential: false,
            cancelTitle: 'Cancel',
        });

        return true;
    } catch {
        return false;
    }
}

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
    bindBiometric();
    bindNotifications();
}

async function bindBiometric() {
    const row = document.querySelector('[data-biometric-row]');
    const absent = document.querySelector('[data-biometric-absent]');

    if (!row) return;

    const status = await biometricStatus();

    if (!status.available) {
        // Left hidden, and the explanation shown instead. A disabled toggle
        // invites people to keep trying it.
        absent?.removeAttribute('hidden');

        return;
    }

    row.removeAttribute('hidden');
    absent?.setAttribute('hidden', '');

    const note = row.querySelector('[data-biometric-note]');
    const toggle = row.querySelector('input[type="checkbox"]');

    if (note) {
        note.textContent = status.type
            ? `This device offers ${status.type}.`
            : 'This device can ask for a biometric check.';
    }

    // Proven before it is stored. Turning this on without passing the check
    // once would leave someone locked out of their own library by a setting
    // that never worked.
    toggle?.addEventListener('change', async (event) => {
        if (!event.target.checked) return;

        if (!await authenticate('Confirm it is you')) {
            event.target.checked = false;

            if (note) note.textContent = 'That did not authenticate, so this stays off.';
        }
    });
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

window.soundchexDevice = { authenticate, biometricStatus, notify, requestNotificationPermission };
