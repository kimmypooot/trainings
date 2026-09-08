<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppInput from '@/Components/AppInput.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppPagination from '@/Components/AppPagination.vue';
import AppRowActions from '@/Components/AppRowActions.vue';
import AppStatTile from '@/Components/AppStatTile.vue';
import AppCombobox from '@/Components/AppCombobox.vue';
import AppModal from '@/Components/AppModal.vue';
import { useFilters, filteringClass } from '@/useFilters';

const props = defineProps({
    agencies: { type: Object, required: true },
    filters: { type: Object, required: true },
    counts: { type: Object, required: true },
    /** Employers people typed because this list did not have them. */
    unlisted: { type: Object, required: true },
    sectors: { type: Array, required: true },
    offices: { type: Array, required: true },
    /** Every active agency, for matching a typed shortcut against the list. */
    agencyOptions: { type: Array, required: true },
    // Superadmin. Deleting is the one action here that cannot be undone.
    canDelete: { type: Boolean, default: false },
});

const search = ref(props.filters.search ?? '');
const sector = ref(props.filters.sector ?? '');
const fieldOfficeId = ref(props.filters.field_office_id ?? '');
const status = ref(props.filters.status ?? '');

/*
 * `only` deliberately excludes `counts` and `unlisted`.
 *
 * Both describe the whole list rather than the rows on screen. A tile reading
 * "18 employers typed instead" is an offer to go and look at eighteen things,
 * so narrowing it with the search box would make it read zero the moment
 * somebody types — the same trap the roster's chip counts document.
 */
const { filtering } = useFilters({
    url: '/admin/agencies',
    only: ['agencies', 'filters'],
    watch: [search, sector, fieldOfficeId, status],
    query: () => ({
        search: search.value || undefined,
        sector: sector.value || undefined,
        field_office_id: fieldOfficeId.value || undefined,
        status: status.value || undefined,
    }),
});

const statusOptions = [
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Inactive only' },
];

/** The agency awaiting confirmation, and which action is being confirmed. */
const confirming = ref(null);
const action = ref('toggle');
const processing = ref(false);

const ask = (agency, which) => {
    action.value = which;
    confirming.value = agency;
};

const people = (count) => `${count} participant${count === 1 ? '' : 's'}`;

/*
 * Why an agency cannot be deleted, or null when it can.
 *
 * Shown on the disabled control rather than left to be discovered by pressing
 * it: "delete is missing" and "delete is refused, because eleven people are
 * filed under this" are different answers, and only the second says what to do
 * instead.
 */
const blockedReason = (agency) =>
    agency.can_delete
        ? null
        : `${people(agency.participants)} ${agency.participants === 1 ? 'is' : 'are'} filed under this agency. Deactivate it instead.`;

const actionsFor = (agency) => [
    { label: 'Edit', icon: 'pencil', href: agency.edit_url },
    agency.is_active
        ? { label: 'Deactivate', icon: 'lock', tone: 'danger', onClick: () => ask(agency, 'toggle') }
        : { label: 'Activate', icon: 'check', tone: 'success', onClick: () => ask(agency, 'toggle') },
    ...(props.canDelete
        ? [
              {
                  label: 'Delete',
                  icon: 'trash',
                  tone: 'danger',
                  disabled: !agency.can_delete,
                  reason: blockedReason(agency),
                  onClick: () => ask(agency, 'delete'),
              },
          ]
        : []),
];

const dialog = computed(() => {
    if (!confirming.value) return null;

    const agency = confirming.value;

    if (action.value === 'delete') {
        return {
            title: `Delete ${agency.name}?`,
            description:
                'Nobody is filed under this agency, so it can be removed completely. This cannot be undone — an agency with people on it is deactivated instead, which keeps existing records readable.',
            confirmLabel: 'Delete',
        };
    }

    if (agency.is_active) {
        return {
            title: `Deactivate ${agency.name}?`,
            description: `${people(agency.participants)} filed under this agency. Deactivating keeps their records exactly as they are, but stops the agency being offered on new profiles.`,
            confirmLabel: 'Deactivate',
        };
    }

    return {
        title: `Activate ${agency.name}?`,
        description: 'Participants will be able to choose this agency on their profile again.',
        confirmLabel: 'Activate',
    };
});

const confirm = () => {
    processing.value = true;

    const agency = confirming.value;
    const done = {
        preserveScroll: true,
        onFinish: () => {
            processing.value = false;
            confirming.value = null;
        },
    };

    if (action.value === 'delete') {
        router.delete(`/admin/agencies/${agency.id}`, done);
        return;
    }

    router.post(`/admin/agencies/${agency.id}/toggle`, {}, done);
};

