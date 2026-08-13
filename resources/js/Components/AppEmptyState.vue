<script setup>
import AppIcon from '@/Components/AppIcon.vue';
import { icons } from '@/icons';

defineProps({
    // An icon name from the registry, not a path.
    icon: {
        type: String,
        default: 'list',
        validator: (value) => value in icons,
    },
    title: { type: String, required: true },
    description: { type: String, default: null },
    /*
     * This block carries its own generous padding, because its usual home is an
     * unpadded card where it is the only content. Set `compact` when the
     * container already pads — inside a dialog body, say — so the two do not
     * stack into a band of empty space.
     */
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <div
        class="flex flex-col items-center justify-center text-center"
        :class="compact ? 'py-6' : 'px-6 py-12'"
    >
        <span
            class="inline-flex items-center justify-center rounded-full bg-csc-blue-tint text-csc-blue"
            :class="compact ? 'size-11' : 'size-14'"
        >
            <AppIcon :name="icon" :size="compact ? 'md' : 'lg'" />
        </span>

        <h3 class="text-base font-semibold text-csc-blue" :class="compact ? 'mt-3.5' : 'mt-5'">
            {{ title }}
        </h3>
        <p
            v-if="description"
            class="max-w-sm text-sm leading-relaxed text-csc-ink/65"
            :class="compact ? 'mt-1.5' : 'mt-2'"
        >
            {{ description }}
        </p>

        <div v-if="$slots.action" :class="compact ? 'mt-4' : 'mt-6'">
            <slot name="action" />
        </div>
    </div>
</template>
