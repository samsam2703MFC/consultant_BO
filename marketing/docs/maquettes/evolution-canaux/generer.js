/* Maquette « Résultat › Évolution magasin » — ventes : Tout | Comptoir | B2B (13/11/2026).
 *
 * Demande : dans le graphique Évolution magasin, différencier en bascule le CA B2B et le CA
 * comptoir des magasins. On reprend l'onglet codé (evVals / evGraphe dans app.js,
 * svgEvolution / tplResultatEvolution dans templates.js) et on lui ajoute la bascule
 * « ventes : Tout | Comptoir | B2B » de Résultat › Analyse réseau, mêmes règles :
 *   B2B      = les tickets des clients pro à TVA valide (vpTvaValides) ;
 *   Comptoir = tout le reste (total − B2B), calculé dans le navigateur.
 *
 *   node docs/maquettes/evolution-canaux/generer.js     → tout.html, comptoir.html, b2b.html, b2b-clients.html
 *   node docs/maquettes/evolution-canaux/capturer.js    → les PNG
 *
 * Données : les totaux sont les vrais (../evolution-magasin/donnees.json, lus au panel le
 * 11/11/2026). LA PART B2B EST FICTIVE : le panel n'a pas été relu pour cette maquette ;
 * elle est tirée d'une part de base par magasin, d'une saison et d'un bruit fixe (graine).
 * Le décor : chrome.html, l'écran Résultat du BO rendu tel quel. */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const SRC = JSON.parse(fs.readFileSync(path.join(D, '..', 'evolution-magasin', 'donnees.json'), 'utf8'));
const CHROME = fs.readFileSync(path.join(D, 'chrome.html'), 'utf8');

/* ── la réponse de /ventes/evolution, avec le B2B de chaque mois (fictif) ──────────────────
 * Ce que l'endpoint rendrait : chaque mois porte `pro: {clients, ca, joursLus}` (les tickets
 * des clients pro à TVA valide, lus dans le relevé gravé `svP{shop}:{jour}`), son N-1 aussi. */
let graine = 20261113;
const alea = () => { graine = (graine * 1103515245 + 12345) % 2147483648; return graine / 2147483648; };
const BASE = { 2: [0.085, 47], 3: [0.11, 42], 5: [0.05, 39], 4: [0.03, 58] };   // Corbais, Gosselies, Sombreffe, Halle : part du CA, panier pro
const SAISON = [0, 1.0, 1.05, 1.1, 0.95, 1.05, 1.1, 0.55, 0.6, 1.15, 1.1, 1.15, 1.3];   // juillet-août : bureaux et écoles fermés
const parts = {};   // [shop][AAAA-MM] = [part, panier]
const partDe = (sid, k, rang) => {
  parts[sid] = parts[sid] || {};
  if (!parts[sid][k]) {
    const [b, p] = BASE[sid] || [0.06, 45];
    const mo = +k.slice(5, 7);
    const tendance = 1 + 0.004 * rang;                                          // le B2B se construit avec le temps
    parts[sid][k] = [b * SAISON[mo] * tendance * (0.86 + 0.28 * alea()), p * (0.9 + 0.2 * alea())];
  }
  return parts[sid][k];
};
const ilYa12 = k => (+k.slice(0, 4) - 1) + k.slice(4);
const avecPro = (o, part, panier) => {
  const ca = Math.round(o.ca * part * 100) / 100;
  return { clients: Math.max(0, Math.round(ca / panier)), ca, joursLus: o.jours };
};
const API = { aujourdhui: SRC.lu, du: null, magasins: [], source: 'panel (margin-heatmap, par jour) · B2B : relevé des tickets (clients pro à TVA valide) · N-1 = même mois un an plus tôt, arrêté au même jour servi' };
SRC.magasins.forEach(m => {
  const sid = +m.id;
  const mois = m.mois.map((x, i) => {
    const k = x.mois;
    const [part, panier] = partDe(sid, k, i);
    const o = { mois: k, an: +k.slice(0, 4), mo: +k.slice(5, 7), lab: x.lab, enCours: x.enCours,
      joursMois: new Date(+k.slice(0, 4), +k.slice(5, 7), 0).getDate(), joursServis: x.joursServis,
      clients: x.clients, ca: x.ca, jours: x.jours, source: x.source, pro: avecPro(x, part, panier), n1: null };
    if (x.n1) {
      const k1 = ilYa12(k), i1 = m.mois.findIndex(y => y.mois === k1);
      const [p1, pa1] = i1 >= 0 ? partDe(sid, k1, i1) : [part, panier];
      o.n1 = { clients: x.n1.clients, ca: x.n1.ca, jours: x.n1.jours, pro: avecPro(x.n1, p1, pa1) };
    }
    return o;
  });
  API.magasins.push({ id: m.id, nom: m.nom, premier: mois[0].mois, mois });
});
API.du = API.magasins.map(m => m.premier).sort()[0];

