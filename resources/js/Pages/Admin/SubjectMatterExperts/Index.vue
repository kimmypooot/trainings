<script setup>
/**
 * The directory of subject matter experts.
 *
 * Mirrors the field-office index deliberately: same shape, same
 * deactivate-rather-than-delete affordance, so staff who know one screen know
 * this one. The rating column is what this list has that the other does not —
 * it is the reason to open the page before staffing a run.
 */
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

defineProps({
    experts: { type: Array, required: true },
});

const confirming = ref(null);
const processing = ref(false);

const dialog = computed(() => {
    if (!confirming.value) return null;

    const expert = confirming.value;

    if (expert.is_active) {
        return {
            title: `Deactivate ${expert.name}?`,
            description: `They stay on the ${expert.trainings} training${expert.trainings === 1 ? '' : 's'} already assigned to them, and their ${expert.responses} evaluation${expert.responses === 1 ? '' : 's'} are kept. They just cannot be added to a new run.`,
            confirmLabel: 'Deactivate',
        };
    }

    return {
        title: `Reactivate ${expert.name}?`,
        description: 'They can be assigned to trainings again.',
        confirmLabel: 'Reactivate',
    };
});

const confirm = () => {
    processing.value = true;
    router.post(
        `/admin/smes/${confirming.value.id}/toggle`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
                confirming.value = null;
            },
        }
    );
};

// A one-decimal mean reads as a rating; two decimals read as a measurement the
// sample size does not support.
const rating = (expert) => (expert.average === null ? '—' : expert.average.toFixed(1));
</script>

<template>
    <Head title="Subject Matter Experts" />

    <AuthenticatedLayout title="Subject Matter Experts" current="admin-smes">
        <div class="mx-auto max-w-7xl space-y-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm leading-relaxed text-csc-ink-muted">
                    Resource persons who deliver training sessions. Assign them on a training's form;
                    participants rate them at the end of each training day. Deactivate rather than
                    delete — evaluations point at these records.
                </p>
                <AppButton href="/admin/smes/create" icon="plus">New Expert</AppButton>
            </div>

            <AppCard v-if="!experts.length" :padded="false">
                <AppEmptyState
                    icon="users"
                    title="No experts yet"
                    description="Add the resource persons your trainings are delivered by."
                >
                    <template #action>
                        <AppButton href="/admin/smes/create" icon="plus">New Expert</AppButton>
                    </template>
                </AppEmptyState>
            </AppCard>

            <template v-else>
                <div class="hidden overflow-hidden rounded-xl border border-csc-line bg-white md:block">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Expert</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Expertise</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Trainings</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Rating</th>
                                <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink-muted">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-csc-line">
                            <tr
                                v-for="expert in experts"
                                :key="expert.id"
                                :class="expert.is_active ? '' : 'opacity-60'"
                            >
                                <td class="px-5 py-3.5">
                                    <Link
                                        :href="expert.view_url"
                                        class="font-medium text-csc-blue hover:underline"
                                    >
                                        {{ expert.name }}
                                    </Link>
                                    <p class="mt-0.5 text-xs text-csc-ink-subtle">
                                        {{ expert.position || '—' }}
                                        <span v-if="!expert.is_active" class="ml-1 font-semibold text-danger">
                                            · Inactive
                                        </span>
                                    </p>
                                    <p v-if="expert.email" class="mt-0.5 text-xs text-csc-ink-subtle">
                                        {{ expert.email }}
                                    </p>
                                </td>
                                <td class="max-w-xs px-5 py-3.5 text-xs text-csc-ink-muted">
                                    {{ expert.expertise || '—' }}
                                </td>
                                <td class="px-5 py-3.5 text-csc-ink-muted">{{ expert.trainings }}</td>
                                <td class="px-5 py-3.5">
                                    <span class="font-semibold text-csc-ink">{{ rating(expert) }}</span>
                                    <span class="ml-1 text-xs text-csc-ink-subtle">
                                        ({{ expert.responses }})
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                    <Link
                                        :href="expert.edit_url"
                                        class="text-xs font-semibold text-csc-blue hover:underline"
                                    >
                                        Edit
                                    </Link>
                                    <button
                                        type="button"
                                        class="ml-3 text-xs font-semibold text-csc-ink-muted hover:underline"
                                        @click="confirming = expert"
                                    >
                                        {{ expert.is_active ? 'Deactivate' : 'Reactivate' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Card list for narrow screens; the table above is hidden there. -->
                <div class="grid gap-3 md:hidden">
                    <AppCard
                        v-for="expert in experts"
                        :key="expert.id"
                        :class="expert.is_active ? '' : 'opacity-60'"
                    >
                        <Link :href="expert.view_url" class="font-medium text-csc-blue hover:underline">
                            {{ expert.name }}
                        </Link>
                        <p class="mt-0.5 text-xs text-csc-ink-subtle">
                            {{ expert.position || '—' }}
                            <span v-if="!expert.is_active" class="font-semibold text-danger">· Inactive</span>
                        </p>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <div>
                                <dt class="text-xs text-csc-ink-subtle">Trainings</dt>
                                <dd class="text-csc-ink">{{ expert.trainings }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-csc-ink-subtle">Rating</dt>
                                <dd class="text-csc-ink">
                                    {{ rating(expert) }}
                                    <span class="text-xs text-csc-ink-subtle">({{ expert.responses }})</span>
                                </dd>
                            </div>
                        </dl>
                        <div class="mt-3 flex gap-3">
                            <Link
                                :href="expert.edit_url"
                                class="text-xs font-semibold text-csc-blue hover:underline"
                            >
                                Edit
                            </Link>
                            <button
                                type="button"
                                class="text-xs font-semibold text-csc-ink-muted hover:underline"
                                @click="confirming = expert"
                            >
                                {{ expert.is_active ? 'Deactivate' : 'Reactivate' }}
                            </button>
                        </div>
                    </AppCard>
                </div>
            </template>
        </div>

        <AppConfirmModal
            :open="Boolean(confirming)"
            :title="dialog?.title ?? ''"
            :description="dialog?.description"
            :confirm-label="dialog?.confirmLabel"
            :processing="processing"
            @confirm="confirm"
            @close="confirming = null"
        />
    </AuthenticatedLayout>
</template>
