<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    awaitingRelease: { type: Array, required: true },
    released: { type: Array, required: true },
});

/*
 * A released certificate is the end of the whole participant journey — the
 * training was attended, the completion credited, the document signed and
 * issued by the Commission. Rendering that as another row in a list undersells
 * it, so the released ones are cards and the pending ones stay rows. The
 * contrast is the point: it is what makes "Earned" read as an achievement
 * rather than as a filter tab.
 */
const earnedLine = computed(() => {
    const count = props.released.length;

    if (!count) return null;

    return `${count} ${count === 1 ? 'certificate' : 'certificates'} earned`;
});
</script>

<template>
    <Head title="Certificates" />

    <AuthenticatedLayout title="Certificates" current="certificates">
        <div class="mx-auto max-w-4xl space-y-5">
            <p class="text-sm leading-relaxed text-csc-ink/70">
                Certificates are issued by the Civil Service Commission after a training is completed. They appear
                here once released.
            </p>

            <!-- Released -->
            <section v-if="released.length">
                <h2 class="mb-3 text-sm font-semibold tracking-wide text-csc-ink/60 uppercase">
                    Earned
                    <span class="ml-1 font-normal normal-case text-csc-ink/45">· {{ earnedLine }}</span>
                </h2>

                <ul class="grid gap-4 sm:grid-cols-2">
                    <li
                        v-for="certificate in released"
                        :key="certificate.id"
                        class="relative flex flex-col overflow-hidden rounded-2xl border border-csc-line bg-white shadow-sm"
                    >
                        <!--
                            The same gradient rule the catalogue card carries, so
                            a participant meets one visual language across the
                            public and signed-in sides of the app.
                        -->
                        <span
                            class="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-csc-blue to-csc-blue-deep"
                            aria-hidden="true"
                        />

                        <div class="flex flex-1 flex-col p-6">
                            <!--
                                Green rather than brand blue: the dashboard feed
                                already tiles a certificate event in the success
                                tone, and a participant should meet one colour
                                for "this is finished" wherever it appears.
                            -->
                            <span
                                class="inline-flex size-12 shrink-0 items-center justify-center rounded-full bg-success-soft text-success"
                            >
                                <AppIcon name="certificate" size="lg" />
                            </span>

                            <h3 class="mt-4 text-base leading-snug font-semibold text-csc-blue">
                                <a
                                    :href="certificate.training_url"
                                    class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                >
                                    {{ certificate.title }}
                                </a>
                            </h3>

                            <!--
                                The number is the part a participant is asked to
                                quote to an HR office, so it gets its own line
                                and tabular figures rather than being folded into
                                a middot-separated meta string.
                            -->
                            <dl class="mt-4 space-y-2 text-sm">
                                <div class="flex items-baseline justify-between gap-3">
                                    <dt class="text-csc-ink/55">Certificate No.</dt>
                                    <dd class="font-semibold tracking-wide text-csc-ink tabular-nums">
                                        {{ certificate.number }}
                                    </dd>
                                </div>
                                <div class="flex items-baseline justify-between gap-3">
                                    <dt class="text-csc-ink/55">Issued</dt>
                                    <dd class="font-medium text-csc-ink">{{ certificate.issued_at }}</dd>
                                </div>
                            </dl>

                            <!-- mt-auto so the actions line up across a row of cards whose titles wrap to different heights -->
                            <div class="mt-auto flex flex-col gap-3 pt-6">
                                <AppButton :href="certificate.url" external size="md" icon="download" block>
                                    Download
                                </AppButton>

                                <!--
                                    Verification is a genuine feature an employer
                                    may be pointed at, but it is not what the
                                    participant came here to do — it sits below
                                    the download, quiet and still reachable.
                                -->
                                <a
                                    :href="certificate.verify_url"
                                    class="inline-flex items-center justify-center gap-1.5 rounded text-xs font-medium text-csc-ink/60 transition-colors hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                >
                                    <AppIcon name="shield" size="sm" />
                                    Public verification link
                                </a>
                            </div>
                        </div>
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
                            <h3 class="text-sm font-semibold text-csc-ink">
                                <a
                                    :href="item.url"
                                    class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                >
                                    {{ item.title }}
                                </a>
                            </h3>
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
                        <AppButton href="/trainings" icon="calendar">Browse Trainings</AppButton>
                    </template>
                </AppEmptyState>
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
