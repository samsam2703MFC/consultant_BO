/* Trésorerie › l'onglet « Plan 2026-2027 » (?vue=tresorerie), et ses fonctions en chapitres (24/11/2026) :
 *   onglet    treso-plan              la routine du dimanche, la décision, les repères, le graphique, la fenêtre de
 *                                     semaines (8, 13, tout ; une barre du graphique), le plan, le pilotage, le hors plan
 *   fonction  treso-solde-dimanche    le solde réel du dimanche 15, l'écart
 *   fonction  treso-prevision-ventes  une case ≈ des encaissements remplacée par sa prévision
 *   fonction  treso-montant-semaine   double-clic : le montant du gérant d'une seule semaine
 *   fonction  treso-ponctuel          un paiement ponctuel dans la semaine où l'argent sort
 *   fonction  treso-pilotage          le cash réel de départ, le coussin de sécurité
 *   fonction  treso-dettes-hors-plan  ajouter, corriger, supprimer une dette hors plan
 *   fonction  treso-reessayer         une saisie qui n'est pas partie (« Réessayer ») ; le plan modifié sur un autre
 *                                     écran (« Reprendre le plan enregistré », sans écriture : rangé ici)
 *   fonction  treso-plan-neuf         un magasin sans plan : le plan proposé, le cash de départ, les paiements réels
 * Données fictives : mocks/09_tresorerie.js (le plan de démo part du 2 novembre ; le dimanche 15 n'est pas saisi). Chaque
 * phrase vient de panel/public/dashboard/tresorerie.js (rendPlan, bandeauEtat, corps, rendGuide) ou de src/tresorerie.php. */
const { Film } = require('../film');
const { fiabiliser, T, poster, modifierPlan, lignes, ligne, saisir, ecrire, onglet, fonction, fonctionPreparee, attendre } = require('./tresorerie');

// En tête, le résumé (26/11/2026, §7.13) : le cash prévu, le coussin, la décision, les repères, puis « À corriger » ;
// dessous, la routine du dimanche et le pilotage côte à côte ; le hors plan sous le plan.
const ROUTINE = T + '.tr-g2 > .tr-carte:nth-child(1)';
const DECISION = T + '.trs-lev';
const REPERES = T + '.trs-g, ' + T + '.trs-meta';
const COUSSIN = T + '.trs-g';
const GRAPHE = T + '.tr-carte:has(.tr-graph)';
const PILOTAGE = T + '.tr-g2 > .tr-carte:nth-child(2)';
const HORS = T + '.tr-hors-c';

