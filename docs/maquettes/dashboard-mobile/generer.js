/* Trois formes pour le dashboard magasin sur téléphone (390 × 844).
 *
 * Les chiffres sont RÉELS : Atelier by Berlo — Corbais, lundi 14 septembre
 * 2026, lus sur le serveur (/exploitation/jour, /exploitation/periode,
 * /ventes/mensuel). Une maquette qui ment sur ses ordres de grandeur ne dit
 * rien de ce qui tiendra à l'écran. */
const fs = require('fs'), path = require('path');
const OUT = __dirname;
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 });
const eur = n => nf(n) + ' €';

const D = {
  shop: 'Atelier by Berlo — Corbais', jour: 'lundi 14 septembre 2026',
  ca: 3916.84, objectif: 4383.11, tickets: 296, panier: 13.23,
  matiere: 1425.73, matierePct: 36.4, brute: 2491.11, brutePct: 63.6,
  labour: 1248.92, labourPct: 31.9, net: 1242.20, netPct: 31.7,
  taches: { total: 21, faites: 0, bloquantes: 5 },
  nc: 1, pic: '12 – 13 h', picClients: 45,
  // La semaine en cours, 14 → 20 septembre, arrêtée au 15.
  sem: {
    du: '14 sept.', au: '20 sept.', objectif: 35177.41, attendu: 9026.52, realise: 11325.44,
    ecart: 2298.92, clients: 114, netPct: 59,
    jours: [['L', 14, 4383.11, 3916.84], ['Ma', 15, 4643.00, 7408.60], ['Me', 16, 4647.00, null],
            ['J', 17, 4693.00, null], ['V', 18, 4763.00, null], ['S', 19, 5952.00, null], ['D', 20, 6096.00, null]]
  },
  valeur: 287555, valMoyenne: 47926, valAnnuel: 575110,
  trim: [['T1 25', 298380], ['T2 25', 299552], ['T3 25', 279341], ['T4 25', 325229], ['T1 26', 305837], ['T2 26', 315622]]
};
const atteinte = D.ca / D.objectif * 100;
const manque = D.objectif - D.ca;
const clients = Math.round(manque / D.panier);

const HEAD = (sous) => `<div class="mh"><img src="/public/assets/img/logo.png" alt="">
  <div><div class="t">${esc(D.shop.replace('Atelier by ', ''))}</div><div class="d">${esc(sous)}</div></div>
  <span class="sp"></span><div class="ic">↻</div></div>`;

/* ------------------------------------------------------------------ A ---- */
const A = `<div class="ph"><div class="enc"></div>
${HEAD(D.jour)}
<div class="sc">
  <div class="a-hero">
    <div class="k">Chiffre d’affaires du jour</div>
    <div class="v">${eur(D.ca)}</div>
    <div class="o">objectif ${eur(D.objectif)} · ${nf(atteinte, 1)} %</div>
    <div class="bar"><i style="width:${atteinte.toFixed(1)}%"></i></div>
    <div class="dl"><span><b>− ${eur(manque)}</b> sur l’objectif</span><span>${clients} clients de moins</span></div>
  </div>
  <div class="a-tui">
    <div><div class="k">Clients</div><div class="v">${nf(D.tickets)}</div></div>
    <div><div class="k">Panier</div><div class="v">${nf(D.panier, 2)} €</div></div>
    <div><div class="k">Marge nette</div><div class="v">${nf(D.netPct, 1)} %</div></div>
  </div>
  <div class="a-alerte"><span class="n">${D.taches.bloquantes}</span>
    <span class="t"><b>tâches bloquantes</b>exploitation non rendue · ${D.taches.faites} / ${D.taches.total} faites aujourd’hui</span>
    <span class="ch">›</span></div>
  <div class="a-sec"><div><span class="t">Les tâches du jour<em>${D.taches.total} obligatoires</em></span><span class="v">${D.taches.faites} / ${D.taches.total}</span><span class="ch">›</span></div></div>
  <div class="a-sec"><div><span class="t">Non-conformités<em>septembre · 43 tâches notées</em></span><span class="v">${D.nc}</span><span class="ch">›</span></div></div>
  <div class="a-sec"><div><span class="t">Les heures<em>pic à ${esc(D.pic)} · ${D.picClients} clients</em></span><span class="v">${esc(D.pic)}</span><span class="ch">›</span></div></div>
  <div class="a-sec"><div><span class="t">La semaine<em>${eur(D.sem.realise)} contre ${eur(D.sem.attendu)} attendus</em></span><span class="v ok">+ ${eur(D.sem.ecart)}</span><span class="ch">›</span></div></div>
  <div class="a-sec"><div><span class="t">Le compte du jour<em>matière ${nf(D.matierePct, 1)} % · main-d’œuvre ${nf(D.labourPct, 1)} %</em></span><span class="v">${eur(D.net)}</span><span class="ch">›</span></div></div>
  <div class="a-sec or"><div><span class="t">Ce que vaut le magasin<em>18 mois clos · × 12 ÷ 6</em></span><span class="v">${eur(D.valeur)}</span><span class="ch">›</span></div></div>
</div>
<div class="a-tabs deux">
  <div class="on"><i>◉</i>Le jour</div><div><i>▤</i>La semaine</div>
</div></div>`;

