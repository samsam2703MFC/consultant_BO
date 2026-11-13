/* Bingo de saison — la maquette HTML autonome et ses captures (12/11/2026).
 *
 *   node docs/maquettes/bingo-saison/generer.js        (depuis pilotage-reseau/)
 *
 * 1. Écrit bingo-maquette.html : planche.html avec ses polices, le logo, la bibliothèque QR, bingo.js et les dessins
 *    (ill/, ceux de la version Claude Design de Sam) intégrés — un seul fichier, qui s'ouvre partout.
 * 2. L'ouvre tel quel (file://) et joue un parcours : un ticket coche la case de la semaine ; deux semaines plus
 *    tard, la 9e case débloque le 3e bon. Échoue si la page lève une erreur ou si une ressource manque.
 * 3. Fait de même pour l'écran d'édition du cockpit (edition.html → edition-maquette.html, edition.jpg).
 * 4. Écrit les captures en densité 2 : planche.jpg (l'état de départ, le 14 janvier), carte.jpg, app.jpg,
 *    app-case-cochee.jpg, app-bon-debloque.jpg et app-tirage.jpg (la carte pleine inscrite au tirage).
 * Les données et le choix des dessins sont dans bingo.js ; la mise en page et l'interaction dans planche.html.
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }

const ICI = __dirname;
const ASSETS = path.resolve(ICI, '../../../public/assets');
const DESSINS = path.join(ICI, 'ill');
const b64 = f => fs.readFileSync(f).toString('base64');
const MIME = { '.otf': 'font/otf', '.ttf': 'font/ttf', '.png': 'image/png' };

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
    /* 1. Les dessins (220 px, tels qu'exportés de Claude Design), puis le fichier autonome. */
    const fichiers = fichiersDessins();
    const IMG = {};
    for (const f of fichiers) {
      const plein = path.join(DESSINS, f);
      if (!fs.existsSync(plein)) { throw new Error('dessin introuvable : ill/' + f); }
      IMG[f.replace(/\.png$/, '')] = 'data:image/png;base64,' + b64(plein);
    }

    /** Une page de la maquette en un seul fichier : polices, logo, bibliothèque QR, bingo.js et dessins intégrés. */
    const autonome = (source, cible) => {
    let html = fs.readFileSync(path.join(ICI, source), 'utf8');
    html = html.replace(/url\('\.\.\/\.\.\/\.\.\/public\/assets\/([^']+)'\)/g, (m, rel) => {
      const f = path.join(ASSETS, rel);
      return `url('data:${MIME[path.extname(f)]};base64,${b64(f)}')`;
    });
    html = html.replace("const LOGO = '../../../public/assets/img/logo.png';", `const LOGO = 'data:image/png;base64,${b64(path.join(ASSETS, 'img/logo.png'))}';`);
    const enLigne = f => '<script>\n' + fs.readFileSync(f, 'utf8') + '\n</script>';
    html = html.replace('<script src="../../../public/assets/vendor/qrcode/qrcode.js"></script>', () => enLigne(path.join(ASSETS, 'vendor/qrcode/qrcode.js')));
    html = html.replace('<script src="bingo.js"></script>', () => '<script>window.BINGO_IMG = ' + JSON.stringify(IMG) + ';</script>\n' + enLigne(path.join(ICI, 'bingo.js')));
    if (/url\('\.\.\/|<script src=/.test(html)) { throw new Error('une ressource reste en lien relatif dans le fichier autonome'); }
    fs.writeFileSync(path.join(ICI, cible), html);
    console.log(cible + ' :', Math.round(html.length / 1024), 'Ko ·', fichiers.length, 'dessins');
    return path.join(ICI, cible);
    };
    const AUTONOME = autonome('planche.html', 'bingo-maquette.html');
    const EDITION = autonome('edition.html', 'edition-maquette.html');

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
    // Chaque cadeau plus gros que le précédent : les bons de la carte imprimée, puis le super bon au-dessus du dernier.
    const valeurs = (await p.locator('.case.bon .val').allInnerTexts()).map(t => parseFloat(t.replace(',', '.')));
    const superBon = parseFloat((await p.locator('[data-r="superBon"]').inputValue()));
    if (!(valeurs.join() === '4.9,9.9,19.9,29.9' && superBon > valeurs[valeurs.length - 1])) {
      erreurs.push('les cadeaux ne grandissent pas : ' + valeurs.join(', ') + ' puis ' + superBon);
    }
    // Le coût réel à 40 % de food cost : 114,50 € de cadeaux en vente coûtent 45,80 €, sur 258,70 € d'achats au moins
    // (155,22 € de marge brute) ; il reste 109,42 €.
    const couts = await p.locator('.couts').innerText();
    if (!/114,50 €[\s\S]*45,80 €[\s\S]*258,70 €[\s\S]*155,22 €[\s\S]*17,7 %[\s\S]*109,42 €/.test(couts)) { erreurs.push('le tableau des coûts est faux : ' + couts.replace(/\s+/g, ' ')); }
    const texte = async () => (await p.locator('.toast').count()) ? p.locator('.toast').innerText() : '';
    // Le 14 janvier, 6,40 € sur un seuil de 19,90 € : un ticket de 4,20 € ne suffit pas, un de 13,50 € de plus coche
    // « Le bon pain » (7e case).
    await clic('[data-a="ticket"][data-m="4.2"]');
    if (!/Encore 9,30 €/.test(await texte())) { erreurs.push('un ticket sous le seuil ne dit pas ce qui manque : ' + (await texte())); }
    await clic('[data-a="ticket"][data-m="13.5"]');
    await p.waitForTimeout(700);
    const t1 = await texte();
    if (!/Case cochée : Le bon pain/.test(t1)) { erreurs.push('la case de la semaine ne s’est pas cochée : ' + t1); }
    await p.locator('.tel').screenshot({ path: path.join(ICI, 'app-case-cochee.jpg'), type: 'jpeg', quality: 92 });
    // Semaine 9 : 7,50 € + 13,50 € (8e case) ; semaine 10 : 7,50 € + 13,50 € → 9e case, le 3e bon.
    await clic('[data-a="suivante"]');
    await clic('[data-a="ticket"][data-m="7.5"]'); await clic('[data-a="ticket"][data-m="13.5"]');
    await clic('[data-a="suivante"]');
    await clic('[data-a="ticket"][data-m="7.5"]'); await clic('[data-a="ticket"][data-m="13.5"]');
    await p.waitForTimeout(700);
    const t2 = await texte();
    if (!/Bon débloqué : Bon de l’hiver, 19,90 €.*prochain vaudra 29,90 €/.test(t2)) { erreurs.push('le 3e bon ne s’est pas débloqué : ' + t2); }
    await p.locator('.tel').screenshot({ path: path.join(ICI, 'app-bon-debloque.jpg'), type: 'jpeg', quality: 92 });
    // x = 4 : trois bons, à la 4e, la 8e et la 12e case.
    await clic('[data-a="x"][data-v="4"]');
    const nBons = await p.locator('.mini .mc.bon').count();
    if (nBons !== 3) { erreurs.push('x = 4 : ' + nBons + ' bons au lieu de 3'); }
    // La carte pleine : la semaine du Nouvel An cochée après coup, puis chaque semaine jusqu'à la 13e → le super bon
    // cadeau, et l'inscription au tirage.
    await clic('[data-a="reset"]');
    await clic('[data-a="semaine"][data-n="6"]');
    await clic('[data-a="ticket"][data-m="13.5"]');
    for (let n = 9; n <= 13; n++) { await clic('[data-a="suivante"]'); await clic('[data-a="ticket"][data-m="7.5"]'); await clic('[data-a="ticket"][data-m="13.5"]'); }
    await p.waitForTimeout(700);
    const t3 = await texte();
    if (!/Carte pleine, 13 cases sur 13 ! Super bon cadeau de 49,90 €.*tirage au sort/.test(t3)) { erreurs.push('la carte pleine ne donne pas le super bon et le tirage : ' + t3); }
    if (!(await p.locator('.mc.mega.plein').count())) { erreurs.push('la case du super bon ne s’allume pas'); }
    if (!(await p.locator('.tir.inscrit').count())) { erreurs.push('la carte du tirage ne dit pas « inscrit »'); }
    if (!/Super bon cadeau/.test(await p.locator('.porte').innerText())) { erreurs.push('le super bon n’est pas dans le portefeuille'); }
    await p.locator('.tel').screenshot({ path: path.join(ICI, 'app-tirage.jpg'), type: 'jpeg', quality: 92 });
    await clic('[data-a="suivante"]');
    const t4 = await texte();
    if (!/printemps arrive lundi 22\/02.*tirage/.test(t4)) { erreurs.push('la fin de carte n’annonce pas le tirage du 22/02 : ' + t4); }
    // Un super bon plus petit que le dernier bon : relevé, et la barre le dit.
    await p.locator('[data-r="superBon"]').fill('3'); await p.locator('[data-r="superBon"]').dispatchEvent('change');
    const releve = await p.locator('[data-r="superBon"]').inputValue();
    if (releve !== '39.9' || !(await p.locator('.ctl .avis').count())) { erreurs.push('le super bon de 3 € n’est pas relevé au-dessus du dernier bon : ' + releve); }
    // Sans tirage : la case du super bon reste, le bandeau et la carte du tirage partent.
    await p.locator('[data-r="tirage"]').uncheck();
    if (await p.locator('.tir, .tirage-ca').count()) { erreurs.push('le tirage reste affiché une fois coupé'); }
    // L'écran d'édition (cockpit) : il s'ouvre seul, la ligne de la Chandeleur montre les crêpes ; changer x refait la
    // grille de l'aperçu ; un bon plus petit que le précédent bloque la publication.
    const e = await b.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
    e.on('pageerror', x => erreurs.push('édition : ' + x.message));
    e.on('requestfailed', r => erreurs.push('édition, ressource manquante : ' + r.url().slice(0, 100)));
    await e.goto('file://' + EDITION, { waitUntil: 'load' });
    await e.evaluate(() => document.fonts.ready);
    await e.waitForTimeout(300);
    if (await e.evaluate(() => [...document.images].filter(i => !i.complete || !i.naturalWidth).length)) { erreurs.push('édition : image(s) non chargée(s)'); }
    if ((await e.locator('.pick button.on').innerText()).trim() !== 'crêpes') { erreurs.push('édition : la Chandeleur ne montre pas les crêpes'); }
    // Une fenêtre aussi haute que la page : la barre de publication (collée en bas de l'écran) reste à sa place, en bas.
    await e.setViewportSize({ width: 1440, height: await e.evaluate(() => document.documentElement.scrollHeight) });
    await e.screenshot({ path: path.join(ICI, 'edition.jpg'), fullPage: true, type: 'jpeg', quality: 90 });
    await e.setViewportSize({ width: 1440, height: 1000 });
    await e.locator('.seg2 [data-a="x"][data-v="4"]').click();
    if ((await e.locator('.apercu .ap.b').count()) !== 3) { erreurs.push('édition : x = 4 ne donne pas 3 bons dans l’aperçu'); }
    await e.locator('[data-k="v1"]').fill('3'); await e.locator('[data-k="v1"]').dispatchEvent('change');
    if (!(await e.locator('.cta[disabled]').count())) { erreurs.push('édition : un bon plus petit que le précédent laisse publier'); }
    await e.close();
    console.log('parcours :', t1.replace(/\s+/g, ' ').slice(0, 70), '|', t2.replace(/\s+/g, ' ').slice(0, 70), '|', t3.replace(/\s+/g, ' ').slice(0, 90));
  } finally {
    await b.close();
  }
  if (erreurs.length) { console.error('ÉCHEC :\n- ' + erreurs.join('\n- ')); process.exit(1); }
  console.log('captures écrites : planche, carte, app, app-case-cochee, app-bon-debloque, app-tirage, edition');
})();
