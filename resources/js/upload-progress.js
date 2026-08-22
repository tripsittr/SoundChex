/**
 * A global upload indicator.
 *
 * Hooks XMLHttpRequest rather than listening for Livewire's
 * livewire-upload-* DOM events. Those events exist, but Filament's file
 * upload uses FilePond, which sends its own XHR and never dispatches them —
 * so a listener on `document` sees nothing at all. Checked, rather than
 * assumed: a capture-phase listener on `window` recorded zero events across a
 * full upload.
 *
 * Wrapping XHR catches every upload in the app, whoever sent it, which is what
 * "shows in the dashboard when files are being uploaded from anywhere"
 * actually requires.
 *
 * The bar is created on demand rather than living in the layout: it should not
 * occupy markup on the overwhelming majority of pages where nothing is being
 * uploaded.
 */
const ID = 'sc-upload-progress';

/** Requests in flight, so several files at once show as one indicator. */
const active = new Set();

function bar() {
    let element = document.getElementById(ID);

    if (element) return element;

    element = document.createElement('div');
    element.id = ID;
    element.setAttribute('role', 'status');
    element.setAttribute('aria-live', 'polite');
    element.className = 'sc-upload-progress';
    element.innerHTML = `
        <div class="sc-upload-progress__track">
            <div class="sc-upload-progress__fill"></div>
        </div>
        <p class="sc-upload-progress__label"></p>
    `;

    document.body.append(element);

    return element;
}

function render(text, percent, state = 'active') {
    const element = bar();

    element.dataset.state = state;
    element.querySelector('.sc-upload-progress__label').textContent = text;

    if (percent !== null) {
        element.querySelector('.sc-upload-progress__fill').style.width = `${percent}%`;
    }
}

function dismiss(delay = 0) {
    setTimeout(() => {
        // Another upload may have started while this one was finishing.
        if (active.size === 0) document.getElementById(ID)?.remove();
    }, delay);
}

/**
 * Only requests that actually carry a file.
 *
 * Livewire posts a component update on nearly every interaction; treating
 * those as uploads would flash the bar constantly.
 */
function isUpload(url, body) {
    if (body instanceof FormData) {
        for (const value of body.values()) {
            if (value instanceof File || value instanceof Blob) return true;
        }
    }

    return /upload-file/.test(String(url ?? ''));
}

function setupUploadProgress() {
    // Patched once. Wrapping an already-wrapped XHR would double-count every
    // progress event and leave a listener behind on each navigation.
    if (window.soundchexUploadBound) return;

    window.soundchexUploadBound = true;

    const open = XMLHttpRequest.prototype.open;
    const send = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this.__scUrl = url;

        return open.call(this, method, url, ...rest);
    };

    XMLHttpRequest.prototype.send = function (body) {
        if (!isUpload(this.__scUrl, body)) {
            return send.call(this, body);
        }

        const token = {};

        active.add(token);
        render('Uploading…', 0);

        const finish = (text, state) => {
            active.delete(token);

            if (active.size === 0) {
                render(text, state === 'error' ? null : 100, state);
                dismiss(state === 'error' ? 5000 : 2000);
            }
        };

        this.upload?.addEventListener('progress', (event) => {
            if (!event.lengthComputable) return;

            const percent = Math.round((event.loaded / event.total) * 100);

            // 100% means the bytes have left, not that the server is done —
            // saying "processing" avoids a full bar sitting there looking stuck.
            render(
                percent >= 100 ? 'Processing…' : `Uploading — ${percent}%`,
                percent,
            );
        });

        this.addEventListener('load', () => {
            finish(this.status >= 400 ? 'Upload failed' : 'Upload complete',
                this.status >= 400 ? 'error' : 'done');
        });

        this.addEventListener('error', () => finish('Upload failed', 'error'));
        this.addEventListener('abort', () => {
            active.delete(token);

            if (active.size === 0) dismiss();
        });

        return send.call(this, body);
    };
}

setupUploadProgress();

export { setupUploadProgress };
