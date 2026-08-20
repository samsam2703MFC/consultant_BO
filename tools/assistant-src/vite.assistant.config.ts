import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

/**
 * Compilation de l'assistant seul, pour son hébergement dans le cockpit CEO.
 *
 * • `base: './'` — la page vit sous /consulant_bo/assistant/, les assets se
 *   résolvent en relatif, quel que soit le sous-répertoire d'installation.
 * • `VITE_API_BASE: '..'` — les appels partent vers ../api/v1/marketing/…,
 *   c'est-à-dire l'API du cockpit (réécriture Apache vers api/marketing/).
 */
export default defineConfig({
  plugins: [react()],
  base: './',
  define: {
    'import.meta.env.VITE_API_BASE': JSON.stringify('..'),
  },
  build: {
    outDir: 'dist-assistant',
    rollupOptions: {
      input: 'assistant.html',
    },
  },
})
