import './bootstrap';
import { createApp } from 'vue';

import CustomerReturn from './components/CustomerReturn.vue';
import DataGrid from './components/DataGrid.vue';
import GiveToVendor from './components/GiveToVendor.vue';
import Loader from './components/Loader.vue';
import PartyEntry from './components/PartyEntry.vue';
import PartyForm from './components/PartyForm.vue';
import ReceiveFileRows from './components/ReceiveFileRows.vue';
import StatusBoard from './components/StatusBoard.vue';
import VendorReturn from './components/VendorReturn.vue';
import WorkTypes from './components/WorkTypes.vue';

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

    // Screens that do one thing to a batch of files.
    'vue-receive-rows': ReceiveFileRows,
    'vue-give-to-vendor': GiveToVendor,
    'vue-customer-return': CustomerReturn,
    'vue-vendor-return': VendorReturn,
    'vue-status-board': StatusBoard,

    // Ledger and reference data.
    'vue-party-entry': PartyEntry,
    'vue-party-form': PartyForm,
    'vue-work-types': WorkTypes,

    /*
     * Every listing, statement and report is the same grid with a different
     * column set, so they share one component under several keys. The key says
     * what the screen is; the props say what it shows.
     */
    'vue-party-list': DataGrid,
    'vue-party-statement': DataGrid,
    'vue-files-list': DataGrid,
    'vue-work-report': DataGrid,
    'vue-client-list': DataGrid,
    'vue-client-statement': DataGrid,
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
