<script setup>
/**
 * How much of the SME evaluation a participant still owes.
 *
 * Third of the table/card pair — see RosterActions. This one was missing from
 * the mobile cards entirely, so the response rate a field office is chasing was
 * invisible to anyone working the roster on a phone.
 *
 * The denominator is `Training::evaluationDays()`, computed server-side and
 * already folded into `expected` — never `duration_days`, because a session an
 * expert carries across two days is rated once, at its end.
 */
defineProps({
    evaluation: { type: Object, required: true },
    /** `card` prefixes the figure with a label; a table column has a header. */
    layout: {
        type: String,
        default: 'row',
        validator: (value) => ['row', 'card'].includes(value),
    },
});
</script>

<template>
    <template v-if="evaluation.expected">
        <p
            class="text-xs font-semibold"
            :class="evaluation.outstanding.length ? 'text-warning' : 'text-success'"
        >
            <span v-if="layout === 'card'" class="font-normal text-csc-ink-subtle">Evaluations · </span>
            {{ evaluation.submitted }} of {{ evaluation.expected }}
        </p>
        <!--
            Naming the days is the point: "day 2 not submitted" is what an
            office can act on, where a bare 1/3 only says something is missing.
            The prop keeps the name `outstanding` because that is the service's
            word for the days still owed; the reader gets the plain one.
        -->
        <p v-if="evaluation.outstanding.length" class="mt-0.5 text-2xs text-csc-ink-subtle">
            Day{{ evaluation.outstanding.length === 1 ? '' : 's' }}
            {{ evaluation.outstanding.join(', ') }} not submitted
        </p>
    </template>

    <span v-else-if="layout === 'row'" class="text-xs text-csc-ink-subtle">—</span>
</template>
