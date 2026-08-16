<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';

/**
 * The general-purpose dialog.
 *
 * PrivacyNoticeModal stays separate on purpose: consent has no decline path, so
 * it must not close on Escape or a backdrop click. This one does both, which is
 * the right behaviour for everything else.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
    // Confirm-style dialogs (sign out, delete) own their copy in the body and
    // shouldn't show the header bar with its close button — there is nothing
    // to dismiss except the choice itself.
    hideHeader: { type: Boolean, default: false },
    size: {
        type: String,
        default: 'md', // sm | md | lg
        validator: (value) => ['sm', 'md', 'lg'].includes(value),
    },
});

const emit = defineEmits(['close']);

const titleId = useId();
const dialogRef = ref(null);

let lastFocused = null;

const widths = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-3xl' };
const width = computed(() => widths[props.size]);

const focusables = () =>
    dialogRef.value
        ? [
              ...dialogRef.value.querySelectorAll(
                  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
              ),
          ].filter((el) => el.offsetParent !== null)
        : [];

const close = () => emit('close');

const onKeydown = (event) => {
    if (event.key === 'Escape') {
        event.preventDefault();
        close();

        return;
    }

    if (event.key !== 'Tab') return;

    const items = focusables();
    if (!items.length) {
        // Nothing focusable yet (the body is still loading) — keep focus on the
        // dialog rather than letting Tab escape to the page underneath.
        event.preventDefault();

        return;
    }

    const first = items[0];
    const last = items[items.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
};

const release = () => {
    window.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
};

watch(
    () => props.open,
    async (isOpen) => {
        if (isOpen) {
            lastFocused = document.activeElement;
            document.body.style.overflow = 'hidden';
            window.addEventListener('keydown', onKeydown);

            await nextTick();
            (focusables()[0] ?? dialogRef.value)?.focus();

            return;
        }

        release();
        // Returning focus to the trigger is what makes a modal navigable by
        // keyboard — without it, focus falls back to the top of the document.
        lastFocused?.focus?.();
    }
);

onBeforeUnmount(release);
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-150"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-150"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-(--z-modal) flex items-end justify-center bg-black/60 p-0 sm:items-center sm:p-4"
                @click.self="close"
            >
                <div
                    ref="dialogRef"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="title ? titleId : undefined"
                    tabindex="-1"
                    class="flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl focus:outline-none sm:rounded-2xl"
                    :class="width"
                >
                    <header
                        v-if="!hideHeader"
                        class="flex shrink-0 items-start justify-between gap-4 border-b border-csc-line px-5 py-4 sm:px-6"
                    >
                        <div class="min-w-0">
                            <h2 :id="titleId" class="text-base font-semibold tracking-tight text-csc-blue sm:text-lg">
                                {{ title }}
                            </h2>
                            <p v-if="subtitle" class="mt-1 text-sm text-csc-ink/60">{{ subtitle }}</p>
                        </div>

                        <button
                            type="button"
                            class="-m-1.5 shrink-0 rounded-lg p-1.5 text-csc-ink/50 transition-colors hover:bg-csc-blue-tint hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            aria-label="Close dialog"
                            @click="close"
                        >
                            <AppIcon name="close" />
                        </button>
                    </header>

                    <div class="flex-1 overflow-y-auto px-5 py-4 sm:px-6 sm:py-5">
                        <slot />
                    </div>

                    <footer
                        v-if="$slots.footer"
                        class="shrink-0 border-t border-csc-line bg-csc-blue-tint/50 px-5 py-4 sm:px-6"
                    >
                        <slot name="footer" />
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