/* ── formats (ceux de app.js) ──────────────────────────────────────────────────────────── */
const fI = n => Math.round(n || 0).toLocaleString('fr-BE').replace(/ | /g, ' ');
const fK = n => Math.abs(n) >= 1e6 ? (n / 1e6).toFixed(2).replace('.', ',') + ' M€' : (Math.abs(n) >= 10000 ? (n / 1000).toFixed(1).replace('.', ',') + ' k€' : fI(n) + ' €');
const fE2 = n => n.toFixed(2).replace('.', ',') + ' €';
const fP1 = n => n.toFixed(1).replace('.', ',') + ' %';
const fD1 = n => n < 10 ? n.toFixed(1).replace('.', ',') : fI(n);     // 2,4 commandes pro par jour
const pc = (a, b) => b ? 100 * (a - b) / b : null;
const sg = v => v == null ? '—' : (v >= 0.5 ? '+' : (v <= -0.5 ? '−' : '±')) + Math.abs(Math.round(v)) + ' %';
const col = v => v == null ? '#B8B2A8' : (v >= 3 ? '#3f7a52' : (v <= -3 ? '#A32014' : '#7A5207'));
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const MC = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const ML = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const rrCourt = nom => { const m = String(nom || '').match(/-\s*([^-]+)$/); return m ? m[1].trim() : String(nom || ''); };
// La couleur de la courbe suit le canal : bordeaux pour Tout et Comptoir, bleu pour le B2B (le vert et le
// rouge sont déjà pris par le gagné / perdu face au N-1).
const COUL = { tout: '#8D1D2C', comptoir: '#8D1D2C', b2b: '#1f5f8b' };

