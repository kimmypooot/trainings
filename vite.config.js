import { defineConfig, loadEnv } from 'vite';
import { fileURLToPath, URL } from 'node:url';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    // '' as the prefix loads the whole .env, not just VITE_*, because this is
    // build-time config rather than something shipped to the client.
    const env = loadEnv(mode, process.cwd(), '');

    // Set VITE_DEV_ORIGIN to the https URL of the *forwarded 5173 port* when
    // running the dev server behind VS Code port forwarding, e.g.
    // https://abc123-5173.asse.devtunnels.ms. laravel-vite-plugin writes this
    // into public/hot verbatim, so @vite points the remote browser at the tunnel
    // instead of at its own localhost:5173 (which is what breaks otherwise: the
    // page renders with no CSS and no JS). Leave it unset for normal local dev.
    const devOrigin = env.VITE_DEV_ORIGIN;
    const devOriginHost = devOrigin ? new URL(devOrigin).hostname : null;

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
                fonts: [
                    bunny('Poppins', {
                        // 400 body, 500 nav/UI, 600 headings, 700 hero title.
                        // No lighter weights on purpose: thin Poppins text is
                        // hard to read and we never use it.
                        weights: [400, 500, 600, 700],
                    }),
                ],
            }),
            tailwindcss(),
            vue({
                template: {
                    transformAssetUrls: {
                        // Leave root-relative URLs alone so `/images/*.png` resolves
                        // from public/ at runtime instead of being bundled.
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
        ],
        resolve: {
            alias: {
                '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            },
        },
        server: {
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
            origin: devOrigin,
            // Over a tunnel the HMR socket has to dial the tunnel hostname on
            // 443/wss, not the viewer's own localhost:5173. Without the origin
            // set we keep the plain localhost socket that local dev wants.
            hmr: devOriginHost
                ? { host: devOriginHost, protocol: 'wss', clientPort: 443 }
                : { host: 'localhost' },
        },
    };
});
