<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppPagination from '@/Components/AppPagination.vue';
import AppStat from '@/Components/AppStat.vue';

const props = defineProps({
    participants: { type: Object, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    stats: { type: Object, required: true },
    exportUrl: { type: String, required: true },
    can: { type: Object, required: true },
    scopedTo: { type: String, default: null },
});

const page = usePage();
const error = computed(() => page.props.errors?.participant);

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const verified = ref(props.filters.verified ?? '');
const sector = ref(props.filters.sector ?? '');
const region = ref(props.filters.region ?? '');

let debounce;
watch([search, status, verified, sector, region], () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            '/admin/participants',
            {
                search: search.value || undefined,
                status: status.value || undefined,
                verified: verified.value || undefined,
                sector: sector.value || undefined,
                region: region.value || undefined,
                // Filtering from the middle of a paged result must not land on
                // page N of the narrowed set — reset to the first page.
                page: 1,
            },
            { preserveState: true, replace: true }
        );
    }, 300);
});

/*
 * The two account actions confirm before they fire, as v1's SweetAlert prompts
 * did. Both are reversible, but both are felt by someone else — one locks a
 * participant out, the other puts an unexpected email in their inbox — so
 * neither should happen on a mis-click in a dense table.
 */
const confirming = ref(null);
const processing = ref(false);

const ask = (action, participant) => {
    confirming.value = { action, participant };
};

const dialog = computed(() => {
    if (!confirming.value) return null;

    const { action, participant } = confirming.value;
    const who = participant.name ?? participant.email;

    if (action === 'reset') {
        return {
            title: 'Send a password reset link?',
            description: `${who} will receive a single-use link at ${participant.email}. Their current password keeps working until they use it.`,
            confirmLabel: 'Send reset link',
        };
    }

    return participant.is_active
        ? {
              title: `Deactivate ${who}?`,
              description:
                  'They will not be able to sign in. Existing registrations and certificates are left as they are.',
              confirmLabel: 'Deactivate',
          }
        : {
              title: `Activate ${who}?`,
              description: 'They will be able to sign in again.',
              confirmLabel: 'Activate',
          };
});