/* ------------------------------------------------------------------ B ---- */
const vs = D.trim.map(t => t[1]);
const mn = Math.min(...vs), mx = Math.max(...vs), W = 330, H = 42;
const px = i => (4 + i * (W - 8) / (vs.length - 1)).toFixed(1);
const py = v => (H - 5 - (H - 12) * (v - mn) / ((mx - mn) || 1)).toFixed(1);
const pts = vs.map((v, i) => px(i) + ',' + py(v)).join(' ');
const R = 26, C = 2 * Math.PI * R, part = C * Math.min(1, atteinte / 100);

const B = `<div class="ph"><div class="enc"></div>
${HEAD(D.jour)}
<div class="sc">
  <div class="b-seg"><div class="on">Le jour</div><div>La semaine</div></div>
  <div class="b-c or"><span class="ch">›</span>
    <div class="k">Ce que vaut le magasin</div>
    <div class="v">${eur(D.valeur)}</div>
    <div class="s">${eur(D.valMoyenne)} de CA mensuel moyen × 12 ÷ 6 · 18 mois clos</div>
    <svg class="b-spk" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none">
      <polygon points="0,${H} ${pts} ${W},${H}"></polygon><polyline points="${pts}"></polyline>
      <circle cx="${px(vs.length - 1)}" cy="${py(vs[vs.length - 1])}" r="2.6"></circle></svg>
    <div class="b-lg"><span>${esc(D.trim[0][0])}</span><span>${esc(D.trim[5][0])}</span></div>
  </div>
  <div class="b-c"><span class="ch">›</span>
    <div class="k">La journée</div>
    <div class="v">${eur(D.ca)}</div>
    <div class="s">objectif ${eur(D.objectif)}<br>${nf(D.tickets)} clients · panier ${nf(D.panier, 2)} €</div>
    <svg class="b-ring" viewBox="0 0 62 62">
      <circle cx="31" cy="31" r="${R}" fill="none" stroke="#EAE4DC" stroke-width="7"></circle>
      <circle cx="31" cy="31" r="${R}" fill="none" stroke="#8D1D2C" stroke-width="7" stroke-linecap="round"
        stroke-dasharray="${part.toFixed(1)} ${(C - part).toFixed(1)}" transform="rotate(-90 31 31)"></circle>
      <text x="31" y="36" text-anchor="middle">${nf(atteinte, 0)} %</text></svg>
  </div>
  <div class="b-c"><span class="ch">›</span>
    <div class="k">Les tâches du jour</div>
    <div class="v">${D.taches.faites} / ${D.taches.total}</div>
    <div class="s">relevées aujourd’hui</div>
    <div class="b-pills"><span class="ko">${D.taches.bloquantes} bloquantes</span><span>${D.taches.total - D.taches.faites} non faites</span><span class="ok">${D.nc} non-conformité ce mois</span></div>
  </div>
  <div class="b-c"><span class="ch">›</span>
    <div class="k">La semaine · ${esc(D.sem.du)} → ${esc(D.sem.au)}</div>
    <div class="v">+ ${eur(D.sem.ecart)}</div>
    <div class="s">${eur(D.sem.realise)} contre ${eur(D.sem.attendu)} attendus à ce jour · <b>${D.sem.clients} clients d’avance</b></div>
    <div class="b-sem">${D.sem.jours.map(([n, d, obj, ca]) => {
      const h = Math.round(Math.min(1.35, (ca || 0) / obj) * 34);
      return `<div><i class="${ca == null ? 'att' : (ca >= obj ? 'ok' : 'ko')}" style="height:${ca == null ? 3 : Math.max(3, h)}px"></i><span>${n}${d}</span></div>`;
    }).join('')}</div>
  </div>
  <div class="b-c"><span class="ch">›</span>
    <div class="k">Le compte du jour</div>
    <div class="b-casc">
      <div><span class="l">Chiffre d’affaires</span><span class="m"><i style="width:100%;background:#78554B"></i></span><span class="n">${eur(D.ca)}</span></div>
      <div><span class="l">− Coût matière</span><span class="m"><i style="width:${D.matierePct}%;background:#C0182B"></i></span><span class="n">${nf(D.matierePct, 1)} %</span></div>
      <div><span class="l">− Main-d’œuvre</span><span class="m"><i style="width:${D.labourPct}%;background:#B26A00"></i></span><span class="n">${nf(D.labourPct, 1)} %</span></div>
      <div><span class="l">= Résultat</span><span class="m"><i style="width:${D.netPct}%;background:#2d7a3e"></i></span><span class="n">${eur(D.net)}</span></div>
    </div>
  </div>
</div></div>`;