/* ── evVals, la bascule « canal » en plus ──────────────────────────────────────────────── */
function evVals(S, D) {
  const quoi = S.evQuoi === 'ca' ? 'ca' : 'clients', mode = S.evMode === 'total' ? 'total' : 'jour';
  const canal = ['comptoir', 'b2b'].includes(S.evCanal) ? S.evCanal : 'tout';
  const out = { quoi, mode, canal, coul: COUL[canal],
    canaux: [['tout', 'Tout'], ['comptoir', 'Comptoir'], ['b2b', 'B2B']].map(o => ({ nom: o[1], on: o[0] === canal })),
    quois: [['clients', 'Trafic clients'], ['ca', 'Chiffre d’affaires']].map(o => ({ nom: o[1], on: o[0] === quoi })),
    modes: [['jour', 'Par jour ouvert'], ['total', 'Total']].map(o => ({ nom: o[1], on: o[0] === mode })) };
  const parJour = mode === 'jour', b2b = canal === 'b2b';
  // La valeur d'un mois (ou de son N-1) sur le canal choisi : le total, le B2B, ou le total moins le B2B.
  const lire = (o, q) => canal === 'tout' ? o[q] : (b2b ? o.pro[q] : o[q] - o.pro[q]);
  const qk = quoi === 'ca' ? 'ca' : 'clients';
  const g = x => lire(x, qk), g1 = x => lire(x.n1, qk);
  const fmt = quoi === 'ca' ? (parJour ? (n => fI(n) + ' €') : fK) : (b2b && parJour ? fD1 : fI);
  const fAxe = quoi === 'ca' ? (parJour ? (n => fI(n) + ' €') : (n => (n >= 10000 ? Math.round(n / 1000) + ' k€' : fI(n) + ' €'))) : (b2b ? fD1 : fI);
  const NOMQ = { tout: ['clients', 'CA'], comptoir: ['clients au comptoir', 'CA comptoir'], b2b: ['commandes pro', 'CA B2B'] }[canal];
  const unite = quoi === 'ca' ? NOMQ[1] + (parJour ? ' par jour ouvert' : ' du mois')
    : NOMQ[0] + (parJour ? ' par jour ouvert' : ' du mois') + ' (tickets)';
  const prep = x => {
    const ref = x.enCours ? x.joursServis : x.joursMois;
    const partiel = !x.enCours && x.jours < 0.75 * x.joursMois;
    const n1Partiel = !!(x.n1 && x.n1.jours < 0.75 * ref);
    return Object.assign({}, x, { partiel, n1Ok: !!(x.n1 && !n1Partiel && !partiel), n1Court: !!(x.n1 && !n1Partiel && !partiel && x.n1.jours < x.jours - 2) });
  };
  const v = x => parJour ? (x.jours ? g(x) / x.jours : null) : g(x);
  const v1 = x => !x.n1Ok ? null : (parJour ? (x.n1.jours ? g1(x) / x.n1.jours : null) : g1(x));
  const somme = (L, f) => L.reduce((t, x) => t + f(x), 0);
  const ecartListe = L => {
    if (!L.length || L.some(x => !x.n1Ok)) { return null; }
    const n = somme(L, g), n1 = somme(L, g1);
    if (!parJour) { return { e: pc(n, n1), n, n1 }; }
    const j = somme(L, x => x.jours), j1 = somme(L, x => x.n1.jours);
    return { e: pc(n / j, n1 / j1), n: n / j, n1: n1 / j1 };
  };
  const lab = x => MC[x.mo] + ' ' + String(x.an).slice(2);
  const fin = String(D.aujourdhui || '').slice(0, 7), axe = [];
  for (let k = D.du; k && k <= fin && axe.length < 240;) {
    axe.push(k); const y = +k.slice(0, 4), mo = +k.slice(5, 7);
    k = mo === 12 ? (y + 1) + '-01' : y + '-' + String(mo + 1).padStart(2, '0');
  }
  const axeG = parJour ? axe : axe.filter(k => k !== fin);
  // La part B2B du CA sur une liste de mois (et sur leurs N-1).
  const partPro = L => { const t = somme(L, x => x.ca); return t ? 100 * somme(L, x => x.pro.ca) / t : null; };
  const partPro1 = L => { const t = somme(L, x => x.n1.ca); return t ? 100 * somme(L, x => x.n1.pro.ca) / t : null; };
  const reseau12 = [];
  out.magasins = D.magasins.map(m => {
    const M = m.mois.map(prep);
    const clos = M.filter(x => !x.enCours && !x.partiel);
    const cur = M.find(x => x.enCours) || null;
    const prem = M[0];
    const premN1 = M.find(x => x.n1Ok) || null;
    const nom = rrCourt(m.nom);
    const d12 = clos.slice(-12), avec12 = d12.filter(x => x.n1Ok);
    reseau12.push(...d12);
    const e12 = ecartListe(avec12);
    const t3 = clos.slice(-3), e3 = t3.length === 3 ? ecartListe(t3) : null;
    const avecN1 = clos.filter(x => x.n1Ok), dessus = avecN1.filter(x => v(x) >= v1(x));
    const p12 = partPro(d12), p12n1 = avec12.length ? partPro1(avec12) : null;
    const tuiles = [
      e12 ? { k: (avec12.length === 12 ? '12 mois' : avec12.length + ' mois') + ' vs N-1', v: sg(e12.e), s: fmt(e12.n) + ' contre ' + fmt(e12.n1) + (parJour ? ' par jour' : '') + ' · ' + lab(avec12[0]) + ' → ' + lab(avec12[avec12.length - 1]), cls: e12.e >= 0 ? 'good' : 'bad' }
        : { k: 'Vs N-1', v: '—', s: 'pas encore de N-1 — il arrive en ' + ML[prem.mo] + ' ' + (prem.an + 1) },
      e3 ? { k: '3 derniers mois vs N-1', v: sg(e3.e), s: MC[t3[0].mo] + ' → ' + MC[t3[2].mo] + ' : ' + fmt(e3.n) + ' contre ' + fmt(e3.n1), cls: e3.e >= 0 ? 'good' : 'bad' }
        : (() => { const a = clos.slice(-3), b = clos.slice(-6, -3); const va = parJour ? somme(a, g) / Math.max(1, somme(a, x => x.jours)) : somme(a, g), vb = parJour ? somme(b, g) / Math.max(1, somme(b, x => x.jours)) : somme(b, g);
          return { k: '3 derniers mois', v: a.length ? fmt(va) : '—', s: b.length === 3 ? '<b style="color:' + col(pc(va, vb)) + '">' + sg(pc(va, vb)) + '</b> face aux 3 d’avant' : '' }; })(),
      // En B2B, la 3e tuile dit la part du CA, face au plafond des visites (b2bMax, 40 %).
      b2b ? { k: 'Part du CA · ' + d12.length + ' mois', v: p12 == null ? '—' : fP1(p12),
          s: (p12n1 != null ? 'contre ' + fP1(p12n1) + ' un an plus tôt · ' : '') + 'plafond 40 %', cls: '' }
        : (avecN1.length ? { k: 'Mois au-dessus du N-1', v: dessus.length + ' / ' + avecN1.length, s: dessus.length ? (dessus.length > 4 ? '… ' : '') + dessus.slice(-4).map(lab).join(', ') : 'aucun', cls: dessus.length * 2 >= avecN1.length ? 'good' : 'bad' }
          : { k: 'Mois au-dessus du N-1', v: '—', s: 'pas encore de N-1' }),
      cur ? (() => { const e = cur.n1Ok ? pc(v(cur), v1(cur)) : null;
        return { k: MC[cur.mo] + ' en cours · ' + cur.jours + ' j', v: fmt(v(cur)),
          s: e == null ? (cur.n1 ? 'N-1 incomplet' : 'pas de N-1 : premier ' + ML[cur.mo]) : '<b style="color:' + col(e) + '">' + sg(e) + '</b> face aux mêmes ' + cur.joursServis + ' jours de ' + ML[cur.mo] + ' ' + (cur.an - 1) + ' (' + fmt(v1(cur)) + ')',
          cls: e == null ? '' : (e >= 0 ? 'good' : 'bad') }; })()
        : { k: 'Mois en cours', v: '—', s: 'pas de vente lue ce mois-ci' }
    ];
    const best = clos.length ? clos.reduce((a, b) => v(b) > v(a) ? b : a) : null;
    const totC = somme(M, x => lire(x, 'clients')), totCa = somme(M, x => lire(x, 'ca')), totJ = somme(M, x => x.jours);
    const NOMC = b2b ? 'commandes pro' : 'clients';
    const faits = [
      'depuis ' + ML[prem.mo] + ' ' + prem.an + (prem.partiel ? ' (premières ventes, ' + prem.jours + ' j)' : ''),
      clos.length + ' mois pleins',
      parJour ? (b2b ? fD1(totC / Math.max(1, totJ)) : fI(totC / Math.max(1, totJ))) + ' ' + NOMC + ' par jour ouvert' : fI(totC) + ' ' + NOMC,
      parJour ? fI(totCa / Math.max(1, totJ)) + ' € par jour ouvert' : fK(totCa) + ' de CA',
      (b2b ? 'panier pro ' : (canal === 'comptoir' ? 'panier comptoir ' : 'panier ')) + fE2(totCa / Math.max(1, totC)),
      canal === 'tout' && p12 != null ? 'dont B2B ' + fP1(p12) + ' sur 12 mois' : '',
      canal === 'comptoir' && p12 != null ? fP1(100 - p12) + ' du CA sur 12 mois' : '',
      best ? 'meilleur mois : ' + ML[best.mo] + ' ' + best.an + ' (' + fmt(v(best)) + ')' : ''
    ].filter(Boolean);
    const svg = evGraphe(M, axeG, v, v1, fmt, fAxe, parJour, out.coul);
    return { id: m.id, nom, faits, tuiles, svg,
      avecN1: !!premN1, n1Txt: premN1 ? 'N-1 : le même mois un an plus tôt (pointillé), dès ' + ML[premN1.mo] + ' ' + premN1.an
        : 'pas encore de N-1 : premières ventes en ' + ML[prem.mo] + ' ' + prem.an + ' — la ligne N-1 apparaîtra en ' + ML[prem.mo] + ' ' + (prem.an + 1),
      ouverture: M.some(x => x.partiel), etoile: !parJour && M.some(x => x.n1Court) };
  });
  out.unite = unite;
  out.chipTxt = 'sous chaque mois : l’écart au N-1, en %' + (parJour ? ' — par jour ouvert : le mois ÷ ses jours de vente, le N-1 ÷ les siens' : '');
  const pR = partPro(reseau12);
  out.canalInfo = {
    tout: 'Tout : tous les tickets de caisse · dont B2B ' + fP1(pR) + ' du CA du réseau sur 12 mois',
    comptoir: 'Comptoir : tous les tickets sauf ceux des clients pro à TVA valide · ' + fP1(100 - pR) + ' du CA du réseau sur 12 mois · les pro sans TVA valide restent au comptoir',
    b2b: 'B2B : les tickets des clients pro à TVA valide · ' + fP1(pR) + ' du CA du réseau sur 12 mois · une commande pro = un ticket'
  }[canal];
  // Trimestre par trimestre, sur le canal choisi.
  out.trimestres = [];
  D.magasins.forEach(m => {
    const M = m.mois.map(prep);
    const tri = x => x.an * 10 + Math.ceil(x.mo / 3);
    [...new Set(M.map(tri))].forEach((t, i) => {
      const ms = M.filter(x => tri(x) === t), cmp = ms.filter(x => x.n1Ok);
      const C = x => lire(x, 'clients'), CA = x => lire(x, 'ca'), C1 = x => lire(x.n1, 'clients'), CA1 = x => lire(x.n1, 'ca');
      const c = somme(ms, C), ca = somme(ms, CA), j = somme(ms, x => x.jours);
      const cC = somme(cmp, C), caC = somme(cmp, CA), jC = somme(cmp, x => x.jours);
      const c1 = somme(cmp, C1), ca1 = somme(cmp, CA1), j1 = somme(cmp, x => x.n1.jours);
      const ec = c1 ? (parJour ? pc(cC / jC, c1 / j1) : pc(cC, c1)) : null, eca = ca1 ? (parJour ? pc(caC / jC, ca1 / j1) : pc(caC, ca1)) : null;
      const ep = c1 ? pc(caC / cC, ca1 / c1) : null;
      const cur = ms.find(x => x.enCours);
      const dernier = cmp[cmp.length - 1];
      const pp = partPro(ms);
      out.trimestres.push({ mag: i === 0 ? rrCourt(m.nom) : '', premier: i === 0, nouvelAn: i > 0 && t % 10 === 1, enCours: !!cur,
        lab: 'T' + (t % 10) + ' ' + Math.floor(t / 10),
        per: MC[ms[0].mo] + ' → ' + (cur ? cur.jours + ' ' + MC[cur.mo] : MC[ms[ms.length - 1].mo]) + (ms.some(x => x.partiel) ? ' · ouverture' : ''),
        clients: parJour ? (b2b ? fD1(c / Math.max(1, j)) : fI(c / Math.max(1, j))) : fI(c), ca: parJour ? fI(ca / Math.max(1, j)) + ' €' : fK(ca), panier: c ? fE2(ca / c) : '—', jours: fI(j),
        part: pp == null ? '—' : fP1(pp),
        ec: ec == null ? '' : sg(ec), ecCoul: col(ec), eca: eca == null ? '' : sg(eca), ecaCoul: col(eca), ep: ep == null ? '' : sg(ep), epCoul: col(ep),
        sansN1: !cmp.length,
        cmp: !cmp.length ? '' : (cmp.length === ms.length ? 'tout le trimestre' + (cur ? ' à date' : '')
          : cmp.length + ' mois : ' + MC[cmp[0].mo] + (cmp.length > 1 ? ' → ' + (dernier.enCours ? dernier.jours + ' ' : '') + MC[dernier.mo] : '')) });
    });
  });
  out.colClients = (b2b ? 'Commandes pro' : 'Clients') + (parJour ? ' / jour' : '');
  out.colCa = (b2b ? 'CA B2B' : (canal === 'comptoir' ? 'CA comptoir' : 'CA')) + (parJour ? ' / jour' : '');
  out.colPanier = b2b ? 'Panier pro' : 'Panier';
  out.colPart = canal === 'tout' ? 'Part B2B' : (canal === 'b2b' ? 'Part du CA' : '');
  out.sousTitre = D.magasins.length + ' magasins · depuis ' + ML[+D.du.slice(5, 7)] + ' ' + D.du.slice(0, 4) + ' · mois par mois, face au N-1';
  out.source = D.source;
  return out;
}

