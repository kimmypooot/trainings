<script setup>
import { computed, ref, useId } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppFileField from '@/Components/AppFileField.vue';
import AppIcon from '@/Components/AppIcon.vue';

/**
 * Registering for a training, wherever the participant happens to be standing.
 *
 * This used to live inline in Trainings/Show.vue, which made the catalogue
 * modal's "Register for this training" a *link* to that page — so a
 * participant read the details in the modal, was sent to a page repeating the
 * same details, and only then found the form. Three steps where one would do.
 * Extracting the form is what lets the modal finish the job; Show.vue keeps
 * rendering it too, because a training URL is a thing people bookmark and
 * share, and a deep link must still work on its own.
 *
 * One component, two hosts, so the two can never drift apart — the payee
 * question in particular is not something that may be worded one way in a
 * modal and another way on a page.
 */
const props = defineProps({
    /** Needs id, title, payment_required, payment_amount. */
    training: { type: Object, required: true },
    /** barred, barred_reason, needs_supporting_document, supporting_document_hint. */
    eligibility: { type: Object, required: true },
    chargeOptions: { type: Array, required: true },
});

const emit = defineEmits(['registered']);

const documentId = useId();

/*
 * Three steps, not two.
 *
 * 'idle' is the button on its own. 'form' is the questions. 'confirm' restates
 * what is about to happen and is the whole reason this component has a state
 * machine rather than a boolean: see the summary block in the template.
 *
 * The confirmation is a step *inside* this container rather than a second
 * modal, because one of the two hosts is already a modal and stacking dialogs
 * means two focus traps fighting over the same keyboard.
 */
const step = ref('idle');

/*
 * Registration is a short form, not a single button: finance needs to know who
 * the fee is billed to before the receipt is cut, and a supervisory course
 * needs proof of the job.
 *
 * charge_to starts empty on purpose. It used to default to Personal, which
 * meant the single most expensive answer on this form — the one that decides
 * whose name goes on the official receipt, and whose correction means
 * cancelling an issued OR — was the one nobody had to look at. An unset radio
 * group cannot be submitted past (see canReview), so the choice is made rather
 * than inherited.
 *
 * needs_certificate is gone from the payload entirely rather than sent as a
 * constant: the column defaults to true and RegistrationService fills the same
 * default when the key is absent, so not asking and not sending is the honest
 * encoding of "everyone gets one".
 */
const form = useForm({
    charge_to: null,
    supporting_document: null,
});

const money = (value) =>
    Number(value).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const chargedTo = computed(() =>
    props.chargeOptions.find((option) => option.value === form.charge_to) ?? null
);

const start = () => {
    form.reset();
    form.clearErrors();

    /*
     * A free run is never asked who is paying, because nothing is: no fee, no
     * receipt, nobody to bill. The server still requires the column, so the
     * only answer that means anything is filled in here rather than put to the
     * participant as a question with no content.
     */
    if (!props.training.payment_required) {
        form.charge_to = 'personal';
    }

    step.value = 'form';
};

/*
 * What must be answered before the summary is worth showing.
 *
 * The payee, because it no longer has a default. And the document, which is
 * the one answer this form cannot check for itself — required or not is the
 * server's call, so this only asks for it when the server said it would.
 */
const canReview = computed(() => {
    if (form.charge_to === null) return false;

    return !props.eligibility.needs_supporting_document || form.supporting_document !== null;
});

const review = () => {
    if (!canReview.value) return;

    step.value = 'confirm';
};

const submit = () =>
    form.post(`/trainings/${props.training.id}/register`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            step.value = 'idle';
            form.reset();
            emit('registered');
        },
        // A rejected submission goes back to the questions rather than sitting
        // on a summary the participant can no longer act on — the errors are
        // attached to fields that only exist in the previous step.
        onError: () => (step.value = 'form'),
    });
</script>

