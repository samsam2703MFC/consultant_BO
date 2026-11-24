/* Vidéo 10 — « Analyse : lire ses ventes » (mode d'emploi de Vendre › Analyse, analyse.js). Environ 2 min 55 avec la fin.
 * Données fictives : mocks/00_analyse.js (13 semaines du 24 août au 19 novembre 2026). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('10-analyse', { vue: 'analyse', serie: 'Mode d’emploi', titreSerie: 'Analyse : lire ses ventes', duree: 'env. 3 min' });
  await f.couverture({
    kicker: 'Mode d’emploi', titre: 'Analyse :<br>lire ses ventes', accroche: 'Vos produits, question par question',
    programme: ['Choisir la période', 'L’essentiel en un coup d’œil', 'La fiche d’un produit', 'La page d’une famille'],
  });

  await f.chapitre({ n: 1, total: 4, titre: 'Choisir la période', texte: 'Semaine, mois, trimestre, année, 13 semaines ou du … au : chaque chiffre se compare à la période d’avant.' });
  await f.etape('1 · La période');
  await f.montrer('.an-pers', 'La période');
  await f.dire('La page s’ouvre sur les <b>13 dernières semaines</b>, comparées aux 13 semaines d’avant, au jour.', 4600);
  await f.montrer('.an-seg--p', 'Le choix');
  await f.dire('Semaine, mois, trimestre, année, 13 semaines, ou <b>du … au</b> : un clic change la période.', 4400);
  await f.cacher(150);
  await f.cliquer('[data-per="mois"]', { apres: 1800 });
  await f.montrer('.an-kpi:first-child', 'Novembre');
  await f.dire('<b>Mois</b> : novembre jusqu’à aujourd’hui, face aux mêmes jours d’octobre. ‹ et › changent de période.', 5200);
  await f.cacher(150);
  await f.cliquer('[data-per="sem13"]', { apres: 1800 });
  await f.cacher();

  await f.chapitre({ n: 2, total: 4, titre: 'L’essentiel en un coup d’œil', texte: 'Le chiffre, la marge réalisée, les familles, et quatre classements de produits.' });
  await f.etape('2 · L’essentiel');
  await f.montrer('.an-kpis', 'L’essentiel');
  await f.dire('En tête : le chiffre, les pièces, la <b>marge réalisée</b>, le panier moyen, les références vendues.', 4800);
  await f.montrer('.an-kpi.fort', 'Marge réalisée');
  await f.dire('La marge réalisée : le chiffre hors TVA, moins les pièces vendues × le coût de leur recette.', 4800);
  await f.montrer('.an-kpi.alerte', 'Recettes sans coût');
  await f.dire('Une recette sans coût au panel : sa marge est inconnue. Elle est <b>à compléter au panel</b>.', 4400);
  await f.cacher(150);
  await f.montrer('.an-corps > .an-g2:not(.an-g2--top) > .an-carte:first-child', 'Les familles');
  await f.dire('Les <b>familles</b>, leur marge et leur flèche : ▲ vert à +5 % et plus, ▶ gris : stable, ▼ orange de −5 à −15 %, ▼ rouge au-delà.', 6400);
  await f.cacher(150);
  await f.montrerTout('.an-g2--top > .an-carte:nth-child(-n+2)', 'Les classements');
  await f.dire('Quatre classements : les plus gros chiffres, <b>ce qui rapporte le plus</b>…', 3800);
  await f.montrerTout('.an-g2--top > .an-carte:nth-child(n+3)', 'Ce qui monte, ce qui baisse');
  await f.dire('… <b>ce qui monte</b> et <b>ce qui baisse</b> : ±8 % et plus face à la période d’avant.', 4400);
  await f.cacher();

  await f.chapitre({ n: 3, total: 4, titre: 'La fiche d’un produit', texte: 'Ce qu’un exploitant veut savoir d’un produit, une carte par question.' });
  await f.etape('3 · Un produit');
  await f.defiler(-4000);
  await f.cliquer('.an-rech input', { apres: 300 });
  await f.taper('Croissant au beurre', 55);
  await f.p.keyboard.press('Enter');
  await f.pause(1800);
  if (!(await f.p.locator('.an-ret').count())) { await f.cliquer('.an-g2--top tr[data-pid="100"]', { apres: 1800 }); }
  await f.dire('Un produit se cherche par son nom, ou s’ouvre d’un clic dans un classement.', 3800);
  await f.montrer('.an-ret', 'Ce qu’il faut retenir');
  await f.dire('En tête, <b>ce qu’il faut retenir</b> : son rang, ce qu’on en jette, son jour fort, son prix.', 4800);
  await f.montrer('.an-qs > .an-qq:nth-child(2)', 'Combien il me rapporte ?');
  await f.dire('Puis une question par carte. <b>Combien il me rapporte</b> : du prix TTC à la marge par pièce.', 4800);
  await f.montrer('.an-qs > .an-qq:nth-child(3)', 'Combien j’en jette ?');
  await f.dire('<b>Combien j’en jette</b> : les pièces jetées, leur part de ce qui sort du four, la matière perdue.', 4800);
  await f.montrer('.an-qs > .an-qq:nth-child(4)', 'Quel jour il part ?');
  await f.dire('<b>Quel jour il part</b> : 4 semaines, jour par jour. La production suit le jour, pas une moyenne.', 5000);
  await f.montrer('.an-qs > .an-qq:nth-child(6)', 'Face aux autres');
  await f.dire('<b>Est-ce que je fais mieux que les autres ?</b> Les pièces pour 10 k€ de chiffre, à taille égale, sans nommer les autres magasins.', 6000);
  await f.cacher();

  await f.chapitre({ n: 4, total: 4, titre: 'La page d’une famille', texte: 'Son poids, qui la porte, ce qu’elle perd, à quelle heure elle se vend, et chez les autres.' });
  await f.etape('4 · Une famille');
  await f.defiler(-4000);
  await f.cliquer('[data-retour]', { apres: 1600 });
  await f.cliquer('.an-fam[data-groupe="Pâtisserie"]', { apres: 1800 });
  await f.montrer('.an-ret', 'Ce qu’il faut retenir');
  await f.dire('Un clic sur une famille ouvre sa page : son poids dans le chiffre et dans la marge, sa tendance, le réseau.', 5200);
  await f.montrer('.an-qs > .an-qq:nth-child(3)', 'Qui la porte ?');
  await f.dire('<b>Quels produits la portent</b> : ceux qu’il ne faut jamais manquer. Un clic ouvre la fiche du produit.', 5000);
  await f.montrer('.an-qs > .an-qq:nth-child(5)', 'Ce qu’elle perd');
  await f.dire('<b>Où est-ce que je perds de l’argent</b> : les invendus, produit par produit. Vert sous 8 %, orange de 8 à 15 %, rouge au-delà.', 6000);
  await f.cacher(150);
  await f.cliquer('.an-qs > .an-qq:nth-child(6) [data-jour="0"]', { apres: 1500 });
  await f.montrer('.an-qs > .an-qq:nth-child(6)', 'À quelle heure ?');
  await f.dire('<b>À quelle heure se vend-elle</b> : un jour moyen, heure par heure. On choisit le jour de la semaine.', 5000);
  await f.montrer('.an-qs > .an-qq:nth-child(7)', 'Et chez les autres ?');
  await f.dire('<b>Et chez les autres ?</b> À taille égale, produit par produit — et ce que d’autres vendent, que vous n’avez pas.', 5400);
  await f.cacher();

  return f.fin({
    titre: 'À vous de lire<br>vos ventes', accroche: 'Une question, une carte',
    retenir: ['La période se choisit en haut de la page', 'Les flèches comparent à la période d’avant', 'Un produit : sa fiche en sept questions', 'Une famille : qui la porte, ce qu’elle perd'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