/* ── evGraphe, tel quel (app.js), la couleur du canal en plus ─────────────────────────────── */
function evGraphe(M, axe, v, v1, fmt, fAxe, parJour, coul) {
  const W = 880, H = 262, ml = 54, mr = 18, mt = 26, mb = 42;
  const parMois = {}; M.forEach(x => { parMois[x.mois] = x; });
  const L = axe.map(k => parMois[k] || { vide: true, mois: k, an: +k.slice(0, 4), mo: +k.slice(5, 7) });
  const N = L.length;
  if (!N || L.every(x => x.vide)) { return null; }
  const pas = (W - ml - mr) / N;
  const avecChips = L.some(x => x.n1Ok) && pas >= 22;
  const n = L.map(x => x.vide ? null : v(x)), n1 = L.map(x => x.vide ? null : v1(x));
  const surCourbe = L.map(x => !x.partiel);
  const vals = [];
  L.forEach((x, i) => { if (surCourbe[i] && n[i] != null) { vals.push(n[i]); } if (n1[i] != null) { vals.push(n1[i]); } });
  if (!vals.length) { L.forEach((x, i) => { if (n[i] != null) { vals.push(n[i]); } }); }
  const vmin = Math.min(...vals), vmax = Math.max(...vals), et = (vmax - vmin) || Math.abs(vmax) || 1;
  let lo = Math.max(0, vmin - et * 0.25), hi = vmax + et * 0.18;
  const r = (hi - lo) / 4, p10 = Math.pow(10, Math.floor(Math.log10(r))), st = [1, 2, 2.5, 5, 10].map(k => k * p10).find(k => k >= r);
  lo = Math.floor(lo / st) * st; hi = Math.ceil(hi / st) * st;
  const X = i => ml + (i + 0.5) * pas;
  const Y = val => mt + (hi - Math.max(lo, val)) / (hi - lo || 1) * (H - mt - mb);
  const f1 = x => x.toFixed(1);
  const out = { W, H, ml, mr, mt, coul, hb: H - mb + 15, hc: H - mb + 32, grad: [], seps: [], aires: [], segs1: [], segs: [], cour: null, pts1: [], pts: [], labels: [], notes: [], cats: [] };
  for (let y = lo; y <= hi + 1e-6; y += st) { out.grad.push({ y: f1(Y(y)), t: fAxe(y), base: y === lo }); }
  const iPrem = L.findIndex(x => !x.vide);
  if (iPrem > 0) { const w = iPrem * pas; out.avant = { x: ml, w: f1(w), y: mt - 2, h: f1(H - mb - mt + 2), tx: f1(ml + w / 2), ty: f1((mt + H - mb) / 2), t: w >= 110 ? 'pas encore ouvert' : '' }; }
  L.forEach((x, i) => { if (i === 0 || x.mo === 1) { const xs = i === 0 ? ml : (X(i - 1) + X(i)) / 2; out.seps.push({ x: f1(xs), xt: f1(xs + 5), t: String(x.an), trait: i > 0, y1: mt - 14, y2: f1(H - mb) }); } });
  const okN = i => surCourbe[i] && n[i] != null && !(L[i].enCours && !parJour);
  for (let i = 0; i < N - 1; i++) {
    if (!okN(i) || !okN(i + 1) || n1[i] == null || n1[i + 1] == null) { continue; }
    const d0 = n[i] - n1[i], d1 = n[i + 1] - n1[i + 1];
    const poly = (x0, x1, a0, a1, b0, b1, pos) => ({ pts: [f1(x0) + ',' + f1(Y(a0)), f1(x1) + ',' + f1(Y(a1)), f1(x1) + ',' + f1(Y(b1)), f1(x0) + ',' + f1(Y(b0))].join(' '), pos });
    if (d0 * d1 >= 0) { out.aires.push(poly(X(i), X(i + 1), n[i], n[i + 1], n1[i], n1[i + 1], d0 + d1 >= 0)); }
    else {
      const t = d0 / (d0 - d1), xm = X(i) + t * pas, vm = n[i] + t * (n[i + 1] - n[i]);
      out.aires.push(poly(X(i), xm, n[i], vm, n1[i], vm, d0 >= 0), poly(xm, X(i + 1), vm, n[i + 1], vm, n1[i + 1], d1 >= 0));
    }
  }
  const coupe = (arr, ok) => { const segs = []; let s = []; arr.forEach((val, i) => { if (ok(i)) { s.push(f1(X(i)) + ',' + f1(Y(val))); } else { if (s.length > 1) { segs.push(s.join(' ')); } s = []; } }); if (s.length > 1) { segs.push(s.join(' ')); } return segs; };
  out.segs1 = coupe(n1, i => n1[i] != null);
  out.segs = coupe(n, i => okN(i) && !L[i].enCours);
  const ic = L.findIndex(x => x.enCours);
  if (ic > 0 && okN(ic) && okN(ic - 1)) { out.cour = f1(X(ic - 1)) + ',' + f1(Y(n[ic - 1])) + ' ' + f1(X(ic)) + ',' + f1(Y(n[ic])); }
  const titre = x => MC[x.mo] + ' ' + x.an;
  L.forEach((x, i) => {
    if (n1[i] != null) { out.pts1.push({ x: f1(X(i)), y: f1(Y(n1[i])), titre: titre(x) + ' · N-1 : ' + fmt(n1[i]) }); }
    if (n[i] == null) { return; }
    const vide = x.partiel || x.enCours;
    out.pts.push({ x: f1(X(i)), y: f1(Y(n[i])), vide, titre: titre(x) + ' · ' + fmt(n[i]) });
    if (x.partiel) { out.notes.push({ x: f1(X(i) + 7), y: f1(Y(n[i]) - 6), t: fmt(n[i]) + ' · ' + x.jours + ' j' }); }
  });
  const idx = L.map((x, i) => i).filter(i => okN(i) && !L[i].enCours);
  if (idx.length) {
    const mx = idx.reduce((a, b) => n[b] > n[a] ? b : a), mn = idx.reduce((a, b) => n[b] < n[a] ? b : a);
    [...new Set([mx, mn, idx[idx.length - 1]])].forEach(i => { const dessous = n1[i] != null ? n[i] < n1[i] : i === mn;
      out.labels.push({ x: f1(X(i)), y: f1(Y(n[i]) + (dessous ? 15 : -8)), t: fmt(n[i]) }); });
  }
  const pasLab = pas >= 24 ? 1 : (pas >= 13 ? 2 : 3);
  L.forEach((x, i) => {
    const e = x.n1Ok && n[i] != null && n1[i] ? 100 * (n[i] - n1[i]) / n1[i] : null;
    out.cats.push({ x: f1(X(i)), lab: (i % pasLab === 0 || x.mo === 1) ? MC[x.mo] : '', cur: !!x.enCours,
      chip: avecChips && e != null ? (e >= 0.5 ? '+' : (e <= -0.5 ? '−' : '')) + Math.abs(Math.round(e)) + (x.n1Court && !parJour ? '*' : '') : '',
      chipCoul: e == null ? '' : (e >= 3 ? '#3f7a52' : (e <= -3 ? '#A32014' : '#7A5207')) });
  });
  out.avecChips = avecChips;
  return out;
}

