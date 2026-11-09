/* Le dashboard magasin sur téléphone — trois mélanges de A (le héros du jour,
 * les onglets) et de B (les cartes qui portent leur contenu).
 *
 * 390 × 844. Sur mobile, seuls LE JOUR et LA SEMAINE sont retenus.
 *
 * Les chiffres sont RÉELS : Atelier by Berlo — Corbais, lundi 14 septembre
 * 2026, la semaine du 14 au 20 arrêtée au 15, la valeur du magasin — lus sur
 * le serveur. Une maquette qui ment sur ses ordres de grandeur ne dit rien de
 * ce qui tiendra à l'écran. */
const fs = require('fs'), path = require('path');
const OUT = __dirname;
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 });
const eur = n => nf(n) + ' €';

const D = {
  shop: 'Berlo — Corbais', jour: 'lundi 14 septembre 2026',
  ca: 3916.84, objectif: 4383.11, tickets: 296, panier: 13.23,
  matierePct: 36.4, labourPct: 31.9, net: 1242.20, netPct: 31.7,
  taches: { total: 21, faites: 0, bloquantes: 5 },
  nc: 1, pic: '12 – 13 h', picClients: 45,
  sem: {
    du: '14 sept.', au: '20 sept.', objectif: 35177.41, attendu: 9026.52, realise: 11325.44,
    ecart: 2298.92, clients: 114,
    jours: [['L', 14, 4383.11, 3916.84], ['Ma', 15, 4643, 7408.60], ['Me', 16, 4647, null],
            ['J', 17, 4693, null], ['V', 18, 4763, null], ['S', 19, 5952, null], ['D', 20, 6096, null]]
  },
  valeur: 287555, valMoyenne: 47926,
  trim: [['T1 25', 298380], ['T2 25', 299552], ['T3 25', 279341], ['T4 25', 325229], ['T1 26', 305837], ['T2 26', 315622]]
};
const atteinte = D.ca / D.objectif * 100;
const manque = D.objectif - D.ca;
const clientsMoins = Math.round(manque / D.panier);

/* --- briques communes ----------------------------------------------------- */
const HEAD = `<div class="mh"><img src="/public/assets/img/logo.png" alt="">
  <div><div class="t">${esc(D.shop)}</div><div class="d">${esc(D.jour)}</div></div>
  <span class="sp"></span><div class="ic">↻</div></div>`;

const TABS = `<div class="a-tabs deux"><div class="on"><i>◉</i>Le jour</div><div><i>▤</i>La semaine</div></div>`;

const ALERTE = `<div class="x-alerte"><span class="n">${D.taches.bloquantes}</span>
  <span class="t"><b>tâches bloquantes</b>exploitation non rendue · ${D.taches.faites} / ${D.taches.total} faites aujourd’hui</span>
  <span class="ch">›</span></div>`;

const anneau = (pct, size) => {
  const r = size / 2 - 5, c = 2 * Math.PI * r, part = c * Math.min(1, pct / 100);
  return `<svg class="ring" viewBox="0 0 ${size} ${size}">
    <circle cx="${size / 2}" cy="${size / 2}" r="${r}" fill="none" stroke="#EAE4DC" stroke-width="7"></circle>
    <circle cx="${size / 2}" cy="${size / 2}" r="${r}" fill="none" stroke="#8D1D2C" stroke-width="7" stroke-linecap="round"
      stroke-dasharray="${part.toFixed(1)} ${(c - part).toFixed(1)}" transform="rotate(-90 ${size / 2} ${size / 2})"></circle>
    <text x="${size / 2}" y="${size / 2 + 5}" text-anchor="middle">${nf(pct, 0)} %</text></svg>`;
};

const HERO = (avecAnneau) => `<div class="x-hero${avecAnneau ? ' anneau' : ''}">
  ${avecAnneau ? anneau(atteinte, 66) : ''}
  <div class="k">Chiffre d’affaires du jour</div>
  <div class="v">${eur(D.ca)}</div>
  <div class="o">objectif ${eur(D.objectif)}${avecAnneau ? '' : ' · ' + nf(atteinte, 1) + ' %'}</div>
  ${avecAnneau ? '' : `<div class="bar"><i style="width:${atteinte.toFixed(1)}%"></i></div>`}
  <div class="dl"${avecAnneau ? ' style="margin-top:9px"' : ''}><span><b>− ${eur(manque)}</b> sur l’objectif</span><span>${clientsMoins} clients de moins</span></div>
</div>`;

const BARRES_SEMAINE = `<div class="b-sem">${D.sem.jours.map(([n, d, obj, ca]) => {
  const h = Math.round(Math.min(1.35, (ca || 0) / obj) * 34);
  return `<div><i class="${ca == null ? 'att' : (ca >= obj ? 'ok' : 'ko')}" style="height:${ca == null ? 3 : Math.max(3, h)}px"></i><span>${n}${d}</span></div>`;
}).join('')}</div>`;

