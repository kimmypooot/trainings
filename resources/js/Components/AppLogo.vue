<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'dark', // 'dark' = for light backgrounds, 'light' = for blue backgrounds
        validator: (value) => ['light', 'dark'].includes(value),
    },
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md', 'lg'].includes(value),
    },
    wordmark: {
        type: Boolean,
        default: true,
    },
});

const light = computed(() => props.variant === 'light');

const markSize = computed(() => ({ sm: 'h-8', md: 'h-10', lg: 'h-14' })[props.size]);
const titleSize = computed(() => ({ sm: 'text-sm', md: 'text-base', lg: 'text-xl' })[props.size]);
const subSize = computed(() => ({ sm: 'text-[10px]', md: 'text-2xs', lg: 'text-xs' })[props.size]);

/*
 * The white plate, for the blue-background variant only.
 *
 * Same reasoning the sidebar rail already carries: the seal is a blue-and-red
 * wordmark on transparency, so on --color-csc-blue the letterforms sit on their
 * own colour and all but vanish. The plate is what makes it legible — it is not
 * decoration. On a light background the bare seal is already legible and a white
 * plate would be invisible, so `dark` keeps the plain mark.
 *
 * Sized a little larger than the mark it holds, since the padding has to come
 * from somewhere and the seal should not shrink to pay for it.
 */
const plateSize = computed(
    () =>
        ({
            sm: 'size-9 rounded-lg p-1',
            md: 'size-11 rounded-xl p-1.5',
            lg: 'size-14 rounded-2xl p-2',
        })[props.size]
);
</script>

<template>
    <span class="inline-flex items-center gap-3">
        <!-- On blue: the seal on its white plate, matching the sidebar rail. -->
        <span
            v-if="light"
            :class="plateSize"
            class="flex shrink-0 items-center justify-center bg-white shadow-sm ring-1 ring-white/20"
        >
            <img
                src="/images/csc-logo-256.png"
                alt=""
                class="h-full w-full object-contain"
                aria-hidden="true"
            />
        </span>

        <img
            v-else
            src="/images/csc-logo-256.png"
            alt=""
            :class="markSize"
            class="w-auto shrink-0 object-contain"
            aria-hidden="true"
        />

        <span v-if="wordmark" class="flex flex-col leading-none">
            <span
                :class="[titleSize, light ? 'text-white' : 'text-csc-blue']"
                class="font-semibold tracking-tight"
            >
                CSC <span class="font-normal">TIMS</span>
            </span>
            <span
                :class="[subSize, light ? 'text-white/70' : 'text-csc-ink-subtle']"
                class="mt-1 font-medium tracking-wide uppercase"
            >
                Civil Service Commission
            </span>
        </span>
    </span>
</template>