/* ── svgEvolution, tel quel (templates.js), #8D1D2C remplacé par la couleur du canal ───────── */
function svgEvolution(S) {
  if (!S) { return ''; }
  const ft = 'font-family:var(--font-ui)', C = S.coul;
  return `<svg viewBox="0 0 ${S.W} ${S.H}" preserveAspectRatio="xMidYMid meet" class="rm-s6c">
    ${S.avant ? `<rect x="${S.avant.x}" y="${S.avant.y}" width="${S.avant.w}" height="${S.avant.h}" fill="rgba(34,34,34,.035)"/>${S.avant.t ? `<text x="${S.avant.tx}" y="${S.avant.ty}" font-size="10.5" text-anchor="middle" fill="#a59d92" style="${ft}">${esc(S.avant.t)}</text>` : ''}` : ''}
    ${S.grad.map(t => `<line x1="${S.ml}" x2="${S.W - S.mr}" y1="${t.y}" y2="${t.y}" stroke="rgba(34,34,34,${t.base ? '.16' : '.07'})"/><text x="${S.ml - 8}" y="${(+t.y + 3.5).toFixed(1)}" font-size="10" text-anchor="end" fill="#8a8378" style="${ft}">${esc(t.t)}</text>`).join('')}
    ${S.seps.map(s => `${s.trait ? `<line x1="${s.x}" x2="${s.x}" y1="${s.y1}" y2="${s.y2}" stroke="rgba(34,34,34,.22)" stroke-dasharray="2 3"/>` : ''}<text x="${s.xt}" y="${S.mt - 7}" font-size="11" font-weight="600" fill="#1c1a17" style="${ft}">${esc(s.t)}</text>`).join('')}
    ${S.aires.map(a => `<polygon points="${a.pts}" fill="${a.pos ? 'rgba(63,122,82,.16)' : 'rgba(163,32,20,.13)'}"/>`).join('')}
    ${S.segs1.map(p => `<polyline points="${p}" fill="none" stroke="#B8B2A8" stroke-width="2" stroke-dasharray="5 4" stroke-linejoin="round"/>`).join('')}
    ${S.segs.map(p => `<polyline points="${p}" fill="none" stroke="${C}" stroke-width="2.6" stroke-linejoin="round" stroke-linecap="round"/>`).join('')}
    ${S.cour ? `<polyline points="${S.cour}" fill="none" stroke="${C}" stroke-width="2" stroke-dasharray="3 3"/>` : ''}
    ${S.pts1.map(p => `<circle cx="${p.x}" cy="${p.y}" r="2.6" fill="#fff" stroke="#B8B2A8" stroke-width="1.6"><title>${esc(p.titre)}</title></circle>`).join('')}
    ${S.pts.map(p => `<circle cx="${p.x}" cy="${p.y}" r="${p.vide ? 3.6 : 3.2}" fill="${p.vide ? '#fff' : C}" stroke="${C}" stroke-width="${p.vide ? 1.6 : 0}"><title>${esc(p.titre)}</title></circle>`).join('')}
    ${S.labels.map(l => `<text x="${l.x}" y="${l.y}" font-size="10.5" font-weight="600" text-anchor="middle" fill="${C}" style="${ft};paint-order:stroke;stroke:#fff;stroke-width:3px">${esc(l.t)}</text>`).join('')}
    ${S.notes.map(l => `<text x="${l.x}" y="${l.y}" font-size="9.5" text-anchor="start" fill="#7a736a" style="${ft};paint-order:stroke;stroke:#fff;stroke-width:3px">${esc(l.t)}</text>`).join('')}
    ${S.cats.map(c => `${c.lab ? `<text x="${c.x}" y="${S.hb}" font-size="10" font-weight="${c.cur ? 600 : 400}" text-anchor="middle" fill="${c.cur ? C : '#3b3731'}" style="${ft}">${esc(c.lab)}</text>` : ''}${c.chip ? `<text x="${c.x}" y="${S.hc}" font-size="9.5" font-weight="600" text-anchor="middle" fill="${c.chipCoul}" style="${ft}">${esc(c.chip)}</text>` : ''}`).join('')}
    ${S.avecChips ? `<text x="${S.ml - 8}" y="${S.hc}" font-size="9" text-anchor="end" fill="#8a8378" style="${ft}">vs N-1</text>` : ''}
  </svg>`;
}

