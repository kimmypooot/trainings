<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppAvatar from '@/Components/AppAvatar.vue';
import AppFooter from '@/Components/AppFooter.vue';
import AppChangePasswordModal from '@/Components/AppChangePasswordModal.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppToast from '@/Components/AppToast.vue';
import AppModal from '@/Components/AppModal.vue';
import AppButton from '@/Components/AppButton.vue';
import AppAuthSplash from '@/Components/AppAuthSplash.vue';

const props = defineProps({
    title: { type: String, required: true },
    current: { type: String, required: true },
});

const page = usePage();
const user = computed(() => page.props.auth?.user ?? {});
const role = computed(() => user.value.role ?? 'participant');
const unread = computed(() => page.props.unreadNotifications ?? 0);
const pendingActions = computed(() => page.props.pendingActions ?? {});
// Staff pass straight through maintenance mode, so without a banner it can sit
// on for days unnoticed — see HandleInertiaRequests' maintenanceMode prop.
const maintenanceMode = computed(() => page.props.maintenanceMode ?? false);

// Notifications count comes from its own shared prop; every other badge is a
// pending action fed to the sidebar by key (see PendingActionCounter).
const countFor = (item) =>
    item.key === 'notifications' ? unread.value : (pendingActions.value[item.key] ?? 0);

const ALL_ROLES = [
    'participant',
    'field-office',
    'collecting-officer',
    'admin',
    'management',
    'superadmin',
];

