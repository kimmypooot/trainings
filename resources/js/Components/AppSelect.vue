<script setup>
import { computed, useId } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    modelValue: { type: [String, Number, Boolean], default: '' },
    label: { type: String, required: true },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Select…' },
    error: { type: String, default: null },
    hint: { type: String, default: null },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);

// Accepts either a plain list of strings or {value, label} pairs, so options
// backed by a reference table can carry an id while fixed lists stay simple.
const normalized = computed(() =>
    props.options.map((option) =>
        typeof option === 'object' && option !== null
            ? { value: option.value, label: option.label }
            : { value: option, label: option }
    )
);

const uid = useId();
const selectId = `field-${uid}`;
const errorId = `${selectId}-error`;
const hintId = `${selectId}-hint`;

const describedBy = computed(() => {
    const ids = [];
    if (props.hint) ids.push(hintId);
    if (props.error) ids.push(errorId);

    return ids.length ? ids.join(' ') : undefined;
});
</script>

<template>
    <div>
        <label v-if="label" :for="selectId" class="mb-1.5 block text-sm font-medium text-csc-ink">
            {{ label }}
            <span v-if="required" class="text-csc-red-ink" aria-hidden="true">*</span>
        </label>

        <div class="relative">
            <select
                :id="selectId"
                :value="modelValue"
                :required="required"
                :disabled="disabled"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
                class="w-full appearance-none rounded-lg border bg-white py-2.5 pr-10 pl-4 text-sm text-csc-ink transition-colors duration-150 focus:outline-2 focus:outline-offset-1 disabled:cursor-not-allowed disabled:bg-csc-blue-tint/50 disabled:text-csc-ink/60"
                :class="
                    error
                        ? 'border-csc-red-ink focus:outline-csc-red-ink'
                        : 'border-csc-line hover:border-csc-blue/40 focus:border-csc-blue focus:outline-csc-blue'
                "
                @change="$emit('update:modelValue', $event.target.value)"
            >
                <!--
                    An optional select has to be un-pickable again: disabling the
                    placeholder on a field that allows no answer traps whoever
                    chose one by accident.
                -->
                <option value="" :disabled="required">{{ placeholder }}</option>
                <option v-for="option in normalized" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>

            <AppIcon
                name="chevron-down"
                size="sm"
                class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-csc-ink/50"
            />
        </div>

        <p v-if="hint && !error" :id="hintId" class="mt-1.5 text-xs text-csc-ink/60">{{ hint }}</p>
        <p v-if="error" :id="errorId" class="mt-1.5 text-xs font-medium text-csc-red-ink">{{ error }}</p>
    </div>
</template>
