import { defineConfig } from 'vitest/config';
import { fileURLToPath, URL } from 'node:url';
import vue from '@vitejs/plugin-vue';

/**
 * A separate config from vite.config.js on purpose: that one is exported as
 * a function shaped around Laravel's asset pipeline (the plugin, the fonts,
 * the dev-tunnel HMR wiring) and none of that applies to running plain unit
 * tests under Node. The one thing worth sharing is the `@` alias, so an
 * import in a test file resolves exactly the way it does in the app.
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.js'],
    },
});
