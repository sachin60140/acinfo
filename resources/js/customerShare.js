/*
 * The messages a customer is sent on WhatsApp about money and finished work.
 *
 * Two of them. One goes from Not Yet Collected: the customer's finished files,
 * what is still owed on each, and whether their papers are waiting to be
 * picked up. The other goes from their statement: the balance, and nothing
 * else.
 *
 * What they leave out matters as much as what they say. Neither ever mentions
 * a vendor — a customer is never told who does the office's work — and neither
 * reads anything the office wrote for itself. Each line is built from fields
 * named here, one at a time, so a row that grows a new field tomorrow cannot
 * start leaking it into a chat.
 */
import { money } from './money';

const rupees = (value) => `₹${money(value)}`;

/**
 * Finished work that is still owed for, as one message to one customer.
 *
 * Longest finished first, because that is the charge the call is about. Work
 * still running — the report can be asked to show everything owing — is said
 * to be in progress rather than finished, and then the heading does not claim
 * the work is ready either.
 *
 * @param {string} customer
 * @param {Array<{file_no:string, registration_no?:string, works?:string,
 *                finished?:string|null, finished_raw?:string|null,
 *                handed_over_raw?:string, outstanding:number}>} rows
 * @param {string} today dd-mm-yyyy
 */
export function readyMessage(customer, rows, today = '') {
    if (! rows.length) {
        return '';
    }

    // Sorted on the ISO date, not the one shown; unfinished work goes last.
    const ordered = [...rows].sort((a, b) => {
        const x = a.finished_raw || '9999-12-31';
        const y = b.finished_raw || '9999-12-31';

        return x.localeCompare(y);
    });

    const allFinished = ordered.every((row) => row.finished);
    const papersHere = ordered.some((row) => row.finished && ! row.handed_over_raw);
    const due = ordered.reduce((sum, row) => sum + (Number(row.outstanding) || 0), 0);
    const count = ordered.length;

    const lines = [allFinished ? `*Your work is ready — ${customer}*` : `*Payment due — ${customer}*`];

    lines.push([`${count} ${count === 1 ? 'file' : 'files'}`, today && `as of ${today}`].filter(Boolean).join(' · '));

    ordered.forEach((row, i) => {
        lines.push('');

        // The vehicle first: it is what a customer knows their file by.
        const heading = [row.registration_no, row.works].filter(Boolean).join(' — ');
        lines.push(`${i + 1}. *${heading || row.file_no}*`);

        lines.push(`   ${[
            row.file_no,
            row.finished ? `completed ${row.finished}` : 'in progress',
            `${rupees(row.outstanding)} due`,
        ].join(' · ')}`);

        if (row.finished && ! row.handed_over_raw) {
            lines.push('   Papers ready to collect');
        }
    });

    lines.push('');
    lines.push(`*Total due: ${rupees(due)}*`);
    lines.push('');
    lines.push(papersHere
        ? 'Please collect your papers and clear the balance. Thank you.'
        : 'Please clear the balance at your convenience. Thank you.');

    return lines.join('\n');
}

/**
 * The balance on a customer's account, as a reminder.
 *
 * The whole balance and not a list of files: it is the one figure the
 * statement and the customer's own page agree on, and it includes what no
 * file carries — an opening balance, a charge booked by hand.
 *
 * @param {string} customer
 * @param {number} amount what they owe; nothing is said about a balance in their favour
 * @param {string} today dd-mm-yyyy
 */
export function balanceMessage(customer, amount, today = '') {
    if (! (Number(amount) > 0.005)) {
        return '';
    }

    return [
        `*Balance reminder — ${customer}*`,
        `${rupees(amount)} due${today ? ` as of ${today}` : ''}`,
        '',
        'Please clear the balance at your earliest convenience. '
            + 'If you have paid it already, please ignore this message. Thank you.',
    ].join('\n');
}
