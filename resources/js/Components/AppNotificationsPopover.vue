<script setup>
/**
 * The header bell, as a preview rather than a destination.
 *
 * It used to be a plain link to /notifications — one click, one full page
 * load, to find out "did anything happen". This answers that from wherever
 * the participant already is, the way a social feed's bell does: a short,
 * fresh list in a popover, with "See previous notifications" handing off to
 * the full history (Notifications/Index) for anything this preview does not
 * hold.
 *
 * Self-contained on purpose, like AppGlobalSearch: its own open state, its
 * own outside-click and Escape handling, its own fetch. AuthenticatedLayout's
 * script is already long, and neither of these two header widgets needs
 * anything from it beyond the page it already shares globally.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';
import { tone } from '@/activityTone';
import { csrfToken } from '@/csrfToken';

defineProps({
    /** The active nav key, so the bell can show itself as "current" from the full list page. */
    current: { type: String, default: '' },
});

const page = usePage();

/*
 * The badge's count comes from this component's own fetch, not the shared
 * `unreadNotifications` Inertia prop — see NotificationController::recent()
 * for the full reasoning. In short: that prop goes stale the moment the
 * browser's Back button restores a page from Inertia's client-side history
 * cache rather than asking the server, which is exactly what happens right
 * after marking a notification read and navigating to it. `null` until the
 * first fetch resolves, so the badge shows the (possibly stale) shared prop
 * for that first instant rather than flashing to zero.
 */
const freshUnread = ref(null);
const unread = computed(() => freshUnread.value ?? page.props.unreadNotifications ?? 0);

const open = ref(false);
const loading = ref(false);
const loaded = ref(false);
const notifications = ref([]);
const rootRef = ref(null);

const hasResults = computed(() => notifications.value.length > 0);

const load = async () => {
    loading.value = true;

    try {
        const response = await fetch('/notifications/recent', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (response.ok) {
            const data = await response.json();
            notifications.value = data.notifications;
            freshUnread.value = data.unread;
        } else {
            notifications.value = [];
        }
    } catch (error) {
        notifications.value = [];
    } finally {
        loading.value = false;
        loaded.value = true;
    }
};

const toggle = () => {
    open.value = !open.value;
};

const close = () => {
    open.value = false;
};

/*
 * Opening a notification is reading it. A plain fetch rather than
 * `router.post` — the click is already navigating to the notification's own
 * `url` via the Link below, and an Inertia visit here would be a second,
 * competing visit to the page this click is *leaving*, not the one it is
 * going to. Fire-and-forget: the layout remounts on the next navigation
 * regardless, so there is no local state here worth waiting to update, and a
 * failed request should never be allowed to strand the click that triggered
 * it.
 */
const markRead = (notification) => {
    if (notification.read) return;

    fetch(`/notifications/${notification.id}/read`, {
        method: 'POST',
        headers: { 'X-XSRF-TOKEN': csrfToken(), Accept: 'application/json' },
        credentials: 'same-origin',
    }).catch(() => {});
};

const markAllRead = () => {
    router.post(
        '/notifications/read',
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                notifications.value = notifications.value.map((item) => ({ ...item, read: true }));
                // Set directly rather than left to re-derive from the shared
                // prop this same visit just refreshed: computed's ?? would
                // otherwise keep showing this component's own now-stale
                // fetched count ahead of the fresher one the visit just
                // brought back.
                freshUnread.value = 0;
            },
        }
    );
};

const onKeydown = (event) => {
    if (event.key === 'Escape' && open.value) {
        close();
        rootRef.value?.querySelector('button')?.focus();
    }
};

const onPointerDown = (event) => {
    if (open.value && !rootRef.value?.contains(event.target)) close();
};

let stopNavigateListener;

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    document.addEventListener('pointerdown', onPointerDown);
    // A notification's own link navigates away, at which point the popover
    // over the old page makes no sense open on the new one.
    stopNavigateListener = router.on('navigate', close);
    // Eager rather than gated behind the first open — see `freshUnread`
    // above. The badge has to be right before anyone has clicked the bell,
    // which means the fetch that corrects it cannot wait for that click.
    load();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    document.removeEventListener('pointerdown', onPointerDown);
    stopNavigateListener?.();
});
</script>

