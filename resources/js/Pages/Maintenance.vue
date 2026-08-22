<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';

defineProps({
    /**
     * Whether a signed-in user is looking at this. Passed directly by
     * EnsureSiteIsAvailable — the middleware runs before Inertia's shared props
     * are assembled, so `auth.user` is not available on this page.
     */
    authenticated: { type: Boolean, default: false },
    // The optional message the superadmin wrote on the maintenance switch.
    // Absent on a direct visit to /maintenance, and the page has its own copy.
    message: { type: String, default: null },
    /**
     * Office contact details, passed explicitly for the same reason as
     * `authenticated`: no shared props exist this early in the pipeline. The
     * default keeps a direct visit to /maintenance from rendering "undefined"
     * where an email address should be.
     */
    office: {
        type: Object,
        default: () => ({ name: 'Civil Service Commission', email: null, phone: null }),
    },
});

/**
 * Routes EnsureSiteIsAvailable deliberately leaves open. Someone landing here
 * mid-training needs to know these still work — otherwise they assume the whole
 * system is down and start phoning the office.
 */
const stillAvailable = [
    {
        icon: 'certificate',
        label: 'Certificate verification',
        description: 'Checking that a printed certificate is genuine at a venue still works.',
    },
    {
        icon: 'qr',
        label: 'Attendance station',
        description: 'The venue check-in station keeps working for the session in progress.',
    },
    {
        icon: 'envelope',
        label: 'Emailed links',
        description: 'Account verification and password reset links sent by email are unaffected.',
    },
];
</script>