const confirm = () => {
    const { action, participant } = confirming.value;
    const url =
        action === 'reset'
            ? `/admin/participants/${participant.id}/password-reset`
            : `/admin/participants/${participant.id}/toggle`;

    processing.value = true;
    router.post(
        url,
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
</script>

<template>
    <Head title="Manage Participants" />

    <AuthenticatedLayout title="Manage Participants" current="admin-participants">
        <div class="mx-auto max-w-7xl space-y-5">
            <AppAlert v-if="error" tone="danger">{{ error }}</AppAlert>

            <AppAlert v-if="scopedTo" tone="info">
                Showing participants assigned to <strong>{{ scopedTo }}</strong> only.
            </AppAlert>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <AppStat label="Total Participants" :value="stats.total" />
                <AppStat label="Active Accounts" :value="stats.active" />
                <AppStat label="Verified Emails" :value="stats.verified" />
                <AppStat label="Deactivated" :value="stats.deactivated" />
            </div>

            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div class="grid flex-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search by name, email, agency, or mobile…"
                        aria-label="Search participants"
                        class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue xl:col-span-3"
                    />
                    <select
                        v-model="status"
                        aria-label="Filter by account status"
                        class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Deactivated</option>
                    </select>
                    <select
                        v-model="verified"
                        aria-label="Filter by email verification"
                        class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                    >
                        <option value="">All verifications</option>
                        <option value="1">Email verified</option>
                        <option value="0">Email unverified</option>
                    </select>
                    <select
                        v-model="sector"
                        aria-label="Filter by sector"
                        class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                    >
                        <option value="">All sectors</option>
                        <option v-for="option in options.sectors" :key="option" :value="option">{{ option }}</option>
                    </select>
                    <select
                        v-model="region"
                        aria-label="Filter by region"
                        class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                    >
                        <option value="">All regions</option>
                        <option v-for="option in options.regions" :key="option" :value="option">{{ option }}</option>
                    </select>
                </div>

                <!-- external: a streamed download, which Inertia's XHR layer cannot follow. -->
                <AppButton :href="exportUrl" external variant="ghost" icon="download" class="shrink-0">
                    Export
                </AppButton>
            </div>

            <AppCard v-if="!participants.data.length" :padded="false">
                <AppEmptyState
                    title="No participants found"
                    description="Clear the filters, or wait for participants to register an account."
                    icon="users"
                />
            </AppCard>

            <template v-else>
                <div class="hidden overflow-x-auto rounded-xl border border-csc-line bg-white lg:block">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Participant</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Agency</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Sector &amp; Region</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Status</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Trainings</th>
                                <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink/70">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-csc-line">
                            <tr
                                v-for="participant in participants.data"
                                :key="participant.id"
                                :class="participant.is_active ? '' : 'opacity-60'"
                            >
                                <td class="px-5 py-3.5">
                                    <Link :href="participant.url" class="font-medium text-csc-blue hover:underline">
                                        {{ participant.name ?? participant.email }}
                                    </Link>
                                    <p class="mt-0.5 text-xs text-csc-ink/60">{{ participant.email }}</p>
                                    <p v-if="participant.mobile" class="mt-0.5 text-xs text-csc-ink/60">
                                        {{ participant.mobile }}
                                    </p>
                                    <p v-if="!participant.profile_complete" class="mt-1 text-xs font-medium text-warning">
                                        Profile incomplete
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-csc-ink/75">
                                    {{ participant.organization ?? '—' }}
                                    <p v-if="participant.position" class="mt-0.5 text-xs text-csc-ink/55">
                                        {{ participant.position }}
                                        <span v-if="participant.salary_grade">· {{ participant.salary_grade }}</span>
                                    </p>
                                    <p v-if="participant.field_office" class="mt-0.5 text-xs text-csc-ink/55">
                                        {{ participant.field_office }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-xs text-csc-ink/70">
                                    {{ participant.sector ?? '—' }}
                                    <p class="mt-0.5 text-csc-ink/55">{{ participant.region ?? '—' }}</p>
                                </td>
                                <td class="px-5 py-3.5 space-y-1">
                                    <AppBadge
                                        :status="participant.is_active ? 'verified' : 'cancelled'"
                                        :label="participant.is_active ? 'Active' : 'Deactivated'"
                                    />
                                    <AppBadge
                                        :status="participant.email_verified ? 'verified' : 'pending'"
                                        :label="participant.email_verified ? 'Email verified' : 'Email unverified'"
                                    />
                                </td>
                                <td class="px-5 py-3.5 text-csc-ink/75">
                                    {{ participant.registrations }}
                                    <p class="mt-0.5 text-xs text-csc-ink/55">
                                        {{ participant.settled_registrations }} settled
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                    <Link
                                        :href="participant.url"
                                        class="text-xs font-semibold text-csc-blue hover:underline"
                                    >
                                        View
                                    </Link>
                                    <template v-if="can.manage">
                                        <span class="px-2 text-csc-line">|</span>
                                        <Link
                                            :href="`/admin/participants/${participant.id}/edit`"
                                            class="text-xs font-semibold text-csc-blue hover:underline"
                                        >
                                            Edit
                                        </Link>
                                        <span class="px-2 text-csc-line">|</span>
                                        <button
                                            type="button"
                                            class="rounded text-xs font-semibold text-csc-blue hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                            @click="ask('reset', participant)"
                                        >
                                            Reset password
                                        </button>
                                        <span class="px-2 text-csc-line">|</span>
                                        <button
                                            type="button"
                                            class="rounded text-xs font-semibold hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                            :class="participant.is_active ? 'text-danger' : 'text-success'"
                                            @click="ask('toggle', participant)"
                                        >
                                            {{ participant.is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <ul class="space-y-3 lg:hidden">
                    <li
                        v-for="participant in participants.data"
                        :key="participant.id"
                        class="rounded-xl border border-csc-line bg-white p-4"
                        :class="participant.is_active ? '' : 'opacity-60'"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <Link :href="participant.url" class="text-sm font-semibold text-csc-blue">
                                    {{ participant.name ?? participant.email }}
                                </Link>
                                <p class="mt-0.5 text-xs text-csc-ink/60">{{ participant.email }}</p>
                                <p v-if="participant.mobile" class="text-xs text-csc-ink/60">
                                    {{ participant.mobile }}
                                </p>
                            </div>
                            <AppBadge
                                class="shrink-0"
                                :status="participant.is_active ? 'verified' : 'cancelled'"
                                :label="participant.is_active ? 'Active' : 'Deactivated'"
                            />
                        </div>

                        <p class="mt-2 text-xs text-csc-ink/70">{{ participant.organization ?? '—' }}</p>
                        <p v-if="participant.sector" class="mt-0.5 text-xs text-csc-ink/55">
                            {{ participant.sector }}
                        </p>
                        <p class="mt-2 text-xs text-csc-ink/60">
                            {{ participant.registrations }} training{{ participant.registrations === 1 ? '' : 's' }} ·
                            {{ participant.settled_registrations }} settled
                        </p>

                        <div v-if="can.manage" class="mt-3 flex flex-wrap gap-x-4 gap-y-2 border-t border-csc-line pt-3">
                            <Link
                                :href="`/admin/participants/${participant.id}/edit`"
                                class="text-xs font-semibold text-csc-blue hover:underline"
                            >
                                Edit
                            </Link>
                            <button
                                type="button"
                                class="rounded text-xs font-semibold text-csc-blue hover:underline"
                                @click="ask('reset', participant)"
                            >
                                Reset password
                            </button>
                            <button
                                type="button"
                                class="rounded text-xs font-semibold"
                                :class="participant.is_active ? 'text-danger' : 'text-success'"
                                @click="ask('toggle', participant)"
                            >
                                {{ participant.is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </div>
                    </li>
                </ul>

                <AppPagination :pagination="participants" label="participants" class="pt-2" />
            </template>
        </div>

        <AppConfirmModal
            v-if="dialog"
            :open="Boolean(confirming)"
            :title="dialog.title"
            :description="dialog.description"
            :confirm-label="dialog.confirmLabel"
            :processing="processing"
            @confirm="confirm"
            @close="confirming = null"
        />
    </AuthenticatedLayout>
</template>
