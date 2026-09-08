<script setup>
/**
 * A row of chips that narrows the list below it to one status.
 *
 * There were seven hand-rolled copies of this: five across the admin index
 * screens and one on the participant's registrations, plus the review queue's
 * tab strip. They had already drifted into three different controls doing one
 * job — the admin chips were `rounded-lg` and the participant's were
 * `rounded-full`, and the count beside the label was a red pill on two screens,
 * a blue-tint pill on a third, and unstyled muted text on a fourth. A
 * participant moving between their registrations and a staff member moving
 * between two admin screens both met a filter that looked like a different
 * control each time.
 *
 * `role="tablist"` rather than a group of buttons, which is what every copy
 * already used: the chips switch which view of one list is on screen, and
 * `aria-selected` is what says which one is showing. It does not manage focus
 * into the list, for AppTabs' reason — the panel here is the rest of the page
 * rather than a labelled region.
 *
 * A count is `null`/`undefined` when the caller has none to give, which is
 * different from `0`. A chip reading "Rejected 0" is an honest and useful
 * answer — it says the queue is clear rather than that the number is unknown —
 * so a zero renders, and only an absent count is omitted.
 */
defineProps({
    /** The selected chip's value. `null` is the "All" chip when one is offered. */
    modelValue: { type: [String, null], default: null },
    /** `[{ value, label, count? }]`. */
    options: { type: Array, required: true },
    ariaLabel: { type: String, required: true },
    /**
     * Prepend an "All" chip that clears the filter. Off by default: several
     * callers have no unfiltered state to go back to, and a chip that selects
     * the same rows as the one beside it is a control that does nothing.
     */
    allowAll: { type: Boolean, default: false },
    allLabel: { type: String, default: 'All' },
    /** The total behind the "All" chip, when the caller has one. */
    allCount: { type: [Number, null], default: null },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="flex flex-wrap gap-2" role="tablist" :aria-label="ariaLabel">
        <button
            v-if="allowAll"
            type="button"
            role="tab"
            :aria-selected="modelValue === null"
            class="inline-flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
            :class="
                modelValue === null
                    ? 'bg-csc-blue text-white'
                    : 'bg-white text-csc-ink-muted ring-1 ring-csc-line hover:text-csc-blue'
            "
            @click="$emit('update:modelValue', null)"
        >
            {{ allLabel }}
            <span
                v-if="allCount !== null"
                class="rounded-full px-1.5 py-0.5 text-xs font-semibold"
                :class="modelValue === null ? 'bg-white/20' : 'bg-csc-blue-tint text-csc-blue'"
            >
                {{ allCount }}
            </span>
        </button>

        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            role="tab"
            :aria-selected="modelValue === option.value"
            class="inline-flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
            :class="
                modelValue === option.value
                    ? 'bg-csc-blue text-white'
                    : 'bg-white text-csc-ink-muted ring-1 ring-csc-line hover:text-csc-blue'
            "
            @click="$emit('update:modelValue', option.value)"
        >
            {{ option.label }}
            <!--
                The count is a pill in the brand tint rather than the red one
                two of the old copies used. Red inside the signed-in app means
                "something is wrong" (see app.css), and the number beside
                "Completed" is not a warning — it was reading as one on every
                chip in the strip, which is also why none of them stood out.
            -->
            <span
                v-if="option.count !== null && option.count !== undefined"
                class="rounded-full px-1.5 py-0.5 text-xs font-semibold"
                :class="modelValue === option.value ? 'bg-white/20' : 'bg-csc-blue-tint text-csc-blue'"
            >
                {{ option.count }}
            </span>
        </button>
    </div>
</template>