/*
 * Matching a typed employer to an agency already on the list.
 *
 * The other half of the gap panel, and the half "Add to list" cannot do.
 * Somebody typed "DEPED" while "Department of Education" is already there:
 * adding it again is refused by the unique name — correctly, since a second row
 * is the split this table exists to end — so without this the shortcut has
 * nowhere to go and sits in the panel permanently.
 *
 * AppCombobox rather than a select because it matches on acronym as well as
 * name, which is exactly what is needed when the typed value *is* an acronym.
 */
const matching = ref(null);
const matchedAgencyId = ref('');
const matchProcessing = ref(false);

const openMatch = (row) => {
    matchedAgencyId.value = '';
    matching.value = row;
};

const matchedAgencyLabel = computed(
    () => props.agencyOptions.find((option) => String(option.value) === String(matchedAgencyId.value))?.name
);

const submitMatch = () => {
    matchProcessing.value = true;

    router.post(
        '/admin/agencies/resolve',
        { organization_name: matching.value.name, agency_id: matchedAgencyId.value },
        {
            preserveScroll: true,
            onFinish: () => {
                matchProcessing.value = false;
                matching.value = null;
            },
        }
    );
};

/*
 * Correcting the text people typed, without deciding what it is yet.
 *
 * Separate from "Match existing" because it answers a different question.
 * Matching says *which agency this is*; this says *what they meant to type*.
 * "DEPED", "DEP ED" and "Dep. Ed." are three rows in this panel until they
 * read the same, and each would otherwise be matched or added on its own — so
 * correcting them into one spelling turns three decisions into one, and leaves
 * the participants' own records reading properly in the meantime.
 */
const renaming = ref(null);
const renamedTo = ref('');
const renameProcessing = ref(false);

const openRename = (row) => {
    renamedTo.value = row.name;
    renaming.value = row;
};

/*
 * Renaming onto a spelling already in the panel merges the two, which is the
 * useful case rather than an accident — so it is announced rather than blocked.
 */
const renameMergesWith = computed(() => {
    const target = renamedTo.value.trim().toUpperCase();

    // Folded on both sides: either spelling can now be mixed case, and the
    // column's collation treats them as one value, so comparing them
    // literally would miss the merge it is here to announce.
    if (!renaming.value || target === renaming.value.name.toUpperCase()) {
        return null;
    }

    return props.unlisted.rows.find((row) => row.name.toUpperCase() === target) ?? null;
});

const submitRename = () => {
    renameProcessing.value = true;

    router.post(
        '/admin/agencies/typed-employers/rename',
        { organization_name: renaming.value.name, name: renamedTo.value },
        {
            preserveScroll: true,
            onFinish: () => {
                renameProcessing.value = false;
                renaming.value = null;
            },
        }
    );
};

const hasFilters = computed(
    () => Boolean(search.value || sector.value || fieldOfficeId.value || status.value)
);

const clearFilters = () => {
    search.value = '';
    sector.value = '';
    fieldOfficeId.value = '';
    status.value = '';
};
</script>

