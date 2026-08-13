<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

defineProps({
    awaitingRelease: { type: Array, required: true },
    released: { type: Array, required: true },
});
</script>

<template>
    <Head title="Certificates" />

    <AuthenticatedLayout title="Certificates" current="certificates">
        <div class="mx-auto max-w-3xl space-y-5">
            <p class="text-sm leading-relaxed text-csc-ink/70">
                Certificates are issued by the Civil Service Commission after a training is completed. They appear
                here once released.
            </p>

            <!-- Released -->
            <section v-if="released.length">
                <h2 class="mb-3 text-sm font-semibold tracking-wide text-csc-ink/60 uppercase">Available</h2>
                <ul class="space-y-3">
                    <li
                        v-for="certificate in released"
                        :key="certificate.id"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-csc-line bg-white p-4 sm:p-5"
                    >
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold text-csc-blue">{{ certificate.title }}</h3>
                            <p class="mt-1 text-xs text-csc-ink/60">
                                No. {{ certificate.number }} · Issued {{ certificate.issued_at }}
                            </p>
                            <a
                                :href="certificate.verify_url"
                                class="mt-1 inline-block rounded text-xs font-medium text-csc-blue underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            >
                                Public verification link
                            </a>
                        </div>
                        <AppButton :href="certificate.url" size="sm">Download</AppButton>
                    </li>
                </ul>
            </section>

            <!-- Completed, not yet released -->
            <section v-if="awaitingRelease.length">
                <h2 class="mb-3 text-sm font-semibold tracking-wide text-csc-ink/60 uppercase">Being Processed</h2>
                <ul class="space-y-3">
                    <li
                        v-for="item in awaitingRelease"
                        :key="item.id"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-csc-line bg-white p-4 sm:p-5"
                    >
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold text-csc-ink">{{ item.title }}</h3>
                            <p class="mt-1 text-xs text-csc-ink/60">Completed {{ item.completed_at }}</p>
                        </div>
                        <AppBadge status="processing" label="Awaiting release" />
                    </li>
                </ul>
            </section>

            <AppCard v-if="!released.length && !awaitingRelease.length" :padded="false">
                <AppEmptyState
                    title="No certificates yet"
                    description="Complete a training and your certificate will appear here once the Commission releases it."
                    icon="certificate"
                >
                    <template #action>
                        <AppButton href="/trainings">Browse Trainings</AppButton>
                    </template>
                </AppEmptyState>
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
