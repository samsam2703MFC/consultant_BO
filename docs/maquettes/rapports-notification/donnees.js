/* Les réponses d'API servies aux deux écrans pendant la capture (le cockpit et le dashboard
 * tournent tels qu'ils sont en ligne, seule l'API est simulée).
 *
 * Le magasin : Atelier by - Halle (shop 4), mardi 6 octobre 2026 à 10:45. La vitrine est le
 * suivi de production réel de ce moment-là (../suivi-duree-de-vie/reel-suivi-halle-0610.json,
 * réponse brute de /production/flux/suivi) ; l'équipe, les commandes, le stock, la veille et les
 * photos du matin sont ceux relevés le même jour (../dashboard-operationnel/reel-halle-0610.json).
 * Le chiffre d'affaires de 08:00 à 10:45 est reconstitué : le relevé s'arrête à 08:14.
 *
 * Les rapports : les quatre rapports récurrents du réseau, avec leurs blocs. Le contenu des
 * notifications (« 7 points à traiter… ») est écrit pour la maquette, dans la forme exacte du
 * résumé que rapportGenerer() produit. */
const path = require('path');
const SUIVI = require('../suivi-duree-de-vie/reel-suivi-halle-0610.json');
const R = require('../dashboard-operationnel/reel-halle-0610.json');

const DATE = '2026-10-06';
const MAINTENANT = '2026-10-06T10:45:00+02:00';

const MAGASINS = [
  { id: '2', code: 'CORBAIS', nom: 'Atelier by Berlo - Corbais', fr: 'Berlo', zone: 'Brabant wallon', status: 'Ouvert', pwaId: 2 },
  { id: '3', code: 'GOSSELIES', nom: 'Atelier by Max & Sandra - Gosselies', fr: 'Max & Sandra', zone: 'Hainaut', status: 'Ouvert', pwaId: 3 },
  { id: '4', code: 'HALLE', nom: 'Atelier by - Halle', fr: '', zone: 'Brabant flamand', status: 'Ouvert', pwaId: 4 },
  { id: '5', code: 'SOMBREFFE', nom: 'Atelier by Harmonie - Sombreffe', fr: 'Harmonie', zone: 'Namur', status: 'Ouvert', pwaId: 5 },
].map(s => Object.assign({ opened: '2024-01', valT: null, panier: null, perf: {} }, s));

/* Le Résultat du jour, pour le magasin : ce que l'onglet Opérationnel lit sur /exploitation/jour. */
const CA = 612.4, TICKETS = 71;
const jour = {
  date: DATE, estAujourdhui: true, reference: {}, reseau: { panier: 8.9 },
  magasins: [{
    shopId: '4', nom: 'Atelier by - Halle', ca: CA, tickets: TICKETS, panier: CA / TICKETS,
    objectifJour: R.ventes.objectif, objectifSource: 'budget', objectifAtteinte: CA / R.ventes.objectif,
    projection: 1690, projectionPart: 36.2, projectionRythme: 1690,
    j7: { date: '2026-09-29', moment: '10:45', ca: 655.2, tickets: 76, caJour: 1139.15, ticketsJour: 114, mb: 398 },
    margeBrute: 371.8, margeBrutePct: 60.7, coutMatiere: 240.6, refTickets: 74,
    planning: R.equipe.postes.map(p => ({ debut: p.debut, fin: p.fin, nom: null, postes: p.postes })),
    planningHeures: R.equipe.heures, planningSource: 'panel', overheadSource: 'mesure',
  }],
};

/* Les tâches du matin et leurs photos (des comptoirs, aucun visage). */
const taches = {
  seuil: 4, repartition: null,
  shops: [{ shopId: '4', taches: R.taches.map(t => Object.assign({ obligatoire: true, faitePar: null }, t, { faitLe: t.faitLe ? DATE + ' ' + t.faitLe + ':00' : null })) }],
};
const photos = {
  photos: R.photos.map(p => ({ taskId: p.taskId, photo: 'https://photos.maquette/' + path.basename(p.f), checklist: null })),
  checklists: {},
};

