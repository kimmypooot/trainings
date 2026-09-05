<script setup>
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
    offices: { type: Array, required: true },
    // Superadmin. Deleting is the one action here that cannot be undone, so it
    // is held a role above creating and editing.
    canDelete: { type: Boolean, default: false },
});

/*
 * The summary and the distribution are both derived from `offices`, which the
 * page already holds in full — this list is not paginated, so there is no
 * "totals for the rows shown" trap here and no query to add. If it ever grows
 * a paginator, these have to move to the controller: a header figure that
 * changes when you turn the page is worse than no header figure.
 */
const summary = computed(() => ({
    offices: props.offices.length,
    active: props.offices.filter((office) => office.is_active).length,
    participants: props.offices.reduce((total, office) => total + office.participants, 0),
    staff: props.offices.reduce((total, office) => total + office.staff, 0),
}));

/*
 * Where the region's participants actually sit.
 *
 * The table answers "what is this office"; this answers "which offices carry
 * the load", which is the question a page of eight near-identical rows cannot
 * be scanned for. Inactive offices are kept — they still hold the records of
 * everyone assigned to them, and dropping them would make the bars sum to less
 * than the tile above.
 */
const byParticipants = computed(() =>
    [...props.offices]
        .map((office) => ({ label: office.name, count: office.participants }))
        .sort((a, b) => b.count - a.count)
);

/** The office awaiting confirmation, and which action is being confirmed. */
const confirming = ref(null);
const action = ref('toggle');
const processing = ref(false);

const ask = (office, which) => {
    action.value = which;
    confirming.value = office;
};

/*
 * Why an office cannot be deleted, or null when it can.
 *
 * Shown on the disabled control itself rather than left to be discovered by
 * pressing it. "Delete is missing" and "delete is refused, because eleven
 * people are attached" are different answers, and only the second one tells a
 * superadmin what to do instead.
 */
const blockedReason = (office) =>
    office.can_delete
        ? null
        : `${office.participants} participant${office.participants === 1 ? '' : 's'} and ${office.staff} staff member${office.staff === 1 ? '' : 's'} are assigned to this office. Deactivate it instead.`;

/*
 * What can be done to one office, listed once.
 *
 * The table and the card list both render this, so an action cannot exist in
 * one layout and be missing from the other — which is what it was before, in
 * two loops that happened to agree.
 */
const actionsFor = (office) => [
    { label: 'View', icon: 'eye', href: office.view_url },
    { label: 'Edit', icon: 'pencil', href: office.edit_url },
    office.is_active
        ? { label: 'Deactivate', icon: 'lock', tone: 'danger', onClick: () => ask(office, 'toggle') }
        : { label: 'Activate', icon: 'check', tone: 'success', onClick: () => ask(office, 'toggle') },
    ...(props.canDelete
        ? [
              {
                  label: 'Delete',
                  icon: 'trash',
                  tone: 'danger',
                  disabled: !office.can_delete,
                  reason: blockedReason(office),
                  onClick: () => ask(office, 'delete'),
              },
          ]
        : []),
];

const dialog = computed(() => {
    if (!confirming.value) return null;

    const office = confirming.value;
    const who = office.name;

    if (action.value === 'delete') {
        return {
            title: `Delete ${who}?`,
            description:
                'Nothing is assigned to this office, so it can be removed completely. This cannot be undone — an office with people on it is deactivated instead, which keeps existing records readable.',
            confirmLabel: 'Delete',
        };
    }

    if (office.is_active) {
        // Deactivation is reversible, but it blocks new profile selections, so
        // the people it would affect are named before the button is pressed.
        return {
            title: `Deactivate ${who}?`,
            description: `${office.participants} participant${office.participants === 1 ? '' : 's'} and ${office.staff} staff member${office.staff === 1 ? '' : 's'} are assigned to this office. Deactivating keeps them on existing records but stops the office being chosen on new profiles.`,
            confirmLabel: 'Deactivate',
        };
    }

    return {
        title: `Activate ${who}?`,
        description: 'Participants will be able to select this office on their profile again.',
        confirmLabel: 'Activate',
    };
});

const confirm = () => {
    processing.value = true;

    const office = confirming.value;
    const done = {
        preserveScroll: true,
        onFinish: () => {
            processing.value = false;
            confirming.value = null;
        },
    };

    if (action.value === 'delete') {
        router.delete(`/admin/field-offices/${office.id}`, done);
        return;
    }

    router.post(`/admin/field-offices/${office.id}/toggle`, {}, done);
};
</script>

