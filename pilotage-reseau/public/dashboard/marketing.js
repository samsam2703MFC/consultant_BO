/* Dashboard magasin › Marketing › Mon marketing (15/11/2026, « faire le total du marketing »).
 *
 * Tout le marketing du magasin sur une page, tel que le cockpit le connaît :
 *   ce qu'il y a à faire, les campagnes (et leurs objectifs produits), les posts du magasin et leur publication vers
 *   Facebook et Instagram, les avis Google, l'e-mailing.
 * Deux lectures, l'une à côté de l'autre :
 *   GET …/dashboard/marketing?shop=                       le hub du marketing, pour ce magasin (src/dashboard_marketing.php)
 *   GET …/exploitation/objectifs-produits?shop=&date=     les objectifs produits des campagnes en cours
 * Rien n'est inventé : un bloc sans donnée le dit, et dit pourquoi (par exemple, la page Facebook pas encore reliée).
 *
 * `window.DashMarketing.racine(shop, {apiBase, postsUrl})` rend l'élément du module, gardé d'un rendu à l'autre.
 */
(function () {
  'use strict';

  const nf0 = new Intl.NumberFormat('fr-BE', { maximumFractionDigits: 0 });
  const nf1 = new Intl.NumberFormat('fr-BE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  const n0 = v => (v == null || isNaN(v)) ? '—' : nf0.format(Math.round(v)).replace(/[  ]/g, ' ');
  const eur = v => (v == null || isNaN(v)) ? '—' : n0(v) + ' €';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const dFr = s => /^\d{4}-\d{2}-\d{2}/.test(s || '') ? s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4) : (s || '—');
  const dCourt = s => /^\d{4}-\d{2}-\d{2}/.test(s || '') ? s.slice(8, 10) + '/' + s.slice(5, 7) : (s || '—');
  const auj = () => { const t = new Date(); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0'); };
  const ico = d => `<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"></path></svg>`;
  const I = { etoile: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z', ext: 'M14 3h7v7M10 14L21 3M21 14v7H3V3h7' };

  const STATUTS_POST = { brouillon: 'brouillon', a_controler: 'à contrôler', a_valider: 'à valider', valide: 'validé', refuse: 'refusé', publie: 'publié' };
  const PHASES = [['en_cours', 'En cours'], ['a_venir', 'À venir'], ['terminee', 'Terminées']];
  const ETATS_OBJ = { atteint: ['ok', 'atteint'], avance: ['ok', 'en avance'], clous: ['att', 'dans les clous'], retard: ['ko', 'en retard'], sans: ['mu', 'sans vente'] };

  const st = { shop: null, api: '../api/cockpit', postsUrl: '', el: null, d: null, err: null, obj: null, objErr: null, phase: 'en_cours', charge: false };

  function lire(p) {
    return fetch(st.api + p, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.json().catch(() => ({})).then(j => r.ok ? j : Promise.reject(new Error((j && (j.error || j.message)) || ('HTTP ' + r.status)))));
  }
  function charger(frais) {
    st.charge = true; st.err = null; rendre();
    const q = 'shop=' + encodeURIComponent(st.shop) + (frais ? '&rafraichir=1' : '&_cache=300');
    lire('/dashboard/marketing?' + q).then(d => {
      st.d = d;
      const c = ((d.campagnes || {}).liste || []);
      if (!c.some(x => x.phase === st.phase)) { const p = PHASES.find(([k]) => c.some(x => x.phase === k)); if (p) { st.phase = p[0]; } }
    }).catch(e => { st.err = e.message; }).finally(() => { st.charge = false; rendre(); });
    lire('/exploitation/objectifs-produits?shop=' + encodeURIComponent(st.shop) + '&date=' + auj()).then(d => { st.obj = d.campagnes || []; st.objErr = null; })
      .catch(e => { st.objErr = e.message; }).finally(rendre);
  }

  /* --- les blocs ------------------------------------------------------------------------------------------------ */
  function kpis(d) {
    const c = (d.campagnes || {}).chiffres || {}, s = (d.social || {}).chiffres || {}, e = (d.emailing || {}).chiffres || {};
    const a = ((d.avis || {}).magasins || [])[0] || null, cible = (d.avis || {}).cible;
    return `<div class="mm-kpis">
      <div><span class="k">Campagnes en cours</span><span class="v">${n0(c.enCours || 0)}</span><span class="s">${n0(c.aVenir || 0)} à venir · ${n0(c.terminees || 0)} terminée${(c.terminees || 0) > 1 ? 's' : ''}</span></div>
      <div><span class="k">Posts publiés ce mois</span><span class="v">${n0(s.publiesMois || 0)}</span><span class="s">${n0(s.total || 0)} post${(s.total || 0) > 1 ? 's' : ''} du magasin · ${n0(s.aTraiter || 0)} à traiter</span></div>
      <div><span class="k">Note Google</span><span class="v">${a && a.note != null ? nf1.format(a.note) : '—'}</span><span class="s">${a ? n0(a.avis) + ' avis' : 'fiche non reliée'}${cible != null ? ' · cible ' + nf1.format(cible) : ''}</span></div>
      <div><span class="k">E-mails envoyés</span><span class="v">${n0(e.envoyes || 0)}</span><span class="s">${e.tauxOuverture != null ? 'ouverture ' + nf1.format(e.tauxOuverture) + ' %' : 'pas encore d’envoi'}</span></div></div>`;
  }

  function aFaire(d) {
    const L = d.aFaire || [];
    if (!L.length) { return ''; }
    return `<section class="mm-carte"><h2>À faire</h2><ul class="mm-afaire">${L.slice(0, 8).map(x => `<li class="${x.niveau === 'urgent' ? 'urg' : ''}"><span class="t">${esc(x.titre)}</span>${x.detail ? `<span class="s">${esc(x.detail)}</span>` : ''}</li>`).join('')}</ul></section>`;
  }

  function campagnes(d) {
    const C = d.campagnes || {};
    if (C.dispo === false) { return `<section class="mm-carte"><h2>Campagnes</h2><p class="mm-mu">Les campagnes ne se lisent pas : ${esc(C.raison || C.erreur || 'indisponibles')}.</p></section>`; }
    const L = C.liste || [];
    const objDe = id => (st.obj || []).find(o => String(o.id) === String(id));
    const vis = L.filter(x => x.phase === st.phase).sort((a, b) => String(a.debut || '').localeCompare(String(b.debut || '')) * (st.phase === 'terminee' ? -1 : 1));
    const carte = x => {
      const o = objDe(x.id), e = o ? (ETATS_OBJ[o.etat] || ['mu', o.etat || '']) : null;
      return `<article class="mm-camp"><div class="mm-camp-h"><span class="mm-pt" style="background:${esc(x.typeCouleur || 'var(--muted)')}"></span><div><h3>${esc(x.nom)}</h3>
          <p>${esc(x.type || 'Campagne')} · ${x.debut ? 'du ' + dCourt(x.debut) + ' au ' + dFr(x.fin) : 'sans dates'}${x.reseau ? ' · tout le réseau' : ''}</p></div>
          <span class="mm-statut">${esc(x.statutNom || '')}</span></div>
        ${o ? `<div class="mm-obj"><div class="mm-obj-h"><span>Objectif : ${n0(o.objectif)} pièces</span><span class="mm-etat ${e[0]}">${esc(e[1])}</span></div>
          <div class="mm-jauge"><i class="${e[0]}" style="width:${Math.min(100, o.pct || 0).toFixed(1)}%"></i>${o.attendu != null ? `<b style="left:${Math.min(100, o.attendu).toFixed(1)}%"></b>` : ''}</div>
          <div class="mm-obj-s">${n0(o.vendu)} vendues (${n0(o.pct)} %)${o.reste > 0 ? ' · reste ' + n0(o.reste) + (o.ilFaut ? ', ' + n0(o.ilFaut) + ' par jour' : '') : ''}${(o.produits || []).length ? ' · ' + esc(o.produits.slice(0, 3).map(p => p.nom).join(', ')) + (o.produits.length > 3 ? '…' : '') : ''}</div></div>`
          : (x.phase === 'en_cours' ? '<p class="mm-mu">Pas d’objectif produit fixé pour le magasin.</p>' : '')}
        ${x.budget ? `<p class="mm-mu">Budget ${eur(x.budget)}${x.depense ? ' · dépensé ' + eur(x.depense) : ''}</p>` : ''}</article>`;
    };
    const n = k => L.filter(x => x.phase === k).length;
    return `<section class="mm-carte"><div class="mm-carte-h"><h2>Campagnes</h2>
        <div class="page-tabs mm-tabs">${PHASES.map(([k, l]) => `<button type="button" class="page-tabs__tab${st.phase === k ? ' is-on' : ''}" data-mmphase="${k}">${l} <span class="page-tabs__count">${n(k)}</span></button>`).join('')}</div></div>
      ${vis.length ? vis.map(carte).join('') : `<p class="mm-mu">Aucune campagne ${({ en_cours: 'en cours', a_venir: 'à venir', terminee: 'terminée' })[st.phase]} pour le magasin.</p>`}
      ${st.objErr ? `<p class="mm-mu">Les objectifs produits ne se lisent pas : ${esc(st.objErr)}.</p>` : ''}</section>`;
  }

  function social(d) {
    const S = d.social || {}, P = S.publication || {}, M = d.meta || {};
    const L = (S.liste || []).slice().sort((a, b) => String(b.publierLe || b.soumisLe || '').localeCompare(String(a.publierLe || a.soumisLe || '')));
    const res = P.reseaux || {};
    const page = M.page;
    const etat = !M.pret ? 'La connexion à Meta n’est pas encore configurée par le bureau : ni publication automatique, ni statistiques Facebook et Instagram.'
      : (!page || !page.pageConnue ? 'La page Facebook du magasin n’est pas encore reliée par le bureau.'
        : (page.statut !== 'connectee' ? 'La page Facebook du magasin est connue mais pas encore connectée.' : ''));
    const lien = st.postsUrl ? `<a class="mm-lien" href="${esc(st.postsUrl)}" target="_blank" rel="noopener">Calendrier des posts ${ico(I.ext)}</a>` : '';
    return `<section class="mm-carte"><div class="mm-carte-h"><h2>Réseaux sociaux</h2>${lien}</div>
      <div class="mm-pub"><span>Publication ${P.reelle ? (P.auto ? 'automatique' : 'à la main') : 'pas encore active'}</span>
        ${['facebook', 'instagram'].map(r => `<span class="mm-res${res[r] ? ' on' : ''}">${r === 'facebook' ? 'Facebook' : 'Instagram'} ${res[r] ? 'actif' : 'inactif'}</span>`).join('')}</div>
      ${etat ? `<p class="mm-note">${esc(etat)}</p>` : ''}
      ${S.dispo === false ? `<p class="mm-mu">Les posts ne se lisent pas : ${esc(S.raison || 'indisponibles')}.</p>`
        : (L.length ? `<table class="mm-t"><thead><tr><th>Date</th><th>Post</th><th>Réseaux</th><th>État</th></tr></thead><tbody>${L.slice(0, 12).map(p => `<tr><td>${esc(dCourt(p.publierLe || p.soumisLe || ''))}</td><td>${esc(String(p.message || '').slice(0, 90))}${String(p.message || '').length > 90 ? '…' : ''}</td><td>${esc((p.reseaux || []).map(r => r === 'facebook' ? 'Facebook' : r === 'instagram' ? 'Instagram' : r).join(', ') || '—')}</td><td><span class="mm-st ${esc(p.statut)}">${esc(STATUTS_POST[p.statut] || p.statut)}</span></td></tr>`).join('')}</tbody></table>`
          : '<p class="mm-mu">Aucun post du magasin dans le cockpit. Les posts se préparent dans l’espace boutique du Marketing (calendrier des posts).</p>')}
    </section>`;
  }

  function avis(d) {
    const A = d.avis || {};
    if (A.dispo === false) { return `<section class="mm-carte"><h2>Avis Google</h2><p class="mm-mu">${esc(A.raison || 'La réputation ne se lit pas.')}</p></section>`; }
    const m = (A.magasins || [])[0];
    if (!m) { return '<section class="mm-carte"><h2>Avis Google</h2><p class="mm-mu">La fiche Google du magasin n’est pas encore reliée.</p></section>'; }
    const niv = ((m.repartition || {}).niveaux || []), tot = niv.reduce((t, x) => t + (x.n || 0), 0);
    return `<section class="mm-carte"><div class="mm-carte-h"><h2>Avis Google</h2>${m.url ? `<a class="mm-lien" href="${esc(m.url)}" target="_blank" rel="noopener">La fiche ${ico(I.ext)}</a>` : ''}</div>
      <div class="mm-avis"><div class="mm-note-g"><span class="v">${m.note != null ? nf1.format(m.note) : '—'}</span><span class="s">${n0(m.avis)} avis${A.cible != null ? ' · cible ' + nf1.format(A.cible) : ''}${m.avis5Requis ? ' · ' + n0(m.avis5Requis) + ' avis 5★ pour l’atteindre' : ''}</span>
        ${A.reseau && A.reseau.moyenne != null ? `<span class="s">moyenne du réseau ${nf1.format(A.reseau.moyenne)}</span>` : ''}</div>
        ${tot ? `<div class="mm-rep">${niv.map(x => `<div><span>${x.note} ★</span><i><b style="width:${(100 * (x.n || 0) / tot).toFixed(1)}%"></b></i><span>${n0(x.n)}</span></div>`).join('')}<small>sur les ${n0(tot)} derniers avis lus</small></div>` : ''}</div>
      ${(m.derniers || []).length ? `<ul class="mm-derniers">${m.derniers.slice(0, 4).map(x => `<li><span class="n">${'★'.repeat(Math.max(0, Math.min(5, x.note || 0)))}<span class="vide">${'★'.repeat(5 - Math.max(0, Math.min(5, x.note || 0)))}</span></span><span class="t">${esc(String(x.texte || '').slice(0, 160))}${String(x.texte || '').length > 160 ? '…' : ''}</span></li>`).join('')}</ul>` : ''}
    </section>`;
  }

  function emailing(d) {
    const E = d.emailing || {}, c = E.chiffres || {}, L = E.liste || [];
    if (E.dispo === false) { return `<section class="mm-carte"><h2>E-mailing</h2><p class="mm-mu">${esc(E.raison || 'L’e-mailing ne se lit pas.')}</p></section>`; }
    return `<section class="mm-carte"><h2>E-mailing</h2>
      <div class="mm-mini"><div><span class="v">${n0(c.envoyes || 0)}</span><span class="k">envoyés</span></div><div><span class="v">${c.tauxOuverture != null ? nf1.format(c.tauxOuverture) + ' %' : '—'}</span><span class="k">ouverture</span></div><div><span class="v">${c.tauxClic != null ? nf1.format(c.tauxClic) + ' %' : '—'}</span><span class="k">clics</span></div><div><span class="v">${n0(c.optin || 0)}</span><span class="k">contacts inscrits</span></div></div>
      ${L.length ? `<ul class="mm-mails">${L.slice(0, 5).map(x => `<li><span>${esc(x.nom)}${x.objet ? ' · ' + esc(x.objet) : ''}</span><span class="mm-mu">${esc(x.statut)}${x.envoiLe ? ' · ' + esc(dCourt(x.envoiLe)) : ''}</span></li>`).join('')}</ul>` : '<p class="mm-mu">Pas encore de campagne e-mail pour le magasin.</p>'}</section>`;
  }

  function html() {
    if (st.err && !st.d) { return `<div class="db-err">Le marketing du magasin ne se lit pas : ${esc(st.err)} <button type="button" class="db-btn" data-mmrelire="1">Relire</button></div>`; }
    if (!st.d) { return '<div class="db-sk" style="height:70px;margin-bottom:12px"></div><div class="db-sk" style="height:320px"></div>'; }
    const d = st.d;
    return kpis(d) + aFaire(d) + `<div class="mm-grille"><div class="mm-col">${campagnes(d)}${social(d)}</div><div class="mm-col">${avis(d)}${emailing(d)}</div></div>`
      + `<p class="mm-pied">Le marketing du magasin, lu à ${esc(String(d.genere || '').slice(11, 16) || '—')} · <button type="button" class="mm-lienb" data-mmrelire="1">relire</button></p>`;
  }

  function rendre() {
    if (!st.el) { return; }
    st.el.innerHTML = html();
    st.el.querySelectorAll('[data-mmphase]').forEach(b => b.addEventListener('click', () => { st.phase = b.dataset.mmphase; rendre(); }));
    st.el.querySelectorAll('[data-mmrelire]').forEach(b => b.addEventListener('click', () => charger(true)));
  }

  window.DashMarketing = {
    racine(shop, o) {
      o = o || {};
      if (o.apiBase) { st.api = o.apiBase; }
      if (o.postsUrl !== undefined && o.postsUrl !== st.postsUrl) { st.postsUrl = o.postsUrl || ''; if (st.el && st.d) { setTimeout(rendre, 0); } }
      if (!st.el || String(st.shop) !== String(shop)) {
        st.shop = String(shop); st.el = document.createElement('div'); st.el.className = 'mm-racine';
        Object.assign(st, { d: null, err: null, obj: null, objErr: null, phase: 'en_cours' });
        charger(false);
      }
      return st.el;
    },
    relire() { if (st.el) { charger(true); } },
  };
})();
