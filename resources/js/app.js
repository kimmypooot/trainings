import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

createInertiaApp({
    title: (title) => `${title} - CSC TIMS`,

    /*
     * The default bar is grey and starts instantly, which makes every fast
     * local navigation flash. Brand colour, and a delay long enough that only
     * genuinely slow visits announce themselves.
     */
    progress: {
        color: '#2a338f',
        delay: 250,
    },

    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue')
        ),

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});