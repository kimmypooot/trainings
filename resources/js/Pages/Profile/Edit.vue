<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppAvatar from '@/Components/AppAvatar.vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppInput from '@/Components/AppInput.vue';
import AppModal from '@/Components/AppModal.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTextarea from '@/Components/AppTextarea.vue';

const props = defineProps({
    options: { type: Object, required: true },
    geography: { type: Array, required: true },
    user: { type: Object, required: true },
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
    : props.profile?.province ?? '';
const provinceNode = regionNode?.provinces.find((province) => province.name === provinceInit) ?? null;
const cityInit = provinceNode
    ? normalizeName(props.profile?.city_municipality, provinceNode.cities)
    : props.profile?.city_municipality ?? '';

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

    consent: true,
});

// The state the page loaded with. `isDirty` compares against this, and a
// successful save re-baselines it, so the guard never complains about a
// profile that was just persisted.
const pristine = { ...form.data() };

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

// Employment classification, derived from the saved sector. Profiles whose
// sector is private/others fall under "Private"; everything else is treated as
// a government employee so the normal fields stay available.
const isPrivateSector = ['Private Sector', 'Non-Government Organization', 'Other'].includes(props.profile?.sector);
const employmentType = ref(isPrivateSector ? 'private' : 'government');
const isPrivate = computed(() => employmentType.value === 'private');

// Non-government roles: salary grade and position level do not apply, and
// employment status is "Others". Sector stays pickable so an NGO or "Other"
// profile keeps its own answer instead of being forced to Private Sector.
const privateSectorOptions = ['Private Sector', 'Non-Government Organization', 'Other'];

// What the employment gate overwrote on load, so a Government → Private →
// Government round trip restores the participant's own answers instead of
// blanking them. Only the fields the decision writes are ever touched back.
const storedGovernment = {
    salary_grade: props.profile?.salary_grade ?? '',
    position_level: props.profile?.position_level ?? '',
    employment_status: props.profile?.employment_status ?? '',
    sector: props.profile?.sector ?? '',
};

const applyPrivate = () => {
    form.salary_grade = 'Not Applicable';
    form.position_level = 'Not Applicable';
    form.employment_status = 'Others';
    if (!form.sector) form.sector = 'Private Sector';
};

const applyGovernment = () => {
    if (form.salary_grade === 'Not Applicable') form.salary_grade = storedGovernment.salary_grade;
    if (form.position_level === 'Not Applicable') form.position_level = storedGovernment.position_level;
    if (form.employment_status === 'Others') form.employment_status = storedGovernment.employment_status;
    if (form.sector === 'Private Sector') form.sector = storedGovernment.sector;
};

watch(employmentType, (value) => {
    if (value === 'private') applyPrivate();
    else if (value === 'government') applyGovernment();
});

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

    const employmentRequired = [
        'position_title', 'salary_grade', 'organization_name', 'sector',
        'field_office_id', 'position_level', 'employment_status', 'organization_address',
    ];

    for (const key of employmentRequired) {
        if (String(form[key] ?? '').trim() === '') errors[key] = REQUIRED;
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

const submitting = ref(false);

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

    submitting.value = true;

    form.put('/profile', {
        onSuccess: () => {
            // Re-baseline the dirty check and step out of the editor so the
            // freshly saved values are shown, not edited.
            Object.assign(pristine, form.data());
            localErrors.value = {};
            viewing.value = true;
        },
        onFinish: () => {
            submitting.value = false;
        },
    });
};

// Read-only summary shown by default; the editor is entered deliberately and
// exited via Save (baselines to view) or a guarded Cancel/nav away.
const viewing = ref(true);

const fieldOfficeLabel = computed(
    () =>
        props.options.fieldOffices.find((office) => String(office.value) === String(form.field_office_id))?.label ??
        form.field_office_id
);

const fullName = computed(
    () =>
        [form.first_name, form.middle_name, form.last_name].filter(Boolean).join(' ') +
        (form.suffix ? ` ${form.suffix}` : '')
);

