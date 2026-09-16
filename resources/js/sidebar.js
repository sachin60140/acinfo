/*
 * Rolling up the sidebar sections.
 *
 * The menu is thirteen screens under four headings, and most of a working day
 * is spent in one of them. So a heading is a button that shuts its section, and
 * what is shut is remembered.
 *
 * Almost none of the work happens here. The server renders each section already
 * open or already shut, from the same cookie this writes — see the note at the
 * foot of _sidebar.blade.php — because this sidebar is replaced wholesale on
 * every visit by navigate.js, and a state applied by script afterwards would
 * flicker on every page. This file toggles, and writes down what happened.
 *
 * The listener is on the document rather than on the headings for the same
 * reason: the headings it would have been attached to are thrown away on the
 * next visit, and the document is not.
 */

const COOKIE = 'nav_collapsed';

/** A year. Long enough that it is not a setting anyone has to make twice. */
const KEEP = 60 * 60 * 24 * 365;

function shutGroups() {
    const found = document.cookie
        .split('; ')
        .find((pair) => pair.startsWith(COOKIE + '='));

    if (! found) {
        return [];
    }

    return decodeURIComponent(found.slice(COOKIE.length + 1)).split(',').filter(Boolean);
}

function remember(keys) {
    // SameSite=Lax because nothing about this needs to travel with a request
    // another site made, and no Secure flag because this application is served
    // over both schemes depending on where it is running.
    document.cookie = `${COOKIE}=${encodeURIComponent(keys.join(','))}; path=/; max-age=${KEEP}; SameSite=Lax`;
}

/**
 * Open or shut one section, and write down which it now is.
 *
 * The markup is the state: aria-expanded is what a screen reader reads, hidden
 * is what everything else goes by, and the class is only there for the arrow.
 * All three move together or the section is lying to somebody.
 */
function toggle(button) {
    const group = button.closest('[data-nav-group]');
    const items = document.getElementById(button.getAttribute('aria-controls'));

    if (! group || ! items) {
        return;
    }

    const open = button.getAttribute('aria-expanded') !== 'true';

    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    items.hidden = ! open;
    group.classList.toggle('is-shut', ! open);

    const key = group.dataset.navGroup;
    const shut = shutGroups().filter((one) => one !== key);

    remember(open ? shut : shut.concat(key));
}

export function sidebar() {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('.nav-heading--toggle');

        if (button) {
            toggle(button);
        }
    });
}
