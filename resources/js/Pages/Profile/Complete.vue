<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLogo from '@/Components/AppLogo.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppInput from '@/Components/AppInput.vue';
import AppModal from '@/Components/AppModal.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTextarea from '@/Components/AppTextarea.vue';

const props = defineProps({
    options: { type: Object, required: true },
    geography: { type: Array, required: true },
    user: { type: Object, required: true },
    registration_complete: { type: Boolean, default: false },
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
    sector: '',
    region: '',
    province: '',
    city_municipality: '',
    field_office_id: '',
    position_level: '',
    employment_status: '',
    organization_address: '',
    food_restrictions_details: '',

    consent: false,
});

// A first-time registration that just went through lands back here with the
// profile saved and the email unverified. The form is replaced by a "Registration
// Successful" modal pointing the participant at the emailed verification link;
// derived from durable server state so the modal survives a resend round-trip.
const successDismissed = ref(false);

// The two-step submit: the form is reviewed in a read-only modal before the
// POST that locks the registration in.
const previewOpen = ref(false);

const resent = ref(false);
const resendError = ref('');
const sending = ref(false);

const resendVerification = () => {
    sending.value = true;
    resent.value = false;
    resendError.value = '';

    router.post('/email/verification-notification', {}, {
        preserveScroll: true,
        onSuccess: () => {
            resent.value = true;
        },
        onError: () => {
            resendError.value = 'Could not resend the link right now. Try again shortly.';
        },
        onFinish: () => {
            sending.value = false;
        },
    });
};

const fieldOfficeLabel = computed(
    () =>
        props.options.fieldOffices.find((office) => String(office.value) === String(form.field_office_id))?.label ??
        form.field_office_id
);

const fullName = computed(
    () => [form.first_name, form.middle_name, form.last_name].filter(Boolean).join(' ') + (form.suffix ? ` ${form.suffix}` : '')
);

const previewSections = computed(() => [
    {
        title: 'Personal Information',
        anchor: 'section-personal',
        /*
         * Full Name is not in this grid. It leads the modal instead — see the
         * template. It is the only field here whose cost of being wrong is not
         * borne by editing it later: ProfileService::save copies fullName()
         * onto the user record, and that string is printed into the certificate
         * PDF, which is rendered once at release and stored. Every other value
         * on this screen can be corrected at /profile with one click and no
         * consequence; this one, once a certificate exists, needs the office to
         * re-issue the document.
         */
        rows: [
            { label: 'Date of Birth', value: form.date_of_birth },
            { label: 'Sex', value: form.sex },
            { label: 'Civil Status', value: form.civil_status },
            { label: 'Person with Disability (PWD)', value: form.is_pwd },
            { label: 'Mobile Number', value: form.mobile_number },
            { label: 'Region', value: form.region },
            { label: 'Province', value: form.province },
            { label: 'City / Municipality', value: form.city_municipality },
            { label: 'Food Restrictions / Allergies', value: form.food_restrictions_details || 'None' },
        ],
    },
    {
        title: 'Employment Details',
        anchor: 'section-employment',
        rows: [
            {
                label: 'Employment Type',
                value: employmentType.value === 'private' ? 'Private sector / Others' : 'Government employee',
            },
            { label: 'Position Title', value: form.position_title },
            { label: 'Salary Grade', value: form.salary_grade },
            { label: 'Position Level', value: form.position_level },
            { label: 'Employment Status', value: form.employment_status },
            { label: 'Name of Agency / Organization', value: form.organization_name },
            { label: 'Sector', value: form.sector },
            { label: 'CSC Field Office', value: fieldOfficeLabel.value },
            { label: 'Organization Address', value: form.organization_address },
        ],
    },
    {
        title: 'Consent',
        anchor: 'section-consent',
        /*
         * One row, and the statement is the row.
         *
         * This used to carry a "Personal Data Consent" label above the
         * sentence, sitting directly under a section heading that already read
         * "Consent" — three words of chrome introducing one line that says the
         * same thing. Every other row in this modal pairs a field name with a
         * value the person typed; this one has no field name, because the
         * sentence is the whole of it.
         *
         * `label` is kept for assistive technology only (see the sr-only dt
         * below): a <dd> with no <dt> is not a valid description list, so the
         * pairing stays in the markup even though it is not drawn.
         */
        rows: [
            {
                label: 'Personal Data Consent',
                labelHidden: true,
                value: 'I consent to the processing of my personal data.',
            },
        ],
    },
]);

