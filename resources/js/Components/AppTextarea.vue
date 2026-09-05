<script setup>
import { computed, useAttrs, useId } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    label: { type: String, required: true },
    rows: { type: Number, default: 4 },
    error: { type: String, default: null },
    hint: { type: String, default: null },
    placeholder: { type: String, default: null },
    required: { type: Boolean, default: false },
    // Profile records are kept in uppercase; transforms as the user types.
    uppercase: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

// Anything not declared above (maxlength…) belongs on the control, not on the
// wrapper div it would otherwise land on — a `maxlength` silently applied to a
// <div> is a constraint the browser never enforces.
defineOptions({ inheritAttrs: false });

const attrs = useAttrs();

const onInput = (event) => {
    const value = props.uppercase ? event.target.value.toUpperCase() : event.target.value;

    // Keep the field in step when the transform changes what was typed.
    if (event.target.value !== value) {
        event.target.value = value;
    }

    emit('update:modelValue', value);
};

const uid = useId();
const fieldId = `field-${uid}`;
const errorId = `${fieldId}-error`;
const hintId = `${fieldId}-hint`;

const describedBy = computed(() => {
    const ids = [];
    if (props.hint) ids.push(hintId);
    if (props.error) ids.push(errorId);

    return ids.length ? ids.join(' ') : undefined;
});

// A character counter for bounded free-text fields, so users can see exactly
// how much room is left before the server would truncate their answer.
const remaining = computed(() => {
    const max = Number(attrs.maxlength);
    return Number.isFinite(max) ? max - props.modelValue.length : null;
});
</script>

<template>
    <div>
        <label :for="fieldId" class="mb-1.5 block text-sm font-medium text-csc-ink">
            {{ label }}
            <span v-if="required" class="text-csc-red-ink" aria-hidden="true">*</span>
        </label>

        <textarea
            v-bind="$attrs"
            :id="fieldId"
            :value="modelValue"
            :rows="rows"
            :placeholder="placeholder"
            :required="required"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="w-full rounded-lg border bg-white px-4 py-2.5 text-base text-csc-ink transition-colors duration-150 placeholder:text-csc-ink-placeholder focus:outline-2 focus:outline-offset-1 sm:text-sm"
            :class="[
                error
                    ? 'border-csc-red-ink focus:outline-csc-red-ink'
                    : 'border-csc-line hover:border-csc-blue/40 focus:border-csc-blue focus:outline-csc-blue',
                uppercase ? 'uppercase placeholder:normal-case' : '',
            ]"
            @input="onInput"
        />

        <div class="mt-1 flex items-start justify-between gap-3">
            <p v-if="hint && !error" :id="hintId" class="text-xs text-csc-ink-subtle">{{ hint }}</p>
            <p v-if="error" :id="errorId" class="text-xs font-medium text-csc-red-ink">{{ error }}</p>
            <p v-if="remaining !== null && !error" class="ml-auto shrink-0 text-xs text-csc-ink-subtle" aria-live="polite">
                {{ remaining }} characters left
            </p>
        </div>
    </div>
</template>