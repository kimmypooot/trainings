<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';
import { iconNames } from '@/icons';

/**
 * The actions at the end of a row, in one place.
 *
 * Every admin index screen had hand-rolled its own: text links joined by a
 * `|` separator, `text-xs` inside a `py-3.5` cell, with the destructive one
 * styled identically to the navigational ones on five of the seven pages.
 * The table and the card list were two loops over the same row, so an action
 * added to one and forgotten in the other was invisible until somebody at a
 * venue could not do their job on a phone. Both layouts take the same array
 * now, and `layout` changes presentation only — the same contract
 * RosterActions carries, for the same reason.
 *
 * **The two layouts label differently, deliberately.**
 *
 * In a table, four spelled-out actions ("View", "Edit", "Deactivate",
 * "Delete") are ~340px of a row — wider than the data, and the eye lands on
 * the controls rather than on the office. So `row` is icon-only with the
 * label in a tooltip on hover *and on keyboard focus*, which is the balance
 * this was asked for.
 *
 * `card` keeps the label visible, and that half is not a preference: the card
 * layout is what a phone gets, hover does not exist there, and these verbs
 * have no glyph a new staff member reads without being taught it. An
 * icon-only touch layout would be a label that is simply absent.
 *
 * The tooltip is decoration. The accessible name comes from `aria-label` on
 * the control, because `title` is not a name a screen reader can be relied on
 * to announce, and a tooltip that appears only on hover never reaches someone
 * tabbing through the row.
 *
 * An action is:
 *   { label, icon?, tone?, href?, external?, onClick?, disabled?, reason? }
 * `reason` is why a disabled action is refused, and it joins the label in the
 * tooltip — "delete is missing" and "delete is refused, because eleven people
 * are attached" are different answers, and only the second says what to do
 * next.
 */
const props = defineProps({
    actions: {
        type: Array,
        required: true,
        validator: (list) =>
            list.every(
                (action) =>
                    typeof action.label === 'string' &&
                    (action.icon === undefined || iconNames.includes(action.icon)) &&
                    ['default', 'danger', 'success', undefined].includes(action.tone),
            ),
    },
    layout: {
        type: String,
        default: 'row', // row (table cell, icon-only) | card (stacked list, labelled)
        validator: (value) => ['row', 'card'].includes(value),
    },
});

const compact = computed(() => props.layout === 'row');

/*
 * `size-9` in the table and `min-h-9` on the card are the same 36px target.
 * The old inline `text-xs` links were a few pixels tall, and the card layout
 * is used on a phone held at a venue.
 */
const base = computed(() => [
    'inline-flex items-center justify-center rounded-lg font-semibold transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue',
    compact.value ? 'size-9' : 'min-h-9 gap-1.5 px-2.5 py-1.5 text-xs',
]);

const tones = {
    default: 'text-csc-blue hover:bg-csc-blue-tint',
    danger: 'text-danger hover:bg-danger-soft',
    success: 'text-success hover:bg-success-soft',
};

const blocked = 'cursor-not-allowed text-csc-ink-subtle';

const classesFor = (action) => [
    base.value,
    action.disabled ? blocked : tones[action.tone ?? 'default'],
];

/** The tooltip and the accessible name are the same sentence. */
const describe = (action) =>
    action.disabled && action.reason ? `${action.label} — ${action.reason}` : action.label;

/*
 * An icon carries the meaning only when there is one. A caller that lists an
 * action without an icon gets its label rendered even in the compact layout,
 * because a blank 36px square is a control nobody can find.
 */
const showsLabel = (action) => !compact.value || !action.icon;

/*
 * Visible actions carry no `disabled` attribute, because a disabled button is
 * removed from the tab order and takes its explanation with it: the reason
 * would then be reachable by mouse hover and by nothing else. It is
 * `aria-disabled` instead, which is announced, still focusable, and refused
 * in the handler.
 */
const activate = (action) => {
    if (action.disabled) return;
    action.onClick?.();
};

const wrapper = computed(() =>
    compact.value
        ? 'flex items-center justify-end gap-0.5'
        : 'flex flex-wrap items-center gap-1',
);

/*
 * The tooltip opens upward and to the left, and both directions are forced by
 * the same thing: every table on these screens sits in a wrapper that clips
 * its overflow (`overflow-hidden` for the rounded corners, `overflow-x-auto`
 * where the table is wide). A bubble hanging below the last row would be cut
 * in half, and a centred one on the last action — the actions column is
 * right-aligned, so that action sits at the table's right edge — loses its
 * tail off the side, which is exactly where the longest text is, since the
 * refused action is the one carrying a reason. Anchored right and opening
 * up, it stays inside the wrapper in both axes. It wraps rather than running
 * on, for the same reason — and `whitespace-normal` is stated here rather
 * than left to the default, because the action cell it sits in is
 * `whitespace-nowrap` (rightly: it stops the controls wrapping) and
 * white-space inherits, so the bubble was silently rendering as one long line
 * that its own `max-w` could not bring back.
 */
const tooltip =
    'pointer-events-none absolute right-0 bottom-full z-(--z-popover) mb-1.5 hidden w-max max-w-56 rounded-md whitespace-normal bg-csc-ink px-2 py-1 text-left text-[11px] leading-snug font-medium text-white shadow-md group-hover:block group-focus-within:block';
</script>

<template>
    <div :class="wrapper">
        <span
            v-for="(action, index) in actions"
            :key="action.label ?? index"
            class="group relative inline-flex"
        >
            <a
                v-if="action.href && action.external"
                :href="action.href"
                :class="classesFor(action)"
                :aria-label="showsLabel(action) ? undefined : action.label"
            >
                <AppIcon v-if="action.icon" :name="action.icon" size="sm" class="shrink-0" />
                <template v-if="showsLabel(action)">{{ action.label }}</template>
            </a>

            <Link
                v-else-if="action.href"
                :href="action.href"
                :class="classesFor(action)"
                :aria-label="showsLabel(action) ? undefined : action.label"
            >
                <AppIcon v-if="action.icon" :name="action.icon" size="sm" class="shrink-0" />
                <template v-if="showsLabel(action)">{{ action.label }}</template>
            </Link>

            <button
                v-else
                type="button"
                :class="classesFor(action)"
                :aria-disabled="action.disabled ? 'true' : undefined"
                :aria-label="showsLabel(action) && !action.disabled ? undefined : describe(action)"
                @click="activate(action)"
            >
                <AppIcon v-if="action.icon" :name="action.icon" size="sm" class="shrink-0" />
                <template v-if="showsLabel(action)">{{ action.label }}</template>
            </button>

            <!--
                Decoration: the name is on the control above. Shown on the card
                layout too, but only for a disabled action, where the label is
                already visible and the tooltip is carrying the reason.
            -->
            <span
                v-if="compact || action.disabled"
                :class="tooltip"
                :aria-hidden="true"
            >
                {{ describe(action) }}
            </span>
        </span>
    </div>
</template>
