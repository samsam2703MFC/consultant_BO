/* Temps morts (vue=morts) : GET /exploitation/temps-morts?shop=&date=&seuil=, aux formes d'ep_temps_morts
 * (panel/src/temps_morts.php), lu par rendTempsMorts (panel/public/dashboard/dashboard.js).
 * Les tickets de chaque jour sont tirés du magasin de démo (lib/demo.js : le nombre de tickets de chaque heure, celui de
 * l'Opérationnel), posés minute par minute, avec les creux de l'après-midi (vers 14 h 30 et 17 h 30, comme les creux
 * habituels de Mon équipe) ; la mesure est celle du serveur (tmMesure, tmMoyenne), recopiée. Tout est fictif. */
'use strict';
const D = require('./lib/demo');

const AUJ = D.AUJ, SEUILS = [5, 10, 15], ISOLE = 45;
// Les creux de l'après-midi, par jour de la semaine (0 = dimanche), en minutes depuis minuit.
const CREUX = { 0: [[868, 886]], 1: [[880, 912], [1062, 1076]], 2: [[862, 900], [1052, 1072]], 3: [[872, 902]], 4: [[858, 893], [1040, 1056]], 5: [[888, 918]], 6: [[902, 925]] };

/** Les heures des tickets d'un jour, en secondes depuis minuit, triées (null : à venir). */
function tickets(s) {
  if (s > AUJ) { return null; }
  const fin = s === AUJ ? D.MAINTENANT : 24 * 60;
  const J = D.journee(s, s === AUJ ? D.MAINTENANT : null), r = D.alea('tm' + s);
  const creux = CREUX[D.dow(s)].map(([a, b]) => { const d = Math.round((r() - 0.5) * 8); return [a + d, b + d + Math.round((r() - 0.5) * 6)]; });
  const t = [];
  J.heures.forEach(({ h, tickets: n }) => {
    // Les minutes où un client peut passer : l'heure, moins les creux, moins ce qui n'est pas encore arrivé.
    let seg = [[h * 60, Math.min(h * 60 + 60, fin)]];
    creux.forEach(([a, b]) => { seg = seg.flatMap(([x, y]) => (b <= x || a >= y) ? [[x, y]] : [[x, a], [b, y]].filter(([u, v]) => v - u > 0.5)); });
    const L = seg.reduce((a, [x, y]) => a + y - x, 0);
    if (!n || L <= 0) { return; }
    // Des passages répartis, un peu irréguliers ; parfois deux tickets dans la même minute (une file).
    const doubles = n >= 12 ? Math.floor(n * 0.15) : 0, ev = n - doubles, pas = L / ev;
    const pos = [];
    for (let i = 0; i < ev; i++) { pos.push(Math.min(L - 0.02, Math.max(0.02, (i + 0.5 + (r() - 0.5) * 0.8) * pas))); }
    const sec = pos.map(p => { let q = p; for (const [x, y] of seg) { if (q < y - x) { return Math.round((x + q) * 60); } q -= y - x; } return Math.round(seg[seg.length - 1][1] * 60 - 30); });
    for (let k = 0; k < doubles; k++) { const i = Math.floor(r() * sec.length); sec.push(sec[i] + 5 + Math.floor(r() * 35)); }
    t.push(...sec);
  });
  return t.sort((a, b) => a - b);
}

