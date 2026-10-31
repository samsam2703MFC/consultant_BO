/* Maquettes « Vérification » (demande du 31/10/2026).
 *
 * Une rubrique nouvelle du cockpit : reprendre les stocks, les achats et les ventes des franchisés,
 * les repasser par les recettes, et dire si tout s'explique. Quatre onglets et une bascule :
 *   a.html        Audit des flux — le réseau, une semaine (entré · produit · vendu · poubelle · invendu, en %)
 *   a-jour.html   Audit des flux — un magasin, un jour, produit par produit (en pièces et en %)
 *   b.html        Audit des flux — un magasin, un mois, jour par jour avec le total de chaque semaine
 *   c.html        Matières ↔ recettes — entre deux inventaires : stock + entré − stock = consommé réel, face au théorique
 *   d.html        Chiffre d'affaires — caisse, P&L, base des redevances, CA attendu par la matière
 *   g-matieres.html  Le même tableau en matières premières : l'entrée = les commandes entrantes validées à la réception
 *   g.html        Fuites par magasin — un tableau par magasin, même structure : stock début + entrées − stock fin = sorti,
 *                 sorti − ventes − poubelle = delta, par catégorie dépliable en produits ; la matrice catégories × magasins
 *   e.html        La bascule allumée : les recettes douteuses ou manquantes, classées par CA concerné
 *   f.html        Évolution — ce que la base quotidienne (ceo_verif_jour) montre semaine après semaine
 *
 * CHIFFRES D'ILLUSTRATION : la maquette a été faite sans accès au panel (pas d'identifiants dans
 * l'environnement de travail). Les magasins, les produits, les catégories et les matières sont ceux du
 * réseau ; les quantités et les montants sont générés (PRNG déterministe, mulberry32) pour être
 * cohérents d'un écran à l'autre : la semaine 43 de a.html est la somme des jours de b.html, et
 * f.html la suite des mêmes semaines.
 *
 *   node docs/maquettes/verification/generer.js
 *   npx http-server . -p 8099 -c-1   (depuis la racine du dépôt) puis node docs/maquettes/verification/capturer.js */
const fs = require('fs');
const path = require('path');
const D = __dirname;

/* ---------- outils ---------- */
const nf = (n, d = 0) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
const eur = n => nf(Math.round(n)) + ' €';
const pc = (n, d = 1) => (n == null || !isFinite(n)) ? '—' : nf(n, d) + ' %';
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;');
function mulberry32(a) { return function () { a |= 0; a = a + 0x6D2B79F5 | 0; let t = Math.imul(a ^ a >>> 15, 1 | a); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; }; }
const R = mulberry32(20261031);
const bruit = a => 1 + (R() * 2 - 1) * a;
const JOURS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
const JOURS_L = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
const ymd = d => d.toISOString().slice(0, 10);
const jjmm = s => s.slice(8, 10) + '/' + s.slice(5, 7);
const semaineIso = s => { const d = new Date(s + 'T12:00:00Z'); const j = (d.getUTCDay() + 6) % 7; d.setUTCDate(d.getUTCDate() - j + 3); const a = new Date(Date.UTC(d.getUTCFullYear(), 0, 4)); return 1 + Math.round(((d - a) / 864e5 - 3 + ((a.getUTCDay() + 6) % 7)) / 7); };

/* ---------- les magasins ---------- */
const SHOPS = [
  { id: 4, court: 'Halle', nom: 'Atelier by - Halle', base: 2050, p: [5.3, 5.1], i: 6.3, n: [4.3, 2.2], r: [0.95, 0.961], rec: [95.6, 97.8], fc: 0.283, dim: { n: 0.4, p: 0.3 } },
  { id: 3, court: 'Gosselies', nom: 'Atelier by Max & Sandra - Gosselies', base: 1790, p: [4.1, 4.0], i: 5.9, n: [7.4, 9.6], r: [0.86, 0.843], rec: [90.6, 91.2], fc: 0.291, dim: { n: 4.2, p: 0.2 } },
  { id: 2, court: 'Corbais', nom: 'Atelier by Berlo - Corbais', base: 1320, p: [12.6, 9.4], i: 4.6, n: [3.7, 3.0], r: [0.965, 0.972], rec: [93.1, 95.4], fc: 0.296, dim: { n: 0.9, p: 4.2 } },
  { id: 5, court: 'Sombreffe', nom: 'Atelier by Harmonie - Sombreffe', base: 1100, p: [3.3, 3.2], i: 7.1, n: [7.4, 5.6], r: [0.80, 0.838], rec: [80.4, 88.6], fc: 0.302, dim: { n: 0.5, p: 0.2 } },
];
const PROFIL = [0.78, 0.92, 0.88, 0.98, 0.95, 1.08, 1.28]; // dim … sam (getUTCDay)

/* ---------- le modèle jour par jour : 3 août → 30 octobre 2026 ---------- */
const DEBUT = new Date('2026-08-03T12:00:00Z'), FIN = new Date('2026-10-30T12:00:00Z');
const nSem = 13; // S32 … S44 (S44 en cours)
const lerp = (ab, t) => ab[0] + (ab[1] - ab[0]) * t;
const JOURS_M = {};
for (const s of SHOPS) {
  const L = []; let reportV = s.base * 0.06;
  for (let d = new Date(DEBUT); d <= FIN; d.setUTCDate(d.getUTCDate() + 1)) {
    const j = ymd(d), wd = d.getUTCDay(), k = Math.floor((d - DEBUT) / 864e5);
    const t = Math.min(1, k / 83);                    // 0 en S32 → 1 en S43
    const prodV = s.base * PROFIL[wd] * bruit(0.06);
    const dispo = reportV + prodV;
    let p = lerp(s.p, t) * bruit(0.12) + (wd === 0 ? s.dim.p : 0);
    let n = lerp(s.n, t) * bruit(0.16) + (wd === 0 ? s.dim.n : 0);
    let i = s.i * bruit(0.10);
    const v = 100 - p - i - n;
    const est = s.id === 3 ? R() < 0.1 : R() < 0.03;  // cuisson pas validée : produit = plan
    L.push({ j, wd, sem: semaineIso(j), reportV, prodV, dispo, vV: dispo * v / 100, pV: dispo * p / 100, iV: dispo * i / 100, nV: dispo * n / 100,
      prodC: prodV * s.fc, est, rec: lerp(s.rec, t) + (R() - 0.5) * 0.6, r: lerp(s.r, t) });
    reportV = dispo * i / 100;
  }
  // L'entré = les commandes entrantes VALIDÉES à la réception (au prix du magasin) : lundi, mercredi, vendredi.
  for (let x = 0; x < L.length; x++) {
    const l = L[x]; l.entre = 0;
    if (l.wd === 1 || l.wd === 3 || l.wd === 5) {
      const h = l.wd === 5 ? 3 : 2; let besoin = 0;
      const fin = Math.min(L.length, x + h);
      for (let y = x; y < fin; y++) { besoin += L[y].prodC; }
      l.entre = besoin * h / (fin - x) / l.r * bruit(0.05);
    }
  }
  JOURS_M[s.id] = L;
}

/* ---------- le jour de Gosselies, produit par produit (dimanche 25/10) ---------- */
const JOUR_PROD = { shop: 3, j: '2026-10-25', lignes: [
  // nom, catégorie, prix HT, report d'hier, reçu (fini), produit (four), vendu, poubelle, invendu gardé
  ['Croissant', 'Viennoiserie Ind.', 1.20, 0, 0, 96, 74, 6, 0],
  ['Pain au Chocolat', 'Viennoiserie Ind.', 1.40, 0, 0, 84, 69, 5, 0],
  ['Couque au Beurre', 'Viennoiserie Ind.', 1.50, 0, 0, 30, 24, 2, 0],
  ['Suisse', 'Viennoiserie Ind.', 1.90, 0, 0, 24, 20, 1, 0],
  ['Baguette Tradition 500 g.', 'Petite Boulangerie', 1.80, 0, 78, 0, 41, 6, 0],
  ['Pistolet à l’Ancienne', 'Petite Boulangerie', 0.75, 0, 0, 120, 104, 8, 0],
  ['Pain Demi-Gris', 'Pain', 2.90, 6, 0, 22, 21, 0, 5],
  ['Pain Tradition - 4 Céréales', 'Pain Tradition', 3.80, 4, 0, 18, 15, 0, 6],
  ['Pain 10 Céréales', 'Pain', 3.40, 3, 0, 14, 12, 0, 4],
  ['FlipFlap - Club', 'Sandwiches garnis', 4.95, 0, 0, 24, 14, 0, 0],
  ['FlipFlap - Thon', 'Sandwiches garnis', 4.95, 0, 0, 18, 12, 0, 0],
  ['FlipFlap - Poulet Curry', 'Sandwiches garnis', 4.95, 0, 0, 16, 13, 2, 0],
  ['Wrapps - Poulet & Parmesan', 'Wrapps', 5.50, 0, 0, 10, 8, 1, 0],
  ['Merveilleux', 'Entremets - Individuel', 3.60, 0, 0, 20, 17, 2, 0],
  ['éclair chocolat', 'Pâtisserie individuelle', 3.20, 0, 0, 16, 12, 3, 0],
  ['Citron Meringué - 120⌀', 'Tartissières - 12Ø', 9.50, 2, 0, 6, 6, 0, 2],
  ['Frangipane & Poires', 'Tartes', 14.50, 1, 0, 4, 4, 0, 1],
  ['1/4 - Quiches', 'Quiches', 4.20, 0, 0, 16, 13, 0, 2],
  ['Cookie Chocolat Lait', 'Cookies', 2.20, 18, 0, 24, 21, 0, 20],
  ['Cookie Double Chocolat', 'Cookies', 2.20, 14, 0, 20, 16, 0, 17],
  ['Cake Marbré - 300 g.', 'Cake 100g.', 6.90, 5, 0, 4, 3, 0, 6],
  ['Brownies', 'Tartes', 3.10, 8, 0, 12, 10, 0, 9],
].map(([nom, cat, prix, rep, recu, prod, v, p, i]) => ({ nom, cat, prix, rep, recu, prod, v, p, i, dispo: rep + recu + prod, n: rep + recu + prod - v - p - i })) };
// Ce jour-là, dans le modèle de Gosselies, prend les chiffres des produits (et la veille garde son report).
{
  const L = JOURS_M[3], x = L.findIndex(l => l.j === JOUR_PROD.j), l = L[x], P = JOUR_PROD.lignes, s = SHOPS[1];
  const som = f => P.reduce((a, q) => a + f(q) * q.prix, 0);
  l.reportV = som(q => q.rep); l.prodV = som(q => q.prod + q.recu); l.dispo = l.reportV + l.prodV;
  l.vV = som(q => q.v); l.pV = som(q => q.p); l.iV = som(q => q.i); l.nV = som(q => q.n); l.prodC = l.prodV * s.fc; l.est = false;
  const h = L[x - 1]; h.iV = l.reportV; h.nV = h.dispo - h.vV - h.pV - h.iV;
  L[x + 1].reportV = l.iV; L[x + 1].dispo = L[x + 1].reportV + L[x + 1].prodV;
  const w = L[x + 1]; const k = w.dispo / (w.vV + w.pV + w.iV + w.nV); w.vV *= k; w.pV *= k; w.iV *= k; w.nV *= k;
}

/* ---------- agrégats ---------- */
// Une période = la somme de ses journées, rapportée à la somme des vitrines du jour : on lit le taux d'une journée moyenne.
function agreger(L) {
  const s = k => L.reduce((a, l) => a + l[k], 0);
  const dispo = s('dispo');
  return { n: L.length, entre: s('entre'), prodC: s('prodC'), prodV: s('prodV'), dispo, vV: s('vV'), pV: s('pV'), iV: s('iV'), nV: s('nV'),
    v: 100 * s('vV') / dispo, p: 100 * s('pV') / dispo, i: 100 * s('iV') / dispo, nx: 100 * s('nV') / dispo,
    ratio: s('entre') > 0 ? 100 * s('prodC') / s('entre') : null, rec: s('rec') / L.length, est: L.filter(l => l.est).length };
}
const parSemaine = (sid, w) => JOURS_M[sid].filter(l => l.sem === w);
const SEMS = Array.from({ length: 12 }, (_, k) => 32 + k); // S32 … S43, closes
const AGG = {}; for (const s of SHOPS) { AGG[s.id] = SEMS.map(w => agreger(parSemaine(s.id, w))); }
const reseauSem = w => agreger(SHOPS.flatMap(s => parSemaine(s.id, w)));

/* ---------- seuils (réglables, Paramètres) ---------- */
const SEUIL = { n: [3, 6], p: [6, 9], i: [8, 12], ratio: [92, 85], rec: [97, 90] };
const ton = (v, [a, b]) => v == null ? 'mu' : (v <= a ? 'ok' : (v <= b ? 'att' : 'ko'));
const tonBas = (v, [a, b]) => v == null ? 'mu' : (v >= a ? 'ok' : (v >= b ? 'att' : 'ko'));
const pill = (t, txt) => `<span class="p ${t}">${txt}</span>`;
const bar = (v, p, i, n, w) => `<div class="vf-bar100"${w ? ` style="width:${w}px"` : ''}><i class="c-v" style="width:${v}%"></i><i class="c-p" style="width:${p}%"></i><i class="c-i" style="width:${i}%"></i><i class="c-n" style="width:${Math.max(0, n)}%"></i></div>`;
const LEG = `<div class="vf-leg"><span><i class="c-v"></i>vendu</span><span><i class="c-p"></i>poubelle déclarée</span><span><i class="c-i"></i>invendu gardé pour demain</span><span><i class="c-n"></i>non expliqué</span></div>`;

