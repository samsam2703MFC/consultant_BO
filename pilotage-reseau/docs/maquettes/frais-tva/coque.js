/* La coque du dashboard magasin pour les maquettes « Frais et TVA » : le rail du bureau tel que
   public/dashboard/dashboard.js le dessine (railHtml, 15/11/2026), une entrée « Frais » ajoutée dans « Les chiffres »,
   et la barre du haut (railBarre). Aux classes du design system commun et de dashboard.css. */
const ICO = {
  tdb: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
  controle: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
  journal: 'M12 8v4l3 3M3 12a9 9 0 1 0 9-9 9 9 0 0 0-9 9',
  cal: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
  calEd: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01',
  perf: 'M3 17l6-6 4 4 8-8M15 7h6v6',
  fonds: 'M3 7h18v12H3zM3 7l3-4h12l3 4M16 13h.5',
  frais: 'M6 2h12v20l-3-2-3 2-3-2-3 2zM9 7h6M9 11h6M9 15h4',
  bundles: 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8',
  offres: 'M19 5L5 19M7 7a1.5 1.5 0 1 0 .01 0M17 17a1.5 1.5 0 1 0 .01 0',
  analyse: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
  etoile: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z',
  brochures: 'M3 5l6-2 6 2 6-2v16l-6 2-6-2-6 2zM9 3v16M15 5v16',
  personnes: 'M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.9'
};
// Le rail du dashboard, ses groupes et son ordre ; « Frais » (nouveau) après Trésorerie.
const RAIL = [
  ['La journée', [['ops', 'Opérationnel', 'tdb'], ['taches', 'Tâches', 'controle'], ['morts', 'Temps morts', 'journal']]],
  ['Les chiffres', [['semaine', 'Semaine', 'cal'], ['mois', 'Mois', 'calEd'], ['annee', 'Année', 'perf'], ['tresorerie', 'Trésorerie', 'fonds'], ['frais', 'Frais', 'frais', 'nouveau']]],
  ['Vendre', [['produits', 'Produits', 'bundles'], ['promos', 'Promotions', 'offres'], ['primes', 'Primes', 'personnes'], ['concurrence', 'Concurrence', 'analyse']]],
  ['Marketing', [['posts', 'Calendrier des posts', 'calEd'], ['fidelite', 'Fidélité', 'etoile'], ['catalogue', 'Mon catalogue', 'brochures']]]
];
const ico = d => `<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"></path></svg>`;
const eur = (v, d = 2) => (v < 0 ? '− ' : '') + Math.abs(v).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/ | /g, ' ') + ' €';
const pc = v => v.toLocaleString('fr-BE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' %';

function rail(actif) {
  const et = [1, 2, 3, 4, 5].map(i => `<svg viewBox="0 0 24 24" class="${i <= 4 ? 'on' : ''}" aria-hidden="true"><path d="${ICO.etoile}"/></svg>`).join('');
  return `<nav id="db-rail" class="sidebar db-rail" aria-label="Navigation du dashboard">
    <div class="sidebar__head"><img class="sidebar__logo" src="/public/assets/img/logo.png" alt="L’Atelier By"><div class="sidebar__kicker">Dashboard magasin</div></div>
    <div class="db-rail-rep">
      <button type="button" class="db-rcarte"><div class="k">Objectif du jour<em>en direct</em></div><div class="v">94 %<small>de 2 790 €</small></div>
        <div class="db-rech"><i class="att" style="width:94%"></i><b style="left:98%"></b></div><div class="s">2 628 € · trait : projection 2 735 €</div></button>
      <div class="db-rcarte"><div class="k">Note Google<em>58 avis</em></div><div class="v">4,3<span class="db-retoiles">${et}</span><small>cible 4,5</small></div>
        <div class="db-rech"><i class="att" style="width:30%"></i><b style="left:50%"></b></div></div>
    </div>
    <div class="sidebar__groups">${RAIL.map(([g, L]) => `<div class="sidebar__group"><div class="sidebar__group-label">${g}</div>${L.map(([v, l, i, neuf]) =>
      `<button type="button" class="sidebar__item${v === actif ? ' is-active' : ''}">${ico(ICO[i])}<span class="sidebar__item-label">${l}</span>${neuf ? '<span class="mq-neuf">nouveau</span>' : ''}${v === 'posts' ? '<span class="db-rail-ext" aria-hidden="true">↗</span>' : ''}</button>`).join('')}</div>`).join('')}</div>
  </nav>`;
}

function barre(titre, periode) {
  const av = (o, k, x, cl = '') => `<button type="button" class="db-av${cl}"><span class="db-av__o">${o}</span><span class="db-av__t"><span class="k">${k}</span><span class="v">${x}</span></span></button>`;
  const env = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 6.5 8.5 6.5 8.5-6.5"/></svg>';
  const lune = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
  return `<div class="db-nav db-nav--rail"><div class="db-vt"><div class="kk">Atelier by Halle</div><h1>${titre}</h1></div>
    ${periode || ''}<span style="flex:1"></span><button class="db-btn">↻ Relire</button>
    <span class="db-avs"><button type="button" class="db-vign"><span class="k">Valeur du magasin</span><span class="v">412 600 €</span></button>
    ${av(env, 'Demande au bureau', 'nouvelle')}${av(lune, 'Revue du soir', 'à 18 h')}</span></div>`;
}

function coque(o) {
  document.body.classList.add('db-rail-on');
  document.body.innerHTML = rail(o.actif) + `<div id="dash">${barre(o.titre, o.periode)}${o.corps}
    <p class="mq-pied">${o.pied}</p></div>`;
  document.body.dataset.pret = '1';
}
