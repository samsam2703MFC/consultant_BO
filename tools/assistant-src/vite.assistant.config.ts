import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

/**
 * Compilation de l'assistant seul, pour son hébergement dans le cockpit CEO.
 *
 * • `base: './'` — la page vit sous /consulant_bo/assistant/, les assets se
 *   résolvent en relatif, quel que soit le sous-répertoire d'installation.
 * • `VITE_API_BASE: 'auto'` — la racine d'API est le répertoire parent de la
 *   page, résolu à l'exécution (voir module.ts) : les appels partent vers
 *   /<install>/api/v1/marketing/…, l'API du cockpit.
 */
export default defineConfig({
  plugins: [react()],
  base: './',
  define: {
    'import.meta.env.VITE_API_BASE': JSON.stringify('auto'),
  },
  build: {
    outDir: 'dist-assistant',
    rollupOptions: {
      input: 'assistant.html',
    },
  },
})
