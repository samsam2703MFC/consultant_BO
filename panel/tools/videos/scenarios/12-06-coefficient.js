/* Promotions › la fenêtre « Coefficient de marge » (?vue=promos), la vidéo de l'onglet (24/11/2026) :
 *   onglet    promos-coefficient   « Où les promotions sont utilisées » (en nombre ou en remise €, 7 ou 30 jours), le
 *                                  coefficient du lot de chaque promotion ; la fenêtre : le cas le plus serré, la jauge
 *                                  avec et sans la promotion, ce qui fixe le chiffre, puis produit par produit
 * Tout se lit, rien ne s'écrit (le dashboard n'a plus d'action d'écriture sur les promotions depuis le 26/10/2026) :
 * pas de chapitre de fonction. Données fictives : mocks/12_promotions.js (« Le 6e cookie offert », ×1,98, sous ×2).
 * Chaque phrase vient de panel/public/dashboard/dashboard.js (rendPmCanaux, pmCoefRendre, pmCoefProduits). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('12-06-coefficient', {
    vue: 'promos', serie: 'Promotions', parent: 'promotions',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css', 'marketing/src/promos_magasin.php'],
  });

  await f.onglet({
    id: 'promos-coefficient', titre: 'Le coefficient de marge',
    resume: 'Où les promotions sont utilisées, puis la fenêtre du coefficient de marge : le lot le plus serré, avec et sans la promotion, produit par produit.',
    accroche: '×2, un repère',
    programme: ['Où les promotions sont utilisées', 'En nombre ou en remise €, 7 ou 30 jours', 'Le coefficient du lot', 'Ce qui fixe le chiffre', 'Produit par produit'],
  });

  /* Où les promotions sont utilisées */
  await f.montrer('.cn-card', 'Où les promotions sont utilisées');
  await f.dire('Les promotions reconnues dans les tickets : au comptoir, en click & collect, en livraison ; et jour par jour.', 5400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-pmcanm="r"]', { apres: 1100 });
  await f.montrer('.cn-card', 'En remise €');
  await f.dire('« <b>En remise €</b> » : ce que chaque promotion a rendu aux clients ; « En nombre » : combien de fois.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-pmcanj="7"]', { apres: 1100 });
  await f.montrer('.cn-card', '7 jours');
  await f.dire('<b>7 jours</b> ou 30 : la période change, barres et jour par jour suivent.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-pmcanm="n"]', { apres: 700 });
  await f.cliquer('[data-pmcanj="30"]', { apres: 1000 });
  await f.montrerTout('.cn-card .cn-r .cn-rc', 'Coef. du lot');
  await f.dire('À droite, le <b>coefficient du lot</b> : prix payé hors TVA ÷ coût matière, le cas le plus serré. Sous ×2, il passe en rouge.', 6400);
  await f.cacher(150); await f.taire(150);

  /* La fenêtre */
  await f.cliquer('[data-pmcoef="panel:2103"]', { apres: 1100 });
  await f.montrer('#db-pmc .pmc-hero', 'Le cas le plus serré');
  await f.dire('Un clic ouvre la fenêtre : le coefficient du lot au plus serré, sans la promotion, et l’écart au repère ×2.', 5600);
  await f.montrer('#db-pmc .pm-jauge', 'Avec et sans la promo');
  await f.dire('La jauge : ×2,38 sans la promotion, <b>×1,98 avec</b>, juste sous le repère.', 4400);
  await f.montrer('#db-pmc .pmc-cas', 'Ce qui fixe le chiffre');
  await f.dire('Ce qui fixe le chiffre : le lot le plus serré, ce que paie le client hors TVA, le coût matière, la marge du lot.', 5600);
  await f.montrer('#db-pmc .pmc-ko', 'Sous ×2');
  await f.dire('Une promotion de la marque sous ×2 : à lui signaler. Le repère ne bloque rien.', 4400);
  await f.cacher(150); await f.taire(150);

  /* Produit par produit */
  await f.cliquer('#db-pmc [data-pmcvue="produits"]', { apres: 1100 });
  await f.montrer('#db-pmc .pmc-tuiles', 'Produit par produit');
  await f.dire('« <b>Voir produit par produit</b> » : le coefficient le plus bas, celui d’un lot type, combien passent sous ×2, la marge cédée.', 6200);
  await f.montrer('#db-pmc .pmc-st', 'Chaque produit');
  await f.dire('Puis chaque produit : son coefficient normal, celui dans la promotion, et le pire lot où il entre.', 5200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#db-pmc [data-pmcvue="lot"]', { apres: 1000 });
  await f.dire('« <b>← L’essentiel</b> » revient au résumé ; « Fermer » referme la fenêtre.', 3800);
  await f.taire(150);
  await f.cliquer('#db-pmc .pmc-pied [data-pmcfermer]', { apres: 1200 });

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
