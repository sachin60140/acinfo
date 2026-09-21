/*
 * The list a vendor is sent on WhatsApp: the files they are holding, and for
 * how long.
 *
 * The point of it is chasing, so it is ordered for chasing — the file that has
 * been out longest comes first, because that is the one the call is about.
 *
 * What it leaves out matters as much as what it says. It never names the
 * customer a file came from. The office keeps a vendor from learning who its
 * customers are for the same reason it keeps a customer from learning who does
 * its work, and a list forwarded once is a list forwarded anywhere. Nor money:
 * what a file was billed at is between the office and the customer.
 */

/**
 * @param {string} vendor the vendor's name, as the band above their files says it
 * @param {Array<{file_no:string, registration_no?:string, work_type?:string,
 *                dispatched?:string, dispatched_sort?:string, days_out?:string}>} rows
 * @param {string} today the date the list was drawn, dd-mm-yyyy
 */
export function vendorFilesMessage(vendor, rows, today = '') {
    if (! rows.length) {
        return '';
    }

    /*
     * Longest out first. Sorted on the ISO date each row carries rather than
     * the dd-mm-yyyy one it shows, which orders by day of the month; a file
     * with no dispatch date at all goes to the bottom rather than the top.
     */
    const ordered = [...rows].sort((a, b) => {
        const x = a.dispatched_sort || '9999-12-31';
        const y = b.dispatched_sort || '9999-12-31';

        return x.localeCompare(y);
    });

    const count = ordered.length;
    const lines = [`*Files with you — ${vendor}*`];

    lines.push([`${count} ${count === 1 ? 'file' : 'files'}`, today && `as of ${today}`].filter(Boolean).join(' · '));

    ordered.forEach((row, i) => {
        lines.push('');

        // The vehicle first: it is what a vendor knows a file by.
        const heading = [row.registration_no, row.work_type].filter(Boolean).join(' — ');
        lines.push(`${i + 1}. *${heading || row.file_no}*`);

        const detail = [
            row.file_no,
            row.dispatched && `dispatched ${row.dispatched}`,
            row.days_out,
        ].filter(Boolean).join(' · ');

        if (detail) {
            lines.push(`   ${detail}`);
        }
    });

    lines.push('');
    lines.push('Please let us know where each of these has got to.');

    return lines.join('\n');
}
