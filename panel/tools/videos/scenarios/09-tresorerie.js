/* Vidéo 09 — « Le plan de trésorerie » (mode d'emploi, la page Trésorerie du rail, section « Les chiffres »).
 * Environ 3 min. Ce qui est dit vient de panel/public/dashboard/tresorerie.js (rendPlan, rendFournisseurs,
 * rendPaiements, rendBudget, rendGuide : le mode d'emploi du module) et de panel/src/tresorerie.php.
 * Le plan de démo (mocks/09_tresorerie.js) part du 2 novembre ; le solde du dimanche 15 n'est pas saisi : on le tape. */
const { Film } = require('../film');
const { ligne } = require('./tresorerie');

/** Un cadre autour de lignes du plan, coupé au bord de leur tableau (les lignes sont plus larges que la carte, qui défile
 * de côté) : l'écran défile d'abord jusqu'à la première. */
async function lignes(f, sel, etiquette) {
  await f.cacher(150);
  await f.defilerVers(f.p.locator(sel).first());
  const r = await f.p.evaluate(sel => {
    const L = [...document.querySelectorAll(sel)];
    if (!L.length) { return null; }
    const c = L[0].closest('.tr-defil').getBoundingClientRect(), R = L.map(e => e.getBoundingClientRect());
    const x = Math.max(c.left, Math.min(...R.map(r => r.left))), x2 = Math.min(c.right, Math.max(...R.map(r => r.right)));
    const y = Math.min(...R.map(r => r.top)), y2 = Math.max(...R.map(r => r.bottom));
    return { x, y, w: x2 - x, h: y2 - y };
  }, sel);
  if (!r) { throw new Error('rien pour ' + sel); }
  await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [r, etiquette]);
}

