<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppFileField from '@/Components/AppFileField.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppInput from '@/Components/AppInput.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppModal from '@/Components/AppModal.vue';
import AppPromptModal from '@/Components/AppPromptModal.vue';
import AppTextarea from '@/Components/AppTextarea.vue';

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

/*
 * Post-training outputs for a supervisory course. The registration is asked
 * for rather than assumed, because a participant can be on more than one
 * supervisory run at a time and the file has to land against the right one.
 */
const submittingOutput = ref(null);

const outputForm = useForm({
    title: '',
    description: '',
    file: null,
});

const startOutput = (registration) => {
    outputForm.reset();
    outputForm.clearErrors();
    submittingOutput.value = registration;
};

const submitOutput = () => {
    outputForm.post(`/my/registrations/${submittingOutput.value.id}/outputs`, {
        preserveScroll: true,
        onSuccess: () => {
            submittingOutput.value = null;
            outputForm.reset();
        },
    });
};

/*
 * A rejected supervisory document can be replaced. The re-upload is its own
 * modal because it needs a file, and it is only offered while the workflow
 * still allows one — once verified, the document is settled.
 */
const resubmitting = ref(null);

const resubmitForm = useForm({
    supporting_document: null,
});

const startResubmit = (registration) => {
    resubmitForm.reset();
    resubmitForm.clearErrors();
    resubmitting.value = registration;
};

const submitResubmit = () => {
    resubmitForm.post(`/my/registrations/${resubmitting.value.id}/supporting-document`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            resubmitting.value = null;
            resubmitForm.reset();
        },
    });
};
</script>

<template>
    <Head title="My Registrations" />

    <AuthenticatedLayout title="My Registrations" current="registrations">
        <div class="mx-auto max-w-4xl space-y-5">
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

                            <!--
                                For a supervisory course the supporting document
                                is reviewed separately from the registration, so
                                the participant needs to see which state their
                                proof is in — and to swap a rejected one.
                            -->
                            <div
                                v-if="registration.supervisory_document"
                                class="mt-4 rounded-lg border border-csc-line bg-csc-blue-tint/30 p-3"
                            >
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        <p class="text-xs font-semibold text-csc-ink">Supporting document</p>
                                        <AppBadge
                                            :status="`document_${registration.supervisory_document.status}`"
                                            :label="registration.supervisory_document.status_label"
                                        />
                                    </div>
                                    <AppButton
                                        v-if="registration.supervisory_document.can_resubmit"
                                        size="sm"
                                        variant="ghost"
                                        icon="upload"
                                        @click="startResubmit(registration)"
                                    >
                                        Re-upload
                                    </AppButton>
                                </div>
                                <p
                                    v-if="registration.supervisory_document.remarks"
                                    class="mt-2 border-t border-csc-line pt-2 text-xs text-csc-ink/70"
                                >
                                    <span class="font-medium">CSC:</span>
                                    {{ registration.supervisory_document.remarks }}
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

                            <!--
                                A supervisory course is not finished when the
                                sessions are — the participant owes a written
                                output, and HRD reviews it before the course
                                counts as complete.
                            -->
                            <div
                                v-if="registration.output_submission"
                                class="mt-4 rounded-lg border border-csc-line bg-csc-blue-tint/30 p-3"
                            >
                                <p class="text-xs font-semibold text-csc-ink">Training Output</p>

                                <ul
                                    v-if="registration.output_submission.submitted.length"
                                    class="mt-2 space-y-2"
                                >
                                    <li
                                        v-for="output in registration.output_submission.submitted"
                                        :key="output.id"
                                        class="rounded-md border border-csc-line bg-white p-2.5"
                                    >
                                        <div class="flex flex-wrap items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <a
                                                    :href="output.download_url"
                                                    class="text-xs font-medium text-csc-blue hover:underline"
                                                >
                                                    {{ output.title }}
                                                </a>
                                                <p class="mt-0.5 text-2xs text-csc-ink/55">
                                                    {{ output.filename }} · {{ output.size }} ·
                                                    {{ output.submitted_at }}
                                                </p>
                                            </div>
                                            <AppBadge :status="output.status" :label="output.status_label" />
                                        </div>
                                        <p
                                            v-if="output.remarks"
                                            class="mt-2 border-t border-csc-line pt-2 text-2xs text-csc-ink/70"
                                        >
                                            <span class="font-medium">CSC:</span> {{ output.remarks }}
                                        </p>
                                    </li>
                                </ul>

                                <p v-else class="mt-1 text-xs text-csc-ink/60">
                                    You have not submitted an output for this training yet.
                                </p>

                                <AppButton
                                    class="mt-3"
                                    size="sm"
                                    variant="ghost"
                                    icon="upload"
                                    @click="startOutput(registration)"
                                >
                                    {{
                                        registration.output_submission.submitted.length
                                            ? 'Submit Another'
                                            : 'Submit Output'
                                    }}
                                </AppButton>
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

        <AppModal
            :open="submittingOutput !== null"
            title="Submit training output"
            :subtitle="
                submittingOutput
                    ? `${submittingOutput.training.title} — CSC reviews every output before the course counts as complete.`
                    : ''
            "
            @close="submittingOutput = null"
        >
            <form class="space-y-4" @submit.prevent="submitOutput">
                <AppInput
                    v-model="outputForm.title"
                    label="Title"
                    placeholder="e.g. Re-entry Action Plan"
                    maxlength="255"
                    :error="outputForm.errors.title"
                    required
                />

                <AppTextarea
                    v-model="outputForm.description"
                    label="Description"
                    :rows="3"
                    maxlength="2000"
                    hint="Optional. Anything the reviewer should know about this submission."
                    :error="outputForm.errors.description"
                />

                <AppFileField
                    id="output-file"
                    label="File"
                    hint="PDF, Word, Excel, PowerPoint or an image. Up to 10 MB."
                    accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png"
                    :error="outputForm.errors.file"
                    required
                    @change="outputForm.file = $event"
                />

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="submittingOutput = null">Cancel</AppButton>
                    <AppButton type="submit" :loading="outputForm.processing">Submit Output</AppButton>
                </div>
            </form>
        </AppModal>

        <AppModal
            :open="resubmitting !== null"
            title="Re-upload supporting document"
            :subtitle="
                resubmitting
                    ? `${resubmitting.training.title} — CSC requires a document showing you supervise staff before this registration can proceed.`
                    : ''
            "
            @close="resubmitting = null"
        >
            <form class="space-y-4" @submit.prevent="submitResubmit">
                <AppFileField
                    id="supporting-document"
                    label="Designation, memorandum or office order"
                    hint="PDF, Word or an image. Up to 5 MB."
                    accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                    :error="resubmitForm.errors.supporting_document"
                    required
                    @change="resubmitForm.supporting_document = $event"
                />

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="resubmitting = null">Cancel</AppButton>
                    <AppButton type="submit" :loading="resubmitForm.processing">Re-upload</AppButton>
                </div>
            </form>
        </AppModal>
    </AuthenticatedLayout>
</template>
