/* La coque du dashboard magasin pour les maquettes « Paramètres › Mails et automatisations » : le rail du bureau tel que
   public/dashboard/dashboard.js le dessine au 16/11/2026 (Boîte mail en tête, La journée, Les chiffres, Vendre,
   Marketing), et un groupe neuf « Paramètres » en bas. La barre du haut : railBarre. */
const ICO = {
  mail: 'M3 5h18v14H3zM3 6l9 7 9-7', tdb: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
  controle: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11', journal: 'M12 8v4l3 3M3 12a9 9 0 1 0 9-9 9 9 0 0 0-9 9',
  cal: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
  calEd: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01',
  perf: 'M3 17l6-6 4 4 8-8M15 7h6v6', fonds: 'M3 7h18v12H3zM3 7l3-4h12l3 4M16 13h.5', frais: 'M6 2h12v20l-3-2-3 2-3-2-3 2zM9 7h6M9 11h6M9 15h4',
  loupe: 'M3 3v18h18M7 15l4-5 3 3 5-7', bundles: 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8', offres: 'M19 5L5 19M7 7a1.5 1.5 0 1 0 .01 0M17 17a1.5 1.5 0 1 0 .01 0',
  offre: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5', personnes: 'M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.9',
  analyse: 'M4 20V10M10 20V4M16 20v-7M22 20H2', pub: 'M4 4h16v12H4zM2 20h20M9 8l4 2-4 2z', reseaux: 'M3 3h18v18H3zM3 9h18M3 15h18M9 3v18M15 3v18',
  etoile: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z', brochures: 'M3 5l6-2 6 2 6-2v16l-6 2-6-2-6 2zM9 3v16M15 5v16',
  // Mails et automatisations : l'enveloppe et l'engrenage.
  auto: 'M3 5h12v10H3zM3 6l6 5 6-5M18.5 14.5a2.5 2.5 0 1 0 .01 0M18.5 10.5v1.5M18.5 17v1.5M22 14.5h-1.5M16.5 14.5H15'
};
const RAIL = [
  [null, [['boite', 'Boîte mail', 'mail']]],
  ['La journée', [['ops', 'Opérationnel', 'tdb'], ['taches', 'Tâches', 'controle'], ['morts', 'Temps morts', 'journal']]],
  ['Les chiffres', [['semaine', 'Semaine', 'cal'], ['mois', 'Mois', 'calEd'], ['annee', 'Année', 'perf'], ['tresorerie', 'Trésorerie', 'fonds'], ['frais', 'Frais', 'frais']]],
  ['Vendre', [['analyse', 'Analyse', 'loupe'], ['produits', 'Produits', 'bundles'], ['promos', 'Promotions', 'offres'], ['offres', 'Offres', 'offre'], ['primes', 'Primes', 'personnes'], ['concurrence', 'Concurrence', 'analyse']]],
  ['Marketing', [['marketing', 'Mon marketing', 'pub'], ['social', 'Réseaux sociaux', 'reseaux'], ['fidelite', 'Fidélité', 'etoile'], ['catalogue', 'Mon catalogue', 'brochures']]],
  ['Paramètres', [['mails', 'Mails et automatisations', 'auto', 'nouveau']]]
];
const ico = d => `<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"></path></svg>`;
function rail(actif) {
  const et = [1, 2, 3, 4, 5].map(i => `<svg viewBox="0 0 24 24" class="${i <= 4 ? 'on' : ''}" aria-hidden="true"><path d="${ICO.etoile}"/></svg>`).join('');
  return `<nav id="db-rail" class="sidebar db-rail" aria-label="Navigation du dashboard">
    <div class="sidebar__head"><img class="sidebar__logo" src="/public/assets/img/logo.png" alt="L’Atelier By"><div class="sidebar__kicker">Dashboard magasin</div></div>
    <div class="db-rail-rep">
      <button type="button" class="db-rcarte"><div class="k">Objectif du jour<em>en direct</em></div><div class="v">49 %<small>de 1 714 €</small></div>
        <div class="db-rech"><i class="att" style="width:49%"></i><b style="left:74%"></b></div><div class="s">841 € · trait : projection 1 276 €</div></button>
      <div class="db-rcarte"><div class="k">Note Google<em>55 avis</em></div><div class="v">4,5<span class="db-retoiles">${et}</span><small>cible 4,6</small></div>
        <div class="db-rech"><i class="att" style="width:50%"></i><b style="left:60%"></b></div></div>
    </div>
    <div class="sidebar__groups">${RAIL.map(([g, L]) => `<div class="sidebar__group">${g ? `<div class="sidebar__group-label">${g}</div>` : ''}${L.map(([v, l, i, neuf]) =>
      `<button type="button" class="sidebar__item${v === actif ? ' is-active' : ''}">${ico(ICO[i])}<span class="sidebar__item-label">${l}</span>${neuf ? '<span class="mq-neuf">nouveau</span>' : ''}</button>`).join('')}</div>`).join('')}</div>
  </nav>`;
}
function barre(titre, kicker) {
  const v = (o, k, x, cl = '') => `<button type="button" class="db-vign${cl}"><span class="db-vign__o">${o}</span><span class="k">${k}</span><span class="v">${x}</span></button>`;
  return `<div class="db-nav db-nav--rail"><div class="db-vt"><div class="kk">${kicker || 'Atelier by - Halle'}</div><h1>${titre}</h1></div><span style="flex:1"></span>
    <div class="mq-vigs">${['↻|Relire|lu à 11 h 40', '⌂|Valeur du magasin|315 507 €', '✦|Coach|assistant', '✉|Demande au bureau|11 ouvertes', '☾|Revue du soir|à 18 h'].map(x => { const [o, k, t] = x.split('|'); return `<div class="mq-vig"><span class="o">${o}</span><span><span class="k">${k}</span><span class="v">${t}</span></span></div>`; }).join('')}</div></div>`;
}
function coque(o) {
  document.body.classList.add('db-rail-on');
  document.body.innerHTML = rail(o.actif || 'mails') + `<div id="dash">${barre(o.titre, o.kicker)}${o.corps}<p class="mq-pied">${o.pied}</p></div>`;
  document.body.dataset.pret = '1';
}
