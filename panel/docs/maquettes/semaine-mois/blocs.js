/* Les blocs que les quatre maquettes assemblent, chacun montrant un chiffre UNE fois. */
const nbJ = () => X.jours.length;
const carte = (titre, mini, corps, cl) => `<div class="db-card${cl ? ' ' + cl : ''}"><div class="ct"><span class="db-lab">${titre}</span>${mini ? `<span class="db-mini">${mini}</span>` : ''}</div>${corps}</div>`;
const tui = (k, v, s, cl) => `<div class="sm-tui"><span class="k">${k}</span><span class="v${cl ? ' ' + cl : ''}">${v}</span><span class="s">${s}</span></div>`;

/** Le CA face à l'objectif : une barre, le trait = 100 %. */
function jauge() {
  const a = att(), l = Math.min(100, 100 * X.ca / Math.max(X.ca, X.objectif));
  return `<div class="db-card sm-jauge"><span class="k">Chiffre d’affaires · ${X.lib}</span><div class="v">${fE(X.ca)}<small>${a} % de l’objectif</small></div>
    <div class="sm-barre"><i style="width:${l}%;background:${coulJour(a)}"></i><b style="left:${Math.min(100, 100 * X.objectif / Math.max(X.ca, X.objectif))}%"></b></div>
    <div class="l"><span>objectif ${fE(X.objectif)}</span><span class="${X.ca >= X.objectif ? 'sm-ok' : 'sm-ko'}">${X.ca >= X.objectif ? '+' : '−'} ${fE(Math.abs(X.ca - X.objectif))}</span></div></div>`;
}
/** Les quatre chiffres qui complètent le CA : clients, panier, marge brute, résultat. */
function quatre(avecCmp) {
  const c = X.cmp, cm = (v, ref, suf) => avecCmp ? `<span class="sm-cmp"><span>${sgn(ecart(v, ref))} vs ${X.avant}</span></span>` : (suf || '');
  return `<div class="sm-tuiles t4">
    ${tui('Clients', fN(X.clients), `${fN(X.clients / nbJ())} par jour · ${sgn(ecart(X.clients, c.n1.clients))} vs ${X.n1}`)}
    ${tui('Panier moyen', X.panier.toLocaleString('fr-FR', { minimumFractionDigits: 2 }) + ' €', `réseau ${c.reseau.panier.toLocaleString('fr-FR', { minimumFractionDigits: 2 })} € · ${X.rang.panier}`, X.panier < c.reseau.panier ? 'ko' : '')}
    ${tui('Marge brute', fP(X.mbPct), `${fE(X.mb)} · matière ${fP(X.matierePct)} (seuil ${SEUILS.matiere} %)`)}
    ${tui('Résultat hors TVA', fE(X.net), `${fP(X.netPct)} des ventes · réseau ${fP(c.reseau.netPct)}`, X.net < 0 ? 'ko' : 'ok')}</div>`;
}
/** Les jours : la barre du CA, le trait de l'objectif, la couleur de l'atteinte, les clients dessous. */
function jours(court) {
  const J = X.jours, mx = Math.max(...J.map(j => Math.max(j[1], j[2]))) * 1.08, cols = `grid-template-columns:repeat(${J.length},minmax(0,1fr))`;
  const bars = J.map(([l, ca, ob]) => { const p = Math.round(100 * ca / ob);
    return `<div class="c">${J.length <= 7 ? `<span class="v">${fN(ca)}</span>` : ''}<i style="height:${(100 * ca / mx).toFixed(1)}%;background:${coulJour(p)}"></i><b style="bottom:${(100 * ob / mx).toFixed(1)}%"></b></div>`; }).join('');
  const labs = J.map(([l, ca, ob, cl]) => `<span>${l}${J.length <= 7 ? `<small>${cl} clients · ${Math.round(100 * ca / ob)} %</small>` : ''}</span>`).join('');
  const fort = J.reduce((a, b) => b[1] / b[2] > a[1] / a[2] ? b : a), faible = J.reduce((a, b) => b[1] / b[2] < a[1] / a[2] ? b : a);
  return carte(court ? 'Les jours' : 'Les jours — le CA face à l’objectif', `${P === 'semaine' ? '' : 'le '}${fort[0]} au plus haut (${Math.round(100 * fort[1] / fort[2])} %) · ${P === 'semaine' ? '' : 'le '}${faible[0]} au plus bas (${Math.round(100 * faible[1] / faible[2])} %)`,
    `<div class="sm-jours" style="${cols}">${bars}</div><div class="sm-jl" style="${cols}">${labs}</div>
     <div class="sm-leg"><span><i style="background:#2e7d32"></i>≥ 110 %</span><span><i style="background:#43a047"></i>100 – 110 %</span><span><i style="background:#c9b98a"></i>90 – 100 %</span><span><i style="background:#ef9a3a"></i>75 – 90 %</span><span><i style="background:#e46f2c"></i>&lt; 75 %</span><span><i style="background:var(--ink);height:2px;vertical-align:3px"></i>objectif du jour</span></div>`);
}
/** Le résultat : du CA hors TVA au résultat, chaque poste face à son seuil. */
function resultat(court) {
  const base = X.caHt, l = (lib, v, pct, seuil, tot, coul) => {
    const mauvais = seuil != null && pct > seuil;
    return `<div class="l${tot ? ' tot' : ''}"><span>${lib}</span><span class="b"><i style="width:${Math.min(100, 100 * Math.abs(v) / base).toFixed(1)}%;background:${coul || (mauvais ? '#d0533b' : '#b8a888')}"></i></span>
      <span class="n">${tot ? '' : '− '}${fE(Math.abs(v)).replace('− ', '')}</span><span class="p ${mauvais ? 'sm-ko' : ''}">${fP(pct)}${seuil != null ? `<small>seuil ${fP(seuil, seuil % 1 ? 1 : 0)}</small>` : ''}</span></div>`;
  };
  return carte(court ? 'Le résultat' : 'Le résultat — du chiffre d’affaires au résultat, hors TVA', `TVA ${fP(X.tvaPct)} retirée ticket par ticket`, `<div class="sm-pl">
    ${l('Chiffre d’affaires', X.caHt, 100, null, true, '#3a3631')}
    ${l('Matière', X.caHt - X.mb, X.matierePct, SEUILS.matiere)}
    ${l('Invendus', X.invendus, X.invendusPct, null)}
    ${l('Main-d’œuvre', X.mo, X.moPct, SEUILS.mo)}
    ${l('Frais généraux', X.frais, X.fraisPct, SEUILS.frais)}
    <div class="l tot"><span><b style="font-weight:400">= Résultat</b></span><span></span><span class="n ${X.net < 0 ? 'sm-ko' : 'sm-ok'}">${fE(X.net)}</span><span class="p ${X.net < 0 ? 'sm-ko' : 'sm-ok'}">${fP(X.netPct)}</span></div></div>`);
}
/** Les heures : une bande, la couleur = la marge nette de l'heure, le chiffre = les ventes. */
function heures(court) {
  const f = (X.ca / nbJ()) / HEURES.reduce((a, h) => a + h[1], 0);
  const c = (v, n) => n < 0 ? '#c62828' : n / v < 0.2 ? '#ef9a3a' : n / v < 0.4 ? '#8bc34a' : '#2e7d32';
  const H = HEURES.map(([h, v, n]) => [h, v * f, n * f]);
  const best = H.reduce((a, b) => b[2] > a[2] ? b : a), worst = H.reduce((a, b) => b[2] < a[2] ? b : a);
  return carte(court ? 'Les heures' : 'Les heures — une journée type', `${best[0]}–${best[0] + 1} h rapporte le plus (+ ${fN(best[2])} € par jour) · ${worst[0]}–${worst[0] + 1} h ${worst[2] < 0 ? 'perd' : 'rapporte le moins'} (${fE(worst[2])})`,
    `<div class="sm-h">${H.map(([h, v, n]) => `<div style="background:${c(v, n)}">${fN(v)} €<small>${n < 0 ? '−' : '+'}${fN(Math.abs(n))}</small></div>`).join('')}</div>
     <div class="sm-hl">${H.map(([h]) => `<span>${h} h</span>`).join('')}</div>
     <div class="sm-leg"><span>ventes de l’heure, en moyenne par jour · dessous : sa marge nette (ventes − matière − rémunération)</span></div>`);
}
function topProduits() {
  return carte('Ce qui rapporte', 'les 3 produits à la plus forte marge brute', `<div class="sm-top">${TOP.map(([n, m, p], i) => `<div><span class="sm-mu">${i + 1}</span><span>${n}</span><span style="text-align:right">${fE(m * (P === 'mois' ? 4.3 : 1))}</span><span class="sm-mu" style="text-align:right">${p} %</span></div>`).join('')}</div>`);
}
/** Ce qui ne s'affiche plus par défaut : un clic l'ouvre. */
function plus(L) { return `<div class="sm-plus">${L.map(([t, s]) => `<span>${t}${s ? `<small>${s}</small>` : ''} ▾</span>`).join('')}</div>`; }
const PLUS = () => [['Ma place dans le réseau', X.rang.ca + ' en CA'], ['Tâches et contrôles', X.taches.faites + ' / ' + X.taches.sur + ' faites'], ['Canaux et commandes', 'pro ' + fP(X.proPct, 1) + ' · ' + X.commandes + ' commandes'],
  ['Invendus', X.invendusPcs + ' pièces · ' + fE(X.invendus)], ['Promotions', 'aucune en cours'], [P === 'semaine' ? 'Les 12 dernières semaines' : 'L’année mois par mois', ''], ['Le détail heure par heure', '']];
function piedSM(dir) {
  return `Maquette ${dir} du 16/11/2026 · Atelier by Harmonie - Sombreffe. Chiffres réels de l’API du cockpit pour ${P === 'semaine' ? 'la semaine S46 (09 → 15/11)' : 'octobre 2026'} : CA, objectif, jours, clients, panier, marge, invendus, main-d’œuvre, frais, résultat, part pro ; comparaisons partiellement d’exemple (voir LISEZMOI), heures et produits d’exemple. Seuils du réseau : matière 40 %, main-d’œuvre 20 %, frais généraux 13,5 %.`;
}