/* ---------- l'habillage du cockpit ---------- */
const NAV = [
  ['Pilotage', ['Résultat', 'Performance', 'Dashboard magasin ↗']],
  ['Franchisés · évaluation et suivi', ['Fiche franchisé', 'Scoring du trimestre', '▸Suivi journalier et opérations', '▸Suivi de terrain']],
  ['Magasins', ['Analyse magasin', 'Budget', 'Jours creux', 'Invendus et poubelle', 'Plan de développement', 'Scouting — où ouvrir']],
  ['Vérification', ['Audit des flux', 'Fuites par magasin', 'Matières et recettes', 'Chiffre d’affaires', 'Évolution'], true],
  ['Produits', ['Catalogue', 'Gamme · scoring', 'Où ça se vend', 'Dans le temps']],
  ['Centrale d’achat', ['Achats', 'Facturation magasins']],
  ['Marque & marketing', ['Campagnes', 'Offres et canaux', 'Projets de développement', 'Fonds & Royalties']],
  ['ERP franchisé', ['Développement commercial', 'Prospection', 'Prospection mobile', 'Tablette vendeuses', 'App worker ↗', 'Newsletter', 'Newsletter magasin', '▸Gestion de production']],
  ['Contrôle', ['Contrôle posts Facebook']],
  ['Administration', ['Paramètres', 'Journal & diagnostic']],
];
const BTN = 'display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;text-align:left;border:none;cursor:pointer;font-family:var(--font-ui);font-size:13px;padding:8px 10px;border-radius:8px;font-weight:300;';
const badge = b => `<span style="min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:var(--color-primary);color:#fff;font-size:10px;font-weight:500;display:inline-flex;align-items:center;justify-content:center">${b}</span>`;
function rail(actif) {
  const sec = NAV.map(([t, items, neuf]) => `<div style="display:flex;flex-direction:column;gap:2px">
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:var(--color-text);padding:0 10px 7px;display:flex;align-items:center;gap:8px">${t}${neuf ? '<span style="font:600 9px var(--font-ui);letter-spacing:.05em;padding:2px 6px;border-radius:999px;background:var(--color-secondary);color:var(--color-on-abricot,#5a3a1a)">NOUVEAU</span>' : ''}</div>
    ${items.map(x => {
      const sub = x.startsWith('▸'), nom = sub ? x.slice(1) : x, on = nom === actif;
      return `<button style="${BTN}${on ? 'background:rgba(141,29,44,0.08);color:var(--color-primary);font-weight:500' : 'background:transparent;color:var(--color-text)'}">${sub ? `<span style="display:flex;align-items:center;gap:7px"><span style="font-size:9px;color:var(--color-text-muted);width:8px;display:inline-block">▸</span>${nom}</span>` : `<span>${nom}</span>`}${nom === 'Matières et recettes' ? badge(37) : ''}</button>`;
    }).join('')}</div>`).join('');
  return `<aside class="shell-rail" style="width:236px;flex:0 0 236px;background:var(--color-surface);border-right:0.5px solid var(--color-border-tertiary);display:flex;flex-direction:column;align-self:stretch">
    <div style="padding:22px 20px 14px"><img src="/public/assets/img/logo.png" alt="L’Atelier" style="width:176px;max-width:100%;height:auto;display:block"></div>
    <div style="padding:0 12px 12px"><input placeholder="Rechercher partout…" style="width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);background:var(--color-background-secondary);color:var(--color-text);border-radius:9px;height:32px;padding:0 10px;font-family:var(--font-ui);font-size:12px"></div>
    <nav style="flex:1;padding:0 12px 16px;display:flex;flex-direction:column;gap:18px">${sec}</nav></aside>`;
}
const ONGLETS = [['flux', 'Audit des flux', 'Audit des flux'], ['fuites', 'Fuites par magasin', 'Fuites par magasin'], ['matieres', 'Matières ↔ recettes', 'Matières et recettes'], ['ca', 'Chiffre d’affaires', 'Chiffre d’affaires'], ['evol', 'Évolution', 'Évolution']];
function page({ fichier, titre, onglet, recettes = false, corps, lu = 'lu à 10:42' }) {
  const railActif = recettes ? 'Matières et recettes' : ONGLETS.find(o => o[0] === onglet)[2];
  const html = `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${esc(titre)} — Cockpit CEO</title>
<link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="/public/assets/css/app.css"><link rel="stylesheet" href="vf.css"></head>
<body><div id="app"><div class="shell" style="display:flex;min-height:100vh;background:var(--color-bg);font-family:var(--font-ui);color:var(--color-text)">
${rail(railActif)}
<main style="flex:1;min-width:0"><div class="shell-page" style="padding:26px 32px 60px;max-width:1460px">
<header class="shell-head" style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:22px">
  <div><h1 style="font-family:var(--font-display);font-size:30px;font-weight:400;margin:0;line-height:1.15">Vérification</h1>
  <p class="shell-sub" style="font-size:13px;color:var(--color-text-muted);margin:5px 0 0;max-width:760px">Ce que chaque magasin fait de ce qui entre : la matière livrée, les pièces produites, vendues, jetées, gardées — et ce qui ne s’explique pas. Les recettes font le lien entre la matière et les pièces : une recette fausse fausse tout le reste, d’où la liste à corriger.</p></div>
  <div style="display:flex;align-items:center;gap:10px;white-space:nowrap"><span class="vf-lu">${esc(lu)}</span><button class="vf-btn">Relire</button></div>
</header>
<div class="vf-bar"><div class="vf-tabs${recettes ? ' eteint' : ''}">${ONGLETS.map(o => `<button class="${o[0] === onglet ? 'on' : ''}">${o[1]}</button>`).join('')}</div>
  <button class="vf-tog${recettes ? ' on' : ''}"><i></i>Recettes douteuses ou manquantes <b>37</b></button></div>
${corps}
</div></main></div></div><div class="mq-illu">Maquette · chiffres d’illustration</div></body></html>`;
  fs.writeFileSync(path.join(D, fichier), html);
}
const filtres = (per, nav, magasin, droite = '') => `<div class="vf-fil">
  <div class="vf-seg">${['Jour', 'Semaine', 'Mois'].map(x => `<button class="${x === per ? 'on' : ''}">${x}</button>`).join('')}</div>
  <div class="vf-nav"><button>‹</button><span>${nav}</span><button>›</button></div>
  <select class="vf-sel"><option>${esc(magasin)}</option></select>
  <div class="r">${droite}</div></div>`;
const kpi = (k, v, s, t = '') => `<div class="vf-kpi ${t}"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s}</div></div>`;

/* ======================================================================
 * A — Audit des flux, le réseau, semaine 43
 * ==================================================================== */
const W = 43, iW = SEMS.indexOf(W);
const RS = reseauSem(W);
const verdictFlux = (s, a) => {
  if (a.nx > SEUIL.n[1]) { return ['ko', 'À regarder', `${pc(a.nx)} de la vitrine sans trace${a.ratio < SEUIL.ratio[1] ? ` ; ${nf(100 - a.ratio, 0)} % de la matière entrée ne ressort pas en pièces` : ''}`]; }
  if (a.p > SEUIL.p[1]) { return ['att', 'Poubelle haute', `${pc(a.p)} jeté, surtout le dimanche`]; }
  if (a.rec < SEUIL.rec[1]) { return ['att', 'Recettes à finir', `${pc(100 - a.rec, 0)} du CA sans recette fiable : l’écart vient en partie des recettes`]; }
  if (a.nx > SEUIL.n[0]) { return ['att', 'À suivre', `${pc(a.nx)} non expliqué`]; }
  return ['ok', 'Tout s’explique', `à ${nf(a.nx, 1)} % près`];
};
{
  const lignes = SHOPS.map(s => ({ s, a: AGG[s.id][iW] })).sort((x, y) => y.a.nx - x.a.nx);
  const ligne = (nom, sous, a, vd, cls = '') => `<tr class="${cls}"><td class="g nom"><b>${nom}</b><small>${sous}</small></td>
    <td>${eur(a.entre)}</td><td>${pill(tonBas(a.ratio, SEUIL.ratio), pc(a.ratio))}<small>${eur(a.prodC)} en pièces</small></td>
    <td>${eur(a.dispo)}</td><td>${pill('mu', pc(a.v))}<small>${eur(a.vV)}</small></td><td>${pill(ton(a.p, SEUIL.p), pc(a.p))}<small>${eur(a.pV)}</small></td>
    <td>${pill(ton(a.i, SEUIL.i), pc(a.i))}<small>${eur(a.iV)}</small></td><td>${pill(ton(a.nx, SEUIL.n), pc(a.nx))}<small>${eur(a.nV)}</small></td>
    <td>${bar(a.v, a.p, a.i, a.nx, 72)}</td><td>${pill(tonBas(a.rec, SEUIL.rec), pc(a.rec))}</td>
    <td class="g">${vd ? `<span class="vf-v ${vd[0]}"><i></i>${vd[1]}</span><span class="vf-why">${vd[2]}</span>` : ''}</td></tr>`;
  const table = `<table class="vf-t"><thead>
    <tr class="grp"><th></th><th colspan="2"><span>Ce qui entre — au coût</span></th><th colspan="6"><span>Où va ce qui est en vitrine — en % du disponible</span></th><th><span>Recettes</span></th><th></th></tr>
    <tr><th class="g">Magasin</th><th>Entré<small>commandes validées</small></th><th>Produit<small>en % de l’entré</small></th>
    <th>Disponible<small>gardé + produit</small></th><th>Vendu</th><th>Poubelle</th><th>Invendu<small>gardé</small></th><th>Non expliqué</th><th></th><th>Fiables<small>part du CA</small></th><th class="g">Verdict</th></tr></thead><tbody>
    ${lignes.map(({ s, a }) => ligne(s.court, `${a.n} jours · ${a.est ? a.est + ' cuisson' + (a.est > 1 ? 's' : '') + ' non validée' + (a.est > 1 ? 's' : '') : 'cuissons validées'}`, a, verdictFlux(s, a), s.id === 3 ? 'sel' : '')).join('')}
    ${ligne('Réseau', '4 magasins ouverts', RS, null, 'tot')}</tbody></table>`;
  // Jour par jour : le non expliqué et la poubelle, une case par jour.
  const heat = (k, seuil, titre) => {
    const jours = parSemaine(4, W).map(l => l.j);
    return `<div class="vf-card"><div class="vf-ct"><h3>${titre}</h3><span class="s">en % du disponible, jour par jour, semaine 43 — un clic sur une case ouvre le jour, produit par produit</span></div>
      <table class="vf-t"><thead><tr><th class="g">Magasin</th>${jours.map(j => { const d = new Date(j + 'T12:00:00Z'); return `<th>${JOURS[d.getUTCDay()]}<small>${jjmm(j)}</small></th>`; }).join('')}<th>Semaine</th></tr></thead><tbody>
      ${SHOPS.map(s => { const L = parSemaine(s.id, W); const a = AGG[s.id][iW]; return `<tr${s.id === 3 ? ' class="sel"' : ''}><td class="g nom"><b>${s.court}</b></td>${L.map(l => { const v = 100 * l[k + 'V'] / l.dispo; return `<td>${pill(ton(v, seuil), pc(v))}</td>`; }).join('')}<td><b>${pc(k === 'n' ? a.nx : a[k])}</b></td></tr>`; }).join('')}
      </tbody></table></div>`;
  };
  const top = lignes[0];
  const corps = `${filtres('Semaine', `Semaine 43 <small>lun. 19 → dim. 25 oct. 2026</small>`, 'Tout le réseau', '<span class="vf-lu">période close · calculée cette nuit à 04:31</span>')}
  <div class="vf-kpis">
    ${kpi('Entré', eur(RS.entre), `commandes entrantes validées à la réception, au coût · 4 magasins`)}
    ${kpi('Produit ÷ entré', pc(RS.ratio), `${eur(RS.prodC)} de matière ressortie en pièces`, tonBas(RS.ratio, SEUIL.ratio))}
    ${kpi('Vendu', pc(RS.v), `${eur(RS.vV)} sur ${eur(RS.dispo)} mis en vitrine`)}
    ${kpi('Poubelle', pc(RS.p), `${eur(RS.pV)} déclarés en caisse`, ton(RS.p, SEUIL.p))}
    ${kpi('Non expliqué', pc(RS.nx), `${eur(RS.nV)} ni vendus, ni jetés, ni gardés`, ton(RS.nx, SEUIL.n))}
  </div>
  <div class="vf-card"><div class="vf-ct"><h3>Audit des flux — par magasin</h3><span class="s">entré · produit · vendu · poubelle · invendu, en %</span><span class="r">${LEG}</span></div>
    <div class="vf-lect"><b>Semaine 43 :</b> sur ${eur(RS.dispo)} de pièces mises en vitrine, ${pc(RS.v)} ont été vendues, ${pc(RS.p)} jetées et déclarées, ${pc(RS.i)} gardées pour le lendemain ; <b>${pc(RS.nx)} (${eur(RS.nV)}) ne s’expliquent pas</b>, dont ${nf(100 * top.a.nV / RS.nV, 0)} % à ${top.s.court}. Côté matière, ${pc(RS.ratio)} de ce qui est entré est ressorti en pièces : le reste est en stock, perdu, ou mal compté par une recette.</div>
    ${table}
    <div class="vf-pied">Le disponible d’un jour = ce qui restait la veille au soir + ce qui sort du four ou arrive tout fait ; à la semaine, chaque colonne est la somme des journées rapportée à la somme des vitrines : on lit le taux d’une journée moyenne. Non expliqué = disponible − vendu − poubelle déclarée − invendu gardé. Le produit vient des cuissons validées (sinon du plan : « estimé ») ; l’entré des commandes entrantes validées à la réception (/deliveries/{id}, date de validation), au prix du magasin. Seuils réglables dans Paramètres : non expliqué vert ≤ 3 %, orange ≤ 6 % ; poubelle vert ≤ 6 %, orange ≤ 9 % ; produit ÷ entré vert ≥ 92 %.</div></div>
  ${heat('n', SEUIL.n, 'Non expliqué')}${heat('p', SEUIL.p, 'Poubelle')}`;
  page({ fichier: 'a.html', titre: 'Audit des flux — réseau', onglet: 'flux', corps });
}

