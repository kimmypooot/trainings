<script setup>
import { computed, useId } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppInput from '@/Components/AppInput.vue';

/**
 * The front door to certificate verification.
 *
 * Written for someone who has no account and wants none: an HR officer with a
 * new hire's folder, an auditor working through a stack, a scholarship board
 * checking a claim. They arrive holding a piece of paper, so the page is built
 * around the one thing they have to do — copy a code off it — and everything
 * else on the page exists to help them find that code or to tell them what the
 * answer will and will not mean.
 */
const page = usePage();
const office = computed(() => page.props.office ?? {});

const form = useForm({
    code: '',
});

const submit = () => form.post('/verify');

// Per-instance, matching AuthLayout: a hard-coded defs id is one shared
// component away from colliding with another pattern on the same page.
const patternId = useId();
</script>

<template>
    <Head title="Verify a certificate">
        <meta
            name="description"
            content="Check that a training certificate issued by the Civil Service Commission is genuine, using the verification code printed on it."
        />
    </Head>

    <!--
        current="verify" matches no nav key on purpose, so nothing in the header
        is marked as the page you are on. Left at the default, the layout
        highlighted "Home" here — telling a visitor they were somewhere they
        were not, which is worse than no highlight at all.
    -->
    <PublicLayout current="verify">
        <!--
            A short banded hero rather than the plain white page this used to
            be. It does two jobs at once: it tells a first-time arrival they are
            in the right place, and it visually separates the act of asking from
            the record they get back, which wears the same brand blue.
        -->
        <section class="relative overflow-hidden bg-csc-blue-deep text-white">
            <svg class="pointer-events-none absolute inset-0 size-full opacity-[0.07]" aria-hidden="true">
                <defs>
                    <pattern :id="patternId" width="72" height="72" patternUnits="userSpaceOnUse">
                        <circle cx="36" cy="36" r="20" fill="none" stroke="white" stroke-width="1" />
                        <circle cx="36" cy="36" r="30" fill="none" stroke="white" stroke-width="0.5" />
                        <path d="M0 36h72M36 0v72" stroke="white" stroke-width="0.4" />
                    </pattern>
                </defs>
                <rect width="100%" height="100%" :fill="`url(#${patternId})`" />
            </svg>

            <div class="relative mx-auto max-w-3xl px-4 py-14 text-center sm:px-6 sm:py-16 lg:px-8">
                <span class="mx-auto inline-flex size-16 items-center justify-center rounded-full bg-white/10 ring-1 ring-white/20">
                    <AppIcon name="shield" size="lg" class="text-white" />
                </span>

                <h1 class="mt-6 text-3xl font-semibold tracking-tight text-balance sm:text-4xl">
                    Verify a certificate
                </h1>
                <p class="mx-auto mt-4 max-w-xl text-base leading-relaxed text-pretty text-white/85">
                    Every training certificate issued by the {{ office.name }} carries a verification code. Enter
                    it below to confirm the certificate is genuine and see what it was awarded for.
                </p>
            </div>
        </section>

        <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 sm:py-14 lg:px-8">
            <!-- The form, lifted onto its own card so it reads as the one task here. -->
            <div class="mx-auto max-w-xl rounded-2xl border border-csc-line bg-white p-6 shadow-sm sm:p-8">
                <form novalidate @submit.prevent="submit">
                    <!--
                        Monospaced and letter-spaced, because this field is
                        filled by transcribing characters off paper rather than
                        by typing a word. In a proportional face 1/l and 0/O are
                        a guess, and a mistyped character is the single likeliest
                        reason a genuine certificate fails to verify here.
                    -->
                    <AppInput
                        v-model="form.code"
                        label="Verification code"
                        hint="Printed at the foot of the certificate, directly beneath the QR code."
                        placeholder="3f9a2c7b8e1d4a6f"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        class="font-mono tracking-[0.15em]"
                        :error="form.errors.code"
                        required
                        autofocus
                    />

                    <AppButton type="submit" size="lg" block class="mt-5" :loading="form.processing" icon="arrow-forward">
                        {{ form.processing ? 'Checking…' : 'Verify certificate' }}
                    </AppButton>
                </form>

                <!--
                    Where to look.

                    A diagram rather than a sentence, because "beneath the QR
                    code" means nothing until you have found the QR code. Someone
                    holding an unfamiliar document can match this shape against
                    the page in front of them in about a second, which is faster
                    than reading any description of it would be.
                -->
                <div class="mt-8 border-t border-csc-line pt-6">
                    <h2 class="text-sm font-semibold text-csc-blue">Where to find the code</h2>

                    <p class="mt-2 text-sm leading-relaxed text-csc-ink-muted">
                        Look at the foot of the certificate. The code is printed directly beneath the QR code —
                        scanning that QR opens this same check automatically, and the printed code is there for
                        when you cannot scan it, or you only have a photocopy.
                    </p>

                    <!--
                        A schematic of the foot of the certificate, not a facsimile.
                    
                        The QR sits low on the sheet with the verification code
                        printed directly under it, and the drawing has to put them
                        in that relationship: someone matching this against the
                        document in their hand is looking for a stacked pair, and
                        a version that set the code off to one side sent them
                        scanning along the wrong axis.
                    
                        Drawn at full width because the QR and the code line are
                        the entire point of the picture — the body-copy bars exist
                        only to give the pair a position on the page.
                    -->
                    <svg
                        class="mt-5 h-auto w-full"
                        viewBox="0 0 400 250"
                        fill="none"
                        role="img"
                        aria-label="Diagram of a certificate: the QR code sits at the foot of the page, with the verification code printed directly beneath it."
                    >
                        <!-- The sheet -->
                        <rect x="2" y="2" width="256" height="246" rx="6" class="fill-white stroke-csc-line" stroke-width="2.5" />

                        <!-- Seal and heading, so the silhouette reads as a certificate at a glance -->
                        <circle cx="130" cy="30" r="12" class="fill-csc-blue/15 stroke-csc-blue/40" stroke-width="1.5" />
                        <rect x="84" y="52" width="92" height="8" rx="4" class="fill-csc-blue/40" />
                        <rect x="105" y="68" width="50" height="5" rx="2.5" class="fill-csc-ink/20" />

                        <!-- Stand-in for the certificate's own body copy -->
                        <rect x="40" y="94" width="180" height="6" rx="3" class="fill-csc-ink/15" />
                        <rect x="55" y="108" width="150" height="6" rx="3" class="fill-csc-ink/15" />
                        <rect x="70" y="122" width="120" height="6" rx="3" class="fill-csc-ink/15" />

                        <!--
                            The QR, drawn with its three finder squares. A plain grey
                            block could be anything; the finder corners are the thing
                            people actually recognise on a page.
                        -->
                        <g class="fill-csc-blue/70">
                            <rect x="38" y="158" width="56" height="56" rx="3" class="fill-white stroke-csc-blue/50" stroke-width="1.5" />
                            <rect x="44" y="164" width="14" height="14" rx="1.5" />
                            <rect x="74" y="164" width="14" height="14" rx="1.5" />
                            <rect x="44" y="194" width="14" height="14" rx="1.5" />
                            <rect x="64" y="170" width="5" height="5" />
                            <rect x="64" y="182" width="5" height="5" />
                            <rect x="76" y="184" width="5" height="5" />
                            <rect x="68" y="194" width="5" height="5" />
                            <rect x="82" y="196" width="5" height="5" />
                            <rect x="66" y="204" width="5" height="5" />
                            <rect x="80" y="206" width="5" height="5" />
                        </g>

                        <!-- The code line, directly beneath the QR and centred on it -->
                        <rect x="20" y="224" width="92" height="18" rx="4" class="fill-csc-red/10 stroke-csc-red-ink" stroke-width="2.5" />
                        <g class="fill-csc-red-ink/70">
                            <rect x="28" y="230" width="7" height="6" rx="1.5" />
                            <rect x="39" y="230" width="7" height="6" rx="1.5" />
                            <rect x="50" y="230" width="7" height="6" rx="1.5" />
                            <rect x="61" y="230" width="7" height="6" rx="1.5" />
                            <rect x="72" y="230" width="7" height="6" rx="1.5" />
                            <rect x="83" y="230" width="7" height="6" rx="1.5" />
                            <rect x="94" y="230" width="7" height="6" rx="1.5" />
                        </g>

                        <!--
                            Leader lines out to the margin. The red rule alone says
                            "this bit matters" but not which of the two stacked marks
                            is the one to type.
                        -->
                        <path d="M98 186h170" class="stroke-csc-ink-subtle/60" stroke-width="1.5" stroke-linecap="round" stroke-dasharray="4 4" />
                        <circle cx="98" cy="186" r="3" class="fill-csc-ink-subtle/60" />
                        <text x="274" y="190" class="fill-csc-ink-subtle text-[12px]" style="font-family: inherit">QR code</text>

                        <path d="M116 233h152" class="stroke-csc-red-ink" stroke-width="2" stroke-linecap="round" stroke-dasharray="5 4" />
                        <circle cx="116" cy="233" r="3.5" class="fill-csc-red-ink" />
                        <text x="274" y="228" class="fill-csc-red-ink text-[13px] font-semibold" style="font-family: inherit">Verification</text>
                        <text x="274" y="245" class="fill-csc-red-ink text-[13px] font-semibold" style="font-family: inherit">code</text>
                    </svg>
                </div>
            </div>

            <!--
                Says plainly what a result does and does not prove. The same two
                points appear on the result page, deliberately: a verifier should
                meet the limits of the answer before they ask and again when they
                get it, rather than discovering them later.
            -->
            <div class="mx-auto mt-8 max-w-xl rounded-xl border border-csc-line bg-csc-blue-tint px-6 py-5">
                <h2 class="text-sm font-semibold text-csc-blue">What this check tells you</h2>
                <ul class="mt-3 space-y-2.5 text-sm leading-relaxed text-csc-ink-muted">
                    <li class="flex items-start gap-2.5">
                        <AppIcon name="check" size="sm" class="mt-0.5 shrink-0 text-success" />
                        A match confirms the Commission issued that certificate, to that person, for that
                        training.
                    </li>
                    <li class="flex items-start gap-2.5">
                        <AppIcon name="close" size="sm" class="mt-0.5 shrink-0 text-csc-ink-subtle" />
                        It does not confirm that the paper copy in front of you is the original, only that the
                        record behind the code is real.
                    </li>
                </ul>
                <p class="mt-4 border-t border-csc-line pt-4 text-sm leading-relaxed text-csc-ink-subtle">
                    If a code will not verify and you believe it should,
                    <a
                        href="/#contact"
                        class="font-medium text-csc-blue underline underline-offset-4 hover:text-csc-blue-deep"
                    >
                        contact the office</a>. A mistyped character is far and away the likeliest explanation.
                </p>
            </div>
        </div>
    </PublicLayout>
</template>
