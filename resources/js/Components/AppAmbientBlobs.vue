<template>
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="blob blob-1 absolute -top-20 -left-20 w-72 h-72 rounded-full opacity-20"></div>
        <div class="blob blob-2 absolute -bottom-16 -right-16 w-96 h-96 rounded-full opacity-15"></div>
        <div class="blob blob-3 absolute top-1/3 right-1/4 w-48 h-48 rounded-full opacity-10"></div>
    </div>
</template>

<script setup>
// Decorative background used behind the full-screen auth transition overlay.
//
// A direct port of the recruitment-system component of the same name, kept
// literal — sizes, offsets, opacities, gradients and keyframes — so the two
// systems show the same preloader. The brand hexes are written out rather than
// pulled from the @theme tokens for the same reason; this file is a copy, and
// a token drifting would silently make it stop being one.
</script>

<style scoped>
.blob-1 { background: radial-gradient(circle, #2a338f 0%, transparent 70%); animation: brand-float 8s ease-in-out infinite; }
.blob-2 { background: radial-gradient(circle, #ec1c2d 0%, transparent 70%); animation: brand-float 10s ease-in-out infinite reverse; }
.blob-3 { background: radial-gradient(circle, #2a338f 0%, transparent 70%); animation: brand-float 12s ease-in-out infinite 2s; }

@keyframes brand-float {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33%       { transform: translate(30px, -30px) scale(1.05); }
  66%       { transform: translate(-20px, 20px) scale(0.95); }
}

@media (prefers-reduced-motion: reduce) {
  .blob { animation: none !important; }
}
</style>