/* ======================================================================
 * A-JOUR — un magasin, un jour, produit par produit
 * ==================================================================== */
{
  const P = JOUR_PROD.lignes, s = SHOPS[1];
  const T = P.reduce((a, q) => { for (const k of ['rep', 'recu', 'prod', 'dispo', 'v', 'p', 'i', 'n']) { a[k] += q[k]; a[k + 'V'] += q[k] * q.prix; } return a; },
    { rep: 0, recu: 0, prod: 0, dispo: 0, v: 0, p: 0, i: 0, n: 0, repV: 0, recuV: 0, prodV: 0, dispoV: 0, vV: 0, pV: 0, iV: 0, nV: 0 });
  const r = (q, k) => 100 * q[k] / q.dispo;
  const vd = q => {
    const n = r(q, 'n');
    if (q.recu > 0 && n > 15) { return ['ko', 'Reçu, pas vendu', `${q.n} pièces livrées par la centrale sans trace en caisse`]; }
    if (n > 15) { return ['ko', 'Ne s’explique pas', q.cat === 'Sandwiches garnis' ? 'short life : rien n’est déclaré en poubelle le soir' : `${q.n} pièces sans trace`]; }
    if (n > 6) { return ['att', 'À suivre', `${q.n} pièce${q.n > 1 ? 's' : ''} sans trace`]; }
    if (r(q, 'p') > 12) { return ['att', 'Trop produit', `${q.p} jetées sur ${q.dispo}`]; }
    return ['ok', 'S’explique', ''];
  };
  const L = P.slice().sort((a, b) => r(b, 'n') - r(a, 'n') || b.dispo - a.dispo);
  const corps = `${filtres('Jour', `Dimanche 25 octobre 2026 <small>journée close</small>`, 'Atelier by Max & Sandra - Gosselies', '<button class="vf-btn">Exporter le jour</button>')}
  <div class="vf-kpis">
    ${kpi('Disponible', nf(T.dispo) + '<small>pièces</small>', `${nf(T.rep)} gardées d’hier · ${nf(T.recu)} reçues · ${nf(T.prod)} sorties du four · ${eur(T.dispoV)}`)}
    ${kpi('Vendu', pc(100 * T.v / T.dispo), `${nf(T.v)} pièces · ${eur(T.vV)}`)}
    ${kpi('Poubelle', pc(100 * T.p / T.dispo), `${nf(T.p)} pièces déclarées · ${eur(T.pV)}`, ton(100 * T.p / T.dispo, SEUIL.p))}
    ${kpi('Invendu gardé', pc(100 * T.i / T.dispo), `${nf(T.i)} pièces pour lundi · ${eur(T.iV)}`)}
    ${kpi('Non expliqué', pc(100 * T.n / T.dispo), `${nf(T.n)} pièces · ${eur(T.nV)} au prix de vente`, ton(100 * T.n / T.dispo, SEUIL.n))}
  </div>
  <div class="vf-card"><div class="vf-ct"><h3>Le jour, produit par produit</h3><span class="s">en pièces et en % du disponible · les écarts d’abord</span><span class="r">${LEG}</span></div>
    <div class="vf-lect"><b>Dimanche 25/10 à Gosselies :</b> ${nf(T.n)} pièces sont sorties du four ou arrivées et n’ont été ni vendues, ni jetées, ni gardées. Trois lignes font ${nf(100 * (L[0].n + L[1].n + L[2].n) / T.n, 0)} % de l’écart : ${esc(L[0].nom)}, ${esc(L[1].nom)} et ${esc(L[2].nom)}. Les baguettes viennent de la centrale : ${L.find(q => q.recu).n} sur ${L.find(q => q.recu).recu} reçues n’ont laissé aucune trace en caisse.</div>
    <table class="vf-t"><thead>
      <tr class="grp"><th></th><th colspan="4"><span>Ce qui entre en vitrine — pièces</span></th><th colspan="5"><span>Où ça va — en % du disponible</span></th><th></th></tr>
      <tr><th class="g">Produit</th><th>Gardé d’hier</th><th>Entré<small>commande validée</small></th><th>Produit<small>sorti du four</small></th><th>Disponible</th>
      <th>Vendu</th><th>Poubelle</th><th>Invendu<small>gardé</small></th><th>Non expliqué</th><th></th><th class="g">Verdict</th></tr></thead><tbody>
      ${L.map(q => { const v = vd(q); return `<tr${v[0] === 'ko' ? ' class="sel"' : ''}><td class="g nom"><b>${esc(q.nom)}</b><small>${esc(q.cat)} · ${nf(q.prix, 2)} € HT</small></td>
        <td>${q.rep || '<span class="mu">—</span>'}</td><td>${q.recu || '<span class="mu">—</span>'}</td><td>${q.prod || '<span class="mu">—</span>'}</td><td><b>${q.dispo}</b></td>
        <td>${pill('mu', pc(r(q, 'v'), 0))}<small>${q.v} pcs</small></td><td>${pill(ton(r(q, 'p'), [8, 12]), pc(r(q, 'p'), 0))}<small>${q.p} pcs</small></td>
        <td>${pill('mu', pc(r(q, 'i'), 0))}<small>${q.i} pcs</small></td><td>${pill(ton(r(q, 'n'), [6, 15]), pc(r(q, 'n'), 0))}<small>${q.n} pcs</small></td>
        <td>${bar(r(q, 'v'), r(q, 'p'), r(q, 'i'), r(q, 'n'), 80)}</td><td class="g">${v[1] ? `<span class="vf-v ${v[0]}"><i></i>${v[1]}</span>${v[2] ? `<span class="vf-why">${v[2]}</span>` : ''}` : ''}</td></tr>`; }).join('')}
      <tr class="tot"><td class="g">Total du jour</td><td>${T.rep}</td><td>${T.recu}</td><td>${T.prod}</td><td>${T.dispo}</td><td>${pc(100 * T.v / T.dispo)}</td><td>${pc(100 * T.p / T.dispo)}</td><td>${pc(100 * T.i / T.dispo)}</td><td>${pc(100 * T.n / T.dispo)}</td><td>${bar(100 * T.v / T.dispo, 100 * T.p / T.dispo, 100 * T.i / T.dispo, 100 * T.n / T.dispo, 80)}</td><td></td></tr>
    </tbody></table>
    <div class="vf-pied">Gardé d’hier : la clôture de samedi (report). Entré : les produits finis des commandes validées ce jour (centrale). Produit : les cuissons validées en production (« estimé » quand c’est le plan). Vendu : tickets du panel, commandes comprises. Poubelle : déclarée en caisse (/shops/{id}/products/waste). Invendu : la clôture du soir, ce qui se garde pour demain. Ce qui reste est « non expliqué » : poubelle non déclarée, consommation de l’équipe, offert, vente hors caisse ou cuisson mal saisie.</div></div>`;
  page({ fichier: 'a-jour.html', titre: 'Audit des flux — un jour', onglet: 'flux', corps });
}

/* ======================================================================
 * B — un magasin, un mois, jour par jour
 * ==================================================================== */
{
  const s = SHOPS[1];
  const L = JOURS_M[3].filter(l => l.j >= '2026-10-01' && l.j <= '2026-10-30');
  const M = agreger(L);
  // Produit ÷ entré sur 7 jours glissants : l'entré d'un jour seul ne veut rien dire (trois commandes validées par semaine).
  const Ltout = JOURS_M[3];
  const glissant = l => { const x = Ltout.indexOf(l); const f = Ltout.slice(Math.max(0, x - 6), x + 1); const e = f.reduce((a, q) => a + q.entre, 0); return e > 0 ? 100 * f.reduce((a, q) => a + q.prodC, 0) / e : null; };
  const ligneJ = l => { const d = new Date(l.j + 'T12:00:00Z'); const n = 100 * l.nV / l.dispo, p = 100 * l.pV / l.dispo, i = 100 * l.iV / l.dispo, v = 100 * l.vV / l.dispo; const g = glissant(l);
    return `<tr${n > SEUIL.n[1] + 4 ? ' class="sel"' : ''}><td class="g nom"><b>${JOURS[d.getUTCDay()]} ${jjmm(l.j)}</b>${l.est ? '<span class="vf-est">estimé</span>' : ''}</td><td>${eur(l.vV)}</td><td>${l.entre ? eur(l.entre) : '<span class="mu">—</span>'}</td><td>${eur(l.prodC)}</td><td>${pill(tonBas(g, SEUIL.ratio), pc(g))}</td>
      <td>${eur(l.dispo)}</td><td>${pill('mu', pc(v))}</td><td>${pill(ton(p, SEUIL.p), pc(p))}</td><td>${pill(ton(i, SEUIL.i), pc(i))}</td><td>${pill(ton(n, SEUIL.n), pc(n))}<small>${eur(l.nV)}</small></td><td>${bar(v, p, i, n, 140)}</td></tr>`; };
  const ligneA = (titre, sous, a, cls) => `<tr class="${cls}"><td class="g nom">${titre}${sous ? `<small>${sous}</small>` : ''}</td><td>${eur(a.vV)}</td><td>${eur(a.entre)}</td><td>${eur(a.prodC)}</td><td>${pill(tonBas(a.ratio, SEUIL.ratio), pc(a.ratio))}</td>
      <td>${eur(a.dispo)}</td><td>${pc(a.v)}</td><td>${pill(ton(a.p, SEUIL.p), pc(a.p))}</td><td>${pill(ton(a.i, SEUIL.i), pc(a.i))}</td><td>${pill(ton(a.nx, SEUIL.n), pc(a.nx))}<small>${eur(a.nV)}</small></td><td>${bar(a.v, a.p, a.i, a.nx, 140)}</td></tr>`;
  const sems = [...new Set(L.map(l => l.sem))];
  let rows = '';
  for (const w of sems) {
    const Lw = L.filter(l => l.sem === w);
    rows += Lw.map(ligneJ).join('');
    rows += ligneA(`Semaine ${w}`, `${jjmm(Lw[0].j)} → ${jjmm(Lw[Lw.length - 1].j)}${w === 44 ? ' · en cours' : ''}`, agreger(Lw), 'sem');
  }
  rows += ligneA('Octobre', `30 jours · ${M.est} journée${M.est > 1 ? 's' : ''} estimée${M.est > 1 ? 's' : ''}`, M, 'tot');
  const dims = L.filter(l => l.wd === 0), autres = L.filter(l => l.wd !== 0);
  const nDim = 100 * dims.reduce((a, l) => a + l.nV, 0) / dims.reduce((a, l) => a + l.dispo, 0), nAut = 100 * autres.reduce((a, l) => a + l.nV, 0) / autres.reduce((a, l) => a + l.dispo, 0);
  // Où ça fuit : par catégorie (le mois), d'après les journées produit par produit.
  const CATS = [['Sandwiches garnis', 16.8, 1.9, 0.0, 2140], ['Viennoiserie Ind.', 12.4, 6.1, 0.0, 3980], ['Petite Boulangerie', 11.7, 5.2, 0.0, 1620], ['Pâtisserie individuelle', 7.9, 9.8, 0.0, 1210], ['Entremets - Individuel', 5.1, 7.4, 0.0, 890], ['Wrapps', 4.6, 6.3, 0.0, 520], ['Quiches', 4.0, 1.2, 9.1, 610], ['Pain', 3.1, 0.4, 18.6, 1460], ['Pain Tradition', 2.9, 0.3, 21.2, 980], ['Tartes', 2.2, 0.8, 24.9, 1340], ['Cookies', 1.1, 0.0, 38.7, 760], ['Cake 100g.', 0.6, 0.0, 44.0, 310]];
  const corps = `${filtres('Mois', `Octobre 2026 <small>1 → 30 oct. · aujourd’hui exclu</small>`, 'Atelier by Max & Sandra - Gosselies', '<button class="vf-btn">Exporter le mois</button>')}
  <div class="vf-kpis">
    ${kpi('Entré', eur(M.entre), `${L.filter(l => l.entre).length} commandes validées à la réception, au coût`)}
    ${kpi('Produit ÷ entré', pc(M.ratio), `${eur(M.entre - M.prodC)} entrés non ressortis en pièces, dont la livraison du 30/10 pour le week-end`, tonBas(M.ratio, SEUIL.ratio))}
    ${kpi('Poubelle', pc(M.p), `${eur(M.pV)} déclarés`, ton(M.p, SEUIL.p))}
    ${kpi('Invendu gardé', pc(M.i), `en moyenne chaque soir`, ton(M.i, SEUIL.i))}
    ${kpi('Non expliqué', pc(M.nx), `${eur(M.nV)} au prix de vente sur le mois`, ton(M.nx, SEUIL.n))}
  </div>
  <div class="vf-card"><div class="vf-ct"><h3>Jour par jour</h3><span class="s">avec le total de chaque semaine et du mois</span><span class="r">${LEG}</span></div>
    <div class="vf-lect"><b>Octobre à Gosselies :</b> ${pc(M.nx)} de la vitrine ne s’explique pas, ${eur(M.nV)} au prix de vente. Les dimanches pèsent le plus (${pc(nDim)} contre ${pc(nAut)} les autres jours) : le soir du dimanche, ce qui part à la poubelle n’est pas déclaré. Et ${pc(100 - M.ratio, 0)} de la matière livrée ne ressort pas en pièces — voir Matières ↔ recettes.</div>
    <table class="vf-t"><thead><tr><th class="g">Jour</th><th>CA<small>vendu, HT</small></th><th>Entré<small>commandes validées</small></th><th>Produit<small>au coût</small></th><th>Produit ÷ entré<small>7 jours glissants</small></th><th>Disponible<small>prix de vente</small></th><th>Vendu</th><th>Poubelle</th><th>Invendu</th><th>Non expliqué</th><th></th></tr></thead>
    <tbody>${rows}</tbody></table>
    <div class="vf-pied">« estimé » : les cuissons du jour n’ont pas été validées en production, le produit est celui du plan. Le taux d’une semaine ou du mois est celui d’une journée moyenne (somme des jours ÷ somme des vitrines). L’entré d’un jour seul ne se compare pas au produit (trois livraisons par semaine) : le rapport se lit sur sept jours glissants.</div></div>
  <div class="vf-card" style="max-width:820px"><div class="vf-ct"><h3>Où ça fuit</h3><span class="s">octobre, par catégorie · en % du disponible</span></div>
    <table class="vf-t"><thead><tr><th class="g">Catégorie</th><th>Non expliqué</th><th>Poubelle</th><th>Invendu</th><th>Vendu<small>HT</small></th></tr></thead><tbody>
    ${CATS.map(([c, n, p, i, ca]) => `<tr${n > 10 ? ' class="sel"' : ''}><td class="g nom"><b>${c}</b></td><td>${pill(ton(n, SEUIL.n), pc(n))}</td><td>${pill(ton(p, SEUIL.p), pc(p))}</td><td>${i ? pc(i) : '<span class="mu">—</span>'}</td><td>${eur(ca * 4.1)}</td></tr>`).join('')}
    </tbody></table>
    <div class="vf-pied">Les short life (sandwichs, viennoiserie, petite boulangerie) n’ont pas d’invendu gardé : tout ce qui reste le soir devrait être déclaré en poubelle. Leur non expliqué est, presque entier, une poubelle oubliée.</div></div>`;
  page({ fichier: 'b.html', titre: 'Audit des flux — un mois', onglet: 'flux', corps });
}

