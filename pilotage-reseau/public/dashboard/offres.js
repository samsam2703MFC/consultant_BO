/* Dashboard magasin › Vendre › Offres (15/11/2026, maquette docs/maquettes/rail-franchise/offres.png).
 *
 * Les offres de prix du magasin à ses clients, telles que TF Buddy les garde, lues et écrites par son API « Client
 * offers » et par elle seule (« only api ») : le serveur du cockpit relaie (src/offres_client.php), le navigateur ne voit
 * jamais le jeton de l'ERP.
 *   la liste, les onglets et les chiffres   GET  …/exploitation/offres-client?shop=
 *   nouvelle offre, modifier, archiver,     POST …/exploitation/offres-client {shop, action, …}
 *   supprimer, convertir en commande
 *   le PDF (celui de TF Buddy)              GET  …/exploitation/offres-client/pdf?shop=&id=&lang=
 *   le client de l'offre                    GET  …/exploitation/promos-magasin/clients?shop=&type=&q= (le même que le bon)
 *   les produits du magasin et leur coût    GET  …/exploitation/promos-magasin/produits?shop=   (la marge de l'offre)
 *   qui prépare la commande                 GET  …/exploitation/offres-client/employes?shop=
 *   l'onglet Newsletter                     …/dashboard/marketing/newsletter* (src/franchise/offres_newsletter.php) : le
 *                                           module newsletter du cockpit au rôle du magasin, affiché par son écran
 *                                           (../assets/js/newsletter.js) — mode test tant que la marque n'envoie pas
 * L'aperçu suit le gabarit A4 version A du design system (§13.4) ; le PDF officiel est celui que TF Buddy rend.
 * La mise en page suit le geste (15/11/2026) : on consulte → « mes offres » sur 2/3, l'offre choisie sur 1/3 ; on fait
 * une offre → le formulaire sur 2/3, la liste sur 1/3. Chaque offre dit sa marge, ligne par ligne (ci-dessous).
 *
 * Une page autonome, sans compilation : `window.DashOffres.racine(shop, {apiBase, nomShop, onCompte, aller})` rend l'élément
 * du module, gardé d'un rendu du dashboard à l'autre (son état et ses champs ne se perdent pas).
 * La boîte mail (15/11/2026) : `nouvelle(brouillon)` ouvre une offre pré-remplie depuis un e-mail (le client, le jour, les
 * produits repérés — à vérifier) ; une fois créée, la réponse à l'e-mail part avec son PDF (`aller('boite', …)`) ;
 * `voir(id)` ouvre une offre citée dans un e-mail ; « Envoyer par e-mail » écrit au client, l'offre jointe.
 */