/* ── tplResultatEvolution, la bascule « ventes » et sa ligne en plus ─────────────────────── */
function tplResultatEvolution(g) {
  const cap = 'font-size:10.5px;font-weight:400;letter-spacing:.05em;text-transform:uppercase;color:var(--color-text-muted)';
  const seg = L => `<div class="rr-ong">${(L || []).map(o => `<button class="${o.on ? 'on' : ''}">${esc(o.nom)}</button>`).join('')}</div>`;
  const NOUVEAU = 'box-shadow:0 0 0 2px #1f5f8b33;border-radius:9px';
  const tete = `<div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap"><span style="font-family:var(--font-display);font-size:18px">Évolution magasin</span><span style="font-size:11.5px;color:var(--color-text-muted)">${esc(g.sousTitre)}</span><span style="margin-left:auto;display:flex;gap:10px;align-items:center;flex-wrap:wrap"><span style="font-size:11px;color:var(--color-text-muted)">ventes</span><span style="${NOUVEAU}">${seg(g.canaux)}</span>${seg(g.quois)}${seg(g.modes)}</span></div>`;
  const legTrait = (coul, dash, w) => `<svg width="20" height="9" style="vertical-align:-1px;margin-right:4px" aria-hidden="true"><line x1="0" x2="20" y1="4.5" y2="4.5" stroke="${coul}" stroke-width="${w || 2}"${dash ? ` stroke-dasharray="${dash}"` : ''}/></svg>`;
  const tag = g.canal === 'tout' ? '' : `<span style="font-size:10.5px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:#fff;background:${g.coul};border-radius:5px;padding:2px 7px;align-self:center">${g.canal === 'b2b' ? 'B2B' : 'Comptoir'}</span>`;
  const carte = m => `
    <div class="rm-bloc ev-mag">
      <div class="ev-tete"><b>${esc(m.nom)}</b>${tag}<span>${m.faits.map(esc).join(' · ')}</span></div>
      <div class="rm-card"><div class="rm-s6"><div>${svgEvolution(m.svg)}
        <div class="rm-leg">
          <span><i style="background:${g.coul};border-radius:50%"></i>${esc(g.unite)}</span>
          <span>${m.avecN1 ? legTrait('#B8B2A8', '4 3') : ''}${esc(m.n1Txt)}</span>
          ${m.avecN1 ? `<span><i style="background:rgba(63,122,82,.35)"></i><i style="background:rgba(163,32,20,.3);margin-left:-2px"></i>gagné / perdu face au N-1</span>` : ''}
          ${m.ouverture ? `<span><i style="background:#fff;border:1.6px solid ${g.coul};border-radius:50%;width:6px;height:6px"></i>mois d’ouverture, incomplet : hors courbe</span>` : ''}
          ${g.mode === 'jour' ? `<span>${legTrait(g.coul, '3 3')}le mois en cours (cercle vide), à ses jours vendus</span>` : ''}
          ${m.etoile ? `<span>* le N-1 a eu des jours fermés — « Par jour ouvert » les neutralise</span>` : ''}
        </div></div>
        <div class="rm-s6t ev-t4">${m.tuiles.map(t => `<div class="t ${t.cls || ''}"><div class="k">${esc(t.k)}</div><div class="v">${esc(t.v)}</div><div class="s">${t.s || ''}</div></div>`).join('')}</div>
      </div></div>
    </div>`;
  const pill = (t, coul) => t ? `<span class="rr-pill" style="background:${coul}">${esc(t)}</span>` : '—';
  const avecPart = !!g.colPart;
  const table = `<div class="rm-bloc"><div style="${cap};margin-bottom:8px;display:flex;justify-content:space-between;gap:12px"><span>Trimestre par trimestre${g.canal === 'tout' ? '' : ' · ' + (g.canal === 'b2b' ? 'B2B' : 'comptoir')}</span><span style="font-weight:400;text-transform:none;letter-spacing:0">chaque trimestre face au même trimestre un an plus tôt · seuls les mois qui ont un N-1 comptent dans l’écart${g.mode === 'jour' ? ' · par jour ouvert' : ''}</span></div><div class="rm-card">
    <table class="rr-tb ev-an"><tr><th>Magasin</th><th>Trimestre</th><th>${esc(g.colClients)}</th><th>vs N-1</th><th>${esc(g.colCa)}</th><th>vs N-1</th><th>${esc(g.colPanier)}</th><th>vs N-1</th>${avecPart ? `<th>${esc(g.colPart)}</th>` : ''}<th>Jours vendus</th><th>Comparé sur</th></tr>
    ${g.trimestres.map(a => `<tr class="${a.premier ? 'ev-prem' : (a.nouvelAn ? 'ev-an1' : '')}${a.enCours ? ' ev-cours' : ''}"><td>${esc(a.mag)}</td><td><b style="font-weight:400">${esc(a.lab)}</b> <span style="color:var(--color-text-muted)">${esc(a.per)}</span></td><td>${esc(a.clients)}</td><td>${a.sansN1 ? '<span style="color:var(--color-text-muted)">pas de N-1</span>' : pill(a.ec, a.ecCoul)}</td><td>${esc(a.ca)}</td><td>${a.sansN1 ? '' : pill(a.eca, a.ecaCoul)}</td><td>${esc(a.panier)}</td><td>${a.sansN1 ? '' : pill(a.ep, a.epCoul)}</td>${avecPart ? `<td>${esc(a.part)}</td>` : ''}<td>${esc(a.jours)}</td><td style="color:var(--color-text-muted)">${esc(a.cmp || '—')}</td></tr>`).join('')}
    </table></div></div>`;
  const corps = `<div style="${cap};font-size:10px;margin:-6px 0 -6px;text-transform:none;letter-spacing:0">${esc(g.chipTxt)}</div>
    <div style="font-size:11px;color:var(--color-text-muted);margin:-2px 0 -4px;display:flex;align-items:center;gap:6px"><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:${g.coul}"></i>${esc(g.canalInfo)}</div>
    ${g.magasins.map(carte).join('')}
    ${table}
    <div style="font-size:10.5px;color:var(--color-text-muted)">Source : ${esc(g.source)}.</div>`;
  return `<div class="rr" style="position:relative;background:var(--color-background-secondary);border:1px solid var(--color-border-tertiary);border-radius:12px;padding:14px 16px 18px;display:flex;flex-direction:column;gap:14px">
    <span style="position:absolute;top:-11px;right:14px;background:#2b2724;color:#fff;font:600 10.5px var(--font-ui);letter-spacing:.08em;padding:4px 10px;border-radius:12px">MAQUETTE · PART B2B FICTIVE</span>${tete}${corps}</div>`;
}

