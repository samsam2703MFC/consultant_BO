/* Dashboard magasin › Marketing › Réseaux sociaux (15/11/2026, « tout doit se faire dans le cockpit franchisé » ; « le
 * nouveau post : prendre tout ce qui a été fait dans le Marketing pour le franchisé » ; « retravailler la vue pour
 * qu'elle soit plus intégrée »).
 *
 * Les pages de l'espace boutique du Marketing — Calendrier, Vues et abonnés, Suivi de ma boutique, Commentaires,
 * Messages privés, Avis Google, Créer un post, Publicités et opérations, Grille Instagram — affichées ici en mode intégré,
 * fondues dans la page : pas de cadre visible, le fond du cockpit, une seule barre d'onglets.
 *   GET …/dashboard/social/ouvrir?shop=&page=   un lien court qui ouvre la session de l'espace pour ce magasin
 *                                              (src/franchise/dashboard_marketing.php → API Social du Marketing)
 * Le dialogue avec l'espace (même origine, src/franchise/EspaceFranchise.tsx du Marketing) :
 *   - l'espace envoie son menu à chaque changement (`{source: 'espace-boutique', type: 'menu', entrees, quota}`) : les
 *     onglets ci-dessous SONT ce menu — ses libellés, ses pastilles, la page active — et le quota de la semaine se lit
 *     sur l'onglet « Créer un post » ;
 *   - un onglet demande la page à l'espace (`{source: 'cockpit', type: 'aller', k}`) : elle change sans recharger, la
 *     session reste ouverte. Avant le premier menu (ou si le cadre ne répond pas), l'onglet rouvre l'espace par un lien.
 * Le cadre prend la hauteur qui reste dans la fenêtre et c'est lui qui défile : ses fenêtres (aperçu d'un post, choix
 * d'un visuel) restent ainsi au milieu de l'écran.
 *
 * `window.DashReseaux.racine(shop, {apiBase, page})` rend l'élément du module, gardé d'un rendu du dashboard à l'autre.
 */
