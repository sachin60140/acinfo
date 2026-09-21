/*
 * The pending-papers message a customer is sent on WhatsApp.
 *
 * Kept apart from the screen that uses it, because what it says to a customer
 * is the part that has to be right: which papers, for which file, and never a
 * word the office wrote for itself.
 *
 * Two notes live on every pending paper. `note` is written for the customer —
 * "Buyer has not signed Form 30" — and is already shown on their own page.
 * `office_note` is the office's own and never leaves it. Only the first is read
 * here, and the second is never so much as looked at, so there is no way for a
 * change elsewhere to start leaking it into a chat.
 */

/**
 * The papers, grouped by the file they belong to, as a message.
 *
 * Numbered rather than bulleted, so a customer on the phone can say "number
 * three" and both ends mean the same paper. WhatsApp reads *stars* as bold.
 *
 * @param {Array<{file_no:string, registration_no?:string, customer?:string, paper:string, note?:string}>} rows
 */
export function pendingMessage(rows) {
    if (! rows.length) {
        return '';
    }

    const files = new Map();

    for (const row of rows) {
        const key = row.file_no;

        if (! files.has(key)) {
            files.set(key, { file_no: row.file_no, vehicle: row.registration_no, customer: row.customer, papers: [] });
        }

        files.get(key).papers.push({ paper: row.paper, note: row.note });
    }

    const customers = new Set(rows.map((row) => row.customer).filter(Boolean));
    const oneCustomer = customers.size === 1 ? [...customers][0] : null;

    const lines = ['*Papers still needed*'];

    // Named once at the top when it is all one customer's; otherwise each file
    // says whose it is, because the same message can reach a group.
    if (oneCustomer) {
        lines.push(oneCustomer);
    }

    for (const file of files.values()) {
        lines.push('');

        const heading = [file.file_no, file.vehicle].filter(Boolean).join(' · ');
        lines.push(`*${heading}*`);

        if (! oneCustomer && file.customer) {
            lines.push(file.customer);
        }

        file.papers.forEach((one, i) => {
            const note = (one.note || '').trim();
            lines.push(`${i + 1}. ${one.paper}${note ? ` — ${note}` : ''}`);
        });
    }

    lines.push('');
    lines.push('Please send these so we can go ahead with the work.');

    return lines.join('\n');
}

// Moved to ./whatsapp so other screens can use them; still available from here.
export { copyText, whatsappNumber, whatsappUrl } from './whatsapp';
