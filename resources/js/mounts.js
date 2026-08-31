/*
 * Which component draws which mount point, and the mounting itself.
 *
 * Separate from app.js because navigation needs it too: a screen swapped into
 * the page has to have its components started, and the one leaving has to have
 * its components stopped. Keeping the registry here means neither file has to
 * import the other.
 */
import { createApp } from 'vue';

import ClientForm from './components/ClientForm.vue';
import ClientPassword from './components/ClientPassword.vue';
import CustomerReturn from './components/CustomerReturn.vue';
import DataGrid from './components/DataGrid.vue';
import Dashboard from './components/Dashboard.vue';
import FileForm from './components/FileForm.vue';
import GiveToVendor from './components/GiveToVendor.vue';
import Loader from './components/Loader.vue';
import PartyEntry from './components/PartyEntry.vue';
import PartyForm from './components/PartyForm.vue';
import PasswordToggle from './components/PasswordToggle.vue';
import PaymentForm from './components/PaymentForm.vue';
import PaymentReceipt from './components/PaymentReceipt.vue';
import ReceiveFileRows from './components/ReceiveFileRows.vue';
import StatusBoard from './components/StatusBoard.vue';
import UserDashboard from './components/UserDashboard.vue';
import VendorReturn from './components/VendorReturn.vue';
import WorkTypes from './components/WorkTypes.vue';

export const components = {
    'vue-loader': Loader,

    /*
     * Login is enhanced, never replaced. The form there is server-rendered HTML
     * that submits with JavaScript switched off, because the one screen where a
     * failed mount cannot be allowed to matter is the one that lets people in.
     */
    'vue-password-toggle': PasswordToggle,

    // Screens that do one thing to a batch of files.
    'vue-receive-rows': ReceiveFileRows,
    'vue-give-to-vendor': GiveToVendor,
    'vue-customer-return': CustomerReturn,
    'vue-vendor-return': VendorReturn,
    'vue-status-board': StatusBoard,

    // Ledger and reference data.
    'vue-dashboard': Dashboard,
    'vue-party-entry': PartyEntry,
    'vue-party-form': PartyForm,
    'vue-work-types': WorkTypes,
    'vue-file-form': FileForm,

    // Clients: the record, the login it can be given, and money received.
    'vue-client-form': ClientForm,
    'vue-client-password': ClientPassword,
    'vue-payment-form': PaymentForm,
    'vue-payment-receipt': PaymentReceipt,

    /*
     * Every listing, statement and report is the same grid with a different
     * column set, so they share one component under several keys. The key says
     * what the screen is; the props say what it shows.
     */
    'vue-party-list': DataGrid,
    'vue-party-statement': DataGrid,
    'vue-files-list': DataGrid,
    'vue-work-report': DataGrid,
    'vue-profit-report': DataGrid,
    'vue-client-list': DataGrid,
    'vue-client-statement': DataGrid,

    // The two screens a client sees rather than the office.
    'vue-user-statement': DataGrid,
    'vue-user-dashboard': UserDashboard,
};

/*
 * Every app currently on the page, so a screen that is swapped out can be taken
 * down rather than left running. Without this each navigation would leave its
 * components mounted on detached nodes, still holding their listeners.
 */
const mounted = new Map();

/**
 * Mounts every component inside a region, and returns how many it found.
 *
 * Called once at load and again after each swapped-in screen. Mount points that
 * are already running are skipped, so calling it twice over the same markup is
 * harmless.
 */
export function mount(root = document) {
    let count = 0;

    for (const [selector, component] of Object.entries(components)) {
        root.querySelectorAll(`[data-vue="${selector}"]`).forEach((el) => {
            if (mounted.has(el)) {
                return;
            }

            const app = createApp(component, readProps(el));
            app.mount(el);
            mounted.set(el, app);
            count++;
        });
    }

    return count;
}

/**
 * Takes down every app whose element has left the document.
 *
 * Keyed on the element rather than the region, so it does not matter which part
 * of the page was replaced — anything detached is gone.
 */
export function unmountDetached() {
    for (const [el, app] of mounted) {
        if (! el.isConnected) {
            app.unmount();
            mounted.delete(el);
        }
    }
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
