import { defineConfig, loadEnv } from 'vite';
import symfonyPlugin from 'vite-plugin-symfony';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const port = Number(env.VITE_PORT || 5175);
  return {
    plugins: [symfonyPlugin({
      buildDirectory: 'public/build',
      publicDirectory: 'public',
      viteDevServerHostname: env.VITE_DEV_SERVER_HOSTNAME || 'localhost',
      viteDevServer: { host: '127.0.0.1', port },
      stimulus: false,
      refresh: ['templates/**/*.twig'],
    })],
    build: { rollupOptions: { input: { app: './assets/app.js' } } },
    server: { host: '127.0.0.1', port, strictPort: true },
  };
});
