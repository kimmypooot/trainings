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
 * screens read as one thing seen at two lengths. It borrows the feed's grid
 * too now, once there is more than a handful of rows: a fifty-row history at
 * full width on a desktop was a single column of white space either side of
 * it, the one screen in the app that had no filter, no tiles, and nothing to
 * break up a plain vertical scroll.
 */
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppFilterChips from '@/Components/AppFilterChips.vue';
import AppIcon from '@/Components/AppIcon.vue';
import { tone } from '@/activityTone';
import { csrfToken } from '@/csrfToken';

const props = defineProps({
    notifications: { type: Array, required: true },
    /** Unread across the whole table, not just this page — see the controller. */
    unread: { type: Number, default: 0 },
    /** How many rows the page holds at most, so it can say so. */
    limit: { type: Number, default: 50 },
});

const markAllRead = () => router.post('/notifications/read', {}, { preserveScroll: true });

/*
 * Opening one notification is reading it. A plain fetch rather than
 * `router.post` — see AppNotificationsPopover's own copy of this same call
 * for why: the click is already navigating to the notification's own `url`,
 * and an Inertia visit here would compete with that rather than assist it.
 */
const markRead = (notification) => {
    if (notification.read) return;

    fetch(`/notifications/${notification.id}/read`, {
        method: 'POST',
        headers: { 'X-XSRF-TOKEN': csrfToken(), Accept: 'application/json' },
        credentials: 'same-origin',
    }).catch(() => {});
};

/*
 * The browser's Back button does not ask the server for anything.
 *
 * Inertia keeps a serialized snapshot of every page's props in
 * `history.state` and, on a popstate (the Back/Forward buttons), restores
 * that snapshot straight into the page rather than making a request — the
 * whole point being that going back is instant. Clicking a notification
 * marks it read through the plain `fetch()` above, entirely outside that
 * snapshot, so the copy Back restores still says `read: false`: correct at
 * the moment it was taken, stale the moment this page's own click mutated
 * the row it describes.
 *
 * A fresh partial reload on every mount corrects it — cheap (two small
 * fields, not the whole page) and unnoticeable on an ordinary visit, where
 * the answer it gets back is the answer already on screen.
 */
onMounted(() => {
    router.reload({ only: ['notifications', 'unread'] });
});

/*
 * All / Unread, client-side — nothing here is a server round trip, unlike
 * useFilters' callers: the whole set this chip strip narrows is already on
 * the page (at most `limit` rows), so a visit would only ask the server for
 * data it just sent.
 *
 * The Unread chip's count is taken from the rows actually on the page, not
 * from `unread` above. `unread` is the whole table's count, and it can run
 * ahead of what a chip filtering only the visible rows can ever show — a
 * participant with a long-unread notification outside the most recent
 * `limit` would see "Unread (12)" narrow the list to five, which reads as
 * seven notifications disappearing rather than as the limit the page already
 * discloses below.
 */
const filter = ref(null);

const unreadCount = computed(() => props.notifications.filter((notification) => !notification.read).length);

const filterOptions = computed(() => [{ value: 'unread', label: 'Unread', count: unreadCount.value }]);

const filtered = computed(() =>
    filter.value === 'unread' ? props.notifications.filter((notification) => !notification.read) : props.notifications
);

/*
 * Consecutive rows sharing a day band sit under one heading, exactly as they do
 * on the dashboard: the eye gets "Today" once rather than the same date stamped
 * on every row.
 *
 * Grouped by walking the list rather than by bucketing it, which keeps the
 * server's ordering intact — the bands are already contiguous because the rows
 * arrive newest first, and bucketing would quietly impose an order of its own
 * on the day that stops being true. Grouping runs over the filtered list, so
 * switching to Unread cannot leave a day heading standing over zero rows.
 */