const CASCADE = `<div class="b-casc">
  <div><span class="l">Chiffre d’affaires</span><span class="m"><i style="width:100%;background:#78554B"></i></span><span class="n">${eur(D.ca)}</span></div>
  <div><span class="l">− Coût matière</span><span class="m"><i style="width:${D.matierePct}%;background:#C0182B"></i></span><span class="n">${nf(D.matierePct, 1)} %</span></div>
  <div><span class="l">− Main-d’œuvre</span><span class="m"><i style="width:${D.labourPct}%;background:#B26A00"></i></span><span class="n">${nf(D.labourPct, 1)} %</span></div>
  <div><span class="l">= Résultat</span><span class="m"><i style="width:${D.netPct}%;background:#2d7a3e"></i></span><span class="n">${eur(D.net)}</span></div>
</div>`;

const vs = D.trim.map(t => t[1]);
const mn = Math.min(...vs), mx = Math.max(...vs), W = 320, H = 34;
const px = i => (3 + i * (W - 6) / (vs.length - 1)).toFixed(1);
const py = v => (H - 4 - (H - 10) * (v - mn) / ((mx - mn) || 1)).toFixed(1);
const pts = vs.map((v, i) => px(i) + ',' + py(v)).join(' ');
const COURBE = `<svg class="x-spk" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none">
  <polygon points="0,${H} ${pts} ${W},${H}"></polygon><polyline points="${pts}"></polyline>
  <circle cx="${px(vs.length - 1)}" cy="${py(vs[vs.length - 1])}" r="2.4"></circle></svg>`;

const PH = (corps, deborde) => `<div class="ph${deborde ? ' deborde' : ''}"><div class="enc"></div>${HEAD}<div class="sc">${corps}</div>${TABS}</div>`;

/* --- 1 : héros + cartes pleines ------------------------------------------- */
const V1 = PH(`${HERO(false)}${ALERTE}
  <div class="x-c"><div class="hd"><span class="k">La semaine<em>${esc(D.sem.du)} → ${esc(D.sem.au)} · ${eur(D.sem.realise)} contre ${eur(D.sem.attendu)} attendus</em></span>
    <span class="v ok">+ ${eur(D.sem.ecart)}</span><span class="ch">›</span></div>
    <div class="cp">${BARRES_SEMAINE}</div></div>
  <div class="x-c"><div class="hd"><span class="k">Les tâches du jour<em>${D.taches.total} obligatoires</em></span>
    <span class="v">${D.taches.faites} / ${D.taches.total}</span><span class="ch">›</span></div>
    <div class="cp"><div class="b-pills"><span class="ko">${D.taches.bloquantes} bloquantes</span><span>${D.taches.total - D.taches.faites} non faites</span><span class="ok">${D.nc} non-conformité ce mois</span></div></div></div>
  <div class="x-c"><div class="hd"><span class="k">Le compte du jour<em>${nf(D.tickets)} clients · panier ${nf(D.panier, 2)} €</em></span>
    <span class="v">${eur(D.net)}</span><span class="ch">›</span></div>
    <div class="cp">${CASCADE}</div></div>
  <div class="x-c or"><div class="hd"><span class="k">Ce que vaut le magasin<em>18 mois clos · × 12 ÷ 6</em></span>
    <span class="v">${eur(D.valeur)}</span><span class="ch">›</span></div>${COURBE}</div>`, true);

/* --- 2 : héros à anneau + grille ------------------------------------------ */
const V2 = PH(`${HERO(true)}${ALERTE}
  <div class="x-grid">
    <div><div class="k">Tâches du jour</div><div class="v">${D.taches.faites} / ${D.taches.total}</div><div class="s"><span class="ko">${D.taches.bloquantes} bloquantes</span></div></div>
    <div><div class="k">Non-conformités</div><div class="v">${D.nc}</div><div class="s">ce mois · <span class="ok">1 reprise</span></div></div>
    <div><div class="k">Heure de pointe</div><div class="v">${esc(D.pic)}</div><div class="s">${D.picClients} clients</div></div>
    <div><div class="k">Panier</div><div class="v">${nf(D.panier, 2)} €</div><div class="s">${nf(D.tickets)} clients</div></div>
  </div>
  <div class="x-c"><div class="hd"><span class="k">La semaine<em>${eur(D.sem.realise)} contre ${eur(D.sem.attendu)} attendus · ${D.sem.clients} clients d’avance</em></span>
    <span class="v ok">+ ${eur(D.sem.ecart)}</span><span class="ch">›</span></div>
    <div class="cp">${BARRES_SEMAINE}</div></div>
  <div class="x-c or"><div class="hd"><span class="k">Ce que vaut le magasin<em>${eur(D.valMoyenne)} de CA mensuel moyen · 18 mois</em></span>
    <span class="v">${eur(D.valeur)}</span><span class="ch">›</span></div>${COURBE}</div>`);

