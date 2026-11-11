/* La coque du cockpit pour les maquettes « Visibilité Google » : le rail réel (rail.js), la barre
   supérieure, l'introduction et les onglets de page, aux classes du design system. Magasins réels ;
   les valeurs lues chez Google sont des EXEMPLES : la clé Places n'a pas encore été interrogée. */
const VG = {
  magasins: [
    { id: 'cor', ville: 'Corbais', nom: 'Atelier by Berlo', shop: 2, langue: 'fr' },
    { id: 'gos', ville: 'Gosselies', nom: 'Atelier by Max & Sandra', shop: 3, langue: 'fr' },
    { id: 'som', ville: 'Sombreffe', nom: 'Atelier by Harmonie', shop: 5, langue: 'fr' },
    { id: 'hal', ville: 'Halle', nom: 'Atelier by Halle', shop: 4, langue: 'nl' }
  ],
  onglets: [['fiches', 'Fiches Google', '7'], ['rang', 'Rang local'], ['pages', 'Pages du site'], ['reglages', 'Réglages']]
};

function coque(o) {
  const tabs = VG.onglets.map(t => `<button class="page-tabs__tab ${t[0] === o.onglet ? 'is-on' : ''}">${t[1]}${t[2] ? ` <span class="page-tabs__count">${t[2]}</span>` : ''}</button>`).join('');
  document.body.innerHTML = `<div id="app"><div class="shell workspace">${window.RAIL}
  <div class="workspace__main">
    <header class="topbar shell-head">
      <div><p class="topbar__kicker">Vue Réseau</p><h1>Visibilité Google</h1></div>
      <div class="topbar__actions">${o.actions || ''}</div>
    </header>
    <main class="app__body"><div class="shell-page">
      <p class="shell-sub page-intro">${o.intro}</p>
      <div class="page-tabs" role="tablist">${tabs}</div>
      ${o.corps}
      <p class="vg-pied">Maquette du 08/11/2026 · magasins réels · valeurs Google d’exemple : la clé Places du cockpit n’a pas encore été interrogée. Les colonnes « site » sont réelles : données structurées d’atelierby.be relues le 08/11/2026. Lecture par l’API Places (New) avec la clé déjà enregistrée dans Paramètres : Place Details pour les fiches, Text Search depuis chaque magasin pour le rang. Ce que la clé ne permet pas : modifier une fiche, publier un post, répondre à un avis (API Business Profile, connexion du compte propriétaire), lire les requêtes du site (Search Console).</p>
    </div></main>
  </div></div></div>`;
}
