// Les commandes fictives de la maquette (../donnees.js), au format du panel ; les bureaux en clients pro fictifs.
//   node sim.js sim.json      (puis les tournées : voir ../LISEZMOI.md)
global.window = {}; require(require('path').join(__dirname, '..', 'donnees.js'));
const D = window.MQ, fs = require('fs');
const ids = {}; let n = 9001; for (const k of Object.keys(D.BUREAUX)) { ids[k] = n++; }
const clients = Object.entries(D.BUREAUX).map(([k, b]) => ({ id: ids[k], is_b2b: 1, active: 1, company_name: b.nom, city: b.zone, zip: '6041', password_hash: 'x' }));
const statut = { livree: [3, 'picked_up'], remise: [3, 'picked_up'], prete: [2, 'completed'], prep: [5, 'in_production'], afaire: [1, 'new'], avenir: [1, 'new'] };
let cn = 1;
const commandes = D.COMMANDES.map(c => {
  const [id, st] = statut[c.statut], pro = !!c.bureau;
  const [pre, nom] = (c.client || '').replace(/^.*· /, '').split(' ');
  return { id: c.n, pick_up_datetime: '2026-11-24 ' + c.h + ':00', is_webshop: c.canal === 'webshop', fulfilment_mode: c.livrer ? 'delivery' : 'collect',
    office_offer_id: c.canal === 'bureau' ? 1 : null, total_value: c.montant, order_status: st, id_order_status: id,
    issuing_timestamp: c.fait ? '2026-11-24 ' + c.fait + ':00' : null, id_transaction: c.pay === 'caisse' && (c.statut === 'remise') ? 77000 + c.n : null,
    payment_status: c.pay === 'enligne' ? 'paid' : null, comment: c.note || (c.quoi.includes('pris') ? c.quoi.split(' · ').slice(1).join(' · ') : ''),
    non_collection_id_reason: null, completion_timestamp: null,
    id_client: pro ? ids[c.bureau] : 8000 + cn, client: pro ? { id: ids[c.bureau], is_b2b: 1, company_name: D.BUREAUX[c.bureau].nom, can_deferral: c.pay === 'facture' ? 1 : 0, payment_terms: c.pay === 'facture' ? 14 : 0 }
      : { id: 8000 + cn++, is_b2b: 0, name: ({ 'M.': 'Marc', 'S.': 'Sophie', 'L.': 'Laura', 'C.': 'Céline', 'A.': 'Anne', 'J.': 'Julie', 'F.': 'François', 'N.': 'Nathalie' })[pre] || 'Client', surname: nom || 'X' } };
});
// Demain : quelques bureaux ont déjà commandé (mercredi 25/11).
for (const [k, h] of [['veto', '11:30'], ['horizon', '11:55'], ['delvaux', '07:30'], ['renard', '08:00'], ['biolab', '08:15'], ['technisol', '11:45'], ['lambot', '12:25']]) {
  commandes.push({ id: 4600 + commandes.length, pick_up_datetime: '2026-11-25 ' + h + ':00', is_webshop: false, fulfilment_mode: 'collect', office_offer_id: 1, total_value: 60, order_status: 'new', id_order_status: 1,
    id_client: ids[k], client: { id: ids[k], is_b2b: 1, company_name: D.BUREAUX[k].nom, can_deferral: 1, payment_terms: 14 } });
}
const tournees = D.TOURNEES.map(t => ({ nom: t.nom, depart: t.depart, retour: t.retour, livreur: t.livreur, vehicule: t.vehicule, jours: '12345',
  bureaux: t.bureaux.map(k => ({ client: ids[k], nom: D.BUREAUX[k].nom, consigne: D.BUREAUX[k].lieu + ' · ' + D.BUREAUX[k].contact })) }));
fs.writeFileSync(process.argv[2], JSON.stringify({ commandes, clients, tournees }, null, 1));
console.log(commandes.length, 'commandes', clients.length, 'clients', tournees.length, 'tournées');
