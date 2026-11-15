/* Maquette « Dashboard franchisé — la version propre » (demande du 15/11/2026 : « la valeur doit être un montant dans
 * une vignette ; refais le design complet, que ce soit propre ; même design system, mais un dashboard minimaliste,
 * rapide et fonctionnel, sur la base du B »).
 *
 *   node docs/maquettes/rail-franchise/generer-final.js   → final.html
 *   node docs/maquettes/rail-franchise/capturer.js        → final.png (avec les autres écrans)
 *
 * Les chiffres sont RÉELS : Atelier by - Halle, mardi 6 octobre 2026 à 08:14, lus pour la maquette
 * docs/maquettes/dashboard-operationnel (reel-halle-0610.json : ventes, heures, vitrine, tâches, commandes, équipe) ;
 * la valeur du même jour (100 328 €) est celle que montre la capture code-bureau.png de cette maquette. Seule la note
 * Google est un exemple (italique pointillé) : elle n'a pas été lue. Les photos sont celles des contrôles de ce matin-là
 * (des comptoirs, personne dessus). */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const R = '../../../public/';
const OP = '../dashboard-operationnel/';
const J = JSON.parse(fs.readFileSync(path.join(D, OP, 'reel-halle-0610.json'), 'utf8'));
const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/[  ]/g, ' ');
const fE = n => nf(Math.round(n), 0) + ' €';

/* Les tracés : catalogue du §8.4, plus la revue du soir, l'euro et la personne (signalés au LISEZMOI). */
const I = {
  tdb: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
  controle: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
  journal: 'M12 8v4l3 3M3 12a9 9 0 1 0 9-9 9 9 0 0 0-9 9',
  perf: 'M3 17l6-6 4 4 8-8M15 7h6v6',
  analyse: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
  bundles: 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8',
  offres: 'M19 5L5 19M7 7a1.5 1.5 0 1 0 .01 0M17 17a1.5 1.5 0 1 0 .01 0',
  fonds: 'M3 7h18v12H3zM3 7l3-4h12l3 4M16 13h.5',
  revue: 'M9 3h6v3H9zM9 4.5H6a2 2 0 0 0-2 2V19a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6.5a2 2 0 0 0-2-2h-3M15.5 15.5a3.2 3.2 0 1 1-3.7-4.4 2.6 2.6 0 0 0 3.7 4.4z',
  formulaires: 'M4 4h16v16H4zM8 8h8M8 12h8M8 16h5',
  campagnes: 'M3 11l14-7v16L3 13zM7 14v5',
  commentaires: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2zM8 9h8M8 13h5',
  etoile: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z',
  retour: 'M19 12H5M12 19l-7-7 7-7',
  personne: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM5 21v-1a7 7 0 0 1 14 0v1',
};
const ico = d => `<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"></path></svg>`;

/* ── Les chiffres du matin ──────────────────────────────────────────────────── */
const V = J.ventes, P = J.production, C = J.commandes, E = J.equipe;
const pctJour = 100 * V.ca / V.objectif;                       // 14 %
const traitJ7 = 100 * V.j7.ca / V.objectif;                    // J−7 à la même heure, sur l'échelle d'aujourd'hui
const nonRendues = J.taches.filter(t => t.statut === 'nonRendue');
const aControler = J.taches.filter(t => t.statut === 'aControler');
const ruptures = P.produits.filter(p => p.verdict === 'rupture');
const manques = P.produits.filter(p => p.verdict === 'manque');
const trop = P.produits.filter(p => p.verdict === 'trop');
const cuissonMatin = P.cuissons[0], cuissonMidi = P.cuissons[1];
const nomTache = t => t.tache.replace(/^Photo du comptoir - /, '').replace(/^Est-ce que les promotions ont été encadrées et visibles \?$/, 'Promotions encadrées').replace(/^étiquetage/, 'Étiquetage');

