<script setup>
import { computed } from 'vue';
import AppModal from '@/Components/AppModal.vue';
import AppButton from '@/Components/AppButton.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppIcon from '@/Components/AppIcon.vue';
import ProgramStatusPill from '@/Components/ProgramStatusPill.vue';
import { spansMultipleDays } from '@/dateRange';

/**
 * The full catalogue view of one program, for anonymous visitors.
 *
 * Shared by the landing page and /programs. `program` is null when closed —
 * the parent owns the selection and clears it on @close.
 */
const props = defineProps({
    program: { type: Object, default: null },
});

defineEmits(['close']);

const subtitle = computed(() =>
    props.program ? `${props.program.mode} · Starts ${props.program.starts_at}` : ''
);

const slotsDetail = computed(() => {
    if (! props.program) return '';

    return props.program.capacity === null
        ? 'No limit'
        : `${props.program.slots_remaining} of ${props.program.capacity} remaining`;
});

/*
 * The call to action follows the status. Sending someone to a sign-in page for
 * a run they cannot join yet — or at all — is the kind of dead end an
 * always-on "Sign in to register" button creates.
 */
const callToAction = computed(() => {
    if (! props.program) return { label: '', enabled: false };

    if (props.program.is_registrable) {
        return { label: 'Sign in to register', enabled: true };
    }

    return {
        label: {
            opening: `Registration opens ${props.program.registration_opens_at}`,
            full: 'This program is fully booked',
            closed: 'Registration has closed',
            ongoing: 'This program is already under way',
        }[props.program.status] ?? 'Registration is not open',
        enabled: false,
    };
});

const money = (value) =>
    Number(value).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
</script>

<template>
    <AppModal
        :open="program !== null"
        :title="program?.title"
        :subtitle="subtitle"
        size="lg"
        @close="$emit('close')"
    >
        <template v-if="program">
            <!-- Same pill as the card, so the modal opens on the answer. -->
            <div class="mb-6 flex flex-wrap items-center gap-2">
                <ProgramStatusPill :status="program.status" :label="program.status_label" />
                <AppBadge v-if="program.is_supervisory" status="supervisory" />
            </div>

            <dl class="grid gap-x-6 gap-y-5 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-csc-ink-subtle">Date</dt>
                    <dd class="mt-0.5 font-medium text-csc-ink">
                        {{ program.starts_at }}
                        <!-- A single-day run stores the same date twice; "Oct 4 – Oct 4" is noise. -->
                        <template v-if="spansMultipleDays(program.starts_at, program.ends_at)">
                            <span class="text-csc-ink-subtle">– {{ program.ends_at }}</span>
                        </template>
                    </dd>
                </div>
                <div>
                    <dt class="text-csc-ink-subtle">Venue</dt>
                    <dd class="mt-0.5 font-medium text-csc-ink">{{ program.venue }}</dd>
                </div>
                <div>
                    <dt class="text-csc-ink-subtle">Mode</dt>
                    <dd class="mt-0.5 font-medium text-csc-ink">{{ program.mode }}</dd>
                </div>
                <div>
                    <dt class="text-csc-ink-subtle">Fee</dt>
                    <dd class="mt-0.5 font-medium" :class="program.payment_required ? 'text-csc-ink' : 'text-success'">
                        {{ program.payment_required ? `₱${money(program.payment_amount)}` : 'Free of charge' }}
                    </dd>
                </div>
                <div v-if="program.category">
                    <dt class="text-csc-ink-subtle">Curriculum</dt>
                    <dd class="mt-0.5 font-medium text-csc-ink">{{ program.category }}</dd>
                </div>
                <div>
                    <dt class="text-csc-ink-subtle">Available slots</dt>
                    <dd class="mt-0.5 font-medium text-csc-ink">{{ slotsDetail }}</dd>
                </div>
                <div v-if="program.duration_days">
                    <dt class="text-csc-ink-subtle">Duration</dt>
                    <dd class="mt-0.5 font-medium text-csc-ink">
                        {{ program.duration_days }} day{{ program.duration_days === 1 ? '' : 's' }}
                    </dd>
                </div>
                <div v-if="program.level_label">
                    <dt class="text-csc-ink-subtle">Level</dt>
                    <dd class="mt-0.5 font-medium text-csc-ink">{{ program.level_label }}</dd>
                </div>
                <div v-if="program.registration_opens_at">
                    <dt class="text-csc-ink-subtle">Registration opens</dt>
                    <dd class="mt-0.5 font-medium text-csc-ink">{{ program.registration_opens_at }}</dd>
                </div>
                <div v-if="program.registration_closes_at">
                    <dt class="text-csc-ink-subtle">Registration closes</dt>
                    <dd class="mt-0.5 font-medium text-csc-ink">{{ program.registration_closes_at }}</dd>
                </div>
                <div v-if="program.venue_details" class="sm:col-span-2">
                    <dt class="text-csc-ink-subtle">Venue details</dt>
                    <dd class="mt-0.5 leading-relaxed whitespace-pre-line text-csc-ink-muted">
                        {{ program.venue_details }}
                    </dd>
                </div>
            </dl>

            <div v-if="program.description" class="mt-6 border-t border-csc-line pt-5">
                <h3 class="text-sm font-semibold text-csc-blue">Description</h3>
                <p class="mt-2 text-sm leading-relaxed whitespace-pre-line text-csc-ink-muted">
                    {{ program.description }}
                </p>
            </div>

            <div v-if="program.target_participants" class="mt-6 border-t border-csc-line pt-5">
                <h3 class="text-sm font-semibold text-csc-blue">Target participants</h3>
                <p class="mt-2 text-sm leading-relaxed whitespace-pre-line text-csc-ink-muted">
                    {{ program.target_participants }}
                </p>
            </div>

            <div v-if="program.prerequisites" class="mt-6 border-t border-csc-line pt-5">
                <h3 class="text-sm font-semibold text-csc-blue">Prerequisites</h3>
                <p class="mt-2 text-sm leading-relaxed whitespace-pre-line text-csc-ink-muted">
                    {{ program.prerequisites }}
                </p>
            </div>

            <p
                v-if="program.is_supervisory"
                class="mt-6 flex items-start gap-2 rounded-lg bg-csc-blue-tint p-4 text-sm text-csc-ink-muted"
            >
                <AppIcon name="info" size="sm" class="mt-0.5 shrink-0 text-csc-blue" />
                This is a Supervisory Development Course. You will be asked to submit an output before
                your completion is credited.
            </p>
        </template>

        <template #footer>
            <div v-if="program" class="w-full">
                <AppButton v-if="callToAction.enabled" href="/login" size="lg" block>
                    {{ callToAction.label }}
                </AppButton>
                <!--
                    Not a disabled button: there is nothing to enable later, so a
                    real explanation reads better than a dead control — and it
                    keeps the reason on screen for a screen reader.
                -->
                <p
                    v-else
                    class="rounded-lg bg-csc-blue-tint px-4 py-3 text-center text-sm font-medium text-csc-ink-muted"
                >
                    {{ callToAction.label }}
                </p>
            </div>
        </template>
    </AppModal>
</template>