/* ======================================================================
 * C — Matières ↔ recettes, entre deux inventaires
 * ==================================================================== */
{
  const MAT = [
    // nom, unité, prix/unité, stock début, entré, stock fin, théorique (produit × recette), ton, cause
    ['Thon à l’huile', 'kg', 26.43, 2.0, 10.0, 1.5, 7.1, 'ko', 'FlipFlap - Thon : 70 g en recette, la poubelle du soir n’est pas déclarée le dimanche — et le coût de la recette est faux à Halle (voir la liste).'],
    ['Baguette Tradition 500 g.', 'pcs', 1.4656, 40, 540, 36, 488, 'ko', '56 baguettes reçues de la centrale, ni vendues ni jetées : ventes hors caisse ou poubelle non déclarée — voir Chiffre d’affaires.'],
    ['Beurre doux 82 %', 'kg', 7.95, 18.0, 75.0, 14.5, 64.2, 'ko', 'Pain au Chocolat Nappé : le beurre de tourage manque dans la recette (0 g) — recette incomplète.'],
    ['Jambon cuit supérieur', 'kg', 11.40, 4.2, 18.0, 3.1, 12.6, 'ko', 'FlipFlap - Club : 35 g en recette, 55 g constatés en visite le 14/10 — garnir à la recette, ou la corriger.'],
    ['Crème 35 %', 'l', 4.10, 12, 48, 10, 41, 'ko', 'Merveilleux, Misérable Ind. : la chantilly n’est pas une sous-recette, la crème n’entre dans aucun coût.'],
    ['Farine T65', 'kg', 0.70, 120, 375, 95, 372, 'ok', 'Dans la tolérance de fabrication (fleurage, ratés de pétrin) : seuil à 8 %.'],
    ['Chocolat noir 58 %', 'kg', 13.00, 9.0, 25.0, 12.0, 23.8, 'att', 'Le théorique dépasse le réel : recette surdosée (Brownies à 180 g ?) ou cuissons saisies en trop.'],
    ['Mozzarella', 'kg', 9.80, 3.0, 12.0, 2.5, 11.9, 'ok', ''],
    ['Œufs entiers', 'pcs', 0.20, 180, 900, 150, 902, 'ok', ''],
    ['Lait entier', 'l', 0.95, 20, 60, 18, 58.5, 'ok', ''],
    ['Saumon fumé', 'kg', 32.00, 1.2, 6.0, 0.8, 6.3, 'ok', ''],
    ['Sucre perlé', 'kg', 3.20, 0, 5.0, 3.5, 0, 'att', 'Acheté, employé par aucune recette : à ajouter là où il sert (Gosette Pomme ? Couque au sucre ?).'],
  ].map(([nom, u, prix, s0, e, s1, th, t, cause]) => { const reel = s0 + e - s1; const ec = reel - th; return { nom, u, prix, s0, e, s1, reel, th, ec, ecP: th > 0 ? 100 * ec / th : null, ecE: ec * prix, t, cause }; })
    .sort((a, b) => Math.abs(b.ecE) - Math.abs(a.ecE));
  const fq = (q, u) => nf(q, u === 'pcs' ? 0 : 1) + ' ' + u;
  const K = { s0: 2140, e: 5930, s1: 2010 }; K.reel = K.s0 + K.e - K.s1; K.th = 5380; K.ec = K.reel - K.th;
  const CAUSES = [['Recettes incomplètes ou fausses', 312, 'ko', 'beurre de tourage, crème, chocolat, sucre perlé'], ['Poubelle non déclarée (estimée)', 205, 'att', 'thon, jambon, baguettes le dimanche'], ['Ventes hors caisse probables', 82, 'ko', 'baguettes de la centrale'], ['Tolérance de fabrication', 81, 'ok', 'farine, œufs, lait sous leurs seuils']];
  const corps = `<div class="vf-fil">
    <div class="vf-seg"><button class="on">Entre deux inventaires</button><button>Semaine</button><button>Mois</button></div>
    <select class="vf-sel"><option>Inventaire du lun. 05/10 → inventaire du lun. 19/10 (14 jours)</option></select>
    <select class="vf-sel"><option>Atelier by Max & Sandra - Gosselies</option></select>
    <div class="r"><span class="vf-lu">143 matières comptées aux deux inventaires · 9 comptées une seule fois, écartées</span></div></div>
  <div class="vf-kpis">
    ${kpi('Stock au 05/10', eur(K.s0), 'inventaire du panel, au coût')}
    ${kpi('+ Entré', eur(K.e), '6 commandes validées à la réception · 3 fournisseurs')}
    ${kpi('− Stock au 19/10', eur(K.s1), 'inventaire du panel, au coût')}
    ${kpi('= Consommé réel', eur(K.reel), `face à ${eur(K.th)} que les recettes expliquent`)}
    ${kpi('Écart matière', pc(100 * K.ec / K.reel), `${eur(K.ec)} · ${pc(100 * K.th / K.reel)} de la matière expliquée par les recettes`, 'ko')}
  </div>
  <div class="vf-2" style="grid-template-columns:1fr 1fr"><div class="vf-card"><div class="vf-ct"><h3>D’où vient l’écart</h3><span class="s">${eur(K.ec)} · règles du cockpit, à confirmer en visite</span></div>
    <div style="padding:14px 18px">${CAUSES.map(([c, v, t, d]) => `<div style="margin-bottom:12px"><div style="display:flex;justify-content:space-between;font-size:12.5px"><b style="font-weight:600">${c}</b><span><b>${eur(v)}</b> <span class="mu" style="color:var(--color-text-muted)">${nf(100 * v / K.ec, 0)} %</span></span></div>
      <div style="height:9px;border-radius:5px;background:#f1ece5;margin:5px 0 3px;overflow:hidden"><i style="display:block;height:100%;width:${100 * v / CAUSES[0][1]}%;background:${t === 'ko' ? '#C0182B' : t === 'att' ? '#D97706' : '#2d7a3e'}"></i></div><div style="font-size:11px;color:var(--color-text-muted)">${d}</div></div>`).join('')}</div>
    <div class="vf-pied">Une matière dont la recette est signalée dans la liste « recettes douteuses » range son écart en « recette » ; une matière d’un produit short life sans poubelle déclarée, en « poubelle » ; un produit fini reçu, ni vendu ni jeté, en « hors caisse ».</div></div>
    <div class="vf-card"><div class="vf-ct"><h3>Les quatre magasins</h3><span class="s">dernière période entre deux inventaires</span></div>
    <table class="vf-t"><thead><tr><th class="g">Magasin</th><th>Période</th><th>Expliquée</th><th>Écart</th></tr></thead><tbody>
      <tr><td class="g nom"><b>Halle</b></td><td class="mu">12 → 26/10</td><td>${pill('ok', '96,4 %')}</td><td>238 €</td></tr>
      <tr class="sel"><td class="g nom"><b>Gosselies</b></td><td class="mu">05 → 19/10</td><td>${pill('ko', pc(100 * K.th / K.reel))}</td><td>${eur(K.ec)}</td></tr>
      <tr><td class="g nom"><b>Corbais</b></td><td class="mu">01 → 22/10</td><td>${pill('att', '93,1 %')}</td><td>402 €</td></tr>
      <tr><td class="g nom"><b>Sombreffe</b></td><td class="mu">28/09 → 26/10</td><td>${pill('att', '90,2 %')}</td><td>596 €<small>dont 9 recettes manquantes</small></td></tr></tbody></table></div></div>
  <div class="vf-card"><div class="vf-ct"><h3>Matière par matière</h3><span class="s">les plus gros écarts en euros d’abord · 12 sur 143</span><span class="r"><button class="vf-btn">Toutes les matières</button></span></div>
    <div class="vf-lect"><b>Du 05 au 19/10 à Gosselies,</b> le magasin a consommé ${eur(K.reel)} de matière ; les pièces produites n’en expliquent que ${eur(K.th)}. Les cinq premières lignes font ${eur(MAT.slice(0, 5).reduce((a, m) => a + m.ecE, 0))} de l’écart ; trois d’entre elles viennent d’une recette qui ne compte pas ce qu’elle emploie.</div>
    <table class="vf-t"><thead>
      <tr class="grp"><th></th><th colspan="4"><span>Le réel — inventaires et livraisons</span></th><th><span>Les recettes</span></th><th colspan="3"><span>L’écart</span></th><th></th></tr>
      <tr><th class="g">Matière</th><th>Stock 05/10</th><th>+ Entré<small>commandes validées</small></th><th>− Stock 19/10</th><th>= Consommé</th><th>Théorique<small>produit × recette</small></th><th>Quantité</th><th>%</th><th>€</th><th class="g">Cause probable</th></tr></thead><tbody>
      ${MAT.map(m => `<tr${m.t === 'ko' ? ' class="sel"' : ''}><td class="g nom"><b>${esc(m.nom)}</b><small>${nf(m.prix, Number.isInteger(Math.round(m.prix * 1e4) / 100) ? 2 : 4)} € / ${m.u}</small></td><td>${fq(m.s0, m.u)}</td><td>${fq(m.e, m.u)}</td><td>${fq(m.s1, m.u)}</td><td><b>${fq(m.reel, m.u)}</b></td><td>${fq(m.th, m.u)}</td>
        <td>${m.ec > 0 ? '+' : ''}${fq(m.ec, m.u)}</td><td>${m.ecP == null ? pill('ko', 'hors recette') : pill(m.t, (m.ecP > 0 ? '+' : '') + pc(m.ecP))}</td><td><b>${m.ecE > 0 ? '+' : ''}${nf(m.ecE, 0)} €</b></td><td class="g wrap" style="max-width:330px;font-size:11.5px">${esc(m.cause)}</td></tr>`).join('')}
      </tbody></table>
    <div class="vf-pied">Consommé réel = stock de l’inventaire de début + entré (les commandes entrantes validées à la réception) − stock de l’inventaire de fin. Théorique = les pièces produites (cuissons validées) et les produits finis reçus, dépliés par leurs recettes et sous-recettes jusqu’à la matière, unités ramenées à l’unité de base. Les emballages et l’hygiène (412 € sur la période) ne passent par aucune recette : suivis à part. Tolérances réglables par catégorie de matière (farines 8 %, laitages 6 %, garnitures 5 %).</div>`;
  page({ fichier: 'c.html', titre: 'Matières ↔ recettes', onglet: 'matieres', corps });
}

