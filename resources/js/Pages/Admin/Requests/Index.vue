<script setup>
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

const props = defineProps({
    cancellations: { type: Array, required: true },
    trainingRequests: { type: Array, required: true },
    outputs: { type: Array, required: true },
    scopedTo: { type: String, default: null },
});

const page = usePage();
const flash = computed(() => page.props.flash?.success);

const tabs = computed(() => [
    { key: 'cancellations', label: 'Withdrawals', count: pendingCount(props.cancellations) },
    { key: 'trainings', label: 'Training Requests', count: pendingCount(props.trainingRequests) },
    { key: 'outputs', label: 'Outputs', count: pendingCount(props.outputs) },
]);

const active = ref('cancellations');

function pendingCount(items) {
    return items.filter((item) => item.status === 'pending').length;
}

/**
 * A rejection has to carry a reason, so it is the one path that prompts —
 * the same rule the registration roster applies.
 */
const decide = (url, decision) => {
    let remarks = null;

    if (decision === 'rejected') {
        remarks = window.prompt('Reason for declining:');

        if (!remarks) {
            return;
        }
    }

    router.post(url, { decision, remarks }, { preserveScroll: true });
};

const convert = (id) =>
    router.post(`/admin/requests/trainings/${id}/convert`, {}, { preserveScroll: true });
</script>

