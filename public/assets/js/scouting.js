/* Scouting commercial — logique applicative.
 * Port fidèle de la classe Component du prototype Design Component
 * (Scouting Belgique.dc.html) : même état, mêmes calculs, mêmes libellés.
 *
 * Rendu : scouting-tpl.js (HTML string + délégation d'événements) dans un
 * élément racine PERSISTANT que le cockpit ré-attache à chaque rendu — la
 * carte Leaflet (#scout-map) n'est jamais recréée et garde sa vue, ses
 * couches et ses popups.
 * Données : boulangeries et communes OpenStreetMap (Overpass, 9 secteurs) ;
 * le cache des secteurs et toutes les saisies (notes, commentaires, zones
 * candidates, populations StatBel, hypothèses) sont persistés via l'API
 * (tables ceo_scouting_*) quand elle est joignable, et toujours en
 * localStorage (repli démo / hors ligne).
 * Notes Google : demandées au serveur (POST /scouting/notes), qui interroge
 * Places avec la clé du connecteur Google de Paramètres — la clé ne transite
 * jamais par le navigateur.
 */
import { API_BASE } from './api.js';
import * as T from './scouting-tpl.js';

const PROV = [
  { code: 'BRU', name: 'Bruxelles-Capitale', reg: 'Bruxelles' },
  { code: 'VAN', name: 'Anvers', reg: 'Flandre' },
  { code: 'VBR', name: 'Brabant flamand', reg: 'Flandre' },
  { code: 'VWV', name: 'Flandre-Occidentale', reg: 'Flandre' },
  { code: 'VOV', name: 'Flandre-Orientale', reg: 'Flandre' },
  { code: 'VLI', name: 'Limbourg', reg: 'Flandre' },
  { code: 'WBR', name: 'Brabant wallon', reg: 'Wallonie' },
  { code: 'WHT', name: 'Hainaut', reg: 'Wallonie' },
  { code: 'WLG', name: 'Liège', reg: 'Wallonie' },
  { code: 'WLX', name: 'Luxembourg', reg: 'Wallonie' },
  { code: 'WNA', name: 'Namur', reg: 'Wallonie' }
];
// Arrondissements administratifs : 2 premiers chiffres du code NIS (ref:INS)
const ARR = {
  11: 'Anvers', 12: 'Malines', 13: 'Turnhout',
  21: 'Bruxelles-Capitale', 23: 'Hal-Vilvorde', 24: 'Louvain', 25: 'Nivelles',
  31: 'Bruges', 32: 'Dixmude', 33: 'Ypres', 34: 'Courtrai', 35: 'Ostende',
  36: 'Roulers', 37: 'Tielt', 38: 'Furnes',
  41: 'Alost', 42: 'Termonde', 43: 'Eeklo', 44: 'Gand', 45: 'Audenarde', 46: 'Saint-Nicolas',
  51: 'Ath', 52: 'Charleroi', 53: 'Mons', 54: 'Mouscron', 55: 'Soignies', 56: 'Thuin', 57: 'Tournai',
  61: 'Huy', 62: 'Liège', 63: 'Verviers', 64: 'Waremme',
  71: 'Hasselt', 72: 'Maaseik', 73: 'Tongres',
  81: 'Arlon', 82: 'Bastogne', 83: 'Marche-en-Famenne', 84: 'Neufchâteau', 85: 'Virton',
  91: 'Dinant', 92: 'Namur', 93: 'Philippeville'
};
// Axes pendulaires principaux — tracés schématiques, poids = ordre de grandeur
// des navetteurs quotidiens (Statbel, déplacements domicile-travail)
const AXES = [
  { name: 'E19 Anvers – Malines – Bruxelles', w: 118000, pts: [[51.22, 4.40], [51.10, 4.44], [51.03, 4.48], [50.95, 4.45], [50.87, 4.38]] },
  { name: 'A12 Anvers – Boom – Bruxelles', w: 46000, pts: [[51.20, 4.37], [51.09, 4.36], [50.98, 4.35], [50.89, 4.34]] },
  { name: 'E40 Bruxelles – Alost – Gand', w: 96000, pts: [[50.87, 4.32], [50.92, 4.13], [50.96, 3.97], [51.01, 3.80], [51.04, 3.72]] },
  { name: 'E40 Gand – Bruges – Ostende', w: 41000, pts: [[51.05, 3.70], [51.10, 3.44], [51.19, 3.22], [51.21, 3.05], [51.23, 2.93]] },
  { name: 'E17 Anvers – Saint-Nicolas – Gand – Courtrai', w: 88000, pts: [[51.21, 4.36], [51.15, 4.14], [51.10, 3.94], [51.05, 3.75], [50.95, 3.55], [50.85, 3.32], [50.80, 3.25]] },
  { name: 'E314 Louvain – Diest – Hasselt – Genk', w: 62000, pts: [[50.88, 4.70], [50.93, 4.95], [50.98, 5.20], [50.95, 5.35], [50.96, 5.50]] },
  { name: 'E40 Bruxelles – Louvain – Liège', w: 84000, pts: [[50.87, 4.42], [50.87, 4.58], [50.87, 4.72], [50.83, 5.00], [50.78, 5.25]] },
  { name: 'E313 Anvers – Herentals – Hasselt', w: 71000, pts: [[51.19, 4.45], [51.17, 4.72], [51.11, 4.98], [51.00, 5.20], [50.94, 5.34]] },
  { name: 'E34 Anvers – Turnhout', w: 29000, pts: [[51.24, 4.47], [51.28, 4.72], [51.31, 4.94]] },
  { name: 'E403 Bruges – Roulers – Courtrai', w: 34000, pts: [[51.19, 3.23], [51.02, 3.15], [50.92, 3.16], [50.82, 3.23]] },
  { name: 'E314/N2 Hasselt – Maastricht', w: 22000, pts: [[50.93, 5.34], [50.90, 5.52], [50.86, 5.68]] },
  { name: 'E19 Bruxelles – Mons – frontière française', w: 52000, pts: [[50.84, 4.33], [50.72, 4.24], [50.60, 4.15], [50.47, 4.02], [50.45, 3.95]] },
  { name: 'E42 Mons – Charleroi – Namur – Liège', w: 74000, pts: [[50.45, 3.95], [50.42, 4.25], [50.41, 4.44], [50.46, 4.72], [50.47, 4.87], [50.55, 5.20], [50.62, 5.57]] },
  { name: 'E411 Bruxelles – Namur – Arlon', w: 68000, pts: [[50.82, 4.40], [50.71, 4.55], [50.58, 4.72], [50.47, 4.87], [50.23, 5.15], [50.00, 5.40], [49.75, 5.60], [49.68, 5.79]] },
  { name: 'E40 Liège – Verviers – Aix-la-Chapelle', w: 38000, pts: [[50.63, 5.57], [50.62, 5.78], [50.61, 5.95], [50.68, 6.05]] },
  { name: 'E25 Liège – Bastogne – Luxembourg', w: 26000, pts: [[50.62, 5.57], [50.42, 5.62], [50.16, 5.70], [50.00, 5.72], [49.85, 5.78]] },
  { name: 'E420 Charleroi – Couvin', w: 14000, pts: [[50.41, 4.44], [50.28, 4.47], [50.12, 4.50], [50.05, 4.50]] },
  { name: 'E403 Tournai – Courtrai', w: 21000, pts: [[50.61, 3.39], [50.70, 3.30], [50.79, 3.24]] }
];
// Préfixe NIS → province (les codes non listés sont hors Belgique)
const INS_PROV = {
  '11': 'VAN', '12': 'VAN', '13': 'VAN',
  '21': 'BRU', '23': 'VBR', '24': 'VBR', '25': 'WBR',
  '31': 'VWV', '32': 'VWV', '33': 'VWV', '34': 'VWV', '35': 'VWV', '36': 'VWV', '37': 'VWV', '38': 'VWV',
  '41': 'VOV', '42': 'VOV', '43': 'VOV', '44': 'VOV', '45': 'VOV', '46': 'VOV',
  '51': 'WHT', '52': 'WHT', '53': 'WHT', '54': 'WHT', '55': 'WHT', '56': 'WHT', '57': 'WHT',
  '61': 'WLG', '62': 'WLG', '63': 'WLG', '64': 'WLG',
  '71': 'VLI', '72': 'VLI', '73': 'VLI',
  '81': 'WLX', '82': 'WLX', '83': 'WLX', '84': 'WLX', '85': 'WLX',
  '91': 'WNA', '92': 'WNA', '93': 'WNA'
};
// Points de comparaison du réseau — chiffres de l'étude GeoConsulting
// (Halle, 28-08-2024, p.26 et p.28). Halle = projet mesuré à 250 m².
const RESEAU = [
  { nom: 'L\'Atelier by Max & Sandra', statut: 'En exploitation',
    pop: 6263, hh: 2613, taille: 2.4, revenu: 38454, jeunes: 19.7, actifs: 67.2, seniors: 13.2,
    marche: 1086800, depense: 416, emprise: null, ca: null, surface: null, lat: 50.46, lng: 4.44 },
  { nom: 'L\'Atelier by Berlo', statut: 'En exploitation',
    pop: 30705, hh: 13821, taille: 2.2, revenu: 48327, jeunes: 17.8, actifs: 71.1, seniors: 11.0,
    marche: 7605148, depense: 550, emprise: null, ca: null, surface: null, lat: 50.63, lng: 5.57 },
  { nom: 'L\'Atelier by Halle', statut: 'Projet mesuré · août 2024',
    pop: 28057, hh: 12164, taille: 2.31, revenu: 47566, jeunes: 16.5, actifs: 64.0, seniors: 19.49,
    marche: 7128652, depense: 586, emprise: 15.5, ca: 1296881, surface: 250, lat: 50.7256, lng: 4.2225 }
];
// Belgique découpée en 9 secteurs — requêtes Overpass plus légères, chargement
// progressif, un échec de secteur ne perd pas le reste
const TILES = [
  ['50.60,2.52,51.55,3.45', 'Flandre-Occidentale'],
  ['50.60,3.45,51.55,4.35', 'Flandre-Orientale'],
  ['50.60,4.35,51.55,5.15', 'Anvers · Brabant flamand · Bruxelles'],
  ['50.60,5.15,51.55,6.10', 'Limbourg'],
  ['50.20,2.80,50.60,4.35', 'Hainaut occidental'],
  ['49.90,4.35,50.60,5.20', 'Brabant wallon · Charleroi · Namur'],
  ['50.20,5.20,50.85,6.41', 'Liège · Verviers'],
  ['49.90,4.30,50.35,5.20', 'Dinant · Philippeville'],
  ['49.44,4.85,50.20,6.05', 'Province de Luxembourg']
];
const OVERPASS = [
  'https://overpass.kumi.systems/api/interpreter',
  'https://overpass-api.de/api/interpreter',
  'https://overpass.private.coffee/api/interpreter'
];
const HH_SIZE = 2.31;       // taille moyenne des ménages, Belgique (étude : 2,34 en Flandre)
const CHAINS = ['panos', 'paul', 'délifrance', 'delifrance', 'zucchero', 'bakkerij aernoudt', 'le pain quotidien', 'jacqmotte'];
const PARAM_KEYS = ['spend', 'emprise', 'passage', 'surface', 'empriseMax', 'compK', 'hhSize', 'minScore', 'radius', 'thresh'];
const LAYERS_CONC = { shops: true, cluster: true, excl: true, prio: false, heat: false, roads: false };
const LAYERS_PRIO = { shops: false, cluster: true, excl: false, prio: true, heat: false, roads: false };
const R_COL = { high: '#1b5e20', mid: '#c17a2a', low: '#8D1D2C', none: '#78554B' };
const LEAFLET_DIR = 'assets/vendor/leaflet/';
const LS = 'ceo_scouting';

