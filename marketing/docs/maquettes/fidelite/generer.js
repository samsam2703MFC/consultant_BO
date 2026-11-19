/* Quatre maquettes du module « Fidélité » (cockpit, rail ERP franchisé › Fidélité,
 * adresse /fidelity), chacune en deux onglets :
 *   Programme — les points, les cartes à tampons, les bons et cadeaux, les
 *               déclencheurs, les créneaux où les points comptent double ;
 *   Clients   — la récurrence des clients et leur type d'achat.
 *
 *   A — Tout sur une page : tuiles, puis une carte par brique, empilées.
 *   B — Le parcours client : les déclencheurs posés sur les étapes du client ;
 *       côté clients, la matrice récence × fréquence.
 *   C — Les règles en phrases, l'aperçu de l'app du client à côté ; côté
 *       clients, le type d'achat heure par heure et la liste des clients.
 *   D — Le calendrier d'abord : la semaine heure par heure et les trois mois
 *       à venir ; côté clients, le rythme (intervalle entre deux visites,
 *       rétention, retour spontané).
 *
 * RÉEL : les quatre magasins (Corbais, Gosselies, Halle, Sombreffe), le CA de
 * Halle en septembre (50 850 €), le modèle de la PWA (pwa_loyalty_card :
 * cartes à tampons, seuil, récompense ; pwa_loyalty_config : euros_per_point,
 * voucher_points, voucher_value ; « les ×2 ne jouent que sur les points »),
 * les bons de l'ERP (voucher_campaign, motif FIDELITE). ILLUSTRÉ : tous les
 * chiffres clients — le programme n'a pas encore de données lisibles d'ici.
 *
 *   python3 -m http.server 8099   (depuis la racine du dépôt)
 *   node docs/maquettes/fidelite/generer.js
 */
const fs = require('fs');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const OUT = __dirname + '/';
const BASE = 'http://127.0.0.1:8099/docs/maquettes/fidelite/';
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 });
const fE = n => nf(Math.round(n)) + ' €';
const fU = n => nf(n, 2) + ' €';
const fP = n => nf(Math.round(n)) + ' %';
const fP1 = n => nf(n, 1) + ' %';
const sum = a => a.reduce((x, v) => x + v, 0);

/* --- Les chiffres (septembre 2026, illustrés sauf mention) ------------------------------------- */
const MAG = [
  { nom: 'Corbais', ca: 142000, actifs: 1420, part: 39, freq: 2.6, ret30: 46, util: 69, cout: 1.8 },
  { nom: 'Gosselies', ca: 61000, actifs: 760, part: 36, freq: 2.5, ret30: 43, util: 66, cout: 1.7 },
  { nom: 'Halle', ca: 50850, actifs: 640, part: 35, freq: 2.4, ret30: 44, util: 64, cout: 1.6 },   // CA réel
  { nom: 'Sombreffe', ca: 38000, actifs: 360, part: 33, freq: 2.3, ret30: 39, util: 61, cout: 1.5 },
];
const CA = sum(MAG.map(m => m.ca)), CAID = sum(MAG.map(m => m.ca * m.part / 100));
const ACTIFS = 3180, INSCRITS = 6940, TICKETS = 24300, TK_ID = 8020;
const PAN_M = CAID / TK_ID, PAN_NM = (CA - CAID) / (TICKETS - TK_ID);
const CFG = { epp: 1, vp: 150, vv: 5 };                       // 1 € = 1 point · 150 points = bon de 5 €
const PTS = { base: Math.round(CAID), x2: 14620, decl: 9630, circ: 286400 };
const CIRC_E = PTS.circ / CFG.vp * CFG.vv;
// Bons et cadeaux du mois : émis, utilisés, expirés, en attente, valeur unitaire (prix de vente pour les produits).
const BL = [
  ['bon', '🎟 Bons de 5 €', 'jauge 150 points', 750, 548, 61, 141, 5],
  ['bon', '🎟 Bienvenue 2 €', 'inscription', 640, 410, 72, 158, 2],
  ['bon', '🎟 On vous attend 3 €', '45 jours sans visite', 98, 27, 19, 52, 3],
  ['bon', '🎟 Filleul 2 €', 'parrainage', 22, 15, 0, 7, 2],
];
const PROD = ['cad', '🎁 Produits offerts', 'cartes à tampons, anniversaire, 10e visite', 731, 630, 0, 597, null, 1332];
const BONS = { n: sum(BL.map(b => b[3])), u: sum(BL.map(b => b[4])), att: sum(BL.map(b => b[6])), bonsE: sum(BL.map(b => b[4] * b[7])), prodE: PROD[8] };
const COUT = BONS.bonsE + BONS.prodE;
const H = [7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19];
const JOURS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
// Tickets par heure, réseau, moyenne de septembre. 0 = fermé.
const TR = [
  [38, 62, 58, 40, 34, 52, 46, 24, 20, 22, 30, 34, 22],
  [44, 70, 64, 44, 38, 60, 52, 26, 22, 24, 34, 40, 26],
  [46, 72, 66, 48, 42, 62, 54, 34, 32, 36, 40, 42, 28],
  [44, 70, 62, 44, 38, 60, 52, 26, 22, 24, 34, 40, 26],
  [48, 76, 70, 50, 44, 66, 58, 32, 30, 32, 42, 48, 32],
  [52, 96, 118, 110, 90, 72, 58, 48, 46, 44, 40, 30, 0],
  [40, 98, 124, 112, 84, 46, 0, 0, 0, 0, 0, 0, 0],
];
const estX2 = (j, h) => j >= 1 && j <= 3 && h >= 14 && h < 17;          // mardi → jeudi 14–17 h
const estPain = (j, h) => h >= 17 && TR[j][H.indexOf(h)] > 0;            // pain après 17:30 (Halle, Gosselies)

