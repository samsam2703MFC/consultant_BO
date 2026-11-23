/* Dashboard magasin › les vidéos d'instruction, ouvertes par une bulle (23/11/2026, demande : « des vidéos
 * d'instruction pour utiliser le dashboard », « déclenchées par une bulle (nouvelle fonction ou autre) »).
 *
 * Le registre : videos/videos.json (servi à côté de la page). Chaque vidéo dit les pages où sa bulle se montre
 * (`vues`, les valeurs de ?vue= ; « ops » pour Opérationnel, « * » partout), son genre (`nouveau` : une nouvelle
 * fonction, annoncée ; `tuto` : un mode d'emploi ; `formation` : un module de formation, à suivre en session), sa date
 * (`depuis`), son fichier MP4 et son affiche.
 *
 * - Une NOUVELLE fonction pas encore vue (et de moins de JOURS_NOUVEAU jours) : la bulle s'ouvre en carte
 *   « Nouveau », avec l'affiche et « Voir la vidéo » ; « Plus tard » la replie.
 * - Sinon : une petite bulle « Vidéo · durée » en bas à droite, sur chaque page qui a sa vidéo.
 * - Le lecteur : une fenêtre, la vidéo, et la liste des autres vidéos. Ouvrir une vidéo la marque « vue ».
 * « Vu » et « plus tard » sont gardés par appareil (localStorage) : rien n'est écrit au serveur.
 *
 * La page ouverte : ?vue= à l'ouverture, puis l'évènement `dashboard:vue` que dashboard.js lance à chaque
 * changement de page (urlMaj). Rien dans le mode incorporé (?embed=).
 * `window.DashTutos.ouvrir(id?)` ouvre le lecteur (sur une vidéo, ou la première de la page).
 */
