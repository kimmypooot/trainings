<script setup>
import { ref } from 'vue';

/**
 * The hero's right-hand column: photographs of the Commission's training,
 * stacked as if laid on a desk.
 *
 * Three deliberate constraints, because this sits on a photograph already:
 *
 * - Each print carries a white edge and its own shadow. Without them the
 *   photographs sit *in* the facade behind rather than on top of it, and the
 *   whole composition reads as one muddy image.
 * - The rotations are small and unequal. Equal angles look like a mistake in
 *   the CSS; a large angle turns a government portal into a scrapbook.
 * - A photograph that fails to load removes itself. These are files an office
 *   drops in later and swaps around, and a broken-image glyph on the front page
 *   is worse than a stack of two.
 *
 * `print:hidden` for the same reason AppBrandBackdrop is: nobody printing this
 * page wants three full-bleed photographs with it.
 *
 * The root is `isolate`, and that is load-bearing rather than tidy. The photos'
 * z-indexes only mean "which print lies on top of which", but a `relative` box
 * with `z-index: auto` starts no stacking context, so they were resolved against
 * the *root* one — where the sticky header sits at `--z-header` (20). The
 * topmost print at `z-30` therefore slid over the header on scroll. Isolating
 * keeps the stack's ordering private to the stack.
 */
const props = defineProps({
    /** `{ src, alt, className }` each — see Home.vue, which owns the content. */
    photos: { type: Array, required: true },
});

/**
 * Raised once every photograph has failed.
 *
 * The hero arranges itself into two columns around this stack, so it has to be
 * told when there is no stack left to arrange around — otherwise a deployment
 * whose photographs were never uploaded gets a left-aligned headline with an
 * empty half beside it, which reads as a page that failed to load rather than
 * as a page with no photographs.
 */
const emit = defineEmits(['empty']);

// Sources that 404'd or failed to decode. Keyed by src, so a swapped file that
// works is unaffected by a sibling that does not.
const failed = ref(new Set());

const drop = (src) => {
    // A new Set rather than a mutation: Vue does not track Set.add().
    failed.value = new Set(failed.value).add(src);

    if (failed.value.size === props.photos.length) {
        emit('empty');
    }
};
</script>

<template>
    <div class="relative isolate mx-auto aspect-4/5 w-full max-w-sm print:hidden lg:max-w-none">
        <template v-for="photo in photos" :key="photo.src">
            <figure
                v-if="!failed.has(photo.src)"
                class="absolute overflow-hidden rounded-2xl bg-white/10 p-1.5 shadow-2xl ring-1 ring-white/25 backdrop-blur-sm"
                :class="photo.className"
            >
                <img
                    :src="photo.src"
                    :alt="photo.alt"
                    loading="lazy"
                    decoding="async"
                    class="size-full rounded-xl object-cover"
                    @error="drop(photo.src)"
                />
            </figure>
        </template>
    </div>
</template>
