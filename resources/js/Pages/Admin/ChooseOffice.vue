<script setup>
import { computed, ref, useId } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppAvatar from '@/Components/AppAvatar.vue';
import AppBrandBackdrop from '@/Components/AppBrandBackdrop.vue';
import AppIcon from '@/Components/AppIcon.vue';

/**
 * Shown right after an IMIS sign-in to someone who heads more than one field
 * office (a concurrent Director). Nothing else opens until they choose, and the
 * header switcher changes it later.
 *
 * Its own full-page layout rather than AuthLayout: this is the one screen that
 * wants the richer treatment (brand panel, greeting, office cards), and the
 * shared auth shell is left exactly as it is for the sign-in pages.
 */
const props = defineProps({
    offices: { type: Array, required: true },
    // Already chosen when they come back here on purpose; null on first sign-in.
    active: { type: Number, default: null },
    homeId: { type: Number, default: null },
});

const page = usePage();
const user = computed(() => page.props.auth?.user ?? {});
const patternId = useId();
const choosing = ref(null);

const agencyLogos = [
    { src: '/images/csc-logo.png', alt: 'Civil Service Commission' },
    { src: '/images/bagong_pilipinas.png', alt: 'Bagong Pilipinas' },
    { src: '/images/lingkod_bayani.png', alt: 'Lingkod Bayani' },
];

const typeLabel = (office) => (office.code === 'wlso' ? 'Satellite Office' : 'Field Office');

const choose = (office) => {
    if (choosing.value !== null) return;

    choosing.value = office.id;
    router.post('/admin/acting-office', { field_office_id: office.id }, { onFinish: () => (choosing.value = null) });
};
</script>

