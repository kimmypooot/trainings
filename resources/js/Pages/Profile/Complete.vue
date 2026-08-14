<script setup>
import { watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLogo from '@/Components/AppLogo.vue';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AppSelect from '@/Components/AppSelect.vue';

const props = defineProps({
    options: { type: Object, required: true },
    user: { type: Object, required: true },
});

const form = useForm({
    first_name: '',
    middle_name: '',
    last_name: '',
    suffix: '',
    date_of_birth: '',
    sex: '',
    is_pwd: '',
    civil_status: '',
    mobile_number: '',

    position_title: '',
    salary_grade: '',
    organization_name: '',
    agency_unit: '',
    sector: '',
    region: '',
    province: '',
    city_municipality: '',
    field_office_id: '',
    position_level: '',
    employment_status: '',
    organization_address: '',
    home_address: '',
    food_restrictions_details: '',

    consent: false,
});

const submit = () => form.post('/profile/complete');
</script>

<template>
    <Head title="Complete your profile" />

    <div class="min-h-screen bg-csc-blue-tint">
        <!-- Header -->
        <header class="bg-csc-blue">
            <div class="mx-auto flex max-w-4xl items-center justify-between gap-4 px-4 py-5 sm:px-6">
                <AppLogo variant="light" size="md" />
                <button
                    type="button"
                    class="rounded text-sm font-medium text-white/70 transition-colors hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                    @click="router.post('/logout')"
                >
                    Sign out
                </button>
            </div>
        </header>

        <main class="mx-auto max-w-4xl px-4 py-10 sm:px-6 sm:py-14">
            <div class="mb-8">
                <span class="flex items-center gap-2" aria-hidden="true">
                    <span class="inline-block h-1 w-12 bg-csc-blue" />
                    <span class="inline-block h-1 w-4 bg-csc-red" />
                </span>
                <h1 class="mt-6 text-2xl font-semibold tracking-tight text-csc-blue sm:text-3xl">
                    Complete your profile
                </h1>
                <p class="mt-3 text-sm leading-relaxed text-csc-ink/70">
                    Signed in as {{ props.user.email }}. We need a few details before you can register for
                    trainings. Records are kept in uppercase.
                </p>
            </div>

            <p
                v-if="Object.keys(form.errors).length"
                class="mb-6 flex items-start gap-2 rounded-lg border border-csc-red-ink/30 bg-csc-red-ink/5 px-4 py-3 text-sm font-medium text-csc-red-ink"
                role="alert"
            >
                <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M12 7v6M12 16.5v.5" stroke-linecap="round" />
                </svg>
                Please review the highlighted fields below.
            </p>

            <form class="space-y-8" novalidate @submit.prevent="submit">
                <!-- Personal information -->
                <fieldset class="rounded-xl border border-csc-line bg-white p-6 sm:p-8">
                    <legend class="px-2 text-sm font-semibold tracking-wide text-csc-blue uppercase">
                        Personal Information
                    </legend>

                    <div class="mt-4 grid gap-5 sm:grid-cols-2">
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
                            autocomplete="additional-name"
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
                            :options="props.options.suffixes"
                            placeholder="None"
                            :error="form.errors.suffix"
                        />
                        <AppInput
                            v-model="form.date_of_birth"
                            label="Date of Birth"
                            type="date"
                            autocomplete="bday"
                            :error="form.errors.date_of_birth"
                            required
                        />

                        <AppSelect
                            v-model="form.sex"
                            label="Sex"
                            :options="props.options.sexes"
                            :error="form.errors.sex"
                            required
                        />
                        <AppSelect
                            v-model="form.civil_status"
                            label="Civil Status"
                            :options="props.options.civilStatuses"
                            :error="form.errors.civil_status"
                            required
                        />

                        <AppSelect
                            v-model="form.is_pwd"
                            label="Person with Disability (PWD)"
                            :options="props.options.yesNo"
                            :error="form.errors.is_pwd"
                            required
                        />
                        <AppInput
                            v-model="form.mobile_number"
                            label="Mobile Number"
                            type="tel"
                            autocomplete="tel"
                            placeholder="09XX XXX XXXX"
                            :error="form.errors.mobile_number"
                            required
                        />
                    </div>
                </fieldset>

                <!-- Employment details -->
                <fieldset class="rounded-xl border border-csc-line bg-white p-6 sm:p-8">
                    <legend class="px-2 text-sm font-semibold tracking-wide text-csc-blue uppercase">
                        Employment Details
                    </legend>

                    <div class="mt-4 grid gap-5 sm:grid-cols-2">
                        <AppInput
                            v-model="form.position_title"
                            label="Position Title"
                            autocomplete="organization-title"
                            :error="form.errors.position_title"
                            uppercase
                            required
                        />
                        <AppSelect
                            v-model="form.salary_grade"
                            label="Salary Grade"
                            :options="props.options.salaryGrades"
                            :error="form.errors.salary_grade"
                            required
                        />

                        <div class="sm:col-span-2">
                            <AppInput
                                v-model="form.organization_name"
                                label="Name of Agency / Company / Organization"
                                autocomplete="organization"
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
                            :options="props.options.sectors"
                            :error="form.errors.sector"
                            required
                        />

                        <AppInput
                            v-model="form.region"
                            label="Region"
                            placeholder="e.g. REGION VIII"
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
                            :options="props.options.fieldOffices"
                            :error="form.errors.field_office_id"
                            required
                        />

                        <AppSelect
                            v-model="form.position_level"
                            label="Position Level"
                            :options="props.options.positionLevels"
                            :error="form.errors.position_level"
                            required
                        />
                        <AppSelect
                            v-model="form.employment_status"
                            label="Employment Status"
                            :options="props.options.employmentStatuses"
                            :error="form.errors.employment_status"
                            required
                        />

                        <div class="sm:col-span-2">
                            <label for="organization_address" class="mb-1.5 block text-sm font-medium text-csc-ink">
                                Agency / Company / Organization Address
                                <span class="text-csc-red-ink" aria-hidden="true">*</span>
                            </label>
                            <textarea
                                id="organization_address"
                                v-model="form.organization_address"
                                rows="3"
                                required
                                :aria-invalid="form.errors.organization_address ? 'true' : undefined"
                                aria-describedby="organization_address-error"
                                class="w-full rounded-lg border bg-white px-4 py-2.5 text-sm text-csc-ink uppercase transition-colors duration-150 placeholder:text-csc-ink/40 focus:outline-2 focus:outline-offset-1"
                                :class="
                                    form.errors.organization_address
                                        ? 'border-csc-red-ink focus:outline-csc-red-ink'
                                        : 'border-csc-line hover:border-csc-blue/40 focus:border-csc-blue focus:outline-csc-blue'
                                "
                                @input="form.organization_address = $event.target.value.toUpperCase()"
                            />
                            <p
                                v-if="form.errors.organization_address"
                                id="organization_address-error"
                                class="mt-1.5 text-xs font-medium text-csc-red-ink"
                            >
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
                                hint="Leave blank if you have none. Used for meal planning at events."
                                :error="form.errors.food_restrictions_details"
                                uppercase
                            />
                        </div>
                    </div>
                </fieldset>

                <!-- Consent -->
                <div class="rounded-xl border border-csc-line bg-white p-6 sm:p-8">
                    <label class="flex items-start gap-3 text-sm text-csc-ink">
                        <input
                            v-model="form.consent"
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0 rounded border-csc-line accent-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            :aria-invalid="form.errors.consent ? 'true' : undefined"
                            aria-describedby="consent-error"
                        />
                        <span class="leading-relaxed">
                            I hereby agree and give consent for the processing of my personal data information, as
                            well as agree to be bound by the
                            <Link href="/privacy-policy" class="font-medium text-csc-blue hover:text-csc-red-ink">
                                Privacy Policy </Link
                            >.
                        </span>
                    </label>
                    <p v-if="form.errors.consent" id="consent-error" class="mt-2 text-xs font-medium text-csc-red-ink">
                        {{ form.errors.consent }}
                    </p>

                    <AppButton type="submit" size="lg" block class="mt-6" :loading="form.processing" icon="check">
                        {{ form.processing ? 'Saving…' : 'Save and continue' }}
                    </AppButton>
                </div>
            </form>
        </main>
    </div>
</template>
