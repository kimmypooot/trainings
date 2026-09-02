<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

defineProps({
    requests: { type: Array, required: true },
});

const form = useForm({
    title: '',
    justification: '',
    category: '',
    expected_participants: '',
    preferred_start: '',
    preferred_end: '',
});

const submit = () => form.post('/my/training-requests', { onSuccess: () => form.reset() });
</script>

<template>
    <Head title="Request a Training" />

    <AuthenticatedLayout title="Request a Training" current="training-requests">
        <div class="mx-auto max-w-4xl space-y-5">
            <p class="text-sm leading-relaxed text-csc-ink-muted">
                If your agency needs a training that is not in the catalogue, ask CSC to run one. HRD reviews
                every request and will schedule it if there is enough demand.
            </p>

            <AppCard title="New Request">
                <form class="grid gap-5" novalidate @submit.prevent="submit">
                    <AppInput
                        v-model="form.title"
                        label="Training Title"
                        :error="form.errors.title"
                        required
                    />

                    <AppTextarea
                        v-model="form.justification"
                        label="Justification"
                        hint="Explain the need and who it is for — this is what HRD weighs."
                        :rows="5"
                        :error="form.errors.justification"
                        required
                    />

                    <div class="grid gap-5 sm:grid-cols-2">
                        <AppInput
                            v-model="form.category"
                            label="Category"
                            placeholder="Leadership, Technical…"
                            :error="form.errors.category"
                        />
                        <AppInput
                            v-model="form.expected_participants"
                            label="Expected Participants"
                            type="number"
                            :error="form.errors.expected_participants"
                        />
                        <AppInput
                            v-model="form.preferred_start"
                            label="Preferred Start"
                            type="date"
                            :error="form.errors.preferred_start"
                        />
                        <AppInput
                            v-model="form.preferred_end"
                            label="Preferred End"
                            type="date"
                            :error="form.errors.preferred_end"
                        />
                    </div>

                    <div class="flex justify-end">
                        <AppButton type="submit" :loading="form.processing" icon="check">Submit Request</AppButton>
                    </div>
                </form>
            </AppCard>

            <AppCard title="My Requests" :padded="requests.length > 0">
                <AppEmptyState
                    v-if="!requests.length"
                    title="No requests yet"
                    description="Requests you submit appear here with their status."
                    icon="document"
                />

                <ul v-else class="space-y-3">
                    <li v-for="item in requests" :key="item.id" class="rounded-lg border border-csc-line p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-csc-ink">{{ item.title }}</p>
                                <p class="mt-0.5 text-xs text-csc-ink-subtle">Submitted {{ item.submitted_at }}</p>
                            </div>
                            <AppBadge :status="item.status" />
                        </div>

                        <p v-if="item.review_remarks" class="mt-3 text-sm text-csc-ink-muted">
                            CSC remarks: {{ item.review_remarks }}
                        </p>

                        <a
                            v-if="item.training_url"
                            :href="item.training_url"
                            class="mt-3 inline-block rounded text-sm font-medium text-csc-blue underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        >
                            View the scheduled training
                        </a>
                    </li>
                </ul>
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
