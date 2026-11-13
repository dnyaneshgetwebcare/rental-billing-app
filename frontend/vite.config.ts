/// <reference types="vitest" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';

// The build goes straight into ../public_html (the GoDaddy web root).
// emptyOutDir stays false so public_html/api, uploads and .htaccess survive;
// scripts/clean-dist.mjs removes the previous build's files instead.
export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['icons/icon-192.png', 'icons/icon-512.png'],
      manifest: {
        name: 'Rental Billing',
        short_name: 'Billing',
        description: 'Warehouse rental billing, GST and accounts',
        theme_color: '#206bc4',
        background_color: '#ffffff',
        display: 'standalone',
        start_url: '/',
        icons: [
          { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [/^\/api\//, /^\/uploads\//],
        globPatterns: ['**/*.{js,css,html,png,svg,ico,woff2}'],
        globIgnores: ['uploads/**', 'api/**'],
        maximumFileSizeToCacheInBytes: 4 * 1024 * 1024,
        runtimeCaching: [],
      },
    }),
  ],
  build: {
    outDir: '../public_html',
    emptyOutDir: false,
    chunkSizeWarningLimit: 1500,
  },
  server: {
    host: true,
    port: 5173,
    proxy: {
      '/api': { target: process.env.API_PROXY ?? 'http://localhost:8082', changeOrigin: false },
      '/uploads': { target: process.env.API_PROXY ?? 'http://localhost:8082', changeOrigin: false },
    },
  },
  test: {
    environment: 'node',
  },
});
