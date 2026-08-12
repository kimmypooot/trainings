<script setup>
import { computed, watch } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppAvatar from '@/Components/AppAvatar.vue';

const props = defineProps({
    options: { type: Object, required: true },
    user: { type: Object, required: true },
    profile: { type: Object, default: null },
});

const page = usePage();
const flash = computed(() => page.props.flash?.success);

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
    agency_unit: props.profile?.agency_unit ?? '',
    sector: props.profile?.sector ?? '',
    region: props.profile?.region ?? '',
    province: props.profile?.province ?? '',
    city_municipality: props.profile?.city_municipality ?? '',
    field_office_id: props.profile?.field_office_id ?? '',
    position_level: props.profile?.position_level ?? '',
    employment_status: props.profile?.employment_status ?? '',
    organization_address: props.profile?.organization_address ?? '',
    home_address: props.profile?.home_address ?? '',
    food_restrictions_details: props.profile?.food_restrictions_details ?? '',

    consent: true,
});

const submit = () => form.put('/profile');
</script>

<template>
    <Head title="My Profile" />

    <AuthenticatedLayout title="My Profile" current="profile">
        <div class="mx-auto max-w-3xl space-y-6">
            <AppAlert v-if="flash" tone="success">{{ flash }}</AppAlert>

            <!-- Identity summary -->
            <AppCard>
                <div class="flex items-center gap-4">
                    <AppAvatar :name="user.name" size="lg" />
                    <div class="min-w-0">
                        <p class="truncate text-lg font-semibold text-csc-blue">{{ user.name ?? '—' }}</p>
                        <p class="truncate text-sm text-csc-ink/70">{{ user.email }}</p>
                        <p class="mt-1.5 inline-block rounded-full bg-csc-blue-tint px-2.5 py-0.5 text-xs font-medium text-csc-blue">
                            {{ user.role_label }}
                        </p>
                    </div>
                </div>
            </AppCard>

            <p
                v-if="Object.keys(form.errors).length"
                class="flex items-start gap-2 rounded-xl border border-danger/25 bg-danger-soft px-4 py-3 text-sm font-medium text-danger"
                role="alert"
            >
                Please review the highlighted fields below.
            </p>

            <form class="space-y-6" novalidate @submit.prevent="submit">
                <AppCard title="Personal Information" subtitle="Records are kept in uppercase.">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <AppInput
                            v-model="form.first_name"
                            label="First Name"
                            autocomplete="given-name"
                            :error="form.errors.first_name"
                            uppercase
                            required
                        />
                        <AppInput
                            v-model="form.middle_name"
                            label="Middle Name"
                            hint="Optional. Shown as an initial on certificates."
                            :error="form.errors.middle_name"
                            uppercase
                        />

                        <AppInput
                            v-model="form.last_name"
                            label="Last Name"
                            autocomplete="family-name"
                            :error="form.errors.last_name"
                            uppercase
                            required
                        />
                        <AppSelect
                            v-model="form.suffix"
                            label="Suffix"
                            :options="options.suffixes"
                            placeholder="None"
                            :error="form.errors.suffix"
                        />
                        <AppInput
                            v-model="form.date_of_birth"
                            label="Date of Birth"
                            type="date"
                            :error="form.errors.date_of_birth"
                            required
                        />

                        <AppSelect
                            v-model="form.sex"
                            label="Sex"
                            :options="options.sexes"
                            :error="form.errors.sex"
                            required
                        />
                        <AppSelect
                            v-model="form.civil_status"
                            label="Civil Status"
                            :options="options.civilStatuses"
                            :error="form.errors.civil_status"
                            required
                        />

                        <AppSelect
                            v-model="form.is_pwd"
                            label="Person with Disability (PWD)"
                            :options="options.yesNo"
                            :error="form.errors.is_pwd"
                            required
                        />
                        <AppInput
                            v-model="form.mobile_number"
                            label="Mobile Number"
                            type="tel"
                            :error="form.errors.mobile_number"
                            required
                        />
                    </div>
                </AppCard>

                <AppCard title="Employment Details">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <AppInput
                            v-model="form.position_title"
                            label="Position Title"
                            :error="form.errors.position_title"
                            uppercase
                            required
                        />
                        <AppSelect
                            v-model="form.salary_grade"
                            label="Salary Grade"
                            :options="options.salaryGrades"
                            :error="form.errors.salary_grade"
                            required
                        />

                        <div class="sm:col-span-2">
                            <AppInput
                                v-model="form.organization_name"
                                label="Name of Agency / Company / Organization"
                                :error="form.errors.organization_name"
                                uppercase
                                required
                            />
                        </div>

                        <AppInput
                            v-model="form.agency_unit"
                            label="Agency Unit / Division"
                            hint="Optional."
                            :error="form.errors.agency_unit"
                            uppercase
                        />
                        <AppSelect
                            v-model="form.sector"
                            label="Sector"
                            :options="options.sectors"
                            :error="form.errors.sector"
                            required
                        />

                        <AppInput
                            v-model="form.region"
                            label="Region"
                            :error="form.errors.region"
                            uppercase
                            required
                        />
                        <AppInput
                            v-model="form.province"
                            label="Province"
                            :error="form.errors.province"
                            uppercase
                            required
                        />
                        <AppInput
                            v-model="form.city_municipality"
                            label="City / Municipality"
                            :error="form.errors.city_municipality"
                            uppercase
                            required
                        />
                        <AppSelect
                            v-model="form.field_office_id"
                            label="CSC Field Office"
                            :options="options.fieldOffices"
                            :error="form.errors.field_office_id"
                            required
                        />

                        <AppSelect
                            v-model="form.position_level"
                            label="Position Level"
                            :options="options.positionLevels"
                            :error="form.errors.position_level"
                            required
                        />
                        <AppSelect
                            v-model="form.employment_status"
                            label="Employment Status"
                            :options="options.employmentStatuses"
                            :error="form.errors.employment_status"
                            required
                        />

                        <div class="sm:col-span-2">
                            <label for="organization_address" class="mb-1.5 block text-sm font-medium text-csc-ink">
                                Agency / Company / Organization Address
                                <span class="text-danger" aria-hidden="true">*</span>
                            </label>
                            <textarea
                                id="organization_address"
                                v-model="form.organization_address"
                                rows="3"
                                class="w-full rounded-lg border bg-white px-4 py-2.5 text-sm text-csc-ink uppercase transition-colors duration-150 focus:outline-2 focus:outline-offset-1"
                                :class="
                                    form.errors.organization_address
                                        ? 'border-danger focus:outline-danger'
                                        : 'border-csc-line hover:border-csc-blue/40 focus:border-csc-blue focus:outline-csc-blue'
                                "
                                @input="form.organization_address = $event.target.value.toUpperCase()"
                            />
                            <p v-if="form.errors.organization_address" class="mt-1.5 text-xs font-medium text-danger">
                                {{ form.errors.organization_address }}
                            </p>
                        </div>

                        <div class="sm:col-span-2">
                            <AppInput
                                v-model="form.home_address"
                                label="Home Address"
                                hint="Optional."
                                :error="form.errors.home_address"
                                uppercase
                            />
                        </div>

                        <div class="sm:col-span-2">
                            <AppInput
                                v-model="form.food_restrictions_details"
                                label="Food Restrictions / Allergies"
                                placeholder="e.g. VEGETARIAN, NO SEAFOOD, ALLERGIC TO NUTS"
                                hint="Leave blank if you have none."
                                :error="form.errors.food_restrictions_details"
                                uppercase
                            />
                        </div>
                    </div>
                </AppCard>

                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <AppButton href="/dashboard" variant="ghost" size="lg">Cancel</AppButton>
                    <AppButton type="submit" size="lg" :loading="form.processing">
                        {{ form.processing ? 'Saving…' : 'Save Changes' }}
                    </AppButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
