/* Dashboard magasin › Vendre › Clients (25/11/2026 ; maquettes panel/docs/maquettes/clients-panel/). Le module « Klienci »
 * du panel TF Buddy dans le dashboard franchisé : une entrée de rail, quatre onglets.
 *   - PARTICULIERS : la recherche d'abord (le téléphone en partie dans la liste), la fiche à côté ; ajouter, modifier,
 *     passer en entreprise (la TVA vérifiée par TF Buddy, puis VIES), supprimer (un client né dans ce magasin) ;
 *   - ENTREPRISES B2B : les entreprises à compléter en tête, les filtres (score, CA 12 mois, fréquence), la fiche à côté
 *     (le crédit, ses bureaux, ses factures), le profil commercial (score, type, mode, crédit), ajouter par la TVA ;
 *   - BUREAUX : une ligne par bureau, la fiche en onglets (Livraison, Catalogue, Offres, Personnes, Documents),
 *     « Ajouter un bureau » en sept étapes (l'assistant du panel) ;
 *   - ANALYSE B2B : un mois, six chiffres face au mois d'avant et au même mois l'an dernier, l'année mois par mois.
 * « Préparer une offre » ouvre la page Offres (offres.js) sur ce client. Tout se lit et s'écrit dans TF Buddy par les
 * comptes du cockpit (panel/src/clients_panel.php) ; ce que TF Buddy leur refuse se dit à l'écran.
 *
 * `window.DashClients.racine(shop, {apiBase, nomShop, aller})` rend l'élément du module, gardé d'un rendu à l'autre ;
 * `relire()` relit l'onglet ouvert.
 */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const nf = (n, d) => (Number(n) || 0).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).replace(/\s/g, ' ');
  const fE = n => nf(n, 2) + ' €';
  const fE0 = n => nf(Math.round(Number(n) || 0)) + ' €';
  const fD = s => s ? s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4) : '—';
  const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const MC = ['jan', 'fév', 'mar', 'avr', 'mai', 'juin', 'juil', 'août', 'sep', 'oct', 'nov', 'déc'];
  const SVG = d => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${d}"/></svg>`;
  const ICO = {
    plus: 'M12 5v14M5 12h14', x: 'M18 6L6 18M6 6l12 12', alerte: 'M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
    camion: 'M1 5h13v11H1zM14 9h4l4 4v3h-8M5.5 18.5a1.8 1.8 0 1 0 0-.01M17.5 18.5a1.8 1.8 0 1 0 0-.01', g: 'M15 18l-6-6 6-6', d: 'M9 18l6-6-6-6',
    cadenas: 'M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4', doc: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6',
  };
  const TYPES = [['standard', 'Standard'], ['vip', 'VIP'], ['restaurant', 'Restaurant'], ['hotel', 'Hôtel'], ['wholesale', 'Gros'], ['other', 'Autre']];
  const MODES = [['delivery', 'Livraison'], ['pickup', 'Magasin'], ['both', 'Les deux']];
  const lib = (L, v) => (L.find(x => x[0] === v) || [null, v || '—'])[1];
  const MANQUE = { nom: 'le nom', adresse: 'l’adresse', tva: 'la TVA', contact: 'un e-mail ou un téléphone' };
  const ETAPES = ['Société', 'Contacts', 'Sites', 'Départements', 'Webshop', 'Personnes', 'Récapitulatif'];
  const JOURS = [[1, 'L'], [2, 'M'], [3, 'M'], [4, 'J'], [5, 'V'], [6, 'S'], [7, 'D']];

  const st = { shop: null, api: '../api/cockpit', el: null, aller: null, nomShop: '', onglet: 'b2b', mo: null,
    p: { q: '', filtre: '', d: null, err: null, lecture: false, sel: null, fiche: null, ficheErr: null },
    e: { d: null, err: null, lecture: false, q: '', score: '', ca: '', freq: '', incompl: false, sel: null, fiche: null, ficheErr: null },
    b: { d: null, err: null, lecture: false, q: '', statut: '', sel: null, ong: 'livraison', det: {}, options: null, w: null },
    a: { annee: null, mois: null, d: null, err: null, lecture: false, metrique: 'ventes' } };

  const json = r => r.json().catch(() => ({})).then(j => { if (!r.ok) { const e = new Error(j.error || j.texte || ('erreur ' + r.status)); e.code = r.status; e.corps = j; throw e; } return j; });
  // Le jeton TF Buddy du magasin, s'il est connecté (tfb-magasin.js), part avec chaque appel : les routes du panel n'ont
  // que lui ; sans lui, les comptes consultant et admin du cockpit.
  const tfb = () => window.DashTfb ? window.DashTfb.entetes(st.shop, st.api) : Promise.resolve({});
  const lire = (p, prm) => tfb().then(h => fetch(st.api + p + '?' + new URLSearchParams(Object.assign({ shop: st.shop }, prm || {})).toString(), { headers: Object.assign({ Accept: 'application/json' }, h), credentials: 'same-origin' })).then(json).then(vuExpire);
  const envoyer = (p, corps) => tfb().then(h => fetch(st.api + p, { method: 'POST', credentials: 'same-origin', headers: Object.assign({ Accept: 'application/json', 'Content-Type': 'application/json' }, h),
    body: JSON.stringify(Object.assign({ shop: st.shop }, corps || {})) })).then(json).then(vuExpire);
  /** Le serveur dit la session TF Buddy du magasin périmée : on l'oublie (le bouton « Connecter » revient). */
  function vuExpire(d) { const x = d && (d.panel || d); if (x && x.magasinExpire && window.DashTfb) { window.DashTfb.oublier(st.shop); } return d; }
  const tfbEtat = () => window.DashTfb ? window.DashTfb.etat(st.shop) : { connecte: false };
  const tfbBouton = (lib) => window.DashTfb ? `<button type="button" class="btn btn--sm btn--primary" data-act="tfb-connecter">${lib || 'Connecter le compte TF Buddy du magasin'}</button>` : '';
  /** Après une connexion ou une déconnexion : tout se relit avec les nouveaux droits. */
  function toutRelire() { st.p.d = null; st.e.d = null; st.e.fiche = null; st.b.d = null; st.b.det = {}; st.b.options = null; st.a.d = null; charger(true); }
  const memo = (k, v) => { try { if (v === undefined) { return localStorage.getItem(k); } localStorage.setItem(k, v); } catch (e) { /* sans mémoire */ } return null; };

  /* ── Les lectures ─────────────────────────────────────────────────────────────────────────────────────────── */
  let tRech = null;
  function lireParticuliers(frais) {
    const P = st.p, q = P.q, f = P.filtre; P.lecture = true; rendre();
    return lire('/dashboard/clients/particuliers', Object.assign({ q, filtre: f }, frais ? { frais: 1 } : {}))
      .then(d => { if (P.q === q && P.filtre === f) { P.d = d; P.err = null; } })
      .catch(e => { P.err = e.message; }).finally(() => { P.lecture = false; rendre(); });
  }
  function lireEntreprises(frais) {
    const E = st.e; E.lecture = true; rendre();
    return lire('/dashboard/clients/entreprises', frais ? { frais: 1 } : {}).then(d => { E.d = d; E.err = null; })
      .catch(e => { E.err = e.message; }).finally(() => { E.lecture = false; rendre(); });
  }
  function lireBureaux() {
    const B = st.b; B.lecture = true; rendre();
    return lire('/dashboard/clients/bureaux', { chemin: '' }).then(d => { B.d = d; B.err = null; })
      .catch(e => { B.err = e.message; }).finally(() => { B.lecture = false; rendre(); });
  }
  function lireOptions() {
    if (st.b.options) { return Promise.resolve(st.b.options); }
    return lire('/dashboard/clients/bureaux', { chemin: '/options' }).then(d => { st.b.options = d.ok ? (d.data || {}) : {}; return st.b.options; }).catch(() => ({}));
  }
  function lireAnalyse() {
    const A = st.a, cle = A.annee + '-' + A.mois; A.lecture = true; rendre();
    return lire('/dashboard/clients/analyse', { annee: A.annee, mois: A.mois }).then(d => { if (A.annee + '-' + A.mois === cle) { A.d = d; A.err = null; } })
      .catch(e => { A.err = e.message; }).finally(() => { A.lecture = false; rendre(); });
  }
  function lireFiche(X, id) {
    X.sel = id; X.fiche = null; X.ficheErr = null; rendre();
    // Sur un écran étroit, la fiche est sous la liste : on y descend.
    if (window.innerWidth <= 1100) { const f = st.el.querySelector('.pcl-fiche'); if (f && f.scrollIntoView) { f.scrollIntoView({ behavior: 'smooth', block: 'start' }); } }
    return lire('/dashboard/clients/fiche', { id }).then(d => { if (X.sel === id) { X.fiche = d; } })
      .catch(e => { if (X.sel === id) { X.ficheErr = e.message; } }).finally(rendre);
  }
  /** Le détail d'un bureau, onglet par onglet : chaque chemin lu une fois. */
  function lireDet(chemin) {
    const B = st.b, k = B.sel + chemin;
    if (B.det[k]) { return; }
    B.det[k] = { lecture: true };
    lire('/dashboard/clients/bureaux', { chemin: '/' + B.sel + chemin }).then(d => { B.det[k] = d; }).catch(e => { B.det[k] = { ok: false, motif: e.message }; }).finally(rendre);
  }
  function charger(frais) {
    if (st.onglet === 'b2c') { return lireParticuliers(frais); }
    if (st.onglet === 'b2b') { if (!st.b.d && !st.b.lecture) { lireBureaux(); } return lireEntreprises(frais); }
    if (st.onglet === 'bur') { st.b.det = {}; return lireBureaux(); }
    return lireAnalyse();
  }

  /* ── Les morceaux ─────────────────────────────────────────────────────────────────────────────────────────── */
  const note = (t, cl) => `<div class="pcl-note${cl ? ' ' + cl : ''}">${SVG(ICO.alerte)}<div>${t}</div></div>`;
  const attente = t => `<div class="pcl-attente">${esc(t)}</div>`;
  const ligneF = (k, v) => `<div class="pcl-lf"><span>${esc(k)}</span><span>${v == null || v === '' ? '<span class="muted">—</span>' : v}</span></div>`;
  const seg = (act, on, L) => `<span class="seg">${L.map(([v, l]) => `<button type="button" class="${on === v ? 'is-on' : ''}" data-act="${act}" data-v="${esc(v)}">${l}</button>`).join('')}</span>`;
  const score = s => s ? `<span class="pcl-score pcl-s${esc(s)}">${esc(s)}</span>` : '<span class="muted">—</span>';
  function credit(c) {
    if (!c || c.limite == null) { return '<span class="muted">—</span>'; }
    const p = c.pct == null ? 0 : c.pct, col = { danger: 'var(--danger)', attention: 'var(--prize)', moyen: '#1B6FA8', ok: 'var(--green)' }[c.alerte] || 'var(--green)';
    return `<div class="pcl-credit"><div class="b"><i style="width:${Math.min(100, p)}%;background:${col}"></i></div><div class="t">${c.utilise != null ? fE0(c.utilise) + ' / ' : ''}${fE0(c.limite)}${c.pct != null ? ' · ' + nf(c.pct) + ' %' : ''}${p >= 100 ? ' · dépassé' : ''}</div></div>`;
  }
  function fenetre(titre, corps, pied, large) {
    return `<div class="confirm pcl-fen" data-act="fermer-fond"><div class="confirm__box card${large ? ' pcl-fen-l' : ''}" role="dialog" aria-modal="true" aria-label="${esc(titre)}">
      <div class="pcl-fen-tete"><h3>${esc(titre)}</h3><button type="button" class="btn btn--sm btn--icon" data-act="fermer" aria-label="Fermer">${SVG(ICO.x)}</button></div>${corps}
      ${st.mo && st.mo.err ? `<p class="error">${esc(st.mo.err)}</p>` : ''}${st.mo && st.mo.ok ? `<p class="pcl-ok">${esc(st.mo.ok)}</p>` : ''}
      ${pied ? `<div class="confirm__actions">${pied}</div>` : ''}</div></div>`;
  }
  /** Un champ d'un formulaire : sa valeur vit dans st.mo.f[k] (ou l'objet `o`), écrite à la saisie sans rendre. */
  function champ(k, l, o) {
    o = o || {}; const src = o.src || (st.mo && st.mo.f) || {}, v = src[k] == null ? '' : src[k];
    const attr = `data-f="${esc(k)}"${o.ou ? ` data-ou="${esc(o.ou)}"` : ''}`;
    let w;
    if (o.choix) { w = `<select ${attr}>${o.choix.map(([cv, cl]) => `<option value="${esc(cv)}"${String(v) === String(cv) ? ' selected' : ''}>${esc(cl)}</option>`).join('')}</select>`; }
    else if (o.type === 'case') { return `<label class="pcl-case${o.plein ? ' plein' : ''}"><input type="checkbox" ${attr}${v ? ' checked' : ''}> ${esc(l)}</label>`; }
    else if (o.type === 'zone') { w = `<textarea rows="${o.lignes || 3}" ${attr} placeholder="${esc(o.aide || '')}">${esc(v)}</textarea>`; }
    else { w = `<input type="${o.type || 'text'}" ${attr} value="${esc(v)}"${o.aide ? ` placeholder="${esc(o.aide)}"` : ''}${o.lecture ? ' readonly' : ''}>`; }
    return `<label class="pcl-champ${o.plein ? ' plein' : ''}"><span>${esc(l)}${o.requis ? ' <em>*</em>' : ''}</span>${w}</label>`;
  }
  /** « Préparer une offre » : la page Offres, sur ce client. */
  function offre(c) {
    if (!st.aller) { return; }
    st.aller('offres', { offre: { client: { id: c.id, nom: c.nom, b2b: !!c.b2b, ville: c.ville || '' }, note: 'Offre pour ' + c.nom + ' (depuis Clients).', source: 'clients' } });
  }

  function tete() {
    const n = (o, k) => o && o.d ? ` <span class="page-tabs__count">${nf(o.d[k])}</span>` : '';
    const ong = [['b2c', 'Particuliers', n(st.p, 'total')], ['b2b', 'Entreprises B2B', n(st.e, 'total')], ['bur', 'Bureaux', st.b.d && st.b.d.ok ? ` <span class="page-tabs__count">${st.b.d.data.bureaux.length}</span>` : ''], ['an', 'Analyse B2B', '']];
    const act = st.b.w && st.onglet === 'bur' ? '' : st.onglet === 'b2c' ? `<button type="button" class="btn btn--primary" data-act="p-ajouter">${SVG(ICO.plus)}Ajouter un client</button>`
      : st.onglet === 'b2b' ? `<button type="button" class="btn btn--primary" data-act="e-ajouter">${SVG(ICO.plus)}Ajouter une entreprise</button>`
      : st.onglet === 'bur' ? `<button type="button" class="btn btn--primary" data-act="w-ouvrir"${st.b.d && st.b.d.ok ? '' : ' disabled title="TF Buddy ne donne pas les bureaux au cockpit"'}>${SVG(ICO.plus)}Ajouter un bureau</button>` : '';
    return `<div class="pcl-tete"><div><p class="pcl-kicker">${esc(st.nomShop || 'Magasin ' + st.shop)}</p><h1>Clients</h1></div><span class="pcl-sp"></span>${act}</div>
      <nav class="page-tabs pcl-tabs" aria-label="Clients">${ong.map(([k, l, c]) => `<button type="button" class="page-tabs__tab${st.onglet === k ? ' is-on' : ''}" data-act="onglet" data-v="${k}">${l}${c}</button>`).join('')}
      <span class="pcl-sp"></span>${window.DashTfb ? (tfbEtat().connecte ? `<span class="pcl-tfb" title="Les données du panel se lisent avec le compte TF Buddy du magasin, connecté sur cet appareil">TF Buddy : compte du magasin connecté · <button type="button" class="linklike" data-act="tfb-deconnecter">déconnecter</button></span>` : `<button type="button" class="linklike" data-act="tfb-connecter">connecter TF Buddy</button>`) : ''}<button type="button" class="linklike" data-act="aller-offres">Les offres : Vendre › Offres</button></nav>`;
  }

  /* ── Particuliers ─────────────────────────────────────────────────────────────────────────────────────────── */
  function vueParticuliers() {
    const P = st.p, d = P.d;
    const barre = `<div class="pcl-filtres"><input id="pcl-pq" type="search" placeholder="Nom, téléphone, e-mail" value="${esc(P.q)}" autocomplete="off">
      <span class="pcl-grp">Avec ${seg('p-filtre', P.filtre, [['', 'Tous'], ['remise', 'Une remise' + (d ? ' · ' + nf(d.avecRemise) : '')]])}</span>
      <span class="pcl-sp"></span><span class="pcl-compte">${d ? nf(d.total) + ' particuliers' + (d.lu ? ' · lus à ' + esc(d.lu) : '') : ''}</span><button type="button" class="linklike" data-act="relire">relire</button></div>`;
    let corps;
    if (P.err && !d) { corps = `<div class="pcl-vide error">${esc(P.err)}</div>`; }
    else if (!d) { corps = attente('Lecture des particuliers du magasin…'); }
    else if (!d.clients.length) { corps = `<div class="pcl-vide">${d.q ? 'Aucun particulier ne répond à « ' + esc(d.q) + ' ».' : 'Aucun particulier.'}</div>`; }
    else {
      corps = `<div class="table-scroll"><table class="ds-table pcl-table"><thead><tr><th>Client</th><th>Téléphone</th><th class="num">Remise</th><th></th></tr></thead><tbody>
        ${d.clients.map(c => `<tr class="pcl-ligne${P.sel === c.id ? ' is-sel' : ''}" data-act="p-sel" data-id="${c.id}"><td>${esc(c.nom)}<span class="sub">${[c.cp, c.ville].filter(Boolean).map(esc).join(' ') || (c.cree ? 'depuis ' + fD(c.cree) : '')}</span></td>
          <td>${esc(c.tel) || '<span class="muted">—</span>'}</td><td class="num">${c.remise ? nf(c.remise, 1).replace(',0', '') + ' %' : '<span class="muted">—</span>'}</td>
          <td class="num"><button type="button" class="btn btn--sm" data-act="p-offre" data-id="${c.id}">Offre</button></td></tr>`).join('')}</tbody></table></div>
        <div class="pcl-pied">${d.trouves > d.clients.length ? nf(d.trouves) + ' trouvés, les ' + d.clients.length + ' premiers montrés : précisez la recherche.' : nf(d.trouves) + (d.q ? ' trouvé' + (d.trouves > 1 ? 's' : '') : ' — les derniers créés d’abord ; cherchez par le nom, le téléphone ou l’e-mail.')}</div>`;
    }
    return `<div class="pcl-2"><div><section class="card pcl-carte">${barre}${P.lecture && d ? '<div class="pcl-fil"></div>' : ''}${corps}</section></div>${ficheParticulier()}</div>`;
  }
  function ficheParticulier() {
    const P = st.p;
    if (!P.sel) { return `<aside class="card pcl-fiche pcl-fiche-vide">Choisissez un client dans la liste : sa fiche s’ouvre ici.</aside>`; }
    if (P.ficheErr) { return `<aside class="card pcl-fiche"><div class="pcl-bloc error">${esc(P.ficheErr)}</div></aside>`; }
    const F = P.fiche;
    if (!F) { return `<aside class="card pcl-fiche">${attente('Lecture de la fiche…')}</aside>`; }
    const c = F.client, adr = [[c.street, c.street_number].filter(Boolean).join(' '), [c.zip, c.city].filter(Boolean).join(' ')].filter(Boolean).join(', ');
    const mien = +c.id_main_shop === +st.shop;
    return `<aside class="card pcl-fiche"><div class="pcl-ft"><div class="pcl-fnom">${esc(c.nom)}</div><div class="pcl-fmeta">${c.create_timestamp ? 'client depuis ' + fD(String(c.create_timestamp).slice(0, 10)) : ''}${mien ? '' : ' · créé par un autre magasin'}</div></div>
      <div class="pcl-kpis"><div><div class="v">${c.personal_discount_percent ? nf(c.personal_discount_percent, 1).replace(',0', '') + ' %' : '—'}</div><div class="k">Remise</div></div>
        <div><div class="v">${c.can_deferral ? 'Oui' : 'Non'}</div><div class="k">Paiement différé</div></div><div><div class="v">${esc(c.city || '—')}</div><div class="k">Ville</div></div></div>
      <div class="pcl-bloc"><h4>Coordonnées</h4>${ligneF('Téléphone', c.phone ? `<a href="tel:${esc(String(c.phone).replace(/\s/g, ''))}">${esc(c.phone)}</a>` : '')}${ligneF('E-mail', c.email ? `<a href="mailto:${esc(c.email)}">${esc(c.email)}</a>` : '')}${ligneF('Adresse', esc(adr))}${c.comment ? ligneF('Note', esc(c.comment)) : ''}</div>
      <div class="pcl-bloc pcl-acts"><button type="button" class="btn btn--primary btn--sm" data-act="p-offre" data-id="${c.id}">Préparer une offre</button><button type="button" class="btn btn--sm" data-act="p-modifier">Modifier</button>
        <button type="button" class="btn btn--sm" data-act="p-entreprise">Passer en entreprise</button>${mien ? '<button type="button" class="btn btn--sm btn--danger" data-act="p-supprimer">Supprimer</button>' : ''}</div>
      <div class="pcl-bloc pcl-doux"><h4>Passer en entreprise</h4><div class="pcl-aide">Le numéro de TVA se vérifie dans TF Buddy puis dans VIES et remplit la société et l’adresse ; la fiche passe dans Entreprises B2B avec ses conditions de paiement et sa remise.</div></div></aside>`;
  }

  /* ── Entreprises B2B ──────────────────────────────────────────────────────────────────────────────────────── */
  function entreprisesVisibles() {
    const E = st.e, L = E.d ? E.d.entreprises : [];
    const q = E.q.trim().toLowerCase(), qn = q.replace(/[\s.]/g, '');
    return L.filter(c => {
      if (E.incompl && !c.manques.length) { return false; }
      if (q && !(c.nom.toLowerCase().includes(q) || (c.tva || '').toLowerCase().replace(/[\s.]/g, '').includes(qn) || (c.ville || '').toLowerCase().includes(q))) { return false; }
      if (E.score && c.score !== E.score) { return false; }
      if (E.ca === 'a' && !(c.ca12 < 1000)) { return false; } if (E.ca === 'b' && !(c.ca12 >= 1000 && c.ca12 <= 5000)) { return false; } if (E.ca === 'c' && !(c.ca12 > 5000)) { return false; }
      if (E.freq === 'a' && !(c.n12 >= 1 && c.n12 <= 5)) { return false; } if (E.freq === 'b' && !(c.n12 >= 6 && c.n12 <= 19)) { return false; } if (E.freq === 'c' && !(c.n12 >= 20)) { return false; }
      return true;
    });
  }
  function vueEntreprises() {
    const E = st.e, d = E.d;
    if (E.err && !d) { return `<div class="pcl-vide error card">${esc(E.err)}</div>`; }
    if (!d) { return attente('Lecture des entreprises du magasin…'); }
    const riche = d.source === 'panel', L = entreprisesVisibles();
    const haut = (d.incompletes ? `<div class="pcl-alerte">${SVG(ICO.alerte)}<div>${nf(d.incompletes)} entreprise${d.incompletes > 1 ? 's ont' : ' a'} des données incomplètes (TVA, adresse, ou ni e-mail ni téléphone) : leurs factures et leurs offres ne peuvent pas partir.</div>
        <button type="button" class="btn btn--sm" data-act="e-incompl">${E.incompl ? 'Toutes les afficher' : 'Les afficher'}</button></div>` : '')
      + (riche ? '' : note(`${esc(d.panel ? d.panel.texte : '')}<br>${esc(d.releve ? d.releve.texte : '')}${d.panel && (!d.panel.magasin || d.panel.magasinExpire) ? '<div style="margin-top:8px">' + tfbBouton() + '</div>' : ''}`, 'pcl-note-doux'));
    const barre = `<div class="pcl-filtres"><input id="pcl-eq" type="search" placeholder="Société, TVA, ville" value="${esc(E.q)}" autocomplete="off">
      ${riche ? `<span class="pcl-grp">Score ${seg('e-score', E.score, [['', 'Tous'], ['A', 'A'], ['B', 'B'], ['C', 'C'], ['D', 'D']])}</span>` : ''}
      <span class="pcl-grp">CA 12M ${seg('e-ca', E.ca, [['', 'Tous'], ['a', '< 1 k'], ['b', '1–5 k'], ['c', '> 5 k']])}</span>
      <span class="pcl-grp">${riche ? 'Commandes' : 'Tickets'} ${seg('e-freq', E.freq, [['', 'Tous'], ['a', '1–5'], ['b', '6–19'], ['c', '20 +']])}</span>
      <span class="pcl-sp"></span><button type="button" class="linklike" data-act="relire">relire</button></div>`;
    const lignes = L.map(c => `<tr class="pcl-ligne${E.sel === c.id ? ' is-sel' : ''}" data-act="e-sel" data-id="${c.id}"><td>${esc(c.nom)}${c.manques.length ? ` <span class="tag-warn" title="Manque ${esc(c.manques.map(m => MANQUE[m]).join(', '))}">à compléter</span>` : ''}<span class="sub">${esc(c.tva || 'pas de TVA')}${c.ville ? ' · ' + esc(c.ville) : ''}</span></td>
      ${riche ? `<td>${score(c.score)}</td><td>${esc(lib(TYPES, c.type))}<span class="sub">${esc(lib(MODES, c.mode))}</span></td>` : ''}
      <td class="num">${c.n12 ? nf(c.n12) : '<span class="muted">0</span>'}<span class="sub">${c.dernier ? 'dernier ' + fD(c.dernier) : 'rien en 12 mois'}</span></td>
      <td class="num">${c.ca12 ? fE0(c.ca12) : '<span class="muted">—</span>'}</td>${riche ? `<td>${credit(c.credit)}</td>` : ''}
      <td class="num"><button type="button" class="btn btn--sm" data-act="e-offre" data-id="${c.id}">Offre</button></td></tr>`).join('');
    const table = L.length ? `<div class="table-scroll"><table class="ds-table pcl-table"><thead><tr><th>Société</th>${riche ? '<th>Score</th><th>Type · mode</th>' : ''}<th class="num">${riche ? 'Commandes' : 'Tickets'} 12M</th><th class="num">CA 12M</th>${riche ? '<th>Crédit</th>' : ''}<th></th></tr></thead><tbody>${lignes}</tbody></table></div>`
      : `<div class="pcl-vide">Aucune entreprise ne répond à ces filtres.</div>`;
    return `<div class="pcl-2"><div>${haut}<section class="card pcl-carte">${barre}${E.lecture ? '<div class="pcl-fil"></div>' : ''}${table}<div class="pcl-pied">${nf(L.length)} sur ${nf(d.total)} entreprises${d.lu ? ' · lues à ' + esc(d.lu) : ''}</div></section></div>${ficheEntreprise()}</div>`;
  }
  /** Les bureaux d'une société (la liste des bureaux, si TF Buddy la donne). */
  const bureauxDe = id => st.b.d && st.b.d.ok ? st.b.d.data.bureaux.filter(b => b.idSociete === id) : null;
  function ficheEntreprise() {
    const E = st.e;
    if (!E.sel) { return `<aside class="card pcl-fiche pcl-fiche-vide">Choisissez une entreprise : sa fiche s’ouvre ici (le crédit, ses bureaux, ses factures).</aside>`; }
    if (E.ficheErr) { return `<aside class="card pcl-fiche"><div class="pcl-bloc error">${esc(E.ficheErr)}</div></aside>`; }
    const F = E.fiche, L = E.d ? E.d.entreprises.find(x => x.id === E.sel) : null;
    if (!F) { return `<aside class="card pcl-fiche">${attente('Lecture de la fiche…')}</aside>`; }
    const c = F.client, p = F.profil || {}, r = F.releve || {}, k = F.credit || (L && L.credit) || null;
    const n12 = L && L.n12 != null ? L.n12 : r.n, ca12 = L && L.ca12 != null ? L.ca12 : r.ca, cy = L && L.cycle != null ? L.cycle : r.cycle;
    const B = bureauxDe(c.id);
    const meta = [c.tax_number || 'pas de TVA', p.depuis ? 'client depuis ' + fD(p.depuis) : '', c.can_deferral ? 'paiement différé' + (c.payment_terms ? ' à ' + nf(c.payment_terms) + ' jours' : '') : 'comptant', c.personal_discount_percent ? 'remise ' + nf(c.personal_discount_percent, 1).replace(',0', '') + ' %' : ''].filter(Boolean);
    const fac = F.factures;
    return `<aside class="card pcl-fiche"><div class="pcl-ft"><div class="pcl-fnom">${esc(c.nom)} ${p.score ? score(p.score) : ''}</div><div class="pcl-fmeta">${meta.map(esc).join(' · ')}</div>
        ${F.manques && F.manques.length ? `<div class="pcl-fmeta"><span class="tag-warn">à compléter</span> manque ${esc(F.manques.map(m => MANQUE[m]).join(', '))}</div>` : ''}</div>
      <div class="pcl-kpis"><div><div class="v">${nf(n12 || 0)}</div><div class="k">${E.d && E.d.source === 'panel' ? 'Commandes' : 'Tickets'} 12M</div></div><div><div class="v">${fE0(ca12 || 0)}</div><div class="k">CA 12M</div></div><div><div class="v">${cy != null ? '~ ' + nf(cy) + ' j' : '—'}</div><div class="k">Cycle d’achat</div></div>
        <div><div class="v">${esc(lib(TYPES, p.type))}</div><div class="k">Type</div></div><div><div class="v">${esc(lib(MODES, p.mode))}</div><div class="k">Mode</div></div><div><div class="v">${k && k.pct != null ? nf(k.pct) + ' %' : '—'}</div><div class="k">Crédit utilisé</div></div></div>
      ${k ? `<div class="pcl-bloc"><h4>Crédit<span>${k.utilise != null ? fE0(k.utilise) + ' impayés sur ' : 'limite '}${fE0(k.limite || 0)}</span></h4>${credit(k)}</div>` : ''}
      <div class="pcl-bloc"><h4>Ses bureaux<span>livrés par une tournée</span></h4>${B === null ? '<div class="pcl-aide">TF Buddy ne donne pas les bureaux au cockpit.</div>' : B.length ? B.map(b => `<div class="pcl-chaine"><span>${esc(c.nom)}</span><i>›</i><span>${esc(b.nom)}</span>${b.ville ? `<i>›</i><span>${esc(b.ville)}</span>` : ''}</div>`).join('') : '<div class="pcl-aide">Aucun bureau pour cette société.</div>'}</div>
      ${F.commandes ? `<div class="pcl-bloc"><h4>Dernières commandes<span>${F.commandes.length}</span></h4><table class="pcl-mini"><tbody>${F.commandes.slice(0, 6).map(o => `<tr><td>${fD(o.date)}</td><td>${esc(o.texte || 'commande n° ' + o.id)}</td><td class="num">${o.montant != null ? fE(o.montant) : ''}</td></tr>`).join('')}</tbody></table></div>` : ''}
      <div class="pcl-bloc"><h4>Factures<span>${fac ? nf(fac.n) + (fac.impayees ? ' · ' + fac.impayees + ' impayée' + (fac.impayees > 1 ? 's' : '') : '') : ''}</span></h4>${!fac ? `<div class="pcl-aide">${esc(F.facturesMotif || 'non lues')}</div>` : fac.liste.length ? `<table class="pcl-mini"><tbody>${fac.liste.slice(0, 6).map(x => `<tr><td>${fD(x.date)}</td><td class="num">${fE(x.montant)}</td><td class="num">${x.payee ? '<span class="chip chip--ok">payée</span>' : '<span class="tag-warn">due ' + fD(x.echeance) + '</span>'}</td></tr>`).join('')}</tbody></table>` : '<div class="pcl-aide">Aucune facture.</div>'}</div>
      <div class="pcl-bloc pcl-acts"><button type="button" class="btn btn--primary btn--sm" data-act="e-offre" data-id="${c.id}">Préparer une offre</button><button type="button" class="btn btn--sm" data-act="e-base">Données de base</button><button type="button" class="btn btn--sm" data-act="e-profil">Profil commercial</button></div></aside>`;
  }

  /* ── Bureaux ──────────────────────────────────────────────────────────────────────────────────────────────── */
  /**
   * Ce que dit un refus (26/11/2026, « Réponse : HTTP 404 : ORDER_NOT_FOUND · consultant (404) puis admin (404) ») : quels
   * comptes ont été essayés, et l'état du compte du magasin — pas connecté sur cet appareil (le cas de ce message), connecté
   * mais son jeton n'est pas arrivé au serveur, session périmée, ou connecté sans droit sur ce magasin.
   */
  function etatMagasin(d) {
    const ici = tfbEtat().connecte;
    if (d.magasinExpire) { return 'la session a expiré : reconnectez-le'; }
    if (d.magasin) { return 'connecté, mais TF Buddy lui refuse ce magasin (est-ce bien un employé de ce magasin ?)'; }
    if (ici) { return 'connecté sur cet appareil, mais son jeton n’est pas arrivé au serveur : rechargez la page, puis réessayez'; }
    return 'pas connecté sur cet appareil';
  }
  function refus(d, quoi) {
    const btn = !d.magasin || d.magasinExpire ? tfbBouton(d.magasinExpire ? 'Reconnecter le compte TF Buddy du magasin' : '') : '';
    const comptes = String(d.comptes || '').replace(/\((\d+)\)/g, (m, c) => c === '404' || c === '401' || c === '403' ? '(refusé, ' + c + ')' : m);
    return `<section class="card pcl-refus">${SVG(ICO.cadenas)}<div><h3>${esc(quoi)} ne se lisent pas</h3><p>${esc(d.texte || d.motif || '')}</p>${btn ? `<p>${btn}</p>` : ''}
      <p class="muted">Compte TF Buddy du magasin : ${esc(etatMagasin(d))}.${comptes ? ' Comptes essayés : ' + esc(comptes) + '.' : ''}${d.motif ? ' Réponse de TF Buddy : ' + esc(d.motif) + '.' : ''}</p></div></section>`;
  }
  const STATUTS = { active: ['Actif', 'chip chip--ok'], pending: ['En attente', 'tag-warn'], inactive: ['Inactif', 'chip chip--draft'], archived: ['Archivé', 'chip chip--draft'] };
  const statut = s => { const x = STATUTS[s] || [s || '—', 'chip chip--draft']; return `<span class="${x[1]}">${esc(x[0])}</span>`; };
  function vueBureaux() {
    const B = st.b, d = B.d;
    if (B.w) { return vueAssistant(); }
    if (B.err && !d) { return `<div class="pcl-vide error card">${esc(B.err)}</div>`; }
    if (!d) { return attente('Lecture des bureaux du magasin…'); }
    if (!d.ok) { return refus(d, 'Les bureaux du magasin') + note('Ce que montre l’onglet une fois le compte TF Buddy du magasin connecté : une ligne par bureau (statut, catalogue, offres, sites), sa fiche en onglets — Livraison (site, tournée, créneau, heure limite), Catalogue, Offres, Personnes (invitations), Documents (affiche, cartes, brochure) — et « Ajouter un bureau » en sept étapes.', 'pcl-note-doux'); }
    const q = B.q.trim().toLowerCase();
    const L = d.data.bureaux.filter(b => (!B.statut || b.statut === B.statut) && (!q || [b.nom, b.societe, b.tva, b.ville, b.contact].join(' ').toLowerCase().includes(q)));
    const nAct = d.data.bureaux.filter(b => b.statut === 'active').length, nAtt = d.data.bureaux.filter(b => b.statut === 'pending').length;
    const barre = `<div class="pcl-filtres"><input id="pcl-bq" type="search" placeholder="Bureau, société, TVA, ville, contact" value="${esc(B.q)}" autocomplete="off">
      <span class="pcl-grp">Statut ${seg('b-statut', B.statut, [['', 'Tous ' + d.data.bureaux.length], ['active', 'Actifs ' + nAct], ['pending', 'En attente ' + nAtt]])}</span><span class="pcl-sp"></span><button type="button" class="linklike" data-act="relire">relire</button></div>`;
    const lignes = L.map(b => `<tr class="pcl-ligne${B.sel === b.id ? ' is-sel' : ''}" data-act="b-sel" data-id="${b.id}"><td>${esc(b.nom)}<span class="sub">${esc([b.societe, b.ville].filter(Boolean).join(' · '))}</span></td><td>${statut(b.statut)}</td>
      <td>${b.catalogue === 'restricted' || b.catalogue === 'custom' ? 'Réduit' : b.catalogue ? 'Complet' : '—'}</td><td class="num">${nf(b.offres)}</td><td class="num">${nf(b.departements)}</td><td>${b.sites ? nf(b.sites) + ' site' + (b.sites > 1 ? 's' : '') : '<span class="tag-warn">pas de site</span>'}</td></tr>`).join('');
    return `<div class="pcl-2"><div><section class="card pcl-carte">${barre}${B.lecture ? '<div class="pcl-fil"></div>' : ''}${L.length ? `<div class="table-scroll"><table class="ds-table pcl-table"><thead><tr><th>Bureau</th><th>Statut</th><th>Catalogue</th><th class="num">Offres</th><th class="num">Départements</th><th>Livraison</th></tr></thead><tbody>${lignes}</tbody></table></div>` : '<div class="pcl-vide">Aucun bureau.</div>'}</section>
      ${d.data.bureaux.some(b => !b.sites) ? note('Un bureau sans site ne peut être livré par aucune tournée tant qu’il n’a pas de point de livraison.', 'pcl-note-doux') : ''}</div>${ficheBureau()}</div>`;
  }
  const detDe = ch => st.b.det[st.b.sel + ch];
  const detCorps = (ch, f) => { const x = detDe(ch); if (!x || x.lecture) { lireDet(ch); return attente('Lecture…'); } if (!x.ok) { return `<div class="pcl-aide error">${esc(x.texte || x.motif || 'illisible')}</div>`; } return f(x.data || {}); };
  function ficheBureau() {
    const B = st.b;
    if (!B.sel) { return `<aside class="card pcl-fiche pcl-fiche-vide">Choisissez un bureau : sa livraison, son catalogue, ses offres, ses personnes et ses documents s’ouvrent ici.</aside>`; }
    const b = B.d.data.bureaux.find(x => x.id === B.sel);
    if (!b) { return ''; }
    const O = [['livraison', 'Livraison'], ['catalogue', 'Catalogue'], ['offres', 'Offres'], ['personnes', 'Personnes'], ['documents', 'Documents']];
    let corps = '';
    if (B.ong === 'livraison') {
      corps = detCorps('/delivery-settings', s => {
        const r = s.route || {};
        return `<div class="pcl-bloc"><h4>Livraison<span><button type="button" class="linklike" data-act="b-livraison">modifier</button></span></h4>${ligneF('Tournée', r.name ? `<span class="pcl-tchip">${SVG(ICO.camion)}${esc(r.name)}</span>` : '<span class="tag-warn">aucune tournée</span>')}
          ${ligneF('Créneau', s.delivery_window_start ? esc(String(s.delivery_window_start).slice(0, 5) + ' – ' + String(s.delivery_window_end || '').slice(0, 5)) : '')}
          ${ligneF('Commandes jusqu’à', s.cutoff_time ? esc(String(s.cutoff_time).slice(0, 5)) + ' ' + (+s.cutoff_days_before === 1 ? 'la veille' : +s.cutoff_days_before > 1 ? s.cutoff_days_before + ' jours avant' : 'le jour même') : '')}${ligneF('Remise sur place', s.drop_minutes ? nf(s.drop_minutes) + ' min' : '')}
          ${ligneF('Jours', b.jours.length ? JOURS.filter(j => b.jours.includes(j[0])).map(j => ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'][j[0] - 1]).join(', ') : '')}</div>`;
      }) + detCorps('/sites', s => { const L = Array.isArray(s) ? s : (s.sites || []); return `<div class="pcl-bloc"><h4>Sites<span>${L.length}</span></h4>${L.length ? L.map(x => ligneF(x.name || 'Site', esc([x.address, x.postal_code, x.city].filter(Boolean).join(' ')) + (x.access_minutes ? ` <span class="muted">· accès ${nf(x.access_minutes)} min</span>` : ''))).join('') : '<div class="pcl-aide">Aucun site : ce bureau ne peut pas être livré.</div>'}</div>`; });
    } else if (B.ong === 'catalogue') {
      corps = detCorps('/assortment', a => { const P = a.products || a.items || a.product_ids || []; return `<div class="pcl-bloc"><h4>Catalogue</h4>${ligneF('Mode', esc(a.mode || a.assortment_mode || b.catalogue || 'complet'))}${ligneF('Produits', Array.isArray(P) ? nf(P.length) : '')}${ligneF('Prix affichés', b.prix ? 'oui' : 'non')}<div class="pcl-aide">Le catalogue se règle produit par produit dans TF Buddy (Biura › Catalogue).</div></div>`; });
    } else if (B.ong === 'offres') {
      corps = detCorps('', o => { const L = o.offers || []; return `<div class="pcl-bloc"><h4>Offres du bureau<span>${L.length || nf(b.offres)}</span></h4>${L.length ? L.map(x => ligneF(x.name || x.title || 'Offre n° ' + x.id, esc([x.discount_percent != null ? x.discount_percent + ' %' : '', x.valid_to ? 'jusqu’au ' + fD(String(x.valid_to).slice(0, 10)) : ''].filter(Boolean).join(' · ')))).join('') : '<div class="pcl-aide">' + (b.offres ? nf(b.offres) + ' offre(s) : le détail se lit dans TF Buddy.' : 'Aucune offre.') + '</div>'}</div>`; });
    } else if (B.ong === 'personnes') {
      corps = detCorps('/members', m => { const L = Array.isArray(m) ? m : (m.members || []); return `<div class="pcl-bloc"><h4>Personnes<span>${L.length}</span></h4>${L.slice(0, 40).map(x => ligneF([x.name, x.surname].filter(Boolean).join(' ') || 'Membre n° ' + (x.id || ''), esc(x.department_name || x.department || ''))).join('') || '<div class="pcl-aide">Personne encore.</div>'}</div>`; })
        + `<div class="pcl-bloc"><h4>Inviter</h4><div class="pcl-ligne-in"><input id="pcl-inv" type="email" placeholder="prenom.nom@societe.be"><button type="button" class="btn btn--sm btn--primary" data-act="b-inviter">Envoyer l’invitation</button></div>
          ${detCorps('/invite', i => { const v = i.invite || null; return v ? `<div class="pcl-aide">Lien d’invitation actif${v.expires_at ? ' jusqu’au ' + fD(String(v.expires_at).slice(0, 10)) : ''}${v.uses != null ? ' · ' + nf(v.uses) + ' inscrits' : ''}. <button type="button" class="linklike" data-act="b-lien-retirer">retirer le lien</button></div>${v.url ? `<div class="pcl-lien">${esc(v.url)}</div>` : ''}` : '<div class="pcl-aide">Pas de lien d’invitation. <button type="button" class="linklike" data-act="b-lien">créer un lien</button></div>'; })}</div>`;
    } else {
      corps = `<div class="pcl-bloc"><h4>Documents</h4><div class="pcl-aide">Les PDF que TF Buddy fabrique pour le bureau.</div><div class="pcl-acts" style="margin-top:8px">
        <button type="button" class="btn btn--sm" data-act="b-pdf" data-v="poster">${SVG(ICO.doc)}Affiche A2</button><button type="button" class="btn btn--sm" data-act="b-pdf" data-v="cards">${SVG(ICO.doc)}Cartes de bienvenue</button><button type="button" class="btn btn--sm" data-act="b-pdf" data-v="brochure">${SVG(ICO.doc)}Brochure</button></div>
        ${B.pdf ? `<p class="${B.pdf.err ? 'error' : 'pcl-aide'}">${esc(B.pdf.err || B.pdf.texte)}</p>` : ''}</div>`;
    }
    // Le message d'un geste (invitation, lien, document) : sous les onglets, quel que soit l'onglet ouvert.
    const msg = B.pdf && B.ong !== 'documents' ? `<div class="pcl-bloc"><p class="${B.pdf.err ? 'error' : 'pcl-ok'}" style="margin:0">${esc(B.pdf.err || B.pdf.texte)}</p></div>` : '';
    return `<aside class="card pcl-fiche"><div class="pcl-ft"><div class="pcl-fnom">${esc(b.nom)} ${statut(b.statut)}</div><div class="pcl-fmeta">${esc(['Société : ' + (b.societe || '—'), b.contact, b.facturation === 'company' ? 'facturé à la société' : b.facturation === 'person' ? 'chaque personne paie' : ''].filter(Boolean).join(' · '))}</div></div>
      <div class="pcl-ong2">${O.map(([k, l]) => `<button type="button" class="${B.ong === k ? 'is-on' : ''}" data-act="b-ong" data-v="${k}">${l}</button>`).join('')}</div>${msg}${corps}</aside>`;
  }

  /* L'assistant « Ajouter un bureau » : les sept étapes du panel (offices-onboarding.js), envoyées d'un coup (POST /onboard). */
  function nouvelAssistant(opt) {
    const cle = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'pcl-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    return { etape: 0, cle, opt: opt || {}, err: null, busy: false, ok: null,
      f: { id_company_client: '', name: '', tax_number: '', segment: '', payment_terms: 'Comptant', credit_limit: '', contact_name: '', contact_function: '', contact_email: '', contact_phone: '',
        delivery_window_start: '', delivery_window_end: '', delivery_validation: '', webshop_discount_percent: '', free_shipping_terms: '', show_prices: true, billing_mode: 'person',
        send_summary: true, send_membership: false, dep_nom: '', dep_n: '', st_nom: '', st_mail: '', csv: '', voucher: '', site_existant: '' },
      sites: [{ name: '', address: '', postal_code: '', city: '', building: '', floor: '', country: 'BE', route_id: '', access_minutes: '', notes: '', delivery_site_id: null }],
      jours: [1, 2, 3, 4, 5], deps: [], staff: [], vouchers: [] };
  }
  function vueAssistant() {
    const w = st.b.w, f = w.f, o = w.opt, E = w.etape;
    const src = { src: f };
    let corps = '';
    if (E === 0) {
      corps = `<div class="pcl-form2">${champ('id_company_client', 'Société (client B2B du magasin)', { src: f, requis: true, plein: true, choix: [['', 'Choisir…'], ...(o.companies || []).map(c => [c.id, [c.name || c.company_name, c.tax_number].filter(Boolean).join(' · ')])] })}
        ${champ('name', 'Nom du bureau', { src: f, requis: true })}${champ('tax_number', 'TVA', { src: f, requis: true, aide: 'BE0123456789' })}
        ${champ('segment', 'Segment', { src: f, choix: [['', '—'], ['horeca', 'Horeca'], ['retail', 'Commerce'], ['corporate', 'Entreprise'], ['event', 'Événement']] })}${champ('payment_terms', 'Paiement', { src: f, choix: [['Comptant', 'Comptant'], ['Différé', 'Différé']] })}
        ${champ('credit_limit', 'Limite de crédit (€)', { src: f, type: 'number' })}</div><div class="pcl-aide pcl-pad">La société est une entreprise B2B du magasin : ajoutez-la d’abord dans Entreprises B2B si elle n’y est pas.</div>`;
    } else if (E === 1) {
      corps = `<div class="pcl-form2">${champ('contact_name', 'Responsable', { src: f, requis: true })}${champ('contact_function', 'Fonction', { src: f, aide: 'Office manager' })}${champ('contact_email', 'E-mail', { src: f, requis: true, type: 'email' })}${champ('contact_phone', 'Téléphone', { src: f, type: 'tel' })}</div>`;
    } else if (E === 2) {
      const R = [['', 'Aucune tournée'], ...(o.routes || []).map(r => [r.id, r.name + (r.start_time ? ' (' + String(r.start_time).slice(0, 5) + ')' : '')])];
      corps = `<div class="pcl-pad"><div class="pcl-h">Où livrer ?</div>${(o.delivery_sites || []).length ? `<div class="pcl-ligne-in">${champ('site_existant', 'Un point de livraison existant', { src: f, choix: [['', 'Choisir…'], ...(o.delivery_sites || []).map(s => [s.id, [s.name, s.address].filter(Boolean).join(' · ')])] })}<button type="button" class="btn btn--sm" data-act="w-site-existant">Ajouter</button></div>` : ''}</div>
        ${w.sites.map((s, i) => `<div class="pcl-site"><div class="pcl-h">Site ${i + 1}${s.delivery_site_id ? ' · point existant' : ''}${i ? ` <button type="button" class="linklike" data-act="w-site-retirer" data-i="${i}">retirer</button>` : ''}</div><div class="pcl-form2">
          ${champ('name', 'Nom du site', { src: s, ou: 's' + i })}${champ('country', 'Pays', { src: s, ou: 's' + i, choix: [['BE', 'Belgique'], ['PL', 'Pologne'], ['FR', 'France'], ['NL', 'Pays-Bas'], ['LU', 'Luxembourg']] })}
          ${champ('address', 'Adresse', { src: s, ou: 's' + i, requis: true, plein: true, aide: 'Rue et numéro' })}${champ('postal_code', 'Code postal', { src: s, ou: 's' + i })}${champ('city', 'Ville', { src: s, ou: 's' + i })}
          ${champ('building', 'Bâtiment', { src: s, ou: 's' + i })}${champ('floor', 'Étage', { src: s, ou: 's' + i })}${champ('access_minutes', 'Temps d’accès sur place (min)', { src: s, ou: 's' + i, type: 'number' })}${champ('route_id', 'Livré par', { src: s, ou: 's' + i, choix: R })}</div></div>`).join('')}
        <div class="pcl-pad"><button type="button" class="btn btn--sm" data-act="w-site-plus">${SVG(ICO.plus)}Un autre site</button></div>
        <div class="pcl-form2">${champ('delivery_window_start', 'Créneau : de', { src: f, type: 'time' })}${champ('delivery_window_end', 'à', { src: f, type: 'time' })}${champ('delivery_validation', 'Validation de la remise', { src: f, choix: [['', '—'], ['qr', 'QR'], ['signature', 'Signature'], ['dropoff', 'Dépôt']] })}
          <label class="pcl-champ plein">Jours desservis<span class="pcl-jours">${JOURS.map(([n, l]) => `<button type="button" class="${w.jours.includes(n) ? 'is-on' : ''}" data-act="w-jour" data-v="${n}">${l}</button>`).join('')}</span></label></div>`;
    } else if (E === 3) {
      const pre = o.department_presets || [];
      corps = `<div class="pcl-pad">${pre.length ? `<div class="pcl-h">Départements courants</div><div class="pcl-chips">${pre.map(n => { const nom = typeof n === 'string' ? n : (n.name || ''); return `<button type="button" class="${w.deps.some(d => d.name === nom) ? 'is-on' : ''}" data-act="w-dep-pre" data-v="${esc(nom)}">${esc(nom)}</button>`; }).join('')}</div>` : ''}</div>
        <div class="pcl-form2">${champ('dep_nom', 'Département', src)}${champ('dep_n', 'Personnes', { src: f, type: 'number' })}</div><div class="pcl-pad"><button type="button" class="btn btn--sm" data-act="w-dep">${SVG(ICO.plus)}Ajouter</button>
        ${w.deps.length ? `<div class="pcl-chips" style="margin-top:10px">${w.deps.map((d, i) => `<span class="pcl-chip">${esc(d.name)}${d.headcount != null ? ' · ' + d.headcount : ''} <button type="button" class="linklike" data-act="w-dep-retirer" data-i="${i}" aria-label="Retirer">×</button></span>`).join('')}</div>` : '<div class="pcl-aide">Facultatif.</div>'}</div>`;
    } else if (E === 4) {
      corps = `<div class="pcl-form2">${champ('webshop_discount_percent', 'Remise webshop (%)', { src: f, type: 'number' })}${champ('free_shipping_terms', 'Livraison offerte', { src: f, aide: 'dès 60 €' })}${champ('show_prices', 'Prix affichés aux personnes', { src: f, type: 'case', plein: true })}
        ${(o.vouchers || []).length ? `<div class="pcl-ligne-in plein">${champ('voucher', 'Bon à rattacher', { src: f, choix: [['', 'Choisir…'], ...(o.vouchers || []).map(v => [v.id, [v.code, v.name].filter(Boolean).join(' · ')])] })}<button type="button" class="btn btn--sm" data-act="w-bon">Ajouter</button></div>` : ''}</div>
        ${w.vouchers.length ? `<div class="pcl-pad pcl-chips">${w.vouchers.map((id, i) => { const v = (o.vouchers || []).find(x => +x.id === id) || {}; return `<span class="pcl-chip">${esc(v.code || 'bon ' + id)} <button type="button" class="linklike" data-act="w-bon-retirer" data-i="${i}">×</button></span>`; }).join('')}</div>` : ''}`;
    } else if (E === 5) {
      corps = `<div class="pcl-form2">${champ('st_nom', 'Nom', src)}${champ('st_mail', 'E-mail', { src: f, type: 'email' })}</div><div class="pcl-pad"><button type="button" class="btn btn--sm" data-act="w-pers">${SVG(ICO.plus)}Ajouter</button></div>
        <div class="pcl-form2">${champ('csv', 'Ou collez une liste « nom;email », une personne par ligne', { src: f, type: 'zone', plein: true, aide: 'Marie Dupont;marie.dupont@societe.be' })}</div><div class="pcl-pad"><button type="button" class="btn btn--sm" data-act="w-csv">Lire la liste</button>
        ${w.staff.length ? `<table class="pcl-mini" style="margin-top:10px"><tbody>${w.staff.map((p, i) => `<tr><td>${esc(p.name)}</td><td>${esc(p.email)}</td><td class="num"><button type="button" class="linklike" data-act="w-pers-retirer" data-i="${i}">retirer</button></td></tr>`).join('')}</tbody></table>` : '<div class="pcl-aide">Facultatif : les personnes peuvent aussi s’inscrire par le lien d’invitation.</div>'}</div>`;
    } else {
      corps = `<div class="pcl-form2">${champ('billing_mode', 'Facturation', { src: f, choix: [['person', 'Chaque personne paie'], ['company', 'Facturé à la société']] })}<span></span>
        ${champ('send_summary', 'Envoyer le résumé au responsable', { src: f, type: 'case', plein: true })}${champ('send_membership', 'Envoyer l’invitation aux personnes', { src: f, type: 'case', plein: true })}</div>
        <div class="pcl-pad pcl-aide">Tout part d’un coup vers TF Buddy (une clé de requête évite le double envoi si l’on clique deux fois).</div>`;
    }
    const resume = resumeAssistant();
    return `<div class="pcl-2"><section class="card pcl-carte pcl-ass"><div class="pcl-ft"><div class="pcl-fnom">Ajouter un bureau${f.name ? ' · ' + esc(f.name) : ''}</div><div class="pcl-fmeta">Étape ${E + 1} sur 7 · ce qui est saisi se garde si l’on revient en arrière</div></div>
      <div class="pcl-stepper">${ETAPES.map((x, i) => `<button type="button" class="${i < E ? 'fait' : i === E ? 'on' : ''}" data-act="w-aller" data-v="${i}"${i > E ? ' disabled' : ''}><i></i>${i + 1} · ${x}</button>`).join('')}</div>
      ${corps}${w.err ? `<p class="error pcl-pad">${esc(w.err)}</p>` : ''}${w.ok ? `<p class="pcl-ok pcl-pad">${esc(w.ok)}</p>` : ''}
      <div class="pcl-pied-ass"><button type="button" class="btn btn--ghost" data-act="${E ? 'w-retour' : 'w-fermer'}">${E ? 'Retour' : 'Annuler'}</button><span class="pcl-aide">${['La société, le nom du bureau, la TVA', 'Le responsable et son e-mail', 'Au moins un site avec une adresse, pour pouvoir livrer', 'Les départements, facultatifs', 'Les conditions du webshop', 'Les personnes, facultatives', 'Relisez, puis créez'][E]}</span>
        <button type="button" class="btn btn--primary${w.busy ? ' is-busy' : ''}" data-act="${E === 6 ? 'w-creer' : 'w-suivant'}"${w.busy ? ' disabled' : ''}>${E === 6 ? 'Créer le bureau' : 'Suivant · ' + ETAPES[E + 1]}</button></div></section>${resume}</div>`;
  }
  /** « Ce que l’on crée » : la chaîne société › bureau › site › tournée, refaite à chaque saisie (saisie()). */
  function resumeAssistant() {
    const w = st.b.w, f = w.f, o = w.opt, soc = (o.companies || []).find(c => String(c.id) === String(f.id_company_client));
    return `<aside class="card pcl-fiche" id="pcl-ass-resume"><div class="pcl-ft"><div class="pcl-fnom">Ce que l’on crée</div><div class="pcl-fmeta">mis à jour à chaque étape</div></div>
      <div class="pcl-bloc"><h4>La chaîne</h4><div class="pcl-chaine"><span>${esc(soc ? (soc.name || soc.company_name) : 'Société ?')}</span><i>›</i><span>Bureau · ${esc(f.name || '?')}</span><i>›</i><span>${esc(w.sites[0].name || w.sites[0].address || 'Site ?')}</span>${(() => { const r = (o.routes || []).find(x => String(x.id) === String(w.sites[0].route_id)); return r ? `<i>›</i><span>${SVG(ICO.camion)} ${esc(r.name)}</span>` : ''; })()}</div></div>
      <div class="pcl-bloc"><h4>Société</h4>${ligneF('TVA', esc(f.tax_number))}${ligneF('Paiement', esc(f.payment_terms))}${f.credit_limit ? ligneF('Crédit', fE0(f.credit_limit)) : ''}</div>
      <div class="pcl-bloc"><h4>Contact</h4>${ligneF('Responsable', esc(f.contact_name))}${ligneF('E-mail', f.contact_email ? 'renseigné' : '')}</div>
      <div class="pcl-bloc"><h4>Ensuite</h4>${ligneF('Sites', nf(w.sites.filter(s => s.address).length))}${ligneF('Départements', nf(w.deps.length))}${ligneF('Personnes', nf(w.staff.length))}${ligneF('Jours', w.jours.map(n => ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'][n - 1]).join(', '))}</div></aside>`;
  }
  function assistantValide(w) {
    const f = w.f;
    if (w.etape === 0 && (!f.id_company_client || !f.name.trim() || !/^[A-Za-z]{2}[0-9A-Za-z]{6,14}$/.test(f.tax_number.replace(/[\s.\-]/g, '')))) { return 'Choisissez la société, nommez le bureau et donnez une TVA (BE0123456789).'; }
    if (w.etape === 1 && (!f.contact_name.trim() || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(f.contact_email.trim()))) { return 'Le responsable et un e-mail valide sont attendus.'; }
    if (w.etape === 2) {
      if (!w.sites.some(s => s.address.trim())) { return 'Au moins un site avec une adresse, pour pouvoir livrer.'; }
      if ((f.delivery_window_start || f.delivery_window_end) && !(f.delivery_window_start && f.delivery_window_end && f.delivery_window_start < f.delivery_window_end)) { return 'Le créneau : une heure de début avant l’heure de fin.'; }
    }
    return null;
  }
  function creerBureau() {
    const w = st.b.w, f = w.f, num = v => v === '' || v == null ? null : Number(v);
    const office = { id_company_client: Number(f.id_company_client), name: f.name.trim(), tax_number: f.tax_number.replace(/[\s.\-]/g, '').toUpperCase(), status: 'active', segment: f.segment || null,
      payment_terms: f.payment_terms || null, credit_limit: f.credit_limit === '' ? null : f.credit_limit, billing_mode: f.billing_mode, show_prices: !!f.show_prices,
      webshop_discount_percent: f.webshop_discount_percent === '' ? null : f.webshop_discount_percent, free_shipping_terms: f.free_shipping_terms || null,
      delivery_window_start: f.delivery_window_start || null, delivery_window_end: f.delivery_window_end || null, delivery_validation: f.delivery_validation || null, delivery_days: w.jours.slice().sort() };
    const sites = w.sites.filter(s => s.address.trim()).map((s, i) => ({ name: s.name.trim() || office.name, address: s.address.trim(), postal_code: s.postal_code.trim(), city: s.city.trim(), building: s.building.trim(),
      floor: s.floor.trim(), country: s.country, notes: s.notes, route_id: num(s.route_id), access_minutes: num(s.access_minutes), delivery_site_id: s.delivery_site_id, is_default: i === 0, active: true }));
    const corps = { office, contact: { name: f.contact_name.trim(), email: f.contact_email.trim(), phone: f.contact_phone.trim(), function: f.contact_function.trim(), role: 'contact' },
      sites, departments: w.deps, staff: w.staff, voucher_ids: w.vouchers, request_key: w.cle, send_summary: !!f.send_summary, send_membership: !!f.send_membership, locale: 'fr' };
    w.busy = true; w.err = null; rendre();
    envoyer('/dashboard/clients/bureaux', { methode: 'POST', chemin: '/onboard', corps }).then(d => {
      const id = Number((d.data && (d.data.office && d.data.office.id || d.data.id)) || 0);
      st.b.w = null; st.b.d = null; st.b.sel = id || null; st.b.ong = 'livraison'; st.b.det = {};
      return lireBureaux();
    }).catch(e => { w.busy = false; w.err = e.message; rendre(); });
  }

  /* ── Analyse B2B ──────────────────────────────────────────────────────────────────────────────────────────── */
  function vueAnalyse() {
    const A = st.a, d = A.d, auj = new Date();
    const dernier = A.annee === auj.getFullYear() && A.mois === auj.getMonth() + 1;
    const barre = `<div class="pcl-barre"><button type="button" class="btn btn--icon btn--sm" data-act="a-pas" data-v="-1" aria-label="Mois précédent">${SVG(ICO.g)}</button><span class="pcl-date">${MOIS[A.mois - 1][0].toUpperCase() + MOIS[A.mois - 1].slice(1)} ${A.annee}</span>
      <button type="button" class="btn btn--icon btn--sm" data-act="a-pas" data-v="1" aria-label="Mois suivant"${dernier ? ' disabled' : ''}>${SVG(ICO.d)}</button><span class="pcl-ici">${dernier ? 'mois en cours, jusqu’au ' + auj.getDate() : ''}</span></div>`;
    if (A.err && !d) { return barre + `<div class="pcl-vide error card">${esc(A.err)}</div>`; }
    if (!d || d.annee !== A.annee || d.mois !== A.mois) { return barre + attente('Lecture de l’analyse B2B…'); }
    if (!d.kpi) { return barre + refus(d.panel || {}, 'Le tableau de bord B2B'); }
    const mo = MC[(A.mois + 10) % 12] + (d.memesJours ? ' (1–' + d.memesJours + ')' : ''), an = MC[A.mois - 1] + '. ' + (A.annee - 1);
    const fl = (x, pts) => x == null ? '<span class="muted">—</span>' : `<span class="${x >= 0 ? 'pcl-up' : 'pcl-down'}">${x >= 0 ? '▲ +' : '▼ '}${nf(x, 1).replace(',0', '')}${pts ? ' pt' : ' %'}</span>`;
    const K = d.kpi, carte = (k, l, v, fort) => `<div class="card pcl-k6${fort ? ' fort' : ''}"><div class="l">${l}</div><div class="v">${v}</div><div class="t">${fl(K[k].mom, k === 'part')} vs ${mo}${fl(K[k].yoy, k === 'part')} vs ${an}</div></div>`;
    const v = (k, f) => K[k].v == null ? '—' : f(K[k].v);
    const kpis = `<div class="pcl-kpi6">${carte('ventes', 'Ventes B2B', v('ventes', fE0), true)}${carte('tickets', 'Tickets B2B', v('tickets', x => nf(x)))}${carte('panier', 'Panier moyen B2B', v('panier', fE))}
      ${carte('clients', 'Clients B2B uniques', v('clients', x => nf(x)))}${carte('part', 'Part B2B du CA', v('part', x => nf(x, 1) + ' %'))}${carte('factures', 'Factures B2B', v('factures', x => nf(x)) + (K.factures.ca ? ' · ' + fE0(K.factures.ca) : ''))}</div>`;
    // Le graphique se relit à chaque mois et chaque métrique (le panel gardait les données du premier affichage).
    const m = A.metrique, G = d.graphique || [], max = Math.max(1, ...G.map(x => Math.max(x[m][0] || 0, x[m][1] || 0)));
    const barres = `<div class="pcl-barres">${G.map(x => `<div class="m${x.mois === A.mois ? ' is-mois' : ''}" title="${MOIS[x.mois - 1]} : ${A.annee} ${x[m][0] == null ? '—' : nf(x[m][0], m === 'part' ? 1 : 0)} · ${A.annee - 1} ${x[m][1] == null ? '—' : nf(x[m][1], m === 'part' ? 1 : 0)}">
      <i class="a" style="height:${100 * (x[m][0] || 0) / max}%"></i><i class="p" style="height:${100 * (x[m][1] || 0) / max}%"></i></div>`).join('')}</div><div class="pcl-mois12">${MC.map(x => `<span>${x}</span>`).join('')}</div>`;
    const part = K.part.v, ca = d.ca;
    const donut = part == null ? '<div class="pcl-aide">La part du chiffre ne se calcule pas pour ce mois.</div>' : `<svg viewBox="0 0 120 120" class="pcl-donut"><circle cx="60" cy="60" r="44" fill="none" stroke="#EDE7DE" stroke-width="18"/><circle cx="60" cy="60" r="44" fill="none" stroke="#8D1D2C" stroke-width="18" stroke-dasharray="${2 * Math.PI * 44 * Math.min(100, part) / 100} 999" transform="rotate(-90 60 60)"/><text x="60" y="65" text-anchor="middle" font-family="Gotham, system-ui" font-size="16" fill="#1c1a17">${nf(part, 1)} %</text></svg>
      ${K.ventes.v != null ? ligneF('B2B', fE0(K.ventes.v)) : ''}${ca != null && K.ventes.v != null ? ligneF('Le reste du chiffre', fE0(ca - K.ventes.v)) : ''}`;
    const notes = (d.source === 'releve' ? note(esc(d.texte) + (d.memesJours ? ` Le mois en cours se compare aux ${d.memesJours} premiers jours du mois d’avant et de ${MOIS[A.mois - 1]} ${A.annee - 1}.` : '') + (d.couverture && d.couverture.lus < d.couverture.jours ? ` <b>${d.couverture.lus} jours lus sur ${d.couverture.jours}</b> : les autres seront relus par le relevé de nuit.` : '') + (d.panel ? '<br>' + esc(d.panel.texte) + (!d.panel.magasin || d.panel.magasinExpire ? '<div style="margin-top:8px">' + tfbBouton() + '</div>' : '') : ''), 'pcl-note-doux') : '');
    return barre + notes + kpis + `<div class="pcl-an2"><section class="card"><h2 class="pcl-h2">Ventes B2B, mois par mois<small>${seg('a-metrique', m, [['ventes', 'Ventes'], ['tickets', 'Tickets'], ['clients', 'Clients'], ['part', 'Part du CA']])}</small></h2>${barres}
      <div class="pcl-leg"><span><i style="background:var(--accent)"></i>${A.annee}</span><span><i style="background:#EBD3D8"></i>${A.annee - 1}</span></div></section>
      <section class="card"><h2 class="pcl-h2">Part B2B du chiffre</h2>${donut}<div class="pcl-aide" style="margin-top:8px">Un pro = une fiche avec une TVA valide, comme la jauge B2B de l’Opérationnel.</div></section></div>`;
  }

  /* ── Les fenêtres ─────────────────────────────────────────────────────────────────────────────────────────── */
  function fenCourante() {
    const m = st.mo;
    if (!m) { return ''; }
    const pied = (lib, act, danger) => `<button type="button" class="btn btn--ghost" data-act="fermer">Annuler</button><button type="button" class="btn ${danger ? 'confirm__danger' : 'btn--primary'}${m.busy ? ' is-busy' : ''}" data-act="${act}"${m.busy ? ' disabled' : ''}>${lib}</button>`;
    const tvaBloc = `<div class="pcl-ligne-in plein">${champ('tax_number', 'Numéro de TVA', { requis: true, aide: 'BE0123456789' })}<button type="button" class="btn btn--sm" data-act="tva">Vérifier</button></div>
      ${m.tva ? `<p class="${m.tva.trouve ? 'pcl-ok' : 'error'} plein">${m.tva.trouve ? 'Trouvée' + (m.tva.source ? ' (' + esc(m.tva.source) + ')' : '') + (m.tva.existe ? ' · déjà cliente dans TF Buddy' + (m.tva.rattache ? ', déjà rattachée à ce magasin' : ' : elle sera rattachée à ce magasin') : '') : 'Introuvable : vérifiez le numéro.'}</p>` : ''}`;
    if (m.type === 'p-form') {
      const c = `<div class="pcl-form2">${champ('name', 'Prénom')}${champ('surname', 'Nom', { requis: true })}${champ('phone', 'Téléphone', { requis: true, type: 'tel' })}${champ('email', 'E-mail', { type: 'email' })}
        ${champ('street', 'Rue')}${champ('street_number', 'Numéro')}${champ('zip', 'Code postal')}${champ('city', 'Ville')}${champ('comment', 'Note', { plein: true })}</div>`;
      return fenetre(m.id ? 'Modifier le client' : 'Ajouter un client', c, pied(m.id ? 'Enregistrer' : 'Ajouter', 'p-ok'));
    }
    if (m.type === 'p-entreprise') {
      return fenetre('Passer en entreprise', `<div class="pcl-form2">${tvaBloc}${champ('company_name', 'Société', { requis: true, plein: true })}${champ('street', 'Rue')}${champ('street_number', 'Numéro')}${champ('zip', 'Code postal')}${champ('city', 'Ville')}
        ${champ('differe', 'Paiement différé', { type: 'case' })}${champ('delai', 'Délai de paiement (jours)', { type: 'number' })}${champ('remise', 'Remise personnelle (%)', { type: 'number' })}</div>
        <p class="pcl-aide">Comme au panel, la fiche devient une entreprise : le nom de la personne s’efface au profit de la société.</p>`, pied('Passer en entreprise', 'p-entreprise-ok'));
    }
    if (m.type === 'p-supprimer') {
      return fenetre('Supprimer le client', `<p>${esc(m.nom)} sera supprimé de TF Buddy. Ses tickets et ses commandes restent.</p>`, pied('Supprimer', 'p-supprimer-ok', true));
    }
    if (m.type === 'e-ajouter') {
      return fenetre('Ajouter une entreprise', `<div class="pcl-form2">${tvaBloc}${champ('company_name', 'Société', { plein: true })}${champ('street', 'Rue')}${champ('street_number', 'Numéro')}${champ('zip', 'Code postal')}${champ('city', 'Ville')}
        ${champ('phone', 'Téléphone', { type: 'tel' })}${champ('email', 'E-mail', { type: 'email' })}</div><p class="pcl-aide">TF Buddy complète la société depuis VIES ; une entreprise déjà cliente d’un autre magasin est rattachée à celui-ci.</p>`, pied('Ajouter', 'e-ajouter-ok'));
    }
    if (m.type === 'e-base') {
      return fenetre('Données de base', `<div class="pcl-form2">${champ('company_name', 'Société', { plein: true })}${champ('tax_number', 'TVA')}${champ('peppol_identifier', 'Identifiant Peppol')}
        ${champ('street', 'Rue')}${champ('street_number', 'Numéro')}${champ('zip', 'Code postal')}${champ('city', 'Ville')}${champ('phone', 'Téléphone', { type: 'tel' })}${champ('email', 'E-mail', { type: 'email' })}${champ('comment', 'Note', { plein: true })}</div>`, pied('Enregistrer', 'e-base-ok'));
    }
    if (m.type === 'e-profil') {
      return fenetre('Profil commercial', `<div class="pcl-form2">${champ('score', 'Score', { choix: [['', '—'], ['A', 'A'], ['B', 'B'], ['C', 'C'], ['D', 'D']] })}${champ('type', 'Type de client', { choix: [['', '—'], ...TYPES] })}
        ${champ('mode', 'Mode de service', { choix: [['', '—'], ...MODES] })}<span></span>${champ('creditActif', 'Crédit accordé', { type: 'case' })}${champ('creditLimite', 'Limite de crédit (€)', { type: 'number' })}</div>`, pied('Enregistrer', 'e-profil-ok'));
    }
    if (m.type === 'b-livraison') {
      const o = st.b.options || {}, R = [['', 'Aucune tournée'], ...(o.routes || []).map(r => [r.id, r.name])];
      return fenetre('Livraison du bureau', `<div class="pcl-form2">${champ('route_id', 'Tournée', { choix: R, plein: true })}${champ('delivery_window_start', 'Créneau : de', { type: 'time' })}${champ('delivery_window_end', 'à', { type: 'time' })}
        ${champ('cutoff_time', 'Commandes jusqu’à', { type: 'time' })}${champ('cutoff_days_before', 'Le', { choix: [['0', 'jour même'], ['1', 'la veille'], ['2', '2 jours avant'], ['3', '3 jours avant']] })}
        ${champ('drop_minutes', 'Remise sur place', { choix: [['5', '5 min'], ['10', '10 min'], ['15', '15 min']] })}</div><p class="pcl-aide">Ce que la page Commandes et l’appli du livreur lisent.</p>`, pied('Enregistrer', 'b-livraison-ok'));
    }
    return '';
  }

  /* ── Le rendu et les gestes ───────────────────────────────────────────────────────────────────────────────── */
  function rendre() {
    if (!st.el) { return; }
    const actif = document.activeElement, id = actif && st.el.contains(actif) && actif.id ? actif.id : null, pos = id && actif.selectionStart != null ? actif.selectionStart : null;
    const corps = st.onglet === 'b2c' ? vueParticuliers() : st.onglet === 'b2b' ? vueEntreprises() : st.onglet === 'bur' ? vueBureaux() : vueAnalyse();
    st.el.innerHTML = tete() + corps + fenCourante();
    if (id) { const i = st.el.querySelector('#' + id); if (i) { i.focus(); if (pos != null && i.setSelectionRange) { try { i.setSelectionRange(pos, pos); } catch (e) { /* champ sans curseur */ } } } }
  }
  const ouvrir = (type, f, plus) => { st.mo = Object.assign({ type, f: Object.assign({}, f || {}), err: null, busy: false }, plus || {}); rendre(); };
  const vide = o => { const r = {}; Object.keys(o).forEach(k => { r[k] = o[k] == null ? '' : o[k]; }); return r; };
  function ecrire(corps, apres) {
    const m = st.mo; m.busy = true; m.err = null; rendre();
    return envoyer('/dashboard/clients/client', corps).then(d => { st.mo = null; return apres ? apres(d) : null; }).catch(e => { if (st.mo) { m.busy = false; m.err = e.message; } rendre(); });
  }
  function verifierTva() {
    const m = st.mo, tva = String(m.f.tax_number || '').trim();
    if (!tva) { m.err = 'Donnez un numéro de TVA.'; rendre(); return; }
    m.busy = true; m.err = null; m.tva = null; rendre();
    envoyer('/dashboard/clients/tva', { tva }).then(d => {
      m.tva = d; const s = d.societe || {};
      ['company_name', 'street', 'street_number', 'zip', 'city', 'phone', 'email'].forEach(k => { if (s[k] && !m.f[k]) { m.f[k] = s[k]; } });
      if (s.tax_number) { m.f.tax_number = s.tax_number; }
    }).catch(e => { m.err = e.message; }).finally(() => { m.busy = false; rendre(); });
  }
  function telechargerPdf(type) {
    const B = st.b, b = B.d.data.bureaux.find(x => x.id === B.sel);
    B.pdf = { texte: 'TF Buddy fabrique le document…' }; rendre();
    const corps = type === 'poster' ? {} : type === 'cards' ? { request_key: (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) } : { catalog: true, offer_ids: [] };
    tfb().then(h => fetch(st.api + '/dashboard/clients/bureaux/pdf', { method: 'POST', credentials: 'same-origin', headers: Object.assign({ 'Content-Type': 'application/json' }, h), body: JSON.stringify({ shop: st.shop, bureau: B.sel, type, corps }) }))
      .then(r => { const t = r.headers.get('Content-Type') || ''; if (r.ok && t.includes('pdf')) { return r.blob(); } return r.json().catch(() => ({})).then(j => { throw new Error(j.error || 'erreur ' + r.status); }); })
      .then(bl => { const u = URL.createObjectURL(bl), a = document.createElement('a'); a.href = u; a.download = 'bureau-' + (b ? b.nom.replace(/[^\w-]+/g, '-') : B.sel) + '-' + type + '.pdf'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(u), 4000); B.pdf = { texte: 'Document téléchargé.' }; })
      .catch(e => { B.pdf = { err: e.message }; }).finally(rendre);
  }
  function ecrireBureau(methode, chemin, corps, ok) {
    return envoyer('/dashboard/clients/bureaux', { methode, chemin, corps }).then(d => { ok && ok(d); }).catch(e => { if (st.mo) { st.mo.busy = false; st.mo.err = e.message; } else { st.b.pdf = { err: e.message }; } rendre(); });
  }
  function geste(ev) {
    const a = ev.target.closest('[data-act]');
    if (!a || !st.el.contains(a)) { return; }
    const act = a.dataset.act, id = a.dataset.id ? +a.dataset.id : null, v = a.dataset.v;
    if (act === 'fermer-fond') { if (ev.target === a) { st.mo = null; rendre(); } return; }
    if (a.tagName === 'TR' && ev.target.closest('button')) { return; }
    ev.preventDefault();
    const P = st.p, E = st.e, B = st.b, A = st.a, m = st.mo;
    switch (act) {
      case 'fermer': st.mo = null; rendre(); break;
      case 'onglet': st.onglet = v; memo('pcl-onglet:' + st.shop, v); st.mo = null; if (v === 'b2c' && !P.d) { lireParticuliers(); } else if (v === 'b2b' && !E.d) { charger(); } else if (v === 'bur' && !B.d) { lireBureaux(); } else if (v === 'an' && !A.d) { lireAnalyse(); } else { rendre(); } break;
      case 'aller-offres': if (st.aller) { st.aller('offres'); } break;
      case 'tfb-connecter': if (window.DashTfb) { window.DashTfb.ouvrir(st.shop, st.api, toutRelire); } break;
      case 'tfb-deconnecter': if (window.DashTfb) { window.DashTfb.deconnecter(st.shop, st.api).then(toutRelire); } break;
      case 'relire': charger(true); break;
      // Particuliers
      case 'p-filtre': P.filtre = v; lireParticuliers(); break;
      case 'p-sel': lireFiche(P, id); break;
      case 'p-offre': { const c = (P.fiche && P.fiche.id === id ? { id, nom: P.fiche.client.nom, ville: P.fiche.client.city } : (P.d.clients.find(x => x.id === id) || {})); offre(Object.assign({ b2b: false }, c, { id })); break; }
      case 'p-ajouter': ouvrir('p-form', { name: '', surname: '', phone: '', email: '', street: '', street_number: '', zip: '', city: '', comment: '' }); break;
      case 'p-modifier': { const c = P.fiche.client; ouvrir('p-form', vide({ name: c.name, surname: c.surname, phone: c.phone, email: c.email, street: c.street, street_number: c.street_number, zip: c.zip, city: c.city, comment: c.comment }), { id: P.sel }); break; }
      case 'p-ok': ecrire(m.id ? { action: 'modifier', id: m.id, champs: m.f } : { action: 'creer', champs: m.f }, d => { const nid = m.id || d.id; P.q = m.f.surname || ''; return lireParticuliers(true).then(() => nid ? lireFiche(P, nid) : null); }); break;
      case 'p-entreprise': { const c = P.fiche.client; ouvrir('p-entreprise', vide({ tax_number: '', company_name: '', street: c.street, street_number: c.street_number, zip: c.zip, city: c.city, differe: false, delai: '', remise: c.personal_discount_percent }), { id: P.sel }); break; }
      case 'p-entreprise-ok': ecrire({ action: 'entreprise', id: m.id, champs: m.f }, () => { P.sel = null; P.fiche = null; E.d = null; st.onglet = 'b2b'; memo('pcl-onglet:' + st.shop, 'b2b'); lireParticuliers(true); return lireEntreprises(true).then(() => lireFiche(E, m.id)); }); break;
      case 'p-supprimer': ouvrir('p-supprimer', {}, { id: P.sel, nom: P.fiche.client.nom }); break;
      case 'p-supprimer-ok': ecrire({ action: 'supprimer', id: m.id }, () => { P.sel = null; P.fiche = null; return lireParticuliers(true); }); break;
      case 'tva': verifierTva(); break;
      // Entreprises
      case 'e-incompl': E.incompl = !E.incompl; rendre(); break;
      case 'e-score': E.score = v; rendre(); break;
      case 'e-ca': E.ca = v; rendre(); break;
      case 'e-freq': E.freq = v; rendre(); break;
      case 'e-sel': lireFiche(E, id); break;
      case 'e-offre': { const c = E.d.entreprises.find(x => x.id === id) || {}; offre({ id, nom: c.nom || (E.fiche && E.fiche.client.nom), b2b: true, ville: c.ville }); break; }
      case 'e-ajouter': ouvrir('e-ajouter', { tax_number: '', company_name: '', street: '', street_number: '', zip: '', city: '', phone: '', email: '' }); break;
      case 'e-ajouter-ok': ecrire({ action: 'creer', entreprise: true, champs: m.f }, d => lireEntreprises(true).then(() => d.id ? lireFiche(E, d.id) : null)); break;
      case 'e-base': { const c = E.fiche.client; ouvrir('e-base', vide({ company_name: c.company_name, tax_number: c.tax_number, peppol_identifier: c.peppol_identifier, street: c.street, street_number: c.street_number, zip: c.zip, city: c.city, phone: c.phone, email: c.email, comment: c.comment }), { id: E.sel }); break; }
      case 'e-base-ok': ecrire({ action: 'modifier', id: m.id, champs: m.f }, () => lireEntreprises(true).then(() => lireFiche(E, m.id))); break;
      case 'e-profil': { const p = E.fiche.profil || {}; ouvrir('e-profil', { score: p.score || '', type: p.type || '', mode: p.mode || '', creditActif: !!p.creditActif, creditLimite: p.creditLimite == null ? '' : p.creditLimite }, { id: E.sel }); break; }
      case 'e-profil-ok': ecrire({ action: 'profil', id: m.id, champs: m.f }, () => lireEntreprises(true).then(() => lireFiche(E, m.id))); break;
      // Bureaux
      case 'b-statut': B.statut = v; rendre(); break;
      case 'b-sel': B.sel = id; B.pdf = null; rendre(); if (window.innerWidth <= 1100) { const f = st.el.querySelector('.pcl-fiche'); if (f && f.scrollIntoView) { f.scrollIntoView({ behavior: 'smooth', block: 'start' }); } } break;
      case 'b-ong': B.ong = v; B.pdf = null; rendre(); break;
      case 'b-livraison': { const s = (detDe('/delivery-settings') || {}).data || {}; lireOptions().then(() => ouvrir('b-livraison', { route_id: s.route && s.route.id || s.route_id || '', delivery_window_start: String(s.delivery_window_start || '').slice(0, 5), delivery_window_end: String(s.delivery_window_end || '').slice(0, 5),
        cutoff_time: String(s.cutoff_time || '').slice(0, 5), cutoff_days_before: String(s.cutoff_days_before || 0), drop_minutes: String(s.drop_minutes || 5) }, { id: B.sel })); break; }
      case 'b-livraison-ok': { const f = m.f; m.busy = true; m.err = null; rendre();
        ecrireBureau('PUT', '/' + m.id + '/delivery-settings', { route_id: f.route_id ? Number(f.route_id) : null, delivery_window_start: f.delivery_window_start || null, delivery_window_end: f.delivery_window_end || null,
          cutoff_time: f.cutoff_time || null, cutoff_days_before: Number(f.cutoff_days_before), drop_minutes: Number(f.drop_minutes) }, () => { st.mo = null; delete B.det[B.sel + '/delivery-settings']; rendre(); }); break; }
      case 'b-inviter': { const i = st.el.querySelector('#pcl-inv'), mail = i ? i.value.trim() : ''; if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(mail)) { B.pdf = { err: 'Une adresse e-mail valide est attendue.' }; rendre(); break; }
        ecrireBureau('POST', '/' + B.sel + '/members/invite', { email: mail, locale: 'fr' }, () => { B.pdf = { texte: 'Invitation envoyée à ' + mail + '.' }; delete B.det[B.sel + '/invite']; delete B.det[B.sel + '/members']; rendre(); }); break; }
      case 'b-lien': ecrireBureau('POST', '/' + B.sel + '/invite', {}, () => { delete B.det[B.sel + '/invite']; rendre(); }); break;
      case 'b-lien-retirer': if (window.confirm('Retirer le lien d’invitation ? Les personnes déjà inscrites le restent.')) { ecrireBureau('DELETE', '/' + B.sel + '/invite', null, () => { delete B.det[B.sel + '/invite']; rendre(); }); } break;
      case 'b-pdf': telechargerPdf(v); break;
      // L'assistant
      case 'w-ouvrir': B.w = nouvelAssistant({}); rendre(); lireOptions().then(o => { if (B.w) { B.w.opt = o; rendre(); } }); break;
      case 'w-fermer': B.w = null; rendre(); break;
      case 'w-retour': B.w.etape--; B.w.err = null; rendre(); window.scrollTo(0, 0); break;
      case 'w-aller': if (+v <= B.w.etape) { B.w.etape = +v; B.w.err = null; rendre(); } break;
      case 'w-suivant': { const e = assistantValide(B.w); B.w.err = e; if (!e) { B.w.etape++; window.scrollTo(0, 0); } rendre(); break; }
      case 'w-creer': creerBureau(); break;
      case 'w-jour': { const n = +v, J = B.w.jours; B.w.jours = J.includes(n) ? J.filter(x => x !== n) : J.concat(n).sort(); rendre(); break; }
      case 'w-site-plus': B.w.sites.push({ name: '', address: '', postal_code: '', city: '', building: '', floor: '', country: 'BE', route_id: '', access_minutes: '', notes: '', delivery_site_id: null }); rendre(); break;
      case 'w-site-retirer': B.w.sites.splice(+a.dataset.i, 1); rendre(); break;
      case 'w-site-existant': { const s = (B.w.opt.delivery_sites || []).find(x => String(x.id) === String(B.w.f.site_existant)); if (s) { const vide0 = B.w.sites.length === 1 && !B.w.sites[0].address; const n = { name: s.name || '', address: s.address || '', postal_code: s.postal_code || '', city: s.city || '', building: '', floor: '', country: s.country || 'BE', route_id: s.route_id || '', access_minutes: s.access_minutes == null ? '' : s.access_minutes, notes: '', delivery_site_id: +s.id }; if (vide0) { B.w.sites[0] = n; } else { B.w.sites.push(n); } rendre(); } break; }
      case 'w-dep-pre': { const i = B.w.deps.findIndex(d => d.name === v); if (i >= 0) { B.w.deps.splice(i, 1); } else { B.w.deps.push({ name: v, headcount: null }); } rendre(); break; }
      case 'w-dep': { const f = B.w.f; if (f.dep_nom.trim()) { B.w.deps.push({ name: f.dep_nom.trim(), headcount: f.dep_n === '' ? null : Number(f.dep_n) }); f.dep_nom = ''; f.dep_n = ''; rendre(); } break; }
      case 'w-dep-retirer': B.w.deps.splice(+a.dataset.i, 1); rendre(); break;
      case 'w-bon': { const n = Number(B.w.f.voucher); if (n && !B.w.vouchers.includes(n)) { B.w.vouchers.push(n); rendre(); } break; }
      case 'w-bon-retirer': B.w.vouchers.splice(+a.dataset.i, 1); rendre(); break;
      case 'w-pers': { const f = B.w.f; if (f.st_nom.trim() && /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(f.st_mail.trim())) { B.w.staff.push({ name: f.st_nom.trim(), email: f.st_mail.trim(), client_id: null }); f.st_nom = ''; f.st_mail = ''; B.w.err = null; } else { B.w.err = 'Un nom et un e-mail valide.'; } rendre(); break; }
      case 'w-pers-retirer': B.w.staff.splice(+a.dataset.i, 1); rendre(); break;
      case 'w-csv': { const L = String(B.w.f.csv || '').split(/\r?\n/).map(l => l.trim()).filter(Boolean).map(l => l.split(/[;,]/).map(x => x.trim().replace(/^"|"$/g, '')));
        const bad = L.filter(x => x.length !== 2 || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(x[1]));
        if (bad.length) { B.w.err = bad.length + ' ligne(s) illisible(s) : « nom;email » attendu.'; } else { L.forEach(([n, e]) => { if (!B.w.staff.some(p => p.email.toLowerCase() === e.toLowerCase())) { B.w.staff.push({ name: n, email: e, client_id: null }); } }); B.w.f.csv = ''; B.w.err = null; }
        rendre(); break; }
      // Analyse
      case 'a-pas': { const d = new Date(A.annee, A.mois - 1 + (+v), 1), auj = new Date(); if (d > auj) { break; } A.annee = d.getFullYear(); A.mois = d.getMonth() + 1; A.d = null; lireAnalyse(); break; }
      case 'a-metrique': A.metrique = v; rendre(); break;
    }
  }
  function majResume() { const r = st.el.querySelector('#pcl-ass-resume'); if (r && st.b.w) { r.outerHTML = resumeAssistant(); } }
  function saisie(ev) {
    const t = ev.target;
    if (t.id === 'pcl-pq') { st.p.q = t.value; clearTimeout(tRech); tRech = setTimeout(() => lireParticuliers(), 300); return; }
    if (t.id === 'pcl-eq') { st.e.q = t.value; rendre(); return; }
    if (t.id === 'pcl-bq') { st.b.q = t.value; rendre(); return; }
    if (!t.dataset || !t.dataset.f) { return; }
    const val = t.type === 'checkbox' ? t.checked : t.value;
    const ou = t.dataset.ou;
    if (ou && ou[0] === 's' && st.b.w) { const s = st.b.w.sites[+ou.slice(1)]; if (s) { s[t.dataset.f] = val; } majResume(); return; }
    if (st.onglet === 'bur' && st.b.w && !st.mo) { st.b.w.f[t.dataset.f] = val; majResume(); return; }
    if (st.mo) { st.mo.f[t.dataset.f] = val; }
  }
  document.addEventListener('keydown', ev => { if (ev.key === 'Escape' && st.mo && st.el && document.body.contains(st.el)) { st.mo = null; rendre(); } });

  window.DashClients = {
    racine(shop, o) {
      o = o || {};
      st.aller = o.aller || st.aller; st.nomShop = o.nomShop || st.nomShop; st.api = o.apiBase || st.api;
      if (!st.el || st.shop !== String(shop)) {
        const auj = new Date();
        Object.assign(st, { shop: String(shop), mo: null });
        st.p = { q: '', filtre: '', d: null, err: null, lecture: false, sel: null, fiche: null, ficheErr: null };
        st.e = { d: null, err: null, lecture: false, q: '', score: '', ca: '', freq: '', incompl: false, sel: null, fiche: null, ficheErr: null };
        st.b = { d: null, err: null, lecture: false, q: '', statut: '', sel: null, ong: 'livraison', det: {}, options: null, w: null, pdf: null };
        st.a = { annee: auj.getFullYear(), mois: auj.getMonth() + 1, d: null, err: null, lecture: false, metrique: 'ventes' };
        const on = o.onglet || memo('pcl-onglet:' + st.shop);
        st.onglet = ['b2c', 'b2b', 'bur', 'an'].includes(on) ? on : 'b2b';
        st.el = document.createElement('div'); st.el.className = 'pcl';
        st.el.addEventListener('click', geste); st.el.addEventListener('input', saisie); st.el.addEventListener('change', saisie);
        rendre(); charger();
      } else { rendre(); }
      return st.el;
    },
    relire() { if (st.el) { charger(true); } },
  };
})();
