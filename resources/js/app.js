import './bootstrap';
import { mount } from './mounts';
import './navigate';

/*
 * Vue is mounted into the existing Blade pages rather than taking over routing.
 *
 * This application is live and holds real ledger balances, so screens moved one
 * at a time: each mount point replaces one piece of a page, the server keeps
 * validating every submission, and every screen is still a real page at a real
 * URL. navigate.js then removes the page loads between them, which is what
 * routing would have been for — without two thousand lines of working, verified
 * filters and headings having to become components first.
 *
 * The registry itself lives in mounts.js, because navigation needs it too.
 */
mount();