<template>
    <div ref="rootRef" class="relative">
        <button
            type="button"
            class="relative inline-flex size-11 items-center justify-center rounded-lg transition-colors hover:bg-csc-blue-tint hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
            :class="open || current === 'notifications' ? 'bg-csc-blue-tint text-csc-blue' : 'text-csc-ink'"
            :aria-expanded="open"
            aria-controls="notifications-popover"
            @click="toggle"
        >
            <AppIcon name="bell" />
            <!-- Unread alert: a pulsing ring draws the eye, the count carries the detail -->
            <template v-if="unread">
                <!--
                    Keyed on the count so the pulse replays when the number
                    actually changes. It used to be animate-ping, which is
                    infinite: a permanent attention-grab for a figure that was
                    not moving. Three beats, then it rests.
                -->
                <span
                    :key="unread"
                    class="bell-ping absolute top-1 right-1 inline-flex size-4 rounded-full bg-danger/50"
                    aria-hidden="true"
                />
                <span
                    class="absolute top-1 right-1 inline-flex min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold text-white ring-2 ring-white"
                    aria-hidden="true"
                >
                    {{ unread > 9 ? '9+' : unread }}
                </span>
            </template>
            <span class="sr-only" aria-live="polite">
                Notifications{{ unread ? ` — ${unread} unread` : '' }}
            </span>
        </button>

        <div
            v-if="open"
            id="notifications-popover"
            class="absolute top-full right-0 z-(--z-popover) mt-2 w-80 overflow-hidden rounded-xl border border-csc-line bg-white shadow-lg sm:w-96"
            role="dialog"
            aria-label="Notifications"
        >
            <div class="flex items-center justify-between border-b border-csc-line px-4 py-2.5">
                <p class="text-sm font-semibold text-csc-ink">Notifications</p>
                <button
                    v-if="unread > 0"
                    type="button"
                    class="rounded text-xs font-semibold text-csc-blue transition-colors hover:text-csc-blue-deep focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    @click="markAllRead"
                >
                    Mark all as read
                </button>
            </div>

            <div class="max-h-96 overflow-y-auto">
                <p v-if="loading" class="px-4 py-8 text-center text-sm text-csc-ink-subtle">Loading…</p>

                <p v-else-if="!hasResults" class="px-4 py-8 text-center text-sm text-csc-ink-subtle">
                    Nothing here yet.
                </p>

                <ul v-else class="divide-y divide-csc-line">
                    <!--
                        A dynamic tag rather than duplicating the row twice: a
                        notification with no target (an older row, or one that
                        only ever announced something) renders as a plain
                        `div` — an inert Link with no href would be a broken
                        control rather than a plain row.
                    -->
                    <li v-for="notification in notifications" :key="notification.id">
                        <component
                            :is="notification.url ? Link : 'div'"
                            v-bind="notification.url ? { href: notification.url } : {}"
                            class="flex items-start gap-3 px-4 py-3 text-left transition-colors"
                            :class="[notification.url ? 'hover:bg-csc-blue-tint/60' : '', notification.read ? '' : 'bg-info-soft']"
                            @click="notification.url && markRead(notification)"
                        >
                            <span
                                class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg ring-1 ring-current/10 ring-inset"
                                :class="tone(notification.kind).node"
                                aria-hidden="true"
                            >
                                <AppIcon :name="tone(notification.kind).icon" size="sm" />
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="flex items-start gap-1.5">
                                    <span class="min-w-0 flex-1 truncate text-sm font-semibold text-csc-ink">
                                        {{ notification.title }}
                                    </span>
                                    <span
                                        v-if="!notification.read"
                                        class="mt-1.5 size-2 shrink-0 rounded-full bg-csc-blue"
                                        aria-hidden="true"
                                    />
                                </span>
                                <span v-if="notification.body" class="mt-0.5 line-clamp-2 block text-xs text-csc-ink-muted">
                                    {{ notification.body }}
                                </span>
                                <time
                                    v-if="notification.at"
                                    :datetime="notification.at"
                                    :title="notification.at_exact"
                                    class="mt-1 block text-2xs text-csc-ink-subtle"
                                >
                                    {{ notification.at_label }}
                                </time>
                            </span>
                        </component>
                    </li>
                </ul>
            </div>

            <Link
                href="/notifications"
                class="block border-t border-csc-line px-4 py-2.5 text-center text-xs font-semibold text-csc-blue transition-colors hover:bg-csc-blue-tint"
            >
                See previous notifications
            </Link>
        </div>
    </div>
</template>

<style scoped>
/*
 * Tailwind's animate-ping is infinite. The unread badge only has news to
 * break when the count changes, so this is the same motion bounded to three
 * beats; the element is keyed on the count, which replays it on the next
 * arrival. The global prefers-reduced-motion block in app.css already
 * neutralises it.
 */
.bell-ping {
    animation: bell-ping 1s cubic-bezier(0, 0, 0.2, 1) 3;
}

@keyframes bell-ping {
    75%,
    100% {
        transform: scale(2);
        opacity: 0;
    }
}
</style>
