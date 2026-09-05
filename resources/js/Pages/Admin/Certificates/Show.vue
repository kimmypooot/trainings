<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppStatTile from '@/Components/AppStatTile.vue';

const props = defineProps({
    certificate: { type: Object, required: true },
    verifications: { type: Array, required: true },
    can: { type: Object, required: true },
});

const resending = ref(false);
const processing = ref(false);

const resend = () => {
    processing.value = true;
    router.post(
        `/admin/certificates/${props.certificate.id}/resend`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
                resending.value = false;
            },
        }
    );
};

const copied = ref(false);
const copyVerifyUrl = async () => {
    await navigator.clipboard.writeText(props.certificate.verify_url);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
};

const details = [
    ['Participant', 'participant'],
    ['Email', 'email'],
    ['Training', 'training'],
    ['Issued', 'issued_at'],
    ['Emailed', 'email_sent_at'],
    ['Last Verified', 'last_verified_at'],
    ['Last Downloaded', 'last_downloaded_at'],
];
</script>

<template>
    <Head :title="certificate.number" />

    <AuthenticatedLayout title="Certificate" current="admin-certificates">
        <div class="mx-auto max-w-4xl space-y-5">
            <Link
                href="/admin/certificates"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-csc-blue hover:text-csc-blue-deep"
            >
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M15 6l-6 6 6 6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                Certificates
            </Link>

            <AppCard>
                <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <p class="font-mono text-sm font-semibold text-csc-blue">{{ certificate.number }}</p>
                        <p class="mt-1 text-lg font-semibold text-csc-ink">{{ certificate.participant }}</p>
                        <p class="mt-0.5 text-sm text-csc-ink-muted">{{ certificate.training }}</p>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <AppButton :href="certificate.download_url" external size="sm" variant="ghost" icon="download">
                                Download PDF
                            </AppButton>
                            <AppButton size="sm" variant="ghost" @click="copyVerifyUrl">
                                {{ copied ? 'Copied' : 'Copy verify link' }}
                            </AppButton>
                            <AppButton v-if="can.resend" size="sm" variant="ghost" icon="envelope" @click="resending = true">
                                Re-send Email
                            </AppButton>
                        </div>
                    </div>

                    <!--
                        The same code printed on the document, so staff can hold
                        the page next to a printout and confirm they are looking
                        at the same certificate.
                    -->
                    <figure class="shrink-0 text-center">
                        <img
                            :src="certificate.qr"
                            alt="QR code linking to this certificate's public verification page"
                            class="size-40 rounded-lg border border-csc-line bg-white p-2"
                        />
                        <figcaption class="mt-2 text-2xs text-csc-ink-subtle">Printed on the certificate</figcaption>
                    </figure>
                </div>
            </AppCard>

            <AppAlert v-if="!certificate.email_sent_at" tone="warning">
                This certificate has never been emailed to the participant.
            </AppAlert>

            <div class="grid grid-cols-2 gap-3">
                <AppStatTile label="Public Verifications" :value="certificate.verifications" icon="shield" />
                <AppStatTile label="Downloads" :value="certificate.downloads" icon="download" />
            </div>

            <AppCard title="Certificate Details">
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div v-for="[label, key] in details" :key="key">
                        <dt class="text-csc-ink-subtle">{{ label }}</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ certificate[key] ?? '—' }}</dd>
                    </div>
                </dl>
            </AppCard>

            <AppCard
                title="Verification History"
                subtitle="Every public lookup of this certificate, newest first. Capped at the last 100."
                :padded="verifications.length > 0"
            >
                <div v-if="verifications.length" class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-csc-line text-xs uppercase">
                            <tr>
                                <th scope="col" class="py-2 pr-4 font-semibold text-csc-ink-muted">When</th>
                                <th scope="col" class="py-2 pr-4 font-semibold text-csc-ink-muted">From</th>
                                <th scope="col" class="py-2 font-semibold text-csc-ink-muted">Client</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-csc-line">
                            <tr v-for="hit in verifications" :key="hit.id">
                                <td class="py-2.5 pr-4 whitespace-nowrap text-csc-ink-muted">{{ hit.verified_at }}</td>
                                <td class="py-2.5 pr-4 font-mono text-xs text-csc-ink-muted">
                                    {{ hit.ip_address ?? '—' }}
                                </td>
                                <td class="max-w-md truncate py-2.5 text-xs text-csc-ink-subtle" :title="hit.user_agent">
                                    {{ hit.user_agent ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <AppEmptyState
                    v-else
                    title="Never verified"
                    description="Nobody has looked this certificate up through the public verification page yet."
                    icon="qr"
                />
            </AppCard>
        </div>

        <AppConfirmModal
            :open="resending"
            title="Re-send this certificate?"
            :description="`${certificate.participant} will receive the certificate email again at ${certificate.email}. The document itself is not re-issued — the same PDF is linked.`"
            confirm-label="Re-send email"
            :processing="processing"
            @confirm="resend"
            @close="resending = false"
        />
    </AuthenticatedLayout>
</template>
