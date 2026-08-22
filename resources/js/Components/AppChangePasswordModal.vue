<script setup>
import { computed, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppInput from '@/Components/AppInput.vue';
import AppModal from '@/Components/AppModal.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

// Creating a first password rather than rotating an existing one. Read from
// the shared auth prop so it stays right after the password is created and the
// page reloads — the dialog then behaves as an ordinary change.
const page = usePage();
const creating = computed(() => page.props.auth?.user?.has_password === false);

const showCurrent = ref(false);
const showNew = ref(false);
const showConfirm = ref(false);

const close = () => emit('close');

// Fresh state each time it opens — a reopened dialog must not show the last
// attempt's values or errors.
watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) return;

        form.reset();
        form.clearErrors();
        showCurrent.value = false;
        showNew.value = false;
        showConfirm.value = false;
    }
);

// The same policy the register and reset forms enforce server-side:
// Password::min(8)->letters()->numbers().
const requirements = computed(() => {
    const p = form.password ?? '';

    return [
        { label: 'At least 8 characters', met: p.length >= 8 },
        { label: 'At least one letter', met: /[a-zA-Z]/.test(p) },
        { label: 'At least one number', met: /[0-9]/.test(p) },
    ];
});

/*
 * Errors with nowhere to land.
 *
 * The dialog renders current_password only while rotating, so a server error
 * on that field is invisible while creating — which is exactly how a rejected
 * change once failed in complete silence: no toast, no field error, nothing at
 * all. Anything the form did not put next to an input gets shown here instead,
 * so a rule this dialog does not anticipate can never be swallowed again.
 */
const shownFields = computed(() =>
    creating.value
        ? ['password', 'password_confirmation']
        : ['current_password', 'password', 'password_confirmation']
);

const unplacedErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([field]) => !shownFields.value.includes(field))
        .map(([, message]) => message)
);

const submit = () =>
    form.post('/change-password', {
        preserveScroll: true,
        onSuccess: () => close(),
    });
</script>

<template>
    <AppModal :open="open" size="sm" :title="creating ? 'Create Password' : 'Change Password'" @close="close">
        <form class="space-y-4" novalidate @submit.prevent="submit">
            <!-- See unplacedErrors: a rejection the fields cannot show. -->
            <div
                v-if="unplacedErrors.length"
                class="flex items-start gap-2.5 rounded-lg bg-danger-soft px-3 py-2.5 text-sm text-danger"
                role="alert"
            >
                <AppIcon name="warning" class="mt-0.5 shrink-0" size="sm" />
                <span>
                    <span v-for="message in unplacedErrors" :key="message" class="block">{{ message }}</span>
                </span>
            </div>

            <!--
                An account created through Google has no password to re-enter.
                That is not a restriction on it — email sign-in is still
                available, the password simply has not been chosen yet. Saying
                so is what turns a form that could only ever fail into the place
                the password gets made.
            -->
            <div
                v-if="creating"
                class="flex items-start gap-2.5 rounded-lg bg-info-soft px-3 py-2.5 text-sm text-csc-ink-muted"
            >
                <AppIcon name="shield" class="mt-0.5 shrink-0 text-info" size="sm" />
                <span>
                    You signed up with Google, so this account has no password yet. Create one to also sign in
                    with your email address — signing in with Google keeps working either way.
                </span>
            </div>

            <p class="text-sm text-csc-ink-subtle">
                {{
                    creating
                        ? 'Choose a password — at least 8 characters with a letter and a number.'
                        : 'Enter your current password, then a new one — at least 8 characters with a letter and a number.'
                }}
            </p>

            <AppInput
                v-if="!creating"
                v-model="form.current_password"
                label="Current Password"
                :type="showCurrent ? 'text' : 'password'"
                autocomplete="current-password"
                placeholder="Enter your current password"
                :error="form.errors.current_password"
                required
            >
                <template #affix>
                    <button
                        type="button"
                        class="rounded p-1 text-csc-ink-subtle transition-colors hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        :aria-label="showCurrent ? 'Hide current password' : 'Show current password'"
                        @click="showCurrent = !showCurrent"
                    >
                        <AppIcon :name="showCurrent ? 'eye-off' : 'eye'" size="sm" />
                    </button>
                </template>
            </AppInput>

            <AppInput
                v-model="form.password"
                :label="creating ? 'Password' : 'New Password'"
                :type="showNew ? 'text' : 'password'"
                autocomplete="new-password"
                :placeholder="creating ? 'Choose a password' : 'Enter a new password'"
                :error="form.errors.password"
                required
            >
                <template #affix>
                    <button
                        type="button"
                        class="rounded p-1 text-csc-ink-subtle transition-colors hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        :aria-label="showNew ? 'Hide new password' : 'Show new password'"
                        @click="showNew = !showNew"
                    >
                        <AppIcon :name="showNew ? 'eye-off' : 'eye'" size="sm" />
                    </button>
                </template>
            </AppInput>

            <ul class="space-y-1.5 rounded-lg bg-csc-blue-tint/60 p-3" aria-label="Password requirements">
                <li
                    v-for="requirement in requirements"
                    :key="requirement.label"
                    class="flex items-center gap-2 text-xs"
                    :class="requirement.met ? 'font-medium text-success' : 'text-csc-ink-subtle'"
                >
                    <AppIcon :name="requirement.met ? 'check' : 'close'" size="sm" class="shrink-0" />
                    {{ requirement.label }}
                </li>
            </ul>

            <AppInput
                v-model="form.password_confirmation"
                :label="creating ? 'Confirm Password' : 'Confirm New Password'"
                :type="showConfirm ? 'text' : 'password'"
                autocomplete="new-password"
                :placeholder="creating ? 'Repeat the password' : 'Repeat the new password'"
                :error="form.errors.password_confirmation"
                required
            >
                <template #affix>
                    <button
                        type="button"
                        class="rounded p-1 text-csc-ink-subtle transition-colors hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        :aria-label="showConfirm ? 'Hide confirmation' : 'Show confirmation'"
                        @click="showConfirm = !showConfirm"
                    >
                        <AppIcon :name="showConfirm ? 'eye-off' : 'eye'" size="sm" />
                    </button>
                </template>
            </AppInput>

            <div class="flex justify-end gap-2 pt-1">
                <AppButton type="button" variant="ghost" @click="close">Cancel</AppButton>
                <AppButton type="submit" icon="lock" :loading="form.processing">
                    {{ creating ? 'Create Password' : 'Update Password' }}
                </AppButton>
            </div>
        </form>
    </AppModal>
</template>