/* ======================================================================
 * D — Chiffre d'affaires : caisse, P&L, redevances, CA attendu par la matière
 * ==================================================================== */
{
  const CA = [
    { s: 'Gosselies', caisse: 49870, pnl: 49870, red: 47200, att: 55600, sp: [1240, 38], co: [1860, 41], t: 'ko', v: 'À regarder', why: 'La matière appelle 5 730 € de ventes de plus ; redevance 2 670 € sous la caisse ; 38 lignes sans produit.' },
    { s: 'Sombreffe', caisse: 31280, pnl: 31280, red: 31280, att: 33900, sp: [410, 15], co: [260, 7], t: 'att', v: 'Attendu peu fiable', why: '11 % du CA sans recette fiable : le CA attendu se corrige avec les recettes.' },
    { s: 'Corbais', caisse: 37950, pnl: 37610, red: 37610, att: 38400, sp: [140, 6], co: [420, 12], t: 'att', v: 'P&L sous la caisse', why: '340 € de corrections passées après la clôture (12 et 26/09).' },
    { s: 'Halle', caisse: 58420, pnl: 58420, red: 58420, att: 59300, sp: [85, 4], co: [312, 9], t: 'ok', v: 'Tout concorde', why: 'Écarts sous 2 %.' },
  ];
  const T = CA.reduce((a, x) => { for (const k of ['caisse', 'pnl', 'red', 'att']) { a[k] += x[k]; } a.sp[0] += x.sp[0]; a.sp[1] += x.sp[1]; a.co[0] += x.co[0]; a.co[1] += x.co[1]; return a; }, { caisse: 0, pnl: 0, red: 0, att: 0, sp: [0, 0], co: [0, 0] });
  const ecart = (x, ref) => { const e = 100 * (x - ref) / ref; return `${pill(Math.abs(e) <= 1 ? 'ok' : Math.abs(e) <= 4 ? 'att' : 'ko', (e > 0 ? '+' : '') + pc(e))}`; };
  const diff = (x, ref) => x === ref ? 'identique' : (x > ref ? '+' : '−') + eur(Math.abs(x - ref));
  const ligne = (x, cls) => `<tr class="${cls || ''}"><td class="g nom"><b>${x.s}</b></td><td><b>${eur(x.caisse)}</b></td>
    <td>${ecart(x.pnl, x.caisse)}<small>${eur(x.pnl)} · ${diff(x.pnl, x.caisse)}</small></td>
    <td>${ecart(x.red, x.caisse)}<small>${eur(x.red)} · ${diff(x.red, x.caisse)}</small></td>
    <td>${ecart(x.caisse, x.att)}<small>attendu ${eur(x.att)}</small></td>
    <td>${eur(x.sp[0])}<small>${x.sp[1]} lignes</small></td><td>${eur(x.co[0])}<small>${x.co[1]} tickets</small></td><td class="g">${x.v ? `<span class="vf-v ${x.t}"><i></i>${x.v}</span><span class="vf-why">${x.why}</span>` : ''}</td></tr>`;
  const JOURS_V = [
    ['dim. 13/09', 'ko', 'Aucun ticket de 11:40 à 13:10, en plein service : 620 € d’habitude sur ce créneau.'],
    ['sam. 19/09', 'ko', '14 lignes « divers » sans produit (412 €), sur deux postes de caisse.'],
    ['mer. 23/09', 'att', '11 annulations après encaissement (386 €), dont 9 après 17:00.'],
    ['dim. 27/09', 'ko', 'Baguettes : 78 reçues, 41 vendues, 6 jetées — 31 sans trace.'],
    ['ven. 25/09', 'att', 'Le panel relève une anomalie de vente (ticket remboursé deux fois, 48,60 €).'],
  ];
  const corps = `${filtres('Mois', `Septembre 2026 <small>dernier mois clos et facturé en redevances</small>`, 'Tout le réseau', '')}
  <div class="vf-kpis">
    ${kpi('CA caisse', eur(T.caisse), 'les tickets du panel, HT, 4 magasins')}
    ${kpi('P&L ÷ caisse', pc(100 * T.pnl / T.caisse), `${eur(T.caisse - T.pnl)} d’écart`, 'ok')}
    ${kpi('Redevances ÷ caisse', pc(100 * T.red / T.caisse), `${eur(T.caisse - T.red)} de base en moins`, 'att')}
    ${kpi('Caisse ÷ CA attendu', pc(100 * T.caisse / T.att), `${eur(T.att - T.caisse)} que la matière consommée laisse attendre`, 'att')}
    ${kpi('Lignes sans produit', eur(T.sp[0]), `${T.sp[1]} lignes · ${eur(T.co[0])} d’annulations`, 'att')}
  </div>
  <div class="vf-card"><div class="vf-ct"><h3>Le chiffre d’affaires, quatre façons</h3><span class="s">la caisse fait référence ; chaque autre chiffre se lit en écart</span></div>
    <div class="vf-lect"><b>Septembre :</b> le P&L et les factures de redevances reprennent la caisse à Halle et à Sombreffe. À Gosselies, la base de redevance est ${eur(2670)} sous la caisse, et surtout <b>la matière consommée laisse attendre ${eur(55600 - 49870)} de ventes de plus</b> (${pc(100 * (55600 - 49870) / 55600)}) — cohérent avec le non expliqué de l’audit des flux et les baguettes de la centrale.</div>
    <table class="vf-t"><thead>
      <tr class="grp"><th></th><th><span>Référence</span></th><th colspan="3"><span>Écart à la caisse</span></th><th colspan="2"><span>En caisse, à part</span></th><th></th></tr>
      <tr><th class="g">Magasin</th><th>Caisse<small>tickets, HT</small></th><th>P&L du panel<small>CA du mois</small></th><th>Redevances<small>base facturée</small></th><th>Attendu par la matière<small>consommé ÷ taux matière</small></th><th>Sans produit<small>lignes « divers »</small></th><th>Annulations<small>après encaissement</small></th><th class="g">Verdict</th></tr></thead><tbody>
      ${CA.map(x => ligne(x, x.s === 'Gosselies' ? 'sel' : '')).join('')}${ligne(Object.assign({ s: 'Réseau' }, T), 'tot')}</tbody></table>
    <div class="vf-pied">Caisse : les tickets du mois (/shops/{id}/transactions, moissonnés chaque nuit). P&L : /consultant/shops/{id}/pnl/daily, sommé sur le mois (/pnl/monthly ne répond pas). Base des redevances : la facture du mois (/admin/royalties/invoices). CA attendu : le consommé réel entre inventaires (onglet Matières), divisé par le taux matière du magasin (coût de recette ÷ prix, pondéré par ses ventes) — il ne vaut que si les recettes sont fiables, d’où la colonne de l’audit des flux. Sans produit : lignes de ticket sans référence (« divers »). Annulations : /shops/{id}/sales-corrections.</div></div>
  <div class="vf-2"><div class="vf-card"><div class="vf-ct"><h3>Gosselies — les jours à regarder</h3><span class="s">septembre · un clic ouvre le jour dans l’audit des flux</span></div>
    <table class="vf-t"><tbody>${JOURS_V.map(([j, t, x]) => `<tr><td class="g nom" style="width:110px"><b>${j}</b></td><td class="g" style="width:20px"><span class="vf-v ${t}"><i></i></span></td><td class="g wrap">${x}</td></tr>`).join('')}</tbody></table></div>
    <div class="vf-card"><div class="vf-ct"><h3>Mois par mois</h3><span class="s">caisse face à l’attendu, réseau</span></div>
    <table class="vf-t serre"><thead><tr><th class="g">Mois</th><th>Caisse</th><th>Attendu</th><th>Écart</th><th>Recettes fiables</th></tr></thead><tbody>
      ${[['Juillet', 171240, 178900, 92.1], ['Août', 168400, 175300, 92.6], ['Septembre', T.caisse, T.att, 93.1], ['Octobre', 166900, 172800, 93.6, true]].map(([m, c, a, r, enc]) => `<tr><td class="g nom"><b>${m}</b>${enc ? '<small>au 30/10</small>' : ''}</td><td>${eur(c)}</td><td>${eur(a)}</td><td>${ecart(c, a)}</td><td>${pill(tonBas(r, SEUIL.rec), pc(r))}</td></tr>`).join('')}
    </tbody></table></div></div>`;
  page({ fichier: 'd.html', titre: 'Chiffre d’affaires', onglet: 'ca', corps });
}

/* ======================================================================
 * E — la bascule : recettes douteuses ou manquantes
 * ==================================================================== */
{
  const TYPES = { sans: ['pb-sans', 'Pas de recette', 8], vide: ['pb-vide', 'Recette vide', 3], prix: ['pb-prix', 'Matière sans prix', 8], inv: ['pb-inv', 'Coût invraisemblable', 5], reseau: ['pb-reseau', 'Coût hors norme du réseau', 4], unite: ['pb-unite', 'Unité suspecte', 3], rend: ['pb-rend', 'Rendement absent', 2], port: ['pb-port', 'Portion sans fraction', 2], orph: ['pb-orph', 'Matière jamais en recette', 2] };
  const L = [
    ['Pâtes Amatriciana', 'Plat Prepare - Chaud', 'sans', 'Vendu 312 fois en 30 jours, aucune recette au panel : son coût est estimé au taux du magasin.', 2650, 'Halle · Gosselies · Corbais', '28 j'],
    ['FlipFlap - Thon', 'Sandwiches garnis', 'reseau', '0,79 € à Halle (coût « custom » du 12/07) ; 3,73 € à 3,91 € ailleurs ; la recette ligne à ligne donne 3,87 €.', 2140, 'Halle', '89 j'],
    ['Merveilleux', 'Entremets - Individuel', 'prix', 'Sous-recette Meringue : sucre glace et blancs d’œufs sans prix dans 3 magasins sur 4.', 1980, '3 magasins', '41 j'],
    ['Pain au Chocolat Nappé', 'Viennoiserie Ind.', 'unite', 'Bâtons chocolat : « 2 » lu en kg au lieu de pièces — 26,40 € de coût pour 1,60 € de vente. Le beurre de tourage manque.', 1540, '4 magasins', '12 j'],
    ['Cookie Noix & Caramel', 'Cookies', 'vide', 'Recette créée sans aucune ligne : coût 0 €.', 1120, '4 magasins', '63 j'],
    ['Taboulé Petits Pois & Poulet', 'Salades féculentes', 'sans', 'Pas de recette au panel.', 960, 'Halle · Sombreffe', '19 j'],
    ['Wrapps - Poulet & Parmesan', 'Wrapps', 'inv', 'Coût 6,85 € pour un prix de 5,50 € HT : la sous-recette sauce compte 1 l au lieu de 10 cl.', 880, '3 magasins', '7 j'],
    ['Pain Tradition - Olives', 'Pain Tradition', 'rend', 'Rendement à 0 : le pâton entier (12 pains) est imputé à un seul pain — coût 14,20 €.', 760, '4 magasins', '35 j'],
    ['1/4 - Quiches', 'Quiches', 'port', 'La portion n’a pas de fraction : un quart coûte une quiche entière.', 610, '4 magasins', '89 j'],
    ['Couque à la Crème', 'Viennoiserie Ind.', 'prix', 'Crème pâtissière : lait entier sans prix à Sombreffe.', 540, 'Sombreffe', '22 j'],
    ['Duo Chocolat - 180⌀', 'Entremets - 190Ø', 'reseau', '11,90 € à Gosselies, médiane du réseau 6,40 € : le biscuit est compté deux fois.', 520, 'Gosselies', '15 j'],
    ['Brookie', 'Tartes', 'sans', 'Pas de recette au panel.', 470, '3 magasins', '51 j'],
    ['Pistolet à l’Ancienne', 'Petite Boulangerie', 'inv', '0,02 € de coût pour 0,90 € de vente (2 %), sous le seuil de 5 % : la farine est lue en g comme si c’étaient des kg.', 450, '4 magasins', '9 j'],
    ['Fromage & Spéculoos - 120⌀', 'Tartissières - 12Ø', 'unite', 'Spéculoos : 120 lu en kg au lieu de g.', 380, 'Corbais · Halle', '4 j'],
    ['Pain Doré', 'Pain', 'prix', 'Graines de sésame sans prix dans 2 magasins.', 290, 'Corbais · Sombreffe', '30 j'],
    ['Sucre perlé', 'matière première', 'orph', '5 kg livrés à Gosselies en octobre, employé par aucune recette.', null, 'Gosselies', '6 j'],
  ];
  const corps = `<div class="vf-card"><div class="vf-cov"><div class="big">93,6 %<small>du CA passe par une recette fiable</small></div>
    <div><div class="jg"><i style="width:93.6%"></i><u style="left:99.6%"></u></div>
    <div class="tx">Objectif <b>100 %</b> : chaque produit vendu a une recette complète, chiffrée et plausible. Il manque <b>6,4 % du CA, soit 8 120 € de ventes sur 30 jours</b>, dont 72 % dans les dix premières lignes. Tant qu’elles restent, l’audit des flux, l’écart matière et le CA attendu en portent une part.</div></div></div>
    <div class="vf-chips"><button class="on">Toutes <b>37</b></button>${Object.values(TYPES).map(([c, n, k]) => `<button><span class="vf-pb ${c}" style="padding:0 6px;margin-right:4px">&nbsp;</span>${n} <b>${k}</b></button>`).join('')}</div>
    <div class="vf-fil" style="padding:10px 18px 0;margin:0"><select class="vf-sel"><option>Tous les magasins</option></select><select class="vf-sel"><option>Classées par CA concerné (30 jours)</option></select><div class="r"><button class="vf-btn">Exporter la liste</button><button class="vf-btn prim">Envoyer au responsable recettes</button></div></div>
    <table class="vf-t" style="margin-top:8px"><thead><tr><th class="g" style="width:30px">#</th><th class="g">Produit</th><th class="g">Problème</th><th class="g">Ce qui cloche</th><th>CA concerné<small>30 jours</small></th><th class="g">Magasins</th><th>Repérée<small>depuis</small></th><th></th></tr></thead><tbody>
    ${L.map(([nom, cat, k, txt, ca, mag, dep], x) => `<tr><td class="g"><span class="vf-rang${x < 5 ? ' h' : ''}">${x + 1}</span></td><td class="g nom"><b>${esc(nom)}</b><small>${esc(cat)}</small></td><td class="g"><span class="vf-pb ${TYPES[k][0]}">${TYPES[k][1]}</span></td>
      <td class="g wrap" style="max-width:420px;font-size:11.5px">${esc(txt)}</td><td>${ca ? '<b>' + eur(ca) + '</b>' : '<span class="mu">—</span>'}</td><td class="g mu">${esc(mag)}</td><td>${dep}</td><td><a class="vf-lien">Ouvrir au panel ↗</a></td></tr>`).join('')}
    </tbody></table>
    <div class="vf-pied">… et 21 autres, pour 1 240 € de CA. Les règles : <b>pas de recette</b> (produit vendu sans id_recipe) · <b>recette vide</b> (aucune ligne) · <b>matière sans prix</b> (price_source MISSING dans un magasin qui la vend) · <b>coût invraisemblable</b> (coût de la pièce sous 5 % ou au-dessus de 100 % du prix HT) · <b>hors norme du réseau</b> (plus de 40 % d’écart à la médiane des magasins) · <b>unité suspecte</b> (une ligne qui pèse plus de la moitié du coût et dont la quantité ×1000 ou ÷1000 ramène le coût dans la norme de la catégorie) · <b>rendement absent</b> (0 ou vide) · <b>portion sans fraction</b> · <b>matière jamais en recette</b> (achetée sur la période, employée par aucune recette). « Repérée depuis » : le premier jour où la base quotidienne l’a vue — une ligne disparaît d’elle-même quand la recette est corrigée au panel.</div></div>`;
  page({ fichier: 'e.html', titre: 'Recettes douteuses ou manquantes', onglet: 'matieres', recettes: true, corps });
}

