<script setup>
import { computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppAvatar from '@/Components/AppAvatar.vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppInput from '@/Components/AppInput.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTextarea from '@/Components/AppTextarea.vue';

/**
 * v1 folded this into the participants modal, which flipped between a read and
 * a write view of the same fields. v2 gives it a page of its own: twenty-odd
 * fields and a three-level geography cascade do not fit a dialog, and a page
 * can be linked to, reloaded, and validated without losing what was typed.
 *
 * Everything here is the participant's *record*. The parts that are theirs to
 * hold — photo, password, linked Google account, consent — are deliberately
 * absent; an administrator corrects what the office knows, not who they are.
 */
const props = defineProps({
    options: { type: Object, required: true },
    geography: { type: Array, required: true },
    participant: { type: Object, required: true },
    profile: { type: Object, default: null },
});

// A value typed before the PSGC pickers existed may not match the canonical
// spelling ("REGION VIII" vs "Region VIII (Eastern Visayas)"). Resolve it
// case-insensitively so the select pre-selects instead of showing a blank.
const normalizeName = (value, list) =>
    list.find((name) => String(name).toLowerCase() === String(value ?? '').trim().toLowerCase()) ?? value ?? '';

const regionInit = normalizeName(props.profile?.region, props.geography.map((region) => region.name));
const regionNode = props.geography.find((region) => region.name === regionInit) ?? null;
const provinceInit = regionNode
    ? normalizeName(props.profile?.province, regionNode.provinces.map((province) => province.name))
    : (props.profile?.province ?? '');
const provinceNode = regionNode?.provinces.find((province) => province.name === provinceInit) ?? null;
const cityInit = provinceNode
    ? normalizeName(props.profile?.city_municipality, provinceNode.cities)
    : (props.profile?.city_municipality ?? '');

const form = useForm({
    first_name: props.profile?.first_name ?? '',
    middle_name: props.profile?.middle_name ?? '',
    last_name: props.profile?.last_name ?? '',
    suffix: props.profile?.suffix ?? '',
    date_of_birth: props.profile?.date_of_birth ?? '',
    sex: props.profile?.sex ?? '',
    is_pwd: props.profile?.is_pwd ?? '',
    civil_status: props.profile?.civil_status ?? '',
    mobile_number: props.profile?.mobile_number ?? '',

    position_title: props.profile?.position_title ?? '',
    salary_grade: props.profile?.salary_grade ?? '',
    organization_name: props.profile?.organization_name ?? '',
    sector: props.profile?.sector ?? '',
    region: regionInit,
    province: provinceInit,
    city_municipality: cityInit,
    field_office_id: props.profile?.field_office_id ?? '',
    position_level: props.profile?.position_level ?? '',
    employment_status: props.profile?.employment_status ?? '',
    organization_address: props.profile?.organization_address ?? '',
    food_restrictions_details: props.profile?.food_restrictions_details ?? '',
});

// Region → Province → City/Municipality cascade, fed by the PSGC reference.
// Each select only lists the children of the pick above it; changing a parent
// clears whatever was chosen below. A value that is not in the dataset (typed
// before the pickers existed) is kept visible rather than silently dropped.
const currentRegion = computed(() => props.geography.find((region) => region.name === form.region) ?? null);
const currentProvince = computed(
    () => currentRegion.value?.provinces.find((province) => province.name === form.province) ?? null
);

const regionOptions = computed(() => props.geography.map((region) => region.name));

const provinceOptions = computed(() => {
    const list = currentRegion.value?.provinces.map((province) => province.name) ?? [];
    if (form.province && !list.includes(form.province)) return [...list, form.province];

    return list;
});

const cityOptions = computed(() => {
    const list = currentProvince.value?.cities ?? [];
    if (form.city_municipality && !list.includes(form.city_municipality)) return [...list, form.city_municipality];

    return list;
});

watch(
    () => form.region,
    (value, old) => {
        if (value === old) return;
        form.province = '';
        form.city_municipality = '';
    }
);

watch(
    () => form.province,
    (value, old) => {
        if (value === old) return;
        form.city_municipality = '';
    }
);

const todayIso = new Date().toISOString().slice(0, 10);

const submit = () => form.put(`/admin/participants/${props.participant.id}`);
</script>

<template>
    <Head :title="`Edit ${participant.name ?? participant.email}`" />

    <AuthenticatedLayout title="Edit Participant" current="admin-participants">
        <div class="mx-auto max-w-4xl space-y-5">
            <Link
                :href="participant.show_url"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-csc-blue hover:text-csc-blue-deep"
            >
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M15 6l-6 6 6 6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                Back to participant
            </Link>

            <AppCard>
                <div class="flex items-center gap-4">
                    <AppAvatar :name="participant.name" :src="participant.avatar" size="lg" />
                    <div class="min-w-0">
                        <p class="text-lg font-semibold text-csc-blue">{{ participant.name ?? '—' }}</p>
                        <p class="text-sm text-csc-ink/70">{{ participant.email }}</p>
                    </div>
                </div>
            </AppCard>

            <AppAlert v-if="!profile" tone="warning">
                This participant has not filled in a profile yet. Saving this form completes it on their behalf.
            </AppAlert>

            <AppAlert tone="info">
                Changes are recorded against the participant's own profile, and their display name follows the name
                entered here. The participant's consent record, password, and photo are left untouched.
            </AppAlert>

            <form class="space-y-6" novalidate @submit.prevent="submit">
                <AppCard title="Personal Information" subtitle="Free-text records are kept in uppercase.">
                    <div class="grid gap-5 sm:grid-cols-12">
                        <div class="sm:col-span-4">
                            <AppInput
                                v-model="form.first_name"
                                label="First Name"
                                placeholder="e.g. JUAN"
                                maxlength="255"
                                :error="form.errors.first_name"
                                uppercase
                                required
                            />
                        </div>
                        <div class="sm:col-span-4">
                            <AppInput
                                v-model="form.middle_name"
                                label="Middle Name"
                                placeholder="e.g. DIZON"
                                maxlength="64"
                                hint="Optional. Shown as an initial on certificates."
                                :error="form.errors.middle_name"
                                uppercase
                            />
                        </div>
                        <div class="sm:col-span-4">
                            <AppInput
                                v-model="form.last_name"
                                label="Last Name"
                                placeholder="e.g. DELA CRUZ"
                                maxlength="255"
                                :error="form.errors.last_name"
                                uppercase
                                required
                            />
                        </div>

                        <div class="sm:col-span-3">
                            <AppSelect
                                v-model="form.suffix"
                                label="Suffix"
                                :options="options.suffixes"
                                placeholder="None"
                                :error="form.errors.suffix"
                            />
                        </div>
                        <div class="sm:col-span-3">
                            <AppInput
                                v-model="form.date_of_birth"
                                label="Date of Birth"
                                type="date"
                                :max="todayIso"
                                :error="form.errors.date_of_birth"
                                required
                            />
                        </div>
                        <div class="sm:col-span-6">
                            <AppInput
                                v-model="form.mobile_number"
                                label="Mobile Number"
                                type="tel"
                                inputmode="numeric"
                                maxlength="11"
                                placeholder="09XX XXX XXXX"
                                hint="PH mobile number starting with 09, e.g. 0917 123 4567."
                                :error="form.errors.mobile_number"
                                required
                            />
                        </div>

                        <div class="sm:col-span-4">
                            <AppSelect
                                v-model="form.sex"
                                label="Sex"
                                :options="options.sexes"
                                :error="form.errors.sex"
                                required
                            />
                        </div>
                        <div class="sm:col-span-4">
                            <AppSelect
                                v-model="form.civil_status"
                                label="Civil Status"
                                :options="options.civilStatuses"
                                :error="form.errors.civil_status"
                                required
                            />
                        </div>
                        <div class="sm:col-span-4">
                            <AppSelect
                                v-model="form.is_pwd"
                                label="Person with Disability (PWD)"
                                :options="options.yesNo"
                                :error="form.errors.is_pwd"
                                required
                            />
                        </div>

                        <div class="sm:col-span-12">
                            <AppInput
                                v-model="form.food_restrictions_details"
                                label="Food Restrictions / Allergies"
                                placeholder="e.g. VEGETARIAN, NO SEAFOOD, ALLERGIC TO NUTS"
                                maxlength="500"
                                hint="Leave blank if there are none."
                                :error="form.errors.food_restrictions_details"
                                uppercase
                            />
                        </div>
                    </div>
                </AppCard>

                <AppCard title="Employment Details">
                    <div class="grid gap-5 sm:grid-cols-12">
                        <div class="sm:col-span-9">
                            <AppInput
                                v-model="form.position_title"
                                label="Position Title"
                                placeholder="e.g. ADMINISTRATIVE OFFICER III"
                                maxlength="255"
                                hint="Enter the full title — do not abbreviate."
                                :error="form.errors.position_title"
                                uppercase
                                required
                            />
                        </div>
                        <div class="sm:col-span-3">
                            <AppSelect
                                v-model="form.salary_grade"
                                label="Salary Grade"
                                :options="options.salaryGrades"
                                :error="form.errors.salary_grade"
                                required
                            />
                        </div>

                        <div class="sm:col-span-6">
                            <AppSelect
                                v-model="form.position_level"
                                label="Position Level"
                                :options="options.positionLevels"
                                :error="form.errors.position_level"
                                required
                            />
                        </div>
                        <div class="sm:col-span-6">
                            <AppSelect
                                v-model="form.employment_status"
                                label="Nature of Appointment"
                                :options="options.employmentStatuses"
                                :error="form.errors.employment_status"
                                required
                            />
                        </div>

                        <div class="sm:col-span-12">
                            <AppInput
                                v-model="form.organization_name"
                                label="Name of Agency / Company / Organization"
                                placeholder="e.g. DEPARTMENT OF EDUCATION"
                                maxlength="255"
                                hint="Enter the full name — do not abbreviate."
                                :error="form.errors.organization_name"
                                uppercase
                                required
                            />
                        </div>

                        <div class="sm:col-span-6">
                            <AppSelect
                                v-model="form.sector"
                                label="Sector"
                                :options="options.sectors"
                                :error="form.errors.sector"
                                required
                            />
                        </div>
                        <div class="sm:col-span-6">
                            <AppSelect
                                v-model="form.field_office_id"
                                label="CSC Field Office"
                                :options="options.fieldOffices"
                                hint="Decides which field office sees this participant."
                                :error="form.errors.field_office_id"
                                required
                            />
                        </div>

                        <div class="sm:col-span-12">
                            <AppTextarea
                                v-model="form.organization_address"
                                label="Agency / Company / Organization Address"
                                :rows="3"
                                maxlength="500"
                                placeholder="e.g. GOVERNMENT CENTER, PALO, LEYTE"
                                uppercase
                                :error="form.errors.organization_address"
                                required
                            />
                        </div>
                    </div>
                </AppCard>

                <AppCard title="Location">
                    <div class="grid gap-5 sm:grid-cols-12">
                        <div class="sm:col-span-4">
                            <AppSelect
                                v-model="form.region"
                                label="Region"
                                :options="regionOptions"
                                placeholder="Select a region"
                                :error="form.errors.region"
                                required
                            />
                        </div>
                        <div class="sm:col-span-4">
                            <AppSelect
                                v-model="form.province"
                                label="Province"
                                :options="provinceOptions"
                                placeholder="Select a province"
                                :disabled="!form.region"
                                :error="form.errors.province"
                                required
                            />
                        </div>
                        <div class="sm:col-span-4">
                            <AppSelect
                                v-model="form.city_municipality"
                                label="City / Municipality"
                                :options="cityOptions"
                                placeholder="Select a city / municipality"
                                :disabled="!form.province"
                                :error="form.errors.city_municipality"
                                required
                            />
                        </div>
                    </div>
                </AppCard>

                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <AppButton :href="participant.show_url" variant="ghost" size="lg">Cancel</AppButton>
                    <AppButton type="submit" size="lg" :loading="form.processing">Save Changes</AppButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
