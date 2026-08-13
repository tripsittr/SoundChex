import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/filament/admin/theme.css',
                'resources/css/media-center.css',
                'resources/js/app.js',
                'resources/js/media-center.js',
                'resources/js/downloads-page.js',
                'resources/js/reader.js',
                'resources/js/now-playing.js',
                'resources/js/navigate.js',
                'resources/js/watch.js',
                // Referenced from Blade via Vite::asset() rather than imported
                // by any JS/CSS entry, so they must be declared explicitly.
                'resources/images/logo-light-on-dark.png',
                'resources/images/logo-dark-on-light.png',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
