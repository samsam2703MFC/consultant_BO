/* Dashboard magasin › Marketing › Mon marketing (15/11/2026, « faire le total du marketing »).
 *
 * Tout le marketing du magasin sur une page, tel que le cockpit le connaît :
 *   ce qu'il y a à faire, les campagnes (et leurs objectifs produits), les posts du magasin et leur publication vers
 *   Facebook et Instagram, les avis Google, l'e-mailing.
 * Deux lectures, l'une à côté de l'autre :
 *   GET …/dashboard/marketing?shop=                       le hub du marketing, pour ce magasin (src/dashboard_marketing.php)
 *   GET …/exploitation/objectifs-produits?shop=&date=     les objectifs produits des campagnes en cours
 * Rien n'est inventé : un bloc sans donnée le dit, et dit pourquoi (par exemple, la page Facebook pas encore reliée).
 * Trois onglets (15/11/2026, « tout doit se faire dans le cockpit franchisé », plus de lien vers l'espace boutique) :
 *   Vue d'ensemble · Calendrier des posts (le mois, les posts du magasin, les campagnes) · Nouveau post (le texte, les
 *   photos, les réseaux, la date ; brouillon ou envoi au contrôle — règles, Brand Guard, puis la validation du bureau) :
 *   POST …/dashboard/marketing/media, …/posts, …/posts/{id}/soumettre (src/dashboard_marketing.php).
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

  const st = { shop: null, api: '../api/cockpit', el: null, d: null, err: null, obj: null, objErr: null, phase: 'en_cours', charge: false,
    onglet: 'apercu', mois: null, post: null, msg: null, occupe: false, f: null, envoi: null };
  const MOIS_L = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const JOURS_C = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
  const RES_L = { facebook: 'Facebook', instagram: 'Instagram', linkedin: 'LinkedIn' };
  function ecrire(p, corps) {
    return fetch(st.api + p, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ shop: st.shop }, corps)) })
      .then(r => r.json().catch(() => ({})).then(j => r.ok ? j : Promise.reject(new Error((j && (j.error || j.message)) || ('HTTP ' + r.status)))));
  }
  const formVide = () => ({ message: '', reseaux: { facebook: true, instagram: true }, format: 'Photo', medias: [], quand: '', campagneId: '', lien: '' });

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
    const lien = `<span class="mm-liens"><button type="button" class="mm-lienb" data-mmong="calendrier">Calendrier des posts</button><button type="button" class="mm-lienb" data-mmong="nouveau">Nouveau post</button></span>`;
    return `<section class="mm-carte"><div class="mm-carte-h"><h2>Réseaux sociaux</h2>${lien}</div>
      <div class="mm-pub"><span>Publication ${P.reelle ? (P.auto ? 'automatique' : 'à la main') : 'pas encore active'}</span>
        ${['facebook', 'instagram'].map(r => `<span class="mm-res${res[r] ? ' on' : ''}">${r === 'facebook' ? 'Facebook' : 'Instagram'} ${res[r] ? 'actif' : 'inactif'}</span>`).join('')}</div>
      ${etat ? `<p class="mm-note">${esc(etat)}</p>` : ''}
      ${S.dispo === false ? `<p class="mm-mu">Les posts ne se lisent pas : ${esc(S.raison || 'indisponibles')}.</p>`
        : (L.length ? `<table class="mm-t"><thead><tr><th>Date</th><th>Post</th><th>Réseaux</th><th>État</th></tr></thead><tbody>${L.slice(0, 12).map(p => `<tr><td>${esc(dCourt(p.publierLe || p.soumisLe || ''))}</td><td>${esc(String(p.message || '').slice(0, 90))}${String(p.message || '').length > 90 ? '…' : ''}</td><td>${esc((p.reseaux || []).map(r => r === 'facebook' ? 'Facebook' : r === 'instagram' ? 'Instagram' : r).join(', ') || '—')}</td><td><span class="mm-st ${esc(p.statut)}">${esc(STATUTS_POST[p.statut] || p.statut)}</span></td></tr>`).join('')}</tbody></table>`
          : '<p class="mm-mu">Pas encore de post du magasin. « Nouveau post » en prépare un : il est relu, validé par le bureau, puis publié.</p>')}
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

  /* --- le calendrier des posts --------------------------------------------------------------------------------- */
  const datePost = p => String(p.publierLe || ((p.publications || [])[0] || {}).prevuLe || (p.publie || {}).le || p.soumisLe || '');
  function calendrier(d) {
    const S = d.social || {}, L = S.liste || [], C = (d.campagnes || {}).liste || [];
    const m = st.mois || auj().slice(0, 7), an = +m.slice(0, 4), mo = +m.slice(5, 7) - 1;
    const premier = new Date(an, mo, 1), n = new Date(an, mo + 1, 0).getDate(), dec = (premier.getDay() + 6) % 7;
    const iso = j => m + '-' + String(j).padStart(2, '0');
    const parJour = {};
    L.forEach(p => { const k = datePost(p).slice(0, 10); if (k.slice(0, 7) === m) { (parJour[k] = parJour[k] || []).push(p); } });
    const camps = C.filter(c => c.debut && c.fin && c.debut.slice(0, 7) <= m && c.fin.slice(0, 7) >= m);
    let cases = '';
    for (let i = 0; i < dec; i++) { cases += '<div class="mm-cj vide"></div>'; }
    for (let j = 1; j <= n; j++) {
      const k = iso(j), P = (parJour[k] || []).sort((a, b) => datePost(a).localeCompare(datePost(b)));
      const enCamp = camps.filter(c => c.debut <= k && c.fin >= k);
      cases += `<div class="mm-cj${k === auj() ? ' auj' : ''}">
        <div class="mm-cj-h"><span>${j}</span>${enCamp.map(c => `<i class="mm-cbar" style="background:${esc(c.typeCouleur || 'var(--prize)')}" title="${esc(c.nom)}"></i>`).join('')}</div>
        ${P.map(p => `<button type="button" class="mm-cp ${esc(p.statut)}${st.post === p.id ? ' on' : ''}" data-mmpost="${esc(p.id)}"><span>${esc(datePost(p).slice(11, 16) || '')}</span>${esc(String(p.message || '').slice(0, 28))}</button>`).join('')}
        <button type="button" class="mm-cj-plus" data-mmnouveau="${k}" title="Préparer un post pour le ${dFr(k)}">+</button></div>`;
    }
    const sel = L.find(p => p.id === st.post);
    const nav = d2 => { const t = new Date(an, mo + d2, 1); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0'); };
    return `<section class="mm-carte"><div class="mm-carte-h"><div class="mm-mnav"><button type="button" class="db-btn" data-mmmois="${nav(-1)}" aria-label="Mois précédent">‹</button><h2 class="mm-mtit">${MOIS_L[mo]} ${an}</h2><button type="button" class="db-btn" data-mmmois="${nav(1)}" aria-label="Mois suivant">›</button></div>
        <span class="mm-legende">${['brouillon', 'a_valider', 'valide', 'publie', 'refuse'].map(k => `<span class="mm-cp ${k}">${STATUTS_POST[k]}</span>`).join('')}</span></div>
      ${camps.length ? `<div class="mm-camps">${camps.map(c => `<span><i style="background:${esc(c.typeCouleur || 'var(--prize)')}"></i>${esc(c.nom)} · ${dCourt(c.debut)} → ${dCourt(c.fin)}</span>`).join('')}</div>` : ''}
      <div class="mm-cal">${JOURS_C.map(j => `<div class="mm-cjn">${j}</div>`).join('')}${cases}</div>
      ${L.length ? '' : '<p class="mm-mu">Pas encore de post du magasin : le « + » d’un jour en prépare un pour ce jour-là.</p>'}</section>
      ${sel ? detailPost(sel) : ''}`;
  }
  function detailPost(p) {
    const ecarts = (p.ecarts || []).filter(e => e.statut !== 'ignore');
    const dec = p.decision || {};
    return `<section class="mm-carte mm-post"><div class="mm-carte-h"><h2>Le post du ${esc(dFr(datePost(p).slice(0, 10)))}${datePost(p).slice(11, 16) ? ' à ' + esc(datePost(p).slice(11, 16)) : ''}</h2><span class="mm-st ${esc(p.statut)}">${esc(STATUTS_POST[p.statut] || p.statut)}</span></div>
      <p class="mm-msg">${esc(p.message || '')}</p>
      ${(p.medias || []).length ? `<div class="mm-medias">${p.medias.map(x => `<img src="${esc(x.url)}" alt="${esc(x.alt || '')}">`).join('')}</div>` : ''}
      <p class="mm-mu">${esc((p.reseaux || []).map(r => RES_L[r] || r).join(' · ') || 'Facebook')}${p.format ? ' · ' + esc(p.format) : ''}</p>
      ${p.agent && p.agent.note != null ? `<div class="mm-ctrl"><b>Contrôle : ${esc(p.agent.note)}/5</b>${p.agent.resume ? ' — ' + esc(p.agent.resume) : ''}</div>` : ''}
      ${ecarts.length ? `<ul class="mm-ecarts">${ecarts.map(e => `<li class="g${esc(e.gravite)}"><span>${esc(e.regle || e.type || '')}</span>${esc(e.message || '')}</li>`).join('')}</ul>` : ''}
      ${dec.le ? `<div class="mm-ctrl">Décision du bureau : ${esc(dec.note != null ? dec.note + '/5 · ' : '')}${esc(dec.commentaire || '')}</div>` : ''}
      ${(p.publications || []).length ? `<ul class="mm-pubs">${p.publications.map(x => `<li>${esc(RES_L[x.reseau] || x.reseau)} : ${esc(x.statut)}${x.publieLe ? ' le ' + esc(dFr(x.publieLe.slice(0, 10))) : (x.prevuLe ? ' pour le ' + esc(dFr(x.prevuLe.slice(0, 10))) : '')}${x.erreur ? ' — ' + esc(x.erreur) : ''}</li>`).join('')}</ul>` : ''}
      ${p.statut === 'brouillon' ? `<div class="mm-actions"><button type="button" class="db-btn prim" data-mmsoumettre="${esc(p.id)}"${st.occupe ? ' disabled' : ''}>Envoyer au contrôle</button></div>` : ''}</section>`;
  }

  /* --- nouveau post ---------------------------------------------------------------------------------------------- */
  function verifs(f, d) {
    const t = f.message, ville = (((d.avis || {}).magasins || [])[0] || {}).ville || '';
    const L = [[/l[’']atelier by/i.test(t), 'le nom « L’Atelier by »'], [/#latelierby\b/i.test(t), 'le hashtag #latelierby']];
    if (ville) { L.push([new RegExp(ville.normalize('NFD').replace(/[̀-ͯ]/g, ''), 'i').test(t.normalize('NFD').replace(/[̀-ͯ]/g, '')), 'la ville du magasin (' + ville + ')']); }
    L.push([t.length >= 80 && t.length <= 1200, 'entre 80 et 1 200 caractères (' + t.length + ')']);
    if (f.reseaux.instagram || f.format !== 'Texte') { L.push([f.medias.length > 0, 'au moins une photo' + (f.reseaux.instagram ? ' (Instagram l’exige)' : '')]); }
    return L;
  }
  function nouveau(d) {
    const f = st.f || (st.f = formVide());
    const C = ((d.campagnes || {}).liste || []).filter(c => c.phase === 'en_cours' || c.phase === 'a_venir');
    const V = verifs(f, d);
    const pret = f.message.trim() && (f.reseaux.facebook || f.reseaux.instagram);
    return `<section class="mm-carte mm-form"><h2>Nouveau post</h2>
      ${st.envoi ? `<div class="${st.envoi.ok ? 'mm-ok' : 'db-err'}">${esc(st.envoi.texte)}</div>` : ''}
      <label class="mm-ch">Le texte<textarea id="mm-msg" rows="6" placeholder="Ce que vous voulez dire, avec « L’Atelier by », #latelierby et votre ville">${esc(f.message)}</textarea></label>
      <div class="mm-ch2">
        <div class="mm-ch"><span>Les réseaux</span><span class="mm-choix">${['facebook', 'instagram'].map(r => `<button type="button" class="mm-c${f.reseaux[r] ? ' on' : ''}" data-mmres="${r}" aria-pressed="${f.reseaux[r] ? 'true' : 'false'}">${RES_L[r]}</button>`).join('')}</span></div>
        <div class="mm-ch"><span>Le format</span><span class="mm-choix">${['Photo', 'Carrousel', 'Texte'].map(x => `<button type="button" class="mm-c${f.format === x ? ' on' : ''}" data-mmformat="${x}">${x}</button>`).join('')}</span></div>
        <label class="mm-ch">Publier le<input type="datetime-local" id="mm-quand" value="${esc(f.quand)}"></label>
        <label class="mm-ch">La campagne<select id="mm-camp"><option value="">aucune</option>${C.map(c => `<option value="${esc(c.id)}"${String(c.id) === String(f.campagneId) ? ' selected' : ''}>${esc(c.nom)}</option>`).join('')}</select></label>
      </div>
      ${f.format !== 'Texte' ? `<div class="mm-ch"><span>Les photos <small>(JPEG, PNG ou WebP, 8 Mo au plus ; 1 080 px de large au moins)</small></span>
        <div class="mm-photos">${f.medias.map((x, i) => `<figure><img src="${esc(x.url)}" alt=""><input data-mmalt="${i}" value="${esc(x.alt || '')}" placeholder="ce que montre la photo"><button type="button" class="mm-x" data-mmretire="${i}" aria-label="Retirer la photo">×</button>${(x.avertissements || []).length ? `<small class="mm-ko">${esc(x.avertissements.join(' · '))}</small>` : ''}</figure>`).join('')}
          ${f.format === 'Carrousel' || !f.medias.length ? `<label class="mm-ajout">+ Ajouter une photo<input type="file" id="mm-fichier" accept="image/jpeg,image/png,image/webp"${f.format === 'Carrousel' ? ' multiple' : ''} hidden></label>` : ''}</div></div>` : ''}
      <div class="mm-verifs"><span class="mm-ch-l">Avant l’envoi</span>${V.map(([ok, l]) => `<span class="${ok ? 'ok' : 'ko'}">${ok ? '✓' : '·'} ${esc(l)}</span>`).join('')}
        <small>Le post est ensuite relu (règles de la marque, Brand Guard), validé par le bureau, puis publié.</small></div>
      <div class="mm-actions"><button type="button" class="db-btn" data-mmenvoyer="brouillon"${st.occupe || !pret ? ' disabled' : ''}>Enregistrer en brouillon</button><button type="button" class="db-btn prim" data-mmenvoyer="a_controler"${st.occupe || !pret ? ' disabled' : ''}>Envoyer au contrôle</button></div>
    </section>`;
  }
  function envoyer(statut) {
    const f = st.f, quand = f.quand ? f.quand.replace('T', ' ') + ':00' : null;
    const reseaux = ['facebook', 'instagram'].filter(r => f.reseaux[r]);
    st.occupe = true; st.envoi = null; rendre();
    ecrire('/dashboard/marketing/posts', { magasinId: st.shop, message: f.message.trim(), reseaux, format: f.format, medias: f.medias.map(x => ({ url: x.url, alt: x.alt || '', largeur: x.largeur, hauteur: x.hauteur, nom: x.nom })),
      publierLe: quand, campagneId: f.campagneId ? +f.campagneId : null, statut, auteur: 'Magasin' })
      .then(r => {
        const c = r.controle;
        st.envoi = { ok: true, texte: statut === 'brouillon' ? 'Brouillon enregistré.' : 'Post envoyé au contrôle' + (c && c.note != null ? ' : ' + c.note + '/5' + (c.resume ? ' — ' + c.resume : '') : '') + '. Le bureau le valide avant publication.' };
        st.f = formVide(); st.post = r.id || null; st.onglet = 'calendrier'; if (quand) { st.mois = quand.slice(0, 7); }
        charger(true);
      })
      .catch(e => { st.envoi = { ok: false, texte: e.message }; })
      .finally(() => { st.occupe = false; rendre(); });
  }
  function televerser(fichiers) {
    const f = st.f;
    [...fichiers].slice(0, 10).forEach(fi => {
      const lec = new FileReader();
      lec.onload = () => {
        st.occupe = true; rendre();
        ecrire('/dashboard/marketing/media', { data: lec.result, nom: fi.name })
          .then(r => { f.medias.push({ url: r.url, alt: '', largeur: r.largeur, hauteur: r.hauteur, nom: fi.name, avertissements: r.avertissements || [] }); st.envoi = null; })
          .catch(e => { st.envoi = { ok: false, texte: 'La photo n’est pas passée : ' + e.message }; })
          .finally(() => { st.occupe = false; rendre(); });
      };
      lec.readAsDataURL(fi);
    });
  }

  function html() {
    if (st.err && !st.d) { return `<div class="db-err">Le marketing du magasin ne se lit pas : ${esc(st.err)} <button type="button" class="db-btn" data-mmrelire="1">Relire</button></div>`; }
    if (!st.d) { return '<div class="db-sk" style="height:70px;margin-bottom:12px"></div><div class="db-sk" style="height:320px"></div>'; }
    const d = st.d;
    const tabs = `<div class="mm-onglets"><div class="page-tabs">${[['apercu', 'Vue d’ensemble'], ['calendrier', 'Calendrier des posts'], ['nouveau', 'Nouveau post']].map(([k, l]) => `<button type="button" class="page-tabs__tab${st.onglet === k ? ' is-on' : ''}" data-mmong="${k}">${l}</button>`).join('')}</div></div>`;
    if (st.onglet === 'calendrier') { return tabs + (st.envoi && st.envoi.ok ? `<div class="mm-ok">${esc(st.envoi.texte)}</div>` : '') + calendrier(d); }
    if (st.onglet === 'nouveau') { return tabs + nouveau(d); }
    return tabs + kpis(d) + aFaire(d) + `<div class="mm-grille"><div class="mm-col">${campagnes(d)}${social(d)}</div><div class="mm-col">${avis(d)}${emailing(d)}</div></div>`
      + `<p class="mm-pied">Le marketing du magasin, lu à ${esc(String(d.genere || '').slice(11, 16) || '—')} · <button type="button" class="mm-lienb" data-mmrelire="1">relire</button></p>`;
  }

  function rendre() {
    if (!st.el) { return; }
    const a = document.activeElement, dans = a && st.el.contains(a) && a.id ? a.id : null, pos = dans && a.selectionStart != null ? a.selectionStart : null;
    st.el.innerHTML = html();
    const on = (sel, ev, fn) => st.el.querySelectorAll(sel).forEach(b => b.addEventListener(ev, fn));
    on('[data-mmphase]', 'click', e => { st.phase = e.currentTarget.dataset.mmphase; rendre(); });
    on('[data-mmrelire]', 'click', () => charger(true));
    on('[data-mmong]', 'click', e => { st.onglet = e.currentTarget.dataset.mmong; if (st.onglet !== 'nouveau') { st.envoi = st.envoi && st.envoi.ok ? st.envoi : null; } rendre(); });
    on('[data-mmmois]', 'click', e => { st.mois = e.currentTarget.dataset.mmmois; st.post = null; rendre(); });
    on('[data-mmpost]', 'click', e => { st.post = st.post === e.currentTarget.dataset.mmpost ? null : e.currentTarget.dataset.mmpost; rendre(); });
    on('[data-mmnouveau]', 'click', e => { st.f = st.f || formVide(); st.f.quand = e.currentTarget.dataset.mmnouveau + 'T10:00'; st.onglet = 'nouveau'; st.envoi = null; rendre(); });
    on('[data-mmsoumettre]', 'click', e => { const id = e.currentTarget.dataset.mmsoumettre; st.occupe = true; rendre();
      ecrire('/dashboard/marketing/posts/' + encodeURIComponent(id) + '/soumettre', { soumettre: true })
        .then(r => { const c = r.controle; st.envoi = { ok: true, texte: 'Post envoyé au contrôle' + (c && c.note != null ? ' : ' + c.note + '/5' : '') + '. Le bureau le valide avant publication.' }; charger(true); })
        .catch(x => { st.envoi = { ok: false, texte: x.message }; }).finally(() => { st.occupe = false; rendre(); }); });
    if (st.onglet === 'nouveau' && st.f) {
      const f = st.f, msg = st.el.querySelector('#mm-msg');
      if (msg) { msg.addEventListener('input', () => { f.message = msg.value; const p = msg.selectionStart; rendre(); const m2 = st.el.querySelector('#mm-msg'); if (m2) { m2.focus(); m2.setSelectionRange(p, p); } }); }
      on('[data-mmres]', 'click', e => { const r = e.currentTarget.dataset.mmres; f.reseaux[r] = !f.reseaux[r]; rendre(); });
      on('[data-mmformat]', 'click', e => { f.format = e.currentTarget.dataset.mmformat; if (f.format === 'Photo' && f.medias.length > 1) { f.medias = f.medias.slice(0, 1); } rendre(); });
      const q = st.el.querySelector('#mm-quand'); if (q) { q.addEventListener('change', () => { f.quand = q.value; }); }
      const c = st.el.querySelector('#mm-camp'); if (c) { c.addEventListener('change', () => { f.campagneId = c.value; }); }
      on('[data-mmalt]', 'input', e => { f.medias[+e.currentTarget.dataset.mmalt].alt = e.currentTarget.value; });
      on('[data-mmretire]', 'click', e => { f.medias.splice(+e.currentTarget.dataset.mmretire, 1); rendre(); });
      const fi = st.el.querySelector('#mm-fichier'); if (fi) { fi.addEventListener('change', () => { if (fi.files && fi.files.length) { televerser(fi.files); } }); }
      on('[data-mmenvoyer]', 'click', e => envoyer(e.currentTarget.dataset.mmenvoyer));
    }
    if (dans && dans !== 'mm-msg') { const b = st.el.querySelector('#' + dans); if (b) { b.focus(); if (pos != null && b.setSelectionRange) { try { b.setSelectionRange(pos, pos); } catch (x) { /* sans curseur */ } } } }
  }

  window.DashMarketing = {
    racine(shop, o) {
      o = o || {};
      if (o.apiBase) { st.api = o.apiBase; }
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
