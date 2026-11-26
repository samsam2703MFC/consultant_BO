/* Analyse › la fiche d'un produit (?vue=analyse&produit=…), 26/11/2026 :
 *   onglet    analyse-produit   l'ouvrir d'un classement, son titre (catégorie, famille, référence), « Ce qu'il faut
 *                               retenir », les sept questions, un autre produit par la recherche, « ‹ Analyse » — des
 *                               gestes de lecture, montrés ici
 * Aucune fonction en chapitre : l'Analyse ne fait que lire. Données fictives : mocks/00_analyse.js (lib/ventes.js).
 * Chaque phrase vient de panel/public/dashboard/analyse.js (vueProduit, rendre, brancher). */
const { Film } = require('../film');

/** Un cadre autour d'un élément, défilé à vue, rogné à l'écran (une carte plus haute que l'écran). */
async function cadre(f, sel, etiq) {
  const r = await f.rect(sel);
  const y = Math.max(8, r.y), y2 = Math.min(810 - 8, r.y + r.h);
  await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [{ x: r.x, y, w: r.w, h: y2 - y }, etiq || '']);
}

module.exports = async function () {
  const f = await Film.ouvrir('10-02-produit', {
    vue: 'analyse', serie: 'Analyse', parent: 'analyse',
    sources: ['panel/public/dashboard/analyse.js', 'panel/public/dashboard/analyse.css', 'panel/src/dashboard_analyse.php'],
  });
  const QQ = n => '.an-qs > .an-qq:nth-child(' + n + ')';

  await f.onglet({
    id: 'analyse-produit', titre: 'La fiche d’un produit',
    resume: 'Un produit, sept questions : combien j’en vends, ce qu’il rapporte, ce qu’on en jette, quel jour il part, son prix face au réseau, face aux autres magasins, depuis l’ouverture.',
    accroche: 'Sept questions, une carte chacune',
    programme: ['L’ouvrir : un classement ou la recherche', 'Ce qu’il faut retenir', 'Les sept questions', 'Revenir à l’entrée'],
  });
  await f.montrer('.an-g2--top > .an-carte:first-child', 'Les plus gros chiffres');
  await f.dire('Dans les classements de l’entrée, un clic sur un produit ouvre sa fiche.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.an-g2--top > .an-carte:first-child tr[data-pid="100"]', { apres: 1900 });
  await f.montrer('.an-titre', 'Croissant au beurre');
  await f.dire('En haut : sa catégorie, sa <b>famille</b> — un lien vers sa page — et sa référence.', 4400);
  await f.montrer('.an-ret', 'Ce qu’il faut retenir');
  await f.dire('D’abord, <b>ce qu’il faut retenir</b> : son rang, ce qu’on en jette, son jour fort, son prix face au réseau.', 5400);
  await f.cacher(150); await f.taire(150);

  /* Les sept questions. */
  await cadre(f, QQ(1), 'Combien j’en vends ?');
  await f.dire('<b>Combien j’en vends</b> : ses pièces face à la période d’avant ; en pointillé, les autres magasins, ramenés à votre taille.', 6000);
  await cadre(f, QQ(2), 'Combien il me rapporte ?');
  await f.dire('<b>Combien il me rapporte</b> : du prix TTC à la marge par pièce, le coefficient, son rang.', 4800);
  await f.cacher(150); await f.taire(150);
  await cadre(f, QQ(3), 'Combien j’en jette ?');
  await f.dire('<b>Combien j’en jette</b> : les pièces jetées, leur part de ce qui sort du four, la matière perdue.', 5000);
  await cadre(f, QQ(4), 'Quel jour il part ?');
  await f.dire('<b>Quel jour il part</b> : 28 jours, quatre de chaque jour ; vendus en moyenne, au plus, au moins, et jetés.', 5600);
  await f.cacher(150); await f.taire(150);
  await cadre(f, QQ(5), 'Mon prix est-il bon ?');
  await f.dire('<b>Mon prix est-il bon</b> : l’affiché, l’encaissé remises comprises, et la médiane du réseau.', 4800);
  await cadre(f, QQ(6), 'Face aux autres');
  await f.dire('<b>Est-ce que je fais mieux que les autres ?</b> Les pièces pour 10 k€ de chiffre, à taille égale, sans nommer les magasins.', 6200);
  await f.cacher(150); await f.taire(150);
  await cadre(f, QQ(7), 'Depuis l’ouverture');
  await f.dire('Et <b>depuis l’ouverture</b> : mois par mois, en pièces par jour, face au mois d’avant.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.defiler(-5000);

  /* Un autre produit, par la recherche ; puis le retour. */
  await f.cliquer('.an-rech input', { apres: 300 });
  await f.dire('La <b>recherche</b> : le nom d’un produit, puis Entrée.', 3000);
  await f.taper('Éclair au chocolat', 60);
  await f.p.keyboard.press('Enter');
  await f.pause(1800);
  if (!(await f.p.locator('.an-titre').filter({ hasText: 'Éclair' }).count())) {
    await f.p.locator('.an-rech input').fill('Éclair au chocolat'); await f.p.locator('.an-rech input').dispatchEvent('change'); await f.pause(1800);
  }
  await f.taire(150);
  await f.montrer('.an-titre', 'Éclair au chocolat');
  await f.dire('Sa fiche s’ouvre, sur la même période.', 3000);
  await f.cacher(150); await f.taire(150);
  await f.montrer('[data-retour]', '‹ Analyse');
  await f.dire('« <b>‹ Analyse</b> » ramène à l’entrée.', 2800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-retour]', { apres: 1500 });

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