/* Une fenêtre observée et ses comparaisons, comme rapFenetresProposees() les résout. */
const decale = (d, ans, mois, jours) => { const t = new Date(d + 'T12:00:00Z'); t.setUTCFullYear(t.getUTCFullYear() + ans, t.getUTCMonth() + mois, t.getUTCDate() + jours); return t.toISOString().slice(0, 10); };
const fen = (du, au, label) => ({ du, au, label, jours: Math.round((new Date(au) - new Date(du)) / 864e5) + 1,
  cmp: { 'A-1': { du: decale(du, -1, 0, 0), au: decale(au, -1, 0, 0) }, 'M-1': { du: decale(du, 0, -1, 0), au: decale(au, 0, -1, 0) }, 'S-1': { du: decale(du, 0, 0, -7), au: decale(au, 0, 0, -7) } } });

/* Les rapports récurrents, tels que GET /rapports les rend. */
const BLOCS = {
  'trafic-ca': ['trafic', 'Chiffre d’affaires — période, cumul du mois, objectif'],
  'trafic-passage': ['trafic', 'Passage clients par jour — magasin, réseau, comparaison'],
  'recurrence-avis': ['recurrence', 'Réputation Google'],
  'xp-ticket': ['xp', 'Ticket moyen — magasin, réseau, comparaison'],
  'xp-bilan': ['xp', 'Bilan des tâches de la période'],
  'xp-taches': ['xp', 'Tâches sous le seuil'],
  'food-cost': ['food-cost', 'Food cost du mois'],
  'food-stock': ['food-cost', 'Stocks négatifs'],
  'labour-caetp': ['labour-cost', 'CA par ETP'],
  'rentab-heatmap': ['overhead-cost', 'Rentabilité par jour (heatmap)'],
  'kpi-table': ['transverse', 'Table KPI — les indicateurs encodés, par magasin'],
  'centrale-commandes': ['transverse', 'Commandes fournisseurs à passer'],
};
const LEVIERS = {
  trafic: { nom: 'Trafic', couleur: '#6366f1' }, recurrence: { nom: 'Récurrence', couleur: '#ec4899' },
  xp: { nom: 'Expérience Client', couleur: '#f59e0b' }, 'food-cost': { nom: 'Food Cost', couleur: '#10b981' },
  'labour-cost': { nom: 'Labour Cost', couleur: '#8b5cf6' }, 'overhead-cost': { nom: 'Overhead Cost', couleur: '#64748b' },
  transverse: { nom: 'Transverse', couleur: '#8D1D2C' },
};
const rapport = (id, code, nom, poste, frequence, heure, jour, blocs, o) => Object.assign({
  id, code, nom, poste, frequence, heure, jour, blocs, ordre: [], sauts: [], destinataires: [], parMagasin: false, actif: true,
  jours: null, envoiMode: 'groupe', destParMagasin: {}, magasins: [], periode: null, periodeDu: null, periodeAu: null, modes: {}, comparaison: 'A-1', dernier: null,
}, o);
const rapports = {
  rapports: [
    rapport(1, 'franchise-hebdo', 'Rapport franchisé — la semaine', 'Franchisé', 'hebdo', 7, 1,
      ['trafic-passage', 'xp-ticket', 'xp-bilan', 'xp-taches', 'food-stock', 'rentab-heatmap'],
      { envoiMode: 'par-magasin', jours: { dows: [1], doms: [] },
        destParMagasin: { 'Atelier by Berlo - Corbais': ['berlo@exemple.be'], 'Atelier by Max & Sandra - Gosselies': ['gosselies@exemple.be'], 'Atelier by - Halle': ['halle@exemple.be'], 'Atelier by Harmonie - Sombreffe': ['sombreffe@exemple.be'] },
        destinataires: ['consultant@exemple.be'],
        dernier: { runId: 412, le: '2026-10-05 07:00', statut: 'envoye', resume: '7 point(s) à traiter — semaine du 28/09 au 04/10/2026' } }),
    rapport(2, 'taches-jour', 'Tâches sous le seuil — la veille', 'Responsable magasin', 'quotidien', 9, 1,
      ['xp-bilan', 'xp-taches'],
      { envoiMode: 'par-magasin', jours: { dows: [1, 2, 3, 4, 5, 6], doms: [] },
        destParMagasin: { 'Atelier by - Halle': ['halle@exemple.be'] },
        dernier: { runId: 419, le: '2026-10-06 09:00', statut: 'vide', resume: '0 point(s) à traiter — le 05/10/2026' } }),
    rapport(3, 'ceo-hebdo', 'Rapport CEO — la semaine', 'CEO', 'hebdo', 7, 1,
      ['trafic-ca', 'trafic-passage', 'recurrence-avis', 'food-cost', 'labour-caetp', 'rentab-heatmap', 'kpi-table'],
      { jours: { dows: [1], doms: [] }, destinataires: ['ceo@exemple.be'],
        dernier: { runId: 411, le: '2026-10-05 07:00', statut: 'envoye', resume: '18 point(s) à traiter — semaine du 28/09 au 04/10/2026' } }),
    rapport(4, 'centrale', 'Commandes fournisseurs — chaque matin', 'Responsable achats', 'quotidien', 6, 1,
      ['centrale-commandes'],
      { jours: { dows: [1, 2, 3, 4, 5, 6], doms: [] }, destinataires: ['achats@exemple.be'],
        dernier: { runId: 418, le: '2026-10-06 06:00', statut: 'vide', resume: '0 point(s) à traiter — le 05/10/2026' } }),
  ],
  runs: [
    { runId: 419, rapport: 'Tâches sous le seuil — la veille', le: '2026-10-06 09:00', statut: 'vide', resume: '0 point(s) à traiter — le 05/10/2026' },
    { runId: 418, rapport: 'Commandes fournisseurs — chaque matin', le: '2026-10-06 06:00', statut: 'vide', resume: '0 point(s) à traiter — le 05/10/2026' },
    { runId: 412, rapport: 'Rapport franchisé — la semaine', le: '2026-10-05 07:00', statut: 'envoye', resume: '7 point(s) à traiter — semaine du 28/09 au 04/10/2026' },
    { runId: 411, rapport: 'Rapport CEO — la semaine', le: '2026-10-05 07:00', statut: 'envoye', resume: '18 point(s) à traiter — semaine du 28/09 au 04/10/2026' },
  ],
  blocs: Object.fromEntries(Object.entries(BLOCS).map(([k, [levier, nom]]) => [k, { levier, nom, famille: /^xp-(bilan|taches)/.test(k) ? 'taches' : undefined }])),
  cronUrl: 'https://cockpit.latelier.by/consulant_bo/api/cockpit/rapports/cron?jeton=••••••••',
  leviers: LEVIERS,
  comparaisons: { 'A-1': 'même période l’an dernier', 'M-1': 'même période le mois dernier', 'S-1': 'même période la semaine passée' },
  periodes: { '': 'selon la cadence d’envoi', hier: 'la journée d’hier', 'semaine-passee': 'la semaine passée (lundi → dimanche)', 'mois-en-cours': 'le mois en cours, jusqu’à aujourd’hui', 'mois-passe': 'le mois passé, entier', libre: 'deux dates au choix' },
  fenetres: {
    hier: fen('2026-10-05', '2026-10-05', 'le 05/10/2026'),
    'semaine-passee': fen('2026-09-28', '2026-10-04', 'semaine du 28/09 au 04/10/2026'),
    'mois-en-cours': fen('2026-10-01', '2026-10-05', 'octobre 2026 jusqu’au 05/10'),
    'mois-passe': fen('2026-09-01', '2026-09-30', 'septembre 2026'),
    'cadence:quotidien': fen('2026-10-05', '2026-10-05', 'le 05/10/2026'),
    'cadence:hebdo': fen('2026-09-28', '2026-10-04', 'semaine du 28/09 au 04/10/2026'),
    'cadence:mensuel': fen('2026-09-01', '2026-09-30', 'septembre 2026'),
  },
  postes: ['CEO', 'Consultant', 'Franchisé', 'Responsable magasin', 'Responsable achats'],
  annuaire: [
    { nom: 'Consultant Réseau', email: 'consultant@exemple.be', poste: 'Consultant' },
    { nom: 'Direction', email: 'ceo@exemple.be', poste: 'CEO' },
  ],
};

