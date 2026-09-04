<script setup>
/**
 * The office's own identity — what this deployment calls itself.
 *
 * These were .env settings, so changing the office telephone number needed
 * server access and a config:cache clear. That is a clerical fact behind a
 * developer, which is the wrong way round; this codebase ships one copy per
 * regional office and every one of them has a different answer.
 *
 * The form is deliberately ordered by consequence: the fields that only affect
 * what a reader sees come first, and the two that change behaviour — the region
 * the office serves, and the certificate number prefix — are separated out
 * below with their own warnings.
 */
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppInput from '@/Components/AppInput.vue';
import AppSelect from '@/Components/AppSelect.vue';

const props = defineProps({
    office: { type: Object, required: true },
    regions: { type: Array, default: () => [] },
    certificatePrefixLocked: { type: Boolean, default: false },
    updated: { type: Object, default: null },
});

const form = useForm({
    name: props.office.name ?? '',
    short_name: props.office.short_name ?? '',
    region: props.office.region ?? '',
    psgc_region: props.office.psgc_region ?? '',
    address: props.office.address ?? '',
    phone: props.office.phone ?? '',
    email: props.office.email ?? '',
    certificate_prefix: props.office.certificate_prefix ?? '',
});

// Shown beside the region picker so the consequence of changing it is on the
// screen rather than in the documentation.
const regionChanged = computed(
    () => form.psgc_region !== (props.office.psgc_region ?? '')
);

const submit = () => form.post('/admin/office', { preserveScroll: true });
</script>

<template>
    <Head title="Office Details" />

    <AuthenticatedLayout title="Office Details" current="admin-office">
        <div class="mx-auto max-w-3xl space-y-5">
            <AppAlert tone="info" title="This is how the system introduces itself">
                These details appear in the site footer, in every email sent from here, on the
                maintenance notice, and on issued certificates. A certificate is created once and
                kept, so the office named on one cannot be corrected afterwards — set these before
                releasing any.
            </AppAlert>

            <form @submit.prevent="submit">
                <AppCard title="Name and contact">
                    <div class="space-y-4">
                        <AppInput
                            v-model="form.name"
                            label="Official name"
                            required
                            hint="The full name, as it should read on a certificate."
                            :error="form.errors.name"
                        />

                        <AppInput
                            v-model="form.short_name"
                            label="Short name"
                            required
                            hint="Used where the full name will not fit — the sidebar and the certificate signature line."
                            :error="form.errors.short_name"
                        />

                        <AppInput
                            v-model="form.region"
                            label="Region, as written"
                            hint="The plain name, for reading rather than matching. Shown in the footer and on certificates."
                            :error="form.errors.region"
                        />

                        <AppInput
                            v-model="form.address"
                            label="Address"
                            :error="form.errors.address"
                        />

                        <div class="grid gap-4 sm:grid-cols-2">
                            <AppInput
                                v-model="form.phone"
                                label="Telephone"
                                hint="Left blank, no number is shown at all."
                                :error="form.errors.phone"
                            />

                            <AppInput
                                v-model="form.email"
                                type="email"
                                label="Email"
                                hint="The address participants are told to write to."
                                :error="form.errors.email"
                            />
                        </div>
                    </div>
                </AppCard>

                <AppCard title="What this office covers" class="mt-5">
                    <AppSelect
                        v-model="form.psgc_region"
                        label="Region served"
                        required
                        :options="regions"
                        placeholder="Choose a region…"
                        hint="Chosen from the official list so it matches the regions on participants' profiles."
                        :error="form.errors.psgc_region"
                    />

                    <AppAlert v-if="regionChanged" tone="warning" class="mt-4">
                        Changing this changes who counts as being outside the region. Participants
                        outside it may ask for their official receipt to be posted to them; those
                        inside are expected to collect it. Existing requests are unaffected.
                    </AppAlert>
                </AppCard>

                <AppCard title="Certificate numbering" class="mt-5">
                    <AppInput
                        v-model="form.certificate_prefix"
                        label="Certificate number prefix"
                        required
                        :disabled="certificatePrefixLocked"
                        :hint="
                            certificatePrefixLocked
                                ? undefined
                                : 'Printed on every certificate — a prefix of CSC8 gives CSC8-2026-000042.'
                        "
                        :error="form.errors.certificate_prefix"
                    />

                    <AppAlert v-if="certificatePrefixLocked" tone="info" class="mt-4">
                        This is fixed now that certificates have been issued. Changing it would
                        leave a permanent break in the numbering, and numbers already given out
                        have to keep matching the printed copies.
                    </AppAlert>
                </AppCard>

                <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                    <p v-if="updated?.by" class="text-sm text-csc-ink-subtle">
                        Last changed by {{ updated.by }} on {{ updated.at }}.
                    </p>
                    <p v-else class="text-sm text-csc-ink-subtle">
                        Not changed yet — these are the details the system was installed with.
                    </p>

                    <AppButton type="submit" icon="check" :disabled="form.processing">
                        Save office details
                    </AppButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