const X2 = [
  { k: '×2', quand: 'Mardi → jeudi, 14:00 – 17:00', pourquoi: 'jours creux : remplir l’après-midi', portee: 'Réseau', depuis: '22/09', effet: '+11 % de tickets membres sur le créneau', eff: 11, ref: 2, jours: [0, 1, 1, 1, 0, 0, 0], on: true },
  { k: '×2', quand: 'Chaque jour après 17:30, sur le pain', pourquoi: 'écouler : moins de pain jeté', portee: 'Halle · Gosselies', depuis: '01/10', effet: 'trop tôt : 3 semaines lues', eff: null, jours: [1, 1, 1, 1, 1, 1, 0], on: true },
  { k: '×2', quand: 'Lundi, toute la journée', pourquoi: 'le jour le plus creux de Sombreffe', portee: 'Sombreffe', depuis: '06/10', effet: '+6 % de tickets membres le lundi', eff: 6, ref: 1, jours: [1, 0, 0, 0, 0, 0, 0], on: true },
  { k: '×2', quand: 'Gamme Automnale, 01/10 → 15/11', pourquoi: 'lancer une gamme', portee: 'Réseau', depuis: '01/10', effet: '31 % des pièces Automnale vont à des membres', eff: null, jours: [1, 1, 1, 1, 1, 1, 1], on: true },
  { k: '×3', quand: 'Le jour de son anniversaire, 3 jours avant ou après', pourquoi: 'un cadeau personnel', portee: 'Réseau', depuis: '01/03', effet: '96 anniversaires en septembre', eff: null, jours: [1, 1, 1, 1, 1, 1, 1], on: true },
  { k: '×2', quand: 'Samedi, 7:00 – 8:00', pourquoi: 'brouillon : ouvrir plus tôt ?', portee: 'Corbais', depuis: '—', effet: 'pas encore activée', eff: null, jours: [0, 0, 0, 0, 0, 1, 0], on: false },
];
const DECL = [
  { ev: 'Inscription à la carte', phrase: 'Quand un client s’inscrit', rec: ['bon', 'Bon de bienvenue 2 €'], cond: 'dès 8 € d’achat', val: 30, n: 640, u: 410, seg: 'Nouveaux' },
  { ev: '2e visite dans les 14 jours', phrase: 'Quand il revient dans les 14 jours après sa 1re visite', rec: ['pts', '+30 points'], val: 0, n: 141, u: 141, seg: 'Nouveaux' },
  { ev: 'Jauge pleine : 150 points', phrase: 'Quand sa jauge atteint 150 points', rec: ['bon', 'Bon de 5 €'], cond: 'automatique', val: 60, n: 750, u: 548, seg: 'Tous' },
  { ev: 'Anniversaire', phrase: 'Le jour de son anniversaire', rec: ['cad', 'Une viennoiserie offerte'], cond: '+ points ×3', val: 7, n: 96, u: 71, seg: 'Tous' },
  { ev: '10e visite du mois', phrase: 'À sa 10e visite du mois', rec: ['cad', 'Une tartelette surprise'], val: 14, n: 38, u: 33, seg: 'Habitués' },
  { ev: 'Panier de 30 € et plus', phrase: 'Quand son panier atteint 30 €', rec: ['pts', '+50 points'], val: 0, n: 64, u: 64, seg: 'Occasionnels' },
  { ev: 'Parrainage d’un ami', phrase: 'Quand il parraine un ami', rec: ['pts', '+100 points'], cond: 'et le filleul, un bon de 2 € valable 30 jours', val: 0, n: 22, u: 15, seg: 'Tous' },
  { ev: 'Absent depuis 45 jours, était régulier', phrase: 'Quand un régulier n’est plus venu depuis 45 jours', rec: ['bon', 'Bon « on vous attend » 3 €'], val: 21, n: 98, u: 27, seg: 'À risque', alerte: true },
];
const CARTES = [
  { nom: 'Carte Pain', unite: 'pains achetés', req: 10, rec: '1 pain offert', tint: '#fbf3e8', fill: '#9a5b16', acc: '#6b3d0a', cl: 1240, tp: 6830, pr: 184, ut: 160, demo: 7 },
  { nom: 'Carte Viennoiserie', unite: 'viennoiseries achetées', req: 10, rec: '1 croissant offert', tint: '#fdf0e6', fill: '#eb6834', acc: '#9a3c12', cl: 1610, tp: 9120, pr: 260, ut: 230, demo: 4 },
  { nom: 'Carte Café', unite: 'cafés achetés', req: 8, rec: '1 café offert', tint: '#f3eee9', fill: '#5c4033', acc: '#3e2a20', cl: 880, tp: 3410, pr: 122, ut: 110, demo: 5 },
  { nom: 'Carte Tarte', unite: 'parts de tarte achetées', req: 6, rec: '1 part de tarte offerte', tint: '#fbeef3', fill: '#c2336a', acc: '#8a1f4a', cl: 420, tp: 1020, pr: 31, ut: 26, demo: 2 },
];
const FREQ = [['1 visite', 1210, 10], ['2 – 3', 1020, 21], ['4 – 7', 590, 27], ['8 – 15', 270, 25], ['16 et +', 90, 17]];
const COH = [['mars', 1420, [52, 44, 40, 37, 35, 34]], ['avril', 860, [48, 41, 37, 35, 33]], ['mai', 720, [47, 40, 36, 34]], ['juin', 640, [45, 38, 35]], ['juillet', 510, [41, 35]], ['août', 470, [46]], ['septembre', 560, []]];
const MIS = [
  { k: 1, nom: 'Pain quotidien', def: 'pain seul ou + 1 article', tk: 31, pan: 5.6, heures: '7–10 h · 17–19 h', freq: 6.1, ret: 61, carte: 'Carte Pain', prof: [9, 12, 8, 4, 3, 4, 3, 2, 2, 3, 9, 13, 8] },
  { k: 2, nom: 'Petit-déjeuner', def: 'viennoiserie + boisson chaude', tk: 22, pan: 7.4, heures: '7–10 h', freq: 4.2, ret: 48, carte: 'Carte Viennoiserie · Café', prof: [16, 24, 18, 9, 3, 1, 0, 0, 0, 0, 0, 0, 0] },
  { k: 3, nom: 'Lunch', def: 'sandwich, quiche, salade + boisson', tk: 18, pan: 10.4, heures: '11:30 – 13:30', freq: 3.1, ret: 39, carte: '— (à créer : Carte Lunch)', prof: [0, 0, 1, 4, 16, 30, 22, 5, 1, 0, 0, 0, 0] },
  { k: 4, nom: 'Goûter', def: 'pâtisserie individuelle, cookies', tk: 14, pan: 9.1, heures: '14–17 h · mercredi', freq: 1.9, ret: 31, carte: '×2 mardi → jeudi 14–17 h', prof: [0, 0, 1, 2, 2, 3, 5, 12, 16, 18, 13, 6, 2] },
  { k: 5, nom: 'Occasion', def: 'tarte entière, entremets, ≥ 25 €', tk: 9, pan: 34.9, heures: 'samedi et dimanche matin', freq: 0.6, ret: 27, carte: 'Carte Tarte · panier ≥ 30 €', prof: [2, 8, 16, 18, 12, 6, 4, 4, 5, 6, 8, 6, 3] },
  { k: 6, nom: 'Commande & bureau', def: 'click & collect, livraison au bureau', tk: 6, pan: 62.0, heures: 'commandé la veille', freq: 1.1, ret: 52, carte: '— (points webshop)', prof: [3, 10, 14, 10, 12, 18, 8, 4, 3, 4, 6, 5, 3] },
];
const caMis = MIS.map(m => m.tk * m.pan); const caMisT = sum(caMis); MIS.forEach((m, i) => { m.ca = 100 * caMis[i] / caMisT; });
const SEG = [
  { nom: 'Habitués', def: '8 visites et plus par mois, vu il y a moins de 7 jours', n: 360, ca: 31, pan: 11.2, freq: 11.4, mix: [52, 26, 10, 5, 2, 5], decl: '10e visite → tartelette', act: 'les garder : cadeau surprise, jamais de remise' },
  { nom: 'Réguliers', def: '2 à 7 visites par mois', n: 1610, ca: 46, pan: 13.9, freq: 3.6, mix: [30, 24, 22, 13, 5, 6], decl: 'jauge 150 points → bon 5 €', act: 'faire passer une 2e mission (lunch, goûter)' },
  { nom: 'Occasionnels', def: '1 visite par mois ou moins, vu dans les 60 jours', n: 1210, ca: 15, pan: 18.4, freq: 0.9, mix: [12, 14, 16, 22, 28, 8], decl: 'panier ≥ 30 € → +50 points', act: 'carte Tarte, rappels avant les fêtes' },
  { nom: 'Nouveaux', def: '1re visite il y a moins de 30 jours', n: 560, ca: 5, pan: 12.1, freq: 1.6, mix: [18, 26, 24, 16, 10, 6], decl: 'bienvenue 2 € · 2e visite +30 points', act: 'la 2e visite dans les 14 jours' },
  { nom: 'À risque', def: 'régulier, plus vu depuis 21 à 45 jours', n: 470, ca: 3, pan: 13.1, freq: 0.4, mix: [22, 18, 30, 16, 8, 6], decl: 'bon retour 3 € à 45 jours', act: 'avancer le bon retour à 21 jours', alerte: true },
  { nom: 'Endormis', def: 'plus vu depuis plus de 60 jours', n: 2730, ca: 0, pan: null, freq: 0, mix: null, decl: 'newsletter « fidélité »', act: 'une relance par trimestre, pas plus' },
];
// Récence × fréquence (clients avec 30 jours d'historique et plus ; les nouveaux à part).
const RF_L = ['8 et +', '4 – 7', '2 – 3', '1', 'moins d’1'], RF_C = ['< 7 j', '7 – 20 j', '21 – 45 j', '46 – 60 j', '> 60 j'];
const RF = [[360, 40, 20, 5, 5], [400, 250, 140, 20, 30], [370, 550, 310, 60, 150], [140, 320, 380, 110, 620], [30, 100, 240, 100, 1630]];
const rfSeg = (l, c) => c >= 3 ? 'E' : (l === 0 && c === 0 ? 'H' : (l <= 2 && c === 2 ? 'AR' : (l <= 2 ? 'R' : 'O')));
// Retour spontané d'un régulier selon les jours sans visite (sans bon).
const SURV = [[0, 100], [7, 92], [14, 78], [21, 52], [30, 38], [45, 21], [60, 12], [90, 6]];
// Jours entre deux visites, membres actifs (part des intervalles, %).
const INTER = Array.from({ length: 45 }, (_, i) => { const d = i + 1; return +(4.6 * Math.exp(-d / 9) + (d === 1 ? 6.5 : 0) + (d === 7 ? 3.6 : 0) + (d === 14 ? 1.4 : 0) + (d === 6 || d === 8 ? 0.9 : 0) + (d === 21 ? 0.5 : 0) + 0.15).toFixed(2); });
const interT = sum(INTER); INTER.forEach((v, i) => { INTER[i] = 100 * v / interT; });
const CLI = [
  ['Martine L.', 'Corbais', 'mars', [3, 4, 3, 4, 4, 3, 4, 4, 3, 4, 4, 4], 6.2, [1, 2], 'Habitués', 128, 'bon 5 € · 12/11', '10e visite jeudi → tartelette'],
  ['Jean-Luc V.', 'Halle', 'mars', [2, 3, 3, 2, 3, 3, 2, 3, 3, 3, 2, 3], 9.8, [2, 3], 'Habitués', 41, '—', 'jauge dans 109 points'],
  ['Sophie D.', 'Gosselies', 'avril', [1, 1, 2, 1, 1, 2, 1, 1, 1, 2, 1, 1], 14.2, [3, 4], 'Réguliers', 142, '—', 'bon 5 € dans 8 points'],
  ['Karim B.', 'Corbais', 'mai', [1, 2, 1, 1, 2, 1, 1, 0, 1, 1, 2, 1], 11.6, [1, 3], 'Réguliers', 96, 'café offert', '—'],
  ['Nathalie P.', 'Sombreffe', 'mars', [1, 1, 1, 0, 1, 1, 0, 1, 1, 0, 1, 1], 21.4, [5, 2], 'Réguliers', 63, '—', 'anniversaire le 28/10 → viennoiserie'],
  ['Thomas G.', 'Halle', 'juin', [2, 2, 1, 2, 1, 1, 0, 0, 0, 0, 0, 0], 12.9, [3], 'À risque', 74, '—', 'bon retour : 34 jours sans visite', true],
  ['Isabelle M.', 'Corbais', 'mars', [1, 0, 1, 0, 0, 1, 0, 0, 1, 0, 0, 1], 38.5, [5], 'Occasionnels', 118, '—', 'panier ≥ 30 € → +50 points'],
  ['Bureau Ethias', 'Gosselies', 'juillet', [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1], 71.0, [6], 'Réguliers', 47, 'bon 5 € · 03/11', 'jauge dans 103 points'],
  ['Lucas F.', 'Gosselies', 'sept.', [0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 0, 1], 8.4, [2, 4], 'Nouveaux', 52, 'bienvenue 2 €', '2e visite faite · +30 points'],
  ['Amélie R.', 'Corbais', 'avril', [2, 1, 2, 2, 1, 1, 1, 0, 0, 0, 0, 0], 10.1, [1, 4], 'À risque', 22, 'pain offert', 'bon retour : 38 jours sans visite', true],
];
const SEGCOL = { 'Habitués': 'ok', 'Réguliers': 'r', 'Occasionnels': 'm', 'Nouveaux': 'pts', 'À risque': 'ko', 'Endormis': '' };

/* --- Petites pièces ------------------------------------------------------------------------------ */
const chip = (cls, t) => `<span class="chip ${cls}">${t}</span>`;
const recChip = r => chip(r[0], (r[0] === 'bon' ? '🎟 ' : r[0] === 'cad' ? '🎁 ' : '★ ') + esc(r[1]));
const misPt = k => `<span class="pt" style="background:var(--m${k})"></span>`;
const misChip = k => `<span class="chip" style="background:color-mix(in srgb,var(--m${k}) 13%,#fff);color:color-mix(in srgb,var(--m${k}) 75%,#000)">${esc(MIS[k - 1].nom)}</span>`;
const tuile = (k, v, s, cls) => `<div class="tuile ${cls || ''}"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s}</div></div>`;
const carte = (t, mini, corps, dr, pied) => `<div class="carte"><div class="ch"><span class="t">${t}</span>${mini ? `<span class="mini">${mini}</span>` : ''}${dr ? `<span class="dr">${dr}</span>` : ''}</div>${corps}${pied ? `<div class="pied">${pied}</div>` : ''}</div>`;
const tog = on => `<span class="tog${on ? ' on' : ''}"></span>`;
const inp = v => `<span class="in">${v}</span>`;
// Rampe séquentielle unique (rubis) : clair → foncé.
const seq = t => { t = Math.max(0, Math.min(1, t)); const a = [251, 244, 240], b = [141, 29, 44]; return `rgb(${a.map((v, i) => Math.round(v + (b[i] - v) * Math.pow(t, .85))).join(',')})`; };
const seqTxt = t => t > .52 ? '#fff' : 'var(--color-text)';
const sable = t => { const a = [246, 241, 234], b = [176, 136, 92]; return `rgb(${a.map((v, i) => Math.round(v + (b[i] - v) * t)).join(',')})`; };
const spark = (v, w, h, col) => { const m = Math.max(...v, 1), bw = w / v.length; return `<svg width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" style="vertical-align:middle">${v.map((x, i) => `<rect x="${(i * bw + 1).toFixed(1)}" y="${(h - Math.max(1.5, h * x / m)).toFixed(1)}" width="${(bw - 2).toFixed(1)}" height="${Math.max(1.5, h * x / m).toFixed(1)}" rx="1.5" fill="${x ? col : '#e3ddd5'}"/>`).join('')}</svg>`; };
const semaine = j => `<span style="display:inline-flex;gap:2px;vertical-align:middle">${j.map((v, i) => `<i title="${JOURS[i]}" style="width:13px;height:13px;border-radius:3px;background:${v ? 'var(--x2)' : '#ece6dd'};color:${v ? '#fff' : '#9b948b'};font:700 7.5px/13px var(--font-ui);text-align:center;font-style:normal">${JOURS[i][0]}</i>`).join('')}</span>`;

/* La grille semaine × heure du trafic, les créneaux ×2 cerclés. */
function grille(big) {
  const mx = Math.max(...TR.flat());
  let h = `<div class="hm${big ? ' big' : ''}"><span></span>${H.map(x => `<span class="h">${x}h</span>`).join('')}`;
  TR.forEach((row, j) => {
    h += `<span class="j">${JOURS[j]}</span>`;
    row.forEach((v, i) => {
      if (!v) { h += `<span class="c ferme" title="fermé">·</span>`; return; }
      const t = v / mx, cls = (estX2(j, H[i]) ? ' x2' : '') + (estPain(j, H[i]) ? ' pain' : '');
      h += `<span class="c${cls}" title="${JOURS[j]} ${H[i]}h : ${v} tickets/h" style="background-color:${sable(t)};color:${t > .6 ? '#fff' : 'var(--color-text)'}">${v}</span>`;
    });
  });
  return h + '</div>';
}
const legGrille = `<div class="leg" style="margin-top:9px"><span><span class="pt" style="background:${sable(.15)}"></span><span class="pt" style="background:${sable(.5)};margin-left:-4px"></span><span class="pt" style="background:${sable(.95)};margin-left:-4px"></span>tickets par heure, réseau, moyenne de septembre</span><span><span class="pt" style="box-shadow:inset 0 0 0 2px var(--x2)"></span>points ×2 (mardi → jeudi 14–17 h)</span><span><span class="pt" style="background-image:repeating-linear-gradient(135deg,transparent 0 2px,rgba(141,29,44,.45) 2px 3px)"></span>×2 sur le pain après 17:30 (Halle, Gosselies)</span><span><span class="pt" style="background:#ece6dd"></span>fermé</span></div>`;

function cohortes() {
  return `<table class="coh"><thead><tr><th class="g">1re visite</th>${[1, 2, 3, 4, 5, 6].map(m => `<th>M+${m}</th>`).join('')}</tr></thead><tbody>${COH.map(([m, n, r]) => `<tr><td class="g">${m}<small>${nf(n)}</small></td>${[0, 1, 2, 3, 4, 5].map(i => r[i] == null ? '<td class="vide"></td>' : `<td title="${m} : ${r[i]} % encore actifs à M+${i + 1}" style="background:${seq((r[i] - 20) / 40)};color:${seqTxt((r[i] - 20) / 40)}">${r[i]} %</td>`).join('')}</tr>`).join('')}</tbody></table>`;
}

