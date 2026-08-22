<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';

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
        <div class="mx-auto max-w-3xl px-4 py-10 sm:py-14">
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
                class="flex items-start gap-4 rounded-t-2xl bg-success px-6 py-6 text-white sm:items-center sm:px-8 print:rounded-none print:border print:border-success print:bg-white print:text-success"
            >
                <span
                    class="flex size-12 shrink-0 items-center justify-center rounded-full bg-white/15 sm:size-14 print:bg-transparent"
                >
                    <AppIcon name="shield" size="lg" class="text-white print:text-success" />
                </span>
                <div class="min-w-0">
                    <p class="text-xl font-semibold tracking-tight sm:text-2xl">Certificate verified</p>
                    <p class="mt-1 text-sm leading-relaxed text-white/90 print:text-csc-ink-muted">
                        This certificate appears in the records of the {{ office.name }}.
                    </p>
                </div>
            </div>

            <!-- The record -->
            <div class="relative overflow-hidden rounded-b-2xl border border-t-0 border-csc-line bg-white">
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
                    <!-- Who, and what for: the two facts the reader came for. -->
                    <p class="text-2xs font-semibold tracking-widest text-csc-ink-subtle uppercase">Awarded to</p>
                    <p class="mt-1.5 text-2xl leading-tight font-semibold text-balance text-csc-blue sm:text-3xl">
                        {{ certificate.participant }}
                    </p>

                    <p class="mt-6 text-2xs font-semibold tracking-widest text-csc-ink-subtle uppercase">
                        For completing
                    </p>
                    <p class="mt-1.5 text-lg leading-snug font-medium text-balance text-csc-ink">
                        {{ certificate.training }}
                    </p>

                    <hr class="my-7 border-csc-line" />

                    <dl class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <dt class="flex items-center gap-1.5 text-2xs font-semibold tracking-widest text-csc-ink-subtle uppercase">
                                <AppIcon name="calendar" size="sm" class="text-csc-blue/50" />
                                Conducted
                            </dt>
                            <dd class="mt-1.5 text-sm font-medium text-csc-ink">
                                {{ certificate.starts_at }}
                                <!-- A single-day run stores the same date twice. -->
                                <template v-if="certificate.ends_at !== certificate.starts_at">
                                    – {{ certificate.ends_at }}
                                </template>
                            </dd>
                            <dd class="mt-0.5 text-sm text-csc-ink-muted">
                                {{ certificate.duration_days }}
                                {{ certificate.duration_days === 1 ? 'day' : 'days' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="flex items-center gap-1.5 text-2xs font-semibold tracking-widest text-csc-ink-subtle uppercase">
                                <AppIcon name="map-pin" size="sm" class="text-csc-blue/50" />
                                Venue
                            </dt>
                            <dd class="mt-1.5 text-sm font-medium text-csc-ink">{{ certificate.venue }}</dd>
                        </div>

                        <div>
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

                        <div>
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
                -->
                <div
                    v-if="verifiedAt"
                    class="relative flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-csc-line bg-csc-blue-tint px-6 py-4 sm:px-8"
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
            <div class="mt-6 rounded-xl border border-csc-line px-6 py-5">
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
            <div class="mt-8 flex flex-wrap items-center justify-center gap-3 print:hidden">
                <AppButton variant="ghost" icon="print" @click="printResult">Print this result</AppButton>
                <AppButton href="/verify" variant="ghost" icon="arrow-forward">Check another certificate</AppButton>
            </div>
        </div>
    </PublicLayout>
</template>
