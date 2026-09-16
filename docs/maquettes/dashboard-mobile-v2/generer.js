/* Le dashboard magasin au téléphone — reprise, trois directions.
 *
 * La version en ligne ouvre sur le chiffre du jour, puis empile des cartes de
 * même poids. Elle répond à « combien ai-je fait ? ». Un écran qu'on ouvre
 * vingt fois par jour devrait plutôt répondre à « qu'est-ce qui a changé, et
 * qu'est-ce qui cloche ? ». Les trois propositions s'écartent chacune dans une
 * direction : par l'urgence, par la densité, par le sujet.
 *
 * Chiffres RÉELS, Atelier by Max & Sandra — Gosselies, lus sur le serveur :
 *   mardi 15 septembre 2026   /exploitation/jour
 *   semaine 14 → 20, au 16    /exploitation/periode?vue=semaine
 *   tâches et non-conformités /pwa/tasks, /pwa/tasks/nc
 *   stock et valeur           /ventes/stock, /ventes/mensuel
 * Le cas est parlant : la journée atteint son objectif à cinq euros près et
 * perd pourtant vingt-cinq euros, la semaine est à mille neuf cents en retard,
 * et quatre-vingt-quinze références sont à zéro. */
const fs = require('fs'), path = require('path');
const OUT = __dirname;
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 });
const eur = n => nf(n) + ' €';

const D = {
  shop: 'Max & Sandra — Gosselies', jour: 'mardi 15 septembre 2026',
  ca: 1769.50, objectif: 1764.50, tickets: 129, panier: 13.72,
  matierePct: 39.5, brutePct: 60.5, labourPct: 14.8, net: -25.56, netPct: -1.4,
  taches: { total: 21, faites: 17, bloquantes: 1 },
  nc: { n: 2, notees: 8, pire: 'Photo du comptoir — Tartes', note: 3 },
  stock: { refs: 504, alertes: 95, zero: 92, compte: '13/09' },
  sem: { du: '14 sept.', au: '20 sept.', arret: '16 septembre', objectif: 13367.42, attendu: 5195.91,
    realise: 3279.20, ecart: -1916.71, clients: 164,
    jours: [['L', 14, 1666, 1061.40], ['Ma', 15, 1764, 1769.50], ['Me', 16, 1766, 448.30],
            ['J', 17, 1783, null], ['V', 18, 1810, null], ['S', 19, 2262, null], ['D', 20, 2317, null]] },
  valeur: 105486,
};
const att = D.ca / D.objectif * 100;
const attSem = D.sem.realise / D.sem.attendu * 100;

const HEAD = (sous) => `<div class="mh"><img src="/public/assets/img/logo.png" alt="">
  <div><div class="t">${esc(D.shop)}</div><div class="d">${esc(sous)}</div></div>
  <span class="sp"></span><div class="ic">↻</div></div>`;
const TABS = `<div class="tabs"><div class="on"><i>◉</i>Le jour</div><div><i>▤</i>La semaine</div></div>`;
const PH = (corps, tabs) => `<div class="ph"><div class="enc"></div>${corps}${tabs === false ? '' : TABS}</div>`;

/* ------------------------------------------------------------------ A ---- */
/* Les ennuis d'abord, classés par ce qu'ils coûtent. Le chiffre du jour n'est
 * pas la question : il est bon, et la journée perd quand même de l'argent. */
