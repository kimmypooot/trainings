<script setup>
import { computed } from 'vue';

/**
 * The Commission facade under a brand gradient — the public site's hero
 * backdrop, in one place.
 *
 * This started on the home page and was about to be copied onto the two
 * verification screens, which is how a three-stop gradient ends up with three
 * slightly different angles in it. The photo and the wash over it are the
 * public face of the app; they belong in a component.
 *
 * The gradient is not decoration. White text sits on this, and the facade is a
 * pale building in bright sun — unwashed, the copy over it is unreadable in
 * exactly the places the stone is brightest. The three stops keep the darkest
 * values at the top and bottom edges where headings and buttons land.
 *
 * Hidden on paper. The verification result is a page people print and file,
 * and a full-bleed photograph behind a record is both unreadable and a
 * remarkable way to empty a toner cartridge.
 */
const props = defineProps({
    /**
     * Whether this is the page's LCP element.
     *
     * True only on the home page, where the facade *is* the largest paint and
     * the preload in `app.blade.php` is aimed at it. On the verification pages
     * it is a band behind a card — the record is what matters, and competing
     * for priority with it would be backwards.
     */
    priority: { type: Boolean, default: false },
    /**
     * Which part of the facade to keep when the box is cropped.
     *
     * The default centres it, which is right for a full-height hero. A short
     * band is a different problem: `object-cover` on a 1920×1440 photo in a
     * 250px-tall strip keeps a narrow horizontal slice, and dead centre on this
     * particular photograph is blank wall — the band came out looking like a
     * flat blue rectangle, which is exactly the plainness it was added to fix.
     * Biasing upward catches the roofline and the entrance, so the building is
     * legible as a building even in a strip.
     */
    objectPosition: { type: String, default: 'center' },
    /**
     * How heavily the gradient covers the photograph.
     *
     * The wash exists for contrast, not for looks, so the choice is decided by
     * one question: does white text sit on this?
     *
     * - `full` — the home hero, where a full-height photo puts headings,
     *   paragraph copy and buttons over the brightest parts of the facade.
     * - `medium` — a shallow band that still carries a heading. At `full` a
     *   250–400px strip is almost entirely the darkest stop, so the building
     *   disappears and the band is an expensive way to draw a blue rectangle.
     * - `soft` — a band carrying no text at all, like the record page, where
     *   the card covers the middle and only the margins show.
     *
     * **`soft` is not safe for text.** Worst case is a pure-white sunlit stone
     * pixel showing through, and against that the middle stop — csc-blue,
     * the lightest of the three — measures:
     *
     *     87% → 7.43:1    78% → 5.75:1    72% → 4.86:1  ← AA floor
     *     68% → 4.35:1    62% → 3.71:1    42% → 2.28:1  ← `soft`
     *
     * So 72% is the hard bottom for anything with copy on it, `medium` keeps
     * a margin at 76%, and `soft` is deliberately far below the line because
     * nothing legible is ever placed on it. Putting a heading on a `soft`
     * backdrop would be a real accessibility regression, not a style choice.
     */
    wash: {
        type: String,
        default: 'full',
        validator: (value) => ['full', 'medium', 'soft'].includes(value),
    },
});

// Same three stops and the same angle throughout; only the coverage changes,
// so a lighter band still reads as the same surface rather than a second look.
const WASHES = {
    full: ['93%', '87%', '95%'],
    medium: ['85%', '76%', '88%'],
    soft: ['58%', '42%', '62%'],
};

const stops = computed(() => WASHES[props.wash] ?? WASHES.full);

const gradient = computed(
    () => `linear-gradient(
        160deg,
        color-mix(in srgb, var(--color-csc-blue-deep) ${stops.value[0]}, transparent) 0%,
        color-mix(in srgb, var(--color-csc-blue) ${stops.value[1]}, transparent) 55%,
        color-mix(in srgb, var(--color-csc-blue-deep) ${stops.value[2]}, transparent) 100%
    )`
);
</script>

<template>
    <div class="absolute inset-0 overflow-hidden print:hidden" aria-hidden="true">
        <!--
            A real <img> rather than a CSS background: a background-image is only
            discovered once the stylesheet has parsed, which on the home page
            would delay the largest paint on the site. WebP first with a JPEG
            fallback; `app.blade.php` preloads the WebP.
        -->
        <picture>
            <source srcset="/images/cscbg_facade.webp" type="image/webp" />
            <img
                src="/images/cscbg_facade.jpeg"
                alt=""
                :fetchpriority="priority ? 'high' : 'auto'"
                :loading="priority ? 'eager' : 'lazy'"
                decoding="async"
                class="absolute inset-0 size-full object-cover"
                :style="{ objectPosition }"
            />
        </picture>

        <div class="absolute inset-0" :style="{ background: gradient }" />

        <!-- Anything a page wants over the wash: a pattern, a vignette. -->
        <slot />
    </div>
</template>