/* ======================================================================
 * F — Évolution : la base quotidienne, semaine après semaine
 * ==================================================================== */
{
  const COUL = { 4: '#2F6EA5', 3: '#8D1D2C', 2: '#B26A00', 5: '#5b3f86' };
  const serie = (sid, k) => AGG[sid].map(a => k === 'n' ? a.nx : a[k]);
  const reseau = k => SEMS.map(w => { const a = reseauSem(w); return k === 'n' ? a.nx : a[k]; });
  function graphe(k, ymax, bandes, titre, sous, notes) {
    const Wd = 1060, H = 250, ml = 40, mr = 118, mt = 14, mb = 28;
    const x = i => ml + i * (Wd - ml - mr) / (SEMS.length - 1), y = v => mt + (H - mt - mb) * (1 - v / ymax);
    let g = '';
    for (const [a, b, c] of bandes) { g += `<rect x="${ml}" y="${y(b)}" width="${Wd - ml - mr}" height="${y(a) - y(b)}" fill="${c}"/>`; }
    for (let v = 0; v <= ymax; v += ymax / 4) { g += `<line x1="${ml}" x2="${Wd - mr}" y1="${y(v)}" y2="${y(v)}" stroke="rgba(0,0,0,.07)"/><text x="${ml - 6}" y="${y(v) + 3.5}" text-anchor="end">${nf(v, 0)} %</text>`; }
    SEMS.forEach((w, i) => { g += `<text x="${x(i)}" y="${H - 8}" text-anchor="middle">S${w}</text>`; });
    notes.forEach(([i, txt], k) => { g += `<line x1="${x(i)}" x2="${x(i)}" y1="${mt}" y2="${H - mb}" stroke="#222" stroke-dasharray="2 3" opacity=".5"/><text x="${x(i) + 4}" y="${mt + 10 + 14 * k}" style="fill:#222;font-weight:600">${txt}</text>`; });
    const lignes = [...SHOPS.map(s => [s.court, serie(s.id, k), COUL[s.id], '']), ['Réseau', reseau(k), '#222', '4 4']];
    const fin = lignes.map(l => ({ l, yy: y(l[1][l[1].length - 1]) })).sort((a, b) => a.yy - b.yy);
    for (let i = 1; i < fin.length; i++) { if (fin[i].yy - fin[i - 1].yy < 13) { fin[i].yy = fin[i - 1].yy + 13; } }
    for (const { l: [nom, v, c, dash], yy } of fin) {
      g += `<polyline fill="none" stroke="${c}" stroke-width="${nom === 'Réseau' ? 1.6 : 2.2}" ${dash ? `stroke-dasharray="${dash}"` : ''} points="${v.map((q, i) => x(i) + ',' + y(q)).join(' ')}"/>`;
      g += `<circle cx="${x(v.length - 1)}" cy="${y(v[v.length - 1])}" r="3" fill="${c}"/><text x="${x(v.length - 1) + 8}" y="${yy + 3.5}" style="fill:${c};font-weight:600">${nom} ${nf(v[v.length - 1], 1)} %</text>`;
    }
    return `<div class="vf-card"><div class="vf-ct"><h3>${titre}</h3><span class="s">${sous}</span></div><div class="vf-chart"><svg viewBox="0 0 ${Wd} ${H}">${g}</svg></div></div>`;
  }
  const spark = (v, c, bas) => { const mn = Math.min(...v), mx = Math.max(...v), w = 56, h = 22; const pts = v.map((q, i) => (i * w / (v.length - 1)).toFixed(1) + ',' + (h - 3 - (h - 6) * (mx === mn ? 0.5 : (q - mn) / (mx - mn))).toFixed(1)).join(' ');
    return `<svg class="vf-spark" width="${w}" height="${h}"><polyline fill="none" stroke="${c}" stroke-width="1.6" points="${pts}"/><circle cx="${w}" cy="${pts.split(' ').pop().split(',')[1]}" r="2.4" fill="${c}"/></svg>`; };
  const moy = (a, i, j) => a.slice(i, j).reduce((s, q) => s + q, 0) / (j - i);
  const IND = [['n', 'Non expliqué', true], ['p', 'Poubelle', true], ['i', 'Invendu', true], ['ratio', 'Produit ÷ entré', false], ['rec', 'Recettes fiables', false]];
  const cel = (v, bas) => { const av = moy(v, 0, 4), ap = moy(v, 8, 12), d = ap - av; const mieux = bas ? d < -0.3 : d > 0.3, pire = bas ? d > 0.3 : d < -0.3;
    return `<td>${spark(v, mieux ? '#2d7a3e' : pire ? '#C0182B' : '#8a8177')}</td><td>${pc(av)} → <b>${pc(ap)}</b><small><span class="vf-fl ${mieux ? 'ok' : pire ? 'ko' : 'mu'}">${mieux ? '▲ mieux' : pire ? '▼ moins bien' : '= stable'}</span> ${d > 0 ? '+' : ''}${nf(d, 1)} pt</small></td>`; };
  const tab = `<table class="vf-t serre"><thead><tr class="grp"><th></th>${IND.map(([, n]) => `<th colspan="2"><span>${n}</span></th>`).join('')}</tr>
    <tr><th class="g">Magasin</th>${IND.map(() => '<th></th><th>avant → après</th>').join('')}</tr></thead><tbody>
    ${SHOPS.map(s => `<tr><td class="g nom"><b>${s.court}</b></td>${IND.map(([k, , bas]) => cel(serie(s.id, k), bas)).join('')}</tr>`).join('')}
    <tr class="tot"><td class="g">Réseau</td>${IND.map(([k, , bas]) => cel(reseau(k), bas)).join('')}</tr></tbody></table>`;
  const nLignes = SHOPS.length * JOURS_M[4].length;
  const corps = `${filtres('Semaine', `12 semaines <small>S32 → S43 · 3 août → 25 oct. 2026</small>`, 'Tout le réseau', '<select class="vf-sel"><option>Non expliqué</option></select>')}
  <div class="vf-card"><div class="vf-db"><span><span class="pt"></span><b>Base à jour</b> — la nuit dernière à 04:31</span><span>Table <code>ceo_verif_jour</code> : ${nf(nLignes)} journées (4 magasins × ${JOURS_M[4].length} jours)</span><span>Chaque nuit : la veille enregistrée, les sept jours d’avant recalculés · rien ne s’efface</span></div>
    <div class="vf-lect"><b>Ce que les douze semaines disent :</b> Halle et Corbais s’améliorent (Corbais jette moins depuis la visite du 01/10 : ${pc(moy(serie(2, 'p'), 0, 4))} → ${pc(moy(serie(2, 'p'), 8, 12))} de poubelle) ; Sombreffe a gagné ${nf(moy(serie(5, 'rec'), 8, 12) - moy(serie(5, 'rec'), 0, 4), 0)} points de recettes fiables depuis les corrections de la S38, et son non expliqué baisse avec. <b>Gosselies se dégrade</b> : ${pc(moy(serie(3, 'n'), 0, 4))} → ${pc(moy(serie(3, 'n'), 8, 12))} de non expliqué, surtout les dimanches.</div></div>
  ${graphe('n', 16, [[0, 3, 'rgba(45,122,62,.07)'], [3, 6, 'rgba(217,119,6,.07)']], 'Non expliqué, semaine par semaine', '% du disponible · vert ≤ 3 %, orange ≤ 6 %', [[6, 'S38 · 14 recettes corrigées (Sombreffe)'], [8, 'S40 · visite Corbais']])}
  <div class="vf-card"><div class="vf-ct"><h3>Avant, maintenant</h3><span class="s">les quatre premières semaines face aux quatre dernières · le sens de la flèche dit si la gestion s’améliore</span></div>${tab}
    <div class="vf-pied">Chaque indicateur se lit dans la base quotidienne, sans relire le panel : une journée enregistrée garde les chiffres de ce jour-là, même si une recette est corrigée ensuite (la correction se voit alors comme une amélioration, à partir du jour où elle a eu lieu).</div></div>`;
  page({ fichier: 'f.html', titre: 'Évolution', onglet: 'evol', corps, lu: 'base mise à jour à 04:31' });
}