const A = PH(`${HEAD(D.jour)}<div class="sc">
  <div class="a-tete"><span class="n">4</span>
    <span class="t">choses à régler<em>au 15 septembre · rien d’autre ne demande de geste</em></span></div>

  <div class="a-p g1"><span class="v">${D.stock.zero}</span>
    <span class="c"><span class="k">références à zéro</span>
      <span class="s">sur ${nf(D.stock.refs)} à l’inventaire · ${D.stock.alertes} sous leur minimum · compté le ${esc(D.stock.compte)}</span></span>
    <span class="ch">›</span></div>

  <div class="a-p g1"><span class="v">${eur(D.sem.ecart)}</span>
    <span class="c"><span class="k">de retard sur la semaine</span>
      <span class="s">${eur(D.sem.realise)} contre ${eur(D.sem.attendu)} attendus au ${esc(D.sem.arret)} — ${D.sem.clients} clients manquants</span></span>
    <span class="ch">›</span></div>

  <div class="a-p g2"><span class="v">${eur(D.net)}</span>
    <span class="c"><span class="k">la journée perd de l’argent</span>
      <span class="s">objectif atteint à ${nf(att, 1)} %, mais matière ${nf(D.matierePct, 1)} % et main-d’œuvre ${nf(D.labourPct, 1)} %</span></span>
    <span class="ch">›</span></div>

  <div class="a-p g3"><span class="v">${D.nc.n}</span>
    <span class="c"><span class="k">non-conformités hier</span>
      <span class="s">sur ${D.nc.notees} tâches notées · la plus basse : ${esc(D.nc.pire)}, ${D.nc.note}/5</span></span>
    <span class="ch">›</span></div>

  <div class="a-ok"><span class="v">${D.taches.faites}/${D.taches.total}</span>
    <span class="k">tâches faites aujourd’hui — <b>1 bloquante</b> reste ouverte</span><span class="ch">›</span></div>

  <div class="a-calme">
    <div><div class="k">CA du jour</div><div class="v">${eur(D.ca)}</div><div class="s">objectif ${eur(D.objectif)}</div></div>
    <div><div class="k">Clients</div><div class="v">${nf(D.tickets)}</div><div class="s">panier ${nf(D.panier, 2)} €</div></div>
    <div><div class="k">Valeur</div><div class="v" style="color:#8a6508">${nf(D.valeur / 1000)} k€</div><div class="s">18 mois</div></div>
  </div>
</div>`);

/* ------------------------------------------------------------------ B ---- */
/* Aucune boîte. Des filets, des chiffres, et la typographie pour hiérarchie.
 * Tout tient sans défiler ni toucher l'écran. */
const barre = (pct, coul) => `<div class="b-jauge"><i style="width:${Math.min(100, pct).toFixed(1)}%;background:${coul}"></i></div>`;
const semBarres = `<div class="b-sem">${D.sem.jours.map(([n, d, obj, ca]) => {
  const h = ca == null ? 3 : Math.max(3, Math.round(Math.min(1.2, ca / obj) * 26));
  const c = ca == null ? 'var(--color-border-secondary)' : (ca >= obj ? '#2d7a3e' : 'var(--color-primary)');
  return `<div><i style="height:${h}px;background:${c}"></i><span>${n}${d}</span></div>`;
}).join('')}</div>`;

const B = PH(`${HEAD(D.jour + ' · 16:42')}<div class="sc"><div class="b-mur">
  <div class="b-r un"><div><div class="k">Chiffre d’affaires du jour</div>
    <div class="v">${eur(D.ca)}</div>
    <div class="s">objectif ${eur(D.objectif)} · ${nf(att, 1)} % · + ${eur(D.ca - D.objectif)}</div>
    ${barre(att, '#2d7a3e')}</div></div>
  <div class="b-r">
    <div><div class="k">Clients</div><div class="v">${nf(D.tickets)}</div><div class="s">panier ${nf(D.panier, 2)} €</div></div>
    <div><div class="k">Résultat</div><div class="v ko">${eur(D.net)}</div><div class="s">${nf(D.netPct, 1)} % des ventes</div></div>
  </div>
  <div class="b-r">
    <div><div class="k">Matière</div><div class="v wa">${nf(D.matierePct, 1)} %</div><div class="s">marge brute ${nf(D.brutePct, 1)} %</div></div>
    <div><div class="k">Main-d’œuvre</div><div class="v">${nf(D.labourPct, 1)} %</div><div class="s">des ventes</div></div>
  </div>
  <div class="b-r un"><div><div class="k">La semaine · au ${esc(D.sem.arret)}</div>
    <div class="v ko">${eur(D.sem.ecart)}</div>
    <div class="s">${eur(D.sem.realise)} contre ${eur(D.sem.attendu)} attendus · ${D.sem.clients} clients manquants</div>
    ${semBarres}</div></div>
  <div class="b-r">
    <div><div class="k">Tâches</div><div class="v">${D.taches.faites} / ${D.taches.total}</div><div class="s ko">${D.taches.bloquantes} bloquante</div></div>
    <div><div class="k">Non-conformités</div><div class="v wa">${D.nc.n}</div><div class="s">hier · ${D.nc.notees} notées</div></div>
  </div>
  <div class="b-r">
    <div><div class="k">Stock à zéro</div><div class="v ko">${D.stock.zero}</div><div class="s">sur ${nf(D.stock.refs)} références</div></div>
    <div><div class="k">Valeur</div><div class="v or">${nf(D.valeur / 1000)} k€</div><div class="s">18 mois clos</div></div>
  </div>
</div></div>`);

