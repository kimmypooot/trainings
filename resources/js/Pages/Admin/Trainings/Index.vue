<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppPagination from '@/Components/AppPagination.vue';

const props = defineProps({
    trainings: { type: Object, required: true },
    filters: { type: Object, required: true },
    tabs: { type: Array, required: true },
});

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');

let debounce;
watch([search, status], () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            '/admin/trainings',
            {
                search: search.value || undefined,
                status: status.value || undefined,
                // A filter change starts from the first page; staying on, say,
                // page 4 of a narrowed search reads as "nothing found".
                page: 1,
            },
            { preserveState: true, replace: true }
        );
    }, 300);
});

const tones = {
    draft: 'bg-csc-blue-tint text-csc-ink',
    published: 'bg-success-soft text-success',
    closed: 'bg-warning-soft text-warning',
    completed: 'bg-info-soft text-info',
    cancelled: 'bg-danger-soft text-danger',
};

// Totals for the table footer. The per-training "registered" figure is the
// sum of its paid + promissory + pending buckets, so summing again across
// rows never double-counts; free and cancelled are counted apart.
const totals = computed(() => {
    const sum = (key) => props.trainings.data.reduce((acc, training) => acc + (training[key] ?? 0), 0);

    return {
        registered: sum('registered'),
        paid: sum('paid'),
        pending: sum('pending'),
        promissory: sum('promissory'),
        free: sum('free'),
        cancelled: sum('cancelled'),
    };
});
</script>