/* ======================================================================
 * G — Fuites par magasin : un tableau par magasin, tous de la même structure
 *     stock début + entrées − stock fin = sorti ; sorti − ventes − poubelle = delta (la fuite)
 * ==================================================================== */
{
  const RG = mulberry32(4343);
  const rb = a => 1 + (RG() * 2 - 1) * a;
  // Le catalogue, le même pour les quatre magasins (même ordre de lignes d'un tableau à l'autre).
  const CAT = [
    ['Viennoiserie Ind.', false, [['Croissant', 1.20, 9], ['Pain au Chocolat', 1.40, 8], ['Couque au Beurre', 1.50, 3], ['Suisse', 1.90, 2.5]]],
    ['Petite Boulangerie', false, [['Baguette Tradition 500 g.', 1.80, 6, 'centrale'], ['Pistolet à l’Ancienne', 0.75, 4]]],
    ['Pain', true, [['Pain Demi-Gris', 2.90, 4], ['Pain 10 Céréales', 3.40, 3]]],
    ['Pain Tradition', true, [['Pain Tradition - 4 Céréales', 3.80, 3.5], ['Pain Tradition - Olives', 4.10, 2]]],
    ['Sandwiches garnis', false, [['FlipFlap - Club', 4.95, 5], ['FlipFlap - Thon', 4.95, 3.5], ['FlipFlap - Poulet Curry', 4.95, 3]]],
    ['Wrapps', false, [['Wrapps - Poulet & Parmesan', 5.50, 2.5]]],
    ['Pâtisserie individuelle', false, [['éclair chocolat', 3.20, 2.5]]],
    ['Entremets - Individuel', false, [['Merveilleux', 3.60, 3], ['Misérable Ind.', 3.40, 1.5]]],
    ['Tartes', true, [['Frangipane & Poires', 14.50, 4], ['Brownies', 3.10, 2]]],
    ['Quiches', true, [['1/4 - Quiches', 4.20, 3]]],
    ['Plat Prepare - Chaud', false, [['Pâtes Amatriciana', 6.50, 3]]],
    ['Cookies', true, [['Cookie Chocolat Lait', 2.20, 2.5], ['Cookie Double Chocolat', 2.20, 2]]],
    ['Cake 100g.', true, [['Cake Marbré - 300 g.', 6.90, 1.5]]],
  ];
  // Où la fuite se loge, magasin par magasin (part du delta en euros) ; le reste se répartit au poids.
  const FUITE = {
    3: { 'Sandwiches garnis': 0.34, 'Viennoiserie Ind.': 0.24, 'Petite Boulangerie': 0.22, 'Pâtisserie individuelle': 0.06 },
    5: { 'Tartes': 0.24, 'Plat Prepare - Chaud': 0.11, 'Quiches': 0.14 },
    2: { 'Viennoiserie Ind.': 0.30, 'Entremets - Individuel': 0.20 },
    4: { 'Plat Prepare - Chaud': 0.07 },
  };
  const DOMINE = { 'FlipFlap - Club': 2.6, 'Baguette Tradition 500 g.': 4, 'Croissant': 1.6, 'Brownies': 2.2, 'Merveilleux': 1.8 };
  const PROD = CAT.flatMap(([c, garde, ps]) => ps.map(([nom, prix, w, src]) => ({ c, garde, nom, prix, w, src })));
  const parMag = {};
  for (const s of SHOPS) {
    const L = parSemaine(s.id, W);
    const T = { deb: L[0].reportV, ent: L.reduce((a, l) => a + l.prodV, 0), fin: L[L.length - 1].iV, pou: L.reduce((a, l) => a + l.pV, 0), del: L.reduce((a, l) => a + l.nV, 0) };
    const lignes = PROD.map(p => ({ ...p, wq: p.w * rb(0.15) }));
    const sw = f => lignes.reduce((a, q) => a + f(q), 0);
    const totW = sw(q => q.wq), totG = sw(q => q.garde ? q.wq : 0);
    const fu = FUITE[s.id], partNommee = Object.values(fu).reduce((a, x) => a + x, 0);
    const poidsCat = {}; for (const q of lignes) { poidsCat[q.c] = (poidsCat[q.c] || 0) + q.wq; }
    const libre = Object.keys(poidsCat).filter(c => !(c in fu)).reduce((a, c) => a + poidsCat[c], 0);
    for (const q of lignes) {
      const pr = q.prix;
      q.ent = Math.round(T.ent * q.wq / totW / pr);
      q.deb = q.garde ? Math.round(T.deb * q.wq / totG / pr) : 0;
      q.fin = q.garde ? Math.round(T.fin * q.wq / totG * rb(0.2) / pr) : 0;
      const pz = s.id === 2 && q.c === 'Viennoiserie Ind.' ? 3.2 : (q.garde ? 0.25 : 1.15);
      q.pou = Math.round(T.pou * q.wq / totW * pz * rb(0.3) / pr);
      // La part de fuite de la catégorie, puis celle du produit dans sa catégorie (un produit domine souvent).
      const partCat = q.c in fu ? fu[q.c] : (1 - partNommee) * poidsCat[q.c] / libre;
      const memeCat = lignes.filter(x => x.c === q.c), dq = DOMINE[q.nom] || 1;
      const partProd = (q.wq * dq) / memeCat.reduce((a, x) => a + x.wq * (DOMINE[x.nom] || 1), 0);
      q.del = Math.max(0, Math.round(T.del * partCat * partProd * rb(0.12) / pr));
      q.recu = q.src ? q.ent : 0; q.prod = q.src ? 0 : q.ent;
      q.sorti = q.deb + q.ent - q.fin;
      q.ven = q.sorti - q.pou - q.del;
    }
    parMag[s.id] = lignes;
  }
  const tot = L => { const o = { deb: 0, ent: 0, recu: 0, prod: 0, fin: 0, sorti: 0, ven: 0, pou: 0, del: 0, debV: 0, entV: 0, recuV: 0, prodV: 0, finV: 0, sortiV: 0, venV: 0, pouV: 0, delV: 0 };
    for (const q of L) { for (const k of ['deb', 'ent', 'recu', 'prod', 'fin', 'sorti', 'ven', 'pou', 'del']) { o[k] += q[k]; o[k + 'V'] += q[k] * q.prix; } } o.pct = o.sortiV > 0 ? 100 * o.delV / o.sortiV : 0; return o; };
  const parCat = sid => CAT.map(([c]) => ({ c, L: parMag[sid].filter(q => q.c === c) })).map(x => ({ ...x, T: tot(x.L) }));
  const SF = [3, 6];
  const fuite = (t, pctSeuil = SF) => { const pct = t.pct != null ? t.pct : 100 * t.del / Math.max(1, t.sorti); return pill(ton(pct, pctSeuil), pc(pct)); };
  const OUVRE = { 3: ['Sandwiches garnis', 'Petite Boulangerie'], 5: ['Tartes'], 2: ['Viennoiserie Ind.'], 4: [] };
  const ordre = SHOPS.map(s => ({ s, T: tot(parMag[s.id]) })).sort((a, b) => b.T.delV - a.T.delV);
  const R = tot(SHOPS.flatMap(s => parMag[s.id]));
  // La matrice : catégories × magasins.
  const matrice = `<div class="vf-card"><div class="vf-ct"><h3>Où ça fuit — catégories × magasins</h3><span class="s">delta en % de ce qui est sorti des stocks · un clic ouvre la catégorie dans le tableau du magasin</span></div>
    <table class="vf-t"><thead><tr><th class="g">Catégorie</th>${ordre.map(({ s }) => `<th>${s.court}</th>`).join('')}<th>Réseau</th></tr></thead><tbody>
    ${CAT.map(([c]) => { const cel = ordre.map(({ s }) => { const t = tot(parMag[s.id].filter(q => q.c === c)); return `<td>${fuite(t)}<small>${t.delV >= 0.5 ? eur(t.delV) : '—'}</small></td>`; }).join('');
      const tr = tot(SHOPS.flatMap(s => parMag[s.id].filter(q => q.c === c))); return `<tr><td class="g nom"><b>${c}</b></td>${cel}<td>${fuite(tr)}<small>${eur(tr.delV)}</small></td></tr>`; }).join('')}
    <tr class="tot"><td class="g">Tout le magasin</td>${ordre.map(({ T }) => `<td>${fuite(T)}<small>${eur(T.delV)}</small></td>`).join('')}<td>${fuite(R)}<small>${eur(R.delV)}</small></td></tr></tbody></table></div>`;
  // Un tableau par magasin, la même structure pour tous.
  const tabMag = ({ s, T }) => {
    const ouv = OUVRE[s.id];
    const ligneP = q => `<tr class="pr"><td class="g nom" style="padding-left:30px">${esc(q.nom)}${q.src ? '<span class="vf-est">centrale</span>' : ''}<small>${nf(q.prix, 2)} € HT</small></td><td>${q.deb || '<span class="mu">—</span>'}</td><td>${q.recu || '<span class="mu">—</span>'}</td><td>${q.prod || '<span class="mu">—</span>'}</td><td>${q.fin || '<span class="mu">—</span>'}</td><td><b>${q.sorti}</b></td><td>${q.ven}</td><td>${q.pou}</td><td><b>${q.del}</b> pc${q.del > 1 ? 's' : ''}</td><td>${fuite(q)}</td><td>${eur(q.del * q.prix)}</td></tr>`;
    const ligneC = ({ c, L, T: t }) => { const o = ouv.includes(c); return `<tr class="${t.pct > SF[1] ? 'sel' : ''}"><td class="g nom"><b>${o ? '▾' : '▸'} ${c}</b><small>${L.length} produit${L.length > 1 ? 's' : ''}</small></td><td>${t.deb || '<span class="mu">—</span>'}</td><td>${t.recu || '<span class="mu">—</span>'}</td><td>${t.prod || '<span class="mu">—</span>'}</td><td>${t.fin || '<span class="mu">—</span>'}</td><td><b>${t.sorti}</b></td><td>${t.ven}</td><td>${t.pou}</td><td><b>${t.del}</b> pc${t.del > 1 ? 's' : ''}</td><td>${fuite(t)}</td><td><b>${eur(t.delV)}</b></td></tr>${o ? L.slice().sort((a, b) => b.del * b.prix - a.del * a.prix).map(ligneP).join('') : ''}`; };
    const cats = parCat(s.id);
    const pire = cats.slice().sort((a, b) => b.T.delV - a.T.delV);
    const v = T.pct > SF[1] ? ['ko', 'Fuite'] : T.pct > SF[0] ? ['att', 'À surveiller'] : ['ok', 'Pas de fuite'];
    return `<div class="vf-card"><div class="vf-ct"><h3>${s.court}</h3><span class="s">${esc(s.nom)}</span><span class="r"><span class="vf-v ${v[0]}"><i></i>${v[1]} · ${pc(T.pct)} · ${eur(T.delV)}</span></span></div>
      <div class="vf-lect">${T.pct > SF[0] ? `<b>${pire[0].c}</b> porte ${nf(100 * pire[0].T.delV / T.delV, 0)} % de la fuite (${eur(pire[0].T.delV)}), puis ${pire[1].c} (${eur(pire[1].T.delV)}) et ${pire[2].c} (${eur(pire[2].T.delV)}).` : `Toutes les catégories restent sous ${SF[0]} % : ${eur(T.delV)} de delta sur ${eur(T.sortiV)} sortis.`}</div>
      <table class="vf-t"><thead>
        <tr class="grp"><th></th><th colspan="5"><span>Les stocks — pièces</span></th><th colspan="2"><span>Ce qui est déclaré</span></th><th colspan="3"><span>Delta = sorti − ventes − poubelle</span></th></tr>
        <tr><th class="g">Catégorie / produit</th><th>Stock début<small>lun. 19/10 matin</small></th><th>+ Entrées<small>commandes validées</small></th><th>+ Produit<small>cuissons validées</small></th><th>− Stock fin<small>dim. 25/10 soir</small></th><th>= Sorti</th><th>Ventes<small>caisse</small></th><th>Poubelle<small>déclarée</small></th><th>Delta</th><th>% du sorti</th><th>€<small>prix de vente</small></th></tr></thead>
      <tbody>${cats.map(ligneC).join('')}
        <tr class="tot"><td class="g">${s.court} — total</td><td>${T.deb}</td><td>${T.recu}</td><td>${T.prod}</td><td>${T.fin}</td><td>${T.sorti}</td><td>${T.ven}</td><td>${T.pou}</td><td>${T.del} pcs</td><td>${fuite(T)}</td><td>${eur(T.delV)}</td></tr></tbody></table></div>`;
  };
  const corps = `${filtres('Semaine', `Semaine 43 <small>lun. 19 → dim. 25 oct. 2026</small>`, 'Les quatre magasins', '<div class="vf-seg"><button class="on">Produits finis</button><button>Matières premières</button></div>')}
  <div class="vf-kpis">${ordre.map(({ s, T }) => kpi(s.court, pc(T.pct), `${eur(T.delV)} de fuite · ${nf(T.del)} pièces sur ${nf(T.sorti)} sorties`, ton(T.pct, SF))).join('')}
    ${kpi('Réseau', pc(R.pct), `${eur(R.delV)} sur ${eur(R.sortiV)} sortis des stocks`, ton(R.pct, SF))}</div>
  <div class="vf-card"><div class="vf-lect" style="border-bottom:none"><b>Semaine 43 :</b> ${nf(R.del)} pièces (${eur(R.delV)}) sont sorties des stocks des quatre magasins sans passer ni en caisse ni en poubelle. ${ordre[0].s.court} en porte ${nf(100 * ordre[0].T.delV / R.delV, 0)} %. Chaque magasin a le même tableau, dans le même ordre de catégories : une ligne rouge d’un magasin se compare d’un coup d’œil à la même ligne des autres.</div></div>
  ${matrice}
  ${ordre.map(tabMag).join('')}
  <div class="vf-card"><div class="vf-pied" style="border-top:none">Stock début : ce qui restait en vitrine et en réserve le lundi matin (clôture du dimanche précédent). Entrées : les produits finis des commandes entrantes validées à la réception (centrale). Produit : les cuissons validées. Stock fin : la clôture du dimanche soir. Sorti = début + entrées + produit − fin. Ventes : tickets du panel, commandes comprises. Poubelle : déclarée en caisse. Delta = sorti − ventes − poubelle : ce qui a quitté le stock sans trace — la fuite. Seuils : vert ≤ 3 %, orange ≤ 6 % du sorti. La même lecture existe en matières premières, où l’entrée est la commande validée : bouton « Matières premières ».</div></div>`;
  page({ fichier: 'g.html', titre: 'Fuites par magasin', onglet: 'fuites', corps });

  /* ---------- G-MATIÈRES : le même tableau, en matières premières ----------
   * Stock début (photo du soir du dimanche 18/10) + entrées (commandes entrantes VALIDÉES à la réception)
   * − stock fin (photo du dimanche 25/10) = sorti ; sorti − ventes × recettes − poubelle × recettes = delta. */
  const MCAT = [
    ['Farines et céréales', [['Farine T65', 'kg', 0.70, 7], ['Farine de seigle', 'kg', 0.95, 1.5], ['Mélange 10 céréales', 'kg', 2.40, 1.2]]],
    ['Beurres et laitages', [['Beurre doux 82 %', 'kg', 7.95, 7], ['Beurre de tourage', 'kg', 8.60, 6], ['Crème 35 %', 'l', 4.10, 3], ['Lait entier', 'l', 0.95, 1.2], ['Mozzarella', 'kg', 9.80, 2]]],
    ['Œufs', [['Œufs entiers', 'pcs', 0.20, 3]]],
    ['Chocolat et sucre', [['Chocolat noir 58 %', 'kg', 13.00, 5], ['Bâtons chocolat', 'pcs', 0.045, 1.5], ['Sucre', 'kg', 1.10, 1.2], ['Sucre perlé', 'kg', 3.20, 0.3]]],
    ['Charcuterie et poisson', [['Jambon cuit supérieur', 'kg', 11.40, 4], ['Thon à l’huile', 'kg', 26.43, 4], ['Saumon fumé', 'kg', 32.00, 3], ['Poulet rôti effiloché', 'kg', 12.50, 3]]],
    ['Fruits et légumes', [['Tomates', 'kg', 2.60, 1.5], ['Salade', 'kg', 4.20, 1], ['Poires au sirop', 'kg', 3.40, 1]]],
    ['Produits finis (centrale)', [['Baguette Tradition 500 g.', 'pcs', 1.4656, 9]]],
    ['Emballages', [['Sachets papier', 'pcs', 0.03, 1.5, 'hors'], ['Boîtes pâtisserie', 'pcs', 0.22, 1.2, 'hors']]],
  ];
  const MFUITE = {
    3: { 'Beurre de tourage': 0.16, 'Thon à l’huile': 0.15, 'Baguette Tradition 500 g.': 0.14, 'Jambon cuit supérieur': 0.13, 'Crème 35 %': 0.09 },
    5: { 'Poulet rôti effiloché': 0.18, 'Chocolat noir 58 %': 0.14, 'Crème 35 %': 0.12 },
    2: { 'Beurre de tourage': 0.22, 'Œufs entiers': 0.10 },
    4: {},
  };
  const MPCT = { 3: 11.4, 5: 8.8, 2: 5.1, 4: 2.6 };
  const MAT = MCAT.flatMap(([c, ms]) => ms.map(([nom, u, prix, w, k]) => ({ c, nom, u, prix, w, hors: k === 'hors' })));
  const fq = (q, u) => q == null ? '<span class="mu">—</span>' : nf(q, u === 'pcs' ? 0 : 1) + ' ' + u;
  const mparMag = {};
  for (const s of SHOPS) {
    const A = AGG[s.id][iW];
    const ent = A.entre, deb = ent * 0.55 * rb(0.08), fin = deb * rb(0.06);
    const L = MAT.map(m => ({ ...m, wq: m.w * rb(0.15) }));
    const totW = L.reduce((a, q) => a + q.wq, 0);
    const sortiSuivi = L.filter(q => !q.hors).reduce((a, q) => a + (deb + ent - fin) * q.wq / totW, 0);
    const delT = sortiSuivi * MPCT[s.id] / 100;
    const fu = MFUITE[s.id], nomme = Object.values(fu).reduce((a, x) => a + x, 0);
    const libre = L.filter(q => !q.hors && !(q.nom in fu)).reduce((a, q) => a + q.wq, 0);
    const pouPart = A.p / (A.v + A.p);
    for (const q of L) {
      const r = q.u === 'pcs' ? (x => Math.round(x)) : (x => Math.round(x * 10) / 10);
      q.ent = r(ent * q.wq / totW / q.prix); q.deb = r(deb * q.wq / totW / q.prix); q.fin = r(fin * q.wq / totW * rb(0.15) / q.prix);
      q.sorti = r(q.deb + q.ent - q.fin);
      if (q.hors) { q.ven = q.pou = q.del = null; continue; }
      const part = q.nom in fu ? fu[q.nom] : (1 - nomme) * q.wq / libre;
      q.del = r(delT * part * rb(0.12) / q.prix);
      if (s.id === 3 && q.nom === 'Mélange 10 céréales') { q.del = -8; q.note = 'livré le sam. 24/10, commande pas encore validée'; }
      const expl = q.sorti - q.del;
      q.pou = r(expl * pouPart * (q.c === 'Produits finis (centrale)' ? 1.6 : 1));
      q.ven = r(expl - q.pou);
    }
    mparMag[s.id] = L;
  }
  const mtot = L => { const o = { debV: 0, entV: 0, finV: 0, sortiV: 0, venV: 0, pouV: 0, delV: 0, sortiSuiviV: 0 };
    for (const q of L) { o.debV += q.deb * q.prix; o.entV += q.ent * q.prix; o.finV += q.fin * q.prix; o.sortiV += q.sorti * q.prix;
      if (!q.hors) { o.venV += q.ven * q.prix; o.pouV += q.pou * q.prix; o.delV += q.del * q.prix; o.sortiSuiviV += q.sorti * q.prix; } }
    o.pct = o.sortiSuiviV > 0 ? 100 * o.delV / o.sortiSuiviV : null; return o; };
  const mfuite = t => t.pct == null ? pill('mu', 'hors recette') : pill(ton(t.pct, SF), (t.pct > 0 ? '' : '') + pc(t.pct));
  const mordre = SHOPS.map(s => ({ s, T: mtot(mparMag[s.id]) })).sort((a, b) => b.T.delV - a.T.delV);
  const MR = mtot(SHOPS.flatMap(s => mparMag[s.id]));
  const MOUVRE = { 3: ['Charcuterie et poisson', 'Farines et céréales'], 5: ['Charcuterie et poisson'], 2: ['Beurres et laitages'], 4: [] };
  const mmatrice = `<div class="vf-card"><div class="vf-ct"><h3>Où ça fuit — catégories de matières × magasins</h3><span class="s">delta en % de la matière sortie des stocks · au coût</span></div>
    <table class="vf-t"><thead><tr><th class="g">Catégorie de matière</th>${mordre.map(({ s }) => `<th>${s.court}</th>`).join('')}<th>Réseau</th></tr></thead><tbody>
    ${MCAT.map(([c]) => { const cel = mordre.map(({ s }) => { const t = mtot(mparMag[s.id].filter(q => q.c === c)); return `<td>${mfuite(t)}<small>${t.pct == null ? eur(t.sortiV) + ' sortis' : eur(t.delV)}</small></td>`; }).join('');
      const tr = mtot(SHOPS.flatMap(s => mparMag[s.id].filter(q => q.c === c))); return `<tr><td class="g nom"><b>${c}</b></td>${cel}<td>${mfuite(tr)}<small>${tr.pct == null ? eur(tr.sortiV) + ' sortis' : eur(tr.delV)}</small></td></tr>`; }).join('')}
    <tr class="tot"><td class="g">Tout le magasin</td>${mordre.map(({ T }) => `<td>${mfuite(T)}<small>${eur(T.delV)}</small></td>`).join('')}<td>${mfuite(MR)}<small>${eur(MR.delV)}</small></td></tr></tbody></table></div>`;
  // Les entrées de la semaine à Gosselies : les commandes, une par une, avec leur validation.
  const hex = () => Array.from({ length: 16 }, () => '0123456789ABCDEF'[Math.floor(RG() * 16)]).join('');
  const entG = AGG[3][iW].entre;
  const CMD = [
    ['SLFood', 'centrale', 'ven. 16/10', 'lun. 19/10 08:42', 38, 0.36, 'att', '2 lignes corrigées à la réception : −6 baguettes, −1 kg de beurre'],
    ['CDT', '', 'dim. 18/10', 'lun. 19/10 09:15', 14, 0.12, 'ok', 'conforme à la commande'],
    ['SLFood', 'centrale', 'lun. 19/10', 'mer. 21/10 07:58', 31, 0.30, 'ok', 'conforme à la commande'],
    ['Rawette', '', 'mar. 20/10', 'mer. 21/10 10:20', 9, 0.10, 'ok', 'conforme à la commande'],
    ['SLFood', 'centrale', 'mer. 21/10', 'ven. 23/10 08:05', 27, 0.12, 'ok', 'conforme à la commande'],
    ['SLFood', 'centrale', 'ven. 23/10', null, 12, null, 'ko', 'livrée le sam. 24/10 (expédition du fournisseur), pas encore validée au panel : ses matières ne sont pas dans les entrées'],
  ];
  const cmdCard = `<div class="vf-card"><div class="vf-ct"><h3>Les entrées de la semaine — Gosselies</h3><span class="s">les commandes entrantes, une par une · l’entrée compte au jour de sa validation</span><span class="r"><select class="vf-sel"><option>Gosselies</option></select></span></div>
    <table class="vf-t"><thead><tr><th class="g">Commande</th><th class="g">Fournisseur</th><th class="g">Commandée</th><th class="g">Validée à la réception</th><th>Lignes</th><th>Valeur<small>au prix du magasin</small></th><th class="g">À la réception</th></tr></thead><tbody>
    ${CMD.map(([f, c, le, val, n, part, t, txt]) => `<tr${t === 'ko' ? ' class="sel"' : ''}><td class="g nom"><b>ORD-3-${hex()}</b></td><td class="g">${f}${c ? '<span class="vf-est">centrale</span>' : ''}</td><td class="g mu">${le}</td><td class="g">${val ? `<span class="vf-v ok"><i></i>${val}</span>` : '<span class="vf-v ko"><i></i>pas validée</span>'}</td><td>${n}</td><td>${part ? eur(entG * part) : '<span class="mu">—</span>'}</td><td class="g wrap" style="font-size:11.5px">${txt}</td></tr>`).join('')}
    <tr class="tot"><td class="g">Entrées validées</td><td></td><td></td><td class="g">5 commandes</td><td>119</td><td>${eur(entG)}</td><td class="g" style="font-weight:400;font-size:11.5px">= l’Entré de l’audit des flux</td></tr></tbody></table>
    <div class="vf-pied">La commande se lit par /deliveries/{id} (aucune route ne liste les commandes : le cockpit balaie les identifiants récents, comme le suivi des achats). Sa date de validation est <code>delivered_on</code>, posée quand le magasin valide la réception (POST /orders/{id}/delivery). La commande ne porte aucun montant : chaque ligne validée est valorisée au prix du magasin (/shops/{id}/materials). Une commande livrée mais pas validée n’entre pas : ses matières ressortent en delta négatif, et la ligne le dit.</div></div>`;
  const mtab = ({ s, T }) => {
    const ouv = MOUVRE[s.id];
    const ligneM = q => `<tr class="pr"><td class="g nom" style="padding-left:30px">${esc(q.nom)}<small>${nf(q.prix, Number.isInteger(Math.round(q.prix * 1e4) / 100) ? 2 : 4)} € / ${q.u}${q.note ? ` <span class="vf-est" style="background:#fbe3e6;color:#C0182B">livrée, pas validée</span>` : ''}</small></td>
      <td>${fq(q.deb, q.u)}</td><td>${fq(q.ent, q.u)}</td><td>${fq(q.fin, q.u)}</td><td><b>${fq(q.sorti, q.u)}</b></td><td>${fq(q.ven, q.u)}</td><td>${fq(q.pou, q.u)}</td>
      <td><b>${q.del == null ? '<span class="mu">—</span>' : (q.del > 0 ? '+' : '') + fq(q.del, q.u)}</b></td><td>${q.hors ? pill('mu', 'hors recette') : pill(q.del < 0 ? 'att' : ton(100 * q.del / q.sorti, SF), pc(100 * q.del / q.sorti))}</td><td>${q.del == null ? '<span class="mu">—</span>' : eur(q.del * q.prix)}</td></tr>`;
    const cats = MCAT.map(([c]) => ({ c, L: mparMag[s.id].filter(q => q.c === c) })).map(x => ({ ...x, T: mtot(x.L) }));
    const ligneC = ({ c, L, T: t }) => { const o = ouv.includes(c); return `<tr class="${t.pct != null && t.pct > SF[1] ? 'sel' : ''}"><td class="g nom"><b>${o ? '▾' : '▸'} ${c}</b><small>${L.length} matière${L.length > 1 ? 's' : ''}</small></td>
      <td>${eur(t.debV)}</td><td>${eur(t.entV)}</td><td>${eur(t.finV)}</td><td><b>${eur(t.sortiV)}</b></td><td>${t.pct == null ? '<span class="mu">—</span>' : eur(t.venV)}</td><td>${t.pct == null ? '<span class="mu">—</span>' : eur(t.pouV)}</td><td><b>${t.pct == null ? '<span class="mu">—</span>' : eur(t.delV)}</b></td><td>${mfuite(t)}</td><td><b>${t.pct == null ? '<span class="mu">—</span>' : eur(t.delV)}</b></td></tr>${o ? L.slice().sort((a, b) => Math.abs((b.del || 0) * b.prix) - Math.abs((a.del || 0) * a.prix)).map(ligneM).join('') : ''}`; };
    const pire = cats.filter(x => x.T.pct != null).sort((a, b) => b.T.delV - a.T.delV);
    const v = T.pct > SF[1] ? ['ko', 'Fuite'] : T.pct > SF[0] ? ['att', 'À surveiller'] : ['ok', 'Pas de fuite'];
    return `<div class="vf-card"><div class="vf-ct"><h3>${s.court}</h3><span class="s">${esc(s.nom)} · matières premières, au coût</span><span class="r"><span class="vf-v ${v[0]}"><i></i>${v[1]} · ${pc(T.pct)} · ${eur(T.delV)}</span></span></div>
      <div class="vf-lect">${T.pct > SF[0] ? `<b>${pire[0].c}</b> porte ${nf(100 * pire[0].T.delV / T.delV, 0)} % de la fuite (${eur(pire[0].T.delV)}), puis ${pire[1].c} (${eur(pire[1].T.delV)}).` : `Toutes les catégories restent sous ${SF[0]} % : ${eur(T.delV)} de delta sur ${eur(T.sortiSuiviV)} de matière sortie.`}</div>
      <table class="vf-t"><thead>
        <tr class="grp"><th></th><th colspan="4"><span>Les stocks — matières</span></th><th colspan="2"><span>Ce qui est déclaré, passé par les recettes</span></th><th colspan="3"><span>Delta = sorti − ventes − poubelle</span></th></tr>
        <tr><th class="g">Catégorie / matière</th><th>Stock début<small>dim. 18/10 soir</small></th><th>+ Entrées<small>commandes validées</small></th><th>− Stock fin<small>dim. 25/10 soir</small></th><th>= Sorti<small>consommé</small></th><th>Ventes<small>× recettes</small></th><th>Poubelle<small>× recettes</small></th><th>Delta</th><th>% du sorti</th><th>€<small>au coût</small></th></tr></thead>
      <tbody>${cats.map(ligneC).join('')}
        <tr class="tot"><td class="g">${s.court} — total</td><td>${eur(T.debV)}</td><td>${eur(T.entV)}</td><td>${eur(T.finV)}</td><td>${eur(T.sortiV)}</td><td>${eur(T.venV)}</td><td>${eur(T.pouV)}</td><td>${eur(T.delV)}</td><td>${mfuite(T)}</td><td>${eur(T.delV)}</td></tr></tbody></table></div>`;
  };
  const corpsM = `${filtres('Semaine', `Semaine 43 <small>lun. 19 → dim. 25 oct. 2026</small>`, 'Les quatre magasins', '<div class="vf-seg"><button>Produits finis</button><button class="on">Matières premières</button></div>')}
  <div class="vf-kpis">${mordre.map(({ s, T }) => kpi(s.court, pc(T.pct), `${eur(T.delV)} de matière sortie sans trace · entrées validées ${eur(T.entV)}`, ton(T.pct, SF))).join('')}
    ${kpi('Réseau', pc(MR.pct), `${eur(MR.delV)} sur ${eur(MR.sortiSuiviV)} de matière sortie`, ton(MR.pct, SF))}</div>
  <div class="vf-card"><div class="vf-lect" style="border-bottom:none"><b>Semaine 43, en matières :</b> les quatre magasins ont validé ${eur(MR.entV)} de commandes entrantes. Sur la matière sortie des stocks, ${eur(MR.delV)} (${pc(MR.pct)}) n’est passée ni par une vente ni par une poubelle, recettes à l’appui. ${mordre[0].s.court} en porte ${nf(100 * mordre[0].T.delV / MR.delV, 0)} %. Même tableau pour chaque magasin, mêmes catégories dans le même ordre.</div></div>
  ${mmatrice}
  ${cmdCard}
  ${mordre.map(mtab).join('')}
  <div class="vf-card"><div class="vf-pied" style="border-top:none">Stock début et stock fin : la photo du soir du stock matière (/shops/{id}/material-inventory, prise chaque nuit par le cockpit), ou l’inventaire du panel quand il y en a un ce jour-là. Entrées : les commandes entrantes validées à la réception dans la semaine, ligne par ligne, au prix du magasin. Sorti = début + entrées − fin : la matière consommée. Ventes × recettes et poubelle × recettes : les pièces vendues et jetées, dépliées par leurs recettes jusqu’à la matière (les pièces gardées d’une semaine à l’autre comptent avec les ventes). Delta = sorti − ventes − poubelle. Une recette fausse fait un faux delta : voir la bascule des recettes. Les emballages ne passent par aucune recette : suivis à part.</div></div>`;
  page({ fichier: 'g-matieres.html', titre: 'Fuites par magasin — matières premières', onglet: 'fuites', corps: corpsM });
}

