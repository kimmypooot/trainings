<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppInput from '@/Components/AppInput.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppPagination from '@/Components/AppPagination.vue';
import AppRowActions from '@/Components/AppRowActions.vue';
import AppStatTile from '@/Components/AppStatTile.vue';
import { useFilters, filteringClass } from '@/useFilters';
import { useDownload } from '@/useDownload';

const props = defineProps({
    certificates: { type: Object, required: true },
    filters: { type: Object, required: true },
    stats: { type: Object, required: true },
    trainings: { type: Array, default: () => [] },
    years: { type: Array, default: () => [] },
    can: { type: Object, required: true },
    scopedTo: { type: String, default: null },
    exportUrl: { type: String, required: true },
});

const page = usePage();
const error = computed(() => page.props.errors?.certificate);

const search = ref(props.filters.search ?? '');
const training = ref(props.filters.training ?? '');
const emailed = ref(props.filters.emailed ?? '');
const year = ref(props.filters.year ?? '');

/*
 * `stats`, `trainings` and `years` describe the whole register — the filter
 * dropdowns are built from them, so narrowing must not narrow the options you
 * would use to widen again. `exportUrl` does move with the filters: the
 * download is meant to be the rows on screen.
 */
const { filtering } = useFilters({
    url: '/admin/certificates',
    only: ['certificates', 'filters', 'exportUrl'],
    watch: [search, training, emailed, year],
    query: () => ({
        search: search.value || undefined,
        training: training.value || undefined,
        emailed: emailed.value || undefined,
        year: year.value || undefined,
    }),
});

const { downloading, start } = useDownload();

/*
 * Re-sending confirms first. It is not destructive, but it puts mail in
 * someone's inbox on a click, and a certificate arriving twice reads as an
 * error on CSC's part.
 */
const resending = ref(null);
const processing = ref(false);

const resend = () => {
    processing.value = true;
    router.post(
        `/admin/certificates/${resending.value.id}/resend`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
                resending.value = null;
            },
        }
    );
};

const copied = ref(null);
const copyVerifyUrl = async (certificate) => {
    await navigator.clipboard.writeText(certificate.verify_url);
    copied.value = certificate.id;
    setTimeout(() => (copied.value = null), 2000);
};

/*
 * What can be done with one certificate, listed once for both layouts — the
 * card list had been missing the verify link entirely.
 *
 * Copying confirms itself by swapping icon, tone and label together for two
 * seconds. Nothing else on screen changes when a link reaches the clipboard,
 * so the control has to be the receipt.
 */
const actionsFor = (certificate) => [
    // A file response, not an Inertia visit.
    { label: 'Download', icon: 'download', href: certificate.download_url, external: true },
    copied.value === certificate.id
        ? { label: 'Copied', icon: 'check', tone: 'success', onClick: () => copyVerifyUrl(certificate) }
        : { label: 'Copy verify link', icon: 'link', onClick: () => copyVerifyUrl(certificate) },
    ...(props.can.resend
        ? [{ label: 'Re-send', icon: 'envelope', onClick: () => (resending.value = certificate) }]
        : []),
];
</script>

