/* Dashboard magasin › Marketing › Réseaux sociaux (15/11/2026, « tout doit se faire dans le cockpit franchisé » ; « le
 * nouveau post : prendre tout ce qui a été fait dans le Marketing pour le franchisé »).
 *
 * Les pages de l'espace boutique du Marketing — Calendrier, Créer un post, Commentaires, Messages privés, Avis Google,
 * Publicités et opérations, Grille Instagram — affichées ici, dans un cadre, en mode intégré (sans leur menu : les onglets
 * ci-dessous en tiennent lieu). Le parcours est celui du Marketing, à jour avec lui : modèles du réseau, produit et
 * promotion du catalogue, visuel, quota de la semaine, répétition, validation par le réseau, publication.
 *   GET …/dashboard/social/ouvrir?shop=&page=   un lien court qui ouvre la session de l'espace pour ce magasin
 *                                              (src/dashboard_marketing.php → API Social du Marketing, jeton de service)
 *
 * `window.DashReseaux.racine(shop, {apiBase})` rend l'élément du module, gardé d'un rendu du dashboard à l'autre.
 */
(function () {
  'use strict';
  const PAGES = [['calendrier', 'Calendrier'], ['publier', 'Créer un post'], ['commentaires', 'Commentaires'], ['messages', 'Messages privés'],
    ['avis-google', 'Avis Google'], ['operations', 'Publicités et opérations'], ['instagram', 'Grille Instagram']];
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const st = { shop: null, api: '../api/cockpit', el: null, page: 'calendrier', err: null, charge: false, url: null };

  function ouvrir(page) {
    st.page = page; st.err = null; st.charge = true; rendre();
    fetch(st.api + '/dashboard/social/ouvrir?shop=' + encodeURIComponent(st.shop) + '&page=' + encodeURIComponent(page), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.json().catch(() => ({})).then(j => r.ok ? j : Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status)))))
      .then(d => { if (st.page === page) { st.url = d.url; } })
      .catch(e => { if (st.page === page) { st.err = e.message; st.url = null; } })
      .finally(() => { if (st.page === page) { st.charge = false; rendre(); } });
  }

  /** Le cadre prend la hauteur qui reste dans la fenêtre. */
  function ajuster() {
    const f = st.el && st.el.querySelector('.rx-cadre');
    if (!f) { return; }
    const haut = f.getBoundingClientRect().top;
    f.style.height = Math.max(520, window.innerHeight - Math.max(0, haut) - 16) + 'px';
  }

  function rendre() {
    if (!st.el) { return; }
    const onglets = `<div class="rx-onglets"><div class="page-tabs">${PAGES.map(([k, l]) => `<button type="button" class="page-tabs__tab${st.page === k ? ' is-on' : ''}" data-rxpage="${k}">${l}</button>`).join('')}</div></div>`;
    let corps;
    if (st.err) { corps = `<div class="db-err">L’espace boutique ne s’ouvre pas : ${esc(st.err)} <button type="button" class="db-btn" data-rxrelire="1">Réessayer</button></div>`; }
    else if (!st.url) { corps = '<div class="db-sk" style="height:480px"></div>'; }
    else { corps = `<iframe class="rx-cadre" src="${esc(st.url)}" title="${esc((PAGES.find(p => p[0] === st.page) || [])[1] || 'Réseaux sociaux')} — espace boutique du Marketing"></iframe>`; }
    // Le cadre ne se recharge que si son adresse change : un rendu du dashboard ne doit pas faire perdre la saisie d'un post.
    const f = st.el.querySelector('.rx-cadre');
    if (f && st.url && f.getAttribute('src') === st.url && !st.err) {
      st.el.querySelectorAll('[data-rxpage]').forEach(b => b.classList.toggle('is-on', b.dataset.rxpage === st.page));
      ajuster(); return;
    }
    st.el.innerHTML = onglets + corps;
    st.el.querySelectorAll('[data-rxpage]').forEach(b => b.addEventListener('click', () => ouvrir(b.dataset.rxpage)));
    st.el.querySelectorAll('[data-rxrelire]').forEach(b => b.addEventListener('click', () => ouvrir(st.page)));
    requestAnimationFrame(ajuster);
  }

  window.addEventListener('resize', ajuster);

  window.DashReseaux = {
    racine(shop, o) {
      o = o || {};
      if (o.apiBase) { st.api = o.apiBase; }
      const page = o.page && PAGES.some(p => p[0] === o.page) ? o.page : null;
      if (!st.el || String(st.shop) !== String(shop)) {
        st.shop = String(shop); st.el = document.createElement('div'); st.el.className = 'rx-racine'; st.url = null;
        ouvrir(page || st.page);
      } else if (page && page !== st.page) { ouvrir(page); }
      else { requestAnimationFrame(ajuster); }
      return st.el;
    },
    relire() { if (st.el) { ouvrir(st.page); } },
  };
})();
