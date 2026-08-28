<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
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
import TrainingDetailSections from '@/Components/TrainingDetailSections.vue';
import { formatDateRange } from '@/dateRange';

const props = defineProps({
    registrations: { type: Array, required: true },
    /** The status filter in force, or null for everything. */
    filters: { type: Object, default: () => ({ status: null }) },
    /** Only the statuses this participant actually has. */
    statusOptions: { type: Array, default: () => [] },
});

/*
 * The status filter, driven by the URL.
 *
 * The dashboard's counts link straight in here with ?status=, so the filter has
 * to survive a cold load rather than living in component state — and a filtered
 * view is worth bookmarking and sharing with the office anyway.
 *
 * `replace` keeps the back button meaningful: flicking between chips should not
 * bury the page the participant arrived from under a dozen history entries.
 */
const filterTo = (status) =>
    router.get(
        '/my/registrations',
        status ? { status } : {},
        { preserveScroll: true, preserveState: true, replace: true }
    );

/*
 * Reading a training without leaving the list.
 *
 * "View training details" used to be a link to the detail page — a whole
 * navigation, and a page built around deciding whether to register, offered to
 * somebody who already has. The dialog answers the question that was actually
 * being asked ("what am I signed up for again?") and leaves the participant
 * where they were.
 *
 * No fetch behind it, unlike the catalogue's version: everything the dialog
 * shows is already in the props for this page. See RegistrationController.
 *
 * Holds the registration rather than its training, so the dialog can offer the
 * one action that belongs to a training you are already on.
 */
const detailing = ref(null);

const upcoming = computed(() => props.registrations.filter((r) => !r.training.is_past));
const past = computed(() => props.registrations.filter((r) => r.training.is_past));

const money = (value) =>
    Number(value).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const schedule = (training) => formatDateRange(training.starts_at, training.ends_at);

/*
 * Why the join link is not in the dialog yet.
 *
 * The link never reaches the client until it is earned (see
 * RegistrationController), so this only ever names the missing step — it never
 * has a link to withhold. Mirrors Trainings/Show, which is where this used to
 * be the only place a participant could find it.
 */
