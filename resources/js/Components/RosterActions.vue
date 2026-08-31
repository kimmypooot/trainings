<script setup>
import AppButton from '@/Components/AppButton.vue';

/**
 * Everything you can *do* to one registration from the roster.
 *
 * This exists because the roster renders its participants twice — a table on a
 * wide screen, stacked cards on a narrow one — and the two had drifted. The
 * table offered Record Payment, Cancel, the OR number and the "payment
 * awaiting review" note; the cards offered none of them. So a collecting
 * officer standing at a venue with a phone could not take a payment, and
 * nobody on a phone could cancel a registration or see that one had been paid.
 * Not a styling difference — a different set of things the roster could do,
 * depending on the width of the window it was open in.
 *
 * One component now answers "what can be done here", and the two layouts are a
 * presentational choice on top of that answer. A new action added here appears
 * in both, which is the only arrangement in which they cannot drift again.
 *
 * The predicates stay on the page rather than moving in here: whether a payment
 * may be recorded folds in the viewer's collecting-officer designation and the
 * training's own fee settings, which is page knowledge. This component is told,
 * it does not decide.
 */
defineProps({
    registration: { type: Object, required: true },
    /*
     * `row` is a table cell: actions on one line, separated by hairlines,
     * never wrapping. `card` is the mobile block: a wrapping flex row with no
     * separators, and tap targets that do not sit shoulder to shoulder.
     */
    layout: {
        type: String,
        default: 'row',
        validator: (value) => ['row', 'card'].includes(value),
    },
    /** Whether this viewer may post a counter-payment against this row. */
    canRecordPayment: { type: Boolean, default: false },
    /** Whether this registration is still in a state that can be given up. */
    cancellable: { type: Boolean, default: false },
});

defineEmits(['decide', 'complete', 'issue', 'record-payment', 'cancel']);

const link = 'rounded text-xs font-semibold hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue';
</script>

<template>
    <div
        :class="
            layout === 'row'
                ? 'whitespace-nowrap'
                : 'flex flex-wrap items-center justify-end gap-x-3 gap-y-2'
        "
    >
        <template v-if="registration.status === 'pending'">
            <button type="button" :class="[link, 'text-success']" @click="$emit('decide', registration, 'approved')">
                Approve
            </button>
            <span v-if="layout === 'row'" class="px-2 text-csc-line">|</span>
            <button type="button" :class="[link, 'text-warning']" @click="$emit('decide', registration, 'waitlisted')">
                Waitlist
            </button>
            <span v-if="layout === 'row'" class="px-2 text-csc-line">|</span>
            <button type="button" :class="[link, 'text-danger']" @click="$emit('decide', registration, 'rejected')">
                Reject
            </button>
        </template>

        <AppButton
            v-else-if="registration.status === 'approved'"
            size="sm"
            variant="ghost"
            @click="$emit('complete', registration)"
        >
            {{ registration.can_complete ? 'Mark Complete' : 'Complete (Override)' }}
        </AppButton>

        <template v-else-if="registration.status === 'completed'">
            <span v-if="registration.certificate_number" class="font-mono text-2xs text-csc-ink-subtle">
                {{ registration.certificate_number }}
            </span>
            <!--
                A promissory note gets someone into the room but not onto a
                certificate, so the button is replaced by the reason rather
                than left to fail.
            -->
            <span v-else-if="!registration.fee_cleared" class="text-2xs text-warning">Fee outstanding</span>
            <AppButton v-else size="sm" variant="ghost" @click="$emit('issue', registration.id)">
                Issue Certificate
            </AppButton>
        </template>

        <span v-else class="text-xs text-csc-ink-subtle">—</span>

        <template v-if="canRecordPayment">
            <span v-if="layout === 'row'" class="px-2 text-csc-line">|</span>
            <button type="button" :class="[link, 'text-csc-blue']" @click="$emit('record-payment', registration)">
                Record Payment
            </button>
        </template>
        <span
            v-else-if="registration.payment.or_number"
            class="font-mono text-2xs text-csc-ink-subtle"
            :class="layout === 'row' ? 'ml-2' : ''"
            :title="`Paid by ${registration.payment.method}`"
        >
            {{ registration.payment.or_number }}
        </span>
        <span
            v-else-if="registration.payment.awaiting_review"
            class="text-2xs text-warning"
            :class="layout === 'row' ? 'ml-2' : ''"
        >
            Payment awaiting review
        </span>

        <template v-if="cancellable">
            <span v-if="layout === 'row'" class="px-2 text-csc-line">|</span>
            <button type="button" :class="[link, 'text-danger']" @click="$emit('cancel', registration)">
                Cancel
            </button>
        </template>
    </div>
</template>
