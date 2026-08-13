<script setup>
defineProps({
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
});
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
            <div v-if="title || subtitle">
                <h2
                    class="text-base font-semibold tracking-tight sm:text-lg"
                    :class="tone === 'brand' ? 'text-white' : 'text-csc-blue'"
                >
                    {{ title }}
                </h2>
                <p
                    v-if="subtitle"
                    class="mt-1 text-sm"
                    :class="tone === 'brand' ? 'text-white/70' : 'text-csc-ink/60'"
                >
                    {{ subtitle }}
                </p>
            </div>
            <slot name="header" />
            <div v-if="$slots.action" class="shrink-0">
                <slot name="action" />
            </div>
        </header>

        <div :class="[padded ? 'px-5 py-5 sm:px-6 sm:py-6' : '', title && padded ? 'pt-4 sm:pt-5' : '']">
            <slot />
        </div>

        <footer
            v-if="$slots.footer"
            class="border-t px-5 py-4 sm:px-6"
            :class="tone === 'brand' ? 'border-white/15' : 'border-csc-line bg-csc-blue-tint/50'"
        >
            <slot name="footer" />
        </footer>
    </section>
</template>