/* ------------------------------------------------------------------ C ---- */
const C3 = `<div class="ph"><div class="enc"></div>
${HEAD('Dashboard magasin')}
<div class="sc"><div class="c-fil">
  <div class="c-b">
    <div class="k">Aujourd’hui</div>
    <div class="v">${eur(D.ca)}</div>
    <div class="c-jauge"><i style="width:${atteinte.toFixed(1)}%;background:var(--color-primary)"></i></div>
    <div class="s"><span class="ko">− ${eur(manque)}</span> sur l’objectif de ${eur(D.objectif)} — <b>${clients} clients</b> de moins.
      ${nf(D.tickets)} clients, panier ${nf(D.panier, 2)} €.</div>
  </div>
  <div class="c-b">
    <div class="k">Ce qu’il reste à faire</div>
    <div class="v m">${D.taches.faites} sur ${D.taches.total}</div>
    <div class="s"><span class="ko">${D.taches.bloquantes} bloquantes</span> — l’exploitation n’est pas rendue.
      Hier, aucune tâche notée.</div>
  </div>
  <div class="c-b">
    <div class="k">Ce qui a coincé</div>
    <div class="v m">${D.nc} non-conformité</div>
    <div class="s">ce mois, sur 43 tâches notées — <span class="ok">1 reprise</span> déjà consignée.</div>
  </div>
  <div class="c-b">
    <div class="k">La semaine</div>
    <div class="v m">+ ${eur(D.sem.ecart)}</div>
    <div class="s">${eur(D.sem.realise)} contre ${eur(D.sem.attendu)} attendus au ${esc(D.sem.au === '20 sept.' ? '15 septembre' : D.sem.au)} —
      <span class="ok">${D.sem.clients} clients d’avance</span>. Objectif de la semaine ${eur(D.sem.objectif)}.</div>
  </div>
  <div class="c-b">
    <div class="k">Ce que vaut le magasin</div>
    <div class="v m">${eur(D.valeur)}</div>
    <div class="s">${eur(D.valMoyenne)} de CA mensuel moyen sur 18 mois clos, × 12 ÷ 6.</div>
  </div>
</div></div>
<div class="c-nav"><div class="b">‹</div><div class="j">lundi 14 septembre<em>appui long : passer à la semaine</em></div><div class="b">›</div></div>
</div>`;

/* ----------------------------------------------------------------------- */
const PAGES = [
  ['a-app-onglets.html', 'A — l’app à onglets', A,
   'Le chiffre du jour en grand, ce qui bloque juste en dessous, le reste replié en quatre lignes qui portent chacune leur chiffre. Deux onglets en bas, le jour et la semaine : on bascule sans revenir en haut. C’est la forme la plus proche d’une application ; c’est aussi celle qui demande le plus de gestes pour tout lire.'],
  ['b-cartes.html', 'B — les cartes', B,
   'Une carte par sujet, chacune complète, qu’on fait défiler. La valeur du magasin ouvre le rang avec sa courbe, puis la journée avec son anneau, la semaine avec ses sept barres, les tâches, le compte du jour. Un chevron ouvre le détail en plein écran. Rien n’est caché derrière un repli : tout se lit en faisant glisser le pouce.'],
  ['c-fil.html', 'C — le fil', C3,
   'Ni carte ni onglet : une suite de chiffres séparés par des filets, dans l’ordre où on se pose les questions — ce que j’ai fait aujourd’hui, ce qu’il reste à faire, ce qui a coincé, où j’en suis dans la semaine, ce que vaut la maison. Un seul geste, faire défiler. La forme la plus dense, la moins « app ».'],
];

for (const [f, titre, corps, sous] of PAGES) {
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
<title>Dashboard magasin sur téléphone — trois formes</title>
<link rel="stylesheet" href="/public/assets/ds/global.css">
<link rel="stylesheet" href="/public/dashboard/dashboard.css">
<link rel="stylesheet" href="mobile.css">
<style>body{margin:0;background:#F5F1EB}</style>
</head><body><div class="planche">
${PAGES.map(([, titre, corps, sous]) => `<div class="col"><p class="lg">${esc(titre)}<em>${esc(sous)}</em></p>${corps}</div>`).join('')}
</div></body></html>`);
console.log('écrit planche.html');
