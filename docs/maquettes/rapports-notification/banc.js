/* Le banc de capture : un serveur statique sur la racine du dépôt, le navigateur à l'heure du
 * magasin (mardi 6 octobre 2026, 10:45 à Bruxelles), et l'API simulée par donnees.js. */
const fs = require('fs');
const http = require('http');
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const { MAINTENANT, API, COCKPIT, COCKPIT_DEFAUT } = require('./donnees');

const RACINE = path.join(__dirname, '../../..');
const PORT = 8099;
const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.woff': 'font/woff', '.otf': 'font/otf', '.ttf': 'font/ttf', '.json': 'application/json', '.webmanifest': 'application/json' };

function serveur() {
  return new Promise(ok => {
    const s = http.createServer((req, res) => {
      let f = path.join(RACINE, decodeURIComponent(new URL(req.url, 'http://x').pathname));
      if (fs.existsSync(f) && fs.statSync(f).isDirectory()) { f = path.join(f, 'index.html'); }
      if (!f.startsWith(RACINE) || !fs.existsSync(f)) { res.writeHead(404); res.end(); return; }
      res.writeHead(200, { 'Content-Type': TYPES[path.extname(f)] || 'application/octet-stream' });
      fs.createReadStream(f).pipe(res);
    });
    s.listen(PORT, '127.0.0.1', () => ok(s));
  });
}

async function navigateur() {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  return b;
}

/** Une page à l'heure du magasin, l'API simulée (celle du cockpit si `cockpit`, sinon celle du
 * dashboard), les photos servies depuis le dépôt. */
async function page(b, largeur, hauteur, cockpit) {
  const ctx = await b.newContext({ viewport: { width: largeur || 1440, height: hauteur || 950 }, timezoneId: 'Europe/Brussels', locale: 'fr-BE', serviceWorkers: 'block' });
  const p = await ctx.newPage();
  p.erreurs = [];
  p.on('pageerror', e => p.erreurs.push(e.message));
  await p.clock.setFixedTime(new Date(MAINTENANT));
  await p.route('**/api/cockpit/**', r => {
    const u = new URL(r.request().url());
    const chemin = u.pathname.replace(/.*\/api\/cockpit/, '');
    const f = cockpit ? (COCKPIT[chemin] || COCKPIT_DEFAUT) : API[chemin];
    if (r.request().method() !== 'GET') { return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); }
    if (!f) { return r.fulfill({ status: 503, contentType: 'application/json', body: '{"error":"hors maquette"}' }); }
    return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(f(u)) });
  });
  await p.route('https://photos.maquette/**', r => {
    const f = path.join(__dirname, '../dashboard-operationnel/photos', path.basename(new URL(r.request().url()).pathname));
    return fs.existsSync(f) ? r.fulfill({ status: 200, contentType: 'image/jpeg', body: fs.readFileSync(f) }) : r.fulfill({ status: 404 });
  });
  return p;
}

module.exports = { serveur, navigateur, page, PORT };
