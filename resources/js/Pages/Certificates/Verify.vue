<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppBrandBackdrop from '@/Components/AppBrandBackdrop.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { spansMultipleDays } from '@/dateRange';

/**
 * The result of a certificate check.
 *
 * The governing constraint is what this page must NOT be: a certificate. It is
 * the record of an enquiry — someone asked whether a document is genuine, and
 * this is the answer with the date it was given. So it deliberately borrows
 * none of the certificate's own visual language: no ornamental border, no
 * script lettering, no signature block, no landscape sheet. Printed, it should
 * read unmistakably as a receipt from the Commission's records, never as
 * something that could be filed in place of the document it vouches for.
 *
 * Within that limit it still has to carry weight, because the person reading it
 * is deciding whether to trust a document in front of them. The gravity comes
 * from structure rather than decoration: one unambiguous verdict, the facts set
 * out as a record, and a dated stamp saying who confirmed it and when.
 */
defineProps({
    certificate: { type: Object, required: true },
    verifiedAt: { type: String, default: null },
});

// The issuing office is a deployment fact now (config/office.php), so this page
// names whoever actually ran the training rather than a hard-coded region.
const page = usePage();
const office = computed(() => page.props.office ?? {});

// `window` is not in template scope in an SFC, so the handler lives here.
const printResult = () => window.print();
</script>

