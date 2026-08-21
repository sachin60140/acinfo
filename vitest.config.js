import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

/*
 * The browser half of the application.
 *
 * navigate.js only exists in a browser and its failures show on the second
 * navigation rather than the first, which is exactly what the PHP suite cannot
 * reach. Components live here too: a dialog that opens on a click, and does not
 * follow the link it was attached to, is browser behaviour with nowhere else to
 * be tested. Everything else is covered by the PHP suite.
 */
export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.js'],
        environmentOptions: {
            // History is only writable within the document's own origin, and the
            // module writes to it on load.
            jsdom: { url: 'http://localhost/admin/dashboard' },
        },
    },
});
