<script setup>
/*
 * The dashboard a client sees when they sign in.
 *
 * Read-only, and deliberately one figure. UserController::userdashboard() sums
 * the client's ledger and hands the total over; nothing here re-derives money,
 * only the rendering moved.
 *
 * A client arrives with two questions — what is my balance, and what made it.
 * The tile answers the first and the link answers the second, and that is the
 * whole screen.
 */
import { computed } from 'vue';
import { money } from '../money';

const props = defineProps({
    available: { type: Number, default: 0 },
    asOn: { type: String, default: '' },
    statementUrl: { type: String, required: true },
});

/*
 * Rounded before it is compared as well as before it is shown. A ledger that
 * nets to a fraction of a paisa is settled, and a float landing a hair below
 * zero would otherwise have the screen tell a customer they owe money.
 */
const nett = computed(() => Math.round(props.available * 100) / 100);

const settled = computed(() => nett.value === 0);
const inCredit = computed(() => nett.value > 0);

/*
 * Magnitude only — a balance is never written with a minus sign.
 *
 * balance() would add the Dr/Cr the office screens use, and it is not used
 * here: those name the sides of the office's books, where money held for a
 * client is a credit. The customer reading this screen thinks of that same
 * money as theirs, so the direction is said in words below instead of in
 * accounting shorthand that would read backwards to them.
 */
const figure = computed(() => money(Math.abs(nett.value)));

const direction = computed(() => {
    if (settled.value) {
        return 'Nothing outstanding either way.';
    }

    return inCredit.value ? 'In your favour.' : 'Payable by you.';
});
</script>

<template>
    <div class="ui ud">
        <div class="ui-stats">
            <div class="ui-stat ui-stat--accent">
                <span class="ui-stat__label">Total Available Balance</span>
                <span class="ui-stat__value ui-money">{{ figure }}</span>
                <span class="ui-stat__note" :class="{ 'ud-note--due': !settled && !inCredit }">
                    {{ direction }}
                </span>
                <!-- The old tile said "| Today", which reads as a figure for the
                     day when it is the balance of the whole ledger as it stands.
                     The date is the server's, so it cannot disagree with the sum
                     it labels. -->
                <span v-if="asOn" class="ui-stat__note">As on {{ asOn }}</span>
            </div>
        </div>

        <!-- The one thing behind the figure. It is in the sidebar as well, which
             on a phone is folded away behind the toggle — and this is the
             question the figure raises. -->
        <a :href="statementUrl" class="ui-btn ud-more">
            <i class="bi bi-journal-text"></i> View my statement
        </a>
    </div>
</template>

<style>
.ud {
    display: flex;
    flex-direction: column;
    gap: var(--s-4);
}

/* One tile, so the auto-fit grid would stretch it across the whole page and
   leave a single figure adrift in a very wide box. Held to about the third of a
   row the same tile gets on the admin dashboard. */
.ud .ui-stats {
    grid-template-columns: minmax(0, 22rem);
}

/* The 28px the dashboard figures have always been — .dashboard .info-card h6 in
   the NiceAdmin stylesheet, which is what the admin tiles next door still use.
   The two screens are one product and the headline number is the loudest thing
   on either of them. */
.ud .ui-stat__value {
    font-size: var(--t-2xl);
    line-height: 1.2;
}

/* Money owed is the one state worth flagging; the figure itself stays in the
   neutral ink every other tile uses, so the colour is on the sentence that
   explains it rather than on a number that has no sign. */
.ud-note--due {
    color: var(--cr-700);
    font-weight: 600;
}

.ud-more {
    align-self: flex-start;
}

/* No table on this screen, so the row-to-card rule has nothing to convert. The
   tile is already a card and the grid above collapses on its own; the button
   goes full width so it is a comfortable target on a phone. */
@media (max-width: 991.98px) {
    .ud-more {
        align-self: stretch;
    }
}
</style>
