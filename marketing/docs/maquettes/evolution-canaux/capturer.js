/* Captures de la maquette « Évolution magasin — ventes : Tout | Comptoir | B2B ».
 *   npx http-server . -p 8099 -c-1        (depuis pilotage-reseau/)
 *   node docs/maquettes/evolution-canaux/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const D = __dirname;
const BASE = process.env.MQ_BASE || 'http://127.0.0.1:8099/docs/maquettes/evolution-canaux/';
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const p = await b.newPage({ viewport: { width: 1440, height: 950 }, deviceScaleFactor: 2 });
  const err = []; p.on('pageerror', e => err.push(e.message));
  for (const m of ['tout', 'comptoir', 'b2b', 'b2b-clients']) {
    await p.goto(BASE + m + '.html', { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    const h = await p.evaluate(() => document.documentElement.scrollHeight);
    const deb = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    // Le haut de l'onglet seul (bascule + premier magasin) : la vue d'ensemble se lit d'un coup d'œil.
    const r = await p.evaluate(() => { const a = document.querySelector('#mq-contenu .rr'), c = document.querySelectorAll('#mq-contenu .ev-mag')[0]; const ra = a.getBoundingClientRect(), rc = c.getBoundingClientRect(); return { x: ra.left - 12, y: ra.top + scrollY - 18, w: ra.width + 24, h: rc.bottom - ra.top + 30 }; });
    await p.screenshot({ path: path.join(D, m + '-haut.png'), clip: { x: r.x, y: r.y, width: r.w, height: r.h } });
    console.log(m, 'hauteur', h, deb ? 'DÉBORDE' : '');
  }
  console.log('erreurs', err.length ? err : 'aucune');
  await b.close();
})();
