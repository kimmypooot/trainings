<template>
    <ImisSplash
        :visible="splash.visible"
        :instant="splash.instant"
        :title="copy.title"
        :subtitle="copy.subtitle"
        :note="copy.note"
        :logo="splash.logo ?? undefined"
        :lead="splash.lead ?? undefined"
    />
</template>

<script setup>
import { computed, onBeforeUnmount, watch } from 'vue';
import ImisSplash from '@/Components/ImisSplash.vue';
import { authSplash as splash } from '@/authSplash';

/*
 * Full-screen "signing you in / out" splash. It is the IMIS transition
 * splash (ImisSplash), so every system under IMIS shows the same screen;
 * the sequence and its timings stay in @/authSplash. Mounted once beside
 * the app (app.js) so it outlives the page swap.
 *
 * The three stages read as IMIS's do: "Signing you in" (or the stage's own
 * title, e.g. "Creating your account", "Opening TIMS"), "Login Successful /
 * Welcome back, <name>", and "Signing Out".
 *
 * The body scroll is locked while it is up: the splash fades out over a live
 * page that is usually taller than the screen.
 */
const copy = computed(() => {
    if (splash.stage === 'welcome') {
        return {
            title: 'Login Successful',
            subtitle: splash.name ? `Welcome back, ${splash.name}` : 'Welcome back',
            note: splash.subtitle,
        };
    }

    if (splash.stage === 'goodbye') {
        return { title: 'Signing Out', subtitle: splash.subtitle, note: '' };
    }

    return { title: splash.title ?? 'Signing you in', subtitle: splash.subtitle, note: '' };
});

const releaseScroll = () => {
    document.body.style.overflow = '';
};

watch(
    () => splash.visible,
    (visible) => {
        if (visible) {
            document.body.style.overflow = 'hidden';
        } else {
            // ImisSplash's leave transition is 300ms.
            window.setTimeout(releaseScroll, 300);
        }
    },
    { immediate: true },
);

onBeforeUnmount(releaseScroll);
</script>
