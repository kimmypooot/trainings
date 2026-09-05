<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppInput from '@/Components/AppInput.vue';
import AppPagination from '@/Components/AppPagination.vue';
import AppRowActions from '@/Components/AppRowActions.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppStatTile from '@/Components/AppStatTile.vue';
import { percent } from '@/charts';
import { useFilters, filteringClass } from '@/useFilters';
import { useDownload } from '@/useDownload';

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

/*
 * Share of the whole directory, for the captions under the tiles.
 *
 * A bare count says how many; the share says whether that is most of them or a
 * handful, which is the thing being read off a summary row. `percent()` guards
 * the empty set, so a fresh install shows 0% rather than dividing by nothing.
 */
const percentOf = (value, total) => percent(value, total, 0);

const unverified = computed(() => props.stats.total - props.stats.verified);

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const verified = ref(props.filters.verified ?? '');
const sector = ref(props.filters.sector ?? '');
const region = ref(props.filters.region ?? '');

/*
 * `stats` is deliberately not reloaded: it counts the whole office, not the
 * filtered rows, so re-fetching it on every keystroke would recompute four
 * aggregates to arrive at the same four numbers. `exportUrl` is, because the
 * download is built from these filters and must match what is on screen.
 */
const { filtering } = useFilters({
    url: '/admin/participants',
    only: ['participants', 'filters', 'exportUrl'],
    watch: [search, status, verified, sector, region],
    query: () => ({
        search: search.value || undefined,
        status: status.value || undefined,
        verified: verified.value || undefined,
        sector: sector.value || undefined,
        region: region.value || undefined,
    }),
});

const { downloading, start } = useDownload();

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

/*
 * What can be done to one participant, listed once for both layouts.
 *
 * The card list used to omit View — the name above it links to the same
 * place — but it is listed here rather than special-cased, because a set that
 * differs by layout is the drift this component exists to stop.
 */
