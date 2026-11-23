/* L'accès, le magasin, le réseau : ce que toutes les vues lisent. Magasin de démo, données fictives. */
const D = require('./lib/demo');
const { r2 } = D;
/** /ventes/mensuel : 29 mois clos (le mois en cours exclu), CA et food cost mesuré. */
function mensuel() {
  const L = [];
  const d = new Date(2024, 5, 1);   // ouverture : juin 2024
  while (d < new Date(2026, 10, 1)) {
    const mois = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
    const n = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
    let ca = 0; for (let j = 1; j <= n; j++) { ca += D.caJourPlein(mois + '-' + String(j).padStart(2, '0')); }
    if (mois === '2024-06') { ca *= 0.55; }
    L.push({ mois, ca: r2(ca), fc: r2(31.5 + D.alea('fc' + mois)() * 3), jours: n });
    d.setMonth(d.getMonth() + 1);
  }
  return { shop: D.SHOP, ouverture: '2024-06-14', mois: L };
}
module.exports = function (m, p, q) {
  if (p === '/acces/moi') return { connecte: true, role: 'franchise', nom: D.NOM, shops: [D.SHOP] };
  if (m !== 'GET') return undefined;
  if (p === '/stores') return [{ id: 4, nom: D.NOM, status: 'ouvert' }];
  if (p === '/meta') return { seuils: { food: 32, labour: 17, overhead: 13.5, royalties: 3, financieres: 2.2 } };
  if (p === '/marketing/avis') return { cible: 4.5, magasins: [{ id: '4', note: 4.6, avis: 318 }] };
  if (p === '/soir/revue') return { heure: '18:00', revue: null };
  // Servis par leurs modules : /coffre/magasin (23_coffre.js), /formation/continue/magasin (72_formation.js),
  // /assistant/magasin/questions (71_assistant.js), /dashboard/boite (70_boite.js : fermée au départ, s'ouvre par son
  // mot de passe ; un scénario qui a besoin d'une boîte ouverte l'ouvre avant de filmer, voir 24-parametres.js).
  if (p === '/helpdesk/magasin') return { demandes: [] };
  if (p === '/ventes/mensuel') return mensuel();
  // /dashboard/boite (fermée tant qu'on ne l'ouvre pas), /assistant/magasin/questions et /formation/continue/magasin :
  // dans 70_boite.js, 71_assistant.js et 72_formation.js, avec la forme complète que lisent toutes les vues.
  return undefined;
};