const joinLockedReason = (registration) => {
    const training = registration.training;

    if (!training.has_meeting_link || training.meeting_link) return null;

    if (!registration.fee_settled) {
        return 'The join link is released once your payment has been verified.';
    }

    if (registration.status === 'pending') {
        return 'The join link is released once CSC approves your registration.';
    }

    return null;
};

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
            <!--
                Filter chips, shown only once there is more than one status to
                choose between — a single chip is a label pretending to be a
                control. Rendered above the empty state too, so a filter that
                happens to match nothing can still be cleared.
            -->
            <div v-if="statusOptions.length > 1" class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    :class="
                        filters.status
                            ? 'border-csc-line bg-white text-csc-ink-muted hover:border-csc-blue/40'
                            : 'border-csc-blue bg-csc-blue text-white'
                    "
                    :aria-pressed="!filters.status"
                    @click="filterTo(null)"
                >
                    All
                </button>
                <button
                    v-for="option in statusOptions"
                    :key="option.value"
                    type="button"
                    class="rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    :class="
                        filters.status === option.value
                            ? 'border-csc-blue bg-csc-blue text-white'
                            : 'border-csc-line bg-white text-csc-ink-muted hover:border-csc-blue/40'
                    "
                    :aria-pressed="filters.status === option.value"
                    @click="filterTo(option.value)"
                >
                    {{ option.label }}
                    <span :class="filters.status === option.value ? 'text-white/70' : 'text-csc-ink-subtle'">
                        {{ option.count }}
                    </span>
                </button>
            </div>

            <!--
                Two different nothings: no registrations at all, and none
                matching the filter. Telling a participant to go browse the
                catalogue when they have six registrations and a narrow filter
                on would be plainly wrong.
            -->
            <AppCard v-if="!registrations.length && filters.status" :padded="false">
                <AppEmptyState
                    title="No registrations with that status"
                    description="Nothing here matches the filter you have applied."
                    icon="bookmark"
                >
                    <template #action>
                        <AppButton icon="close" @click="filterTo(null)">Show All</AppButton>
                    </template>
                </AppEmptyState>
            </AppCard>

            <AppCard v-else-if="!registrations.length" :padded="false">
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
                    <h2 class="mb-3 text-sm font-semibold tracking-wide text-csc-ink-subtle uppercase">Upcoming</h2>
                    <ul class="space-y-3">
                        <li
                            v-for="registration in upcoming"
                            :key="registration.id"
                            class="rounded-xl border border-csc-line bg-white p-4 sm:p-5"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="text-sm font-semibold text-csc-blue">
                                        <button
                                            type="button"
                                            class="rounded text-left hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                            @click="detailing = registration"
                                        >
                                            {{ registration.training.title }}
                                        </button>
                                    </h3>
                                    <p class="mt-1 text-xs text-csc-ink-subtle">{{ schedule(registration.training) }}</p>
                                </div>
                                <AppBadge :status="registration.status" />
                            </div>

                            <dl class="mt-4 grid gap-3 border-t border-csc-line pt-4 text-xs sm:grid-cols-2">
                                <div class="flex items-start gap-2">
                                    <AppIcon name="map-pin" size="sm" class="mt-0.5 shrink-0" />
                                    <div class="min-w-0">
                                        <dt class="text-csc-ink-subtle">Venue</dt>
                                        <dd class="mt-0.5 font-medium text-csc-ink">{{ registration.training.venue }}</dd>
                                        <dd
                                            v-if="registration.training.venue_details"
                                            class="mt-0.5 leading-relaxed whitespace-pre-line text-csc-ink-subtle"
                                        >
                                            {{ registration.training.venue_details }}
                                        </dd>
                                    </div>
                                </div>

                                <div v-if="registration.training.mode_label" class="flex items-start gap-2">
                                    <AppIcon name="link" size="sm" class="mt-0.5 shrink-0" />
                                    <div>
                                        <dt class="text-csc-ink-subtle">Mode</dt>
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
                                        <dt class="text-csc-ink-subtle">Level</dt>
                                        <dd class="mt-0.5 font-medium text-csc-ink">
                                            {{ registration.training.level_label }}
                                        </dd>
                                    </div>
                                </div>

                                <div v-if="registration.training.category" class="flex items-start gap-2">
                                    <AppIcon name="bookmark" size="sm" class="mt-0.5 shrink-0" />
                                    <div>
                                        <dt class="text-csc-ink-subtle">Category</dt>
                                        <dd class="mt-0.5 font-medium text-csc-ink">
                                            {{ registration.training.category }}
                                        </dd>
                                    </div>
                                </div>

                                <div v-if="registration.training.payment_required" class="flex items-start gap-2">
                                    <AppIcon name="card" size="sm" class="mt-0.5 shrink-0" />
                                    <div>
                                        <dt class="text-csc-ink-subtle">Training fee</dt>
                                        <dd class="mt-0.5 font-medium text-csc-ink">
                                            PHP {{ money(registration.training.payment_amount) }}
                                        </dd>
                                    </div>
                                </div>
                            </dl>

                            <p
                                v-if="registration.training.description"
                                class="mt-3 line-clamp-2 text-xs leading-relaxed text-csc-ink-subtle"
                            >
                                {{ registration.training.description }}
                            </p>

                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded text-sm font-medium text-csc-blue transition-colors hover:text-csc-blue-deep focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                    @click="detailing = registration"
                                >
                                    View training details
                                    <AppIcon name="chevron-right" size="sm" />
                                </button>
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
                                    class="mt-2 border-t border-csc-line pt-2 text-xs text-csc-ink-muted"
                                >
                                    <span class="font-medium">CSC:</span>
                                    {{ registration.supervisory_document.remarks }}
                                </p>
                            </div>
                        </li>
                    </ul>
                </section>

                <section v-if="past.length">
                    <h2 class="mb-3 text-sm font-semibold tracking-wide text-csc-ink-subtle uppercase">Past</h2>
                    <ul class="space-y-3">
                        <li
                            v-for="registration in past"
                            :key="registration.id"
                            class="rounded-xl border border-csc-line bg-white p-4 sm:p-5"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="text-sm font-semibold text-csc-ink">
                                        <button
                                            type="button"
                                            class="rounded text-left hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                            @click="detailing = registration"
                                        >
                                            {{ registration.training.title }}
                                        </button>
                                    </h3>
                                    <p class="mt-1 text-xs text-csc-ink-subtle">{{ schedule(registration.training) }}</p>
                                    <p v-if="registration.training.venue" class="text-xs text-csc-ink-subtle">
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
                                                <p class="mt-0.5 text-2xs text-csc-ink-subtle">
                                                    {{ output.filename }} · {{ output.size }} ·
                                                    {{ output.submitted_at }}
                                                </p>
                                            </div>
                                            <AppBadge :status="output.status" :label="output.status_label" />
                                        </div>
                                        <p
                                            v-if="output.remarks"
                                            class="mt-2 border-t border-csc-line pt-2 text-2xs text-csc-ink-muted"
                                        >
                                            <span class="font-medium">CSC:</span> {{ output.remarks }}
                                        </p>
                                    </li>
                                </ul>

                                <p v-else class="mt-1 text-xs text-csc-ink-subtle">
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

                            <button
                                type="button"
                                class="mt-3 inline-flex items-center gap-1.5 rounded text-sm font-medium text-csc-blue transition-colors hover:text-csc-blue-deep focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                @click="detailing = registration"
                            >
                                View training details
                                <AppIcon name="chevron-right" size="sm" />
                            </button>
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

        <!--
            The training, read in place. No slots or registration window here,
            unlike the catalogue's dialog: those answer "should I sign up",
            which is already settled for everything on this page.
        -->
        <AppModal
            :open="detailing !== null"
            :title="detailing?.training.title"
            :subtitle="detailing ? `${detailing.training.mode_label} · ${schedule(detailing.training)}` : null"
            size="lg"
            @close="detailing = null"
        >
            <template v-if="detailing">
                <dl class="grid gap-x-6 gap-y-5 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-csc-ink-subtle">Date</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ schedule(detailing.training) }}</dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink-subtle">Venue</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ detailing.training.venue }}</dd>
                    </div>
                    <div v-if="detailing.training.mode_label">
                        <dt class="text-csc-ink-subtle">Mode</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ detailing.training.mode_label }}</dd>
                    </div>
                    <div v-if="detailing.training.payment_required">
                        <dt class="text-csc-ink-subtle">Fee</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">₱{{ money(detailing.training.payment_amount) }}</dd>
                    </div>
                    <div v-if="detailing.training.category">
                        <dt class="text-csc-ink-subtle">Curriculum</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ detailing.training.category }}</dd>
                    </div>
                    <div v-if="detailing.training.duration_days">
                        <dt class="text-csc-ink-subtle">Duration</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">
                            {{ detailing.training.duration_days }} day{{ detailing.training.duration_days === 1 ? '' : 's' }}
                        </dd>
                    </div>
                    <div v-if="detailing.training.level_label">
                        <dt class="text-csc-ink-subtle">Level</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ detailing.training.level_label }}</dd>
                    </div>
                    <div v-if="detailing.training.training_code">
                        <dt class="text-csc-ink-subtle">Training code</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ detailing.training.training_code }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-csc-ink-subtle">Your registration</dt>
                        <dd class="mt-1"><AppBadge :status="detailing.status" /></dd>
                    </div>
                </dl>

                <!--
                    The join link, once it has been earned. This is what the
                    dialog was missing while "Open the full page" was the only
                    way to it — and on the day of an online session it is the
                    single thing a participant comes to this page for.
                -->
                <div v-if="detailing.training.meeting_link" class="mt-5 rounded-lg bg-info-soft p-4">
                    <h3 class="flex items-center gap-2 text-sm font-semibold text-info">
                        <AppIcon name="link" size="sm" />
                        Join link
                    </h3>
                    <a
                        :href="detailing.training.meeting_link"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-1.5 block text-sm font-medium break-all text-csc-blue underline underline-offset-2 transition-colors hover:text-csc-blue-deep focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    >
                        {{ detailing.training.meeting_link }}
                    </a>
                    <p class="mt-1.5 text-xs text-csc-ink-subtle">
                        Please keep this link to yourself — it is issued to you alone.
                    </p>
                </div>

                <p
                    v-else-if="joinLockedReason(detailing)"
                    class="mt-5 flex items-start gap-2 rounded-lg bg-csc-blue-tint p-4 text-sm text-csc-ink-muted"
                >
                    <AppIcon name="lock" size="sm" class="mt-0.5 shrink-0" />
                    {{ joinLockedReason(detailing) }}
                </p>

                <TrainingDetailSections :training="detailing.training" class="mt-6" />
            </template>

            <template v-if="detailing" #footer>
                <div class="flex justify-end">
                    <AppButton variant="ghost" @click="detailing = null">Close</AppButton>
                </div>
            </template>
        </AppModal>
    </AuthenticatedLayout>
</template>