const groups = computed(() => {
    const out = [];

    for (const notification of filtered.value) {
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

/*
 * The tone map gives a background/foreground pair for the icon chip
 * (`node`), tuned to sit on a white card. The row's left accent reuses the
 * same colour rather than a second map someone has to remember to update
 * alongside the first — the `text-*` class inside `node` is already that
 * colour, so this just aims a border utility at it.
 */
const accent = (kind) => {
    const textClass = tone(kind).node.split(' ').find((cls) => cls.startsWith('text-'));

    return textClass ? textClass.replace('text-', 'border-') : 'border-csc-line';
};
</script>

<template>
    <Head title="Notifications" />

    <AuthenticatedLayout title="Notifications" current="notifications">
        <div class="mx-auto max-w-7xl space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <p class="text-sm leading-relaxed text-csc-ink-muted">
                        <template v-if="unread">
                            <span class="font-semibold text-csc-ink">{{ unread }}</span>
                            unread {{ unread === 1 ? 'notification' : 'notifications' }}.
                        </template>
                        <template v-else-if="notifications.length">You are up to date.</template>
                    </p>

                    <AppFilterChips
                        v-if="notifications.length"
                        v-model="filter"
                        :options="filterOptions"
                        aria-label="Filter notifications"
                        allow-all
                        :all-count="notifications.length"
                    />
                </div>

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

            <AppCard v-else-if="!filtered.length" :padded="false">
                <AppEmptyState
                    title="No unread notifications"
                    description="You are caught up. Switch back to All to see your full history."
                    icon="check"
                >
                    <template #action>
                        <AppButton icon="close" @click="filter = null">Show All</AppButton>
                    </template>
                </AppEmptyState>
            </AppCard>

            <div v-else class="space-y-8">
                <section v-for="group in groups" :key="group.label">
                    <h2 class="mb-3 text-2xs font-semibold tracking-wider text-csc-ink-subtle uppercase">
                        {{ group.label }}
                    </h2>

                    <ul class="grid gap-3">
                        <!--
                            A dynamic tag rather than a bare card with a "View"
                            link tucked in the corner: this is the entire
                            row's job, so the whole card is the control, on
                            the same convention the dashboard's feed and the
                            header bell's popover both already use. A
                            notification with no target — one that only ever
                            announced something — renders as a plain,
                            unclickable card instead of an inert link.
                        -->
                        <li
                            v-for="notification in group.entries"
                            :key="notification.id"
                            class="h-full"
                        >
                            <component
                                :is="notification.url ? Link : 'div'"
                                v-bind="notification.url ? { href: notification.url } : {}"
                                class="flex h-full items-start gap-4 rounded-xl border border-l-4 p-4 transition-colors duration-150 sm:p-5"
                                :class="[
                                    accent(notification.kind),
                                    notification.read ? 'border-csc-line bg-white' : 'border-csc-blue/30 bg-info-soft',
                                    notification.url ? 'hover:border-csc-blue/50 hover:bg-csc-blue-tint' : '',
                                ]"
                                @click="notification.url && markRead(notification)"
                            >
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
                                    class="inline-flex size-11 shrink-0 items-center justify-center rounded-lg ring-1 ring-current/10 ring-inset"
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
                                </div>

                                <!--
                                    Relative inside the week and absolute
                                    beyond it, with the other form in the
                                    tooltip — the house rule, from the same
                                    FeedMoment that formats the dashboard.
                                    Sits in its own column on the row's
                                    trailing edge, centered against the row's
                                    full height rather than stacked under the
                                    body text.
                                -->
                                <time
                                    v-if="notification.at"
                                    :datetime="notification.at"
                                    :title="notification.at_exact"
                                    class="shrink-0 self-center text-right text-xs leading-4 whitespace-nowrap text-csc-ink-subtle"
                                >
                                    {{ notification.at_label }}
                                </time>

                                <AppIcon
                                    v-if="notification.url"
                                    name="chevron-right"
                                    size="sm"
                                    class="shrink-0 self-center text-csc-ink-subtle"
                                    aria-hidden="true"
                                />

                                <span v-if="!notification.read" class="sr-only">Unread</span>
                            </component>
                        </li>
                    </ul>
                </section>

                <p v-if="truncated && filter === null" class="text-center text-xs text-csc-ink-subtle">
                    Showing your {{ limit }} most recent notifications.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
