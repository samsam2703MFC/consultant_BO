/* Les SMS des formulaires bureaux (panel/src/sms_formulaires.php), vus du franchisé : le réglage du magasin, le siège
 * qui reçoit aussi (son numéro n'est pas montré), l'essai. Données fictives.
 *   GET|POST /dashboard/sms-formulaires, POST /dashboard/sms-formulaires/essai */
const ST = { magasin: { actif: false, numero: '' }, siege: { actif: true }, aujourdhui: 0 };
const err = (s, e) => ({ __status: s, json: { error: e } });
/** smsNumero : un numéro au format international, ou null. */
function numero(brut) {
  let n = String(brut || '').replace(/[^\d+]/g, '');
  if (!n) { return null; }
  if (n.startsWith('00')) { n = '+' + n.slice(2); } else if (n.startsWith('0')) { n = '+32' + n.slice(1); } else if (!n.startsWith('+')) { n = '+' + n; }
  return /^\+\d{8,15}$/.test(n) ? n : null;
}
const etat = () => ({ fournisseur: true, magasin: Object.assign({}, ST.magasin), siege: { actif: ST.siege.actif, numero: null }, siegeModifiable: false, aujourdhui: ST.aujourdhui, max: 10 });
module.exports = function (m, p, q, c) {
  if (p === '/dashboard/sms-formulaires' && m === 'GET') { return etat(); }
  if (p === '/dashboard/sms-formulaires' && m === 'POST') {
    const b = c || {}, actif = !!b.actif, t = String(b.numero || '').trim();
    if (b.siege) { return err(403, 'Le numéro du siège se règle depuis un compte du siège.'); }
    let n = '';
    if (t !== '' || actif) { n = numero(t); if (!n) { return err(422, 'Le GSM du franchisé : un numéro de GSM valide (0470 12 34 56 ou +32 470 12 34 56).'); } }
    ST.magasin = { actif, numero: n };
    return Object.assign({ ok: true }, etat());
  }
  if (p === '/dashboard/sms-formulaires/essai' && m === 'POST') {
    const dest = (ST.magasin.actif && ST.magasin.numero ? 1 : 0) + (ST.siege.actif ? 1 : 0);
    if (!dest) { return err(422, 'Aucun numéro allumé : allumez et enregistrez un GSM d’abord.'); }
    if (ST.aujourdhui >= 10) { return err(429, 'Le plafond de 10 SMS par jour est atteint pour ce magasin : réessayez demain.'); }
    ST.aujourdhui++;
    return { ok: true, envoyes: dest, echecs: 0, plafond: false };
  }
  return undefined;
};
