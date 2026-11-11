/* Maquette « Évaluation rapide » : l'étape de fin de visite sur la tablette de contrôle, le radar des six axes, le même
 * radar dans le rapport (PDF) et sur l'écran du bureau. Photographiée sur l'application en ligne, en lecture seule (tout
 * appel autre que GET est intercepté), avec les fichiers de ce dépôt à la place des fichiers servis ; le bloc du rapport
 * sort du PHP de la branche (outils.php). Les notes sont celles de notes.json, données pour la capture.
 * Régénérer : node docs/maquettes/evaluation-rapide/generer.js   (php et pdftoppm requis) */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const OUT = __dirname + '/', JS = path.join(__dirname, '../../../public/assets/js/');
const BASE = 'http://185.180.206.46/consulant_bo/';
const VISITE = '6';                 // Halle (shop 4), visite régulière en cours du 26/10/2026, Loic V.
// Les notes de la maquette (exemple, données par nous pour la capture) et une évaluation précédente fictive posée sur la
// visite terminée du 24/10 (n° 2), pour montrer la comparaison en pointillé. Rien de cela n'existe en base.
const N = JSON.parse(fs.readFileSync(OUT + 'notes.json', 'utf8'));
const NOTES = Object.fromEntries(Object.entries(N.notes).map(([k, x]) => [k, [x.note, x.com]]));
const PREC = N.precedent.notes;
const { execFileSync } = require('child_process');
const TMP = require('os').tmpdir() + '/mq-eval-' + process.pid + '-';
async function ouvrir(b, vp, lng, maj) {
  const c = await b.newContext({ viewport: vp, deviceScaleFactor: 2 });
  await c.addInitScript(([l, v]) => { try { localStorage.setItem('vi.moi', 'u8'); localStorage.setItem('vi.lng', l); localStorage.setItem('vi.lng.' + v, l); } catch (e) {} }, [lng, VISITE]);
  await c.route('**/*', async r => {
    const u = r.request().url();
    if (r.request().method() !== 'GET') { return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); }
    const m = /\/assets\/js\/(visites|visites-nl)\.js(\?|$)/.exec(u);
    if (m) { return r.fulfill({ status: 200, contentType: 'application/javascript', body: fs.readFileSync(JS + m[1] + '.js') }); }
    if (/\/api\/cockpit\/visites\/app\?/.test(u)) {
      const rep = await r.fetch(); const d = await rep.json();
      // Pour aller droit à la fin : aucune étape bloquante (Réglages › Contrôle guidé le permet), et l'évaluation fictive de la visite d'avant.
      d.seuils = Object.assign({}, d.seuils, { etapesBloquantes: { photos_ext: false, photos_int: false, 'mod:*': false, controles: false, checklist: false, pistes: false, constat: false, meteo: false, evaluation: false }, etapeAuto: false });
      (d.visites || []).forEach(v => { if (String(v.id) === '2') { v.evaluation = { notes: Object.fromEntries(Object.entries(PREC).map(([k, n]) => [k, { note: n, com: '' }])) }; } });
      if (maj) { maj(d); }
      return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(d) });
    }
    return r.continue();
  });
  const p = await c.newPage();
  p.on('pageerror', e => console.log('pageerror:', e.message));
  await p.goto(BASE + 'controle/?id=u8#controle/' + VISITE, { waitUntil: 'load' });
  await p.waitForFunction(() => document.querySelector('[data-a="etape"]'), null, { timeout: 180000 });
  await p.waitForTimeout(1500);
  // L'étape « Évaluation » : par la barre du bas (le temps Météo et fin), puis son onglet en tête.
  const aller = async () => {
    const i = await p.evaluate(() => { const bs = [...document.querySelectorAll('[data-a="etape"]')]; const x = bs.find(e => /valuati/i.test(e.textContent)); return x ? x.dataset.v : null; });
    if (i != null) { await p.click(`[data-a="etape"][data-v="${i}"]`); await p.waitForTimeout(700); return true; }
    return false;
  };
  if (!(await aller())) {
    const t = await p.evaluate(() => { const bs = [...document.querySelectorAll('[data-a="etape"]')]; const x = bs.find(e => /m[ée]t[ée]o et fin|weer/i.test(e.textContent)); return x ? x.dataset.v : null; });
    if (t != null) { await p.click(`[data-a="etape"][data-v="${t}"]`); await p.waitForTimeout(700); }
    await aller();
  }
  return p;
}
async function noter(p, notes) {
  for (const [k, [n, com]] of Object.entries(notes)) {
    if (n) { await p.click(`[data-v="ev_${k}|${n}"]`); await p.waitForTimeout(150); }
    if (com) { await p.fill(`[data-f="form|evc_${k}"]`, com); }
  }
  await p.evaluate(() => window.scrollTo(0, 0)); await p.waitForTimeout(400);
}
(async () => {
  const b = await chromium.launch();
  const tab = { width: 1180, height: 820 };
  // A : l'étape à son ouverture, rien de noté.
  let p = await ouvrir(b, tab, 'fr');
  console.log('titre :', await p.evaluate(() => (document.querySelector('.tb-step') || document.body).innerText.slice(0, 120).replace(/\s+/g, ' ')));
  await p.screenshot({ path: OUT + 'a-tablette-vide.png' });
  // B : les six notes données, des commentaires, la visite précédente en pointillé.
  await noter(p, NOTES);
  await p.screenshot({ path: OUT + 'b-tablette-notee.png' });
  await p.screenshot({ path: OUT + 'b-tablette-notee-page.png', fullPage: true });
  await p.context().close();
  // C : la même en néerlandais (Halle ouvre la tablette dans sa langue).
  p = await ouvrir(b, tab, 'nl');
  await noter(p, NOTES);
  await p.screenshot({ path: OUT + 'c-tablette-nl.png' });
  await p.context().close();
  // D : à la verticale (tablette en portrait) : le radar au-dessus des axes.
  p = await ouvrir(b, { width: 820, height: 1180 }, 'fr');
  await noter(p, NOTES);
  await p.screenshot({ path: OUT + 'd-tablette-portrait.png' });
  await p.context().close();
  // E : le rapport, tel que le franchisé le reçoit en PDF (l'aperçu en ligne de la visite, le bloc de la branche inséré).
  for (const l of ['fr', 'nl']) {
    const r = await fetch(BASE + 'api/cockpit/suivi/rapport/apercu?visite=' + VISITE + '&langue=' + l);
    fs.writeFileSync(TMP + l + '.json', await r.text());
    execFileSync('php', [OUT + 'outils.php', 'rapport', TMP + l + '.json', l, TMP + l + '.html']);
    const q = await b.newPage(); await q.goto('file://' + TMP + l + '.html');
    await q.pdf({ path: OUT + 'e-rapport-' + l + '.pdf', format: 'A4', printBackground: true, preferCSSPageSize: true }); await q.close();
    execFileSync('pdftoppm', ['-png', '-r', '110', '-f', '1', '-l', '1', '-singlefile', OUT + 'e-rapport-' + l + '.pdf', OUT + 'e-rapport-' + l]);
  }
  // F : l'écran du bureau, Visites › le rapport à adapter de Halle : la carte de l'évaluation.
  const EV = JSON.parse(execFileSync('php', [OUT + 'outils.php', 'vue']).toString());
  const c = await b.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
  const A = path.join(__dirname, '../../../public/assets/');
  await c.route('**/*', async r => {
    const u = r.request().url();
    if (r.request().method() !== 'GET') { return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); }
    const m = /\/assets\/(js\/suivi-tpl\.js|js\/suivi-vals\.js|css\/app\.css)(\?|$)/.exec(u);
    if (m) { return r.fulfill({ status: 200, contentType: m[1].endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(A + m[1]) }); }
    if (/\/api\/cockpit\/suivi\/rapport\?visite=/.test(u)) { const rep = await r.fetch(); const d = await rep.json(); d.evaluation = EV; return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(d) }); }
    return r.continue();
  });
  p = await c.newPage();
  p.on('pageerror', e => console.log('pageerror:', e.message));
  await p.goto(BASE + '#/franchises', { waitUntil: 'load' });
  await p.waitForFunction(() => [...document.querySelectorAll('button')].some(b => /Halle\s+rapport à adapter/i.test(b.innerText.replace(/\s+/g, ' '))), null, { timeout: 120000 });
  await p.evaluate(() => [...document.querySelectorAll('button')].find(b => /Halle\s+rapport à adapter/i.test(b.innerText.replace(/\s+/g, ' '))).click());
  await p.waitForFunction(() => [...document.querySelectorAll('.mko-lab')].some(e => /évaluation rapide/i.test(e.textContent)), null, { timeout: 60000 });
  await p.waitForTimeout(1200);
  const carte = (await p.evaluateHandle(() => [...document.querySelectorAll('.mko-carte')].find(e => { const l = e.querySelector('.mko-lab'); return l && /évaluation rapide/i.test(l.textContent); }))).asElement();
  await carte.scrollIntoViewIfNeeded(); await carte.screenshot({ path: OUT + 'f-bureau-carte.png' });
  await c.close();
  for (const f of fs.readdirSync(require('os').tmpdir())) { if (f.startsWith('mq-eval-' + process.pid)) { fs.unlinkSync(require('os').tmpdir() + '/' + f); } }
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
