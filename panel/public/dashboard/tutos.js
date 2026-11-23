/* Dashboard magasin › les vidéos d'instruction (23/11/2026, demande : « des vidéos d'instruction pour utiliser le
 * dashboard », « déclenchées par une bulle (nouvelle fonction ou autre) » ; puis « le bouton vidéo dans un petit carré
 * à côté de l'assistant IA » — proposition 3 retenue, maquettes panel/docs/maquettes/videos-bouton/ ; rien au
 * téléphone).
 *
 * Le registre : videos/videos.json (servi à côté de la page). Chaque vidéo dit ses pages (`vues`, les valeurs de
 * ?vue= ; « ops » pour Opérationnel, « * » partout), son genre (`nouveau` : une nouvelle fonction, annoncée ; `tuto` :
 * un mode d'emploi ; `formation` : un module de formation, à suivre en session), sa date (`depuis`), son MP4 et son
 * affiche.
 *
 * - LE CARRÉ : dans le rail, à côté d'« Assistant IA » (à côté de « Boîte mail » quand l'assistant n'est pas ouvert au
 *   magasin). dashboard.js pose la place (`.db-tu-slot`, remplie par `DashTutos.carre()` à chaque rendu du rail) ; ce
 *   module la remplit aussi dès que le registre est lu. Abricot au repos ; Ruby avec le nombre de vidéos « nouveau »
 *   pas encore vues. Un clic ouvre le lecteur : la vidéo de la page s'il y en a une, sinon la nouveauté, sinon le
 *   mode d'emploi.
 * - L'ANNONCE : une nouvelle fonction pas encore vue (moins de JOURS_NOUVEAU jours) s'annonce dans une bulle
 *   accrochée au carré (« Voir la vidéo », « Plus tard » : elle ne revient plus pour cette vidéo).
 * - Le lecteur : une fenêtre, la vidéo, la liste des autres vidéos. Ouvrir une vidéo la marque « vue ».
 * « Vu » et « plus tard » sont gardés par appareil (localStorage) : rien n'est écrit au serveur.
 * Au téléphone, le rail n'est pas affiché : ni carré ni annonce. Rien dans le mode incorporé (?embed=).
 * La page ouverte : ?vue= à l'ouverture, puis l'évènement `dashboard:vue` (urlMaj de dashboard.js).
 * `window.DashTutos.ouvrir(id?)` ouvre le lecteur ; `window.DashTutos.carre()` rend le carré (HTML).
 */
