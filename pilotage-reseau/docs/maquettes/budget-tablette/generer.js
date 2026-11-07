/* Maquette « Le mois, le trimestre, l'année » de la tablette de contrôle : trois jauges du CA face au budget, en tête du
 * bloc « Le CA face au budget » de l'étape KPI (temps 4 · Analyse). Photographiée sur l'application en ligne, en lecture
 * seule (tout appel autre que GET est intercepté), avec visites.js et visites-nl.js de ce dépôt ; chiffres réels de Halle
 * (/visites/budget/4, arrêtés à la veille).
 * Régénérer : node docs/maquettes/budget-tablette/generer.js */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const OUT = __dirname + '/', JS = path.join(__dirname, '../../../public/assets/js/');
const BASE = 'http://185.180.206.46/consulant_bo/';
const VISITE = '6';                 // Halle (shop 4), visite régulière en cours, Loic V.
async function ouvrir(b, vp, lng) {
  const c = await b.newContext({ viewport: vp, deviceScaleFactor: 2, timezoneId: 'Europe/Brussels' });
  await c.addInitScript(([l, v]) => { try { localStorage.setItem('vi.moi', 'u8'); localStorage.setItem('vi.lng', l); localStorage.setItem('vi.lng.' + v, l); } catch (e) {} }, [lng, VISITE]);
  await c.route('**/*', async r => {
    const u = r.request().url();
    if (r.request().method() !== 'GET') { return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); }
    const m = /\/assets\/js\/(visites|visites-nl)\.js(\?|$)/.exec(u);
    if (m) { return r.fulfill({ status: 200, contentType: 'application/javascript', body: fs.readFileSync(JS + m[1] + '.js') }); }
    if (/\/api\/cockpit\/visites\/app\?/.test(u)) {
      const rep = await r.fetch(); const d = await rep.json();
      // Pour aller droit à l'analyse : aucune étape bloquante le temps de la capture (Réglages › Contrôle guidé le permet).
      d.seuils = Object.assign({}, d.seuils, { etapesBloquantes: { photos_ext: false, photos_int: false, 'mod:*': false, controles: false, checklist: false, pistes: false, constat: false, meteo: false, evaluation: false, equipe: false }, etapeAuto: false });
      return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(d) });
    }
    return r.continue();
  });
  const p = await c.newPage(); p.on('pageerror', e => console.log('pageerror:', e.message));
  await p.goto(BASE + 'controle/?id=u8#controle/' + VISITE, { waitUntil: 'load' });
  await p.waitForFunction(() => document.querySelector('[data-a="etape"]'), null, { timeout: 180000 });
  await p.waitForTimeout(1200);
  const etape = async re => { const i = await p.evaluate(s => { const x = [...document.querySelectorAll('[data-a="etape"]')].find(e => new RegExp(s, 'i').test(e.textContent)); return x ? x.dataset.v : null; }, re); if (i != null) { await p.click(`[data-a="etape"][data-v="${i}"]`); await p.waitForTimeout(700); } return i; };
  await etape('4\\s*·\\s*Analyse'); await etape('^\\s*\\d+\\s*KPI');
  // Le serveur relit le panel quand une lecture a échoué (« relu dans quelques minutes ») : on attend les trois jauges remplies.
  for (let k = 0; k < 8; k++) {
    await p.waitForFunction(() => document.querySelector('.vb3 .vb3-r'), null, { timeout: 240000 });
    if ((await p.locator('.vb3 .vb3-b i').count()) >= 3) { break; }
    await p.waitForTimeout(20000); await p.click('[data-a="sante-relire"]').catch(() => {}); await p.waitForTimeout(3000);
  }
  await p.waitForTimeout(1500);
  return p;
}
(async () => {
  const b = await chromium.launch();
  let p = await ouvrir(b, { width: 1180, height: 820 }, 'fr');
  const el = await p.$('.vb3'); await el.scrollIntoViewIfNeeded();
  await el.screenshot({ path: OUT + 'a-trois-jauges.png' });
  console.log(await p.$eval('.vb3', e => e.innerText.replace(/\s+/g, ' ')));
  // À sa place : le bloc entier, les trois jauges puis les onglets et le mini P&L.
  await p.evaluate(() => { const c = document.querySelector('.vb3'); const cap = c.previousElementSibling; (cap || c).scrollIntoView({ block: 'start' }); window.scrollBy(0, -12); });
  await p.waitForTimeout(400);
  await p.screenshot({ path: OUT + 'b-a-sa-place.png' });
  // Un toucher sur l'année ouvre son onglet.
  await p.click('.vb3-r[data-v="annee"]'); await p.waitForTimeout(600);
  await p.evaluate(() => { const c = document.querySelector('.vb3'); (c.previousElementSibling || c).scrollIntoView({ block: 'start' }); window.scrollBy(0, -12); });
  await p.waitForTimeout(300);
  await p.screenshot({ path: OUT + 'c-annee-ouverte.png' });
  await p.context().close();
  p = await ouvrir(b, { width: 820, height: 1180 }, 'nl');
  await (await p.$('.vb3')).screenshot({ path: OUT + 'd-portrait-nl.png' });
  await p.context().close();
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
