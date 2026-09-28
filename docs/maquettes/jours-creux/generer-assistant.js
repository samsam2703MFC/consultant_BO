/* Capture les trois écrans de l'assistant en PNG (largeur cockpit 1300 px).
   node docs/maquettes/jours-creux/generer-assistant.js — serveur statique sur 8099 depuis la racine. */
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const path = require('path');
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  for (const [etape, nom] of [['2', 'assistant-1'], ['4', 'assistant-2'], ['5', 'assistant-3']]) {
    const p = await b.newPage({ viewport: { width: 1348, height: 900 }, deviceScaleFactor: 1.5 });
    p.on('pageerror', e => console.log('ERREUR', etape, e.message));
    await p.goto('http://127.0.0.1:8099/docs/maquettes/jours-creux/assistant.html?etape=' + etape, { waitUntil: 'load' });
    await p.waitForSelector('#ecran > *', { timeout: 20000 }); await p.waitForTimeout(600);
    await p.screenshot({ path: path.join(__dirname, nom + '.png'), fullPage: true });
    await p.close(); console.log('✓', nom);
  }
  await b.close();
})();
