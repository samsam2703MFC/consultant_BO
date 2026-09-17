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
  51: 'Ath', 52: 'Charleroi', 53: 'Mons', 54: 'Mouscron', 55: 'Soignies', 56: 'Thuin', 57: 'Tournai', 58: 'La Louvière',
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
  '51': 'WHT', '52': 'WHT', '53': 'WHT', '54': 'WHT', '55': 'WHT', '56': 'WHT', '57': 'WHT', '58': 'WHT',   // 58 : arrondissement de La Louvière (2019)
  '61': 'WLG', '62': 'WLG', '63': 'WLG', '64': 'WLG',
  '71': 'VLI', '72': 'VLI', '73': 'VLI',
  '81': 'WLX', '82': 'WLX', '83': 'WLX', '84': 'WLX', '85': 'WLX',
  '91': 'WNA', '92': 'WNA', '93': 'WNA'
};
// Points de comparaison du réseau — chiffres des études GeoConsulting :
// Sombreffe (étude de potentiel du 17-04-2024, p.25 comparaison au réseau,
// p.26 potentiel d'affaires : projet mesuré à 110 m²) et Halle (28-08-2024,
// p.26 et p.28 : projet mesuré à 250 m²). Max & Sandra et Berlo sont les
// zones de chalandise des magasins existants telles que l'étude les mesure.
const RESEAU = [
  { nom: 'L\'Atelier by Max & Sandra', statut: 'En exploitation', etude: '04/2024',
    pop: 6263, hh: 2613, taille: 2.4, revenu: 38454, jeunes: 19.7, actifs: 67.2, seniors: 13.2,
    marche: 1086800, depense: 416, emprise: null, ca: null, surface: null, lat: 50.46, lng: 4.44 },
  { nom: 'L\'Atelier by Berlo', statut: 'En exploitation', etude: '04/2024',
    pop: 30705, hh: 13821, taille: 2.2, revenu: 48327, jeunes: 17.8, actifs: 71.1, seniors: 11.0,
    marche: 7605148, depense: 550, emprise: null, ca: null, surface: null, lat: 50.63, lng: 5.57 },
  { nom: 'L\'Atelier by Harmonie — Sombreffe', statut: 'Projet mesuré · avril 2024', etude: '04/2024',
    pop: 19189, hh: 7522, taille: 2.55, revenu: 53283, jeunes: 19.25, actifs: 66.93, seniors: 13.82,
    marche: 4889480, depense: 650, emprise: 17.8, ca: 965644, surface: 110, lat: 50.5285, lng: 4.5885 },
  { nom: 'L\'Atelier by Halle', statut: 'Projet mesuré · août 2024', etude: '08/2024',
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
const PARAM_KEYS = ['spend', 'emprise', 'passage', 'surface', 'empriseMax', 'compK', 'hhSize', 'minScore', 'radius', 'thresh', 'weak', 'caVise', 'hhMin'];
const ZONING_BASE = 100;   // décalage des secteurs de zoning dans le cache partagé
// Le zoning est un fond de plan, pas une couche qu'on allume pour une question
// précise : il est coché d'origine dans les deux jeux, et se décoche comme les
// autres.
const LAYERS_CONC = { shops: true, cluster: true, excl: true, prio: false, heat: false, roads: false, zoning: true };
const LAYERS_PRIO = { shops: false, cluster: true, excl: false, prio: true, heat: false, roads: false, zoning: true };
const ZONE_MAX = 1200;     // au-delà, la vue est illisible : on garde les plus grandes
const ZONE_GENRE = { industrial: 'Zone industrielle', commercial: 'Zone d\'activité commerciale', retail: 'Zone de vente' };
const R_COL = { high: '#1b5e20', mid: '#c17a2a', low: '#8D1D2C', none: '#78554B' };
// « Ath » doit trouver Ath, « chatelet » Châtelet et « SAINT-GHISLAIN »
// Saint-Ghislain : on compare sans casse ni accents.
const sansAccent = v => String(v == null ? '' : v).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
// Ce que la carte peint, maille de 1 km² par maille : une valeur du modèle,
// quatre classes (quartiles de la sélection), une rampe. Le thème « potentiel »
// est la lecture de départ : ménages accessibles ÷ (concurrents + 1).
const THEMES = [
  ['potentiel', 'Ménages par point de vente', 'Ménages accessibles dans le rayon ÷ (concurrents du rayon + 1). Beaucoup de ménages pour peu de commerces : la maille est sous-servie.'],
  ['menages', 'Ménages accessibles', 'Population du recensement 2021 dans le rayon, divisée par la taille des ménages — là où les gens habitent.'],
  ['concurrence', 'Concurrents dans le rayon', 'Boulangeries et pâtisseries relevées dans OpenStreetMap à moins d\'un rayon de la maille.'],
  ['ca', 'CA annuel estimé', 'Le modèle de la fiche, maille par maille : ménages × dépense × emprise ÷ (1 − passage), l\'emprise descendant avec la pression concurrentielle.'],
  ['score', 'Score d\'opportunité', 'ménages ÷ 14 000 × 60 + emprise ÷ emprise max × 40, de 0 à 100 — le score des zones prioritaires.'],
  ['aucun', 'Rien — le fond de carte seul', '']
];
const THEME_RAMPE = {
  potentiel: ['#e9f0e6', '#bcd6b5', '#7fb076', '#2f7d32'],
  menages: ['#eaf0f4', '#c2d4e0', '#8fb0c6', '#3f6f92'],
  concurrence: ['#f7e9ea', '#e3b9bd', '#c77c85', '#8D1D2C'],
  ca: ['#e9f0e6', '#bcd6b5', '#7fb076', '#2f7d32'],
  score: ['#faf3e4', '#f0d9a0', '#dfae55', '#b8791c']
};
// Les outils de dessin de la zone d'étude. « point » est le geste d'origine :
// un clic, le rayon du curseur.
const OUTILS = [
  ['point', 'Évaluer un point', 'Un clic sur la carte : la zone est le rayon réglé à gauche.'],
  ['cercle', 'Cercle', 'Deux clics : le centre, puis le bord.'],
  ['polygone', 'Polygone', 'Un clic par sommet ; double-clic, ou clic sur le premier sommet, pour fermer. Échap annule.'],
  ['rectangle', 'Rectangle', 'Deux clics : deux coins opposés.'],
  ['isochrone', 'Isochrone', 'Un clic : la zone atteignable en ce temps de trajet, calculée sur le réseau routier (Valhalla, OpenStreetMap).']
];
const ISO_URL = 'https://valhalla1.openstreetmap.de/isochrone';
// Le même service, en matrice : les minutes de route de quelques points vers
// quelques autres (au plus cent paires et 150 km par appel).
const MATRICE_URL = 'https://valhalla1.openstreetmap.de/sources_to_targets';
const ISO_CHOIX = [['auto10', '10 min en voiture'], ['auto15', '15 min en voiture'], ['auto20', '20 min en voiture'], ['pedestrian15', '15 min à pied']];
const LEAFLET_DIR = 'assets/vendor/leaflet/';
const GRID_URL = 'assets/data/population_grid_2021.json';   // grille 1 km² du recensement 2021 (StatBel, diffusion Eurostat)
const LS = 'ceo_scouting';

// Info-bulles (i) : ce que chaque réglage change, et la formule de chaque champ
// calculé — les mêmes formules que evaluate(), scanPrio(), strength() et
// arrStats() ci-dessous, à tenir en phase avec elles.
const TIP_REGL = {
  minRating: 'Ne garde que les concurrents dont la note atteint ce minimum. Dès que le curseur dépasse 0, les commerces sans note sortent de la carte et des calculs.',
  minHh: 'Ne garde que les communes d\'au moins ce nombre de ménages (population ÷ taille des ménages) ; leurs commerces suivent.',
  radius: 'Rayon autour d\'un concurrent fort où aucune implantation n\'est retenue (zone rouge). C\'est aussi le rayon d\'évaluation d\'une zone : ménages, marché et concurrents y sont comptés. 2 km, soit 15 à 20 min en voiture.',
  weak: 'Note en dessous de laquelle un commerce n\'est pas tenu pour un concurrent : sa force tombe à zéro, il ne pèse ni dans la pression ni dans les zones rouges.\nforce = (note − ce seuil) ÷ (5 − ce seuil).',
  caVise: 'Chiffre d\'affaires annuel TTC en dessous duquel une zone n\'est ni tracée ni listée. À 0, aucun plancher — seul le score minimum filtre.',
  thresh: 'Note à partir de laquelle un concurrent est « fort » : zone rouge autour de lui, et poids × 1,5 dans la pression concurrentielle.\nSans note, il est fort si sa force OSM ≥ 0,75 − (5 − seuil) × 0,05.',
  minScore: 'Score d\'opportunité en dessous duquel une zone n\'est ni tracée sur la carte ni listée dans les zones candidates.\nscore = ménages du rayon ÷ 14 000 × 60 + emprise ÷ emprise max × 40, de 0 à 100.',
  ca: 'CA annuel TTC = ménages du rayon × dépense par ménage × emprise ÷ (1 − passage).'
};
const TIP_HYP = {
  'Dépense boulangerie / ménage (€/an)': 'Ce qu\'un ménage dépense par an en boulangerie-pâtisserie — études GeoConsulting : 650 € à Sombreffe (04-2024), 586 € à Halle (08-2024), 550 € à Berlo, 416 € chez Max & Sandra.\nMarché du rayon = ménages du rayon × dépense.',
  'Emprise imposée (%, 0 = calculée)': 'Part du marché du rayon captée par le point. À 0, l\'emprise est calculée depuis la concurrence (emprise max, sensibilité, pression). Une valeur > 0 s\'applique telle quelle à toutes les zones — Halle mesurée : 15,5 %.',
  'Part du passage (%)': 'Part du CA apportée par la clientèle de passage, en plus des ménages du rayon.\nCA = CA des ménages ÷ (1 − passage) ; à 15 %, CA des ménages ÷ 0,85.',
  'Surface nette cible (m²)': 'Surface de vente du projet. N\'entre que dans le rendement : €/m² = CA annuel ÷ surface (Halle : 1 296 881 € sur 250 m²).',
  'Emprise maximale du modèle (%)': 'Emprise d\'un point sans aucun concurrent dans le rayon. Chaque concurrent la fait baisser :\nemprise = emprise max ÷ (1 + sensibilité × pression), plancher 4 %.',
  'Sensibilité à la concurrence': 'Vitesse à laquelle la concurrence fait baisser l\'emprise.\nemprise = emprise max ÷ (1 + sensibilité × pression concurrentielle)\nAvec 0,22 et une emprise max de 30 % : pression 0 → 30 % ; pression 1 → 24,6 % ; pression 4,5 → 15,1 % (proche de Halle). Plus la valeur est haute, plus la même concurrence pèse.',
  'Taille moyenne des ménages': 'Personnes par ménage, pour passer de la population des communes aux ménages : ménages = population ÷ taille. Belgique 2,31 (étude : 2,34 en Flandre).'
};
const TIP_FICHE = {
  'Score d\'opportunité': 'score = ménages du rayon ÷ 14 000 × 60 + emprise ÷ emprise max × 40, borné de 0 à 100.\n14 000 ménages dans le rayon valent les 60 points de potentiel ; l\'emprise — donc la concurrence — vaut les 40 points restants.',
  'Ménages dans le rayon': 'Population des cellules de 1 km² du recensement 2021 (StatBel, grille Eurostat) dont le centre est dans le rayon — les cellules de bord comptent au prorata — divisée par la taille moyenne des ménages. Sans grille : part du territoire de chaque commune comprise dans le rayon.',
  'Population communale': 'Population de la commune la plus proche du point : relation OSM, ou CSV StatBel importé. « Estimée » = déduite de la densité médiane des communes voisines.',
  'dont zone primaire': 'Même calcul sur un rayon réduit à 55 % : la clientèle la plus proche, celle qui vient sans détour.',
  'Marché boulangerie': 'Marché = ménages du rayon × dépense boulangerie par ménage (hypothèse).',
  'Dépense / ménage': 'Hypothèse du modèle : dépense annuelle d\'un ménage en boulangerie-pâtisserie.',
  'Boulangeries dans le rayon': 'Concurrents de la sélection à moins de « rayon » km du point. « Fortes » : note ≥ seuil « concurrent fort », ou force OSM élevée sans note.',
  'Pression concurrentielle': 'Σ, sur les concurrents du rayon, de : force (0 à 1) × (1 − 0,6 × distance ÷ rayon) × 1,5 si le concurrent est fort.\nUn concurrent au centre pèse toute sa force, un concurrent en bord de rayon 40 % de sa force. Force = (note − 3) ÷ 2, ou signaux OSM sans note.',
  'Emprise estimée': 'emprise = emprise max ÷ (1 + sensibilité × pression concurrentielle), entre 4 % et l\'emprise max.',
  'Emprise imposée': 'Emprise fixée dans les hypothèses : la même pour toutes les zones, la concurrence ne la modifie pas.',
  'Passage': 'Majoration par la clientèle de passage : CA = CA des ménages ÷ (1 − passage).',
  'Rendement annuel / m²': '€/m² = CA annuel ÷ surface nette cible (hypothèse).',
  'CA hebdomadaire': 'CA annuel ÷ 52.',
  'Ménages dans la zone': 'Population des cellules de 1 km² du recensement 2021 dont le centre est dans la zone dessinée, divisée par la taille moyenne des ménages. Le rayon équivalent est celui du disque de même aire : c\'est lui qui sert au modèle.',
  'Boulangeries dans la zone': 'Concurrents de la sélection dont le point est dans la zone dessinée. « Fortes » : à moins d\'un rayon du centre, avec une note ≥ seuil « concurrent fort ».',
  'Chaînes dans la zone': 'Enseignes de chaîne (marque relevée par OpenStreetMap, ou nom connu) parmi les concurrents de la zone.',
};
const TIP_ZONES = {
  forts: 'Concurrents forts dans le rayon : note ≥ seuil « concurrent fort », ou force OSM élevée sans note.',
  chaines: 'Enseignes de chaîne dans le rayon — la marque relevée par OpenStreetMap, ou un nom connu (Paul, Panos, Délifrance, Le Pain Quotidien…). Une chaîne déjà là dit que la zone de chalandise tient.',
  score: TIP_FICHE['Score d\'opportunité'],
  hh: 'Population des cellules de 1 km² du recensement 2021 dans le rayon autour du point balayé (cellules de bord au prorata), divisée par la taille des ménages — le même calcul que la fiche.',
  n: 'Concurrents de la sélection à moins de « rayon » km du point. Une zone à moins de « rayon » km d\'un concurrent fort n\'est pas retenue (zone rouge).',
  emprise: 'emprise = emprise max ÷ (1 + sensibilité × pression), entre 4 % et l\'emprise max — ou l\'emprise imposée. Pression = Σ force × (1 − 0,6 × distance ÷ rayon).',
  ca: 'CA annuel TTC = ménages × dépense par ménage × emprise ÷ (1 − passage).',
  m2: '€/m² = CA annuel ÷ surface nette cible.'
};
const TIP_ARR = {
  communes: 'Communes OSM (admin_level 8) rattachées à l\'arrondissement.',
  pop: 'Somme des populations communales : grille du recensement 2021 par commune, CSV StatBel importé s\'il existe, sinon OSM ou estimation.',
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
// Aire commune à deux disques de rayons r1 et r2 dont les centres sont à d km.
const lens = (r1, r2, d) => {
  if (d >= r1 + r2) return 0;
  if (d <= Math.abs(r1 - r2)){ const r = Math.min(r1, r2); return Math.PI * r * r; }
  const a = r1 * r1, b = r2 * r2;
  return a * Math.acos((d * d + a - b) / (2 * d * r1)) + b * Math.acos((d * d + b - a) / (2 * d * r2))
    - 0.5 * Math.sqrt((-d + r1 + r2) * (d + r1 - r2) * (d - r1 + r2) * (d + r1 + r2));
};
// Point dans un polygone (lancer de rayon), aire en km² (formule du lacet sur
// une projection locale), centre de gravité — ce qu'il faut pour une zone
// dessinée à la main.
const dansPoly = (poly, lat, lng) => {
  let ok = false;
  for (let i = 0, j = poly.length - 1; i < poly.length; j = i++){
    const a = poly[i], b = poly[j];
    if ((a[0] > lat) !== (b[0] > lat) && lng < (b[1] - a[1]) * (lat - a[0]) / (b[0] - a[0]) + a[1]) ok = !ok;
  }
  return ok;
};
const airePoly = poly => {
  if (poly.length < 3) return 0;
  const lat0 = poly[0][0], kx = 111.2 * Math.cos(lat0 * Math.PI / 180), ky = 111.2;
  let a = 0;
  for (let i = 0, j = poly.length - 1; i < poly.length; j = i++){
    a += (poly[j][1] * kx) * (poly[i][0] * ky) - (poly[i][1] * kx) * (poly[j][0] * ky);
  }
  return Math.abs(a) / 2;
};
const centrePoly = poly => {
  let lat = 0, lng = 0;
  poly.forEach(p => { lat += p[0]; lng += p[1]; });
  return [lat / poly.length, lng / poly.length];
};
const boitePoly = poly => {
  const b = { s: 90, n: -90, w: 180, e: -180 };
  poly.forEach(p => { b.s = Math.min(b.s, p[0]); b.n = Math.max(b.n, p[0]); b.w = Math.min(b.w, p[1]); b.e = Math.max(b.e, p[1]); });
  return b;
};
const cerclePoly = (lat, lng, rKm) => {
  const out = [], kx = 111.2 * Math.cos(lat * Math.PI / 180);
  for (let a = 0; a < 360; a += 6){ const t = a * Math.PI / 180; out.push([lat + rKm / 111.2 * Math.cos(t), lng + rKm / kx * Math.sin(t)]); }
  return out;
};
// Web Mercator : le pixel d'un point, au zoom z, dans le monde des tuiles.
const merc = (lat, lng, z) => {
  const n = 256 * Math.pow(2, z), si = Math.sin(lat * Math.PI / 180);
  return [(lng + 180) / 360 * n, (0.5 - Math.log((1 + si) / (1 - si)) / (4 * Math.PI)) * n];
};
const slugDe = v => String(v || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'zone';
const nomCourt = nom => String(nom || '').replace(/^L['’]?\s*Atelier by\s*-?\s*/i, '').replace(/^Atelier by\s*-?\s*/i, '') || String(nom || '');
const pct1 = v => (v * 100).toFixed(1).replace('.', ',') + ' %';
const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const medOf = a => { const s = a.slice().sort((x, y) => x - y); return s[Math.floor(s.length / 2)]; };
const pickParams = v => { const o = {}; PARAM_KEYS.forEach(k => { if (v && typeof v[k] === 'number' && isFinite(v[k])) o[k] = v[k]; });
  // `nMax` est nul quand le filtre est éteint : il ne passe pas le test des
  // nombres, et se relit donc à part.
  if (v && (v.nMax === null || (typeof v.nMax === 'number' && isFinite(v.nMax)))) o.nMax = v.nMax;
  if (v && (v.zoneMax === null || (typeof v.zoneMax === 'number' && isFinite(v.zoneMax)))) o.zoneMax = v.zoneMax;
  return o; };

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
      // Les deux bornes de la concurrence, et le chiffre visé. `weak` était
      // écrit en dur dans strength() ; `caVise` à 0 = pas de filtre par CA.
      weak: 3, caVise: 0,
      // Le terrain : nombre maximum de concurrents dans le rayon (null = sans
      // limite, 0 = aucune boulangerie) et ménages minimum dans le rayon.
      nMax: null, hhMin: 0,
      // Zoning industriel : distance maximale à une zone d'activité (km),
      // null = filtre éteint. Les zones vivent dans `zoning`.
      zoneMax: null, zoning: [],
      // L'assistant : 0 fermé, 1..4 l'étape ouverte ; wizFait = le bandeau de résultat.
      wiz: 0, wizFait: false,
      sel: null, candidates: [], compare: false, cmpA: '', cmpB: '',
      view: 'map', sortKey: 'score', sortDir: -1, q: '', ville: '', wville: '', stop: false, reseau: false, notes: {},
      gconf: null, magasins: [], placing: null, enriching: false, enrichDone: 0, enrichTotal: 0, ratings: {}, pops: {}, toast: null,
      // Ce que la carte peint (thème, classes éteintes), les sections repliées
      // du panneau gauche, l'outil de dessin et le temps de l'isochrone :
      // des préférences de vue, gardées dans ce navigateur, jamais en base.
      theme: 'potentiel', themeOff: {}, plis: { filtres: false, couches: false, hyp: false, calage: false, sources: false },
      tool: 'point', iso: 'auto10', isoBusy: false, dessin: 0,
      // Le dossier d'implantation : ouvert sur la fiche courante, avec la zone
      // retenue dont il vient (pour dire ce qui a changé depuis), et sa carte.
      dossier: false, dossierCand: null, dossierImg: '', dossierBusy: false,
      // Les points de comparaison ajoutés à la main, et le formulaire ouvert.
      references: [], refForm: null,
      // L'échelle de lecture (maille, commune, arrondissement) et les trois
      // lectures côte à côte : la carte principale garde son thème, les deux
      // autres panneaux ont le leur.
      echelle: 'maille', trio: false, trio2: 'concurrence', trio3: 'ca',
      // Le plan d'expansion : les meilleures zones de chaque province, ce
      // qu'elles peuvent dégager, en deux clics. N zones par province, avec
      // ou sans le score minimum.
      plan: false, planN: 5, planSeuil: false, planImg: '', planBusy: false,
      // L'écart minimum entre deux ouvertures du plan, en minutes de voiture
      // (0 = aucun), et la même distance vis-à-vis des magasins déjà ouverts.
      planEcart: 20, planReseau: true,
      // planRoute : l'écart se mesure en vraies minutes de route (service de
      // routage) plutôt qu'à vol d'oiseau ; planSel garde la sélection calculée
      // et sa clé, planCalc l'avancement pendant le calcul.
      planRoute: true, planSel: null, planCalc: null
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
      + '<div style="flex:1 1 auto;position:relative;min-width:320px"><div id="scout-map" style="position:absolute;inset:0;background:#EAE4DC"></div>'
      + '<div id="scout-map2" class="sc-panneau" style="left:33.34%;right:33.33%"></div><div id="scout-map3" class="sc-panneau" style="left:66.67%;right:0"></div>'
      + '<div data-sc-part="mapui"></div></div>'
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
    const vue = ls.get('vue') || {};
    if (THEMES.some(t => t[0] === vue.theme)) s.theme = vue.theme;
    if (vue.themeOff && typeof vue.themeOff === 'object') s.themeOff = vue.themeOff;
    if (vue.plis && typeof vue.plis === 'object') s.plis = Object.assign({}, s.plis, vue.plis);
    if (ISO_CHOIX.some(c => c[0] === vue.iso)) s.iso = vue.iso;
    if (['maille', 'commune', 'arrondissement'].includes(vue.echelle)) s.echelle = vue.echelle;
    if (THEMES.some(t => t[0] === vue.trio2 && t[0] !== 'aucun')) s.trio2 = vue.trio2;
    if (THEMES.some(t => t[0] === vue.trio3 && t[0] !== 'aucun')) s.trio3 = vue.trio3;
    s.ratings = ls.get('ratings') || {};
    s.notes = ls.get('notes') || {};
    s.candidates = ls.get('cand') || [];
    s.pops = ls.get('pops') || {};
    s.references = ls.get('refs') || [];
  }

  /* --- cycle de rendu ------------------------------------------------------- */
  setState(patch){
    Object.assign(this.state, typeof patch === 'function' ? patch(this.state) : patch);
    this.render();
  }

  reg(fn){ this._h.push(fn); return this._h.length - 1; }

  // préférences de vue : thème, classes éteintes, sections repliées, isochrone
  setVue(patch){
    this.setState(patch);
    const s = this.state;
    ls.set('vue', { theme: s.theme, themeOff: s.themeOff, plis: s.plis, iso: s.iso, echelle: s.echelle, trio2: s.trio2, trio3: s.trio3 });
  }

  render(){
    if (!this.el.isConnected) return;
    this.hideTip();
    this._h = [];
    const x = {
      A: fn => fn ? `data-sh="${this.reg(fn)}"` : '',
      C: fn => fn ? `data-sc="${this.reg(fn)}"` : '',
      I: fn => fn ? `data-si="${this.reg(fn)}"` : '',
      K: fn => fn ? `data-sk="${this.reg(fn)}"` : '',
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
    // L'arrondissement trouvé par la recherche peut être n'importe où dans le
    // tableau de l'étape 2 : on l'amène sous les yeux, une fois, au changement.
    const tr = this.el.querySelector('.wz-t tr.on');
    if (!tr) this._arrVu = null;
    else if (this._arrVu !== s.wiz + '|' + s.arr){
      this._arrVu = s.wiz + '|' + s.arr;
      try { tr.scrollIntoView({ block: 'nearest' }); } catch (e) { /* navigateur sans options */ }
    }
    this.el.classList.toggle('sc-overlay-open', !!(s.reseau || s.compare || s.view !== 'map' || s.dossier || s.plan));
    if (this.el.classList.contains('sc-trio') !== !!s.trio){
      this.el.classList.toggle('sc-trio', !!s.trio);
      setTimeout(() => { try { this.ajusterTrio(); } catch (e) { console.error('[scouting] trio', e); } }, 30);
    }
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
    this.el.addEventListener('keydown', e => run('data-sk', e));
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
      s.spend, s.passage, s.emprise, s.empriseMax, s.compK, s.compare ? 1 : 0, s.weak, s.caVise, s.nMax, s.hhMin, s.zoneMax, s.zoning.length, s.wizFait ? 1 : 0,
      s.theme, JSON.stringify(s.themeOff), s.sel && s.sel.zone ? 'zone' + s.sel.zone.poly.length : '', this._grid ? 1 : 0,
      s.echelle, s.trio ? 1 : 0, s.trio2, s.trio3].join('|');
  }

  scheduleRedraw(ms){
    clearTimeout(this._rd);
    this._rd = setTimeout(() => { try { this.redraw(); } catch (e) { console.error('[scouting] redraw', e); } }, ms);
  }

  /* Le zoning industriel : neuf secteurs, cache serveur puis cache navigateur.
   * Jamais Overpass depuis le navigateur — c'est une donnée de confort, pas de
   * quoi faire attendre l'écran. Absente, le filtre le dit et reste éteint. */
  async chargerZoning(api){
    if (this._zLoading) return;
    this._zLoading = true;
    const vues = {}, out = [];
    for (let i = 0; i < TILES.length; i++){
      // Le serveur d'abord, comme pour les commerces : le relevé du dimanche
      // ajoute des secteurs, et un navigateur qui se contenterait de sa copie
      // resterait sur le cache partiel du jour où il est passé.
      let d = null;
      if (api){
        try { d = await apiGet('/scouting/tiles/' + (ZONING_BASE + i), 30000); } catch (e) { d = null; }
        if (d && d.z) ls.set('z' + i, d); else d = null;
      }
      if (!d) d = ls.get('z' + i);
      if (!d || !d.z) continue;
      d.z.forEach(z => {
        const k = z.lat.toFixed(4) + ',' + z.lng.toFixed(4);
        if (!vues[k]){ vues[k] = 1; out.push(z); }
      });
    }
    this._zLoading = false;
    if (out.length){ this.setState({ zoning: out }); }
  }

  /* --- persistance des saisies ---------------------------------------------- */
  paramsObj(){ const o = {}; PARAM_KEYS.forEach(k => { o[k] = this.state[k]; }); o.nMax = this.state.nMax; o.zoneMax = this.state.zoneMax; return o; }

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
    // Les magasins du réseau (position, CA réel) arrivent à part : la fiche
    // Google peut prendre quelques secondes la première fois.
    apiGet('/scouting/reseau', 30000).then(r => { this.setState({ magasins: (r && r.magasins) || [] }); }).catch(e => console.warn('[scouting] /scouting/reseau :', e.message));
    if (!d) return;
    this._serverTiles = {};
    (d.tiles || []).forEach(t => { this._serverTiles[t.sector] = t; });
    const ratings = {}, notes = {};
    (d.competitors || []).forEach(r => {
      if (r.rating != null) ratings[r.id] = { rating: +r.rating, n: +(r.reviews || 0), manual: r.source === 'manuel' };
      else if (r.source === 'google') ratings[r.id] = { rating: null, n: 0 };   // déjà interrogé, sans note
      if (r.comment) notes[r.id] = r.comment;
    });
    const patch = { ratings, notes, candidates: d.candidates || [], pops: d.populations || {}, references: Array.isArray(d.references) ? d.references : [] };
    ls.set('refs', patch.references);
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
    // Ajouté avant les autres : ses tracés se posent sous les points, les
    // zones rouges et les zones prioritaires, jamais par-dessus.
    this.gZone = L.layerGroup().addTo(map);
    this.gShops = L.layerGroup().addTo(map);
    this.gExcl = L.layerGroup().addTo(map);
    this.gRoads = L.layerGroup();
    this.gSel = L.layerGroup().addTo(map);
    this.gDraw = L.layerGroup().addTo(map);   // le tracé en cours
    // La peinture des mailles : un canvas dans un pane sous les tracés
    // vectoriels (400) et au-dessus des tuiles (200). Il ne reçoit aucun clic.
    map.createPane('sc-raster').style.zIndex = 350;
    map.getPane('sc-raster').style.pointerEvents = 'none';
    const self = this;
    const Raster = L.Layer.extend({
      initialize(opts){ this.opts = opts || {}; },
      onAdd(m){
        this._c = L.DomUtil.create('canvas', 'leaflet-zoom-hide');
        this._c.style.position = 'absolute';
        m.getPane('sc-raster').appendChild(this._c);
        m.on('moveend zoomend viewreset resize', this._peindre, this);
        this._peindre();
      },
      onRemove(m){ L.DomUtil.remove(this._c); m.off('moveend zoomend viewreset resize', this._peindre, this); },
      _peindre(){ try { self.paintRaster(this._c, this._map, this.opts.theme ? this.opts.theme() : null, !!this.opts.dots); } catch (e) { console.error('[scouting] mailles', e); } }
    });
    this.Raster = Raster;
    this.raster = new Raster({}).addTo(map);
    this.rasters = [];
    map.on('click', e => this.onMapClick(e));
    map.on('dblclick', e => { if (this._draw && this._draw.type === 'polygone') this.finirPolygone(); });
    map.on('mousemove', e => { if (this._draw) this.tracerTemp(e.latlng); });
    this._onKey = e => { if (e.key === 'Escape' && this._draw){ this.cancelDraw(); this.notify('Tracé annulé'); } };
    document.addEventListener('keydown', this._onKey);
    map.on('zoomend moveend', () => {
      if (!this.state.communes.length) return;
      this.scheduleRedraw(120);
      // Le balayage dépend de la vue : le bandeau de l'assistant compte des
      // points chauds qui changent quand la carte bouge. Sans ce rendu, il
      // affichait le compte d'avant le déplacement.
      if (this.state.wizFait){ clearTimeout(this._wizR); this._wizR = setTimeout(() => this.render(), 260); }
    });
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
        // « bb » : la boîte englobante de la commune — son emprise réelle ; le
        // centre est celui de la boîte, comme « center » le rendait.
        const bb = e.bounds || null;
        const c = e.center || (bb ? { lat: (bb.minlat + bb.maxlat) / 2, lon: (bb.minlon + bb.maxlon) / 2 } : {});
        if (!prov || !c.lat) return;
        const pop = parseInt((t.population || '').replace(/[^0-9]/g, ''), 10);
        const com = { id: e.id, name: t['name:fr'] || t.name || '—', nl: t.name || '', ins: ins,
          arr: ARR[parseInt(ins.slice(0, 2), 10)] || '—', prov: prov, pop: pop || 0, lat: c.lat, lng: c.lon };
        if (bb) com.bb = [bb.minlat, bb.minlon, bb.maxlat, bb.maxlon];
        tc.push(com);
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
    const gridP = this.loadGrid();   // la grille de population, en parallèle des secteurs
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
          r = await this.overpass('[out:json][timeout:240];rel(' + bbox + ')["boundary"="administrative"]["admin_level"="8"];out tags bb;'
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
    // la grille manque encore ? on lui laisse huit secondes, puis on affiche sans
    // elle et on la reprend dès qu'elle arrive
    if (!this._grid){
      await Promise.race([gridP, new Promise(res => setTimeout(res, 8000))]);
      if (!this._grid) gridP.then(g => { if (g && !this._loading){ this.fillPop(this.state.communes, null); this._rev++; this.setState({}); } });
    }
    this._loading = false;
    // Le zoning industriel se lit APRÈS, et sans bloquer : il n'est utile qu'à
    // un filtre facultatif, et son absence ne doit rien empêcher.
    this.chargerZoning(api);
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
    const g = this._grid;
    if (g){
      // la grille du recensement fait foi pour la commune, sauf CSV importé
      cs.forEach(c => {
        if (off[c.ins]) return;
        const p = g.byNis[c.ins];
        if (p){ c.pop = p; c.est = false; c.official = true; c.grille = true; c.hh = Math.round(p / hs); }
      });
    }
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
  // Le rayon habité de chaque commune. D'abord son emprise réelle : la boîte
  // englobante OSM, dont un territoire irrégulier remplit ~60 % — l'écart aux
  // communes voisines, seul repère avant, donnait 3,7 km à Anvers pour 8 réels
  // et faisait déborder toute sa population sur le port. Sans boîte (ancien
  // cache), on retombe sur l'écart aux voisines.
  index(communes){
    const cs = communes || [];
    const areas = this._grid && this._grid.areas;
    cs.forEach(c => {
      let r = null;
      // la surface exacte de la commune (contours LAU livrés avec la grille) prime
      if (areas && areas[c.ins] > 0.5) r = Math.sqrt(areas[c.ins] / Math.PI);
      if (r === null && c.bb && c.bb.length === 4){
        const dLat = (c.bb[2] - c.bb[0]) * 111, dLng = (c.bb[3] - c.bb[1]) * 111 * Math.cos(c.lat * Math.PI / 180);
        const area = 0.6 * dLat * dLng;
        if (area > 0.5) r = Math.sqrt(area / Math.PI);
      }
      if (r === null){
        const ds = [];
        cs.forEach(o => { if (o !== c) ds.push(dist(c.lat, c.lng, o.lat, o.lng)); });
        ds.sort((a, b) => a - b);
        const near = ds.slice(0, 3);
        const m = near.length ? near.reduce((a, b) => a + b, 0) / near.length : 7;
        r = m * 0.62;
      }
      c.rKm = Math.max(1.3, r);
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
      + (this.estChaine(b) ? '<br><b>Enseigne de chaîne — ' + esc(this.marqueDe(b)) + '</b>' : '')
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
      const r = this.rating(b), ch = this.estChaine(b);
      const col = !r ? R_COL.none : r >= 4.5 ? R_COL.high : r >= 3.5 ? R_COL.mid : R_COL.low;
      // Une enseigne de chaîne porte un liseré noir : on la reconnaît de loin,
      // quelle que soit sa note.
      return L.circleMarker([b.lat, b.lng], { renderer: this.vecR, radius: ch ? 6 : 5, color: ch ? '#221E1A' : '#fff', weight: ch ? 2.2 : 1, fillColor: col, fillOpacity: .95 })
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
      // Une pastille DISCRÈTE : petite, sombre et translucide, le fond de
      // carte reste lisible dessous. Le blanc opaque d'avant faisait un mur
      // de ronds qui cachait les zones et les communes. Le liseré rouge ne
      // reste que là où la moitié des commerces sont des concurrents forts.
      const d = 16 + Math.min(8, Math.round(Math.log(arr.length) * 3));
      const fort = strong / arr.length >= 0.5;
      const m = L.marker([lat, lng], {
        icon: L.divIcon({
          className: '', iconSize: [d, d], iconAnchor: [d / 2, d / 2],
          html: '<div style="width:' + d + 'px;height:' + d + 'px;border-radius:50%;display:flex;align-items:center;justify-content:center;'
            + 'background:rgba(40,34,30,.3);border:' + (fort ? '1px solid rgba(141,29,44,.8)' : 'none') + ';color:#fff;'
            + 'font-family:Gotham,sans-serif;font-size:' + (arr.length >= 100 ? 8 : 9) + 'px;font-weight:500;letter-spacing:-0.02em;'
            + 'text-shadow:0 0 3px rgba(0,0,0,.7)">' + arr.length + '</div>'
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
    // Assistant en cours : le balayage ne suit plus la vue mais les
    // arrondissements retenus — sinon la carte, le bandeau et ceo_zones
    // montreraient trois comptes différents du même territoire.
    if (this.state.wizFait) return this.scanArrs();
    if (!this.map) return [];
    const s = this.state, R = s.radius;
    const b = this.map.getBounds();
    const key = [b.toBBoxString(), R, s.thresh, s.minScore, s.arr, JSON.stringify(s.prov), s.minRating, s.minHh, this._rev,
      s.spend, s.passage, s.emprise, s.empriseMax, s.compK, s.weak, s.caVise, s.nMax, s.hhMin, s.zoneMax, s.zoning.length].join('|');
    if (key === this._scanKey) return this._scanVal;
    const shops = this.shops();
    const strong = shops.filter(x => this.isStrong(x));
    const cs = this.filteredCommunes();
    const out = [];
    if (cs.length){
      const stepKm = Math.max(R * 0.9, 1.2);
      const mid = (b.getNorth() + b.getSouth()) / 2;
      // toutes les communes à portée du rayon, sélectionnées ou non : les
      // ménages ne disparaissent pas quand on décoche une province
      const pLat = (R + 12) / 111, pLng = (R + 12) / (111 * Math.cos(mid * Math.PI / 180));
      const toutes = s.communes.filter(c => c.lat >= b.getSouth() - pLat && c.lat <= b.getNorth() + pLat && c.lng >= b.getWest() - pLng && c.lng <= b.getEast() + pLng);
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
          const hh = this.householdsIn(lat, lng, R, toutes);
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
    // Le CA visé filtre AVANT le regroupement par commune : une commune dont
    // le meilleur point n'atteint pas le montant ne doit pas apparaître.
    const best = {};
    out.filter(p => this.zoneRetenue(p)).forEach(p => {
      const k = p.commune + '|' + p.arr;
      if (!best[k] || p.score > best[k].score) best[k] = p;
    });
    const res = Object.keys(best).map(k => best[k]).sort((x, y) => (y.score - x.score) || (y.ca - x.ca)).slice(0, 30);   // ex æquo au score : le CA départage
    this._scanKey = key; this._scanVal = res;
    return res;
  }

  // Top 5 par province : le même balayage que ceo_zones, mais sur l'emprise
  // entière de chaque province cochée — indépendant du cadrage de la carte —
  // et sans score minimum : ce sont les cinq meilleures communes disponibles,
  // le score dit ce qu'elles valent. Les commerces sont rangés par cases d'un
  // rayon de côté : un point ne regarde que ses neuf cases voisines, et onze
  // provinces se balaient en une fraction de seconde.
  scanTop5(max){
    const s = this.state, R = s.radius;
    max = max || 5;
    const key = [R, s.thresh, s.arr, JSON.stringify(s.prov), s.minRating, s.minHh, this._rev,
      s.spend, s.passage, s.emprise, s.empriseMax, s.compK, max].join('|');
    if (key === this._top5Key) return this._top5Val;
    const shopsAll = this.shops();
    const cs = this.filteredCommunes();
    const eMax = (s.empriseMax || 30) / 100;
    const pad = R + 2;   // km : communes et concurrents juste hors province comptent aussi
    const out = [];
    PROV.filter(p => s.prov[p.code]).forEach(p => {
      const mine = cs.filter(c => c.prov === p.code);
      if (!mine.length) return;
      let s0 = 90, n0 = -90, w0 = 180, e0 = -180;
      mine.forEach(c => { s0 = Math.min(s0, c.lat); n0 = Math.max(n0, c.lat); w0 = Math.min(w0, c.lng); e0 = Math.max(e0, c.lng); });
      const kLat = 1 / 111, kLng = 1 / (111 * Math.cos((s0 + n0) / 2 * Math.PI / 180));
      const S = s0 - pad * kLat, N = n0 + pad * kLat, W = w0 - pad * kLng, E = e0 + pad * kLng;
      const inBox = o => o.lat >= S && o.lat <= N && o.lng >= W && o.lng <= E;
      const voisines = cs.filter(inBox);           // le point le plus proche peut être une commune d'à côté
      const pL = (R + 12) * kLat, pG = (R + 12) * kLng;
      const toutes = s.communes.filter(c => c.lat >= s0 - pL && c.lat <= n0 + pL && c.lng >= w0 - pG && c.lng <= e0 + pG);   // ménages : toutes les communes à portée
      const shops = shopsAll.filter(inBox);
      const bLat = Math.max(R, 0.5) * kLat, bLng = Math.max(R, 0.5) * kLng, bucket = {};
      shops.forEach(x => { const k = Math.floor(x.lat / bLat) + ',' + Math.floor(x.lng / bLng); (bucket[k] || (bucket[k] = [])).push(x); });
      const around = (lat, lng) => {
        const i = Math.floor(lat / bLat), j = Math.floor(lng / bLng), res = [];
        for (let a = i - 1; a <= i + 1; a++) for (let b = j - 1; b <= j + 1; b++){
          const l = bucket[a + ',' + b]; if (!l) continue;
          for (let q = 0; q < l.length; q++){ const d = dist(lat, lng, l[q].lat, l[q].lng); if (d <= R) res.push({ x: l[q], d: d }); }
        }
        return res;
      };
      const stepKm = Math.max(R * 0.9, 1.2);
      let dLat = stepKm * kLat, dLng = stepKm * kLng;
      const cells = ((n0 - s0) / dLat) * ((e0 - w0) / dLng);
      if (cells > 2600){ const f = Math.sqrt(cells / 2600); dLat *= f; dLng *= f; }
      const zones = this.balayerEmprise({ s0: s0, n0: n0, w0: w0, e0: e0, dLat: dLat, dLng: dLng },
        voisines, toutes, around, c => c.prov === p.code)
        // Le score plafonne à 100 : à égalité, le CA estimé départage.
        .sort((a, b) => (b.score - a.score) || (b.ca - a.ca)).slice(0, max);
      out.push({ code: p.code, prov: p.name, communes: mine.length, shops: shops.filter(x => x.prov === p.code).length, zones: zones });
    });
    this._top5Key = key; this._top5Val = out;
    return out;
  }

  /* Un point est-il à portée d'une zone d'activité ? La distance se mesure au
   * BORD de la zone, pas à son centre : un parc de deux kilomètres de large
   * commence à son entrée, pas au milieu. */
  prochedeZoning(lat, lng){
    const s = this.state, max = s.zoneMax;
    if (max == null) return true;
    const Z = s.zoning;
    for (let i = 0; i < Z.length; i++){
      if (Math.abs(Z[i].lat - lat) > (max + 6) / 111) continue;    // rejet rapide
      if (dist(lat, lng, Z[i].lat, Z[i].lng) - (Z[i].rKm || 0) <= max) return true;
    }
    return false;
  }

  /* Ce qu'une zone doit tenir pour être retenue. Une seule règle, lue par le
   * balayage de la vue comme par celui des arrondissements — sinon la carte et
   * la liste finissent par ne plus montrer les mêmes points. */
  zoneRetenue(p){
    const s = this.state;
    if (p.score < (s.minScore || 0)) return false;
    if (s.caVise && p.ca < s.caVise) return false;
    if (s.nMax != null && p.n > s.nMax) return false;      // 0 = aucune boulangerie dans le rayon
    if (s.hhMin && p.hh < s.hhMin) return false;           // densité : ménages du rayon
    if (s.zoneMax != null && !this.prochedeZoning(p.lat, p.lng)) return false;
    return true;
  }

  /* Le cœur du balayage d'une emprise : une maille, et pour chaque point la
   * commune la plus proche, la concurrence du rayon, les ménages, l'emprise,
   * le CA et le score. Partagé par le Top 5 par province et par l'assistant —
   * une seule formule, donc un seul endroit où elle peut se tromper. */
  balayerEmprise(b, voisines, toutes, around, appartient){
    const s = this.state, R = s.radius, eMax = (s.empriseMax || 30) / 100;
    const best = {};
    let guard = 0;
    for (let lat = b.s0; lat <= b.n0 && guard < 2600; lat += b.dLat){
      for (let lng = b.w0; lng <= b.e0 && guard < 2600; lng += b.dLng){
        guard++;
        let com = null, cd = 1e9;
        voisines.forEach(c => { const d = dist(lat, lng, c.lat, c.lng); if (d < cd){ cd = d; com = c; } });
        if (!com || !appartient(com) || cd > (com.rKm || 3) * 1.4) continue;   // hors emprise, ou hors zone habitée connue
        const near = around(lat, lng);
        if (near.some(o => this.isStrong(o.x))) continue;                      // zone rouge
        let load = 0;
        near.forEach(o => { load += this.strength(o.x) * (1 - o.d / R * 0.6); });
        const hh = this.householdsIn(lat, lng, R, toutes);
        const auto = Math.max(0.04, Math.min(eMax, eMax / (1 + (s.compK || 0.22) * load)));
        const emprise = s.emprise > 0 ? s.emprise / 100 : auto;
        const ca = hh * s.spend * emprise / (1 - s.passage / 100);
        const score = Math.max(0, Math.min(100, Math.round((hh / 14000) * 60 + emprise / eMax * 40)));
        const k = com.name + '|' + com.arr;
        if (!best[k] || score > best[k].score) best[k] = { lat: lat, lng: lng, hh: hh, ca: ca, score: score, emprise: emprise, n: near.length, commune: com.name, arr: com.arr };
      }
    }
    return Object.keys(best).map(k => best[k]);
  }

  /* Le balayage de l'assistant : par ARRONDISSEMENT, jamais par la vue.
   *
   * Le balayage de la carte travaille sur ce qu'on voit, et sa maille s'élargit
   * quand la vue s'élargit : à l'échelle du pays, deux points chauds seulement
   * sortaient d'un territoire qui en compte des dizaines. L'assistant balaie
   * donc chaque arrondissement retenu sur sa propre emprise, à maille fine, et
   * réunit les résultats. Le prix est une poignée de millisecondes par
   * arrondissement, les commerces étant rangés par cases.
   */
  scanArrs(){
    const s = this.state, R = s.radius;
    const noms = s.arr === 'all'
      ? Array.from(new Set(s.communes.filter(c => s.prov[c.prov]).map(c => c.arr))).filter(a => a && a !== '—')
      : [s.arr];
    const key = [noms.join(','), R, s.thresh, s.weak, s.minRating, s.minHh, this._rev,
      s.spend, s.passage, s.emprise, s.empriseMax, s.compK, s.minScore, s.caVise, s.nMax, s.hhMin, s.zoneMax, s.zoning.length].join('|');
    if (key === this._arrKey) return this._arrVal;
    const shopsAll = this.shops(), cs = this.filteredCommunes();
    const pad = R + 2;
    let out = [], nCom = 0;
    noms.forEach(nom => {
      const mine = cs.filter(c => c.arr === nom);
      if (!mine.length) return;
      nCom += mine.length;
      let s0 = 90, n0 = -90, w0 = 180, e0 = -180;
      mine.forEach(c => { s0 = Math.min(s0, c.lat); n0 = Math.max(n0, c.lat); w0 = Math.min(w0, c.lng); e0 = Math.max(e0, c.lng); });
      const kLat = 1 / 111, kLng = 1 / (111 * Math.cos((s0 + n0) / 2 * Math.PI / 180));
      const S = s0 - pad * kLat, N = n0 + pad * kLat, W = w0 - pad * kLng, E = e0 + pad * kLng;
      const inBox = o => o.lat >= S && o.lat <= N && o.lng >= W && o.lng <= E;
      const voisines = cs.filter(inBox);
      const pL = (R + 12) * kLat, pG = (R + 12) * kLng;
      const toutes = s.communes.filter(c => c.lat >= s0 - pL && c.lat <= n0 + pL && c.lng >= w0 - pG && c.lng <= e0 + pG);
      const shops = shopsAll.filter(inBox);
      const bLat = Math.max(R, 0.5) * kLat, bLng = Math.max(R, 0.5) * kLng, bucket = {};
      shops.forEach(x => { const k2 = Math.floor(x.lat / bLat) + ',' + Math.floor(x.lng / bLng); (bucket[k2] || (bucket[k2] = [])).push(x); });
      const around = (lat, lng) => {
        const i = Math.floor(lat / bLat), j = Math.floor(lng / bLng), res = [];
        for (let a = i - 1; a <= i + 1; a++) for (let b2 = j - 1; b2 <= j + 1; b2++){
          const l = bucket[a + ',' + b2]; if (!l) continue;
          for (let q = 0; q < l.length; q++){ const d = dist(lat, lng, l[q].lat, l[q].lng); if (d <= R) res.push({ x: l[q], d: d }); }
        }
        return res;
      };
      const stepKm = Math.max(R * 0.9, 1.2);
      let dLat = stepKm * kLat, dLng = stepKm * kLng;
      const cells = ((n0 - s0) / dLat) * ((e0 - w0) / dLng);
      if (cells > 2600){ const f = Math.sqrt(cells / 2600); dLat *= f; dLng *= f; }
      out = out.concat(this.balayerEmprise({ s0: s0, n0: n0, w0: w0, e0: e0, dLat: dLat, dLng: dLng },
        voisines, toutes, around, c => c.arr === nom));
    });
    const res = out.filter(p => this.zoneRetenue(p))
      .sort((a, b) => (b.score - a.score) || (b.ca - a.ca)).slice(0, 30);
    this._arrKey = key; this._arrVal = res; this._arrBrut = out; this._arrCom = nCom;
    return res;
  }

  redraw(){
    if (!this.map || !this.el.isConnected) return;
    const s = this.state, shops = this.shops();
    this.gExcl.clearLayers(); this.gSel.clearLayers();
    this.gZone.clearLayers();
    if (s.layers.zoning && s.zoning.length) try {
      if (!this.gZone._map) this.map.addLayer(this.gZone);
      const vbz = this.map.getBounds().pad(0.25);
      const vues = s.zoning.filter(z => vbz.contains([z.lat, z.lng]));
      // Toute la Belgique dans la vue, c'est plus de trois mille pastilles :
      // on peint les plus grandes, celles qui portent des emplois.
      const liste = vues.length > ZONE_MAX
        ? vues.slice().sort((a, b) => b.rKm - a.rKm).slice(0, ZONE_MAX) : vues;
      liste.forEach(z => {
        L.circle([z.lat, z.lng], {
          renderer: this.vecR, radius: Math.max(120, z.rKm * 1000), stroke: false,
          fillColor: '#6B7A8F', fillOpacity: 0.22
        }).bindPopup('<div class="sc-pop"><b style="color:#4C5A6B">' + esc(z.nom || 'Zone d\'activité') + '</b><br>'
          + (ZONE_GENRE[z.genre] || 'Zone d\'activité')
          + '<br>Rayon ' + (z.rKm < 1 ? Math.round(z.rKm * 1000) + ' m' : z.rKm.toFixed(1).replace('.', ',') + ' km')
          + '</div>').addTo(this.gZone);
      });
    } catch (e) { console.error('[scouting] zoning', e); }
    else if (this.gZone._map){ try { this.map.removeLayer(this.gZone); } catch (e) { /* déjà retiré */ } }
    try { this.drawShops(); } catch (e) { console.error('[scouting] points', e); }
    if (s.layers.excl) try {
      const vb = this.map.getBounds().pad(0.35);
      // Sous une carte peinte, la contrainte se lit au liseré : le disque plein
      // recouvrait la lecture qu'on vient d'allumer.
      const peint = s.theme !== 'aucun' && !!this._grid;
      shops.filter(b => this.isStrong(b) && vb.contains([b.lat, b.lng])).forEach(b => {
        const c = L.circle([b.lat, b.lng], { renderer: this.vecR, radius: s.radius * 1000, color: '#8D1D2C', weight: peint ? 1.2 : 1, opacity: peint ? .55 : .5, fillColor: '#8D1D2C', fillOpacity: peint ? .05 : .18 });
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
            .bindPopup('<div class="sc-pop"><b>' + esc(a.name) + '</b><br>environ ' + fmtInt(a.w) + ' navetteurs/jour (ordre de grandeur)</div>')
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
    if (s.sel && s.sel.zone){
      // La zone tracée en trait plein ; le disque du rayon, au même centre, en
      // pointillé : c'est la comparaison que la fiche chiffre.
      L.circle([s.sel.lat, s.sel.lng], { renderer: this.vecR, radius: s.radius * 1000, color: '#2b2b2b', weight: 1.6, dashArray: '5 6', fill: false, opacity: .8 }).addTo(this.gSel);
      L.polygon(s.sel.zone.poly, { renderer: this.vecR, color: '#1b5e20', weight: 2.2, fillColor: '#1b5e20', fillOpacity: .12 }).addTo(this.gSel);
      L.circleMarker([s.sel.lat, s.sel.lng], { renderer: this.vecR, radius: 6, color: '#fff', weight: 2, fillColor: '#1b5e20', fillOpacity: 1 }).addTo(this.gSel);
    } else if (s.sel){
      L.circle([s.sel.lat, s.sel.lng], { renderer: this.vecR, radius: s.radius * 1000, color: '#1b5e20', weight: 2, dashArray: '5 5', fillColor: '#1b5e20', fillOpacity: .08 }).addTo(this.gSel);
      L.circleMarker([s.sel.lat, s.sel.lng], { renderer: this.vecR, radius: 7, color: '#fff', weight: 2, fillColor: '#1b5e20', fillOpacity: 1 }).addTo(this.gSel);
    }
    if (this.raster) this.raster._peindre();
    (this.rasters || []).forEach(r => r._peindre());
    this.el.classList.toggle('sc-dessine', (s.tool || 'point') !== 'point');
    s.candidates.forEach(c => {
      L.circleMarker([c.lat, c.lng], { renderer: this.vecR, radius: 6, color: '#1b5e20', weight: 2, fillColor: '#FAC775', fillOpacity: 1 })
        .bindPopup('<div class="sc-pop"><b>' + esc(c.name) + '</b><br>CA estimé : ' + fmtEur(c.ca) + '</div>').addTo(this.gSel);
    });
  }

  /* ---------- filtres ---------- */
  rating(b){ const r = this.state.ratings[b.id]; return r && r.rating ? r.rating : null; }

  strength(b){  // force du concurrent, 0–1
    const r = this.rating(b);
    // La borne basse est un RÉGLAGE : en dessous, le commerce ne pèse rien.
    // Elle valait 3 en dur, ce qui interdisait de dire « ici, un 3,5 n'est pas
    // un concurrent ». Le haut de l'échelle reste 5.
    const w = Math.max(0, Math.min(4.9, this.state.weak != null ? this.state.weak : 3));
    if (r) return Math.max(0, Math.min(1, (r - w) / (5 - w)));
    let s = 0.4;
    const n = (b.name || '').toLowerCase();
    if (this.estChaine(b)) s += 0.25;
    if (b.web) s += 0.1;
    if (b.hours) s += 0.1;
    if (b.pastry) s += 0.05;
    return Math.min(1, s);
  }

  /* Une enseigne de chaîne : la marque relevée par OpenStreetMap (`brand`)
   * d'abord — c'est de la donnée, pas une liste écrite à la main — sinon le nom,
   * pour les quelques enseignes connues que la marque ne porte pas. Un point
   * chaud déjà pris par une chaîne dit que la zone de chalandise tient : elles
   * ne s'installent pas au hasard. */
  estChaine(b){
    if (b.brand) return true;
    const n = (b.name || '').toLowerCase();
    return CHAINS.some(c => n.includes(c));
  }

  marqueDe(b){
    if (b.brand) return b.brand;
    const n = (b.name || '').toLowerCase();
    const c = CHAINS.find(k => n.includes(k));
    // « le pain quotidien » s'écrit Le Pain Quotidien, pas Le pain quotidien :
    // la liste est en minuscules pour la comparaison, pas pour l'affichage.
    return c ? c.replace(/(^|[\s-])(\S)/g, (m, a, l) => a + l.toUpperCase()) : '';
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

  /* ---------- population : grille 1 km² du recensement 2021 ---------- */
  // Les cellules de 1 km² du recensement 2021 (StatBel, diffusion Eurostat) :
  // la population là où elle vit, et non répartie sur toute la commune. Servie
  // en statique avec l'écran ; rangée par cases de 0,02° pour les sommes dans
  // un rayon ; ses totaux par commune corrigent les populations communales.
  loadGrid(){
    if (this._grid) return Promise.resolve(this._grid);
    if (this._gridP) return this._gridP;
    this._gridP = fetch(GRID_URL, { credentials: 'same-origin' })
      .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(d => {
        const cells = (d && d.cellules) || [];
        const buckets = {}, byNis = {};
        let pop = 0;
        cells.forEach(c => {
          const k = Math.floor(c[0] / 0.02) + ',' + Math.floor(c[1] / 0.02);
          (buckets[k] || (buckets[k] = [])).push(c);
          if (c[3]) byNis[c[3]] = (byNis[c[3]] || 0) + c[2];
          pop += c[2];
        });
        this._grid = { buckets: buckets, byNis: byNis, areas: (d && d.communes) || {}, n: cells.length, pop: pop, source: (d && d.source) || 'recensement 2021', annee: (d && d.annee) || 2021 };
        this._rbKey = null;
        this.scheduleRedraw(30);
        return this._grid;
      })
      .catch(e => { console.warn('[scouting] grille de population indisponible :', e.message); this._gridP = null; return null; });
    return this._gridP;
  }

  // Ménages dans le rayon. Avec la grille : population des cellules de 1 km²
  // dont le centre est dans le rayon — les cellules de bord comptent au prorata
  // sur 1 km de transition — divisée par la taille moyenne des ménages.
  // Sans grille (fichier absent) : chaque commune compte pour la part de son
  // territoire — un disque de rayon rKm autour de son centre — comprise dans
  // le rayon. Une commune ne compte donc jamais plus que ses propres ménages,
  // et un rayon large additionne les communes qu'il couvre, au lieu d'étendre
  // la densité de la plus proche à tout le disque (ce qui donnait 210 000
  // ménages au port d'Anvers). Le même calcul sert à la fiche, à ceo_zones et
  // au Top 5 : leurs chiffres concordent.
  householdsIn(lat, lng, R, communes){
    const g = this._grid;
    if (g){
      const step = 0.02, m = R + 0.8, kl = 111 * Math.cos(lat * Math.PI / 180);
      const i0 = Math.floor((lat - m / 111) / step), i1 = Math.floor((lat + m / 111) / step);
      const j0 = Math.floor((lng - m / kl) / step), j1 = Math.floor((lng + m / kl) / step);
      let pop = 0;
      for (let i = i0; i <= i1; i++) for (let j = j0; j <= j1; j++){
        const l = g.buckets[i + ',' + j]; if (!l) continue;
        for (let q = 0; q < l.length; q++){
          const c = l[q], d = dist(lat, lng, c[0], c[1]);
          if (d <= R - 0.5) pop += c[2];
          else if (d < R + 0.5) pop += c[2] * (R + 0.5 - d);
        }
      }
      return pop / (this.state.hhSize || HH_SIZE);
    }
    const cs = communes || this.state.communes;
    let hh = 0;
    for (let i = 0; i < cs.length; i++){
      const c = cs[i], rc = c.rKm || 3;
      const d = dist(lat, lng, c.lat, c.lng);
      if (d >= R + rc || !c.hh) continue;
      hh += c.hh * lens(R, rc, d) / (Math.PI * rc * rc);
    }
    return hh;
  }

  evaluate(lat, lng){
    const s = this.state;
    if (!s.communes.length) return;
    const R = s.radius;
    const near = this.shops().map(b => ({ b: b, d: dist(lat, lng, b.lat, b.lng) })).filter(o => o.d <= R).sort((a, b) => a.d - b.d);
    const blocked = near.filter(o => this.isStrong(o.b));
    const hh = this.householdsIn(lat, lng, R), prim = this.householdsIn(lat, lng, R * 0.55);
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
    if (x) x.zone ? this.evaluateZone(x.zone) : this.evaluate(x.lat, x.lng);
  }

  /* ---------- les points de comparaison saisis à la main ---------- */
  // Un magasin à ouvrir, une zone mesurée, un concurrent qu'on connaît : à
  // côté des trois références de l'étude, avec les mêmes lignes. Enregistrés
  // en base quand l'API répond, toujours dans ce navigateur.
  refOuvrir(prefill){
    this.setState({ reseau: true, refForm: Object.assign({ nom: '', statut: '' }, prefill || {}) });
    setTimeout(() => { const el = this.el.querySelector('#sc-ref-nom'); if (el) el.focus(); }, 60);
  }

  refFermer(){ this.setState({ refForm: null }); }

  refChamp(k, v){ if (this.state.refForm) this.state.refForm[k] = v; }

  refEnregistrer(){
    const f = this.state.refForm;
    if (!f) return;
    const nom = String(f.nom || '').trim();
    if (!nom){ this.notify('Donne un nom au point de comparaison'); return; }
    const num = v => { const n = parseFloat(String(v == null ? '' : v).replace(',', '.').replace(/\s/g, '')); return isFinite(n) && n >= 0 ? n : null; };
    const r = { id: f.id || String(Date.now()), nom: nom.slice(0, 80), statut: String(f.statut || '').trim().slice(0, 80), lat: num(f.lat), lng: num(f.lng) };
    if (r.lat == null || r.lng == null || r.lat < 49.4 || r.lat > 51.6 || r.lng < 2.4 || r.lng > 6.5){ r.lat = null; r.lng = null; }
    ['pop', 'hh', 'taille', 'revenu', 'jeunes', 'actifs', 'seniors', 'depense', 'marche', 'emprise', 'ca', 'surface'].forEach(k => { r[k] = num(f[k]); });
    if (r.marche == null && r.hh && r.depense) r.marche = Math.round(r.hh * r.depense);
    const list = this.state.references.filter(o => o.id !== r.id).concat([r]);
    this.refSauver(list);
    this.setState({ refForm: null });
    this.notify('Point de comparaison enregistré — ' + r.nom);
  }

  refSupprimer(id){
    const r = this.state.references.find(o => o.id === id);
    this.refSauver(this.state.references.filter(o => o.id !== id));
    this.notify('Point de comparaison retiré' + (r ? ' — ' + r.nom : ''));
  }

  refSauver(list){
    ls.set('refs', list);
    this.setState({ references: list });
    if (this.useApi()) apiWrite('PUT', '/scouting/references', { references: list });
  }

  /* ---------- le plan d'expansion ---------- */
  // Le Top 5 par province, mis en plan : N zones par province, ce que chacune
  // peut dégager, le total pour le réseau, la carte des points. Deux clics :
  // le bouton, le PDF.
  ouvrirPlan(){
    this.setState({ plan: true, planImg: '', dossier: false, compare: false, reseau: false, view: 'map' });
    this.carteStatiquePlan().then(uri => { if (this.state.plan) this.setState({ planImg: uri }); });
  }

  fermerPlan(){ this._planCle = null; this.setState({ plan: false, planCalc: null }); }

  setPlan(patch){
    this.setState(patch);
    this._planImgKey = null;
    this.carteStatiquePlan().then(uri => { if (this.state.plan) this.setState({ planImg: uri }); });
  }

  // Ce qui entre dans la sélection du plan : les candidates au score, dans
  // tout le pays, et la clé qui dit si la sélection par la route est à jour.
  planSelection(){
    const s = this.state;
    const N = Math.max(1, Math.min(5, s.planN || 5)), seuil = !!s.planSeuil;
    const minutes = Math.max(0, +s.planEcart || 0), ecartKm = minutes * 45 / 60;
    const ouverts = (s.magasins || []).filter(m => m.ouvert && m.lat != null && m.lng != null)
      .map(m => ({ lat: +m.lat, lng: +m.lng, nom: m.nom || m.name || 'magasin', fixe: true }));
    const groupes = this.scanTop5(ecartKm > 0 ? 40 : 5);
    const cands = [];
    groupes.forEach(g => g.zones.forEach(z => { if (!seuil || z.score >= s.minScore) cands.push(Object.assign({ prov: g.prov, code: g.code }, z)); }));
    cands.sort((a, b) => (b.score - a.score) || (b.ca - a.ca));
    const route = ecartKm > 0 && s.planRoute !== false;
    // au-delà de `cut` km à vol d'oiseau, l'écart en minutes est acquis : il
    // faudrait rouler à 140 km/h de moyenne, porte à porte
    const cut = minutes / 60 * 140;
    const cle = route ? [this._top5Key, N, seuil, minutes, !!s.planReseau, ouverts.map(m => m.lat.toFixed(4) + ',' + m.lng.toFixed(4)).join(';')].join('#') : '';
    return { N: N, seuil: seuil, minutes: minutes, ecartKm: ecartKm, cut: cut, ouverts: ouverts, groupes: groupes, cands: cands, route: route, cle: cle };
  }

  planDonnees(){
    const s = this.state, self = this;
    const P = this.planSelection();
    const N = P.N, seuil = P.seuil, minutes = P.minutes, ecartKm = P.ecartKm, cut = P.cut, groupes = P.groupes;
    const fixes = s.planReseau ? P.ouverts : [];
    let retenues = [], ecartes = 0, ecartesProv = {}, temps = {}, sel = null, enCours = false;
    if (P.route){
      // la sélection par la route est asynchrone : tant qu'elle n'est pas là, le plan attend
      sel = s.planSel && s.planSel.cle === P.cle ? s.planSel : null;
      if (sel){ retenues = sel.retenues; ecartes = sel.ecartes; ecartesProv = sel.ecartesProv; temps = sel.temps; }
      else { enCours = true; this.planLancer(P); }
    } else {
      // À vol d'oiseau, à 45 km/h de moyenne — 20 min font 15 km. Les zones se
      // prennent au score dans tout le pays ; une zone trop près d'une retenue
      // (ou d'un magasin ouvert, si demandé) cède la place à la suivante de sa province.
      const parProv = {};
      P.cands.forEach(z => {
        if ((parProv[z.code] || 0) >= N) return;
        if (ecartKm > 0){
          const trop = retenues.concat(fixes).some(r => dist(z.lat, z.lng, r.lat, r.lng) < ecartKm);
          if (trop) { ecartes++; ecartesProv[z.code] = (ecartesProv[z.code] || 0) + 1; return; }
        }
        parProv[z.code] = (parProv[z.code] || 0) + 1;
        retenues.push(z);
      });
    }
    const de = n => (/^[aeiouyàâéèêëîïôûùh]/i.test(n) ? 'd’' : 'de ') + n;
    const provinces = [], tous = [];
    let total = 0, hhTot = 0, k = 0;
    groupes.forEach(g => {
      const zones = retenues.filter(z => z.code === g.code);
      const lignes = zones.map((z, i) => {
        const cc = self.concurrenceAu(z.lat, z.lng, s.radius);
        k++;
        const v = ecartKm > 0 ? self.voisinDe(z, retenues.concat(fixes), temps, cut, P.route) : null;
        const voisinTxt = !(ecartKm > 0) ? '' : v ? (P.route ? 'à ' + Math.round(v.sec / 60) + ' min ' + de(v.nom) + (v.approx ? ' (estimé)' : '') : 'à ' + Math.round(v.km) + ' km ' + de(v.nom) + ' à vol d’oiseau') : (P.route ? 'rien à moins de ' + Math.round(cut) + ' km' : '');
        return { rang: i + 1, num: k, commune: z.commune, arr: z.arr, prov: g.prov, score: z.score, hh: z.hh, n: z.n, forts: cc.forts, chaines: cc.chainesTxt,
          emprise: z.emprise, ca: z.ca, m2: z.ca / s.surface, lat: z.lat, lng: z.lng, voisin: v, voisinTxt: voisinTxt };
      });
      const sous = lignes.reduce((a, l) => a + l.ca, 0);
      total += sous; hhTot += lignes.reduce((a, l) => a + l.hh, 0);
      const manque = ecartKm > 0 && lignes.length < N && ecartesProv[g.code] ? ' · ' + ecartesProv[g.code] + ' zone' + (ecartesProv[g.code] > 1 ? 's' : '') + ' écartée' + (ecartesProv[g.code] > 1 ? 's' : '') + ' pour proximité' : '';
      provinces.push({ nom: g.prov, code: g.code, detail: fmtInt(g.communes) + ' communes · ' + fmtInt(g.shops) + ' commerces dans la sélection' + manque, lignes: lignes, sousTotal: sous });
      lignes.forEach(l => tous.push(l));
    });
    const reseau = (s.magasins || []).filter(m => m.ouvert && m.caAnnuel);
    const caReseau = reseau.reduce((a, m) => a + m.caAnnuel, 0);
    const nProv = provinces.filter(p => p.lignes.length).length;
    const R = s.radius.toFixed(1).replace('.', ',') + ' km';
    let ecartTxt = 'sans écart minimum entre elles';
    if (ecartKm > 0 && P.route) ecartTxt = 'à ' + minutes + ' min de voiture au moins les unes des autres' + (s.planReseau ? ' et des magasins ouverts' : '') + ' — minutes de route du service Valhalla sur OpenStreetMap, ' + fmtInt(sel ? sel.trajets : 0) + ' trajets calculés' + (sel && sel.approx ? ', ' + sel.approx + ' estimé' + (sel.approx > 1 ? 's' : '') + ' à vol d’oiseau faute de réponse' : '') + ' ; au-delà de ' + Math.round(cut) + ' km à vol d’oiseau, l’écart est acquis sans calcul';
    else if (ecartKm > 0) ecartTxt = 'à ' + minutes + ' min de voiture au moins les unes des autres' + (s.planReseau ? ' et des magasins ouverts' : '') + ' (' + Math.round(ecartKm) + ' km à vol d’oiseau, à 45 km/h de moyenne)';
    return {
      enCours: enCours, calc: s.planCalc, route: P.route, cut: cut,
      titre: 'Plan d’expansion — ' + tous.length + ' ouverture' + (tous.length > 1 ? 's' : '') + ' dans ' + nProv + ' province' + (nProv > 1 ? 's' : ''),
      date: new Date().toLocaleDateString('fr-BE', { day: 'numeric', month: 'long', year: 'numeric' }),
      N: N, seuil: seuil, minScore: s.minScore, minutes: minutes, ecartes: ecartes, ecartTxt: ecartTxt,
      total: total, hhTot: hhTot, nPts: tous.length, nProv: nProv, caReseau: caReseau, nReseau: reseau.length,
      resume: [
        ['CA annuel estimé, toutes ouvertures', fmtEur(total), fmtEur(total / 52) + ' par semaine'],
        ['Ouvertures retenues', String(tous.length), N + ' par province au plus' + (seuil ? ', score ≥ ' + s.minScore : '') + (ecartKm > 0 ? ' · ' + minutes + ' min entre elles' + (P.route ? ' par la route' : '') + (ecartes ? ' · ' + ecartes + ' écartée' + (ecartes > 1 ? 's' : '') + ' pour proximité' : '') : '')],
        ['Ménages accessibles cumulés', fmtInt(hhTot), tous.length ? fmtInt(hhTot / tous.length) + ' par point en moyenne' : ''],
        caReseau ? ['Le réseau aujourd’hui', fmtEur(caReseau), reseau.length + ' magasin' + (reseau.length > 1 ? 's' : '') + ' ouverts · le plan ajouterait + ' + Math.round(total / caReseau * 100) + ' %'] : ['CA moyen par ouverture', tous.length ? fmtEur(total / tous.length) : '—', 'sur ' + s.surface + ' m²']
      ],
      provinces: provinces,
      classement: tous.slice().sort((a, b) => b.ca - a.ca).slice(0, 10),
      carteNote: 'Les ronds verts numérotés : les zones retenues, dans l’ordre du plan. Les carrés noirs : les magasins du réseau déjà ouverts. Fond de carte © OpenStreetMap.',
      methode: 'Chaque province cochée est balayée sur toute son emprise, à la maille d’un rayon ; en chaque point, les ménages du recensement dans ' + R + ', les concurrents et leur pression, l’emprise et le CA du modèle de la fiche. Une zone par commune, hors des rayons d’exclusion des concurrents forts, les ' + N + ' meilleures au score par province' + (seuil ? ', au-dessus du score ' + s.minScore : ' — sans score minimum, le score dit ce qu’elles valent') + ', ' + ecartTxt + (ecartes ? ' ; ' + ecartes + ' zone' + (ecartes > 1 ? 's' : '') + ' mieux classée' + (ecartes > 1 ? 's' : '') + ' ' + (ecartes > 1 ? 'ont' : 'a') + ' cédé la place pour cause de proximité' : '') + '. Les CA s’additionnent comme si chaque ouverture était seule : deux zones voisines se partageraient une partie du marché.',
      hypotheses: [
        ['Dépense par ménage', fmtEur(s.spend) + ' / an'], ['Part du passage', s.passage + ' %'], ['Surface nette cible', s.surface + ' m²'],
        ['Emprise', s.emprise > 0 ? 'imposée ' + s.emprise + ' %' : 'calculée, max ' + s.empriseMax + ' %'], ['Sensibilité à la concurrence', String(s.compK).replace('.', ',')],
        ['Rayon', R], ['Concurrent fort dès', (+s.thresh).toFixed(1).replace('.', ',') + ' ★'], ['Score minimum', String(s.minScore)],
        ['Écart entre ouvertures', ecartKm > 0 ? minutes + ' min de voiture' + (P.route ? ', temps de route' : ', ≈ ' + Math.round(ecartKm) + ' km') + (s.planReseau ? ', magasins ouverts compris' : '') : 'aucun']
      ],
      sources: 'Commerces et communes : OpenStreetMap' + (self.osmDate() ? ', cache du serveur relu le ' + self.osmDate() : '') + ' · population : grille 1 km² du recensement 2021 (StatBel, diffusion Eurostat)'
        + ' · dépense par ménage, emprise et surface : étude GeoConsulting (Halle, 28/08/2024)' + (self.googleOk() ? ' · notes : Google Places' : '') + (ecartKm > 0 && P.route ? ' · temps de route : Valhalla (FOSSGIS) sur OpenStreetMap' : '') + ' · CA réel du réseau : P&L du panel, douze derniers mois clos.'
    };
  }

  /* ---------- l'écart entre ouvertures, en vraies minutes de route ---------- */
  // La sélection reste gloutonne, au score : chaque candidate est comparée
  // aux zones déjà retenues (et aux magasins ouverts) qui sont à moins de
  // `cut` km à vol d'oiseau ; les minutes de route de ces paires viennent du
  // service de matrice, par salves de candidates, quatre appels de front au
  // plus — c'est un service communautaire. Les trajets connus sont gardés en
  // mémoire et sur le poste : le plan suivant ne redemande que le neuf.
  planLancer(P){
    if (this._planCle === P.cle) return;
    this._planCle = P.cle;
    setTimeout(() => this.planCalculer(P), 0);
  }

  async planCalculer(P){
    const lim = P.minutes * 60, cut = P.cut, N = P.N, cands = P.cands;
    const fixes = this.state.planReseau ? P.ouverts : [];
    const run = { appels: 0, echecs: 0, trajets: 0, approx: 0, examinees: 0, coupe: false, t0: Date.now() };
    const vivant = () => this._planCle === P.cle && this.state.plan;
    const kp = (a, b) => { const x = a.lat.toFixed(4) + ',' + a.lng.toFixed(4), y = b.lat.toFixed(4) + ',' + b.lng.toFixed(4); return x < y ? x + '|' + y : y + '|' + x; };
    const union = (a, b) => a.concat(b.filter(x => a.indexOf(x) < 0));
    const retenues = [], parProv = {}, ecartesProv = {}, temps = {};
    let ecartes = 0, i = 0;
    this.setState({ planCalc: { examinees: 0, retenues: 0, appels: 0 } });
    try {
      while (i < cands.length){
        // la salve : les prochaines candidates encore éligibles
        const salve = [];
        while (i < cands.length && salve.length < 16){ const z = cands[i++]; if ((parProv[z.code] || 0) < N) salve.push(z); }
        if (!salve.length) break;
        // ce qu'il faut demander pour chacune : les retenues et magasins à
        // moins de `cut`, et les candidates de la salve qui la précèdent ; des
        // candidates à moins de 60 km l'une de l'autre partagent un appel
        const groupes = [];
        salve.forEach((z, j) => {
          const cibles = retenues.concat(fixes).filter(r => dist(z.lat, z.lng, r.lat, r.lng) < cut)
            .concat(salve.slice(0, j).filter(r => dist(z.lat, z.lng, r.lat, r.lng) < cut));
          if (!cibles.length) return;
          const g = groupes.find(g => g.sources.every(a => dist(a.lat, a.lng, z.lat, z.lng) < 60) && (g.sources.length + 1) * union(g.cibles, cibles).length <= 100);
          if (g){ g.sources.push(z); g.cibles = union(g.cibles, cibles); } else groupes.push({ sources: [z], cibles: cibles.slice() });
        });
        for (let o = 0; o < groupes.length; o += 4){
          await Promise.all(groupes.slice(o, o + 4).map(g => this.tempsRoute(g.sources, g.cibles, run).then(grille => {
            g.sources.forEach((a, si) => g.cibles.forEach((b, ci) => { const v = grille[si][ci]; if (v != null && v >= 0){ const c = kp(a, b); temps[c] = temps[c] === undefined ? v : Math.min(temps[c], v); } }));
          })));
          if (!vivant()){ if (this._planCle === P.cle) this._planCle = null; return; }
        }
        // puis la décision, dans l'ordre du score
        salve.forEach(z => {
          run.examinees++;
          if ((parProv[z.code] || 0) >= N) return;
          const trop = retenues.concat(fixes).some(r => dist(z.lat, z.lng, r.lat, r.lng) < cut && this.tempsEntre(z, r, temps, run) < lim);
          if (trop){ ecartes++; ecartesProv[z.code] = (ecartesProv[z.code] || 0) + 1; return; }
          parProv[z.code] = (parProv[z.code] || 0) + 1;
          retenues.push(z);
        });
        this.setState({ planCalc: { examinees: run.examinees, retenues: retenues.length, appels: run.appels } });
      }
    } catch (e) {
      console.warn('[scouting] temps de route :', e);
      if (!vivant()) return;
      this._planCle = null;
      this.notify('Temps de route indisponibles (' + e.message + ') — l’écart est mesuré à vol d’oiseau');
      this.setState({ planCalc: null, planRoute: false });
      return;
    }
    this.routesSauver();
    if (!vivant()){ if (this._planCle === P.cle) this._planCle = null; return; }
    if (run.trajets === 0 && run.echecs) this.notify('Le service de routage n’a pas répondu : les écarts sont estimés à vol d’oiseau (45 km/h)');
    else if (run.coupe) this.notify('Service de routage lent : au-delà de ' + run.appels + ' appels, les derniers écarts sont estimés à vol d’oiseau');
    this._planImgKey = null;
    this.setState({ planCalc: null, planSel: { cle: P.cle, retenues: retenues, ecartes: ecartes, ecartesProv: ecartesProv, temps: temps, trajets: run.trajets, appels: run.appels, approx: run.approx, echecs: run.echecs, duree: Date.now() - run.t0 } });
    this.carteStatiquePlan().then(uri => { if (this.state.plan) this.setState({ planImg: uri }); });
  }

  // Les secondes de route entre deux points, si on les a demandées ; sinon
  // l'estimation à vol d'oiseau à 45 km/h, comptée comme telle.
  tempsEntre(a, b, temps, run){
    const x = a.lat.toFixed(4) + ',' + a.lng.toFixed(4), y = b.lat.toFixed(4) + ',' + b.lng.toFixed(4);
    const v = temps[x < y ? x + '|' + y : y + '|' + x];
    if (v !== undefined) return v;
    if (run) run.approx++;
    return dist(a.lat, a.lng, b.lat, b.lng) / 45 * 3600;
  }

  // Le voisin le plus proche d'une zone retenue : par la route parmi ceux à
  // moins de `cut` km (au-delà, l'écart est acquis), sinon à vol d'oiseau.
  voisinDe(z, autres, temps, cut, route){
    let best = null;
    autres.forEach(r => {
      if (r === z) return;
      const d = dist(z.lat, z.lng, r.lat, r.lng);
      if (route){
        if (d >= cut) return;
        const x = z.lat.toFixed(4) + ',' + z.lng.toFixed(4), y = r.lat.toFixed(4) + ',' + r.lng.toFixed(4);
        const v = temps[x < y ? x + '|' + y : y + '|' + x];
        const sec = v !== undefined ? v : d / 45 * 3600;
        if (!best || sec < best.sec) best = { nom: r.commune || r.nom, sec: sec, km: d, approx: v === undefined };
      } else if (!best || d < best.km) best = { nom: r.commune || r.nom, km: d, sec: d / 45 * 3600, approx: true };
    });
    return best;
  }

  routesCache(){ return this._routes || (this._routes = ls.get('routes') || {}); }

  routesSauver(){
    const R = this.routesCache(), cles = Object.keys(R);
    if (cles.length > 8000){ const garde = {}; cles.slice(-5000).forEach(c => { garde[c] = R[c]; }); this._routes = garde; }
    ls.set('routes', this._routes);
  }

  // Les secondes de route de `sources` vers `cibles`, par le service de
  // matrice, en tranches d'au plus cent paires. Rend une grille
  // [source][cible] : -1 quand il n'y a pas de route, null quand le service
  // n'a pas répondu. Les paires connues sont relues du cache.
  async tempsRoute(sources, cibles, run){
    const R = this.routesCache();
    const kk = (a, b) => a.lat.toFixed(4) + ',' + a.lng.toFixed(4) + '>' + b.lat.toFixed(4) + ',' + b.lng.toFixed(4);
    const grille = sources.map(a => cibles.map(b => R[kk(a, b)]));
    const manque = cibles.map((c, j) => j).filter(j => sources.some((a, si) => grille[si][j] === undefined));
    const tranche = Math.max(1, Math.floor(100 / sources.length));
    for (let o = 0; o < manque.length; o += tranche){
      const part = manque.slice(o, o + tranche);
      if (run.coupe || run.appels >= 300 || Date.now() - run.t0 > 150000){
        run.coupe = true;
        sources.forEach((a, si) => part.forEach(j => { if (grille[si][j] === undefined) grille[si][j] = null; }));
        continue;
      }
      const q = { sources: sources.map(a => ({ lat: a.lat, lon: a.lng })), targets: part.map(j => ({ lat: cibles[j].lat, lon: cibles[j].lng })), costing: 'auto' };
      const ctl = new AbortController(), t = setTimeout(() => ctl.abort(), 25000);
      run.appels++;
      try {
        const r = await fetch(MATRICE_URL + '?json=' + encodeURIComponent(JSON.stringify(q)), { signal: ctl.signal, headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const j = await r.json();
        const m = j && j.sources_to_targets || [];
        sources.forEach((a, si) => part.forEach((j2, n) => {
          const c = (m[si] || [])[n];
          const v = c && c.time != null ? Math.round(c.time) : -1;
          grille[si][j2] = v; R[kk(a, cibles[j2])] = v; run.trajets++;
        }));
      } catch (e) {
        run.echecs++;
        sources.forEach((a, si) => part.forEach(j2 => { if (grille[si][j2] === undefined) grille[si][j2] = null; }));
      } finally { clearTimeout(t); }
    }
    return grille;
  }

  // La carte du plan : le pays (ou l'emprise des points), les zones retenues
  // numérotées, les magasins ouverts. Même assemblage de tuiles que le dossier.
  carteStatiquePlan(){
    const s = this.state, d = this.planDonnees();
    if (d.enCours) return Promise.resolve('');
    const pts = [];
    d.provinces.forEach(p => p.lignes.forEach(l => pts.push(l)));
    const mags = (s.magasins || []).filter(m => m.ouvert && m.lat != null && m.lng != null);
    if (!pts.length && !mags.length) return Promise.resolve('');
    const key = pts.map(l => l.lat.toFixed(4) + ',' + l.lng.toFixed(4)).join(';') + '|' + mags.length;
    if (this._planImgKey === key && this._planImgP) return this._planImgP;
    // Le pays entier tient au zoom 8 dans 740 × 540 : un cran de moins et la
    // Belgique n'occupait que le tiers de l'image.
    const W = 740, H = 540;
    const bb = boitePoly(pts.map(l => [l.lat, l.lng]).concat(mags.map(m => [+m.lat, +m.lng])));
    let z = 12;
    for (; z > 6; z--){ const a = merc(bb.n, bb.w, z), b = merc(bb.s, bb.e, z); if (b[0] - a[0] <= W * 0.97 && b[1] - a[1] <= H * 0.97) break; }
    const c = merc((bb.n + bb.s) / 2, (bb.w + bb.e) / 2, z), x0 = c[0] - W / 2, y0 = c[1] - H / 2;
    const canvas = document.createElement('canvas');
    canvas.width = W; canvas.height = H;
    const cx = canvas.getContext('2d');
    cx.fillStyle = '#EAE4DC'; cx.fillRect(0, 0, W, H);
    const charges = [];
    for (let tx = Math.floor(x0 / 256); tx <= Math.floor((x0 + W) / 256); tx++) for (let ty = Math.floor(y0 / 256); ty <= Math.floor((y0 + H) / 256); ty++){
      charges.push(new Promise(res => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => { try { cx.drawImage(img, tx * 256 - x0, ty * 256 - y0); } catch (e) { /* tuile refusée */ } res(); };
        img.onerror = () => res();
        img.src = 'https://tile.openstreetmap.org/' + z + '/' + tx + '/' + ty + '.png';
      }));
    }
    const P = ll => { const m = merc(ll[0], ll[1], z); return [m[0] - x0, m[1] - y0]; };
    this._planImgKey = key;
    this._planImgP = Promise.all(charges).then(() => {
      // un voile clair : les repères doivent se lire sur le fond
      cx.fillStyle = 'rgba(255,255,255,.28)'; cx.fillRect(0, 0, W, H);
      mags.forEach(m => {
        const p = P([+m.lat, +m.lng]);
        cx.fillStyle = '#221E1A'; cx.fillRect(p[0] - 6, p[1] - 6, 12, 12);
        cx.lineWidth = 2; cx.strokeStyle = '#fff'; cx.strokeRect(p[0] - 6, p[1] - 6, 12, 12);
      });
      pts.forEach(l => {
        const p = P([l.lat, l.lng]);
        cx.beginPath(); cx.arc(p[0], p[1], 10, 0, 6.2832);
        cx.fillStyle = '#1b5e20'; cx.fill(); cx.lineWidth = 2; cx.strokeStyle = '#fff'; cx.stroke();
        cx.fillStyle = '#fff'; cx.font = 'bold 10px Helvetica, Arial, sans-serif'; cx.textAlign = 'center'; cx.textBaseline = 'middle';
        cx.fillText(String(l.num), p[0], p[1] + 0.5);
      });
      cx.textAlign = 'left'; cx.textBaseline = 'alphabetic';
      cx.font = '11px Helvetica, Arial, sans-serif';
      const txt = '© OpenStreetMap', wt = cx.measureText(txt).width + 10;
      cx.fillStyle = 'rgba(255,255,255,.85)'; cx.fillRect(W - wt - 4, H - 18, wt, 16);
      cx.fillStyle = '#333'; cx.fillText(txt, W - wt + 1, H - 6);
      try { return canvas.toDataURL('image/jpeg', 0.88); } catch (e) { console.warn('[scouting] carte du plan :', e.message); return ''; }
    });
    return this._planImgP;
  }

  planPayload(d){
    return {
      titre: d.titre, date: d.date, total: fmtEur(d.total), sousTitre: d.nPts + ' ouverture' + (d.nPts > 1 ? 's' : '') + ' · ' + d.N + ' par province au plus' + (d.seuil ? ' · score ≥ ' + d.minScore : '') + (d.minutes ? ' · ' + d.minutes + ' min de voiture au moins entre elles' : ''),
      resume: d.resume, carte: d.carte || '', carteNote: d.carteNote,
      provinces: d.provinces.map(p => ({ nom: p.nom, detail: p.detail, sousTotal: fmtEur(p.sousTotal),
        lignes: p.lignes.map(l => [String(l.num), l.commune, l.arr + (l.voisinTxt ? ' · ' + l.voisinTxt : ''), String(l.score), fmtInt(l.hh), l.n + (l.forts ? ' (' + l.forts + ' fort' + (l.forts > 1 ? 's' : '') + ')' : ''), l.chaines || '—', pct1(l.emprise), fmtEur(l.ca), fmtEur(l.m2)]) })),
      classement: d.classement.map(l => [String(l.num), l.commune, l.prov, String(l.score), fmtEur(l.ca)]),
      hypotheses: d.hypotheses, methode: d.methode, sources: d.sources
    };
  }

  planPret(d){
    if (!d.enCours) return true;
    this.notify('Les temps de route sont en cours de calcul — un instant.');
    return false;
  }

  async telechargerPlanPdf(){
    const d = this.planDonnees();
    if (!this.planPret(d)) return;
    if (!this.useApi()){ this.imprimerPlan(); return; }
    this.setState({ planBusy: true });
    try {
      d.carte = await this.carteStatiquePlan();
      const ctl = new AbortController();
      const t = setTimeout(() => ctl.abort(), 60000);
      const r = await fetch(API_BASE + '/scouting/plan.pdf', {
        method: 'POST', credentials: 'same-origin', signal: ctl.signal,
        headers: { 'Content-Type': 'application/json', Accept: 'application/pdf' }, body: JSON.stringify(this.planPayload(d))
      });
      clearTimeout(t);
      if (r.status === 501){ this.notify('Aucun moteur PDF sur ce serveur : la fenêtre d’impression produit le même document.'); this.imprimerPlan(); return; }
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const url = URL.createObjectURL(await r.blob());
      const a = document.createElement('a');
      a.href = url; a.download = 'plan-expansion-' + new Date().toISOString().slice(0, 10) + '.pdf'; a.click();
      setTimeout(() => URL.revokeObjectURL(url), 4000);
      this.notify('Plan d’expansion téléchargé — ' + d.nPts + ' ouvertures, ' + fmtEur(d.total));
    } catch (e) {
      this.notify('PDF impossible (' + (e.name === 'AbortError' ? 'délai dépassé' : e.message) + ') — la fenêtre d’impression prend le relais');
      this.imprimerPlan();
    } finally {
      this.setState({ planBusy: false });
    }
  }

  imprimerPlan(){
    const d = this.planDonnees();
    if (!this.planPret(d)) return;
    d.carte = this.state.planImg || '';
    const logo = new URL('assets/img/logo.png', document.baseURI).href;
    const html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>' + esc(d.titre) + '</title><style>' + T.DOSS_CSS + T.DOSS_PRINT + '</style></head><body>'
      + T.planPage(d, esc, logo) + '</body></html>';
    const w = window.open('', '_blank');
    if (!w){ this.notify('Fenêtre bloquée par le navigateur — autorise les fenêtres surgissantes pour imprimer le plan.'); return; }
    w.document.open(); w.document.write(html); w.document.close();
    setTimeout(() => { try { w.focus(); w.print(); } catch (e) { /* fenêtre fermée */ } }, 600);
  }

  exporterPlanCsv(){
    const d = this.planDonnees(), s = this.state;
    if (!this.planPret(d)) return;
    const rows = [];
    d.provinces.forEach(p => p.lignes.forEach(l => rows.push([l.num, p.nom, l.rang, l.commune, l.arr, l.score, Math.round(l.hh), l.n, l.forts, l.chaines, (l.emprise * 100).toFixed(1), Math.round(l.ca), Math.round(l.m2), l.lat.toFixed(5), l.lng.toFixed(5),
      l.voisin ? l.voisin.nom : '', l.voisin ? l.voisin.km.toFixed(1) : '', l.voisin && d.route && !l.voisin.approx ? Math.round(l.voisin.sec / 60) : ''])));
    rows.push(['', 'TOTAL', '', d.nPts + ' ouvertures', '', '', Math.round(d.hhTot), '', '', '', '', Math.round(d.total), '', '', '', '', '', '']);
    this.csv('plan_expansion', ['numero', 'province', 'rang_province', 'commune', 'arrondissement', 'score', 'menages', 'concurrents', 'concurrents_forts', 'chaines', 'emprise_pct', 'ca_annuel_ttc', 'ca_par_m2', 'lat', 'lng', 'plus_proche', 'plus_proche_km_vol_oiseau', 'plus_proche_min_route'], rows);
  }

  /* ---------- la concurrence d'un point, lue pour les listes ---------- */
  // Qui est là, dans le rayon : les forts, les enseignes de chaîne (marque et
  // nombre), et les noms avec leur note — ce que la liste des points où
  // ouvrir doit dire, pas seulement un compte.
  concurrenceAu(lat, lng, R){
    const near = this.shops().map(b => ({ b: b, d: dist(lat, lng, b.lat, b.lng) })).filter(o => o.d <= R).sort((a, b) => a.d - b.d);
    const forts = near.filter(o => this.isStrong(o.b));
    const ch = near.filter(o => this.estChaine(o.b));
    const marques = ch.length ? this.marquesDe(ch.map(o => o.b)) : [];
    const chainesTxt = marques.map(m => m.nom + (m.n > 1 ? ' ×' + m.n : '')).join(', ');
    const noms = near.slice(0, 12).map(o => {
      const r = this.rating(o.b);
      return o.b.name + (r ? ' ' + r.toFixed(1).replace('.', ',') : '') + (this.isStrong(o.b) ? ' — fort' : '') + (this.estChaine(o.b) ? ' — chaîne' : '') + ' · ' + o.d.toFixed(1).replace('.', ',') + ' km';
    });
    return { n: near.length, forts: forts.length, chaines: ch.length, chainesTxt: chainesTxt, marques: marques,
      noms: noms.join('\n') + (near.length > 12 ? '\n… et ' + (near.length - 12) + ' autres' : '') };
  }

  /* ---------- le dossier d'implantation ---------- */
  zoneLibelle(z){
    const t = z.type === 'isochrone' ? 'Isochrone ' + z.minutes + ' min ' + (z.mode === 'pedestrian' ? 'à pied' : 'en voiture')
      : z.type === 'cercle' ? 'Cercle de ' + (+z.rayon || 0).toFixed(1).replace('.', ',') + ' km' : z.type === 'rectangle' ? 'Rectangle' : 'Polygone à ' + z.poly.length + ' sommets';
    if (z.aire == null) return t;
    return t + ' · ' + (z.aire >= 10 ? Math.round(z.aire) : z.aire.toFixed(1).replace('.', ',')) + ' km² · rayon équivalent ' + z.req.toFixed(1).replace('.', ',') + ' km';
  }

  ouvrirDossier(cand){
    if (!this.state.sel) return;
    this.setState({ dossier: true, dossierCand: cand || null, dossierImg: '', view: 'map', compare: false, reseau: false });
    this.carteStatique().then(uri => { if (this.state.dossier) this.setState({ dossierImg: uri }); });
  }

  fermerDossier(){ this.setState({ dossier: false, dossierCand: null }); }

  // Tout ce que le dossier dit, sous forme de données : la page à l'écran, le
  // PDF du serveur, l'impression et le CSV en sont quatre lectures.
  dossierDonnees(){
    const s = this.state, x = s.sel, self = this;
    if (!x) return null;
    const z = x.zone, R = s.radius, hhSize = s.hhSize || HH_SIZE;
    const dans = z ? 'dans la zone' : 'dans le rayon';
    const rayonTxt = R.toFixed(1).replace('.', ',') + ' km';
    const verdictOk = !x.blocked.length && x.score >= 55;
    const nomCom = x.commune || 'Zone';
    const reseau = [[nomCom + ' — projet', 'zone évaluée', fmtInt(x.hh), fmtEur(s.spend), pct1(x.emprise), fmtEur(x.ca), 'ce dossier']];
    this.calage().rows.forEach(r => {
      if (!r.ev) return;
      reseau.push([nomCourt(r.m.nom), 'en exploitation' + (r.m.caAnnuel ? ' · CA réel ' + fmtEur(r.m.caAnnuel) : ''),
        fmtInt(r.ev.hh), fmtEur(s.spend), pct1(r.ev.emprise), fmtEur(r.ev.ca),
        r.ratio ? 'réel = modèle ' + (r.ratio >= 1 ? '+' : '−') + Math.round(Math.abs(r.ratio - 1) * 100) + ' %' : 'CA réel inconnu']);
    });
    RESEAU.forEach(r => reseau.push([r.nom, 'référence — étude GeoConsulting ' + (r.etude || ''), fmtInt(r.hh), fmtEur(r.depense), r.emprise ? pct1(r.emprise / 100) : '—',
      r.ca ? fmtEur(r.ca) : '—', r.statut + (r.marche ? ' · marché ' + fmtEur(r.marche) : '')]));
    const chaines = x.near.filter(o => self.estChaine(o.b));
    const marques = chaines.length ? self.marquesDe(chaines.map(o => o.b)) : [];
    const notes = [];
    if (z && x.disque) notes.push('Au même centre, le rayon de ' + rayonTxt + ' compte ' + fmtInt(x.disque.hh) + ' ménages et ' + x.disque.n + ' concurrent' + (x.disque.n > 1 ? 's' : '')
      + ' — la zone dessinée en compte ' + fmtInt(x.hh) + ' et ' + x.near.length + ' : ' + (x.hh >= x.disque.hh ? '+ ' : '− ') + fmtInt(Math.abs(x.hh - x.disque.hh)) + ' ménages, soit '
      + (x.hh >= x.disque.hh ? '+ ' : '− ') + fmtEur(Math.abs(x.hh - x.disque.hh) * s.spend) + ' de marché.');
    const cand = s.dossierCand;
    if (cand && cand.hyp){
      const noms = { spend: ['dépense par ménage', v => fmtEur(v)], passage: ['passage', v => v + ' %'], surface: ['surface', v => v + ' m²'], emprise: ['emprise imposée', v => v ? v + ' %' : 'calculée'],
        empriseMax: ['emprise maximale', v => v + ' %'], compK: ['sensibilité', v => String(v).replace('.', ',')], hhSize: ['taille des ménages', v => String(v).replace('.', ',')],
        radius: ['rayon', v => (+v).toFixed(1).replace('.', ',') + ' km'], thresh: ['concurrent fort dès', v => (+v).toFixed(1).replace('.', ',') + ' ★'], weak: ['concurrent dès', v => (+v).toFixed(1).replace('.', ',') + ' ★'] };
      const diff = [];
      Object.keys(noms).forEach(k => { if (cand.hyp[k] != null && typeof s[k] === 'number' && Math.abs(cand.hyp[k] - s[k]) > 1e-9) diff.push(noms[k][0] + ' ' + noms[k][1](cand.hyp[k]) + ' → ' + noms[k][1](s[k])); });
      const quand = cand.date ? new Date(cand.date).toLocaleDateString('fr-BE') : '';
      notes.push(diff.length
        ? 'Depuis que la zone a été retenue' + (quand ? ' le ' + quand : '') + ', les hypothèses ont changé : ' + diff.join(' ; ') + '. Le dossier est calculé avec celles d’aujourd’hui.'
        : 'Hypothèses inchangées depuis que la zone a été retenue' + (quand ? ' le ' + quand : '') + '.');
    }
    return {
      titre: nomCom + ' — étude d’implantation', commune: nomCom,
      geo: 'arr. ' + x.arr + ' · ' + x.prov + ' · ' + x.lat.toFixed(4) + ', ' + x.lng.toFixed(4),
      date: new Date().toLocaleDateString('fr-BE', { day: 'numeric', month: 'long', year: 'numeric' }),
      score: x.score, verdictOk: verdictOk,
      verdict: x.blocked.length ? 'Zone exclue — concurrence forte' : x.score >= 55 ? 'Zone candidate prioritaire' : 'Zone candidate secondaire',
      verdictNote: x.blocked.length
        ? x.blocked.length + ' concurrent(s) fort(s) à moins de ' + rayonTxt + (z ? ' du centre' : '') + ' : ' + x.blocked.slice(0, 3).map(o => o.b.name).join(', ')
        : 'Score d’opportunité ' + x.score + '/100 — ménages accessibles pondérés par la pression concurrentielle.',
      zone: z ? this.zoneLibelle(z) : 'Rayon de ' + rayonTxt + ' autour du point',
      carteNote: 'En vert, la zone évaluée' + (z ? ' ; en pointillé, le rayon de ' + rayonTxt + ' au même centre' : '') + '. Les points : les commerces relevés, colorés par leur note Google. Fond de carte © OpenStreetMap.',
      essentiel: [
        ['Ménages ' + dans, fmtInt(x.hh), 'recensement 2021'],
        ['Concurrents ' + dans, String(x.near.length), x.blocked.length ? 'dont ' + x.blocked.length + ' fort' + (x.blocked.length > 1 ? 's' : '') : chaines.length ? chaines.length + ' de chaîne' : 'aucun fort'],
        ['Emprise ' + (s.emprise > 0 ? 'imposée' : 'estimée'), pct1(x.emprise), 'pression ' + x.load.toFixed(2)],
        ['CA annuel estimé', fmtEur(x.ca), fmtEur(x.ca / s.surface) + ' / m² sur ' + s.surface + ' m²']
      ],
      marche: [
        ['Population ' + dans, fmtInt(x.hh * hhSize) + ' hab.', self._grid ? 'recensement 2021, maille de 1 km²' : 'part du territoire des communes dans le rayon'],
        ['Ménages (' + String(hhSize).replace('.', ',') + ' personnes)', fmtInt(x.hh), 'population ÷ taille des ménages'],
        ['dont zone primaire', fmtInt(x.prim), 'rayon réduit à 55 %'],
        ['Dépense boulangerie par ménage', fmtEur(s.spend) + ' / an', 'hypothèse du modèle — études GeoConsulting : 650 € à Sombreffe, 586 € à Halle, 550 € à Berlo, 416 € chez Max & Sandra'],
        ['Marché boulangerie ' + dans, fmtEur(x.market), 'ménages × dépense'],
        ['Pression concurrentielle', x.load.toFixed(2), 'Σ force × (1 − 0,6 × distance ÷ rayon), les forts comptant 1,5'],
        ['Emprise ' + (s.emprise > 0 ? 'imposée' : 'estimée'), pct1(x.emprise), s.emprise > 0 ? 'imposée à toutes les zones' : s.empriseMax + ' % ÷ (1 + ' + String(s.compK).replace('.', ',') + ' × pression), plancher 4 %'],
        ['CA annuel estimé TTC', fmtEur(x.ca), 'marché × emprise ÷ (1 − ' + s.passage + ' % de passage)'],
        ['Rendement sur ' + s.surface + ' m²', fmtEur(x.ca / s.surface) + ' / m²', 'Halle mesurée : 5 188 € / m² sur 250 m²'],
        ['CA hebdomadaire', fmtEur(x.ca / 52), 'CA annuel ÷ 52']
      ],
      concurrence: x.near.slice(0, 30).map(o => {
        const rv = s.ratings[o.b.id], r = self.rating(o.b), ch = self.estChaine(o.b);
        return [o.b.name, o.b.commune || '—', o.d.toFixed(1).replace('.', ',') + ' km',
          r ? r.toFixed(1).replace('.', ',') + (rv && rv.manual ? ' (saisie)' : rv && rv.n ? ' (' + rv.n + ' avis)' : '') : '—',
          Math.round(self.strength(o.b) * 100) + ' %', self.isStrong(o.b), ch ? self.marqueDe(o.b) : ''];
      }),
      chaines: marques.map(m => m.nom + (m.n > 1 ? ' ×' + m.n : '')).join(', '),
      reseau: reseau,
      hypotheses: [
        ['Dépense par ménage', fmtEur(s.spend) + ' / an'], ['Part du passage', s.passage + ' %'], ['Surface nette cible', s.surface + ' m²'],
        ['Emprise imposée', s.emprise > 0 ? s.emprise + ' %' : 'calculée'], ['Emprise maximale', s.empriseMax + ' %'], ['Sensibilité à la concurrence', String(s.compK).replace('.', ',')],
        ['Taille des ménages', String(hhSize).replace('.', ',')], ['Rayon', rayonTxt], ['Concurrent dès', (+s.weak).toFixed(1).replace('.', ',') + ' ★'],
        ['Concurrent fort dès', (+s.thresh).toFixed(1).replace('.', ',') + ' ★'], ['Score minimum', String(s.minScore)]
      ],
      notes: notes,
      sources: 'Commerces et communes : OpenStreetMap' + (self.osmDate() ? ', cache du serveur relu le ' + self.osmDate() : '') + ' · population : grille 1 km² du recensement 2021 (StatBel, diffusion Eurostat)'
        + ' · dépense par ménage, emprise et surface : étude GeoConsulting (Halle, 28/08/2024)' + (self.googleOk() ? ' · notes : Google Places' : '')
        + (z && z.type === 'isochrone' ? ' · isochrone : Valhalla (routage OpenStreetMap)' : '') + '.'
    };
  }

  // La carte du dossier : les tuiles OpenStreetMap assemblées dans un canvas,
  // la zone et les commerces dessinés dessus, rendus en image — le PDF part
  // sans réseau, et l'impression aussi.
  carteStatique(){
    const s = this.state, x = s.sel;
    if (!x) return Promise.resolve('');
    const pts = x.zone ? x.zone.poly : cerclePoly(x.lat, x.lng, s.radius);
    const key = [x.lat.toFixed(5), x.lng.toFixed(5), pts.length, s.radius, this._rev, s.minRating, s.arr].join('|');
    if (this._imgKey === key && this._imgP) return this._imgP;
    const W = 720, H = 400, bb = boitePoly(pts);
    let z = 15;
    for (; z > 8; z--){ const a = merc(bb.n, bb.w, z), b = merc(bb.s, bb.e, z); if (b[0] - a[0] <= W * 0.8 && b[1] - a[1] <= H * 0.8) break; }
    const c = merc((bb.n + bb.s) / 2, (bb.w + bb.e) / 2, z), x0 = c[0] - W / 2, y0 = c[1] - H / 2;
    const canvas = document.createElement('canvas');
    canvas.width = W; canvas.height = H;
    const cx = canvas.getContext('2d');
    cx.fillStyle = '#EAE4DC'; cx.fillRect(0, 0, W, H);
    const charges = [];
    for (let tx = Math.floor(x0 / 256); tx <= Math.floor((x0 + W) / 256); tx++) for (let ty = Math.floor(y0 / 256); ty <= Math.floor((y0 + H) / 256); ty++){
      charges.push(new Promise(res => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => { try { cx.drawImage(img, tx * 256 - x0, ty * 256 - y0); } catch (e) { /* tuile refusée */ } res(); };
        img.onerror = () => res();
        img.src = 'https://tile.openstreetmap.org/' + z + '/' + tx + '/' + ty + '.png';
      }));
    }
    const P = ll => { const m = merc(ll[0], ll[1], z); return [m[0] - x0, m[1] - y0]; };
    this._imgKey = key;
    this._imgP = Promise.all(charges).then(() => {
      const vb = { s: bb.s - 0.05, n: bb.n + 0.05, w: bb.w - 0.08, e: bb.e + 0.08 };
      this.shops().forEach(b => {
        if (b.lat < vb.s || b.lat > vb.n || b.lng < vb.w || b.lng > vb.e) return;
        const p = P([b.lat, b.lng]), r = this.rating(b);
        cx.beginPath(); cx.arc(p[0], p[1], 4.5, 0, 6.2832);
        cx.fillStyle = !r ? R_COL.none : r >= 4.5 ? R_COL.high : r >= 3.5 ? R_COL.mid : R_COL.low; cx.fill();
        cx.lineWidth = 1.2; cx.strokeStyle = '#fff'; cx.stroke();
        if (this.estChaine(b)){ cx.beginPath(); cx.arc(p[0], p[1], 7.5, 0, 6.2832); cx.lineWidth = 1.6; cx.strokeStyle = '#221E1A'; cx.stroke(); }
      });
      cx.beginPath();
      pts.forEach((p, i) => { const q = P(p); if (i) cx.lineTo(q[0], q[1]); else cx.moveTo(q[0], q[1]); });
      cx.closePath();
      cx.fillStyle = 'rgba(27,94,32,.14)'; cx.fill();
      cx.lineWidth = 2.5; cx.strokeStyle = '#1b5e20'; cx.stroke();
      const c0 = P([x.lat, x.lng]);
      if (x.zone){
        const rp = P([x.lat, x.lng + s.radius / (111.2 * Math.cos(x.lat * Math.PI / 180))]);
        cx.setLineDash([5, 5]); cx.beginPath(); cx.arc(c0[0], c0[1], Math.abs(rp[0] - c0[0]), 0, 6.2832);
        cx.lineWidth = 1.4; cx.strokeStyle = '#2b2b2b'; cx.stroke(); cx.setLineDash([]);
      }
      cx.beginPath(); cx.arc(c0[0], c0[1], 6, 0, 6.2832); cx.fillStyle = '#1b5e20'; cx.fill(); cx.lineWidth = 2; cx.strokeStyle = '#fff'; cx.stroke();
      cx.font = '11px Helvetica, Arial, sans-serif';
      const txt = '© OpenStreetMap', wt = cx.measureText(txt).width + 10;
      cx.fillStyle = 'rgba(255,255,255,.85)'; cx.fillRect(W - wt - 4, H - 18, wt, 16);
      cx.fillStyle = '#333'; cx.fillText(txt, W - wt + 1, H - 6);
      // En JPEG : six fois plus léger qu'en PNG pour une carte, et le PDF part
      // avec une image de 120 Ko au lieu de 800.
      try { return canvas.toDataURL('image/jpeg', 0.88); } catch (e) { console.warn('[scouting] carte du dossier :', e.message); return ''; }
    });
    return this._imgP;
  }

  // Le PDF vient du serveur (même chaîne que l'analyse magasin) ; sans moteur
  // PDF là-bas, ou hors ligne, la fenêtre d'impression produit le même document.
  async telechargerPdf(){
    const d = this.dossierDonnees();
    if (!d) return;
    if (!this.useApi()){ this.imprimerDossier(); return; }
    this.setState({ dossierBusy: true });
    try {
      d.carte = await this.carteStatique();
      const ctl = new AbortController();
      const t = setTimeout(() => ctl.abort(), 60000);
      const r = await fetch(API_BASE + '/scouting/dossier.pdf', {
        method: 'POST', credentials: 'same-origin', signal: ctl.signal,
        headers: { 'Content-Type': 'application/json', Accept: 'application/pdf' }, body: JSON.stringify(d)
      });
      clearTimeout(t);
      if (r.status === 501){ this.notify('Aucun moteur PDF sur ce serveur : la fenêtre d’impression produit le même document.'); this.imprimerDossier(); return; }
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const url = URL.createObjectURL(await r.blob());
      const a = document.createElement('a');
      a.href = url; a.download = 'dossier-implantation-' + slugDe(d.commune) + '-' + new Date().toISOString().slice(0, 10) + '.pdf'; a.click();
      setTimeout(() => URL.revokeObjectURL(url), 4000);
      this.notify('Dossier PDF téléchargé — ' + d.commune);
    } catch (e) {
      this.notify('PDF impossible (' + (e.name === 'AbortError' ? 'délai dépassé' : e.message) + ') — la fenêtre d’impression prend le relais');
      this.imprimerDossier();
    } finally {
      this.setState({ dossierBusy: false });
    }
  }

  imprimerDossier(){
    const d = this.dossierDonnees();
    if (!d) return;
    d.carte = this.state.dossierImg || '';
    const logo = new URL('assets/img/logo.png', document.baseURI).href;
    const html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>' + esc(d.titre) + '</title><style>' + T.DOSS_CSS + T.DOSS_PRINT + '</style></head><body>'
      + T.dossierPage(d, esc, logo) + '</body></html>';
    const w = window.open('', '_blank');
    if (!w){ this.notify('Fenêtre bloquée par le navigateur — autorise les fenêtres surgissantes pour imprimer le dossier.'); return; }
    w.document.open(); w.document.write(html); w.document.close();
    setTimeout(() => { try { w.focus(); w.print(); } catch (e) { /* fenêtre fermée */ } }, 600);
  }

  exporterDossierCsv(){
    const d = this.dossierDonnees();
    if (!d) return;
    const rows = [['dossier', 'titre', d.titre, d.date], ['dossier', 'zone', d.zone, d.geo], ['dossier', 'score', d.score, d.verdict]];
    d.essentiel.forEach(r => rows.push(['essentiel', r[0], r[1], r[2]]));
    d.marche.forEach(r => rows.push(['marche', r[0], r[1], r[2]]));
    d.concurrence.forEach(r => rows.push(['concurrence', r[0], r[2] + ' · note ' + r[3] + ' · force ' + r[4], r[1] + (r[5] ? ' · concurrent fort' : '') + (r[6] ? ' · chaîne ' + r[6] : '')]));
    d.reseau.forEach(r => rows.push(['reseau', r[0], r[5], r[1] + ' · ' + r[2] + ' ménages · ' + r[3] + ' · emprise ' + r[4] + ' · ' + r[6]]));
    d.hypotheses.forEach(r => rows.push(['hypotheses', r[0], r[1], '']));
    d.notes.forEach(n => rows.push(['notes', '', n, '']));
    rows.push(['sources', '', d.sources, '']);
    this.csv('dossier_' + slugDe(d.commune), ['section', 'mesure', 'valeur', 'detail'], rows);
  }

  /* ---------- la zone dessinée ---------- */
  // Tout ce que la fiche calcule pour un disque, calculé dans un polygone :
  // les ménages des mailles de 1 km² dont le centre est dedans, les commerces
  // dedans, la pression au prorata de la distance au centre — rapportée au
  // rayon équivalent (même aire), pour que le modèle reste le même.
  evaluateZone(zone){
    const s = this.state;
    if (!s.communes.length || !zone || !zone.poly || zone.poly.length < 3) return;
    const poly = zone.poly, cen = zone.centre || centrePoly(poly);
    const aire = airePoly(poly), Req = Math.max(0.3, Math.sqrt(aire / Math.PI));
    const bb = boitePoly(poly);
    const near = this.shops()
      .filter(b => b.lat >= bb.s && b.lat <= bb.n && b.lng >= bb.w && b.lng <= bb.e && dansPoly(poly, b.lat, b.lng))
      .map(b => ({ b: b, d: dist(cen[0], cen[1], b.lat, b.lng) })).sort((a, b) => a.d - b.d);
    // La zone rouge garde sa règle : pas d'installation à moins d'un rayon
    // d'un concurrent fort — c'est le centre qui est jugé, pas toute la zone.
    const blocked = this.shops().filter(b => this.isStrong(b) && dist(cen[0], cen[1], b.lat, b.lng) <= s.radius)
      .map(b => ({ b: b, d: dist(cen[0], cen[1], b.lat, b.lng) })).sort((a, b) => a.d - b.d);
    const hh = this.householdsInPoly(poly, bb, cen, Req), prim = this.householdsIn(cen[0], cen[1], Req * 0.55);
    let load = 0;
    near.forEach(o => { load += this.strength(o.b) * (1 - Math.min(o.d, Req) / Req * 0.6) * (this.isStrong(o.b) ? 1.5 : 1); });
    const eMax = (s.empriseMax || 30) / 100;
    const auto = Math.max(0.04, Math.min(eMax, eMax / (1 + (s.compK || 0.22) * load)));
    const emprise = s.emprise > 0 ? s.emprise / 100 : auto;
    const market = hh * s.spend;
    const ca = market * emprise / (1 - s.passage / 100);
    let cm = null, cd = 1e9;
    s.communes.forEach(c => { const d = dist(cen[0], cen[1], c.lat, c.lng); if (d < cd){ cd = d; cm = c; } });
    const score = Math.max(0, Math.min(100, Math.round((hh / 14000) * 60 + emprise / eMax * 40)));
    this.setState({
      sel: {
        lat: cen[0], lng: cen[1], hh: hh, prim: prim, market: market, emprise: emprise, ca: ca,
        near: near, blocked: blocked, load: load, score: score,
        commune: cm ? cm.name : '—', arr: cm ? cm.arr : '—',
        prov: cm ? (PROV.find(p => p.code === cm.prov) || {}).name : '—',
        cmHh: cm ? cm.hh : 0, cmEst: cm ? !!cm.est : false, cmPop: cm ? cm.pop : 0,
        zone: Object.assign({}, zone, { centre: cen, aire: aire, req: Req }),
        disque: this.evalDisque(cen[0], cen[1], s.radius)
      }
    });
  }

  // Le disque du rayon au même centre : ménages et commerces, pour dire ce que
  // la forme de la zone change.
  evalDisque(lat, lng, R){
    return { hh: this.householdsIn(lat, lng, R), n: this.shops().filter(b => dist(lat, lng, b.lat, b.lng) <= R).length };
  }

  householdsInPoly(poly, bb, cen, Req){
    const g = this._grid;
    if (!g) return this.householdsIn(cen[0], cen[1], Req);
    const step = 0.02;
    let pop = 0;
    for (let i = Math.floor(bb.s / step); i <= Math.floor(bb.n / step); i++) for (let j = Math.floor(bb.w / step); j <= Math.floor(bb.e / step); j++){
      const l = g.buckets[i + ',' + j]; if (!l) continue;
      for (let q = 0; q < l.length; q++){ const c = l[q]; if (dansPoly(poly, c[0], c[1])) pop += c[2]; }
    }
    return pop / (this.state.hhSize || HH_SIZE);
  }

  /* ---------- le dessin sur la carte ---------- */
  setTool(t){
    this.cancelDraw();
    if (this.map){ if (t === 'polygone') this.map.doubleClickZoom.disable(); else this.map.doubleClickZoom.enable(); }
    this.setState({ tool: t });
  }

  cancelDraw(){
    this._draw = null;
    if (this.gDraw) this.gDraw.clearLayers();
    if (this.state.dessin) this.setState({ dessin: 0 });
  }

  onMapClick(e){
    const s = this.state, lat = e.latlng.lat, lng = e.latlng.lng;
    if (s.placing) return this.placeStore(lat, lng);
    const t = s.tool || 'point';
    if (t === 'point') return this.evaluate(lat, lng);
    if (t === 'isochrone') return this.isochrone(lat, lng);
    if (this._fini && Date.now() - this._fini < 600) return;   // la fin du double-clic qui vient de fermer
    const d = this._draw || (this._draw = { type: t, pts: [] });
    // Le double-clic qui ferme un polygone envoie d'abord deux clics : le
    // second sommet, à quelques mètres du premier, n'en est pas un.
    const last = d.pts[d.pts.length - 1];
    if (last && dist(last[0], last[1], lat, lng) < 0.03) return;
    if (t === 'polygone' && d.pts.length >= 3 && this.presDuPremier(e.latlng)) return this.finirPolygone();
    d.pts.push([lat, lng]);
    if (t === 'cercle' && d.pts.length === 2){
      const c = d.pts[0], r = Math.max(0.2, dist(c[0], c[1], lat, lng));
      return this.finirDessin({ type: 'cercle', poly: cerclePoly(c[0], c[1], r), centre: c, rayon: r });
    }
    if (t === 'rectangle' && d.pts.length === 2){
      const a = d.pts[0], b = d.pts[1];
      return this.finirDessin({ type: 'rectangle', poly: [[a[0], a[1]], [a[0], b[1]], [b[0], b[1]], [b[0], a[1]]] });
    }
    this.setState({ dessin: d.pts.length });
    this.tracerTemp(e.latlng);
  }

  presDuPremier(ll){
    const d = this._draw; if (!d || !d.pts.length || !this.map) return false;
    const a = this.map.latLngToContainerPoint(d.pts[0]), b = this.map.latLngToContainerPoint(ll);
    return Math.abs(a.x - b.x) < 10 && Math.abs(a.y - b.y) < 10;
  }

  finirPolygone(){
    const d = this._draw;
    if (!d || d.type !== 'polygone') return;
    if (this._fini && Date.now() - this._fini < 600) return;
    if (d.pts.length < 3){ this.notify('Il faut au moins trois sommets'); return; }
    this.finirDessin({ type: 'polygone', poly: d.pts.slice() });
  }

  finirDessin(zone){
    this._draw = null;
    this._fini = Date.now();
    if (this.gDraw) this.gDraw.clearLayers();
    this.setState({ dessin: 0 });
    this.evaluateZone(zone);
  }

  // Le tracé qui suit le curseur : cercle, rectangle ou polyligne provisoire.
  tracerTemp(ll){
    const d = this._draw; if (!d || !this.map || !d.pts.length) return;
    this.gDraw.clearLayers();
    const st = { renderer: this.vecR, color: '#1b5e20', weight: 1.5, dashArray: '4 4', fillColor: '#1b5e20', fillOpacity: .06 };
    const c = d.pts[0];
    if (d.type === 'cercle') L.circle(c, Object.assign({}, st, { radius: Math.max(200, dist(c[0], c[1], ll.lat, ll.lng) * 1000) })).addTo(this.gDraw);
    else if (d.type === 'rectangle') L.rectangle([c, [ll.lat, ll.lng]], st).addTo(this.gDraw);
    else L.polyline(d.pts.concat([[ll.lat, ll.lng]]), Object.assign({}, st, { fill: false })).addTo(this.gDraw);
    d.pts.forEach((p, i) => L.circleMarker(p, { renderer: this.vecR, radius: i ? 3 : 5, color: '#1b5e20', weight: 1.5, fillColor: '#fff', fillOpacity: 1 }).addTo(this.gDraw));
  }

  // L'isochrone : le polygone atteignable en N minutes, demandé au service de
  // routage public de la communauté OpenStreetMap (Valhalla). Mis en mémoire
  // par point et par durée ; s'il ne répond pas, le disque du rayon sert.
  isochrone(lat, lng){
    const s = this.state;
    const m = /^(auto|pedestrian)(\d+)$/.exec(s.iso || 'auto10') || ['', 'auto', '10'];
    const costing = m[1], minutes = +m[2];
    const key = [lat.toFixed(4), lng.toFixed(4), costing, minutes].join('|');
    this._isoCache = this._isoCache || {};
    const go = poly => this.evaluateZone({ type: 'isochrone', poly: poly, centre: [lat, lng], minutes: minutes, mode: costing });
    if (this._isoCache[key]) return go(this._isoCache[key]);
    if (s.isoBusy) return;
    this.setState({ isoBusy: true });
    const ctl = new AbortController();
    const t = setTimeout(() => ctl.abort(), 15000);
    const q = { locations: [{ lat: lat, lon: lng }], costing: costing, contours: [{ time: minutes }], polygons: true, denoise: 0.3, generalize: 100 };
    fetch(ISO_URL + '?json=' + encodeURIComponent(JSON.stringify(q)), { signal: ctl.signal, headers: { Accept: 'application/json' } })
      .then(r => { clearTimeout(t); if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(j => {
        const f = (j && j.features || [])[0], geo = f && f.geometry;
        let ring = null;
        if (geo && geo.type === 'Polygon') ring = geo.coordinates[0];
        else if (geo && geo.type === 'MultiPolygon') geo.coordinates.forEach(p => { if (!ring || p[0].length > ring.length) ring = p[0]; });
        if (!ring || ring.length < 4) throw new Error('réponse sans polygone');
        const poly = ring.map(pt => [pt[1], pt[0]]);
        this._isoCache[key] = poly;
        this.setState({ isoBusy: false });
        go(poly);
      })
      .catch(e => {
        clearTimeout(t);
        this.setState({ isoBusy: false });
        this.notify('Isochrone indisponible (' + (e.name === 'AbortError' ? 'délai dépassé' : e.message) + ') — le rayon de ' + s.radius.toFixed(1) + ' km est évalué à la place');
        this.evaluate(lat, lng);
      });
  }

  /* ---------- la carte de potentiel : une valeur par maille de 1 km² ---------- */
  // Le modèle de la fiche, calculé sur chaque maille du recensement dont la
  // commune passe les filtres : ménages du rayon, concurrents du rayon et leur
  // pression, emprise, CA, score. Une passe sur tout le pays (une demi-seconde),
  // mémorisée tant que rien de ce qui compte ne change ; la peinture, elle,
  // ne coûte rien et suit la vue. `calculer` à faux : on ne fait que lire ce
  // qui est déjà prêt — le rendu n'attend jamais le calcul.
  /* Le socle des mailles, indépendant du thème : pour chaque maille de 1 km²
   * dont la commune passe les filtres, le modèle de la fiche — ménages et
   * concurrents du rayon, pression, emprise, CA, score — et le code NIS de sa
   * commune. Une passe sur le pays (un tiers de seconde), mémorisée tant que
   * rien de ce qui compte ne change. Les thèmes et les échelles s'en
   * déduisent sans recalculer. */
  rasterBase(calculer){
    const s = this.state, g = this._grid;
    if (!g || !s.communes.length) return null;
    const R = s.radius;
    const key = [R, this._rev, s.communes.length, s.bakeries.length, JSON.stringify(s.prov), s.arr, s.minRating, s.minHh, s.thresh, s.weak,
      s.spend, s.passage, s.emprise, s.empriseMax, s.compK, s.hhSize].join('|');
    if (key === this._rbKey) return this._rbVal;
    // Pendant le chargement, les communes arrivent secteur par secteur : on ne
    // recalcule pas neuf fois, la carte se peint quand tout est là.
    if (s.busy || !calculer) return null;
    const okIns = {};
    this.filteredCommunes().forEach(c => { okIns[c.ins] = 1; });
    const shops = this.shops(), force = new Map();
    shops.forEach(x => force.set(x, this.strength(x)));
    const kLat = 1 / 111, kLng = 1 / (111 * Math.cos(50.6 * Math.PI / 180));
    const bLat = Math.max(R, 0.5) * kLat, bLng = Math.max(R, 0.5) * kLng, bucket = {};
    shops.forEach(x => { const k2 = Math.floor(x.lat / bLat) + ',' + Math.floor(x.lng / bLng); (bucket[k2] || (bucket[k2] = [])).push(x); });
    const eMax = (s.empriseMax || 30) / 100, compK = s.compK || 0.22;
    const cells = [];
    let sans = 0;
    Object.keys(g.buckets).forEach(bk => {
      const l = g.buckets[bk];
      for (let q = 0; q < l.length; q++){
        const c = l[q];
        if (!c[3] || !okIns[c[3]]) continue;
        const lat = c[0], lng = c[1];
        const hh = this.householdsIn(lat, lng, R);
        let n = 0, load = 0;
        const i = Math.floor(lat / bLat), j = Math.floor(lng / bLng);
        for (let a = i - 1; a <= i + 1; a++) for (let b2 = j - 1; b2 <= j + 1; b2++){
          const m = bucket[a + ',' + b2]; if (!m) continue;
          for (let k = 0; k < m.length; k++){ const d = dist(lat, lng, m[k].lat, m[k].lng); if (d <= R){ n++; load += force.get(m[k]) * (1 - d / R * 0.6); } }
        }
        const auto = Math.max(0.04, Math.min(eMax, eMax / (1 + compK * load)));
        const emprise = s.emprise > 0 ? s.emprise / 100 : auto;
        const ca = hh * s.spend * emprise / (1 - s.passage / 100);
        const score = Math.max(0, Math.min(100, Math.round((hh / 14000) * 60 + emprise / eMax * 40)));
        if (n === 0) sans++;
        cells.push([lat, lng, hh, n, emprise, ca, score, c[3]]);
      }
    });
    this._rbKey = key;
    this._rbVal = { cells: cells, sans: sans };
    this._rkDeriv = {};
    this._rkStamp = (this._rkStamp || 0) + 1;
    return this._rbVal;
  }

  // Les entités d'une échelle — communes ou arrondissements de la sélection —
  // avec leurs ménages, leurs commerces, leur population, et la valeur du
  // thème : ménages ÷ commerces (ménages par point de vente), ménages, ou
  // commerces pour dix mille habitants.
  entites(ech, th){
    const cs = this.filteredCommunes(), parIns = {}, vals = {}, nParIns = {};
    this.shops().forEach(b => { if (b.ins) nParIns[b.ins] = (nParIns[b.ins] || 0) + 1; });
    cs.forEach(c => {
      const key = ech === 'commune' ? c.ins : (c.arr || '—');
      parIns[c.ins] = key;
      const e = vals[key] || (vals[key] = { key: key, nom: ech === 'commune' ? c.name : (c.arr || '—'), sous: ech === 'commune' ? 'arr. ' + c.arr : '', hh: 0, pop: 0, shops: 0, nCom: 0, lat: 0, lng: 0, bb: null });
      e.hh += c.hh || 0; e.pop += c.pop || 0; e.shops += nParIns[c.ins] || 0; e.nCom++;
      e.lat += c.lat; e.lng += c.lng;
      if (c.bb && c.bb.length === 4) e.bb = e.bb ? [Math.min(e.bb[0], c.bb[0]), Math.min(e.bb[1], c.bb[1]), Math.max(e.bb[2], c.bb[2]), Math.max(e.bb[3], c.bb[3])] : c.bb.slice();
    });
    const liste = Object.keys(vals).map(k => {
      const e = vals[k];
      e.lat /= e.nCom; e.lng /= e.nCom;
      e.v = th === 'potentiel' ? e.hh / Math.max(1, e.shops) : th === 'menages' ? e.hh : (e.pop > 0 ? e.shops / (e.pop / 10000) : 0);
      if (ech === 'arrondissement') e.sous = e.nCom + ' commune' + (e.nCom > 1 ? 's' : '') + ' · ' + fmtInt(e.hh) + ' ménages · ' + e.shops + ' commerce' + (e.shops > 1 ? 's' : '');
      else e.sous += ' · ' + fmtInt(e.hh) + ' ménages · ' + e.shops + ' commerce' + (e.shops > 1 ? 's' : '');
      return e;
    }).sort((a, b) => b.v - a.v);
    return { parIns: parIns, vals: vals, liste: liste };
  }

  /* La lecture d'un thème à une échelle : la classe de chaque maille (0–3,
   * 255 = pas peinte), les bornes des quartiles, les comptes. À la maille,
   * chaque maille vaut pour elle-même ; à la commune ou à l'arrondissement,
   * chaque maille prend la valeur de son entité, et les quartiles sont ceux
   * des entités. Dérivé du socle, mémorisé par thème et échelle.
   * `calculer` à faux : on ne fait que lire ce qui est prêt. */
  rasterVals(theme, calculer){
    const s = this.state;
    theme = theme || s.theme;
    if (theme === 'aucun' || !THEME_RAMPE[theme]) return null;
    const ech = s.echelle || 'maille';
    // CA et score n'ont de sens qu'à la maille : à l'entité, on lit les
    // ménages par point de vente.
    const th = ech !== 'maille' && (theme === 'ca' || theme === 'score') ? 'potentiel' : theme;
    const k = th + '|' + ech;
    this._rkDeriv = this._rkDeriv || {};
    const base = this.rasterBase(calculer);
    if (!base) return this._rkDeriv[k] || null;
    if (this._rkDeriv[k]) return this._rkDeriv[k];
    const cells = base.cells, n = cells.length, cls = new Uint8Array(n), counts = [0, 0, 0, 0];
    const quart = (arr, unique) => {
      let tri = arr.slice().sort((a, b) => a - b);
      if (unique) tri = tri.filter((v, i) => i === 0 || v !== tri[i - 1]);
      const q = f => tri.length ? tri[Math.min(tri.length - 1, Math.floor(tri.length * f))] : 0;
      return [q(0.25), q(0.5), q(0.75)];
    };
    let bornes, entites = null;
    if (ech === 'maille'){
      const val = new Array(n);
      for (let i = 0; i < n; i++){
        const c = cells[i];
        val[i] = th === 'potentiel' ? c[2] / (c[3] + 1) : th === 'menages' ? c[2] : th === 'concurrence' ? c[3] : th === 'ca' ? c[5] : c[6];
      }
      bornes = quart(val, th === 'concurrence' || th === 'score');
      for (let i = 0; i < n; i++){ const v = val[i]; const j = v < bornes[0] ? 0 : v < bornes[1] ? 1 : v < bornes[2] ? 2 : 3; cls[i] = j; counts[j]++; }
    } else {
      entites = this.entites(ech, th);
      bornes = quart(entites.liste.map(e => e.v), false);
      entites.liste.forEach(e => { e.cls = e.v < bornes[0] ? 0 : e.v < bornes[1] ? 1 : e.v < bornes[2] ? 2 : 3; counts[e.cls]++; });
      for (let i = 0; i < n; i++){
        const key = entites.parIns[cells[i][7]], e = key != null ? entites.vals[key] : null;
        cls[i] = e ? e.cls : 255;
      }
    }
    const out = { cells: cells, cls: cls, bornes: bornes, counts: counts, n: n, sans: base.sans, theme: th, demande: theme, echelle: ech,
      entites: entites, nEnt: entites ? entites.liste.length : 0 };
    this._rkDeriv[k] = out;
    this._rkStamp = (this._rkStamp || 0) + 1;
    return out;
  }

  // La peinture d'un panneau : ses mailles au thème demandé, et, sur les
  // panneaux de comparaison, les commerces en points — ils n'ont pas la
  // couche des repères de la carte principale.
  paintRaster(canvas, map, theme, points){
    map = map || this.map;
    const s = this.state;
    if (!map || !canvas) return;
    const size = map.getSize(), dpr = window.devicePixelRatio || 1;
    L.DomUtil.setPosition(canvas, map.containerPointToLayerPoint([0, 0]));
    if (canvas.width !== size.x * dpr || canvas.height !== size.y * dpr){
      canvas.width = size.x * dpr; canvas.height = size.y * dpr;
      canvas.style.width = size.x + 'px'; canvas.style.height = size.y + 'px';
    }
    const cx = canvas.getContext('2d');
    cx.setTransform(dpr, 0, 0, dpr, 0, 0);
    cx.clearRect(0, 0, size.x, size.y);
    const avant = this._rkStamp || 0;
    const rv = this.rasterVals(theme || s.theme, true);
    // Un calcul vient d'avoir lieu : les légendes ont de nouvelles bornes.
    if ((this._rkStamp || 0) !== avant && !this._rkRendu){ this._rkRendu = true; setTimeout(() => { this._rkRendu = false; if (this.el.isConnected) this.render(); }, 0); }
    const b = map.getBounds().pad(0.05);
    const sud = b.getSouth(), nord = b.getNorth(), ouest = b.getWest(), est = b.getEast();
    const z = map.getZoom();
    if (rv){
      const rampe = THEME_RAMPE[rv.theme], off = map === this.map ? (s.themeOff || {}) : {};
      const demi = 0.5 / 111.2;
      // De loin la peinture porte la lecture ; de près, c'est la rue qu'on lit,
      // la couleur reste en fond.
      cx.globalAlpha = z <= 9 ? 0.68 : z <= 11 ? 0.55 : z <= 12 ? 0.42 : 0.32;
      const cells = rv.cells, cls = rv.cls;
      for (let i = 0; i < cells.length; i++){
        const c = cells[i], k = cls[i];
        if (k === 255 || off[k] || c[0] < sud || c[0] > nord || c[1] < ouest || c[1] > est) continue;
        const kx = 0.5 / (111.2 * Math.cos(c[0] * Math.PI / 180));
        const p1 = map.latLngToContainerPoint([c[0] + demi, c[1] - kx]), p2 = map.latLngToContainerPoint([c[0] - demi, c[1] + kx]);
        cx.fillStyle = rampe[k];
        cx.fillRect(p1.x, p1.y, Math.max(1.2, p2.x - p1.x + 0.8), Math.max(1.2, p2.y - p1.y + 0.8));
      }
      cx.globalAlpha = 1;
    }
    if (points){
      const r = z >= 12 ? 4 : z >= 10 ? 2.6 : 1.6;
      this.shops().forEach(x => {
        if (x.lat < sud || x.lat > nord || x.lng < ouest || x.lng > est) return;
        const p = map.latLngToContainerPoint([x.lat, x.lng]);
        cx.beginPath(); cx.arc(p.x, p.y, r, 0, 6.2832);
        cx.fillStyle = 'rgba(141,29,44,.75)'; cx.fill();
        if (this.estChaine(x)){ cx.lineWidth = 1.4; cx.strokeStyle = '#221E1A'; cx.stroke(); }
      });
    }
  }

  // La légende d'un thème à l'échelle courante : quatre classes, leurs bornes,
  // leurs comptes (mailles, ou communes, ou arrondissements). `oeil` : les
  // classes s'éteignent — sur la carte principale seulement.
  legendeDe(theme, oeil){
    const s = this.state, ech = s.echelle || 'maille';
    if (theme === 'aucun') return { rows: [], note: 'La carte ne peint rien : seuls les repères (commerces, zones) sont dessinés.' };
    if (!this._grid) return { rows: [], note: 'Grille de population indisponible : rien à peindre.' };
    const rv = this.rasterVals(theme, false);
    if (!rv) return { rows: [], note: s.communes.length ? 'Calcul des mailles…' : 'En attente des communes…' };
    const th = rv.theme;
    const fmt = v => th === 'ca' ? fmtEur(v) : th === 'concurrence' ? (ech === 'maille' ? String(Math.round(v)) : v.toFixed(1).replace('.', ',')) : th === 'score' ? String(Math.round(v)) : fmtInt(v);
    const u = th === 'potentiel' ? (ech === 'maille' ? 'ménages par point de vente' : 'ménages par point de vente (ménages ÷ commerces)') : th === 'menages' ? 'ménages' + (ech === 'maille' ? ' accessibles' : '')
      : th === 'concurrence' ? (ech === 'maille' ? 'concurrents dans le rayon' : 'commerces pour 10 000 habitants') : th === 'score' ? 'score sur 100' : 'CA annuel estimé';
    const b = rv.bornes, lab = ['moins de ' + fmt(b[0]), fmt(b[0]) + ' à ' + fmt(b[1]), fmt(b[1]) + ' à ' + fmt(b[2]), fmt(b[2]) + ' et plus'];
    const self = this;
    const quoi = ech === 'maille' ? fmtInt(rv.n) + ' mailles de 1 km² · ' + fmtInt(rv.sans) + ' (' + Math.round(rv.sans / Math.max(1, rv.n) * 100) + ' %) sans concurrent en ' + s.radius.toFixed(1).replace('.', ',') + ' km'
      : rv.nEnt + ' ' + (ech === 'commune' ? 'communes' : 'arrondissements');
    return {
      rows: [3, 2, 1, 0].map(i => ({
        color: THEME_RAMPE[th][i], label: lab[i], n: fmtInt(rv.counts[i]), off: oeil && !!s.themeOff[i],
        toggle: oeil ? () => self.setVue({ themeOff: Object.assign({}, s.themeOff, { [i]: !s.themeOff[i] }) }) : null
      })),
      note: u.charAt(0).toUpperCase() + u.slice(1) + ', quartiles ' + (ech === 'maille' ? 'de la sélection' : 'des ' + (ech === 'commune' ? 'communes' : 'arrondissements')) + ' · ' + quoi
    };
  }

  /* ---------- trois lectures côte à côte ---------- */
  // Deux cartes de plus, créées à la première demande, calées sur la carte
  // principale et qui la suivent (et réciproquement) : le même territoire lu
  // trois fois. Un clic sur l'une évalue le point comme sur la principale.
  ensureTrioMaps(){
    if (this.trioMaps || !this.map || !window.L) return;
    const self = this;
    this.trioMaps = [];
    [['scout-map2', () => self.state.trio2], ['scout-map3', () => self.state.trio3]].forEach(([id, theme]) => {
      const el = this.el.querySelector('#' + id);
      if (!el) return;
      const m = L.map(el, { center: this.map.getCenter(), zoom: this.map.getZoom(), minZoom: 6, maxZoom: 14, zoomControl: false, attributionControl: false, doubleClickZoom: false });
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(m);
      m.createPane('sc-raster').style.zIndex = 350;
      m.getPane('sc-raster').style.pointerEvents = 'none';
      const r = new this.Raster({ theme: theme, dots: true }).addTo(m);
      this.rasters.push(r);
      m.on('click', e => this.onMapClick(e));
      this.trioMaps.push(m);
    });
    const tous = [this.map].concat(this.trioMaps);
    const suivre = src => {
      if (this._sync) return;
      this._sync = true;
      try { const c = src.getCenter(), z = src.getZoom(); tous.forEach(m => { if (m !== src) m.setView(c, z, { animate: false }); }); }
      finally { this._sync = false; }
    };
    tous.forEach(m => m.on('move', () => suivre(m)));
  }

  ajusterTrio(){
    if (!this.map) return;
    if (this.state.trio) this.ensureTrioMaps();
    this.map.invalidateSize();
    (this.trioMaps || []).forEach(m => { m.invalidateSize(); m.setView(this.map.getCenter(), this.map.getZoom(), { animate: false }); });
    this.scheduleRedraw(40);
  }

  /* ---------- calage sur le réseau ---------- */
  // Le CA que le modèle prédit à l'emplacement d'un magasin du réseau, avec les
  // hypothèses courantes et TOUS les commerces connus — la sélection de
  // provinces n'a rien à y faire. Le magasin lui-même, présent dans OSM, n'est
  // pas son propre concurrent : rien à moins de 80 m.
  evalStore(st){
    const s = this.state, R = s.radius;
    if (st.lat == null || st.lng == null || !s.communes.length) return null;
    const near = s.bakeries.map(b => ({ b: b, d: dist(st.lat, st.lng, b.lat, b.lng) })).filter(o => o.d <= R && o.d > 0.08);
    const hh = this.householdsIn(st.lat, st.lng, R);
    let load = 0;
    near.forEach(o => { load += this.strength(o.b) * (1 - o.d / R * 0.6) * (this.isStrong(o.b) ? 1.5 : 1); });
    const eMax = (s.empriseMax || 30) / 100;
    const auto = Math.max(0.04, Math.min(eMax, eMax / (1 + (s.compK || 0.22) * load)));
    const emprise = s.emprise > 0 ? s.emprise / 100 : auto;
    return { hh: hh, n: near.length, emprise: emprise, ca: hh * s.spend * emprise / (1 - s.passage / 100) };
  }

  // Réel ÷ modèle pour chaque magasin ouvert et positionné, et la médiane des
  // rapports : c'est elle qui cale la dépense par ménage, à laquelle le CA du
  // modèle est proportionnel. La médiane, pour qu'un magasin hors norme ne
  // tire pas tout le réseau.
  calage(){
    const rows = (this.state.magasins || []).filter(m => m.ouvert).map(m => {
      const ev = this.evalStore(m);
      const ratio = ev && ev.ca > 0 && m.caAnnuel ? m.caAnnuel / ev.ca : null;
      return { m: m, ev: ev, ratio: ratio };
    });
    const rs = rows.map(r => r.ratio).filter(r => r != null).sort((a, b) => a - b);
    const med = rs.length ? (rs.length % 2 ? rs[(rs.length - 1) / 2] : (rs[rs.length / 2 - 1] + rs[rs.length / 2]) / 2) : null;
    return { rows: rows, med: med, n: rs.length };
  }

  caler(){
    const c = this.calage();
    if (!c.med) return;
    const spend = Math.max(50, Math.round(this.state.spend * c.med));
    this.setParam({ spend: spend });
    this.notify('Dépense par ménage calée à ' + spend + ' €/an — réel = modèle × ' + c.med.toFixed(2).replace('.', ',') + ' sur ' + c.n + ' magasin(s)');
  }

  placeStore(lat, lng){
    const id = this.state.placing;
    const m = (this.state.magasins || []).find(x => x.id === id);
    if (!m){ this.setState({ placing: null }); return; }
    const upd = this.state.magasins.map(x => x.id === id ? Object.assign({}, x, { lat: lat, lng: lng, position: 'saisie' }) : x);
    this.setState({ placing: null, magasins: upd });
    if (this.useApi()) apiWrite('PUT', '/scouting/reseau/' + id, { lat: lat, lng: lng });
    this.notify(m.nom + ' placé sur la carte');
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
  // les hypothèses du modèle, telles qu'elles s'enregistrent avec une zone
  hypotheses(){
    const s = this.state, o = {};
    ['spend', 'passage', 'surface', 'emprise', 'empriseMax', 'compK', 'hhSize', 'radius', 'thresh', 'weak', 'minScore'].forEach(k => { if (typeof s[k] === 'number') o[k] = s[k]; });
    return o;
  }

  // une zone retenue se rouvre telle qu'elle a été tracée
  candEval(c){
    if (c.zone && Array.isArray(c.zone.poly) && c.zone.poly.length >= 3) this.evaluateZone(Object.assign({}, c.zone, { centre: [+c.lat, +c.lng] }));
    else this.evaluate(+c.lat, +c.lng);
  }

  addCandidate(){
    const s = this.state, x = s.sel, self = this;
    if (!x) return;
    const z = x.zone;
    const c = {
      id: Date.now(), name: x.commune + ' — ' + (z ? self.zoneLibelle(z).split(' · ')[0].toLowerCase() : 'zone ' + x.lat.toFixed(3) + '/' + x.lng.toFixed(3)),
      commune: x.commune, arr: x.arr, prov: x.prov, lat: x.lat, lng: x.lng,
      hh: Math.round(x.hh), market: Math.round(x.market), emprise: x.emprise,
      ca: Math.round(x.ca), score: x.score, n: x.near.length, strong: x.blocked.length,
      m2: Math.round(x.ca / s.surface),
      // La zone telle qu'elle a été tracée, et les hypothèses du moment : le
      // dossier se refait sur la même zone, et dit ce qui a changé depuis.
      zone: z ? { type: z.type, poly: z.poly.map(p => [+(+p[0]).toFixed(5), +(+p[1]).toFixed(5)]), rayon: z.rayon || null, minutes: z.minutes || null, mode: z.mode || null } : null,
      hyp: this.hypotheses(), date: new Date().toISOString()
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
  /* Les marques d'une liste de commerces, la plus implantée d'abord. */
  marquesDe(bs){
    const n = {};
    bs.forEach(b => { const m = this.marqueDe(b); if (m) n[m] = (n[m] || 0) + 1; });
    return Object.keys(n).sort((a, b) => n[b] - n[a]).map(m => ({ nom: m, n: n[m] }));
  }

  arrStats(name){
    const cs = this.state.communes.filter(c => c.arr === name);
    const bs = this.state.bakeries.filter(b => b.arr === name);
    const pop = cs.reduce((a, c) => a + c.pop, 0), hh = cs.reduce((a, c) => a + c.hh, 0);
    const rated = bs.map(b => this.rating(b)).filter(Boolean);
    return {
      communes: cs.length, pop: pop, hh: hh, market: hh * this.state.spend, shops: bs.length,
      strong: bs.filter(b => this.isStrong(b)).length,
      chains: bs.filter(b => this.estChaine(b)).length,
      marques: this.marquesDe(bs),
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

  /* --- L'assistant « où puis-je ouvrir, et pour combien » --------------------
   *
   * Quatre questions posées dans l'ordre, et rien de neuf sous le capot : il
   * écrit dans les réglages que l'écran utilise déjà (provinces, arrondissement,
   * bornes de la concurrence, rayon, hypothèses), puis allume la couche des
   * zones prioritaires et cadre la carte. Ce qu'il apporte, c'est l'ordre des
   * questions et ce qu'on voit en y répondant.
   */
  wizOuvrir(){ this.setState({ wiz: 1, wville: '', reseau: false, compare: false, view: 'map' }); }
  wizFermer(){ this.setState({ wiz: 0 }); }
  wizAller(n){ this.setState({ wiz: Math.max(1, Math.min(4, n)) }); }

  // Les arrondissements des provinces cochées, classés par ménages par point
  // de vente : c'est la question de l'étape, donc l'ordre du tableau.
  wizArrs(){
    const s = this.state;
    const noms = Array.from(new Set(s.communes.filter(c => s.prov[c.prov]).map(c => c.arr))).filter(a => a && a !== '—');
    return noms.map(n => {
      const st = this.arrStats(n);
      return { nom: n, communes: st.communes, hh: st.hh, shops: st.shops, strong: st.strong,
        chains: st.chains, marques: st.marques,
        perShop: st.shops ? st.hh / st.shops : 0, avg: st.avg };
    }).sort((a, b) => b.perShop - a.perShop);
  }

  // La distribution des notes de la sélection, découpée par les deux bornes.
  wizNotes(){
    const s = this.state, w = s.weak, t = s.thresh;
    const notes = this.shops().map(b => this.rating(b)).filter(Boolean);
    const bins = [[0, 2.5], [2.5, 3], [3, 3.5], [3.5, 4], [4, 4.3], [4.3, 4.5], [4.5, 4.7], [4.7, 5.01]];
    const cnt = bins.map(b => notes.filter(r => r >= b[0] && r < b[1]).length);
    const max = Math.max(1, ...cnt);
    return {
      notes: notes.length, sans: this.shops().length - notes.length,
      ignores: notes.filter(r => r < w).length,
      comptes: notes.filter(r => r >= w && r < t).length,
      forts: this.shops().filter(b => this.isStrong(b)).length,
      bars: bins.map((b, i) => ({
        label: b[0].toFixed(1).replace('.', ',') + ' – ' + (b[1] > 5 ? '5' : b[1].toFixed(1).replace('.', ',')),
        n: cnt[i], h: Math.round(cnt[i] / max * 100),
        cls: b[1] <= w ? 'ign' : (b[0] >= t ? 'fort' : (b[0] >= 4 ? 'mid' : ''))
      })),
      // position des deux traits, en % de la largeur (échelle 0 → 5)
      xWeak: Math.max(0, Math.min(100, w / 5 * 100)),
      xFort: Math.max(0, Math.min(100, t / 5 * 100))
    };
  }

  // Ce qu'il faut de ménages pour atteindre le CA visé, à emprise courante.
  wizMenages(){
    const s = this.state;
    const eMax = (s.empriseMax || 30) / 100;
    const em = s.emprise > 0 ? s.emprise / 100 : eMax / (1 + (s.compK || 0.22) * 1.2);   // 1,2 = pression d'un secteur ordinaire
    const d = s.spend * em / (1 - s.passage / 100);
    return { emprise: em, hh: d > 0 ? s.caVise / d : 0 };
  }

  // Les points chauds : le balayage déjà en place, sous les conditions posées.
  wizChauds(){ return this.state.communes.length ? this.scanArrs() : []; }

  /* Quand rien ne sort, dire lequel des filtres est responsable. Sans ça, on
   * relâche au hasard quatre réglages pour retrouver une liste. */
  wizObstacle(){
    const s = this.state, brut = this._arrBrut || [];
    if (!brut.length){
      // Deux vides bien différents : rien à balayer, ou tout balayé et tout
      // écarté parce que chaque point tombe dans le rayon d'un concurrent fort.
      return this._arrCom
        ? 'Les ' + fmtInt(this._arrCom) + ' communes ont été balayées, mais chaque point tombe à moins de '
          + s.radius.toFixed(1).replace('.', ',') + ' km d’un concurrent fort. Relevez le seuil « concurrent fort » ou réduisez le rayon.'
        : 'Aucune zone habitée n’a été balayée : élargissez les provinces ou l’arrondissement.';
    }
    const tests = [
      ['le score minimum de ' + (s.minScore || 0), p => p.score >= (s.minScore || 0)],
      ['le plancher de CA', p => !s.caVise || p.ca >= s.caVise],
      [s.nMax === 0 ? 'la condition « aucune boulangerie »' : 'la limite de concurrents', p => s.nMax == null || p.n <= s.nMax],
      ['le minimum de ménages', p => !s.hhMin || p.hh >= s.hhMin],
      ['la proximité d’un zoning', p => s.zoneMax == null || this.prochedeZoning(p.lat, p.lng)]
    ];
    let best = null;
    tests.forEach((t, i) => {
      if (i === 1 && !s.caVise) return;
      if (i === 2 && s.nMax == null) return;
      if (i === 3 && !s.hhMin) return;
      if (i === 4 && s.zoneMax == null) return;
      const n = brut.filter(p => tests.every((u, j) => j === i || u[1](p))).length;
      if (n > 0 && (!best || n > best.n)) best = { nom: t[0], n: n };
    });
    return best
      ? 'Sans ' + best.nom + ', ' + best.n + ' emplacement' + (best.n > 1 ? 's resteraient' : ' resterait') + '.'
      : 'Aucun emplacement ne tient ces conditions, même en relâchant un seul filtre.';
  }

  // Fin de l'assistant : la couche des zones prioritaires s'allume, la carte se
  // cadre sur l'arrondissement choisi, et le bandeau rappelle les réponses.
  wizTerminer(){
    const s = this.state;
    this.setState({ wiz: 0, wizFait: true, sel: null, view: 'map',
      layers: Object.assign({}, s.layers, { prio: true, excl: true, shops: true }) });
    this.wizCadrer();
  }

  // Le cadrage ne décide plus du résultat — le balayage est par arrondissement —
  // mais il décide de ce qu'on voit. Un arrondissement choisi : on montre tout
  // son territoire. « Tous » : on cadre sur les points trouvés, pour ne pas
  // ouvrir sur un pays où trois pastilles se perdent.
  wizCadrer(){
    const s = this.state;
    if (!this.map || !window.L) return;
    let pts;
    if (s.arr !== 'all'){
      pts = s.communes.filter(c => c.arr === s.arr).map(c => [c.lat, c.lng]);
    } else {
      const chauds = this.scanArrs();
      pts = chauds.length ? chauds.map(p => [p.lat, p.lng])
        : s.communes.filter(c => s.prov[c.prov]).map(c => [c.lat, c.lng]);
    }
    if (!pts.length) return;
    const b = window.L.latLngBounds(pts);
    try { this.map.fitBounds(b.pad(0.12)); } catch (e) { /* carte non prête */ }
    this.scheduleRedraw(120);
    setTimeout(() => { if (this.state.wizFait) this.render(); }, 420);
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
    // Les points chauds de la carte, tels quels : la liste de droite montre les
    // mêmes cercles verts, dans le même ordre. Un clic sur un repère évalue le
    // point, donc surligne sa ligne — c'est la même sélection des deux côtés.
    const chauds = (s.layers.prio && s.communes.length) ? this.scanPrio() : [];
    const memePoint = p => !!(x && Math.abs(x.lat - p.lat) < 1e-9 && Math.abs(x.lng - p.lng) < 1e-9);
    const unArr = chauds.length > 0 && chauds.every(p => p.arr === chauds[0].arr);

    // ----- recherche par ville -----
    // Elle cherche dans TOUTES les communes relevées, pas seulement celles des
    // provinces cochées : on ne peut pas trouver ce qu'on a masqué. Aller à une
    // ville hors sélection recoche sa province, sinon la fiche s'ouvrirait sans
    // un seul concurrent — la carte ne les charge pas.
    const nParCom = {};
    if (sansAccent(s.ville).trim().length >= 2 || sansAccent(s.wville).trim().length >= 2){
      s.bakeries.forEach(b => { nParCom[b.ins] = (nParCom[b.ins] || 0) + 1; });
    }
    const vq = sansAccent(s.ville).trim();
    const aller = c => () => {
      const patch = { ville: '' };
      if (!s.prov[c.prov]) patch.prov = Object.assign({}, s.prov, { [c.prov]: true });
      if (s.arr !== 'all' && s.arr !== c.arr) patch.arr = c.arr;
      self.setState(patch);
      setTimeout(() => {
        if (self.map) self.map.setView([c.lat, c.lng], 12);
        self.evaluate(c.lat, c.lng);
      }, 60);
    };
    // Le relevé garde les deux noms : « Ypres » côté français, « Ieper » côté
    // néerlandais. Chercher l'un doit trouver l'autre — le pays est bilingue,
    // et OpenStreetMap ne tranche pas toujours dans le même sens.
    const chercherVille = q => q.length < 2 ? [] : s.communes
      .map(c => {
        const a = sansAccent(c.name).indexOf(q);
        const b = c.nl ? sansAccent(c.nl).indexOf(q) : -1;
        return { c: c, i: a < 0 ? b : (b < 0 ? a : Math.min(a, b)) };
      })
      .filter(o => o.i >= 0)
      .sort((a, b) => (a.i - b.i) || (b.c.hh - a.c.hh))   // le nom qui commence par la saisie d'abord
      .slice(0, 8);
    const ligneVille = o => ({
      nom: o.c.name + (o.c.nl && sansAccent(o.c.nl) !== sansAccent(o.c.name) ? ' (' + o.c.nl + ')' : ''),
      meta: 'arr. ' + o.c.arr + ' · ' + fmtInt(o.c.hh) + ' ménages · '
        + (nParCom[o.c.ins] || 0) + ' commerce' + ((nParCom[o.c.ins] || 0) > 1 ? 's' : '')
    });
    const trouvees = chercherVille(vq);

    // ----- lignes des tableaux -----
    const scan = (s.view === 'zones' && s.communes.length) ? this.scanPrio() : [];
    let zonesRows = scan.map((p, i) => {
      const emp = p.ca ? (p.ca * (1 - s.passage / 100)) / (p.hh * s.spend) : 0;
      const cc = self.concurrenceAu(p.lat, p.lng, s.radius);
      return {
        rang: i + 1, commune: p.commune, arr: p.arr, lat: p.lat, lng: p.lng,
        score: p.score, hh: fmtInt(p.hh), hhRaw: Math.round(p.hh), n: p.n,
        forts: cc.forts, chaines: cc.chainesTxt || (cc.chaines ? String(cc.chaines) : '—'), chainesN: cc.chaines, noms: cc.noms,
        emprise: (emp * 100).toFixed(1) + ' %', empriseRaw: (emp * 100).toFixed(1),
        ca: fmtEur(p.ca), caRaw: Math.round(p.ca),
        m2: fmtEur(p.ca / s.surface), m2Raw: Math.round(p.ca / s.surface),
        open: () => goMap(p.lat, p.lng, 13, true)
      };
    });
    if (sk !== 'rang'){
      const num = { score: 'score', hh: 'hhRaw', n: 'n', forts: 'forts', chaines: 'chainesN', emprise: 'empriseRaw', ca: 'caRaw', m2: 'm2Raw' };
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

    // Une colonne du réseau : les références de l'étude ont tous leurs
    // chiffres, un point saisi à la main n'a que ceux qu'on lui a donnés.
    const ou = (v, f) => (v == null || v === '' || isNaN(+v)) ? '—' : f(+v);
    const reseauRows = r => [
      { k: 'Population de la zone', v: ou(r.pop, fmtInt) },
      { k: 'Ménages', v: ou(r.hh, fmtInt) },
      { k: 'Taille des ménages', v: ou(r.taille, v => String(v).replace('.', ',')) },
      { k: 'Revenu moyen / ménage', v: ou(r.revenu, fmtEur) },
      { k: 'Part de jeunes', v: ou(r.jeunes, v => String(v).replace('.', ',') + ' %') },
      { k: 'Part d\'actifs', v: ou(r.actifs, v => String(v).replace('.', ',') + ' %') },
      { k: 'Part de seniors', v: ou(r.seniors, v => String(v).replace('.', ',') + ' %') },
      { k: 'Dépense boulangerie / ménage', v: ou(r.depense, fmtEur) },
      { k: 'Marché boulangerie', v: ou(r.marche != null && r.marche !== '' ? r.marche : (r.hh && r.depense ? r.hh * r.depense : null), fmtEur) },
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
      // --- l'assistant ---------------------------------------------------
      wiz: (() => {
        if (!s.wiz && !s.wizFait) return { etape: 0, fait: false };
        const chauds = s.wizFait ? self.wizChauds() : [];
        const nd = self.wizNotes(), men = self.wizMenages();
        const arrs = s.wiz === 2 ? self.wizArrs() : [];
        const wq = sansAccent(s.wville).trim();
        const wtrouvees = s.wiz === 2 ? chercherVille(wq) : [];
        // Choisir une ville, ici, c'est choisir son arrondissement : c'est la
        // question de l'étape. La province se recoche au besoin, sans quoi
        // l'arrondissement ne serait même pas dans le tableau.
        const allerArr = c => () => {
          const patch = { wville: '', arr: c.arr, sel: null };
          if (!s.prov[c.prov]) patch.prov = Object.assign({}, s.prov, { [c.prov]: true });
          self.setState(patch);
        };
        const provOn = PROV.filter(p => s.prov[p.code]);
        const csSel = s.communes.filter(c => s.prov[c.prov]);
        return {
          etape: s.wiz, fait: s.wizFait,
          fermer: () => self.wizFermer(),
          aller: n => () => self.wizAller(n),
          terminer: () => self.wizTerminer(),
          rouvrir: () => self.wizOuvrir(),
          cacher: () => self.setState({ wizFait: false }),
          pas: [['La zone', 'province'], ['L\u2019arrondissement', 'où précisément'],
            ['La concurrence', 'qui compte, qui non'], ['Le chiffre visé', 'CA et hypothèses']],
          // 1 — les provinces
          provinces: PROV.map(p => ({
            code: p.code, reg: p.reg, nom: p.name, on: !!s.prov[p.code],
            shops: s.bakeries.filter(b => b.prov === p.code).length,
            hh: s.communes.filter(c => c.prov === p.code).reduce((a, c) => a + c.hh, 0),
            toggle: () => self.setState({ prov: Object.assign({}, s.prov, { [p.code]: !s.prov[p.code] }), sel: null })
          })),
          provResume: provOn.length
            ? provOn.length + ' province' + (provOn.length > 1 ? 's' : '') + ' retenue' + (provOn.length > 1 ? 's' : '')
              + ' · ' + fmtInt(self.shops().length) + ' commerces · ' + fmtInt(csSel.reduce((a, c) => a + c.hh, 0)) + ' ménages'
            : 'Aucune province retenue',
          provVide: !provOn.length,
          // 2 — les arrondissements
          arrs: arrs.map(a => Object.assign({}, a, {
            on: s.arr === a.nom,
            hhTxt: fmtInt(a.hh), perTxt: fmtInt(a.perShop),
            barre: Math.round(Math.max(4, Math.min(100, a.perShop / (arrs[0] ? arrs[0].perShop : 1) * 100))),
            avgTxt: a.avg ? a.avg.toFixed(1).replace('.', ',') : '—',
            chains: a.chains,
            // Les marques en toutes lettres : « 3 » ne dit pas si c'est Paul ou
            // le boulanger du coin qui s'est déclaré une enseigne.
            marquesTxt: a.marques.slice(0, 3).map(m => m.nom + (m.n > 1 ? ' ×' + m.n : '')).join(', ')
              + (a.marques.length > 3 ? ', +' + (a.marques.length - 3) : ''),
            choisir: () => self.setState({ arr: a.nom, sel: null })
          })),
          arrTous: s.arr === 'all', choisirTous: () => self.setState({ arr: 'all', sel: null }),
          // La même recherche qu'à gauche, mais ici elle répond à la question de
          // l'étape : on connaît sa ville, rarement son arrondissement.
          wville: s.wville,
          setWville: e => self.setState({ wville: e.target.value }),
          wvilleEntree: e => { if (e.key === 'Enter' && wtrouvees.length){ e.preventDefault(); allerArr(wtrouvees[0].c)(); } },
          wvilles: wtrouvees.map(o => Object.assign(ligneVille(o), {
            meta: ligneVille(o).meta + (s.prov[o.c.prov] ? '' : ' · province à recocher'),
            aller: allerArr(o.c)
          })),
          wvilleVide: wq.length >= 2 && !wtrouvees.length ? 'Aucune commune de ce nom dans le relevé.' : '',
          arrResume: s.arr === 'all' ? 'Toute la sélection · ' + fmtInt(self.shops().length) + ' commerces'
            : (() => { const st = self.arrStats(s.arr); return s.arr + ' · ' + fmtInt(st.communes) + ' communes · '
                + fmtInt(st.hh) + ' ménages · ' + fmtInt(st.shops) + ' commerce' + (st.shops > 1 ? 's' : '')
                + ', dont ' + fmtInt(st.strong) + ' fort' + (st.strong > 1 ? 's' : '')
                + ' et ' + fmtInt(st.chains) + ' de chaîne'
                + (st.marques.length ? ' (' + st.marques.slice(0, 3).map(m => m.nom).join(', ') + ')' : ''); })(),
          // 3 — la concurrence
          weak: s.weak, thresh: s.thresh, radius: s.radius,
          setWeak: e => { const v = parseFloat(String(e.target.value).replace(',', '.')); if (!isNaN(v)) self.setParam({ weak: Math.max(0, Math.min(s.thresh - 0.1, v)) }); },
          setThresh: e => { const v = parseFloat(String(e.target.value).replace(',', '.')); if (!isNaN(v)) self.setParam({ thresh: Math.max(s.weak + 0.1, Math.min(5, v)) }); },
          setRadius: e => { const v = parseFloat(String(e.target.value).replace(',', '.')); if (!isNaN(v)) self.setParam({ radius: Math.max(0.5, Math.min(15, v)) }); },
          notes: nd,
          // Le terrain : « aucune boulangerie » et la densité. Le compte dit ce
          // que chaque filtre retire, sinon on tâtonne sans savoir pourquoi la
          // liste est vide.
          nMax: s.nMax, hhMin: s.hhMin,
          sansBoul: s.nMax === 0,
          toggleSansBoul: () => self.setParam({ nMax: s.nMax === 0 ? null : 0 }),
          setNMax: e => { const v = parseInt(String(e.target.value).replace(/[^0-9]/g, ''), 10); self.setParam({ nMax: isNaN(v) ? null : Math.max(0, v) }); },
          setHhMin: e => { const v = parseInt(String(e.target.value).replace(/[^0-9]/g, ''), 10); self.setParam({ hhMin: isNaN(v) ? 0 : Math.max(0, v) }); },
          hhMinTxt: s.hhMin ? fmtInt(s.hhMin) : '',
          nMaxTxt: s.nMax == null ? '' : String(s.nMax),
          // Le zoning : la donnée peut manquer (relevé hebdomadaire séparé).
          // Le filtre le dit alors, plutôt que de vider la liste en silence.
          zoneMax: s.zoneMax, zoneMaxTxt: s.zoneMax == null ? '' : String(s.zoneMax).replace('.', ','),
          zoning: s.zoning.length,
          zoningPret: s.zoning.length > 0,
          setZoneMax: e => { const v = parseFloat(String(e.target.value).replace(',', '.')); self.setParam({ zoneMax: isNaN(v) ? null : Math.max(0, Math.min(30, v)) }); },
          toggleZoning: () => self.setParam({ zoneMax: s.zoneMax == null ? 2 : null }),
          // 4 — le chiffre visé
          caVise: s.caVise, caViseTxt: s.caVise ? fmtInt(s.caVise) : '',
          setCaVise: e => { const v = parseFloat(String(e.target.value).replace(/[^0-9.,]/g, '').replace(',', '.')); self.setParam({ caVise: isNaN(v) ? 0 : Math.max(0, v) }); },
          hhVises: men.hh ? fmtInt(men.hh) : '—',
          empriseVise: (men.emprise * 100).toFixed(1).replace('.', ',') + ' %',
          hyp: [
            { k: 'Dépense par ménage', u: '€/an', v: s.spend, set: e => self.setParam({ spend: parseFloat(e.target.value) || 0 }) },
            { k: 'Part du passage', u: '%', v: s.passage, set: e => self.setParam({ passage: Math.min(95, parseFloat(e.target.value) || 0) }) },
            { k: 'Emprise maximale', u: '%', v: s.empriseMax, set: e => self.setParam({ empriseMax: parseFloat(e.target.value) || 30 }) },
            { k: 'Surface nette cible', u: 'm²', v: s.surface, set: e => self.setParam({ surface: parseFloat(e.target.value) || 1 }) }
          ],
          // le résultat
          chauds: chauds.map((p, i) => ({
            rang: i + 1, commune: p.commune, arr: p.arr, ca: fmtEur(p.ca), score: p.score,
            hh: fmtInt(p.hh) + ' ménages', n: (() => { const cc = self.concurrenceAu(p.lat, p.lng, s.radius); return p.n + ' concurrent' + (p.n > 1 ? 's' : '') + (cc.forts ? ', ' + cc.forts + ' fort' + (cc.forts > 1 ? 's' : '') : '') + (cc.chainesTxt ? ' · ' + cc.chainesTxt : ''); })(),
            voir: () => { if (self.map) self.map.setView([p.lat, p.lng], 12); self.evaluate(p.lat, p.lng); }
          })),
          nChauds: chauds.length,
          // Le diagnostic n'a de sens qu'après un balayage : avant, il lirait
          // une liste brute qui n'existe pas encore.
          obstacle: s.wizFait && !chauds.length ? self.wizObstacle() : '',
          nArrs: s.arr === 'all' ? new Set(chauds.map(p => p.arr)).size : 0,
          caRange: chauds.length ? fmtEur(Math.min(...chauds.map(c => c.ca))) + ' → ' + fmtEur(Math.max(...chauds.map(c => c.ca))) : '',
          resume: [
            s.arr === 'all'
              ? (provOn.length === PROV.length ? 'toute la Belgique' : provOn.map(p => p.name).join(', '))
                + (chauds.length ? ' · ' + new Set(chauds.map(p => p.arr)).size + ' arrondissement'
                   + (new Set(chauds.map(p => p.arr)).size > 1 ? 's' : '') : '')
              : s.arr,
            'concurrent dès ' + s.weak.toFixed(1).replace('.', ',') + ' ★',
            'fort à ' + s.thresh.toFixed(1).replace('.', ',') + ' ★',
            'rayon ' + s.radius.toFixed(1).replace('.', ',') + ' km',
            s.caVise ? 'CA ≥ ' + fmtEur(s.caVise) : 'sans plancher de CA'
          ].concat(s.nMax != null ? [s.nMax === 0 ? 'aucune boulangerie dans le rayon' : 'au plus ' + s.nMax + ' concurrent' + (s.nMax > 1 ? 's' : '')] : [])
           .concat(s.hhMin ? ['≥ ' + fmtInt(s.hhMin) + ' ménages dans le rayon'] : [])
           .concat(s.zoneMax != null ? ['à ' + String(s.zoneMax).replace('.', ',') + ' km d’un zoning'] : [])
        };
      })(),
      ouvrirWiz: () => self.wizOuvrir(),
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
      layersPlus: [['prio', 'Zones prioritaires'], ['zoning', 'Zoning d\'activité'], ['heat', 'Densité de ménages'], ['roads', 'Axes pendulaires']]
        .map(([k, name]) => ({ name: name, on: !!s.layers[k], toggle: layerToggle(k) })),
      layersMinus: [['excl', 'Zones d\'exclusion'], ['shops', 'Boulangeries concurrentes'], ['cluster', 'Regrouper les points au dézoom']]
        .map(([k, name]) => ({ name: name, on: !!s.layers[k], toggle: layerToggle(k) })),
      onlyPrio: onlyPrio,
      toggleOnlyPrio: () => self.setState({ layers: Object.assign({}, onlyPrio ? LAYERS_CONC : LAYERS_PRIO), sel: null }),
      minScore: s.minScore, slideMinScore: this.slide('minScore', v => parseInt(v, 10)),
      setMinScore: e => self.setState({ minScore: parseInt(e.target.value, 10) }),
      prioCount: s.layers.prio && s.communes.length ? String(chauds.length) : '—',
      presetPrio: () => self.setState({ layers: { shops: false, cluster: true, excl: false, prio: true, heat: true, roads: false, zoning: true }, sel: null }),
      presetConc: () => self.setState({ layers: Object.assign({}, LAYERS_CONC), sel: null }),
      exportParams: () => self.csv('ceo_parametres', ['parametre', 'valeur'], [
        ['depense_menage_eur', s.spend], ['emprise_imposee_pct', s.emprise], ['part_passage_pct', s.passage],
        ['surface_nette_m2', s.surface], ['emprise_max_pct', s.empriseMax], ['sensibilite_concurrence', s.compK],
        ['taille_menages', s.hhSize], ['rayon_exclusion_km', s.radius], ['seuil_concurrent_fort', s.thresh],
        ['seuil_pas_un_concurrent', s.weak], ['ca_annuel_vise_eur', s.caVise],
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
      popCoverage: self._grid
        ? 'Grille 1 km² du recensement 2021 (StatBel, diffusion Eurostat) : ' + fmtInt(self._grid.n) + ' cellules, ' + fmtInt(self._grid.pop) + ' habitants · ' + fmtInt(s.communes.filter(c => c.grille).length) + ' communes sur la grille, ' + fmtInt(s.communes.filter(c => c.est).length) + ' estimées'
        : fmtInt(s.communes.filter(c => !c.est).length) + ' communes avec population source · ' + fmtInt(s.communes.filter(c => c.est).length) + ' estimées par densité des communes voisines',
      popTip: 'La population vit sur la grille de 1 km² du recensement 2021 (StatBel, diffusée par Eurostat/GISCO) : chaque zone compte les habitants des cellules de son rayon, là où ils habitent — et non la population communale étalée sur toute la commune. Les totaux par commune en découlent ; un CSV StatBel importé (code NIS ; population) prime pour la commune.',
      importPops: e => self.importPops(e),
      tips: TIP_REGL, concTips: TIP_CONC,
      calage: (() => {
        const c = self.calage();
        const rows = c.rows.map(r => ({
          id: r.m.id, nom: String(r.m.nom || '').replace(/^L['’]?\s*Atelier by\s*-?\s*/i, '').replace(/^Atelier by\s*-?\s*/i, '') || r.m.nom,
          pos: r.m.position, mois: r.m.mois, annualise: r.m.annualise,
          reel: r.m.caAnnuel ? fmtEur(r.m.caAnnuel) : 'CA réel inconnu', modele: r.ev ? fmtEur(r.ev.ca) : (r.m.lat == null ? 'position inconnue' : '—'),
          ecart: r.ratio ? (r.ratio >= 1 ? '+' : '−') + Math.round(Math.abs(r.ratio - 1) * 100) + ' %' : '—',
          ecartColor: r.ratio ? (Math.abs(r.ratio - 1) <= 0.25 ? '#1b5e20' : '#c17a2a') : 'var(--color-text-muted)',
          place: () => { self.setState({ placing: r.m.id, view: 'map', reseau: false, compare: false }); self.notify('Clique sur la carte à l\'emplacement de ' + r.m.nom); }
        }));
        return { rows: rows, med: c.med, n: c.n, placing: !!s.placing, caler: () => self.caler(),
          intro: 'CA réel des magasins ouverts (P&L du panel, douze derniers mois clos) face au CA que le modèle prédit à leur emplacement, hypothèses courantes. L\'écart lit le réel par rapport au modèle : +30 % = le magasin fait 30 % de plus que prévu.',
          bouton: c.med ? 'Caler la dépense/ménage (× ' + c.med.toFixed(2).replace('.', ',') + ')' : '',
          note: c.med ? 'Le calage multiplie la dépense par ménage par le rapport médian réel ÷ modèle : le modèle retrouve le réseau, et les écarts qui restent disent ce que l\'emplacement n\'explique pas.' : 'Il faut au moins un magasin ouvert, positionné et avec un CA réel pour caler.',
          vide: s.magasins && s.magasins.length ? 'Aucun magasin ouvert positionné.' : (self.useApi() ? 'Magasins du réseau en cours de lecture…' : 'Hors ligne : magasins du réseau indisponibles.'),
          tip: 'Pour chaque magasin ouvert : CA réel = somme des douze derniers mois clos du P&L (annualisé s\'il y en a moins) ; CA modèle = ménages du rayon × dépense × emprise ÷ (1 − passage) à son emplacement, tous les commerces OSM comptés sauf lui-même.\nCalage : dépense ← dépense × médiane(réel ÷ modèle).' };
      })(),
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
      /* ----- ce que la carte montre : thème et légende classée ----- */
      theme: s.theme,
      themes: THEMES.map(t => ({ k: t[0], label: t[1], tip: t[2], on: s.theme === t[0], pick: () => self.setVue({ theme: t[0] }) })),
      themeLegend: self.legendeDe(s.theme, true),
      legendeDe: t => self.legendeDe(t, false),
      echelle: s.echelle,
      echelles: [['maille', 'Maille 1 km²'], ['commune', 'Commune'], ['arrondissement', 'Arrondissement']].map(([k, label]) => ({ k: k, label: label, on: (s.echelle || 'maille') === k, pick: () => self.setVue({ echelle: k }) })),
      echelleNote: (s.echelle || 'maille') === 'maille' ? '' : (s.theme === 'ca' || s.theme === 'score' ? 'CA et score se lisent à la maille seulement : à cette échelle, la carte montre les ménages par point de vente. ' : '')
        + 'Chaque maille prend la valeur de sa ' + (s.echelle === 'commune' ? 'commune' : 'commune, et la commune celle de son arrondissement') + ' ; les quartiles sont ceux des ' + (s.echelle === 'commune' ? 'communes' : 'arrondissements') + ' de la sélection.',
      trio: !!s.trio,
      toggleTrio: () => self.setState({ trio: !s.trio, compare: false, reseau: false, view: 'map' }),
      trioPans: s.trio ? [
        { i: 1, label: (THEMES.find(t => t[0] === s.theme) || THEMES[0])[1], rows: self.legendeDe(s.theme, false).rows, select: null },
        { i: 2, label: '', rows: self.legendeDe(s.trio2, false).rows, select: { value: s.trio2, set: e => self.setVue({ trio2: e.target.value }) } },
        { i: 3, label: '', rows: self.legendeDe(s.trio3, false).rows, select: { value: s.trio3, set: e => self.setVue({ trio3: e.target.value }) } }
      ] : [],
      trioChoix: THEMES.filter(t => t[0] !== 'aucun').map(t => ({ value: t[0], label: t[1] })),
      // À l'échelle de la commune ou de l'arrondissement : le classement des
      // entités, en tête et en queue, cliquables pour cadrer la carte.
      classement: (() => {
        const ech = s.echelle || 'maille';
        if (ech === 'maille' || s.theme === 'aucun') return null;
        const rv = self.rasterVals(s.theme, false);
        if (!rv || !rv.entites) return null;
        const th = rv.theme, l = rv.entites.liste;
        const fmt = e => th === 'potentiel' ? fmtInt(e.v) : th === 'menages' ? fmtInt(e.v) : e.v.toFixed(1).replace('.', ',');
        const unite = th === 'potentiel' ? 'ménages / point' : th === 'menages' ? 'ménages' : '/ 10 000 hab.';
        const ligne = (e, rang) => ({ rang: rang, nom: e.nom, sous: e.sous, v: fmt(e), unite: unite,
          aller: () => { if (!self.map) return; if (e.bb) self.map.fitBounds([[e.bb[0], e.bb[1]], [e.bb[2], e.bb[3]]], { padding: [20, 20] }); else self.map.setView([e.lat, e.lng], ech === 'commune' ? 12 : 10); } });
        const nomEch = ech === 'commune' ? 'communes' : 'arrondissements';
        return {
          titre: th === 'potentiel' ? 'Les plus sous-servis' : th === 'menages' ? 'Les plus peuplés' : 'Les plus denses en commerces',
          titreQueue: th === 'potentiel' ? 'Les plus saturés' : th === 'menages' ? 'Les moins peuplés' : 'Les moins denses',
          tete: l.slice(0, 7).map((e, i) => ligne(e, i + 1)),
          queue: l.slice(-3).reverse().map((e, i) => ligne(e, l.length - i)),
          note: l.length + ' ' + nomEch + ' dans la sélection · ' + (th === 'potentiel' ? 'ménages ÷ commerces : beaucoup de ménages pour peu de commerces, le territoire est sous-servi' : th === 'menages' ? 'ménages des communes, recensement 2021' : 'commerces relevés pour dix mille habitants') + ' · clique une ligne pour cadrer la carte'
        };
      })(),
      themeTip: 'La carte peint chaque maille de 1 km² du recensement dont la commune passe les filtres, avec le modèle de la fiche appliqué à la maille : ménages et concurrents dans le rayon réglé ci-dessous. Quatre classes aux quartiles de la sélection ; l\'œil éteint une classe. La concurrence est celle qu\'OpenStreetMap connaît : une maille sans concurrent peut l\'être parce que le commerce n\'est pas cartographié.',
      plis: s.plis,
      pli: k => () => self.setVue({ plis: Object.assign({}, s.plis, { [k]: !s.plis[k] }) }),
      /* ----- la zone d'étude : outils de dessin ----- */
      tool: s.tool || 'point',
      tools: OUTILS.map(o => ({ k: o[0], label: o[1], tip: o[2], on: (s.tool || 'point') === o[0], pick: () => self.setTool(o[0]) })),
      iso: s.iso, isoChoix: ISO_CHOIX.map(c => ({ value: c[0], label: c[1] })), setIso: e => self.setVue({ iso: e.target.value }),
      dessinHint: (() => {
        const t = s.tool || 'point';
        if (s.isoBusy) return 'Isochrone en cours de calcul…';
        if (t === 'point') return '';
        if (t === 'polygone') return s.dessin ? s.dessin + ' sommet' + (s.dessin > 1 ? 's' : '') + ' — double-clic ou clic sur le premier pour fermer, Échap pour annuler' : 'Clique un premier sommet.';
        if (t === 'cercle') return s.dessin ? 'Clique le bord du cercle.' : 'Clique le centre du cercle.';
        if (t === 'rectangle') return s.dessin ? 'Clique le coin opposé.' : 'Clique un premier coin.';
        return 'Clique le point de départ : la zone atteignable est calculée sur les routes.';
      })(),
      effacerZone: () => { self.cancelDraw(); self.setState({ sel: null }); },
      legend: [
        { color: R_COL.high, label: 'Note 4,5 et plus' },
        { color: R_COL.mid, label: 'Note 3,5 – 4,5' },
        { color: R_COL.low, label: 'Note inférieure à 3,5' },
        { color: R_COL.none, label: 'Note non renseignée' },
        { color: '#fff', border: '#221E1A', label: 'Enseigne de chaîne — liseré noir' },
        { color: 'rgba(141,29,44,.35)', label: 'Zone d\'exclusion — concurrence forte' },
        { color: '#1b5e20', label: 'Zone prioritaire — score élevé' },
        { color: '#FAC775', label: 'Zone candidate retenue' },
        { color: 'rgba(107,122,143,.5)', label: 'Zoning d\'activité, de commerce, de vente' }
      ],
      ville: s.ville,
      setVille: e => self.setState({ ville: e.target.value }),
      // Entrée ouvre la première trouvée : la liste est juste dessous, mais on
      // ne tape pas un nom pour attraper la souris ensuite.
      villeEntree: e => { if (e.key === 'Enter' && trouvees.length){ e.preventDefault(); aller(trouvees[0].c)(); } },
      villes: trouvees.map(o => Object.assign(ligneVille(o), {
        meta: ligneVille(o).meta + (s.prov[o.c.prov] ? '' : ' · province décochée'),
        aller: aller(o.c)
      })),
      villeVide: vq.length >= 2 && !trouvees.length ? 'Aucune commune de ce nom dans le relevé.' : '',
      pointsChauds: chauds.map((p, i) => ({
        rang: i + 1, commune: p.commune, score: p.score, ca: fmtEur(p.ca),
        // L'arrondissement ne se répète que s'il y en a plusieurs : sur un seul
        // il repoussait « concurrents » à la ligne pour ne rien apprendre.
        meta: (() => { const cc = self.concurrenceAu(p.lat, p.lng, s.radius); return (unArr ? '' : 'arr. ' + p.arr + ' · ') + fmtInt(p.hh) + ' ménages · '
          + p.n + ' concurrent' + (p.n > 1 ? 's' : '') + (cc.forts ? ' (' + cc.forts + ' fort' + (cc.forts > 1 ? 's' : '') + ')' : '') + (cc.chainesTxt ? ' · chaîne' + (cc.chaines > 1 ? 's' : '') + ' : ' + cc.chainesTxt : ''); })(),
        on: memePoint(p),
        voir: () => {
          if (self.map) self.map.setView([p.lat, p.lng], Math.max(self.map.getZoom(), 12));
          self.evaluate(p.lat, p.lng);
        }
      })),
      chaudsNote: !s.layers.prio
        ? 'Coche « Zones prioritaires » ci-contre, ou lance l’assistant, pour les faire sortir.'
        : chauds.length
          ? chauds.length + ' emplacement' + (chauds.length > 1 ? 's' : '') + ' au-dessus du score '
            + s.minScore + (unArr ? ', arr. ' + chauds[0].arr + '.' : s.wizFait ? ', sur les arrondissements balayés.' : ', dans la vue courante.')
            + ' Clique une ligne ou un repère : c’est la même sélection.'
          : 'Aucun emplacement ne passe les filtres dans cette vue.',
      hasSel: !!x,
      selRang: (chauds.findIndex(memePoint) + 1) || 0,
      selCommune: x ? x.commune : '',
      selGeo: x ? 'arr. ' + x.arr + ' · ' + x.prov + ' · ' + x.lat.toFixed(4) + ', ' + x.lng.toFixed(4) : '',
      selZone: x && x.zone ? self.zoneLibelle(x.zone) : '',
      selDisque: x && x.zone && x.disque ? 'Au même centre, le rayon de ' + s.radius.toFixed(1).replace('.', ',') + ' km compte ' + fmtInt(x.disque.hh) + ' ménages et '
        + x.disque.n + ' concurrent' + (x.disque.n > 1 ? 's' : '') + ' — la zone dessinée en compte '
        + fmtInt(x.hh) + ' et ' + x.near.length + ' : ' + (x.hh >= x.disque.hh ? '+ ' : '− ') + fmtInt(Math.abs(x.hh - x.disque.hh)) + ' ménages, soit '
        + (x.hh >= x.disque.hh ? '+ ' : '− ') + fmtEur(Math.abs(x.hh - x.disque.hh) * s.spend) + ' de marché.' : '',
      selVerdict: x ? (x.blocked.length ? 'Zone exclue — concurrence forte' : x.score >= 55 ? 'Zone candidate prioritaire' : 'Zone candidate secondaire') : '',
      selVerdictBg: x ? (x.blocked.length ? 'rgba(141,29,44,.10)' : x.score >= 55 ? 'rgba(27,94,32,.10)' : 'var(--color-background-secondary)') : '',
      selVerdictColor: x ? (x.blocked.length ? '#8D1D2C' : x.score >= 55 ? '#1b5e20' : 'var(--color-text)') : '',
      selVerdictNote: x ? (x.blocked.length
        ? x.blocked.length + ' concurrent(s) fort(s) à moins de ' + s.radius.toFixed(1) + ' km' + (x.zone ? ' du centre' : '') + ' : ' + x.blocked.slice(0, 3).map(o => o.b.name).join(', ')
        : 'Score d\'opportunité ' + x.score + '/100 — ménages accessibles pondérés par la pression concurrentielle.') : '',
      selRows: x ? [
        { k: 'Score d\'opportunité', v: x.score + ' / 100' },
        { k: x.zone ? 'Ménages dans la zone' : 'Ménages dans le rayon', v: fmtInt(x.hh) },
        { k: 'Population communale', v: fmtInt(x.cmPop) + (x.cmEst ? ' (estimée)' : '') },
        { k: 'dont zone primaire', v: fmtInt(x.prim) },
        { k: 'Marché boulangerie', v: fmtEur(x.market) },
        { k: 'Dépense / ménage', v: fmtEur(s.spend) },
        { k: x.zone ? 'Boulangeries dans la zone' : 'Boulangeries dans le rayon', v: fmtInt(x.near.length) + (x.blocked.length ? ' (dont ' + x.blocked.length + ' fortes)' : '') },
        { k: x.zone ? 'Chaînes dans la zone' : 'Chaînes dans le rayon', v: (() => {
          const ch = x.near.filter(o => self.estChaine(o.b));
          if (!ch.length) return 'aucune';
          const m = self.marquesDe(ch.map(o => o.b));
          return ch.length + ' — ' + m.slice(0, 3).map(k => k.nom + (k.n > 1 ? ' ×' + k.n : '')).join(', ')
            + (m.length > 3 ? ', +' + (m.length - 3) : '');
        })() },
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
            + (self.estChaine(o.b) ? ' · chaîne ' + self.marqueDe(o.b) : '')
        };
      }) : [],
      addCandidate: () => self.addCandidate(),
      exportCsv: () => self.exportCsv(),
      nCandidates: s.candidates.length,
      noCandidates: s.candidates.length === 0,
      candidates: s.candidates.map(c => ({
        name: c.commune, meta: fmtEur(c.ca) + ' · ' + fmtInt(c.hh) + ' ménages · score ' + c.score + (c.zone ? ' · zone dessinée' : ''),
        focus: () => { if (self.map) self.map.setView([c.lat, c.lng], 12); self.candEval(c); },
        dossier: () => { if (self.map) self.map.setView([c.lat, c.lng], 12); self.candEval(c); self.ouvrirDossier(c); },
        remove: () => self.removeCandidate(c)
      })),
      ouvrirDossier: () => self.ouvrirDossier(null),
      ouvrirPlan: () => self.ouvrirPlan(),
      plan: s.plan ? Object.assign(self.planDonnees(), {
        img: s.planImg, busy: s.planBusy,
        nChoix: [1, 2, 3, 5].map(n => ({ value: String(n), label: n + ' par province' })), nVal: String(Math.max(1, Math.min(5, s.planN || 5))),
        setN: e => self.setPlan({ planN: parseInt(e.target.value, 10) || 5 }),
        seuilOn: !!s.planSeuil, toggleSeuil: () => self.setPlan({ planSeuil: !s.planSeuil }),
        ecartChoix: [[0, 'sans écart minimum'], [10, '10 min entre elles'], [15, '15 min entre elles'], [20, '20 min entre elles'], [30, '30 min entre elles']].map(c => ({ value: String(c[0]), label: c[1] })),
        ecartVal: String(Math.max(0, +s.planEcart || 0)), setEcart: e => self.setPlan({ planEcart: parseInt(e.target.value, 10) || 0 }),
        reseauOn: !!s.planReseau, toggleReseau: () => self.setPlan({ planReseau: !s.planReseau }),
        routeOn: s.planRoute !== false, toggleRoute: () => self.setPlan({ planRoute: s.planRoute === false }),
        fermer: () => self.fermerPlan(), pdf: () => self.telechargerPlanPdf(), csv: () => self.exporterPlanCsv(), imprimer: () => self.imprimerPlan()
      }) : null,
      dossier: s.dossier && x ? Object.assign(self.dossierDonnees(), {
        img: s.dossierImg, busy: s.dossierBusy,
        fermer: () => self.fermerDossier(),
        pdf: () => self.telechargerPdf(),
        csv: () => self.exporterDossierCsv(),
        imprimer: () => self.imprimerDossier()
      }) : null,
      histTitle: hist.title, hist: hist.bars,

      /* ----- modale réseau ----- */
      reseau: s.reseau,
      openReseau: () => self.setState({ reseau: true }),
      closeReseau: () => self.setState({ reseau: false }),
      reseauCols: RESEAU.map(r => ({
        nom: r.nom, statut: r.statut, rows: reseauRows(r), perso: false,
        locate: () => { self.setState({ reseau: false, compare: false, view: 'map' }); setTimeout(() => { if (self.map) self.map.setView([r.lat, r.lng], 13); self.evaluate(r.lat, r.lng); }, 80); },
        applyDepense: () => self.setParam({ spend: r.depense })
      })).concat(s.references.map(r => ({
        nom: r.nom, statut: r.statut || 'point de comparaison', rows: reseauRows(r), perso: true,
        locate: r.lat != null && r.lng != null ? () => { self.setState({ reseau: false, compare: false, view: 'map' }); setTimeout(() => { if (self.map) self.map.setView([+r.lat, +r.lng], 13); self.evaluate(+r.lat, +r.lng); }, 80); } : null,
        applyDepense: r.depense ? () => self.setParam({ spend: +r.depense }) : null,
        modifier: () => self.refOuvrir(r),
        supprimer: () => self.refSupprimer(r.id)
      }))),
      refForm: s.refForm ? {
        champs: [['nom', 'Nom', 'text'], ['statut', 'Statut', 'text'], ['lat', 'Latitude', 'number'], ['lng', 'Longitude', 'number'],
          ['pop', 'Population de la zone', 'number'], ['hh', 'Ménages', 'number'], ['taille', 'Taille des ménages', 'number'], ['revenu', 'Revenu moyen / ménage (€)', 'number'],
          ['jeunes', 'Part de jeunes (%)', 'number'], ['actifs', 'Part d\'actifs (%)', 'number'], ['seniors', 'Part de seniors (%)', 'number'],
          ['depense', 'Dépense boulangerie / ménage (€/an)', 'number'], ['marche', 'Marché boulangerie (€) — vide = ménages × dépense', 'number'],
          ['emprise', 'Emprise retenue (%)', 'number'], ['ca', 'CA annuel TTC (€)', 'number'], ['surface', 'Surface nette (m²)', 'number']]
          .map(([k, label, type]) => ({ k: k, label: label, type: type, v: s.refForm[k] == null ? '' : s.refForm[k], set: e => self.refChamp(k, e.target.value) })),
        neuf: !s.refForm.id, depuisZone: !!s.refForm.depuisZone,
        enregistrer: () => self.refEnregistrer(), annuler: () => self.refFermer()
      } : null,
      refOuvrir: () => self.refOuvrir(null),
      refDepuisZone: x ? () => self.refOuvrir({
        depuisZone: true, nom: x.commune + ' — zone évaluée', statut: 'zone évaluée le ' + new Date().toLocaleDateString('fr-BE'),
        lat: +x.lat.toFixed(5), lng: +x.lng.toFixed(5), pop: Math.round(x.hh * (s.hhSize || HH_SIZE)), hh: Math.round(x.hh), taille: s.hhSize || HH_SIZE,
        depense: s.spend, marche: Math.round(x.market), emprise: +(x.emprise * 100).toFixed(1), ca: Math.round(x.ca), surface: s.surface
      }) : null,
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
      views: [['map', 'Carte'], ['zones', 'Zones candidates'], ['concurrents', 'Concurrents'], ['arrondissements', 'Arrondissements'], ['top5', 'Top 5 par province']]
        .map(([k, label]) => ({
          label: label,
          color: s.view === k ? 'var(--color-primary)' : 'var(--color-text-muted)',
          border: s.view === k ? 'var(--color-primary)' : 'transparent',
          go: () => { self._scroll['sc-table'] = 0; self.setState({ view: k, compare: false }); }
        })),
      isZones: s.view === 'zones',
      isConc: s.view === 'concurrents',
      isArr: s.view === 'arrondissements',
      isTop5: s.view === 'top5',
      q: s.q, setQ: e => self.setState({ q: e.target.value }),
      zonesRows: zonesRows,
      zonesEmpty: s.busy ? 'Chargement en cours…' : !this.map ? 'Carte indisponible.' : 'Aucune zone ne passe le score minimum ' + s.minScore + ' dans la vue courante — dézoome la carte ou abaisse le seuil.',
      zonesCols: [['rang', 'Rang'], ['commune', 'Localité'], ['arr', 'Arrondissement'], ['score', 'Score'], ['hh', 'Ménages'], ['n', 'Concurrents'], ['forts', 'Forts'], ['chaines', 'Chaînes'], ['emprise', 'Emprise'], ['ca', 'CA estimé'], ['m2', '€/m²']]
        .map(([k, label]) => ({ label: label, sort: sortBy(k), tip: TIP_ZONES[k] || '' })),
      concRows: concRows,
      concCount: concCount,
      arrRows: arrRows,
      top5: s.view === 'top5' ? self.scanTop5().map(g => ({
        prov: g.prov, detail: fmtInt(g.communes) + ' communes · ' + fmtInt(g.shops) + ' commerces dans la sélection',
        rows: g.zones.map((p, i) => { const cc = self.concurrenceAu(p.lat, p.lng, s.radius); return { rang: i + 1, commune: p.commune, arr: p.arr, score: p.score, hh: fmtInt(p.hh), n: p.n,
          forts: cc.forts, chaines: cc.chainesTxt || (cc.chaines ? String(cc.chaines) : '—'), noms: cc.noms,
          emprise: (p.emprise * 100).toFixed(1) + ' %', ca: fmtEur(p.ca), m2: fmtEur(p.ca / s.surface), open: () => goMap(p.lat, p.lng, 13, true) }; })
      })) : [],
      top5Cols: [['rang', 'Rang'], ['commune', 'Commune'], ['arr', 'Arrondissement'], ['score', 'Score'], ['hh', 'Ménages'], ['n', 'Concurrents'], ['forts', 'Forts'], ['chaines', 'Chaînes'], ['emprise', 'Emprise'], ['ca', 'CA estimé'], ['m2', '€/m²']]
        .map(([k, label]) => ({ label: label, tip: TIP_ZONES[k] || '' })),
      top5Empty: s.busy ? 'Chargement en cours…' : 'Aucune commune dans la sélection — coche au moins une province.',
      exportTop5: () => {
        const g = self.scanTop5();
        self.csv('ceo_top5_provinces', ['province', 'rang', 'commune', 'arrondissement', 'score', 'menages', 'concurrents', 'concurrents_forts', 'chaines', 'concurrents_noms', 'emprise_pct', 'ca_annuel_ttc', 'ca_par_m2', 'lat', 'lng',
          'hyp_rayon_km', 'hyp_depense_menage', 'hyp_emprise_max_pct', 'hyp_sensibilite', 'hyp_passage_pct', 'hyp_surface_m2'],
          [].concat.apply([], g.map(x => x.zones.map((p, i) => { const cc = self.concurrenceAu(p.lat, p.lng, s.radius); return [x.prov, i + 1, p.commune, p.arr, p.score, Math.round(p.hh), p.n, cc.forts, cc.chainesTxt, String(cc.noms).replace(/\n/g, ' / '), (p.emprise * 100).toFixed(1), Math.round(p.ca), Math.round(p.ca / s.surface),
            p.lat.toFixed(5), p.lng.toFixed(5), s.radius.toFixed(1), s.spend, s.empriseMax, s.compK, s.passage, s.surface]; }))));
      },
      arrCols: [['arr', 'Arrondissement'], ['communes', 'Communes'], ['pop', 'Population'], ['hh', 'Ménages'], ['market', 'Marché'], ['shops', 'Commerces'], ['strong', 'Forts'], ['dens', '/ 10.000 hab.'], ['avg', 'Note moy.'], ['perShop', 'Ménages / point']]
        .map(([k, label]) => ({ label: label, sort: sortBy(k), tip: TIP_ARR[k] || '' })),
      exportZones: () => self.csv('ceo_zones', ['rang', 'localite', 'arrondissement', 'score', 'menages', 'concurrents', 'concurrents_forts', 'chaines', 'concurrents_noms', 'emprise_pct', 'ca_annuel_ttc', 'ca_par_m2', 'lat', 'lng',
        'hyp_depense_menage', 'hyp_passage_pct', 'hyp_surface_m2', 'hyp_emprise_max_pct', 'hyp_sensibilite', 'hyp_taille_menages', 'hyp_rayon_km'],
        zonesRows.map(r => [r.rang, r.commune, r.arr, r.score, r.hhRaw, r.n, r.forts, r.chainesN ? r.chaines : '', String(r.noms || '').replace(/\n/g, ' / '), r.empriseRaw, r.caRaw, r.m2Raw, r.lat.toFixed(5), r.lng.toFixed(5),
          s.spend, s.passage, s.surface, s.empriseMax, s.compK, s.hhSize, s.radius])),
      exportConc: () => self.csv('ceo_concurrents', ['nom', 'commune', 'arrondissement', 'province', 'note', 'avis', 'source_note', 'force_pct', 'concurrent_fort', 'commentaire', 'adresse', 'lat', 'lng'],
        concRows.map(r => [r.name, r.commune, r.arr, r.prov, r.note, r.avis, r.src, r.force, r.strong ? 'oui' : 'non', String(r.comment || '').replace(/[\r\n]+/g, ' '), r.addr, r.lat.toFixed(5), r.lng.toFixed(5)])),
      exportArr: () => self.csv('ceo_arrondissements', ['arrondissement', 'communes', 'population', 'menages', 'marche_boulangerie', 'commerces', 'concurrents_forts', 'densite_10000_hab', 'note_moyenne', 'menages_par_commerce'],
        arrRows.map(r => [r.arr, r.communes, r.popRaw, r.hhRaw, r.marketRaw, r.shops, r.strong, r.dens, r.avgRaw, r.perShopRaw]))
    });
  }
}
