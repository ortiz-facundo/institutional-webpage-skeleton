import { defineConfig, loadEnv } from 'vite';
import symfonyPlugin from 'vite-plugin-symfony';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const viteDevServerHostname = env.VITE_DEV_SERVER_HOSTNAME || undefined;

    return {
        plugins: [
            symfonyPlugin({
                buildDirectory: 'public/build',
                publicDirectory: 'public',
                ...(viteDevServerHostname && { viteDevServerHostname }),
                viteDevServer: {
                    host: '0.0.0.0',
                    port: 5173,
                },
                stimulus: false,
                refresh: ['templates/**/*.twig'],
            }),
        ],
        build: {
            rollupOptions: {
                input: {
                    app: './assets/app.js',
                },
            },
        },
        server: {
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
            cors: true,
        },
    };
});
