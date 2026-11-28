/* Analyse › la page d'une famille (?vue=analyse&famille=…), 26/11/2026 :
 *   onglet    analyse-famille   l'ouvrir d'un clic dans « Les familles », « Ce qu'il faut retenir », les huit questions,
 *                               le jour de la semaine (« À quelle heure se vend-elle ? »), un produit qui la porte, puis
 *                               le lien de sa famille, et « Famille · … » dans la recherche — des gestes de lecture,
 *                               montrés ici
 * Aucune fonction en chapitre : l'Analyse ne fait que lire. Données fictives : mocks/00_analyse.js (lib/ventes.js).
 * Chaque phrase vient de panel/public/dashboard/analyse.js (vueFamille, rendre, brancher). */
const { Film } = require('../film');

/** Un cadre autour d'un élément, défilé à vue, rogné à l'écran (une carte plus haute que l'écran). */
async function cadre(f, sel, etiq) {
  const r = await f.rect(sel);
  const y = Math.max(8, r.y), y2 = Math.min(810 - 8, r.y + r.h);
  await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [{ x: r.x, y, w: r.w, h: y2 - y }, etiq || '']);
}

module.exports = async function () {
  const f = await Film.ouvrir('10-03-famille', {
    vue: 'analyse', serie: 'Analyse', parent: 'analyse',
    sources: ['panel/public/dashboard/analyse.js', 'panel/public/dashboard/analyse.css', 'panel/src/dashboard_analyse.php'],
  });
  const QQ = n => '.an-qs > .an-qq:nth-child(' + n + ')';

  await f.onglet({
    id: 'analyse-famille', titre: 'La page d’une famille',
    resume: 'Une famille, huit questions : son poids, sa tendance face au réseau, qui la porte, ce qui monte ou baisse, ce qu’elle perd, à quelle heure elle se vend, chez les autres, depuis l’ouverture.',
    accroche: 'Une famille, huit questions',
    programme: ['L’ouvrir : un clic, une fiche ou la recherche', 'Ce qu’il faut retenir', 'Les huit questions', 'À quelle heure, jour par jour'],
  });
  await cadre(f, '.an-corps > .an-g2:not(.an-g2--top) > .an-carte:first-child', 'Les familles');
  await f.dire('Dans « <b>Les familles</b> », un clic ouvre la page d’une famille.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.an-fam[data-groupe="Pâtisserie"]', { apres: 1900 });
  await f.montrer('.an-res-ret', 'Ce qu’il faut retenir');
  await f.dire('<b>Ce qu’il faut retenir</b> : son poids, sa tendance, les produits qui la portent, ce qu’elle perd.', 4500);
  await f.cacher(150); await f.taire(150);

  /* Les huit questions. */
  await cadre(f, QQ(1), 'Combien pèse-t-elle ?');
  await f.dire('<b>Combien pèse-t-elle</b> : son chiffre, sa marge, sa part du magasin.', 3300);
  await cadre(f, QQ(2), 'Monte-t-elle ?');
  await f.dire('<b>Monte-t-elle ou baisse-t-elle</b> : chez vous et dans le réseau, ramené à votre taille.', 4600);
  await f.cacher(150); await f.taire(150);
  await cadre(f, QQ(3), 'Qui la porte ?');
  await f.dire('<b>Quels produits la portent</b> : ceux qu’il ne faut jamais manquer. Un clic ouvre leur fiche.', 4800);
  await cadre(f, QQ(4), 'Montent, baissent');
  await f.dire('<b>Lesquels montent, lesquels baissent</b> ; les nouveaux et les semaines hors norme, à part.', 4800);
  await f.cacher(150); await f.taire(150);
  await cadre(f, QQ(5), 'Ce qu’elle perd');
  await f.dire('<b>Où est-ce que je perds de l’argent</b> : les invendus, produit par produit. Vert sous 8 %, or de 8 à 15 %, rouge au-delà.', 5900);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(QQ(6) + ' [data-jour="0"]', { apres: 1500 });
  await cadre(f, QQ(6), 'À quelle heure ?');
  await f.dire('<b>À quelle heure se vend-elle</b> : un jour moyen, heure par heure. On choisit le jour ; ici, le dimanche.', 5600);
  await f.cacher(150); await f.taire(150);
  await cadre(f, QQ(7), 'Et chez les autres ?');
  await f.dire('<b>Et chez les autres ?</b> Produit par produit à taille égale, et ce qu’ils vendent que vous n’avez pas.', 5400);
  await cadre(f, QQ(8), 'Depuis l’ouverture');
  await f.dire('Puis, <b>depuis l’ouverture</b>, mois par mois.', 2800);
  await f.cacher(150); await f.taire(150);

  /* Les autres chemins : depuis la fiche d'un produit, et la recherche. */
  await f.cliquer(QQ(3) + ' tr[data-pid="113"]', { apres: 1900 });
  await f.montrer('.an-titre [data-groupe]', 'Sa famille');
  await f.dire('Sur la fiche d’un produit, le nom de sa <b>famille</b> ouvre sa page.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.an-titre [data-groupe]', { apres: 1700 });
  await f.dire('Dans la recherche : « <b>Famille ·</b> » et son nom, puis Entrée.', 2400);
  await f.cliquer('.an-rech input', { apres: 400 });
  await f.p.locator('.an-rech input').focus();
  await f.taper('Famille · Viennoiserie', 55);
  console.log('  recherche :', JSON.stringify(await f.p.locator('.an-rech input').inputValue()));
  await f.p.keyboard.press('Enter');
  await f.pause(1800);
  if (!(await f.p.locator('.an-titre').filter({ hasText: 'Viennoiserie' }).count())) {
    await f.p.locator('.an-rech input').fill('Famille · Viennoiserie'); await f.p.locator('.an-rech input').dispatchEvent('change'); await f.pause(1800);
  }
  await f.taire(150);
  await f.montrer('.an-titre', 'Viennoiserie');
  await f.dire('La page de la famille s’ouvre, sur la même période.', 2800);
  await f.cacher(150); await f.taire(150);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
