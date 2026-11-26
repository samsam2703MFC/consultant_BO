/* La barre du haut › « Valeur du magasin » (?vue=ops), 26/11/2026 :
 *   onglet    barre-valeur    les vignettes de la barre (Dossier de formation, Relire : des gestes de lecture, montrés
 *                             ici), puis le panneau de la valeur : le calcul pas à pas et les six derniers trimestres
 * Aucune fonction en chapitre : la vignette ne fait que lire. Données fictives : mocks/00_commun.js (/ventes/mensuel,
 * /meta). Chaque phrase vient de panel/public/dashboard/dashboard.js (railBarre, valeurMagasin, valoPanneau,
 * valoTrimestres : VALO_JOURS 730, VALO_MULT 3,5, les charges normatives de /meta). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('01-15-valeur', {
    vue: 'ops', serie: 'Barre du haut', parent: 'decouvrir',
    sources: ['panel/public/dashboard/dashboard.js', 'panel/public/dashboard/dashboard.css'],
  });

  await f.onglet({
    id: 'barre-valeur', titre: 'La valeur du magasin',
    resume: 'Les vignettes de la barre du haut, puis la valeur du magasin : le calcul pas à pas (chiffre d’affaires annuel, marge brute réelle, charges normatives, × 3,5) et les six derniers trimestres.',
    accroche: 'Ce que vaut votre magasin',
    programme: ['Les vignettes de la barre', 'Relire les chiffres', 'Le calcul, pas à pas', 'Les six derniers trimestres'],
  });
  await f.montrer('#dash .db-avs', 'La barre du haut');
  await f.dire('Sur la page <b>Opérationnel</b>, la barre du haut : six vignettes, les raccourcis de la journée.', 4600);
  await f.montrer('#dash .db-avs [data-fcouvrir]', 'Dossier de formation');
  await f.dire('« <b>Dossier de formation</b> » dit ce qui reste à faire ; un clic ouvre la page Formations et suivi.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#dash .db-avs [data-recharger]', { apres: 250 });
  await f.montrer('#dash .db-avs [data-recharger]', 'Relire');
  await f.dire('« <b>Relire</b> » relit les chiffres de la page ; dessous, l’heure de la dernière lecture.', 4400);
  await f.cacher(150); await f.taire(150);

  await f.montrer('#dash .db-avs [data-vdrop]', 'Valeur du magasin');
  await f.dire('La vignette « <b>Valeur du magasin</b> » : ce que vaut le magasin, calculé sur ses ventes.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#dash .db-avs [data-vdrop]', { apres: 900 });
  await f.montrer('#dash .db-vpop', 'Le calcul');
  await f.dire('Un clic ouvre le <b>calcul</b>, pas à pas, sous la vignette.', 3400);
  const l = i => f.p.locator('#dash .db-vpop-l').nth(i);
  await f.montrer(l(0), 'Chiffre d’affaires annuel');
  await f.dire('Le chiffre d’affaires des <b>730 derniers jours</b> d’activité, ramené à 365 jours.', 4400);
  await f.montrer(l(1), 'Marge brute réelle');
  await f.dire('Moins le coût matière mesuré mois par mois : la <b>marge brute réelle</b>.', 4000);
  await f.montrer(l(2), 'Charges normatives');
  await f.dire('Moins les <b>charges normatives</b> du réseau : main-d’œuvre, frais généraux, redevances, charges financières.', 5400);
  await f.montrer(l(3), 'Résultat normatif');
  await f.dire('Reste le <b>résultat normatif</b> : ce que le magasin gagne aux normes du réseau, sur un an.', 4600);
  await f.montrer(l(4), '× 3,5');
  await f.dire('Multiplié par <b>3,5</b> : la valeur du magasin.', 3200);
  await f.montrer('#dash .db-vpop-t', 'Six trimestres');
  await f.dire('Dessous, les <b>six derniers trimestres</b>, à la même règle : la valeur monte-t-elle ?', 4600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#dash .db-vpop-x', { apres: 700 });
  await f.dire('La croix referme le panneau.', 2400);
  await f.taire(150);
  await f.pause(800);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
