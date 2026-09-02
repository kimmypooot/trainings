<script setup>
/**
 * The endings of a scan that did not reach a form.
 *
 * Read by somebody standing in a function room, on a phone, seconds after
 * pointing it at a poster — so the page is one card, one sentence and one way
 * onward, and it says the training's name back to them. Confirming *what they
 * scanned* is most of the value: half of these outcomes are "you scanned the
 * wrong thing", and a page that does not name the thing cannot tell them that.
 *
 * A successful scan never renders this; it redirects into the form. Every state
 * here is therefore a refusal, and none of them are the participant's fault, so
 * none of them are phrased as though they were.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    outcome: { type: String, required: true },
    title: { type: String, required: true },
    /** The service's own sentence, when there is one. */
    reason: { type: String, default: null },
    training: { type: Object, default: null },
    day: { type: Number, default: null },
    catalogueUrl: { type: String, required: true },
    evaluationsUrl: { type: String, required: true },
});

/*
 * Icon and tone per outcome.
 *
 * Tones stay inside the app's semantic set rather than reaching for the brand
 * red, and none of them is danger: nothing here has gone wrong, in the sense
 * that word carries elsewhere in the app. The worst case is a poster that is out
 * of date, which is the office's problem and not the reader's.
 */
const tones = {
    blocked: { icon: 'clock', wrap: 'bg-warning-soft text-warning' },
    not_registered: { icon: 'user', wrap: 'bg-info-soft text-info' },
    no_longer_scheduled: { icon: 'calendar', wrap: 'bg-csc-line/60 text-csc-ink-subtle' },
    revoked: { icon: 'close', wrap: 'bg-csc-line/60 text-csc-ink-subtle' },
};

const tone = computed(() => tones[props.outcome] ?? tones.revoked);

/*
 * What to say when the service has no sentence of its own.
 *
 * `reason` is only populated for a blocked day — the other outcomes are states
 * of the code or the roster rather than of the evaluation, so their explanation
 * belongs here, next to the tone that goes with them.
 */
const fallbacks = {
    not_registered:
        'This code belongs to a training you are not registered for. If you attended it, ask the training staff to check the roster before you leave.',
    no_longer_scheduled:
        'The schedule for this training changed after this code was printed, and the day it points to is no longer part of the run.',
    revoked:
        'This code has been withdrawn or replaced. Your evaluations are all still reachable from your own list.',
};

const explanation = computed(() => props.reason ?? fallbacks[props.outcome] ?? fallbacks.revoked);

// Named so the reader can confirm the poster matched the room they were in.
const scanned = computed(() => {
    if (!props.training) return null;

    return props.day ? `${props.training.title} · Day ${props.day}` : props.training.title;
});
</script>

<template>
    <Head :title="title" />

    <AuthenticatedLayout title="Session Evaluations" current="evaluations">
        <!--
            Narrow and centred rather than the usual wide column: this page holds
            one short message, and on the phone that will actually read it the
            difference is invisible, while on a desktop a full-width card for two
            sentences reads as though something failed to load.
        -->
        <div class="mx-auto max-w-lg">
            <AppCard>
                <div class="text-center">
                    <span
                        class="mx-auto inline-flex size-14 items-center justify-center rounded-2xl"
                        :class="tone.wrap"
                    >
                        <AppIcon :name="tone.icon" size="lg" />
                    </span>

                    <h2 class="mt-4 text-lg font-semibold tracking-tight text-csc-blue">{{ title }}</h2>

                    <p v-if="scanned" class="mt-1 text-sm font-medium text-csc-ink">{{ scanned }}</p>
                    <p v-if="training?.dates" class="mt-0.5 text-xs text-csc-ink-subtle">
                        {{ training.dates }}
                    </p>

                    <p class="mt-3 text-sm leading-relaxed text-csc-ink-muted">{{ explanation }}</p>
                </div>

                <template #footer>
                    <!--
                        Always a way onward. A dead end reached by scanning is the
                        one that generates a phone call, because the reader has no
                        URL to edit and no back button that helps.
                    -->
                    <div class="flex flex-col gap-2 sm:flex-row sm:justify-center">
                        <AppButton :href="evaluationsUrl" size="sm" icon="clipboard">
                            My Evaluations
                        </AppButton>
                        <AppButton
                            v-if="outcome === 'not_registered'"
                            :href="catalogueUrl"
                            size="sm"
                            variant="ghost"
                        >
                            Browse Trainings
                        </AppButton>
                    </div>
                </template>
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
