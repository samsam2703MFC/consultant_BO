/* Dashboard magasin › « API » (15/11/2026, demande : « un index des API en modale, via un bouton API dans le header, pour
 * vérifier quels sont les appels »).
 *
 * Deux choses, chargées avant le reste du dashboard :
 *  - le JOURNAL : chaque appel à l'API du cockpit fait par la page (le dashboard et ses modules) est noté — méthode,
 *    chemin, statut HTTP, durée, heure. Rien ne part ailleurs : il vit dans l'onglet du navigateur, et s'oublie avec lui ;
 *  - l'INDEX : public/dashboard/api-index.json, généré depuis le code par tools/dashboard-api-index.py — chaque chemin
 *    que le dashboard appelle, la fonction PHP qui le sert, son fichier, la première phrase de son commentaire, et s'il
 *    est ouvert à une session de magasin.
 * `window.DashApi.ouvrir()` ouvre la fenêtre ; `window.DashApi.n()` dit le nombre d'appels de la session.
 */
(function () {
  'use strict';
  const MAX = 400;
  const journal = [];
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const nf0 = new Intl.NumberFormat('fr-BE', { maximumFractionDigits: 0 });

  /* --- le journal : fetch, enveloppé ---------------------------------------------------------------------------- */
  const fetchOrigine = window.fetch ? window.fetch.bind(window) : null;
  function cheminApi(u) {
    try {
      const x = new URL(typeof u === 'string' ? u : (u && u.url) || '', location.href);
      const i = x.pathname.indexOf('/api/cockpit');
      return i < 0 ? null : { p: x.pathname.slice(i + '/api/cockpit'.length) || '/', q: x.search.replace(/^\?/, '') };
    } catch (e) { return null; }
  }
  if (fetchOrigine) {
    window.fetch = function (entree, init) {
      const c = cheminApi(entree);
      if (!c) { return fetchOrigine(entree, init); }
      const m = String((init && init.method) || (entree && entree.method) || 'GET').toUpperCase();
      const t0 = performance.now(), e = { m, p: c.p, q: c.q.replace(/(^|&)(_cache|rafraichir|frais|fond)=[^&]*/g, '').replace(/^&/, ''), s: null, ms: null, t: new Date() };
      journal.push(e); if (journal.length > MAX) { journal.shift(); }
      return fetchOrigine(entree, init).then(r => { e.s = r.status; e.ms = Math.round(performance.now() - t0); vivant(); return r; },
        x => { e.s = 0; e.ms = Math.round(performance.now() - t0); vivant(); throw x; });
    };
  }

  /* --- l'index -------------------------------------------------------------------------------------------------- */
  let index = null, indexErr = null, lecture = null;
  function lireIndex() {
    if (index || lecture) { return lecture; }
    lecture = (fetchOrigine || fetch)('api-index.json', { cache: 'no-cache' }).then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)))
      .then(d => { index = d; }).catch(e => { indexErr = e.message; }).finally(() => { lecture = null; rendre(); });
    return lecture;
  }
  /** La route de l'index qui sert un chemin appelé (chemin exact, motif, ou préfixe d'un routeur). */
  function routeDe(p) {
    if (!index) { return null; }
    const L = index.routes || [];
    return L.find(r => r.chemin === p) || L.find(r => (r.routes || []).some(x => x.chemin.startsWith('^') && new RegExp(x.chemin).test(p)))
      || L.filter(r => p.startsWith(r.chemin + '/')).sort((a, b) => b.chemin.length - a.chemin.length)[0] || null;
  }

  /* --- la fenêtre ----------------------------------------------------------------------------------------------- */
  const st = { el: null, onglet: 'session', q: '' };
  let tVivant = null;
  function vivant() { if (st.el) { clearTimeout(tVivant); tVivant = setTimeout(rendre, 250); } }

  function session() {
    const par = new Map();
    journal.forEach(e => {
      const k = e.m + ' ' + e.p;
      const g = par.get(k) || { m: e.m, p: e.p, n: 0, ko: 0, ms: 0, nMs: 0, s: null, t: null, q: '' };
      g.n++; if (e.s != null && (e.s === 0 || e.s >= 400)) { g.ko++; }
      if (e.ms != null) { g.ms += e.ms; g.nMs++; }
      if (!g.t || e.t > g.t) { g.t = e.t; g.s = e.s; g.q = e.q; }
      par.set(k, g);
    });
    const q = st.q.trim().toLowerCase();
    const L = [...par.values()].filter(g => !q || (g.m + ' ' + g.p + ' ' + g.q).toLowerCase().includes(q)).sort((a, b) => b.t - a.t);
    if (!L.length) { return `<p class="api-mu">${journal.length ? 'Aucun appel ne répond à la recherche.' : 'Pas encore d’appel dans cette session.'}</p>`; }
    return `<table class="api-t"><thead><tr><th>Méthode</th><th>Chemin</th><th class="n">Appels</th><th>Dernier statut</th><th class="n">Durée moy.</th><th>Dernier appel</th><th>Servi par</th></tr></thead><tbody>
      ${L.map(g => { const r = routeDe(g.p), h = r && (r.routes || [])[0]; return `<tr><td><span class="api-m ${g.m}">${g.m}</span></td>
        <td class="api-p">${esc(g.p)}${g.q ? `<small>?${esc(g.q.slice(0, 90))}${g.q.length > 90 ? '…' : ''}</small>` : ''}</td><td class="n">${g.n}${g.ko ? ` <span class="api-ko">(${g.ko} en erreur)</span>` : ''}</td>
        <td>${g.s == null ? '<span class="api-mu">en cours</span>' : `<span class="api-s ${g.s === 0 || g.s >= 400 ? 'ko' : 'ok'}">${g.s === 0 ? 'réseau' : g.s}</span>`}</td>
        <td class="n">${g.nMs ? nf0.format(g.ms / g.nMs) + ' ms' : '—'}</td><td>${g.t.toTimeString().slice(0, 8)}</td>
        <td>${h ? `<code>${esc(h.handler)}</code><small>${esc(h.source)}</small>` : '<span class="api-mu">hors index</span>'}</td></tr>`; }).join('')}</tbody></table>`;
  }

  function lindex() {
    if (indexErr) { return `<p class="api-ko">L’index ne se lit pas : ${esc(indexErr)}.</p>`; }
    if (!index) { lireIndex(); return '<p class="api-mu">Lecture de l’index…</p>'; }
    const q = st.q.trim().toLowerCase();
    const appels = {};
    journal.forEach(e => { const r = routeDe(e.p); if (r) { appels[r.chemin] = (appels[r.chemin] || 0) + 1; } });
    const L = (index.routes || []).filter(r => !q || JSON.stringify(r).toLowerCase().includes(q));
    const groupes = {};
    L.forEach(r => { const g = r.chemin.split('/')[1] || '/'; (groupes[g] = groupes[g] || []).push(r); });
    return `<p class="api-mu">${(index.routes || []).length} chemins appelés par le dashboard · généré le ${esc(String(index.genere || '').slice(0, 16).replace('T', ' '))} · ${esc(index.source || '')}</p>
      ${Object.keys(groupes).sort().map(g => `<h3 class="api-g">/${esc(g)}</h3><table class="api-t"><tbody>${groupes[g].map(r => { const h = (r.routes || [])[0] || {}; return `<tr>
        <td class="api-ms">${(r.methodes || []).map(m => `<span class="api-m ${m}">${m}</span>`).join('')}</td>
        <td class="api-p">${esc(r.chemin)}<small>${esc(h.resume || '')}</small></td>
        <td><code>${esc(h.handler || '')}</code><small>${esc(h.source || '')}</small></td>
        <td>${r.magasin ? '<span class="api-s ok">magasin</span>' : '<span class="api-s ko" title="En mode « console », une session de magasin reçoit 403 sur ce chemin.">siège seulement</span>'}</td>
        <td class="n">${appels[r.chemin] ? appels[r.chemin] + ' ici' : ''}</td></tr>`; }).join('')}</tbody></table>`).join('') || '<p class="api-mu">Aucun chemin ne répond à la recherche.</p>'}`;
  }

  function rendre() {
    if (!st.el) { return; }
    const corps = st.el.querySelector('.api-corps'), top = corps ? corps.scrollTop : 0;
    const a = document.activeElement, focus = a && a.id === 'api-q', pos = focus ? a.selectionStart : null;
    st.el.innerHTML = `<div class="api-fond" data-apifermer="1"></div><div class="api-boite" role="dialog" aria-modal="true" aria-label="Les API du cockpit franchisé">
      <div class="api-h"><div><span class="api-k">Cockpit franchisé</span><h2>Les appels à l’API</h2></div><button type="button" class="api-x" data-apifermer="1" aria-label="Fermer">×</button></div>
      <div class="api-o"><div class="page-tabs">${[['session', 'Cette session', journal.length], ['index', 'Index', index ? (index.routes || []).length : null]].map(([k, l, n]) => `<button type="button" class="page-tabs__tab${st.onglet === k ? ' is-on' : ''}" data-apiong="${k}">${l}${n != null ? ` <span class="page-tabs__count">${n}</span>` : ''}</button>`).join('')}</div>
        <input id="api-q" type="search" placeholder="Chercher un chemin, une fonction…" value="${esc(st.q)}"></div>
      <div class="api-corps">${st.onglet === 'session' ? session() : lindex()}</div></div>`;
    st.el.querySelectorAll('[data-apifermer]').forEach(b => b.addEventListener('click', fermer));
    st.el.querySelectorAll('[data-apiong]').forEach(b => b.addEventListener('click', () => { st.onglet = b.dataset.apiong; rendre(); }));
    const q = st.el.querySelector('#api-q');
    q.addEventListener('input', () => { st.q = q.value; rendre(); });
    if (focus) { q.focus(); q.setSelectionRange(pos, pos); }
    const c2 = st.el.querySelector('.api-corps'); if (c2) { c2.scrollTop = top; }
  }
  function cle(e) { if (e.key === 'Escape') { fermer(); } }
  function ouvrir() {
    if (!st.el) { st.el = document.createElement('div'); st.el.className = 'api-modale'; document.body.appendChild(st.el); document.addEventListener('keydown', cle); }
    lireIndex(); rendre();
    const q = st.el.querySelector('#api-q'); if (q) { q.focus(); }
  }
  function fermer() { if (st.el) { st.el.remove(); st.el = null; document.removeEventListener('keydown', cle); } }

  window.DashApi = { ouvrir, fermer, n: () => journal.length, journal: () => journal.slice() };
})();
