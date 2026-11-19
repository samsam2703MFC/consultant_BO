/* Maquette « Repères sur les photos » de la tablette de contrôle : la photo en grand, l'éditeur (comme dans Contrôle des
 * tâches : glisser pour tracer un cadre numéroté, sa gravité, sa remarque), la photo annotée, et la même dans le rapport PDF.
 * Photographiée sur l'application en ligne, en lecture seule (tout appel autre que GET est intercepté), avec visites.js et
 * visites-nl.js de ce dépôt ; le bloc du rapport sort du PHP de la branche (outils.php).
 * La photo d'essai de l'intérieur de Sombreffe est remplacée par une photo de comptoir de Gosselies (illustration) et datée
 * du jour (une photo du jour ne se montre que prise aujourd'hui) ; les
 * repères sont ceux de reperes.json, tracés au pointeur comme au doigt.
 * Régénérer : node docs/maquettes/reperes-photos/generer.js   (php et pdftoppm requis) */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path'), os = require('os');
const { execFileSync } = require('child_process');
const OUT = __dirname + '/', JS = path.join(__dirname, '../../../public/assets/js/');
const BASE = 'http://185.180.206.46/consulant_bo/';
const VISITE = '4';                                  // Sombreffe (shop 5), visite régulière en cours du 26/10/2026, Loic V.
const ESSAI = '20261027-083722-8d20d04b0e.jpg';      // la photo d'essai de l'intérieur (id 8), remplacée
const R = JSON.parse(fs.readFileSync(OUT + 'reperes.json', 'utf8'));
const PHOTO = fs.readFileSync(path.join(OUT, R.photo));
// Le serveur simulé : les repères enregistrés (PUT intercepté) reviennent dans les relectures de /visites/app, comme en vrai.
const ENREG = {};
async function ouvrir(b, vp, lng) {
  const c = await b.newContext({ viewport: vp, deviceScaleFactor: 2 });
  await c.addInitScript(([l, v]) => { try { localStorage.setItem('vi.moi', 'u8'); localStorage.setItem('vi.lng', l); localStorage.setItem('vi.lng.' + v, l); } catch (e) {} }, [lng, VISITE]);
  await c.route('**/*', async r => {
    const u = r.request().url();
    if (r.request().method() !== 'GET') {
      const k = /\/visites\/photos\/([\w-]+)\/reperes$/.exec(u); if (k) { ENREG[decodeURIComponent(k[1])] = (r.request().postDataJSON() || {}).reperes || []; }
      return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' });
    }
    const m = /\/assets\/js\/(visites|visites-nl)\.js(\?|$)/.exec(u);
    if (m) { return r.fulfill({ status: 200, contentType: 'application/javascript', body: fs.readFileSync(JS + m[1] + '.js') }); }
    if (u.includes(ESSAI)) { return r.fulfill({ status: 200, contentType: 'image/jpeg', body: PHOTO }); }
    if (/\/api\/cockpit\/visites\/app\?/.test(u)) {
      const rep = await r.fetch(); const d = await rep.json();
      // La photo du jour ne compte que prise aujourd'hui : la photo d'essai de l'intérieur (27/10) est datée de ce matin.
      const auj = new Date(); const iso = auj.getFullYear() + '-' + String(auj.getMonth() + 1).padStart(2, '0') + '-' + String(auj.getDate()).padStart(2, '0');
      (d.photos || []).forEach(x => { if (String(x.id) === '8') { x.prise_a = iso + ' 10:12:00'; } const e = ENREG[x.client_id] || ENREG[String(x.id)]; if (e) { x.reperes = e; } });
      d.seuils = Object.assign({}, d.seuils, { etapesBloquantes: { photos_ext: false, photos_int: false, 'mod:*': false, controles: false, checklist: false, pistes: false, constat: false, meteo: false, evaluation: false }, etapeAuto: false });
      return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(d) });
    }
    return r.continue();
  });
  const p = await c.newPage();
  p.on('pageerror', e => console.log('pageerror:', e.message));
  await p.goto(BASE + 'controle/?id=u8#controle/' + VISITE, { waitUntil: 'load' });
  await p.waitForFunction(() => document.querySelector('[data-a="etape"]'), null, { timeout: 180000 });
  await p.waitForTimeout(1200);
  // L'étape « L'intérieur en photo » : le temps Intérieur, puis son onglet.
  const etape = async re => { const i = await p.evaluate(s => { const x = [...document.querySelectorAll('[data-a="etape"]')].find(e => new RegExp(s, 'i').test(e.textContent)); return x ? x.dataset.v : null; }, re); if (i != null) { await p.click(`[data-a="etape"][data-v="${i}"]`); await p.waitForTimeout(600); } return i; };
  await etape('2\\s*·\\s*(Int|Binnen)'); await etape('^\\s*\\d*\\s*(Photo|Foto)\\b');
  return p;
}
async function tracer(p) {
  for (const r of R.reperes) {
    // Un rendu passé pendant le toucher l'emporte (la tablette relit ses données en fond) : on vérifie la gravité choisie.
    for (let k = 0; k < 4 && !(await p.$(`.ed-niv.on[data-v="${r.niveau}"]`)); k++) { await p.click(`[data-a="ed-niv"][data-v="${r.niveau}"]`); await p.waitForTimeout(200); }
    const b = await p.$eval('[data-edsurf]', e => { const q = e.getBoundingClientRect(); return { x: q.left, y: q.top, w: q.width, h: q.height }; });
    await p.mouse.move(b.x + r.x * b.w, b.y + r.y * b.h); await p.mouse.down();
    await p.mouse.move(b.x + (r.x + r.l / 2) * b.w, b.y + (r.y + r.h / 2) * b.h, { steps: 4 });
    await p.mouse.move(b.x + (r.x + r.l) * b.w, b.y + (r.y + r.h) * b.h, { steps: 4 }); await p.mouse.up(); await p.waitForTimeout(250);
    const n = await p.$$eval('[data-f^="ed|"]', l => l.length);
    await p.fill(`[data-f="ed|${n - 1}"]`, r.txt); await p.waitForTimeout(100);
  }
}
(async () => {
  const b = await chromium.launch();
  const tab = { width: 1180, height: 820 };
  let p = await ouvrir(b, tab, 'fr');
  await p.screenshot({ path: OUT + 'a-etape-photo.png' });
  await p.click('.ph[data-a="voir"]'); await p.waitForTimeout(600);
  await p.screenshot({ path: OUT + 'b-photo-en-grand.png' });
  await p.click('[data-a="ed-ouvrir"]'); await p.waitForTimeout(600);
  await p.screenshot({ path: OUT + 'c-editeur-vide.png' });
  await tracer(p);
  await p.click('.ed-r[data-v="0"] .h b').catch(() => {}); await p.waitForTimeout(300);   // le titre de la ligne : son centre est un bouton de gravité
  await p.screenshot({ path: OUT + 'd-editeur-trois-reperes.png' });
  await p.click('[data-a="ed-enreg"]'); await p.waitForTimeout(800);
  await p.screenshot({ path: OUT + 'e-photo-annotee.png' });
  await p.click('[data-a="fermer-voir"]'); await p.waitForTimeout(500);
  await p.waitForFunction(() => !document.querySelector('.toast'), null, { timeout: 15000 }).catch(() => {});
  await p.screenshot({ path: OUT + 'f-vignette-pastille.png' });
  await p.context().close();
  // La tablette à la verticale, en néerlandais (Halle et les magasins flamands).
  p = await ouvrir(b, { width: 820, height: 1180 }, 'nl');
  await p.click('.ph[data-a="voir"]'); await p.waitForTimeout(500);
  await p.click('[data-a="ed-ouvrir"]'); await p.waitForTimeout(500);
  await tracer(p); await p.waitForTimeout(300);
  await p.screenshot({ path: OUT + 'g-editeur-portrait-nl.png' });
  await p.context().close();
  // Le rapport PDF : l'aperçu en ligne de la visite, la photo annotée dans sa section Photos.
  const TMP = os.tmpdir() + '/mq-rep-' + process.pid + '-';
  fs.writeFileSync(TMP + 'essai.jpg', Buffer.from(await (await fetch(BASE + 'uploads/visites/5/' + ESSAI)).arrayBuffer()));
  for (const l of ['fr', 'nl']) {
    fs.writeFileSync(TMP + l + '.json', await (await fetch(BASE + 'api/cockpit/suivi/rapport/apercu?visite=' + VISITE + '&langue=' + l)).text());
    execFileSync('php', [OUT + 'outils.php', TMP + l + '.json', l, TMP + 'essai.jpg', TMP + l + '.html']);
    const q = await b.newPage(); await q.goto('file://' + TMP + l + '.html');
    await q.pdf({ path: OUT + 'h-rapport-' + l + '.pdf', format: 'A4', printBackground: true, preferCSSPageSize: true }); await q.close();
    execFileSync('pdftoppm', ['-png', '-r', '110', '-f', '1', '-l', '1', '-singlefile', OUT + 'h-rapport-' + l + '.pdf', OUT + 'h-rapport-' + l]);
  }
  for (const f of fs.readdirSync(os.tmpdir())) { if (f.startsWith('mq-rep-' + process.pid)) { fs.unlinkSync(os.tmpdir() + '/' + f); } }
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
