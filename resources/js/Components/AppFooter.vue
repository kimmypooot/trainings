<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

// The year advances on its own, so the copyright never goes stale.
const year = new Date().getFullYear();

// The office name was a literal here while the public footer had already moved
// to config/office.php — the same fact stated two ways, one of which would go
// stale silently the day this codebase served a different regional office. The
// version number was the same trap one line down; it now comes from
// config('app.version') for exactly the same reason.
const page = usePage();
const officeName = computed(() => page.props.office?.name ?? 'Civil Service Commission');
const version = computed(() => page.props.appVersion);

// The public footer has carried these three since launch; signed-in users could
// reach none of them. The accessibility statement is the one that matters most
// — it is a compliance page that existed and was unreachable from inside the app.
const legalLinks = [
    { label: 'Privacy Policy', href: '/privacy-policy' },
    { label: 'Terms of Service', href: '/terms-of-service' },
    { label: 'Accessibility', href: '/accessibility' },
];
</script>

<template>
    <!--
        Bottom padding clears the mobile tab bar. It used to sit on <main>,
        which kept the page content clear but left the footer — the last thing
        in the flow — underneath the fixed bar. The offset belongs on whatever
        actually ends the document.
    -->
    <footer class="shrink-0 border-t border-csc-line px-4 pt-3 pb-24 sm:px-6 md:pb-3">
        <div
            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 text-xs text-csc-ink-subtle"
        >
            <span>&copy; {{ year }} {{ officeName }}. All rights reserved</span>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <a
                    v-for="link in legalLinks"
                    :key="link.href"
                    :href="link.href"
                    class="rounded transition-colors hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                >
                    {{ link.label }}
                </a>
                <span v-if="version" class="hidden sm:inline">v{{ version }}</span>
            </div>
        </div>
    </footer>
</template>
