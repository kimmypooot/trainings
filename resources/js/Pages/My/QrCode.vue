<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';

defineProps({
    qr: { type: String, required: true },
    participant: { type: Object, required: true },
});

const confirmingReset = ref(false);
const regenerating = ref(false);

const regenerate = () => {
    regenerating.value = true;
    router.post(
        '/my/qr/regenerate',
        {},
        {
            onFinish: () => {
                regenerating.value = false;
                confirmingReset.value = false;
            },
        }
    );
};
</script>

<template>
    <Head title="My QR Code" />

    <AuthenticatedLayout title="My QR Code" current="qr">
        <div class="mx-auto max-w-lg space-y-5">
            <AppCard>
                <div class="text-center">
                    <p class="text-sm text-csc-ink-muted">Show this code at the registration desk.</p>

                    <!--
                        The plate is pinned to light: `color-scheme` and
                        `forced-color-adjust` stop a browser's auto-dark or
                        high-contrast mode from inverting the code, which would
                        make it unscannable.
                    -->
                    <div class="mt-5 flex justify-center">
                        <div
                            class="rounded-xl border border-csc-line bg-white p-3"
                            style="color-scheme: only light; forced-color-adjust: none"
                        >
                            <img
                                :src="qr"
                                alt="Your personal CSC TIMS check-in QR code"
                                class="block aspect-square w-full max-w-xs bg-white"
                                style="forced-color-adjust: none"
                            />
                        </div>
                    </div>

                    <p class="mt-5 text-lg font-semibold text-csc-blue">{{ participant.name }}</p>
                    <p v-if="participant.position" class="mt-0.5 text-sm text-csc-ink-muted">
                        {{ participant.position }}
                    </p>
                    <p v-if="participant.organization" class="text-sm text-csc-ink-subtle">
                        {{ participant.organization }}
                    </p>
                </div>

                <template #footer>
                    <div class="flex flex-col gap-3">
                        <!--
                            AppButton with the registry's own download glyph.
                            This was a hand-rolled anchor carrying a hand-drawn
                            SVG — the ghost button's classes copied out by hand,
                            which had already drifted (no `gap-2` sizing rule,
                            a 1.8 stroke where AppIcon draws 1.5), and a second
                            arrow-into-tray that did not match the one the rest
                            of the app downloads with.

                            `external`, because this is a PNG: Inertia would
                            try to render it as a page. The `download` attribute
                            falls through to the anchor AppButton renders.
                        -->
                        <AppButton
                            href="/my/qr.png"
                            external
                            variant="ghost"
                            icon="download"
                            download="csc-tims-qr-code.png"
                        >
                            Save or Print My Code
                        </AppButton>

                        <p class="text-xs leading-relaxed text-csc-ink-subtle">
                            This code is unique to you and stays the same across every CSC event. It works without
                            an internet connection once this page has loaded. Do not share screenshots of it.
                        </p>
                    </div>
                </template>
            </AppCard>

            <AppCard title="Trouble with your code?">
                <p class="text-sm leading-relaxed text-csc-ink-muted">
                    If someone else has a photo of your code, issue a new one. Your old code stops working
                    immediately.
                </p>

                <div v-if="!confirmingReset" class="mt-4">
                    <AppButton variant="ghost" size="sm" icon="qr" @click="confirmingReset = true">
                        Issue a New Code
                    </AppButton>
                </div>

                <AppAlert v-else tone="warning" title="Replace your QR code?" class="mt-4">
                    Your current code will stop working straight away. You will need to show the new one at any
                    event you attend.

                    <template #action>
                        <div class="flex gap-2">
                            <AppButton size="sm" variant="ghost" @click="confirmingReset = false">Cancel</AppButton>
                            <AppButton size="sm" variant="accent" :loading="regenerating" icon="qr" @click="regenerate">
                                Replace
                            </AppButton>
                        </div>
                    </template>
                </AppAlert>
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
