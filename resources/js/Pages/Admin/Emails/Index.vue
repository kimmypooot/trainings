<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AppModal from '@/Components/AppModal.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppPagination from '@/Components/AppPagination.vue';

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    trainings: { type: Array, default: () => [] },
    audiences: { type: Array, default: () => [] },
    audienceFilters: { type: Object, default: () => ({ sectors: [], regions: [] }) },
    variables: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    // Arrives only on the partial reload that asks for it.
    audiencePreview: { type: Object, default: null },
});

const form = useForm({
    training_id: props.trainings[0]?.value ?? '',
    subject: '',
    message: '',
    statuses: ['approved'],
    sectors: [],
    regions: [],
});

const submit = () =>
    form.post('/admin/emails', {
        preserveScroll: true,
        onSuccess: () => form.reset('subject', 'message'),
    });

/*
 * Live recipient count and preview.
 *
 * Fetched as an optional prop by partial reload rather than being worked out
 * here: the send uses the same query server-side, and a client-side estimate
 * would be a second implementation of the audience rules that drifts from it.
 * The number on screen has to be the number of emails that go out.
 */
const previewing = ref(false);

const audienceParams = computed(() => ({
    training_id: form.training_id,
    statuses: form.statuses,
    sectors: form.sectors,
    regions: form.regions,
    subject: form.subject,
    message: form.message,
}));

const refreshPreview = () => {
    previewing.value = true;

    router.reload({
        only: ['audiencePreview'],
        data: audienceParams.value,
        // The compose form lives in local state; without this the reload would
        // discard what the sender has typed.
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => (previewing.value = false),
    });
};

// Debounced: the sender types into subject and message, and one request per
// keystroke would hammer the endpoint for a number that barely changes.
let previewTimer;
watch(
    audienceParams,
    () => {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(refreshPreview, 400);
    },
    { deep: true }
);

onMounted(refreshPreview);

const audienceCount = computed(() => props.audiencePreview?.count ?? null);
const samples = computed(() => props.audiencePreview?.samples ?? []);

/** Drop a placeholder in at the end of the message. */
const insertVariable = (token) => {
    form.message = `${form.message}${form.message.endsWith(' ') || !form.message ? '' : ' '}${token}`;
};

const applyTemplate = (template) => {
    form.subject = template.subject;
    form.message = template.body;
};

const sendTest = () => {
    router.post(
        '/admin/emails/test',
        {
            training_id: form.training_id,
            statuses: form.statuses,
            sectors: form.sectors,
            regions: form.regions,
            subject: form.subject,
            message: form.message,
        },
        { preserveScroll: true }
    );
};

const savingTemplate = ref(false);

const templateForm = useForm({
    name: '',
    subject: '',
    body: '',
    category: 'general',
});

const openSaveTemplate = () => {
    templateForm.name = '';
    templateForm.subject = form.subject;
    templateForm.body = form.message;
    templateForm.category = 'general';
    templateForm.clearErrors();
    savingTemplate.value = true;
};

const saveTemplate = () =>
    templateForm.post('/admin/emails/templates', {
        preserveScroll: true,
        onSuccess: () => {
            savingTemplate.value = false;
            templateForm.reset();
        },
    });

const deleteTemplate = (template) =>
    router.delete(`/admin/emails/templates/${template.id}`, { preserveScroll: true });
</script>

