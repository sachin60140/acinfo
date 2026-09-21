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
 * A receipt for a payment just booked, and where the account stands after it.
 *
 * The customer's own reference goes in — the UPI or cheque number is how they
 * find the payment in their bank — but the particular on the entry never
 * does: it is the office's description, written for the office.
 *
 * @param {{name:string, amount:number, dateLabel?:string, mode?:string,
 *          reference?:string, balance:number, todayLabel?:string}} receipt
 *          balance: today's, signed, positive when they still owe and negative
 *          when they are in advance
 */
export function receiptMessage({ name, amount, dateLabel = '', mode = '', reference = '', balance = 0, todayLabel = '' }) {
    if (! (Number(amount) > 0.005)) {
        return '';
    }

    const left = Number(balance) || 0;

    const lines = [
        `*Payment received — ${name}*`,
        [`${rupees(amount)} received`, dateLabel && `on ${dateLabel}`, mode && `(${mode})`].filter(Boolean).join(' '),
    ];

    if (reference && reference.trim()) {
        lines.push(`Ref: ${reference.trim()}`);
    }

    lines.push('');

    // Dated, because the payment above may not be today's and the balance is.
    const asOf = todayLabel ? ` as of ${todayLabel}` : '';

    if (left > 0.005) {
        lines.push(`Balance due${asOf}: ${rupees(left)}`);
    } else if (left < -0.005) {
        lines.push(`Paid in advance${asOf}: ${rupees(-left)}`);
    } else {
        lines.push(`Your account is fully settled${asOf}.`);
    }

    lines.push('');
    lines.push('Thank you.');

    return lines.join('\n');
}

/**
 * Work approved, as one message to the customer whose file it is.
 *
 * Which works came through and on what day, anything on the same file still in
 * progress, whether the papers are ready to collect, and the balance. Built
 * from the fields named here only: nothing about a vendor, and never the
 * remark typed on the approval, which is the office's own.
 *
 * @param {{customer:string, vehicle?:string, fileNo?:string,
 *          works:Array<{work:string, on?:string}>, pending?:string[],
 *          papersReady?:boolean, balance?:number}} notice
 */
export function approvalMessage(notice) {
    const works = notice.works ?? [];

    if (! works.length) {
        return '';
    }

    const lines = [`*Work approved — ${notice.customer}*`];

    const which = [notice.vehicle, notice.fileNo].filter(Boolean).join(' · ');

    if (which) {
        lines.push(which);
    }

    lines.push('');

    for (const one of works) {
        lines.push(`✓ ${one.work} approved${one.on ? ` on ${one.on}` : ''}`);
    }

    const pending = notice.pending ?? [];

    if (pending.length) {
        lines.push(`Still in progress: ${pending.join(', ')}`);
    }

    const due = Number(notice.balance) || 0;
    const closing = [];

    if (notice.papersReady) {
        closing.push('Your papers are ready to collect.');
    }

    if (due > 0.005) {
        closing.push(`Balance due: ${rupees(due)}`);
    }

    if (closing.length) {
        lines.push('');
        lines.push(...closing);
    }

    lines.push('');
    lines.push('Thank you.');

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