module.exports = async function () {
  const f = await Film.ouvrir('09-01-plan', {
    vue: 'tresorerie', serie: 'Trésorerie', parent: 'tresorerie', chargement: 3500,
    sources: ['panel/public/dashboard/tresorerie.js', 'panel/src/tresorerie.php'],
  });
  fiabiliser(f);

  /* L'onglet */
  await f.onglet({
    id: 'treso-plan', titre: 'Le plan, semaine par semaine',
    resume: 'La routine du dimanche, la décision sur quatre semaines, les repères, le cash de chaque fin de semaine, le plan, le pilotage et les dettes hors plan.',
    accroche: 'Payer tout le monde, semaine après semaine',
    programme: ['Saisir le solde du dimanche', 'Taper sa prévision de ventes', 'Remplacer un montant d’une semaine', 'Saisir un paiement ponctuel',
      'Régler le cash de départ et le coussin', 'Gérer les dettes hors plan', 'Réessayer une saisie', 'Démarrer un plan neuf'],
  });
  await f.montrer(T + '.tr-ong', 'Plan 2026-2027');
  await f.dire('La Trésorerie s’ouvre sur le <b>plan</b> : semaine par semaine, montants en € TTC.', 4000);
  await f.montrer(DECISION, 'La décision');
  await f.dire('<b>La décision</b> : le cash prévu des quatre semaines à venir, face au coussin de sécurité.', 4400);
  await f.montrerTout(REPERES, 'Les repères');
  await f.dire('Les repères : le <b>point bas</b> du cash, les semaines sous le coussin, les ventes prévues, marchandises et personnel en % des ventes.', 6000);
  await f.montrer(T + '.trs-l2', 'À corriger');
  await f.dire('Dessous, « <b>À corriger</b> » : un bouton par point, qui mène à sa case du plan.', 4000);
  await f.montrer(ROUTINE, 'Routine du dimanche');
  await f.dire('La <b>routine du dimanche</b> : le solde réel du compte, lu dans la banque, et l’écart avec le prévu.', 4600);
  await f.montrer(PILOTAGE, 'Pilotage');
  await f.dire('À côté, le <b>pilotage</b> : le cash de départ et le coussin de sécurité.', 3600);
  await f.montrer(GRAPHE, 'Cash fin de semaine');
  await f.dire('Une barre par semaine : le cash prévu de fin de semaine. Sous le coussin, elle change de couleur ; à découvert, elle passe en rouge.', 6000);
  await f.cacher(200); await f.taire(150);
  await f.cliquer(T + '[data-tra="barre:13"]', { apres: 900 });
  await f.montrer(T + '.tr-carte-t .tr-cbar', 'La fenêtre');
  await f.dire('Un clic sur une barre, celle du 1er février : le plan se place sur cette semaine.', 4200);
  await f.dire('Le plan se lit par <b>8 semaines</b>, <b>13 semaines</b> ou en entier ; les flèches avancent, « Cette semaine » y revient.', 5600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(T + '.tr-carte-t [data-tra="nav:0"]', { apres: 900 });
  await lignes(f, await ligne(f, 'Encaissements (ventes)', 'Repère · réalisé en caisse', 'Repère · budget validé', 'Repère · même semaine N-1'), 'Les ventes');
  await f.dire('Une colonne par lundi. Une semaine close prend les <b>ventes réelles</b> de la caisse ; les autres partent du budget, marquées ≈.', 6000);
  await lignes(f, await ligne(f, 'Marchandises (fournisseurs)', 'Gérant', 'Personnel', 'Frais fixes', 'Crédits & leasing', 'TVA & ONSS', 'Remboursement dettes', 'Investissements'), 'Les calculs');
  await f.dire('Marchandises, gérant, personnel, frais… : calculés depuis les onglets <b>Fournisseurs</b> et <b>Paiements</b>.', 5000);
  await lignes(f, await ligne(f, 'Cash fin de semaine (prévu)', 'Cash réel en banque (dimanche)', 'Écart réel − prévu'), 'Le bas du plan');
  await f.dire('En bas, la ligne qui compte : le <b>cash fin de semaine</b>, puis le réel du dimanche et l’écart.', 4800);
  await f.montrer(HORS, 'Hors plan');
  await f.dire('Sous le plan, les dettes <b>hors plan</b>, à arbitrer.', 3200);
  await f.taire(150); await f.cacher(300);
  await f.defiler(-4000);

  /* Le solde du dimanche */
  await fonction(f, { id: 'treso-solde-dimanche', titre: 'Saisir le solde réel du dimanche',
    resume: 'Chaque dimanche soir, le solde lu dans la banque : l’écart avec le prévu s’affiche, la semaine suivante repart de ce chiffre.',
    points: ['Ouvrir sa banque', 'Taper le solde dans « Routine du dimanche »', 'Entrée : l’écart s’affiche'] });
  await f.montrer(ROUTINE, 'Un dimanche oublié');
  await f.dire('Le dimanche 15 n’est pas encore saisi. Le plan prévoyait <b>9 438 €</b> ce dimanche-là.', 4200);
  await f.cacher(150); await f.taire(150);
  await saisir(f, 'r:2026-11-09#dim', '8790', { apres: 1500 });
  await f.montrer(ROUTINE, 'L’écart');
  await f.dire('À côté, l’<b>écart</b> réel − prévu : − 648 €. Plus de 500 € : on en trouve la cause avant lundi.', 5600);
  await lignes(f, await ligne(f, 'Cash réel en banque (dimanche)', 'Écart réel − prévu'), 'Dans le plan');
  await f.dire('Le même chiffre est dans la ligne « Cash réel en banque » : la semaine suivante repart de lui.', 4600);
  await f.taire(150); await f.cacher(300);

  /* La prévision de ventes */
  await fonction(f, { id: 'treso-prevision-ventes', titre: 'Taper sa prévision de ventes',
    resume: 'Une case ≈ part du budget validé : on tape sa prévision par-dessus. Vidée, la case revient au budget.',
    points: ['Une case ≈ des encaissements', 'Taper sa prévision', 'Entrée : le cash suit'] });
  await lignes(f, await ligne(f, 'Encaissements (ventes)'), 'Encaissements');
  await f.dire('Les semaines à venir partent du budget validé, marquées ≈. La semaine du 21 décembre, on attend plus.', 5000);
  await f.cacher(150); await f.taire(150);
  await saisir(f, 's:2026-12-21', '19500');
  await f.montrer(T + '[data-trn="s:2026-12-21"]', 'Prévision tapée');
  await f.dire('La case perd son ≈ : c’est <b>ta prévision</b>. Vider la case la ramène au budget.', 4400);
  await lignes(f, await ligne(f, 'Marchandises (fournisseurs)'), 'Les achats suivent');
  await f.dire('Les achats estimés en % des ventes suivent : <b>6 115 €</b> cette semaine-là.', 4200);
  await lignes(f, await ligne(f, 'Solde de la semaine', 'Cash fin de semaine (prévu)'), 'Le cash suit');
  await f.dire('Le solde de la semaine et le <b>cash fin de semaine</b> suivent, et toutes les semaines après.', 4600);
  await f.taire(150); await f.cacher(300);

  /* Un montant pour une seule semaine */
  await fonction(f, { id: 'treso-montant-semaine', titre: 'Remplacer un montant pour une seule semaine',
    resume: 'Double-clic sur une case calculée : le vrai montant de cette semaine-là. Vidée, la case revient au calcul.',
    points: ['Double-clic sur la case', 'Taper le montant', 'Entrée : la case est « saisie »'] });
  await lignes(f, await ligne(f, 'Gérant'), 'Gérant');
  await f.dire('Le gérant : calculé depuis l’onglet Paiements, <b>4 800 €</b> la première semaine du mois.', 4400);
  await f.dire('Quand le cash est court, c’est la première ligne qui s’ajuste : en décembre, il se verse moins.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.survoler(T + '[data-trdbl="f:Gérant:2026-12-07"]', { pause: 300 });
  await f.p.mouse.dblclick(f.souris.x, f.souris.y);
  await f.pause(500);
  await f.taper('3000', 120);
  await f.p.keyboard.press('Enter');
  await f.aller(f.souris.x + 34, f.souris.y + 40);
  await f.pause(1300);
  await f.montrer(T + '[data-trdbl="f:Gérant:2026-12-07"]', 'Saisi pour cette semaine');
  await f.dire('<b>Double-clic</b>, 3 000, Entrée : la case est marquée « saisie », pour cette semaine seulement.', 5000);
  await lignes(f, await ligne(f, 'Gérant'), 'Les autres mois');
  await f.dire('Janvier garde le calcul. Vider la case la ramène au calcul.', 3600);
  await f.taire(150); await f.cacher(300);

  /* Un paiement ponctuel */
  await fonction(f, { id: 'treso-ponctuel', titre: 'Saisir un paiement ponctuel',
    resume: 'Ce qui n’est pas récurrent — réparation, prime, régularisation — se tape dans la semaine où l’argent sort.',
    points: ['La ligne « Ponctuels / imprévus »', 'La semaine où l’argent sort', 'Taper le montant'] });
  await lignes(f, await ligne(f, 'Ponctuels / imprévus'), 'Ponctuels / imprévus');
  await f.dire('Tout ce qui n’est pas récurrent : réparation, prime de fin d’année, pécule de vacances, régularisation d’énergie.', 5400);
  await f.dire('Une réparation du four, payée la semaine du 30 novembre : <b>1 350 €</b>.', 3600);
  await f.cacher(150); await f.taire(150);
  await saisir(f, 'p:2026-11-30', '1350');
  await lignes(f, await ligne(f, 'Ponctuels / imprévus', 'Total décaissements', 'Solde de la semaine', 'Cash fin de semaine (prévu)'), 'Le cash suit');
  await f.dire('Le total des décaissements et le <b>cash fin de semaine</b> en tiennent compte.', 4400);
  await f.taire(150); await f.cacher(300);

  /* Le pilotage */
  await fonction(f, { id: 'treso-pilotage', titre: 'Régler le cash de départ et le coussin',
    resume: 'Le cash réel de départ, lu dans la banque, et le coussin minimum : le graphique et les semaines sous le coussin suivent.',
    points: ['La carte « Pilotage »', 'Le cash réel de départ', 'Le coussin de sécurité minimum'] });
  await f.montrer(PILOTAGE, 'Pilotage');
  await f.dire('Le <b>cash réel de départ</b> : le solde du compte le dimanche avant le premier lundi. Tout le plan part de lui.', 5400);
  await f.cacher(150); await f.taire(150);
  await saisir(f, 'start', '11580');
  await f.dire('Un chiffre mal recopié se corrige ici : la première semaine repart du bon solde.', 4000);
  await f.taire(150);
  await f.montrer(T + '.tr-param:has([data-trn="coussin"])', 'Le coussin');
  await f.dire('Le <b>coussin</b> : le minimum à garder sur le compte. On le monte de 5 000 à 9 000 €.', 4600);
  await f.cacher(150); await f.taire(150);
  await saisir(f, 'coussin', '9000', { apres: 1000 });
  await f.montrer(GRAPHE, 'Coussin 9 000 €');
  await f.dire('La ligne pointillée du coussin monte : les semaines en dessous changent de couleur.', 4600);
  await f.montrer(COUSSIN, 'Semaines sous le coussin');
  await f.dire('Le résumé compte maintenant <b>5 semaines</b> sous le coussin.', 3600);
  await f.taire(150); await f.cacher(300);

  /* Les dettes hors plan */
  await fonction(f, { id: 'treso-dettes-hors-plan', titre: 'Gérer les dettes hors plan',
    resume: 'Ce qu’on doit sans que ce soit dans le plan : ajouter une dette, la corriger, la supprimer.',
    points: ['« + Ajouter une dette »', 'Libellé, remarque, montant', '× supprime la ligne'] });
  await f.montrer(HORS, 'Hors plan, à arbitrer');
  await f.dire('Ce qu’on doit sans que ce soit <b>dans le plan</b> : tant que ce n’est pas un échéancier, le cash prévu est trop beau.', 5400);
  await f.cacher(150); await f.taire(150);
  await f.dire('« <b>+ Ajouter une dette</b> » : son libellé, une remarque, le montant.', 3600);
  await f.cliquer(T + '[data-tra="addH"]', { apres: 800 });
  const neuve = T + '.tr-hors-l:last-child';
  await ecrire(f, neuve + ' input.tr-txt:not(.tr-txt-mu)', 'Facture du chauffagiste');
  await ecrire(f, neuve + ' input.tr-txt-mu', 'Payable en deux fois');
  await saisir(f, (await f.p.locator(neuve + ' input.tr-in').getAttribute('data-trn')), '980', { apres: 900 });
  await f.taire(150);
  await saisir(f, (await f.p.locator(T + '.tr-hors-l:nth-child(2) input.tr-in').getAttribute('data-trn')), '2400', { apres: 900 });
  await f.dire('Les arriérés donnés par le comptable : <b>2 400 €</b>.', 3200);
  await f.taire(150);
  await f.cliquer(T + '.tr-hors-l:first-child [data-tra^="delH:"]', { apres: 1100 });
  await f.dire('Une dette réglée sort de la liste : <b>×</b>.', 3000);
  await f.montrer(HORS, 'Le total suit');
  await f.dire('Le total, en haut de la carte, suit : <b>3 380 €</b> à transformer en échéancier.', 4400);
  await f.taire(150); await f.cacher(300);

  /* Réessayer ; reprendre le plan enregistré */
  await poster(f.p, '/_film/tresorerie', { echecs: 1 });   // le prochain enregistrement échoue (connexion coupée)
  await fonction(f, { id: 'treso-reessayer', titre: 'Réessayer une saisie non enregistrée',
    resume: 'Une saisie qui n’a pas pu partir le dit : « Réessayer ». Le plan changé sur un autre écran : « Reprendre le plan enregistré ».',
    points: ['Le bandeau rouge : « Réessayer »', 'Un autre écran : « Reprendre le plan enregistré »', 'Retaper sa saisie'] });
  await saisir(f, 's:2026-11-23', '15000', { apres: 1500 });
  await f.defiler(-4000);
  await f.montrer(T + '[data-tretat] .db-err', 'Pas enregistrée');
  await f.dire('La connexion a lâché : « Ta dernière saisie n’est <b>pas enregistrée</b> ». Elle reste à l’écran.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(T + '[data-tra="reessayer"]', { apres: 1300 });
  await f.dire('« <b>Réessayer</b> » la renvoie : le bandeau disparaît, le plan est enregistré.', 4200);
  await f.dire('Autre cas : le consultant voit le même plan, et le modifie au même moment sur son écran.', 4600);
  await modifierPlan(f.p, d => { d.coussin = 6000; });   // l'autre écran : le coussin passe à 6 000 €
  await f.taire(150);
  await saisir(f, 's:2026-11-30', '16000', { apres: 1500 });
  await f.defiler(-4000);
  await f.montrer(T + '.tr > .db-err', 'Un autre écran');
  await f.dire('La saisie suivante est prévenue au lieu d’<b>écraser</b> la sienne : elle n’est pas enregistrée.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(T + '[data-tra="reprendre"]', { apres: 1300 });
  await f.montrer(COUSSIN, 'Coussin : 6 000 €');
  await f.dire('« <b>Reprendre le plan enregistré</b> » : le plan revient tel que le consultant l’a laissé. On y retape sa saisie.', 5600);
  await f.taire(150); await f.cacher(300);

  /* Un plan neuf */
  await fonctionPreparee(f, { id: 'treso-plan-neuf', titre: 'Démarrer un plan neuf',
    resume: 'Un magasin sans plan : il est proposé d’après le budget. On tape le cash réel de départ, puis ses paiements réels.',
    points: ['Lire le bandeau « Plan proposé »', 'Taper le cash réel de départ', 'Remplacer les paiements proposés'] }, async () => {
    await poster(f.p, '/_film/tresorerie', { neuf: true });
    await f.p.evaluate(() => { window.DashTresorerie.relire(); });
    await f.p.waitForSelector(T + '.tr-bandeau', { timeout: 3000 });
    await f.p.evaluate(() => window.scrollTo(0, 0));
    await attendre(300);
  });
  await f.montrer(T + '.tr-bandeau', 'Plan proposé');
  await f.dire('Un magasin sans plan : il est <b>proposé</b> d’après le budget validé. Rien n’est enregistré tant qu’on ne modifie rien.', 6000);
  await f.montrer(ROUTINE, 'Cash réel de départ');
  await f.dire('D’abord le <b>cash réel de départ</b> : le solde du dimanche avant le premier lundi. Vide, le plan part de 0 €.', 5400);
  await f.cacher(150); await f.taire(150);
  await saisir(f, 'start#dim', '9200', { apres: 1600 });
  await f.montrer(ROUTINE, 'Enregistré');
  await f.dire('Le plan est enregistré : le bandeau « Plan proposé » disparaît.', 3600);
  await f.cacher(150); await f.taire(150);
  await onglet(f, 'paiements');
  await f.montrer(T + '.tr-pay', 'Paiements proposés');
  await f.dire('Les <b>paiements proposés</b> viennent des postes du budget : un taux du CA, traduit en euros par mois.', 5200);
  await f.cacher(150); await f.taire(150);
  const loyer = await f.p.locator(T + '.tr-pay-l:has(input[value="Loyer et charges"]) input.tr-in').getAttribute('data-trn');
  await saisir(f, loyer, '4300');
  await f.montrer(T + '.tr-pay-l:has(input[value="Loyer et charges"])', 'Le montant réel');
  await f.dire('On les remplace par les <b>montants réels</b> : le loyer du bail, 4 300 €. TVA et ONSS s’encodent avec le comptable.', 5800);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
