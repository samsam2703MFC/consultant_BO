/* Captures de la maquette « Relances SMS des demandes au bureau ».
   Serveur statique à la racine du dépôt (la maquette lit les styles du cockpit dans shared/public/assets) :
     php -S 127.0.0.1:8099 -t .
   puis : node consultant/docs/maquettes/helpdesk-relances/capturer.cjs */
const { chromium } = require('playwright');
const URL = 'http://127.0.0.1:8099/consultant/docs/maquettes/helpdesk-relances/index.html';
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1560, height: 1000 }, deviceScaleFactor: 1.25 });
  const err = []; p.on('pageerror', e => err.push(e.message)); p.on('requestfailed', r => err.push('absent : ' + r.url()));
  await p.goto(URL, { waitUntil: 'networkidle' });
  await p.evaluate('document.fonts.ready');
  const bas = async sel => p.evaluate(s => { const r = document.querySelector(s).getBoundingClientRect(); return r.bottom + window.scrollY; }, sel);
  const haut = async sel => p.evaluate(s => { const r = document.querySelector(s).getBoundingClientRect(); return r.top + window.scrollY; }, sel);
  const larg = await p.evaluate(() => document.documentElement.scrollWidth);
  // A : l'en-tête et ses questions, puis les réglages ; B et C : leur section.
  await p.screenshot({ path: __dirname + '/a-reglages.png', fullPage: true, clip: { x: 0, y: 0, width: larg, height: (await bas('#a')) + 16 } });
  for (const [nom, sel] of [['b-liste-fiche', '#b'], ['c-sms-journal', '#c']]) {
    const y = await haut(sel);
    await p.screenshot({ path: __dirname + '/' + nom + '.png', fullPage: true, clip: { x: 0, y: y - 16, width: larg, height: (await bas(sel)) - y + 32 } });
  }
  console.log(larg > 1560 ? 'DÉBORDE (' + larg + ' px)' : 'largeur ok', err.length ? '\n' + err.join('\n') : '· sans erreur');
  await b.close();
})();
