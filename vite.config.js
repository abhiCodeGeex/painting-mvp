import angular from '@analogjs/vite-plugin-angular';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts', 'resources/js/tools/frame-studio.ts'],
            refresh: true,
        }),
        // Compiles the Angular components (AOT) inside Laravel's normal Vite build.
        angular({
            tsconfig: 'tsconfig.json',
            jit: false,
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
    build: {
        // three.js lives in its own lazy chunk (only loaded for the 3D room).
        chunkSizeWarningLimit: 1200,
    },
});
