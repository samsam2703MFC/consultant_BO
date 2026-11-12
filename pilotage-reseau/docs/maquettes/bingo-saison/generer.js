/* Bingo de saison — la maquette HTML autonome et ses captures (12/11/2026).
 *
 *   node docs/maquettes/bingo-saison/generer.js        (depuis pilotage-reseau/)
 *
 * 1. Écrit bingo-maquette.html : planche.html avec ses polices, le logo, la bibliothèque QR et bingo.js intégrés —
 *    un seul fichier, qui s'ouvre partout (double-clic, pièce jointe, téléphone).
 * 2. L'ouvre tel quel (file://) et joue un parcours : un ticket coche la case de la semaine ; deux semaines plus
 *    tard, la 9e case débloque le 3e bon. Échoue si la page lève une erreur.
 * 3. Écrit les captures en densité 2 : planche.jpg (l'état de départ, le 14 janvier), carte.jpg, app.jpg,
 *    app-case-cochee.jpg et app-bon-debloque.jpg.
 * Les données et les illustrations sont dans bingo.js ; la mise en page et l'interaction dans planche.html.
 */
const fs = require('fs');
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }

const ICI = __dirname;
const ASSETS = path.resolve(ICI, '../../../public/assets');
const b64 = f => fs.readFileSync(f).toString('base64');
const MIME = { '.otf': 'font/otf', '.ttf': 'font/ttf', '.png': 'image/png' };

/* 1. Le fichier autonome. */
let html = fs.readFileSync(path.join(ICI, 'planche.html'), 'utf8');
html = html.replace(/url\('\.\.\/\.\.\/\.\.\/public\/assets\/([^']+)'\)/g, (m, rel) => {
  const f = path.join(ASSETS, rel);
  return `url('data:${MIME[path.extname(f)]};base64,${b64(f)}')`;
});
html = html.replace("const LOGO = '../../../public/assets/img/logo.png';", `const LOGO = 'data:image/png;base64,${b64(path.join(ASSETS, 'img/logo.png'))}';`);
const enLigne = f => '<script>\n' + fs.readFileSync(f, 'utf8') + '\n</script>';
html = html.replace('<script src="../../../public/assets/vendor/qrcode/qrcode.js"></script>', () => enLigne(path.join(ASSETS, 'vendor/qrcode/qrcode.js')));
html = html.replace('<script src="bingo.js"></script>', () => enLigne(path.join(ICI, 'bingo.js')));
if (/\.\.\/\.\.\/\.\.\/public|src="bingo\.js"/.test(html)) { throw new Error('un chemin relatif reste dans le fichier autonome'); }
const AUTONOME = path.join(ICI, 'bingo-maquette.html');
fs.writeFileSync(AUTONOME, html);
console.log('bingo-maquette.html :', Math.round(html.length / 1024), 'Ko');

/* 2 et 3. Le parcours et les captures, sur le fichier autonome. */
(async () => {
  const b = await pw.chromium.launch();
  const erreurs = [];
  try {
    const p = await b.newPage({ viewport: { width: 1680, height: 1100 }, deviceScaleFactor: 2 });
    p.on('pageerror', e => erreurs.push(e.message));
    p.on('requestfailed', r => erreurs.push('requête : ' + r.url().slice(0, 80)));
    await p.goto('file://' + AUTONOME, { waitUntil: 'load' });
    await p.evaluate(() => document.fonts.ready);
    await p.waitForTimeout(300);
    await p.screenshot({ path: path.join(ICI, 'planche.jpg'), fullPage: true, type: 'jpeg', quality: 90 });
    await p.locator('.carte').screenshot({ path: path.join(ICI, 'carte.jpg'), type: 'jpeg', quality: 92 });
    await p.locator('.tel').screenshot({ path: path.join(ICI, 'app.jpg'), type: 'jpeg', quality: 92 });

    const clic = sel => p.locator(sel).first().click();
    const texte = async () => (await p.locator('.toast').count()) ? p.locator('.toast').innerText() : '';
    // Le 14 janvier, 6,40 € : un ticket de 4,20 € coche « Le bon pain » (7e case).
    await clic('[data-a="ticket"][data-m="4.2"]');
    await p.waitForTimeout(700);
    const t1 = await texte();
    if (!/Case cochée : Le bon pain/.test(t1)) { erreurs.push('la case de la semaine ne s’est pas cochée : ' + t1); }
    await p.locator('.tel').screenshot({ path: path.join(ICI, 'app-case-cochee.jpg'), type: 'jpeg', quality: 92 });
    // Semaine 9 : 7,50 € + 2,80 € (8e case) ; semaine 10 : 7,50 € + 4,20 € → 9e case, le 3e bon.
    await clic('[data-a="suivante"]');
    await clic('[data-a="ticket"][data-m="7.5"]'); await clic('[data-a="ticket"][data-m="2.8"]');
    await clic('[data-a="suivante"]');
    await clic('[data-a="ticket"][data-m="7.5"]'); await clic('[data-a="ticket"][data-m="4.2"]');
    await p.waitForTimeout(700);
    const t2 = await texte();
    if (!/Bon débloqué : Bon de l’hiver/.test(t2)) { erreurs.push('le 3e bon ne s’est pas débloqué : ' + t2); }
    await p.locator('.tel').screenshot({ path: path.join(ICI, 'app-bon-debloque.jpg'), type: 'jpeg', quality: 92 });
    // x = 4 : trois bons, à la 4e, la 8e et la 12e case.
    await clic('[data-a="x"][data-v="4"]');
    const nBons = await p.locator('.mini .mc.bon').count();
    if (nBons !== 3) { erreurs.push('x = 4 : ' + nBons + ' bons au lieu de 3'); }
    await clic('[data-a="reset"]');
    console.log('parcours :', t1.replace(/\s+/g, ' ').slice(0, 90), '|', t2.replace(/\s+/g, ' ').slice(0, 90));
  } finally {
    await b.close();
  }
  if (erreurs.length) { console.error('ÉCHEC :\n- ' + erreurs.join('\n- ')); process.exit(1); }
  console.log('captures écrites : planche, carte, app, app-case-cochee, app-bon-debloque');
})();
