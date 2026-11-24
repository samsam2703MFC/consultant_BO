/* Les captures de l'autocontrôle qualité (tablette en portrait, 820 × 1180, et l'écran de paramétrage au bureau),
 * puis la planche qui les rassemble.
 *
 *   node formation/docs/maquettes/autocontrole-qualite/generer.js
 *
 * Le script sert lui-même la racine du dépôt (port 8099 par défaut, PORT=… pour un autre) : les pages lisent les
 * polices dans shared/ et les icônes dans panel/. Aucune donnée réseau : la fiche est figée dans contexte/ (figer.js).
 */
const fs = require('fs'), path = require('path'), http = require('http');
const { chromium } = require('playwright');

const ICI = __dirname;
const RACINE = path.resolve(ICI, '../../../..');
const PORT = Number(process.env.PORT || 8099);
const BASE = `http://127.0.0.1:${PORT}/formation/docs/maquettes/autocontrole-qualite/`;

// Numéro, page, titre de la planche, ce que l'écran montre.
const ECRANS = [
  ['01-fiche-bouton', 'fiche.html#ecran=fiche', 'La fiche produit', 'Le bouton « Autocontrôle qualité » sous le nom, les quatre étapes annoncées, les 7 derniers jours en pastilles.'],
  ['02-cuisson', 'fiche.html#ecran=cuisson&demo=ecarts', '1. Cuisson', 'Photo obligatoire à côté de la référence ; teintes en vignettes ; un écart ouvre sa correction sur place.'],
  ['03-structure', 'fiche.html#ecran=structure&demo=ecarts', '2. Structure', 'Volume dessiné contre la cible, « Retombé après cuisson » en oui / non, mie au toucher de très mou à dur.'],
  ['04-structure-suite', 'fiche.html#ecran=structure&demo=ecarts&defiler=mie_toucher', '2. Structure (suite)', 'Épaisseur de la croûte en coupe, forme vue de dessus, poids avec sa plage de tolérance.'],
  ['05-organoleptique', 'fiche.html#ecran=organoleptique&demo=ecarts', '3. Organoleptique', 'Odeur, goût, mie en bouche, « je le mettrais en vitrine » : un toucher par ligne.'],
  ['06-presentation', 'fiche.html#ecran=presentation&demo=ecarts', '4. Présentation', 'Semblable à la photo de référence (côte à côte) ; à sa place dans la vitrine, case du planogramme en bordeaux.'],
  ['07-bilan', 'fiche.html#ecran=bilan&demo=ecarts', 'Bilan avec écarts', 'Décision suggérée pour le lot, réglages de la prochaine fournée sans doublon, écarts, photos, suites.'],
  ['08-bilan-conforme', 'fiche.html#ecran=bilan&demo=conforme', 'Bilan conforme', 'Tout à la cible : « Le reste est conforme » à chaque étape, le lot part en vitrine.'],
  ['09-fiche-apres', 'fiche.html#ecran=fait&demo=ecarts', 'La fiche, après', 'L\'état du jour remplace le bouton ; un défaut qui revient 3 fois en 7 jours est signalé.'],
  ['10-parametrage', 'parametrage.html#categorie=brioche&critere=mie_toucher', 'Paramétrage réseau', 'Une grille par catégorie et des critères communs à tous les produits (photo de référence, planogramme) : photo obligatoire ou non, corrections, surcharges par produit.', { width: 1440, height: 1240 }],
];

const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json',
  '.jpg': 'image/jpeg', '.png': 'image/png', '.otf': 'font/otf', '.ttf': 'font/ttf', '.woff': 'font/woff', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' };

function serveur() {
  return new Promise(ok => {
    const s = http.createServer((req, res) => {
      const f = path.join(RACINE, decodeURIComponent(new URL(req.url, 'http://x').pathname));
      if (!f.startsWith(RACINE) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); }
      res.writeHead(200, { 'Content-Type': TYPES[path.extname(f)] || 'application/octet-stream' });
      fs.createReadStream(f).pipe(res);
    }).listen(PORT, '127.0.0.1', () => ok(s));
  });
}