/* ── Le rail (B) ───────────────────────────────────────────────────────────── */
const entree = ([lib, d, etat, on]) => `<a class="sidebar__item${on ? ' is-active' : ''}" href="#"${on ? ' aria-current="page"' : ''}>${ico(d)}<span class="sidebar__item-label">${lib}</span>${etat ? `<span class="rf-etat">${etat}</span>` : ''}</a>`;
const groupe = (lib, items) => `<div class="sidebar__group"><div class="sidebar__group-label">${lib}</div>${items.map(entree).join('')}</div>`;
const etoiles = n => `<span class="rf-etoiles">${[1, 2, 3, 4, 5].map(i => `<svg viewBox="0 0 24 24" class="${i <= Math.round(n - 0.4) ? 'on' : ''}"><path d="${I.etoile}"/></svg>`).join('')}</span>`;
const rail = `<div class="sidebar__head"><img class="sidebar__logo" src="${R}assets/img/logo.png" alt="L’Atelier By"><div class="sidebar__kicker">Dashboard magasin</div></div>
  <div class="sidebar__back"><a href="#">${ico(I.retour)}Retour à l’ERP</a></div>
  <div class="rf-rep"><div class="rf-rep__t">Atelier by – Halle<small>08:14</small></div>
    <div class="rf-cartes">
      <button class="rf-carte"><div class="k">Objectif du jour<em>à 08:14</em></div><div class="v">${nf(pctJour, 0)} %<small>de ${fE(V.objectif)}</small></div>
        <div class="rf-echelle"><i class="att" style="width:${pctJour.toFixed(1)}%"></i><b style="left:${traitJ7.toFixed(1)}%"></b></div>
        <div class="s">reste ${fE(V.objectif - V.ca)} · trait : J−7, ${fE(V.j7.ca)}</div></button>
      <button class="rf-carte"><div class="k">Note Google<em class="ex">287 avis</em></div><div class="v"><span class="ex">4,6</span>${etoiles(4.6)}</div>
        <div class="rf-echelle"><i style="width:60%"></i><b style="left:50%"></b></div><div class="rf-echelle-l"><span>4,0</span><span>cible 4,5</span><span>5,0</span></div></button>
    </div></div>
  <div class="sidebar__groups">
    ${groupe('Le magasin', [['Opérationnel', I.tdb, '', true], ['Tâches', I.controle, String(nonRendues.length)], ['Résultat', I.perf], ['Temps morts', I.journal]])}
    ${groupe('Vendre', [['Produits', I.bundles], ['Promotions', I.offres], ['Concurrence', I.analyse], ['Primes', I.fonds]])}
    ${groupe('Assistants', [['Plan d’action', I.formulaires], ['Campagne', I.campagnes], ['Réclamation fournisseur', I.commentaires]])}
  </div>`;

/* ── La barre du haut : la date, puis la valeur en vignette, le coach et la revue ── */
const avatar = (d, k, v, cl) => `<button class="rf-av${cl ? ' ' + cl : ''}"><span class="rf-av__o">${ico(d)}</span><span><span class="rf-av__k">${k}</span><span class="rf-av__v">${v}</span></span></button>`;
const topbar = `<header class="topbar"><div><p class="topbar__kicker">Vue Franchisé</p><h1>Opérationnel</h1></div>
  <div class="topbar__actions">
    <button class="btn btn--icon" aria-label="Jour précédent">‹</button><span class="fn-date">mardi 6 octobre</span><button class="btn btn--icon" aria-label="Jour suivant">›</button>
    <button class="btn">↻ Relire</button>
    <div class="rf-avs">
      <button class="fn-vignette" title="CA des 730 derniers jours ÷ 730 × 365 ÷ 6"><span class="k">Valeur du magasin</span><span class="v">100 328 €</span></button>
      ${avatar(I.personne, 'Coach', 'assistant IA')}
      ${avatar(I.revue, 'Revue du soir', 'ce soir')}
    </div></div></header>`;

