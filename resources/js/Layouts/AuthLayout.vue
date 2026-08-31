<script setup>
import { useId } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/Components/AppLogo.vue';

/**
 * The two-column auth shell shared by the sign-in, register, forgot-password,
 * and reset-password screens.
 *
 * The branding panel is identical across those pages — only the headline,
 * tagline, and benefit bullets differ — so the copy comes in as props and the
 * form rides the default slot. The pattern's SVG id is per-instance (useId) so
 * two auth screens can never collide on the defs reference.
 */
defineProps({
    headline: { type: String, required: true },
    tagline: { type: String, required: true },
    // Small benefit bullets beneath the tagline; an empty array hides the list.
    benefits: { type: Array, default: () => [] },
});

const patternId = useId();
</script>

<template>
    <div class="min-h-screen lg:grid lg:grid-cols-2">
        <!-- Left: branding. Hidden below lg. -->
        <aside class="relative hidden overflow-hidden lg:flex lg:min-h-screen lg:flex-col lg:justify-center">
            <!--
                The media condition is load-bearing, not decoration. This aside
                is `hidden lg:flex`, but display:none does not stop a browser
                fetching an <img> inside it — every phone was pulling the full
                facade photo for a panel it would never show. Both sources are
                gated at the same breakpoint the panel appears at, so below lg
                there is no candidate to fetch at all.
            -->
            <picture class="absolute inset-0" aria-hidden="true">
                <source
                    media="(min-width: 1024px)"
                    srcset="/images/cscbg_facade.webp"
                    type="image/webp"
                />
                <source media="(min-width: 1024px)" srcset="/images/cscbg_facade.jpeg" type="image/jpeg" />
                <!--
                    A 1x1 transparent GIF, inline. The <img> is the fallback
                    every <picture> must carry, so it cannot simply be dropped —
                    and a src-less <img> is what the browser treats as broken.
                    Below lg this resolves to 43 bytes that were already in the
                    document instead of a photograph.
                -->
                <img
                    src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
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
                        color-mix(in srgb, var(--color-csc-blue-deep) 93%, transparent) 0%,
                        color-mix(in srgb, var(--color-csc-blue) 87%, transparent) 55%,
                        color-mix(in srgb, var(--color-csc-blue-deep) 95%, transparent) 100%
                    );
                "
                aria-hidden="true"
            />
            <svg class="pointer-events-none absolute inset-0 size-full opacity-[0.08]" aria-hidden="true">
                <defs>
                    <pattern :id="patternId" width="64" height="64" patternUnits="userSpaceOnUse">
                        <circle cx="32" cy="32" r="18" fill="none" stroke="white" stroke-width="1" />
                        <path d="M0 32h64M32 0v64" stroke="white" stroke-width="0.5" />
                    </pattern>
                </defs>
                <rect width="100%" height="100%" :fill="`url(#${patternId})`" />
            </svg>

            <div class="relative px-12 py-16 xl:px-20">
                <Link
                    href="/"
                    class="inline-block rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white"
                >
                    <AppLogo variant="light" size="lg" />
                    <span class="sr-only">Back to CSC TIMS home</span>
                </Link>

                <div class="mt-10 max-w-lg">
                    <h1 class="text-4xl leading-tight font-semibold tracking-tight text-balance text-white xl:text-5xl">
                        {{ headline }}
                    </h1>

                    <p class="mt-6 text-base leading-relaxed text-pretty text-white/75 xl:text-lg">
                        {{ tagline }}
                    </p>

                    <ul v-if="benefits.length" class="mt-8 space-y-3 text-sm text-white/75">
                        <li v-for="benefit in benefits" :key="benefit" class="flex items-start gap-3">
                            <svg class="mt-0.5 size-5 shrink-0 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M5 12.5l4.5 4.5L19 7.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            {{ benefit }}
                        </li>
                    </ul>
                </div>
            </div>
        </aside>

        <!--
            Mobile brand strip. Replaces the left panel below lg.

            It used to be the logo alone, which meant every phone visitor met
            the form with none of the reason for filling it in — the headline,
            the tagline and the three benefits all lived in the desktop-only
            panel. Most people reaching a regional office's portal are on a
            phone, so the copy that sells the account was being withheld from
            the majority. The tagline comes across; the benefit list does not,
            since three ticked bullets above a form is a scroll cost on a small
            screen where the form itself is the point.
        -->
        <div class="bg-csc-blue px-4 py-6 sm:px-6 lg:hidden">
            <Link
                href="/"
                class="inline-block rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white"
            >
                <AppLogo variant="light" size="md" />
                <span class="sr-only">Back to CSC TIMS home</span>
            </Link>

            <p class="mt-4 max-w-prose text-sm leading-relaxed text-pretty text-white/85">
                {{ tagline }}
            </p>
        </div>

        <!-- Right: form -->
        <main class="flex items-center justify-center bg-white px-4 py-12 sm:px-6 lg:min-h-screen lg:px-12 lg:py-16">
            <div class="w-full max-w-md">
                <slot />
            </div>
        </main>
    </div>
</template>
