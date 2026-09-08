<script setup>
import { computed } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppButton from '@/Components/AppButton.vue';
import { verdictStyles } from '@/scanner/station';

/**
 * The viewfinder and the verdict — the whole of what a facilitator looks at.
 *
 * Both stations render this, so a scan that reads as a duplicate at the staff
 * desk cannot look like something else at the volunteer's door.
 *
 * Three decisions here are about a queue rather than about looks:
 *
 *  - The verdict covers the *whole* frame rather than sitting in a strip along
 *    the bottom of it. It used to be a strip, and at arm's length across a
 *    registration desk a green strip and an amber one are the same object with
 *    a different tint; a full-frame colour change is legible in peripheral
 *    vision, which is where the operator's attention actually is while they
 *    are reaching for the next badge.
 *
 *  - The participant's name is the largest text on the page. It is the one
 *    thing the operator has to check against the person standing in front of
 *    them, and everything else in the card is detail they read only when
 *    something looks wrong.
 *
 *  - Nothing here has to be dismissed. The verdict clears itself and the camera
 *    never stops, so the loop is scan → read → next with no tap in between —
 *    see `show()` in scanner/station.js, which is where those timings live. The
 *    one exception is a walk-in offer, which is a decision and therefore waits.
 */
const props = defineProps({
    /*
     * How the page hands the composable its <video> element.
     *
     * A *function*, not the ref itself, and the difference is not stylistic —
     * passing the ref does not work and fails at runtime rather than at build
     * time. `useScanStation` returns `video` as a Ref, and Vue unwraps a
     * setup-returned ref inside the template, so `:video-ref="video"` sends the
     * ref's *current value* — null, on first render — and the function ref here
     * then throws "Cannot set properties of null". The page defines this
     * callback in `<script setup>`, where `video` is still genuinely a Ref.
     *
     * The alternative — leaving the <video> in each page and slotting the
     * chrome around it — is exactly what would let the two doors' viewfinders
     * drift apart again, which is what this component exists to prevent.
     */
    bindVideo: { type: Function, required: true },
    cameraState: { type: String, required: true }, // idle|starting|running|denied|unsupported
    cameraError: { type: String, default: null },
    torchOn: { type: Boolean, default: false },
    hasTorch: { type: Boolean, default: false },
    verdict: { type: Object, default: null },
    /** True while a walk-in admission is in flight — the one action that waits. */
    admitting: { type: Boolean, default: false },
    testing: { type: Boolean, default: false },
    /** "Test mode" at the staff door, "Practice" at the volunteer's. */
    testLabel: { type: String, default: 'Test mode' },
});

const emit = defineEmits(['start', 'stop', 'torch', 'admit']);

const running = computed(() => props.cameraState === 'running');

const skin = computed(() => (props.verdict ? verdictStyles[props.verdict.verdict] : null));

/**
 * The status line above the frame.
 *
 * Deliberately a sentence about the hardware, never about the last scan: the
 * verdict already has the frame, and a strip that changed with it would be the
 * same news twice while the state the operator cannot otherwise see — is the
 * camera actually running? — went unreported.
 */
const status = computed(() => {
    if (props.admitting) {
        return { tone: 'info', label: 'Processing…', hint: 'Admitting the walk-in' };
    }

    switch (props.cameraState) {
        case 'running':
            return { tone: 'success', label: 'Ready to scan', hint: 'Hold the code inside the frame' };
        case 'starting':
            return { tone: 'info', label: 'Starting camera…', hint: 'Allow camera access if asked' };
        case 'denied':
            return { tone: 'danger', label: 'Camera blocked', hint: 'Allow it in the site settings, then start again' };
        case 'unsupported':
            return { tone: 'danger', label: 'No camera', hint: 'Mark arrivals from the roster instead' };
        default:
            return { tone: 'warning', label: 'Camera off', hint: 'Nothing is scanned until you start it' };
    }
});

const statusSkin = {
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    danger: 'bg-danger-soft text-danger',
    info: 'bg-info-soft text-info',
};

/** The four bracket corners of the reticle. */
const corners = [
    'top-0 left-0 rounded-tl-lg border-t-[3px] border-l-[3px]',
    'top-0 right-0 rounded-tr-lg border-t-[3px] border-r-[3px]',
    'bottom-0 left-0 rounded-bl-lg border-b-[3px] border-l-[3px]',
    'bottom-0 right-0 rounded-br-lg border-b-[3px] border-r-[3px]',
];
</script>

