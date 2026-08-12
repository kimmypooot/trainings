<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

const props = defineProps({
    registrations: { type: Array, required: true },
});

const page = usePage();
const flash = computed(() => page.props.flash?.success);

const upcoming = computed(() => props.registrations.filter((r) => !r.training.is_past));
const past = computed(() => props.registrations.filter((r) => r.training.is_past));

/**
 * Withdrawing is a request, not an immediate cancellation — CSC caters and
 * prints against a confirmed head count — so the slot is held until reviewed.
 */
const withdraw = (registration) => {
    const reason = window.prompt(
        `Why are you withdrawing from “${registration.training.title}”? CSC reviews every withdrawal.`
    );

    if (!reason) {
        return;
    }

    router.delete(`/my/registrations/${registration.id}`, {
        data: { reason },
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="My Registrations" />

    <AuthenticatedLayout title="My Registrations" current="registrations">
        <div class="mx-auto max-w-3xl space-y-5">
            <AppAlert v-if="flash" tone="success">{{ flash }}</AppAlert>

            <AppCard v-if="!registrations.length" :padded="false">
                <AppEmptyState
                    title="You have not registered for any training yet"
                    description="Browse the catalogue and reserve a slot — your registrations will be listed here."
                    icon="M7 4h10v16l-5-3-5 3z"
                >
                    <template #action>
                        <AppButton href="/trainings">Browse Trainings</AppButton>
                    </template>
                </AppEmptyState>
            </AppCard>

            <template v-else>
                <section v-if="upcoming.length">
                    <h2 class="mb-3 text-sm font-semibold tracking-wide text-csc-ink/60 uppercase">Upcoming</h2>
                    <ul class="space-y-3">
                        <li
                            v-for="registration in upcoming"
                            :key="registration.id"
                            class="rounded-xl border border-csc-line bg-white p-4 sm:p-5"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="text-sm font-semibold text-csc-blue">
                                        <Link :href="registration.training.url" class="hover:underline">
                                            {{ registration.training.title }}
                                        </Link>
                                    </h3>
                                    <p class="mt-1 text-xs text-csc-ink/60">{{ registration.training.starts_at }}</p>
                                    <p class="text-xs text-csc-ink/60">{{ registration.training.venue }}</p>
                                </div>
                                <AppBadge :status="registration.status" />
                            </div>

                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <AppButton
                                    v-if="registration.can_withdraw"
                                    size="sm"
                                    variant="ghost"
                                    @click="withdraw(registration)"
                                >
                                    Request Withdrawal
                                </AppButton>
                                <p
                                    v-else-if="registration.withdrawal_pending"
                                    class="text-xs font-medium text-warning"
                                >
                                    Withdrawal requested — your slot is held until CSC reviews it.
                                </p>
                            </div>
                        </li>
                    </ul>
                </section>

                <section v-if="past.length">
                    <h2 class="mb-3 text-sm font-semibold tracking-wide text-csc-ink/60 uppercase">Past</h2>
                    <ul class="space-y-3">
                        <li
                            v-for="registration in past"
                            :key="registration.id"
                            class="rounded-xl border border-csc-line bg-white p-4 sm:p-5"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="text-sm font-semibold text-csc-ink">
                                        <Link :href="registration.training.url" class="hover:underline">
                                            {{ registration.training.title }}
                                        </Link>
                                    </h3>
                                    <p class="mt-1 text-xs text-csc-ink/60">{{ registration.training.starts_at }}</p>
                                </div>
                                <AppBadge :status="registration.status" />
                            </div>
                        </li>
                    </ul>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