// Info-bulles (i) : ce que chaque réglage change, et la formule de chaque champ
// calculé — les mêmes formules que evaluate(), scanPrio(), strength() et
// arrStats() ci-dessous, à tenir en phase avec elles.
const TIP_REGL = {
  minRating: 'Ne garde que les concurrents dont la note atteint ce minimum. Dès que le curseur dépasse 0, les commerces sans note sortent de la carte et des calculs.',
  minHh: 'Ne garde que les communes d\'au moins ce nombre de ménages (population ÷ taille des ménages) ; leurs commerces suivent.',
  radius: 'Rayon autour d\'un concurrent fort où aucune implantation n\'est retenue (zone rouge). C\'est aussi le rayon d\'évaluation d\'une zone : ménages, marché et concurrents y sont comptés. 2 km ≈ 15 à 20 min en voiture.',
  thresh: 'Note à partir de laquelle un concurrent est « fort » : zone rouge autour de lui, et poids × 1,5 dans la pression concurrentielle.\nSans note, il est fort si sa force OSM ≥ 0,75 − (5 − seuil) × 0,05.',
  minScore: 'Score d\'opportunité en dessous duquel une zone n\'est ni tracée sur la carte ni listée dans ceo_zones.\nscore = ménages du rayon ÷ 14 000 × 60 + emprise ÷ emprise max × 40, de 0 à 100.',
  ca: 'CA annuel TTC = ménages du rayon × dépense par ménage × emprise ÷ (1 − passage).'
};
const TIP_HYP = {
  'Dépense boulangerie / ménage (€/an)': 'Ce qu\'un ménage dépense par an en boulangerie-pâtisserie — étude GeoConsulting (Halle, 08-2024) : 586 € à Halle, 550 € à Berlo, 416 € chez Max & Sandra.\nMarché du rayon = ménages du rayon × dépense.',
  'Emprise imposée (%, 0 = calculée)': 'Part du marché du rayon captée par le point. À 0, l\'emprise est calculée depuis la concurrence (emprise max, sensibilité, pression). Une valeur > 0 s\'applique telle quelle à toutes les zones — Halle mesurée : 15,5 %.',
  'Part du passage (%)': 'Part du CA apportée par la clientèle de passage, en plus des ménages du rayon.\nCA = CA des ménages ÷ (1 − passage) ; à 15 %, CA des ménages ÷ 0,85.',
  'Surface nette cible (m²)': 'Surface de vente du projet. N\'entre que dans le rendement : €/m² = CA annuel ÷ surface (Halle : 1 296 881 € sur 250 m²).',
  'Emprise maximale du modèle (%)': 'Emprise d\'un point sans aucun concurrent dans le rayon. Chaque concurrent la fait baisser :\nemprise = emprise max ÷ (1 + sensibilité × pression), plancher 4 %.',
  'Sensibilité à la concurrence': 'Vitesse à laquelle la concurrence fait baisser l\'emprise.\nemprise = emprise max ÷ (1 + sensibilité × pression concurrentielle)\nAvec 0,22 et une emprise max de 30 % : pression 0 → 30 % ; pression 1 → 24,6 % ; pression 4,5 → 15,1 % (≈ Halle). Plus la valeur est haute, plus la même concurrence pèse.',
  'Taille moyenne des ménages': 'Personnes par ménage, pour passer de la population des communes aux ménages : ménages = population ÷ taille. Belgique 2,31 (étude : 2,34 en Flandre).'
};
const TIP_FICHE = {
  'Score d\'opportunité': 'score = ménages du rayon ÷ 14 000 × 60 + emprise ÷ emprise max × 40, borné de 0 à 100.\n14 000 ménages dans le rayon valent les 60 points de potentiel ; l\'emprise — donc la concurrence — vaut les 40 points restants.',
  'Ménages dans le rayon': 'Le disque du rayon est découpé en 26 × 26 cases ; chaque case prend la densité de ménages de la commune la plus proche (à moins de 1,9 × son rayon habité). Ménages = Σ densité × surface de case.',
  'Population communale': 'Population de la commune la plus proche du point : relation OSM, ou CSV StatBel importé. « Estimée » = déduite de la densité médiane des communes voisines.',
  'dont zone primaire': 'Ménages des cases à moins de 55 % du rayon : la clientèle la plus proche, celle qui vient sans détour.',
  'Marché boulangerie': 'Marché = ménages du rayon × dépense boulangerie par ménage (hypothèse).',
  'Dépense / ménage': 'Hypothèse du modèle : dépense annuelle d\'un ménage en boulangerie-pâtisserie.',
  'Boulangeries dans le rayon': 'Concurrents de la sélection à moins de « rayon » km du point. « Fortes » : note ≥ seuil « concurrent fort », ou force OSM élevée sans note.',
  'Pression concurrentielle': 'Σ, sur les concurrents du rayon, de : force (0 à 1) × (1 − 0,6 × distance ÷ rayon) × 1,5 si le concurrent est fort.\nUn concurrent au centre pèse toute sa force, un concurrent en bord de rayon 40 % de sa force. Force = (note − 3) ÷ 2, ou signaux OSM sans note.',
  'Emprise estimée': 'emprise = emprise max ÷ (1 + sensibilité × pression concurrentielle), entre 4 % et l\'emprise max.',
  'Emprise imposée': 'Emprise fixée dans les hypothèses : la même pour toutes les zones, la concurrence ne la modifie pas.',
  'Passage': 'Majoration par la clientèle de passage : CA = CA des ménages ÷ (1 − passage).',
  'Rendement annuel / m²': '€/m² = CA annuel ÷ surface nette cible (hypothèse).',
  'CA hebdomadaire': 'CA annuel ÷ 52.'
};
const TIP_ZONES = {
  score: TIP_FICHE['Score d\'opportunité'],
  hh: 'Ménages du rayon autour du point balayé = densité de ménages de la commune la plus proche × π × rayon².',
  n: 'Concurrents de la sélection à moins de « rayon » km du point. Une zone à moins de « rayon » km d\'un concurrent fort n\'est pas retenue (zone rouge).',
  emprise: 'emprise = emprise max ÷ (1 + sensibilité × pression), entre 4 % et l\'emprise max — ou l\'emprise imposée. Pression = Σ force × (1 − 0,6 × distance ÷ rayon).',
  ca: 'CA annuel TTC = ménages × dépense par ménage × emprise ÷ (1 − passage).',
  m2: '€/m² = CA annuel ÷ surface nette cible.'
};
const TIP_ARR = {
  communes: 'Communes OSM (admin_level 8) rattachées à l\'arrondissement.',
  pop: 'Somme des populations communales (OSM, CSV StatBel, ou estimation par la densité des voisines).',
  hh: 'Ménages = population ÷ taille moyenne des ménages, sommés sur les communes.',
  market: 'Marché boulangerie = ménages × dépense par ménage.',
  shops: 'Boulangeries et pâtisseries OSM rattachées aux communes de l\'arrondissement.',
  strong: 'Concurrents forts : note ≥ seuil « concurrent fort », ou force OSM élevée sans note.',
  dens: 'Commerces pour 10 000 habitants = commerces ÷ (population ÷ 10 000).',
  avg: 'Moyenne des notes (Google ou terrain) des commerces notés de l\'arrondissement.',
  perShop: 'Ménages par point de vente = ménages ÷ commerces : plus c\'est haut, moins l\'offre est dense.'
};
const TIP_CONC = {
  'Note / 5': 'Note Google (via la clé de Paramètres) ou note terrain saisie dans la case — la saisie prime.',
  'Source': 'Google : note lue chez Google Places · saisie : note terrain · vide : pas encore interrogé.',
  'Force': 'Force du concurrent, 0 à 100 % : (note − 3) ÷ 2 avec une note ; sinon signaux OSM : 0,40 + 0,25 enseigne ou chaîne + 0,10 site web + 0,10 horaires + 0,05 pâtisserie.'
};

const fmtInt = n => Math.round(n).toLocaleString('fr-BE');
const fmtEur = n => Math.round(n).toLocaleString('fr-BE') + ' €';
const dist = (a, b, c, d) => {
  const R = 6371, p = Math.PI / 180;
  const x = (c - a) * p, y = (d - b) * p * Math.cos((a + c) / 2 * p);
  return Math.sqrt(x * x + y * y) * R;
};
const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const medOf = a => { const s = a.slice().sort((x, y) => x - y); return s[Math.floor(s.length / 2)]; };
const pickParams = v => { const o = {}; PARAM_KEYS.forEach(k => { if (v && typeof v[k] === 'number' && isFinite(v[k])) o[k] = v[k]; }); return o; };

/* --- stockage local : cache Overpass et repli hors API ----------------------- */
const ls = {
  get(k){ try { const v = localStorage.getItem(LS + '_' + k); return v ? JSON.parse(v) : null; } catch (e) { return null; } },
  set(k, v){ try { localStorage.setItem(LS + '_' + k, JSON.stringify(v)); } catch (e) { /* stockage plein ou indisponible */ } }
};

/* --- API cockpit (routes /scouting/*, voir contrat-api.md) ------------------- */
function apiGet(path, timeoutMs){
  const ctl = new AbortController();
  const t = setTimeout(() => ctl.abort(), timeoutMs || 8000);
  return fetch(API_BASE + path, { headers: { Accept: 'application/json' }, signal: ctl.signal, credentials: 'same-origin' })
    .then(r => { clearTimeout(t); if (!r.ok) throw new Error(path + ' → HTTP ' + r.status); return r.json(); })
    .catch(e => { clearTimeout(t); throw e; });
}
function apiWrite(method, path, payload){
  return fetch(API_BASE + path, {
    method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: payload === undefined ? undefined : JSON.stringify(payload)
  }).then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .catch(e => { console.warn('[scouting] écriture ' + path + ' échouée :', e.message); return null; });
}
// POST /scouting/notes — un lot de commerces ; ici l'erreur du serveur est
// rendue lisible (422 sans clé, 502 Google) au lieu d'être avalée.
function apiNotes(rows){
  const ctl = new AbortController();
  const t = setTimeout(() => ctl.abort(), 90000);
  return fetch(API_BASE + '/scouting/notes', {
    method: 'POST', credentials: 'same-origin', signal: ctl.signal,
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ rows })
  }).then(async r => {
    clearTimeout(t);
    const j = await r.json().catch(() => null);
    if (!r.ok) throw new Error((j && j.error) || ('HTTP ' + r.status));
    return j || { rows: [] };
  }).catch(e => { clearTimeout(t); throw e; });
}

// POST /scouting/refresh/{n} — le serveur relit un secteur chez OpenStreetMap
// (une à trois minutes) et le rend ; l'erreur du serveur est rendue lisible.
function apiRefresh(i){
  const ctl = new AbortController();
  const t = setTimeout(() => ctl.abort(), 330000);
  return fetch(API_BASE + '/scouting/refresh/' + i, { method: 'POST', credentials: 'same-origin', signal: ctl.signal, headers: { Accept: 'application/json' } })
    .then(async r => {
      clearTimeout(t);
      const j = await r.json().catch(() => null);
      if (!r.ok) throw new Error((j && j.error) || ('HTTP ' + r.status));
      return j;
    }).catch(e => { clearTimeout(t); throw e; });
}

/* Leaflet est chargé à la demande (vendored, aucun CDN) : les autres écrans
 * du cockpit ne le paient pas. */
let leafletP = null;
function loadLeaflet(){
  if (window.L) return Promise.resolve();
  if (leafletP) return leafletP;
  leafletP = new Promise((res, rej) => {
    const link = document.createElement('link');
    link.rel = 'stylesheet'; link.href = LEAFLET_DIR + 'leaflet.css';
    const css = new Promise(ok => { link.onload = ok; link.onerror = ok; setTimeout(ok, 3000); });
    document.head.appendChild(link);
    const s = document.createElement('script');
    s.src = LEAFLET_DIR + 'leaflet.js'; s.async = true;
    s.onload = () => css.then(res);
    s.onerror = () => rej(new Error('Leaflet introuvable (' + LEAFLET_DIR + 'leaflet.js)'));
    document.head.appendChild(s);
  });
  return leafletP;
}

export class Scouting {
  constructor(app){
    this.app = app;
    this.state = {
      busy: true, progress: 'Initialisation…', err: null,
      bakeries: [], communes: [],
      prov: PROV.reduce((a, p) => { a[p.code] = true; return a; }, {}),
      arr: 'all', minRating: 0, minHh: 0, radius: 2, thresh: 4.5,
      layers: Object.assign({}, LAYERS_CONC),
      spend: 586, surface: 250, passage: 15, emprise: 0,   // emprise 0 = calculée depuis la concurrence
      hhSize: HH_SIZE, compK: 0.22, empriseMax: 30, minScore: 55,
      sel: null, candidates: [], compare: false, cmpA: '', cmpB: '',
      view: 'map', sortKey: 'score', sortDir: -1, q: '', stop: false, reseau: false, notes: {},
      gconf: null, enriching: false, enrichDone: 0, enrichTotal: 0, ratings: {}, pops: {}, toast: null
    };
    this._h = [];
    this._scroll = {};
    this._rev = 0;          // révision des données (communes, commerces, notes) → redessin
    this.el = document.createElement('div');
    this.el.className = 'sc-root';
    this.el.style.cssText = 'flex:1;min-height:0;display:flex;flex-direction:column;background:var(--color-bg);font-family:var(--font-ui);color:var(--color-text);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden;position:relative';
    this.el.innerHTML = '<div data-sc-part="top" style="flex:0 0 auto"></div>'
      + '<div style="flex:1;display:flex;min-height:0;position:relative">'
      + '<div data-sc-part="left" id="sc-left" class="sc-scroll" style="width:296px;flex:0 1 296px;min-width:236px;background:var(--color-surface);border-right:0.5px solid var(--color-border-tertiary);overflow-y:auto;padding:16px;box-sizing:border-box"></div>'
      + '<div style="flex:1 1 auto;position:relative;min-width:320px"><div id="scout-map" style="position:absolute;inset:0;background:#EAE4DC"></div><div data-sc-part="mapui"></div></div>'
      + '<div data-sc-part="right" id="sc-right" class="sc-scroll" style="width:336px;flex:0 1 336px;min-width:260px;background:var(--color-surface);border-left:0.5px solid var(--color-border-tertiary);overflow-y:auto;padding:16px;box-sizing:border-box"></div>'
      + '<div data-sc-part="overlays" style="display:contents"></div>'
      + '</div><div data-sc-part="modal"></div>';
    this.parts = {};
    this.el.querySelectorAll('[data-sc-part]').forEach(n => { this.parts[n.getAttribute('data-sc-part')] = n; });
    this.bindEvents();
    this.restoreLocal();
  }

  /* --- cycle de vie dans le cockpit ----------------------------------------- */
  // Appelé après chaque rendu du cockpit quand l'écran est actif : l'élément
  // racine est ré-attaché tel quel, la carte n'est pas recréée.
  mount(host){
    if (!host) return;
    if (this.el.parentNode !== host) host.appendChild(this.el);
    this.render();
    if (!this._started){ this._started = true; this.load(false); }
    this.ensureMap();
    if (this.map){ setTimeout(() => { try { this.map.invalidateSize(); } catch (e) { /* carte retirée */ } }, 0); this.scheduleRedraw(60); }
  }

