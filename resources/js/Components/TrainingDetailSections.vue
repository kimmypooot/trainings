<script setup>
import AppIcon from '@/Components/AppIcon.vue';

/**
 * The long-form half of a training's detail: the prose, not the figures.
 *
 * Shared because it is genuinely the same everywhere — a description reads
 * identically to somebody deciding whether to register and to somebody who
 * already has. The metadata grid above it is not shared, and deliberately: the
 * catalogue has to say how many slots are left and when registration closes,
 * neither of which means anything once you hold a place.
 *
 * Each section renders only if it has something to say, so a training with no
 * prerequisites shows no empty "Prerequisites" heading.
 */
defineProps({
    /** venue_details, description, target_participants, is_supervisory. */
    training: { type: Object, required: true },
});
</script>

<template>
    <div class="space-y-6">
        <div v-if="training.venue_details" class="border-t border-csc-line pt-5">
            <h3 class="text-sm font-semibold text-csc-blue">Venue details</h3>
            <p class="mt-2 text-sm leading-relaxed whitespace-pre-line text-csc-ink/75">
                {{ training.venue_details }}
            </p>
        </div>

        <div v-if="training.description" class="border-t border-csc-line pt-5">
            <h3 class="text-sm font-semibold text-csc-blue">Description</h3>
            <p class="mt-2 text-sm leading-relaxed whitespace-pre-line text-csc-ink/75">
                {{ training.description }}
            </p>
        </div>

        <div v-if="training.target_participants" class="border-t border-csc-line pt-5">
            <h3 class="text-sm font-semibold text-csc-blue">Target participants</h3>
            <p class="mt-2 text-sm leading-relaxed whitespace-pre-line text-csc-ink/75">
                {{ training.target_participants }}
            </p>
        </div>

        <div v-if="training.prerequisites" class="border-t border-csc-line pt-5">
            <h3 class="text-sm font-semibold text-csc-blue">Prerequisites</h3>
            <p class="mt-2 text-sm leading-relaxed whitespace-pre-line text-csc-ink/75">
                {{ training.prerequisites }}
            </p>
        </div>

        <p
            v-if="training.is_supervisory"
            class="flex items-start gap-2 rounded-lg bg-csc-blue-tint p-4 text-sm text-csc-ink/70"
        >
            <AppIcon name="info" size="sm" class="mt-0.5 shrink-0 text-csc-blue" />
            This is a Supervisory Development Course. You will be asked to submit an output before your
            completion is credited.
        </p>
    </div>
</template>
