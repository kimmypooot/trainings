<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppPromptModal from '@/Components/AppPromptModal.vue';

const props = defineProps({
    registrations: { type: Array, required: true },
});

const upcoming = computed(() => props.registrations.filter((r) => !r.training.is_past));
const past = computed(() => props.registrations.filter((r) => r.training.is_past));

const money = (value) =>
    Number(value).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const schedule = (training) =>
    training.ends_at ? `${training.starts_at} – ${training.ends_at}` : training.starts_at;

/**
 * Withdrawing is a request, not an immediate cancellation — CSC caters and
 * prints against a confirmed head count — so the slot is held until reviewed.
 */
const withdrawing = ref(null);
const withdrawBusy = ref(false);

const closeWithdraw = () => {
    withdrawing.value = null;
    withdrawBusy.value = false;
};

const submitWithdrawal = (reason) => {
    withdrawBusy.value = true;

    router.delete(`/my/registrations/${withdrawing.value.id}`, {
        data: { reason },
        preserveScroll: true,
        onSuccess: closeWithdraw,
        onFinish: () => (withdrawBusy.value = false),
    });
};
</script>

<template>
    <Head title="My Registrations" />

    <AuthenticatedLayout title="My Registrations" current="registrations">
        <div class="mx-auto max-w-3xl space-y-5">
            <AppCard v-if="!registrations.length" :padded="false">
                <AppEmptyState
                    title="You have not registered for any training yet"
                    description="Browse the catalogue and reserve a slot — your registrations will be listed here."
                    icon="bookmark"
                >
                    <template #action>
                        <AppButton href="/trainings" icon="calendar">Browse Trainings</AppButton>
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
                                    <p class="mt-1 text-xs text-csc-ink/60">{{ schedule(registration.training) }}</p>
                                </div>
                                <AppBadge :status="registration.status" />
                            </div>

                            <dl class="mt-4 grid gap-3 border-t border-csc-line pt-4 text-xs sm:grid-cols-2">
                                <div class="flex items-start gap-2">
                                    <AppIcon name="map-pin" size="sm" class="mt-0.5 shrink-0" />
                                    <div class="min-w-0">
                                        <dt class="text-csc-ink/60">Venue</dt>
                                        <dd class="mt-0.5 font-medium text-csc-ink">{{ registration.training.venue }}</dd>
                                        <dd
                                            v-if="registration.training.venue_details"
                                            class="mt-0.5 leading-relaxed whitespace-pre-line text-csc-ink/60"
                                        >
                                            {{ registration.training.venue_details }}
                                        </dd>
                                    </div>
                                </div>

                                <div v-if="registration.training.mode_label" class="flex items-start gap-2">
                                    <AppIcon name="link" size="sm" class="mt-0.5 shrink-0" />
                                    <div>
                                        <dt class="text-csc-ink/60">Mode</dt>
                                        <dd class="mt-0.5 font-medium text-csc-ink">
                                            {{ registration.training.mode_label }}
                                            <span v-if="registration.training.duration_days">
                                                · {{ registration.training.duration_days }} day{{
                                                    registration.training.duration_days === 1 ? '' : 's'
                                                }}
                                            </span>
                                        </dd>
                                    </div>
                                </div>

                                <div v-if="registration.training.level_label" class="flex items-start gap-2">
                                    <AppIcon name="clipboard" size="sm" class="mt-0.5 shrink-0" />
                                    <div>
                                        <dt class="text-csc-ink/60">Level</dt>
                                        <dd class="mt-0.5 font-medium text-csc-ink">
                                            {{ registration.training.level_label }}
                                        </dd>
                                    </div>
                                </div>

                                <div v-if="registration.training.category" class="flex items-start gap-2">
                                    <AppIcon name="bookmark" size="sm" class="mt-0.5 shrink-0" />
                                    <div>
                                        <dt class="text-csc-ink/60">Category</dt>
                                        <dd class="mt-0.5 font-medium text-csc-ink">
                                            {{ registration.training.category }}
                                        </dd>
                                    </div>
                                </div>

                                <div v-if="registration.training.payment_required" class="flex items-start gap-2">
                                    <AppIcon name="card" size="sm" class="mt-0.5 shrink-0" />
                                    <div>
                                        <dt class="text-csc-ink/60">Training fee</dt>
                                        <dd class="mt-0.5 font-medium text-csc-ink">
                                            PHP {{ money(registration.training.payment_amount) }}
                                        </dd>
                                    </div>
                                </div>
                            </dl>

                            <p
                                v-if="registration.training.description"
                                class="mt-3 line-clamp-2 text-xs leading-relaxed text-csc-ink/65"
                            >
                                {{ registration.training.description }}
                            </p>

                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <Link
                                    :href="registration.training.url"
                                    class="inline-flex items-center gap-1.5 rounded text-sm font-medium text-csc-blue transition-colors hover:text-csc-blue-deep focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                >
                                    View training details
                                    <AppIcon name="chevron-right" size="sm" />
                                </Link>
                                <AppButton
                                    v-if="registration.can_withdraw"
                                    size="sm"
                                    variant="ghost"
                                    @click="withdrawing = registration"
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
                                    <p class="mt-1 text-xs text-csc-ink/60">{{ schedule(registration.training) }}</p>
                                    <p v-if="registration.training.venue" class="text-xs text-csc-ink/60">
                                        {{ registration.training.venue }}
                                    </p>
                                </div>
                                <AppBadge :status="registration.status" />
                            </div>
                            <Link
                                :href="registration.training.url"
                                class="mt-3 inline-flex items-center gap-1.5 rounded text-sm font-medium text-csc-blue transition-colors hover:text-csc-blue-deep focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            >
                                View training details
                                <AppIcon name="chevron-right" size="sm" />
                            </Link>
                        </li>
                    </ul>
                </section>
            </template>
        </div>

        <AppPromptModal
            :open="withdrawing !== null"
            title="Request withdrawal"
            :description="
                withdrawing
                    ? `“${withdrawing.training.title}” — your slot is held until CSC reviews this.`
                    : undefined
            "
            label="Why are you withdrawing?"
            hint="CSC caters and prints against a confirmed head count, so every withdrawal is reviewed."
            confirm-label="Send request"
            :min-length="10"
            :processing="withdrawBusy"
            @confirm="submitWithdrawal"
            @close="closeWithdraw"
        />
    </AuthenticatedLayout>
</template>