  useApi(){ return !!(this.app && this.app.source === 'api'); }

  // La date du secteur le plus ancien du cache serveur : ce que l'écran montre
  // a au plus cet âge (le cron relit chaque semaine, « Recharger » tout de suite).
  osmDate(){
    const t = this._serverTiles; if (!t) return '';
    const ds = Object.keys(t).map(k => String((t[k] && t[k].fetchedAt) || '')).filter(Boolean).sort();
    if (!ds.length) return '';
    const d = ds[0].slice(0, 10).split('-');
    return d.length === 3 ? d[2] + '/' + d[1] + '/' + d[0] : ds[0];
  }

  restoreLocal(){
    const s = this.state;
    Object.assign(s, pickParams(ls.get('params')));
    s.ratings = ls.get('ratings') || {};
    s.notes = ls.get('notes') || {};
    s.candidates = ls.get('cand') || [];
    s.pops = ls.get('pops') || {};
  }

  /* --- cycle de rendu ------------------------------------------------------- */
  setState(patch){
    Object.assign(this.state, typeof patch === 'function' ? patch(this.state) : patch);
    this.render();
  }

  reg(fn){ this._h.push(fn); return this._h.length - 1; }

  render(){
    if (!this.el.isConnected) return;
    this.hideTip();
    this._h = [];
    const x = {
      A: fn => fn ? `data-sh="${this.reg(fn)}"` : '',
      C: fn => fn ? `data-sc="${this.reg(fn)}"` : '',
      I: fn => fn ? `data-si="${this.reg(fn)}"` : '',
      esc
    };
    const c = this.renderVals();
    const active = document.activeElement;
    const focusId = active && active.id && this.el.contains(active) ? active.id : null;
    const selStart = focusId && active.selectionStart != null ? active.selectionStart : null;
    this.parts.top.innerHTML = T.renderTop(c, x);
    this.parts.left.innerHTML = T.renderLeft(c, x);
    this.parts.mapui.innerHTML = T.renderMapUi(c, x);
    this.parts.right.innerHTML = T.renderRight(c, x);
    this.parts.overlays.innerHTML = T.renderOverlays(c, x);
    this.parts.modal.innerHTML = T.renderModal(c, x);
    Object.keys(this._scroll).forEach(id => { const el = this.el.querySelector('#' + id); if (el) el.scrollTop = this._scroll[id]; });
    if (focusId){
      const el = this.el.querySelector('#' + CSS.escape(focusId));
      if (el){ el.focus(); if (selStart != null && el.setSelectionRange) try { el.setSelectionRange(selStart, selStart); } catch (e) { /* type sans sélection */ } }
    }
    this.afterRender();
  }

  afterRender(){
    const s = this.state;
    this.el.classList.toggle('sc-overlay-open', !!(s.reseau || s.compare || s.view !== 'map'));
    this.saveParams();
    const fp = this.fingerprint();
    if (fp === this._fp) return;
    this._fp = fp;
    this.scheduleRedraw(30);
  }

  bindEvents(){
    const run = (attr, e) => {
      const el = e.target && e.target.closest ? e.target.closest('[' + attr + ']') : null;
      if (!el || !this.el.contains(el)) return;
      const fn = this._h[+el.getAttribute(attr)];
      if (fn) fn(e);
    };
    // Info-bulles (i) : le texte vit dans data-sc-tip ; la bulle est posée en
    // position fixe dans le document, hors des panneaux qui défilent (un ::after
    // CSS y serait rogné). Le clic la fige — écrans tactiles — et n'atteint pas
    // le bouton qui porte l'icône (en-têtes triables).
    const tipOf = e => { const el = e.target && e.target.closest ? e.target.closest('[data-sc-tip]') : null; return el && this.el.contains(el) ? el : null; };
    this.el.addEventListener('mouseover', e => { const el = tipOf(e); if (el && el !== this._tipFor) this.showTip(el); });
    this.el.addEventListener('mouseout', e => { const el = tipOf(e); if (el && !(e.relatedTarget && el.contains(e.relatedTarget)) && !this._tipPinned) this.hideTip(); });
    this.el.addEventListener('click', e => { const el = tipOf(e); if (!el) return; e.preventDefault(); e.stopImmediatePropagation(); if (this._tipFor === el && this._tipPinned) this.hideTip(); else { this.showTip(el); this._tipPinned = true; } });
    this.el.addEventListener('click', e => run('data-sh', e));
    this.el.addEventListener('change', e => run('data-sc', e));
    this.el.addEventListener('input', e => run('data-si', e));
    // positions de défilement des panneaux, restaurées après chaque rendu
    this.el.addEventListener('scroll', e => { if (e.target && e.target.id) this._scroll[e.target.id] = e.target.scrollTop; this.hideTip(); }, true);
  }

  showTip(el){
    this.hideTip();
    const tip = document.createElement('div');
    tip.className = 'sc-tip';
    tip.textContent = el.getAttribute('data-sc-tip') || '';
    document.body.appendChild(tip);
    const r = el.getBoundingClientRect(), w = tip.offsetWidth, h = tip.offsetHeight;
    const left = Math.min(window.innerWidth - w - 8, Math.max(8, r.left + r.width / 2 - w / 2));
    let top = r.top - h - 8;
    if (top < 8) top = r.bottom + 8;
    tip.style.left = left + 'px'; tip.style.top = top + 'px';
    this._tip = tip; this._tipFor = el; this._tipPinned = false;
  }

  hideTip(){ if (this._tip) this._tip.remove(); this._tip = null; this._tipFor = null; this._tipPinned = false; }

  // Curseurs : pendant le glissement on ne re-rend pas (le curseur serait
  // remplacé sous la souris) — état mis à jour, libellés patchés, carte
  // redessinée ; le rendu complet arrive au relâchement (change).
  slide(key, parse){
    return e => {
      this.state[key] = parse(e.target.value);
      const v = this.liveVals();
      this.el.querySelectorAll('[data-sc-live]').forEach(el => { const k = el.getAttribute('data-sc-live'); if (v[k] != null) el.textContent = v[k]; });
      this.scheduleRedraw(90);
      this.saveParams();
    };
  }

  notify(msg){ clearTimeout(this._tt); this.setState({ toast: msg }); this._tt = setTimeout(() => this.setState({ toast: null }), 3600); }

  // empreinte de l'état qui influence la carte
  fingerprint(){
    const s = this.state;
    return [s.bakeries.length, s.communes.length, this._rev, JSON.stringify(s.prov), s.arr, s.minRating,
      s.minHh, s.radius, s.thresh, JSON.stringify(s.layers), s.sel ? s.sel.lat + ',' + s.sel.lng : '',
      s.candidates.length, s.minScore, s.view, s.reseau ? 1 : 0,
      s.spend, s.passage, s.emprise, s.empriseMax, s.compK, s.compare ? 1 : 0].join('|');
  }

  scheduleRedraw(ms){
    clearTimeout(this._rd);
    this._rd = setTimeout(() => { try { this.redraw(); } catch (e) { console.error('[scouting] redraw', e); } }, ms);
  }

  /* --- persistance des saisies ---------------------------------------------- */
  paramsObj(){ const o = {}; PARAM_KEYS.forEach(k => { o[k] = this.state[k]; }); return o; }

  // les hypothèses du modèle survivent au rechargement (et sont partagées
  // via ceo_app_setting quand l'API répond)
  saveParams(){
    const v = this.paramsObj(), j = JSON.stringify(v);
    if (j === this._pv) return;
    this._pv = j;
    ls.set('params', v);
    // pas d'envoi avant la relecture initiale : les défauts écraseraient la base
    if (this.useApi() && this._pulled){ clearTimeout(this._pt); this._pt = setTimeout(() => apiWrite('PUT', '/parametres/scoutingParams', { valeur: v }), 800); }
  }

  // saisies déjà en base : elles font foi quand l'API répond
  async pullSaved(){
    let d = null;
    try { d = await apiGet('/scouting', 8000); } catch (e) { console.warn('[scouting] /scouting injoignable :', e.message); }
    this._pulled = true;
    if (!d) return;
    this._serverTiles = {};
    (d.tiles || []).forEach(t => { this._serverTiles[t.sector] = t; });
    const ratings = {}, notes = {};
    (d.competitors || []).forEach(r => {
      if (r.rating != null) ratings[r.id] = { rating: +r.rating, n: +(r.reviews || 0), manual: r.source === 'manuel' };
      else if (r.source === 'google') ratings[r.id] = { rating: null, n: 0 };   // déjà interrogé, sans note
      if (r.comment) notes[r.id] = r.comment;
    });
    const patch = { ratings, notes, candidates: d.candidates || [], pops: d.populations || {} };
    const pr = pickParams(d.params);
    Object.assign(patch, pr);
    patch.gconf = d.google && typeof d.google === 'object' ? d.google : null;   // l'état du connecteur, jamais la clé
    ls.set('ratings', ratings); ls.set('notes', notes); ls.set('cand', patch.candidates); ls.set('pops', patch.pops);
    if (Object.keys(pr).length) ls.set('params', pr);
    this._rev++;
    this.setState(patch);
    this._pv = JSON.stringify(this.paramsObj());   // rien à renvoyer au serveur
  }

  pushCompetitors(rows){
    if (!rows.length || !this.useApi()) return;
    apiWrite('PUT', '/scouting/competitors', { rows });
  }

  competitorRow(id){
    const b = this.state.bakeries.find(o => o.id === id) || {};
    return { id, name: b.name || '', commune: b.commune || '', arr: b.arr || '' };
  }

  /* ---------- carte ---------- */
  ensureMap(){
    if (this.map || this._mapP) return;
    this._mapP = loadLeaflet().then(() => this.initMap()).catch(e => { this._mapP = null; this.setState({ err: e.message }); });
  }