/* Les réponses, par chemin (sans /api/cockpit). Une fonction reçoit l'URL. */
const API = {
  '/stores': () => MAGASINS,
  '/production/flux/suivi': () => SUIVI,
  '/exploitation/jour': () => jour,
  // La frise des jours : le lundi 5 reconstitué (le relevé du 06/10 ne le porte pas), le mardi en direct.
  '/exploitation/periode': () => ({ magasins: [{ shopId: '4', jours: [{ date: '2026-10-05', ca: 1052.6, objectif: 1290 }, { date: DATE, objectif: R.ventes.objectif }] }] }),
  '/ventes/stats': () => ({ totaux: { ca: CA, tickets: TICKETS } }),
  // La valeur du magasin (30 mois de chiffre) n'est pas relevée : la pastille dira « — ».
  '/ventes/mensuel': () => ({ mois: [] }),
  '/ventes/commandes': () => ({ commandes: R.commandes }),
  '/ventes/stock': () => R.stock,
  '/pwa/tasks': () => taches,
  '/pwa/tasks/photos': () => photos,
  '/pwa/tasks/nc': () => ({ nc: [], notees: R.hier.notees }),
  '/exploitation/invendus': () => ({ declare: false }),
  '/exploitation/promos': () => ({ promos: [] }),
  '/exploitation/canaux': () => ({ liste: [{ heure: '09:30', statut: 'remis' }], demain: { n: 1, ca: 46.8 } }),
};

