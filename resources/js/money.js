/*
 * How money is written, in one place.
 *
 * This mirrors PartyLedgerModel::formatBalance() on the server. Two
 * implementations of the same rule is one too many, but the server renders the
 * first paint and the browser renders every change after it, so both need it.
 * Keeping them side by side and identical is the next best thing — if one
 * changes, change the other.
 */

/**
 * A plain amount: grouped, always two decimals, never signed.
 *
 * Grouped in threes, because that is what PHP's number_format does and the
 * server prints half of every converted screen — the summary tiles above a grid
 * and every screen not yet converted. toLocaleString('en-IN') was used here
 * first and groups the Indian way, which put "12,34,567.89" in the table and
 * "1,234,567.89" in the tile above it, on the same screen, for the same money.
 * Either convention is defensible; disagreeing on one page is not.
 */
export function money(value) {
    const parsed = Number(value) || 0;
    const [whole, fraction] = Math.abs(parsed).toFixed(2).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    // An amount too small to show is not a negative amount: number_format(-0.001)
    // is "0.00", and "-0.00" in a column of figures reads as a mistake.
    const negative = parsed < 0 && Number(whole + fraction) !== 0;

    return `${negative ? '-' : ''}${grouped}.${fraction}`;
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