const actionsFor = (participant) => [
    { label: 'View', icon: 'eye', href: participant.url },
    ...(props.can.manage
        ? [
              { label: 'Edit', icon: 'pencil', href: `/admin/participants/${participant.id}/edit` },
              { label: 'Reset password', icon: 'key', onClick: () => ask('reset', participant) },
              participant.is_active
                  ? { label: 'Deactivate', icon: 'lock', tone: 'danger', onClick: () => ask('toggle', participant) }
                  : { label: 'Activate', icon: 'check', tone: 'success', onClick: () => ask('toggle', participant) },
          ]
        : []),
];

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

            <!--
                Tone carries meaning here, never decoration: a figure is
                brand-coloured unless it is telling you something is good or
                wants looking at. So "Deactivated" is neutral — it is an
                administrative state, not a problem — while an unverified
                address is a participant who cannot be reached.
            -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <AppStatTile label="Total Participants" :value="stats.total" icon="users" />
                <AppStatTile
                    label="Active Accounts"
                    :value="stats.active"
                    icon="check-circle"
                    tone="success"
                    :caption="`${percentOf(stats.active, stats.total)} of the directory`"
                />
                <AppStatTile
                    label="Verified Emails"
                    :value="stats.verified"
                    icon="envelope"
                    :tone="stats.total > 0 && stats.verified < stats.total ? 'warning' : 'success'"
                    :caption="unverified > 0 ? `${unverified} not yet verified` : 'All addresses confirmed'"
                />
                <AppStatTile label="Deactivated" :value="stats.deactivated" icon="lock" />
            </div>

            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div class="grid flex-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <AppInput
                        v-model="search"
                        label=""
                        type="search"
                        placeholder="Search by name, email, agency, or mobile…"
                        aria-label="Search participants"
                        class="xl:col-span-3"
                    />
                    <AppSelect
                        v-model="status"
                        label=""
                        aria-label="Filter by account status"
                        placeholder="All statuses"
                        :options="[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Deactivated' }]"
                    />
                    <AppSelect
                        v-model="verified"
                        label=""
                        aria-label="Filter by email verification"
                        placeholder="All verifications"
                        :options="[{ value: '1', label: 'Email verified' }, { value: '0', label: 'Email unverified' }]"
                    />
                    <AppSelect
                        v-model="sector"
                        label=""
                        aria-label="Filter by sector"
                        placeholder="All sectors"
                        :options="options.sectors"
                    />
                    <AppSelect
                        v-model="region"
                        label=""
                        aria-label="Filter by region"
                        placeholder="All regions"
                        :options="options.regions"
                    />
                </div>

                <!-- external: a streamed download, which Inertia's XHR layer cannot follow. -->
                <AppButton
                    :href="exportUrl"
                    external
                    variant="ghost"
                    icon="download"
                    class="shrink-0"
                    :loading="downloading === exportUrl"
                    @click.prevent="start(exportUrl)"
                >
                    Export
                </AppButton>
            </div>

            <!--
                 The results dim while a filtered visit is out. The controls above stay
                 live — narrowing further mid-request is the normal thing to do — but
                 these rows are already superseded, so they stop taking clicks until
                 they have been redrawn.
            -->
            <div :class="filteringClass(filtering)" :aria-busy="filtering" class="space-y-5">
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
                                    <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Participant</th>
                                    <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Agency</th>
                                    <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Sector &amp; Region</th>
                                    <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Status</th>
                                    <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Trainings</th>
                                    <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink-muted">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-csc-line">
                                <tr
                                    v-for="participant in participants.data"
                                    :key="participant.id"
                                    :class="participant.is_active ? '' : 'opacity-60'"
                                >
                                    <td class="px-5 py-3.5">
                                        <div class="flex items-center gap-1.5">
                                            <Link :href="participant.url" class="font-medium text-csc-blue hover:underline">
                                                {{ participant.name ?? participant.email }}
                                            </Link>
                                            <AppIcon
                                                v-if="participant.email_verified"
                                                name="check-circle"
                                                class="shrink-0 cursor-help text-success"
                                                aria-hidden="true"
                                                title="Email verified"
                                            />
                                        </div>
                                        <p class="mt-0.5 text-xs text-csc-ink-subtle">{{ participant.email }}</p>
                                        <p v-if="participant.mobile" class="mt-0.5 text-xs text-csc-ink-subtle">
                                            {{ participant.mobile }}
                                        </p>
                                        <p v-if="!participant.profile_complete" class="mt-1 text-xs font-medium text-warning">
                                            Profile incomplete
                                        </p>
                                    </td>
                                    <td class="px-5 py-3.5 text-csc-ink-muted">
                                        {{ participant.organization ?? '—' }}
                                        <p v-if="participant.position" class="mt-0.5 text-xs text-csc-ink-subtle">
                                            {{ participant.position }}
                                            <span v-if="participant.salary_grade">· {{ participant.salary_grade }}</span>
                                        </p>
                                        <p v-if="participant.field_office" class="mt-0.5 text-xs text-csc-ink-subtle">
                                            {{ participant.field_office }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-3.5 text-xs text-csc-ink-muted">
                                        {{ participant.sector ?? '—' }}
                                        <p class="mt-0.5 text-csc-ink-subtle">{{ participant.region ?? '—' }}</p>
                                    </td>
                                    <td class="px-5 py-3.5 space-y-1">
                                        <AppBadge
                                            :status="participant.is_active ? 'verified' : 'cancelled'"
                                            :label="participant.is_active ? 'Active' : 'Deactivated'"
                                        />
                                        <AppBadge v-if="!participant.email_verified" status="pending" label="Email unverified" />
                                    </td>
                                    <td class="px-5 py-3.5 text-csc-ink-muted">
                                        {{ participant.registrations }}
                                        <p class="mt-0.5 text-xs text-csc-ink-subtle">
                                            {{ participant.settled_registrations }} settled
                                        </p>
                                    </td>
                                    <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                        <AppRowActions :actions="actionsFor(participant)" />
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
                                    <p class="mt-0.5 text-xs text-csc-ink-subtle">{{ participant.email }}</p>
                                    <p v-if="participant.mobile" class="text-xs text-csc-ink-subtle">
                                        {{ participant.mobile }}
                                    </p>
                                </div>
                                <AppBadge
                                    class="shrink-0"
                                    :status="participant.is_active ? 'verified' : 'cancelled'"
                                    :label="participant.is_active ? 'Active' : 'Deactivated'"
                                />
                            </div>

                            <p class="mt-2 text-xs text-csc-ink-muted">{{ participant.organization ?? '—' }}</p>
                            <p v-if="participant.sector" class="mt-0.5 text-xs text-csc-ink-subtle">
                                {{ participant.sector }}
                            </p>
                            <p class="mt-2 text-xs text-csc-ink-subtle">
                                {{ participant.registrations }} training{{ participant.registrations === 1 ? '' : 's' }} ·
                                {{ participant.settled_registrations }} settled
                            </p>

                            <div class="mt-3 border-t border-csc-line pt-3">
                                <AppRowActions :actions="actionsFor(participant)" layout="card" />
                            </div>
                        </li>
                    </ul>

                    <AppPagination :pagination="participants" label="participants" class="pt-2" />
                </template>
            </div>
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
