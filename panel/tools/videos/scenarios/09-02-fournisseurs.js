/* Trésorerie › l'onglet « Fournisseurs » (?vue=tresorerie), et ses fonctions en chapitres (24/11/2026) :
 *   onglet    treso-fournisseurs            un fournisseur par ligne, l'estimation, les montants réels, les totaux, le
 *                                           plafond ; le lien « Onglet Fournisseurs » du plan, la fenêtre de semaines
 *   fonction  treso-facture-fournisseur     le montant réel d'une facture : la cause de l'écart du dimanche
 *   fonction  treso-estimation-fournisseur  % ou € : un montant fixe par semaine
 *   fonction  treso-fournisseur-ajouter     ajouter, renommer, supprimer un fournisseur
 *   fonction  treso-plafond-achats          le plafond d'achats en % des ventes
 * Données fictives : mocks/09_tresorerie.js ; avant de filmer, le solde du dimanche 15 est saisi (8 790 €, l'écart de
 * − 648 € que la facture de la crèmerie explique) et un fournisseur arrêté est ajouté (à supprimer). Chaque phrase vient
 * de panel/public/dashboard/tresorerie.js (rendFournisseurs, rendPlan, calcul). */
const { Film } = require('../film');
const { fiabiliser, T, ROUTINE, modifierPlan, recharger, lignes, ligne, saisir, ecrire, onglet, fonction } = require('./tresorerie');

const FOURN = T + '.tr-carte-t .tr-grille > .tr-r:has(.tr-lab-f)';
const rangDe = id => T + `.tr-carte-t .tr-grille > .tr-r:has([data-trtxt="f:${id}:nom"])`;

