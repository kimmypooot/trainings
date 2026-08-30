<script setup>
import { ref, useId, watch } from 'vue';

const props = defineProps({
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
    // Emphasised cards carry the brand blue; used sparingly, for the hero card.
    tone: {
        type: String,
        default: 'plain',
        validator: (value) => ['plain', 'brand'].includes(value),
    },
    /*
     * Gutters around the body.
     *
     * Keep this true whenever the card holds real content — lists, tables and
     * forms all assume the gutter is there, and a table that bleeds to the card
     * edge with `-mx-5` needs a matching `px-5` to bleed *from*.
     *
     * Set it false only when the sole child brings its own padding, which in
     * practice means an AppEmptyState. Reading it as "is the card empty?" is
     * backwards and has been the source of every spacing bug here.
     */
    padded: { type: Boolean, default: true },
    /*
     * Fold the body away behind its own heading.
     *
     * For a card that is reference rather than work: something a particular
     * reader needs on some visits and never on others. The heading becomes the
     * control, so the card still announces what it holds while folded — which
     * is the difference between a disclosure and simply hiding something.
     *
     * Requires a `title`: without one there is nothing to click and nothing
     * left on screen to say what was folded away.
     */
    collapsible: { type: Boolean, default: false },
    /*
     * A localStorage key. With one, a reader's choice sticks across visits;
     * without one the card reopens on every page load.
     *
     * Per viewer and per browser by design — this is a reading preference, not
     * an account setting, and it never leaves the device.
     */
    rememberAs: { type: String, default: null },
    /*
     * Open on a first visit, before anyone has expressed a preference.
     *
     * Defaulting to open is deliberate. A collapsible panel that starts folded
     * hides something from the one reader who came for it, and they pay that
     * cost on every visit; one that starts open costs the readers who do not
     * want it exactly one click, once, forever. The clutter is worth solving,
     * but not by guessing which reader is in front of us.
     */
    defaultOpen: { type: Boolean, default: true },
});

/*
 * Storage can throw rather than merely come back empty — a private window, a
 * browser set to block site data, a thumbnail capture — so both directions are
 * guarded and a failure just means the card behaves as if nothing was
 * remembered.
 */
const remembered = () => {
    if (!props.rememberAs) return null;

    try {
        const stored = window.localStorage.getItem(`card:${props.rememberAs}`);

        return stored === null ? null : stored === 'open';
    } catch {
        return null;
    }
};

const open = ref(remembered() ?? props.defaultOpen);

watch(open, (isOpen) => {
    if (!props.rememberAs) return;

    try {
        window.localStorage.setItem(`card:${props.rememberAs}`, isOpen ? 'open' : 'closed');
    } catch {
        // A reading preference is not worth failing a render over.
    }
});

// Ties the heading's aria-controls to the body it opens.
const bodyId = useId();
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border"
        :class="tone === 'brand' ? 'border-csc-blue/20 bg-csc-blue text-white' : 'border-csc-line bg-white'"
    >
        <header
            v-if="title || $slots.header || $slots.action"
            class="flex items-start justify-between gap-4 px-5 pt-5 sm:px-6 sm:pt-6"
        >
            <!--
                Collapsible cards put the heading inside the control, so the
                whole title block is the target rather than a lone chevron.
                The h2 stays where it is either way: a disclosure must not cost
                the document its heading outline.
            -->
            <component
                :is="collapsible && title ? 'button' : 'div'"
                v-if="title || subtitle"
                :type="collapsible && title ? 'button' : undefined"
                :aria-expanded="collapsible && title ? open : undefined"
                :aria-controls="collapsible && title ? bodyId : undefined"
                :class="
                    collapsible && title
                        ? 'group flex min-w-0 items-start gap-2 rounded text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue'
                        : ''
                "
                @click="collapsible && title ? (open = !open) : undefined"
            >
                <svg
                    v-if="collapsible && title"
                    class="mt-1 size-4 shrink-0 transition-transform duration-150"
                    :class="[open ? 'rotate-90' : '', tone === 'brand' ? 'text-white/70' : 'text-csc-ink-subtle']"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <path d="M9 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>

                <span class="min-w-0">
                    <h2
                        class="text-base font-semibold tracking-tight sm:text-lg"
                        :class="[
                            tone === 'brand' ? 'text-white' : 'text-csc-blue',
                            collapsible && title ? 'group-hover:underline' : '',
                        ]"
                    >
                        {{ title }}
                    </h2>
                    <p
                        v-if="subtitle"
                        class="mt-1 text-sm"
                        :class="tone === 'brand' ? 'text-white/70' : 'text-csc-ink-subtle'"
                    >
                        {{ subtitle }}
                    </p>
                </span>
            </component>
            <slot name="header" />
            <div v-if="$slots.action" class="shrink-0">
                <slot name="action" />
            </div>
        </header>

        <!--
            v-show, not v-if: folding a card is a reading preference, and
            unmounting the body would throw away whatever transient state it was
            holding — a revealed station code, a half-filled field — for someone
            who only meant to get it out of the way for a moment.
        -->
        <div
            :id="collapsible ? bodyId : undefined"
            v-show="!collapsible || open"
            :class="[padded ? 'px-5 py-5 sm:px-6 sm:py-6' : '', title && padded ? 'pt-4 sm:pt-5' : '']"
        >
            <slot />
        </div>

        <footer
            v-if="$slots.footer"
            v-show="!collapsible || open"
            class="border-t px-5 py-4 sm:px-6"
            :class="tone === 'brand' ? 'border-white/15' : 'border-csc-line bg-csc-blue-tint/50'"
        >
            <slot name="footer" />
        </footer>
    </section>
</template>
