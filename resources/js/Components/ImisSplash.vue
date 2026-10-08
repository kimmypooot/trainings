<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * The IMIS transition splash, the same in every system under the IMIS hub:
 * a white card on a muted page, the CSC seal inside two counter-rotating
 * rings (navy outer, red inner), the message, a line under it and an
 * optional note, and three dots that take turns lighting up red. A
 * prop-driven copy of new-imis Components/Feedback/TransitionSplash.vue;
 * the styles are the .imis-tr classes in resources/css/imis-loading.css.
 */
const props = defineProps({
    visible: { type: Boolean, default: false },
    title: { type: String, default: 'Please wait' },
    subtitle: { type: String, default: '' },
    note: { type: String, default: '' },
    // Skip the fade-in, when the card must cover a page from its first frame.
    instant: { type: Boolean, default: false },
    logo: { type: String, default: '/images/imis/csc-logo.png' },
});

const rings = [
    { cls: 'imis-tr__ring-outer', r: 10, width: 2.5, arc: 'imis-tr__arc--navy', dash: 62.832, offset: 20 },
    { cls: 'imis-tr__ring-inner', r: 8, width: 1.5, arc: 'imis-tr__arc--red', dash: 50.265, offset: 15 },
];

// One dot lights up at a time, moving every 400ms.
const activeDot = ref(0);
let dotsTimer = null;

watch(
    () => props.visible,
    (visible) => {
        window.clearInterval(dotsTimer);
        if (!visible) return;
        activeDot.value = 0;
        dotsTimer = window.setInterval(() => (activeDot.value = (activeDot.value + 1) % 3), 400);
    },
    { immediate: true },
);

onBeforeUnmount(() => window.clearInterval(dotsTimer));
</script>

<template>
    <Teleport to="body">
        <Transition :name="instant ? 'imis-tr-instant' : 'imis-tr'" :duration="{ enter: 450, leave: 300 }">
            <div v-if="visible" class="imis-tr" role="status" aria-live="polite" aria-busy="true">
                <div class="imis-tr__card">
                    <div class="imis-tr__rings" aria-hidden="true">
                        <svg v-for="ring in rings" :key="ring.cls" :class="ring.cls" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" :r="ring.r" class="imis-tr__track" :stroke-width="ring.width" />
                            <circle
                                cx="12"
                                cy="12"
                                :r="ring.r"
                                :class="ring.arc"
                                :stroke-width="ring.width"
                                stroke-linecap="round"
                                :stroke-dasharray="ring.dash"
                                :stroke-dashoffset="ring.offset"
                            />
                        </svg>
                        <img :src="logo" alt="" class="imis-tr__logo" draggable="false" />
                    </div>

                    <div class="imis-tr__msg">{{ title }}</div>
                    <div v-if="subtitle" class="imis-tr__sub">{{ subtitle }}</div>
                    <div v-if="note" class="imis-tr__note">{{ note }}</div>

                    <div class="imis-tr__dots" aria-hidden="true">
                        <span v-for="index in 3" :key="index" :class="{ 'is-active': activeDot === index - 1 }" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
