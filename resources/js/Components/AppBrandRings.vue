<template>
    <div class="relative w-28 h-28 mx-auto mb-6">
        <svg class="absolute inset-0 w-28 h-28 animate-spin" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="10" stroke="#e5e7eb" stroke-width="2.5"/>
            <circle cx="12" cy="12" r="10" stroke="#2a338f" stroke-width="2.5"
                stroke-linecap="round" stroke-dasharray="62.832" stroke-dashoffset="20"/>
        </svg>
        <svg class="absolute inset-2 w-[96px] h-[96px] animate-spin" style="animation-duration:2s;animation-direction:reverse;" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="8" stroke="#e5e7eb" stroke-width="1.5"/>
            <circle cx="12" cy="12" r="8" stroke="#ec1c2d" stroke-width="1.5"
                stroke-linecap="round" stroke-dasharray="50.265" stroke-dashoffset="15"/>
        </svg>
        <img v-if="seal" :src="seal" alt="CSC"
            class="absolute w-12 h-12 rounded-full bg-white shadow-sm object-contain p-1.5"
            style="top:50%;left:50%;transform:translate(-50%,-50%);" />
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { sealUrl } from '@/brandSeal';

// The spinning seal at the top of the auth splash — a direct port of the
// recruitment-system component of the same name. Two counter-rotating arcs
// (blue outside, red inside) around the CSC seal; the ring geometry, dash
// arrays, offsets and the reversed 2s inner spin are copied verbatim so the
// two systems animate identically.
//
// The seal is only put into the DOM once it is decoded and ready to paint in
// one go. It used to be a plain <img> pointed at /images/csc-logo.png — the
// 4499×4269 master, 233KB — rendered into a 48px circle, so on a cold cache
// you watched a progressive PNG fill itself in behind the already-spinning
// rings. Two things fix that and both matter:
//
//  - the source is the 256px rendition every other logo in the app already
//    uses (AppLogo, both layouts, Home), so by the time anyone reaches a
//    sign-in it is almost always a cache hit; and
//  - `decode()` means the first frame that includes the seal is a complete
//    one. Until then the rings spin around an empty centre, which is the
//    honest loading state, rather than a half-drawn logo.
//
// A failed decode (a missing file) leaves `seal` null, which is the same
// graceful nothing the old @error handler produced.
const seal = ref(null);

onMounted(() => {
    sealUrl().then((url) => { seal.value = url; }, () => {});
});
</script>