/* ── Le bandeau : cinq chiffres ─────────────────────────────────────────────── */
const kpi = (lib, val, note, cl) => `<div class="kpi${cl ? ' ' + cl : ''}"><div class="kpi__label">${lib}</div><div class="kpi__value">${val}</div><div class="kpi__note">${note}</div></div>`;
const panierJ7 = V.j7.ca / V.j7.tickets;
const bandeau = `<section class="fn-bande"><div class="kpi-row">
  ${kpi('Ventes', fE(V.ca), `${V.tickets} clients · J−7 à 08:05 : ${fE(V.j7.ca)}`)}
  ${kpi('Panier moyen', nf(V.panier, 2) + ' €', `J−7 : ${nf(panierJ7, 2)} €`)}
  ${kpi('Vitrine', P.totaux.stock + ' pièces', `${ruptures.length} ruptures · ${manques.length} vont manquer`, 'is-att')}
  ${kpi('Équipe', nf(E.heures, 1) + ' h', `au planning · ${fE(E.cout)}`)}
  ${kpi('Prochaine cuisson', esc(cuissonMidi.nom), `four à ${cuissonMidi.four.replace(':', ' h ')} · ${cuissonMidi.pieces} pièces`)}
</div></section>`;

/* ── À faire maintenant : ce qui demande un geste, et où le faire ───────────── */
const afaire = [
  ['ko', `${C.retard} commandes clients en retard`, `${C.enCours} en cours, ${C.aVenir} à venir`, 'Commandes'],
  ['ko', `${ruptures.length} produits en rupture`, `dont ${ruptures.filter(p => /^Cookie/.test(p.nom)).length} cookies, sortis à 10 et jamais réassortis`, 'Vitrine'],
  ['att', `${nonRendues.length} contrôles pas rendus`, nonRendues.map(nomTache).join(', '), 'Tâches'],
  ['att', `Cuisson du matin pas validée`, `four à ${cuissonMatin.four.replace(':', ' h ')} · ${cuissonMatin.pieces} pièces`, 'Production'],
];
const blocAfaire = `<section class="card fn-bloc"><h2>À faire maintenant</h2>
  ${afaire.map(([c, t, s, ou]) => `<a class="fn-geste" href="#"><i class="rf-dot ${c}"></i><span class="fn-geste__t">${t}<small>${esc(s)}</small></span><span class="fn-geste__ou">${ou} ›</span></a>`).join('')}
</section>`;

/* ── Les ventes heure par heure, face aux six derniers mardis ───────────────── */
function graphe() {
  const H = [6, 7, 8, 9, 10, 11, 12], auj = {}; V.heures.forEach(x => { auj[x.h] = x.ca; });
  const max = 170, w = 560, h = 170, bas = 140, pas = w / H.length, bw = 22;
  const y = v => bas - v / max * (bas - 14);
  let g = `<line x1="0" x2="${w}" y1="${bas}" y2="${bas}" stroke="#e6e0d8"/>`;
  H.forEach((hh, i) => {
    const x0 = i * pas + pas / 2 - bw - 2, m = J.moyHeure[hh], a = auj[hh];
    g += `<rect x="${x0.toFixed(1)}" y="${y(m).toFixed(1)}" width="${bw}" height="${(bas - y(m)).toFixed(1)}" fill="#EDE7DE" rx="3"/>`;
    if (a != null) {
      const enCours = hh === 8;
      g += `<rect x="${(x0 + bw + 4).toFixed(1)}" y="${y(a).toFixed(1)}" width="${bw}" height="${Math.max(1, bas - y(a)).toFixed(1)}" fill="#1c1a17" rx="3"${enCours ? ' opacity="0.35"' : ''}/>`;
      g += `<text x="${(x0 + bw * 1.5 + 4).toFixed(1)}" y="${(y(a) - 6).toFixed(1)}" text-anchor="middle" font-size="11" fill="#1c1a17">${Math.round(a)}</text>`;
    }
    g += `<text x="${(i * pas + pas / 2).toFixed(1)}" y="${bas + 18}" text-anchor="middle" font-size="11" fill="#7a736a">${hh} h</text>`;
  });
  return `<svg class="fn-graphe" viewBox="0 0 ${w} ${h}" role="img" aria-label="Ventes par heure, aujourd’hui face à la moyenne des six derniers mardis">${g}</svg>`;
}
const blocVentes = `<section class="card fn-bloc"><h2>Les ventes, heure par heure</h2>
  ${graphe()}
  <div class="fn-legende"><span><i style="background:#1c1a17"></i>aujourd’hui, en euros</span><span><i style="background:#EDE7DE"></i>moyenne des 6 derniers mardis</span><span class="muted">8 h : en cours</span></div>
</section>`;

