<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppInput from '@/Components/AppInput.vue';
import AppSelect from '@/Components/AppSelect.vue';

const props = defineProps({
    agency: { type: Object, default: null },
    /** A name carried over from a typed employer on the index. */
    prefillName: { type: String, default: null },
    /** How many participants typed that name and will be moved onto this agency. */
    claimCount: { type: Number, default: 0 },
    sectors: { type: Array, required: true },
    offices: { type: Array, required: true },
    /** How many participants are currently filed under this agency. */
    linkedProfiles: { type: Number, default: 0 },
});

const editing = computed(() => props.agency !== null);

const form = useForm({
    /*
     * Canonical casing, not uppercase.
     *
     * `agencies.name` holds the agency's proper name as written — "Abuyog
     * Water District" — and it is the `saved` hook that upper-cases it on
     * the way out to `profiles.organization_name`, which is the column that
     * is stored shouting. Putting AppInput's `uppercase` transform on this
     * field would rewrite the reference list itself on every edit, so the
     * picker would show a few hundred title-cased rows and a handful of
     * uppercase ones — and the next re-seed would flip them back, because
     * the file still holds the proper name.
     *
     * A name arriving from a typed employer is uppercase, since that is how
     * profiles store it. It is left exactly as typed rather than
     * title-cased, because no automatic transform survives contact with
     * "Bureau of Fire Protection - Regional Office VIII"; the hint on the
     * field asks for the proper name instead.
     */
    name: props.agency?.name ?? props.prefillName ?? '',
    acronym: props.agency?.acronym ?? '',
    sector: props.agency?.sector ?? '',
    field_office_id: props.agency?.field_office_id ?? '',
    is_active: props.agency?.is_active ?? true,
    /*
     * The exact string these participants typed, carried through the form
     * untouched so the server can find them again.
     *
     * It has to travel separately from `name`, because correcting the name
     * is the entire point: somebody typed "DEPED" and the agency being
     * created is "Department of Education". Matching on the corrected name
     * afterwards would find nobody, and the people who prompted the whole
     * exercise would be left exactly where they were.
     */
    claim: props.prefillName ?? '',
});

const originalOfficeId = props.agency?.field_office_id ?? '';

/*
 * Changing the field office is not a relabel.
 *
 * Saving pushes the new office onto every profile linked to this agency, so
 * those participants leave one office's lists, exports and roster counts and
 * appear in another's. That is a legitimate thing to do — agencies are
 * reassigned — but it is a visibility change wearing a dropdown, and the number
 * of people it carries is the part nobody can guess. So it is confirmed by
 * number before it is saved.
 *
 * Only when people are actually attached: on a new agency, or one nobody is
 * filed under, there is nothing to move and a confirmation would be noise.
 */
const officeIsMoving = computed(
    () => editing.value && String(form.field_office_id) !== String(originalOfficeId)
);

const movesPeople = computed(() => officeIsMoving.value && props.linkedProfiles > 0);

const newOfficeName = computed(
    () => props.offices.find((office) => String(office.value) === String(form.field_office_id))?.label
        ?? 'no field office'
);

const confirmingMove = ref(false);

const submit = () => {
    if (movesPeople.value && !confirmingMove.value) {
        confirmingMove.value = true;
        return;
    }

    confirmingMove.value = false;

    if (editing.value) {
        form.put(`/admin/agencies/${props.agency.id}`);
        return;
    }

    form.post('/admin/agencies');
};

const title = computed(() => (editing.value ? `Edit ${props.agency.name}` : 'Add agency'));
</script>