<template>
    <div class="relative flex min-h-screen flex-col overflow-hidden bg-csc-blue">
        <Head title="Under Maintenance" />

        <!--
            The facade photo, laid out like the Home hero: WebP first with a
            JPEG fallback. The preload in app.blade.php already warms the WebP,
            so this background costs no extra round-trip.
        -->
        <picture class="absolute inset-0" aria-hidden="true">
            <source srcset="/images/cscbg_facade.webp" type="image/webp" />
            <img
                src="/images/cscbg_facade.jpeg"
                alt=""
                decoding="async"
                class="absolute inset-0 size-full object-cover"
            />
        </picture>

        <!-- Brand gradient keeps the facade readable behind the white card -->
        <div
            class="absolute inset-0"
            style="
                background: linear-gradient(
                    160deg,
                    color-mix(in srgb, var(--color-csc-blue-deep) 93%, transparent) 0%,
                    color-mix(in srgb, var(--color-csc-blue) 87%, transparent) 55%,
                    color-mix(in srgb, var(--color-csc-blue-deep) 95%, transparent) 100%
                );
            "
            aria-hidden="true"
        />

        <!--
            Instead of a repeating pattern, lift the card with a soft radial glow
            behind it — it draws the eye to the notice instead of competing with
            the photo. The bottom fade then grounds the page so it doesn't end
            abruptly. Both use brand tokens via color-mix; no hardcoded hex.
        -->
        <div
            class="absolute inset-0"
            style="
                background: radial-gradient(
                    ellipse 62% 52% at 50% 50%,
                    color-mix(in srgb, var(--color-csc-blue-tint) 26%, transparent) 0%,
                    color-mix(in srgb, var(--color-csc-blue-tint) 0%, transparent) 70%
                );
            "
            aria-hidden="true"
        />
        <div
            class="absolute inset-0"
            style="
                background: linear-gradient(
                    to bottom,
                    transparent 55%,
                    color-mix(in srgb, var(--color-csc-blue-deep) 88%, transparent) 100%
                );
            "
            aria-hidden="true"
        />

        <main class="relative flex flex-1 items-center justify-center px-4 py-12 sm:px-6">
            <div class="w-full max-w-5xl overflow-hidden rounded-xl border border-white/10 bg-white shadow-xl">
                <header class="flex items-center gap-3 border-b border-csc-line bg-csc-blue-tint/60 px-8 py-3 sm:px-10">
                    <img src="/images/csc-logo.png" alt="Civil Service Commission" class="h-9 w-auto shrink-0" />
                    <div class="min-w-0">
                        <p class="text-xs font-semibold tracking-wide text-csc-blue uppercase">
                            Civil Service Commission — Regional Office VIII
                        </p>
                        <p class="mt-0.5 text-xs text-csc-ink-subtle">Training Information Management System</p>
                    </div>
                </header>

                <!--
                    Two columns so the whole notice fits a single viewport: the
                    status copy on the left, what still works on the right.
                -->
                <div class="grid sm:min-h-[34rem] sm:grid-cols-2">
                    <div class="flex flex-col px-8 py-8 sm:px-10">
                        <div class="flex items-start gap-4">
                            <span
                                class="mt-0.5 flex size-14 shrink-0 items-center justify-center rounded-full bg-csc-blue-tint text-csc-blue"
                            >
                                <AppIcon name="clock" size="lg" />
                            </span>
                            <div class="min-w-0">
                                <h1 class="text-2xl font-bold tracking-tight text-csc-ink">We'll be back shortly</h1>
                                <p class="mt-2 text-base leading-relaxed text-csc-ink-muted">
                                    {{
                                        message
                                            || 'The CSC TIMS portal is temporarily unavailable while we carry out scheduled maintenance. Your records, registrations and certificates are safe.'
                                    }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-auto pt-8">
                            <p class="text-xs font-semibold tracking-wide text-csc-ink-subtle uppercase">Urgent concerns</p>
                            <div class="mt-3 flex flex-col gap-2 text-base">
                                <!--
                                    From config/office.php, passed explicitly by
                                    EnsureSiteIsAvailable — this page renders
                                    before Inertia's shared props exist, so it
                                    cannot reach `office` the way every other
                                    page does.

                                    Both of these were hard-coded, and both were
                                    wrong: a generic mailbox and the Central
                                    Office trunkline in Quezon City, on the one
                                    page a stuck participant in Eastern Visayas
                                    actually reads.
                                -->
                                <a
                                    :href="`mailto:${office.email}`"
                                    class="inline-flex items-center gap-2 font-medium text-csc-blue hover:underline"
                                >
                                    <AppIcon name="envelope" class="shrink-0" />
                                    {{ office.email }}
                                </a>
                                <!-- Omitted rather than guessed when unset; see config/office.php. -->
                                <a
                                    v-if="office.phone"
                                    :href="`tel:${office.phone.replace(/[^+\d]/g, '')}`"
                                    class="inline-flex items-center gap-2 font-medium text-csc-blue hover:underline"
                                >
                                    <AppIcon name="phone" class="shrink-0" />
                                    {{ office.phone }}
                                </a>
                            </div>
                        </div>

                        <!--
                            Signed in and still seeing this: they are a participant,
                            since staff pass straight through. /login would bounce
                            them back here via the guest redirect, so offer the way
                            out instead.
                        -->
                        <div class="mt-6 border-t border-csc-line pt-5">
                            <template v-if="authenticated">
                                <p class="text-sm text-csc-ink-subtle">
                                    You're signed in, but the portal is closed to participants during maintenance.
                                </p>
                                <Link
                                    href="/logout"
                                    method="post"
                                    as="button"
                                    type="button"
                                    class="mt-2 inline-flex items-center gap-1.5 text-base font-semibold text-csc-blue hover:underline"
                                >
                                    <AppIcon name="sign-out" class="shrink-0" />
                                    Sign out
                                </Link>
                            </template>
                            <p v-else class="text-sm text-csc-ink-subtle">
                                CSC staff can
                                <Link href="/login" class="font-semibold text-csc-blue hover:underline">sign in here</Link>.
                            </p>
                        </div>
                    </div>

                    <aside
                        class="flex flex-col border-t border-csc-line bg-csc-blue-tint/50 px-8 py-8 sm:border-t-0 sm:border-l sm:px-10"
                    >
                        <p class="text-xs font-semibold tracking-wide text-csc-ink-subtle uppercase">Still available</p>
                        <ul class="mt-5 space-y-5">
                            <li v-for="item in stillAvailable" :key="item.label" class="flex gap-3">
                                <span
                                    class="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-full bg-white text-csc-blue ring-1 ring-csc-line"
                                >
                                    <AppIcon :name="item.icon" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-base font-medium text-csc-ink">{{ item.label }}</p>
                                    <p class="text-sm leading-relaxed text-csc-ink-subtle">{{ item.description }}</p>
                                </div>
                            </li>
                        </ul>
                        <p class="mt-auto pt-6 text-sm leading-relaxed text-csc-ink-subtle">
                            These stay open so a session in progress is never interrupted mid-training.
                        </p>
                    </aside>
                </div>
            </div>
        </main>

        <footer class="relative pb-6 text-center text-xs text-white/70">
            &copy; {{ new Date().getFullYear() }} Civil Service Commission Regional Office VIII
        </footer>
    </div>
</template>