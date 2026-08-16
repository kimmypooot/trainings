<script setup>
import AppModal from '@/Components/AppModal.vue';
import AppButton from '@/Components/AppButton.vue';

/**
 * The "are you sure?" dialog for a decision that does not need a reason.
 *
 * Rejections and overrides use AppPromptModal instead — those put a recorded
 * reason on the record. This one is for the positive one-click decisions whose
 * weight comes from being hard to reverse (money verified, a slot released),
 * not from needing the human's note kept alongside them.
 */
defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    description: { type: String, default: null },
    confirmLabel: { type: String, default: 'Confirm' },
    processing: { type: Boolean, default: false },
});

const emit = defineEmits(['confirm', 'close']);
</script>

<template>
    <AppModal :open="open" :title="title" @close="emit('close')">
        <p v-if="description" class="text-sm text-csc-ink/70">{{ description }}</p>

        <template #footer>
            <div class="flex flex-col gap-2 sm:flex-row-reverse">
                <AppButton size="sm" :loading="processing" @click="emit('confirm')">
                    {{ confirmLabel }}
                </AppButton>
                <AppButton size="sm" variant="ghost" :disabled="processing" @click="emit('close')">
                    Cancel
                </AppButton>
            </div>
        </template>
    </AppModal>
</template>
