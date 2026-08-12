<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    trainings: { type: Array, default: () => [] },
    audiences: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash?.success);

const form = useForm({
    training_id: props.trainings[0]?.value ?? '',
    subject: '',
    message: '',
    statuses: ['approved'],
});

const submit = () =>
    form.post('/admin/emails', {
        preserveScroll: true,
        onSuccess: () => form.reset('subject', 'message'),
    });
</script>

<template>
    <Head title="Emails" />

    <AuthenticatedLayout title="Emails" current="admin-emails">
        <div class="mx-auto max-w-5xl space-y-5">
            <AppAlert v-if="flash" tone="success">{{ flash }}</AppAlert>

            <AppCard
                title="Send an Announcement"
                subtitle="Goes to the selected participants by email and as an in-app notification."
            >
                <form class="grid gap-5" novalidate @submit.prevent="submit">
                    <div>
                        <label for="training" class="mb-1.5 block text-sm font-medium text-csc-ink">
                            Training <span class="text-csc-red-ink" aria-hidden="true">*</span>
                        </label>
                        <select
                            id="training"
                            v-model="form.training_id"
                            class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                        >
                            <option v-for="option in trainings" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                        <p v-if="form.errors.training_id" class="mt-1.5 text-xs font-medium text-csc-red-ink">
                            {{ form.errors.training_id }}
                        </p>
                    </div>

                    <fieldset>
                        <legend class="mb-1.5 text-sm font-medium text-csc-ink">Send to</legend>
                        <div class="flex flex-wrap gap-x-5 gap-y-2">
                            <label
                                v-for="audience in audiences"
                                :key="audience.value"
                                class="flex items-center gap-2 text-sm text-csc-ink"
                            >
                                <input
                                    v-model="form.statuses"
                                    type="checkbox"
                                    :value="audience.value"
                                    class="size-4 rounded border-csc-line text-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                                />
                                {{ audience.label }}
                            </label>
                        </div>
                        <p v-if="form.errors.statuses" class="mt-1.5 text-xs font-medium text-csc-red-ink">
                            {{ form.errors.statuses }}
                        </p>
                    </fieldset>

                    <AppInput
                        v-model="form.subject"
                        label="Subject"
                        :error="form.errors.subject"
                        required
                    />

                    <AppTextarea
                        v-model="form.message"
                        label="Message"
                        rows="6"
                        :error="form.errors.message"
                        required
                    />

                    <div class="flex justify-end">
                        <AppButton type="submit" :loading="form.processing">Queue Announcement</AppButton>
                    </div>
                </form>
            </AppCard>

            <AppCard title="Sent Mail" :padded="!logs.data.length">
                <AppEmptyState
                    v-if="!logs.data.length"
                    title="Nothing sent yet"
                    description="Outbound mail is recorded here automatically."
                    icon="M3 7l9 6 9-6M3 7v10h18V7M3 7l9-4 9 4"
                />

                <div v-else class="-mx-5 overflow-x-auto sm:-mx-6">
                    <table class="w-full min-w-160 text-left text-sm">
                        <thead class="border-y border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Recipient</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Subject</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Status</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Sent</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-csc-line">
                            <tr v-for="log in logs.data" :key="log.id">
                                <td class="px-5 py-3.5">
                                    <p class="font-medium text-csc-ink">{{ log.recipient_name ?? '—' }}</p>
                                    <p class="mt-0.5 text-xs break-words text-csc-ink/60">
                                        {{ log.recipient_email }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-csc-ink/75">{{ log.subject }}</td>
                                <td class="px-5 py-3.5 text-xs text-csc-ink/70">{{ log.status }}</td>
                                <td class="px-5 py-3.5 text-xs whitespace-nowrap text-csc-ink/70">
                                    {{ log.sent_at ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
