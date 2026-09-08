<script setup>
import { computed, ref, watch } from 'vue';
import AppCombobox from '@/Components/AppCombobox.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppInput from '@/Components/AppInput.vue';
import AppSelect from '@/Components/AppSelect.vue';

/**
 * Employer, sector and CSC field office — the three fields that move together.
 *
 * Three forms fill in a profile: the participant's one-time gate, their later
 * edit screen, and the HRD editor. All three carried their own copy of these
 * fields, which is exactly how the roster's table and cards drifted into
 * different feature sets. One component mounted by all three is the guard.
 *
 * Two modes, and the toggle is explicit rather than inferred. Picking an agency
 * fills its sector and office and shows them locked, because that pairing is a
 * fact about the agency and not a question for the participant — a wrong one is
 * corrected once in the reference rather than in one person's record. The
 * checkbox is the escape hatch, and it restores exactly the form that existed
 * before the reference list did.
 *
 * The locked fields stay *visible* rather than hidden. Which field office
 * serves you decides who can see your registration, so a participant reading
 * their own profile is entitled to the answer even when they cannot change it.
 */
const props = defineProps({
    // The Inertia form object, mutated in place. Passing the form rather than
    // emitting three v-models keeps the two modes' bookkeeping in here, where
    // the rule about which field is cleared when belongs.
    form: { type: Object, required: true },
    options: { type: Object, required: true },
    // Narrowed by the private-employment gate on the participant's own forms.
    // Defaults to the full list, which is what the HRD editor wants.
    sectorOptions: { type: Array, default: null },
    sectorHint: { type: String, default: null },
    fieldOfficeHint: { type: String, default: null },
    // The pages resolve errors differently — the participant forms fold in
    // their own client-side checks — so the accessor is passed in.
    errorFor: { type: Function, default: null },
});

const emit = defineEmits(['agency-selected']);

const agencies = computed(() => props.options.agencies ?? []);
const sectors = computed(() => props.sectorOptions ?? props.options.sectors ?? []);

const error = (field) => (props.errorFor ? props.errorFor(field) : props.form.errors?.[field] ?? null);

/*
 * Which mode the form opens in.
 *
 * A record already linked to an agency opens on the picker. A record with a
 * typed employer and no link opens on the typed fields — reopening it on the
 * picker would show an empty required field to somebody who has already
 * answered it. An empty form opens on the picker, unless this deployment has
 * not built its list yet, in which case the picker is a dead end.
 */
const unlisted = ref(agencies.value.length === 0 || (!props.form.agency_id && Boolean(props.form.organization_name)));

const selected = computed(
    () => agencies.value.find((agency) => String(agency.value) === String(props.form.agency_id)) ?? null
);

// The office is only locked when the agency actually names one. An agency whose
// serving office has not been decided leaves the question open rather than
// answering it with a blank.
const officeLocked = computed(() => Boolean(selected.value?.field_office_id));

const officeLabel = computed(
    () =>
        props.options.fieldOffices?.find((office) => String(office.value) === String(props.form.field_office_id))
            ?.label ?? ''
);

const onSelect = (agency) => {
    if (!agency) {
        props.form.organization_name = '';

        return;
    }

    // Mirrored locally so the form reads correctly the moment it is picked. The
    // server derives all three from agency_id regardless and does not trust
    // these — see ProfileService::resolveEmployer.
    props.form.organization_name = agency.name;
    props.form.sector = agency.sector;

    if (agency.field_office_id) props.form.field_office_id = agency.field_office_id;

    emit('agency-selected', agency);
};

watch(unlisted, (isUnlisted) => {
    if (isUnlisted) {
        // The name, sector and office are left standing: someone whose agency
        // was *almost* right has just said they want to correct it by hand, and
        // clearing all three would make them retype what was already close.
        props.form.agency_id = '';

        return;
    }

    // Going back to the picker drops the typed name, so the field cannot sit
    // there showing an employer that no longer matches the (empty) selection.
    props.form.organization_name = '';
});
</script>

<template>
    <div class="sm:col-span-12">
        <AppCombobox
            v-if="!unlisted"
            v-model="form.agency_id"
            label="Name of Agency / Company / Organization"
            placeholder="Type to search — name or acronym"
            :options="agencies"
            hint="Start typing your employer's name. Your sector and field office are filled in for you."
            empty-text="No agency matches. Tick the box below to type it in."
            :error="error('agency_id') || error('organization_name')"
            required
            @select="onSelect"
        />

        <AppInput
            v-else
            v-model="form.organization_name"
            label="Name of Agency / Company / Organization"
            autocomplete="organization"
            placeholder="e.g. DEPARTMENT OF EDUCATION"
            maxlength="255"
            hint="Enter the full name — do not abbreviate."
            :error="error('organization_name')"
            uppercase
            required
        />

        <label v-if="agencies.length" class="mt-2.5 flex items-start gap-2.5 text-sm text-csc-ink-muted">
            <input
                v-model="unlisted"
                type="checkbox"
                class="mt-0.5 size-4 shrink-0 rounded border-csc-line text-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
            />
            <span>My agency is not on the list — let me type it and choose the sector and field office myself.</span>
        </label>
    </div>

    <div class="sm:col-span-6">
        <AppSelect
            v-model="form.sector"
            label="Sector"
            :options="sectors"
            :hint="selected ? undefined : sectorHint"
            :disabled="Boolean(selected)"
            :error="error('sector')"
            required
        />

        <p v-if="selected" class="mt-1.5 flex items-start gap-1.5 text-xs text-csc-ink-subtle">
            <AppIcon name="check" size="sm" class="mt-px shrink-0" aria-hidden="true" />
            <span>From {{ selected.name }}'s record.</span>
        </p>
    </div>

    <div class="sm:col-span-6">
        <AppSelect
            v-model="form.field_office_id"
            label="CSC Field Office"
            :options="options.fieldOffices"
            :hint="officeLocked ? undefined : fieldOfficeHint"
            :disabled="officeLocked"
            :error="error('field_office_id')"
            required
        />

        <p v-if="officeLocked" class="mt-1.5 flex items-start gap-1.5 text-xs text-csc-ink-subtle">
            <AppIcon name="check" size="sm" class="mt-px shrink-0" aria-hidden="true" />
            <span>{{ officeLabel }} serves {{ selected.name }}.</span>
        </p>
    </div>
</template>