  initMap(){
    const el = this.el.querySelector('#scout-map');
    if (!el || !window.L) return;
    const map = L.map(el, { center: [50.64, 4.67], zoom: 8, minZoom: 6, maxZoom: 14, zoomControl: true });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>', maxZoom: 19
    }).addTo(map);
    this.map = map;
    this.vecR = L.svg({ padding: 0.4 }).addTo(map);
    this.gHeat = L.layerGroup();
    this.gPrio = L.layerGroup();
    this.gShops = L.layerGroup().addTo(map);
    this.gExcl = L.layerGroup().addTo(map);
    this.gRoads = L.layerGroup();
    this.gSel = L.layerGroup().addTo(map);
    map.on('click', e => this.evaluate(e.latlng.lat, e.latlng.lng));
    map.on('zoomend moveend', () => { if (this.state.communes.length) this.scheduleRedraw(120); });
    this.scheduleRedraw(50);
  }

  /* ---------- données ---------- */
  // Un secteur part du miroir désigné par `start` : trois secteurs chargés en
  // parallèle frappent trois miroirs différents au lieu de faire la file sur
  // le premier. Sur échec, on passe au miroir suivant, puis on réessaie.
  async overpass(q, label, start){
    let last;
    const n = OVERPASS.length, first = Math.abs(start || 0) % n;
    for (let attempt = 0; attempt < 3; attempt++){
      for (let k = 0; k < n; k++){
        const ep = OVERPASS[(first + k) % n];
        try {
          if (attempt > 0) this.setState({ progress: label + ' — nouvelle tentative (' + (attempt + 1) + '/3)' });
          const r = await fetch(ep, { method: 'POST', body: 'data=' + encodeURIComponent(q), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
          if (!r.ok) throw new Error('HTTP ' + r.status);
          const j = await r.json();
          if (!j.elements) throw new Error('réponse vide');
          return j;
        } catch (e) { last = e; }
      }
      await new Promise(res => setTimeout(res, 2500 + attempt * 4000));
    }
    throw last;
  }

  // relations communales (admin_level 8), nœuds place peuplés, boulangeries
  // et pâtisseries d'un secteur → { c, b, p }
  parseTile(r){
    const tc = [], tb = [], tp = [];
    (r.elements || []).forEach(e => {
      const t = e.tags || {};
      if (t.boundary === 'administrative'){
        const ins = (t['ref:INS'] || t.ref || '').replace(/[^0-9]/g, '');
        const prov = INS_PROV[ins.slice(0, 2)];
        const c = e.center || {};
        if (!prov || !c.lat) return;
        const pop = parseInt((t.population || '').replace(/[^0-9]/g, ''), 10);
        tc.push({ id: e.id, name: t['name:fr'] || t.name || '—', nl: t.name || '', ins: ins,
          arr: ARR[parseInt(ins.slice(0, 2), 10)] || '—', prov: prov, pop: pop || 0, lat: c.lat, lng: c.lon });
        return;
      }
      if (t.place && t.population){
        const pop = parseInt(String(t.population).replace(/[^0-9]/g, ''), 10);
        const lat = e.lat || (e.center && e.center.lat), lng = e.lon || (e.center && e.center.lon);
        if (pop && lat) tp.push({ name: t['name:fr'] || t.name || '', nl: t.name || '', pop: pop, lat: lat, lng: lng });
        return;
      }
      if (t.shop !== 'bakery' && t.shop !== 'pastry') return;
      const lat = e.lat || (e.center && e.center.lat), lng = e.lon || (e.center && e.center.lon);
      if (!lat) return;
      const addr = [t['addr:street'], t['addr:housenumber']].filter(Boolean).join(' ');
      tb.push({
        id: e.type[0] + e.id, name: t.name || t.brand || 'Boulangerie sans nom', lat: lat, lng: lng,
        addr: [addr, [t['addr:postcode'], t['addr:city']].filter(Boolean).join(' ')].filter(Boolean).join(', '),
        brand: t.brand || '', web: !!(t.website || t['contact:website']),
        hours: t.opening_hours || '', pastry: t.shop === 'pastry', cuisine: t.cuisine || ''
      });
    });
    return { t: Date.now(), c: tc, b: tb, p: tp };
  }

  async load(force){
    if (this._loading) return;
    this._loading = true;
    this.setState({ err: null, busy: true, progress: 'Interrogation d\'OpenStreetMap…' });
    const api = this.useApi();
    if (api && !this._serverTiles){ this.setState({ progress: 'Lecture des saisies enregistrées…' }); await this.pullSaved(); }
    const bakeries = [], communes = [], places = [], seenC = {}, seenB = {}, failed = [];
    let done = 0;
    const absorb = (tc, tb, tp) => {
      const fresh = [];
      (tc || []).forEach(c => { if (!seenC[c.id]){ seenC[c.id] = 1; communes.push(Object.assign({}, c)); } });
      (tb || []).forEach(b => { if (!seenB[b.id]){ seenB[b.id] = 1; const o = Object.assign({}, b); bakeries.push(o); fresh.push(o); } });
      (tp || []).forEach(p => places.push(p));
      return fresh;
    };
    // Un secteur : cache serveur partagé, puis cache navigateur, puis Overpass.
    const tile = async (i) => {
      const [bbox, label] = TILES[i];
      let d = null;
      if (!force){
        if (api && this._serverTiles && this._serverTiles[i]){
          try { d = await apiGet('/scouting/tiles/' + i, 60000); } catch (e) { d = null; }
          if (d && d.c) ls.set('t' + i, d); else d = null;
        }
        if (!d){
          d = ls.get('t' + i);
          if (d && d.c){ if (api) apiWrite('PUT', '/scouting/tiles/' + i, d); }
          else d = null;
        }
      }
      if (!d && api){
        // Le serveur relit le secteur chez OpenStreetMap et le dépose dans le
        // cache partagé : tout le monde en profite, et le navigateur n'a plus à
        // atteindre Overpass. Repli navigateur si le serveur n'y arrive pas.
        this.setState({ busy: true, progress: label + ' — le serveur relit OpenStreetMap… (' + done + '/' + TILES.length + ' secteurs reçus)' });
        try { d = await apiRefresh(i); } catch (e) { console.warn('[scouting] relecture serveur du secteur ' + i + ' :', e.message); d = null; }
        if (d && d.c){
          ls.set('t' + i, d);
          if (!this._serverTiles) this._serverTiles = {};
          this._serverTiles[i] = { sector: i, fetchedAt: new Date(d.t || Date.now()).toISOString().slice(0, 19).replace('T', ' ') };
        } else d = null;
      }
      if (!d){
        this.setState({ busy: true, progress: label + ' — interrogation d\'OpenStreetMap… (' + done + '/' + TILES.length + ' secteurs reçus)' });
        let r;
        try {
          r = await this.overpass('[out:json][timeout:240];rel(' + bbox + ')["boundary"="administrative"]["admin_level"="8"];out tags center;'
            + 'node(' + bbox + ')["place"]["population"];out tags center;'
            + '(nwr["shop"="bakery"](' + bbox + ');nwr["shop"="pastry"](' + bbox + '););out center tags;', label, i);
        } catch (e) { failed.push(label); return; }
        d = this.parseTile(r);
        ls.set('t' + i, d);
        if (api) apiWrite('PUT', '/scouting/tiles/' + i, d);
      }
      const fresh = absorb(d.c, d.b, d.p || []);
      this.index(communes);
      this.fillPop(communes, places);
      // Rattachement provisoire des commerces du secteur aux communes déjà
      // connues : les filtres province / arrondissement, la carte et l'onglet
      // ceo_concurrents s'appliquent dès ce secteur — et non un quart d'heure
      // plus tard, une fois les neuf secteurs lus.
      this.attach(fresh, communes);
      done++;
      this._rev++;
      this.setState({ communes: communes.slice(), bakeries: bakeries.filter(b => b.prov),
        progress: done + '/' + TILES.length + ' secteurs reçus' + (done < TILES.length ? '…' : '') });
    };
    // Trois secteurs à la fois, chacun partant d'un miroir Overpass différent :
    // un secteur prend une à trois minutes, et les attendre l'un après l'autre
    // faisait de la première ouverture un quart d'heure.
    const queue = TILES.map((_, i) => i);
    const worker = async () => { while (queue.length){ await tile(queue.shift()); } };
    await Promise.all([worker(), worker(), worker()]);
    this._loading = false;
    if (!communes.length){
      this.setState({ busy: false, progress: '', err: 'Overpass injoignable — réessaie avec « Recharger les données ».' });
      return;
    }
    this.index(communes);
    this.fillPop(communes, places);
    // rattachement définitif, toutes communes connues ; hors Belgique = écarté
    const kept = this.attach(bakeries, communes);
    this.index(communes);
    this._rev++;
    this.setState({ bakeries: kept, communes: communes, busy: false, progress: '',
      err: failed.length ? 'Secteurs incomplets : ' + failed.join(', ') + ' — relance le chargement pour les compléter.' : null });
    setTimeout(() => { try { if (this.map) this.map.invalidateSize(); this.redraw(); } catch (e) { console.error('[scouting]', e); } }, 80);
  }

  // Rattache chaque commerce à la commune la plus proche (≤ 10 km) et rend ceux
  // qui ont trouvé la leur ; au-delà, le commerce est hors Belgique et reste
  // sans province — donc hors de toute sélection.
  attach(list, communes){
    const kept = [];
    list.forEach(b => {
      let best = null, bd = 1e9;
      communes.forEach(c => { const d = dist(b.lat, b.lng, c.lat, c.lng); if (d < bd){ bd = d; best = c; } });
      if (!best || bd > 10){ b.commune = ''; b.arr = ''; b.ins = ''; b.prov = ''; return; }
      b.commune = best.name; b.arr = best.arr; b.ins = best.ins; b.prov = best.prov;
      kept.push(b);
    });
    return kept;
  }

  // Population : la valeur portée par la relation communale OSM fait foi ;
  // un import CSV StatBel (code NIS;population) la remplace ; à défaut on
  // reprend le nœud « place » homonyme, puis la densité médiane des communes
  // sourcées voisines appliquée à la surface estimée de la commune —
  // l'estimation est signalée partout dans l'interface.
  fillPop(communes, places){
    const cs = communes || [];
    const off = this.state.pops || {};
    if (places && places.length) this._places = places;
    const pl = places || this._places || [];
    const hs = this.state.hhSize || HH_SIZE;
    cs.forEach(c => {
      if (c.popOsm === undefined) c.popOsm = c.pop || 0;
      if (off[c.ins]){ c.pop = off[c.ins]; c.est = false; c.official = true; }
      else { c.pop = c.popOsm; c.est = false; c.official = false; }
      c.hh = c.pop ? Math.round(c.pop / hs) : 0;
    });
    if (pl.length){
      const idx = {};
      pl.forEach(p => {
        [p.name, p.nl].filter(Boolean).forEach(n => { const k = n.toLowerCase(); (idx[k] = idx[k] || []).push(p); });
      });
      cs.forEach(c => {
        if (c.pop) return;
        const cand = (idx[(c.name || '').toLowerCase()] || []).concat(idx[(c.nl || '').toLowerCase()] || []);
        let best = null, bd = 1e9;
        cand.forEach(p => { const d = dist(c.lat, c.lng, p.lat, p.lng); if (d <= 10 && d < bd){ bd = d; best = p; } });
        if (best){ c.pop = best.pop; c.hh = Math.round(best.pop / hs); c.est = true; }
      });
    }
    const all = [];
    cs.forEach(c => { if (c.pop && c.aKm2 && !c.est) all.push(c.pop / c.aKm2); });
    // estimation spatiale : densité médiane des six communes sourcées les
    // plus proches (jamais un donneur d'une autre province)
    const gmed = all.length ? medOf(all) : 300;
    const src = cs.filter(c => c.pop && c.aKm2);
    cs.forEach(c => {
      if (c.pop) return;
      const near = src.filter(o => o.prov === c.prov)
        .map(o => ({ d: dist(c.lat, c.lng, o.lat, o.lng), v: o.pop / o.aKm2 }))
        .sort((x, y) => x.d - y.d).slice(0, 6).filter(o => o.d < 35).map(o => o.v);
      const d = near.length >= 3 ? medOf(near) : gmed;
      c.pop = Math.max(700, Math.min(90000, Math.round(d * (c.aKm2 || 40))));
      c.hh = Math.round(c.pop / hs);
      c.est = true;
    });
    cs.forEach(c => { c.dens = c.aKm2 ? c.hh / c.aKm2 : 0; });
  }

  // surface utile d'une commune estimée par les voisins les plus proches
  // (pas de polygone récupéré : rayon de Voronoï approché)
  index(communes){
    const cs = communes || [];
    cs.forEach(c => {
      const ds = [];
      cs.forEach(o => { if (o !== c) ds.push(dist(c.lat, c.lng, o.lat, o.lng)); });
      ds.sort((a, b) => a - b);
      const near = ds.slice(0, 3);
      const m = near.length ? near.reduce((a, b) => a + b, 0) / near.length : 7;
      c.rKm = Math.max(1.3, m * 0.62);
      c.aKm2 = Math.PI * c.rKm * c.rKm;
      c.dens = c.hh / c.aKm2;
    });
  }

  // recalcul des populations (import StatBel, taille des ménages)
  recomputePop(){
    const cs = this.state.communes.map(c => Object.assign({}, c));
    this.index(cs); this.fillPop(cs);
    this._rev++;
    this.setState({ communes: cs });
    const x = this.state.sel;
    if (x) this.evaluate(x.lat, x.lng);
  }

  /* ---------- rendu carte ---------- */
  shopPopup(b){
    const r = this.rating(b), rv = this.state.ratings[b.id];
    return '<div class="sc-pop"><b>' + esc(b.name) + '</b><br>' + esc(b.addr || 'adresse non renseignée')
      + '<br>' + esc(b.commune || '—') + ' · arr. ' + esc(b.arr || '—')
      + '<br>Note : ' + (r ? r.toFixed(1) + ' / 5 (' + ((rv && rv.n) || 0) + ' avis)' : 'non renseignée')
      + '<br>Force estimée : ' + Math.round(this.strength(b) * 100) + ' %'
      + (this.isStrong(b) ? '<br><b style="color:#8D1D2C">Concurrent fort</b>' : '') + '</div>';
  }

  // clustering maison : agrégation en grille de 64 px, points individuels
  // au-delà du zoom 11, et seulement ce qui est dans la vue
  drawShops(){
    const s = this.state;
    this.gShops.clearLayers();
    if (!s.layers.shops) return;
    const bounds = this.map.getBounds().pad(0.25), z = this.map.getZoom();
    const vis = this.shops().filter(b => bounds.contains([b.lat, b.lng]));
    const single = b => {
      const r = this.rating(b);
      const col = !r ? R_COL.none : r >= 4.5 ? R_COL.high : r >= 3.5 ? R_COL.mid : R_COL.low;
      return L.circleMarker([b.lat, b.lng], { renderer: this.vecR, radius: 5, color: '#fff', weight: 1, fillColor: col, fillOpacity: .95 })
        .bindPopup(this.shopPopup(b));
    };
    if (!s.layers.cluster || z >= 12){
      vis.forEach(b => this.gShops.addLayer(single(b)));
      return;
    }
    const cell = 64, buckets = {};
    vis.forEach(b => {
      const p = this.map.latLngToLayerPoint([b.lat, b.lng]);
      const k = Math.floor(p.x / cell) + '_' + Math.floor(p.y / cell);
      (buckets[k] = buckets[k] || []).push(b);
    });
    Object.keys(buckets).forEach(k => {
      const arr = buckets[k];
      if (arr.length === 1){ this.gShops.addLayer(single(arr[0])); return; }
      // bulle posée au centre de la cellule : deux barycentres voisins
      // pouvaient se superposer et masquer leurs chiffres
      const parts = k.split('_');
      const cp = this.map.layerPointToLatLng(L.point((+parts[0] + 0.5) * cell, (+parts[1] + 0.5) * cell));
      const lat = cp.lat, lng = cp.lng;
      const strong = arr.filter(b => this.isStrong(b)).length;
      const d = 26 + Math.min(18, Math.round(Math.log(arr.length) * 8));
      const m = L.marker([lat, lng], {
        icon: L.divIcon({
          className: '', iconSize: [d, d], iconAnchor: [d / 2, d / 2],
          html: '<div style="width:' + d + 'px;height:' + d + 'px;border-radius:50%;display:flex;align-items:center;justify-content:center;'
            + 'background:rgba(255,255,255,.92);border:2px solid ' + (strong / arr.length >= 0.5 ? '#8D1D2C' : '#78554B') + ';color:#222;'
            + 'font-family:Gotham,sans-serif;font-size:11px;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.18)">' + arr.length + '</div>'
        })
      });
      m.bindPopup('<div class="sc-pop"><b>' + arr.length + ' commerces</b><br>'
        + (strong ? strong + ' concurrent(s) fort(s)<br>' : '')
        + arr.slice(0, 6).map(b => esc(b.name)).join('<br>') + (arr.length > 6 ? '<br>…' : '') + '</div>');
      m.on('click', () => this.map.setView([lat, lng], Math.min(14, z + 3)));
      this.gShops.addLayer(m);
    });
  }

  // Balayage de la vue : maille de points espacés d'un rayon, on écarte tout
  // ce qui tombe dans une zone d'exclusion et on classe le reste au score.
  // Mémorisé par vue et par état : appelé par la carte, le compteur et
  // l'onglet ceo_zones dans le même cycle.
  scanPrio(){
    if (!this.map) return [];
    const s = this.state, R = s.radius;
    const b = this.map.getBounds();
    const key = [b.toBBoxString(), R, s.thresh, s.minScore, s.arr, JSON.stringify(s.prov), s.minRating, s.minHh, this._rev,
      s.spend, s.passage, s.emprise, s.empriseMax, s.compK].join('|');
    if (key === this._scanKey) return this._scanVal;
    const shops = this.shops();
    const strong = shops.filter(x => this.isStrong(x));
    const cs = this.filteredCommunes();
    const out = [];
    if (cs.length){
      const stepKm = Math.max(R * 0.9, 1.2);
      const mid = (b.getNorth() + b.getSouth()) / 2;
      let dLat = stepKm / 111, dLng = stepKm / (111 * Math.cos(mid * Math.PI / 180));
      // vue large : la maille s'élargit pour couvrir toute la vue dans le
      // budget de 2 600 points (sinon le balayage s'arrêtait au sud de la vue)
      const cells = ((b.getNorth() - b.getSouth()) / dLat) * ((b.getEast() - b.getWest()) / dLng);
      if (cells > 2600){ const f = Math.sqrt(cells / 2600); dLat *= f; dLng *= f; }
      const eMax = (s.empriseMax || 30) / 100;
      let guard = 0;
      for (let lat = b.getSouth(); lat <= b.getNorth() && guard < 2600; lat += dLat){
        for (let lng = b.getWest(); lng <= b.getEast() && guard < 2600; lng += dLng){
          guard++;
          let com = null, cd = 1e9;
          cs.forEach(c => { const d = dist(lat, lng, c.lat, c.lng); if (d < cd){ cd = d; com = c; } });
          if (!com || cd > (com.rKm || 3) * 1.4) continue;                       // hors zone habitée connue
          if (strong.some(x => dist(lat, lng, x.lat, x.lng) <= R)) continue;      // zone rouge
          const near = shops.filter(x => dist(lat, lng, x.lat, x.lng) <= R);
          let load = 0;
          near.forEach(x => { load += this.strength(x) * (1 - dist(lat, lng, x.lat, x.lng) / R * 0.6); });
          const hh = com.dens * Math.PI * R * R;
          const auto = Math.max(0.04, Math.min(eMax, eMax / (1 + (s.compK || 0.22) * load)));
          const emprise = s.emprise > 0 ? s.emprise / 100 : auto;
          const ca = hh * s.spend * emprise / (1 - s.passage / 100);
          const score = Math.max(0, Math.min(100, Math.round((hh / 14000) * 60 + emprise / eMax * 40)));
          out.push({ lat: lat, lng: lng, hh: hh, ca: ca, score: score, n: near.length, commune: com.name, arr: com.arr });
        }
      }
    }
    // une seule zone par commune : les points voisins d'une même maille
    // donnent des lignes identiques et noient le tableau
    const best = {};
    out.filter(p => p.score >= (s.minScore || 0)).forEach(p => {
      const k = p.commune + '|' + p.arr;
      if (!best[k] || p.score > best[k].score) best[k] = p;
    });
    const res = Object.keys(best).map(k => best[k]).sort((x, y) => y.score - x.score).slice(0, 30);
    this._scanKey = key; this._scanVal = res;
    return res;
  }

  redraw(){
    if (!this.map || !this.el.isConnected) return;
    const s = this.state, shops = this.shops();
    this.gExcl.clearLayers(); this.gSel.clearLayers();
    try { this.drawShops(); } catch (e) { console.error('[scouting] points', e); }
    if (s.layers.excl) try {
      const vb = this.map.getBounds().pad(0.35);
      shops.filter(b => this.isStrong(b) && vb.contains([b.lat, b.lng])).forEach(b => {
        const c = L.circle([b.lat, b.lng], { renderer: this.vecR, radius: s.radius * 1000, color: '#8D1D2C', weight: 1, opacity: .5, fillColor: '#8D1D2C', fillOpacity: .18 });
        const r = this.rating(b);
        c.bindPopup('<div class="sc-pop"><b style="color:#8D1D2C">Pas d\'installation — concurrence forte</b><br>'
          + esc(b.name) + (r ? ' · ' + r.toFixed(1) + '/5' : '') + '<br>' + esc(b.addr || b.commune || '')
          + '<br>Rayon d\'exclusion : ' + s.radius.toFixed(1) + ' km</div>');
        this.gExcl.addLayer(c);
      });
    } catch (e) { console.error('[scouting] exclusions', e); }
    if (s.layers.roads) try {
      if (!this.gRoads._map) this.map.addLayer(this.gRoads);
      if (!this.gRoads.getLayers().length){
        AXES.forEach(a => {
          L.polyline(a.pts, { renderer: this.vecR, color: '#78554B', weight: Math.max(2, a.w / 14000), opacity: .45, lineCap: 'round' })
            .bindPopup('<div class="sc-pop"><b>' + esc(a.name) + '</b><br>≈ ' + fmtInt(a.w) + ' navetteurs/jour (ordre de grandeur)</div>')
            .addTo(this.gRoads);
        });
      }
    } catch (e) { console.error('[scouting] axes', e); }
    else if (this.gRoads._map){ try { this.map.removeLayer(this.gRoads); } catch (e) { /* déjà retiré */ } }
    this.gPrio.clearLayers();
    if (s.layers.prio) try {
      if (!this.gPrio._map) this.map.addLayer(this.gPrio);
      const best = this.scanPrio();
      const top = best.length ? best[0].score : 1;
      best.forEach((p, i) => {
        const t = p.score / (top || 1);
        L.circle([p.lat, p.lng], {
          renderer: this.vecR, radius: s.radius * 1000, color: '#1b5e20', weight: i < 5 ? 2 : 1,
          opacity: 0.7, fillColor: '#1b5e20', fillOpacity: 0.10 + 0.16 * t, dashArray: i < 5 ? null : '4 4'
        }).bindPopup('<div class="sc-pop"><b style="color:#1b5e20">Zone prioritaire nº' + (i + 1) + '</b><br>'
          + esc(p.commune) + ' · arr. ' + esc(p.arr) + '<br>Score ' + p.score + '/100<br>'
          + fmtInt(p.hh) + ' ménages · ' + fmtInt(p.n) + ' concurrents<br>CA estimé : ' + fmtEur(p.ca)
          + '<br><i>Clic sur le centre pour la fiche détaillée</i></div>').addTo(this.gPrio);
        L.circleMarker([p.lat, p.lng], { renderer: this.vecR, radius: 5, color: '#fff', weight: 1.5, fillColor: '#1b5e20', fillOpacity: 1 })
          .on('click', () => this.evaluate(p.lat, p.lng)).addTo(this.gPrio);
      });
    } catch (e) { console.error('[scouting] zones prioritaires', e); }
    else if (this.gPrio._map){ try { this.map.removeLayer(this.gPrio); } catch (e) { /* déjà retiré */ } }
    this.gHeat.clearLayers();
    if (s.layers.heat) try {
      if (!this.gHeat._map) this.map.addLayer(this.gHeat);
      const vb2 = this.map.getBounds().pad(0.35);
      const all = this.filteredCommunes();
      const cs = all.filter(c => vb2.contains([c.lat, c.lng]));
      const dens = all.map(c => c.dens).sort((x, y) => x - y);
      const p95 = dens.length ? dens[Math.floor(dens.length * 0.95)] : 1;
      cs.forEach(c => {
        const t = Math.max(0, Math.min(1, c.dens / (p95 || 1)));
        const col = t < .25 ? '#FDF6E7' : t < .45 ? '#FAC775' : t < .65 ? '#E8964B' : t < .85 ? '#C41E3A' : '#5C1018';
        L.circle([c.lat, c.lng], { renderer: this.vecR, radius: c.rKm * 1000 * 0.9, stroke: false, fillColor: col, fillOpacity: 0.42 })
          .bindPopup('<div class="sc-pop"><b>' + esc(c.name) + '</b><br>arr. ' + esc(c.arr)
            + '<br>' + fmtInt(c.pop) + ' habitants' + (c.est ? ' (estimation)' : c.official ? ' (StatBel)' : ' (OSM)')
            + '<br>' + fmtInt(c.hh) + ' ménages · ' + fmtInt(c.dens) + ' ménages/km²'
            + '<br>Marché boulangerie : ' + fmtEur(c.hh * s.spend) + '</div>').addTo(this.gHeat);
      });
    } catch (e) { console.error('[scouting] densité', e); }
    else if (this.gHeat._map){ try { this.map.removeLayer(this.gHeat); } catch (e) { /* déjà retiré */ } }
    if (s.sel){
      L.circle([s.sel.lat, s.sel.lng], { renderer: this.vecR, radius: s.radius * 1000, color: '#1b5e20', weight: 2, dashArray: '5 5', fillColor: '#1b5e20', fillOpacity: .08 }).addTo(this.gSel);
      L.circleMarker([s.sel.lat, s.sel.lng], { renderer: this.vecR, radius: 7, color: '#fff', weight: 2, fillColor: '#1b5e20', fillOpacity: 1 }).addTo(this.gSel);
    }
    s.candidates.forEach(c => {
      L.circleMarker([c.lat, c.lng], { renderer: this.vecR, radius: 6, color: '#1b5e20', weight: 2, fillColor: '#FAC775', fillOpacity: 1 })
        .bindPopup('<div class="sc-pop"><b>' + esc(c.name) + '</b><br>CA estimé : ' + fmtEur(c.ca) + '</div>').addTo(this.gSel);
    });
  }

  /* ---------- filtres ---------- */
  rating(b){ const r = this.state.ratings[b.id]; return r && r.rating ? r.rating : null; }

  strength(b){  // force du concurrent, 0–1
    const r = this.rating(b);
    if (r) return Math.max(0, Math.min(1, (r - 3) / 2));
    let s = 0.4;
    const n = (b.name || '').toLowerCase();
    if (CHAINS.some(c => n.includes(c)) || b.brand) s += 0.25;
    if (b.web) s += 0.1;
    if (b.hours) s += 0.1;
    if (b.pastry) s += 0.05;
    return Math.min(1, s);
  }

  isStrong(b){
    const r = this.rating(b), t = this.state.thresh;
    if (r) return r >= t;
    return this.strength(b) >= 0.75 - (5 - t) * 0.05;
  }

  shops(){
    const s = this.state;
    const hhOk = {};
    s.communes.forEach(c => { hhOk[c.ins] = c.hh >= s.minHh; });
    return s.bakeries.filter(b => {
      if (!b.prov || !s.prov[b.prov]) return false;          // commune inconnue = hors sélection
      if (s.arr !== 'all' && b.arr !== s.arr) return false;
      if (s.minRating > 0){ const r = this.rating(b); if (!r || r < s.minRating) return false; }
      if (s.minHh > 0 && !hhOk[b.ins]) return false;
      return true;
    });
  }

  filteredCommunes(){
    const s = this.state;
    return s.communes.filter(c => s.prov[c.prov] && (s.arr === 'all' || c.arr === s.arr) && c.hh >= s.minHh);
  }

  households(lat, lng, R){
    const cs = this.state.communes.filter(c => dist(lat, lng, c.lat, c.lng) < R + 14);
    if (!cs.length) return { hh: 0, prim: 0 };
    const N = 26, step = R * 2 / N, cell = step * step;
    let hh = 0, prim = 0;
    for (let i = 0; i < N; i++) for (let j = 0; j < N; j++){
      const dx = -R + step * (i + .5), dy = -R + step * (j + .5);
      const d = Math.sqrt(dx * dx + dy * dy);
      if (d > R) continue;
      const plat = lat + dy / 111, plng = lng + dx / (111 * Math.cos(lat * Math.PI / 180));
      let hit = null, bd = 1e9;
      cs.forEach(c => { const dd = dist(plat, plng, c.lat, c.lng); if (dd < bd){ bd = dd; hit = c; } });
      if (!hit || bd > (hit.rKm || 3) * 1.9) continue;
      const v = hit.dens * cell;
      hh += v;
      if (d <= R * 0.55) prim += v;
    }
    return { hh: hh, prim: prim };
  }

  evaluate(lat, lng){
    const s = this.state;
    if (!s.communes.length) return;
    const R = s.radius;
    const near = this.shops().map(b => ({ b: b, d: dist(lat, lng, b.lat, b.lng) })).filter(o => o.d <= R).sort((a, b) => a.d - b.d);
    const blocked = near.filter(o => this.isStrong(o.b));
    const { hh, prim } = this.households(lat, lng, R);
    let load = 0;
    near.forEach(o => { load += this.strength(o.b) * (1 - o.d / R * 0.6) * (this.isStrong(o.b) ? 1.5 : 1); });
    const eMax = (s.empriseMax || 30) / 100;
    const auto = Math.max(0.04, Math.min(eMax, eMax / (1 + (s.compK || 0.22) * load)));
    const emprise = s.emprise > 0 ? s.emprise / 100 : auto;
    const market = hh * s.spend;
    const caZone = market * emprise;
    const ca = caZone / (1 - s.passage / 100);
    let cm = null, cd = 1e9;
    s.communes.forEach(c => { const d = dist(lat, lng, c.lat, c.lng); if (d < cd){ cd = d; cm = c; } });
    const score = Math.max(0, Math.min(100, Math.round((hh / 14000) * 60 + emprise / eMax * 40)));
    this.setState({
      sel: {
        lat: lat, lng: lng, hh: hh, prim: prim, market: market, emprise: emprise, ca: ca,
        near: near, blocked: blocked, load: load, score: score,
        commune: cm ? cm.name : '—', arr: cm ? cm.arr : '—',
        prov: cm ? (PROV.find(p => p.code === cm.prov) || {}).name : '—',
        cmHh: cm ? cm.hh : 0, cmEst: cm ? !!cm.est : false, cmPop: cm ? cm.pop : 0
      }
    });
  }

  // les hypothèses changent → la fiche ouverte est recalculée
  setParam(patch){
    this.setState(patch);
    const x = this.state.sel;
    if (x) this.evaluate(x.lat, x.lng);
  }

  /* ---------- Google Places (via le serveur) ---------- */
  // La clé est celle du connecteur Google de Paramètres ; l'écran n'en connaît
  // que l'état. Sans API (repli local), aucune note ne peut être demandée.
  googleOk(){ const g = this.state.gconf; return !!(g && g.configure); }

  googleBlocage(){
    if (!this.useApi()) return 'Notes Google indisponibles hors ligne — l\'API du cockpit ne répond pas.';
    if (!this.googleOk()) return 'Aucune clé Google — renseigne-la dans Paramètres › Général (connecteur Google).';
    return null;
  }

  // Un lot de commerces → { rows: [{ id, rating, reviews }], erreur }
  notesLot(list){
    return apiNotes(list.map(b => ({ id: b.id, name: b.name, addr: b.addr || '', commune: b.commune || '', arr: b.arr || '', lat: b.lat, lng: b.lng })))
      .then(r => ({ rows: (r && Array.isArray(r.rows)) ? r.rows : [], erreur: (r && r.erreur) || null }));
  }

  goParams(){ if (this.app && typeof this.app.setState === 'function') this.app.setState({ screen: 'parametres', gq: '' }); }

  saveRatings(out){ ls.set('ratings', out); this._rev++; }

  // Enrichissement de la vue courante : 40 commerces visibles, par lots de 10
  // demandés au serveur — chaque lot est enregistré et affiché dès sa réponse.
  async enrich(){
    const s = this.state;
    const blocage = this.googleBlocage();
    if (blocage){ this.setState({ err: blocage }); return; }
    if (!this.map || s.enriching) return;
    this.setState({ enriching: true, stop: false, enrichDone: 0, enrichTotal: 0, err: null });
    const out = Object.assign({}, s.ratings);
    try {
      const bounds = this.map.getBounds();
      const list = this.shops().filter(b => bounds.contains([b.lat, b.lng]) && !out[b.id]).slice(0, 40);
      this.setState({ enrichTotal: list.length });
      if (!list.length){ this.setState({ enriching: false, err: 'Rien à enrichir dans cette vue (déjà fait ou aucun commerce visible).' }); return; }
      const r = await this.enrichLots(list, out);
      this.setState({ ratings: out, enriching: false, stop: false,
        err: r.erreur ? r.erreur : (r.ok ? null : 'Aucune note trouvée pour cette vue — vérifie que l\'API Places (New) est activée sur la clé de Paramètres.') });
    } catch (e) {
      this.saveRatings(out);
      this.setState({ enriching: false, stop: false, ratings: out, err: 'Google Places : ' + (e.message || e) });
    }
  }

  // Enrichissement en masse : toute la sélection filtrée, par lots de 10,
  // interruptible entre deux lots.
  async enrichAll(){
    const s = this.state;
    if (s.enriching){ this.setState({ stop: true }); return; }
    const blocage = this.googleBlocage();
    if (blocage){ this.setState({ err: blocage }); return; }
    const out = Object.assign({}, s.ratings);
    const list = this.shops().filter(b => !out[b.id]);
    if (!list.length){ this.setState({ err: 'Toute la sélection est déjà enrichie.' }); return; }
    this.setState({ enriching: true, stop: false, enrichDone: 0, enrichTotal: list.length, err: null });
    try {
      const r = await this.enrichLots(list, out);
      this.setState({ ratings: out, enriching: false, stop: false, err: r.erreur || null });
    } catch (e) {
      this.saveRatings(out);
      this.setState({ enriching: false, stop: false, ratings: out, err: 'Google Places : ' + (e.message || e) });
    }
  }

  // Le cœur commun : lots de 10, résultats fusionnés dans `out` et sauvegardés
  // au fil de l'eau ; s'arrête sur `stop` ou sur une erreur du serveur.
  async enrichLots(list, out){
    let ok = 0, done = 0, erreur = null;
    for (let i = 0; i < list.length; i += 10){
      if (this.state.stop) break;
      const r = await this.notesLot(list.slice(i, i + 10));
      r.rows.forEach(x => { out[x.id] = { rating: x.rating != null ? +x.rating : null, n: +(x.reviews || 0) }; if (x.rating) ok++; });
      done += r.rows.length;
      this.saveRatings(out);
      this.setState({ ratings: Object.assign({}, out), enrichDone: done });
      if (r.erreur){ erreur = r.erreur; break; }
    }
    return { ok, done, erreur };
  }

  /* ---------- notation manuelle ---------- */
  // une note saisie prime sur Google et recalcule zone rouge, emprise et CA
  setRating(id, v){
    const out = Object.assign({}, this.state.ratings);
    const n = parseFloat(String(v).replace(',', '.'));
    if (!v && v !== 0) delete out[id];
    else if (!isNaN(n) && n >= 0 && n <= 5) out[id] = { rating: n, n: (out[id] && out[id].n) || 0, manual: true };
    else return;
    this.saveRatings(out);
    this.pushCompetitors([Object.assign(this.competitorRow(id), { rating: out[id] ? out[id].rating : null, reviews: out[id] ? out[id].n : 0, source: out[id] ? 'manuel' : null })]);
    this.setState({ ratings: out });
    const x = this.state.sel;
    if (x) setTimeout(() => this.evaluate(x.lat, x.lng), 0);
  }

  // commentaire libre sur un concurrent (200 caractères)
  setComment(id, txt){
    const notes = Object.assign({}, this.state.notes);
    const v = String(txt || '').slice(0, 200);
    if (v) notes[id] = v; else delete notes[id];
    ls.set('notes', notes);
    this.pushCompetitors([Object.assign(this.competitorRow(id), { comment: v || null })]);
    this.setState({ notes: notes });
  }

  /* ---------- populations officielles ---------- */
  async importPops(e){
    const file = e.target.files && e.target.files[0];
    if (!file) return;
    const txt = await file.text();
    const pops = Object.assign({}, this.state.pops), added = {};
    let n = 0;
    txt.split(/\r?\n/).forEach(line => {
      const cells = line.split(/[;\t,]/).map(s => s.trim().replace(/"/g, '').replace(/\s/g, ''));
      const ins = cells.find(v => /^[0-9]{5}$/.test(v));
      if (!ins) return;
      let pop = 0;
      cells.forEach(v => {
        if (v === ins) return;
        const num = parseInt(v.replace(/[^0-9]/g, ''), 10);
        if (num > 100 && num < 2000000 && num > pop) pop = num;
      });
      if (pop){ pops[ins] = pop; added[ins] = pop; n++; }
    });
    e.target.value = '';
    if (!n){ this.setState({ err: 'CSV illisible : attendu une colonne code NIS (5 chiffres) et une colonne population.' }); return; }
    ls.set('pops', pops);
    if (this.useApi()) apiWrite('PUT', '/scouting/populations', { populations: added, fichier: file.name });
    this.setState({ pops: pops, err: null });
    setTimeout(() => this.recomputePop(), 0);
    this.notify('Import StatBel : ' + fmtInt(n) + ' communes mises à jour');
  }

  /* ---------- candidats & export ---------- */
  addCandidate(){
    const s = this.state, x = s.sel;
    if (!x) return;
    const c = {
      id: Date.now(), name: x.commune + ' — zone ' + x.lat.toFixed(3) + '/' + x.lng.toFixed(3),
      commune: x.commune, arr: x.arr, prov: x.prov, lat: x.lat, lng: x.lng,
      hh: Math.round(x.hh), market: Math.round(x.market), emprise: x.emprise,
      ca: Math.round(x.ca), score: x.score, n: x.near.length, strong: x.blocked.length,
      m2: Math.round(x.ca / s.surface)
    };
    const list = s.candidates.concat([c]);
    ls.set('cand', list);
    if (this.useApi()) apiWrite('POST', '/scouting/candidates', c);
    this.setState({ candidates: list });
    this.notify('Zone ajoutée aux candidats — ' + x.commune + ' · ' + fmtEur(x.ca));
  }

  removeCandidate(c){
    const list = this.state.candidates.filter(o => o.id !== c.id);
    ls.set('cand', list);
    if (this.useApi()) apiWrite('DELETE', '/scouting/candidates/' + c.id);
    this.setState({ candidates: list });
  }

  csv(name, head, rows){
    const txt = [head.join(';')].concat(rows.map(r => r.map(v => String(v == null ? '' : v).replace(/;/g, ',')).join(';'))).join('\n');
    const url = URL.createObjectURL(new Blob(['﻿' + txt], { type: 'text/csv;charset=utf-8' }));
    const a = document.createElement('a');
    a.href = url; a.download = name + '.csv'; a.click();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
    this.notify(name + '.csv exporté — ' + fmtInt(rows.length) + ' lignes');
  }

  exportCsv(){
    const s = this.state;
    if (!s.candidates.length){ this.notify('Aucune zone candidate à exporter — ajoute d\'abord une zone depuis sa fiche.'); return; }
    this.csv('zones_candidates_belgique',
      ['localite', 'arrondissement', 'province', 'lat', 'lng', 'menages_zone', 'marche_boulangerie_eur', 'emprise_pct', 'ca_annuel_ttc_eur', 'ca_par_m2_eur', 'score_0_100', 'boulangeries_rayon', 'dont_fortes', 'rayon_km', 'depense_par_menage_eur', 'surface_m2'],
      s.candidates.map(c => [c.commune, c.arr, c.prov, (+c.lat).toFixed(5), (+c.lng).toFixed(5), c.hh, c.market, (c.emprise * 100).toFixed(1), c.ca, c.m2, c.score, c.n, c.strong, s.radius.toFixed(1), s.spend, s.surface]));
  }

  /* ---------- agrégats ---------- */
  arrStats(name){
    const cs = this.state.communes.filter(c => c.arr === name);
    const bs = this.state.bakeries.filter(b => b.arr === name);
    const pop = cs.reduce((a, c) => a + c.pop, 0), hh = cs.reduce((a, c) => a + c.hh, 0);
    const rated = bs.map(b => this.rating(b)).filter(Boolean);
    return {
      communes: cs.length, pop: pop, hh: hh, market: hh * this.state.spend, shops: bs.length,
      strong: bs.filter(b => this.isStrong(b)).length,
      dens: pop ? bs.length / (pop / 10000) : 0,
      avg: rated.length ? rated.reduce((a, b) => a + b, 0) / rated.length : null,
      list: cs
    };
  }

  histogram(){
    const s = this.state;
    const shops = this.shops();
    const rated = shops.map(b => this.rating(b)).filter(Boolean);
    if (rated.length >= 5){
      const bins = [[0, 3], [3, 3.5], [3.5, 4], [4, 4.3], [4.3, 4.5], [4.5, 4.7], [4.7, 5.01]];
      const counts = bins.map(b => rated.filter(r => r >= b[0] && r < b[1]).length);
      const max = Math.max(1, ...counts);
      return {
        title: 'Notes Google — ' + (s.arr === 'all' ? 'sélection courante' : 'arr. ' + s.arr) + ' · ' + rated.length + ' commerces notés',
        bars: bins.map((b, i) => ({
          label: b[0].toFixed(1), n: counts[i], h: Math.round(counts[i] / max * 100) + '%',
          color: b[0] >= 4.5 ? R_COL.high : b[0] >= 3.5 ? R_COL.mid : R_COL.low
        }))
      };
    }
    const cs = this.filteredCommunes();
    const bins = [[0, 2000], [2000, 4000], [4000, 7000], [7000, 11000], [11000, 16000], [16000, 25000], [25000, 1e9]];
    const labels = ['0', '2k', '4k', '7k', '11k', '16k', '25k+'];
    const counts = bins.map(b => cs.filter(c => c.hh >= b[0] && c.hh < b[1]).length);
    const max = Math.max(1, ...counts);
    return {
      title: 'Ménages par commune — ' + (s.arr === 'all' ? 'sélection courante' : 'arr. ' + s.arr) + ' · ' + cs.length + ' communes. Les notes Google apparaîtront ici après enrichissement.',
      bars: bins.map((b, i) => ({ label: labels[i], n: counts[i], h: Math.round(counts[i] / max * 100) + '%', color: '#c17a2a' }))
    };
  }

  // libellés rafraîchis pendant le glissement d'un curseur
  liveVals(){
    const s = this.state, shops = this.shops(), cs = this.filteredCommunes();
    const hh = cs.reduce((a, c) => a + c.hh, 0);
    return {
      minRatingLabel: s.minRating > 0 ? s.minRating.toFixed(1) + ' et plus' : 'toutes',
      ratingCoverage: shops.filter(b => this.rating(b)).length + ' / ' + shops.length + ' commerces notés' + (s.minRating > 0 ? ' — le filtre masque les non notés' : ''),
      minHhLabel: s.minHh > 0 ? fmtInt(s.minHh) + ' ménages' : 'sans minimum',
      communeCoverage: fmtInt(cs.length) + ' communes retenues · ' + fmtInt(hh) + ' ménages',
      radiusLabel: s.radius.toFixed(1) + ' km',
      threshLabel: s.thresh.toFixed(1) + ' / 5',
      threshHint: shops.filter(b => this.isStrong(b)).length + ' concurrents forts dans la sélection',
      minScoreLabel: String(s.minScore),
      statsLine: fmtInt(shops.length) + ' commerces affichés · ' + fmtInt(cs.length) + ' communes · ' + fmtInt(hh) + ' ménages · rayon ' + s.radius.toFixed(1) + ' km'
    };
  }

  /* --- valeurs de rendu (port de renderVals) --------------------------------- */
  renderVals(){
    const s = this.state, self = this;
    const shops = this.shops(), cs = this.filteredCommunes();
    const arrNames = Array.from(new Set(s.communes.filter(c => s.prov[c.prov]).map(c => c.arr))).filter(a => a !== '—').sort();
    const arrOptions = [{ value: 'all', label: 'Tous les arrondissements' }].concat(arrNames.map(a => ({ value: a, label: a })));
    const hist = this.histogram();
    const x = s.sel;
    const dir = s.sortDir, sk = s.sortKey;
    const sortBy = k => () => self.setState({ sortKey: k, sortDir: s.sortKey === k ? -s.sortDir : -1 });
    const goMap = (lat, lng, zoom, evaluate) => { self._scroll['sc-table'] = 0; self.setState({ view: 'map' }); setTimeout(() => { if (self.map) self.map.setView([lat, lng], zoom); if (evaluate) self.evaluate(lat, lng); }, 60); };
    const layerToggle = k => () => self.setState({ layers: Object.assign({}, s.layers, { [k]: !s.layers[k] }) });
    const onlyPrio = !!(s.layers.prio && !s.layers.excl && !s.layers.shops);

    // ----- lignes des tableaux -----
    const scan = (s.view === 'zones' && s.communes.length) ? this.scanPrio() : [];
    let zonesRows = scan.map((p, i) => {
      const emp = p.ca ? (p.ca * (1 - s.passage / 100)) / (p.hh * s.spend) : 0;
      return {
        rang: i + 1, commune: p.commune, arr: p.arr, lat: p.lat, lng: p.lng,
        score: p.score, hh: fmtInt(p.hh), hhRaw: Math.round(p.hh), n: p.n,
        emprise: (emp * 100).toFixed(1) + ' %', empriseRaw: (emp * 100).toFixed(1),
        ca: fmtEur(p.ca), caRaw: Math.round(p.ca),
        m2: fmtEur(p.ca / s.surface), m2Raw: Math.round(p.ca / s.surface),
        open: () => goMap(p.lat, p.lng, 13, true)
      };
    });
    if (sk !== 'rang'){
      const num = { score: 'score', hh: 'hhRaw', n: 'n', emprise: 'empriseRaw', ca: 'caRaw', m2: 'm2Raw' };
      zonesRows = zonesRows.slice().sort((a, b) => num[sk]
        ? (parseFloat(a[num[sk]]) - parseFloat(b[num[sk]])) * dir
        : String(a[sk] || '').localeCompare(String(b[sk] || '')) * dir);
    }
    const qq = (s.q || '').toLowerCase();
    const concAll = shops.filter(b => !qq
      || (b.name || '').toLowerCase().includes(qq) || (b.commune || '').toLowerCase().includes(qq) || (b.arr || '').toLowerCase().includes(qq));
    const concCount = fmtInt(concAll.length) + ' commerces' + (qq ? ' filtrés' : '') + ' · ' + fmtInt(concAll.filter(b => self.rating(b)).length) + ' notés';
    const concRows = (s.view === 'concurrents' ? concAll.slice(0, 400) : []).map(b => {
      const rv = s.ratings[b.id], r = self.rating(b), strong = self.isStrong(b);
      return {
        id: b.id, name: b.name, commune: b.commune || '—', arr: b.arr || '—',
        prov: (PROV.find(p => p.code === b.prov) || {}).name || '—',
        addr: b.addr || '', lat: b.lat, lng: b.lng,
        note: r || '', avis: (rv && rv.n) || '',
        comment: s.notes[b.id] || '',
        setComment: e => self.setComment(b.id, e.target.value),
        src: rv ? (rv.manual ? 'saisie' : 'Google') : '',
        force: Math.round(self.strength(b) * 100), strong: strong,
        color: strong ? R_COL.low : R_COL.mid,
        setNote: e => self.setRating(b.id, e.target.value),
        locate: () => goMap(b.lat, b.lng, 14, false)
      };
    });
    const arrRows = (s.view === 'arrondissements' ? arrNames : []).map(n => {
      const st = self.arrStats(n);
      return {
        arr: n, communes: st.communes, pop: fmtInt(st.pop), popRaw: st.pop,
        hh: fmtInt(st.hh), hhRaw: st.hh, market: fmtEur(st.market), marketRaw: Math.round(st.market),
        shops: st.shops, strong: st.strong, dens: st.dens.toFixed(1),
        avg: st.avg ? st.avg.toFixed(2) : '—', avgRaw: st.avg ? st.avg.toFixed(2) : '',
        perShop: st.shops ? fmtInt(st.hh / st.shops) : '—', perShopRaw: st.shops ? Math.round(st.hh / st.shops) : '',
        pick: () => { self._scroll['sc-table'] = 0; self.setState({ arr: n, view: 'map' }); }
      };
    }).sort((a, b) => {
      const num = { communes: 'communes', pop: 'popRaw', hh: 'hhRaw', market: 'marketRaw', shops: 'shops', strong: 'strong', dens: 'dens', avg: 'avgRaw', perShop: 'perShopRaw' };
      return num[sk] ? (parseFloat(a[num[sk]] || 0) - parseFloat(b[num[sk]] || 0)) * dir : String(a.arr).localeCompare(String(b.arr)) * (sk === 'arr' ? dir : 1);
    });

    const cmp = s.compare ? [['cmpA', s.cmpA || arrNames[0] || ''], ['cmpB', s.cmpB || arrNames[1] || '']].map(([k, name]) => {
      const st = name ? self.arrStats(name) : null;
      const bins = [[0, 2000], [2000, 4000], [4000, 7000], [7000, 11000], [11000, 16000], [16000, 1e9]];
      const labels = ['0', '2k', '4k', '7k', '11k', '16k+'];
      const counts = st ? bins.map(b => st.list.filter(c => c.hh >= b[0] && c.hh < b[1]).length) : [];
      const max = Math.max(1, ...counts);
      return {
        arr: name, options: arrOptions.slice(1),
        setArr: e => self.setState({ [k]: e.target.value }),
        histTitle: 'Ménages par commune',
        hist: counts.map((n, i) => ({ n: n, label: labels[i], h: Math.round(n / max * 100) + '%', color: '#c17a2a' })),
        rows: st ? [
          { k: 'Communes', v: fmtInt(st.communes) },
          { k: 'Population', v: fmtInt(st.pop) },
          { k: 'Ménages', v: fmtInt(st.hh) },
          { k: 'Marché boulangerie', v: fmtEur(st.market) },
          { k: 'Boulangeries / pâtisseries', v: fmtInt(st.shops) },
          { k: 'Dont concurrents forts', v: fmtInt(st.strong) },
          { k: 'Densité / 10.000 hab.', v: st.dens.toFixed(1) },
          { k: 'Note moyenne', v: st.avg ? st.avg.toFixed(2) + ' / 5' : 'non enrichie' },
          { k: 'Ménages par boulangerie', v: st.shops ? fmtInt(st.hh / st.shops) : '—' }
        ] : []
      };
    }) : [];

    const reseauRows = r => [
      { k: 'Population de la zone', v: fmtInt(r.pop) },
      { k: 'Ménages', v: fmtInt(r.hh) },
      { k: 'Taille des ménages', v: String(r.taille).replace('.', ',') },
      { k: 'Revenu moyen / ménage', v: fmtEur(r.revenu) },
      { k: 'Part de jeunes', v: String(r.jeunes).replace('.', ',') + ' %' },
      { k: 'Part d\'actifs', v: String(r.actifs).replace('.', ',') + ' %' },
      { k: 'Part de seniors', v: String(r.seniors).replace('.', ',') + ' %' },
      { k: 'Dépense boulangerie / ménage', v: fmtEur(r.depense) },
      { k: 'Marché boulangerie', v: fmtEur(r.marche) },
      { k: 'Emprise retenue', v: r.emprise ? String(r.emprise).replace('.', ',') + ' %' : '—' },
      { k: 'CA annuel TTC', v: r.ca ? fmtEur(r.ca) : '—' },
      { k: 'Surface nette', v: r.surface ? r.surface + ' m²' : '—' },
      { k: 'Rendement / m²', v: r.ca && r.surface ? fmtEur(r.ca / r.surface) : '—' },
      { k: 'CA hebdomadaire', v: r.ca ? fmtEur(r.ca / 52) : '—' }
    ];

    return Object.assign(this.liveVals(), {
      busy: s.busy, veil: s.busy && !s.bakeries.length, progress: s.progress, compare: s.compare, toast: s.toast,
      statusColor: s.err ? '#8D1D2C' : s.busy ? '#c17a2a' : '#1b5e20',
      statusLabel: s.err ? 'Erreur : ' + s.err : s.busy ? (s.progress || 'Chargement…') : fmtInt(s.bakeries.length) + ' commerces · ' + fmtInt(s.communes.length) + ' communes' + (self.osmDate() ? ' · OpenStreetMap relu le ' + self.osmDate() : '') + (self.useApi() ? '' : ' · saisies locales à ce navigateur'),
      provinces: PROV.map(p => ({
        name: p.name, on: !!s.prov[p.code],
        count: s.bakeries.filter(b => b.prov === p.code).length || '—',
        toggle: () => self.setState({ prov: Object.assign({}, s.prov, { [p.code]: !s.prov[p.code] }), sel: null })
      })),
      arr: s.arr, arrOptions: arrOptions,
      setArr: e => self.setState({ arr: e.target.value }),
      minRating: s.minRating, slideMinRating: this.slide('minRating', parseFloat), setMinRating: e => self.setState({ minRating: parseFloat(e.target.value) }),
      minHh: s.minHh, slideMinHh: this.slide('minHh', v => parseInt(v, 10)), setMinHh: e => self.setState({ minHh: parseInt(e.target.value, 10) }),
      radius: s.radius, slideRadius: this.slide('radius', parseFloat), setRadius: e => self.setParam({ radius: parseFloat(e.target.value) }),
      thresh: s.thresh, slideThresh: this.slide('thresh', parseFloat), setThresh: e => self.setParam({ thresh: parseFloat(e.target.value) }),
      layersPlus: [['prio', 'Zones prioritaires'], ['heat', 'Densité de ménages'], ['roads', 'Axes pendulaires']]
        .map(([k, name]) => ({ name: name, on: !!s.layers[k], toggle: layerToggle(k) })),
      layersMinus: [['excl', 'Zones d\'exclusion'], ['shops', 'Boulangeries concurrentes'], ['cluster', 'Regrouper les points au dézoom']]
        .map(([k, name]) => ({ name: name, on: !!s.layers[k], toggle: layerToggle(k) })),
      onlyPrio: onlyPrio,
      toggleOnlyPrio: () => self.setState({ layers: Object.assign({}, onlyPrio ? LAYERS_CONC : LAYERS_PRIO), sel: null }),
      minScore: s.minScore, slideMinScore: this.slide('minScore', v => parseInt(v, 10)),
      setMinScore: e => self.setState({ minScore: parseInt(e.target.value, 10) }),
      prioCount: s.layers.prio && s.communes.length ? String(self.scanPrio().length) : '—',
      presetPrio: () => self.setState({ layers: { shops: false, cluster: true, excl: false, prio: true, heat: true, roads: false }, sel: null }),
      presetConc: () => self.setState({ layers: Object.assign({}, LAYERS_CONC), sel: null }),
      exportParams: () => self.csv('ceo_parametres', ['parametre', 'valeur'], [
        ['depense_menage_eur', s.spend], ['emprise_imposee_pct', s.emprise], ['part_passage_pct', s.passage],
        ['surface_nette_m2', s.surface], ['emprise_max_pct', s.empriseMax], ['sensibilite_concurrence', s.compK],
        ['taille_menages', s.hhSize], ['rayon_exclusion_km', s.radius], ['seuil_concurrent_fort', s.thresh],
        ['score_minimum', s.minScore], ['provinces_actives', PROV.filter(p => s.prov[p.code]).map(p => p.name).join(' / ')],
        ['arrondissement', s.arr], ['date_export', new Date().toISOString().slice(0, 16).replace('T', ' ')]
      ]),
      params: [
        { k: 'Dépense boulangerie / ménage (€/an)', v: s.spend, set: e => self.setParam({ spend: parseFloat(e.target.value) || 0 }) },
        { k: 'Emprise imposée (%, 0 = calculée)', v: s.emprise, set: e => self.setParam({ emprise: parseFloat(e.target.value) || 0 }) },
        { k: 'Part du passage (%)', v: s.passage, set: e => self.setParam({ passage: Math.min(95, parseFloat(e.target.value) || 0) }) },
        { k: 'Surface nette cible (m²)', v: s.surface, set: e => self.setParam({ surface: parseFloat(e.target.value) || 1 }) },
        { k: 'Emprise maximale du modèle (%)', v: s.empriseMax, set: e => self.setParam({ empriseMax: parseFloat(e.target.value) || 30 }) },
        { k: 'Sensibilité à la concurrence', v: s.compK, set: e => self.setParam({ compK: parseFloat(String(e.target.value).replace(',', '.')) || 0.22 }) },
        { k: 'Taille moyenne des ménages', v: s.hhSize, set: e => { const val = parseFloat(String(e.target.value).replace(',', '.')) || HH_SIZE; self.setState({ hhSize: val }); setTimeout(() => self.recomputePop(), 0); } }
      ].map(p => Object.assign(p, { i: TIP_HYP[p.k] || '' })),
      empriseHint: s.emprise > 0 ? 'Emprise imposée à ' + s.emprise + ' % pour toutes les zones' : 'Emprise calculée : ' + s.empriseMax + ' % divisés par la pression concurrentielle du rayon',
      popCoverage: fmtInt(s.communes.filter(c => !c.est).length) + ' communes avec population source · ' + fmtInt(s.communes.filter(c => c.est).length) + ' estimées par densité des communes voisines',
      importPops: e => self.importPops(e),
      tips: TIP_REGL, concTips: TIP_CONC,
      gOk: !self.googleBlocage(),
      gLabel: !self.useApi() ? 'Hors ligne — les notes Google passent par l\'API du cockpit.'
        : self.googleOk() ? 'Connecteur Google actif · clé ' + ((s.gconf && s.gconf.empreinte) || '…') + ' (Paramètres).'
        : 'Aucune clé Google — à renseigner dans Paramètres › Général.',
      goParams: () => self.goParams(),
      gkeyHint: 'Enrichit les boulangeries visibles à l\'écran (40 max par lot) : le serveur interroge Google Places avec la clé de Paramètres — elle ne transite jamais par le navigateur — et le résultat est en cache partagé. Sans clé, la force du concurrent est estimée sur les signaux OSM (enseigne, site web, horaires, terrasse).',
      enrich: () => self.enrich(),
      enrichAll: () => self.enrichAll(),
      enrichAllLabel: s.enriching ? 'Interrompre (' + (s.enrichDone || 0) + '/' + (s.enrichTotal || '?') + ')' : 'Enrichir toute la sélection',
      enrichLabel: s.enriching ? 'Enrichissement… ' + (s.enrichDone || 0) + '/' + (s.enrichTotal || '?') : 'Enrichir les notes (vue actuelle)',
      reload: () => { if (!s.busy) self.load(true); },
      toggleCompare: () => self.setState({ compare: !s.compare, reseau: false }),
      compareCols: cmp,
      legend: [
        { color: R_COL.high, label: 'Note 4,5 et plus' },
        { color: R_COL.mid, label: 'Note 3,5 – 4,5' },
        { color: R_COL.low, label: 'Note inférieure à 3,5' },
        { color: R_COL.none, label: 'Note non renseignée' },
        { color: 'rgba(141,29,44,.35)', label: 'Zone d\'exclusion — concurrence forte' },
        { color: '#1b5e20', label: 'Zone prioritaire — score élevé' },
        { color: '#FAC775', label: 'Zone candidate retenue' }
      ],
      hasSel: !!x,
      selCommune: x ? x.commune : '',
      selGeo: x ? 'arr. ' + x.arr + ' · ' + x.prov + ' · ' + x.lat.toFixed(4) + ', ' + x.lng.toFixed(4) : '',
      selVerdict: x ? (x.blocked.length ? 'Zone exclue — concurrence forte' : x.score >= 55 ? 'Zone candidate prioritaire' : 'Zone candidate secondaire') : '',
      selVerdictBg: x ? (x.blocked.length ? 'rgba(141,29,44,.10)' : x.score >= 55 ? 'rgba(27,94,32,.10)' : 'var(--color-background-secondary)') : '',
      selVerdictColor: x ? (x.blocked.length ? '#8D1D2C' : x.score >= 55 ? '#1b5e20' : 'var(--color-text)') : '',
      selVerdictNote: x ? (x.blocked.length
        ? x.blocked.length + ' concurrent(s) fort(s) à moins de ' + s.radius.toFixed(1) + ' km : ' + x.blocked.slice(0, 3).map(o => o.b.name).join(', ')
        : 'Score d\'opportunité ' + x.score + '/100 — ménages accessibles pondérés par la pression concurrentielle.') : '',
      selRows: x ? [
        { k: 'Score d\'opportunité', v: x.score + ' / 100' },
        { k: 'Ménages dans le rayon', v: fmtInt(x.hh) },
        { k: 'Population communale', v: fmtInt(x.cmPop) + (x.cmEst ? ' (estimée)' : '') },
        { k: 'dont zone primaire', v: fmtInt(x.prim) },
        { k: 'Marché boulangerie', v: fmtEur(x.market) },
        { k: 'Dépense / ménage', v: fmtEur(s.spend) },
        { k: 'Boulangeries dans le rayon', v: fmtInt(x.near.length) + (x.blocked.length ? ' (dont ' + x.blocked.length + ' fortes)' : '') },
        { k: 'Pression concurrentielle', v: x.load.toFixed(2) },
        { k: 'Emprise ' + (s.emprise > 0 ? 'imposée' : 'estimée'), v: (x.emprise * 100).toFixed(1) + ' %' },
        { k: 'Passage', v: s.passage + ' %' },
        { k: 'Rendement annuel / m²', v: fmtEur(x.ca / s.surface) },
        { k: 'CA hebdomadaire', v: fmtEur(x.ca / 52) }
      ].map(r => Object.assign(r, { i: TIP_FICHE[r.k] || '' })) : [],
      selCa: x ? fmtEur(x.ca) : '',
      selCaDetail: x ? fmtInt(x.hh) + ' ménages × ' + fmtEur(s.spend) + ' × ' + (x.emprise * 100).toFixed(1) + ' % d\'emprise, majoré de ' + s.passage + ' % de passage · sur ' + s.surface + ' m²' : '',
      selCompetitors: x ? x.near.slice(0, 14).map(o => {
        const rv = s.ratings[o.b.id], r = self.rating(o.b);
        return {
          id: o.b.id, name: o.b.name, dist: o.d.toFixed(1) + ' km',
          color: self.isStrong(o.b) ? R_COL.low : R_COL.mid,
          note: r || '',
          setNote: e => self.setRating(o.b.id, e.target.value),
          comment: s.notes[o.b.id] || '',
          setComment: e => self.setComment(o.b.id, e.target.value),
          meta: (r ? r.toFixed(1) + '/5' + (rv && rv.manual ? ' saisie' : rv && rv.n ? ' · ' + rv.n + ' avis' : '') : 'non notée')
            + ' · force ' + Math.round(self.strength(o.b) * 100) + ' %'
        };
      }) : [],
      addCandidate: () => self.addCandidate(),
      exportCsv: () => self.exportCsv(),
      nCandidates: s.candidates.length,
      noCandidates: s.candidates.length === 0,
      candidates: s.candidates.map(c => ({
        name: c.commune, meta: fmtEur(c.ca) + ' · ' + fmtInt(c.hh) + ' ménages · score ' + c.score,
        focus: () => { if (self.map) self.map.setView([c.lat, c.lng], 12); self.evaluate(+c.lat, +c.lng); },
        remove: () => self.removeCandidate(c)
      })),
      histTitle: hist.title, hist: hist.bars,

      /* ----- modale réseau ----- */
      reseau: s.reseau,
      openReseau: () => self.setState({ reseau: true }),
      closeReseau: () => self.setState({ reseau: false }),
      reseauCols: RESEAU.map(r => ({
        nom: r.nom, statut: r.statut, rows: reseauRows(r),
        locate: () => { self.setState({ reseau: false, compare: false, view: 'map' }); setTimeout(() => { if (self.map) self.map.setView([r.lat, r.lng], 13); self.evaluate(r.lat, r.lng); }, 80); },
        applyDepense: () => self.setParam({ spend: r.depense })
      })),
      zoneCol: x ? {
        nom: 'Zone évaluée — ' + x.commune, statut: 'arr. ' + x.arr,
        rows: [
          { k: 'Population de la zone', v: fmtInt(x.hh * (s.hhSize || HH_SIZE)) },
          { k: 'Ménages', v: fmtInt(x.hh) },
          { k: 'Taille des ménages', v: String(s.hhSize).replace('.', ',') },
          { k: 'Revenu moyen / ménage', v: 'non chargé' },
          { k: 'Part de jeunes', v: '—' },
          { k: 'Part d\'actifs', v: '—' },
          { k: 'Part de seniors', v: '—' },
          { k: 'Dépense boulangerie / ménage', v: fmtEur(s.spend) },
          { k: 'Marché boulangerie', v: fmtEur(x.market) },
          { k: 'Emprise retenue', v: (x.emprise * 100).toFixed(1) + ' %' },
          { k: 'CA annuel TTC', v: fmtEur(x.ca) },
          { k: 'Surface nette', v: s.surface + ' m²' },
          { k: 'Rendement / m²', v: fmtEur(x.ca / s.surface) },
          { k: 'CA hebdomadaire', v: fmtEur(x.ca / 52) }
        ]
      } : null,
      hasZoneCol: !!x,

      /* ----- vues tabulaires ceo_ ----- */
      views: [['map', 'Carte'], ['zones', 'ceo_zones'], ['concurrents', 'ceo_concurrents'], ['arrondissements', 'ceo_arrondissements']]
        .map(([k, label]) => ({
          label: label,
          color: s.view === k ? 'var(--color-primary)' : 'var(--color-text-muted)',
          border: s.view === k ? 'var(--color-primary)' : 'transparent',
          go: () => { self._scroll['sc-table'] = 0; self.setState({ view: k, compare: false }); }
        })),
      isZones: s.view === 'zones',
      isConc: s.view === 'concurrents',
      isArr: s.view === 'arrondissements',
      q: s.q, setQ: e => self.setState({ q: e.target.value }),
      zonesRows: zonesRows,
      zonesEmpty: s.busy ? 'Chargement en cours…' : !this.map ? 'Carte indisponible.' : 'Aucune zone ne passe le score minimum ' + s.minScore + ' dans la vue courante — dézoome la carte ou abaisse le seuil.',
      zonesCols: [['rang', 'Rang'], ['commune', 'Localité'], ['arr', 'Arrondissement'], ['score', 'Score'], ['hh', 'Ménages'], ['n', 'Concurrents'], ['emprise', 'Emprise'], ['ca', 'CA estimé'], ['m2', '€/m²']]
        .map(([k, label]) => ({ label: label, sort: sortBy(k), tip: TIP_ZONES[k] || '' })),
      concRows: concRows,
      concCount: concCount,
      arrRows: arrRows,
      arrCols: [['arr', 'Arrondissement'], ['communes', 'Communes'], ['pop', 'Population'], ['hh', 'Ménages'], ['market', 'Marché'], ['shops', 'Commerces'], ['strong', 'Forts'], ['dens', '/ 10.000 hab.'], ['avg', 'Note moy.'], ['perShop', 'Ménages / point']]
        .map(([k, label]) => ({ label: label, sort: sortBy(k), tip: TIP_ARR[k] || '' })),
      exportZones: () => self.csv('ceo_zones', ['rang', 'localite', 'arrondissement', 'score', 'menages', 'concurrents', 'emprise_pct', 'ca_annuel_ttc', 'ca_par_m2', 'lat', 'lng',
        'hyp_depense_menage', 'hyp_passage_pct', 'hyp_surface_m2', 'hyp_emprise_max_pct', 'hyp_sensibilite', 'hyp_taille_menages', 'hyp_rayon_km'],
        zonesRows.map(r => [r.rang, r.commune, r.arr, r.score, r.hhRaw, r.n, r.empriseRaw, r.caRaw, r.m2Raw, r.lat.toFixed(5), r.lng.toFixed(5),
          s.spend, s.passage, s.surface, s.empriseMax, s.compK, s.hhSize, s.radius])),
      exportConc: () => self.csv('ceo_concurrents', ['nom', 'commune', 'arrondissement', 'province', 'note', 'avis', 'source_note', 'force_pct', 'concurrent_fort', 'commentaire', 'adresse', 'lat', 'lng'],
        concRows.map(r => [r.name, r.commune, r.arr, r.prov, r.note, r.avis, r.src, r.force, r.strong ? 'oui' : 'non', String(r.comment || '').replace(/[\r\n]+/g, ' '), r.addr, r.lat.toFixed(5), r.lng.toFixed(5)])),
      exportArr: () => self.csv('ceo_arrondissements', ['arrondissement', 'communes', 'population', 'menages', 'marche_boulangerie', 'commerces', 'concurrents_forts', 'densite_10000_hab', 'note_moyenne', 'menages_par_commerce'],
        arrRows.map(r => [r.arr, r.communes, r.popRaw, r.hhRaw, r.marketRaw, r.shops, r.strong, r.dens, r.avgRaw, r.perShopRaw]))
    });
  }
}
