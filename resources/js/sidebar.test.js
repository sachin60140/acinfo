import { beforeEach, describe, expect, it } from 'vitest';
import { sidebar } from './sidebar';

/*
 * Rolling the menu sections up.
 *
 * The server renders which sections are shut, so what is left to go wrong is
 * here: a toggle that moves the arrow but not what a screen reader is told, or
 * one that forgets the section it shut a moment ago. Both look fine on the
 * click and are wrong on the next page.
 */

function menu() {
    document.body.innerHTML = `
        <aside class="sidebar">
          <ul class="sidebar-nav">
            <li class="nav-item nav-group" data-nav-group="work-files">
              <button type="button" class="nav-heading nav-heading--toggle"
                      aria-expanded="true" aria-controls="nav-group-work-files">
                <span>Work Files</span>
              </button>
              <ul class="nav-group__items" id="nav-group-work-files">
                <li class="nav-item"><a class="nav-link" href="/admin/files">All Work Files</a></li>
              </ul>
            </li>
            <li class="nav-item nav-group" data-nav-group="reports">
              <button type="button" class="nav-heading nav-heading--toggle"
                      aria-expanded="true" aria-controls="nav-group-reports">
                <span>Reports</span>
              </button>
              <ul class="nav-group__items" id="nav-group-reports">
                <li class="nav-item"><a class="nav-link" href="/admin/reports/profit">Profit</a></li>
              </ul>
            </li>
          </ul>
        </aside>
    `;
}

const heading = (key) => document.querySelector(`[data-nav-group="${key}"] .nav-heading--toggle`);
const items = (key) => document.getElementById(`nav-group-${key}`);

/** What the server will be handed on the next request. */
function written() {
    const found = document.cookie.split('; ').find((pair) => pair.startsWith('nav_collapsed='));

    return found ? decodeURIComponent(found.slice('nav_collapsed='.length)).split(',').filter(Boolean) : [];
}

// Registered once, as the application registers it once: the listener is on
// the document precisely because the sidebar under it is replaced on every
// visit, so rebuilding the menu between tests is the real case.
sidebar();

describe('the sidebar sections', () => {
    beforeEach(() => {
        document.cookie = 'nav_collapsed=; path=/; max-age=0';
        menu();
    });

    it('shuts a section, and says so three ways at once', () => {
        heading('work-files').click();

        // The class draws the arrow, hidden takes the links out of the page for
        // everybody including find-in-page, and aria-expanded is what a screen
        // reader reads. One of the three moving alone is a section that tells
        // two different people two different things.
        expect(heading('work-files').getAttribute('aria-expanded')).toBe('false');
        expect(items('work-files').hidden).toBe(true);
        expect(document.querySelector('[data-nav-group="work-files"]').classList.contains('is-shut')).toBe(true);
    });

    it('remembers what was shut, because the sidebar is rebuilt on the next visit', () => {
        heading('work-files').click();

        expect(written()).toEqual(['work-files']);
    });

    it('opens it again and stops remembering it', () => {
        heading('work-files').click();
        heading('work-files').click();

        expect(heading('work-files').getAttribute('aria-expanded')).toBe('true');
        expect(items('work-files').hidden).toBe(false);
        expect(written()).toEqual([]);
    });

    /*
     * The one that went wrong when this wrote the cookie from the section it had
     * just toggled rather than from everything currently shut: the second
     * section replaced the first, and the first sprang open on the next page.
     */
    it('keeps every shut section, not only the last one', () => {
        heading('work-files').click();
        heading('reports').click();

        expect(written().sort()).toEqual(['reports', 'work-files']);
    });

    it('leaves one shut when the other is opened again', () => {
        heading('work-files').click();
        heading('reports').click();
        heading('reports').click();

        expect(written()).toEqual(['work-files']);
    });

    it('does nothing to a click on a link, which has somewhere of its own to go', () => {
        document.querySelector('.nav-link').click();

        expect(written()).toEqual([]);
        expect(items('work-files').hidden).toBe(false);
    });
});
