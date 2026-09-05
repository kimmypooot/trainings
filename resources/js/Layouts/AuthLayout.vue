<script setup>
import { useId } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppBrandBackdrop from '@/Components/AppBrandBackdrop.vue';
import AppLogo from '@/Components/AppLogo.vue';

/**
 * The two-column auth shell shared by the sign-in, register, forgot-password,
 * and reset-password screens.
 *
 * The branding panel is identical across those pages — only the headline,
 * tagline, and benefit bullets differ — so the copy comes in as props and the
 * form rides the default slot. The pattern's SVG id is per-instance (useId) so
 * two auth screens can never collide on the defs reference.
 *
 * Heights are `dvh`, not `vh`. On a phone `100vh` is the viewport with the
 * browser's own chrome subtracted *as if it were hidden*, so a full-height
 * column is taller than what is actually on screen and the page gains a scroll
 * it does not need — on the shortest screen here, enough to push the Sign in
 * button under the address bar. `dvh` tracks the live viewport instead.
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
    <div class="min-h-dvh lg:grid lg:grid-cols-2">
        <!-- Left: branding. Hidden below lg. -->
        <aside class="relative hidden overflow-hidden lg:flex lg:min-h-dvh lg:flex-col lg:justify-center">
            <!--
                The shared backdrop, not a local copy of it.

                This panel used to hand-roll the facade: a <picture> with two
                media-gated <source>s, and beside it an inline
                `linear-gradient(160deg, …)` whose three stops were
                character-for-character AppBrandBackdrop's `full` — the exact
                duplication that component was extracted to prevent, and the
                reason its own comment warns about "a three-stop gradient with
                three slightly different angles in it".

                The media gating went with it, and it turns out never to have
                saved the bytes it was written for. `app.blade.php` carries an
                unconditional `<link rel="preload" as="image"
                fetchpriority="high">` for the facade — it is in the shared
                shell, so it fires on /login and /register exactly as it does on
                the landing page, and a preload fetches whether or not anything
                uses it. So a phone opening the sign-in screen was already
                pulling the full photograph at high priority and then painting a
                flat blue band over the top of it: the download happened, and
                the only thing the gating achieved was throwing it away.

                Measured on /register at a 310px viewport:
                `performance.getEntriesByType('resource')` shows exactly one
                entry for cscbg_facade.webp, 429KB, `initiatorType: "link"` —
                the preload. Neither this panel's <img> nor the strip's issues a
                request of its own; both draw on that single copy. The bytes
                were being spent either way, and now they are spent on
                something.
            -->
            <AppBrandBackdrop>
                <svg class="pointer-events-none absolute inset-0 size-full opacity-[0.08]" aria-hidden="true">
                    <defs>
                        <pattern :id="patternId" width="64" height="64" patternUnits="userSpaceOnUse">
                            <circle cx="32" cy="32" r="18" fill="none" stroke="white" stroke-width="1" />
                            <path d="M0 32h64M32 0v64" stroke="white" stroke-width="0.5" />
                        </pattern>
                    </defs>
                    <rect width="100%" height="100%" :fill="`url(#${patternId})`" />
                </svg>
            </AppBrandBackdrop>

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
        <div class="relative overflow-hidden bg-csc-blue px-4 py-7 sm:px-6 sm:py-8 lg:hidden">
            <!--
                The facade, on a phone too.

                This strip was a flat `bg-csc-blue`, so the office appeared on
                the landing page's hero and then vanished the moment a visitor
                tapped Sign in — the same building, the same brand, two
                different-looking products one tap apart. VerifyLookup's band
                already made this argument in its own comment and it holds here:
                a plain coloured band is the one backdrop that could belong to
                anybody, and the building is the Commission identifying itself
                on the screen where a visitor is about to type a password.

                `medium`, not `full`, and the object-position matched to
                VerifyLookup's band rather than picked by eye — both for the
                reasons AppBrandBackdrop documents. At `full` a strip this
                shallow is almost entirely the darkest stop, which is an
                expensive way to redraw the blue rectangle this is replacing;
                and dead centre on this photograph is blank wall, so a short
                band biased upward is what catches the roofline and the
                entrance. `medium` sits at 76% against a 72% floor, and the
                white/85 tagline over it is the same pairing already shipping on
                that band.

                `bg-csc-blue` stays underneath deliberately: the wash is
                semi-transparent, so if the photograph ever fails to load this
                falls back to the brand blue it used to be rather than to
                whatever is behind it.
            -->
            <AppBrandBackdrop object-position="center 38%" wash="medium" />

            <div class="relative">
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
        </div>

        <!-- Right: form -->
        <main class="flex items-center justify-center bg-white px-4 py-10 sm:px-6 sm:py-12 lg:min-h-dvh lg:px-12 lg:py-16">
            <div class="w-full max-w-md">
                <slot />
            </div>
        </main>
    </div>
</template>
