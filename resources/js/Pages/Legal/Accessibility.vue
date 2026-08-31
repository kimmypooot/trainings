<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import LegalLayout from '@/Layouts/LegalLayout.vue';

// Named so the statement points at the office that actually has to answer a
// report, rather than at "the CSC" generally.
const page = usePage();
const office = computed(() => page.props.office ?? {});
</script>

<template>
    <Head title="Accessibility" />

    <LegalLayout title="Accessibility Statement" updated="21 August 2026">
        <section>
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">1. Our commitment</h2>
            <p>
                The {{ office.name }} is committed to making the Training Information Management System (TIMS)
                usable by everyone, including personnel who use screen readers, keyboard-only navigation, screen
                magnification, or other assistive technology. Access to the Commission's training programs should
                not depend on how a person uses a computer.
            </p>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">2. Conformance target</h2>
            <p>
                This site aims to conform to the
                <strong>Web Content Accessibility Guidelines (WCAG) 2.1, Level AA</strong>, consistent with the
                Philippine Government Web Norms and with Republic Act No. 7277 (the Magna Carta for Persons with
                Disability) as amended.
            </p>
            <p class="mt-3">
                We describe the site as <strong>partially conformant</strong>: most of it meets Level AA, and the
                areas we know fall short are listed below rather than left for a visitor to discover.
            </p>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">3. What we have done</h2>
            <ul class="list-inside list-disc space-y-1 text-csc-ink-muted">
                <li>Text and background colours are checked against the 4.5:1 minimum for body-size text.</li>
                <li>
                    Status is never carried by colour alone — every status badge pairs its colour with an icon and
                    a written label.
                </li>
                <li>Every page can be operated by keyboard, and a "Skip to content" link opens each page.</li>
                <li>Form fields carry visible labels, and errors are announced, not just coloured red.</li>
                <li>Animation is reduced automatically for visitors whose system requests reduced motion.</li>
                <li>Pages are readable at 200% zoom and reflow to a single column on small screens.</li>
            </ul>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">4. Known limitations</h2>
            <p>
                We are aware of the following, and are working on them. Listing them is not an excuse for them —
                it is so a visitor who hits one knows it is a defect and not their error:
            </p>
            <ul class="mt-3 list-inside list-disc space-y-1 text-csc-ink-muted">
                <li>
                    <strong>Uploaded documents.</strong> Certificates and payment records are issued as PDFs. Some
                    older documents were produced before we began tagging them for screen readers.
                </li>
                <li>
                    <strong>QR check-in.</strong> Scanning at an event assumes a working camera. Any participant
                    who cannot use the scanner can be checked in manually by staff at the venue — this is a
                    supported route, not a workaround.
                </li>
                <li>
                    <strong>The site is currently available in English only.</strong>
                </li>
            </ul>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-csc-blue">5. Reporting a barrier</h2>
            <p>
                If you cannot reach something on this site, please tell us. Include the page address, what you were
                trying to do, and the assistive technology or browser you were using, if you know it.
            </p>
            <ul class="mt-3 space-y-1">
                <li>
                    Email:
                    <a
                        :href="`mailto:${office.email}`"
                        class="font-medium text-csc-blue underline underline-offset-4 hover:text-csc-blue-deep"
                    >
                        {{ office.email }}
                    </a>
                </li>
                <li v-if="office.phone">Telephone: {{ office.phone }}</li>
                <li>Office: {{ office.address }}</li>
            </ul>
            <p class="mt-3">
                We aim to respond within five working days. If a program's registration closes while we are
                resolving an access problem you reported, contact the office — we will not treat a barrier on this
                site as a missed deadline on your part.
            </p>
        </section>
    </LegalLayout>
</template>