const openPreview = () => {
    localErrors.value = validate();

    if (hasLocalErrors()) {
        nextTick(() => {
            const invalid = formEl.value?.querySelector('[aria-invalid="true"]');
            invalid?.focus({ preventScroll: true });
            invalid?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        });
        return;
    }

    previewOpen.value = true;
};

/*
 * Leave the review and land on the part being corrected.
 *
 * The only reason to read this modal is to catch a wrong value, and until now
 * catching one closed the modal at whatever scroll position the form happened
 * to be at — which, after the submit bar opened the preview, is the bottom.
 * Someone who spotted a mistyped salary grade had to go and find it again among
 * nineteen fields, and the further down the modal the mistake was, the further
 * the hunt. A review step that makes acting on the review expensive is a speed
 * bump wearing a confirmation's clothes.
 *
 * Focus follows the scroll rather than only the viewport moving: a keyboard or
 * screen-reader user dismissing the modal would otherwise be returned to the
 * top of the document with no indication anything had happened.
 */
const editSection = (anchor) => {
    previewOpen.value = false;

    nextTick(() => {
        const section = document.getElementById(anchor);
        if (!section) return;

        section.scrollIntoView({ block: 'start', behavior: 'smooth' });

        // The heading is not focusable on its own, so aim at the first control
        // in the group — that is where a correction actually starts. tabindex
        // -1 on the section is the fallback when a group holds no control.
        const target = section.querySelector('input, select, textarea') ?? section;
        target.focus({ preventScroll: true });
    });
};

// Employment classification gate: answers whether the participant is a
// government employee, which decides how the employment fields behave.
const employmentType = ref(null);
const isPrivate = computed(() => employmentType.value === 'private');

// Non-government roles are grouped under "Private" (lenient): salary grade and
// position level do not apply, but sector stays pickable — Private Sector, an
// NGO, or Other — rather than being forced to one value.
const privateSectorOptions = ['Private Sector', 'Non-Government Organization', 'Other'];

const applyPrivate = () => {
    form.salary_grade = 'Not Applicable';
    form.position_level = 'Not Applicable';
    form.employment_status = 'Others';
    if (!form.sector) form.sector = 'Private Sector';
};

const applyGovernment = () => {
    if (form.salary_grade === 'Not Applicable') form.salary_grade = '';
    if (form.position_level === 'Not Applicable') form.position_level = '';
    if (form.employment_status === 'Others') form.employment_status = '';
    if (form.sector === 'Private Sector') form.sector = '';
};

watch(employmentType, (value) => {
    delete localErrors.value.employmentType;

    if (value === 'private') applyPrivate();
    else if (value === 'government') applyGovernment();
});

// Region → Province → City/Municipality cascade, fed by the PSGC reference.
// Each select only lists the children of the pick above it; changing a parent
// clears whatever was chosen below. A value that is not in the dataset (typed
// before the pickers existed) is kept visible rather than silently dropped, so
// a legacy address never disappears the moment a profile is edited.
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

// Draft restore writes region/province/city back in one pass, so the cascade's
// clearing must not fight it. The watchers run synchronously (flush: 'sync') so
// the guard is still up while the restore loop is writing the fields — with the
// default pre-render flush they would fire after onMounted has already lowered
// it and would wipe the restored province and city.
const restoringDraft = ref(false);

watch(
    () => form.region,
    (value, old) => {
        if (restoringDraft.value || value === old) return;
        form.province = '';
        form.city_municipality = '';
    },
    { flush: 'sync' }
);

