/* La vue Opérationnel : la journée du magasin de démo (jeudi 19/11/2026, 15 h 40). */
const J = require('./lib/jour');
const D = require('./lib/demo');
module.exports = function (m, p, q) {
  if (m !== 'GET') { return undefined; }
  if (p === '/exploitation/jour') { return J.exploitationJour(q.get('date') || D.AUJ); }
  if (p === '/ventes/stats' && q.get('vue') === 'jour') { return J.ventesStatsJour(q.get('date') || D.AUJ); }
  if (p === '/production/flux/suivi') { return J.suiviProduction(q.get('date') || D.AUJ); }
  if (p === '/exploitation/canaux') { return J.canaux(q.get('date') || D.AUJ); }
  if (p === '/analyse/produits/magasin') { return require('./lib/fiche').fiche(q.get('pid'), +(q.get('mois') || 1)); }
  if (p === '/analyse/produits/recette') { return require('./lib/fiche').recette(q.get('pid')); }
  if (p === '/exploitation/invendus/detail') { return require('./lib/fiche').invendusDetail(q.get('date') || D.AUJ); }
  if (p === '/exploitation/pro/detail') { return J.proDetail(q.get('date') || D.AUJ); }
  if (p === '/exploitation/commandes/detail') { return J.commandesDetail(q.get('date') || D.AUJ); }
  return undefined;
};
/* Les lectures secondaires de la journée : des réponses vides mais bien formées (rien à signaler). */
const vides = {
  '/ventes/commandes': () => ({ commandes: { lignes: [], retard: 0 }, livraisons: { lignes: [] } }),
  '/exploitation/invendus': () => ({ declare: true, cout: 31, pieces: 35, lignes: [] }),
  '/pwa/tasks/nc': () => ({ nc: [], notees: 7 }),
  '/exploitation/promos': () => ({ promos: [] }),
};
const principal = module.exports;
const T = require('./lib/taches');
module.exports = function (m, p, q, c) {
  const r = principal(m, p, q, c);
  if (m === 'GET' && p === '/pwa/tasks') { return T.taches(q.get('date') || D.AUJ); }
  if (m === 'GET' && p === '/pwa/tasks/photos') { return T.photos(q.get('date') || D.AUJ); }
  if (m === 'GET' && p.startsWith('/__photo/')) { const svg = T.illustration(p.slice(9)); return svg ? { __body: svg, type: 'image/svg+xml' } : undefined; }
  if (r !== undefined) { return r; }
  if (m === 'GET' && vides[p]) { return vides[p](); }
  return undefined;
};
