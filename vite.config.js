import { defineConfig } from 'vite';
import symfonyPlugin from 'vite-plugin-symfony';

export default defineConfig({
    plugins: [
        symfonyPlugin({
            buildDirectory: 'public/build',
            publicDirectory: 'public',
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
    },
});