const viewSections = computed(() => [
    {
        title: 'Personal Information',
        rows: [
            { label: 'Full Name', value: fullName.value },
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
]);

const lastSaved = computed(() => {
    if (!props.profile?.updated_at) return null;

    const date = new Date(props.profile.updated_at);
    if (Number.isNaN(date.getTime())) return null;

    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
});

// --- Profile completeness ---------------------------------------------------
//
// A lightweight gauge for the Identity summary card. It counts the same core
// fields the profile form asks for — not every column, just the ones that
// matter for registration and reporting — so an incomplete profile is visible
// at a glance and the fix is one click away.
const completeness = computed(() => {
    const profile = props.profile ?? {};

    const checks = [
        ['Full name', Boolean(profile.first_name && profile.last_name)],
        ['Date of birth', Boolean(profile.date_of_birth)],
        ['Sex', Boolean(profile.sex)],
        ['Civil status', Boolean(profile.civil_status)],
        ['Mobile number', Boolean(profile.mobile_number)],
        ['Sector', Boolean(profile.sector)],
        ['Employment details', Boolean(profile.position_level || profile.position_title || profile.organization_name)],
        ['Location', Boolean(profile.region && profile.province && profile.city_municipality)],
    ];

    const missing = checks.filter(([, filled]) => !filled).map(([label]) => label);

    return {
        percent: Math.round(((checks.length - missing.length) * 100) / checks.length),
        complete: missing.length === 0,
        missing,
    };
});

// --- Profile photo ----------------------------------------------------------
//
// Its own form, posted the moment a file is chosen: a photo is a single
// decision, not a field of the profile, and making it wait for Save would mean
// the header avatar and the form could disagree while the page sat open.
// `photoBusy` keeps these visits out of the unsaved-changes guard below — they
// are this page saving something, not the user navigating away.
const photoInput = ref(null);
const photoBusy = ref(false);
const photoError = ref(null);

// An object URL for the file just picked, shown while the upload is in flight
// so the new photo appears instantly instead of after the round trip.
const localPreview = ref(null);

const releasePreview = () => {
    if (localPreview.value) URL.revokeObjectURL(localPreview.value);
    localPreview.value = null;
};

const photoUrl = computed(() => localPreview.value ?? props.user.avatar);

const photoSourceLabel = computed(() =>
    props.user.avatar ? 'Your profile photo' : 'No photo — your initials are shown'
);

const photoVisit = (options = {}) => {
    photoBusy.value = true;

    return {
        preserveScroll: true,
        ...options,
        // Chained, not replaced: a caller that needs its own teardown still
        // gets it, and the shared cleanup runs either way.
        onFinish: (visit) => {
            photoBusy.value = false;
            releasePreview();
            options.onFinish?.(visit);
        },
    };
};

const choosePhoto = () => photoInput.value?.click();

const onPhotoChosen = (event) => {
    const file = event.target.files?.[0] ?? null;
    // Clear the input so re-picking the same file still fires a change.
    event.target.value = '';
    if (!file) return;

    photoError.value = null;

    // Mirrors the server rules, so an obviously-too-large file is refused
    // before it is uploaded rather than after.
    if (file.size > 2 * 1024 * 1024) {
        photoError.value = 'The photo may not be larger than 2 MB.';
        return;
    }

    releasePreview();
    localPreview.value = URL.createObjectURL(file);

    const upload = useForm({ photo: file });

    upload.post(
        '/profile/photo',
        photoVisit({
            forceFormData: true,
            onError: (errors) => {
                photoError.value = errors.photo ?? 'The photo could not be uploaded.';
            },
        })
    );
};

const removePhoto = () => {
    photoError.value = null;
    router.delete('/profile/photo', photoVisit({}));
};

// --- Linked accounts --------------------------------------------------------
//
// Connecting is a plain link out to Google's consent screen, so it needs no
// handler. Disconnecting changes how the participant signs in, so it is
// confirmed first even though it can be undone by connecting again.
const confirmingDisconnect = ref(false);
const disconnecting = ref(false);


const disconnectGoogle = () => {
    confirmingDisconnect.value = false;
    disconnecting.value = true;

    router.delete(
        '/profile/google',
        photoVisit({
            onFinish: () => {
                disconnecting.value = false;
            },
        })
    );
};

// --- Email address ----------------------------------------------------------
//
// The address is the account: it is what you sign in with and where every
// certificate and notice is sent. So the change is deliberate — a panel that
// has to be opened, a password re-entered where there is one, and nothing moves
// until the link sent to the new address is opened.
const changingEmail = ref(false);

const emailForm = useForm({
    email: '',
    current_password: '',
});

const openEmailChange = () => {
    emailForm.reset();
    emailForm.clearErrors();
    changingEmail.value = true;
};

const closeEmailChange = () => {
    changingEmail.value = false;
    emailForm.reset();
    emailForm.clearErrors();
};

const submitEmailChange = () => {
    emailForm.post('/profile/email', {
        preserveScroll: true,
        // Only on success: an error has to leave the panel open with the
        // address still in it, or the participant retypes it to read why.
        onSuccess: closeEmailChange,
        onFinish: () => emailForm.reset('current_password'),
    });
};

const cancellingEmail = ref(false);

const cancelPendingEmail = () => {
    cancellingEmail.value = true;

    router.delete('/profile/email', {
        preserveScroll: true,
        onFinish: () => {
            cancellingEmail.value = false;
        },
    });
};

// Unsaved-changes guard. Two layers:
//  - window.beforeunload catches tab close / refresh with the native dialog.
//  - the Inertia `before` visit event catches in-app navigation (sidebar,
//    Cancel, sign-out) and parks it behind a confirm modal. Confirming
//    re-issues the same visit; declining drops it. The page's own save marks
//    `submitting` so the PUT is never intercepted.
const isDirty = computed(() =>
    Object.keys(pristine).some((key) => String(form[key] ?? '') !== String(pristine[key] ?? ''))
);

const confirmingLeave = ref(false);
// Lets the re-issued visit past the guard without needing a second confirm.
const allowLeave = ref(false);
let pendingVisit = null;
let stopBeforeListener = null;

const handleBeforeUnload = (event) => {
    if (!isDirty.value) return;
    event.preventDefault();
    event.returnValue = '';
};

const handleBeforeVisit = (event) => {
    // allowLeave is the door confirmDiscard opens for the one visit it
    // re-issues; without it the guard parks its own re-issue right back.
    if (allowLeave.value || !isDirty.value || submitting.value || photoBusy.value) return;

    event.preventDefault();
    pendingVisit = event.detail.visit;
    confirmingLeave.value = true;
};

const confirmDiscard = () => {
    confirmingLeave.value = false;

    const visit = pendingVisit;
    pendingVisit = null;
    if (!visit) return;

    // Re-issue the visit the guard parked. `visit` is Inertia's PendingVisit —
    // a plain object carrying the already-resolved options — so read them off
    // it directly (it has no `.all()`; calling one threw here and left the
    // modal closing without navigating). Its `data` is already transformed: a
    // GET's query is folded into `url` and `data` left empty, so passing both
    // is correct either way. The per-visit callbacks are not on this object —
    // Inertia keeps them beside it — so a parked visit loses onSuccess/onFinish;
    // the visits that reach this guard are navigations, which carry none.
    allowLeave.value = true;

    try {
        router.visit(visit.url, {
            method: visit.method,
            data: visit.data,
            replace: visit.replace,
            preserveScroll: visit.preserveScroll,
            preserveState: visit.preserveState,
            only: visit.only,
            except: visit.except,
            headers: visit.headers,
        });
    } finally {
        // `before` fires synchronously inside visit(), so the door is open for
        // exactly that call — and closes even if the visit throws.
        allowLeave.value = false;
    }
};

const cancelDiscard = () => {
    confirmingLeave.value = false;
    pendingVisit = null;
};

onMounted(() => {
    if (employmentType.value === 'private') applyPrivate();

    window.addEventListener('beforeunload', handleBeforeUnload);
    stopBeforeListener = router.on('before', handleBeforeVisit);
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', handleBeforeUnload);
    stopBeforeListener?.();
    releasePreview();
});
</script>

<template>
    <Head title="My Profile" />

    <AuthenticatedLayout title="My Profile" current="profile">
        <div class="mx-auto max-w-7xl space-y-5">
            <!-- Identity summary -->
            <AppCard>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="flex min-w-0 items-center gap-1.5 text-lg font-semibold text-csc-blue">
                            <span class="truncate">{{ user.name ?? '—' }}</span>
                            <svg
                                v-if="user.is_verified"
                                viewBox="0 0 24 24"
                                class="size-5 shrink-0 text-csc-blue"
                                role="img"
                                aria-label="Verified email"
                                title="Verified email"
                            >
                                <circle cx="12" cy="12" r="10" fill="currentColor" />
                                <path
                                    d="M8.5 12.2l2.4 2.4 4.6-5"
                                    fill="none"
                                    stroke="#fff"
                                    stroke-width="2.4"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                        </p>
                        <p class="truncate text-sm text-csc-ink-muted">{{ user.email }}</p>
                        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span
                                class="inline-block rounded-full bg-csc-blue-tint px-2.5 py-0.5 text-xs font-medium text-csc-blue"
                            >
                                {{ user.role_label }}
                            </span>
                            <p
                                class="flex items-center gap-1 text-xs text-csc-ink-subtle"
                                :title="
                                    user.is_verified
                                        ? 'Your email is verified.'
                                        : 'Your email still needs verification.'
                                "
                            >
                                <AppIcon :name="user.is_verified ? 'shield' : 'lock'" class="size-3.5" />
                                {{ user.is_verified ? 'Verified email' : 'Email not yet verified' }}
                                <!--
                                    This used to end "· Cannot be changed here",
                                    which stopped being true the moment the
                                    Email Address card below gained a Change
                                    button — and a page that contradicts itself
                                    about who may change a sign-in address is
                                    worse than one that simply says nothing.
                                -->
                            </p>
                        </div>
                    </div>

                    <div class="w-full shrink-0 sm:w-96 sm:border-l sm:border-csc-line sm:pl-5">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="text-xs font-medium text-csc-ink-subtle">Profile completeness</p>
                            <p
                                class="text-sm font-semibold"
                                :class="completeness.complete ? 'text-success' : 'text-csc-blue'"
                            >
                                {{ completeness.percent }}%
                            </p>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-csc-blue-tint" role="progressbar" :aria-valuenow="completeness.percent" aria-valuemin="0" aria-valuemax="100">
                            <div
                                class="h-full rounded-full transition-all duration-300"
                                :class="completeness.complete ? 'bg-success' : 'bg-csc-blue'"
                                :style="{ width: `${completeness.percent}%` }"
                            />
                        </div>

                        <div
                            v-if="!completeness.complete"
                            class="mt-2.5 rounded-lg bg-info-soft px-3 py-2"
                        >
                            <p class="text-xs font-medium text-info">Missing</p>
                            <p class="mt-0.5 text-xs leading-relaxed text-csc-ink-muted">
                                {{ completeness.missing.join(', ') }}
                            </p>
                        </div>
                        <p v-else class="mt-2.5 flex items-center gap-1.5 text-xs text-success">
                            <AppIcon name="check" class="size-3.5" />
                            All set.
                        </p>

                        <p v-if="lastSaved" class="mt-2.5 flex shrink-0 items-center gap-1.5 text-xs text-csc-ink-subtle">
                            <AppIcon name="clock" class="size-3.5" />
                            Last saved {{ lastSaved }}
                        </p>
                    </div>
                </div>
            </AppCard>

            <!--
                Account settings, paired: who you are and how you sign in. They
                sit side by side because the Google connection is what feeds the
                synced photo — reading one explains the options in the other.
                They stack on narrow screens, where the pairing cannot be seen
                anyway.
            -->
            <div class="grid items-stretch gap-5 lg:grid-cols-2">
                <AppCard title="Profile Photo" subtitle="Shown beside your name across TIMS.">
                    <div class="flex items-center gap-4">
                        <!--
                            The avatar doubles as the upload control: clicking
                            it opens the file picker, which is where people
                            reach for first. The buttons below spell the same
                            action out for keyboard and screen-reader users.

                            The photo sits in its own fixed-width column so it
                            reads as a distinct block beside the copy. The
                            button is locked to the same square as the avatar
                            so the circle can never be stretched into an oval.
                        -->
                        <div class="flex w-36 shrink-0 items-center justify-center">
                            <button
                                type="button"
                                class="group relative flex size-28 shrink-0 items-center justify-center rounded-full focus:outline-2 focus:outline-offset-2 focus:outline-csc-blue"
                                :disabled="photoBusy"
                                :aria-label="user.avatar ? 'Change your profile photo' : 'Add a profile photo'"
                                @click="choosePhoto"
                            >
                                <AppAvatar :name="user.name" :src="photoUrl" size="xl" />
                                <span
                                    class="absolute inset-0 flex items-center justify-center rounded-full bg-csc-blue/70 text-white opacity-0 transition-opacity duration-150 group-hover:opacity-100 group-focus:opacity-100"
                                    :class="photoBusy && 'opacity-100'"
                                    aria-hidden="true"
                                >
                                    <AppIcon :name="photoBusy ? 'clock' : 'upload'" class="size-5" />
                                </span>
                            </button>
                        </div>

                        <input
                            ref="photoInput"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="hidden"
                            @change="onPhotoChosen"
                        />
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-csc-ink">{{ photoSourceLabel }}</p>
                            <p class="mt-1 text-xs leading-relaxed text-csc-ink-subtle">
                                Use a clear, recent photo of yourself. It is cropped to a square and shown as a
                                circle.
                            </p>

                            <!-- Photo controls -->
                            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                                <button
                                    type="button"
                                    class="text-xs font-medium text-csc-blue underline-offset-2 hover:underline disabled:opacity-50"
                                    :disabled="photoBusy"
                                    @click="choosePhoto"
                                >
                                    {{ user.avatar ? 'Change photo' : 'Add photo' }}
                                </button>

                                <button
                                    v-if="user.avatar"
                                    type="button"
                                    class="text-xs font-medium text-csc-ink-subtle underline-offset-2 hover:text-csc-red-ink hover:underline disabled:opacity-50"
                                    :disabled="photoBusy"
                                    @click="removePhoto"
                                >
                                    Remove
                                </button>
                            </div>

                            <p v-if="photoError" class="mt-1.5 text-xs font-medium text-csc-red-ink">
                                {{ photoError }}
                            </p>
                            <p v-else class="mt-1.5 text-xs text-csc-ink-subtle">
                                JPG, PNG or WebP · up to 2 MB. Photos are squared and resized automatically.
                            </p>
                        </div>
                    </div>
                </AppCard>

                <!--
                    Linked Accounts. Adapted from the recruitment system's
                    complete-profile page, which pairs the same two cards — but
                    rebuilt on AppCard and the theme tokens rather than carrying
                    that app's raw greys across.
                -->
                <AppCard title="Linked Accounts" subtitle="Connect Google to sign in without a password.">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <!--
                                Google's own mark, in Google's own colours: a
                                provider logo is the one place the theme tokens
                                do not apply, because recolouring it would make
                                it something other than the badge people are
                                looking for. Inline rather than a remote asset —
                                nothing here loads from a third party.
                            -->
                            <svg class="size-8 shrink-0" viewBox="0 0 24 24" role="img" aria-label="Google">
                                <path
                                    fill="#4285F4"
                                    d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"
                                />
                                <path
                                    fill="#34A853"
                                    d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                                />
                                <path
                                    fill="#FBBC05"
                                    d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                                />
                                <path
                                    fill="#EA4335"
                                    d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                                />
                            </svg>

                            <div class="min-w-0">
                                <p class="text-sm font-medium text-csc-ink">Google</p>
                                <!-- Status carries an icon and a word, never colour alone. -->
                                <p
                                    class="mt-0.5 flex items-center gap-1 text-xs"
                                    :class="user.has_google ? 'text-success' : 'text-csc-ink-subtle'"
                                >
                                    <AppIcon :name="user.has_google ? 'check' : 'link'" class="size-3.5" />
                                    {{ user.has_google ? 'Connected' : 'Not connected' }}
                                </p>
                                <!--
                                    Named, not just "Connected": the Google
                                    address does not have to match the TIMS one,
                                    so this is what makes a wrong account
                                    noticeable. Phrased as a sign-in identity,
                                    because that is all it is — it never
                                    receives CSC mail.
                                -->
                                <p v-if="user.google_email" class="truncate text-xs text-csc-ink-subtle">
                                    Signed in as {{ user.google_email }}
                                </p>
                            </div>
                        </div>

                        <!--
                            A real link, not an Inertia visit: this leaves the
                            app for Google's consent screen, which an XHR
                            cannot follow.
                        -->
                        <AppButton
                            v-if="!user.has_google"
                            href="/profile/google/connect"
                            external
                            icon="link"
                            :disabled="!user.google_configured"
                        >
                            Connect
                        </AppButton>

                        <AppButton
                            v-else-if="user.has_password"
                            variant="ghost"
                            :disabled="disconnecting"
                            @click="confirmingDisconnect = true"
                        >
                            {{ disconnecting ? 'Disconnecting…' : 'Disconnect' }}
                        </AppButton>
                    </div>

                    <p v-if="!user.google_configured" class="mt-4 text-xs text-csc-ink-subtle">
                        Google sign-in is not configured on this server yet.
                    </p>

                    <!--
                        Google-created accounts have no password, so
                        disconnecting would lock them out. The card says so
                        instead of offering a button that only ever refuses.
                    -->
                    <p
                        v-else-if="user.has_google && !user.has_password"
                        class="mt-4 flex items-start gap-1.5 rounded-lg bg-info-soft px-3 py-2 text-xs text-csc-ink-muted"
                    >
                        <AppIcon name="shield" class="mt-0.5 size-3.5 shrink-0 text-info" />
                        <span>
                            Google is currently the only way in to this account. Use
                            <strong class="font-medium">Create Password</strong> in the account menu first — then
                            you can sign in with your email address and disconnect Google.
                        </span>
                    </p>

                    <p v-else class="mt-4 text-xs text-csc-ink-subtle">
                        {{
                            user.has_google
                                ? 'You can sign in with Google or with your email and password.'
                                : 'Connect any Google account — it does not have to match your CSC email. You will be able to sign in with one tap, and your Google photo becomes your profile photo.'
                        }}
                    </p>
                </AppCard>
            </div>

            <!--
                Email address.

                Its own card, full width, below the pair above: this is the one
                setting on the page that decides both how you sign in and where
                every certificate and notice is delivered, so it does not belong
                tucked into a half-column beside the photo picker.
            -->
            <AppCard
                title="Email Address"
                subtitle="Where the CSC writes to you, and the address you sign in with."
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-csc-blue-tint">
                            <AppIcon name="envelope" class="text-csc-blue" />
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-csc-ink">{{ user.email }}</p>
                            <!-- Status carries an icon and a word, never colour alone. -->
                            <p
                                class="mt-0.5 flex items-center gap-1 text-xs"
                                :class="user.is_verified ? 'text-success' : 'text-warning'"
                            >
                                <AppIcon :name="user.is_verified ? 'check' : 'warning'" class="size-3.5" />
                                {{ user.is_verified ? 'Verified' : 'Not yet verified' }}
                            </p>
                        </div>
                    </div>

                    <AppButton
                        v-if="!user.pending_email && !changingEmail"
                        variant="ghost"
                        icon="settings"
                        @click="openEmailChange"
                    >
                        Change
                    </AppButton>
                </div>

                <!--
                    A change awaiting its link. Shown instead of the form, so
                    there is only ever one pending move to reason about.
                -->
                <div
                    v-if="user.pending_email"
                    class="mt-4 rounded-lg border border-info/40 bg-info-soft px-4 py-3"
                    role="status"
                >
                    <div class="flex items-start gap-2">
                        <AppIcon name="clock" class="mt-0.5 size-4 shrink-0 text-info" />
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-csc-ink">
                                Waiting for you to confirm {{ user.pending_email }}
                            </p>
                            <p class="mt-1 text-xs leading-relaxed text-csc-ink-muted">
                                We sent a link to that address. Open it to finish the change — until you do, your
                                account keeps the address above and nothing else changes. The link lasts an hour.
                            </p>
                            <button
                                type="button"
                                class="mt-2 rounded text-xs font-medium text-csc-ink-subtle underline-offset-2 hover:text-csc-red-ink hover:underline disabled:opacity-50"
                                :disabled="cancellingEmail"
                                @click="cancelPendingEmail"
                            >
                                {{ cancellingEmail ? 'Cancelling…' : 'Cancel this change' }}
                            </button>
                        </div>
                    </div>
                </div>

                <form v-else-if="changingEmail" class="mt-4 space-y-4" novalidate @submit.prevent="submitEmailChange">
                    <AppInput
                        v-model="emailForm.email"
                        label="New email address"
                        type="email"
                        autocomplete="email"
                        placeholder="juan.delacruz@example.com"
                        :error="emailForm.errors.email"
                        hint="Use an address you can open — we send a confirmation link there before anything changes."
                        required
                        autofocus
                    />

                    <!--
                        Google-only accounts have no password to re-enter, and
                        they are the ones most likely to need this — asking for
                        a password they were never issued would make the feature
                        a dead end for exactly them. The server decides; this
                        only renders what it was told.
                    -->
                    <AppInput
                        v-if="user.confirms_with_password"
                        v-model="emailForm.current_password"
                        label="Current password"
                        type="password"
                        autocomplete="current-password"
                        placeholder="••••••••"
                        :error="emailForm.errors.current_password"
                        hint="Confirms it is really you making the change."
                        required
                    />

                    <div class="flex flex-wrap items-center gap-3">
                        <AppButton type="submit" :loading="emailForm.processing" icon="arrow-right">
                            {{ emailForm.processing ? 'Sending link…' : 'Send confirmation link' }}
                        </AppButton>
                        <AppButton variant="ghost" :disabled="emailForm.processing" @click="closeEmailChange">
                            Cancel
                        </AppButton>
                    </div>
                </form>

                <p v-else class="mt-4 flex items-start gap-1.5 text-xs leading-relaxed text-csc-ink-subtle">
                    <AppIcon name="info" class="mt-0.5 size-3.5 shrink-0" />
                    <span>
                        Moving to a new agency? Change this rather than registering again — it keeps your
                        certificates, attendance, and payment history on one account.
                    </span>
                </p>
            </AppCard>

            <!-- Read-only summary: the default view of the profile -->
            <template v-if="viewing">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-csc-blue">Profile summary</h2>
                        <p class="mt-1 text-sm text-csc-ink-muted">
                            Your details as they appear to the CSC. Edit anything that has changed.
                        </p>
                    </div>
                    <AppButton icon="user" @click="viewing = false">Edit Profile</AppButton>
                </div>

                <section
                    v-for="section in viewSections"
                    :key="section.title"
                    class="rounded-xl border border-csc-line bg-white p-6 sm:p-8"
                >
                    <h3 class="text-sm font-semibold tracking-wide text-csc-blue uppercase">{{ section.title }}</h3>
                    <dl class="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                        <div v-for="row in section.rows" :key="row.label">
                            <dt class="text-xs font-medium text-csc-ink-subtle">{{ row.label }}</dt>
                            <dd class="mt-0.5 text-sm font-medium text-csc-ink">{{ row.value || '—' }}</dd>
                        </div>
                    </dl>
                </section>

                <div class="flex justify-end">
                    <AppButton href="/dashboard" variant="ghost" size="lg">Back to Dashboard</AppButton>
                </div>
            </template>

            <!-- Editor -->
            <template v-else>
                <p
                    v-if="isDirty"
                    class="flex items-center gap-2 rounded-xl border border-warning/25 bg-warning-soft px-4 py-3 text-sm font-medium text-warning"
                    role="status"
                >
                    <AppIcon name="warning" class="size-4 shrink-0" />
                    You have unsaved changes.
                </p>

                <p
                    v-if="Object.keys(form.errors).length || hasLocalErrors()"
                    class="flex items-start gap-2 rounded-xl border border-danger/25 bg-danger-soft px-4 py-3 text-sm font-medium text-danger"
                    role="alert"
                >
                    Please review the highlighted fields below.
                </p>

                <form ref="formEl" class="space-y-6" novalidate @submit.prevent="submit">
                    <AppCard title="Personal Information" subtitle="Free-text records are kept in uppercase.">
                        <div class="grid gap-5 sm:grid-cols-12">
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
                                    :options="options.suffixes"
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
                                    :options="options.sexes"
                                    :error="errorFor('sex')"
                                    required
                                />
                            </div>
                            <div class="sm:col-span-4">
                                <AppSelect
                                    v-model="form.civil_status"
                                    label="Civil Status"
                                    :options="options.civilStatuses"
                                    :error="errorFor('civil_status')"
                                    required
                                />
                            </div>
                            <div class="sm:col-span-4">
                                <AppSelect
                                    v-model="form.is_pwd"
                                    label="Person with Disability (PWD)"
                                    :options="options.yesNo"
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
                                    hint="Leave blank if you have none."
                                    :error="errorFor('food_restrictions_details')"
                                    uppercase
                                />
                            </div>
                        </div>
                    </AppCard>

                    <AppCard title="Employment Details">
                        <!-- Employment classification gate: the details only appear once
                             the participant says which kind of employee they are. -->
                        <div>
                            <p id="employment-type-label" class="mb-2 text-sm font-medium text-csc-ink">
                                Are you a government employee?
                                <span class="text-csc-red-ink" aria-hidden="true">*</span>
                            </p>
                            <div
                                role="radiogroup"
                                aria-labelledby="employment-type-label"
                                :aria-invalid="form.errors.employmentType ? 'true' : undefined"
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
                            <p v-if="form.errors.employmentType" class="mt-1.5 text-xs font-medium text-csc-red-ink">
                                {{ form.errors.employmentType }}
                            </p>
                        </div>

                        <div class="mt-5 grid gap-5 sm:grid-cols-12">
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
                                    :options="options.salaryGrades"
                                    :disabled="isPrivate"
                                    :error="errorFor('salary_grade')"
                                    required
                                />
                            </div>

                            <div class="sm:col-span-6">
                                <AppSelect
                                    v-model="form.position_level"
                                    label="Position Level"
                                    :options="options.positionLevels"
                                    :disabled="isPrivate"
                                    :error="errorFor('position_level')"
                                    required
                                />
                            </div>
                            <div class="sm:col-span-6">
                                <AppSelect
                                    v-model="form.employment_status"
                                    label="Employment Status"
                                    :options="options.employmentStatuses"
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
                                    :options="isPrivate ? privateSectorOptions : options.sectors"
                                    :hint="isPrivate ? 'Pick the closest match for your organization.' : undefined"
                                    :error="errorFor('sector')"
                                    required
                                />
                            </div>
                            <div class="sm:col-span-6">
                                <AppSelect
                                    v-model="form.field_office_id"
                                    label="CSC Field Office"
                                    :options="options.fieldOffices"
                                    :error="errorFor('field_office_id')"
                                    required
                                />
                            </div>

                            <div class="sm:col-span-12">
                                <AppTextarea
                                    v-model="form.organization_address"
                                    label="Agency / Company / Organization Address"
                                    rows="3"
                                    maxlength="500"
                                    placeholder="e.g. GOVERNMENT CENTER, PALO, LEYTE"
                                    uppercase
                                    :error="errorFor('organization_address')"
                                    required
                                />
                            </div>
                        </div>
                    </AppCard>

                    <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                        <AppButton href="/dashboard" variant="ghost" size="lg">Cancel</AppButton>
                        <AppButton
                            type="submit"
                            size="lg"
                            :loading="form.processing"
                            :disabled="!isDirty"
                            icon="check"
                        >
                            {{ form.processing ? 'Saving…' : 'Save Changes' }}
                        </AppButton>
                    </div>
                </form>
            </template>
        </div>

        <!-- Discard guard: shown instead of letting a dirty page be navigated away from -->
        <AppModal
            :open="confirmingLeave"
            title="Discard unsaved changes?"
            subtitle="Your profile changes have not been saved."
            @close="cancelDiscard"
        >
            <p class="text-sm leading-relaxed text-csc-ink-muted">
                Leaving now will lose everything you have changed in this session. If you need a moment, keep
                editing and Save before you go.
            </p>

            <template #footer>
                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <AppButton variant="ghost" @click="cancelDiscard">Keep editing</AppButton>
                    <AppButton variant="accent" @click="confirmDiscard">Discard changes</AppButton>
                </div>
            </template>
        </AppModal>

        <AppModal
            :open="confirmingDisconnect"
            title="Disconnect Google?"
            subtitle="You will sign in with your email and password."
            @close="confirmingDisconnect = false"
        >
            <p class="text-sm leading-relaxed text-csc-ink-muted">
                Your training records, registrations, certificates, and profile photo are all unaffected — only
                the sign-in method changes. You can connect the account again at any time.
            </p>

            <template #footer>
                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <AppButton variant="ghost" @click="confirmingDisconnect = false">Keep connected</AppButton>
                    <AppButton variant="accent" @click="disconnectGoogle">Disconnect</AppButton>
                </div>
            </template>
        </AppModal>
    </AuthenticatedLayout>
</template>