<template>
    <Head title="Emails" />

    <AuthenticatedLayout title="Emails" current="admin-emails">
        <div class="mx-auto max-w-6xl space-y-5">
            <AppCard
                title="Send an Announcement"
                subtitle="Goes to the selected participants by email and as an in-app notification."
            >
                <form class="grid gap-5" novalidate @submit.prevent="submit">
                    <div>
                        <label for="training" class="mb-1.5 block text-sm font-medium text-csc-ink">
                            Training <span class="text-csc-red-ink" aria-hidden="true">*</span>
                        </label>
                        <select
                            id="training"
                            v-model="form.training_id"
                            class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                        >
                            <option v-for="option in trainings" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                        <p v-if="form.errors.training_id" class="mt-1.5 text-xs font-medium text-csc-red-ink">
                            {{ form.errors.training_id }}
                        </p>
                    </div>

                    <fieldset>
                        <legend class="mb-1.5 text-sm font-medium text-csc-ink">Send to</legend>
                        <div class="flex flex-wrap gap-x-5 gap-y-2">
                            <label
                                v-for="audience in audiences"
                                :key="audience.value"
                                class="flex items-center gap-2 text-sm text-csc-ink"
                            >
                                <input
                                    v-model="form.statuses"
                                    type="checkbox"
                                    :value="audience.value"
                                    class="size-4 rounded border-csc-line text-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                                />
                                {{ audience.label }}
                            </label>
                        </div>
                        <p v-if="form.errors.statuses" class="mt-1.5 text-xs font-medium text-csc-red-ink">
                            {{ form.errors.statuses }}
                        </p>
                    </fieldset>

                    <!--
                        Sector and region narrow the audience further. Both are
                        drawn from the profiles that exist, so an option is
                        never offered that would send to nobody.
                    -->
                    <div v-if="audienceFilters.sectors.length || audienceFilters.regions.length" class="grid gap-5 sm:grid-cols-2">
                        <fieldset v-if="audienceFilters.sectors.length">
                            <legend class="mb-1.5 text-sm font-medium text-csc-ink">
                                Sector <span class="font-normal text-csc-ink/55">(all, if none picked)</span>
                            </legend>
                            <div class="max-h-32 space-y-1.5 overflow-y-auto rounded-lg border border-csc-line p-3">
                                <label
                                    v-for="sector in audienceFilters.sectors"
                                    :key="sector.value"
                                    class="flex items-center gap-2 text-sm text-csc-ink"
                                >
                                    <input
                                        v-model="form.sectors"
                                        type="checkbox"
                                        :value="sector.value"
                                        class="size-4 rounded border-csc-line text-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                                    />
                                    {{ sector.label }}
                                </label>
                            </div>
                        </fieldset>

                        <fieldset v-if="audienceFilters.regions.length">
                            <legend class="mb-1.5 text-sm font-medium text-csc-ink">
                                Region <span class="font-normal text-csc-ink/55">(all, if none picked)</span>
                            </legend>
                            <div class="max-h-32 space-y-1.5 overflow-y-auto rounded-lg border border-csc-line p-3">
                                <label
                                    v-for="region in audienceFilters.regions"
                                    :key="region.value"
                                    class="flex items-center gap-2 text-sm text-csc-ink"
                                >
                                    <input
                                        v-model="form.regions"
                                        type="checkbox"
                                        :value="region.value"
                                        class="size-4 rounded border-csc-line text-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                                    />
                                    {{ region.label }}
                                </label>
                            </div>
                        </fieldset>
                    </div>

                    <!-- How many this actually reaches, straight from the send query. -->
                    <AppAlert :tone="audienceCount === 0 ? 'warning' : 'info'">
                        <span v-if="previewing">Counting recipients…</span>
                        <span v-else-if="audienceCount === null">Adjust the filters to see the audience.</span>
                        <span v-else-if="audienceCount === 0">
                            No participants match these filters. Nothing would be sent.
                        </span>
                        <span v-else>
                            This reaches <strong>{{ audienceCount }}</strong> participant(s).
                        </span>
                    </AppAlert>

                    <p v-if="form.errors.audience" class="text-xs font-medium text-csc-red-ink">
                        {{ form.errors.audience }}
                    </p>

                    <AppInput
                        v-model="form.subject"
                        label="Subject"
                        :error="form.errors.subject"
                        required
                    />

                    <div>
                        <AppTextarea
                            v-model="form.message"
                            label="Message"
                            rows="6"
                            :error="form.errors.message"
                            required
                        />

                        <!--
                            Placeholders come from the server's own list, so the
                            screen can never offer one the sender does not
                            replace — in v1 those were two hand-kept lists, and
                            unreplaced tokens went out to participants.
                        -->
                        <div class="mt-2">
                            <p class="mb-1.5 text-xs font-medium text-csc-ink/70">
                                Insert a placeholder — each is filled in per recipient:
                            </p>
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="variable in variables"
                                    :key="variable.token"
                                    type="button"
                                    class="rounded border border-csc-line px-2 py-1 font-mono text-xs text-csc-blue transition-colors hover:border-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                    :title="variable.description"
                                    @click="insertVariable(variable.token)"
                                >
                                    {{ variable.token }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- What the first few recipients will actually receive. -->
                    <div v-if="samples.length" class="rounded-lg border border-csc-line bg-csc-mist/40 p-4">
                        <h3 class="mb-2 text-sm font-medium text-csc-ink">Preview</h3>
                        <ul class="space-y-3">
                            <li v-for="sample in samples" :key="sample.email" class="text-sm">
                                <p class="text-xs text-csc-ink/55">
                                    To {{ sample.name }} &lt;{{ sample.email }}&gt;
                                </p>
                                <p class="font-medium text-csc-ink">{{ sample.subject }}</p>
                                <p class="whitespace-pre-line text-csc-ink/75">{{ sample.body }}</p>
                            </li>
                        </ul>
                    </div>

                    <div class="flex flex-wrap justify-end gap-2">
                        <AppButton type="button" variant="ghost" @click="openSaveTemplate">
                            Save as Template
                        </AppButton>
                        <AppButton type="button" variant="ghost" icon="envelope" @click="sendTest">
                            Send Test to Myself
                        </AppButton>
                        <AppButton
                            type="submit"
                            :loading="form.processing"
                            :disabled="audienceCount === 0"
                            icon="envelope"
                        >
                            Queue Announcement
                        </AppButton>
                    </div>
                </form>
            </AppCard>

            <AppCard
                v-if="templates.length"
                title="Templates"
                subtitle="Load one into the form above, then adjust before sending."
            >
                <ul class="divide-y divide-csc-line">
                    <li
                        v-for="template in templates"
                        :key="template.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-csc-ink">
                                {{ template.name }}
                                <span class="ml-1 text-xs font-normal text-csc-ink/55">
                                    {{ template.category }}
                                </span>
                            </p>
                            <p class="truncate text-xs text-csc-ink/60">{{ template.subject }}</p>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <AppButton size="sm" variant="ghost" @click="applyTemplate(template)">
                                Use
                            </AppButton>
                            <AppButton
                                v-if="!template.is_system"
                                size="sm"
                                variant="ghost"
                                icon="close"
                                @click="deleteTemplate(template)"
                            >
                                Delete
                            </AppButton>
                        </div>
                    </li>
                </ul>
            </AppCard>

            <AppCard title="Sent Mail" :padded="logs.data.length > 0">
                <AppEmptyState
                    v-if="!logs.data.length"
                    title="Nothing sent yet"
                    description="Outbound mail is recorded here automatically."
                    icon="envelope"
                />

                <div v-else class="-mx-5 overflow-x-auto sm:-mx-6">
                    <table class="w-full min-w-160 text-left text-sm">
                        <thead class="border-y border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Recipient</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Subject</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Status</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Sent</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-csc-line">
                            <tr v-for="log in logs.data" :key="log.id">
                                <td class="px-5 py-3.5">
                                    <p class="font-medium text-csc-ink">{{ log.recipient_name ?? '—' }}</p>
                                    <p class="mt-0.5 text-xs break-words text-csc-ink/60">
                                        {{ log.recipient_email }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-csc-ink/75">{{ log.subject }}</td>
                                <td class="px-5 py-3.5 text-xs text-csc-ink/70">{{ log.status }}</td>
                                <td class="px-5 py-3.5 text-xs whitespace-nowrap text-csc-ink/70">
                                    {{ log.sent_at ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <AppPagination :pagination="logs" label="emails" class="pt-3" />
            </AppCard>
        </div>

        <AppModal
            :open="savingTemplate"
            title="Save as template"
            subtitle="Keeps the subject and message as written, placeholders included."
            @close="savingTemplate = false"
        >
            <form class="space-y-4" @submit.prevent="saveTemplate">
                <AppInput
                    v-model="templateForm.name"
                    label="Template name"
                    hint="How it will appear in the list — “Payment reminder”, “Venue change”."
                    :error="templateForm.errors.name"
                    required
                />

                <AppSelect
                    v-model="templateForm.category"
                    label="Category"
                    :options="categories.map((c) => ({ value: c, label: c }))"
                    :error="templateForm.errors.category"
                    required
                />

                <AppInput
                    v-model="templateForm.subject"
                    label="Subject"
                    :error="templateForm.errors.subject"
                    required
                />

                <AppTextarea
                    v-model="templateForm.body"
                    label="Message"
                    rows="6"
                    :error="templateForm.errors.body"
                    required
                />

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="savingTemplate = false">
                        Cancel
                    </AppButton>
                    <AppButton type="submit" :processing="templateForm.processing">
                        Save template
                    </AppButton>
                </div>
            </form>
        </AppModal>
    </AuthenticatedLayout>
</template>