/* Le cockpit (l'écran Reporting) : il ne s'ouvre qu'avec /meta, et dès que /meta répond, il
 * attend toutes ses lectures de démarrage. Elles reçoivent la forme vide d'api.js
 * (emptyPayload), le reste de l'écran ne s'en sert pas. */
const META = { build: null, reseau: { nom: 'L’Atelier by', sousTitre: 'Pilotage Réseau' }, utilisateur: { initiales: 'CEO', nom: 'Direction', role: 'CEO' },
  aujourdhui: DATE, dateLabel: '', periodeLabel: '', exercice: 2026,
  moisLabels: ['Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'],
  seuils: { food: 32, labour: 33, overhead: 13.5, royalties: 3, financieres: 2.2, caEtp: 13000 },
  contribOuverture: 0, notes: {}, familles: [], reportTypes: [] };
const COCKPIT = {
  '/meta': () => META,
  '/stores': () => MAGASINS,
  '/rapports': () => rapports,
  '/referentiels/project-templates': () => ({}),
  '/reporting': () => ({ reports: [], alertRules: [] }),
  '/pwa/reports': () => ({ base: '', magasins: [], partages: [] }),
  '/pwa/tasks': () => ({ date: DATE, dates: [], shops: [], consultants: [], totals: { taches: 0, valides: 0, refuses: 0, aValider: 0, noteMoy: null }, indispo: true }),
  '/pwa/compte': () => ({ base: '', phone: '', motDePasseDefini: false, configure: false }),
  '/erp/compte': () => ({ base: '', phone: '', motDePasseDefini: false, configure: false, jetonValide: false }),
  '/exploitation': () => ({ jour: null, semaine: null, mois: null, magasins: [], reseau: {}, avertissement: null }),
  '/production/groupes': () => ({ groupes: [] }),
  '/production/categories': () => ({ categories: [] }),
  '/production/periodes': () => ({ periodes: [] }),
  '/referentiels/roles': () => ({ source: null, roles: [] }),
  '/referentiels/facebook-regles': () => ({ seuil: 4, familles: [], regles: [] }),
  '/auth/status': () => ({ setup: true, authed: true, disabled: true }),
};
/* Toute autre lecture du cockpit : une liste vide. */
const COCKPIT_DEFAUT = () => [];

module.exports = { DATE, MAINTENANT, MAGASINS, API, COCKPIT, COCKPIT_DEFAUT, rapports };
