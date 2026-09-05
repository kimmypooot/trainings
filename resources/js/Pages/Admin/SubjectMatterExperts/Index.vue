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
import AppBarList from '@/Components/AppBarList.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppRowActions from '@/Components/AppRowActions.vue';
import AppStatTile from '@/Components/AppStatTile.vue';

const props = defineProps({
    experts: { type: Array, required: true },
});

/*
 * Derived from `experts`, which the page already holds in full — this list is
 * not paginated, so no query is added. The average is weighted by how many
 * responses each expert has: a mean of the per-expert means lets somebody with
 * a single five-star form pull the region's figure as hard as somebody with
 * ninety, which is the classic way a rating summary ends up wrong.
 */
const summary = computed(() => {
    const rated = props.experts.filter((expert) => expert.responses > 0);
    const responses = rated.reduce((total, expert) => total + expert.responses, 0);
    const weighted = rated.reduce((total, expert) => total + expert.average * expert.responses, 0);

    return {
        experts: props.experts.length,
        active: props.experts.filter((expert) => expert.is_active).length,
        responses,
        average: responses > 0 ? weighted / responses : null,
    };
});

/*
 * Who is actually being asked to deliver, largest first.
 *
 * Assignments rather than ratings: a rating chart would rank people by a
 * number several of them have too few responses to earn, and the table already
 * carries the rating beside its response count where that caveat is visible.
 */
const byTrainings = computed(() =>
    [...props.experts]
        .filter((expert) => expert.trainings > 0)
        .map((expert) => ({ label: expert.name, count: expert.trainings }))
        .sort((a, b) => b.count - a.count)
);

const confirming = ref(null);
const processing = ref(false);

/*
 * The same three the field-office index offers, in the same order — this page
 * mirrors that one deliberately, and View was the one it was missing.
 */
const actionsFor = (expert) => [
    { label: 'View', icon: 'eye', href: expert.view_url },
    { label: 'Edit', icon: 'pencil', href: expert.edit_url },
    expert.is_active
        ? { label: 'Deactivate', icon: 'lock', tone: 'danger', onClick: () => (confirming.value = expert) }
        : { label: 'Reactivate', icon: 'check', tone: 'success', onClick: () => (confirming.value = expert) },
];

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
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <AppStatTile label="Experts" :value="summary.experts" icon="users" />
                    <AppStatTile
                        label="Available"
                        :value="summary.active"
                        icon="check-circle"
                        tone="success"
                        :caption="
                            summary.experts - summary.active > 0
                                ? `${summary.experts - summary.active} deactivated`
                                : 'All can be assigned'
                        "
                    />
                    <AppStatTile label="Evaluations" :value="summary.responses" icon="clipboard" />
                    <!--
                        Tone follows the same bands the expert's own page uses,
                        so a rating does not mean one thing here and another
                        one click away.
                    -->
                    <AppStatTile
                        label="Average Rating"
                        :value="summary.average === null ? '—' : summary.average.toFixed(1)"
                        icon="analytics"
                        :tone="
                            summary.average === null
                                ? 'brand'
                                : summary.average >= 4
                                  ? 'success'
                                  : summary.average >= 3
                                    ? 'warning'
                                    : 'danger'
                        "
                        :caption="summary.average === null ? 'No responses yet' : 'Weighted across all responses'"
                    />
                </div>

                <AppCard
                    v-if="byTrainings.length"
                    title="Assignments per Expert"
                    subtitle="How the delivery load is spread. Ratings stay in the table, where each one sits beside the number of responses it rests on."
                    collapsible
                    remember-as="smes-assignments"
                >
                    <AppBarList :rows="byTrainings" label-width="14rem" :limit="10" />
                </AppCard>

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
                                    <AppRowActions :actions="actionsFor(expert)" />
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
                        <div class="mt-3">
                            <AppRowActions :actions="actionsFor(expert)" layout="card" />
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