module.exports = async function () {
  const f = await Film.ouvrir('09-02-fournisseurs', {
    vue: 'tresorerie', serie: 'Trésorerie', parent: 'tresorerie', chargement: 3500,
    sources: ['panel/public/dashboard/tresorerie.js', 'panel/src/tresorerie.php'],
    avant: async p => {
      await modifierPlan(p, d => {
        d.reel['2026-11-09'] = 8790;
        d.fournisseurs.push({ id: 'f7', nom: 'Ancien grossiste (arrêté)', mode: 'fixe', pct: 0, fixe: 0, montants: {} });
      });
      await recharger(p, 'tresorerie');
    },
  });
  fiabiliser(f);

  /* L'onglet */
  await f.onglet({
    id: 'treso-fournisseurs', titre: 'Les fournisseurs',
    resume: 'Un fournisseur par ligne : son estimation, les montants réels tapés, le total des achats en % des ventes et le plafond.',
    accroche: 'Ce que vous payez, fournisseur par fournisseur',
    programme: ['Saisir le montant réel d’une facture', 'Régler l’estimation d’un fournisseur', 'Ajouter, renommer ou supprimer un fournisseur', 'Régler le plafond d’achats'],
  });
  await lignes(f, await ligne(f, 'Marchandises (fournisseurs)'), 'Marchandises');
  await f.dire('Dans le plan, la ligne <b>Marchandises</b> vient de l’onglet Fournisseurs : le lien sous son nom y mène.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(T + '.tr-sous-l[data-tra="onglet:fournisseurs"]', { apres: 1200 });
  await f.defiler(-4000);
  await f.montrer(T + '.tr-intro', 'Fournisseurs');
  await f.dire('Le montant à <b>payer</b> à chaque fournisseur, semaine par semaine, en € TTC.', 4000);
  await lignes(f, FOURN, 'Un fournisseur par ligne');
  await f.dire('Un fournisseur par ligne, 15 au plus, et son <b>estimation</b> : un % des ventes prévues, ou un montant fixe par semaine.', 6000);
  await f.montrer(T + '[data-trn="fa:f2:2026-11-16"]', 'Une estimation');
  await f.dire('Une case ≈ : l’estimation, tant que le montant réel n’est pas tapé.', 3800);
  await f.montrer(T + '[data-trn="fa:f1:2026-11-09"]', 'Un montant réel');
  await f.dire('Le <b>montant réel</b>, dès qu’on le connaît : la meunerie, 1 240 € la semaine du 9 novembre.', 5000);
  await lignes(f, await ligne(f, 'Total achats', 'En % des ventes', 'dont montants réels saisis'), 'Les totaux');
  await f.dire('Dessous : le total des achats, sa part des ventes, ce qui est déjà réel. Le total alimente la ligne Marchandises du plan.', 6400);
  await f.montrer(T + '.tr-pied', 'Le pied');
  await f.dire('En pied : « + Ajouter un fournisseur » et le <b>plafond</b> d’achats, en % des ventes.', 4400);
  await f.montrer(T + '.tr-carte-t .tr-cbar', 'Les semaines');
  await f.dire('Les mêmes semaines que le plan : 8, 13 ou toutes ; les flèches avancent, « Cette semaine » y revient.', 5200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(T + '.tr-carte-t [data-tra="nav:1"]', { apres: 1200 });
  await f.cliquer(T + '.tr-carte-t [data-tra="nav:0"]', { apres: 900 });

  /* Le montant réel d'une facture */
  await fonction(f, { id: 'treso-facture-fournisseur', titre: 'Saisir le montant réel d’une facture',
    resume: 'La facture connue remplace l’estimation de sa semaine (0 si rien n’est payé) : la ligne Marchandises et l’écart suivent.',
    points: ['La case ≈ de la semaine', 'Taper le montant de la facture', 'Entrée : Marchandises et l’écart suivent'] });
  await onglet(f, 'plan');
  await f.montrer(ROUTINE, 'Un écart');
  await f.dire('Dimanche 15 : <b>− 648 €</b> d’écart entre le réel et le prévu. On en cherche la cause.', 4600);
  await f.cacher(150); await f.taire(150);
  await onglet(f, 'fournisseurs');
  await f.montrer(T + '[data-trn="fa:f2:2026-11-09"]', 'Estimée');
  await f.dire('La crèmerie, semaine du 9 : estimée à <b>1 597 €</b>. La facture dit autre chose.', 4200);
  await f.cacher(150); await f.taire(150);
  await saisir(f, 'fa:f2:2026-11-09', '2240');
  await lignes(f, rangDe('f2'), 'La facture réelle');
  await f.dire('2 240 €, pas 1 597 : voilà la cause. Une semaine où rien n’est payé, on tape 0.', 4800);
  await f.cacher(150); await f.taire(150);
  await onglet(f, 'plan');
  await f.montrer(ROUTINE, 'L’écart expliqué');
  await f.dire('Dans le plan, les marchandises et l’écart suivent : il retombe à quelques euros. <b>Écart sous contrôle</b>.', 5400);
  await f.taire(150); await f.cacher(300);

  /* L'estimation d'un fournisseur */
  await fonction(f, { id: 'treso-estimation-fournisseur', titre: 'Régler l’estimation d’un fournisseur',
    resume: 'Un % des ventes prévues, ou un montant fixe chaque semaine : ce qui compte tant que le montant réel n’est pas tapé.',
    points: ['% ou € sur la ligne', 'Taper le taux ou le montant par semaine', 'Les cases ≈ suivent'] });
  await onglet(f, 'fournisseurs');
  await lignes(f, rangDe('f4'), 'Fruits et légumes');
  await f.dire('Fruits et légumes : estimés à <b>3 %</b> des ventes prévues de chaque semaine.', 4000);
  await f.dire('Pour un montant fixe chaque semaine : <b>€</b> sur la ligne, puis le montant.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(T + '[data-tra="fmode:f4:fixe"]', { apres: 700 });
  await saisir(f, 'ff:f4', '180');
  await lignes(f, rangDe('f4'), '180 € par semaine');
  await f.dire('Chaque semaine sans montant tapé passe à <b>≈ 180 €</b>. En %, on tape le taux des ventes.', 4800);
  await f.taire(150); await f.cacher(300);

  /* Ajouter, renommer, supprimer */
  await fonction(f, { id: 'treso-fournisseur-ajouter', titre: 'Ajouter, renommer ou supprimer un fournisseur',
    resume: '« + Ajouter un fournisseur » (15 au plus), son nom et son estimation ; le nom se change dans sa case ; × supprime la ligne.',
    points: ['« + Ajouter un fournisseur »', 'Son nom et son estimation', '× supprime la ligne'] });
  await onglet(f, 'fournisseurs');
  await f.montrer(T + '[data-tra="addF"]', 'Ajouter');
  await f.dire('« <b>+ Ajouter un fournisseur</b> » : une ligne de plus, 15 au plus.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(T + '[data-tra="addF"]', { apres: 900 });
  const neuf = f.p.locator(FOURN).last();
  await ecrire(f, neuf.locator('input.tr-txt'), 'Glaces artisanales');
  const k = await f.p.locator(FOURN).last().locator('input.tr-in-pct').getAttribute('data-trn');
  await saisir(f, k, '2');
  await lignes(f, rangDe(k.slice(3)), 'Glaces artisanales');
  await f.dire('Son nom, puis son estimation : <b>2 %</b> des ventes. Les cases ≈ se remplissent.', 4400);
  await f.cacher(150); await f.taire(150);
  await ecrire(f, T + '[data-trtxt="f:f5:nom"]', 'Boissons et café');
  await f.dire('Un nom se change à tout moment, dans sa case.', 3000);
  await lignes(f, rangDe('f7'), 'Plus livré');
  await f.dire('Un fournisseur qu’on ne paie plus : <b>×</b> supprime sa ligne.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(T + '[data-tra="delF:f7"]', { apres: 1100 });
  await lignes(f, FOURN, 'Les fournisseurs');
  await f.dire('La ligne part ; le total des achats et la ligne Marchandises du plan suivent.', 4200);
  await f.taire(150); await f.cacher(300);

  /* Le plafond */
  await fonction(f, { id: 'treso-plafond-achats', titre: 'Régler le plafond d’achats',
    resume: 'Le % des ventes à ne pas dépasser en achats : les semaines au-dessus se colorent, ici et dans les repères du plan.',
    points: ['Le pied du tableau', 'Taper le % à ne pas dépasser', 'Les semaines au-dessus se colorent'] });
  await onglet(f, 'fournisseurs');
  await f.montrer(T + '.tr-pied label', 'Plafond');
  await f.dire('Le maximum d’achats qu’on s’autorise face aux ventes. Dépassé plusieurs semaines de suite : on commande trop, ou on jette trop.', 6400);
  await f.cacher(150); await f.taire(150);
  await saisir(f, 'plafond', '32');
  await lignes(f, await ligne(f, 'En % des ventes'), 'Au-dessus de 32 %');
  await f.dire('Plafond à <b>32 %</b> : les semaines au-dessus se colorent dans « En % des ventes ».', 4600);
  await f.cacher(150); await f.taire(150);
  await onglet(f, 'plan');
  await f.montrer(T + '.tr-kpi:nth-child(4)', 'Marchandises');
  await f.dire('Dans le plan, le repère des marchandises se compare au même plafond.', 4000);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
