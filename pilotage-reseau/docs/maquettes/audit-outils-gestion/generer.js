/* Maquette « Les outils de gestion » : l'étape d'audit de la tablette de contrôle et le bloc du courriel de rapport.
 * Maquette statique (maquette.html) : le CSS de la tablette est celui de la branche — la constante CSS de
 * public/assets/js/visites.js, injectée ici — et le design system est public/assets/ds/global.css. Rien n'est lu en
 * ligne, rien n'est écrit en base ; les chiffres « lus tout seuls » et les remarques sont des exemples.
 * Régénérer : node docs/maquettes/audit-outils-gestion/generer.js */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const OUT = __dirname + '/';
const src = fs.readFileSync(path.join(__dirname, '../../../public/assets/js/visites.js'), 'utf8');
const m = /const CSS = `([\s\S]*?)\n`;/.exec(src);
if (!m) { throw new Error('constante CSS introuvable dans visites.js'); }
const CSS = m[1];
const URL = 'file://' + OUT + 'maquette.html';

async function vue(b, vp, h) {
  const c = await b.newContext({ viewport: vp, deviceScaleFactor: 2 });
  const p = await c.newPage();
  p.on('pageerror', e => console.log('pageerror:', e.message));
  await p.goto(URL + '#' + h, { waitUntil: 'load' });
  await p.addStyleTag({ content: CSS });
  await p.evaluate(() => document.fonts.ready);
  await p.waitForTimeout(400);
  return p;
}

(async () => {
  const b = await chromium.launch();
  const tab = { width: 1180, height: 820 };
  // A : l'étape remplie (la tablette couchée, ce qu'on voit à l'écran) et l'étape entière.
  let p = await vue(b, tab, 'a');
  await p.screenshot({ path: OUT + 'a-tablette.png' });
  // L'étape entière : la barre du bas posée en bas de la page, et non au milieu (elle est fixée à l'écran).
  await p.addStyleTag({ content: '.vi.tab .tb-bar{position:absolute}.vi .og-som{position:static}' });
  await p.screenshot({ path: OUT + 'a-tablette-page.png', fullPage: true });
  await p.context().close();
  // B : le (i) ouvert à côté du titre : le rappel à lire avec le franchisé.
  p = await vue(b, tab, 'b');
  await p.screenshot({ path: OUT + 'b-tablette-info.png' });
  await p.context().close();
  // C : le courriel du rapport, avec le bloc des outils et le rappel.
  p = await vue(b, { width: 900, height: 1000 }, 'c');
  await p.screenshot({ path: OUT + 'c-courriel-rapport.png', fullPage: true });
  await p.context().close();
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
