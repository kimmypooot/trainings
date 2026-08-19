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
        <img src="/images/csc-logo.png" alt="CSC"
            class="absolute w-12 h-12 rounded-full bg-white shadow-sm object-contain p-1.5"
            style="top:50%;left:50%;transform:translate(-50%,-50%);"
            @error="e => e.target.style.display = 'none'" />
    </div>
</template>

<script setup>
// The spinning seal at the top of the auth splash — a direct port of the
// recruitment-system component of the same name. Two counter-rotating arcs
// (blue outside, red inside) around the CSC seal; the ring geometry, dash
// arrays, offsets and the reversed 2s inner spin are copied verbatim so the
// two systems animate identically.
//
// The seal is served from /images rather than bundled, matching the original,
// and app.js warms that exact URL at boot so the circle is never blank.
</script>