/**
 * Nav is grouped by workflow — what the user is trying to get done — and
 * every item declares the roles allowed to see it, so adding staff areas
 * later is a data change here rather than a rewrite of the shell. `primary`
 * marks the items that earn a slot in the mobile tab bar.
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
                icon: 'home',
            },
            {
                key: 'admin-dashboard',
                label: 'Dashboard',
                href: '/admin',
                primary: true,
                // Collecting officers reach /admin too — they are staff, they
                // just have no business in the roster or participant directory,
                // which is why they are not in STAFF_ROLES.
                roles: [...STAFF_ROLES, 'collecting-officer'],
                icon: 'home',
            },
            {
                key: 'notifications',
                label: 'Notifications',
                href: '/notifications',
                roles: ALL_ROLES,
                icon: 'bell',
            },
        ],
    },
    {
        key: 'trainings',
        label: 'Trainings',
        items: [
            {
                key: 'trainings',
                label: 'Browse Trainings',
                href: '/trainings',
                primary: true,
                roles: ['participant'],
                icon: 'list',
            },
            {
                key: 'trainings-calendar',
                label: 'Calendar',
                href: '/trainings/calendar',
                roles: ['participant'],
                icon: 'calendar',
            },
            {
                key: 'admin-trainings',
                label: 'Manage Trainings',
                href: '/admin/trainings',
                roles: ['admin', 'superadmin'],
                icon: 'calendar',
            },
            {
                key: 'admin-participants',
                label: 'Manage Participants',
                href: '/admin/participants',
                primary: true,
                roles: STAFF_ROLES,
                icon: 'users',
            },
            {
                key: 'registrations',
                label: 'My Registrations',
                href: '/my/registrations',
                roles: ['participant'],
                icon: 'bookmark',
            },
            {
                key: 'certificates',
                label: 'Certificates',
                href: '/my/certificates',
                primary: true,
                roles: ['participant'],
                icon: 'certificate',
            },
            {
                key: 'admin-certificates',
                label: 'Certificates',
                href: '/admin/certificates',
                roles: STAFF_ROLES,
                icon: 'certificate',
            },
        ],
    },
    {
        key: 'payments',
        label: 'Payments & Fees',
        items: [
            {
                key: 'payments',
                label: 'Payments',
                href: '/my/payments',
                roles: ['participant'],
                icon: 'card',
            },
            {
                key: 'physical-or',
                label: 'Physical OR',
                href: '/my/physical-or',
                roles: ['participant'],
                icon: 'document',
            },
            {
                key: 'admin-payments',
                label: 'Payments',
                href: '/admin/payments',
                // Collecting is a designation, not a role, so this one item
                // cannot be decided from the role list alone — `designation`
                // names the shared auth flag that actually gates the routes.
                roles: STAFF_ROLES,
                designation: 'collects_payments',
                icon: 'card',
            },
            {
                key: 'admin-physical-or',
                label: 'Physical OR',
                href: '/admin/physical-or',
                roles: ['admin', 'superadmin'],
                icon: 'document',
            },
        ],
    },
    {
        key: 'requests',
        label: 'Requests',
        items: [
            {
                key: 'admin-requests',
                label: 'Requests',
                href: '/admin/requests',
                primary: true,
                roles: STAFF_ROLES,
                icon: 'document',
            },
            {
                key: 'admin-agency-requests',
                label: 'Agency Requests',
                href: '/admin/agency-requests',
                roles: ['admin', 'superadmin'],
                icon: 'building',
            },
            {
                key: 'training-requests',
                label: 'Suggest a Training',
                href: '/my/training-requests',
                roles: ['participant'],
                icon: 'plus',
            },
            {
                // Named for what it is, so it is not confused with the
                // suggestion box above: this one is a formal request from an
                // agency, with letters going both ways.
                key: 'agency-requests',
                label: 'Agency Requests',
                href: '/my/agency-requests',
                roles: ['participant'],
                icon: 'document',
            },
        ],
    },
    {
        key: 'administration',
        label: 'Administration',
        items: [
            {
                key: 'admin-field-offices',
                label: 'Field Offices',
                href: '/admin/field-offices',
                roles: ['admin', 'superadmin'],
                icon: 'building',
            },
            {
                key: 'admin-analytics',
                label: 'Analytics',
                href: '/admin/analytics',
                roles: STAFF_ROLES,
                icon: 'analytics',
            },
            {
                key: 'admin-emails',
                label: 'Emails',
                href: '/admin/emails',
                roles: ['admin', 'superadmin'],
                icon: 'envelope',
            },
            {
                key: 'admin-users',
                label: 'Users & Roles',
                href: '/admin/users',
                // HRD reads the directory; superadmin administers it. The page
                // drops its own controls for anyone who cannot.
                roles: ['admin', 'superadmin'],
                icon: 'users',
            },
            {
                key: 'admin-activity',
                label: 'Activity Log',
                href: '/admin/activity',
                roles: ['superadmin'],
                icon: 'shield',
            },
            {
                key: 'admin-maintenance',
                label: 'Maintenance Mode',
                href: '/admin/maintenance',
                roles: ['superadmin'],
                icon: 'settings',
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
                icon: 'qr',
            },
            {
                key: 'profile',
                label: 'My Profile',
                href: '/profile',
                roles: ALL_ROLES,
                icon: 'user',
            },
        ],
    },
];

const visibleGroups = computed(() =>
    navGroups
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) =>
                    item.roles.includes(role.value) &&
                    // An item may also require a designation the role does not
                    // carry — collecting payments is the one such duty.
                    (!item.designation || page.props.auth?.user?.[item.designation])
            ),
        }))
        .filter((group) => group.items.length)
);

const tabItems = computed(() =>
    visibleGroups.value
        .flatMap((group) => group.items)
        .filter((item) => item.primary)
        .slice(0, 4)
);

// The bar is the tabs plus the Menu button, and roles do not all have the same
// number of tabs — a fixed column count would strand staff in a one-fifth-wide
// bar with four empty cells.
const tabColumns = computed(() => `repeat(${tabItems.value.length + 1}, minmax(0, 1fr))`);

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

const accountRef = ref(null);

const onKeydown = (event) => {
    if (event.key === 'Escape') {
        drawerOpen.value = false;

        if (accountOpen.value) {
            accountOpen.value = false;
            // Escape should hand the keyboard back to the control that opened
            // the popover, not drop focus at the top of the document.
            accountRef.value?.querySelector('button')?.focus();
        }
    }
};

// A popover that only closes on Escape or a re-click strands itself over the
// content for anyone using a mouse.
const onPointerDown = (event) => {
    if (accountOpen.value && !accountRef.value?.contains(event.target)) {
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
    document.addEventListener('pointerdown', onPointerDown);
    // Any navigation closes the transient surfaces.
    stopNavigateListener = router.on('navigate', () => {
        drawerOpen.value = false;
        accountOpen.value = false;
    });
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    document.removeEventListener('pointerdown', onPointerDown);
    document.body.style.overflow = '';
    stopNavigateListener?.();
});

// Sign-out is two steps on purpose: a confirm dialog, then the branded splash
// while the session request is in flight. The splash lives in this layout so
// it is up before the POST starts and the whole shell unmounts on arrival.
const confirmingSignOut = ref(false);
const signingOut = ref(false);
const changePasswordOpen = ref(false);

const openChangePassword = () => {
    accountOpen.value = false;
    changePasswordOpen.value = true;
};

const signOut = () => {
    confirmingSignOut.value = true;
};

const confirmSignOut = () => {
    confirmingSignOut.value = false;
    signingOut.value = true;
    router.post('/logout');
};
</script>

<template>
    <div class="min-h-screen bg-csc-blue-tint">
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-(--z-skip-link) focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-csc-blue"
        >
            Skip to content
        </a>

        <!-- Backdrop for the mobile drawer -->
        <div
            v-if="drawerOpen"
            class="fixed inset-0 z-(--z-backdrop) bg-black/40 md:hidden"
            @click="drawerOpen = false"
        />

        <!--
            One sidebar serving three presentations: off-canvas drawer below md,
            icon rail when collapsed, full width when expanded.
        -->
        <aside
            id="app-sidebar"
            class="fixed inset-y-0 left-0 z-(--z-drawer) flex w-64 flex-col bg-csc-blue transition-[transform,width] duration-200 md:z-(--z-backdrop) md:translate-x-0"
            :class="[
                drawerOpen ? 'translate-x-0' : '-translate-x-full',
                collapsed ? 'md:w-16' : 'md:w-64',
            ]"
        >
            <div
                class="flex items-center gap-3 border-b border-white/10 px-5 py-5"
                :class="collapsed ? 'md:justify-center md:px-0' : ''"
            >
                <Link
                    href="/dashboard"
                    class="flex items-center gap-3 rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white"
                >
                    <img
                        src="/images/csc-logo.png"
                        alt="CSC Logo"
                        class="h-9 w-9 shrink-0 object-contain"
                    />
                    <span v-if="!collapsed" class="leading-tight">
                        <span class="block text-sm font-bold text-white">CSC RO VIII</span>
                        <span class="mt-0.5 block text-xs font-medium tracking-wide text-white/60">
                            TIMS
                        </span>
                    </span>
                    <span class="sr-only">CSC TIMS dashboard</span>
                </Link>

                <button
                    type="button"
                    class="ml-auto inline-flex size-10 items-center justify-center rounded-lg text-white/70 transition-colors hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white md:hidden"
                    @click="drawerOpen = false"
                >
                    <span class="sr-only">Close menu</span>
                    <AppIcon name="close" />
                </button>
            </div>

            <nav class="sidebar-nav min-h-0 flex-1 space-y-4 overflow-y-auto px-3 py-4" aria-label="Main">
                <div v-for="group in visibleGroups" :key="group.key">
                    <p
                        class="mb-1.5 px-3 text-[10px] font-bold tracking-widest text-white/40 uppercase"
                        :class="collapsed ? 'md:hidden' : ''"
                    >
                        {{ group.label }}
                    </p>

                    <div class="space-y-0.5">
                        <Link
                            v-for="item in group.items"
                            :key="item.key"
                            :href="item.href"
                            class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                            :class="[
                                props.current === item.key
                                    ? 'bg-white/15 text-white'
                                    : 'text-white/75 hover:bg-white/10 hover:text-white',
                                collapsed ? 'md:justify-center md:px-0' : '',
                            ]"
                            :aria-current="props.current === item.key ? 'page' : undefined"
                            :title="collapsed ? item.label : undefined"
                        >
                            <span class="relative shrink-0">
                                <AppIcon :name="item.icon" />
                                <!-- Rail has no room for the count, so it shows a dot -->
                                <span
                                    v-if="countFor(item) && collapsed"
                                    class="absolute -top-0.5 -right-0.5 hidden size-2 rounded-full bg-danger ring-2 ring-csc-blue md:block"
                                    aria-hidden="true"
                                />
                            </span>
                            <span :class="collapsed ? 'md:hidden' : ''">{{ item.label }}</span>
                            <span
                                v-if="countFor(item)"
                                class="ml-auto rounded-full bg-danger px-1.5 py-0.5 text-2xs font-semibold text-white"
                                :class="collapsed ? 'md:hidden' : ''"
                            >
                                {{ countFor(item) > 99 ? '99+' : countFor(item) }}
                            </span>
                        </Link>
                    </div>
                </div>
            </nav>

            <div class="border-t border-white/10 px-3 py-4">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-white/80 transition-colors hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                    :class="collapsed ? 'md:justify-center md:px-0' : ''"
                    :title="collapsed ? 'Sign out' : undefined"
                    @click="signOut"
                >
                    <AppIcon name="sign-out" class="shrink-0" />
                    <span :class="collapsed ? 'md:hidden' : ''">Sign out</span>
                </button>
            </div>
        </aside>

        <!-- Content column shifts with the sidebar width -->
        <div
            class="flex min-h-screen flex-col transition-[padding] duration-200"
            :class="collapsed ? 'md:pl-16' : 'md:pl-64'"
        >
            <header class="sticky top-0 z-(--z-header) border-b border-csc-line bg-white/95 backdrop-blur">
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
                            <AppIcon name="menu" size="lg" />
                        </button>

                        <h1 class="truncate text-lg font-semibold tracking-tight text-csc-blue">{{ title }}</h1>
                    </div>

                    <div class="flex items-center gap-1 sm:gap-2">
                        <Link
                            href="/notifications"
                            class="relative inline-flex size-11 items-center justify-center rounded-lg text-csc-ink transition-colors hover:bg-csc-blue-tint hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        >
                            <AppIcon name="bell" />
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

                        <div ref="accountRef" class="relative">
                            <button
                                type="button"
                                class="flex items-center gap-2 rounded-lg p-1.5 transition-colors hover:bg-csc-blue-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                :aria-expanded="accountOpen"
                                aria-controls="account-popover"
                                @click="accountOpen = !accountOpen"
                            >
                                <AppAvatar :name="user.name" :src="user.avatar" size="sm" />
                                <span class="hidden flex-col items-start sm:flex">
                                    <span class="text-sm leading-tight font-medium whitespace-nowrap text-csc-ink">
                                        {{ user.name ?? user.email }}
                                    </span>
                                    <span class="mt-0.5 rounded-full bg-csc-blue-tint px-2 py-0.5 text-2xs font-medium text-csc-blue">
                                        {{ user.role_label }}
                                    </span>
                                </span>
                                <AppIcon
                                    name="chevron-down"
                                    size="sm"
                                    class="hidden text-csc-ink/50 transition-transform duration-150 sm:block"
                                    :class="accountOpen ? 'rotate-180' : ''"
                                />
                                <span class="sr-only">Account menu</span>
                            </button>

                            <!--
                                Deliberately not role="menu": that ARIA pattern
                                promises arrow-key roving focus and type-ahead,
                                which two links do not implement. A plain
                                popover of links keeps Tab working as expected.
                            -->
                            <div
                                v-if="accountOpen"
                                id="account-popover"
                                class="absolute right-0 z-(--z-popover) mt-2 w-56 overflow-hidden rounded-xl border border-csc-line bg-white shadow-lg"
                            >
                                <div class="border-b border-csc-line px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
                                        <p class="text-xs text-csc-ink/60">{{ user.email }}</p>
                                        <span
                                            v-if="user.email_verified"
                                            class="inline-flex items-center gap-1 text-2xs font-semibold text-csc-blue"
                                        >
                                            <span class="inline-flex size-3 items-center justify-center rounded-full bg-csc-blue" aria-hidden="true">
                                                <svg
                                                    class="size-1.5 text-white"
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    stroke-width="3"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                >
                                                    <path d="M5 13l4 4L19 7" />
                                                </svg>
                                            </span>
                                            Verified
                                        </span>
                                        <span v-else class="text-2xs font-medium text-csc-ink/45">
                                            Not Verified
                                        </span>
                                    </div>
                                </div>
                                <Link
                                    href="/profile"
                                    class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-csc-ink transition-colors hover:bg-csc-blue-tint"
                                >
                                    <AppIcon name="user" class="shrink-0 text-csc-ink/50" />
                                    My Profile
                                </Link>
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2.5 px-4 py-2.5 text-left text-sm text-csc-ink transition-colors hover:bg-csc-blue-tint"
                                    @click="openChangePassword"
                                >
                                    <AppIcon name="lock" class="shrink-0 text-csc-ink/50" />
                                    <!--
                                        A Google-created account has no password
                                        to change yet — it has one to make. The
                                        label says which, so the item is never a
                                        form that can only fail.
                                    -->
                                    {{ user.has_password === false ? 'Create Password' : 'Change Password' }}
                                </button>
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2.5 border-t border-csc-line px-4 py-2.5 text-left text-sm text-danger transition-colors hover:bg-danger-soft"
                                    @click="signOut"
                                >
                                    <AppIcon name="sign-out" class="shrink-0" />
                                    Sign out
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!--
                Only staff see the app while maintenance is on, so the banner is
                how they remember the public site is still down.
            -->
            <div
                v-if="maintenanceMode"
                class="border-b border-warning/25 bg-warning-soft px-4 py-2.5 sm:px-6 lg:px-8"
                role="status"
            >
                <div class="mx-auto flex w-full max-w-7xl items-center gap-2 text-sm text-warning">
                    <AppIcon name="warning" class="shrink-0" />
                    <p class="min-w-0">
                        <span class="font-semibold">Maintenance mode is on.</span>
                        You can see this because you are staff — participants and visitors get a maintenance notice instead.
                        <Link href="/admin/maintenance" class="font-semibold underline hover:opacity-80">
                            Manage
                        </Link>
                    </p>
                </div>
            </div>

            <main id="main" class="flex-1 px-4 py-6 pb-24 sm:px-6 md:pb-8 lg:px-8 lg:py-8">
                <!-- Keyed by the page component so each navigation plays the
                     fade-and-rise once; see .page-enter in app.css. -->
                <Transition name="page" appear>
                    <div :key="page.component">
                        <slot />
                    </div>
                </Transition>
            </main>

            <AppFooter />

            <AppToast />
        </div>

        <!-- Mobile tab bar: the four most-used destinations, plus the full menu -->
        <nav
            class="fixed inset-x-0 bottom-0 z-(--z-tabbar) border-t border-csc-line bg-white pb-[env(safe-area-inset-bottom)] md:hidden"
            aria-label="Primary"
        >
            <div class="grid" :style="{ gridTemplateColumns: tabColumns }">
                <Link
                    v-for="item in tabItems"
                    :key="item.key"
                    :href="item.href"
                    class="flex min-h-14 flex-col items-center justify-center gap-1 px-1 py-2 text-2xs font-medium transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-csc-blue"
                    :class="props.current === item.key ? 'text-csc-blue' : 'text-csc-ink/60'"
                    :aria-current="props.current === item.key ? 'page' : undefined"
                >
                    <span
                        v-if="item.highlight"
                        class="flex size-9 items-center justify-center rounded-full"
                        :class="props.current === item.key ? 'bg-csc-blue text-white' : 'bg-csc-blue-tint text-csc-blue'"
                    >
                        <AppIcon :name="item.icon" />
                    </span>
                    <AppIcon v-else :name="item.icon" />
                    {{ item.label }}
                </Link>

                <button
                    type="button"
                    class="flex min-h-14 flex-col items-center justify-center gap-1 px-1 py-2 text-2xs font-medium text-csc-ink/60 transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-csc-blue"
                    :aria-expanded="drawerOpen"
                    aria-controls="app-sidebar"
                    @click="drawerOpen = true"
                >
                    <AppIcon name="menu" />
                    Menu
                </button>
            </div>
        </nav>

        <!-- Logout confirmation modal — centered body, no header chrome -->
        <AppModal
            :open="confirmingSignOut"
            size="sm"
            :hide-header="true"
            @close="confirmingSignOut = false"
        >
            <div class="text-center">
                <span class="mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-danger-soft text-danger">
                    <AppIcon name="sign-out" size="lg" />
                </span>
                <h3 class="text-base font-semibold tracking-tight text-csc-blue">Sign out</h3>
                <p class="mt-1 text-sm text-csc-ink/70">Are you sure you want to sign out of your account?</p>
                <div class="mt-6 flex gap-3">
                    <AppButton variant="ghost" block @click="confirmingSignOut = false">Cancel</AppButton>
                    <AppButton variant="accent" block @click="confirmSignOut">Sign out</AppButton>
                </div>
            </div>
        </AppModal>

        <!-- Branded splash while the logout request is in flight -->
        <AppAuthSplash :visible="signingOut">
            <p class="mb-1 text-xl font-semibold text-csc-blue">Signing you out</p>
            <p class="text-sm text-csc-ink/70">See you next time!</p>
        </AppAuthSplash>

        <!-- Rotating the password from the account menu -->
        <AppChangePasswordModal :open="changePasswordOpen" @close="changePasswordOpen = false" />
    </div>
</template>

<style scoped>
/*
 * The sidebar scrollbar wears the sidebar: a thin, translucent-white thumb so
 * the vertical scrolling reads as part of the blue surface instead of a stray
 * OS control. Mirrors the recruitment-system sidebar treatment.
 */
.sidebar-nav {
    scrollbar-width: thin;
    scrollbar-color: color-mix(in srgb, white 20%, transparent) transparent;
}

.sidebar-nav::-webkit-scrollbar {
    width: 5px;
}

.sidebar-nav::-webkit-scrollbar-track {
    background: transparent;
}

.sidebar-nav::-webkit-scrollbar-thumb {
    background: color-mix(in srgb, white 20%, transparent);
    border-radius: 3px;
}

.sidebar-nav::-webkit-scrollbar-thumb:hover {
    background: color-mix(in srgb, white 35%, transparent);
}
</style>
