<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

defineProps({
    trainings: { type: Object, required: true },
});

const page = usePage();
const flash = computed(() => page.props.flash?.success);
</script>

<template>
    <Head title="Trainings" />

    <AuthenticatedLayout title="Trainings" current="trainings">
        <div class="mx-auto max-w-5xl space-y-5">
            <AppAlert v-if="flash" tone="success">{{ flash }}</AppAlert>

            <p class="text-sm leading-relaxed text-csc-ink/70">
                Programs offered by the Civil Service Commission. Slots are taken on a first-come basis.
            </p>

            <div v-if="trainings.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <article
                    v-for="training in trainings.data"
                    :key="training.id"
                    class="flex flex-col overflow-hidden rounded-xl border border-csc-line bg-white transition-shadow duration-150 hover:shadow-md"
                >
                    <div class="flex items-start gap-4 p-5">
                        <!-- Date block reads faster than a formatted string in a grid -->
                        <div class="flex size-14 shrink-0 flex-col items-center justify-center rounded-lg bg-csc-blue text-white">
                            <span class="text-lg leading-none font-bold">{{ training.day }}</span>
                            <span class="mt-0.5 text-[11px] font-medium uppercase">{{ training.month }}</span>
                        </div>

                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm leading-snug font-semibold text-csc-blue">
                                <Link :href="training.url" class="hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue">
                                    {{ training.title }}
                                </Link>
                            </h2>
                            <p class="mt-1 text-xs text-csc-ink/60">{{ training.venue }}</p>
                            <p class="mt-0.5 text-xs text-csc-ink/60">{{ training.starts_at }}</p>
                        </div>
                    </div>

                    <div class="mt-auto flex items-center justify-between gap-2 border-t border-csc-line px-5 py-3">
                        <span v-if="training.is_registered" class="inline-flex items-center gap-1.5 text-xs font-semibold text-success">
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                <path d="M5 12.5l4.5 4.5L19 7.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            Registered
                        </span>
                        <span v-else-if="training.is_full" class="text-xs font-semibold text-danger">Full</span>
                        <span v-else-if="training.registration_closed" class="text-xs font-semibold text-csc-ink/50">
                            Registration closed
                        </span>
                        <span v-else-if="training.slots_remaining !== null" class="text-xs font-medium text-csc-ink/60">
                            {{ training.slots_remaining }} slot{{ training.slots_remaining === 1 ? '' : 's' }} left
                        </span>
                        <span v-else class="text-xs font-medium text-csc-ink/60">Open</span>

                        <Link
                            :href="training.url"
                            class="rounded text-xs font-semibold text-csc-blue transition-colors hover:text-csc-blue-deep focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        >
                            View Details
                        </Link>
                    </div>
                </article>
            </div>

            <AppCard v-else :padded="false">
                <AppEmptyState
                    title="No trainings available right now"
                    description="When the Commission publishes a new program, it will appear here."
                    icon="M8 3v3M16 3v3M4 9h16M5 6h14a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z"
                />
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
