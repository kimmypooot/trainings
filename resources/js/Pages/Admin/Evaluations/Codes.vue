<script setup>
/**
 * The posters, laid out for paper.
 *
 * A separate page from the roster panel rather than a print stylesheet over it,
 * because they are not the same document: the panel is a management view of scan
 * counts and buttons, and this is a sign that has to be read, and scanned, from
 * a few metres away in a function room with the lights half down.
 *
 * One evaluation day per sheet. Days that carry over are absent entirely — they
 * collect no feedback and a poster for one would send the room to a form that
 * turns them away. The roster panel is where that rule is explained; here it
 * would only be a blank page to throw out.
 */
import { Head } from '@inertiajs/vue3';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

defineProps({
    training: { type: Object, required: true },
    sheets: { type: Array, default: () => [] },
    rosterUrl: { type: String, required: true },
});

// The browser's own dialog. Nothing to prepare first — the sheets below are the
// printed document, and everything that is not part of it is print:hidden.
const printSheets = () => window.print();
</script>

<template>
    <Head :title="`Evaluation codes — ${training.title}`" />

    <div class="min-h-screen bg-csc-blue-tint/40 px-4 py-8 print:bg-white print:p-0">
        <!--
            The only chrome, and it does not print. Deliberately not the app
            shell: a sidebar and header on a page whose entire purpose is to
            come out of a printer is three extra things to hide.
        -->
        <div class="mx-auto mb-6 flex max-w-3xl flex-wrap items-center justify-between gap-3 print:hidden">
            <div>
                <h1 class="text-lg font-semibold tracking-tight text-csc-blue">{{ training.title }}</h1>
                <p class="mt-0.5 text-sm text-csc-ink-muted">
                    {{ training.dates }}<template v-if="training.code"> · {{ training.code }}</template>
                </p>
            </div>
            <div class="flex gap-2">
                <AppButton :href="rosterUrl" size="sm" variant="ghost">Back to roster</AppButton>
                <AppButton size="sm" icon="download" @click="printSheets">Print</AppButton>
            </div>
        </div>

        <div v-if="!sheets.length" class="mx-auto max-w-3xl print:hidden">
            <AppEmptyState
                icon="clipboard"
                title="No codes to print"
                description="Generate the evaluation codes from the training's roster first."
            >
                <template #action>
                    <AppButton :href="rosterUrl" variant="ghost">Back to roster</AppButton>
                </template>
            </AppEmptyState>
        </div>

        <!--
            break-after-page on every sheet but the last, so a four-day run comes
            out as four signs rather than one long ribbon.
        -->
        <article
            v-for="(sheet, index) in sheets"
            :key="sheet.day"
            class="mx-auto mb-6 max-w-3xl rounded-2xl border border-csc-line bg-white p-8 text-center last:mb-0 print:mb-0 print:rounded-none print:border-0 print:p-0"
            :class="index < sheets.length - 1 ? 'print:break-after-page' : ''"
        >
            <p class="text-xs font-semibold tracking-widest text-csc-ink-subtle uppercase">
                Session evaluation
            </p>

            <h2 class="mt-2 text-2xl font-bold tracking-tight text-csc-blue">{{ sheet.training }}</h2>

            <p class="mt-1 text-lg font-semibold text-csc-ink">Day {{ sheet.day }}</p>
            <p class="text-sm text-csc-ink-muted">{{ sheet.date }}</p>

            <p v-if="sheet.experts.length" class="mt-1 text-sm text-csc-ink-muted">
                {{ sheet.experts.join(' · ') }}
            </p>

            <!--
                `only light` and no forced-colour adjustment: a QR rendered in
                inverted or high-contrast colours does not scan, and the printer
                is not the only thing that decides those.
            -->
            <div
                class="mx-auto mt-6 w-fit rounded-xl border border-csc-line bg-white p-4"
                style="color-scheme: only light; forced-color-adjust: none"
            >
                <img :src="sheet.qr" :alt="`Evaluation code for day ${sheet.day}`" class="size-64 sm:size-72" />
            </div>

            <p class="mt-5 text-base font-medium text-csc-ink">
                Scan this code to rate today's session
            </p>
            <p class="mt-1 text-sm text-csc-ink-muted">
                Sign in with your CSC TIMS account — your evaluation is linked to you automatically.
            </p>

            <!--
                The URL in text as well as in the code. A phone whose camera will
                not focus, a cracked screen, a participant who would rather type:
                the fallback costs one line and removes the only failure mode a
                poster has.
            -->
            <p class="mt-4 font-mono text-xs break-all text-csc-ink-subtle">{{ sheet.url }}</p>

            <p v-if="sheet.revoked" class="mt-4 text-sm font-semibold text-warning print:hidden">
                This code has been withdrawn and will not open the form.
            </p>
        </article>
    </div>
</template>
