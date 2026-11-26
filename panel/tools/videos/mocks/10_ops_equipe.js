/* Opérationnel › L'équipe du jour : « heure par heure » sous une personne (opEquipe dans dashboard.js), pour la vidéo
 * 01-08-equipe. /exploitation/equipe-heures rend, pour chaque vendeuse, ses ventes de chaque heure :
 * { personnes: { nom: { h7: [ca, tickets, articles, tickets aux articles lus] } }, lu, aLire }.
 * Le partage est celui de lib/jour.js (vendeursDe : le CA de chaque heure entre les vendeuses présentes, mêmes graines),
 * pour que les cases d'une personne fassent son total de la colonne « Vendu ». Données fictives.
 */
const D = require('./lib/demo');
const { r2 } = D;

function equipeHeures(s) {
  const J = D.journee(s, s === D.AUJ ? D.MAINTENANT : null), planning = D.planningDe(s);
  const vend = p => p.postes.some(x => /Comptoir|Responsable/.test(x)) || p.postes.includes('Snacking');
  const personnes = {};
  J.heures.forEach(x => {
    const en = planning.filter(p => vend(p) && D.toMin(p.debut) < (x.h + 1) * 60 && D.toMin(p.fin) > x.h * 60 && !(p.postes.includes('Snacking') && (x.h < 11 || x.h > 13)));
    const poids = en.map(p => (p.postes.includes('Responsable') ? 0.6 : 1) * D.bruit('vd' + p.nom + x.h, 0.25)), tot = poids.reduce((a, v) => a + v, 0);
    en.forEach((p, i) => {
      const f = poids[i] / tot, t = Math.max(1, Math.round(x.tickets * f));
      (personnes[p.nom] = personnes[p.nom] || {})['h' + x.h] = [r2(x.ca * f), t, Math.round(t * 2.3), t];
    });
  });
  return { shop: +D.SHOP, date: s, lu: s === D.AUJ ? '15:38' : '23:50', aLire: 0, personnes };
}

module.exports = function (m, p, q) {
  if (m === 'GET' && p === '/exploitation/equipe-heures') { return equipeHeures(q.get('date') || D.AUJ); }
  return undefined;
};
