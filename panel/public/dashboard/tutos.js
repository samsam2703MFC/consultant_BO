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
 *   accrochée au carré (« Voir la vidéo » ; « Plus tard » la range pour la journée : elle revient le lendemain, tant que
 *   la vidéo n'est pas vue).
 * - Le lecteur : une fenêtre, la vidéo, puis la page onglet par onglet (les vidéos des onglets et, sous chacune, celles
 *   de ses fonctions), puis les vidéos des autres pages. Ouvrir une vidéo la marque « vue ».
 * - TROIS NIVEAUX (24/11/2026, Sam : « chaque vidéo dans le rail, ensuite une vidéo par onglet interne, ensuite une
 *   vidéo spécifique à chaque fonction ») : `niveau` page (par défaut), onglet ou fonction ; `parent` dit sous quelle
 *   vidéo elle se range. Les onglets et leurs fonctions partagent un fichier (un MP4 par onglet, les fonctions en
 *   chapitres) : `debut` et `fin` (secondes) bornent le passage, que le lecteur joue seul.
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
  const NIVEAUX = { onglet: 'Onglet', fonction: 'Fonction' };
  // `memo` : « vu » et « plus tard » gardés aussi en mémoire de la page (24/11/2026, Sam : « si je pousse plus tard, la
  // bulle doit partir ») — quand le navigateur refuse d'écrire (stockage plein ou bloqué : plusieurs applications
  // partagent l'adresse du serveur), la bulle part quand même et ne revient pas avant le rechargement.
  const T = { videos: [], vue: vueDeLAdresse(), lecteur: null, annonce: null, minuterie: null, attente: null, memo: { [CLE_VUS]: {}, [CLE_TARD]: {} } };

  function vueDeLAdresse() { return new URLSearchParams(location.search).get('vue') || 'ops'; }
  /** Ce qui est gardé : l'appareil (localStorage), l'onglet (sessionStorage, en secours) et la page (T.memo). */
  function lireSet(cle) {
    const lu = st => { try { return JSON.parse(st.getItem(cle) || '{}') || {}; } catch (e) { return {}; } };
    return Object.assign({}, lu(window.sessionStorage), lu(window.localStorage), T.memo[cle] || {});
  }
  function ecrireSet(cle, o) {
    T.memo[cle] = Object.assign(T.memo[cle] || {}, o);
    const txt = JSON.stringify(o);
    try { localStorage.setItem(cle, txt); return; } catch (e) { /* plein ou bloqué : l'onglet, sinon la page seule */ }
    try { sessionStorage.setItem(cle, txt); } catch (e) { /* la page seule */ }
  }
  function vue(id) { return !!lireSet(CLE_VUS)[id]; }
  function marquerVue(id) { const o = lireSet(CLE_VUS); o[id] = new Date().toISOString().slice(0, 10); ecrireSet(CLE_VUS, o); }
  /** Le jour local (AAAA-MM-JJ) : « plus tard » vaut pour la journée. */
  function jour() { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function plusTard(id) { const o = lireSet(CLE_TARD); o[id] = jour(); ecrireSet(CLE_TARD, o); }
  /** « Plus tard » (ou ×) range l'annonce pour la journée ; elle revient le lendemain, tant que la vidéo n'est pas vue
   * (23/11/2026, Sam : « quand je mets plus tard, il ne s'ouvre que quand je pousse dessus »). */
  function remise(id) { return lireSet(CLE_TARD)[id] === jour(); }
  /** Une nouvelle fonction pas encore vue, et récente. */
  function aVoir(v) {
    if (v.genre !== 'nouveau' || vue(v.id)) { return false; }
    const d = Date.parse((v.depuis || '') + 'T00:00:00');
    return !isNaN(d) && (Date.now() - d) / 864e5 <= JOURS_NOUVEAU;
  }
  const dePage = v => (v.vues || []).includes(T.vue) || (v.vues || []).includes('*');
  const estPage = v => !v.niveau || v.niveau === 'page';
  const parId = id => T.videos.find(x => x.id === id) || null;
  const enfants = v => T.videos.filter(x => x.parent === v.id && !estPage(x));
  /** La vidéo de page sous laquelle une vidéo se range (elle-même pour une page). */
  function racine(v) { let r = v; for (let i = 0; i < 4 && r && !estPage(r); i++) { const p = parId(r.parent); if (!p) { break; } r = p; } return r; }
  /** La vidéo qu'ouvre le carré : celle de la page (une nouveauté d'abord), sinon une nouveauté, sinon le mode d'emploi. */
  function choix() {
    const L = T.videos.filter(estPage);
    return L.find(v => dePage(v) && aVoir(v)) || L.find(dePage) || L.find(aVoir) || L.find(v => v.genre === 'tuto') || L[0] || null;
  }
  /** Le chemin d'une vidéo d'onglet ou de fonction : « Boîte mail › Classique ». */
  function chemin(v) {
    const L = []; let r = v;
    for (let i = 0; i < 3 && r && r.parent; i++) { r = parId(r.parent); if (r) { L.unshift(r.titre); } }
    return L.join(' › ');
  }
  const src = v => 'videos/' + esc(v.fichier) + (v.debut != null && v.fin != null ? '#t=' + (+v.debut).toFixed(2) + ',' + (+v.fin).toFixed(2) : '');
  const item = (x, actif) => `<button type="button" class="tu-item${actif ? ' on' : ''}" data-tuf="v" data-id="${esc(x.id)}"${actif ? ' aria-current="true"' : ''}>${x.affiche ? `<img src="videos/${esc(x.affiche)}" alt="" loading="lazy">` : ''}<span><b>${esc(x.titre)}</b><small>${aVoir(x) ? '<em class="tu-tag">Nouveau</em> ' : ''}${esc(x.duree || '')}</small></span></button>`;
  /** La page, onglet par onglet : chaque onglet, puis ses fonctions en lignes courtes. */
  function arbre(page, v) {
    const O = enfants(page);
    if (!O.length) { return ''; }
    return `<div class="tu-autres"><div class="tu-k">${esc(page.titre)} · onglet par onglet</div><div class="tu-arbre">${O.map(o => {
      const F = enfants(o);
      return `<div class="tu-ong">${item(o, o.id === v.id)}${F.length ? `<div class="tu-fns">${F.map(f => `<button type="button" class="tu-fn${f.id === v.id ? ' on' : ''}" data-tuf="v" data-id="${esc(f.id)}"${f.id === v.id ? ' aria-current="true"' : ''}><span>${esc(f.titre)}</span><small>${esc(f.duree || '')}</small></button>`).join('')}</div>` : ''}</div>`;
    }).join('')}</div></div>`;
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
    const L = T.videos.filter(v => aVoir(v) && !remise(v.id));
    const v = L.find(dePage) || L[0];
    const c = carreVisible();
    if (!v || !c || T.lecteur) { retirerAnnonce(); return; }
    if (!T.annonce || T.annonce.dataset.id !== v.id) {
      retirerAnnonce();
      const a = document.createElement('div');
      a.className = 'tu-annonce'; a.dataset.id = v.id;
      a.setAttribute('role', 'dialog'); a.setAttribute('aria-label', 'Nouvelle fonction : ' + v.titre);
      // Minimaliste (23/11/2026, Sam : « fais la boîte autour du message plus minimaliste ») : « Nouveau », le titre, deux liens.
      a.innerHTML = `<div class="tu-a-k">Nouveau</div><div class="tu-a-t">${esc(v.titre)}</div>
        <div class="tu-a-a"><button type="button" class="tu-a-l" data-tu="voir" data-id="${esc(v.id)}">Voir la vidéo${v.duree ? ' · ' + esc(v.duree) : ''}</button><button type="button" class="tu-a-l tu-a-tard" data-tu="tard" data-id="${esc(v.id)}">Plus tard</button></div>`;
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
    const page = racine(v) || v;
    const autres = T.videos.filter(x => estPage(x) && x.id !== page.id);
    const k = (NIVEAUX[v.niveau] ? NIVEAUX[v.niveau] + ' · ' + esc(chemin(v)) : GENRES[v.genre] || GENRES.tuto) + (v.duree ? ' · ' + esc(v.duree) : '');
    o.innerHTML = `<div class="tu-fen" role="dialog" aria-modal="true" aria-label="${esc(v.titre)}">
      <div class="tu-f-h"><div><div class="tu-k">${k}</div><h3>${esc(v.titre)}</h3></div><a class="tu-mt" href="../formation/catalogue/#/videos/v/${encodeURIComponent(v.id)}" target="_blank" rel="noopener">Voir dans la médiathèque ↗</a><button type="button" class="tu-x" data-tuf="x" aria-label="Fermer">${ICO_X}</button></div>
      <video class="tu-video" src="${src(v)}"${v.affiche ? ` poster="videos/${esc(v.affiche)}"` : ''} controls autoplay playsinline preload="metadata"></video>
      ${v.resume ? `<p class="tu-res">${esc(v.resume)}</p>` : ''}
      ${page.id !== v.id ? `<div class="tu-autres tu-retour">${item(page, false)}</div>` : ''}
      ${arbre(page, v)}
      ${autres.length ? `<div class="tu-autres"><div class="tu-k tu-k-l"><span>Les autres pages</span><a href="../formation/catalogue/#/videos/${encodeURIComponent(((page.vues || []).filter(x => x !== '*')[0]) || T.vue)}" target="_blank" rel="noopener">La médiathèque ↗</a></div><div class="tu-liste">${autres.map(x => item(x, false)).join('')}</div></div>` : ''}
    </div>`;
    // Un passage (onglet ou fonction) s'arrête à sa fin, une fois : on peut ensuite avancer à la main dans le fichier.
    const vid = o.querySelector('video');
    if (vid && v.fin != null) {
      let fini = false;
      vid.addEventListener('timeupdate', () => { if (!fini && vid.currentTime >= +v.fin - 0.05) { fini = true; vid.pause(); } });
      vid.addEventListener('loadedmetadata', () => { if (v.debut != null && vid.currentTime < +v.debut) { vid.currentTime = +v.debut; } }, { once: true });
    }
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