<template>
    <Head title="Field Offices" />

    <AuthenticatedLayout title="Field Offices" current="admin-field-offices">
        <div class="mx-auto max-w-7xl space-y-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm leading-relaxed text-csc-ink-muted">
                    Offices participants select on their profile. Deactivate rather than delete — existing
                    profiles point at these records.
                </p>
                        <AppButton href="/admin/field-offices/create" icon="plus">New Office</AppButton>
            </div>

            <!--
                One compact row, deliberately. An index page's job is finding a
                row, and stacking a tall summary above the table pushes the
                thing people came for below the fold.
            -->
            <div v-if="offices.length" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <AppStatTile label="Field Offices" :value="summary.offices" icon="building" />
                <AppStatTile
                    label="Active"
                    :value="summary.active"
                    icon="check-circle"
                    tone="success"
                    :caption="
                        summary.offices - summary.active > 0
                            ? `${summary.offices - summary.active} deactivated`
                            : 'All accepting new profiles'
                    "
                />
                <AppStatTile label="Participants" :value="summary.participants" icon="users" />
                <AppStatTile label="Assigned Staff" :value="summary.staff" icon="user" />
            </div>

            <AppCard
                v-if="offices.length"
                title="Participants by Office"
                subtitle="Where the region's participants are assigned. Deactivated offices are included — they still hold their records."
                collapsible
                remember-as="field-offices-distribution"
            >
                <AppBarList :rows="byParticipants" label-width="14rem" />
            </AppCard>

            <AppCard v-if="!offices.length" :padded="false">
                <AppEmptyState
                    title="No field offices yet"
                    description="Add the offices participants can be assigned to."
                    icon="building"
                >
                    <template #action>
                <AppButton href="/admin/field-offices/create" icon="plus">New Office</AppButton>
                    </template>
                </AppEmptyState>
            </AppCard>

            <template v-else>
                <div class="hidden overflow-hidden rounded-xl border border-csc-line bg-white md:block">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Office</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Jurisdiction</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Head</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Participants</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Staff</th>
                                <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink-muted">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-csc-line">
                            <tr v-for="office in offices" :key="office.id" :class="office.is_active ? '' : 'opacity-60'">
                                <td class="px-5 py-3.5">
                                    <Link :href="office.view_url" class="font-medium text-csc-blue hover:underline">
                                        {{ office.name }}
                                    </Link>
                                    <p class="mt-0.5 text-xs text-csc-ink-subtle">
                                        {{ office.code.toUpperCase() }} · {{ office.type_label }}
                                        <span v-if="!office.is_active" class="ml-1 font-semibold text-danger">
                                            · Inactive
                                        </span>
                                    </p>
                                    <p v-if="office.email" class="mt-0.5 text-xs text-csc-ink-subtle">{{ office.email }}</p>
                                </td>
                                <td class="px-5 py-3.5 text-xs text-csc-ink-muted">
                                    {{ office.jurisdiction.join(', ') || office.province }}
                                </td>
                                <td class="px-5 py-3.5 text-csc-ink-muted">
                                    {{ office.head_name ?? '—' }}
                                    <p v-if="office.head_position" class="mt-0.5 text-xs text-csc-ink-subtle">
                                        {{ office.head_position }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-csc-ink-muted">{{ office.participants }}</td>
                                <td class="px-5 py-3.5 text-csc-ink-muted">{{ office.staff }}</td>
                                <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                    <AppRowActions :actions="actionsFor(office)" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <ul class="space-y-3 md:hidden">
                    <li
                        v-for="office in offices"
                        :key="office.id"
                        class="rounded-xl border border-csc-line bg-white p-4"
                        :class="office.is_active ? '' : 'opacity-60'"
                    >
                        <p class="text-sm font-semibold text-csc-ink">{{ office.name }}</p>
                        <p class="mt-0.5 text-xs text-csc-ink-subtle">
                            {{ office.code.toUpperCase() }} · {{ office.type_label }}
                            <span v-if="!office.is_active" class="ml-1 font-semibold text-danger">· Inactive</span>
                        </p>
                        <p class="mt-2 text-xs text-csc-ink-muted">{{ office.head_name ?? '—' }}</p>
                        <div class="mt-3 space-y-2 border-t border-csc-line pt-3">
                            <p class="text-xs text-csc-ink-subtle">
                                {{ office.participants }} participants · {{ office.staff }} staff
                            </p>
                            <AppRowActions :actions="actionsFor(office)" layout="card" />
                        </div>
                    </li>
                </ul>
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
