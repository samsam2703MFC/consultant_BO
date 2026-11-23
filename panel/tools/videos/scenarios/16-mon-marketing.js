/* Vidéo 16 — « Mon marketing » (mode d'emploi de Marketing › Mon marketing, vue=marketing). Environ 2 min.
 * panel/public/dashboard/marketing.js : le calendrier de l'année (les campagnes du magasin, les saisons des produits et
 * leurs ventes de l'an dernier), puis chaque campagne en cours décrite comme la note du réseau. Données fictives :
 * mocks/16_marketing.js. */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('16-mon-marketing', { vue: 'marketing', serie: 'Mode d’emploi', titreSerie: 'Mon marketing', duree: 'env. 2 min' });
  await f.couverture({
    kicker: 'Mode d’emploi · Marketing', titre: 'Mon marketing', accroche: 'Les campagnes de mon magasin, en clair',
    programme: ['Le calendrier de l’année', 'Les saisons des produits', 'Une campagne en cours', 'Son calendrier et sa communication'],
  });

  /* 1 · Le calendrier de l'année. */
  await f.chapitre({ n: 1, total: 3, titre: 'Le calendrier de l’année', texte: 'Les campagnes du magasin et les saisons des produits, mois par mois.', ms: 3400 });
  await f.etape('1 · Le calendrier');
  await f.montrer('.cal', 'Le calendrier de l’année');
  await f.dire('En tête, le <b>calendrier de l’année</b> : les campagnes du magasin et les saisons des produits. Le trait, c’est aujourd’hui.', 5400);
  await f.montrerTout('.cal-l--c', 'Les campagnes');
  await f.dire('Les campagnes du magasin, de janvier à décembre : <b>terminées</b>, <b>en cours</b>, <b>à venir</b>.', 4400);
  await f.montrerTout('.cal-l--s', 'Les saisons des produits');
  await f.dire('Les <b>saisons des produits</b> : les gammes du panel, dans l’ordre de l’année, chacune avec son illustration.', 4800);
  await f.cacher(150);
  await f.cliquer('[data-calsaison="18"]', { apres: 1400 });
  await f.montrer('.cal-drop', 'Saint-Nicolas');
  await f.dire('Un clic ouvre la liste de ses produits : ce qu’ils ont fait <b>l’an dernier</b> sur la saison — quantité, chiffre d’affaires, marge.', 5800);
  await f.cacher(150);
  await f.cliquer('.cal-drop .cal-x', { apres: 900 });

  /* 2 · Une campagne en cours. */
  await f.chapitre({ n: 2, total: 3, titre: 'Une campagne en cours', texte: 'Décrite comme la note de campagne que le réseau envoie au magasin.', ms: 3400 });
  await f.etape('2 · Une campagne');
  await f.montrer('.mc .mc-h', 'Saint-Nicolas gourmand');
  await f.dire('Chaque <b>campagne en cours</b> : son type, son levier, ses dates, sa portée ; la barre dit où elle en est — jour 4 sur 21.', 5600);
  await f.montrer('.mc-bloc:has-text("Ce que la campagne vise")', 'Ce qu’elle vise');
  await f.dire('Ce qu’elle vise : elle se juge sur le <b>panier moyen</b>, avec un objectif de + 4 %. La référence de l’an dernier, le visé, les clients et le chiffre attendus.', 6600);
  await f.montrer('.mc-bloc:has-text("Les produits mis en avant")', 'Les produits mis en avant');
  await f.dire('Les produits mis en avant : l’<b>objectif du magasin</b> en pièces, la jauge, et l’état — ici, en avance.', 5000);
  await f.montrer('.mc-mot', 'Le mot du responsable');
  await f.dire('Et le <b>mot du responsable</b> de la campagne, quand il est écrit.', 3600);
  await f.cacher(150);

  /* 3 · Son calendrier et sa communication. */
  await f.chapitre({ n: 3, total: 3, titre: 'Son calendrier et sa communication', texte: 'Les étapes, les canaux, le budget et les documents joints.', ms: 3400 });
  await f.etape('3 · Calendrier et communication');
  await f.montrer('.mc-bloc:has-text("Le calendrier")', 'Le calendrier');
  await f.dire('Le <b>calendrier</b> de la campagne : les étapes du réseau, le lancement et la fin ; ce qui est fait est coché.', 5000);
  await f.montrer('.mc-bloc:has-text("La communication")', 'La communication');
  await f.dire('La <b>communication</b> canal par canal, les formats, et le budget levier par levier.', 4400);
  await f.montrer('.mc-bloc:has-text("Les documents")', 'Les documents');
  await f.dire('Les <b>documents</b> joints à la note s’ouvrent d’un clic.', 3400);
  await f.cacher(150);
  await f.montrer('.mc >> nth=1', 'Douceurs d’automne');
  await f.dire('Puis la campagne suivante : celle-ci vise <b>9 magasins désignés</b>, dont le vôtre.', 4200);
  await f.cacher(150);
  return f.fin({
    titre: 'À vous<br>de suivre', accroche: 'Une page, vos campagnes',
    retenir: ['Le calendrier de l’année d’un coup d’œil', 'Une saison : ses produits l’an dernier', 'Chaque campagne en cours, en clair', 'L’objectif du magasin et sa jauge'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
