<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';

/**
 * Flash messages, in one place.
 *
 * Every page used to re-read `flash.success` and render its own alert, which
 * meant an action redirecting to a page without that boilerplate reported
 * nothing at all. Mounted once in the layout, this cannot be forgotten.
 */
const page = usePage();

const toast = ref(null);
let timer = null;

const TONES = {
    success: { classes: 'border-success/30 bg-success-soft text-success', icon: 'check' },
    error: { classes: 'border-danger/30 bg-danger-soft text-danger', icon: 'warning' },
};

const undo = ref(null);
const remaining = ref(0);
const undoing = ref(false);
let countdown = null;

const dismiss = () => {
    clearTimeout(timer);
    clearInterval(countdown);
    toast.value = null;
    undo.value = null;
};

const show = (tone, message, offer = null) => {
    clearTimeout(timer);
    clearInterval(countdown);

    toast.value = { tone, message, ...TONES[tone] };
    undo.value = offer;

    if (offer) {
        /*
         * An undoable toast lives exactly as long as the server will honour the
         * token. Counting down in the open makes the deadline something the
         * user can see rather than guess at.
         */
        remaining.value = offer.seconds;

        countdown = setInterval(() => {
            remaining.value -= 1;

            if (remaining.value <= 0) dismiss();
        }, 1000);

        return;
    }

    // Errors stay until dismissed. A success is a receipt for something the
    // user just did and does not need to be read twice.
    if (tone === 'success') {
        timer = setTimeout(dismiss, 5000);
    }
};

const applyUndo = () => {
    if (!undo.value) return;

    undoing.value = true;

    router.post(
        '/admin/undo',
        { token: undo.value.token },
        {
            preserveScroll: true,
            // The reversal flashes its own message, which replaces this toast.
            onFinish: () => (undoing.value = false),
        }
    );
};

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.error) return show('error', flash.error);
        if (flash?.success) return show('success', flash.success, flash.undo ?? null);
    },
    { immediate: true, deep: true }
);

onBeforeUnmount(() => {
    clearTimeout(timer);
    clearInterval(countdown);
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200"
            enter-from-class="translate-y-2 opacity-0"
            leave-active-class="transition duration-150"
            leave-to-class="translate-y-2 opacity-0"
        >
            <!--
                Above the mobile tab bar rather than over it, and announced
                politely: a receipt should not interrupt what is being read.
            -->
            <div
                v-if="toast"
                class="fixed inset-x-4 bottom-20 z-(--z-modal) mx-auto max-w-md md:inset-x-auto md:right-6 md:bottom-6"
                role="status"
                aria-live="polite"
            >
                <div
                    class="flex items-start gap-3 rounded-xl border px-4 py-3 shadow-lg"
                    :class="toast.classes"
                >
                    <AppIcon :name="toast.icon" size="sm" class="mt-0.5 shrink-0" />

                    <div class="flex-1">
                        <p class="text-sm font-medium">{{ toast.message }}</p>

                        <button
                            v-if="undo"
                            type="button"
                            class="mt-1.5 rounded text-xs font-semibold underline underline-offset-2 transition-opacity hover:opacity-80 disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current"
                            :disabled="undoing"
                            @click="applyUndo"
                        >
                            Undo<span aria-hidden="true"> · {{ remaining }}s</span>
                        </button>
                    </div>

                    <button
                        type="button"
                        class="-m-1 shrink-0 rounded p-1 opacity-70 transition-opacity hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current"
                        aria-label="Dismiss"
                        @click="dismiss"
                    >
                        <AppIcon name="close" size="sm" />
                    </button>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