<template>
    <Head title="Choose an office" />

    <div class="flex min-h-dvh flex-col bg-white lg:grid lg:grid-cols-2">
        <!-- Brand panel (desktop only; the card carries the logos on a phone) -->
        <aside class="relative hidden overflow-hidden lg:flex lg:min-h-dvh lg:flex-col lg:items-center lg:justify-center">
            <AppBrandBackdrop>
                <svg class="pointer-events-none absolute inset-0 size-full opacity-[0.08]" aria-hidden="true">
                    <defs>
                        <pattern :id="patternId" width="64" height="64" patternUnits="userSpaceOnUse">
                            <circle cx="32" cy="32" r="18" fill="none" stroke="white" stroke-width="1" />
                            <path d="M0 32h64M32 0v64" stroke="white" stroke-width="0.5" />
                        </pattern>
                    </defs>
                    <rect width="100%" height="100%" :fill="`url(#${patternId})`" />
                </svg>
            </AppBrandBackdrop>

            <div class="relative flex w-full max-w-md flex-col items-center px-8 py-12 text-center text-white">
                <div class="flex items-center justify-center gap-6">
                    <img
                        v-for="logo in agencyLogos"
                        :key="logo.src"
                        :src="logo.src"
                        alt=""
                        class="h-16 w-auto select-none drop-shadow-lg xl:h-20"
                        draggable="false"
                    />
                </div>

                <div class="my-6 h-0.5 w-10 rounded bg-white/20" />

                <h1 class="text-3xl leading-tight font-bold tracking-tight text-balance xl:text-4xl">
                    Which office are you working as?
                </h1>
                <p class="mt-5 text-sm leading-relaxed text-pretty text-white/75 xl:text-base">
                    You head more than one office. Everything you see in TIMS is for the office you pick, and you can
                    switch from the header at any time.
                </p>

                <ul class="mt-8 w-full space-y-2.5 text-left">
                    <li class="flex items-center gap-3 rounded-xl border border-white/15 bg-white/10 px-4 py-3 backdrop-blur-sm">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/15">
                            <AppIcon name="users" size="md" />
                        </span>
                        <span class="text-sm font-medium text-white/90">Participants and rosters of that office</span>
                    </li>
                    <li class="flex items-center gap-3 rounded-xl border border-white/15 bg-white/10 px-4 py-3 backdrop-blur-sm">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/15">
                            <AppIcon name="shield" size="md" />
                        </span>
                        <span class="text-sm font-medium text-white/90">Other offices stay out of view until you switch</span>
                    </li>
                </ul>
            </div>

            <p class="absolute inset-x-0 bottom-8 text-center text-[0.66rem] tracking-widest text-white/50 uppercase">
                Civil Service Commission Regional Office VIII
            </p>
        </aside>

        <!-- Choice panel -->
        <main class="relative flex flex-1 items-center justify-center overflow-hidden bg-csc-mist px-4 py-8 sm:px-6 lg:min-h-dvh lg:p-10">
            <div class="pointer-events-none absolute -top-24 -right-24 size-72 rounded-full bg-csc-blue/5" aria-hidden="true" />
            <div class="pointer-events-none absolute -bottom-28 -left-20 size-64 rounded-full bg-csc-red/5" aria-hidden="true" />

            <div class="choose-card relative w-full max-w-md">
                <div class="overflow-hidden rounded-2xl border border-csc-line bg-white shadow-lg">
                    <div class="h-1.5 bg-csc-red" aria-hidden="true" />

                    <div class="px-6 py-8 sm:px-10">
                        <!-- The agency logos live on the brand panel from lg up. -->
                        <div class="mb-5 flex items-center justify-center gap-5 border-b border-csc-line pb-5 lg:hidden">
                            <img
                                v-for="logo in agencyLogos"
                                :key="logo.src"
                                :src="logo.src"
                                :alt="logo.alt"
                                class="pointer-events-none h-9 w-auto select-none"
                                draggable="false"
                            />
                        </div>

                        <div class="flex items-center gap-3 rounded-xl bg-csc-blue-tint px-4 py-3">
                            <AppAvatar :name="user.name" :src="user.avatar" size="md" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-csc-blue">Welcome, {{ user.first_name }}</p>
                                <p class="truncate text-xs text-csc-ink-muted">{{ user.role_label }} · signed in from IMIS</p>
                            </div>
                        </div>

                        <h2 class="mt-6 text-2xl font-semibold tracking-tight text-csc-blue sm:text-3xl">Choose an office</h2>
                        <p class="mt-2 text-sm text-csc-ink-muted">
                            Pick the office whose participants and trainings you want to manage now.
                        </p>

                        <ul class="mt-6 space-y-3">
                            <li v-for="office in props.offices" :key="office.id">
                                <button
                                    type="button"
                                    class="group flex w-full items-center gap-4 rounded-xl border bg-white p-4 text-left shadow-xs transition-all duration-150 hover:-translate-y-0.5 hover:border-csc-blue hover:bg-csc-blue-tint hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue disabled:cursor-wait disabled:opacity-60 disabled:hover:translate-y-0"
                                    :class="office.id === props.active ? 'border-csc-blue bg-csc-blue-tint' : 'border-csc-line'"
                                    :disabled="choosing !== null"
                                    @click="choose(office)"
                                >
                                    <span class="flex size-12 shrink-0 items-center justify-center rounded-lg bg-csc-blue-tint text-csc-blue transition-colors group-hover:bg-white">
                                        <AppIcon name="building" size="lg" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-semibold text-csc-ink">{{ office.name }}</span>
                                        <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-csc-ink-muted">
                                            <span class="font-medium">{{ office.code.toUpperCase() }}</span>
                                            <span aria-hidden="true">·</span>
                                            <span>{{ typeLabel(office) }}</span>
                                            <span
                                                v-if="office.id === props.homeId"
                                                class="rounded-full bg-csc-blue px-2 py-0.5 text-2xs font-semibold tracking-wide text-white uppercase"
                                            >
                                                Your office
                                            </span>
                                        </span>
                                    </span>
                                    <AppIcon
                                        name="arrow-right"
                                        size="md"
                                        class="shrink-0 text-csc-blue transition-transform duration-150 group-hover:translate-x-1"
                                    />
                                </button>
                            </li>
                        </ul>

                        <p class="mt-6 text-center text-xs text-csc-ink-subtle">
                            You can change this later from the office menu in the header.
                        </p>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>

<style scoped>
@media (prefers-reduced-motion: no-preference) {
    .choose-card {
        animation: choose-rise 0.5s ease-out both;
    }
}

@keyframes choose-rise {
    from {
        opacity: 0;
        transform: translateY(12px);
    }
    to {
        opacity: 1;
        transform: none;
    }
}
</style>
