<script setup>
import { computed, ref, watch } from 'vue';
import AppModal from '@/Components/AppModal.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import AppButton from '@/Components/AppButton.vue';

/**
 * Asks for a reason before a decision goes through.
 *
 * Replaces window.prompt, which cannot be styled, cannot show the context of
 * what is being decided, cannot validate before it closes, and on some mobile
 * browsers is suppressed outright — meaning a staff member could tap "Reject"
 * and have nothing happen at all.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    /** Context above the field: who or what this decision affects. */
    description: { type: String, default: null },
    label: { type: String, default: 'Reason' },
    hint: { type: String, default: null },
    confirmLabel: { type: String, default: 'Confirm' },
    /** Reasons are on the record, so a minimum length is a real requirement. */
    minLength: { type: Number, default: 1 },
    processing: { type: Boolean, default: false },
});

const emit = defineEmits(['confirm', 'close']);

const value = ref('');
const touched = ref(false);

// Each opening starts clean; a reason left over from the last decision is the
// kind of thing that ends up on the wrong person's record.
watch(
    () => props.open,
    (open) => {
        if (open) {
            value.value = '';
            touched.value = false;
        }
    }
);

const trimmed = computed(() => value.value.trim());
const valid = computed(() => trimmed.value.length >= props.minLength);

const error = computed(() => {
    if (!touched.value || valid.value) return null;

    return props.minLength > 1
        ? `Give at least ${props.minLength} characters.`
        : 'This is required.';
});

const submit = () => {
    touched.value = true;

    if (!valid.value) return;

    emit('confirm', trimmed.value);
};
</script>

<template>
    <AppModal :open="open" :title="title" :subtitle="description" @close="emit('close')">
        <AppTextarea
            v-model="value"
            :label="label"
            :hint="hint"
            :error="error"
            :rows="3"
            required
            @focusout="touched = true"
            @keydown.enter.meta="submit"
            @keydown.enter.ctrl="submit"
        />

        <template #footer>
            <div class="flex flex-col gap-2 sm:flex-row-reverse">
                <AppButton size="sm" :loading="processing" @click="submit">
                    {{ confirmLabel }}
                </AppButton>
                <AppButton size="sm" variant="ghost" :disabled="processing" @click="emit('close')">
                    Cancel
                </AppButton>
            </div>
        </template>
    </AppModal>
</template>
