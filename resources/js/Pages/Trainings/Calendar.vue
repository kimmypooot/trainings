<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppIcon from '@/Components/AppIcon.vue';

defineProps({
    month: { type: Object, required: true },
    weeks: { type: Array, required: true },
    trainings: { type: Array, required: true },
});

const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
</script>

<template>
    <Head :title="`Calendar — ${month.label}`" />

    <AuthenticatedLayout title="Learning &amp; Development Calendar" current="trainings-calendar">
        <div class="mx-auto max-w-6xl space-y-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-2">
                    <Link
                        :href="`/trainings/calendar?month=${month.previous}`"
                        class="rounded-lg border border-csc-line bg-white p-2 text-csc-blue transition-colors hover:border-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        aria-label="Previous month"
                    >
                        <AppIcon name="chevron-left" size="sm" />
                    </Link>
                    <h2 class="min-w-44 text-center text-lg font-semibold text-csc-blue">{{ month.label }}</h2>
                    <Link
                        :href="`/trainings/calendar?month=${month.next}`"
                        class="rounded-lg border border-csc-line bg-white p-2 text-csc-blue transition-colors hover:border-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        aria-label="Next month"
                    >
                        <AppIcon name="chevron-right" size="sm" />
                    </Link>
                    <Link
                        v-if="!month.is_current"
                        href="/trainings/calendar"
                        class="ml-1 rounded text-sm font-medium text-csc-blue hover:underline"
                    >
                        Today
                    </Link>
                </div>

                <AppButton href="/trainings" variant="ghost" size="sm" icon="list">Browse as a list</AppButton>
            </div>

            <!--
                The grid is the month at a glance. It carries titles only — a
                cell has no room for anything else — so the list below is the
                accessible, full-detail version rather than a duplicate.
            -->
            <AppCard :padded="false" class="hidden overflow-hidden md:block">
                <table class="w-full table-fixed border-collapse">
                    <caption class="sr-only">Trainings running in {{ month.label }}</caption>
                    <thead>
                        <tr class="border-b border-csc-line bg-csc-blue-tint/60">
                            <th
                                v-for="weekday in weekdays"
                                :key="weekday"
                                scope="col"
                                class="px-2 py-2.5 text-xs font-semibold text-csc-ink/70 uppercase"
                            >
                                {{ weekday }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(week, index) in weeks" :key="index" class="border-b border-csc-line last:border-0">
                            <td
                                v-for="day in week"
                                :key="day.date"
                                class="h-28 border-r border-csc-line align-top last:border-0"
                                :class="[
                                    day.in_month ? 'bg-white' : 'bg-csc-blue-tint/20',
                                    day.is_today ? 'ring-2 ring-inset ring-csc-blue' : '',
                                ]"
                            >
                                <div class="flex h-full flex-col gap-1 p-1.5">
                                    <span
                                        class="text-xs font-semibold"
                                        :class="[
                                            day.in_month ? 'text-csc-ink' : 'text-csc-ink/35',
                                            day.is_today ? 'text-csc-blue' : '',
                                        ]"
                                    >
                                        {{ day.day }}
                                    </span>

                                    <div class="min-h-0 flex-1 space-y-1 overflow-y-auto">
                                        <Link
                                            v-for="event in day.events"
                                            :key="event.id"
                                            :href="event.url"
                                            class="block truncate rounded px-1.5 py-0.5 text-2xs font-medium transition-colors"
                                            :class="
                                                event.is_registered
                                                    ? 'bg-success-soft text-success hover:bg-success-soft/80'
                                                    : 'bg-csc-blue-tint text-csc-blue hover:bg-csc-blue-tint/70'
                                            "
                                            :title="`${event.title}${event.is_registered ? ' — you are registered' : ''}`"
                                        >
                                            {{ event.title }}
                                        </Link>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </AppCard>

            <div class="hidden items-center gap-4 text-xs text-csc-ink/60 md:flex">
                <span class="flex items-center gap-1.5">
                    <span class="size-3 rounded bg-csc-blue-tint" aria-hidden="true" />
                    Open to register
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="size-3 rounded bg-success-soft" aria-hidden="true" />
                    You are registered
                </span>
            </div>

            <AppCard :title="`Running in ${month.label}`" :padded="trainings.length > 0">
                <ul v-if="trainings.length" class="divide-y divide-csc-line">
                    <li
                        v-for="training in trainings"
                        :key="training.id"
                        class="flex flex-wrap items-start justify-between gap-3 py-3.5"
                    >
                        <div class="min-w-0">
                            <Link :href="training.url" class="text-sm font-medium text-csc-blue hover:underline">
                                {{ training.title }}
                            </Link>
                            <p class="mt-0.5 text-xs text-csc-ink/60">
                                {{ training.starts_at }}<span v-if="training.ends_at"> – {{ training.ends_at }}</span>
                                <span v-if="training.venue"> · {{ training.venue }}</span>
                                · {{ training.mode_label }}
                            </p>
                        </div>
                        <span
                            v-if="training.is_registered"
                            class="shrink-0 rounded-full bg-success-soft px-2.5 py-1 text-xs font-semibold text-success"
                        >
                            Registered
                        </span>
                        <span
                            v-else-if="training.is_full"
                            class="shrink-0 rounded-full bg-warning-soft px-2.5 py-1 text-xs font-semibold text-warning"
                        >
                            Full
                        </span>
                    </li>
                </ul>

                <AppEmptyState
                    v-else
                    title="Nothing scheduled"
                    description="No trainings are running in this month. Try another month, or browse the full catalogue."
                    icon="calendar"
                />
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
