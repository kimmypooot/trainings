import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import AppProgressBar from '@/Components/AppProgressBar.vue';
import AppAuthSplash from '@/Components/AppAuthSplash.vue';
import { playWelcome } from '@/authSplash';
import { warmSeal } from '@/brandSeal';
import '@/analytics';

// The seal in the middle of the splash's spinning rings is an <img>, and the
// first paint of the overlay is exactly when a cold fetch of it would show as
// a blank — or half-drawn — circle. Warming it at boot costs one cached
// request, and the decode is warmed with it so the splash gets something it
// can paint whole. See @/brandSeal.
warmSeal();

createInertiaApp({
    title: (title) => `${title} - CSC TIMS`,

    /*
     * No `progress` config: the built-in bar is grey and starts instantly,
     * which makes every fast local navigation flash. AppProgressBar mounts
     * beside the app instead — brand colour, a delay long enough that only
     * genuinely slow visits announce themselves, and a danger flash on
     * invalidated visits.
     */
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue')
        ),

    setup({ el, App, props, plugin }) {
        // A sign-in that arrives on a *fresh document* — the round trip out to
        // Google — boots a new JS context, so the splash the login page raised
        // is long gone and the sequence in @/authSplash never ran. The one-shot
        // server flag is how that hand-off is recovered: play the welcome beat
        // here so a Google sign-in ends the same way a password one does.
        //
        // Only ever on a document load. An ordinary Inertia sign-in never gets
        // here, and its splash is already mid-sequence.
        if (props.initialPage.props.flash?.just_logged_in === true) {
            playWelcome(props.initialPage.props.auth?.user?.first_name ?? null);
        }

        // A fragment so the bar stays mounted across whole-page swaps — it is
        // app chrome, not page chrome, and holds no world state of its own.
        // The auth splash rides along for the same reason, and more sharply:
        // its whole job is to still be on screen while the page underneath is
        // being replaced, which nothing mounted inside a page can do.
        createApp({ render: () => [h(AppProgressBar), h(AppAuthSplash), h(App, props)] })
            .use(plugin)
            .mount(el);
    },
});
