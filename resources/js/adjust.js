/*
 * Adjusting a payment against files: which of the party's files it is for.
 *
 * Shared by the Entry screen, where a payment is typed, and the Adjust screen,
 * where a payment already saved has its files set or changed. The state lives
 * with the screen that calls this — the Entry screen resets it with the rest of
 * its form — and AdjustFiles.vue draws it.
 *
 * The server checks every line again and refuses what does not fit; what is
 * worked out here is only so the screen can say so before Save is pressed.
 */
import { computed, reactive, ref, watch } from 'vue';
import { money } from './money';

/**
 * @param {object} options
 * @param {string} options.billsUrl   the party's files, with __ID__ for the party
 * @param {object} options.initialAlloc  amounts to start with, by file
 * @param {() => (string|number|null)} options.partyId
 * @param {() => string} options.partyName
 * @param {() => number} options.amount  the payment's amount
 * @param {() => boolean} options.active  whether the section is on screen at all
 * @param {number|null} [options.except]  a saved payment being re-adjusted: its
 *     own adjustments and its money are left out of what the files show
 * @param {Array<{id: number, fileNo: string, vehicle: string, amount: number, why: ?string}>} [options.kept]
 *     what that payment is adjusted against now, each of which may stay as it
 *     is or be lowered even where the file is no longer open
 */