<template>
    <AppAlert v-if="eligibility.barred" tone="warning" title="You are not eligible for this course">
        {{ eligibility.barred_reason }}
    </AppAlert>

    <AppButton v-else-if="step === 'idle'" size="lg" block icon="clipboard" @click="start">
        Register for This Training
    </AppButton>

    <form v-else-if="step === 'form'" class="space-y-4" @submit.prevent="review">
        <!-- Only where there is a fee to bill; see start(). -->
        <fieldset v-if="training.payment_required">
            <legend class="mb-2 text-sm font-medium text-csc-ink">Who is paying the training fee?</legend>
            <div class="space-y-2">
                <label
                    v-for="option in chargeOptions"
                    :key="option.value"
                    class="flex cursor-pointer gap-3 rounded-lg border p-3 transition-colors"
                    :class="
                        form.charge_to === option.value
                            ? 'border-csc-blue bg-csc-blue-tint'
                            : 'border-csc-line hover:border-csc-blue/40'
                    "
                >
                    <input
                        v-model="form.charge_to"
                        type="radio"
                        name="charge_to"
                        :value="option.value"
                        class="mt-0.5 accent-csc-blue"
                    />
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-csc-ink">{{ option.label }}</span>
                        <span class="block text-xs text-csc-ink-subtle">{{ option.description }}</span>
                    </span>
                </label>
            </div>
            <p v-if="form.errors.charge_to" class="mt-1.5 text-xs font-medium text-csc-red-ink">
                {{ form.errors.charge_to }}
            </p>
        </fieldset>

        <AppFileField
            v-if="eligibility.needs_supporting_document"
            :id="documentId"
            label="Proof of supervisory function"
            :hint="eligibility.supporting_document_hint"
            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
            required
            :error="form.errors.supporting_document"
            @change="form.supporting_document = $event"
        />

        <div class="flex gap-2">
            <AppButton type="button" variant="ghost" @click="step = 'idle'">Cancel</AppButton>
            <AppButton type="submit" block icon="clipboard" :disabled="!canReview">
                Review Registration
            </AppButton>
        </div>
    </form>

    <!--
        The confirmation.

        Deliberately a summary of the commitment rather than "Are you sure?" —
        a bare yes/no is clicked through without being read, and would buy
        nothing. Two things here are worth a participant's second look:

        The payee, because charge_to defaults to Personal and is therefore the
        field most likely to be submitted untouched by someone whose agency is
        in fact paying. Correcting it later means cancelling an issued official
        receipt (see App\Enums\ChargeTo), so this is the last cheap moment to
        catch it.

        And what "registered" actually means on a paid run: the slot is held,
        not confirmed, until the fee is settled by
        PaymentService::confirmSlotOnSettlement. A participant who thinks a
        submitted form is a seat is the person who turns up to a training they
        were never counted in.
    -->
    <div v-else class="space-y-4">
        <div class="rounded-lg border border-csc-line p-4">
            <h3 class="text-sm font-semibold text-csc-blue">Confirm your registration</h3>

            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-csc-ink-subtle">Training</dt>
                    <dd class="text-right font-medium text-csc-ink">{{ training.title }}</dd>
                </div>

                <div v-if="training.payment_required" class="flex justify-between gap-4">
                    <dt class="text-csc-ink-subtle">Fee</dt>
                    <dd class="text-right font-medium text-csc-ink">₱{{ money(training.payment_amount) }}</dd>
                </div>

                <div v-if="training.payment_required && chargedTo" class="flex justify-between gap-4">
                    <dt class="text-csc-ink-subtle">Charged to</dt>
                    <dd class="text-right font-medium text-csc-ink">{{ chargedTo.label }}</dd>
                </div>

            </dl>

            <p v-if="training.payment_required && chargedTo" class="mt-2 text-xs text-csc-ink-subtle">
                {{ chargedTo.description }}
            </p>
        </div>

        <p
            v-if="training.payment_required"
            class="flex items-start gap-2.5 rounded-lg bg-warning-soft px-3 py-2.5 text-sm text-csc-ink-muted"
        >
            <AppIcon name="info" size="sm" class="mt-0.5 shrink-0 text-warning" />
            <span>
                Your slot is held as <strong class="font-medium">Pending</strong> and is confirmed once the
                fee is settled. Record your payment under <strong class="font-medium">Payments</strong>.
            </span>
        </p>

        <p v-else class="flex items-start gap-2.5 rounded-lg bg-info-soft px-3 py-2.5 text-sm text-csc-ink-muted">
            <AppIcon name="info" size="sm" class="mt-0.5 shrink-0 text-info" />
            <span>This training has no fee, so your registration is approved straight away.</span>
        </p>

        <div class="flex gap-2">
            <AppButton type="button" variant="ghost" :disabled="form.processing" @click="step = 'form'">
                Back
            </AppButton>
            <AppButton block icon="clipboard" :loading="form.processing" @click="submit">
                Submit Registration
            </AppButton>
        </div>
    </div>
</template>