/* ------------------------------------------------------------------ C ---- */
/* Une page plein écran par sujet, qu'on fait glisser du pouce. Ici la
 * troisième page — le magasin — pour montrer ce que devient une liste. */
const C = PH(`${HEAD('mercredi 16 septembre')}
<div class="c-pg">
  <div class="k">Le magasin, ce matin</div>
  <div class="v ko">${D.stock.zero}</div>
  <div class="s">références <b>à zéro</b> sur ${nf(D.stock.refs)} à l’inventaire, comptées le ${esc(D.stock.compte)}.
    <span class="ko">${D.stock.alertes} sous leur minimum</span> en tout.</div>
  <div class="c-l">
    <div><span class="n ko">1</span><span class="t">tâche bloquante<em>${D.taches.faites} des ${D.taches.total} tâches sont faites</em></span></div>
    <div><span class="n" style="color:#B26A00">${D.nc.n}</span><span class="t">non-conformités hier<em>${esc(D.nc.pire)} · ${D.nc.note}/5</em></span></div>
    <div><span class="n ok">${nf(D.valeur / 1000)}</span><span class="t">k€ — ce que vaut le magasin<em>CA moyen des 18 mois clos × 12 ÷ 6</em></span></div>
  </div>
</div>
<div class="c-pts"><i></i><i></i><i class="on"></i><i></i></div>`, false);

/* ----------------------------------------------------------------------- */
const PAGES = [
  ['a-ce-qui-cloche.html', 'A — ce qui cloche, d’abord', A,
   'L’écran ouvre sur les ennuis, classés par ce qu’ils coûtent, et non sur le chiffre du jour. Ici il dit l’essentiel en quatre lignes : 92 références à zéro, 1 917 € de retard sur la semaine, une journée qui atteint son objectif et perd pourtant de l’argent, deux non-conformités. Le CA, les clients et la valeur descendent en bas, au calme. Un écran qu’on ouvre vingt fois par jour n’a pas à répéter le même nombre : il doit dire ce qui a changé.'],
  ['b-le-mur.html', 'B — le mur', B,
   'Aucune boîte, aucun repli : des filets, des chiffres, et la typographie pour toute hiérarchie. Dix mesures tiennent sur un écran sans défiler ni toucher quoi que ce soit — CA, clients, résultat, matière, main-d’œuvre, la semaine avec ses sept barres, les tâches, les non-conformités, le stock, la valeur. C’est la forme la plus dense, et la plus sévère : elle ne hiérarchise pas pour vous.'],
  ['c-une-page.html', 'C — une page par sujet', C,
   'Quatre pages plein écran qu’on fait glisser du pouce — aujourd’hui, la semaine, le magasin, la valeur — avec les points de repère en bas. Un seul chiffre par page, en très grand, et ce qui l’explique dessous. La troisième page est montrée ici. C’est la forme la plus lisible à bout de bras et la plus lente à parcourir en entier.'],
];

for (const [f, titre, corps] of PAGES) {
  fs.writeFileSync(path.join(OUT, f), `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(titre)}</title>
<link rel="stylesheet" href="/public/assets/ds/global.css">
<link rel="stylesheet" href="/public/dashboard/dashboard.css">
<link rel="stylesheet" href="mobile2.css">
<style>body{margin:0;background:#F5F1EB;display:flex;justify-content:center;padding:24px 0}</style>
</head><body>${corps}</body></html>`);
  console.log('écrit', f);
}
fs.writeFileSync(path.join(OUT, 'planche.html'), `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard au téléphone — reprise</title>
<link rel="stylesheet" href="/public/assets/ds/global.css">
<link rel="stylesheet" href="/public/dashboard/dashboard.css">
<link rel="stylesheet" href="mobile2.css">
<style>body{margin:0;background:#F5F1EB}</style>
</head><body><div class="planche">
${PAGES.map(([, titre, corps, sous]) => `<div class="col"><p class="lg">${esc(titre)}<em>${esc(sous)}</em></p>${corps}</div>`).join('')}
</div></body></html>`);
console.log('écrit planche.html');
