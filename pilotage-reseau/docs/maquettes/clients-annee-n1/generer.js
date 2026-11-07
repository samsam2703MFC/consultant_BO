/* Maquettes « Clients par mois, 2026 face à 2025 » — Cockpit › Résultat › Année.
 *
 *   node docs/maquettes/clients-annee-n1/generer.js
 *
 * 1. donnees.js depuis reel-clients-2026.json (relu le 24/10/2026, lecture seule :
 *    GET /ventes/semaines?shop=…&vue=annee, date 2026-10-24 puis 2025-12-31) ;
 * 2. captures a.jpg, a-gosselies.jpg, b.jpg et planche.jpg (serveur statique
 *    intégré, racine du dépôt, pour que /public/assets/ds/global.css se charge) ;
 * 3. avec --autonome <fichier> : une page unique, fontes et styles embarqués,
 *    les deux maquettes derrière un sélecteur A / B (lecture hors du dépôt). C'est
 *    un fragment — titre, styles, puis le contenu, sans <html> ni <body> : la page
 *    publiée reçoit son squelette à la publication.
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const { chromium } = require('playwright');
const ICI = __dirname + '/', RACINE = path.resolve(__dirname, '../../..');
const D = require('./reel-clients-2026.json');

fs.writeFileSync(ICI + 'donnees.js', '/* généré par generer.js depuis reel-clients-2026.json */\nwindow.CN_DONNEES = ' + JSON.stringify(D) + ';\n');

const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript', '.json': 'application/json', '.otf': 'font/otf', '.ttf': 'font/ttf', '.png': 'image/png', '.jpg': 'image/jpeg' };
function serveur() {
  return new Promise(ok => {
    const s = http.createServer((q, r) => {
      const f = path.join(RACINE, decodeURIComponent(q.url.split('?')[0]));
      if (!f.startsWith(RACINE) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { r.writeHead(404); r.end(); return; }
      r.writeHead(200, { 'Content-Type': TYPES[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(r);
    }).listen(0, '127.0.0.1', () => ok(s));
  });
}

// La page autonome : global.css et ses fontes (celles que l'écran emploie) en data:, cn1.css, données et moteur en ligne.
function autonome(sortie) {
  const ds = RACINE + '/public/assets/ds/';
  const garder = ['Gotham_Regular_New.otf', 'Gotham_Medium.otf', 'Gotham_Regular.otf', 'Gotham_Black.otf', 'GC_Vank.ttf'];
  let g = fs.readFileSync(ds + 'global.css', 'utf8');
  g = g.replace(/@font-face\s*{[^}]*}/g, bloc => {
    const m = /url\('fonts\/([^']+)'\)/.exec(bloc);
    if (!m || !garder.includes(m[1]) || /font-style:\s*italic/.test(bloc)) { return ''; }
    const typ = m[1].endsWith('.ttf') ? 'font/ttf' : 'font/otf';
    return bloc.replace(/url\('fonts\/[^']+'\)/, `url(data:${typ};base64,${fs.readFileSync(ds + 'fonts/' + m[1]).toString('base64')})`);
  }).replace(/@import[^;]+;/g, '');
  const css = fs.readFileSync(ICI + 'cn1.css', 'utf8'), js = fs.readFileSync(ICI + 'cn1.js', 'utf8');
  const html = `<title>Clients ${D.annee} face à ${D.annee - 1}</title><style>${g}\n${css}
:root{color-scheme:light}
.choix{background:#222;color:#fff;font-family:var(--font-ui)}
.choix>div{max-width:1180px;margin:0 auto;padding:10px 32px;display:flex;gap:14px;align-items:center;flex-wrap:wrap}
.choix b{font-weight:500;font-size:13px}
.choix .q{font-size:12px;color:#cfc8bf;flex:1 1 320px;line-height:1.45}
.choix nav{display:inline-flex;gap:3px;background:#3a3633;border-radius:9px;padding:3px}
.choix button{border:none;background:none;color:#cfc8bf;font:500 12.5px var(--font-ui);padding:7px 14px;border-radius:7px;cursor:pointer}
.choix button.on{background:#fff;color:#222}
.vue[hidden]{display:none}
@media (max-width:900px){.choix>div{padding:10px 16px}}
</style>
<script>window.CN_DONNEES = ${JSON.stringify(D)};</script><script>${js}</script>
<div class="choix"><div><b>Résultat › Année · clients ${D.annee} face à ${D.annee - 1}</b>
<span class="q">Deux maquettes, mêmes données réelles (relues le 24/10/${D.annee}). Survolez un mois : le détail et l’écart à date.</span>
<nav><button data-v="a" class="on">A · un graphique, magasin au choix</button><button data-v="b">B · une courbe par magasin</button></nav></div></div>
<div class="vue" id="v-a"><div class="mq" data-mq="a"></div></div><div class="vue" id="v-b" hidden><div class="mq" data-mq="b"></div></div>
<script>document.querySelectorAll('.choix button').forEach(b => b.onclick = () => {
  document.querySelectorAll('.choix button').forEach(x => x.classList.toggle('on', x === b));
  document.querySelectorAll('.vue').forEach(v => { v.hidden = v.id !== 'v-' + b.dataset.v; });
  window.dispatchEvent(new Event('resize'));
});</script>`;
  fs.writeFileSync(sortie, html);
  return html.length;
}

(async () => {
  const i = process.argv.indexOf('--autonome');
  if (i > 0) { console.log('page autonome :', process.argv[i + 1], Math.round(autonome(process.argv[i + 1]) / 1024) + ' Ko'); }
  const s = await serveur(), base = 'http://127.0.0.1:' + s.address().port + '/docs/maquettes/clients-annee-n1/';
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1240, height: 900 }, deviceScaleFactor: 1.5 });
  const erreurs = []; p.on('pageerror', e => erreurs.push(e.message)); p.on('console', m => { if (m.type() === 'error') { erreurs.push(m.text()); } });
  const prendre = async (url, fichier, avant) => {
    await p.goto(base + url, { waitUntil: 'networkidle' }); await p.evaluate(() => document.fonts.ready);
    if (avant) { await avant(); }
    await p.waitForTimeout(300);
    await (await p.$('.mq')).screenshot({ path: ICI + fichier, type: 'jpeg', quality: 82 });
    console.log(fichier, await p.evaluate(() => document.querySelector('.mq').offsetHeight) + ' px');
  };
  await prendre('a.html', 'a.jpg');
  await prendre('a.html?magasin=3', 'a-gosselies.jpg', async () => { const r = await p.$('#cn-g rect[data-i="6"]'); await r.hover(); });
  await prendre('b.html', 'b.jpg');
  if (erreurs.length) { console.log('ERREURS', erreurs); process.exitCode = 1; }
  await b.close(); s.close();
})();
