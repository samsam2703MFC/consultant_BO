/* La barre du haut › « Revue du soir » (vidéos 01-18 à 01-23) : la revue du magasin de démo, jeudi 19/11/2026.
 *   GET  /soir/revue?shop=&date=[&leger=1]   la journée à passer en revue : six checklists (trois pas finies), les neuf
 *                                            points du réglage par défaut (trois vérifiés en ordre par leur sonde), les
 *                                            raisons ; ?leger=1 : l'heure et l'état seuls (la vignette) ;
 *   PUT  /soir/revue                         chaque réponse ; « cloturer » fige la journée, « rouvrir » la rouvre ;
 *   POST /__film/soir                        (le film seulement) l'état de départ : { revue: null | {par, statut, reponses} }.
 * 00_commun.js répond déjà au GET ({ revue: null }, pour toutes les autres vidéos) et passe avant ce fichier : les
 * scénarios de la revue installent ce GET sur leur page (prioritaires(page) : une route de la page passe avant celles
 * du contexte). La forme : panel/src/revue_soir.php (rsDefaut, rsRevue, rsChecklistsDuJour, rsRevueEcrire) ; les
 * tâches et les noms sont fictifs. */
const R = (t, ok) => ({ t, ok });
const POINTS = [
  { id: 'invendus', type: 'auto', icone: '🗑', titre: 'Invendus encodés', question: 'Les invendus et les pertes du jour ne sont pas encore déclarés au panel.',
    reponses: [R('Je le fais maintenant', true), R('Rien à déclarer', true), R('Oublié', false)],
    sonde: { revenue: true, etat: 'ok', texte: 'déclarés : 32 pièce(s) · 31,00 € de coût', valeur: 32, ms: 212 } },
  { id: 'stock', type: 'auto', icone: '📦', titre: 'Stock compté', question: 'Le stock n’a plus été compté depuis trop longtemps. Il est compté ce soir ?',
    reponses: [R('Compté ce soir', true), R('Prévu demain', true), R('Pas cette semaine', false)],
    sonde: { revenue: true, etat: 'ok', texte: 'dernier comptage le 17/11 à 19:20 — il y a 2 jours', valeur: 2, ms: 340 } },
  { id: 'commandes', type: 'auto', icone: '🧾', titre: 'Commandes clients clôturées', question: 'Des commandes clients passées sont encore « en cours » au panel. On les clôture ?',
    reponses: [R('Clôturées maintenant', true), R('Déjà retirées, à clôturer', false), R('À vérifier demain', false)],
    sonde: { revenue: true, etat: 'ko', texte: '1 commande passée encore « en cours » au panel', valeur: 1, ms: 188 } },
  { id: 'photos', type: 'auto', icone: '📷', titre: 'Photos rendues', question: 'Des photos de contrôle manquent. Pourquoi ?',
    reponses: [R('Pas eu le temps', false), R('Manque de personnel', false), R('Oublié', false), R('Faites, pas envoyées', false)],
    sonde: { revenue: true, etat: 'ko', texte: '4 / 5 photos rendues (80 %)', valeur: 80, ms: 260 } },
  { id: 'nc', type: 'auto', icone: '✅', titre: 'Non-conformités reprises', question: 'Une non-conformité n’a pas encore été reprise. Elle est corrigée ?',
    reponses: [R('Corrigée aujourd’hui', true), R('En cours', false), R('Pas encore', false)],
    sonde: { revenue: true, etat: 'ok', texte: '1 non-conformité(s), 1 reprise(s)', valeur: 0, ms: 230 } },
  { id: 'feedback', type: 'question', icone: '💬', titre: 'Feedback à l’équipe', question: 'Les retours du jour (photos, vitrine, ce qui manquait) ont-ils été donnés à l’équipe ?',
    reponses: [R('Oui, au briefing', true), R('Oui, un par un', true), R('Pas encore', false), R('Pas nécessaire', true)] },
  { id: 'caisse', type: 'question', icone: '💶', titre: 'Caisse clôturée', question: 'La journée est-elle clôturée dans la caisse ?',
    reponses: [R('Oui, clôturée', true), R('Pas encore', false)] },
  { id: 'fournisseurs', type: 'question', icone: '🚚', titre: 'Commande fournisseurs de demain', question: 'La commande fournisseurs de demain est-elle passée et confirmée ?',
    reponses: [R('Passée', true), R('Pas nécessaire', true), R('Pas encore', false)] },
  { id: 'demain', type: 'question', icone: '📋', titre: 'Demain est prêt', question: 'Bon de commande imprimé, commandes traiteur préparées, équipe de demain prévenue ?',
    reponses: [R('Tout est prêt', true), R('En partie', false), R('Pas encore', false)] },
];
const RAISONS = ['Fait, pas coché', 'Pas eu le temps', 'Manque de personnel', 'Oublié', 'Pas concerné aujourd’hui', 'Produit / matériel manquant'];
const PF = (tache, heure, etat) => ({ tache, heure, etat: etat || 'pasFaite' });
const CL = (code, titre, heure, faites, total, pas) => ({ nom: code + ' · ' + titre, code, titre, heure, faites, total, manquent: (pas || []).map(t => t.tache), pasFaites: pas || [] });
/** Les checklists du jour : 24 tâches obligatoires, 17 faites ; trois checklists pas finies (sept tâches). */
function checklists() {
  const liste = [
    CL('1.2.01', 'Comptoir de l’après-midi', '14:00', 2, 4, [PF('Photo du comptoir après-midi - vitrine', '14:00', 'photo'), PF('Réassort de la vitrine viennoiseries', '14:30')]),
    CL('4.1.02', 'Nettoyage du fournil', '12:30', 3, 5, [PF('Dégraissage de la hotte', '12:30'), PF('Sol du fournil lavé', '13:00')]),
    CL('4.1.03', 'Nettoyage de la salle', '15:00', 0, 3, [PF('Tables et chaises nettoyées', '15:00'), PF('Machine à café détartrée', '15:15'), PF('Poubelles de la salle vidées', '15:30')]),
  ];
  const toutes = [CL('1.1.01', 'Ouverture du comptoir', '06:45', 5, 5), liste[0], CL('2.1.01', 'Cuisson du matin', '06:30', 4, 4), CL('3.1.01', 'Mise en place snacking', '10:45', 3, 3), liste[1], liste[2]]
    .map(c => ({ nom: c.nom, code: c.code, titre: c.titre, faites: c.faites, total: c.total }));
  return { lu: true, taches: 24, faites: 17, liste, toutes, qui: ['Julie M.', 'Thomas D.', 'Camille R.', 'Lucas V.'] };
}

