<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLogo from '@/Components/AppLogo.vue';
import AppAvatar from '@/Components/AppAvatar.vue';

const props = defineProps({
    title: { type: String, required: true },
    current: { type: String, required: true },
});

const page = usePage();
const user = computed(() => page.props.auth?.user ?? {});
const role = computed(() => user.value.role ?? 'participant');
const unread = computed(() => page.props.unreadNotifications ?? 0);

const ALL_ROLES = ['participant', 'field-office', 'admin', 'management', 'superadmin'];

/**
 * Nav is grouped by what the participant is trying to do, and every item
 * declares the roles allowed to see it — adding the staff areas later is a data
 * change here rather than a rewrite of the shell. `primary` marks the items
 * that earn a slot in the mobile tab bar.
 */
const STAFF_ROLES = ['field-office', 'admin', 'management', 'superadmin'];

const navGroups = [
    {
        key: 'overview',
        label: 'Overview',
        items: [
            {
                key: 'dashboard',
                label: 'Dashboard',
                href: '/dashboard',
                primary: true,
                roles: ['participant'],
                icon: 'M4 10.5 12 4l8 6.5V19a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z',
            },
            {
                key: 'admin-dashboard',
                label: 'Dashboard',
                href: '/admin',
                roles: STAFF_ROLES,
                icon: 'M4 10.5 12 4l8 6.5V19a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z',
            },
            {
                key: 'notifications',
                label: 'Notifications',
                href: '/notifications',
                roles: ALL_ROLES,
                icon: 'M6 9a6 6 0 1 1 12 0c0 4 1.5 5.5 1.5 5.5h-15S6 13 6 9ZM10 18.5a2 2 0 0 0 4 0',
            },
        ],
    },
    {
        key: 'training',
        label: 'Training',
        items: [
            {
                key: 'trainings',
                label: 'Browse Trainings',
                href: '/trainings',
                primary: true,
                roles: ['participant'],
                icon: 'M4 6h16M4 12h16M4 18h10',
            },
            {
                key: 'registrations',
                label: 'My Registrations',
                href: '/my/registrations',
                roles: ['participant'],
                icon: 'M7 4h10v16l-5-3-5 3z',
            },
            {
                key: 'certificates',
                label: 'Certificates',
                href: '/my/certificates',
                primary: true,
                roles: ['participant'],
                icon: 'M12 4a5 5 0 1 1 0 10 5 5 0 0 1 0-10ZM9 14.5V21l3-1.8 3 1.8v-6.5',
            },
            {
                key: 'payments',
                label: 'Payments',
                href: '/my/payments',
                roles: ['participant'],
                icon: 'M3 10h18M5 6h14a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z',
            },
            {
                key: 'training-requests',
                label: 'Request a Training',
                href: '/my/training-requests',
                roles: ['participant'],
                icon: 'M12 5v14M5 12h14',
            },
        ],
    },
    {
        key: 'core',
        label: 'Core Management',
        items: [
            {
                key: 'admin-trainings',
                label: 'Manage Trainings',
                href: '/admin/trainings',
                roles: ['admin', 'superadmin'],
                icon: 'M8 3v3M16 3v3M4 9h16M5 6h14a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z',
            },
            {
                key: 'admin-participants',
                label: 'Manage Participants',
                href: '/admin/participants',
                roles: STAFF_ROLES,
                icon: 'M3 20a6 6 0 0 1 12 0M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7M17 20a5 5 0 0 0-3-4.6M16 11a3 3 0 1 0 0-6',
            },
            {
                key: 'admin-field-offices',
                label: 'Field Offices',
                href: '/admin/field-offices',
                roles: ['admin', 'superadmin'],
                icon: 'M4 20h16M6 20V8l6-4 6 4v12M10 12h4',
            },
            {
                key: 'admin-analytics',
                label: 'Analytics',
                href: '/admin/analytics',
                roles: STAFF_ROLES,
                icon: 'M4 19V5m0 14h16M8 15V9m4 6v-3m4 3V7',
            },
            {
                key: 'admin-payments',
                label: 'Payments',
                href: '/admin/payments',
                roles: ['collecting-officer', 'admin', 'superadmin'],
                icon: 'M3 10h18M5 6h14a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z',
            },
            {
                key: 'admin-requests',
                label: 'Requests',
                href: '/admin/requests',
                roles: STAFF_ROLES,
                icon: 'M9 12h6m-6 4h6M9 8h6M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Z',
            },
            {
                key: 'admin-emails',
                label: 'Emails',
                href: '/admin/emails',
                roles: ['admin', 'superadmin'],
                icon: 'M3 7l9 6 9-6M3 7v10h18V7M3 7l9-4 9 4',
            },
            {
                key: 'admin-users',
                label: 'Users & Roles',
                href: '/admin/users',
                roles: ['superadmin'],
                icon: 'M3 20a6 6 0 0 1 12 0M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7M17 20a5 5 0 0 0-3-4.6M16 11a3 3 0 1 0 0-6',
            },
        ],
    },
    {
        key: 'account',
        label: 'Account',
        items: [
            {
                key: 'qr',
                label: 'My QR Code',
                href: '/my/qr',
                primary: true,
                highlight: true,
                roles: ['participant'],
                icon: 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 18h2v2h-2z',
            },
            {
                key: 'profile',
                label: 'My Profile',
                href: '/profile',
                roles: ALL_ROLES,
                icon: 'M4.5 20a7.5 7.5 0 0 1 15 0M12 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7',
            },
        ],
    },
];