/* ---------- planche ---------- */
fs.writeFileSync(path.join(D, 'planche.html'), `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Vérification — planche</title><link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="vf.css"></head><body class="pl">
<h1>Vérification — stocks, ventes, recettes, chiffre d’affaires</h1>
<div class="acc">Une rubrique nouvelle du cockpit. Quatre onglets et une bascule : l’audit des flux (entré · produit · vendu · poubelle · invendu, en %, au jour, à la semaine, au mois), la matière face aux recettes entre deux inventaires, le chiffre d’affaires rapproché quatre fois, l’évolution lue dans une base remplie chaque nuit — et la liste des recettes à corriger pour une gestion à 100 %. Chiffres d’illustration.</div>
<div class="g">${[['a', 'Audit des flux — le réseau, la semaine', 'Par magasin : ce qui entre (au coût), ce qui devient pièces, et où vont les pièces, en % ; le non expliqué jour par jour.'],
  ['a-jour', 'Audit des flux — un jour, produit par produit', 'Gardé d’hier, entré, produit, disponible ; vendu, poubelle, invendu, non expliqué — en pièces et en %.'],
  ['b', 'Audit des flux — un magasin, le mois', 'Jour par jour, le total de chaque semaine et du mois ; où ça fuit, par catégorie.'],
  ['g', 'Fuites par magasin — produits finis', 'Un tableau par magasin, la même structure : stock début + entrées (commandes validées) + produit − stock fin = sorti ; − ventes − poubelle = delta, par catégorie et par produit.'],
  ['g-matieres', 'Fuites par magasin — matières premières', 'Le même tableau en matières : l’entrée est la commande validée à la réception ; les commandes de la semaine, une par une.'],
  ['c', 'Matières ↔ recettes', 'Stock + entré − stock = consommé réel, face au théorique des recettes ; d’où vient l’écart.'],
  ['d', 'Chiffre d’affaires', 'Caisse, P&L, base des redevances, CA attendu par la matière ; les jours à regarder.'],
  ['e', 'La bascule : recettes douteuses ou manquantes', '37 recettes classées par CA concerné ; la couverture vers 100 %.'],
  ['f', 'Évolution', 'La base quotidienne : douze semaines, avant / maintenant, mieux ou moins bien.']].map(([f, t, d]) => `<figure><img src="${f}.png"><figcaption><b>${t}</b> — ${d}</figcaption></figure>`).join('')}</div></body></html>`);

console.log('écrit : a.html a-jour.html b.html c.html d.html e.html f.html g.html g-matieres.html planche.html');
console.log('S43 réseau', { dispo: Math.round(RS.dispo), v: RS.v.toFixed(1), p: RS.p.toFixed(1), i: RS.i.toFixed(1), n: RS.nx.toFixed(1), ratio: RS.ratio.toFixed(1) });
for (const s of SHOPS) { const a = AGG[s.id][iW]; console.log(s.court, { dispo: Math.round(a.dispo), v: a.v.toFixed(1), p: a.p.toFixed(1), i: a.i.toFixed(1), n: a.nx.toFixed(1), ratio: a.ratio && a.ratio.toFixed(1), rec: a.rec.toFixed(1) }); }
