<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppStatTile from '@/Components/AppStatTile.vue';

const props = defineProps({
    office: { type: Object, required: true },
    stats: { type: Object, required: true },
    recent: { type: Array, required: true },
});

// Money is a currency amount, not a bare integer: thousand separators and two
// decimal places, as everywhere else in the app.
const money = (value) =>
    Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const jurisdiction = props.office.jurisdiction.join(', ') || props.office.province;
</script>

<template>
    <Head :title="office.name" />

    <AuthenticatedLayout title="Field Office" current="admin-field-offices">
        <div class="mx-auto max-w-5xl space-y-5">
            <Link
                href="/admin/field-offices"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-csc-blue hover:text-csc-blue-deep"
            >
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M15 6l-6 6 6 6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                Manage Field Offices
            </Link>

            <AppCard>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-lg font-semibold text-csc-blue">{{ office.name }}</p>
                        <p class="mt-0.5 text-sm text-csc-ink-muted">
                            {{ office.code.toUpperCase() }} · {{ office.type_label }}
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <AppBadge
                                :status="office.is_active ? 'verified' : 'cancelled'"
                                :label="office.is_active ? 'Active' : 'Inactive'"
                            />
                        </div>
                    </div>

                    <AppButton :href="office.edit_url" size="sm" variant="ghost">Edit Office</AppButton>
                </div>
            </AppCard>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <AppStatTile label="Participants" :value="stats.participants" icon="users" />
                <AppStatTile label="Assigned Staff" :value="stats.staff" icon="user" />
                <AppStatTile label="Registrations" :value="stats.registrations" icon="list" />
                <AppStatTile label="Fees Settled" :value="stats.settled" icon="check-circle" tone="success" />
                <AppStatTile
                    label="Awaiting Payment"
                    :value="stats.outstanding"
                    icon="clock"
                    :tone="stats.outstanding > 0 ? 'warning' : 'success'"
                />
                <AppStatTile
                    label="Collected"
                    :value="`₱${money(stats.collected)}`"
                    icon="card"
                    tone="success"
                />
            </div>

            <AppCard title="Office Details">
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-csc-ink-subtle">Type</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ office.type_label }}</dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink-subtle">Primary Province</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ office.province ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-csc-ink-subtle">Jurisdiction</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ jurisdiction }}</dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink-subtle">Address</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ office.address ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink-subtle">Contact Number</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ office.contact_number ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink-subtle">Email</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">
                            <a
                                v-if="office.email"
                                :href="`mailto:${office.email}`"
                                class="text-csc-blue hover:underline"
                            >
                                {{ office.email }}
                            </a>
                            <span v-else>—</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink-subtle">Office Head</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ office.head_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink-subtle">Head Position</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ office.head_position ?? '—' }}</dd>
                    </div>
                    <div v-if="office.remarks" class="sm:col-span-2">
                        <dt class="text-csc-ink-subtle">Remarks</dt>
                        <dd class="mt-0.5 whitespace-pre-line font-medium text-csc-ink">{{ office.remarks }}</dd>
                    </div>
                </dl>
            </AppCard>

            <AppCard title="Recent Registrations" :padded="recent.length > 0">
                <ul v-if="recent.length" class="divide-y divide-csc-line">
                    <li
                        v-for="registration in recent"
                        :key="registration.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3.5"
                    >
                        <div class="min-w-0">
                            <Link
                                :href="registration.roster_url"
                                class="text-sm font-medium text-csc-blue hover:underline"
                            >
                                {{ registration.training }}
                            </Link>
                            <p class="mt-0.5 text-xs text-csc-ink-subtle">
                                {{ registration.participant }} · Registered {{ registration.registered_at }}
                            </p>
                        </div>
                        <AppBadge :status="registration.status" :label="registration.status_label" />
                    </li>
                </ul>

                <AppEmptyState
                    v-else
                    title="No registrations yet"
                    description="Participants from this office have not registered for any training."
                    icon="bookmark"
                />
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>