watch(
    () => form.province,
    (value, old) => {
        if (restoringDraft.value || value === old) return;
        form.city_municipality = '';
    },
    { flush: 'sync' }
);

// Local, submit-time validation that mirrors the server rules so a field that
// reads clean here cannot bounce on the round trip. Server errors still win.
const localErrors = ref({});
const formEl = ref(null);

const today = new Date();
const todayIso = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(
    today.getDate()
).padStart(2, '0')}`;

const REQUIRED = 'This field is required.';

const validate = () => {
    const errors = {};

    const personalRequired = [
        'first_name', 'last_name', 'sex', 'is_pwd', 'civil_status', 'mobile_number',
        'region', 'province', 'city_municipality',
    ];

    for (const key of personalRequired) {
        if (String(form[key] ?? '').trim() === '') errors[key] = REQUIRED;
    }

    if (!form.date_of_birth) {
        errors.date_of_birth = REQUIRED;
    } else if (form.date_of_birth >= todayIso) {
        errors.date_of_birth = 'Date of birth must be in the past.';
    }

    if (form.mobile_number && !/^09\d{9}$/.test(form.mobile_number)) {
        errors.mobile_number = 'Enter a valid PH mobile number starting with 09 (e.g. 0917 123 4567).';
    }

    // The employment details are gated: nothing to validate until the
    // classification is answered, and the gate itself is a required answer.
    // Location and food restrictions are personal, not employment, so they are
    // validated above with the rest of the personal fields.
    if (!employmentType.value) {
        errors.employmentType = 'Select whether you are a government employee or from the private sector.';
    } else {
        const employmentRequired = [
            'position_title', 'salary_grade', 'organization_name', 'sector',
            'field_office_id', 'position_level', 'employment_status', 'organization_address',
        ];

        for (const key of employmentRequired) {
            if (String(form[key] ?? '').trim() === '') errors[key] = REQUIRED;
        }
    }

    if (!form.consent) {
        errors.consent = 'You must give consent for the processing of your personal data to continue.';
    }

    return errors;
};

// A failed submit clears each field's error as soon as the user types a new
// value, so the red never outlives the mistake that caused it.
watch(
    () => ({ ...form.data() }),
    (value, old) => {
        for (const key of Object.keys(localErrors.value)) {
            if (value[key] !== old[key]) delete localErrors.value[key];
        }
    }
);

const errorFor = (key) => form.errors[key] ?? localErrors.value[key] ?? null;

const hasLocalErrors = () => Object.keys(localErrors.value).length > 0;

const submit = () => {
    localErrors.value = validate();

    if (hasLocalErrors()) {
        nextTick(() => {
            const invalid = formEl.value?.querySelector('[aria-invalid="true"]');
            invalid?.focus({ preventScroll: true });
            invalid?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        });
        return;
    }

    // Close the review modal before the request goes out so it cannot sit open
    // over the "Registration Successful" modal that replaces this page.
    previewOpen.value = false;

    form.post('/profile/complete', {
        onSuccess: clearDraft,
    });
};

// The gate form is long; a stale tab refresh should not cost 27 typed fields.
// The draft is saved to this device and only written here — Edit.vue never
// restores one — and is cleared the moment the server accepts the profile.
const DRAFT_KEY = `tims.profile-draft.${props.user.email}`;

function loadDraft() {
    try {
        const raw = localStorage.getItem(DRAFT_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

function saveDraft() {
    try {
        localStorage.setItem(DRAFT_KEY, JSON.stringify({ ...form.data(), employmentType: employmentType.value }));
    } catch {
        // Private mode or a full quota — the draft is best-effort, never fatal.
    }
}

function clearDraft() {
    try {
        localStorage.removeItem(DRAFT_KEY);
    } catch {
        // Ignore.
    }
}

watch(
    () => ({ ...form.data(), employmentType: employmentType.value }),
    saveDraft,
    { deep: true }
);

onMounted(() => {
    const draft = loadDraft();
    if (!draft) return;

    restoringDraft.value = true;

    for (const key of Object.keys(form.data())) {
        const value = draft[key];
        if (typeof value === 'string' || typeof value === 'boolean' || typeof value === 'number') {
            form[key] = value;
        }
    }

    if (draft.employmentType === 'government' || draft.employmentType === 'private') {
        employmentType.value = draft.employmentType;
    }

    restoringDraft.value = false;
});
</script>

<template>
    <Head title="Complete your profile" />

    <div class="min-h-screen bg-csc-blue-tint">
        <!-- Header -->
        <header class="bg-csc-blue">
            <div class="mx-auto flex max-w-4xl items-center justify-between gap-4 px-6 py-3 sm:px-6">
                <AppLogo variant="light" size="md" />
                <AppButton
                    variant="ghost"
                    size="sm"
                    onDark
                    icon="sign-out"
                    @click="router.post('/logout')"
                >
                    Sign out
                </AppButton>
            </div>
        </header>

        <main class="mx-auto max-w-4xl px-4 py-10 sm:px-6 sm:py-14">
            <template v-if="!props.registration_complete">
            <div class="mb-8">
                <p
                    class="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 text-2xs font-semibold tracking-widest text-csc-blue uppercase"
                >
                    Step 2 of 2 · Profile details
                </p>
                <h1 class="mt-4 text-2xl font-semibold tracking-tight text-csc-blue sm:text-3xl">
                    Complete your profile
                </h1>
                <p class="mt-3 text-sm leading-relaxed text-csc-ink-muted">
                    Signed in as {{ props.user.email }}. We need a few details before you can register for
                    trainings. Free-text records are kept in uppercase, and your progress is saved on this device.
                </p>
            </div>

            <p
                v-if="Object.keys(form.errors).length || hasLocalErrors()"
                class="mb-6 flex items-start gap-2 rounded-lg border border-csc-red-ink/30 bg-csc-red-ink/5 px-4 py-3 text-sm font-medium text-csc-red-ink"
                role="alert"
            >
                <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M12 7v6M12 16.5v.5" stroke-linecap="round" />
                </svg>
                Please review the highlighted fields below.
            </p>

            <form ref="formEl" class="space-y-8" novalidate @submit.prevent="submit">
                <!-- Personal information -->
                <fieldset id="section-personal" tabindex="-1" class="rounded-xl border border-csc-line bg-white p-6 sm:p-8 focus:outline-none">
                    <legend class="sr-only">Personal Information</legend>

                    <h2 class="flex items-center gap-2.5 text-sm font-semibold tracking-wide text-csc-blue uppercase">
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-full bg-csc-blue-tint text-xs text-csc-blue"
                            aria-hidden="true"
                        >
                            1
                        </span>
                        Personal Information
                    </h2>

                    <div class="mt-5 grid gap-5 sm:grid-cols-12">
                        <div class="sm:col-span-4">
                            <AppInput
                                v-model="form.first_name"
                                label="First Name"
                                autocomplete="given-name"
                                placeholder="e.g. JUAN"
                                maxlength="255"
                                :error="errorFor('first_name')"
                                uppercase
                                required
                                autofocus
                            />
                        </div>
                        <div class="sm:col-span-4">
                            <AppInput
                                v-model="form.middle_name"
                                label="Middle Name"
                                autocomplete="additional-name"
                                placeholder="e.g. DIZON"
                                maxlength="64"
                                hint="Optional. Shown as an initial on certificates."
                                :error="errorFor('middle_name')"
                                uppercase
                            />
                        </div>
                        <div class="sm:col-span-4">
                            <AppInput
                                v-model="form.last_name"
                                label="Last Name"
                                autocomplete="family-name"
                                placeholder="e.g. DELA CRUZ"
                                maxlength="255"
                                :error="errorFor('last_name')"
                                uppercase
                                required
                            />
                        </div>
                        <div class="sm:col-span-3">
                            <AppSelect
                                v-model="form.suffix"
                                label="Suffix"
                                :options="props.options.suffixes"
                                placeholder="None"
                                :error="errorFor('suffix')"
                            />
                        </div>
                        <div class="sm:col-span-3">
                            <AppInput
                                v-model="form.date_of_birth"
                                label="Date of Birth"
                                type="date"
                                autocomplete="bday"
                                :max="todayIso"
                                :error="errorFor('date_of_birth')"
                                required
                            />
                        </div>
                        <div class="sm:col-span-6">
                            <AppInput
                                v-model="form.mobile_number"
                                label="Mobile Number"
                                type="tel"
                                autocomplete="tel"
                                inputmode="numeric"
                                maxlength="11"
                                placeholder="09XX XXX XXXX"
                                hint="PH mobile number starting with 09, e.g. 0917 123 4567."
                                :error="errorFor('mobile_number')"
                                required
                            />
                        </div>

                        <div class="sm:col-span-4">
                            <AppSelect
                                v-model="form.sex"
                                label="Sex"
                                :options="props.options.sexes"
                                :error="errorFor('sex')"
                                required
                            />
                        </div>
                        <div class="sm:col-span-4">
                            <AppSelect
                                v-model="form.civil_status"
                                label="Civil Status"
                                :options="props.options.civilStatuses"
                                :error="errorFor('civil_status')"
                                required
                            />
                        </div>
                        <div class="sm:col-span-4">
                            <AppSelect
                                v-model="form.is_pwd"
                                label="Person with Disability (PWD)"
                                :options="props.options.yesNo"
                                :error="errorFor('is_pwd')"
                                required
                            />
                        </div>

                        <div class="sm:col-span-4">
                            <AppSelect
                                v-model="form.region"
                                label="Region"
                                :options="regionOptions"
                                placeholder="Select a region"
                                :error="errorFor('region')"
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
                                :error="errorFor('province')"
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
                                :error="errorFor('city_municipality')"
                                required
                            />
                        </div>

                        <div class="sm:col-span-12">
                            <AppInput
                                v-model="form.food_restrictions_details"
                                label="Food Restrictions / Allergies"
                                placeholder="e.g. VEGETARIAN, NO SEAFOOD, ALLERGIC TO NUTS"
                                maxlength="500"
                                hint="Leave blank if you have none. Used for meal planning at events."
                                :error="errorFor('food_restrictions_details')"
                                uppercase
                            />
                        </div>
                    </div>
                </fieldset>

                <!-- Employment details -->
                <fieldset id="section-employment" tabindex="-1" class="rounded-xl border border-csc-line bg-white p-6 sm:p-8 focus:outline-none">
                    <legend class="sr-only">Employment Details</legend>

                    <h2 class="flex items-center gap-2.5 text-sm font-semibold tracking-wide text-csc-blue uppercase">
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-full bg-csc-blue-tint text-xs text-csc-blue"
                            aria-hidden="true"
                        >
                            2
                        </span>
                        Employment Details
                    </h2>

                    <!-- Employment classification gate: the details only appear once
                         the participant says which kind of employee they are. -->
                    <div class="mt-5">
                        <p id="employment-type-label" class="mb-2 text-sm font-medium text-csc-ink">
                            Are you a government employee?
                            <span class="text-csc-red-ink" aria-hidden="true">*</span>
                        </p>
                        <div
                            role="radiogroup"
                            aria-labelledby="employment-type-label"
                            :aria-invalid="errorFor('employmentType') ? 'true' : undefined"
                            class="grid gap-3 sm:grid-cols-2"
                        >
                            <label
                                class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-colors duration-150"
                                :class="
                                    employmentType === 'government'
                                        ? 'border-csc-blue bg-csc-blue-tint'
                                        : 'border-csc-line bg-white hover:border-csc-blue/40'
                                "
                            >
                                <input
                                    v-model="employmentType"
                                    type="radio"
                                    name="employment-type"
                                    value="government"
                                    class="mt-0.5 size-4 shrink-0 accent-csc-blue"
                                />
                                <span>
                                    <span class="block text-sm font-semibold text-csc-ink">Government employee</span>
                                    <span class="mt-1 block text-xs leading-relaxed text-csc-ink-subtle">
                                        Employed in a national or local government agency, GOCC, SUC, or other
                                        government institution.
                                    </span>
                                </span>
                            </label>

                            <label
                                class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-colors duration-150"
                                :class="
                                    employmentType === 'private'
                                        ? 'border-csc-blue bg-csc-blue-tint'
                                        : 'border-csc-line bg-white hover:border-csc-blue/40'
                                "
                            >
                                <input
                                    v-model="employmentType"
                                    type="radio"
                                    name="employment-type"
                                    value="private"
                                    class="mt-0.5 size-4 shrink-0 accent-csc-blue"
                                />
                                <span>
                                    <span class="block text-sm font-semibold text-csc-ink">Private sector / Others</span>
                                    <span class="mt-1 block text-xs leading-relaxed text-csc-ink-subtle">
                                        Employed in the private sector, an NGO, or another non-government
                                        organization.
                                    </span>
                                </span>
                            </label>
                        </div>
                        <p v-if="errorFor('employmentType')" class="mt-1.5 text-xs font-medium text-csc-red-ink">
                            {{ errorFor('employmentType') }}
                        </p>
                    </div>

                    <div v-if="employmentType" class="mt-5 grid gap-5 sm:grid-cols-12">
                        <div class="sm:col-span-9">
                            <AppInput
                                v-model="form.position_title"
                                label="Position Title"
                                autocomplete="organization-title"
                                placeholder="e.g. ADMINISTRATIVE OFFICER III"
                                maxlength="255"
                                hint="Enter the full title — do not abbreviate."
                                :error="errorFor('position_title')"
                                uppercase
                                required
                            />
                        </div>
                        <div class="sm:col-span-3">
                            <AppSelect
                                v-model="form.salary_grade"
                                label="Salary Grade"
                                :options="props.options.salaryGrades"
                                :disabled="isPrivate"
                                :error="errorFor('salary_grade')"
                                required
                            />
                        </div>

                        <div class="sm:col-span-6">
                            <AppSelect
                                v-model="form.position_level"
                                label="Position Level"
                                :options="props.options.positionLevels"
                                :disabled="isPrivate"
                                :error="errorFor('position_level')"
                                required
                            />
                        </div>
                        <div class="sm:col-span-6">
                            <AppSelect
                                v-model="form.employment_status"
                                label="Employment Status"
                                :options="props.options.employmentStatuses"
                                :disabled="isPrivate"
                                :error="errorFor('employment_status')"
                                required
                            />
                        </div>

                        <div class="sm:col-span-12">
                            <AppInput
                                v-model="form.organization_name"
                                label="Name of Agency / Company / Organization"
                                autocomplete="organization"
                                placeholder="e.g. DEPARTMENT OF EDUCATION"
                                maxlength="255"
                                hint="Enter the full name — do not abbreviate."
                                :error="errorFor('organization_name')"
                                uppercase
                                required
                            />
                        </div>

                        <div class="sm:col-span-6">
                            <AppSelect
                                v-model="form.sector"
                                label="Sector"
                                :options="isPrivate ? privateSectorOptions : props.options.sectors"
                                :hint="isPrivate ? 'Pick the closest match for your organization.' : undefined"
                                :error="errorFor('sector')"
                                required
                            />
                        </div>
                        <div class="sm:col-span-6">
                            <AppSelect
                                v-model="form.field_office_id"
                                label="CSC Field Office"
                                :options="props.options.fieldOffices"
                                :error="errorFor('field_office_id')"
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
                                :error="errorFor('organization_address')"
                                required
                            />
                        </div>
                    </div>
                </fieldset>

                <!-- Consent -->
                <div id="section-consent" tabindex="-1" class="rounded-xl border border-csc-line bg-white p-6 sm:p-8 focus:outline-none">
                    <label class="flex items-start gap-3 text-sm text-csc-ink">
                        <input
                            v-model="form.consent"
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0 rounded border-csc-line accent-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            :aria-invalid="errorFor('consent') ? 'true' : undefined"
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
                    <p v-if="errorFor('consent')" id="consent-error" class="mt-2 text-xs font-medium text-csc-red-ink">
                        {{ errorFor('consent') }}
                    </p>
                </div>

                <!-- Sticky submit bar: the way forward stays in view while the long form is scrolled -->
                <div class="sticky bottom-0 -mx-4 border-t border-csc-line bg-white/90 px-4 py-4 backdrop-blur sm:-mx-6 sm:px-6">
                    <div class="flex items-center justify-between gap-4">
                        <p class="hidden text-sm font-medium text-csc-ink-subtle sm:block">
                            Step 2 of 2 · Profile details
                        </p>
                        <AppButton type="button" size="lg" block class="sm:w-auto" icon="check" @click="openPreview">
                            Review &amp; Submit
                        </AppButton>
                    </div>
                </div>
            </form>
            </template>

            <!-- What the gate form hands off to once the registration is in:
                 the account is complete but locked until the emailed link is
                 clicked. Mirrors the success modal so dismissing it leaves a
                 page that still says the same thing. -->
            <template v-else>
                <div class="rounded-xl border border-csc-line bg-white p-8 text-center sm:p-12">
                    <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-success-soft">
                        <AppIcon name="check" size="lg" class="text-success" />
                    </span>
                    <h1 class="mt-4 text-2xl font-semibold tracking-tight text-csc-blue sm:text-3xl">
                        Registration Successful!
                    </h1>
                    <p class="mx-auto mt-3 max-w-md text-sm leading-relaxed text-csc-ink-muted">
                        Your account has been successfully registered. Please check your email and click the
                        verification link to verify your account before logging in.
                    </p>
                    <p v-if="resent" class="mt-3 text-sm font-medium text-success">
                        A new verification link has been sent to your email.
                    </p>
                    <p v-else-if="resendError" class="mt-3 text-sm font-medium text-danger">{{ resendError }}</p>
                    <div class="mt-6 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        <AppButton variant="ghost" :loading="sending" @click="resendVerification">
                            Resend verification email
                        </AppButton>
                        <AppButton icon="arrow-right" @click="router.get('/email/verify')">Continue</AppButton>
                    </div>
                </div>
            </template>
        </main>

        <!-- Review-before-submit: every field, read back in a labelled summary -->
        <AppModal
            :open="previewOpen"
            title="Review your information"
            subtitle="Confirm the details below before submitting your registration."
            size="lg"
            @close="previewOpen = false"
        >
            <div class="space-y-4">
                <!--
                    The name leads, because it is the only thing on this screen
                    that a later edit cannot fully undo.

                    Everything else here is editable at /profile afterwards —
                    one click, no confirmation, same save path — which is a fair
                    argument that a review step is not worth anyone's twenty
                    seconds. The name is the exception that earns it:
                    ProfileService::save copies fullName() onto the user record,
                    and that string is printed into the certificate PDF, which
                    is rendered once at release and stored. Correcting a
                    misspelling after a certificate exists means asking the
                    office to re-issue the document.

                    So the screen now spends its most prominent line on the one
                    field where proof-reading actually pays, and says why rather
                    than leaving the reader to weigh twenty identical rows.
                -->
                <section class="rounded-lg border border-csc-blue/25 bg-csc-blue-tint p-4">
                    <div class="flex items-baseline justify-between gap-4">
                        <h3 class="text-xs font-semibold tracking-widest text-csc-blue uppercase">
                            Name on your certificates
                        </h3>
                        <button
                            type="button"
                            class="shrink-0 rounded text-xs font-semibold text-csc-blue underline underline-offset-4 transition-colors hover:text-csc-blue-deep focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            @click="editSection('section-personal')"
                        >
                            Edit<span class="sr-only"> your name</span>
                        </button>
                    </div>

                    <p class="mt-2 text-xl leading-tight font-semibold text-balance text-csc-blue sm:text-2xl">
                        {{ fullName }}
                    </p>

                    <p class="mt-2.5 text-xs leading-relaxed text-csc-ink-muted">
                        Please check the spelling. This is the name that will be printed on every certificate you
                        earn, and a certificate is written once when it is issued — correcting the spelling
                        afterwards means asking the office to re-issue the document.
                    </p>
                </section>

                <section v-for="section in previewSections" :key="section.title" class="rounded-lg border border-csc-line p-4">
                    <div class="flex items-baseline justify-between gap-4">
                        <h3 class="text-xs font-semibold tracking-widest text-csc-blue uppercase">
                            {{ section.title }}
                        </h3>

                        <!--
                            Per section, not one button for the whole form. The
                            footer's "Edit details" only ever meant "close this",
                            which left the reader to find the wrong value a
                            second time; naming the section in the accessible
                            label keeps three identical "Edit" links tellable
                            apart when they are read out of context.
                        -->
                        <button
                            type="button"
                            class="shrink-0 rounded text-xs font-semibold text-csc-blue underline underline-offset-4 transition-colors hover:text-csc-blue-deep focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            @click="editSection(section.anchor)"
                        >
                            Edit<span class="sr-only"> {{ section.title }}</span>
                        </button>
                    </div>
                    <dl class="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                        <!--
                            A row whose label is hidden takes the full width:
                            with nothing above it, half a grid column would
                            leave the sentence wrapping against empty space.
                        -->
                        <div v-for="row in section.rows" :key="row.label" :class="row.labelHidden ? 'sm:col-span-2' : ''">
                            <dt :class="row.labelHidden ? 'sr-only' : 'text-xs font-medium text-csc-ink-subtle'">
                                {{ row.label }}
                            </dt>
                            <dd class="text-sm font-medium text-csc-ink" :class="row.labelHidden ? '' : 'mt-0.5'">
                                {{ row.value || '—' }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>

            <template #footer>
                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <!--
                        "Back to form" rather than "Edit details": editing is
                        now what the per-section links do, and two controls both
                        offering to edit would just raise the question of how
                        they differ. This one only dismisses.
                    -->
                    <AppButton variant="ghost" @click="previewOpen = false">Back to form</AppButton>
                    <AppButton :loading="form.processing" icon="check" @click="submit">
                        {{ form.processing ? 'Submitting…' : 'Submit Registration' }}
                    </AppButton>
                </div>
            </template>
        </AppModal>

        <!-- Registration just went through — the account exists, but the emailed
             verification link has to be clicked before the system opens up. -->
        <AppModal
            :open="props.registration_complete && !successDismissed"
            hideHeader
            size="sm"
            @close="successDismissed = true"
        >
            <div class="text-center">
                <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-success-soft">
                    <AppIcon name="check" size="lg" class="text-success" />
                </span>
                <h2 class="mt-4 text-xl font-semibold tracking-tight text-csc-ink">Registration Successful!</h2>
                <p class="mt-2 text-sm leading-relaxed text-csc-ink-muted">
                    Your account has been successfully registered. Please check your email and click the verification
                    link to verify your account before logging in.
                </p>
                <p v-if="resent" class="mt-3 text-sm font-medium text-success">
                    A new verification link has been sent to your email.
                </p>
                <p v-else-if="resendError" class="mt-3 text-sm font-medium text-danger">{{ resendError }}</p>
            </div>

            <template #footer>
                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <AppButton variant="ghost" :loading="sending" @click="resendVerification">
                        Resend verification email
                    </AppButton>
                    <AppButton icon="arrow-right" @click="router.get('/email/verify')">Continue</AppButton>
                </div>
            </template>
        </AppModal>
    </div>
</template>