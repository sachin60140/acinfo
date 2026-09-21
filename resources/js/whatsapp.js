/*
 * Talking to somebody on WhatsApp from a screen in this application.
 *
 * Shared by every screen that sends a list to a customer or a vendor, so the
 * rules about whose number a chat opens on, and the promise that nothing is
 * ever sent without the office pressing Send, live in one place.
 */

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
