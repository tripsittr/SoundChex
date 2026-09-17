// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/**
 * Highlight and note storage for the reader.
 *
 * Annotations are loaded once when a book opens and kept in memory — paging
 * through a book shouldn't cost a request per page. Writes go to the server
 * immediately, because losing a note the reader just typed is unacceptable in
 * a way that losing a scroll position isn't.
 */

export const HIGHLIGHT_COLORS = {
    yellow: { label: 'Yellow', ink: '#f5c518' },
    green: { label: 'Green', ink: '#5bc47a' },
    blue: { label: 'Blue', ink: '#5aa9e6' },
    pink: { label: 'Pink', ink: '#e87fa8' },
};

export class AnnotationStore {
    /**
     * @param {string} baseUrl  Collection endpoint for this book.
     * @param {string} csrf
     */
    constructor(baseUrl, csrf) {
        this.baseUrl = baseUrl;
        this.csrf = csrf;
        this.items = [];
        this.listeners = new Set();
    }

    onChange(fn) {
        this.listeners.add(fn);

        return () => this.listeners.delete(fn);
    }

    notify() {
        this.listeners.forEach((fn) => fn(this.items));
    }

    async load() {
        try {
            const response = await fetch(this.baseUrl, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) return;

            const data = await response.json();
            this.items = data.annotations ?? [];
            this.notify();
        } catch {
            // An unreachable server shouldn't stop the book from opening;
            // the reader just starts with no highlights.
        }
    }

    /** Every annotation on a given page, for the PDF renderer. */
    forPage(page) {
        return this.items.filter((item) => item.page === page);
    }

    find(id) {
        return this.items.find((item) => item.id === id) ?? null;
    }

    async create(payload) {
        const created = await this.request(this.baseUrl, 'POST', payload);

        if (created?.annotation) {
            this.items.push(created.annotation);
            this.notify();

            return created.annotation;
        }

        return null;
    }

    async update(id, changes) {
        const updated = await this.request(`${this.baseUrl}/${id}`, 'PATCH', changes);

        if (updated?.annotation) {
            const index = this.items.findIndex((item) => item.id === id);

            if (index !== -1) this.items[index] = updated.annotation;

            this.notify();

            return updated.annotation;
        }

        return null;
    }

    async remove(id) {
        const result = await this.request(`${this.baseUrl}/${id}`, 'DELETE');

        if (result?.deleted) {
            this.items = this.items.filter((item) => item.id !== id);
            this.notify();

            return true;
        }

        return false;
    }

    async request(url, method, body) {
        try {
            const response = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.csrf ?? '',
                    Accept: 'application/json',
                },
                body: body ? JSON.stringify(body) : undefined,
            });

            if (!response.ok) return null;

            return await response.json();
        } catch {
            return null;
        }
    }
}
