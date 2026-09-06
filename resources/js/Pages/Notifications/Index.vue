<script setup>
/**
 * Everything that has happened to this participant, newest first.
 *
 * This is the same set of events the dashboard's activity feed draws, and it
 * used to be the screen that said least about them: fifty identically bordered
 * boxes, distinguished only by an unread tint, with the timestamp as a bare
 * "06 Sep 2026, 9:14 AM" on every row. A participant opens this page *because*
 * something happened, so it is the last place that should make them read every
 * row to find out what.
 *
 * It borrows the feed's vocabulary rather than inventing a second one — the
 * semantic icon tile per kind (activityTone.ts) and the day bands — so the two
 * screens read as one thing seen at two lengths. What it does not borrow is the
 * feed's two-up grid: the feed shows the five most recent as tiles to be
 * glanced over, while this is a fifty-row history to be scanned down, and a
 * scan wants one column.
 */
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { tone } from '@/activityTone';

const props = defineProps({
    notifications: { type: Array, required: true },
    /** Unread across the whole table, not just this page — see the controller. */
    unread: { type: Number, default: 0 },
    /** How many rows the page holds at most, so it can say so. */
    limit: { type: Number, default: 50 },
});

const markAllRead = () => router.post('/notifications/read', {}, { preserveScroll: true });

/*
 * Consecutive rows sharing a day band sit under one heading, exactly as they do
 * on the dashboard: the eye gets "Today" once rather than the same date stamped
 * on every row.
 *
 * Grouped by walking the list rather than by bucketing it, which keeps the
 * server's ordering intact — the bands are already contiguous because the rows
 * arrive newest first, and bucketing would quietly impose an order of its own
 * on the day that stops being true.
 */
const groups = computed(() => {
    const out = [];

    for (const notification of props.notifications) {
        const last = out.at(-1);
        const label = notification.group ?? 'Earlier';

        if (last?.label === label) {
            last.entries.push(notification);
            continue;
        }

        out.push({ label, entries: [notification] });
    }

    return out;
});

// Said only when the list is actually full. "Showing the latest 50" above six
// rows is a caveat about a limit nobody has reached.
const truncated = computed(() => props.notifications.length >= props.limit);
</script>

<template>
    <Head title="Notifications" />

    <AuthenticatedLayout title="Notifications" current="notifications">
        <div class="mx-auto max-w-4xl space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm leading-relaxed text-csc-ink-muted">
                    <template v-if="unread">
                        <span class="font-semibold text-csc-ink">{{ unread }}</span>
                        unread {{ unread === 1 ? 'notification' : 'notifications' }}.
                    </template>
                    <template v-else-if="notifications.length">You are up to date.</template>
                </p>

                <AppButton v-if="unread > 0" variant="ghost" size="sm" icon="check" @click="markAllRead">
                    Mark All as Read
                </AppButton>
            </div>

            <AppCard v-if="!notifications.length" :padded="false">
                <AppEmptyState
                    title="No notifications"
                    description="Updates about your registrations, trainings, and certificates will appear here."
                    icon="bell"
                />
            </AppCard>

            <div v-else class="space-y-8">
                <section v-for="group in groups" :key="group.label">
                    <h2 class="mb-3 text-2xs font-semibold tracking-wider text-csc-ink-subtle uppercase">
                        {{ group.label }}
                    </h2>

                    <ul class="space-y-3">
                        <!--
                            The background belongs in the conditional, both
                            halves of it. It used to be a static `bg-white` on
                            the row with `bg-info-soft` added here for an unread
                            one, which is two utilities setting one property:
                            Tailwind emits both and the cascade picks by source
                            order, not by which the template meant. White won,
                            so the unread tint had never rendered on this page
                            at all — the state was carried by the border and the
                            dot alone.
                        -->
                        <li
                            v-for="notification in group.entries"
                            :key="notification.id"
                            class="rounded-xl border p-4 transition-colors duration-150 sm:p-5"
                            :class="
                                notification.read
                                    ? 'border-csc-line bg-white'
                                    : 'border-csc-blue/30 bg-info-soft'
                            "
                        >
                            <div class="flex items-start gap-4">
                                <!--
                                    The same chip the dashboard feed draws, from
                                    the same map. Decorative: the title beside
                                    it is the content, and an unread row is
                                    announced by the visually-hidden label at
                                    the end of the row rather than by a colour.

                                    The inset ring is what the feed does not
                                    need. There, every card is white and each
                                    chip's own tint is its edge; here an unread
                                    row is itself tinted, and the two blue kinds
                                    — `registered` on csc-blue-tint, `approved`
                                    and `payment` on info-soft — are close
                                    enough to that tint to dissolve into it. The
                                    glyph stayed legible, the chip stopped
                                    reading as a chip, and it happened only on
                                    unread rows, which are the ones being
                                    scanned. A ring in the chip's own colour
                                    restores the edge without touching the tone.
                                -->
                                <span
                                    class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg ring-1 ring-current/10 ring-inset"
                                    :class="tone(notification.kind).node"
                                    aria-hidden="true"
                                >
                                    <AppIcon :name="tone(notification.kind).icon" />
                                </span>

                                <div class="min-w-0 flex-1">
                                    <h3 class="flex items-start gap-2 text-sm leading-5 font-semibold text-csc-blue">
                                        <span class="min-w-0">{{ notification.title }}</span>
                                        <!--
                                            The unread dot sits with the title
                                            rather than out at the row's leading
                                            edge, where it was competing with
                                            nothing and read as a bullet.
                                        -->
                                        <span
                                            v-if="!notification.read"
                                            class="mt-1.5 size-2 shrink-0 rounded-full bg-csc-blue"
                                            aria-hidden="true"
                                        />
                                    </h3>

                                    <p
                                        v-if="notification.body"
                                        class="mt-1 text-sm leading-relaxed text-csc-ink-muted"
                                    >
                                        {{ notification.body }}
                                    </p>

                                    <!--
                                        Relative inside the week and absolute
                                        beyond it, with the other form in the
                                        tooltip — the house rule, from the same
                                        FeedMoment that formats the dashboard.
                                    -->
                                    <time
                                        v-if="notification.at"
                                        :datetime="notification.at"
                                        :title="notification.at_exact"
                                        class="mt-2 block text-xs leading-4 text-csc-ink-subtle"
                                    >
                                        {{ notification.at_label }}
                                    </time>

                                    <Link
                                        v-if="notification.url"
                                        :href="notification.url"
                                        class="mt-2 inline-flex items-center gap-1.5 rounded text-xs font-semibold text-csc-blue transition-colors hover:text-csc-blue-deep focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                    >
                                        View
                                        <AppIcon name="chevron-right" size="sm" />
                                    </Link>
                                </div>

                                <span v-if="!notification.read" class="sr-only">Unread</span>
                            </div>
                        </li>
                    </ul>
                </section>

                <p v-if="truncated" class="text-center text-xs text-csc-ink-subtle">
                    Showing your {{ limit }} most recent notifications.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