<template>
    <Head title="Certificates" />

    <AuthenticatedLayout title="Certificates" current="admin-certificates">
        <div class="mx-auto max-w-7xl space-y-5">
            <AppAlert v-if="error" tone="danger">{{ error }}</AppAlert>

            <AppAlert v-if="scopedTo" tone="info">
                Showing certificates for participants of <strong>{{ scopedTo }}</strong> only.
            </AppAlert>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <AppStatTile label="Issued" :value="stats.total" icon="certificate" />
                <AppStatTile label="Issued This Year" :value="stats.this_year" icon="calendar" />
                <!--
                    The one figure on this row that is work rather than record,
                    so it is the one allowed to change colour: amber while
                    anything is undelivered, green once nothing is.
                -->
                <AppStatTile
                    label="Not Yet Emailed"
                    :value="stats.not_emailed"
                    icon="envelope"
                    :tone="stats.not_emailed > 0 ? 'warning' : 'success'"
                    :caption="stats.not_emailed > 0 ? 'Waiting to be sent' : 'Every certificate delivered'"
                />
                <AppStatTile
                    label="Public Verifications"
                    :value="stats.verifications"
                    icon="shield"
                    caption="Lookups on the public checker"
                />
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <AppInput
                    v-model="search"
                    label=""
                    type="search"
                    placeholder="Search by number, participant, or training…"
                    aria-label="Search certificates"
                    class="xl:col-span-2"
                />
                <AppSelect
                    v-model="training"
                    label=""
                    aria-label="Filter by training"
                    placeholder="All trainings"
                    :options="trainings"
                />
                <AppSelect
                    v-model="emailed"
                    label=""
                    aria-label="Filter by delivery"
                    placeholder="Emailed or not"
                    :options="[{ value: '1', label: 'Emailed' }, { value: '0', label: 'Not yet emailed' }]"
                />
                <AppSelect
                    v-model="year"
                    label=""
                    aria-label="Filter by issue year"
                    placeholder="All years"
                    :options="years"
                />
            </div>

            <div class="flex justify-end">
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
                <AppCard v-if="!certificates.data.length" :padded="false">
                    <AppEmptyState
                        title="No certificates found"
                        description="Certificates appear here once they are issued from a training's roster."
                        icon="certificate"
                    />
                </AppCard>

                <template v-else>
                    <div class="hidden overflow-x-auto rounded-xl border border-csc-line bg-white lg:block">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                                <tr>
                                    <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Certificate</th>
                                    <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Participant</th>
                                    <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Training</th>
                                    <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Delivery</th>
                                    <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Activity</th>
                                    <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink-muted">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-csc-line">
                                <tr v-for="certificate in certificates.data" :key="certificate.id">
                                    <td class="px-5 py-3.5">
                                        <Link
                                            :href="`/admin/certificates/${certificate.id}`"
                                            class="font-mono text-xs font-semibold text-csc-blue hover:underline"
                                        >
                                            {{ certificate.number }}
                                        </Link>
                                        <p class="mt-0.5 text-xs text-csc-ink-subtle">Issued {{ certificate.issued_at }}</p>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <p class="font-medium text-csc-ink">{{ certificate.participant }}</p>
                                        <p class="mt-0.5 text-xs text-csc-ink-subtle">{{ certificate.email }}</p>
                                    </td>
                                    <td class="px-5 py-3.5 text-csc-ink-muted">{{ certificate.training }}</td>
                                    <td class="px-5 py-3.5 text-xs">
                                        <span v-if="certificate.email_sent_at" class="text-csc-ink-muted">
                                            Emailed {{ certificate.email_sent_at }}
                                        </span>
                                        <span v-else class="font-medium text-warning">Not yet emailed</span>
                                    </td>
                                    <td class="px-5 py-3.5 text-xs text-csc-ink-muted">
                                        {{ certificate.verifications }} verification{{
                                            certificate.verifications === 1 ? '' : 's'
                                        }}
                                        <p class="mt-0.5 text-csc-ink-subtle">{{ certificate.downloads }} download(s)</p>
                                        <p v-if="certificate.last_verified_at" class="mt-0.5 text-csc-ink-subtle">
                                            Last {{ certificate.last_verified_at }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                        <AppRowActions :actions="actionsFor(certificate)" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <ul class="space-y-3 lg:hidden">
                        <li
                            v-for="certificate in certificates.data"
                            :key="certificate.id"
                            class="rounded-xl border border-csc-line bg-white p-4"
                        >
                            <Link
                                :href="`/admin/certificates/${certificate.id}`"
                                class="font-mono text-xs font-semibold text-csc-blue"
                            >
                                {{ certificate.number }}
                            </Link>
                            <p class="mt-1 text-sm font-medium text-csc-ink">{{ certificate.participant }}</p>
                            <p class="mt-0.5 text-xs text-csc-ink-subtle">{{ certificate.training }}</p>
                            <p class="mt-2 text-xs text-csc-ink-subtle">
                                Issued {{ certificate.issued_at }} ·
                                {{ certificate.verifications }} verification(s)
                            </p>
                            <p v-if="!certificate.email_sent_at" class="mt-1 text-xs font-medium text-warning">
                                Not yet emailed
                            </p>

                            <div class="mt-3 border-t border-csc-line pt-3">
                                <AppRowActions :actions="actionsFor(certificate)" layout="card" />
                            </div>
                        </li>
                    </ul>

                    <AppPagination :pagination="certificates" label="certificates" class="pt-2" />
                </template>
            </div>
        </div>

        <AppConfirmModal
            :open="resending !== null"
            title="Re-send this certificate?"
            :description="
                resending
                    ? `${resending.participant} will receive the certificate email again at ${resending.email}. The document itself is not re-issued — the same PDF is linked.`
                    : ''
            "
            confirm-label="Re-send email"
            :processing="processing"
            @confirm="resend"
            @close="resending = null"
        />
    </AuthenticatedLayout>
</template>
