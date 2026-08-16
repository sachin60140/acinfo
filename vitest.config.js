import { defineConfig } from 'vitest/config';

/*
 * The browser half of the application.
 *
 * navigate.js only exists in a browser and its failures show on the second
 * navigation rather than the first, which is exactly what the PHP suite cannot
 * reach. Everything else is covered there; this covers that.
 */
export default defineConfig({
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
