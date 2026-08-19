<template>
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0" enter-to-class="opacity-100"
                    leave-active-class="transition ease-in duration-200" leave-from-class="opacity-100" leave-to-class="opacity-0"
                    @after-leave="releaseScroll">
            <div v-if="splash.visible" class="fixed inset-0 z-[9999] flex items-center justify-center overflow-hidden"
                style="background: linear-gradient(135deg, #f0eef9 0%, #e8eafa 50%, #fdeef0 100%);">
                <AppAmbientBlobs />

                <div class="relative bg-white/80 backdrop-blur-xl rounded-2xl shadow-lg border border-white/40 p-10 text-center max-w-sm w-full mx-4">
                    <AppBrandRings />

                    <Transition name="pfade" mode="out-in">
                        <div v-if="splash.stage === 'welcome'" key="welcome" class="space-y-2">
                            <p class="text-sm font-medium tracking-wide uppercase text-accent">Welcome back</p>
                            <p class="text-2xl font-bold text-gray-900">{{ splash.name ? `${splash.name}!` : '' }}</p>
                            <p class="text-gray-500 text-sm">{{ splash.subtitle }}</p>
                        </div>
                        <div v-else-if="splash.stage === 'goodbye'" key="goodbye" class="space-y-2">
                            <p class="text-xl font-semibold text-primary">Signing you out</p>
                            <p class="text-gray-500 text-sm">{{ splash.subtitle }}</p>
                        </div>
                        <div v-else key="loading" class="space-y-2">
                            <p class="text-xl font-semibold text-primary">Signing you in</p>
                            <p class="text-gray-500 text-sm">{{ splash.subtitle }}</p>
                        </div>
                    </Transition>

                    <AppPulsingDots />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
import { watch, onBeforeUnmount } from 'vue';
import AppAmbientBlobs from '@/Components/AppAmbientBlobs.vue';
import AppBrandRings from '@/Components/AppBrandRings.vue';
import AppPulsingDots from '@/Components/AppPulsingDots.vue';
import { authSplash as splash } from '@/authSplash';

/*
 * Full-screen "signing you in / out" splash — a direct port of the
 * recruitment-system's AuthSplashOverlay, down to the backdrop gradient, the
 * card's translucent blur, the 300ms/200ms fades and the `pfade` copy
 * transition. The three message states are the ones that system shows: the
 * uppercase "Welcome back" over the name on sign-in, and the plain title over
 * "See you next time!" on sign-out.
 *
 * Two departures, both forced by the difference in navigation model and both
 * invisible on screen:
 *
 *  - The copy comes from the shared module rather than a slot. There the
 *    overlay is re-declared in each of four call sites, which can each own
 *    their own text; here it is mounted once beside the app so that it can
 *    outlive the page swap, which leaves nobody to pass a slot in.
 *
 *  - The body scroll is locked while it is up. There, every sequence ends in a
 *    document navigation, so a scrollbar beside the overlay never has time to
 *    matter; here the splash fades out over a live page that is usually taller
 *    than the screen.
 */
const lockScroll = () => {
    document.body.style.overflow = 'hidden';
};

const releaseScroll = () => {
    document.body.style.overflow = '';
};

watch(() => splash.visible, (visible) => {
    if (visible) lockScroll();
}, { immediate: true });

onBeforeUnmount(releaseScroll);
</script>

<style scoped>
/*
 * The originals' palette, written out rather than mapped onto this app's
 * @theme tokens: `primary` and `accent` are the recruitment-system's own
 * variables, and this overlay is meant to stay a copy of that surface.
 */
.text-primary { color: #2a338f; }
.text-accent  { color: #ec1c2d; }

.pfade-enter-active, .pfade-leave-active { transition: opacity 0.4s ease, transform 0.4s ease; }
.pfade-enter-from { opacity: 0; transform: translateY(8px); }
.pfade-leave-to   { opacity: 0; transform: translateY(-8px); }
</style>