module.exports = async function () {
  const f = await Film.ouvrir('09-tresorerie', { vue: 'tresorerie', serie: 'Mode d’emploi', titreSerie: 'Le plan de trésorerie', duree: 'env. 3 min', chargement: 3500 });
  await f.couverture({
    kicker: 'Mode d’emploi · Les chiffres', titre: 'Le plan de<br>trésorerie', accroche: 'Payer tout le monde, semaine après semaine',
    programme: ['La routine du dimanche soir', 'Le plan, semaine par semaine', 'Fournisseurs et paiements', 'Le budget et le mode d’emploi'],
  });

  await f.chapitre({ n: 1, total: 4, titre: 'La routine du dimanche soir', texte: 'Une seule question : est-ce que vous pouvez payer tout le monde dans les semaines qui viennent ?' });
  await f.etape('1 · La routine du dimanche');
  const routine = '#db-tresorerie .tr-g2 > .tr-carte:nth-child(1)';
  await f.montrer(routine, 'Routine du dimanche');
  await f.dire('Chaque dimanche soir, on ouvre sa banque et on tape le <b>solde réel</b> du compte. Le vrai chiffre, pas une estimation.', 5200);
  await f.dire('Le dimanche 15 n’est pas encore saisi : un <b>dimanche oublié</b>. Le plan prévoyait 9 438 €.', 4800);
  await f.cliquer('#db-tresorerie [data-trn="r:2026-11-09#dim"]', { apres: 700 });
  await f.taper('8790', 110);
  await f.p.keyboard.press('Enter');
  await f.pause(1400);
  await f.montrer(routine, 'L’écart');
  await f.dire('Juste à côté, l’<b>écart</b> entre le réel et le prévu : − 648 €.', 4000);
  await f.dire('Plus de <b>500 €</b> : on en trouve la cause avant lundi. Un écart qu’on n’explique pas revient toujours, en plus gros.', 6000);
  await f.montrer('#db-tresorerie .trs-lev', 'La décision');
  await f.dire('Puis la <b>décision</b> : le cash prévu des quatre semaines à venir. Ici, il reste au-dessus du coussin.', 5200);
  await f.dire('Or : sous le coussin. Rouge : on ne peut pas tout payer. On décide quoi décaler et on prévient avant l’échéance.', 5800);
  await f.cacher();

  await f.chapitre({ n: 2, total: 4, titre: 'Le plan, semaine par semaine', texte: 'Ce qui entre, ce qui sort, et le cash de chaque fin de semaine, en € TTC.' });
  await f.etape('2 · Le plan');
  await f.montrerTout('#db-tresorerie .trs-g, #db-tresorerie .trs-meta', 'Les repères');
  await f.dire('Les repères : le <b>point bas</b> du cash, les semaines sous le <b>coussin</b>, les ventes prévues, marchandises et personnel en % des ventes.', 6000);
  await f.cacher(150);
  await f.montrer('#db-tresorerie .tr-carte:has(.tr-graph)', 'Cash fin de semaine');
  await f.dire('Le cash de chaque fin de semaine : vert au-dessus du coussin, <b>or</b> en dessous, rouge à découvert. En janvier, il passe sous le coussin.', 6600);
  await lignes(f, await ligne(f, 'Encaissements (ventes)'), 'Les ventes');
  await f.dire('Le plan : une colonne par lundi. Une semaine close prend les <b>ventes réelles</b> de la caisse ; les autres partent du budget, marquées ≈.', 6400);
  await f.dire('On tape sa prévision par-dessus.', 2400);
  await lignes(f, await ligne(f, 'Marchandises (fournisseurs)'), 'Les calculs');
  await f.dire('Marchandises, gérant, personnel, frais : calculés depuis les onglets. <b>Double-clic</b> sur une case : le montant de cette semaine seulement.', 6400);
  // Les trois dernières lignes du plan : cash fin de semaine (prévu), cash réel en banque (dimanche), écart réel − prévu.
  await lignes(f, '#db-tresorerie .tr-carte-t .tr-grille > .tr-r:nth-last-child(-n+3)', 'Le bas du plan');
  await f.dire('En bas, la ligne qui compte : le <b>cash fin de semaine</b>, puis le réel du dimanche et l’écart.', 5200);
  await f.cacher();

  await f.chapitre({ n: 3, total: 4, titre: 'Fournisseurs et paiements', texte: 'Ce que vous payez à chaque fournisseur, et les paiements qui reviennent.' });
  await f.etape('3 · Fournisseurs et paiements');
  await f.cliquer('#db-tresorerie [data-tra="onglet:fournisseurs"]', { apres: 1400 });
  await f.montrer('#db-tresorerie .tr-carte-t', 'Les fournisseurs');
  await f.dire('Un <b>fournisseur</b> par ligne : son estimation, un % des ventes ou un fixe par semaine, jusqu’à ce qu’on tape le <b>montant réel</b>.', 6400);
  await f.cliquer('#db-tresorerie [data-trn="fa:f2:2026-11-09"]', { apres: 700 });
  await f.taper('2240', 110);
  await f.p.keyboard.press('Enter');
  await f.pause(1200);
  await lignes(f, '#db-tresorerie .tr-carte-t .tr-r:has([data-trtxt="f:f2:nom"])', 'La facture réelle');
  await f.dire('La facture de la crèmerie, la semaine du 9 : 2 240 €, pas 1 597. Voilà la cause de l’écart.', 5200);
  await f.taire(150);
  await f.cacher(200);
  await f.cliquer('#db-tresorerie [data-tra="onglet:plan"]', { apres: 1400 });
  await f.montrer(routine, 'L’écart expliqué');
  await f.dire('Retour au plan : l’écart retombe à quelques euros. <b>Écart sous contrôle</b>.', 4400);
  await f.taire(150);
  await f.cacher(200);
  await f.cliquer('#db-tresorerie [data-tra="onglet:paiements"]', { apres: 1400 });
  await f.montrer('#db-tresorerie .tr-carte-t', 'Les paiements');
  await f.dire('Les <b>paiements</b> récurrents : montant, fréquence, semaine du mois ; la <b>catégorie</b> décide la ligne du plan.', 5400);
  await f.dire('TVA et ONSS ne sont pas préremplies : on les encode avec son comptable.', 3800);
  await f.cacher();

  await f.chapitre({ n: 4, total: 4, titre: 'Le budget et le mode d’emploi', texte: 'Le budget, les frais du mois, et la routine pas à pas.' });
  await f.etape('4 · Le budget');
  await f.cliquer('#db-tresorerie [data-tra="onglet:budget"]', { apres: 1600 });
  await f.montrer('#db-tresorerie .tr-carte-t', 'Budget & étude');
  await f.dire('<b>Budget & étude</b>, en lecture seule : l’étude, le budget mois par mois, le réalisé, les charges et le réel du P&L.', 5800);
  await f.taire(150);
  await f.cacher(200);
  await f.cliquer('#db-tresorerie [data-tra="onglet:frais"]', { apres: 1600 });
  await f.montrer('#db-tresorerie .fx .ac', 'Frais');
  await f.dire('<b>Frais</b> : les frais du mois saisis au panel, et la TVA qu’ils contiennent, que le P&L retire.', 5000);
  await f.taire(150);
  await f.cacher(200);
  await f.cliquer('#db-tresorerie [data-tra="onglet:guide"]', { apres: 1400 });
  await f.montrer('#db-tresorerie .tr-guide', 'Mode d’emploi');
  await f.dire('Le <b>mode d’emploi</b> reprend la routine du dimanche et le sens de chaque couleur. Votre <b>consultant</b> voit le même plan.', 5600);
  await f.taire(200);
  await f.cacher();
  return f.fin({
    titre: 'À vous,<br>dimanche soir', accroche: 'Le vrai chiffre, chaque semaine',
    retenir: ['Le solde réel, chaque dimanche', 'Plus de 500 € d’écart : la cause', 'Les 4 semaines à venir décident', 'Les montants réels remplacent l’estimation'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