<template>
    <section class="overflow-hidden rounded-2xl border border-csc-line bg-white shadow-sm" aria-label="QR scanner">
        <!-- Hardware status. One line, and always the same line. -->
        <div class="flex items-center gap-3 border-b border-csc-line px-4 py-2.5">
            <span
                class="inline-flex shrink-0 items-center gap-2 rounded-full px-3 py-1 text-xs font-bold tracking-wide uppercase"
                :class="statusSkin[status.tone]"
            >
                <span
                    class="size-2 rounded-full bg-current"
                    :class="running && !admitting ? 'animate-pulse' : ''"
                    aria-hidden="true"
                />
                {{ status.label }}
            </span>

            <p class="min-w-0 flex-1 truncate text-2xs text-csc-ink-subtle">{{ status.hint }}</p>

            <span
                v-if="testing"
                class="shrink-0 rounded-full bg-warning-soft px-2.5 py-1 text-2xs font-bold tracking-wide text-warning uppercase"
            >
                {{ testLabel }}
            </span>
        </div>

        <!--
            The viewfinder — the one dark surface in the interface.

            That is both what a video frame needs in order to be legible and
            what makes it the thing the eye lands on when the page opens. The
            page around it is light precisely so this can be the exception.

            Taller than it is wide on a phone and 4:3 from `sm`: a badge is held
            up portrait, and 16:9 spent a tablet's height on ceiling.

            The `max-h` is the part that was arrived at by getting it wrong.
            A 4:3 box in a 700px column is 525px tall, which on a laptop pushed
            the day's three figures — registered, here, remaining — below the
            fold on the one screen whose whole job is telling an operator where
            the session stands at a glance. Capping against the viewport rather
            than at a fixed pixel keeps that true on a 13" laptop and a 27"
            monitor alike; the video is `object-cover`, so the crop costs
            nothing the decoder needs.
            A hardware-decoded `<video>` is also why this box needs `isolate`.
            Mobile Safari and Chrome promote a playing video to its own
            compositing layer, and that layer has been seen painting *above*
            this page's sticky header — z-index and `overflow-hidden` are a
            stacking-context question, and a rogue compositing layer is not
            answering it — right as the address bar collapses or returns on
            scroll, which is exactly when the layer gets rebuilt. `isolate`
            forces this box to own a stacking context of its own, so the video
            layer has a ceiling it cannot paint past regardless of what the
            browser does with the toolbar.
        -->
        <div
            class="relative isolate aspect-[4/5] max-h-[62vh] w-full overflow-hidden bg-csc-blue-deep sm:aspect-[4/3]"
        >
            <video :ref="bindVideo" class="size-full object-cover" muted playsinline />

            <!-- Reticle. Aiming only; the decoder reads the whole frame. -->
            <div v-if="running" class="pointer-events-none absolute inset-0 grid place-items-center">
                <!--
                    Sized off the frame's *height*, not its width. The
                    viewfinder is portrait on a phone and landscape on a
                    tablet, and a percentage of width that reads well in one
                    orientation is a reticle taller than the frame in the other.
                -->
                <div class="relative aspect-square h-[62%] max-w-[78%]">
                    <!-- Everything outside the frame, dimmed. -->
                    <div
                        class="absolute inset-0 rounded-xl shadow-[0_0_0_9999px_rgba(15,20,55,0.45)]"
                        aria-hidden="true"
                    />

                    <span
                        v-for="corner in corners"
                        :key="corner"
                        class="absolute size-9 border-white/95"
                        :class="corner"
                        aria-hidden="true"
                    />

                    <!-- The sweep: proof the decoder is still reading frames. -->
                    <span
                        class="station-sweep absolute inset-x-2 h-0.5 rounded-full bg-gradient-to-r from-transparent via-csc-red to-transparent"
                        aria-hidden="true"
                    />
                </div>
            </div>

            <p
                v-if="running"
                class="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/55 to-transparent px-4 pt-8 pb-3 text-center text-sm font-medium text-white"
            >
                Position the participant’s QR code inside the frame
            </p>

            <!-- Camera off, blocked, or unsupported. -->
            <div v-if="!running" class="absolute inset-0 grid place-items-center bg-csc-blue-deep px-6 py-8 text-center">
                <div class="max-w-sm">
                    <span class="mx-auto grid size-14 place-items-center rounded-2xl bg-white/10 text-white/80">
                        <AppIcon :name="cameraState === 'unsupported' ? 'warning' : 'camera'" size="lg" />
                    </span>

                    <p v-if="cameraError" class="mt-4 text-sm leading-relaxed text-white/85">{{ cameraError }}</p>
                    <p v-else class="mt-4 text-sm leading-relaxed text-white/75">
                        The camera is off. Nothing is scanned until you start it.
                    </p>

                    <AppButton
                        v-if="cameraState !== 'unsupported'"
                        class="mt-5"
                        size="lg"
                        on-dark
                        icon="camera"
                        :loading="cameraState === 'starting'"
                        @click="emit('start')"
                    >
                        {{ cameraState === 'starting' ? 'Starting…' : 'Start camera' }}
                    </AppButton>
                </div>
            </div>

            <!--
                The verdict, over the frame.

                `aria-live="assertive"` because it answers an action the operator
                just took and replaces itself seconds later — a polite region
                would queue behind whatever else is speaking and announce
                somebody who has already walked away.
            -->
            <transition
                enter-active-class="transition duration-150"
                enter-from-class="opacity-0"
                leave-active-class="transition duration-150"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="verdict"
                    class="station-verdict absolute inset-0 flex flex-col justify-center gap-3 overflow-y-auto px-5 py-6 text-white sm:px-7"
                    :class="skin.fill"
                    role="status"
                    aria-live="assertive"
                >
                    <div class="flex items-center gap-2.5">
                        <AppIcon :name="skin.icon" size="lg" class="shrink-0" />
                        <p class="text-sm font-bold tracking-[0.12em] uppercase sm:text-base">{{ skin.title }}</p>
                    </div>

                    <!--
                        The name, at the top of the type scale. It is what the
                        operator checks against the person in front of them.
                    -->
                    <p v-if="verdict.participant" class="text-2xl leading-tight font-bold sm:text-4xl">
                        {{ verdict.participant.name }}
                    </p>

                    <div v-if="verdict.participant" class="text-sm text-white/90 sm:text-base">
                        <p v-if="verdict.participant.organization">{{ verdict.participant.organization }}</p>
                        <p v-if="verdict.participant.position" class="text-white/80">
                            {{ verdict.participant.position }}
                        </p>
                    </div>

                    <!-- What was recorded, in words. Never colour alone. -->
                    <p v-if="verdict.verdict === 'success'" class="text-sm font-semibold sm:text-base">
                        Day {{ verdict.day }} · {{ verdict.status === 'late' ? 'Late' : 'Present' }}
                    </p>
                    <p v-else-if="verdict.verdict === 'duplicate'" class="text-sm sm:text-base">
                        Recorded earlier
                        <template v-if="verdict.existing.time_in"> at {{ verdict.existing.time_in }}</template>
                        ({{ verdict.existing.status_label }}). No second record was made.
                    </p>
                    <p v-else class="text-sm leading-relaxed sm:text-base">{{ verdict.message }}</p>

                    <!--
                        The overrun, stated where the decision was made. The
                        organiser needs it now — chairs, meals and kits are
                        ordered against the cap — and a number that first appears
                        in a report next week is one nobody acted on.
                    -->
                    <p v-if="verdict.overCapacity" class="rounded-lg bg-black/25 px-3 py-1.5 text-sm font-semibold">
                        Over capacity by {{ verdict.overBy }}. Tell the organiser.
                    </p>

                    <p
                        v-if="verdict.participant?.food_restrictions"
                        class="rounded-lg bg-black/20 px-3 py-1.5 text-sm"
                    >
                        Food restrictions: {{ verdict.participant.food_restrictions }}
                    </p>

                    <p v-if="testing" class="rounded-lg bg-black/25 px-3 py-1.5 text-2xs font-semibold">
                        {{ testLabel }} — nothing was saved.
                        <template v-if="verdict.simulatedDay">
                            This training is not running today, so day {{ verdict.day }} was stood in for
                            the rehearsal.
                        </template>
                    </p>

                    <!--
                        Admitting is a second, deliberate tap rather than
                        something the scan does by itself. The station cannot
                        tell a walk-in from the wrong training being loaded —
                        both look like a code that is not on this roster — and
                        only the operator standing there knows which it is.
                    -->
                    <div v-if="verdict.admittable" class="pt-1">
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-danger shadow-sm transition-colors hover:bg-csc-blue-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:opacity-60"
                            :disabled="admitting"
                            @click="emit('admit', verdict.token)"
                        >
                            <AppIcon :name="admitting ? 'clock' : 'plus'" size="sm" />
                            {{ admitting ? 'Admitting…' : 'Admit as walk-in' }}
                        </button>
                    </div>
                </div>
            </transition>
        </div>

        <!--
            Controls. Touch-sized, because this is worked with a thumb —
            each one full-width and stacked rather than a row of small
            buttons crammed onto one line, which is exactly what a phone-width
            screen turned this into.

            Present only while there is something to control. Starting the
            camera belongs to the overlay above, which is where somebody looking
            at a dark rectangle is already looking — a second "Start camera" down
            here was two buttons doing one job, and the quieter of the two was
            the one in the operator's line of sight.
        -->
        <div
            v-if="running || $slots.controls"
            class="flex flex-col gap-2 border-t border-csc-line bg-csc-blue-tint/40 px-4 py-3"
        >
            <AppButton v-if="running" size="sm" variant="ghost" icon="pause" block @click="emit('stop')">
                Pause camera
            </AppButton>

            <AppButton
                v-if="running && hasTorch"
                size="sm"
                variant="ghost"
                icon="flash"
                block
                @click="emit('torch')"
            >
                Light {{ torchOn ? 'off' : 'on' }}
            </AppButton>

            <div v-if="$slots.controls" class="flex flex-col gap-2">
                <slot name="controls" />
            </div>
        </div>
    </section>
</template>
