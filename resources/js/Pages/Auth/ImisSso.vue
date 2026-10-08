<script setup>
import { onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { beginImisSignIn, dismiss, welcome } from '@/authSplash';

/*
 * Where the IMIS portal sends staff. The splash is raised from the first
 * paint and the one-time token is posted back while it stays up: the server
 * then asks IMIS who it was issued for and either signs the person in (the
 * welcome beat plays over the dashboard) or sends them to the login page with
 * the reason. The splash lives beside the app (see app.js), so it survives
 * the page swap — the same sequence the password sign-in uses.
 */
const props = defineProps({
    token: { type: String, required: true },
});

// Raised during setup, so it paints in the same frame as the server-drawn card.
beginImisSignIn();

onMounted(() => {

    router.post('/sso', { sso_token: props.token }, {
        replace: true,
        onSuccess: (page) => {
            // A refused sign-in redirects to the login page with its reason.
            if (page.component === 'Auth/Login') {
                dismiss();
                return;
            }

            welcome(page.props.auth?.user?.first_name ?? page.props.auth?.user?.name?.split(' ')[0] ?? null);
        },
        onError: dismiss,
    });
});
</script>

<template>
    <Head title="Signing you in" />
</template>
