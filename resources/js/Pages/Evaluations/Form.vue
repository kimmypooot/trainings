<script setup>
/**
 * The participant's end-of-day evaluation of the day's subject matter experts.
 *
 * One page per training day, however many experts that day had. The ratings are
 * radio groups rather than a select or a star widget for two reasons: the scale
 * is a labelled agreement scale, not a score out of five, and a keyboard or
 * screen-reader user has to be able to hear "Agree" rather than "4".
 */
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppTextarea from '@/Components/AppTextarea.vue';

const props = defineProps({
    training: { type: Object, required: true },
    day: { type: Object, required: true },
    experts: { type: Array, required: true },
    scale: { type: Array, required: true },
    criteria: { type: Array, required: true },
    existing: { type: Object, default: null },
    submitUrl: { type: String, required: true },
});

const blankRatings = () =>
    Object.fromEntries(
        props.experts.map((expert) => [
            expert.id,
            {
                ...Object.fromEntries(
                    props.criteria.map((criterion) => [
                        criterion.key,
                        props.existing?.ratings?.[expert.id]?.[criterion.key] ?? null,
                    ])
                ),
                comments: props.existing?.ratings?.[expert.id]?.comments ?? '',
            },
        ])
    );

const form = useForm({
    learned: props.existing?.learned ?? '',
    liked_most: props.existing?.liked_most ?? '',
    needs_improvement: props.existing?.needs_improvement ?? '',
    suggestions: props.existing?.suggestions ?? '',
    ratings: blankRatings(),
});

// Client-side only, and only to point at what is missing before a round trip —
// the server refuses an incomplete submission regardless.
const showMissing = ref(false);

const unanswered = computed(() =>
    props.experts.filter((expert) =>
        props.criteria.some((criterion) => form.ratings[expert.id][criterion.key] === null)
    )
);

const submit = () => {
    if (unanswered.value.length) {
        showMissing.value = true;

        return;
    }

    showMissing.value = false;
    form.post(props.submitUrl, { preserveScroll: true });
};

const ratingName = (expertId, key) => `rating-${expertId}-${key}`;
</script>

<template>
    <Head :title="`Evaluate Day ${day.number}`" />

    <AuthenticatedLayout title="Session Evaluation" current="evaluations">
        <div class="mx-auto max-w-3xl space-y-5">
            <AppCard>
                <p class="text-xs font-semibold tracking-wide text-csc-ink-subtle uppercase">
                    Day {{ day.number }} of {{ day.total }} · {{ day.date }}
                </p>
                <h1 class="mt-1 text-lg font-semibold text-csc-ink">{{ training.title }}</h1>
                <p class="mt-1 text-sm text-csc-ink-muted">
                    {{ training.training_code }} · {{ training.venue }}
                </p>
                <p class="mt-3 text-sm leading-relaxed text-csc-ink-muted">
                    Your answers help the Commission decide which resource persons to invite back and
                    what to change in the next run. They are read by the training staff, not by the
                    expert during the session.
                </p>
            </AppCard>

            <AppAlert v-if="existing" tone="info" title="You have already submitted this day">
                Filed {{ existing.submitted_at }}. Changing an answer below replaces what you sent.
            </AppAlert>

            <AppAlert v-if="showMissing" tone="warning" title="Some ratings are missing">
                Please answer every statement for
                {{ unanswered.map((expert) => expert.name).join(', ') }}.
            </AppAlert>

            <AppAlert v-if="form.errors.ratings || form.errors.day" tone="danger">
                {{ form.errors.ratings || form.errors.day }}
            </AppAlert>

            <form class="space-y-5" @submit.prevent="submit">
                <AppCard
                    v-for="expert in experts"
                    :key="expert.id"
                    :title="expert.display_name"
                    :subtitle="expert.topic || expert.organization"
                >
                    <div class="space-y-5">
                        <!--
                            A session that ran across more than one day is rated
                            once, here, at its end. Saying so matters: without
                            it the participant answers for this afternoon only
                            and the earlier days go unjudged.
                        -->
                        <p
                            v-if="expert.days && expert.days.length > 1"
                            class="rounded-lg bg-csc-blue-tint px-3 py-2 text-xs text-csc-ink-muted"
                        >
                            This session ran over
                            {{ expert.days.length }} days (day
                            {{ expert.days[0] }}–{{ expert.days[expert.days.length - 1] }}).
                            Your answers below cover all of them.
                        </p>

                        <fieldset
                            v-for="criterion in criteria"
                            :key="criterion.key"
                            class="border-t border-csc-line pt-4 first:border-t-0 first:pt-0"
                        >
                            <legend class="text-sm font-medium text-csc-ink">
                                {{ criterion.statement }}
                            </legend>

                            <!--
                                A row of five on anything wider than a phone, a
                                stack below that. The label sits beside the
                                control rather than under a bare number, so the
                                meaning of "3" never has to be remembered from a
                                legend further up the page.
                            -->
                            <div class="mt-3 grid gap-2 sm:grid-cols-5">
                                <label
                                    v-for="option in scale"
                                    :key="option.value"
                                    class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2.5 text-sm transition-colors"
                                    :class="
                                        form.ratings[expert.id][criterion.key] === option.value
                                            ? 'border-csc-blue bg-csc-blue-tint font-medium text-csc-blue'
                                            : 'border-csc-line text-csc-ink-muted hover:bg-csc-blue-tint/50'
                                    "
                                >
                                    <input
                                        v-model="form.ratings[expert.id][criterion.key]"
                                        type="radio"
                                        :name="ratingName(expert.id, criterion.key)"
                                        :value="option.value"
                                        class="size-4 shrink-0 border-csc-line text-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                                    />
                                    <span>
                                        <span class="font-semibold">{{ option.value }}</span>
                                        <span class="ml-1 sm:block sm:ml-0 sm:text-xs">
                                            {{ option.label }}
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </fieldset>

                        <AppTextarea
                            v-model="form.ratings[expert.id].comments"
                            :label="`Comments on the facilitation of ${expert.name}`"
                            :rows="3"
                            placeholder="Optional."
                        />
                    </div>
                </AppCard>

                <AppCard title="About the session">
                    <div class="grid gap-5">
                        <AppTextarea
                            v-model="form.learned"
                            label="What are the things you have learned in this session that are relevant to your line of work?"
                            :rows="3"
                            :error="form.errors.learned"
                        />
                        <AppTextarea
                            v-model="form.liked_most"
                            label="What parts of the session did you like the most?"
                            :rows="3"
                            :error="form.errors.liked_most"
                        />
                        <AppTextarea
                            v-model="form.needs_improvement"
                            label="What needs to be improved?"
                            :rows="3"
                            :error="form.errors.needs_improvement"
                        />
                        <AppTextarea
                            v-model="form.suggestions"
                            label="Other suggestions or recommendations"
                            :rows="3"
                            :error="form.errors.suggestions"
                        />
                    </div>
                </AppCard>

                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <AppButton href="/my/evaluations" variant="ghost" size="lg">Cancel</AppButton>
                    <AppButton type="submit" size="lg" :loading="form.processing" icon="check">
                        {{ existing ? 'Update Evaluation' : 'Submit Evaluation' }}
                    </AppButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