<template>
    <Head title="Agencies" />

    <AuthenticatedLayout title="Agencies" current="admin-agencies">
        <div class="mx-auto max-w-7xl space-y-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="max-w-2xl text-sm leading-relaxed text-csc-ink-muted">
                    The employer list participants choose from on their profile. Picking an agency also
                    fills in its sector and field office, so keeping this list right is what keeps those
                    two right. Deactivate rather than delete — existing profiles point at these records.
                </p>
                <AppButton href="/admin/agencies/create" icon="plus">Add agency</AppButton>
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <AppStatTile
                    label="On the list"
                    :value="counts.all"
                    icon="building"
                    caption="Agencies participants can choose"
                />
                <AppStatTile
                    label="Employers typed instead"
                    :value="unlisted.distinct"
                    icon="pencil"
                    :tone="unlisted.distinct > 0 ? 'warning' : 'brand'"
                    caption="Names people entered themselves"
                />
                <AppStatTile
                    label="Missing a field office"
                    :value="counts.without_office"
                    icon="map-pin"
                    :tone="counts.without_office > 0 ? 'warning' : 'brand'"
                    caption="Participants are asked to pick one"
                />
            </div>

            <!--
                The gap, above the list.

                A participant who cannot find their employer types it instead and
                nothing objects, so a missing agency is invisible unless it is put
                somewhere people look. Ranked by how many said the same thing:
                twenty people typing one name is one missing row, and adding it
                moves twenty participants onto canonical data at once.
            -->
            <AppCard
                title="Employers people typed"
                :subtitle="
                    unlisted.distinct > 0
                        ? `${people(unlisted.participants)} could not find their employer on the list and entered it themselves.`
                        : 'Everyone has found their employer on the list.'
                "
                collapsible
                remember-as="agencies-unlisted"
            >
                <AppEmptyState
                    v-if="unlisted.rows.length === 0"
                    icon="check-circle"
                    title="Nothing missing"
                    description="Every participant has picked their employer from this list rather than typing it."
                    compact
                />

                <div v-else class="space-y-2">
                    <div
                        v-for="row in unlisted.rows"
                        :key="row.name"
                        class="flex flex-col gap-2 rounded-lg border border-csc-line p-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-csc-ink">{{ row.name }}</p>
                            <p class="text-xs text-csc-ink-subtle">{{ people(row.participants) }}</p>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2">
                            <AppButton
                                variant="ghost"
                                size="sm"
                                icon="pencil"
                                @click="openRename(row)"
                            >
                                Edit
                            </AppButton>
                            <AppButton
                                variant="ghost"
                                size="sm"
                                icon="link"
                                @click="openMatch(row)"
                            >
                                Match existing
                            </AppButton>
                            <AppButton :href="row.add_url" variant="secondary" size="sm" icon="plus">
                                Add to list
                            </AppButton>
                        </div>
                    </div>

                    <p v-if="unlisted.distinct > unlisted.shown" class="pt-1 text-xs text-csc-ink-subtle">
                        Showing the {{ unlisted.shown }} most common of {{ unlisted.distinct }} typed
                        employers. Adding these first clears the most records.
                    </p>
                </div>
            </AppCard>

            <AppCard title="Agency list">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <AppInput v-model="search" label="Search" placeholder="Name or acronym" />
                    <AppSelect
                        v-model="sector"
                        label="Sector"
                        :options="sectors"
                        placeholder="All sectors"
                    />
                    <AppSelect
                        v-model="fieldOfficeId"
                        label="Field office"
                        :options="offices"
                        placeholder="All offices"
                    />
                    <AppSelect
                        v-model="status"
                        label="Status"
                        :options="statusOptions"
                        placeholder="Active and inactive"
                    />
                </div>

                <div :class="filteringClass(filtering)" :aria-busy="filtering" class="mt-4 space-y-3">
                    <AppEmptyState
                        v-if="agencies.data.length === 0"
                        icon="building"
                        :title="hasFilters ? 'No agencies match' : 'The employer list is empty'"
                        :description="
                            hasFilters
                                ? 'Nothing on the list matches these filters.'
                                : 'Add the first agency to start offering it on the profile form.'
                        "
                    >
                        <AppButton v-if="hasFilters" variant="secondary" @click="clearFilters">
                            Clear filters
                        </AppButton>
                    </AppEmptyState>

                    <template v-else>
                        <!-- Table above md, cards below: the same action set either way. -->
                        <div class="hidden overflow-x-auto md:block">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr
                                        class="border-b border-csc-line text-xs uppercase tracking-wide text-csc-ink-subtle"
                                    >
                                        <th class="px-3 py-2 font-semibold">Agency</th>
                                        <th class="px-3 py-2 font-semibold">Sector</th>
                                        <th class="px-3 py-2 font-semibold">Field office</th>
                                        <th class="px-3 py-2 text-right font-semibold">Participants</th>
                                        <th class="px-3 py-2"><span class="sr-only">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="agency in agencies.data"
                                        :key="agency.id"
                                        class="border-b border-csc-line/70 last:border-0"
                                        :class="agency.is_active ? '' : 'opacity-60'"
                                    >
                                        <td class="px-3 py-2.5">
                                            <p class="font-medium text-csc-ink">
                                                {{ agency.name }}
                                                <span
                                                    v-if="!agency.is_active"
                                                    class="ml-1 text-xs font-semibold text-danger"
                                                >
                                                    · Inactive
                                                </span>
                                            </p>
                                            <p v-if="agency.acronym" class="text-xs text-csc-ink-subtle">
                                                {{ agency.acronym }}
                                            </p>
                                        </td>
                                        <td class="px-3 py-2.5 text-csc-ink-muted">{{ agency.sector }}</td>
                                        <td class="px-3 py-2.5 text-csc-ink-muted">
                                            <span v-if="agency.field_office">{{ agency.field_office }}</span>
                                            <span v-else class="font-semibold text-warning">Not set</span>
                                        </td>
                                        <td
                                            class="px-3 py-2.5 text-right tabular-nums text-csc-ink-muted"
                                        >
                                            {{ agency.participants }}
                                        </td>
                                        <td class="px-3 py-2.5 text-right">
                                            <AppRowActions :actions="actionsFor(agency)" />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="space-y-2 md:hidden">
                            <div
                                v-for="agency in agencies.data"
                                :key="agency.id"
                                class="rounded-lg border border-csc-line p-3"
                                :class="agency.is_active ? '' : 'opacity-60'"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="font-medium text-csc-ink">
                                            {{ agency.name }}
                                            <span
                                                v-if="!agency.is_active"
                                                class="ml-1 text-xs font-semibold text-danger"
                                            >
                                                · Inactive
                                            </span>
                                        </p>
                                        <p v-if="agency.acronym" class="text-xs text-csc-ink-subtle">
                                            {{ agency.acronym }}
                                        </p>
                                    </div>
                                    <AppRowActions :actions="actionsFor(agency)" layout="card" />
                                </div>
                                <dl class="mt-2 grid grid-cols-2 gap-2 text-xs">
                                    <div>
                                        <dt class="text-csc-ink-subtle">Sector</dt>
                                        <dd class="text-csc-ink-muted">{{ agency.sector }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-csc-ink-subtle">Field office</dt>
                                        <dd
                                            :class="
                                                agency.field_office
                                                    ? 'text-csc-ink-muted'
                                                    : 'font-semibold text-warning'
                                            "
                                        >
                                            {{ agency.field_office ?? 'Not set' }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-csc-ink-subtle">Participants</dt>
                                        <dd class="tabular-nums text-csc-ink-muted">
                                            {{ agency.participants }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </div>

                        <AppPagination :pagination="agencies" preserve-scroll />
                    </template>
                </div>
            </AppCard>
        </div>

        <AppModal
            :open="renaming !== null"
            title="Correct what was typed"
            :subtitle="renaming ? `${renaming.participants} ${renaming.participants === 1 ? 'participant' : 'participants'} typed “${renaming.name}”.` : null"
            @close="renaming = null"
        >
            <div class="space-y-3">
                <p class="text-sm text-csc-ink-muted">
                    Use this when the spelling itself is the problem — a shortcut, an abbreviation or
                    a typo. It rewrites what these participants have on their records; it does not
                    put anything on the agency list. To do that, use Match existing or Add to list.
                </p>

                <AppInput
                    v-model="renamedTo"
                    label="Employer name"
                    hint="Written exactly as you type it. Participants' own entries are stored in capitals; a correction made here is not, because you are deciding the proper spelling."
                    required
                />

                <p
                    v-if="renameMergesWith"
                    class="rounded-lg border border-warning/30 bg-warning-soft p-3 text-sm text-csc-ink"
                >
                    <span class="font-semibold">These will be combined.</span>
                    “{{ renameMergesWith.name }}” is already in this list with
                    {{ renameMergesWith.participants }}
                    {{ renameMergesWith.participants === 1 ? 'participant' : 'participants' }}, so the
                    two become one entry of
                    {{ renameMergesWith.participants + renaming.participants }}.
                </p>
            </div>

            <template #footer>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <AppButton variant="secondary" @click="renaming = null">Cancel</AppButton>
                    <AppButton
                        :disabled="
                            !renamedTo.trim() ||
                            renamedTo.trim().toUpperCase() === renaming?.name.toUpperCase()
                        "
                        :loading="renameProcessing"
                        @click="submitRename"
                    >
                        Save
                    </AppButton>
                </div>
            </template>
        </AppModal>

        <AppModal
            :open="matching !== null"
            title="Match to an agency already on the list"
            size="lg"
            allow-overflow
            :subtitle="matching ? `${matching.participants} ${matching.participants === 1 ? 'participant' : 'participants'} typed “${matching.name}”.` : null"
            @close="matching = null"
        >
            <div class="space-y-3">
                <p class="text-sm text-csc-ink-muted">
                    Use this when what they typed is an agency you already have — a shortcut, an
                    acronym or a different spelling. They will be moved onto the agency you pick and
                    take its sector and field office with them.
                </p>

                <AppCombobox
                    v-model="matchedAgencyId"
                    label="Agency"
                    :options="agencyOptions"
                    placeholder="Search by name or acronym…"
                    hint="Searches acronyms too, so typing what the participant typed usually finds it."
                />

                <p
                    v-if="matchedAgencyLabel"
                    class="rounded-lg border border-csc-line bg-csc-blue-tint/40 p-3 text-sm text-csc-ink"
                >
                    “{{ matching.name }}” becomes
                    <span class="font-semibold">{{ matchedAgencyLabel }}</span>
                    for {{ matching.participants }}
                    {{ matching.participants === 1 ? 'participant' : 'participants' }}.
                </p>
            </div>

            <template #footer>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <AppButton variant="secondary" @click="matching = null">Cancel</AppButton>
                    <AppButton
                        :disabled="!matchedAgencyId"
                        :loading="matchProcessing"
                        @click="submitMatch"
                    >
                        Move them
                    </AppButton>
                </div>
            </template>
        </AppModal>
        <AppConfirmModal
            :open="confirming !== null"
            :title="dialog?.title ?? ''"
            :description="dialog?.description"
            :confirm-label="dialog?.confirmLabel"
            :processing="processing"
            @confirm="confirm"
            @close="confirming = null"
        />
    </AuthenticatedLayout>
</template>