/* Barres appariées : part des clients et part du CA identifié, par fréquence de visite. */
function freqBarres() {
  const W = 520, Hh = 190, x0 = 30, y0 = 160, bw = 34, gap = (W - x0 - 10) / FREQ.length;
  let s = `<svg viewBox="0 0 ${W} ${Hh}" width="100%">`;
  [0, 10, 20, 30, 40].forEach(v => { const y = y0 - v * 3.4; s += `<line class="gr" x1="${x0}" x2="${W}" y1="${y}" y2="${y}"/><text class="ax" x="${x0 - 6}" y="${y + 3}" text-anchor="end">${v}</text>`; });
  FREQ.forEach(([l, n, ca], i) => {
    const pc = 100 * n / ACTIFS, cx = x0 + 20 + i * gap;
    const h1 = pc * 3.4, h2 = ca * 3.4;
    s += `<path d="M${cx},${y0} v${-(h1 - 4)} q0,-4 4,-4 h${bw - 8} q4,0 4,4 v${h1 - 4} z" fill="#c9bfb3"><title>${l} : ${fP(pc)} des clients</title></path>`;
    s += `<path d="M${cx + bw + 2},${y0} v${-(h2 - 4)} q0,-4 4,-4 h${bw - 8} q4,0 4,4 v${h2 - 4} z" fill="var(--color-primary)"><title>${l} : ${ca} % du CA</title></path>`;
    s += `<text class="lb" x="${cx + bw / 2}" y="${y0 - h1 - 5}" text-anchor="middle">${fP(pc)}</text><text class="lb" x="${cx + bw * 1.5 + 2}" y="${y0 - h2 - 5}" text-anchor="middle" style="fill:var(--color-primary)">${ca} %</text>`;
    s += `<text class="ax" x="${cx + bw + 1}" y="${y0 + 15}" text-anchor="middle" style="font-weight:600">${l}</text><text class="ax" x="${cx + bw + 1}" y="${y0 + 27}" text-anchor="middle">${nf(n)} clients</text>`;
  });
  return s + '</svg>';
}
const legFreq = `<div class="leg"><span><span class="pt" style="background:#c9bfb3"></span>part des clients actifs</span><span><span class="pt" style="background:var(--color-primary)"></span>part du CA identifié</span><span>visites par client en septembre</span></div>`;

function tabMissions(court) {
  return `<table class="tb"><thead><tr><th>Type d’achat</th><th>Part des tickets</th><th class="n">Panier</th><th class="n">Part du CA</th>${court ? '' : '<th>Quand</th>'}<th class="n">Visites / mois</th><th class="n">Encore là à M+3</th>${court ? '' : '<th>Ce qui le fidélise</th>'}</tr></thead><tbody>
  ${MIS.map(m => `<tr><td class="nom">${misPt(m.k)}${esc(m.nom)}<small style="padding-left:15px">${esc(m.def)}</small></td><td><div class="hb" style="width:150px"><div class="tr"><i style="width:${m.tk * 3}%;background:var(--m${m.k})"></i></div><span class="num b" style="width:32px">${m.tk} %</span></div></td><td class="n">${fU(m.pan)}</td><td class="n b">${fP(m.ca)}</td>${court ? '' : `<td class="mu s11">${esc(m.heures)}</td>`}<td class="n">${nf(m.freq, 1)}</td><td class="n ${m.ret >= 45 ? 'ok' : m.ret < 32 ? 'ko' : ''}">${m.ret} %</td>${court ? '' : `<td class="s11">${esc(m.carte)}</td>`}</tr>`).join('')}
  </tbody></table>`;
}
function tabSegments() {
  return `<table class="tb"><thead><tr><th>Segment</th><th class="n">Clients</th><th class="n">Part du CA</th><th class="n">Panier</th><th class="n">Visites / mois</th><th>Ce qu’ils achètent</th><th>Déclencheur lié</th><th>À faire</th></tr></thead><tbody>
  ${SEG.map(s => `<tr${s.n > 2000 ? ' class="off"' : ''}><td class="nom">${chip(SEGCOL[s.nom], esc(s.nom))}<small>${esc(s.def)}</small></td><td class="n b">${nf(s.n)}</td><td class="n">${s.ca ? s.ca + ' %' : '—'}${s.alerte ? `<small class="d ko">était 9 % en juillet</small>` : ''}</td><td class="n">${s.pan ? fU(s.pan) : '—'}</td><td class="n">${s.freq ? nf(s.freq, 1) : '—'}</td><td>${s.mix ? mixBarre(s.mix, 150) : '<span class="mu">—</span>'}</td><td class="s11">${esc(s.decl)}</td><td class="s11 ${s.alerte ? 'ko b' : ''}">${esc(s.act)}</td></tr>`).join('')}
  </tbody></table>`;
}
const mixBarre = (mix, w) => `<div style="display:flex;gap:2px;width:${w}px;height:10px">${mix.map((v, i) => v ? `<i title="${MIS[i].nom} ${v} %" style="flex:${v};background:var(--m${i + 1});border-radius:${i === 0 ? '3px 0 0 3px' : i === mix.length - 1 ? '0 3px 3px 0' : '0'}"></i>` : '').join('')}</div>`;
const legMis = `<div class="leg">${MIS.map(m => `<span>${misPt(m.k)}${esc(m.nom)}</span>`).join('')}</div>`;

function tabDecl(compact) {
  return `<table class="tb"><thead><tr><th>Quand</th><th>Le client reçoit</th>${compact ? '' : '<th class="n">Valable</th>'}<th class="n">Sept. : déclenchés → utilisés</th><th></th></tr></thead><tbody>
  ${DECL.map(d => `<tr><td class="nom">${esc(d.ev)}${d.cond ? `<small>${esc(d.cond)}</small>` : ''}</td><td>${recChip(d.rec)}</td>${compact ? '' : `<td class="n mu">${d.val ? d.val + ' j' : '—'}</td>`}<td class="n">${nf(d.n)} → <b class="${d.alerte ? 'ko' : ''}">${d.rec[0] === 'pts' ? 'crédités' : nf(d.u) + ' · ' + fP(100 * d.u / d.n)}</b>${d.alerte ? '<small class="d ko">trop tard : à 21 jours, 52 % reviennent encore seuls</small>' : ''}</td><td class="c">${tog(true)}</td></tr>`).join('')}
  </tbody></table>`;
}
function stampsHTML(cols) {
  return `<div class="stamps"${cols ? ` style="grid-template-columns:repeat(${cols},minmax(0,1fr))"` : ''}>${CARTES.map(c => `<div class="stamp" style="--tint:${c.tint};--fill:${c.fill};--acc:${c.acc}"><div class="n">${esc(c.nom)}</div><div class="r">${c.req} achetés = ${esc(c.rec)}</div><div class="dots">${Array.from({ length: c.req }, (_, i) => `<i class="${i < c.demo ? 'f' : ''}"></i>`).join('')}<i class="g">🎁</i></div><div class="st"><span><b>${nf(c.cl)}</b> clients</span><span><b>${nf(c.tp)}</b> tampons en cours</span><span><b>${nf(c.pr)}</b> prêtes · ${nf(c.ut)} prises</span></div></div>`).join('')}</div>`;
}
function tabMag() {
  return `<table class="tb"><thead><tr><th>Magasin</th><th class="n">CA du mois</th><th class="n">Membres actifs</th><th>Part du CA identifiée</th><th class="n">Visites / mois</th><th class="n">Retour à 30 j</th><th class="n">Bons utilisés</th><th class="n">Coût</th><th>Règles locales</th></tr></thead><tbody>
  ${MAG.map(m => `<tr><td class="nom">${m.nom}</td><td class="n">${fE(m.ca)}</td><td class="n b">${nf(m.actifs)}</td><td><div class="hb" style="width:160px"><div class="tr"><i style="width:${m.part * 2}%;background:var(--color-primary)"></i></div><span class="num b">${m.part} %</span></div></td><td class="n">${nf(m.freq, 1)}</td><td class="n ${m.ret30 < 42 ? 'att' : ''}">${m.ret30} %</td><td class="n">${m.util} %</td><td class="n">${fP1(m.cout)} du CA</td><td class="s11">${m.nom === 'Halle' || m.nom === 'Gosselies' ? chip('x2', '×2') + ' pain après 17:30' : m.nom === 'Sombreffe' ? chip('x2', '×2') + ' lundi' : m.nom === 'Corbais' ? chip('', 'brouillon') + ' ×2 samedi 7–8 h' : ''}</td></tr>`).join('')}
  <tr class="tot"><td>Réseau</td><td class="n">${fE(CA)}</td><td class="n">${nf(ACTIFS)}</td><td>${fP1(100 * CAID / CA)}</td><td class="n">2,5</td><td class="n">44 %</td><td class="n">${fP(100 * BONS.u / BONS.n)}</td><td class="n">${fP1(100 * COUT / CA)} du CA</td><td></td></tr></tbody></table>`;
}

/* --- La coque : le rail du cockpit, l'en-tête, les onglets ------------------------------------- */
function rail() {
  const sec = (t, items) => `<div><div class="sec">${t}</div>${items.map(i => typeof i === 'string' ? `<div class="it">${i}</div>` : i).join('')}</div>`;
  return `<aside class="rail"><div class="lg"><img src="/public/assets/img/logo.png" alt=""><div>Pilotage Réseau</div></div><div class="q">Rechercher partout…</div><nav>
    ${sec('Pilotage', ['Vue générale', 'Performance'])}
    ${sec('Magasins', ['Analyse magasin', 'Budget', 'Jours creux'])}
    ${sec('Marque &amp; marketing', ['Campagnes', 'Offres et canaux'])}
    ${sec('ERP franchisé', ['Développement commercial', 'Prospection', 'Tablette vendeuses', 'Newsletter', 'Newsletter magasin', '<div class="it on">Fidélité <span class="nv">nouveau</span></div>', '<div class="rep">Gestion de production ▸</div>'])}
    ${sec('Administration', ['Paramètres', 'Journal &amp; diagnostic'])}
  </nav></aside>`;
}
function page(id, dir, titreDir, onglet, corps, opts) {
  opts = opts || {};
  const onglets = `<div class="ongl"><span class="${onglet === 'p' ? 'on' : ''}">Programme <i>${X2.filter(x => x.on).length + DECL.length + CARTES.length} règles</i></span><span class="${onglet === 'c' ? 'on' : ''}">Clients · récurrence et type d’achat <i>${nf(ACTIFS)} actifs</i></span></div>`;
  const portee = `<span class="seg"><span class="on">Réseau</span>${MAG.map(m => `<span>${m.nom}</span>`).join('')}</span>`;
  return `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Fidélité — maquette ${dir}</title>
<link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="fi.css"></head><body><div class="app">${rail()}
<main id="mq"><div class="hd"><div><div class="fil">ERP franchisé › Fidélité <code>/fidelity</code></div><h1>Fidélité</h1><p class="sous">${opts.sous || 'Les points, les cartes à tampons, les bons et les cadeaux du client — ce qui les déclenche, quand les points comptent double — et ce que les clients en font.'}</p></div>
<div class="dr"><span class="mq">Maquette ${dir} · ${esc(titreDir)}</span><span class="lim">⚠ chiffres clients illustrés</span>${opts.btn || ''}</div></div>
<div class="barre">${onglets}<span class="esp"></span>${portee}<span class="ctl">Septembre 2026 ▾</span></div>
${corps}</main></div></body></html>`;
}

/* ================================================================================================
 * A — Tout sur une page
 * ============================================================================================== */