/* --- 3 : héros + cartes repliées, une seule ouverte ------------------------ */
const V3 = PH(`${HERO(false)}${ALERTE}
  <div class="x-c"><div class="hd"><span class="k">La semaine<em>${esc(D.sem.du)} → ${esc(D.sem.au)}</em></span>
    <span class="v ok">+ ${eur(D.sem.ecart)}</span><span class="ch">⌄</span></div>
    <div class="cp"><div class="s" style="font-size:11px;color:var(--color-text-muted);line-height:1.4">${eur(D.sem.realise)} contre ${eur(D.sem.attendu)} attendus à ce jour — <b style="color:#2d7a3e">${D.sem.clients} clients d’avance</b>. Objectif ${eur(D.sem.objectif)}.</div>${BARRES_SEMAINE}</div></div>
  <div class="x-c"><div class="hd"><span class="k">Les tâches du jour<em>${D.taches.bloquantes} bloquantes · ${D.taches.total} obligatoires</em></span>
    <span class="v">${D.taches.faites} / ${D.taches.total}</span><span class="ch">›</span></div></div>
  <div class="x-c"><div class="hd"><span class="k">Non-conformités<em>septembre · 43 tâches notées</em></span>
    <span class="v">${D.nc}</span><span class="ch">›</span></div></div>
  <div class="x-c"><div class="hd"><span class="k">Les heures<em>pic à ${esc(D.pic)} · ${D.picClients} clients</em></span>
    <span class="v">${esc(D.pic)}</span><span class="ch">›</span></div></div>
  <div class="x-c"><div class="hd"><span class="k">Le compte du jour<em>matière ${nf(D.matierePct, 1)} % · main-d’œuvre ${nf(D.labourPct, 1)} %</em></span>
    <span class="v">${eur(D.net)}</span><span class="ch">›</span></div></div>
  <div class="x-c or"><div class="hd"><span class="k">Ce que vaut le magasin<em>18 mois clos · × 12 ÷ 6</em></span>
    <span class="v">${eur(D.valeur)}</span><span class="ch">›</span></div></div>`, true);

/* ----------------------------------------------------------------------- */
const PAGES = [
  ['ab1-heros-cartes.html', '1 — le héros, puis des cartes ouvertes', V1,
   'Le chiffre du jour de A en tête, avec sa barre et l’alerte. Dessous, les cartes de B, toutes ouvertes : la semaine avec ses sept barres, les tâches avec leurs pastilles, le compte du jour en cascade, la valeur avec sa courbe. Un seul défilement descend tout ; rien ne demande de clic pour être lu.'],
  ['ab2-heros-grille.html', '2 — le héros à anneau, puis une grille', V2,
   'Le héros reprend l’anneau de B : le pourcentage se lit sans barre. Dessous, quatre petites cartes en grille — tâches, non-conformités, heure de pointe, panier — puis la semaine et la valeur en pleine largeur. La forme la plus dense : tout le jour tient sur un écran, presque sans défiler.'],
  ['ab3-heros-repli.html', '3 — le héros, puis des cartes repliées', V3,
   'Les cartes de B, mais repliées : chacune montre son titre et son chiffre, et s’ouvre sur place. La semaine est montrée ouverte pour l’exemple. C’est la forme la plus courte à l’arrivée et celle qui laisse le plus de place au chiffre du jour ; il faut un geste par carte pour aller au détail.'],
];

for (const [f, titre, corps] of PAGES) {
  fs.writeFileSync(path.join(OUT, f), `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(titre)}</title>
<link rel="stylesheet" href="/public/assets/ds/global.css">
<link rel="stylesheet" href="/public/dashboard/dashboard.css">
<link rel="stylesheet" href="mobile.css">
<style>body{margin:0;background:#F5F1EB;display:flex;justify-content:center;padding:24px 0}</style>
</head><body>${corps}</body></html>`);
  console.log('écrit', f);
}

fs.writeFileSync(path.join(OUT, 'planche.html'), `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard magasin sur téléphone — A + B, trois mélanges</title>
<link rel="stylesheet" href="/public/assets/ds/global.css">
<link rel="stylesheet" href="/public/dashboard/dashboard.css">
<link rel="stylesheet" href="mobile.css">
<style>body{margin:0;background:#F5F1EB}</style>
</head><body><div class="planche">
${PAGES.map(([, titre, corps, sous]) => `<div class="col"><p class="lg">${esc(titre)}<em>${esc(sous)}</em></p>${corps}</div>`).join('')}
</div></body></html>`);
console.log('écrit planche.html');
