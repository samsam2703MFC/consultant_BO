/* Opérationnel › « La journée en direct » (?vue=ops, le jour même), la vidéo de l'onglet (25/11/2026) :
 *   onglet  ops-journee-direct   ta place dans le réseau, la jauge du comptoir (objectif comptoir, projection, jeudi
 *                                dernier, la part de la journée écoulée), le ruban des canaux et « Commandes du jour »,
 *                                la jauge B2B et « Ventes B2B », le duel chiffre par chiffre, le P&L court, « les
 *                                saisies » et « Frais ▸ » ; la page se relit toute seule toutes les deux minutes.
 * Pas de chapitre de fonction : rien ne s'écrit ici (ouvrir les commandes d'un canal, les ventes B2B, les invendus,
 * aller aux frais : des gestes de lecture, montrés dans l'onglet). Les fenêtres elles-mêmes ont leurs vidéos (barre du
 * haut et fenêtres communes). Données fictives : mocks/10_ops.js. Chaque phrase vient de panel/public/dashboard/
 * dashboard.js (rendOps, rendBench, opDuel, opRuban, opDuelPro, opDuelTable, cascade, le setInterval de 120 000 ms).
 */
const { ouvrir, enHautDe, dire, rien } = require('./ops-onglets');

const CPT = '.op-duel:not(.b2b)';

module.exports = async function () {
  const f = await ouvrir('01-01-direct', { sources: ['shared/src/pnl_ht.php'] });

  await f.onglet({
    id: 'ops-journee-direct', titre: 'La journée en direct',
    resume: 'La journée à l’heure qu’il est : ta place dans le réseau, la jauge du comptoir face à l’objectif et à jeudi dernier, la projection, les canaux, le B2B, le duel chiffre par chiffre et le P&L court. La page se relit toute seule toutes les deux minutes.',
    accroche: 'Où en est la journée ?',
    programme: ['Ta place dans le réseau', 'La jauge du comptoir', 'Les canaux et le B2B', 'Le duel et le P&L court'],
  });

  await dire(f, '.db-frise .fr-c.on', 'Aujourd’hui', 'La journée en cours, qui se <b>relit toute seule</b> toutes les deux minutes.', 4000);
  await dire(f, '.db-bench', 'Ta place dans le réseau', 'Ta <b>place dans le réseau</b> : ton rang sur le chiffre, les clients, le panier, la marge et le B2B.', 4800);
  await rien(f);

  /* La jauge du comptoir */
  await enHautDe(f, CPT, 16);
  await f.montrerTout(`${CPT} > .dh, ${CPT} > .op-piste, ${CPT} > .op-dax`, 'Le comptoir');
  await f.dire('L’<b>objectif comptoir</b> : l’objectif du jour, moins la part B2B habituelle.', 4400);
  await dire(f, `${CPT} .seg.proj`, 'La projection', 'En Ruby, le chiffre à 15:40 ; en hachuré, la <b>projection</b> au rythme de la journée.', 5000);
  await f.montrer(f.p.locator(`${CPT} > .op-piste`).nth(1), 'Jeudi dernier');
  await f.dire('En bleu, <b>jeudi dernier</b> à la même heure, puis sa journée. Le trait : la part de la journée écoulée.', 5400);
  await rien(f);

  /* Les canaux */
  await enHautDe(f, '.op-rb', 20);
  await dire(f, '.op-rb', 'Par canal', '<b>Par canal</b> : le comptoir, les commandes prises au comptoir et le webshop, face à jeudi dernier.', 4800);
  await rien(f);
  await f.cliquer('.op-rb [data-cmdm="web"]', { apres: 1100 });
  await dire(f, '#db-cmdm .fi-modale', 'Commandes du jour', 'Un canal touché ouvre « <b>Commandes du jour</b> » sur ce canal : ici, le webshop.', 4200);
  await rien(f);
  await f.cliquer('#db-cmdm .fi-x[data-cmdx]', { apres: 700 });

  /* Le B2B */
  await enHautDe(f, '.op-duel.b2b', 20);
  await dire(f, '.op-duel.b2b', 'Le B2B', 'Le <b>B2B</b> a sa jauge : les tickets pro, face à la part B2B habituelle.', 4400);
  await rien(f);
  await f.cliquer('.op-duel.b2b .op-piste[data-b2bj]', { apres: 1100, dx: 0.3 });
  await f.dire('Un clic ouvre « <b>Ventes B2B</b> » : chaque ticket pro, et ce qu’ils ont acheté.', 4300);
  await f.taire(150);
  await f.cliquer('.fi-x[data-b2bx]', { apres: 700 });

  /* Le duel, chiffre par chiffre */
  await enHautDe(f, '.op-dt', 70);
  await dire(f, '.op-dt', 'Le duel, chiffre par chiffre', 'Le <b>duel</b> : chiffre, clients et panier, face à jeudi dernier, à toute sa journée et à la référence.', 4800);
  await rien(f);

  /* Le P&L court */
  await enHautDe(f, '.db-cascade', 40);
  await dire(f, '.db-cascade', 'Le P&L court', 'Le <b>P&L court</b> de la journée : du chiffre encaissé au résultat hors TVA.', 4000);
  await rien(f);
  await f.cliquer('[data-invmodale]', { apres: 1100, dx: 0.12 });
  await f.dire('« <b>les saisies</b> » : chaque invendu de la journée, son heure, qui l’a saisi et pourquoi.', 4600);
  await f.taire(150);
  await f.p.keyboard.press('Escape'); await f.pause(700);
  await f.montrer('.db-cl-lien[data-vue="frais"]', 'Frais');
  await f.dire('« <b>Frais ▸</b> » ouvre les frais du mois, dans la Trésorerie.', 3400);
  await rien(f);
  await f.cliquer('.db-cl-lien[data-vue="frais"]', { apres: 2000 });
  await f.cacher(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
