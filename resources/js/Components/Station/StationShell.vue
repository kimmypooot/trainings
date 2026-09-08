<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';

/**
 * The station's ground and masthead, shared by both scanning doors.
 *
 * `/admin/scanner` and `/station/{token}` are one job at two doors, and their
 * chrome had been written out twice — the same header, the same status pill,
 * the same translucent panels — in two files that had already begun to drift.
 * This is the move `Components/Roster*.vue` makes for the roster's two
 * layouts: whatever the two stations must agree on lives here once, and each
 * page keeps only what is genuinely its own (a way out, for the staff door; a
 * six-digit gate, for the volunteer's).
 *
 * The masthead carries the seal because of who reads this screen. It is held
 * up to a member of the public at a venue door, badge in hand, and for many
 * participants it is the only part of this system they will ever look at. An
 * unbranded page with a camera in it is not a thing to ask somebody to trust
 * with their name.
 */
defineProps({
    /** The strong line: the event being scanned, or the station itself. */
    title: { type: String, required: true },
    online: { type: Boolean, default: true },
});

const office = computed(() => usePage().props.office ?? {});
</script>

<template>
    <div class="station-ground flex min-h-dvh flex-col text-csc-ink">
        <!--
            `will-change-transform` promotes this header to its own composited
            layer. Without it, mobile browsers have been seen letting the
            camera viewport's own hardware-decoded layer paint over this
            sticky header on scroll — worst right as the address bar
            collapses or returns, which is when the browser rebuilds layers —
            a video's compositing layer answers to its own stacking order,
            not to this element's z-index, so the fix is giving this element
            an explicit one to compete with rather than raising z-header
            further. See StationViewport's matching `isolate` on the video's
            own box.
        -->
        <header class="sticky top-0 z-header will-change-transform border-b border-csc-line bg-white/95 backdrop-blur">
            <div class="station-rule h-[3px]" aria-hidden="true" />

            <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-2.5 sm:gap-4">
                <slot name="lead" />

                <img
                    src="/images/csc-logo-256.png"
                    alt=""
                    aria-hidden="true"
                    class="size-9 shrink-0 object-contain sm:size-10"
                />

                <div class="min-w-0 flex-1">
                    <p class="truncate text-2xs font-semibold tracking-[0.14em] text-csc-ink-subtle uppercase">
                        {{ office.short_name ?? 'Civil Service Commission' }} · Attendance Station
                    </p>
                    <p class="truncate text-sm leading-tight font-semibold text-csc-blue sm:text-base">
                        {{ title }}
                    </p>
                </div>

                <!--
                    Connection, not sync. The two are separate lines on this
                    page on purpose: this one answers "will a download work",
                    the sync card answers "has my morning left this device",
                    and an operator conflating them is how a tablet gets put
                    away with forty arrivals still on it.
                -->
                <span
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-2.5 py-1 text-2xs font-semibold"
                    :class="
                        online
                            ? 'border-success/30 bg-success-soft text-success'
                            : 'border-warning/30 bg-warning-soft text-warning'
                    "
                >
                    <AppIcon :name="online ? 'check' : 'warning'" size="sm" class="shrink-0" />
                    {{ online ? 'Online' : 'Offline' }}
                </span>
            </div>

            <!-- Event context: date, venue, which day of the run. Only ever
                 rendered once a roster is loaded, so it never sits empty. -->
            <div v-if="$slots.meta" class="border-t border-csc-line bg-csc-blue-tint/60">
                <div
                    class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-4 gap-y-1 px-4 py-1.5 text-2xs text-csc-ink-muted"
                >
                    <slot name="meta" />
                </div>
            </div>
        </header>

        <slot name="banner" />

        <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-4 sm:py-6">
            <slot />
        </main>
    </div>
</template>
