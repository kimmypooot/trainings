<script setup>
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';

/**
 * A quiet link from a screen to the part of the guide about it.
 *
 * Contextual help is only worth having if it lands on the answer rather than on
 * the top of a long page, which is what the guide's section anchors are for —
 * `/help#payments`, not `/help`. This exists so that pairing is made in one
 * place: a page names the section it belongs to and gets the same treatment
 * everywhere, instead of six screens each inventing a link.
 *
 * Deliberately understated. It sits above content the participant came to read
 * and is useful to a minority of visits, so it reads as an aside — no button,
 * no card, no colour beyond the link itself. A prominent help affordance on
 * every screen is how an application admits its screens are confusing; this is
 * a door for the reader who wants one, placed where they would look.
 *
 * An Inertia Link, so following it is an in-app visit rather than a reload —
 * which matters here more than usual, because the reader is mid-task and will
 * be coming back.
 */
defineProps({
    /** The guide section this screen is about — see Pages/Help/Index.vue. */
    anchor: { type: String, required: true },
});
</script>

<template>
    <p class="flex">
        <Link
            :href="`/help#${anchor}`"
            class="inline-flex items-center gap-1.5 rounded text-xs font-medium text-csc-ink-subtle transition-colors hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
        >
            <AppIcon name="info" size="sm" class="shrink-0" />
            <slot />
        </Link>
    </p>
</template>
