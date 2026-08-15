import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    // Laravel serves these from public/, not from the bundle.
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    /*
     * No build overrides here on purpose. laravel-vite-plugin already writes to
     * public/build and puts the manifest where Laravel looks for it; setting
     * outDir and manifest by hand moved it to public/build/.vite/manifest.json
     * and every page 500'd with "Vite manifest not found".
     *
     * public/build is committed to the repository — the production host deploys
     * by git pull and has no npm step, so unbuilt assets would never arrive.
     */
});