(function () {
  'use strict';
  // Le menu de l'espace tant qu'il ne l'a pas envoyé : [clé du menu, libellé, page de l'espace (lien court), requête].
  const DEPART = [['cal', 'Calendrier', 'calendrier'], ['analyse', 'Vues et abonnés', 'calendrier', 'vue=analyse'], ['suivi', 'Suivi de ma boutique', 'calendrier', 'vue=suivi'],
    ['com', 'Commentaires', 'commentaires'], ['msg', 'Messages privés', 'messages'], ['google', 'Avis Google', 'avis-google'],
    ['pub', 'Créer un post', 'publier'], ['camp', 'Publicités et opérations', 'operations'], ['ig', 'Grille Instagram', 'instagram']];
  // Les libellés courts de la barre (le libellé de l'espace reste en infobulle) : neuf onglets tiennent sur une ligne.
  const COURT = { suivi: 'Suivi', msg: 'Messages', camp: 'Publicités', ig: 'Instagram' };
  const CLE_DE_PAGE = { calendrier: 'cal', publier: 'pub', commentaires: 'com', messages: 'msg', 'avis-google': 'google', operations: 'camp', instagram: 'ig' };
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const st = { shop: null, api: '../api/cockpit', el: null, k: 'cal', err: null, url: null, menu: null, quota: null, attente: null, frame: null };

  const depart = k => DEPART.find(x => x[0] === k) || DEPART[0];
  const origineCadre = () => { try { return new URL(st.url, location.href).origin; } catch (e) { return location.origin; } };

  /** Ouvre l'espace sur une page, par un lien court (première ouverture, ou quand le cadre ne répond pas). */
  function ouvrir(k) {
    const d = depart(k);
    st.k = k; st.err = null; st.url = null; st.menu = null; st.attente = d[3] ? k : null; rendre();
    fetch(st.api + '/dashboard/social/ouvrir?shop=' + encodeURIComponent(st.shop) + '&page=' + encodeURIComponent(d[2]), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.json().catch(() => ({})).then(j => r.ok ? j : Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status)))))
      .then(x => { if (st.k === k) { st.url = x.url; } })
      .catch(e => { if (st.k === k) { st.err = e.message; } })
      .finally(() => { if (st.k === k) { rendre(); } });
  }

  /** Un onglet : l'espace change de page lui-même quand il a déjà parlé ; sinon, un lien court. */
  function aller(k) {
    const f = st.frame;
    if (f && f.contentWindow && st.menu && !st.err) {
      st.k = k; barre();
      f.contentWindow.postMessage({ source: 'cockpit', type: 'aller', k }, origineCadre());
      return;
    }
    ouvrir(k);
  }

  window.addEventListener('message', e => {
    const f = st.frame, d = e.data;
    if (!f || e.source !== f.contentWindow || e.origin !== origineCadre() || !d || d.source !== 'espace-boutique' || d.type !== 'menu') { return; }
    st.menu = Array.isArray(d.entrees) ? d.entrees.filter(x => x && typeof x.k === 'string') : null;
    st.quota = d.quota || null;
    const actif = (st.menu || []).find(x => x.actif);
    if (st.attente && st.menu && st.menu.some(x => x.k === st.attente)) {
      // Ouvert sur le calendrier pour « Vues et abonnés » ou « Suivi » : la page demandée, maintenant que l'espace écoute.
      const k = st.attente; st.attente = null; st.k = k;
      f.contentWindow.postMessage({ source: 'cockpit', type: 'aller', k }, origineCadre());
    } else if (actif) { st.k = actif.k; }
    barre();
  });

  /** La barre : les onglets (le menu de l'espace) et le quota de la semaine. Redessinée seule : le cadre ne bouge pas. */
  function barre() {
    const b = st.el && st.el.querySelector('.rx-barre');
    if (!b) { return; }
    const L = st.menu && st.menu.length ? st.menu : DEPART.map(([k, label]) => ({ k, label, n: 0 }));
    const q = st.quota;
    // Le quota de la semaine se lit sur l'onglet « Créer un post » : les posts déjà faits sur ceux permis.
    const quota = x => x.k === 'pub' && q && q.max ? ` <span class="rx-quota${q.used >= q.max ? ' plein' : ''}" title="${esc('Cette semaine : ' + q.used + ' post' + (q.used > 1 ? 's' : '') + ' sur ' + q.max + (q.regles ? ' · ' + q.regles : ''))}">${q.used}/${q.max}</span>` : '';
    b.innerHTML = `<div class="page-tabs rx-tabs">${L.map(x => `<button type="button" class="page-tabs__tab${st.k === x.k ? ' is-on' : ''}${x.groupe ? ' rx-groupe' : ''}" data-rxk="${esc(x.k)}"${COURT[x.k] ? ` title="${esc(x.label)}"` : ''}>${esc(COURT[x.k] || x.label)}${x.n > 0 ? ` <span class="page-tabs__count">${x.n}</span>` : ''}${quota(x)}</button>`).join('')}</div>`;
    b.querySelectorAll('[data-rxk]').forEach(x => x.addEventListener('click', () => aller(x.dataset.rxk)));
  }

  /** Le cadre prend la hauteur qui reste dans la fenêtre ; c'est lui qui défile. */
  function ajuster() {
    const f = st.el && st.el.querySelector('.rx-cadre');
    if (!f) { return; }
    const haut = f.getBoundingClientRect().top;
    f.style.height = Math.max(480, window.innerHeight - Math.max(0, haut)) + 'px';
  }

  function rendre() {
    if (!st.el) { return; }
    // Le cadre ne se recharge que si son adresse change : un rendu du dashboard ne doit pas faire perdre la saisie d'un post.
    const f = st.el.querySelector('.rx-cadre');
    if (f && st.url && f.dataset.src === st.url && !st.err) { barre(); ajuster(); return; }
    let corps;
    if (st.err) { corps = `<div class="db-err">L’espace boutique ne s’ouvre pas : ${esc(st.err)} <button type="button" class="db-btn" data-rxrelire="1">Réessayer</button></div>`; }
    else if (!st.url) { corps = '<div class="rx-attente"><div class="db-sk" style="height:44px;margin-bottom:10px"></div><div class="db-sk" style="height:420px"></div></div>'; }
    else { corps = `<iframe class="rx-cadre" src="${esc(st.url)}" data-src="${esc(st.url)}" title="Réseaux sociaux — espace boutique du Marketing"></iframe>`; }
    st.el.innerHTML = `<div class="rx-barre"></div>${corps}`;
    st.frame = st.el.querySelector('.rx-cadre');
    barre();
    st.el.querySelectorAll('[data-rxrelire]').forEach(b => b.addEventListener('click', () => ouvrir(st.k)));
    requestAnimationFrame(ajuster);
  }

  window.addEventListener('resize', ajuster);

  window.DashReseaux = {
    racine(shop, o) {
      o = o || {};
      if (o.apiBase) { st.api = o.apiBase; }
      const k = o.page ? (CLE_DE_PAGE[o.page] || (DEPART.some(x => x[0] === o.page) ? o.page : null)) : null;
      if (!st.el || String(st.shop) !== String(shop)) {
        st.shop = String(shop); st.el = document.createElement('div'); st.el.className = 'rx-racine';
        ouvrir(k || st.k);
      } else if (k && k !== st.k) { aller(k); }
      else { requestAnimationFrame(ajuster); }
      return st.el;
    },
    relire() { if (st.el) { ouvrir(st.k); } },
  };
})();