function aProgramme() {
  const tuiles = `<div class="tuiles">
    ${tuile('Membres actifs', nf(ACTIFS), `achat ce mois · ${nf(INSCRITS)} inscrits (${fP(100 * ACTIFS / INSCRITS)})`)}
    ${tuile('CA identifié', fP1(100 * CAID / CA), `${fE(CAID)} passés avec la carte, sur ${fE(CA)}`, 'fort')}
    ${tuile('Panier membre', fU(PAN_M), `contre ${fU(PAN_NM)} sans carte (+${fP(100 * (PAN_M / PAN_NM - 1))})`)}
    ${tuile('Points en circulation', nf(PTS.circ), `= ${fE(CIRC_E)} de bons si tout est converti`)}
    ${tuile('Bons utilisés', fP(100 * BONS.u / BONS.n), `${nf(BONS.u)} sur ${nf(BONS.n)} émis ce mois · ${nf(BONS.att)} en attente`)}
    ${tuile('Coût du programme', fP1(100 * COUT / CA) + ' <small>du CA</small>', `${fE(COUT)} · ${fP1(100 * COUT / CAID)} du CA identifié`)}
  </div>`;
  // Les soldes de points : combien de clients près du bon.
  const soldes = [[0, 25, 820], [25, 50, 610], [50, 75, 520], [75, 100, 430], [100, 125, 388], [125, 150, 412]];
  const mxS = Math.max(...soldes.map(s => s[2]));
  const jauge = `<div class="cb"><div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;font-size:13.5px;margin:2px 0 12px">
      <span>Chaque ${inp('1 €')} = ${inp('1 point')}</span><span class="mu">→</span><span>${inp('150 points')} = un bon de ${inp('5 €')} valable ${inp('60 jours')}</span><span class="chip pts">rend ${fP1(100 * CFG.vv / CFG.vp)} du CA · ${fP1(100 * (CFG.vv / CFG.vp) * (1 + PTS.x2 / PTS.base))} avec les ×2</span></div>
    <div class="cap mu s11 b" style="text-transform:uppercase;letter-spacing:.06em;font-size:10px">Où en sont les soldes — membres actifs</div>
    <div style="display:flex;gap:6px;align-items:flex-end;height:96px;margin-top:8px">${soldes.map(([a, b, n], i) => `<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:3px"><span class="s11 b">${nf(n)}</span><i title="${a}–${b} points : ${n} clients" style="display:block;width:100%;height:${70 * n / mxS}px;border-radius:4px 4px 0 0;background:${i === 5 ? 'var(--color-primary)' : seq(.15 + i * .1)}"></i><span class="s11 mu">${a}–${b}</span></div>`).join('')}</div>
    <div class="lecture"><b>412 clients</b> sont à moins de 25 points de leur bon : un ×2 ciblé sur eux remplit un creux et déclenche le bon dans la semaine.</div></div>`;
  const ligneB = r => `<tr><td class="nom">${chip(r[0], r[1])}<small>${esc(r[2])}${r[5] ? ` · ${r[5]} expirés` : ''}</small></td><td class="n">${nf(r[3])}</td><td class="n b${r[4] / r[3] < .4 ? ' ko' : ''}">${nf(r[4])}</td><td class="n">${nf(r[6])}</td><td class="n">${fE(r[8] != null ? r[8] : r[4] * r[7])}</td></tr>`;
  const bons = `<table class="tb"><thead><tr><th>Bons et cadeaux</th><th class="n">Émis</th><th class="n">Utilisés</th><th class="n">En attente</th><th class="n">Coût</th></tr></thead><tbody>
    ${BL.map(ligneB).join('')}${ligneB(PROD)}
    <tr class="tot"><td>Total</td><td class="n">${nf(BONS.n + PROD[3])}</td><td class="n">${nf(BONS.u + PROD[4])}</td><td class="n">${nf(BONS.att + PROD[6])}</td><td class="n">${fE(COUT)}</td></tr></tbody></table>`;
  const x2 = `<div class="cb" style="display:grid;grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);gap:18px">
    <div>${grille()}${legGrille}</div>
    <div>${X2.map(x => `<div style="display:flex;gap:9px;align-items:flex-start;padding:7px 0;border-bottom:.5px dashed var(--color-border-tertiary);${x.on ? '' : 'opacity:.55'}">${chip(x.k === '×3' ? 'x3' : 'x2', x.k)}<div style="flex:1;min-width:0"><div class="b" style="font-size:12.5px">${esc(x.quand)}</div><div class="s11 mu">${esc(x.pourquoi)} · ${esc(x.portee)}</div><div class="s11 ${x.eff ? 'ok b' : 'mu'}">${esc(x.effet)}</div></div>${tog(x.on)}</div>`).join('')}
    <div style="margin-top:9px"><span class="btn">+ Un créneau ×2</span></div></div></div>`;
  return page('a-programme', 'A', 'Tout sur une page', 'p', `<div class="col">${tuiles}
    <div class="g21">${carte('La règle de base et la jauge', 'pwa_loyalty_config : euros_per_point, voucher_points, voucher_value', jauge, '<span class="btn">Modifier</span>')}
    ${carte('Bons et cadeaux — septembre', 'bons ERP motif FIDELITE + récompenses PWA', bons)}</div>
    ${carte('Quand les points comptent double', 'les créneaux posés sur le trafic du réseau : les ×2 vont aux heures creuses, pas aux heures pleines', x2, null, 'Les multiplicateurs ne se cumulent pas (le plus fort l’emporte) et ne jouent que sur les points, jamais sur les tampons. <b>Effet</b> = tickets membres sur le créneau, face aux 4 semaines d’avant, comme les promotions jours creux.')}
    ${carte('Cartes à tampons', 'un tampon par article acheté de la famille · les ×2 ne jouent pas sur les tampons', `<div class="cb">${stampsHTML(4)}</div>`, '<span class="s11 mu">brouillon : Carte Lunch — 8 formules = 1 boisson</span><span class="btn">+ Une carte</span>')}
    ${carte('Déclencheurs', 'un événement → un bon, un cadeau ou des points, envoyé dans l’app du client', tabDecl(false), '<span class="btn">+ Un déclencheur</span>')}
    ${carte('Par magasin', 'la même règle partout ; un magasin peut ajouter ses créneaux ×2', tabMag())}</div>`);
}
function aClients() {
  const tuiles = `<div class="tuiles">
    ${tuile('Clients actifs', nf(ACTIFS), 'au moins une visite avec la carte en septembre')}
    ${tuile('Visites par mois', '2,5', 'par client actif · 2,3 en juin')}
    ${tuile('Intervalle médian', '8 <small>jours</small>', 'entre deux visites d’un même client')}
    ${tuile('Retour à 30 jours', '44 %', 'des nouveaux d’août sont revenus')}
    ${tuile('CA par client actif', fE(CAID / ACTIFS) + ' <small>/ mois</small>', `panier ${fU(PAN_M)}`)}
    ${tuile('Le cœur', '11 % → 42 %', '360 clients à 8 visites et plus font 42 % du CA identifié', 'fort')}
  </div>`;
  return page('a-clients', 'A', 'Tout sur une page', 'c', `<div class="col">${tuiles}
    <div class="g2">${carte('Combien de fois ils viennent', 'septembre, clients actifs', `<div class="cb">${freqBarres()}${legFreq}<div class="lecture"><b>11 % des clients</b> (8 visites et plus) font <b>42 % du CA</b> identifié ; 38 % ne passent qu’une fois.</div></div>`)}
    ${carte('Combien reviennent', 'part des clients encore actifs, mois après leur 1re visite', `<div class="cb">${cohortes()}<div class="lecture">Un tiers des clients de mars sont encore là six mois après. La cohorte de juillet décroche (vacances) ; celle d’août repart à <b>46 %</b>.</div></div>`)}</div>
    ${carte('Les segments', 'récence × fréquence · un client change de segment chaque mois', tabSegments(), '<span class="btn">Exporter vers la newsletter</span>')}
    ${carte('Le type d’achat', 'chaque ticket rangé dans une mission d’après son contenu et son heure', `<div class="cb" style="padding-top:0">${tabMissions(false)}</div>`, null, '<b>Occasion</b> et <b>Commande &amp; bureau</b> : 15 % des tickets, 52 % du CA identifié. <b>Lunch</b> retient mal (39 % à M+3) et n’a aucune carte : c’est la carte à créer. Un client à <b>3 missions et plus</b> dépense 3,4 fois plus qu’un client à une seule.')}</div>`);
}

/* ================================================================================================
 * B — Le parcours client
 * ============================================================================================== */
function bProgramme() {
  const et = [
    { t: 'Inscrit', n: INSCRITS, s: '640 ce mois · app ou caisse', d: [DECL[0]] },
    { t: '1re visite', n: 560, s: '88 % des inscrits du mois', d: [DECL[1]] },
    { t: '2e visite', n: 246, s: '44 % reviennent sous 30 j', d: [DECL[2]] },
    { t: 'Régulier', n: 1610, s: '2 à 7 visites par mois', d: [DECL[4], DECL[5]] },
    { t: 'Habitué', n: 360, s: '8 visites et plus par mois', d: [DECL[3]] },
  ];
  const fl = ['88 %', '44 %', '61 %', '18 %'];
  const col = (e, i) => `<div style="display:flex;flex-direction:column;gap:8px;min-width:0">
    <div style="background:${seq(.12 + i * .17)};color:${seqTxt(.12 + i * .17)};border-radius:11px;padding:11px 13px"><div style="font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;opacity:.85">${i + 1} · ${e.t}</div><div style="font-size:26px;font-weight:500;line-height:1.1;margin-top:2px">${nf(e.n)}</div><div style="font-size:11px;opacity:.85">${e.s}</div></div>
    ${e.d.map(d => `<div style="border:1px dashed var(--color-border-secondary);border-radius:10px;padding:8px 10px;background:#fff"><div class="s11 mu">quand : ${esc(d.ev)}</div><div style="margin:4px 0">${recChip(d.rec)}</div><div class="s11">${nf(d.n)} → ${d.rec[0] === 'pts' ? 'crédités' : `<b>${fP(100 * d.u / d.n)}</b> utilisés`}</div></div>`).join('')}</div>`;
  const fleche = f => `<div style="display:flex;flex-direction:column;align-items:center;padding-top:22px;color:var(--color-text-muted)"><span style="font-size:18px;line-height:1">→</span><span style="font-size:11px;font-weight:700;color:var(--color-text);white-space:nowrap">${f}</span></div>`;
  const parcours = `<div class="cb"><div style="display:grid;grid-template-columns:minmax(0,1fr) 44px minmax(0,1fr) 44px minmax(0,1fr) 44px minmax(0,1fr) 44px minmax(0,1fr);align-items:start">${et.map((e, i) => col(e, i) + (i < 4 ? fleche(fl[i]) : '')).join('')}</div>
    <div style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:26px;margin-top:12px">
      <div style="grid-column:1 / span 2;font-size:11.5px;color:var(--color-text-muted);padding-top:10px;line-height:1.5">À toutes les étapes : ${recChip(DECL[6].rec)} quand il parraine un ami, et les points ${chip('x2', '×2')} sur les créneaux creux (ci-dessous).</div>
      <div style="grid-column:3 / span 3;background:#fdf3f4;border:1px solid #efc7cd;border-radius:11px;padding:10px 13px;display:grid;grid-template-columns:1fr auto 1fr auto 1fr;gap:10px;align-items:center">
        <div><div style="font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase" class="ko">↓ À risque</div><div style="font-size:22px;font-weight:500">470</div><div class="s11 mu">régulier, absent 21 à 45 j</div></div>
        <span class="mu b">→</span>
        <div><div class="s11 mu">à 45 jours</div><div style="margin:3px 0">${recChip(DECL[7].rec)}</div><div class="s11"><b class="ko">28 %</b> utilisés · trop tard</div></div>
        <span class="mu b">→</span>
        <div><div style="font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase" class="mu">Endormi</div><div style="font-size:22px;font-weight:500">2 730</div><div class="s11 mu">plus de 60 j · 91 revenus ce mois</div></div>
      </div></div></div>`;
  const gagner = `<div class="cb"><div style="font-size:13.5px;margin-bottom:10px">Chaque ${inp('1 €')} = ${inp('1 point')} <span class="mu s11">· comptoir, click &amp; collect, livraison</span></div>
    ${X2.map(x => `<div style="display:grid;grid-template-columns:34px 1fr auto auto;gap:9px;align-items:center;padding:6px 0;border-top:.5px dashed var(--color-border-tertiary);${x.on ? '' : 'opacity:.55'}">${chip(x.k === '×3' ? 'x3' : 'x2', x.k)}<div><div class="b" style="font-size:12px">${esc(x.quand)}</div><div class="s11 mu">${esc(x.portee)} · ${esc(x.effet)}</div></div>${semaine(x.jours)}${tog(x.on)}</div>`).join('')}</div>`;
  const utiliser = `<div class="cb"><div style="font-size:13.5px;margin-bottom:8px">${inp('150 points')} = bon de ${inp('5 €')} · ${inp('60 jours')}</div>
    <div style="height:12px;border-radius:6px;background:var(--color-background-secondary);display:flex;overflow:hidden;gap:2px;margin-bottom:4px">${[820, 610, 520, 430, 388, 412].map((n, i) => `<i style="flex:${n};background:${seq(.12 + i * .14)}"></i>`).join('')}</div>
    <div class="s11 mu" style="margin-bottom:10px">les soldes des 3 180 actifs, de 0 à 150 points · <b style="color:var(--color-primary)">412</b> à moins de 25 points du bon</div>
    ${CARTES.map(c => `<div style="display:grid;grid-template-columns:12px 1fr 110px 74px;gap:9px;align-items:center;padding:6px 0;border-top:.5px dashed var(--color-border-tertiary)"><i style="width:12px;height:12px;border-radius:50%;background:${c.fill}"></i><div><div class="b" style="font-size:12px">${esc(c.nom)}</div><div class="s11 mu">${c.req} = ${esc(c.rec)} · ${nf(c.cl)} clients</div></div><div class="hb"><div class="tr"><i style="width:${100 * c.tp / c.cl / c.req}%;background:${c.fill}"></i></div></div><span class="s11 num"><b>${c.pr}</b> prêtes</span></div>`).join('')}</div>`;
  const bilan = `<div class="cb" style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px">
    ${[['CA identifié', fE(CAID), `${fP1(100 * CAID / CA)} du CA`], ['Bons utilisés', '− ' + fE(BONS.bonsE), nf(BONS.u) + ' bons'], ['Produits offerts', '− ' + fE(BONS.prodE), 'prix de vente · 630 articles'], ['Coût du programme', fE(COUT), `${fP1(100 * COUT / CAID)} du CA identifié`], ['Ramené par les déclencheurs', '+ ' + fE(27 * CAID / ACTIFS + 141 * 13.4), '27 retours + 141 deuxièmes visites']].map(([k, v, s], i) => `<div style="padding:10px 12px;border-radius:10px;background:${i === 3 ? '#fdf4f5' : i === 4 ? '#eef6f0' : 'var(--color-background-secondary)'}"><div class="s11 mu" style="text-transform:uppercase;letter-spacing:.06em;font-size:10px;font-weight:600">${k}</div><div style="font-size:19px;font-weight:500;margin-top:3px;color:${i === 3 ? 'var(--color-primary)' : i === 4 ? 'var(--ok)' : 'inherit'}">${v}</div><div class="s11 mu">${s}</div></div>`).join('')}</div>`;
  return page('b-programme', 'B', 'Le parcours client', 'p', `<div class="col">
    ${carte('Le parcours du client et ce qui le pousse à l’étape suivante', 'septembre · chaque déclencheur est posé sur l’étape qu’il vise', parcours, '<span class="btn">+ Un déclencheur</span>', '<b>Lecture</b> : 44 % des nouveaux reviennent dans les 30 jours ; le bon « on vous attend » part à 45 jours, quand un régulier n’a plus que 21 % de chances de revenir seul — à 21 jours, il en a encore 52 %.')}
    <div class="g2">${carte('Gagner des points', 'la base, et les créneaux où ils comptent double', gagner, '<span class="btn">+ Un créneau</span>')}
    ${carte('Les dépenser', 'la jauge des points, les cartes à tampons', utiliser, '<span class="btn">+ Une carte</span>')}</div>
    ${carte('Ce que ça coûte, ce que ça ramène', 'septembre, réseau', bilan)}</div>`);
}
const ORDRE = ['Habitués', 'Réguliers', 'Occasionnels', 'À risque', 'Endormis'];
const TRANS = [[290, 52, 4, 6, 0], [58, 1210, 120, 172, 20], [2, 160, 760, 30, 238], [0, 96, 40, 92, 227], [0, 14, 50, 0, 2456]];
const MONTES = sum(TRANS.map((r, i) => sum(r.filter((v, j) => j < i)))), DESC = sum(TRANS.map((r, i) => sum(r.filter((v, j) => j > i))));
const REVENUS = sum(TRANS[3].slice(0, 3)) + sum(TRANS[4].slice(0, 3));
function bClients() {
  const ordre = ORDRE;
  const mx = 1630, segNom = { H: 'Habitués', R: 'Réguliers', O: 'Occasionnels', AR: 'À risque', E: 'Endormis' };
  const bord = { H: '#2d7a3e', R: '#1f5f8b', O: '#b26a00', AR: '#C0182B', E: '#9b948b' };
  const grilleRF = `<div style="display:grid;grid-template-columns:86px repeat(5,minmax(0,1fr));gap:4px;font-size:11px">
    <span></span>${RF_C.map(c => `<span class="mu b" style="text-align:center;font-size:10px">${c}</span>`).join('')}
    ${RF.map((r, l) => `<span class="mu b" style="display:flex;align-items:center;font-size:10.5px">${RF_L[l]}</span>${r.map((v, c) => { const s = rfSeg(l, c), t = Math.sqrt(v / mx); return `<span title="${segNom[s]} : ${v} clients" style="height:52px;border-radius:7px;background:${seq(t)};color:${seqTxt(t)};display:flex;flex-direction:column;align-items:center;justify-content:center;box-shadow:inset 0 0 0 2px ${s === 'AR' ? bord.AR : 'transparent'};font-weight:600;font-size:13px">${nf(v)}<small style="font-size:9px;font-weight:600;opacity:.8">${l === 0 && c === 0 || (s === 'AR' && l === 1) || (s === 'R' && l === 2 && c === 1) || (s === 'O' && l === 3 && c === 1) || (s === 'E' && l === 4 && c === 4) ? segNom[s] : ''}</small></span>`; }).join('')}`).join('')}
    </div><div style="display:flex;justify-content:space-between;margin:6px 0 0 90px" class="s11 mu"><span>← vu récemment</span><span>dernière visite</span><span>plus vu depuis longtemps →</span></div>`;
  const ar = SEG[4];
  const detail = `<div class="cb"><div style="display:flex;align-items:baseline;gap:8px">${chip('ko', 'À risque')}<span style="font-size:24px;font-weight:500">470</span><span class="mu s11">clients · 31 jours sans visite en moyenne</span></div>
    <div class="s11 mu" style="margin:8px 0 4px;text-transform:uppercase;letter-spacing:.06em;font-weight:600;font-size:10px">Ce qu’ils achetaient</div>${mixBarre(ar.mix, 330)}
    <div class="leg" style="margin-top:5px">${ar.mix.map((v, i) => v >= 16 ? `<span>${misPt(i + 1)}${MIS[i].nom} ${v} %</span>` : '').join('')}</div>
    <table class="tb" style="margin-top:8px"><tbody>
      <tr><td class="mu">CA qu’ils faisaient</td><td class="n b">4 280 € / mois</td></tr>
      <tr><td class="mu">Panier · visites</td><td class="n">${fU(ar.pan)} · 2,8 / mois avant</td></tr>
      <tr><td class="mu">Magasin le plus touché</td><td class="n">Corbais · 212</td></tr>
      <tr><td class="mu">Déclencheur aujourd’hui</td><td class="n">${recChip(DECL[7].rec)} à 45 j</td></tr></tbody></table>
    <div class="lecture">Le lunch pèse 30 % de leurs achats contre 18 % en moyenne : ce sont surtout des <b>clients du midi</b> qui ont décroché. Avancer le bon à <b>21 jours</b> et le réserver au lunch.</div>
    <div style="display:flex;gap:6px;margin-top:10px"><span class="btn p">Avancer à 21 jours</span><span class="btn">Envoyer la liste à la newsletter</span></div></div>`;

  const trans = `<div class="cb"><table class="coh"><thead><tr><th class="g">En août ↓ · en septembre →</th>${ordre.map(o => `<th>${o}</th>`).join('')}</tr></thead><tbody>${TRANS.map((r, i) => { const t = sum(r); return `<tr><td class="g">${ordre[i]}<small>${nf(t)}</small></td>${r.map((v, j) => { const p = 100 * v / t; return `<td title="${ordre[i]} → ${ordre[j]} : ${v}" style="background:${i === j ? '#efe9e1' : j < i ? `rgba(45,122,62,${Math.min(.85, p / 30)})` : `rgba(192,24,43,${Math.min(.85, p / 30)})`};color:${p > 18 && i !== j ? '#fff' : 'var(--color-text)'}">${p < 0.5 ? '·' : fP(p)}</td>`; }).join('')}</tr>`; }).join('')}</tbody></table>
    <div class="leg" style="margin-top:8px"><span><span class="pt" style="background:#efe9e1"></span>restés</span><span><span class="pt" style="background:rgba(45,122,62,.7)"></span>montés</span><span><span class="pt" style="background:rgba(192,24,43,.7)"></span>descendus</span></div>
    <div class="lecture">172 réguliers sont passés « à risque » en septembre (11 %) ; ${nf(REVENUS)} clients à risque ou endormis sont revenus, dont <b>27 grâce au bon</b>.</div></div>`;
  const mixSeg = `<div class="cb">${SEG.filter(s => s.mix).map(s => `<div style="display:grid;grid-template-columns:96px 1fr 58px;gap:10px;align-items:center;padding:6px 0"><span class="b s11">${s.nom}</span>${mixBarre(s.mix, '100%')}<span class="s11 num mu">${fU(s.pan)}</span></div>`).join('')}${legMis}
    <div class="lecture">Les habitués viennent pour le <b>pain</b> (52 %) ; les occasionnels pour l’<b>occasion</b> (28 %) et le goûter — deux programmes différents dans une même carte.</div></div>`;
  return page('b-clients', 'B', 'Le parcours client', 'c', `<div class="col">
    <div class="tuiles">${tuile('Clients actifs', nf(ACTIFS), 'visite avec la carte en septembre')}${tuile('Retour à 30 jours', '44 %', 'des nouveaux d’août')}${tuile('Montés', '+ ' + nf(MONTES), 'clients passés à un segment plus fidèle')}${tuile('Descendus', '− ' + nf(DESC), 'dont 172 réguliers devenus à risque', '')}${tuile('À risque', '470', '4 280 € de CA mensuel en jeu', 'fort')}</div>
    ${carte('Récence × fréquence', 'chaque case = des clients ; en ligne leurs visites par mois (moyenne 90 j), en colonne leur dernière visite · les 560 nouveaux sont lus à part', `<div class="cb" style="display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);gap:20px"><div>${grilleRF}</div><div style="border-left:.5px solid var(--color-border-tertiary);margin:-4px 0 -14px;padding-left:4px">${detail}</div></div>`)}
    <div class="g2">${carte('D’un mois à l’autre', 'qui monte, qui descend', trans)}${carte('Ce qu’achète chaque segment', 'part des tickets par type d’achat · panier moyen', mixSeg)}</div></div>`);
}

/* ================================================================================================
 * C — Les règles en phrases + l'aperçu de l'app
 * ============================================================================================== */
function cProgramme() {
  const regle = (txt, stat, opts) => { opts = opts || {}; return `<div style="display:grid;grid-template-columns:1fr 170px auto;gap:12px;align-items:center;padding:9px 16px;border-top:.5px dashed var(--color-border-tertiary);${opts.off ? 'opacity:.5' : ''}"><div style="font-size:13px;line-height:1.9">${txt}</div><div class="s11 num ${opts.cls || 'mu'}" style="max-width:190px;white-space:normal;text-align:right">${stat}</div>${tog(!opts.off)}</div>`; };
  const portee = p => chip(p === 'Réseau' ? 'r' : 'm', p);
  const bloc = (n, t, sous, corps, btn) => `<div class="carte"><div class="ch"><span style="width:22px;height:22px;border-radius:50%;background:var(--color-primary);color:#fff;font:700 11px/22px var(--font-ui);text-align:center">${n}</span><span class="t">${t}</span><span class="mini">${sous}</span><span class="dr">${btn ? `<span class="btn">${btn}</span>` : ''}</span></div>${corps}</div>`;
  const gagne = regle(`Chaque ${inp('1 €')} dépensé rapporte ${inp('1 point')}, en boutique, en click &amp; collect et en livraison. ${portee('Réseau')}`, `${nf(PTS.base)} points ce mois`)
    + regle(`Un panier de ${inp('30 €')} et plus rapporte ${inp('+50 points')}. ${portee('Réseau')}`, '64 paniers · 3 200 points');
  const double = X2.map(x => regle(`${x.k === '×3' ? 'Les points comptent ' + chip('x3', '×3') : 'Les points comptent ' + chip('x2', '×2')} ${esc(x.quand).replace(/^./, c => c.toLowerCase())} ${semaine(x.jours)} ${portee(x.portee === 'Réseau' ? 'Réseau' : x.portee)}`, x.effet, { off: !x.on, cls: x.eff ? 'ok b' : 'mu' })).join('');
  const recoit = regle(`Tous les ${inp('150 points')}, le client reçoit un ${chip('bon', '🎟 bon de 5 €')} valable ${inp('60 jours')}`, '750 bons · 73 % utilisés')
    + CARTES.map(c => regle(`<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${c.fill};vertical-align:0;margin-right:4px"></span>${esc(c.nom)} : ${inp(c.req)} ${esc(c.unite)} = ${chip('cad', '🎁 ' + esc(c.rec))}`, `${nf(c.cl)} clients · ${c.pr} prêtes`)).join('')
    + DECL.filter((d, i) => i !== 2).map(d => regle(`${esc(d.phrase).replace(/(\d+) (jours|points|€)/, (m, n, u) => inp(n + ' ' + u))}, il reçoit ${recChip(d.rec)}${d.val ? ` valable ${inp(d.val + ' jours')}` : ''}${d.cond ? ` <span class="mu s11">(${esc(d.cond)})</span>` : ''}`, d.rec[0] === 'pts' ? `${d.n} fois ce mois` : `${d.n} → ${fP(100 * d.u / d.n)} utilisés`, { cls: d.alerte ? 'ko b' : 'mu' })).join('');
  const garde = regle(`Pas plus de ${inp('300 points')} bonus par jour et par client.`, '2 clients plafonnés')
    + regle(`Les multiplicateurs ne se cumulent pas : ${inp('le plus fort')} l’emporte.`, '')
    + regle(`${inp('Un bon')} par ticket ; bon fidélité et promotion jours creux ${inp('ne se cumulent pas')}.`, '')
    + regle(`Les points s’effacent après ${inp('12 mois')} sans visite ; le client est prévenu ${inp('30 jours')} avant.`, '41 200 points en sursis')
    + regle(`Aucun ×2 les ${inp('24/12, 31/12')} et les veilles de fête (déjà pleins).`, '');
  const phone = `<div style="width:262px;margin:2px auto 0;border-radius:34px;background:#1d1b19;padding:10px;box-shadow:0 10px 28px rgba(0,0,0,.18)"><div style="border-radius:26px;background:#faf6f1;overflow:hidden;height:470px;position:relative">
    <div style="background:var(--color-primary);color:#fff;padding:20px 16px 16px"><div style="font-size:10px;letter-spacing:.08em;text-transform:uppercase;opacity:.8">Bonjour Martine</div><div style="font-family:var(--font-display);font-size:22px;margin-top:2px">128 <small style="font:400 12px var(--font-ui);opacity:.85">/ 150 points</small></div>
      <div style="height:8px;border-radius:4px;background:rgba(255,255,255,.25);margin-top:8px;overflow:hidden"><i style="display:block;width:85%;height:100%;background:var(--color-secondary)"></i></div><div style="font-size:10.5px;margin-top:6px;opacity:.9">Encore 22 points pour votre bon de 5 €</div>
      <div style="margin-top:9px;font-size:10.5px;background:rgba(255,255,255,.14);border-radius:8px;padding:6px 8px">✦ Aujourd’hui 14:00–17:00 : vos points comptent <b>double</b></div></div>
    <div style="padding:12px 14px"><div style="font-size:9.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#777">Mes cartes</div>
      ${CARTES.slice(0, 3).map(c => `<div style="margin-top:7px;background:${c.tint};border-radius:9px;padding:7px 9px"><div style="font-size:11px;font-weight:700;color:${c.acc}">${c.nom}</div><div style="display:flex;gap:3px;margin-top:4px">${Array.from({ length: c.req }, (_, i) => `<i style="width:11px;height:11px;border-radius:50%;border:1.5px solid ${c.fill};background:${i < c.demo ? c.fill : '#fff'}"></i>`).join('')}</div></div>`).join('')}
      <div style="font-size:9.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#777;margin-top:12px">Mon portefeuille</div>
      <div style="margin-top:6px;display:flex;gap:6px"><div style="flex:1;border:1.5px dashed #d9a441;border-radius:9px;padding:7px;background:#fff6e5"><div style="font-size:15px;font-weight:700;color:#7a4a12">5 €</div><div style="font-size:9.5px;color:#7a4a12">jusqu’au 12/11</div></div><div style="flex:1;border:1.5px dashed #8e7fd0;border-radius:9px;padding:7px;background:#f3eefb"><div style="font-size:12px;font-weight:700;color:#4b3a8c">🎁 Café</div><div style="font-size:9.5px;color:#4b3a8c">carte complète</div></div></div></div></div></div>
    <div class="s11 mu" style="text-align:center;margin-top:8px">l’app du client (PWA) telle que les règles la montrent</div>`;
  const PROF = [['Martine — pain tous les jours', 'Habituée · 26 visites · 5,90 €', 26 * 5.9, 26 * 5.9 * .14], ['Karim — lunch 2 fois par semaine', 'Régulier · 9 visites · 11,60 €', 9 * 11.6, 0], ['Isabelle — tarte du dimanche', 'Occasionnelle · 2 visites · 38,50 €', 2 * 38.5, 50]];
  const simu = `<div class="cb">${PROF.map(([n, s, ca, bonus]) => { const pts = ca + bonus, jours = 30 * CFG.vp / pts; return `<div style="padding:8px 0;border-top:.5px dashed var(--color-border-tertiary)"><div class="b" style="font-size:12px">${n}</div><div class="s11 mu">${s}</div><div style="display:flex;gap:14px;margin-top:4px;font-size:12px"><span><b>${nf(pts)}</b> pts / mois</span><span>un bon tous les <b>${nf(jours)}</b> j</span><span class="${100 * (pts / CFG.vp * CFG.vv) / ca > 4 ? 'att' : ''}">rend <b>${fP1(100 * (pts / CFG.vp * CFG.vv) / ca)}</b></span></div></div>`; }).join('')}</div>`;
  const budget = `<div class="cb"><div style="display:flex;justify-content:space-between;font-size:12px"><span>Coût de septembre <b>${fE(COUT)}</b></span><span class="mu">plafond fixé : 2 % du CA</span></div>
    <div style="height:12px;border-radius:6px;background:var(--color-background-secondary);margin:7px 0 4px;position:relative;overflow:hidden"><i style="display:block;height:100%;width:${100 * (100 * COUT / CA) / 2.5}%;background:var(--color-primary);border-radius:6px"></i><i style="position:absolute;left:80%;top:0;bottom:0;width:2px;background:var(--color-text)"></i></div>
    <div class="s11 mu">${fP1(100 * COUT / CA)} du CA · si l’on avance le bon retour à 21 jours : + 0,1 point · Automnale ×2 jusqu’au 15/11 : + 0,1 point</div></div>`;
  return page('c-programme', 'C', 'Les règles en phrases', 'p', `<div class="g21"><div class="col">
    ${bloc(1, 'On gagne des points', 'la base, sur tous les canaux', gagne, '+ Une règle')}
    ${bloc(2, 'Ça compte double', 'le jour, l’heure, la gamme, l’anniversaire', double, '+ Un créneau')}
    ${bloc(3, 'On reçoit', 'la jauge, les cartes à tampons, les déclencheurs', recoit, '+ Un déclencheur')}
    ${bloc(4, 'Garde-fous', 'ce qui empêche le programme de coûter trop', garde)}
    </div><div class="col">${carte('Ce que voit le client', 'Martine L., Corbais', `<div class="cb">${phone}</div>`)}
    ${carte('Simulateur', 'ce que les règles donnent à trois clients types', simu)}
    ${carte('Budget', '', budget)}</div></div>`, { btn: '<span class="btn p">Publier les règles</span>' });
}
function cClients() {
  const mxP = Math.max(...MIS.map(m => Math.max(...m.prof)));
  const matrice = `<div class="cb"><div style="display:grid;grid-template-columns:150px repeat(13,minmax(0,1fr)) 62px 84px 96px;gap:3px;font-size:10.5px;align-items:center">
    <span></span>${H.map(h => `<span class="mu b" style="text-align:center;font-size:10px">${h}h</span>`).join('')}<span class="mu b num" style="font-size:9.5px">PANIER</span><span class="mu b num" style="font-size:9.5px">VISITES / MOIS</span><span class="mu b num" style="font-size:9.5px">LÀ À M+3</span>
    ${MIS.map(m => `<span class="b" style="font-size:12px">${misPt(m.k)}${esc(m.nom)}<small class="mu" style="display:block;font-weight:400;font-size:10px;padding-left:15px">${m.tk} % des tickets</small></span>${m.prof.map((v, i) => { const t = v / mxP; return `<span title="${m.nom} ${H[i]}h" style="height:30px;border-radius:5px;background:${t ? `color-mix(in srgb,var(--m${m.k}) ${Math.round(12 + 88 * t)}%,#fff)` : '#f4efe8'}"></span>`; }).join('')}<span class="num b">${fU(m.pan)}</span><span class="num">${nf(m.freq, 1)}</span><span class="num ${m.ret >= 45 ? 'ok' : m.ret < 32 ? 'ko' : ''} b">${m.ret} %</span>`).join('')}
    </div><div class="s11 mu" style="margin-top:8px">intensité = part des tickets de la mission à cette heure · un ticket est rangé d’après son contenu (familles du catalogue) et son heure ; un panier de 25 € et plus est une « occasion ».</div></div>`;
  const multi = [['1 seule mission', 58, 21], ['2 missions', 29, 41], ['3 et plus', 13, 72]];
  const multiH = `<div class="cb">${multi.map(([l, p, ca]) => `<div style="display:grid;grid-template-columns:110px 1fr 90px;gap:10px;align-items:center;padding:7px 0"><span class="b s11">${l}</span><div class="hb"><div class="tr" style="height:14px"><i style="width:${p}%;background:${seq(.25 + p / 100)}"></i></div><span class="num s11 b" style="width:34px">${p} %</span></div><span class="num s11"><b>${ca} €</b> / mois</span></div>`).join('')}
    <div class="lecture">Un client à <b>3 missions</b> dépense <b>3,4 fois</b> plus. Le chemin le plus fréquent : pain quotidien → petit-déjeuner (24 %), puis lunch → goûter (17 %).</div></div>`;
  const pass = [['Pain quotidien', 'Petit-déjeuner', 24], ['Petit-déjeuner', 'Lunch', 19], ['Lunch', 'Goûter', 17], ['Pain quotidien', 'Occasion', 11], ['Goûter', 'Occasion', 9]];
  const passH = `<div class="cb">${pass.map(([a, b, p]) => { const ka = MIS.find(m => m.nom === a).k, kb = MIS.find(m => m.nom === b).k; return `<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-top:.5px dashed var(--color-border-tertiary)">${misChip(ka)}<span class="mu">→</span>${misChip(kb)}<span class="esp"></span><span class="num b">${p} %</span></div>`; }).join('')}<div class="s11 mu" style="margin-top:6px">part des clients d’une mission qui ajoutent la seconde dans les 60 jours</div></div>`;
  const liste = `<table class="tb"><thead><tr><th>Client</th><th>Magasin</th><th>Membre depuis</th><th>12 dernières semaines</th><th class="n">Panier</th><th>Type d’achat</th><th>Segment</th><th>Points</th><th>En attente</th><th>Prochain déclencheur</th></tr></thead><tbody>
    ${CLI.map(c => `<tr><td class="nom" style="white-space:nowrap">${esc(c[0])}</td><td class="mu">${c[1]}</td><td class="mu">${c[2]}</td><td>${spark(c[3], 108, 20, c[10] ? '#C0182B' : 'var(--color-primary)')}</td><td class="n">${fU(c[4])}</td><td>${c[5].map(misChip).join(' ')}</td><td>${chip(SEGCOL[c[6]], c[6])}</td><td><div class="hb" style="width:90px"><div class="tr"><i style="width:${100 * c[7] / 150}%;background:var(--color-primary)"></i></div><span class="num s11">${c[7]}</span></div></td><td class="s11">${c[8] === '—' ? '<span class="mu">—</span>' : chip(c[8].startsWith('bon') || c[8].startsWith('bienv') ? 'bon' : 'cad', esc(c[8]))}</td><td class="s11 ${c[10] ? 'ko b' : ''}">${esc(c[9])}</td></tr>`).join('')}</tbody></table>`;
  const filtres = `<span class="seg"><span class="on">Tous</span>${SEG.slice(0, 5).map(s => `<span>${s.nom}</span>`).join('')}</span><span class="ctl" style="min-width:180px;color:var(--color-text-muted)">Nom, carte, téléphone…</span>`;
  return page('c-clients', 'C', 'Les règles en phrases', 'c', `<div class="col">
    <div class="tuiles">${tuile('Clients actifs', nf(ACTIFS), 'septembre')}${tuile('Visites par mois', '2,5', 'intervalle médian 8 jours')}${tuile('Missions par client', '1,6', '42 % en font deux et plus')}${tuile('Panier membre', fU(PAN_M), `${fU(PAN_NM)} sans carte`)}${tuile('Le type qui retient', 'Pain quotidien', '61 % encore là à M+3', 'fort')}</div>
    ${carte('Le type d’achat, heure par heure', 'septembre, tickets avec la carte', matrice)}
    <div class="g2">${carte('Une, deux, trois missions', 'les clients selon le nombre de types d’achat qu’ils font', multiH)}${carte('D’une mission à l’autre', 'les passages qui font monter le panier', passH)}</div>
    ${carte('Les clients', 'les plus actifs d’abord · un clic ouvre la fiche (historique, bons, points, opt-in)', liste, filtres, 'Données personnelles : prénom et initiale à l’écran ; la fiche complète réservée au magasin du client et à la marque. Export limité aux clients ayant accepté la newsletter.')}</div>`);
}

/* ================================================================================================
 * D — Le calendrier d'abord
 * ============================================================================================== */
function dProgramme() {
  const effets = X2.filter(x => x.eff).map(x => `<div style="padding:8px 0;border-top:.5px dashed var(--color-border-tertiary)"><div class="b s11">${chip('x2', x.k)} ${esc(x.quand)}</div>
    <div style="display:grid;grid-template-columns:86px 1fr 40px;gap:6px;align-items:center;margin-top:6px;font-size:11px"><span class="mu">membres</span><div class="hb"><div class="tr"><i style="width:${x.eff * 6}%;background:var(--color-primary)"></i></div></div><span class="num b">+${x.eff} %</span>
    <span class="mu">sans carte</span><div class="hb"><div class="tr"><i style="width:${x.ref * 6}%;background:#c9bfb3"></i></div></div><span class="num">+${x.ref} %</span></div></div>`).join('');
  const sem = `<div class="cb" style="display:grid;grid-template-columns:minmax(0,1fr) 270px;gap:20px"><div>${grille(true)}${legGrille}</div>
    <div><div class="s11 mu b" style="text-transform:uppercase;letter-spacing:.06em;font-size:10px">Effet mesuré · face aux 4 semaines d’avant</div>${effets}
    <div class="lecture" style="margin-top:10px">Le mardi → jeudi 14–17 h rapporte <b>+9 points nets</b> de tickets. Le samedi 9–11 h est plein : <b>aucun ×2</b> à y poser.</div>
    <div style="margin-top:10px"><span class="btn p">+ Dessiner un créneau ×2</span></div></div></div>`;
  // Les trois mois à venir.
  const D0 = new Date('2026-10-01'), D1 = new Date('2027-01-01'), tot = (D1 - D0) / 864e5;
  const xp = d => 100 * ((new Date(d) - D0) / 864e5) / tot;
  const lanes = [
    ['Toujours', [['2026-10-01', '2027-01-01', '×2 mardi → jeudi 14–17 h', 'x2'], ['2026-10-01', '2027-01-01', '×3 anniversaire', 'x3', 1]]],
    ['Gammes', [['2026-10-01', '2026-11-16', '×2 Automnale', 'x2'], ['2026-11-17', '2027-01-01', '×2 Hiver (à créer)', 'brouillon']]],
    ['Temps forts', [['2026-10-28', '2026-11-01', 'Halloween : goûter ×2', 'x2'], ['2026-12-01', '2026-12-07', 'Saint-Nicolas : spéculoos offert dès 15 €', 'cad'], ['2026-12-10', '2026-12-24', 'Bûches commandées : ×3', 'x3', 1]]],
    ['Garde-fous', [['2026-12-24', '2026-12-25', '24/12 : pas de ×2', 'stop'], ['2026-12-31', '2027-01-01', '31/12 : pas de ×2', 'stop', 1]]],
  ];
  const sty = { x2: 'background:var(--x2);color:#fff', x3: 'background:#4A1E3C;color:#fff', cad: 'background:#e9e2f7;color:#4b3a8c', brouillon: 'background:#fff;border:1px dashed var(--color-border-secondary);color:var(--color-text-muted)', stop: 'background:repeating-linear-gradient(135deg,#f8e3e5 0 4px,#fff 4px 8px);color:var(--ko);border:1px solid #efc7cd' };
  const cal = `<div class="cb"><div style="display:grid;grid-template-columns:90px 1fr;gap:6px 10px;align-items:center">
    <span></span><div style="position:relative;height:18px">${['2026-10-01', '2026-11-01', '2026-12-01'].map((m, i) => `<span style="position:absolute;left:${xp(m)}%;font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--color-text-muted);border-left:1px solid var(--color-border-secondary);padding-left:5px;height:220px">${['Octobre', 'Novembre', 'Décembre'][i]}</span>`).join('')}<span style="position:absolute;left:${xp('2026-10-24')}%;top:-2px;font-size:9.5px;font-weight:700;color:var(--color-primary);border-left:2px solid var(--color-primary);padding-left:3px;height:220px;z-index:2">auj.</span></div>
    ${lanes.map(([n, ev]) => `<span class="b s11">${n}</span><div style="position:relative;height:${ev.some(e => e[4]) ? 52 : 26}px">${ev.map(([a, b, t, k, row]) => { const w = xp(b) - xp(a), court = w < 12; return `<span title="${esc(t)}" style="position:absolute;top:${row ? 26 : 0}px;left:${xp(a)}%;width:calc(${w}% - 2px);min-width:10px;height:22px;border-radius:6px;${sty[k]};font-size:10.5px;font-weight:600;line-height:22px;padding:0 ${court ? 0 : 7}px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;z-index:1">${court ? '' : esc(t)}</span>${court ? `<span style="position:absolute;top:${row ? 26 : 0}px;${xp(b) > 80 ? `right:${100 - xp(a) + .6}%` : `left:${xp(b) + .6}%`};font-size:10.5px;font-weight:600;line-height:22px;white-space:nowrap;color:${k === 'stop' ? 'var(--ko)' : 'var(--color-text)'}">${esc(t)}</span>` : ''}`; }).join('')}</div>`).join('')}
    </div></div>`;
  const jaugeC = `<div class="cb"><div style="font-size:13px">${inp('1 €')} = ${inp('1 point')} · ${inp('150')} = ${chip('bon', '🎟 5 €')}</div><div class="s11 mu" style="margin-top:6px">rend ${fP1(100 * CFG.vv / CFG.vp)} · ${nf(PTS.circ)} points en circulation (${fE(CIRC_E)})</div><div class="s11" style="margin-top:6px">750 bons ce mois · <b>73 %</b> utilisés</div><div style="height:12px;border-radius:6px;display:flex;overflow:hidden;gap:2px;margin:12px 0 4px">${[820, 610, 520, 430, 388, 412].map((n, i) => `<i style="flex:${n};background:${seq(.12 + i * .14)}"></i>`).join('')}</div><div class="s11 mu">les soldes des membres actifs, de 0 à 150 points · <b style="color:var(--color-primary)">412</b> à moins de 25 points du bon</div></div>`;
  const cartesC = `<div class="cb">${CARTES.map(c => `<div style="display:flex;align-items:center;gap:8px;padding:4px 0"><i style="width:10px;height:10px;border-radius:50%;background:${c.fill}"></i><span class="b s11" style="flex:1">${esc(c.nom)}</span><span class="s11 mu">${c.req} = 1</span><span class="s11 num" style="width:64px"><b>${c.pr}</b> prêtes</span></div>`).join('')}</div>`;
  const declC = `<div class="cb">${DECL.slice(0, 6).concat(DECL[7]).map(d => `<div style="display:flex;align-items:center;gap:8px;padding:3px 0;font-size:11.5px"><span style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(d.ev)}</span>${recChip(d.rec)}</div>`).join('')}</div>`;
  return page('d-programme', 'D', 'Le calendrier d’abord', 'p', `<div class="col">
    ${carte('La semaine, heure par heure', 'le trafic du réseau en fond, les créneaux où les points comptent double par-dessus · un glisser sur la grille crée un créneau', sem)}
    ${carte('Les trois mois à venir', 'les règles datées : gammes, temps forts, jours où rien ne double', cal, '<span class="btn">+ Un temps fort</span>')}
    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px">${carte('La jauge', '', jaugeC, '<span class="btn">Régler ›</span>')}${carte('Cartes à tampons', '', cartesC, '<span class="btn">Régler ›</span>')}${carte('Déclencheurs', '', declC, '<span class="btn">Régler ›</span>')}</div></div>`);
}
function dClients() {
  // Histogramme des intervalles.
  const W = 1060, Hh = 216, x0 = 40, y0 = 190, bw = (W - x0 - 10) / 45, K = 8;
  let hist = `<svg viewBox="0 0 ${W} ${Hh}" width="100%">`;
  [0, 5, 10, 15, 20].forEach(v => { const y = y0 - v * K; hist += `<line class="gr" x1="${x0}" x2="${W}" y1="${y}" y2="${y}"/><text class="ax" x="${x0 - 6}" y="${y + 3}" text-anchor="end">${v} %</text>`; });
  INTER.forEach((v, i) => { const h = v * K, x = x0 + i * bw, d = i + 1; hist += `<path d="M${(x + 1).toFixed(1)},${y0} v${-(Math.max(h, 4) - 3).toFixed(1)} q0,-3 3,-3 h${(bw - 8).toFixed(1)} q3,0 3,3 v${(Math.max(h, 4) - 3).toFixed(1)} z" fill="${d <= 8 ? 'var(--color-primary)' : d === 14 || d === 21 ? '#b8707a' : '#d8c9c0'}"><title>${d} jour${d > 1 ? 's' : ''} : ${nf(v, 1)} % des retours</title></path>`; if (d === 1 || d % 7 === 0 || d === 45) hist += `<text class="ax" x="${x + bw / 2}" y="${y0 + 14}" text-anchor="middle">${d} j</text>`; });
  const ann = (d, t, dx) => { const x = x0 + (d - .5) * bw, y = y0 - INTER[d - 1] * K - 6; return `<text class="lb" x="${x + (dx < -100 ? -bw / 2 - 3 : dx || 0)}" y="${y}" text-anchor="${dx ? (dx < -100 ? 'end' : 'start') : 'middle'}">${t}</text>`; };
  hist += ann(1, `le lendemain : ${nf(INTER[0])} %`, -8) + ann(7, `7 jours pile : ${nf(INTER[6])} %`, -150) + ann(14, '15 jours') + ann(21, '3 semaines') + `<line x1="${x0 + 8 * bw}" x2="${x0 + 8 * bw}" y1="${y0}" y2="60" stroke="var(--color-text)" stroke-dasharray="3 3"/><text class="lb" x="${x0 + 8 * bw + 6}" y="68">médiane : 8 jours — la moitié des retours se font dans la semaine</text>`;
  hist += '</svg>';
  // Rétention : une ligne par cohorte.
  const W2 = 520, H2 = 230, xa = 44, ya = 196, sx = (W2 - xa - 70) / 6, sy = 1.7;
  let ret = `<svg viewBox="0 0 ${W2} ${H2}" width="100%">`;
  [0, 25, 50, 75, 100].forEach(v => { const y = ya - v * sy; ret += `<line class="gr" x1="${xa}" x2="${W2 - 70}" y1="${y}" y2="${y}"/><text class="ax" x="${xa - 6}" y="${y + 3}" text-anchor="end">${v} %</text>`; });
  [0, 1, 2, 3, 4, 5, 6].forEach(m => { ret += `<text class="ax" x="${xa + m * sx}" y="${ya + 14}" text-anchor="middle">${m ? 'M+' + m : '1re'}</text>`; });
  COH.slice(0, 6).forEach(([m, n, r], i) => { const t = .3 + .7 * (1 - i / 5), pts = [100].concat(r).map((v, k) => [xa + k * sx, ya - v * sy]); ret += `<path d="M${pts.map(p => p.join(',')).join(' L')}" fill="none" stroke="${seq(t)}" stroke-width="2"/>${pts.map(p => `<circle cx="${p[0]}" cy="${p[1]}" r="3" fill="${seq(t)}" stroke="#fff" stroke-width="1.5"/>`).join('')}`; const l = pts[pts.length - 1]; if (i === 0) ret += `<text class="lb" x="${l[0] + 7}" y="${l[1] + 3}">${m} ${r[r.length - 1]} %</text>`; if (i === 4) ret += `<text class="lb" x="${l[0] + 4}" y="${l[1] + 16}">${m} ${r[r.length - 1]} %</text>`; if (i === 5) ret += `<text class="lb" x="${l[0] + 6}" y="${l[1] - 9}">${m} ${r[r.length - 1]} %</text>`; });
  ret += '</svg>';
  // Retour spontané.
  const W3 = 520, H3 = 230, xb = 44, yb = 196, sxb = (W3 - xb - 20) / 90, syb = 1.7;
  let surv = `<svg viewBox="0 0 ${W3} ${H3}" width="100%">`;
  [0, 25, 50, 75, 100].forEach(v => { const y = yb - v * syb; surv += `<line class="gr" x1="${xb}" x2="${W3 - 20}" y1="${y}" y2="${y}"/><text class="ax" x="${xb - 6}" y="${y + 3}" text-anchor="end">${v} %</text>`; });
  [0, 14, 21, 30, 45, 60, 90].forEach(d => { surv += `<text class="ax" x="${xb + d * sxb}" y="${yb + 14}" text-anchor="middle">${d} j</text>`; });
  surv += `<rect x="${xb + 21 * sxb}" y="${yb - 100 * syb}" width="${24 * sxb}" height="${100 * syb}" fill="rgba(141,29,44,.06)"/>`;
  surv += `<path d="M${SURV.map(([d, p]) => `${xb + d * sxb},${yb - p * syb}`).join(' L')}" fill="none" stroke="var(--color-primary)" stroke-width="2"/>${SURV.map(([d, p]) => `<circle cx="${xb + d * sxb}" cy="${yb - p * syb}" r="3.5" fill="var(--color-primary)" stroke="#fff" stroke-width="1.5"><title>${d} jours sans visite : ${p} % reviennent seuls</title></circle>`).join('')}`;
  surv += `<line x1="${xb + 45 * sxb}" x2="${xb + 45 * sxb}" y1="${yb}" y2="${yb - 100 * syb}" stroke="var(--color-text)" stroke-dasharray="3 3"/><text class="lb" x="${xb + 45 * sxb + 5}" y="${yb - 92 * syb}">bon aujourd’hui : 45 j → 21 %</text>`;
  surv += `<line x1="${xb + 21 * sxb}" x2="${xb + 21 * sxb}" y1="${yb}" y2="${yb - 100 * syb}" stroke="var(--ok)" stroke-width="1.5"/><text class="lb" x="${xb + 21 * sxb + 5}" y="${yb - 60 * syb}" style="fill:var(--ok)">proposé : 21 j → 52 %</text>`;
  surv += '</svg>';
  // La semaine type : tickets par jour, rangés par mission.
  const SEM = [[34, 22, 19, 12, 5, 8], [33, 23, 20, 13, 4, 7], [30, 21, 17, 22, 4, 6], [33, 23, 19, 14, 4, 7], [31, 22, 18, 13, 9, 7], [27, 24, 8, 12, 25, 4], [26, 28, 2, 6, 37, 1]];
  const TJ = [940, 1060, 1120, 1070, 1190, 1460, 1180];
  const mxT = Math.max(...TJ);
  const semH = `<div class="cb"><div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:14px;align-items:end;height:200px">${SEM.map((r, j) => `<div style="display:flex;flex-direction:column;align-items:center;gap:4px;height:100%;justify-content:flex-end"><span class="s11 b">${nf(TJ[j])}</span><div style="width:46px;height:${150 * TJ[j] / mxT}px;display:flex;flex-direction:column-reverse;gap:2px">${r.map((v, k) => `<i title="${JOURS[j]} · ${MIS[k].nom} ${v} %" style="flex:${v};background:var(--m${k + 1});${k === r.length - 1 ? 'border-radius:4px 4px 0 0' : ''}"></i>`).join('')}</div><span class="s11 mu b">${JOURS[j]}</span></div>`).join('')}</div>${legMis}
    <div class="lecture">Le week-end change de client : l’<b>occasion</b> passe de 4 % à 37 % des tickets le dimanche, le lunch disparaît. Le mercredi après-midi est le <b>goûter</b> (22 %).</div></div>`;
  return page('d-clients', 'D', 'Le calendrier d’abord', 'c', `<div class="col">
    <div class="tuiles">${tuile('Intervalle médian', '8 <small>jours</small>', 'entre deux visites d’un même client')}${tuile('Rythme hebdo', fP(INTER[5] + INTER[6] + INTER[7]), 'des retours tombent à 7 jours pile, à un jour près')}${tuile('Retour à 30 jours', '44 %', 'des nouveaux d’août')}${tuile('Encore là à 6 mois', '34 %', 'cohorte de mars')}${tuile('Point de bascule', '21 <small>jours</small>', 'au-delà, un régulier revient seul moins d’une fois sur deux', 'fort')}</div>
    ${carte('Combien de jours entre deux visites', 'septembre, membres actifs · part des retours selon l’écart avec la visite précédente', `<div class="cb">${hist}</div>`)}
    <div class="g2">${carte('Combien restent, mois après mois', 'une ligne par cohorte (mois de la 1re visite)', `<div class="cb">${ret}<div class="lecture">Les courbes se tassent après M+3 : qui passe trois mois reste.</div></div>`)}
    ${carte('Revient-il tout seul ?', 'réguliers : part qui reviennent sans bon, selon les jours sans visite', `<div class="cb">${surv}<div class="lecture">Entre 21 et 45 jours, la moitié des réguliers décroche. Le bon « on vous attend » arrive après.</div></div>`, '<span class="btn p">Avancer à 21 jours</span>')}</div>
    ${carte('La semaine type d’un membre', 'tickets avec la carte par jour, rangés par type d’achat', semH)}
    ${carte('Le type d’achat', '', `<div class="cb" style="padding-top:0">${tabMissions(true)}</div>`)}</div>`);
}

/* --- Génération + captures ---------------------------------------------------------------------- */
const PAGES = { 'a-programme': aProgramme, 'a-clients': aClients, 'b-programme': bProgramme, 'b-clients': bClients, 'c-programme': cProgramme, 'c-clients': cClients, 'd-programme': dProgramme, 'd-clients': dClients };
const DIRS = [
  ['a', 'A — Tout sur une page', 'Le format habituel du cockpit : six tuiles, puis une carte par brique — la jauge et les soldes, les bons et cadeaux du mois, la semaine heure par heure avec les créneaux ×2, les cartes à tampons, les déclencheurs, le détail par magasin. Côté clients : fréquence (part des clients face à la part du CA), cohortes, segments, types d’achat.',
    ['Tout se lit d’un défilement, comme les autres écrans du cockpit.', 'Les soldes de points montrent qui est près du bon : de quoi cibler un ×2.', 'Le tableau des segments dit, pour chacun, le déclencheur lié et quoi faire.'],
    ['Long : réglages et lecture sont mélangés.', 'Le lien entre un déclencheur et le client qu’il vise reste implicite.']],
  ['b', 'B — Le parcours client', 'Les étapes du client — inscrit, 1re visite, 2e visite, régulier, habitué, puis à risque et endormi — avec, sous chacune, le déclencheur qui le pousse à l’étape suivante et ce qu’il a donné. Côté clients : la matrice récence × fréquence, le détail du segment choisi, qui monte et qui descend d’un mois à l’autre.',
    ['On voit tout de suite où le programme manque : le bon retour arrive trop tard.', 'La matrice récence × fréquence montre les 470 clients à risque d’un coup d’œil.', 'Chaque segment ouvre sur une action (avancer le bon, envoyer à la newsletter).'],
    ['Les créneaux ×2 passent au second plan.', 'La matrice demande une phrase d’explication la première fois.']],
  ['c', 'C — Les règles en phrases', 'Chaque règle s’écrit comme une phrase aux valeurs modifiables (« Tous les 150 points, un bon de 5 € valable 60 jours »), en quatre blocs : on gagne, ça compte double, on reçoit, garde-fous. À droite, l’app du client telle que les règles la montrent, un simulateur sur trois clients types et le budget. Côté clients : le type d’achat heure par heure, les missions par client, la liste des clients.',
    ['Un franchisé comprend et règle sans formation.', 'L’aperçu de l’app et le simulateur montrent l’effet d’un réglage avant de publier.', 'Les garde-fous (plafond, cumul, expiration) sont écrits noir sur blanc.'],
    ['Peu de chiffres d’effet côté programme.', 'La liste des clients pose la question des données personnelles (accès par magasin).']],
  ['d', 'D — Le calendrier d’abord', 'La semaine heure par heure en grand, le trafic en fond et les créneaux ×2 par-dessus, avec l’effet mesuré de chacun (membres face aux non-membres) ; puis les trois mois à venir (gammes, temps forts, jours sans ×2) ; jauge, cartes et déclencheurs en trois cartes compactes. Côté clients : le rythme — jours entre deux visites, rétention par cohorte, retour spontané, la semaine type.',
    ['Les ×2 se posent là où le trafic est creux, et leur effet se mesure.', 'Le calendrier évite les doublons et les ×2 sur des jours déjà pleins.', 'La courbe de retour spontané fixe le bon moment pour relancer (21 jours).'],
    ['Les déclencheurs et les cartes se règlent ailleurs (un clic de plus).', 'Moins parlant pour un franchisé que pour la marque.']],
];
(async () => {
  for (const [id, fn] of Object.entries(PAGES)) fs.writeFileSync(OUT + id + '.html', fn());
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const c = await b.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1.5 });
  const p = await c.newPage(); const err = []; p.on('pageerror', e => err.push(e.message)); p.on('console', m => { if (m.type() === 'error') err.push(m.text()); });
  for (const id of Object.keys(PAGES)) {
    await p.goto(BASE + id + '.html', { waitUntil: 'load' }); await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(300);
    await p.screenshot({ path: OUT + id + '.jpg', type: 'jpeg', quality: 84, fullPage: true });
    console.log(id, '· hauteur', await p.evaluate(() => document.documentElement.scrollHeight), '· déborde', await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth));
  }
  const planche = `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Fidélité : quatre maquettes</title><link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="fi.css"></head><body><div class="pl" id="planche">
  <span class="u">Maquettes · cockpit › ERP franchisé › Fidélité (/fidelity) · 24/10/2026</span><h1>Fidélité — quatre façons de faire la page</h1>
  <p class="acc">Une page, deux onglets. <b>Programme</b> : les points (1 € = 1 point, 150 points = un bon de 5 €), les cartes à tampons, les bons et cadeaux, les déclencheurs (bienvenue, 2e visite, anniversaire, 10e visite, panier ≥ 30 €, parrainage, client absent) et les créneaux où les points comptent double (jours et heures creux, gamme, anniversaire). <b>Clients</b> : la récurrence (fréquence, cohortes, segments, rythme) et le type d’achat (pain quotidien, petit-déjeuner, lunch, goûter, occasion, commande &amp; bureau). Réel : les quatre magasins, le modèle de la PWA (cartes, jauge, ×2) et des bons ERP. Illustré : tous les chiffres clients.</p>
  <div class="dirs">${DIRS.map(([k, t, acc, plus, moins]) => `<div class="dir"><h2>${t}</h2><p class="acc">${acc}</p><div class="imgs"><figure><figcaption>Onglet Programme</figcaption><div class="vg"><img src="${k}-programme.jpg" alt=""></div></figure><figure><figcaption>Onglet Clients</figcaption><div class="vg"><img src="${k}-clients.jpg" alt=""></div></figure></div><div class="pc"><div class="p"><h4>Ce que ça apporte</h4><ul>${plus.map(x => `<li>${x}</li>`).join('')}</ul></div><div class="m"><h4>Limites</h4><ul>${moins.map(x => `<li>${x}</li>`).join('')}</ul></div></div></div>`).join('')}</div>
  </div></body></html>`;
  fs.writeFileSync(OUT + 'planche.html', planche);
  const q = await c.newPage(); await q.setViewportSize({ width: 1660, height: 900 });
  await q.goto(BASE + 'planche.html', { waitUntil: 'load' }); await q.evaluate(() => document.fonts.ready); await q.waitForTimeout(600);
  await (await q.$('#planche')).screenshot({ path: OUT + 'planche.jpg', type: 'jpeg', quality: 80 });
  console.log('erreurs :', err.join(' | ') || 'aucune');
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