<template>
    <Head :title="title" />

    <AuthenticatedLayout :title="title" current="admin-agencies">
        <div class="mx-auto max-w-3xl space-y-5">
            <p class="text-sm leading-relaxed text-csc-ink-muted">
                <template v-if="prefillName">
                    “{{ prefillName }}” was typed in by participants who could not find it on the
                    list. Give it its proper name, sector and field office — the
                    {{ claimCount }} {{ claimCount === 1 ? 'participant' : 'participants' }} who
                    typed it will be moved onto the new agency, and pick up its sector and office
                    with it.
                </template>
                <template v-else-if="editing">
                    Corrections here follow through to everyone filed under this agency, so their
                    profiles, exports and search results stay in step with the list.
                </template>
                <template v-else>
                    Adding an agency puts it in the picker on the profile form. Its sector and field
                    office are filled in from this row, so participants are not asked to guess them.
                </template>
            </p>

            <form @submit.prevent="submit">
                <AppCard :title="editing ? 'Agency details' : 'New agency'">
                    <div class="space-y-4">
                        <AppInput
                            v-model="form.name"
                            label="Agency name"
                            placeholder="Department of Education"
                            :error="form.errors.name"
                            :hint="
                                prefillName
                                    ? 'Shown in the picker exactly as written here. Participants typed this in capitals — correct it to the agency\'s proper name if you can.'
                                    : 'Shown in the picker exactly as written here.'
                            "
                            required
                        />

                        <AppInput
                            v-model="form.acronym"
                            label="Acronym"
                            placeholder="DepEd"
                            :error="form.errors.acronym"
                            hint="Optional. Participants often search by acronym, so adding it makes the agency easier to find."
                        />

                        <AppSelect
                            v-model="form.sector"
                            label="Sector"
                            :options="sectors"
                            placeholder="Select a sector…"
                            :error="form.errors.sector"
                            hint="Filled in automatically on the profile of anyone who picks this agency."
                            required
                        />

                        <AppSelect
                            v-model="form.field_office_id"
                            label="Field office"
                            :options="offices"
                            placeholder="No field office yet"
                            :error="form.errors.field_office_id"
                            hint="The office that serves this agency. Leaving it blank still works — the participant is asked to choose their own office instead."
                        />

                        <!--
                            Said before saving, not after. The confirmation
                            repeats it, but somebody who reads the form rather
                            than the dialog should not be surprised either.
                        -->
                        <p
                            v-if="movesPeople"
                            class="rounded-lg border border-warning/30 bg-warning-soft p-3 text-sm text-csc-ink"
                        >
                            <span class="font-semibold">This moves people.</span>
                            {{ linkedProfiles }}
                            {{ linkedProfiles === 1 ? 'participant' : 'participants' }} filed under this
                            agency will move into {{ newOfficeName }}, and will no longer appear in their
                            current office's lists and exports.
                        </p>

                        <label class="flex items-start gap-2.5 text-sm">
                            <input
                                v-model="form.is_active"
                                type="checkbox"
                                class="mt-0.5 size-4 rounded border-csc-line text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            />
                            <span>
                                <span class="font-medium text-csc-ink">Offer this agency on the profile form</span>
                                <span class="block text-xs text-csc-ink-subtle">
                                    Turning this off hides it from the picker. Anyone already filed under
                                    it keeps their records exactly as they are.
                                </span>
                            </span>
                        </label>
                    </div>

                    <template #footer>
                        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <AppButton href="/admin/agencies" variant="secondary">Cancel</AppButton>
                            <AppButton type="submit" :loading="form.processing">
                                {{ editing ? 'Save changes' : 'Add agency' }}
                            </AppButton>
                        </div>
                    </template>
                </AppCard>
            </form>
        </div>

        <AppConfirmModal
            :open="confirmingMove"
            :title="`Move ${linkedProfiles} ${linkedProfiles === 1 ? 'participant' : 'participants'}?`"
            :description="`Changing the field office for ${form.name} moves everyone filed under it into ${newOfficeName}. They will leave their current office's participant lists, exports and roster counts. Their own records are not otherwise changed, and you can move them back by setting the office again.`"
            confirm-label="Move them"
            :processing="form.processing"
            @confirm="submit"
            @close="confirmingMove = false"
        />
    </AuthenticatedLayout>
</template>
