/* Mois › « La fenêtre Invendus et poubelle », la vidéo de l'onglet (26/11/2026) :
 *   onglet  mois-invendus   la puce « Invendus » du mois (les chiffres de tête, le détail par produit), puis la fenêtre :
 *                           les pièces jetées du mois regroupées par jour, une saisie Qualité ou Casse et ses deux
 *                           gestes, le total par opérateur.
 * Réclamer au fournisseur et la remarque à l'opérateur : le même code qu'en Semaine, tournés une fois (06-05,
 * semaine-invendus-reclamer et semaine-invendus-remarque) : pas de chapitre de fonction ici. Données fictives :
 * mocks/15_chiffres_periode.js. Chaque phrase vient de panel/public/dashboard/dashboard.js (invCarte, invModaleRendre) et
 * de consultant/src/invendus.php. */
const { ouvrir, enHautDe, dire, rien } = require('./chiffres');
const B = require('./barre');

const INV = '#dash [data-qbsec=invendus]';
const M = '#db-invm';
const QUAL = M + ' tr:has(.db-inv-tag.qual):has-text("Éclair au chocolat")';
const defilerA = (f, sel, marge) => f.p.locator(sel).last().evaluate((e, mg) => { const m = e.closest('.fi-modale'); const de = m.scrollTop, a = de + e.getBoundingClientRect().top - m.getBoundingClientRect().top - mg;
  return new Promise(r => { let i = 0; const n = 40; const pas = () => { i++; const t = i / n, x = t < .5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; m.scrollTop = de + (a - de) * x; if (i < n) { requestAnimationFrame(pas); } else { r(); } }; pas(); }); }, marge);

module.exports = async function () {
  const f = await ouvrir('07-06-invendus', 'mois', { puces: ['heures'], sources: ['consultant/src/invendus.php'] });

  await f.onglet({
    id: 'mois-invendus', titre: 'Invendus et poubelle du mois',
    resume: 'Les pièces jetées du mois : leur coût retranché du résultat, le détail par produit, puis la fenêtre des saisies, regroupées par jour, avec l’heure, l’opérateur et le motif.',
    accroche: 'Un mois de poubelle',
    programme: ['Les chiffres du mois', 'Par produit', 'Les saisies, jour par jour'],
  });

  await enHautDe(f, '#dash .qb-plus', 380);
  await f.cliquer('#dash [data-qbplus=invendus]', { apres: 1600 });
  await enHautDe(f, INV, 20);
  await dire(f, INV + ' .db-inv-kpi', 'Le mois', 'La puce « <b>Invendus</b> » du mois : les pièces jetées, leur coût de production, la vente perdue, les motifs.', 6000);
  await rien(f);
  await f.cliquer(INV + ' [data-invdrop]', { dx: 0.85, apres: 1300 });
  await dire(f, INV + ' .db-pro-corps', 'Par produit', '« voir le détail ▾ » : les produits jetés du mois, du plus coûteux au moins coûteux.', 4800);
  await rien(f);
  await enHautDe(f, INV, 20);
  await f.cliquer(INV + ' [data-invmodale]', { apres: 1800 });

  await B.cadre(f, M + ' .fi-chips', 'Le mois');
  await f.dire('La fenêtre, la même qu’en Semaine : ici les saisies du mois.', 3800);
  await B.cadre(f, M + ' .im-tab', 'Jour par jour');
  await f.dire('Les pièces jetées sont <b>regroupées par jour</b> : l’heure, l’opérateur, le produit, la quantité, le motif.', 5800);
  await f.cacher(150); await f.taire(150);
  await defilerA(f, QUAL, 300);
  await f.pause(500);
  await B.cadre(f, QUAL, 'Qualité');
  await f.dire('Une saisie « <b>Qualité</b> » ou « <b>Casse</b> » : réclamer au fournisseur, ou une remarque à l’opérateur, comme en Semaine.', 6200);
  await f.cacher(150); await f.taire(150);
  await defilerA(f, M + ' .im-ops', 300);
  await f.pause(500);
  // La ligne « Par opérateur » est tout en bas de la fenêtre : le sous-titre la couvrirait, l'étiquette suffit.
  await B.cadre(f, M + ' .im-ops', 'Le total du mois par opérateur', 3000);
  await f.cacher(150);
  await f.cliquer(M + ' .fi-x', { apres: 1200 });
  await rien(f);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
