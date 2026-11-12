/* Bingo de saison — la maquette HTML autonome et ses captures (12/11/2026).
 *
 *   node docs/maquettes/bingo-saison/generer.js        (depuis pilotage-reseau/)
 *
 * 1. Écrit bingo-maquette.html : planche.html avec ses polices, le logo, la bibliothèque QR, bingo.js et les dessins
 *    du design system (public/tablette/img, ramenés à 280 px) intégrés — un seul fichier, qui s'ouvre partout.
 * 2. L'ouvre tel quel (file://) et joue un parcours : un ticket coche la case de la semaine ; deux semaines plus
 *    tard, la 9e case débloque le 3e bon. Échoue si la page lève une erreur ou si une ressource manque.
 * 3. Écrit les captures en densité 2 : planche.jpg (l'état de départ, le 14 janvier), carte.jpg, app.jpg,
 *    app-case-cochee.jpg, app-bon-debloque.jpg et app-tirage.jpg (la carte pleine inscrite au tirage).
 * Les données et le choix des dessins sont dans bingo.js ; la mise en page et l'interaction dans planche.html.
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }

const ICI = __dirname;
const ASSETS = path.resolve(ICI, '../../../public/assets');
const DESSINS = path.resolve(ICI, '../../../public/tablette/img');
const b64 = f => fs.readFileSync(f).toString('base64');
const MIME = { '.otf': 'font/otf', '.ttf': 'font/ttf', '.png': 'image/png' };
const TAILLE = 280;          // les dessins s'affichent à 52 px au plus : 280 px suffisent en densité 2

/* Les dessins dont la maquette a besoin : la liste que bingo.js déclare. */
function fichiersDessins(){
  const ctx = { window: {} };
  vm.runInNewContext(fs.readFileSync(path.join(ICI, 'bingo.js'), 'utf8'), ctx);
  return ctx.window.BINGO.FICHIERS;
}

(async () => {
  const b = await pw.chromium.launch();
  const erreurs = [];
  try {
    /* 1. Les dessins, ramenés à 280 px dans le navigateur (canvas), puis le fichier autonome. */
    const fichiers = fichiersDessins();
    const outil = await b.newPage();
    const IMG = {};
    for (const f of fichiers) {
      const plein = path.join(DESSINS, f);
      if (!fs.existsSync(plein)) { throw new Error('dessin introuvable dans le design system : ' + f); }
      IMG[f] = await outil.evaluate(async ([uri, t]) => {
        const im = new Image(); im.src = uri; await im.decode();
        const c = document.createElement('canvas'); c.width = t; c.height = t;
        const g = c.getContext('2d'); g.imageSmoothingQuality = 'high'; g.drawImage(im, 0, 0, t, t);
        return c.toDataURL('image/png');
      }, ['data:image/png;base64,' + b64(plein), TAILLE]);
    }
    await outil.close();

    let html = fs.readFileSync(path.join(ICI, 'planche.html'), 'utf8');
    html = html.replace(/url\('\.\.\/\.\.\/\.\.\/public\/assets\/([^']+)'\)/g, (m, rel) => {
      const f = path.join(ASSETS, rel);
      return `url('data:${MIME[path.extname(f)]};base64,${b64(f)}')`;
    });
    html = html.replace("const LOGO = '../../../public/assets/img/logo.png';", `const LOGO = 'data:image/png;base64,${b64(path.join(ASSETS, 'img/logo.png'))}';`);
    const enLigne = f => '<script>\n' + fs.readFileSync(f, 'utf8') + '\n</script>';
    html = html.replace('<script src="../../../public/assets/vendor/qrcode/qrcode.js"></script>', () => enLigne(path.join(ASSETS, 'vendor/qrcode/qrcode.js')));
    html = html.replace('<script src="bingo.js"></script>', () => '<script>window.BINGO_IMG = ' + JSON.stringify(IMG) + ';</script>\n' + enLigne(path.join(ICI, 'bingo.js')));
    if (/url\('\.\.\/|<script src=/.test(html)) { throw new Error('une ressource reste en lien relatif dans le fichier autonome'); }
    const AUTONOME = path.join(ICI, 'bingo-maquette.html');
    fs.writeFileSync(AUTONOME, html);
    console.log('bingo-maquette.html :', Math.round(html.length / 1024), 'Ko ·', fichiers.length, 'dessins du design system');

    /* 2 et 3. Le parcours et les captures, sur le fichier autonome. */
    const p = await b.newPage({ viewport: { width: 1680, height: 1100 }, deviceScaleFactor: 2 });
    p.on('pageerror', e => erreurs.push(e.message));
    p.on('requestfailed', r => erreurs.push('ressource manquante : ' + r.url().slice(0, 100)));
    await p.goto('file://' + AUTONOME, { waitUntil: 'load' });
    await p.evaluate(() => document.fonts.ready);
    await p.waitForTimeout(300);
    const cassees = await p.evaluate(() => [...document.images].filter(i => !i.complete || !i.naturalWidth).length);
    if (cassees) { erreurs.push(cassees + ' image(s) non chargée(s)'); }
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
    // Le tirage : la semaine du Nouvel An cochée après coup, puis chaque semaine jusqu'à la 12e → carte pleine, inscrite.
    await clic('[data-a="reset"]');
    await clic('[data-a="semaine"][data-n="6"]');
    await clic('[data-a="ticket"][data-m="7.5"]');
    for (let n = 9; n <= 12; n++) { await clic('[data-a="suivante"]'); await clic('[data-a="ticket"][data-m="7.5"]'); await clic('[data-a="ticket"][data-m="4.2"]'); }
    await p.waitForTimeout(700);
    const t3 = await texte();
    if (!/Carte pleine !.*tirage au sort/.test(t3)) { erreurs.push('la carte pleine n’entre pas au tirage : ' + t3); }
    if (!(await p.locator('.tir.inscrit').count())) { erreurs.push('la carte du tirage ne dit pas « inscrit »'); }
    await p.locator('.tel').screenshot({ path: path.join(ICI, 'app-tirage.jpg'), type: 'jpeg', quality: 92 });
    await clic('[data-a="suivante"]');
    const t4 = await texte();
    if (!/Tirage au sort ce lundi 15 février/.test(t4)) { erreurs.push('la 13e semaine n’annonce pas le tirage : ' + t4); }
    console.log('parcours :', t1.replace(/\s+/g, ' ').slice(0, 70), '|', t2.replace(/\s+/g, ' ').slice(0, 70), '|', t3.replace(/\s+/g, ' ').slice(0, 90));
  } finally {
    await b.close();
  }
  if (erreurs.length) { console.error('ÉCHEC :\n- ' + erreurs.join('\n- ')); process.exit(1); }
  console.log('captures écrites : planche, carte, app, app-case-cochee, app-bon-debloque, app-tirage');
})();
