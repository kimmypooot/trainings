<script setup>
import { computed, nextTick, ref, useAttrs, useId, watch } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';

/**
 * A type-to-filter picker for a list too long to scroll.
 *
 * AppSelect is still the right control for a fixed list of a dozen options — a
 * native <select> beats anything reimplemented, especially on a phone. This is
 * for the case AppSelect cannot serve: several hundred agencies, where the only
 * usable way in is to type three letters of the name.
 *
 * Options take the same {value, label} shape AppSelect accepts, plus an
 * optional `search` string the match runs against instead of the label. That is
 * what lets "DEPED" find an agency whose label spells the name out in full —
 * and it is assembled server-side, so the client cannot decide to match on
 * something else.
 */
const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    label: { type: String, required: true },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Type to search…' },
    error: { type: String, default: null },
    hint: { type: String, default: null },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    // How many matches to render at once. The browser will happily build every
    // row of a long list into the DOM on the first keystroke, when nobody is
    // going to read past the first handful.
    limit: { type: Number, default: 50 },
    emptyText: { type: String, default: 'No match found.' },
});

const emit = defineEmits(['update:modelValue', 'select']);

// Same reasoning as AppSelect: anything not declared above must reach the
// <input> itself, not the wrapper div.
defineOptions({ inheritAttrs: false });

// class/style are the exception, also as in AppSelect: a caller sizing this
// field in a grid means the wrapper, not the control inside it.
const attrs = useAttrs();
const rootClass = computed(() => attrs.class);
const rootStyle = computed(() => attrs.style);
const controlAttrs = computed(() => {
    const { class: _class, style: _style, ...rest } = attrs;

    return rest;
});

const normalized = computed(() =>
    props.options.map((option) => ({
        value: option.value,
        label: option.label,
        search: String(option.search ?? option.label ?? '').toLowerCase(),
        option,
    }))
);

const selected = computed(
    () => normalized.value.find((option) => String(option.value) === String(props.modelValue)) ?? null
);

const query = ref('');
const open = ref(false);
const active = ref(0);
const root = ref(null);
const input = ref(null);

// Every word must appear somewhere, in any order: "educ leyte" finds
// "DEPARTMENT OF EDUCATION - LEYTE DIVISION", which a substring match on the
// whole phrase would miss.
const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();
    if (!needle) return normalized.value;

    const words = needle.split(/\s+/);

    return normalized.value.filter((option) => words.every((word) => option.search.includes(word)));
});

const matches = computed(() => filtered.value.slice(0, props.limit));
const truncated = computed(() => Math.max(filtered.value.length - matches.value.length, 0));

// What the input shows: the chosen label when closed, whatever is being typed
// when open. Keeping the query separate from the model is what lets an
// abandoned search restore the previous choice instead of clearing it.
const display = computed({
    get: () => (open.value ? query.value : selected.value?.label ?? ''),
    set: (value) => {
        query.value = value;
    },
});

const openList = () => {
    if (props.disabled) return;

    open.value = true;
    query.value = '';
    active.value = 0;
};

const closeList = () => {
    open.value = false;
    query.value = '';
};

const choose = (match) => {
    emit('update:modelValue', match.value);
    emit('select', match.option);
    closeList();
    input.value?.blur();
};

const clear = () => {
    emit('update:modelValue', '');
    emit('select', null);
    closeList();
    nextTick(() => input.value?.focus());
};

const move = (delta) => {
    if (!open.value) {
        openList();

        return;
    }

    const count = matches.value.length;
    if (!count) return;

    active.value = (active.value + delta + count) % count;
};

const commit = () => {
    if (!open.value) return;

    const match = matches.value[active.value];
    if (match) choose(match);
};

// Typing moves the highlight back to the top: the row highlighted two
// keystrokes ago is not the row now sitting at that index.
watch(query, () => {
    active.value = 0;
});