(function () {
  'use strict';
  const q = new URLSearchParams(location.search);
  if (q.get('embed')) { return; }
  const REGISTRE = 'videos/videos.json';
  const JOURS_NOUVEAU = 60;
  const CLE_VUS = 'db.videos.vues', CLE_TARD = 'db.videos.plusTard';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const ICO_VIDEO = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m10.2 9.3 4.6 2.7-4.6 2.7z"/></svg>';
  const ICO_X = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';
  const GENRES = { nouveau: 'Nouvelle fonction', tuto: 'Mode d’emploi', formation: 'Formation' };
  const T = { videos: [], vue: vueDeLAdresse(), lecteur: null, annonce: null, minuterie: null, attente: null };

  function vueDeLAdresse() { return new URLSearchParams(location.search).get('vue') || 'ops'; }
  function lireSet(cle) { try { return JSON.parse(localStorage.getItem(cle) || '{}') || {}; } catch (e) { return {}; } }
  function ecrireSet(cle, o) { try { localStorage.setItem(cle, JSON.stringify(o)); } catch (e) { /* stockage indisponible : l'annonce reviendra */ } }
  function vue(id) { return !!lireSet(CLE_VUS)[id]; }
  function marquerVue(id) { const o = lireSet(CLE_VUS); o[id] = new Date().toISOString().slice(0, 10); ecrireSet(CLE_VUS, o); }
  function plusTard(id) { const o = lireSet(CLE_TARD); o[id] = new Date().toISOString().slice(0, 10); ecrireSet(CLE_TARD, o); }
  /** Une nouvelle fonction pas encore vue, et récente. */
  function aVoir(v) {
    if (v.genre !== 'nouveau' || vue(v.id)) { return false; }
    const d = Date.parse((v.depuis || '') + 'T00:00:00');
    return !isNaN(d) && (Date.now() - d) / 864e5 <= JOURS_NOUVEAU;
  }
  const dePage = v => (v.vues || []).includes(T.vue) || (v.vues || []).includes('*');
  /** La vidéo qu'ouvre le carré : celle de la page (une nouveauté d'abord), sinon une nouveauté, sinon le mode d'emploi. */
  function choix() {
    const L = T.videos;
    return L.find(v => dePage(v) && aVoir(v)) || L.find(dePage) || L.find(aVoir) || L.find(v => v.genre === 'tuto') || L[0] || null;
  }

  /* --- le carré ------------------------------------------------------------------------------------------------- */
  function carre() {
    if (!T.videos.length) { return ''; }
    // Le rail vient d'être redessiné avec ce carré : l'annonce se (re)place juste après.
    if (!T.attente) { T.attente = setTimeout(() => { T.attente = null; annoncer(); }, 0); }
    const n = T.videos.filter(aVoir).length;
    const v = choix();
    const titre = n ? n + ' nouvelle' + (n > 1 ? 's' : '') + ' vidéo' + (n > 1 ? 's' : '') + ' à voir' : 'Vidéo : ' + (v ? v.titre : 'les vidéos');
    return `<button type="button" class="tu-carre${n ? ' nv' : ''}" data-tu="ouvrir" title="${esc(titre)}" aria-label="${esc(titre)}">${ICO_VIDEO}${n ? `<i class="tu-n">${n}</i>` : ''}</button>`;
  }
  function remplir() {
    document.querySelectorAll('.db-tu-slot').forEach(s => { const h = carre(); if (s.innerHTML !== h) { s.innerHTML = h; } });
    annoncer();
  }

  /* --- l'annonce : une bulle accrochée au carré ----------------------------------------------------------------- */
  function carreVisible() {
    return [...document.querySelectorAll('.tu-carre')].find(c => c.offsetParent !== null && c.getBoundingClientRect().width > 0) || null;
  }
  function annoncer() {
    const tard = lireSet(CLE_TARD);
    const L = T.videos.filter(v => aVoir(v) && !tard[v.id]);
    const v = L.find(dePage) || L[0];
    const c = carreVisible();
    if (!v || !c || T.lecteur) { retirerAnnonce(); return; }
    if (!T.annonce || T.annonce.dataset.id !== v.id) {
      retirerAnnonce();
      const a = document.createElement('div');
      a.className = 'tu-annonce'; a.dataset.id = v.id;
      a.setAttribute('role', 'dialog'); a.setAttribute('aria-label', 'Nouvelle fonction : ' + v.titre);
      a.innerHTML = `<div class="tu-a-h"><span class="tu-tag">Nouveau</span><button type="button" class="tu-x" data-tu="tard" data-id="${esc(v.id)}" title="Plus tard" aria-label="Plus tard">${ICO_X}</button></div>
        <div class="tu-a-t">${esc(v.titre)}</div>${v.resume ? `<div class="tu-a-r">${esc(v.resume)}</div>` : ''}
        <div class="tu-a-a"><button type="button" class="btn btn--primary btn--sm" data-tu="voir" data-id="${esc(v.id)}">Voir la vidéo${v.duree ? ' · ' + esc(v.duree) : ''}</button><button type="button" class="btn btn--ghost btn--sm" data-tu="tard" data-id="${esc(v.id)}">Plus tard</button></div>`;
      document.body.appendChild(a);
      T.annonce = a;
      // Le rail défile, se redessine : la bulle suit son carré.
      T.minuterie = setInterval(placer, 400);
    }
    placer();
  }
  function placer() {
    const a = T.annonce; if (!a) { return; }
    const c = carreVisible();
    if (!c) { a.hidden = true; return; }
    const r = c.getBoundingClientRect();
    const rail = document.getElementById('db-rail');
    const gauche = Math.max(r.right, rail ? rail.getBoundingClientRect().right : r.right) + 14;
    a.hidden = false;
    const h = a.offsetHeight;
    const haut = Math.min(Math.max(8, r.top + r.height / 2 - 30), window.innerHeight - h - 8);
    a.style.left = gauche + 'px'; a.style.top = haut + 'px';
    a.style.setProperty('--y', Math.round(r.top + r.height / 2 - haut - 7) + 'px');
  }
  function retirerAnnonce() {
    if (T.minuterie) { clearInterval(T.minuterie); T.minuterie = null; }
    if (T.annonce) { T.annonce.remove(); T.annonce = null; }
  }

  /* --- le lecteur ----------------------------------------------------------------------------------------------- */
  function ouvrir(id) {
    const v = T.videos.find(x => x.id === id) || choix();
    if (!v) { return; }
    marquerVue(v.id);
    fermer(true);
    retirerAnnonce();
    const o = document.createElement('div');
    o.className = 'tu-voile';
    o.innerHTML = `<div class="tu-fen" role="dialog" aria-modal="true" aria-label="${esc(v.titre)}">
      <div class="tu-f-h"><div><div class="tu-k">${GENRES[v.genre] || GENRES.tuto}${v.duree ? ' · ' + esc(v.duree) : ''}</div><h3>${esc(v.titre)}</h3></div><button type="button" class="tu-x" data-tuf="x" aria-label="Fermer">${ICO_X}</button></div>
      <video class="tu-video" src="videos/${esc(v.fichier)}"${v.affiche ? ` poster="videos/${esc(v.affiche)}"` : ''} controls autoplay playsinline preload="metadata"></video>
      ${v.resume ? `<p class="tu-res">${esc(v.resume)}</p>` : ''}
      ${T.videos.length > 1 ? `<div class="tu-autres"><div class="tu-k">Les autres vidéos</div><div class="tu-liste">${T.videos.filter(x => x.id !== v.id).map(x => `<button type="button" class="tu-item" data-tuf="v" data-id="${esc(x.id)}">${x.affiche ? `<img src="videos/${esc(x.affiche)}" alt="">` : ''}<span><b>${esc(x.titre)}</b><small>${aVoir(x) ? '<em class="tu-tag">Nouveau</em> ' : ''}${esc(x.duree || '')}</small></span></button>`).join('')}</div></div>` : ''}
    </div>`;
    o.addEventListener('click', e => {
      if (e.target === o || e.target.closest('[data-tuf="x"]')) { fermer(); return; }
      const it = e.target.closest('[data-tuf="v"]');
      if (it) { ouvrir(it.dataset.id); }
    });
    document.body.appendChild(o);
    T.lecteur = o;
    document.addEventListener('keydown', echap);
    const b = o.querySelector('.tu-x'); if (b) { b.focus({ preventScroll: true }); }
    remplir();
  }
  function echap(e) { if (e.key === 'Escape') { fermer(); } }
  function fermer(silence) {
    if (!T.lecteur) { return; }
    const vid = T.lecteur.querySelector('video'); if (vid) { try { vid.pause(); } catch (e) { /* déjà arrêtée */ } }
    T.lecteur.remove(); T.lecteur = null;
    document.removeEventListener('keydown', echap);
    if (!silence) { remplir(); }
  }

  /* --- le branchement ------------------------------------------------------------------------------------------- */
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-tu]');
    if (!b) { return; }
    if (b.dataset.tu === 'ouvrir') { ouvrir((choix() || {}).id); }
    if (b.dataset.tu === 'voir') { ouvrir(b.dataset.id); }
    if (b.dataset.tu === 'tard') { plusTard(b.dataset.id); retirerAnnonce(); remplir(); }
  });
  window.addEventListener('dashboard:vue', e => { T.vue = (e.detail && e.detail.vue) || vueDeLAdresse(); fermer(true); remplir(); });
  window.addEventListener('resize', placer);
  function demarrer() {
    fetch(REGISTRE, { cache: 'no-cache', headers: { Accept: 'application/json' } })
      .then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)))
      .then(j => { T.videos = (j && Array.isArray(j.videos) ? j.videos : []).filter(v => v && v.id && v.fichier); remplir(); })
      .catch(() => { /* pas de registre : pas de carré */ });
  }
  window.DashTutos = { ouvrir, carre, n: () => T.videos.length };
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', demarrer); } else { demarrer(); }
})();