const hm = s => String(Math.floor(s / 3600)).padStart(2, '0') + ':' + String(Math.floor(s % 3600 / 60)).padStart(2, '0');
const r1 = v => Math.round(v * 10) / 10;
/** tmMesure : la mesure d'une journée (ses tickets, un seuil en minutes) ; `detail` : les tickets et les trous. */
function mesure(t0, seuil, detail) {
  const t = t0.slice().sort((a, b) => a - b), n0 = t.length, iso = ISOLE * 60;
  let i0 = 0, i1 = n0 - 1;
  while (i1 - i0 >= 1 && t[i0 + 1] - t[i0] >= iso) { i0++; }
  while (i1 - i0 >= 1 && t[i1] - t[i1 - 1] >= iso) { i1--; }
  const u = n0 > 0 ? t.slice(i0, i1 + 1) : [], n = u.length;
  if (n < 2) { return { ferme: true, tickets: n }; }
  const deb = u[0], fin = u[n - 1], S = seuil * 60, H = {};
  for (let h = Math.floor(deb / 3600); h <= Math.floor(fin / 3600); h++) { H[h] = { ouvert: Math.max(0, Math.min(fin, (h + 1) * 3600) - Math.max(deb, h * 3600)), mort: 0, nb: 0, tk: 0 }; }
  u.forEach(x => { H[Math.floor(x / 3600)].tk++; });
  const trous = []; let mort = 0, long = [0, deb, deb];
  for (let i = 0; i + 1 < n; i++) {
    const a = u[i], b = u[i + 1], g = b - a;
    if (g > long[0]) { long = [g, a, b]; }
    if (g < S) { continue; }
    trous.push([a, b]); mort += g; H[Math.floor(a / 3600)].nb++;
    for (let h = Math.floor(a / 3600); h * 3600 < b; h++) { const o = Math.min(b, (h + 1) * 3600) - Math.max(a, h * 3600); if (o > 0) { H[h].mort += o; } }
  }
  const ouvert = fin - deb, heures = {};
  Object.keys(H).forEach(h => { const x = H[h]; heures[h] = { ouvert: r1(x.ouvert / 60), mort: r1(x.mort / 60), pct: x.ouvert > 0 ? r1(100 * x.mort / x.ouvert) : null, nb: x.nb, tk: x.tk }; });
  const r = { ferme: false, tickets: n0, isoles: n0 - n, debut: hm(deb), fin: hm(fin), ouvert: Math.round(ouvert / 60), nb: trous.length, mort: Math.round(mort / 60),
    pct: ouvert > 0 ? r1(100 * mort / ouvert) : null, plusLong: { min: r1(long[0] / 60), de: hm(long[1]), a: hm(long[2]) }, heures };
  if (detail) { r.ticks = u; r.trous = trous; r.ecartes = t.slice(0, i0).concat(t.slice(i1 + 1)); }
  return r;
}
/** tmMoyenne : la moyenne par jour ouvert ; la part de l'ouverture sur les minutes additionnées. */
function moyenne(M) {
  const m = M.filter(x => x && !x.ferme), n = m.length;
  if (!n) { return { jours: 0 }; }
  const s = k => m.reduce((a, x) => a + +x[k], 0);
  return { jours: n, nb: r1(s('nb') / n), mort: Math.round(s('mort') / n), ouvert: Math.round(s('ouvert') / n), tickets: Math.round(s('tickets') / n),
    pct: s('ouvert') > 0 ? r1(100 * s('mort') / s('ouvert')) : null, plusLong: r1(Math.max(...m.map(x => +x.plusLong.min))) };
}
/** ep_temps_morts : le jour, son habitude (les 4 mêmes jours d'avant), la semaine du lundi au dimanche, la semaine d'avant. */
function tempsMorts(date0, seuil0) {
  const date = /^\d{4}-\d{2}-\d{2}$/.test(date0 || '') && date0 <= AUJ ? date0 : AUJ;
  const seuil = SEUILS.includes(+seuil0) ? +seuil0 : 10;
  const lun = D.plus(date, 1 - (D.dow(date) || 7));
  const semaine = [0, 1, 2, 3, 4, 5, 6].map(i => D.plus(lun, i)), avant = semaine.map(d => D.plus(d, -7));
  const habitude = [1, 2, 3, 4].map(k => D.plus(date, -7 * k));
  const mes = (d, detail) => { const t = tickets(d); return t === null ? null : mesure(t, seuil, detail); };
  const etat = (d, m) => d > AUJ ? 'avenir' : (m === null ? 'nonlu' : (m.ferme ? 'ferme' : (d === AUJ ? 'encours' : 'lu')));
  const mJ = mes(date, true);
  const jours = semaine.map(d => {
    const m = d === date ? mJ : mes(d), l = { date: d, etat: etat(d, m) };
    if (m && !m.ferme) { const c = Object.assign({}, m); delete c.ticks; delete c.trous; delete c.ecartes; Object.assign(l, c); }
    return l;
  });
  const clos = L => L.map(d => d < AUJ ? mes(d) : null);
  return { shop: +D.SHOP, date, seuil, seuils: SEUILS, isole: ISOLE,
    jour: mJ === null ? null : Object.assign({ date, etat: etat(date, mJ) }, mJ),
    habitude: Object.assign({ dates: habitude }, moyenne(clos(habitude))),
    semaine: { du: semaine[0], au: semaine[6], jours, moyenne: moyenne(clos(semaine)) },
    semainePrecedente: { du: avant[0], au: avant[6], moyenne: moyenne(clos(avant)) },
    manquants: 0,
    source: 'liste des tickets du panel (/shops/{id}/transactions?date=), heure de chaque ticket ; tous les tickets, comptoir et pro' };
}

module.exports = function (m, p, q) {
  if (m === 'GET' && p === '/exploitation/temps-morts') { return tempsMorts(q.get('date'), q.get('seuil')); }
  return undefined;
};
module.exports.tempsMorts = tempsMorts;