let revue = null;   // { par, statut: 'encours' | 'cloturee', reponses: { points, checklists, demain }, majLe, clotureLe }
const lu = () => (revue ? JSON.parse(JSON.stringify(revue)) : null);
function lire(q) {
  const shop = +(q.get('shop') || 4), date = q.get('date') || '2026-11-19';
  const r = date === '2026-11-19' ? lu() : null;
  if (q.get('leger')) { return { shop, date, heure: '18:00', revue: r }; }
  return { shop, date, heure: '18:00', raisons: RAISONS, checklists: checklists(), points: POINTS, revue: r };
}
const propre = L => { const o = {}; Object.entries(L || {}).forEach(([k, v]) => { if (v && typeof v === 'object') { o[k] = { r: String(v.r || '').slice(0, 60), c: String(v.c || '').slice(0, 300) }; } }); return o; };
function ecrire(c) {
  c = c || {};
  if (revue && revue.statut === 'cloturee' && !c.rouvrir) { return { __status: 409, json: { error: 'la revue de ce soir est déjà clôturée', revue: lu() } }; }
  const clo = !!c.cloturer;
  revue = { par: String(c.par || '').slice(0, 120) || null, statut: clo ? 'cloturee' : 'encours',
    reponses: { points: propre(c.reponses), checklists: propre(c.checklists), demain: String(c.demain || '').slice(0, 500) },
    majLe: '2026-11-19 15:42', clotureLe: clo ? '2026-11-19 15:42' : null };
  return { ok: true, revue: lu() };
}

module.exports = function (m, p, q, c) {
  if (m === 'POST' && p === '/__film/soir') { revue = c && c.revue ? Object.assign({ majLe: '2026-11-19 15:41', clotureLe: c.revue.statut === 'cloturee' ? '2026-11-19 15:41' : null }, c.revue) : null; return { ok: true, revue: lu() }; }
  if (p !== '/soir/revue') { return undefined; }
  if (m === 'PUT') { return ecrire(c); }
  if (m === 'GET') { return lire(q); }   // jamais atteint tant que 00_commun.js répond : voir prioritaires()
  return undefined;
};
/** Sur la page d'un scénario de la revue : GET /soir/revue répond d'ici (route de la page, avant celles du contexte). */
module.exports.prioritaires = async page => {
  await page.route('**/api/cockpit/soir/revue*', r => {
    const req = r.request();
    if (req.method() !== 'GET') { return r.fallback(); }
    return r.fulfill({ json: lire(new URL(req.url()).searchParams) });
  });
};
/** Toutes les réponses d'une revue faite (le départ des onglets « pour demain » et « le bilan »). */
module.exports.REPONSES = {
  checklists: { '1.2.01 · Comptoir de l’après-midi': { r: 'Manque de personnel', c: 'Camille malade, seule au comptoir de 13 h à 15 h' },
    '4.1.02 · Nettoyage du fournil': { r: 'Pas eu le temps', c: '' }, '4.1.03 · Nettoyage de la salle': { r: 'Fait, pas coché', c: '' } },
  points: { commandes: { r: 'Clôturées maintenant', c: '' }, photos: { r: 'Faites, pas envoyées', c: '' }, feedback: { r: 'Oui, au briefing', c: '' },
    caisse: { r: 'Oui, clôturée', c: '' }, fournisseurs: { r: 'Passée', c: '' }, demain: { r: 'En partie', c: 'commande traiteur de 8 h à préparer demain matin' } },
  demain: '',
};