<template>
    <Head title="Manage Trainings" />

    <AuthenticatedLayout title="Manage Trainings" current="admin-trainings">
        <div class="mx-auto max-w-7xl space-y-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search by title…"
                    aria-label="Search trainings"
                    class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue sm:max-w-xs"
                />

                <AppButton href="/admin/trainings/create" icon="plus">New Training</AppButton>
            </div>

            <div class="flex flex-wrap gap-2" role="tablist" aria-label="Filter trainings by status">
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    role="tab"
                    :aria-selected="status === tab.value"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    :class="
                        status === tab.value
                            ? 'bg-csc-blue text-white'
                            : 'bg-white text-csc-ink/70 ring-1 ring-csc-line hover:text-csc-blue'
                    "
                    @click="status = tab.value"
                >
                    {{ tab.label }}
                    <span
                        v-if="tab.count"
                        class="ml-1.5 rounded-full px-1.5 py-0.5 text-xs font-semibold"
                        :class="status === tab.value ? 'bg-white/20' : 'bg-csc-red text-white'"
                    >
                        {{ tab.count }}
                    </span>
                </button>
            </div>

            <AppCard v-if="!trainings.data.length" :padded="false">
                <AppEmptyState
                    title="No trainings found"
                    description="Create one, or clear the filters if you were searching."
                    icon="calendar"
                >
                    <template #action>
                        <AppButton href="/admin/trainings/create" icon="plus">Create Training</AppButton>
                    </template>
                </AppEmptyState>
            </AppCard>

            <template v-else>
                <!-- Table on wide screens; the same rows stack into cards below md -->
                <div class="hidden overflow-hidden rounded-xl border border-csc-line bg-white md:block">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                            <tr>
                                <th scope="col" rowspan="2" class="px-5 py-3 font-semibold text-csc-ink/70">Training</th>
                                <th scope="col" rowspan="2" class="px-5 py-3 font-semibold text-csc-ink/70">Schedule</th>
                                <th scope="colgroup" colspan="6" class="border-b border-csc-line px-5 py-3 text-center font-semibold text-csc-ink/70">
                                    Breakdown of Participants
                                </th>
                                <th scope="col" rowspan="2" class="px-5 py-3 text-right font-semibold text-csc-ink/70">Actions</th>
                            </tr>
                            <tr>
                                <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink/70">Total</th>
                                <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink/70">Paid</th>
                                <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink/70">Pending</th>
                                <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink/70">Promissory</th>
                                <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink/70">Free</th>
                                <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink/70">Cancelled</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-csc-line">
                            <tr v-for="training in trainings.data" :key="training.id">
                                <td class="px-5 py-3.5">
                                    <p class="font-medium text-csc-ink">{{ training.title }}</p>
                                    <p class="mt-0.5 text-xs text-csc-ink/60">{{ training.venue }}</p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <p class="text-[11px] leading-snug text-csc-ink/75">
                                        {{ training.starts_at }}<template v-if="training.ends_at && training.ends_at !== training.starts_at"> –</template>
                                    </p>
                                    <p v-if="training.ends_at && training.ends_at !== training.starts_at" class="text-[11px] leading-snug text-csc-ink/75">
                                        {{ training.ends_at }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-right font-medium text-csc-ink">
                                    {{ training.registered
                                    }}<template v-if="training.capacity"> / {{ training.capacity }}</template>
                                </td>
                                <td class="px-5 py-3.5 text-right tabular-nums text-csc-ink/75">{{ training.paid }}</td>
                                <td class="px-5 py-3.5 text-right tabular-nums text-csc-ink/75">{{ training.pending }}</td>
                                <td class="px-5 py-3.5 text-right tabular-nums text-csc-ink/75">{{ training.promissory }}</td>
                                <td class="px-5 py-3.5 text-right tabular-nums text-csc-ink/75">{{ training.free }}</td>
                                <td class="px-5 py-3.5 text-right tabular-nums text-csc-ink/75">{{ training.cancelled }}</td>
                                <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                    <Link :href="training.roster_url" class="text-xs font-semibold text-csc-blue hover:underline">
                                        Roster
                                    </Link>
                                    <span class="px-2 text-csc-line">|</span>
                                    <Link :href="training.edit_url" class="text-xs font-semibold text-csc-blue hover:underline">
                                        Edit
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                        <!-- The footer spans the label columns, then one cell per
                             payment bucket so a glance at the page gives the
                             regional totals without opening any roster. -->
                        <tfoot class="border-t border-csc-line bg-csc-blue-tint/60">
                            <tr>
                                <td colspan="2" class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-csc-ink/70">
                                    Totals
                                </td>
                                <td class="px-5 py-3 text-right font-semibold text-csc-ink">{{ totals.registered }}</td>
                                <td class="px-5 py-3 text-right tabular-nums font-semibold text-csc-ink">{{ totals.paid }}</td>
                                <td class="px-5 py-3 text-right tabular-nums font-semibold text-csc-ink">{{ totals.pending }}</td>
                                <td class="px-5 py-3 text-right tabular-nums font-semibold text-csc-ink">{{ totals.promissory }}</td>
                                <td class="px-5 py-3 text-right tabular-nums font-semibold text-csc-ink">{{ totals.free }}</td>
                                <td class="px-5 py-3 text-right tabular-nums font-semibold text-csc-ink">{{ totals.cancelled }}</td>
                                <td class="px-5 py-3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <ul class="space-y-3 md:hidden">
                    <li
                        v-for="training in trainings.data"
                        :key="training.id"
                        class="rounded-xl border border-csc-line bg-white p-4"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-csc-ink">{{ training.title }}</p>
                                <p class="mt-0.5 text-[11px] leading-snug text-csc-ink/60">
                                    {{ training.starts_at }}<template v-if="training.ends_at && training.ends_at !== training.starts_at"> –</template>
                                </p>
                                <p v-if="training.ends_at && training.ends_at !== training.starts_at" class="text-[11px] leading-snug text-csc-ink/60">
                                    {{ training.ends_at }}
                                </p>
                                <p class="text-xs text-csc-ink/60">{{ training.venue }}</p>
                            </div>
                            <span
                                class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold"
                                :class="tones[training.status]"
                            >
                                {{ training.status_label }}
                            </span>
                        </div>
                        <dl class="mt-3 grid grid-cols-3 gap-x-3 gap-y-1.5 border-t border-csc-line pt-3 text-xs">
                            <div class="flex items-center justify-between gap-2">
                                <dt class="text-csc-ink/55">Registered</dt>
                                <dd class="font-semibold text-csc-ink">{{ training.registered }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <dt class="text-csc-ink/55">Paid</dt>
                                <dd class="tabular-nums text-csc-ink/75">{{ training.paid }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <dt class="text-csc-ink/55">Pending</dt>
                                <dd class="tabular-nums text-csc-ink/75">{{ training.pending }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <dt class="text-csc-ink/55">Promissory</dt>
                                <dd class="tabular-nums text-csc-ink/75">{{ training.promissory }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <dt class="text-csc-ink/55">Free</dt>
                                <dd class="tabular-nums text-csc-ink/75">{{ training.free }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <dt class="text-csc-ink/55">Cancelled</dt>
                                <dd class="tabular-nums text-csc-ink/75">{{ training.cancelled }}</dd>
                            </div>
                        </dl>
                        <div class="mt-3 flex items-center justify-between border-t border-csc-line pt-3">
                            <span class="flex gap-3">
                                <Link :href="training.roster_url" class="text-xs font-semibold text-csc-blue">Roster</Link>
                                <Link :href="training.edit_url" class="text-xs font-semibold text-csc-blue">Edit</Link>
                            </span>
                        </div>
                    </li>
                </ul>

                <AppPagination :pagination="trainings" label="trainings" class="pt-2" />
            </template>
        </div>
    </AuthenticatedLayout>
</template>
