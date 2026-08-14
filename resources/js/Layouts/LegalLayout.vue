<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';

defineProps({
    title: { type: String, required: true },
    updated: { type: String, required: true },
});
</script>

<template>
    <PublicLayout current="legal">
        <section class="relative overflow-hidden text-white">
            <picture class="absolute inset-0" aria-hidden="true">
                <source srcset="/images/cscbg_facade.webp" type="image/webp" />
                <img
                    src="/images/cscbg_facade.jpeg"
                    alt=""
                    decoding="async"
                    class="absolute inset-0 size-full object-cover"
                />
            </picture>
            <div
                class="absolute inset-0"
                style="
                    background: linear-gradient(
                        160deg,
                        color-mix(in srgb, var(--color-csc-blue-deep) 94%, transparent) 0%,
                        color-mix(in srgb, var(--color-csc-blue-deep) 96%, transparent) 100%
                    );
                "
                aria-hidden="true"
            />

            <div class="relative mx-auto max-w-3xl px-4 py-16 sm:px-6 lg:px-8">
                <span class="flex items-center gap-2" aria-hidden="true">
                    <span class="inline-block h-1 w-12 bg-white" />
                    <span class="inline-block h-1 w-4 bg-csc-red" />
                </span>
                <h1 class="mt-6 text-3xl font-semibold tracking-tight text-balance sm:text-4xl">{{ title }}</h1>
                <p class="mt-3 text-sm text-white/70">Last updated {{ updated }}</p>
            </div>
        </section>

        <article
            class="mx-auto max-w-3xl space-y-8 px-4 py-14 text-sm leading-relaxed text-csc-ink sm:px-6 lg:px-8"
        >
            <slot />
        </article>
    </PublicLayout>
</template>
