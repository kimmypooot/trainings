<script setup>
import { computed, useId } from 'vue';

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    label: { type: String, required: true },
    type: { type: String, default: 'text' },
    error: { type: String, default: null },
    hint: { type: String, default: null },
    autocomplete: { type: String, default: null },
    placeholder: { type: String, default: null },
    required: { type: Boolean, default: false },
    // Profile records are kept in uppercase; transforms as the user types.
    uppercase: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const onInput = (event) => {
    const value = props.uppercase ? event.target.value.toUpperCase() : event.target.value;

    // Keep the field in step when the transform changes what was typed.
    if (event.target.value !== value) {
        event.target.value = value;
    }

    emit('update:modelValue', value);
};

const uid = useId();
const inputId = `field-${uid}`;
const errorId = `${inputId}-error`;
const hintId = `${inputId}-hint`;

const describedBy = computed(() => {
    const ids = [];
    if (props.hint) ids.push(hintId);
    if (props.error) ids.push(errorId);

    return ids.length ? ids.join(' ') : undefined;
});
</script>

<template>
    <div>
        <label :for="inputId" class="mb-1.5 block text-sm font-medium text-csc-ink">
            {{ label }}
            <span v-if="required" class="text-csc-red-ink" aria-hidden="true">*</span>
        </label>

        <div class="relative">
            <input
                :id="inputId"
                :type="type"
                :value="modelValue"
                :autocomplete="autocomplete"
                :placeholder="placeholder"
                :required="required"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
                class="w-full rounded-lg border bg-white px-4 py-2.5 text-sm text-csc-ink transition-colors duration-150 placeholder:text-csc-ink/40 focus:outline-2 focus:outline-offset-1"
                :class="[
                    error
                        ? 'border-csc-red-ink focus:outline-csc-red-ink'
                        : 'border-csc-line hover:border-csc-blue/40 focus:border-csc-blue focus:outline-csc-blue',
                    $slots.affix ? 'pr-12' : '',
                    uppercase ? 'uppercase placeholder:normal-case' : '',
                ]"
                @input="onInput"
            />

            <div v-if="$slots.affix" class="absolute inset-y-0 right-0 flex items-center pr-2">
                <slot name="affix" />
            </div>
        </div>

        <p v-if="hint && !error" :id="hintId" class="mt-1.5 text-xs text-csc-ink/60">
            {{ hint }}
        </p>
        <p v-if="error" :id="errorId" class="mt-1.5 text-xs font-medium text-csc-red-ink">
            {{ error }}
        </p>
    </div>
</template>