const visibleGroups = computed(() =>
    navGroups
        .map((group) => ({ ...group, items: group.items.filter((item) => item.roles.includes(role.value)) }))
        .filter((group) => group.items.length)
);

const tabItems = computed(() =>
    visibleGroups.value
        .flatMap((group) => group.items)
        .filter((item) => item.primary)
        .slice(0, 4)
);

// Desktop: collapse the sidebar to an icon rail. Remembered between visits.
const STORAGE_KEY = 'csc_tims_sidebar_collapsed';
const collapsed = ref(false);

// Mobile: the same sidebar as an off-canvas drawer.
const drawerOpen = ref(false);
const accountOpen = ref(false);

// One control, two behaviours: collapse the rail on desktop, open the
// off-canvas drawer on mobile.
const toggleSidebar = () => {
    if (window.matchMedia('(min-width: 768px)').matches) {
        collapsed.value = !collapsed.value;
        localStorage.setItem(STORAGE_KEY, collapsed.value ? '1' : '0');

        return;
    }

    drawerOpen.value = !drawerOpen.value;
};

const onKeydown = (event) => {
    if (event.key === 'Escape') {
        drawerOpen.value = false;
        accountOpen.value = false;
    }
};

watch(drawerOpen, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});

let stopNavigateListener;

onMounted(() => {
    collapsed.value = localStorage.getItem(STORAGE_KEY) === '1';
    window.addEventListener('keydown', onKeydown);
    // Any navigation closes the transient surfaces.
    stopNavigateListener = router.on('navigate', () => {
        drawerOpen.value = false;
        accountOpen.value = false;
    });
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
    stopNavigateListener?.();
});

const signOut = () => router.post('/logout');
</script>

