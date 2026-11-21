/* Les captures de la boîte mail (21/11/2026) : maquette.html (le vrai boite.js, une fausse API) pilotée comme par un
 * magasin — cases cochées, gestes, « Ranger avec l'IA ». 1440 px de large, ×2, page entière.
 *   node docs/maquettes/boite-mail/capturer.cjs */
const { chromium } = require('playwright');
const path = require('path');

const ecrans = [
  ['1-archives-creees', 'creation', async p => {
    // La boîte n'a pas de dossier d'archives : « Archiver » le crée, puis range.
    await p.click('[data-bxu="1296"]');
    await p.waitForSelector('[data-bxg="archiver"]');
    await p.click('[data-bxg="archiver"]');
    await p.waitForSelector('.bx-ok');
    await p.click('[data-bxu="1299"]');
    await p.waitForSelector('.bx-m-h h2');
  }],
  ['2-selection', 'selection', async p => {
    for (const u of [1296, 1295, 1291, 1290, 1289, 1287]) { await p.click(`[data-bxc="${u}"] + i`); }
    await p.waitForSelector('.bx-sel');
  }],
  ['3-ranger-avec-ia', 'selection', async p => {
    await p.click("#bx-tout + i");
    await p.click('[data-bx="ranger"]');
    await p.waitForSelector('.bx-ia--prop');
    await p.click('[data-bxrp="1291"]');   // « Changer » : les dossiers en boutons, pour un e-mail
    await p.waitForSelector('.bx-dossiers--palette');
  }],
  ['4-corbeille-tout-effacer', 'selection', async p => {
    await p.click('[data-bxd="Trash"]');
    await p.waitForSelector('[data-bxu="400"]');
    await p.click("#bx-tout + i");
    await p.waitForSelector('.bx-sel');
  }],
];

(async () => {
  const b = await chromium.launch();
  for (const [nom, scene, jouer] of ecrans) {
    const p = await b.newPage({ viewport: { width: 1440, height: 960 }, deviceScaleFactor: 2 });
    p.on('pageerror', e => console.error(nom, 'erreur de page :', e.message));
    await p.goto('file://' + path.join(__dirname, 'maquette.html') + '?scene=' + scene);
    await p.waitForSelector('.bx-it');
    await jouer(p);
    await p.mouse.move(0, 0);
    await p.evaluate(() => document.fonts.ready);
    await p.waitForTimeout(200);
    await p.screenshot({ path: path.join(__dirname, nom + '.png'), fullPage: true });
    console.log('capturé', nom);
    await p.close();
  }
  await b.close();
})();
