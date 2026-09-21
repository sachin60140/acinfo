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

/**
 * A mobile number the way wa.me wants it: country code, no plus, no spaces.
 *
 * Indian numbers only, because that is every number on these books, and only
 * ones that can be a mobile — a ten-digit number starting 6 to 9. A landline
 * has no WhatsApp, and opening a chat with one fails somewhere the office
 * cannot see why. Anything else is null rather than guessed at: a message sent
 * to the wrong person is worse than one the office addresses by hand.
 *
 * @param {string|null|undefined} mobile
 * @returns {string|null}
 */
export function whatsappNumber(mobile) {
    const digits = String(mobile ?? '').replace(/\D/g, '');

    let local = null;

    if (digits.length === 10) {
        local = digits;
    } else if (digits.length === 11 && digits.startsWith('0')) {
        local = digits.slice(1);
    } else if (digits.length === 12 && digits.startsWith('91')) {
        local = digits.slice(2);
    }

    return local && /^[6-9]\d{9}$/.test(local) ? `91${local}` : null;
}

/**
 * Where to send it. With a number the chat opens on that customer; without
 * one WhatsApp asks who it is for. Either way the office presses Send itself —
 * this fills the message in, it never sends anything.
 */
export function whatsappUrl(number, text) {
    const message = `text=${encodeURIComponent(text)}`;

    return number ? `https://wa.me/${number}?${message}` : `https://wa.me/?${message}`;
}

/**
 * Onto the clipboard, the modern way where the page is allowed to and the old
 * way where it is not — a page served over plain http outside localhost has no
 * clipboard API at all, and "Copy" doing nothing there is worse than it working.
 *
 * @returns {Promise<boolean>} whether it got there
 */
export async function copyText(text) {
    try {
        if (navigator.clipboard?.writeText && window.isSecureContext) {
            await navigator.clipboard.writeText(text);

            return true;
        }
    } catch {
        // Refused — fall through to the old way.
    }

    const box = document.createElement('textarea');
    box.value = text;
    box.setAttribute('readonly', '');
    box.style.position = 'fixed';
    box.style.opacity = '0';
    document.body.appendChild(box);
    box.select();

    let copied = false;

    try {
        copied = document.execCommand('copy');
    } catch {
        copied = false;
    }

    box.remove();

    return copied;
}
