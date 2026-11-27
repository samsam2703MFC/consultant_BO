/* Analyse › l'entrée (?vue=analyse), 26/11/2026 :
 *   onglet    analyse-entree   le résumé de tête, les familles et leurs flèches, le chiffre tranche par tranche, les
 *                              quatre classements ; puis la période : Mois, ‹ et ›, « Du … au » — des gestes de lecture,
 *                              montrés ici (la même barre sert à la fiche d'un produit et à la page d'une famille)
 * Aucune fonction en chapitre : l'Analyse ne fait que lire. Données fictives : mocks/00_analyse.js (lib/ventes.js).
 * Chaque phrase vient de panel/public/dashboard/analyse.js (vueAccueil, choixPeriode, bornesPer, decaler, brancher). */
const { Film } = require('../film');

/** Un cadre autour d'un élément, défilé à vue, rogné à l'écran (une carte plus haute que l'écran). */
async function cadre(f, sel, etiq) {
  const r = await f.rect(sel);
  const y = Math.max(8, r.y), y2 = Math.min(810 - 8, r.y + r.h);
  await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [{ x: r.x, y, w: r.w, h: y2 - y }, etiq || '']);
}

module.exports = async function () {
  const f = await Film.ouvrir('10-01-entree', {
    vue: 'analyse', serie: 'Analyse', parent: 'analyse',
    sources: ['panel/public/dashboard/analyse.js', 'panel/public/dashboard/analyse.css', 'panel/src/dashboard_analyse.php'],
  });
  const FAM = '.an-corps > .an-g2:not(.an-g2--top) > .an-carte';

  await f.onglet({
    id: 'analyse-entree', titre: 'L’entrée : l’essentiel',
    resume: 'La page s’ouvre sur l’essentiel de la période : le résumé, les familles et leurs flèches, le chiffre tranche par tranche, quatre classements ; la période se choisit en haut.',
    accroche: 'Vos ventes, d’un coup d’œil',
    programme: ['Le résumé de tête', 'Les familles et leurs flèches', 'Le chiffre, tranche par tranche', 'Quatre classements', 'Choisir la période'],
  });
  await f.montrer('.an-pers', 'La période');
  await f.dire('L’Analyse s’ouvre sur les <b>13 dernières semaines</b>, comparées aux 13 semaines d’avant, au jour.', 4800);
  await f.montrer('.an-res', 'Le résumé');
  await f.dire('En tête : le chiffre, les pièces, le panier moyen, la <b>marge réalisée</b>, les références ; dessous, « À corriger » : les <b>recettes sans coût</b> au panel.', 6400);
  await f.cacher(150); await f.taire(150);
  await cadre(f, FAM + ':first-child', 'Les familles');
  await f.dire('Les <b>familles</b> : leur chiffre, leur marge réalisée et leur flèche face à la période d’avant.', 4600);
  await f.dire('▲ vert à +5 % et plus, ▶ gris : stable, ▼ or à −5 % et moins.', 4000);
  await cadre(f, FAM + ':nth-child(2)', 'Le chiffre');
  await f.dire('Le chiffre, semaine par semaine ; <b>jour par jour</b> sur une semaine ou un mois, mois par mois sur une année.', 5400);
  await f.cacher(150); await f.taire(150);
  await f.montrerTout('.an-g2--top > .an-carte:nth-child(-n+2)', 'Les classements');
  await f.dire('Quatre classements : les plus gros chiffres, <b>ce qui rapporte le plus</b>…', 3800);
  await f.montrerTout('.an-g2--top > .an-carte:nth-child(n+3)', 'Ce qui monte, ce qui baisse');
  await f.dire('… <b>ce qui monte</b> et <b>ce qui baisse</b> : ±8 % et plus. Un clic sur un produit ouvre sa fiche.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.defiler(-4000);

  /* La période : Mois, ‹ et ›, Du … au. */
  await f.montrer('.an-seg--p', 'La période');
  await f.dire('Semaine, mois, trimestre, année, 13 semaines, ou <b>du … au</b> : un clic change la période.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-per="mois"]', { apres: 1700 });
  await f.montrer('.an-res-v', 'Novembre');
  await f.dire('<b>Mois</b> : novembre jusqu’à aujourd’hui, face aux mêmes jours d’octobre.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-pas="-1"]', { apres: 1700 });
  await f.montrer('.an-nav', 'Octobre');
  await f.dire('‹ et › : la période d’avant ou d’après. Ici octobre, face à septembre.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-pas="1"]', { apres: 1300 });
  await f.cliquer('[data-per="plage"]', { apres: 1500 });
  await f.montrer('.an-pers', 'Du … au');
  await f.dire('<b>Du … au</b> : deux dates au choix, 400 jours au plus.', 3600);
  await f.cacher(150);
  await f.cliquer('[data-plage="du"]', { dx: 0.22, apres: 300 });
  await f.p.locator('[data-plage="du"]').fill('2026-10-15');
  await f.pause(1500);
  await f.montrer('.an-pers', 'Du 15 octobre au 19 novembre');
  await f.dire('Du 15 octobre à aujourd’hui : chaque chiffre se compare aux <b>36 jours d’avant</b>.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-per="sem13"]', { apres: 1500 });
  await f.dire('La période choisie vaut aussi pour la fiche d’un produit et la page d’une famille.', 4400);
  await f.taire(150);
  await f.pause(500);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