<template>
    <Head title="Requests" />

    <AuthenticatedLayout title="Requests" current="admin-requests">
        <div class="mx-auto max-w-5xl space-y-5">
            <AppAlert v-if="flash" tone="success">{{ flash }}</AppAlert>

            <AppAlert v-if="scopedTo" tone="info">
                Showing requests from <strong>{{ scopedTo }}</strong> only.
            </AppAlert>

            <div class="flex flex-wrap gap-2" role="tablist">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    role="tab"
                    :aria-selected="active === tab.key"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    :class="
                        active === tab.key
                            ? 'bg-csc-blue text-white'
                            : 'bg-white text-csc-ink/70 ring-1 ring-csc-line hover:text-csc-blue'
                    "
                    @click="active = tab.key"
                >
                    {{ tab.label }}
                    <span
                        v-if="tab.count"
                        class="ml-1.5 rounded-full px-1.5 py-0.5 text-xs font-semibold"
                        :class="active === tab.key ? 'bg-white/20' : 'bg-csc-red text-white'"
                    >
                        {{ tab.count }}
                    </span>
                </button>
            </div>

            <!-- Withdrawals -->
            <AppCard v-if="active === 'cancellations'" title="Withdrawal Requests" :padded="!cancellations.length">
                <AppEmptyState
                    v-if="!cancellations.length"
                    title="No withdrawal requests"
                    description="Participants asking to give up a slot appear here."
                    icon="M6 18L18 6M6 6l12 12"
                />

                <ul v-else class="space-y-3">
                    <li
                        v-for="item in cancellations"
                        :key="item.id"
                        class="rounded-lg border border-csc-line p-4"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-csc-ink">{{ item.participant }}</p>
                                <p class="mt-0.5 text-sm text-csc-ink/60">{{ item.training }}</p>
                            </div>
                            <AppBadge :status="item.status" />
                        </div>

                        <p class="mt-3 text-sm text-csc-ink/80">{{ item.reason }}</p>
                        <p v-if="item.review_remarks" class="mt-1.5 text-xs text-csc-ink/55">
                            Remarks: {{ item.review_remarks }}
                        </p>

                        <div v-if="item.status === 'pending'" class="mt-4 flex flex-wrap gap-2">
                            <AppButton
                                size="sm"
                                @click="decide(`/admin/requests/cancellations/${item.id}`, 'approved')"
                            >
                                Approve &amp; Release Slot
                            </AppButton>
                            <AppButton
                                size="sm"
                                variant="ghost"
                                @click="decide(`/admin/requests/cancellations/${item.id}`, 'rejected')"
                            >
                                Decline
                            </AppButton>
                        </div>
                    </li>
                </ul>
            </AppCard>

            <!-- Training requests -->
            <AppCard v-if="active === 'trainings'" title="Requested Trainings" :padded="!trainingRequests.length">
                <AppEmptyState
                    v-if="!trainingRequests.length"
                    title="No training requests"
                    description="Agencies asking CSC to run a training appear here."
                    icon="M8 3v3M16 3v3M4 9h16M5 6h14a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z"
                />

                <ul v-else class="space-y-3">
                    <li
                        v-for="item in trainingRequests"
                        :key="item.id"
                        class="rounded-lg border border-csc-line p-4"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-csc-ink">{{ item.title }}</p>
                                <p class="mt-0.5 text-sm text-csc-ink/60">
                                    Requested by {{ item.requester ?? '—' }} · {{ item.submitted_at }}
                                </p>
                            </div>
                            <AppBadge :status="item.status" />
                        </div>

                        <p class="mt-3 text-sm text-csc-ink/80">{{ item.justification }}</p>
                        <p class="mt-1.5 text-xs text-csc-ink/55">
                            <template v-if="item.category">{{ item.category }} · </template>
                            <template v-if="item.expected_participants">
                                ~{{ item.expected_participants }} participants ·
                            </template>
                            Preferred {{ item.preferred_start ?? 'any date' }}
                        </p>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <template v-if="item.status === 'pending'">
                                <AppButton
                                    size="sm"
                                    @click="decide(`/admin/requests/trainings/${item.id}`, 'approved')"
                                >
                                    Approve
                                </AppButton>
                                <AppButton
                                    size="sm"
                                    variant="ghost"
                                    @click="decide(`/admin/requests/trainings/${item.id}`, 'rejected')"
                                >
                                    Decline
                                </AppButton>
                            </template>

                            <AppButton
                                v-else-if="item.status === 'approved' && !item.converted"
                                size="sm"
                                @click="convert(item.id)"
                            >
                                Create Draft Training
                            </AppButton>

                            <p v-else-if="item.converted" class="text-xs text-csc-ink/55">
                                A draft training has been created from this request.
                            </p>
                        </div>
                    </li>
                </ul>
            </AppCard>

            <!-- Outputs -->
            <AppCard v-if="active === 'outputs'" title="Submitted Outputs" :padded="!outputs.length">
                <AppEmptyState
                    v-if="!outputs.length"
                    title="No outputs submitted"
                    description="Post-training deliverables appear here for review."
                    icon="M12 16V4m0 0L8 8m4-4 4 4M4 20h16"
                />

                <ul v-else class="space-y-3">
                    <li v-for="item in outputs" :key="item.id" class="rounded-lg border border-csc-line p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-csc-ink">{{ item.title }}</p>
                                <p class="mt-0.5 text-sm text-csc-ink/60">
                                    {{ item.participant }} · {{ item.training }}
                                </p>
                            </div>
                            <AppBadge :status="item.status" />
                        </div>

                        <p v-if="item.description" class="mt-3 text-sm text-csc-ink/80">
                            {{ item.description }}
                        </p>

                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <a
                                :href="item.download_url"
                                class="rounded text-sm font-medium text-csc-blue underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            >
                                {{ item.filename }}
                            </a>
                            <span class="text-xs text-csc-ink/55">{{ item.size }}</span>
                        </div>

                        <div v-if="item.status === 'pending'" class="mt-4 flex flex-wrap gap-2">
                            <AppButton
                                size="sm"
                                @click="decide(`/admin/requests/outputs/${item.id}`, 'approved')"
                            >
                                Accept
                            </AppButton>
                            <AppButton
                                size="sm"
                                variant="ghost"
                                @click="decide(`/admin/requests/outputs/${item.id}`, 'rejected')"
                            >
                                Return for Revision
                            </AppButton>
                        </div>
                    </li>
                </ul>
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
