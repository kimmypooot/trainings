<script setup>
import AppButton from '@/Components/AppButton.vue';
import { toneDots } from '@/scanner/station';

/**
 * Where the morning's scans currently are.
 *
 * Always on screen, never behind a toggle: an operator must never have to
 * wonder whether the last hour of scanning has left the tablet. This is the
 * only thing on the page that reports the queue, and a device put away with
 * forty arrivals still on it is the one failure this station cannot recover
 * from by itself.
 *
 * Separate from the connection chip in the masthead on purpose. That one
 * answers "would a download work"; this one answers "has my work left this
 * device", and they are different questions with different remedies — being
 * online with a failed batch is a worse position than being offline with a
 * clean queue, and one indicator cannot say both.
 */
defineProps({
    label: { type: String, required: true },
    tone: { type: String, required: true }, // success | warning | danger | info
    state: { type: String, required: true }, // idle | syncing | error
    message: { type: String, default: null },
    lastSyncedAt: { type: Date, default: null },
    syncedCount: { type: Number, default: 0 },
    pendingCount: { type: Number, default: 0 },
    failedCount: { type: Number, default: 0 },
});

const emit = defineEmits(['sync', 'retry']);
</script>

<template>
    <section class="rounded-2xl border border-csc-line bg-white p-4 shadow-sm">
        <div class="flex items-center gap-3">
            <span class="size-2.5 shrink-0 rounded-full" :class="toneDots[tone]" aria-hidden="true" />

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-csc-ink">{{ label }}</p>
                <p v-if="lastSyncedAt" class="truncate text-2xs text-csc-ink-subtle">
                    Last sync {{ lastSyncedAt.toLocaleTimeString() }} · {{ syncedCount }} sent in total
                </p>
                <p v-else class="truncate text-2xs text-csc-ink-subtle">
                    Scans are held on this device until they are sent.
                </p>
            </div>

            <div class="flex shrink-0 gap-2">
                <AppButton v-if="failedCount" size="sm" variant="ghost" icon="refresh" @click="emit('retry')">
                    Retry
                </AppButton>
                <AppButton
                    size="sm"
                    icon="upload"
                    :loading="state === 'syncing'"
                    @click="emit('sync')"
                >
                    {{ state === 'syncing' ? 'Syncing…' : 'Sync now' }}
                </AppButton>
            </div>
        </div>

        <p
            v-if="message"
            class="mt-3 rounded-lg px-3 py-2 text-2xs leading-relaxed"
            :class="state === 'error' ? 'bg-danger-soft text-danger' : 'bg-csc-blue-tint text-csc-ink-muted'"
        >
            {{ message }}
        </p>

        <p v-if="pendingCount" class="mt-3 text-2xs leading-relaxed text-csc-ink-subtle">
            {{ pendingCount }} scan<span v-if="pendingCount !== 1">s</span> still to send. They are safe
            here — keep this page open and they go out as soon as there is a signal.
        </p>

        <div v-if="$slots.default" class="mt-4 flex flex-wrap gap-2 border-t border-csc-line pt-3">
            <slot />
        </div>
    </section>
</template>
