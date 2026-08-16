<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppPagination from '@/Components/AppPagination.vue';
import AppStat from '@/Components/AppStat.vue';

const props = defineProps({
    certificates: { type: Object, required: true },
    filters: { type: Object, required: true },
    stats: { type: Object, required: true },
    trainings: { type: Array, default: () => [] },
    can: { type: Object, required: true },
    scopedTo: { type: String, default: null },
});

const page = usePage();
const error = computed(() => page.props.errors?.certificate);

const search = ref(props.filters.search ?? '');
const training = ref(props.filters.training ?? '');
const emailed = ref(props.filters.emailed ?? '');

let debounce;
watch([search, training, emailed], () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            '/admin/certificates',
            {
                search: search.value || undefined,
                training: training.value || undefined,
                emailed: emailed.value || undefined,
                page: 1,
            },
            { preserveState: true, replace: true }
        );
    }, 300);
});

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
                <AppStat label="Issued" :value="stats.total" />
                <AppStat label="Issued This Year" :value="stats.this_year" />
                <AppStat label="Not Yet Emailed" :value="stats.not_emailed" />
                <AppStat label="Public Verifications" :value="stats.verifications" />
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search by number, participant, or training…"
                    aria-label="Search certificates"
                    class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue xl:col-span-2"
                />
                <select
                    v-model="training"
                    aria-label="Filter by training"
                    class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                >
                    <option value="">All trainings</option>
                    <option v-for="option in trainings" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
                <select
                    v-model="emailed"
                    aria-label="Filter by delivery"
                    class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                >
                    <option value="">Emailed or not</option>
                    <option value="1">Emailed</option>
                    <option value="0">Not yet emailed</option>
                </select>
            </div>

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
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Certificate</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Participant</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Training</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Delivery</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Activity</th>
                                <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink/70">Actions</th>
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
                                    <p class="mt-0.5 text-xs text-csc-ink/60">Issued {{ certificate.issued_at }}</p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <p class="font-medium text-csc-ink">{{ certificate.participant }}</p>
                                    <p class="mt-0.5 text-xs text-csc-ink/60">{{ certificate.email }}</p>
                                </td>
                                <td class="px-5 py-3.5 text-csc-ink/75">{{ certificate.training }}</td>
                                <td class="px-5 py-3.5 text-xs">
                                    <span v-if="certificate.email_sent_at" class="text-csc-ink/70">
                                        Emailed {{ certificate.email_sent_at }}
                                    </span>
                                    <span v-else class="font-medium text-warning">Not yet emailed</span>
                                </td>
                                <td class="px-5 py-3.5 text-xs text-csc-ink/70">
                                    {{ certificate.verifications }} verification{{
                                        certificate.verifications === 1 ? '' : 's'
                                    }}
                                    <p class="mt-0.5 text-csc-ink/55">{{ certificate.downloads }} download(s)</p>
                                    <p v-if="certificate.last_verified_at" class="mt-0.5 text-csc-ink/55">
                                        Last {{ certificate.last_verified_at }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                    <a
                                        :href="certificate.download_url"
                                        class="text-xs font-semibold text-csc-blue hover:underline"
                                    >
                                        Download
                                    </a>
                                    <span class="px-2 text-csc-line">|</span>
                                    <button
                                        type="button"
                                        class="rounded text-xs font-semibold text-csc-blue hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                        @click="copyVerifyUrl(certificate)"
                                    >
                                        {{ copied === certificate.id ? 'Copied' : 'Copy verify link' }}
                                    </button>
                                    <template v-if="can.resend">
                                        <span class="px-2 text-csc-line">|</span>
                                        <button
                                            type="button"
                                            class="rounded text-xs font-semibold text-csc-blue hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                            @click="resending = certificate"
                                        >
                                            Re-send
                                        </button>
                                    </template>
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
                        <p class="mt-0.5 text-xs text-csc-ink/60">{{ certificate.training }}</p>
                        <p class="mt-2 text-xs text-csc-ink/60">
                            Issued {{ certificate.issued_at }} ·
                            {{ certificate.verifications }} verification(s)
                        </p>
                        <p v-if="!certificate.email_sent_at" class="mt-1 text-xs font-medium text-warning">
                            Not yet emailed
                        </p>

                        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-2 border-t border-csc-line pt-3">
                            <a
                                :href="certificate.download_url"
                                class="text-xs font-semibold text-csc-blue hover:underline"
                            >
                                Download
                            </a>
                            <button
                                v-if="can.resend"
                                type="button"
                                class="rounded text-xs font-semibold text-csc-blue hover:underline"
                                @click="resending = certificate"
                            >
                                Re-send
                            </button>
                        </div>
                    </li>
                </ul>

                <AppPagination :pagination="certificates" label="certificates" class="pt-2" />
            </template>
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