// A click away is an abandoned search, not a choice — the model is untouched,
// so the field goes back to showing what it showed before.
const onFocusOut = (event) => {
    if (!root.value?.contains(event.relatedTarget)) closeList();
};

const uid = useId();
const fieldId = `combobox-${uid}`;
const listId = `${fieldId}-list`;
const errorId = `${fieldId}-error`;
const hintId = `${fieldId}-hint`;

const describedBy = computed(() => {
    const ids = [];
    if (props.hint) ids.push(hintId);
    if (props.error) ids.push(errorId);

    return ids.length ? ids.join(' ') : undefined;
});
</script>

<template>
    <div ref="root" :class="rootClass" :style="rootStyle" @focusout="onFocusOut">
        <label v-if="label" :for="fieldId" class="mb-1.5 block text-sm font-medium text-csc-ink">
            {{ label }}
            <span v-if="required" class="text-csc-red-ink" aria-hidden="true">*</span>
        </label>

        <div class="relative">
            <input
                v-bind="controlAttrs"
                :id="fieldId"
                ref="input"
                v-model="display"
                type="text"
                role="combobox"
                autocomplete="off"
                :placeholder="selected && !open ? '' : placeholder"
                :disabled="disabled"
                :aria-expanded="open"
                :aria-controls="listId"
                :aria-activedescendant="open && matches[active] ? `${listId}-${active}` : undefined"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
                class="w-full rounded-lg border bg-white py-2.5 pr-16 pl-4 text-base text-csc-ink transition-colors duration-150 focus:outline-2 focus:outline-offset-1 disabled:cursor-not-allowed disabled:bg-csc-blue-tint/50 disabled:text-csc-ink-subtle sm:text-sm"
                :class="
                    error
                        ? 'border-csc-red-ink focus:outline-csc-red-ink'
                        : 'border-csc-line hover:border-csc-blue/40 focus:border-csc-blue focus:outline-csc-blue'
                "
                @focus="openList"
                @keydown.down.prevent="move(1)"
                @keydown.up.prevent="move(-1)"
                @keydown.enter.prevent="commit"
                @keydown.esc.prevent="closeList"
                @keydown.tab="closeList"
            />

            <div class="pointer-events-none absolute top-1/2 right-3 flex -translate-y-1/2 items-center gap-1">
                <button
                    v-if="selected && !disabled"
                    type="button"
                    class="pointer-events-auto rounded p-0.5 text-csc-ink-subtle hover:text-csc-ink focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                    :aria-label="`Clear ${label}`"
                    @click="clear"
                >
                    <AppIcon name="close" size="sm" />
                </button>
                <AppIcon name="search" size="sm" class="text-csc-ink-subtle" />
            </div>

            <ul
                v-show="open"
                :id="listId"
                role="listbox"
                class="absolute z-(--z-popover) mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-csc-line bg-white py-1 shadow-lg"
            >
                <li
                    v-for="(match, index) in matches"
                    :id="`${listId}-${index}`"
                    :key="match.value"
                    role="option"
                    :aria-selected="String(match.value) === String(modelValue)"
                    class="cursor-pointer px-4 py-2 text-sm text-csc-ink"
                    :class="index === active ? 'bg-csc-blue-tint' : 'hover:bg-csc-blue-tint/60'"
                    @mousedown.prevent="choose(match)"
                    @mousemove="active = index"
                >
                    {{ match.label }}
                </li>

                <li v-if="!matches.length" class="px-4 py-3 text-sm text-csc-ink-subtle">{{ emptyText }}</li>
                <li v-else-if="truncated" class="px-4 py-2 text-xs text-csc-ink-subtle">
                    {{ truncated }} more — keep typing to narrow the list.
                </li>
            </ul>
        </div>

        <p v-if="hint && !error" :id="hintId" class="mt-1.5 text-xs text-csc-ink-subtle">{{ hint }}</p>
        <p v-if="error" :id="errorId" class="mt-1.5 text-xs font-medium text-csc-red-ink">{{ error }}</p>
    </div>
</template>
