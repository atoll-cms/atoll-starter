import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';

export default defineConfig({
  plugins: [svelte()],
  base: '/admin/',
  build: {
    outDir: '../admin',
    emptyOutDir: true,
    assetsDir: 'assets'
  },
  server: {
    proxy: {
      '/admin/api': 'http://localhost:8080'
    }
  }
});