function planche() {
  const vignette = ([n, , titre, texte, vue]) => `<figure class="${vue ? 'large' : ''}"><img src="${n}.jpg" alt=""><figcaption><b>${n.slice(0, 2)} · ${titre}</b>${texte}</figcaption></figure>`;
  return `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Autocontrôle qualité — planche</title><style>
@font-face { font-family: 'Gotham'; src: url('../../../../shared/public/assets/ds/fonts/Gotham_Medium.otf'); font-weight: 400; }
@font-face { font-family: 'Gotham'; src: url('../../../../shared/public/assets/ds/fonts/Gotham_Regular.otf'); font-weight: 700; }
@font-face { font-family: 'Vank'; src: url('../../../../shared/public/assets/ds/fonts/GC_Vank.ttf'); }
body { margin: 0; padding: 36px 40px 44px; background: #EAE4DC; font-family: 'Gotham', sans-serif; color: #241f1c; width: 2000px; box-sizing: border-box; }
h1 { font-family: 'Vank', serif; font-weight: 400; font-size: 44px; margin: 0; color: #8D1D2C; }
.sous { margin: 6px 0 26px; font-size: 17px; color: #7a7168; }
.grille { display: grid; grid-template-columns: repeat(6, 1fr); gap: 26px 22px; align-items: start; }
figure { margin: 0; }
figure img { width: 100%; display: block; border-radius: 14px; box-shadow: 0 2px 6px rgba(70,40,25,.08), 0 18px 34px -20px rgba(70,40,25,.4); }
figure.large { grid-column: span 3; }
figure.large img { width: 100%; }
figcaption { font-size: 14px; line-height: 1.4; margin-top: 10px; color: #5f564e; }
figcaption b { display: block; color: #241f1c; font-size: 16px; margin-bottom: 2px; }
</style></head><body><h1>Autocontrôle qualité — Cougnou Nature 400 g</h1>
<p class="sous">App Cuisine, tablette en portrait · quatre étapes (cuisson, structure, organoleptique, présentation), photos, oui / non, corrections · grilles par catégorie</p>
<div class="grille">${ECRANS.map(vignette).join('')}</div></body></html>`;
}

(async () => {
  const s = await serveur();
  const b = await chromium.launch();
  try {
    for (const [nom, page, , , vue] of ECRANS) {
      const ctx = await b.newContext({ viewport: vue || { width: 820, height: 1180 }, deviceScaleFactor: 2 });
      const p = await ctx.newPage();
      const erreurs = [];
      p.on('pageerror', e => erreurs.push(e.message));
      await p.goto(BASE + page);
      await p.waitForSelector('html[data-pret]', { timeout: 10000 }).catch(() => erreurs.push('page jamais prête'));
      if (erreurs.length) throw new Error(nom + ' : ' + erreurs.join(' ; '));
      await p.evaluate(() => document.fonts.ready);
      await p.waitForTimeout(300);
      await p.screenshot({ path: path.join(ICI, nom + '.jpg'), type: 'jpeg', quality: 78 });
      await ctx.close();
      console.log('✓', nom + '.jpg');
    }
    fs.writeFileSync(path.join(ICI, 'planche.html'), planche());
    const ctx = await b.newContext({ viewport: { width: 2000, height: 1000 }, deviceScaleFactor: 1.5 });
    const p = await ctx.newPage();
    await p.goto(BASE + 'planche.html');
    await p.evaluate(() => Promise.all([document.fonts.ready, ...[...document.images].map(i => i.complete || new Promise(r => { i.onload = r; }))]));
    await p.screenshot({ path: path.join(ICI, 'planche.jpg'), type: 'jpeg', quality: 72, fullPage: true });
    console.log('✓ planche.jpg');
  } finally {
    await b.close();
    s.close();
  }
})();