(function () {
  'use strict';

  const ETATS = { cours: 'en cours', conv: 'convertie', arch: 'archivée' };
  const ONGLETS = [['cours', 'En cours'], ['conv', 'Converties'], ['arch', 'Archivées'], ['tout', 'Toutes'], ['news', 'Newsletter']];
  const MOL = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const nf2 = new Intl.NumberFormat('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const eur = v => (v == null || isNaN(v)) ? '—' : nf2.format(v).replace(/[  ]/g, ' ') + ' €';
  const nq = v => (v == null || isNaN(v)) ? '' : (Math.round(v) === v ? String(v) : String(v).replace('.', ','));
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const auj = () => { const t = new Date(); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0'); };
  const dFr = s => /^\d{4}-\d{2}-\d{2}/.test(s || '') ? s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4) : (s || '—');
  const dCourt = s => /^\d{4}-\d{2}-\d{2}/.test(s || '') ? s.slice(8, 10) + '/' + s.slice(5, 7) : (s || '—');
  const hCourt = s => /\d{2}:\d{2}/.test(s || '') ? s.match(/(\d{2}:\d{2})/)[1] : '';
  const lireNombre = t => { const s = String(t == null ? '' : t).trim().replace(/[\s  €]/g, '').replace(',', '.'); if (s === '') { return null; } const v = parseFloat(s); return isNaN(v) ? undefined : v; };
  const ico = d => `<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"></path></svg>`;
  const I = { plus: 'M12 5v14M5 12h14', pdf: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M12 18v-6M9 15l3 3 3-3', panier: 'M6 6h15l-1.5 9h-12zM6 6L5 3H2M9 20a1 1 0 1 0 0-.01M18 20a1 1 0 1 0 0-.01', chercher: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM21 21l-5.2-5.2' };

  const st = {
    shop: null, api: '../api/cockpit', nomShop: '', el: null, onCompte: null,
    liste: null, n: null, lu: null, err: null, charge: false,
    onglet: 'cours', q: '', sel: null,
    mode: null,           // null | 'edit' | 'conv' | 'suppr'
    form: null,           // le brouillon de l'offre (nouvelle ou modifiée)
    conv: null,           // {retrait, employe}
    msg: null,            // {ok, texte}
    occupe: false,
    produits: null, prodErr: null, employes: null, empErr: null,
    seuils: { ko: 1.67, att: 2.5, food: 40 },   // ceux de « Recette & marge », relus avec les produits
    cli: { type: 'particulier', q: '', l: null, err: null, enCours: false },
    nl: null,             // l'hôte de l'écran newsletter, gardé d'un rendu à l'autre (sa saisie ne se perd pas)
    aller: null,          // le dashboard : aller('boite', intention)
    source: null,         // l'e-mail d'où vient l'offre en cours d'écriture {dossier, uid, de}
  };

  /* --- lectures et écritures ------------------------------------------------------------------------------------ */
  const url = (p, prm) => st.api + p + '?' + new URLSearchParams(Object.assign({ shop: st.shop }, prm || {})).toString();
  function lire(p, prm) {
    return fetch(url(p, prm), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.json().catch(() => ({})).then(j => r.ok ? j : Promise.reject(new Error((j && (j.error || j.message)) || ('HTTP ' + r.status)))));
  }
  function ecrire(corps) {
    return fetch(st.api + '/exploitation/offres-client', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ shop: st.shop }, corps)) })
      .then(r => r.json().catch(() => ({})).then(j => r.ok ? j : Promise.reject(new Error((j && (j.error || j.message)) || ('HTTP ' + r.status)))));
  }
  function charger(frais) {
    st.charge = true; st.err = null; rendre();
    lire('/exploitation/offres-client', frais ? { frais: 1 } : null).then(d => {
      st.liste = d.offres || []; st.n = d.n || null; st.lu = d.lu || null;
      if (st.sel != null && !st.liste.some(o => o.id === st.sel)) { st.sel = null; }
      if (st.sel == null) { const v = visibles(); st.sel = v.length ? v[0].id : null; }
      if (st.onCompte) { st.onCompte(st.n ? st.n.cours : null); }
    }).catch(e => { st.err = e.message; }).finally(() => { st.charge = false; rendre(); });
  }
  function chargerProduits() {
    if (st.produits || st.prodErr === '…') { return; }
    st.prodErr = '…';
    lire('/exploitation/promos-magasin/produits').then(d => { st.produits = (d.produits || []).filter(p => p.id > 0).sort((a, b) => a.nom.localeCompare(b.nom, 'fr')); st.prodErr = null; if (d.seuils) { st.seuils = d.seuils; } })
      .catch(e => { st.prodErr = e.message; }).finally(rendre);
  }
  function chargerEmployes() {
    if (st.employes || st.empErr === '…') { return; }
    st.empErr = '…';
    lire('/exploitation/offres-client/employes').then(d => { st.employes = d.employes || []; st.empErr = null; })
      .catch(e => { st.empErr = e.message; }).finally(rendre);
  }
  let tCli = null;
  function chercherClients() {
    clearTimeout(tCli);
    const c = st.cli;
    if (c.type !== 'b2b' && c.q.trim().length < 2) { c.l = null; c.err = null; rendre(); return; }
    tCli = setTimeout(() => {
      c.enCours = true; rendre();
      const q = c.q.trim();
      lire('/exploitation/promos-magasin/clients', { type: c.type, q }).then(d => { if (c.q.trim() === q) { c.l = d.clients || []; c.err = null; } })
        .catch(e => { c.err = e.message; }).finally(() => { c.enCours = false; rendre(); });
    }, 300);
  }

  /* --- ce qui se voit ------------------------------------------------------------------------------------------- */
  const offre = id => (st.liste || []).find(o => o.id === id) || null;
  function visibles() {
    const q = st.q.trim().toLowerCase();
    return (st.liste || []).filter(o => (st.onglet === 'tout' || o.etat === st.onglet) && (!q
      || [String(o.id), o.nom, o.description, o.client ? o.client.nom : '', ...(o.lignes || []).map(l => l.nom)].join(' ').toLowerCase().includes(q)));
  }
  const lieu = o => o.retrait ? 'retrait en magasin' : 'livraison' + (o.livraison && (o.livraison.cp || o.livraison.ville) ? ' · ' + [o.livraison.cp, o.livraison.ville].filter(Boolean).join(' ') : '');
  const etat = o => `<span class="of-etat ${o.etat}">${ETATS[o.etat] || o.etat}</span>`;

  function kpis() {
    const L = st.liste || [];
    const som = e => L.filter(o => o.etat === e).reduce((t, o) => t + (o.total || 0), 0);
    const nb = e => L.filter(o => o.etat === e).length;
    const faites = nb('conv') + nb('cours') + nb('arch');
    const prochain = L.filter(o => o.etat === 'cours' && /^\d{4}-\d{2}-\d{2}$/.test(o.jour) && o.jour >= auj()).sort((a, b) => a.jour.localeCompare(b.jour))[0];
    return `<div class="of-kpis">
      <div><span class="k">En cours</span><span class="v">${nb('cours')} · ${eur(som('cours'))}</span><span class="s">offres envoyées ou à envoyer</span></div>
      <div><span class="k">Converties en commande</span><span class="v">${nb('conv')} · ${eur(som('conv'))}</span><span class="s">dans la liste de TF Buddy</span></div>
      <div><span class="k">Taux de conversion</span><span class="v">${faites ? Math.round(100 * nb('conv') / faites) + ' %' : '—'}</span><span class="s">${nb('conv')} sur ${faites} offre${faites > 1 ? 's' : ''}</span></div>
      <div><span class="k">Prochain événement</span><span class="v">${prochain ? dCourt(prochain.jour) : '—'}</span><span class="s">${prochain ? 'offre n° ' + prochain.id + ' · ' + esc(prochain.nom) : 'aucune offre en cours datée'}</span></div></div>`;
  }

  function liste() {
    const v = visibles();
    const lignes = v.map(o => `<tr data-ofsel="${o.id}"${o.id === st.sel ? ' class="on"' : ''}><td class="n">${o.id}</td>
        <td>${esc(o.nom || 'Offre sans nom')}<small>${o.client ? esc(o.client.nom) + (o.client.b2b ? ' · B2B' : '') : 'sans client'}${o.cree ? ' · créée le ' + dCourt(o.cree) : ''}</small></td>
        <td class="c-ev">${o.jour ? dCourt(o.jour) : '—'}<small>${esc(lieu(o))}</small></td><td class="n">${eur(o.total)}</td><td>${etat(o)}</td></tr>`).join('');
    return `<div class="of-g"><div class="of-outils"><label class="of-q">${ico(I.chercher)}<input id="of-q" type="search" placeholder="Chercher une offre, un client, un produit…" value="${esc(st.q)}"></label></div>
      <table class="of-t"><thead><tr><th>N°</th><th>Offre · client</th><th class="c-ev">Événement</th><th class="n">Total TTC</th><th>État</th></tr></thead><tbody>
      ${lignes || `<tr><td colspan="5" class="of-vide">${st.q ? 'Aucune offre ne répond à cette recherche.' : 'Aucune offre ' + ({ cours: 'en cours', conv: 'convertie', arch: 'archivée', tout: '' }[st.onglet]) + '.'}</td></tr>`}
      </tbody></table><div class="of-pied">${(st.liste || []).length} offre${(st.liste || []).length > 1 ? 's' : ''} dans TF Buddy${st.lu ? ' · lues à ' + st.lu : ''} · <button type="button" class="of-lien" data-ofrelire="1">relire</button></div></div>`;
  }

  /* --- la marge de l'offre (15/11/2026, « dans les offres je dois avoir le calcul de la marge de l'offre ») --------
   * Ligne par ligne : ce que le client paie HT (le total de la ligne, remise déduite, sans la TVA) moins le coût de
   * recette de la pièce × la quantité — le coût du jour que le panel calcule pour ce magasin, sinon celui du réseau,
   * le même que Produits › Recette & marge et l'assistant des promotions. Les frais de livraison n'y entrent pas. Les
   * couleurs sont celles de « Recette & marge » : rouge sous × 1,67 (marge brute < 40 %), orange sous × 2,5 (< 60 %),
   * vert au-delà ; l'objectif du réseau est une matière à 32 % du prix HT. */
  const produitDe = id => (st.produits || []).find(p => p.id === id) || null;
  const pc1 = v => v == null || isNaN(v) ? '—' : String(Math.round(v * 10) / 10).replace('.', ',') + ' %';
  function margeLigne(idProduit, qte, totalTtc, totalHt, tvaLigne) {
    const p = produitDe(idProduit);
    const tva = tvaLigne != null ? tvaLigne : (p && p.tva != null ? p.tva : null);
    const ht = totalHt != null ? totalHt : (totalTtc != null && tva != null ? totalTtc / (1 + tva / 100) : null);
    const cout = p && p.cout != null && qte > 0 ? qte * p.cout : null;
    const marge = ht != null && cout != null ? ht - cout : null;
    return { ht, cout, marge, pct: marge != null && ht > 0 ? 100 * marge / ht : null, reseau: !!(p && p.source === 'reseau' && cout != null) };
  }
  function margeOffre(ms) {
    const ok = ms.filter(m => m.marge != null), som = (L, k) => L.reduce((s, m) => s + m[k], 0);
    const ht = som(ok, 'ht'), cout = som(ok, 'cout'), marge = ht - cout;
    return { ht, cout, marge, pct: ht > 0 ? 100 * marge / ht : null, food: ht > 0 ? 100 * cout / ht : null, coef: cout > 0 ? ht / cout : null,
      n: ms.length, nOk: ok.length, nReseau: ok.filter(m => m.reseau).length };
  }
  const zoneMarge = coef => coef == null ? 'mu' : coef < st.seuils.ko ? 'ko' : coef < st.seuils.att ? 'att' : 'ok';
  const ZONES_MARGE = { ok: 'marge brute de 60 % ou plus', att: 'marge brute entre 40 et 60 %', ko: 'marge brute sous 40 %', mu: 'pas de coût de recette' };
  /** Le bloc « Marge de l'offre » : prix HT, coût matière, marge brute, et l'écart à l'objectif du réseau. */
  function margeBloc(M, lignesHtml) {
    if (!st.produits) {
      return `<div class="of-marge of-marge--mu"><span class="of-eyebrow">Marge de l’offre</span><p class="of-mu">${st.prodErr && st.prodErr !== '…' ? 'Les coûts de recette ne se lisent pas : ' + esc(st.prodErr) : 'Lecture des coûts de recette du magasin…'}</p></div>`;
    }
    if (!M.n) { return ''; }
    const z = zoneMarge(M.coef), food = st.seuils.food;
    const objectif = M.nOk && M.food != null && M.food > food + 0.05 ? M.cout * 100 / food : null;
    const notes = [];
    if (M.nOk < M.n) { notes.push(`${M.n - M.nOk} ligne${M.n - M.nOk > 1 ? 's' : ''} sans coût de recette au panel : hors du calcul`); }
    if (M.nReseau) { notes.push(`${M.nReseau} coût${M.nReseau > 1 ? 's' : ''} du réseau (le panel n’a pas encore calculé celui du magasin)`); }
    notes.push('livraison hors marge');
    return `<div class="of-marge of-marge--${z}">
      <div class="of-marge-h"><span class="of-eyebrow">Marge de l’offre</span>${M.nOk ? `<span class="of-marge-z">${ZONES_MARGE[z]}</span>` : ''}</div>
      ${M.nOk ? `<div class="of-marge-k">
        <div><span class="k">Prix HT</span><span class="v">${eur(M.ht)}</span><span class="s">payé par le client, remises déduites</span></div>
        <div><span class="k">Coût matière</span><span class="v">${eur(M.cout)}</span><span class="s">${pc1(M.food)} du prix HT</span></div>
        <div class="du"><span class="k">Marge brute</span><span class="v">${eur(M.marge)}</span><span class="s">${pc1(M.pct)} · coefficient × ${M.coef != null ? String(Math.round(M.coef * 100) / 100).replace('.', ',') : '—'}</span></div></div>
        <p class="of-marge-o">${objectif != null ? `Objectif du réseau, matière à ${pc1(food)} : il faudrait ${eur(objectif)} HT, soit ${eur(objectif - M.ht)} de plus.` : `Dans l’objectif du réseau : matière à ${pc1(food)} du prix HT au plus.`}</p>`
        : '<p class="of-mu">Aucun produit de l’offre n’a de coût de recette au panel : la marge ne se calcule pas.</p>'}
      ${lignesHtml || ''}
      <p class="of-marge-n">${notes.join(' · ')}.</p></div>`;
  }
  /** La marge d'une offre de TF Buddy, ligne par ligne (le détail, à droite de la liste). */
  function margeDetail(o) {
    chargerProduits();
    const L = (o.lignes || []).map(l => Object.assign({ nom: l.nom }, margeLigne(l.idProduit, l.qte, l.total, l.totalNet, l.tva)));
    if (!st.produits) { return margeBloc(null); }
    const M = margeOffre(L);
    const t = L.length ? `<table class="of-marge-t"><thead><tr><th>Produit</th><th class="n">HT</th><th class="n">Coût</th><th class="n">Marge</th></tr></thead><tbody>
      ${L.map(m => `<tr><td>${esc(m.nom)}${m.reseau ? ' <small>coût réseau</small>' : ''}</td><td class="n">${eur(m.ht)}</td><td class="n">${m.cout != null ? eur(m.cout) : '<span class="of-mu">—</span>'}</td>
        <td class="n">${m.marge != null ? `<span class="of-mz ${zoneMarge(m.cout > 0 ? m.ht / m.cout : null)}">${pc1(m.pct)}</span>` : '<span class="of-mu">sans coût</span>'}</td></tr>`).join('')}</tbody></table>` : '';
    return margeBloc(M, t);
  }

  /* L'aperçu, au gabarit A4 version A (§13.4) : les champs de l'offre, rien d'autre. */
  function apercu(o) {
    const c = o.client;
    const ht = o.totalNet, tva = ht != null ? o.totalProduits - ht : null;
    const refs = [['Date', dFr(o.cree)], ['Jour de l’événement', o.jour ? dFr(o.jour) : '—'],
      [o.retrait ? 'Retrait' : 'Livraison', o.retrait ? 'en magasin' : [o.livraison.rue, [o.livraison.cp, o.livraison.ville].filter(Boolean).join(' ')].filter(Boolean).join(', ') || '—'],
      ['Conditions', o.conditions || '—']];
    return `<div class="doc-a4">
      <div class="doc__head"><img class="doc__logo" src="../assets/img/logo.png" alt="L’Atelier By"><div class="doc__type"><b>Offre de prix</b>N° ${o.id} · ${dFr(o.cree)}</div></div>
      <div class="doc__parties"><div><div class="doc__label">Émetteur</div><p><b>${esc(st.nomShop || 'L’Atelier By')}</b></p></div>
        <div><div class="doc__label">Destinataire</div>${c ? `<p><b>${esc(c.nom)}</b></p>${c.contact ? `<p>${esc(c.contact)}</p>` : ''}${c.rue || c.cp || c.ville ? `<p>${esc([c.rue, [c.cp, c.ville].filter(Boolean).join(' ')].filter(Boolean).join(', '))}</p>` : ''}${c.tva ? `<p>TVA ${esc(c.tva)}</p>` : ''}` : '<p class="doc__manque">pas de client sur l’offre</p>'}</div></div>
      <div class="doc__refs">${refs.map(([k, v]) => `<div><div class="doc__label">${k}</div><div class="v">${esc(v)}</div></div>`).join('')}</div>
      ${o.description ? `<div class="doc__objet"><div class="doc__label">Objet</div><p>${esc(o.description)}</p></div>` : ''}
      <table><thead><tr><th>Désignation</th><th class="num">Qté</th><th class="num">PU TTC</th><th class="num">TVA</th><th class="num">Total TTC</th></tr></thead><tbody>
        ${(o.lignes || []).map(l => `<tr><td><b>${esc(l.nom)}</b>${l.remiseMontant > 0 ? `<span class="sub">remise ${l.remiseValeur != null && l.remiseType !== 2 ? nq(l.remiseValeur) + ' % · ' : ''}${eur(l.remiseMontant)}</span>` : ''}</td><td class="num">${nq(l.qte)}</td><td class="num">${eur(l.pu)}</td><td class="num">${l.tva != null ? nq(l.tva) + ' %' : ''}</td><td class="num">${eur(l.total)}</td></tr>`).join('') || '<tr><td colspan="5" class="doc__manque">pas de produit sur l’offre</td></tr>'}
        ${!o.retrait && o.livraison.frais > 0 ? `<tr><td><b>Livraison</b></td><td class="num">1</td><td class="num">${eur(o.livraison.frais)}</td><td class="num"></td><td class="num">${eur(o.livraison.frais)}</td></tr>` : ''}
      </tbody></table>
      <table class="doc__totals"><tbody>
        ${ht != null ? `<tr><td>Total HT des produits</td><td class="num">${eur(ht)}</td></tr><tr><td>TVA</td><td class="num">${eur(tva)}</td></tr>` : ''}
        ${!o.retrait && o.livraison.frais > 0 ? `<tr><td>Livraison</td><td class="num">${eur(o.livraison.frais)}</td></tr>` : ''}
        <tr class="is-due"><td>Total TTC</td><td class="num">${eur(o.total)}</td></tr></tbody></table>
      <div class="doc__box"><div class="doc__label">Bon pour accord</div><div class="doc__sign"><div>Date</div><div>Nom</div><div>Signature</div></div></div>
      <div class="doc__foot"><span>Aperçu à l’écran. Le document officiel est le PDF que TF Buddy rend (bouton PDF).</span><span>Page 1 / 1</span></div>
    </div>`;
  }

  function detail(o) {
    if (!o) { return `<div class="of-d of-d--vide">${st.liste && st.liste.length ? 'Choisissez une offre dans la liste.' : 'Pas encore d’offre : « Nouvelle offre » en crée une dans TF Buddy.'}</div>`; }
    const conv = o.etat === 'conv', pdfOk = !!o.client && (o.lignes || []).length > 0;
    const pdf = l => `${url('/exploitation/offres-client/pdf', { id: o.id, lang: l })}`;
    const dis = st.occupe ? ' disabled' : '';
    return `<div class="of-d">
      <div class="of-d-h"><div><span class="of-eyebrow">Offre n° ${o.id} · ${etat(o)}</span><h2>${esc(o.nom || 'Offre sans nom')}</h2>
        <p>${o.client ? esc(o.client.nom) + (o.client.b2b ? ' · B2B' : '') : 'sans client'}${o.cree ? ' · créée le ' + dFr(o.cree) + (hCourt(o.cree) ? ' à ' + hCourt(o.cree) : '') : ''}${o.client && o.client.email ? ' · ' + esc(o.client.email) : ''}${o.client && o.client.tel ? ' · ' + esc(o.client.tel) : ''}</p></div></div>
      <div class="of-gestes">
        <button type="button" class="db-btn" data-ofgeste="modifier"${conv ? ' disabled title="Une offre convertie en commande ne se modifie plus."' : dis}>Modifier</button>
        ${pdfOk ? `<span class="of-pdf">${ico(I.pdf)}PDF <a href="${pdf('fr')}" target="_blank" rel="noopener">FR</a><a href="${pdf('nl')}" target="_blank" rel="noopener">NL</a><a href="${pdf('en')}" target="_blank" rel="noopener">EN</a></span>`
          : `<button type="button" class="db-btn" disabled title="TF Buddy ne rend le PDF qu’avec un client et au moins un produit.">${ico(I.pdf)}PDF</button>`}
        ${st.aller && o.client && o.client.email && pdfOk ? `<button type="button" class="db-btn" data-ofmail="1">Envoyer par e-mail</button>` : ''}
        <button type="button" class="db-btn" data-ofgeste="convertir"${conv || !(o.lignes || []).length ? ' disabled' : dis}>${ico(I.panier)}Convertir en commande</button>
        ${o.etat === 'arch' ? `<button type="button" class="db-btn" data-ofgeste="reactiver"${dis}>Remettre en cours</button>` : `<button type="button" class="db-btn" data-ofgeste="archiver"${dis}>Archiver</button>`}
        <button type="button" class="db-btn" data-ofgeste="supprimer"${conv ? ' disabled title="Une offre convertie en commande s’archive, elle ne se supprime pas."' : dis}>Supprimer</button>
      </div>
      ${!pdfOk ? `<div class="of-note">${!o.client ? 'Pas de client sur l’offre : TF Buddy ne rend pas de PDF sans client. « Modifier » permet d’en choisir un.' : 'Pas de produit sur l’offre : ajoutez-en un avec « Modifier ».'}</div>` : ''}
      ${st.mode === 'conv' ? convForm(o) : ''}${st.mode === 'suppr' ? supprForm(o) : ''}
      ${(o.lignes || []).length ? `<div class="of-marge-p">${margeDetail(o)}</div>` : ''}
      <div class="of-apercu"><div class="of-apercu-l">Aperçu · gabarit A4</div><div class="of-apercu-c">${apercu(o)}</div></div>
    </div>`;
  }

  function convForm(o) {
    chargerEmployes();
    const c = st.conv;
    const emps = st.employes ? st.employes.map(e => `<option value="${e.id}"${String(e.id) === String(c.employe) ? ' selected' : ''}>${esc(e.nom)}</option>`).join('') : '';
    return `<section class="of-panneau"><h3>Convertir l’offre n° ${o.id} en commande</h3>
      <p>La commande se crée dans TF Buddy avec les produits de l’offre ; l’offre passe en « convertie ».</p>
      <div class="of-ch2"><label>Retrait ou départ de la livraison<input class="of-in" type="datetime-local" id="of-conv-quand" value="${esc(c.retrait)}"></label>
        <label>Préparée par${st.employes ? `<select class="of-in" id="of-conv-emp"><option value="">choisir…</option>${emps}</select>` : `<span class="of-in of-in--ro">${st.empErr && st.empErr !== '…' ? esc(st.empErr) : 'lecture de l’équipe…'}</span>`}</label></div>
      <div class="of-actions"><button type="button" class="db-btn" data-ofannuler="1">Annuler</button><button type="button" class="db-btn prim" data-ofconvertir="1"${st.occupe ? ' disabled' : ''}>${ico(I.panier)}Convertir en commande</button></div></section>`;
  }
  function supprForm(o) {
    return `<section class="of-panneau of-panneau--stop"><h3>Supprimer l’offre n° ${o.id} ?</h3><p>Elle disparaît de TF Buddy avec ses lignes. Pour la garder sans la voir, archivez-la plutôt.</p>
      <div class="of-actions"><button type="button" class="db-btn" data-ofannuler="1">Annuler</button><button type="button" class="db-btn of-stop" data-ofsupprimer="1"${st.occupe ? ' disabled' : ''}>Supprimer</button></div></section>`;
  }

  /* --- le formulaire : les champs de OfferInput ----------------------------------------------------------------- */
  function brouillon(o) {
    if (!o) {
      return { id: null, nom: '', description: '', client: null, jour: '', retrait: true, livraison: { rue: '', cp: '', ville: '', frais: '' }, conditions: '',
        lignes: [], lignesDepart: '[]' };
    }
    const lignes = (o.lignes || []).map(l => ({ idProduit: l.idProduit, nom: l.nom, qte: l.qte, pu: l.pu, remiseValeur: l.remiseValeur, remiseMontant: l.remiseMontant, remiseType: l.remiseType }));
    return { id: o.id, nom: o.nom, description: o.description, client: o.client ? { id: o.client.id, nom: o.client.nom, b2b: o.client.b2b, ville: o.client.ville } : null,
      jour: /^\d{4}-\d{2}-\d{2}$/.test(o.jour) ? o.jour : '', retrait: o.retrait,
      livraison: { rue: o.livraison.rue || '', cp: o.livraison.cp || '', ville: o.livraison.ville || '', frais: o.livraison.frais ? String(o.livraison.frais).replace('.', ',') : '' },
      conditions: o.conditions, lignes, lignesDepart: JSON.stringify(lignes) };
  }
  /** Une ligne : la remise d'origine suit la quantité quand c'est un pourcentage. */
  function totalLigne(l) {
    const q = lireNombre(l.qte), pu = lireNombre(l.pu);
    if (q == null || q === undefined || pu == null || pu === undefined) { return null; }
    let rm = l.remiseMontant || 0;
    if (rm > 0 && l.remiseValeur > 0 && l.remiseType !== 2) { rm = Math.round(q * pu * l.remiseValeur) / 100; }
    return { brut: q * pu, rm, total: Math.round((q * pu - rm) * 100) / 100 };
  }
  function formulaire() {
    chargerProduits();
    const f = st.form, nouveau = f.id == null;
    const ms = f.lignes.map(l => { const t = totalLigne(l); return margeLigne(l.idProduit, lireNombre(l.qte), t ? t.total : null, null, null); });
    const L = f.lignes.map((l, i) => { const t = totalLigne(l), m = ms[i]; return `<tr><td><input class="of-in" list="of-produits" data-ofl="${i}" data-ofc="nom" value="${esc(l.nom)}" placeholder="produit du catalogue du magasin">${l.idProduit ? '' : '<small class="of-ko">choisissez un produit de la liste</small>'}</td>
      <td class="n"><input class="of-in of-in--n" data-ofl="${i}" data-ofc="qte" value="${esc(nq(l.qte))}" inputmode="decimal"></td>
      <td class="n"><input class="of-in of-in--n" data-ofl="${i}" data-ofc="pu" value="${esc(l.pu == null ? '' : String(l.pu).replace('.', ','))}" inputmode="decimal"></td>
      <td class="n">${t && t.rm > 0 ? eur(t.rm) : '—'}</td><td class="n">${t ? eur(t.total) : '—'}</td>
      <td class="n">${m.marge != null ? `${eur(m.marge)}<small class="of-mz ${zoneMarge(m.ht / m.cout)}">${pc1(m.pct)}</small>` : `<span class="of-mu">${l.idProduit && st.produits ? 'sans coût' : '—'}</span>`}</td>
      <td><button type="button" class="of-x" data-oflx="${i}" title="Retirer la ligne" aria-label="Retirer la ligne">×</button></td></tr>`; }).join('');
    const totP = f.lignes.reduce((s, l) => s + ((totalLigne(l) || {}).total || 0), 0);
    const frais = f.retrait ? 0 : (lireNombre(f.livraison.frais) || 0);
    const c = st.cli;
    const res = c.l ? c.l.map(x => `<button type="button" class="of-cli" data-ofcli="${x.id}"><span>${esc(x.nom)}${x.b2b ? ' · B2B' : ''}</span><small>${esc([x.ville, x.tva ? 'TVA ' + x.tva : '', x.contact].filter(Boolean).join(' · '))}</small></button>`).join('') : '';
    return `<section class="of-d of-f"><div class="of-d-h"><div><span class="of-eyebrow">${nouveau ? 'Nouvelle offre · TF Buddy' : 'Offre n° ' + f.id + ' · modifier'}</span><h2>${nouveau ? 'Nouvelle offre' : esc(f.nom || 'Offre sans nom')}</h2></div></div>
      <div class="of-form">
        <div class="of-ch2">
          <label class="large">Nom de l’offre<input class="of-in" data-off="nom" value="${esc(f.nom)}" placeholder="ex. Anniversaire 21/11"></label>
          <div class="large of-client"><span class="of-lab">Client <small>(TF Buddy ne rend pas de PDF sans client)</small></span>
            ${f.client ? `<div class="of-cli-choisi"><span>${esc(f.client.nom)}${f.client.b2b ? ' · B2B' : ''}${f.client.ville ? ' · ' + esc(f.client.ville) : ''}</span><button type="button" class="of-lien" data-ofclix="1">changer</button></div>`
              : `<div class="of-cli-q"><span class="seg"><button type="button" data-oftype="particulier" class="${c.type === 'particulier' ? 'is-on' : ''}">Particulier</button><button type="button" data-oftype="b2b" class="${c.type === 'b2b' ? 'is-on' : ''}">B2B</button></span>
                <input class="of-in" id="of-cli-q" type="search" value="${esc(c.q)}" placeholder="${c.type === 'b2b' ? 'société, TVA… (vide : toutes)' : 'nom, e-mail, téléphone, ville…'}"></div>
                <div class="of-cli-l">${c.enCours ? '<span class="of-mu">lecture…</span>' : ''}${c.err ? `<span class="of-ko">${esc(c.err)}</span>` : ''}${res}${c.l && !c.l.length ? '<span class="of-mu">aucun client trouvé dans TF Buddy</span>' : ''}</div>`}</div>
          <label>Jour de l’événement<input class="of-in" type="date" data-off="jour" value="${esc(f.jour)}"></label>
          <div><span class="of-lab">Retrait ou livraison</span><span class="seg"><button type="button" data-ofretrait="1" class="${f.retrait ? 'is-on' : ''}">Retrait en magasin</button><button type="button" data-ofretrait="0" class="${f.retrait ? '' : 'is-on'}">Livraison</button></span></div>
          ${f.retrait ? '' : `<label class="large">Adresse de livraison<input class="of-in" data-ofliv="rue" value="${esc(f.livraison.rue)}"></label>
            <label>Code postal<input class="of-in" data-ofliv="cp" value="${esc(f.livraison.cp)}"></label><label>Ville<input class="of-in" data-ofliv="ville" value="${esc(f.livraison.ville)}"></label>
            <label>Frais de livraison (€ TTC)<input class="of-in" data-ofliv="frais" value="${esc(f.livraison.frais)}" inputmode="decimal"></label>`}
          <label class="large">Conditions<input class="of-in" data-off="conditions" value="${esc(f.conditions)}" placeholder="ex. acompte de 30 % · offre valable jusqu’au 20/11"></label>
          <label class="large">Description<textarea class="of-in" data-off="description" rows="2" placeholder="ce que le client demande">${esc(f.description)}</textarea></label>
        </div>
        <datalist id="of-produits">${(st.produits || []).map(p => `<option value="${esc(p.nom)}"></option>`).join('')}</datalist>
        <table class="of-lignes"><thead><tr><th>Produit du catalogue</th><th class="n">Quantité</th><th class="n">Prix TTC</th><th class="n">Remise</th><th class="n">Total TTC</th><th class="n">Marge HT</th><th></th></tr></thead><tbody>
          ${L || '<tr><td colspan="7" class="of-mu">Pas encore de produit.</td></tr>'}
          <tr><td colspan="7"><button type="button" class="of-lien" data-ofajout="1">${ico(I.plus)} Ajouter un produit</button>${st.prodErr && st.prodErr !== '…' ? ` <span class="of-ko">les produits ne se lisent pas : ${esc(st.prodErr)}</span>` : (!st.produits ? ' <span class="of-mu">lecture du catalogue…</span>' : '')}</td></tr></tbody></table>
        <div class="of-tot"><span class="k">Produits</span><span>${eur(totP)}</span>${f.retrait ? '' : `<span class="k">Livraison</span><span>${eur(frais)}</span>`}<span class="k du">Total TTC</span><span class="du">${eur(totP + frais)}</span></div>
        ${f.lignes.some(l => l.idProduit) ? margeBloc(margeOffre(ms.filter((m, i) => f.lignes[i].idProduit))) : ''}
        <div class="of-actions"><button type="button" class="db-btn" data-ofannuler="1">Annuler</button><button type="button" class="db-btn prim" data-ofenregistrer="1"${st.occupe ? ' disabled' : ''}>${nouveau ? 'Créer l’offre dans TF Buddy' : 'Enregistrer l’offre'}</button></div>
      </div></section>`;
  }

  function html() {
    const n = st.n || {};
    const edition = st.mode === 'edit' && st.form;
    const news = st.onglet === 'news';
    const enTete = `<div class="of-onglets"><div class="page-tabs">${ONGLETS.map(([k, l]) => `<button type="button" class="page-tabs__tab${st.onglet === k ? ' is-on' : ''}" data-ofong="${k}">${l}${st.liste && k !== 'news' ? ` <span class="page-tabs__count">${n[k] != null ? n[k] : 0}</span>` : ''}</button>`).join('')}</div>
      <span style="flex:1"></span>${news ? '' : `<button type="button" class="db-btn${st.mode ? '' : ' prim'}" data-ofnouvelle="1">${ico(I.plus)}Nouvelle offre</button>`}</div>`;
    // La newsletter : l'écran du module, monté dans sa place après le rendu (monterNewsletter).
    if (news) { return enTete + '<div class="of-nl-place"></div>'; }
    const msg = st.msg ? `<div class="${st.msg.ok ? 'of-ok' : 'db-err'}">${esc(st.msg.texte)}${st.msg.repondre ? ` <button type="button" class="db-btn prim of-msg-b" data-ofrepondre="1">Répondre à l’e-mail avec l’offre n° ${st.msg.repondre.offre} (PDF)</button>` : ''}</div>` : '';
    // Une offre en cours d'écriture (née d'un e-mail, par exemple) s'affiche même si la liste n'est pas encore lue.
    if (st.err && !st.liste && !edition) { return enTete + msg + `<div class="db-err">Les offres ne se lisent pas : ${esc(st.err)}</div>`; }
    if (!st.liste && !edition) { return enTete + '<div class="of-charge"><div class="db-sk" style="height:60px;margin-bottom:12px"></div><div class="db-sk" style="height:320px"></div></div>'; }
    // On consulte : « mes offres » sur 2/3. On fait une offre : le formulaire sur 2/3, la liste se resserre sur 1/3.
    return enTete + msg + kpis() + `<div class="of${edition ? ' of--edition' : ''}">${liste()}${edition ? formulaire() : detail(offre(st.sel))}</div>`;
  }

  /* --- les gestes ----------------------------------------------------------------------------------------------- */
  function finir(p, texte, apres) {
    st.occupe = true; st.msg = null; rendre();
    return p.then(r => { st.msg = { ok: true, texte }; if (apres) { apres(r); } charger(true); })
      .catch(e => { st.msg = { ok: false, texte: e.message }; })
      .finally(() => { st.occupe = false; rendre(); });
  }
  function enregistrer() {
    const f = st.form;
    const lignes = f.lignes.map(l => { const t = totalLigne(l); return { idProduit: l.idProduit, nom: l.nom, qte: lireNombre(l.qte), pu: lireNombre(l.pu), remiseValeur: l.remiseValeur, remiseMontant: t ? t.rm : 0, remiseType: l.remiseType }; });
    if (!f.nom.trim()) { st.msg = { ok: false, texte: 'Donnez un nom à l’offre.' }; return rendre(); }
    if (!lignes.length || lignes.some(l => !l.idProduit || !(l.qte > 0) || l.pu == null || l.pu === undefined || l.pu < 0)) { st.msg = { ok: false, texte: 'Chaque ligne demande un produit du catalogue, une quantité et un prix.' }; return rendre(); }
    const corps = { nom: f.nom, description: f.description, conditions: f.conditions, jour: f.jour, retrait: f.retrait,
      livraison: { rue: f.livraison.rue, cp: f.livraison.cp, ville: f.livraison.ville, frais: lireNombre(f.livraison.frais) || 0 } };
    if (f.client) { corps.clientId = f.client.id; }
    // Une modification ne renvoie les produits que s'ils ont changé : TF Buddy remplace alors toutes les lignes.
    const depart = JSON.stringify(f.lignes);
    if (f.id == null || depart !== f.lignesDepart) { corps.lignes = lignes; }
    const nouveau = f.id == null;
    finir(ecrire(nouveau ? { action: 'creer', offre: corps } : { action: 'modifier', id: f.id, offre: corps }),
      nouveau ? 'Offre créée dans TF Buddy.' : 'Offre enregistrée dans TF Buddy.',
      r => {
        st.mode = null; st.form = null;
        if (r && r.id) { st.sel = r.id; st.onglet = 'cours'; }
        // Née d'un e-mail : la réponse, l'offre en PDF, est à un geste.
        if (r && r.id && st.source && st.aller) { const s = st.source; setTimeout(() => { st.msg = { ok: true, texte: 'Offre n° ' + r.id + ' créée dans TF Buddy.', repondre: { dossier: s.dossier, uid: s.uid, offre: r.id } }; rendre(); }, 0); }
        st.source = null;
      });
  }

  function brancher() {
    const el = st.el;
    const on = (sel, ev, fn) => el.querySelectorAll(sel).forEach(n => n.addEventListener(ev, fn));
    on('[data-ofong]', 'click', e => { st.onglet = e.currentTarget.dataset.ofong; if (st.onglet === 'news') { st.msg = null; return rendre(); } const v = visibles(); if (!v.some(o => o.id === st.sel)) { st.sel = v.length ? v[0].id : null; } if (st.mode !== 'edit') { st.mode = null; } rendre(); });
    on('[data-ofsel]', 'click', e => { if (st.mode === 'edit') { return; } st.sel = +e.currentTarget.dataset.ofsel; st.mode = null; st.msg = null; rendre(); });
    on('[data-ofrelire]', 'click', () => charger(true));
    on('[data-ofrepondre]', 'click', () => { const r = st.msg && st.msg.repondre; if (r && st.aller) { st.msg = null; st.aller('boite', { repondre: r }); } });
    on('[data-ofmail]', 'click', () => { const o = offre(st.sel); if (o && o.client && st.aller) { st.aller('boite', { ecrire: { a: o.client.email, nom: o.client.contact || o.client.nom, objet: 'Notre offre n° ' + o.id, offre: o.id } }); } });
    on('[data-ofnouvelle]', 'click', () => { st.form = brouillon(null); st.mode = 'edit'; st.msg = null; st.cli = { type: 'particulier', q: '', l: null, err: null, enCours: false }; rendre(); });
    on('[data-ofannuler]', 'click', () => { st.mode = null; st.form = null; st.conv = null; st.source = null; rendre(); });
    on('[data-ofgeste]', 'click', e => {
      const g = e.currentTarget.dataset.ofgeste, o = offre(st.sel);
      if (!o) { return; }
      st.msg = null;
      if (g === 'modifier') { st.form = brouillon(o); st.mode = 'edit'; st.cli = { type: o.client && o.client.b2b ? 'b2b' : 'particulier', q: '', l: null, err: null, enCours: false }; return rendre(); }
      if (g === 'convertir') { st.mode = 'conv'; st.conv = { retrait: (/^\d{4}-\d{2}-\d{2}$/.test(o.jour) ? o.jour : auj()) + 'T10:00', employe: '' }; return rendre(); }
      if (g === 'supprimer') { st.mode = 'suppr'; return rendre(); }
      if (g === 'archiver' || g === 'reactiver') { finir(ecrire({ action: g, id: o.id }), g === 'archiver' ? 'Offre n° ' + o.id + ' archivée.' : 'Offre n° ' + o.id + ' remise en cours.'); }
    });
    on('[data-ofsupprimer]', 'click', () => { const o = offre(st.sel); if (o) { finir(ecrire({ action: 'supprimer', id: o.id }), 'Offre n° ' + o.id + ' supprimée de TF Buddy.', () => { st.mode = null; st.sel = null; }); } });
    on('[data-ofconvertir]', 'click', () => {
      const o = offre(st.sel), q = el.querySelector('#of-conv-quand'), em = el.querySelector('#of-conv-emp');
      st.conv.retrait = q ? q.value : st.conv.retrait; st.conv.employe = em ? em.value : st.conv.employe;
      if (!st.conv.retrait) { st.msg = { ok: false, texte: 'La date et l’heure de retrait sont obligatoires.' }; return rendre(); }
      if (!st.conv.employe) { st.msg = { ok: false, texte: 'Choisissez qui prépare la commande.' }; return rendre(); }
      if (o) { finir(ecrire({ action: 'convertir', id: o.id, retrait: st.conv.retrait, employe: +st.conv.employe }), 'Offre n° ' + o.id + ' convertie en commande dans TF Buddy.', () => { st.mode = null; st.conv = null; }); }
    });
    const cq = el.querySelector('#of-conv-quand'); if (cq) { cq.addEventListener('change', () => { st.conv.retrait = cq.value; }); }
    const ce = el.querySelector('#of-conv-emp'); if (ce) { ce.addEventListener('change', () => { st.conv.employe = ce.value; }); }
    const q = el.querySelector('#of-q');
    if (q) { q.addEventListener('input', () => { st.q = q.value; const p = q.selectionStart; rendre(); const q2 = st.el.querySelector('#of-q'); if (q2) { q2.focus(); q2.setSelectionRange(p, p); } }); }
    if (st.mode !== 'edit' || !st.form) { return; }
    const f = st.form;
    on('[data-off]', 'input', e => { f[e.currentTarget.dataset.off] = e.currentTarget.value; });
    on('[data-ofliv]', 'input', e => { f.livraison[e.currentTarget.dataset.ofliv] = e.currentTarget.value; });
    on('[data-ofliv="frais"]', 'change', rendre);
    on('[data-ofretrait]', 'click', e => { f.retrait = e.currentTarget.dataset.ofretrait === '1'; rendre(); });
    on('[data-ofl]', 'change', e => {
      const i = +e.currentTarget.dataset.ofl, c = e.currentTarget.dataset.ofc, v = e.currentTarget.value, l = f.lignes[i];
      if (c === 'nom') {
        const p = (st.produits || []).find(x => x.nom === v);
        l.nom = v; l.idProduit = p ? p.id : 0;
        if (p && (l.pu == null || l.pu === '') && p.prixTtc != null) { l.pu = p.prixTtc; }
        if (p && p.prixTtc != null && l.pu !== p.prixTtc && !l.puTouche) { l.pu = p.prixTtc; }
        l.remiseMontant = 0; l.remiseValeur = null; l.remiseType = null;
      } else { l[c] = v; if (c === 'pu') { l.puTouche = true; } }
      rendre();
    });
    on('[data-oflx]', 'click', e => { f.lignes.splice(+e.currentTarget.dataset.oflx, 1); rendre(); });
    on('[data-ofajout]', 'click', () => { f.lignes.push({ idProduit: 0, nom: '', qte: 1, pu: null, remiseValeur: null, remiseMontant: 0, remiseType: null }); rendre(); const n = st.el.querySelectorAll('[data-ofc="nom"]'); if (n.length) { n[n.length - 1].focus(); } });
    on('[data-oftype]', 'click', e => { st.cli.type = e.currentTarget.dataset.oftype; st.cli.l = null; chercherClients(); rendre(); });
    const cliq = el.querySelector('#of-cli-q');
    if (cliq) { cliq.addEventListener('input', () => { st.cli.q = cliq.value; const p = cliq.selectionStart; chercherClients(); const c2 = st.el.querySelector('#of-cli-q'); if (c2 && c2 !== cliq) { c2.focus(); c2.setSelectionRange(p, p); } }); }
    on('[data-ofcli]', 'click', e => { const x = (st.cli.l || []).find(c => String(c.id) === e.currentTarget.dataset.ofcli); if (x) { f.client = { id: x.id, nom: x.nom, b2b: x.b2b, ville: x.ville }; rendre(); } });
    on('[data-ofclix]', 'click', () => { f.client = null; st.cli.q = ''; st.cli.l = null; if (st.cli.type === 'b2b') { chercherClients(); } rendre(); });
    on('[data-ofenregistrer]', 'click', enregistrer);
  }

  /** L'onglet Newsletter : l'écran du module newsletter du cockpit, au rôle du magasin, sur les routes du dashboard. */
  function monterNewsletter() {
    const place = st.el && st.el.querySelector('.of-nl-place');
    if (!place) { return; }
    if (!window.CockpitNewsletter) { place.innerHTML = '<div class="db-err">L’écran de la newsletter n’est pas chargé.</div>'; return; }
    const neuf = !st.nl;
    if (neuf) { st.nl = document.createElement('div'); st.nl.className = 'of-nl'; }
    place.appendChild(st.nl);
    if (neuf) {
      // apiBase …/api/cockpit/dashboard : l'écran appelle « /marketing/newsletter… », servi par /dashboard/marketing/newsletter….
      window.CockpitNewsletter.mount(st.nl, { role: st.shop, apiBase: st.api + '/dashboard', imgDir: '../assets/img/newsletter/' });
    }
  }

  /** L'aperçu A4 se réduit à la largeur de sa colonne (1/3 quand on consulte). */
  function echelle() {
    const c = st.el && st.el.querySelector('.of-apercu-c');
    if (!c || !c.parentElement.clientWidth) { return; }
    const k = Math.max(0.3, Math.min(0.64, (c.parentElement.clientWidth - 28) / 794));
    c.style.width = Math.round(794 * k) + 'px'; c.style.height = Math.round(1123 * k) + 'px';
    const d = c.querySelector('.doc-a4'); if (d) { d.style.transform = 'scale(' + k + ')'; }
  }
  window.addEventListener('resize', echelle);

  function rendre() {
    if (!st.el) { return; }
    // Le champ qui a la main la garde (une lecture qui arrive ne doit pas faire perdre la saisie).
    const a = document.activeElement, dans = a && st.el.contains(a) && a.id ? a.id : null, pos = dans && a.selectionStart != null ? a.selectionStart : null;
    st.el.innerHTML = html();
    brancher();
    monterNewsletter();
    echelle(); requestAnimationFrame(echelle);
    if (dans) { const b = st.el.querySelector('#' + dans); if (b) { b.focus(); if (pos != null && b.setSelectionRange) { try { b.setSelectionRange(pos, pos); } catch (e) { /* champ sans curseur */ } } } }
  }

  window.DashOffres = {
    /** L'élément du module pour ce magasin, gardé d'un rendu du dashboard à l'autre. */
    racine(shop, o) {
      o = o || {};
      if (o.apiBase) { st.api = o.apiBase; }
      // Le nom du magasin arrive avec la liste des magasins : l'aperçu (l'émetteur) se redessine quand il change.
      if (o.nomShop && o.nomShop !== st.nomShop) { st.nomShop = o.nomShop; if (st.el && st.mode !== 'edit') { setTimeout(rendre, 0); } }
      if (o.onCompte) { st.onCompte = o.onCompte; }
      if (o.aller) { st.aller = o.aller; }
      if (!st.el || String(st.shop) !== String(shop)) {
        st.shop = String(shop); st.el = document.createElement('div'); st.el.className = 'of-racine';
        Object.assign(st, { liste: null, n: null, sel: null, mode: null, form: null, msg: null, produits: null, prodErr: null, employes: null, empErr: null, nl: null });
        charger(false);
      }
      return st.el;
    },
    relire() { if (st.el) { charger(true); } },
    /** Une offre pré-remplie depuis un e-mail (boite.js) : {nom, description, jour, retrait, client, clientQ, lignes, note, source}. */
    nouvelle(p) {
      if (!st.el || !p) { return; }
      const f = brouillon(null);
      Object.assign(f, { nom: p.nom || '', description: p.description || '', jour: p.jour || '', retrait: p.retrait !== false, client: p.client || null,
        lignes: (p.lignes || []).map(l => ({ idProduit: l.idProduit, nom: l.nom, qte: l.qte, pu: l.pu, remiseValeur: null, remiseMontant: 0, remiseType: null })) });
      st.form = f; st.mode = 'edit'; st.source = p.source || null;
      st.msg = p.note ? { ok: true, texte: p.note } : null;
      st.cli = { type: 'particulier', q: p.clientQ || '', l: null, err: null, enCours: false };
      if (!p.client && p.clientQ) { chercherClients(); }
      rendre();
    },
    /** Ouvre une offre (citée dans un e-mail) : l'onglet « Toutes », l'offre choisie. */
    voir(id) {
      if (!st.el) { return; }
      st.onglet = 'tout'; st.sel = +id; st.mode = null; st.form = null;
      if (st.liste && !st.liste.some(o => o.id === +id)) { st.msg = { ok: false, texte: 'L’offre n° ' + id + ' n’est pas une offre de ce magasin dans TF Buddy.' }; }
      rendre();
    },
  };
})();
