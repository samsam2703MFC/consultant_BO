/* Trésorerie › l'onglet « Paiements » (?vue=tresorerie), et ses fonctions en chapitres (24/11/2026) :
 *   onglet    treso-paiements          les paiements qui reviennent : la catégorie (la ligne du plan), la fréquence, la
 *                                      semaine du mois, les dates, le total sur la période ; le lien « Onglet Paiements »
 *   fonction  treso-paiement-ajouter   un leasing : libellé, catégorie, montant, semaine, dates, remarque
 *   fonction  treso-paiement-modifier  le montant de l'ONSS donné par le comptable, les dates des travaux, × un abonnement
 * Données fictives : mocks/09_tresorerie.js ; avant de filmer, un abonnement résilié est ajouté (à supprimer). Chaque
 * phrase vient de panel/public/dashboard/tresorerie.js (rendPaiements, paieLaSemaine, rendGuide). */
const { Film } = require('../film');
const { fiabiliser, T, modifierPlan, recharger, lignes, ligne, defilerCote, saisir, ecrire, choisir, dater, onglet, fonction } = require('./tresorerie');

const rang = id => T + `.tr-pay-l:has([data-trtxt="p:${id}:libelle"])`;

module.exports = async function () {
  const f = await Film.ouvrir('09-03-paiements', {
    vue: 'tresorerie', serie: 'Trésorerie', parent: 'tresorerie', chargement: 3500,
    sources: ['panel/public/dashboard/tresorerie.js', 'panel/src/tresorerie.php'],
    avant: async p => {
      await modifierPlan(p, d => {
        d.paiements.push({ id: 'p15', libelle: 'Abonnement magazine (résilié)', cat: 'Frais fixes', montant: 35, freq: 'Chaque mois', sem: 3, debut: '', fin: '', note: 'Résilié en octobre' });
      });
      await recharger(p, 'tresorerie');
    },
  });
  fiabiliser(f);

  /* L'onglet */
  await f.onglet({
    id: 'treso-paiements', titre: 'Les paiements récurrents',
    resume: 'Les paiements qui reviennent : la catégorie décide la ligne du plan, puis la fréquence, la semaine du mois, les dates et le total sur la période.',
    accroche: 'Ce qui sort du compte, chaque mois',
    programme: ['Ajouter un paiement récurrent', 'Modifier ou supprimer un paiement'],
  });
  await lignes(f, await ligne(f, 'Gérant', 'Personnel', 'Frais fixes', 'Crédits & leasing', 'TVA & ONSS', 'Remboursement dettes', 'Investissements'), 'Depuis Paiements');
  await f.dire('Gérant, personnel, frais, crédits, TVA… : ces lignes du plan viennent de l’onglet <b>Paiements</b>.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(T + '.tr-sous-l[data-tra="onglet:paiements"]', { apres: 1200 });
  await f.defiler(-4000);
  await f.montrer(T + '.tr-intro', 'Paiements');
  await f.dire('Une ligne par <b>paiement qui revient</b> : chaque semaine, chaque mois ou chaque trimestre.', 4400);
  await f.montrerTout(T + '[data-trsel="p:p3:cat"], ' + T + '[data-trsel="p:p4:cat"], ' + T + '[data-trsel="p:p13:cat"]', 'Catégorie');
  await f.dire('La <b>catégorie</b> décide dans quelle ligne du plan le montant atterrit : ici, TVA & ONSS.', 4800);
  await f.montrerTout(T + '[data-trsel="p:p4:freq"], ' + T + '[data-trsel="p:p4:sem"]', 'Fréquence · semaine');
  await f.dire('La TVA du trimestre sort en janvier, avril, juillet et octobre, la <b>semaine 3</b> du mois.', 4800);
  await f.dire('La semaine 1 est celle du premier lundi du mois.', 2800);
  await f.montrer(rang('p10'), 'Dates');
  await f.dire('Premier et dernier paiement : facultatifs. Le prêt bancaire s’arrête fin mai 2030.', 4400);
  await f.cacher(150); await f.taire(150);
  await defilerCote(f, 400);
  await f.montrerTout(T + '.tr-pay-hd > span:nth-child(8), ' + T + '.tr-pay-l:nth-child(-n+7) > b.d', 'Sur la période');
  await f.dire('<b>Sur la période</b> : le total compté dans le plan. Puis la source, ou une remarque.', 4600);
  await lignes(f, rang('p3') + ', ' + rang('p4') + ', ' + rang('p13'), 'TVA & ONSS');
  await f.dire('TVA et ONSS ne sont pas préremplies : on les encode avec son <b>comptable</b>. Sans elles, le plan est trop optimiste.', 6000);
  await f.cacher(150); await f.taire(150);
  await defilerCote(f, 0);

  /* Ajouter */
  await fonction(f, { id: 'treso-paiement-ajouter', titre: 'Ajouter un paiement récurrent',
    resume: '« + Ajouter un paiement » : libellé, catégorie, montant, fréquence, semaine du mois, premier et dernier paiement, remarque.',
    points: ['« + Ajouter un paiement »', 'Libellé, catégorie, montant', 'Fréquence, semaine, dates', 'Une remarque'] });
  await onglet(f, 'paiements');
  await f.montrer(T + '[data-tra="addP"]', 'Ajouter');
  await f.dire('Un nouveau leasing : « <b>+ Ajouter un paiement</b> ».', 2800);
  await f.cacher(150);
  await f.cliquer(T + '[data-tra="addP"]', { apres: 800 });
  await f.taire(150);
  const id = (await f.p.locator(T + '.tr-pay-l').last().locator('input.tr-txt').first().getAttribute('data-trtxt')).split(':')[1];
  await ecrire(f, T + `[data-trtxt="p:${id}:libelle"]`, 'Leasing du lave-vaisselle');
  await choisir(f, `[data-trsel="p:${id}:cat"]`, 'Crédits & leasing');
  await saisir(f, 'pa:' + id, '145', { apres: 900 });
  await choisir(f, `[data-trsel="p:${id}:sem"]`, '3');
  await dater(f, `[data-trdate="p:${id}:debut"]`, '2027-01-01');
  await dater(f, `[data-trdate="p:${id}:fin"]`, '2029-12-31');
  await f.montrer(rang(id), 'Le nouveau paiement');
  await f.dire('Catégorie <b>Crédits & leasing</b>, 145 € chaque mois, la semaine 3, de janvier 2027 à décembre 2029.', 5200);
  await f.cacher(150); await f.taire(150);
  await defilerCote(f, 400);
  await ecrire(f, T + `[data-trtxt="p:${id}:note"]`, 'Contrat signé le 12/11');
  await f.montrer(rang(id), 'Sur la période');
  await f.dire('Une remarque ; le total sur la période atterrit dans la ligne « Crédits & leasing » du plan.', 4600);
  await f.taire(150); await f.cacher(300);
  await defilerCote(f, 0);

  /* Modifier, supprimer */
  await fonction(f, { id: 'treso-paiement-modifier', titre: 'Modifier ou supprimer un paiement récurrent',
    resume: 'Changer le montant, la fréquence, la semaine ou les dates d’une ligne ; × la supprime. Le plan suit tout seul.',
    points: ['Changer le montant', 'Changer la fréquence, la semaine ou les dates', '× supprime la ligne'] });
  await onglet(f, 'paiements');
  await f.montrer(rang('p3'), 'ONSS et précompte');
  await f.dire('Le comptable donne le montant de l’ONSS et du précompte : <b>3 150 €</b> par mois.', 4400);
  await f.cacher(150); await f.taire(150);
  await saisir(f, 'pa:p3', '3150');
  await f.montrer(rang('p14'), 'Rénovation');
  await f.dire('Les travaux de rénovation glissent de janvier à mars : on change les deux dates.', 4200);
  await f.cacher(150); await f.taire(150);
  await dater(f, '[data-trdate="p:p14:debut"]', '2027-03-01');
  await dater(f, '[data-trdate="p:p14:fin"]', '2027-03-31');
  await f.montrer(rang('p14'), 'Mars 2027');
  await f.dire('Les 26 000 € sortent maintenant la semaine 2 de mars : le plan se met à jour tout seul.', 4600);
  await f.cacher(150); await f.taire(150);
  await defilerCote(f, 400);
  await f.montrer(rang('p15'), 'Résilié');
  await f.dire('Un abonnement résilié : <b>×</b>, au bout de la ligne, le retire du plan.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(T + '[data-tra="delP:p15"]', { apres: 900 });
  await f.montrer(T + '.trs-court .trs-cle', 'Le plan suit');
  await f.dire('La ligne part : le résumé, en haut, compte 15 paiements récurrents ; le plan suit.', 4400);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