export function useAdjust(options) {
    const alloc = reactive({ ...options.initialAlloc });
    const bills = ref([]);
    const billsState = ref('idle');

    /*
     * A saved payment's own lines. Found in review: a line on a file cancelled
     * or moved since — which the ledger keeps, for when the file is charged
     * again — is on no list of open files, so the screen took it off by itself
     * and the next save let it go. It is drawn from here instead, and only the
     * office can lower it or let it go.
     */
    const kept = new Map((options.kept ?? []).map((line) => [String(line.id), line]));
    const keptAmount = (id) => Number(kept.get(String(id))?.amount) || 0;

    /*
     * Every open file is fetched once; which of them are drawn is worked out here.
     *
     * Files already covered by money on account are hidden unless asked for —
     * except one with an amount against it, which is always drawn and always
     * posted. Worked out from the amounts themselves, so nothing a reader does —
     * narrowing the list, Clear, Reset, a refused save putting amounts back — can
     * leave an amount they typed out of sight and quietly not sent. (Found over
     * two reviews: a list fetched afresh as the toggle moved could, several ways.)
     */
    const showCovered = ref(false);
    const dropped = ref(false);

    /*
     * Covered files kept on screen because something was typed in them. Found in
     * the fourth review: drawn only while their amount was above nothing, a row
     * vanished the moment its box was emptied to be retyped, and what was typed
     * next went nowhere. Pinned when the list loads, on Reset, and when the list
     * is narrowed; let go only when the party changes.
     */
    const pinned = ref(new Set());

    function pinTyped() {
        const next = new Set(pinned.value);

        for (const key of Object.keys(alloc)) {
            if (String(alloc[key] ?? '') !== '') {
                next.add(String(key));
            }
        }

        pinned.value = next;
    }

    const amount = computed(() => Number(options.amount()) || 0);
    const priced = computed(() => amount.value > 0);
    const partyName = computed(() => options.partyName() ?? '');

    /*
     * The party's open files. A slow answer for a party since changed is thrown
     * away rather than shown under the wrong name.
     */
    let asked = 0;

    async function loadBills() {
        bills.value = [];

        const partyId = options.partyId();

        if (! options.active() || ! options.billsUrl || ! partyId) {
            billsState.value = 'idle';

            return;
        }

        const ticket = ++asked;
        billsState.value = 'loading';

        try {
            const url = options.billsUrl.replace('__ID__', String(partyId)) + '?all=1'
                + (options.except ? `&except=${encodeURIComponent(String(options.except))}` : '');

            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (! response.ok) {
                throw new Error(String(response.status));
            }

            const data = await response.json();

            if (ticket === asked) {
                bills.value = data.bills ?? [];
                billsState.value = 'ready';
                dropUnlisted();
                pinTyped();
            }
        } catch {
            if (ticket === asked) {
                billsState.value = 'failed';
            }
        }
    }

    /*
     * An amount against a file that is not open at all — a refused save put it
     * back, or the file has since been settled, cancelled or given to someone
     * else — cannot be adjusted. It is taken off, and the page says so, rather
     * than kept out of sight.
     */
    function dropUnlisted() {
        const listed = new Set(bills.value.map((bill) => String(bill.id)));
        const gone = Object.keys(alloc).filter((key) => Number(alloc[key]) > 0 && ! listed.has(String(key)) && ! kept.has(String(key)));

        for (const key of gone) {
            delete alloc[key];
        }

        if (gone.length) {
            dropped.value = true;
        }
    }

    // Another party's files are not this one's: what was typed against them goes.
    function forget() {
        for (const key of Object.keys(alloc)) {
            delete alloc[key];
        }

        showCovered.value = false;
        dropped.value = false;
        pinned.value = new Set();
    }

    // Back to what the page loaded with.
    function restore(initial) {
        for (const key of Object.keys(alloc)) {
            delete alloc[key];
        }

        Object.assign(alloc, initial);
        showCovered.value = false;
        dropped.value = false;
    }

    // Narrowed: whatever has something in its box stays where the reader left it.
    watch(showCovered, (on) => {
        if (! on) {
            pinTyped();
        }
    });

    const amountOf = (bill) => Number(alloc[bill.id]) || 0;

    // The payment's own lines on files the list does not carry.
    const keptRows = computed(() => {
        if (billsState.value !== 'ready') {
            return [];
        }

        const listed = new Set(bills.value.map((bill) => String(bill.id)));

        return [...kept.values()].filter((line) => ! listed.has(String(line.id)));
    });

    // As much as is open, or as much as the payment has on it already.
    const limitOf = (bill) => Math.max(Number(bill.open) || 0, keptAmount(bill.id));

    const keptOn = (bill) => keptAmount(bill.id);

    const isCovered = (bill) => Number(bill.due) <= 0.005;

    // How many are hidden by default; the toggle appears only when there are some.
    const coveredCount = computed(() => bills.value.filter(isCovered).length);

    const visibleBills = computed(() =>
        bills.value.filter((bill) => showCovered.value || ! isCovered(bill) || pinned.value.has(String(bill.id)))
    );

    // What is posted, and only that: a line is posted only with an amount above
    // nothing, so nothing else is counted either.
    const allocated = computed(() => [...bills.value, ...keptRows.value].reduce((sum, bill) => sum + Math.max(0, amountOf(bill)), 0));

    const onAccount = computed(() => Math.max(0, amount.value - allocated.value));

    const overAllocated = computed(() => allocated.value > amount.value + 0.005);

    const overOpen = (bill) => amountOf(bill) > limitOf(bill) + 0.005;

    // A line on a file no longer open can stay or be lowered, not raised.
    const overKept = (line) => amountOf(line) > keptAmount(line.id) + 0.005;

    // Said before Save is pressed; the server refuses the same, and keeps the typing.
    const problem = computed(() => {
        if (! options.active()) {
            return '';
        }

        const over = bills.value.filter(overOpen);

        if (over.length) {
            return `${over.map((bill) => bill.fileNo).join(', ')}: more than is open on the file.`;
        }

        const raised = keptRows.value.filter(overKept);

        if (raised.length) {
            return `${raised.map((line) => line.fileNo).join(', ')}: can be kept or lowered, not raised — nothing more is open on it.`;
        }

        if (overAllocated.value) {
            return `The files come to ${money(allocated.value)}, more than the payment of ${money(amount.value)}.`;
        }

        return '';
    });

    /*
     * Oldest first, which is what the payment would do if nobody said: against
     * what is still due, in the order the ledger reaches the files. Found in
     * review: filled against what was open, it put the payment on files already
     * paid by money on account, and the customer's receipt named them. And a bill
     * typed into the ledger with no file takes its turn in that queue too, so
     * what is owed on those ahead of each file is stepped over first.
     */
    function fillOldest() {
        // What is kept on files not listed stays, and is not given twice.
        let left = amount.value - keptRows.value.reduce((sum, line) => sum + Math.max(0, amountOf(line)), 0);
        let passed = 0;

        for (const bill of bills.value) {
            const ahead = Number(bill.ahead) || 0;

            left = Math.max(0, left - Math.max(0, ahead - passed));
            passed = Math.max(passed, ahead);

            const take = Math.min(Number(bill.due), left);
            alloc[bill.id] = take > 0.005 ? take.toFixed(2) : '';
            left -= Math.max(0, take);
        }
    }

    function clear() {
        for (const bill of [...bills.value, ...keptRows.value]) {
            alloc[bill.id] = '';
        }
    }

    function full(bill) {
        alloc[bill.id] = limitOf(bill).toFixed(2);
    }

    // A line on a file not listed, back to what the payment has on it.
    function keep(line) {
        alloc[line.id] = keptAmount(line.id).toFixed(2);
    }

    return {
        alloc,
        bills,
        billsState,
        showCovered,
        dropped,
        pinned,
        priced,
        partyName,
        coveredCount,
        visibleBills,
        allocated,
        onAccount,
        problem,
        amountOf,
        overOpen,
        overKept,
        keptRows,
        keptOn,
        keep,
        loadBills,
        forget,
        restore,
        fillOldest,
        clear,
        full,
    };
}