<template>
    <div class="min-h-screen bg-csc-blue-tint">
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-csc-blue"
        >
            Skip to content
        </a>

        <!-- Backdrop for the mobile drawer -->
        <div
            v-if="drawerOpen"
            class="fixed inset-0 z-30 bg-black/40 md:hidden"
            @click="drawerOpen = false"
        />

        <!--
            One sidebar serving three presentations: off-canvas drawer below md,
            icon rail when collapsed, full width when expanded.
        -->
        <aside
            id="app-sidebar"
            class="fixed inset-y-0 left-0 z-40 flex w-60 flex-col bg-csc-blue transition-[transform,width] duration-200 md:z-30 md:translate-x-0"
            :class="[
                drawerOpen ? 'translate-x-0' : '-translate-x-full',
                collapsed ? 'md:w-16' : 'md:w-60',
            ]"
        >
            <div
                class="flex h-16 items-center gap-2 border-b border-white/10 px-3"
                :class="collapsed ? 'md:justify-center md:px-0' : ''"
            >
                <Link
                    href="/dashboard"
                    class="rounded px-2 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white"
                >
                    <AppLogo variant="light" size="sm" :wordmark="!collapsed" />
                    <span class="sr-only">CSC TIMS dashboard</span>
                </Link>

                <button
                    type="button"
                    class="ml-auto inline-flex size-10 items-center justify-center rounded-lg text-white/70 transition-colors hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white md:hidden"
                    @click="drawerOpen = false"
                >
                    <span class="sr-only">Close menu</span>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
                    </svg>
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto p-3" aria-label="Main">
                <div v-for="(group, index) in visibleGroups" :key="group.key" :class="index ? 'mt-5' : ''">
                    <!-- Collapsed rail keeps the grouping as a rule, since labels have no room -->
                    <p
                        class="px-3 pb-2 text-[11px] font-semibold tracking-wider text-white/45 uppercase"
                        :class="collapsed ? 'md:hidden' : ''"
                    >
                        {{ group.label }}
                    </p>
                    <div v-if="index" class="mx-3 mb-2 hidden h-px bg-white/10" :class="collapsed ? 'md:block' : ''" />

                    <div class="space-y-1">
                        <Link
                            v-for="item in group.items"
                            :key="item.key"
                            :href="item.href"
                            class="group relative flex min-h-11 items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                            :class="[
                                props.current === item.key
                                    ? 'bg-white/15 text-white'
                                    : 'text-white/70 hover:bg-white/10 hover:text-white',
                                collapsed ? 'md:justify-center md:px-0' : '',
                            ]"
                            :aria-current="props.current === item.key ? 'page' : undefined"
                            :title="collapsed ? item.label : undefined"
                        >
                            <span
                                v-if="props.current === item.key"
                                class="absolute inset-y-1.5 left-0 w-1 rounded-r bg-white"
                                aria-hidden="true"
                            />
                            <span class="relative shrink-0">
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path :d="item.icon" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <!-- Rail has no room for the count, so it shows a dot -->
                                <span
                                    v-if="item.key === 'notifications' && unread && collapsed"
                                    class="absolute -top-0.5 -right-0.5 hidden size-2 rounded-full bg-danger ring-2 ring-csc-blue md:block"
                                    aria-hidden="true"
                                />
                            </span>
                            <span :class="collapsed ? 'md:hidden' : ''">{{ item.label }}</span>
                            <span
                                v-if="item.key === 'notifications' && unread"
                                class="ml-auto rounded-full bg-danger px-1.5 py-0.5 text-[11px] font-semibold text-white"
                                :class="collapsed ? 'md:hidden' : ''"
                            >
                                {{ unread > 99 ? '99+' : unread }}
                            </span>
                        </Link>
                    </div>
                </div>
            </nav>

            <div class="border-t border-white/10 p-3">
                <button
                    type="button"
                    class="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-white/70 transition-colors hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                    :class="collapsed ? 'md:justify-center md:px-0' : ''"
                    :title="collapsed ? 'Sign out' : undefined"
                    @click="signOut"
                >
                    <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M15 17l5-5-5-5M20 12H9M12 20H6a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span :class="collapsed ? 'md:hidden' : ''">Sign out</span>
                </button>
            </div>
        </aside>

        <!-- Content column shifts with the sidebar width -->
        <div class="transition-[padding] duration-200" :class="collapsed ? 'md:pl-16' : 'md:pl-60'">
            <header class="sticky top-0 z-20 border-b border-csc-line bg-white/95 backdrop-blur">
                <!-- Left padding stays tight so the toggle and title sit close to
                     the sidebar edge; the right side keeps normal breathing room. -->
                <div class="flex h-16 items-center justify-between gap-3 pr-4 pl-1.5 sm:pr-6 lg:pr-8">
                    <div class="flex min-w-0 items-center gap-2">
                        <!-- Collapses the rail on desktop, opens the drawer on mobile -->
                        <button
                            type="button"
                            class="inline-flex size-11 shrink-0 items-center justify-center rounded-lg text-csc-blue transition-colors hover:bg-csc-blue-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            :aria-expanded="drawerOpen || !collapsed"
                            aria-controls="app-sidebar"
                            @click="toggleSidebar"
                        >
                            <span class="sr-only">Toggle navigation menu</span>
                            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M4 7h16M4 12h16M4 17h16" stroke-linecap="round" />
                            </svg>
                        </button>

                        <h1 class="truncate text-lg font-semibold tracking-tight text-csc-blue">{{ title }}</h1>
                    </div>

                    <div class="flex items-center gap-1 sm:gap-2">
                        <Link
                            href="/notifications"
                            class="relative inline-flex size-11 items-center justify-center rounded-lg text-csc-ink transition-colors hover:bg-csc-blue-tint hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        >
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M6 9a6 6 0 1 1 12 0c0 4 1.5 5.5 1.5 5.5h-15S6 13 6 9ZM10 18.5a2 2 0 0 0 4 0" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <!-- Unread alert: a pulsing ring draws the eye, the count carries the detail -->
                            <template v-if="unread">
                                <span
                                    class="absolute top-1 right-1 inline-flex size-4 animate-ping rounded-full bg-danger/50"
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
                        </Link>

                        <div class="relative">
                            <button
                                type="button"
                                class="flex items-center gap-2 rounded-lg p-1.5 transition-colors hover:bg-csc-blue-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                :aria-expanded="accountOpen"
                                aria-haspopup="menu"
                                @click="accountOpen = !accountOpen"
                            >
                                <AppAvatar :name="user.name" :src="user.avatar" size="sm" />
                                <span class="hidden text-sm font-medium whitespace-nowrap text-csc-ink sm:inline">
                                    {{ user.name ?? user.email }}
                                </span>
                                <svg
                                    class="hidden size-4 text-csc-ink/50 transition-transform duration-150 sm:block"
                                    :class="accountOpen ? 'rotate-180' : ''"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    aria-hidden="true"
                                >
                                    <path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <span class="sr-only">Account menu</span>
                            </button>

                            <div
                                v-if="accountOpen"
                                class="absolute right-0 z-30 mt-2 w-56 overflow-hidden rounded-xl border border-csc-line bg-white shadow-lg"
                                role="menu"
                            >
                                <div class="border-b border-csc-line px-4 py-3">
                                    <p class="truncate text-sm font-semibold text-csc-ink">{{ user.name ?? '—' }}</p>
                                    <p class="truncate text-xs text-csc-ink/60">{{ user.email }}</p>
                                    <p class="mt-1.5 inline-block rounded-full bg-csc-blue-tint px-2 py-0.5 text-[11px] font-medium text-csc-blue">
                                        {{ user.role_label }}
                                    </p>
                                </div>
                                <Link href="/profile" class="block px-4 py-2.5 text-sm text-csc-ink transition-colors hover:bg-csc-blue-tint" role="menuitem">
                                    My Profile
                                </Link>
                                <button
                                    type="button"
                                    class="block w-full px-4 py-2.5 text-left text-sm text-danger transition-colors hover:bg-danger-soft"
                                    role="menuitem"
                                    @click="signOut"
                                >
                                    Sign out
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main id="main" class="px-4 py-6 pb-24 sm:px-6 md:pb-8 lg:px-8 lg:py-8">
                <slot />
            </main>
        </div>

        <!-- Mobile tab bar: the four most-used destinations, plus the full menu -->
        <nav
            class="fixed inset-x-0 bottom-0 z-20 border-t border-csc-line bg-white pb-[env(safe-area-inset-bottom)] md:hidden"
            aria-label="Primary"
        >
            <div class="grid grid-cols-5">
                <Link
                    v-for="item in tabItems"
                    :key="item.key"
                    :href="item.href"
                    class="flex min-h-14 flex-col items-center justify-center gap-1 px-1 py-2 text-[11px] font-medium transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-csc-blue"
                    :class="props.current === item.key ? 'text-csc-blue' : 'text-csc-ink/60'"
                    :aria-current="props.current === item.key ? 'page' : undefined"
                >
                    <span
                        v-if="item.highlight"
                        class="flex size-9 items-center justify-center rounded-full"
                        :class="props.current === item.key ? 'bg-csc-blue text-white' : 'bg-csc-blue-tint text-csc-blue'"
                    >
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path :d="item.icon" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                    <svg v-else class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path :d="item.icon" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    {{ item.label }}
                </Link>

                <button
                    type="button"
                    class="flex min-h-14 flex-col items-center justify-center gap-1 px-1 py-2 text-[11px] font-medium text-csc-ink/60 transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-csc-blue"
                    :aria-expanded="drawerOpen"
                    aria-controls="app-sidebar"
                    @click="drawerOpen = true"
                >
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M4 7h16M4 12h16M4 17h16" stroke-linecap="round" />
                    </svg>
                    Menu
                </button>
            </div>
        </nav>
    </div>
</template>
