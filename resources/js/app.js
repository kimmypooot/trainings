import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import AppProgressBar from '@/Components/AppProgressBar.vue';
import '@/analytics';

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
        // A fragment so the bar stays mounted across whole-page swaps — it is
        // app chrome, not page chrome, and holds no world state of its own.
        createApp({ render: () => [h(AppProgressBar), h(App, props)] })
            .use(plugin)
            .mount(el);
    },
});