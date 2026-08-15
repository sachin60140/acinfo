import './bootstrap';
import { createApp } from 'vue';

import Loader from './components/Loader.vue';
import ReceiveFileRows from './components/ReceiveFileRows.vue';

/*
 * Vue is mounted into the existing Blade pages rather than taking over routing.
 *
 * This application is live and holds real ledger balances, so screens move one
 * at a time: each mount point below replaces one piece of a page, the server
 * keeps validating every submission, and the rest of the page carries on
 * working while the migration proceeds. Full client-side routing is the last
 * step, not the first.
 */
const components = {
    'vue-loader': Loader,
    'vue-receive-rows': ReceiveFileRows,
};

for (const [selector, component] of Object.entries(components)) {
    document.querySelectorAll(`[data-vue="${selector}"]`).forEach((el) => {
        const app = createApp(component, readProps(el));
        app.mount(el);
    });
}

/**
 * Server data arrives as a JSON blob on the mount point rather than as
 * individual attributes, so a component's inputs stay typed instead of every
 * number and boolean arriving as a string.
 */
function readProps(el) {
    const raw = el.getAttribute('data-props');

    if (!raw) {
        return {};
    }

    try {
        return JSON.parse(raw);
    } catch (error) {
        console.error('Invalid data-props on', el, error);

        return {};
    }
}
