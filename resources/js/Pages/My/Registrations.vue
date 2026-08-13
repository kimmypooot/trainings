<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppPromptModal from '@/Components/AppPromptModal.vue';

const props = defineProps({
    registrations: { type: Array, required: true },
});

const upcoming = computed(() => props.registrations.filter((r) => !r.training.is_past));
const past = computed(() => props.registrations.filter((r) => r.training.is_past));

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
                                    <p class="mt-1 text-xs text-csc-ink/60">{{ registration.training.starts_at }}</p>
                                </div>
                                <AppBadge :status="registration.status" />
                            </div>
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
