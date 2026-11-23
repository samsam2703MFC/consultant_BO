/* Tâches (vue=taches) : les jours passés et les retours du contrôle qualité du magasin de démo.
 * Lu par panel/public/dashboard/dashboard.js (rendTachesMag) : /pwa/tasks?shop=&date= (chaque jour de la semaine),
 * /pwa/tasks/photos?shop=&date= (les photos de chaque jour) et /pwa/tasks/nc?…&retours=1 (les notes des 30 jours, aux
 * formes d'ep_pwa_tasks_nc, shared/src/endpoints.php). Aujourd'hui reste celui de lib/taches.js (l'Opérationnel le lit
 * aussi) ; les jours d'avant varient un peu : tout fait lundi, l'alarme oubliée mardi, une photo oubliée mercredi. Le
 * récit : les croissants du matin trop colorés (note 3, non-conformité mineure) lundi et mardi, repris mercredi (4),
 * revenus aujourd'hui. Passe avant 10_ops.js (ordre des noms). Tout est fictif. */
'use strict';
const D = require('./lib/demo');
const T = require('./lib/taches');

const AUJ = D.AUJ, CONSULTANT = 'Le consultant';
const PH = n => 'https://demo.local/api/cockpit/__photo/' + n + '.svg';
const COMMENTAIRES = {
  9001: 'étiquettes de prix manquantes sur deux plateaux', 9002: 'vitrine pas remplie à l’ouverture',
  9003: 'coloration inégale sur la plaque du bas', 9004: 'film mal posé sur les sandwichs', 9005: 'farine au sol sous le plan',
};

/** Les notes d'un jour passé : 9001 vitrine viennoiseries, 9002 pâtisseries, 9003 croissants, 9004 sandwichs, 9005 fournil. */
function notesDu(d) {
  const fixe = { '2026-11-16': { 9003: 3 }, '2026-11-17': { 9003: 3, 9002: 5 }, '2026-11-18': { 9003: 4 }, '2026-11-12': { 9003: 3 }, '2026-11-13': { 9003: 4 },
    '2026-11-05': { 9001: 3 }, '2026-11-06': { 9001: 4 }, '2026-10-28': { 9005: 2 }, '2026-10-29': { 9005: 4 } }[d] || {};
  const r = D.alea('notes' + d), N = {};
  [9001, 9002, 9003, 9004, 9005].forEach(id => { N[id] = fixe[id] != null ? fixe[id] : (r() < 0.55 ? 4 : 5); });
  return N;
}
/** Les tâches d'un jour : celles de lib/taches.js, et, pour un jour passé, ce qui a été fait, rendu et noté ce jour-là. */
function jour(d) {
  const R = T.taches(d);
  if (d >= AUJ) { return R; }
  const N = notesDu(d), r = D.alea('fait' + d);
  const alarme = d !== '2026-11-17' && r() > 0.15, photoOubliee = d === '2026-11-18';
  R.shops[0].taches.forEach(t => {
    if (N[t.taskId] != null) {
      Object.assign(t, { statut: 'notee', note: N[t.taskId], valideeLe: d + (t.taskId === 9005 ? ' 13:30:00' : ' 09:1' + (t.taskId % 10) + ':00'), valideePar: CONSULTANT });
      if (!t.faitLe) { t.faitLe = d + ' 10:56:00'; t.faitePar = 'Lucas V.'; }
      t.comment = N[t.taskId] < 4 ? COMMENTAIRES[t.taskId] : null;
    }
    if (t.taskId === 9006) { Object.assign(t, photoOubliee ? { statut: 'sansPhoto', faitLe: d + ' 16:12:00', faitePar: 'Sarah K.' } : { statut: 'notee', note: 4, faitLe: d + ' 16:08:00', faitePar: 'Sarah K.', valideeLe: d + ' 17:40:00', valideePar: CONSULTANT }); }
    if (t.taskId === 9007) { Object.assign(t, alarme ? { statut: 'sansPhoto', faitLe: d + ' 19:04:00', faitePar: 'Inès B.' } : { statut: 'nonRendue' }); }
    if (t.taskId === 9008) { Object.assign(t, { statut: 'sansPhoto' }); }
  });
  return R;
}
/** Les photos d'un jour : les croissants dorés quand ils sont conformes, entourés quand ils ne le sont pas. */
function photos(d) {
  const P = T.photos(d);
  if (d >= AUJ) { return P; }
  const N = notesDu(d);
  P.photos.forEach(p => { if (p.taskId === 9003 && N[9003] >= 4) { p.photo = PH('croissants-ok'); p.reperes = []; } });
  if (!(d === '2026-11-18')) { P.photos.push({ taskId: 9006, photo: PH('patisseries'), reperes: [] }); }
  return P;
}
/** Les retours du contrôleur sur une période (?retours=1) : les non-conformités, leur récidive et leur suite. */
function nc(du, au) {
  const jours = []; for (let d = du; d <= au && d <= AUJ; d = D.plus(d, 1)) { jours.push(d); }
  const avis = [];
  jours.forEach(d => jour(d).shops[0].taches.filter(t => t.note != null).forEach(t => avis.push({ taskId: String(t.taskId), jour: d, tache: t.tache, note: t.note, comment: t.comment || null, consultant: t.valideePar || CONSULTANT, le: String(t.valideeLe || d + ' 09:10:00').slice(0, 16) })));
  // L'historique de chaque tâche jusqu'à aujourd'hui (la suite regarde en avant).
  const hist = {};
  for (let d = D.plus(du, -6); d <= AUJ; d = D.plus(d, 1)) { jour(d).shops[0].taches.filter(t => t.note != null).forEach(t => { (hist[t.taskId] = hist[t.taskId] || []).push({ jour: d, note: t.note, nc: t.note < 4 }); }); }
  const L = avis.filter(a => a.note < 4).sort((a, b) => b.jour.localeCompare(a.jour)).map(a => {
    const H = hist[a.taskId] || [], deb = D.plus(a.jour, -6);
    const rec = H.filter(h => h.nc && h.jour >= deb && h.jour <= a.jour).length;
    const s = H.find(h => h.jour > a.jour);
    return { taskId: a.taskId, jour: a.jour, tache: a.tache, note: a.note, comment: a.comment, consultant: a.consultant, releveeLe: a.le,
      recidive: rec > 1 ? rec : null, suite: s ? { jour: s.jour, note: s.note, conforme: !s.nc } : null, valideeLe: null, valideePar: null };
  });
  const retours = avis.sort((a, b) => b.le.localeCompare(a.le)).slice(0, 40).map(a => Object.assign({}, a, { nc: a.note < 4 }));
  return { shopId: D.SHOP, date: null, du, au, seuil: 4, niveaux: [], notees: avis.length, nc: L, semaine: [], indispo: false, retours };
}
/** Les croissants conformes : l'illustration de lib/taches.js, toutes les plaques dorées. */
function croissantsOk() { const s = T.illustration('croissants'); return s ? s.replace(/url\(#fonce\)/g, 'url(#dore)') : null; }

module.exports = function (m, p, q) {
  if (m !== 'GET') { return undefined; }
  const d = q.get('date') || AUJ;
  if (p === '/pwa/tasks' && d < AUJ) { return jour(d); }
  if (p === '/pwa/tasks/photos' && d < AUJ) { return photos(d); }
  if (p === '/pwa/tasks/nc' && q.get('retours') && q.get('du') && q.get('au')) { return nc(q.get('du'), q.get('au')); }
  if (p === '/__photo/croissants-ok.svg') { return { __body: croissantsOk(), type: 'image/svg+xml' }; }
  return undefined;
};