/* ── La vitrine : ce qui s'écarte, rien d'autre ─────────────────────────────── */
const verdict = p => p.verdict === 'rupture' ? '<span class="tag-stop">rupture</span>'
  : p.verdict === 'manque' ? `<span class="tag-warn">manque vers ${p.manque.h} h</span>` : '<span class="chip chip--neutral">en trop</span>';
const lignesVitrine = [...ruptures.slice(0, 3), ...manques];
const blocVitrine = `<section class="card fn-bloc fn-bloc--table"><h2>La vitrine<span class="fn-aside">${ruptures.length + manques.length + trop.length} produits sur ${P.produits.length} s’écartent · prévision des 6 derniers mardis</span></h2>
  <table class="ds-table fn-table"><thead><tr><th>Produit</th><th class="num">Sorti</th><th class="num">Vendu</th><th class="num">En vitrine</th><th>Ce soir</th></tr></thead>
  <tbody>${lignesVitrine.map(p => `<tr><td>${esc(p.nom)}<span class="sub">${esc(p.groupe)}</span></td><td class="num">${p.sorti}</td><td class="num">${p.vendu}</td><td class="num">${Math.max(0, p.stock)}</td><td>${verdict(p)}</td></tr>`).join('')}</tbody></table>
  <a class="fn-plus" href="#">Toute la vitrine (${P.produits.length} produits) ›</a>
</section>`;

/* ── Les contrôles du matin : les photos rendues, ce qui manque ─────────────── */
const blocControles = `<section class="card fn-bloc"><h2>Les contrôles du matin<span class="fn-aside">${aControler.length} rendus · ${nonRendues.length} pas rendus</span></h2>
  <div class="fn-photos">${J.photos.slice(0, 3).map(ph => `<figure><img src="${OP}${ph.f}" alt=""><figcaption>${esc(nomTache(ph))}</figcaption></figure>`).join('')}</div>
  <div class="fn-manque">Pas rendus : ${nonRendues.map(t => `<span class="tag-warn">${esc(nomTache(t))}</span>`).join(' ')}</div>
  <a class="fn-plus" href="#">Les ${J.photos.length} photos du matin ›</a>
</section>`;

const html = `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard franchisé — Opérationnel (maquette, la version propre)</title>
<link rel="icon" href="${R}assets/img/logo.png">
<link rel="stylesheet" href="${R}assets/ds/global.css">
<link rel="stylesheet" href="rf.css">
<link rel="stylesheet" href="final.css">
</head><body>
<div class="workspace">
<nav class="sidebar" aria-label="Navigation du dashboard">
${rail}
</nav>
<div class="workspace__main">${topbar}
<main class="app__body"><div class="fn">
  <p class="fn-lu">En direct · relu à 08:14 <span class="rf-pill">Maquette · chiffres réels de Halle, mardi 6 octobre 2026 · note Google : exemple</span></p>
  ${bandeau}
  <div class="fn-deux">${blocAfaire}${blocVentes}</div>
  <div class="fn-deux fn-deux--b">${blocVitrine}${blocControles}</div>
</div></main>
</div>
</div>
</body></html>
`;
fs.writeFileSync(path.join(D, 'final.html'), html);
console.log('final.html');
