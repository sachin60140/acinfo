/*
 * The names the export libraries publish themselves under.
 *
 * These two lines cannot be checked by reading this repository: they are a
 * contract with pdfmake and SheetJS, and the only way to know a name is right
 * is to load the library and look. Getting one wrong fails silently — the call
 * throws a TypeError, the catch reports it, and the button simply never works.
 *
 * That is exactly what happened. The PDF export called window.pdfmake for as
 * long as it existed; the global is window.pdfMake, so every click threw and
 * reported "PDF export unavailable offline" — sending anyone who looked
 * towards the network rather than the typo.
 *
 * Verified against the real files at the pinned versions:
 *   pdfmake 0.2.10  ->  window.pdfMake  (and vfs_fonts.js writes pdfMake.vfs)
 *   SheetJS 0.20.2  ->  window.XLSX
 */
import { readFileSync } from 'node:fs';
import { describe, expect, test } from 'vitest';

// A path from the project root, not import.meta.url: under jsdom that resolves
// to an http URL and readFileSync will not take one.
const raw = readFileSync('resources/js/components/DataGrid.vue', 'utf8');

/*
 * Comments stripped first. Half of these assertions are about names that must
 * NOT appear, and the comments in that file explain the bug by naming the very
 * things being banned — so a check over the raw text fails on its own
 * documentation. What matters is what the code calls.
 */
const source = raw
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:])\/\/.*$/gm, '$1');

describe('the globals the export libraries create', () => {
    test('pdfmake is called by the name it actually publishes', () => {
        expect(source).toContain('window.pdfMake');

        // The lowercase spelling is undefined, and reads as correct.
        expect(source).not.toMatch(/window\.pdfmake\b/);
    });

    test('sheetjs is called by the name it actually publishes', () => {
        expect(source).toContain('window.XLSX');
    });

    /*
     * Load order matters: vfs_fonts.js does `this.pdfMake = this.pdfMake || {}`
     * and hangs the fonts off it, so loading it first and the library second
     * would replace the object and take the fonts with it.
     */
    test('pdfmake is loaded before its fonts', () => {
        const library = source.indexOf('pdfmake.min.js');
        const fonts = source.indexOf('vfs_fonts.js');

        expect(library).toBeGreaterThan(-1);
        expect(fonts).toBeGreaterThan(library);
    });

    /*
     * A failure inside these functions is not a failure to reach the network,
     * and saying so is what hid the bug above.
     */
    test('an export failure does not claim to know why', () => {
        expect(source).not.toContain('unavailable offline');
        expect(source).toContain("console.error('PDF export failed'");
        expect(source).toContain("console.error('Excel export failed'");
    });
});