/* ── les pages ─────────────────────────────────────────────────────────────────────────── */
const PAGES = [
  ['tout', { evCanal: 'tout', evQuoi: 'ca', evMode: 'jour' }],
  ['comptoir', { evCanal: 'comptoir', evQuoi: 'ca', evMode: 'jour' }],
  ['b2b', { evCanal: 'b2b', evQuoi: 'ca', evMode: 'jour' }],
  ['b2b-clients', { evCanal: 'b2b', evQuoi: 'clients', evMode: 'total' }]
];
PAGES.forEach(([nom, S]) => {
  const html = tplResultatEvolution(evVals(S, API));
  fs.writeFileSync(path.join(D, nom + '.html'), CHROME.replace('<div id="mq-contenu"></div>', `<div id="mq-contenu">${html}</div>`)
    .replace('Un magasin, depuis que le panel a ses ventes : les clients et le chiffre d’affaires, période après période, face au N-1.',
      'Chaque magasin depuis sa première vente : le trafic clients et le chiffre d’affaires, mois après mois, face au N-1 — en total ou par jour ouvert, tout, comptoir ou B2B.')
    .replace('</head>', '<style>/* capture pleine page : le corps défile avec la fenêtre */.workspace{height:auto!important;overflow:visible!important;min-height:100vh}.app__body{overflow:visible!important}/* la colonne « part » en plus : pas de retour à la ligne */.rr-tb.ev-an td,.rr-tb.ev-an th{white-space:nowrap}.rr-tb.ev-an td:last-child{white-space:normal}</style></head>'));
  console.log(nom + '.html');
});
fs.writeFileSync(path.join(D, 'donnees-api.json'), JSON.stringify(API, null, 1));