(function () {
  'use strict';
  const q = new URLSearchParams(location.search);
  if (q.get('embed')) { return; }
  const REGISTRE = 'videos/videos.json';
  const JOURS_NOUVEAU = 60;
  const CLE_VUS = 'db.videos.vues', CLE_TARD = 'db.videos.plusTard';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const ICO_PLAY = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m10 8.5 5.5 3.5-5.5 3.5z"/></svg>';
  const ICO_X = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';

  const GENRES = { nouveau: 'Nouvelle fonction', tuto: 'Mode d’emploi', formation: 'Formation' };
  const T = { videos: [], vue: vueDeLAdresse(), lecteur: null, bulle: null };

  function vueDeLAdresse() { return new URLSearchParams(location.search).get('vue') || 'ops'; }
  function lireSet(cle) { try { return JSON.parse(localStorage.getItem(cle) || '{}') || {}; } catch (e) { return {}; } }
  function ecrireSet(cle, o) { try { localStorage.setItem(cle, JSON.stringify(o)); } catch (e) { /* stockage indisponible : la bulle reviendra */ } }
  function vue(id) { return !!lireSet(CLE_VUS)[id]; }
  function marquerVue(id) { const o = lireSet(CLE_VUS); o[id] = new Date().toISOString().slice(0, 10); ecrireSet(CLE_VUS, o); }
  function plusTard(id) { const o = lireSet(CLE_TARD); o[id] = new Date().toISOString().slice(0, 10); ecrireSet(CLE_TARD, o); }
  function estNouvelle(v) {
    if (v.genre !== 'nouveau' || vue(v.id) || lireSet(CLE_TARD)[v.id]) { return false; }
    const d = Date.parse((v.depuis || '') + 'T00:00:00');
    return !isNaN(d) && (Date.now() - d) / 864e5 <= JOURS_NOUVEAU;
  }
  /** Les vidéos de la page ouverte : d'abord les nouvelles, puis celles de la page, puis celles de partout. */
  function videosDeLaPage() {
    const ici = T.videos.filter(v => (v.vues || []).includes(T.vue));
    const partout = T.videos.filter(v => (v.vues || []).includes('*') && !ici.includes(v));
    return ici.concat(partout).sort((a, b) => (estNouvelle(b) ? 1 : 0) - (estNouvelle(a) ? 1 : 0));
  }

  /* --- la bulle ------------------------------------------------------------------------------------------------- */
  function bulle() {
    if (!T.bulle) {
      T.bulle = document.createElement('div');
      T.bulle.className = 'tu-bulle';
      T.bulle.setAttribute('role', 'complementary');
      T.bulle.setAttribute('aria-label', 'Vidéos d’instruction');
      T.bulle.addEventListener('click', e => {
        const b = e.target.closest('[data-tu]');
        if (!b) { return; }
        const v = T.videos.find(x => x.id === b.dataset.id);
        if (b.dataset.tu === 'voir' && v) { ouvrir(v.id); }
        if (b.dataset.tu === 'tard' && v) { plusTard(v.id); rendreBulle(); }
      });
      document.body.appendChild(T.bulle);
    }
    return T.bulle;
  }
  function rendreBulle() {
    const el = bulle();
    const L = videosDeLaPage();
    const v = L[0];
    el.classList.toggle('tu-haut', !!document.querySelector('.mb-tabs'));
    if (!v || T.lecteur) { el.hidden = true; el.innerHTML = ''; return; }
    el.hidden = false;
    if (estNouvelle(v)) {
      el.className = 'tu-bulle tu-carte' + (el.classList.contains('tu-haut') ? ' tu-haut' : '');
      el.innerHTML = `<div class="tu-c-h"><span class="tu-tag">Nouveau</span><button type="button" class="tu-x" data-tu="tard" data-id="${esc(v.id)}" title="Plus tard" aria-label="Plus tard">${ICO_X}</button></div>
        <button type="button" class="tu-affiche" data-tu="voir" data-id="${esc(v.id)}" aria-label="Voir la vidéo : ${esc(v.titre)}">${v.affiche ? `<img src="videos/${esc(v.affiche)}" alt="">` : ''}<span class="tu-lire">${ICO_PLAY}</span></button>
        <div class="tu-c-t">${esc(v.titre)}</div>
        <div class="tu-c-r">${esc(v.resume || '')}</div>
        <div class="tu-c-a"><button type="button" class="btn btn--primary btn--sm" data-tu="voir" data-id="${esc(v.id)}">Voir la vidéo${v.duree ? ' · ' + esc(v.duree) : ''}</button><button type="button" class="btn btn--ghost btn--sm" data-tu="tard" data-id="${esc(v.id)}">Plus tard</button></div>`;
    } else {
      el.className = 'tu-bulle tu-mini' + (el.classList.contains('tu-haut') ? ' tu-haut' : '');
      const n = L.filter(x => !vue(x.id)).length;
      el.innerHTML = `<button type="button" class="tu-b" data-tu="voir" data-id="${esc(v.id)}" title="${esc(v.titre)}">${ICO_PLAY}<span>Vidéo${v.duree ? ' · ' + esc(v.duree) : ''}</span>${n ? '<i class="tu-pt" aria-label="pas encore vue"></i>' : ''}</button>`;
    }
  }

  /* --- le lecteur ----------------------------------------------------------------------------------------------- */
  function ouvrir(id) {
    const v = T.videos.find(x => x.id === id) || videosDeLaPage()[0] || T.videos[0];
    if (!v) { return; }
    marquerVue(v.id);
    fermer(true);
    const o = document.createElement('div');
    o.className = 'tu-voile';
    o.innerHTML = `<div class="tu-fen" role="dialog" aria-modal="true" aria-label="${esc(v.titre)}">
      <div class="tu-f-h"><div><div class="tu-k">${GENRES[v.genre] || GENRES.tuto}${v.duree ? ' · ' + esc(v.duree) : ''}</div><h3>${esc(v.titre)}</h3></div><button type="button" class="tu-x" data-tuf="x" aria-label="Fermer">${ICO_X}</button></div>
      <video class="tu-video" src="videos/${esc(v.fichier)}"${v.affiche ? ` poster="videos/${esc(v.affiche)}"` : ''} controls autoplay playsinline preload="metadata"></video>
      ${v.resume ? `<p class="tu-res">${esc(v.resume)}</p>` : ''}
      ${T.videos.length > 1 ? `<div class="tu-autres"><div class="tu-k">Les autres vidéos</div><div class="tu-liste">${T.videos.filter(x => x.id !== v.id).map(x => `<button type="button" class="tu-item" data-tuf="v" data-id="${esc(x.id)}">${x.affiche ? `<img src="videos/${esc(x.affiche)}" alt="">` : ''}<span><b>${esc(x.titre)}</b><small>${x.genre === 'nouveau' && !vue(x.id) ? '<em class="tu-tag">Nouveau</em> ' : ''}${esc(x.duree || '')}</small></span></button>`).join('')}</div></div>` : ''}
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
    rendreBulle();
  }
  function echap(e) { if (e.key === 'Escape') { fermer(); } }
  function fermer(silence) {
    if (!T.lecteur) { return; }
    const vid = T.lecteur.querySelector('video'); if (vid) { try { vid.pause(); } catch (e) { /* déjà arrêtée */ } }
    T.lecteur.remove(); T.lecteur = null;
    document.removeEventListener('keydown', echap);
    if (!silence) { rendreBulle(); }
  }

  /* --- le branchement ------------------------------------------------------------------------------------------- */
  window.addEventListener('dashboard:vue', e => { T.vue = (e.detail && e.detail.vue) || vueDeLAdresse(); fermer(true); rendreBulle(); });
  window.addEventListener('resize', () => { if (T.bulle) { rendreBulle(); } });
  function demarrer() {
    fetch(REGISTRE, { cache: 'no-cache', headers: { Accept: 'application/json' } })
      .then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)))
      .then(j => { T.videos = (j && Array.isArray(j.videos) ? j.videos : []).filter(v => v && v.id && v.fichier); rendreBulle(); })
      .catch(() => { /* pas de registre : pas de bulle */ });
  }
  window.DashTutos = { ouvrir, n: () => T.videos.length };
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', demarrer); } else { demarrer(); }
})();
