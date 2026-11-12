/* Bingo de saison — la planche en capture (12/11/2026).
 *
 *   node docs/maquettes/bingo-saison/generer.js        (depuis pilotage-reseau/)
 *
 * Sert pilotage-reseau/ sur un port local le temps de la capture (les polices et le logo du cockpit sont lus là),
 * puis écrit planche.jpg (la planche entière), carte.jpg (la carte imprimée) et app.jpg (l'app du client), en
 * densité 2. Les données et les illustrations sont dans bingo.js.
 */
const http = require('http');
const fs = require('fs');
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }

const RACINE = path.resolve(__dirname, '../../..');
const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.png': 'image/png', '.otf': 'font/otf', '.ttf': 'font/ttf', '.jpg': 'image/jpeg' };
const serveur = http.createServer((req, res) => {
  const f = path.join(RACINE, decodeURIComponent(new URL(req.url, 'http://x').pathname));
  if (!f.startsWith(RACINE) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); res.end(); return; }
  res.writeHead(200, { 'Content-Type': TYPES[path.extname(f)] || 'application/octet-stream' });
  fs.createReadStream(f).pipe(res);
});

serveur.listen(0, '127.0.0.1', async () => {
  const port = serveur.address().port;
  const b = await pw.chromium.launch();
  try {
    const p = await b.newPage({ viewport: { width: 1680, height: 1100 }, deviceScaleFactor: 2 });
    p.on('pageerror', e => console.error('erreur de page :', e.message));
    await p.goto(`http://127.0.0.1:${port}/docs/maquettes/bingo-saison/planche.html`, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    await p.waitForTimeout(300);
    await p.screenshot({ path: path.join(__dirname, 'planche.jpg'), fullPage: true, type: 'jpeg', quality: 90 });
    await p.locator('.carte').screenshot({ path: path.join(__dirname, 'carte.jpg'), type: 'jpeg', quality: 92 });
    await p.locator('.tel').screenshot({ path: path.join(__dirname, 'app.jpg'), type: 'jpeg', quality: 92 });
    console.log('planche.jpg, carte.jpg, app.jpg écrits dans', __dirname);
  } finally {
    await b.close();
    serveur.close();
  }
});
