/*
 * How money is written, in one place.
 *
 * This mirrors PartyLedgerModel::formatBalance() on the server. Two
 * implementations of the same rule is one too many, but the server renders the
 * first paint and the browser renders every change after it, so both need it.
 * Keeping them side by side and identical is the next best thing — if one
 * changes, change the other.
 */

const LOCALE = 'en-IN';

/**
 * A plain amount: grouped, always two decimals, never signed.
 */
export function money(value) {
    return (Number(value) || 0).toLocaleString(LOCALE, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/**
 * A balance the way a ledger prints it: magnitude plus the side it falls on,
 * never a minus sign. "1,200.00 Cr" reads correctly to anyone who keeps books;
 * "-1,200.00" has to be interpreted against a convention first.
 */
export function balance(value) {
    const parsed = Number(value) || 0;
    const shown = money(Math.abs(parsed));

    // Rounds to nothing on either side — a settled account has no side.
    if (Math.abs(parsed) < 0.005) {
        return shown;
    }

    return `${shown} ${parsed < 0 ? 'Cr' : 'Dr'}`;
}

/**
 * Which way a balance leans, for colouring it.
 */
export function side(value) {
    const parsed = Number(value) || 0;

    if (Math.abs(parsed) < 0.005) {
        return 'nil';
    }

    return parsed < 0 ? 'cr' : 'dr';
}
