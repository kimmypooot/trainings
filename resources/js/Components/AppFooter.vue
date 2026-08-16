<script setup>
// The year advances on its own, so the copyright never goes stale.
const year = new Date().getFullYear();
</script>

<template>
    <footer class="flex-shrink-0 border-t border-csc-line bg-white px-4 py-3 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-csc-ink/55">
            <span>&copy; {{ year }} Civil Service Commission Regional Office VIII. All rights reserved</span>
            <span class="hidden sm:inline">v2.5.0</span>
        </div>
    </footer>
</template>