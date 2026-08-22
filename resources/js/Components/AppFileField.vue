<script setup>
/**
 * A labelled file input.
 *
 * AppInput cannot cover this: it binds a value, and a file input is the one
 * control a page is not allowed to set the value of. So the markup was being
 * hand-rolled at every upload — the payment proof, the refund proof, the
 * supervisory document — and the agency-request screens need seven more. Nine
 * copies of the same label/input/hint/error block is how the error styling on
 * one of them quietly stops matching the rest.
 *
 * Emits the File itself rather than the event, so callers write
 * `@change="form.proof = $event"` instead of reaching into the DOM.
 */
defineProps({
    id: { type: String, required: true },
    label: { type: String, required: true },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
    accept: { type: String, default: null },
});

const emit = defineEmits(['change']);

const onChange = (event) => emit('change', event.target.files[0] ?? null);
</script>

<template>
    <div>
        <label :for="id" class="mb-1.5 block text-sm font-medium text-csc-ink">
            {{ label }}
            <span v-if="required" class="text-csc-red-ink" aria-hidden="true">*</span>
        </label>

        <input
            :id="id"
            type="file"
            :accept="accept"
            :required="required"
            class="w-full rounded-lg border bg-white px-4 py-2.5 text-sm text-csc-ink transition-colors duration-150 file:mr-3 file:rounded file:border-0 file:bg-csc-blue-tint file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-csc-blue"
            :class="
                error
                    ? 'border-csc-red-ink focus:outline-csc-red-ink'
                    : 'border-csc-line hover:border-csc-blue/40 focus:border-csc-blue focus:outline-csc-blue'
            "
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="hint ? `${id}-hint` : undefined"
            @change="onChange"
        />

        <p v-if="hint" :id="`${id}-hint`" class="mt-1.5 text-xs text-csc-ink-subtle">{{ hint }}</p>
        <p v-if="error" class="mt-1.5 text-xs font-medium text-csc-red-ink">{{ error }}</p>
    </div>
</template>
