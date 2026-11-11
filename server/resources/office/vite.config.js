import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Ajoute le français au tableur (FortuneSheet ne le fournit pas) : la langue « fr » est construite à partir de l'anglais
const fortuneFr = {
  name: 'fortune-fr',
  transform(code, id) {
    if (id.includes('@fortune-sheet/core') && code.includes('var localeObj = {')) {
      return code.replace('var localeObj = {', 'var localeObj = { get fr() { return (window.__fsLocale ? window.__fsLocale(en) : en); },');
    }
  },
};

export default defineConfig({
  plugins: [react(), fortuneFr],
  base: './',
  define: { 'process.env.NODE_ENV': '"production"' },
  build: {
    outDir: 'dist',
    manifest: 'manifest.json',
    emptyOutDir: true,
    cssCodeSplit: false,
    chunkSizeWarningLimit: 8000,
    rollupOptions: {
      input: 'src/main.jsx',
      output: {
        // React à part : l'entrée (chargée avec ?v=) n'est jamais réimportée par les morceaux, une seule copie de React
        manualChunks: (id) => (/node_modules\/(react|react-dom|scheduler)\//.test(id) ? 'react' : undefined),
        entryFileNames: 'office-[hash].js', chunkFileNames: 'chunks/[name]-[hash].js', assetFileNames: (a) => (a.name && a.name.endsWith('.css') ? 'office.css' : 'assets/[name]-[hash][extname]') },
    },
  },
});