<template>
    <Head :title="`Certificate ${certificate.number} verified`">
        <!--
            Verification results are records about a named person. They are
            public by design — anyone holding the document may check it — but
            that is not the same as wanting them indexed and searchable by name.
        -->
        <meta name="robots" content="noindex" />
    </Head>

    <!-- See VerifyLookup: matches no nav key, so the header marks nothing. -->
    <PublicLayout current="verify">
        <!--
            The record sits on the site's own backdrop rather than on nothing.

            This page used to be a white card on a white page, which read as an
            unstyled document the browser had rendered by accident — the worst
            possible impression for the one screen whose entire job is to look
            authoritative to a stranger checking a stranger's credentials.

            The facade band is short and stops behind the verdict: it identifies
            whose records these are and then gets out of the way. The record
            itself keeps every pixel of its white, because that is the part
            being read. Both are dropped for print by the rules below.
        -->
        <div class="relative bg-csc-blue-tint print:bg-white">
            <div class="absolute inset-x-0 top-0 h-80 print:hidden" aria-hidden="true">
                <AppBrandBackdrop object-position="center 45%" wash="soft" />

                <!--
                    The same wave the home page and the lookup form close their
                    hero with. Without it the band ended on a hard horizontal
                    rule straight across the middle of the record card, which
                    read as a rendering fault rather than as a design.
                -->
                <div class="absolute inset-x-0 bottom-0 h-10 overflow-hidden">
                    <svg
                        viewBox="0 0 1440 40"
                        preserveAspectRatio="none"
                        class="absolute inset-0 size-full fill-csc-blue-tint"
                    >
                        <path
                            d="M0 40L60 36C120 32 240 20 360 18C480 16 600 24 720 28C840 32 960 30 1080 24C1200 20 1320 24 1380 28L1440 32V40H0Z"
                        />
                    </svg>
                </div>
            </div>

            <div class="relative mx-auto max-w-3xl px-4 py-10 sm:py-14">
            <!--
                The verdict, before anything else.

                Status is never colour alone: the green carries an icon and the
                word "verified" in text, so it survives a greyscale printout and
                a colour-blind reader alike.

                The print: variants are not polish. Browsers drop background
                colours when printing unless a page asks otherwise, so a solid
                green band carrying white text prints as white text on white
                paper — the verdict, the one thing this page exists to state,
                would vanish from every copy anyone filed. On paper it inverts
                to green ink on white inside a green rule instead.
            -->
            <div
                class="verify-rise flex items-start gap-4 rounded-t-2xl bg-success px-6 py-6 text-white sm:items-center sm:px-8 print:rounded-none print:border print:border-success print:bg-white print:text-success"
            >
                <!--
                    The mark, drawn rather than stamped out whole.

                    A check that draws itself is the difference between a page
                    asserting a verdict and a page *reaching* one — the ring
                    closes, then the tick is struck through it, which is the
                    order the act actually happens in. It replaces the shield
                    icon here (and only here) because AppIcon renders a static
                    path: the registry is for interface furniture, and this is
                    the single illustration the page exists to deliver.

                    aria-hidden throughout: "Certificate verified" is stated in
                    text immediately to the right, and a screen reader gains
                    nothing from a second, wordless assertion of the same thing.
                -->
                <span
                    class="flex size-12 shrink-0 items-center justify-center rounded-full bg-white/15 sm:size-14 print:bg-transparent"
                >
                    <svg
                        class="size-7 sm:size-8"
                        viewBox="0 0 32 32"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <!-- 2πr at r=13, so the dash length is the ring itself. -->
                        <circle
                            cx="16"
                            cy="16"
                            r="13"
                            class="verify-draw text-white print:text-success"
                            style="--draw-length: 82; --delay: 0.15s"
                        />
                        <path
                            d="M10 16.5l4.2 4.2L22.5 12"
                            class="verify-draw text-white print:text-success"
                            style="--draw-length: 18; --delay: 0.55s"
                        />
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="verify-rise text-xl font-semibold tracking-tight sm:text-2xl" style="--delay: 0.1s">
                        Certificate verified
                    </p>
                    <p
                        class="verify-rise mt-1 text-sm leading-relaxed text-white/90 print:text-csc-ink-muted"
                        style="--delay: 0.18s"
                    >
                        This certificate appears in the records of the {{ office.name }}.
                    </p>
                </div>
            </div>

            <!-- The record -->
            <div
                class="verify-rise relative overflow-hidden rounded-b-2xl border border-t-0 border-csc-line bg-white"
                style="--delay: 0.22s"
            >
                <!--
                    A large, very faint seal behind the record. Watermark rather
                    than ornament: it is what makes a photocopy of this page read
                    as a document from somewhere, and it sits at an opacity low
                    enough that it never competes with the text over it. Dropped
                    on paper, where a faint grey wash is just toner.
                -->
                <svg
                    class="pointer-events-none absolute -right-16 -bottom-16 size-72 text-csc-blue opacity-[0.04] print:hidden"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="0.6"
                    aria-hidden="true"
                >
                    <circle cx="12" cy="12" r="10.5" />
                    <circle cx="12" cy="12" r="8.5" />
                    <circle cx="12" cy="12" r="6" />
                    <path d="M12 1.5v21M1.5 12h21M4.5 4.5l15 15M19.5 4.5l-15 15" />
                </svg>

                <div class="relative px-6 py-7 sm:px-8 sm:py-9">
                    <!--
                        Who, and what for: the two facts the reader came for, and
                        so the two that arrive first once the card is down.
                    -->
                    <div class="verify-rise" style="--delay: 0.34s">
                        <p class="text-2xs font-semibold tracking-widest text-csc-ink-subtle uppercase">Awarded to</p>
                        <p class="mt-1.5 text-2xl leading-tight font-semibold text-balance text-csc-blue sm:text-3xl">
                            {{ certificate.participant }}
                        </p>
                    </div>

                    <div class="verify-rise" style="--delay: 0.42s">
                        <p class="mt-6 text-2xs font-semibold tracking-widest text-csc-ink-subtle uppercase">
                            For completing
                        </p>
                        <p class="mt-1.5 text-lg leading-snug font-medium text-balance text-csc-ink">
                            {{ certificate.training }}
                        </p>
                    </div>

                    <hr class="verify-rise my-7 border-csc-line" style="--delay: 0.48s" />

                    <!--
                        The supporting facts, staged in reading order. The stagger
                        is small and stops at four: past about half a second of
                        total offset a stagger stops reading as sequence and
                        starts reading as the page loading slowly.
                    -->
                    <dl class="grid gap-6 sm:grid-cols-2">
                        <div class="verify-rise" style="--delay: 0.54s">
                            <dt class="flex items-center gap-1.5 text-2xs font-semibold tracking-widest text-csc-ink-subtle uppercase">
                                <AppIcon name="calendar" size="sm" class="text-csc-blue/50" />
                                Conducted
                            </dt>
                            <dd class="mt-1.5 text-sm font-medium text-csc-ink">
                                {{ certificate.starts_at }}
                                <!-- A single-day run stores the same date twice. -->
                                <template v-if="spansMultipleDays(certificate.starts_at, certificate.ends_at)">
                                    – {{ certificate.ends_at }}
                                </template>
                            </dd>
                            <dd class="mt-0.5 text-sm text-csc-ink-muted">
                                {{ certificate.duration_days }}
                                {{ certificate.duration_days === 1 ? 'day' : 'days' }}
                            </dd>
                        </div>

                        <div class="verify-rise" style="--delay: 0.6s">
                            <dt class="flex items-center gap-1.5 text-2xs font-semibold tracking-widest text-csc-ink-subtle uppercase">
                                <AppIcon name="map-pin" size="sm" class="text-csc-blue/50" />
                                Venue
                            </dt>
                            <dd class="mt-1.5 text-sm font-medium text-csc-ink">{{ certificate.venue }}</dd>
                        </div>

                        <div class="verify-rise" style="--delay: 0.66s">
                            <dt class="flex items-center gap-1.5 text-2xs font-semibold tracking-widest text-csc-ink-subtle uppercase">
                                <AppIcon name="certificate" size="sm" class="text-csc-blue/50" />
                                Certificate number
                            </dt>
                            <!--
                                Monospaced, and allowed to break anywhere. These
                                are transcribed by hand off a printed page far
                                more often than they are copied, and in a
                                proportional face 1/l and 0/O become a guess.
                            -->
                            <dd class="mt-1.5 font-mono text-sm break-all text-csc-ink">{{ certificate.number }}</dd>
                            <dd class="mt-0.5 text-sm text-csc-ink-muted">Issued {{ certificate.issued_at }}</dd>
                        </div>

                        <div class="verify-rise" style="--delay: 0.72s">
                            <dt class="flex items-center gap-1.5 text-2xs font-semibold tracking-widest text-csc-ink-subtle uppercase">
                                <AppIcon name="qr" size="sm" class="text-csc-blue/50" />
                                Verification code
                            </dt>
                            <dd class="mt-1.5 font-mono text-sm break-all text-csc-ink">{{ certificate.code }}</dd>
                        </div>
                    </dl>
                </div>

                <!--
                    The stamp.

                    This strip is the whole reason the page is worth printing:
                    it says who confirmed the record and exactly when. Without
                    the timestamp a filed printout claims only that the
                    certificate was genuine at some unstated moment, which is
                    the one thing a record in a folder must not be vague about.

                    So it is the one element that lands rather than rises, and
                    it lands last — after every fact it is vouching for is on
                    the page. Settling from very slightly oversized is what a
                    stamp being pressed looks like, and the sequence ends on the
                    gesture the strip already means.
                -->
                <div
                    v-if="verifiedAt"
                    class="verify-stamp relative flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-csc-line bg-csc-blue-tint px-6 py-4 sm:px-8"
                    style="--delay: 0.8s"
                >
                    <AppIcon name="check-circle" size="sm" class="shrink-0 text-success" />
                    <p class="text-sm text-csc-ink-muted">
                        Checked against Commission records on
                        <span class="font-semibold text-csc-ink">{{ verifiedAt }}</span>
                    </p>
                </div>
            </div>

            <!--
                What the check does and does not settle. The same two points
                appear on the lookup form, deliberately: a verifier should meet
                the limits of the answer before they ask and again when they get
                it, rather than discovering them later.
            -->
            <!-- White, now that the page behind it is tinted: transparent, it stopped reading as a panel. -->
            <div class="verify-rise mt-6 rounded-xl border border-csc-line bg-white px-6 py-5" style="--delay: 0.9s">
                <h2 class="text-sm font-semibold text-csc-blue">What this confirms</h2>
                <ul class="mt-3 space-y-2.5 text-sm leading-relaxed text-csc-ink-muted">
                    <li class="flex items-start gap-2.5">
                        <AppIcon name="check" size="sm" class="mt-0.5 shrink-0 text-success" />
                        The Commission issued this certificate, to this person, for this training.
                    </li>
                    <li class="flex items-start gap-2.5">
                        <AppIcon name="close" size="sm" class="mt-0.5 shrink-0 text-csc-ink-subtle" />
                        It does not confirm that the paper copy you are holding is the original. If the document
                        in front of you differs in any detail from the record above, treat it as unverified and
                        <a
                            href="/#contact"
                            class="font-medium text-csc-blue underline underline-offset-4 hover:text-csc-blue-deep"
                        >
                            contact the office</a>.
                    </li>
                </ul>
            </div>

            <!--
                Hidden on paper: a printed page cannot be clicked, and "Check
                another certificate" on a filed record reads as an instruction
                to whoever opens the folder.
            -->
            <div class="verify-rise mt-8 flex flex-wrap items-center justify-center gap-3 print:hidden" style="--delay: 0.96s">
                <AppButton variant="ghost" icon="print" @click="printResult">Print this result</AppButton>
                <AppButton href="/verify" variant="ghost" icon="arrow-forward">Check another certificate</AppButton>
            </div>
            </div>
        </div>
    </PublicLayout>
</template>
