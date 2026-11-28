/* Le siège de l'autocontrôle qualité (24/11/2026) — API : consultant/src/autocontrole.php.
 *
 *   grilles.html#resultats  les contrôles des magasins (filtres : magasin, période, essais), les défauts qui reviennent ;
 *   grilles.html#grilles    les grilles : une par catégorie, les critères de tous les produits, les surcharges par
 *                           produit ; le brouillon s'enregistre à tout moment, la publication l'envoie aux tablettes
 *                           s'il n'a plus d'erreur.
 */
(function () {
  'use strict';
  const API = '../api/cockpit';
  const ICO = (n, t) => window.AQ_ICO(n, t || 16).replace('class="aq-ico"', 'class="ico"');
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"]/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]));
  function rep(r) { return r.ok ? r.json() : r.json().then(j => Promise.reject(Object.assign(new Error((j && j.error) || ('HTTP ' + r.status)), { j })), () => Promise.reject(new Error('HTTP ' + r.status))); }
  const lire = p => fetch(API + p, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(rep);
  const envoyer = (m, p, b) => fetch(API + p, { method: m, headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, credentials: 'same-origin', body: JSON.stringify(b || {}) }).then(rep);

  const NIV = { ok: 'Conforme', mineur: 'À surveiller', majeur: 'Non conforme' };
  const DEC = { vente: 'Mis en vente', declasser: 'Déclassé', jeter: 'Jeté' };
  const TYPE = { echelle: 'Échelle', oui_non: 'Oui / non', mesure: 'Mesure' };
  const PH = { obligatoire: 'Obligatoire', facultative: 'Facultative', non: 'Non' };
  // L'état se dit par un mot (§3, §7.9) : pastille verte, étiquette or, étiquette d'arrêt.
  const etat = (n, t) => n === 'ok' ? `<span class="chip chip--ok">${esc(t || NIV.ok)}</span>` : n === 'mineur' || n === 'majeur'
    ? `<span class="${n === 'mineur' ? 'tag-warn' : 'tag-stop'}">${esc(t || NIV[n])}</span>` : `<span class="chip chip--draft">${esc(t)}</span>`;
  const date = s => s ? s.slice(8, 10) + '/' + s.slice(5, 7) + ' ' + s.slice(11, 16) : '';

  const S = { vue: (location.hash.replace('#', '') || 'resultats'), res: null, shops: [], f: { shop: '', jours: 30, essais: false }, ouvert: null,
    g: null, doc: null, cat: 0, sel: null, modifie: false, msg: '', erreurs: {} };

  /* ── La coquille : rail du module, barre supérieure ──────────────────────────────────────────────────── */
  function rail() {
    const shop = S.f.shop || (S.shops[0] || {}).id || '';
    const it = (v, ico, l) => `<a href="#${v}" class="sidebar__item${S.vue === v ? ' is-active' : ''}"${S.vue === v ? ' aria-current="page"' : ''}>${ICO(ico)}${l}</a>`;
    $('rail').innerHTML = `<div class="sidebar__head"><img class="sidebar__logo" src="../assets/img/logo.png" alt="L'Atelier By"><div class="sidebar__kicker">Cockpit · qualité</div></div>
      <div class="sidebar__back"><a href="../">${ICO('retour')}Retour au cockpit</a></div>
      <div class="sidebar__groups"><div class="sidebar__group"><div class="sidebar__group-label">Autocontrôle qualité</div>
        ${it('resultats', 'analyse', 'Résultats des magasins')}${it('grilles', 'controle', 'Grilles par catégorie')}
        <a href="index.html?essai=1${shop ? '&shop=' + encodeURIComponent(shop) : ''}" target="_blank" rel="noopener" class="sidebar__item">${ICO('ecran')}Tablette, en essai ↗</a></div></div>`;
  }
  function rendre() {
    rail();
    $('titre').textContent = S.vue === 'grilles' ? 'Grilles par catégorie' : 'Résultats des autocontrôles';
    if (S.vue === 'grilles') rendreGrilles(); else rendreResultats();
    document.documentElement.dataset.pret = '1';
  }
  window.addEventListener('hashchange', () => { S.vue = location.hash.replace('#', '') || 'resultats'; charger(); });

  /* ── Les résultats ───────────────────────────────────────────────────────────────────────────────────── */
  function rendreResultats() {
    $('actions').innerHTML = `<div class="gq-filtres">
      <select data-f="shop" aria-label="Magasin"><option value="">Tous les magasins</option>${S.shops.map(s => `<option value="${esc(s.id)}"${S.f.shop === s.id ? ' selected' : ''}>${esc(s.nom)}</option>`).join('')}</select>
      <span class="seg">${[7, 30, 90].map(j => `<button type="button" data-jours="${j}" class="${S.f.jours === j ? 'is-on' : ''}">${j} jours</button>`).join('')}</span>
      <label class="field"><input type="checkbox" data-f="essais"${S.f.essais ? ' checked' : ''}> Essais</label></div>`;
    const r = S.res;
    if (!r) { $('page').innerHTML = '<p class="muted">Lecture des contrôles…</p>'; return; }
    const k = r.controles;
    const n = c => k.filter(c).length;
    const pct = k.length ? Math.round(n(x => x.niveau === 'ok') / k.length * 100) + ' %' : '—';
    const mags = new Set(k.map(x => x.shop)).size;
    const kpi = (v, l, note) => `<div class="kpi"><div class="kpi__value">${v}</div><div class="kpi__label">${l}</div>${note ? `<div class="kpi__note">${note}</div>` : ''}</div>`;
    const recs = r.recurrents.length ? r.recurrents.map(d => `<div class="gq-rec">${ICO('repeter')}<div>${esc(d.libelle)} · ${esc(d.produit)} · ${esc(d.magasin)} ${etat('mineur', d.fois + ' fois')}
        <small>${d.jours.map(j => j.slice(8, 10) + '/' + j.slice(5, 7)).join(', ')}${d.prochaine ? ' · correction proposée : ' + esc(d.prochaine) : ''}</small></div></div>`).join('')
      : '<p class="gq-vide">Aucun défaut ne revient trois fois sur la période.</p>';
    const ligne = c => {
      const ecarts = c.ecarts.map(e => e.libelle).join(', ');
      const ph = c.photos.map(p => `<img src="${API + esc(p.url)}" alt="" loading="lazy">`).join('');
      const tr = `<tr class="row-link" data-ouvrir="${c.id}"><td>${date(c.cree_le)}${c.essai ? ' <span class="chip chip--draft">essai</span>' : ''}<span class="sub">${c.fournee ? 'fournée de ' + esc(c.fournee) : 'sans fournée'}${c.pieces ? ' · ' + c.pieces + ' pcs' : ''}</span></td>
        <td>${esc(c.magasin)}<span class="sub">${esc([c.auteur, c.roleNom, c.visite ? 'visite du consultant' : ''].filter(Boolean).join(' · '))}</span></td><td>${esc(c.produit)}<span class="sub">${esc(c.grille || 'critères communs')}</span></td>
        <td>${etat(c.niveau)}</td><td>${esc(DEC[c.decision] || c.decision)}${c.decision !== c.suggestion ? `<span class="sub">suggéré : ${esc((DEC[c.suggestion] || '').toLowerCase())}</span>` : ''}</td>
        <td class="num">${c.ecarts.length}${ecarts ? `<span class="sub" style="text-align:left">${esc(ecarts)}</span>` : ''}</td><td><div class="gq-ph">${ph}</div></td>
        <td>${c.prevenir ? '<span class="chip chip--draft">Responsable prévenu</span> ' : ''}${c.reclamer ? '<span class="chip chip--draft">Réclamation</span>' : ''}</td></tr>`;
      if (S.ouvert !== c.id) return tr;
      return tr + `<tr class="gq-detail"><td colspan="8"><div class="gq-detail-g"><div>
          <p class="section-label" style="margin:0">Les écarts et leurs corrections</p>${c.ecarts.length ? `<ul>${c.ecarts.map(e => `<li>${esc(e.libelle)} : ${esc(e.reponse)} (${esc(NIV[e.niveau])}) — ${esc(e.cause)} Maintenant : ${esc(e.maintenant)} Prochaine fournée : ${esc(e.prochaine)}</li>`).join('')}</ul>` : '<p class="gq-vide">Aucun écart.</p>'}
          ${c.actions.length ? `<p class="section-label">Consignes données pour la fournée suivante</p><ul>${c.actions.map(a => `<li>${esc(a.consigne)}</li>`).join('')}</ul>` : ''}</div>
        <div><p class="section-label" style="margin:0 0 8px">Photos</p><div class="gq-ph">${c.photos.map(p => `<a href="${API + esc(p.url)}" target="_blank" rel="noopener"><img src="${API + esc(p.url)}" alt="${esc(p.etape)}"></a>`).join('') || '<p class="gq-vide">Aucune photo.</p>'}</div></div></div></td></tr>`;
    };
    $('page').innerHTML = `<section class="card gq-kpis"><div class="kpi-row">${kpi(k.length, 'Contrôles', r.jours + ' derniers jours')}${kpi(pct, 'Conformes')}
        ${kpi(n(x => x.decision === 'declasser'), 'Lots déclassés')}${kpi(n(x => x.decision === 'jeter'), 'Lots jetés')}${kpi(mags, 'Magasins')}</div></section>
      <section class="card"><h2 class="block-title">Les défauts qui reviennent</h2>${recs}</section>
      <section class="card table-card"><h2 class="block-title">Les contrôles</h2>${k.length ? `<div class="table-scroll"><table class="ds-table gq-res"><thead><tr><th>Quand</th><th>Magasin</th><th>Produit</th><th>Résultat</th><th>Le lot</th><th class="num">Écarts</th><th>Photos</th><th>Suites</th></tr></thead>
        <tbody>${k.map(ligne).join('')}</tbody></table></div>` : '<p class="gq-vide">Aucun contrôle sur la période. La tablette du magasin : Tablette, en essai ↗ dans le rail.</p>'}</section>`;
  }
  function chargerResultats() {
    S.res = null; rendre();
    const p = new URLSearchParams({ jours: S.f.jours }); if (S.f.shop) p.set('shop', S.f.shop); if (S.f.essais) p.set('essais', '1');
    lire('/autocontrole/controles?' + p).then(r => { S.res = r; rendre(); }).catch(e => { $('page').innerHTML = `<p class="error">${esc(e.message)}</p>`; });
  }

  /* ── Les grilles ─────────────────────────────────────────────────────────────────────────────────────── */
  const ETAPES = () => (S.doc && S.doc.etapes) || [];
  const onglets = () => (S.doc ? S.doc.categories.map((c, i) => [i, c.libelle, c.criteres.length]).concat([['communs', 'Tous les produits', S.doc.communs.criteres.length], ['produits', 'Réglages par produit', Object.keys(S.doc.produits || {}).length]]) : []);
  const grille = () => (S.cat === 'communs' ? S.doc.communs : S.doc.categories[S.cat]);
  // Les réponses d'un critère sous une même forme, pour l'aperçu de sa carte.
  function reponses(c) {
    if (c.type === 'echelle') return c.options || [];
    if (c.type === 'oui_non') return ['oui', 'non'].map(v => v === c.attendu ? { libelle: v === 'oui' ? 'Oui' : 'Non', niveau: 'ok', cible: true } : Object.assign({ libelle: v === 'oui' ? 'Oui' : 'Non' }, c[v]));
    const cible = c.minimum !== undefined ? `${c.minimum} ${c.unite} minimum` : `${c.cible - c.tolerance} à ${c.cible + c.tolerance} ${c.unite}`;
    return [{ libelle: cible, niveau: 'ok', cible: true }, Object.assign({ libelle: 'Hors tolérance' }, c.hors)];
  }
  // Un champ lié au document : data-p = chemin dans le brouillon (« categories.0.criteres.3.options.1.cause »).
  const champ = (p, v, attrs) => `<input data-p="${p}" value="${esc(v)}"${attrs || ''}>`;
  const zone = (p, v) => `<textarea data-p="${p}">${esc(v)}</textarea>`;
  const choix = (p, v, opts) => `<select data-p="${p}">${Object.entries(opts).map(([k, l]) => `<option value="${k}"${String(v) === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
  const correction = (p, o) => `<td>${choix(p + '.niveau', o.niveau, { mineur: NIV.mineur, majeur: NIV.majeur })}</td><td>${zone(p + '.cause', o.cause || '')}</td><td>${zone(p + '.maintenant', o.maintenant || '')}</td>
    <td>${zone(p + '.prochaine', o.prochaine || '')}</td><td class="etroit">${choix(p + '.decision', o.decision || 'vente', { vente: 'Vente', declasser: 'Déclasser', jeter: 'Jeter' })}</td>
    <td class="etroit"><input type="checkbox" data-p="${p}.reclamation"${o.reclamation ? ' checked' : ''} title="Défaut du produit cru : réclamation proposée"></td>`;

  function actionsGrilles() {
    const pub = S.g.publiee;
    const nbErr = Object.values(S.erreurs).reduce((a, l) => a + l.length, 0);
    $('actions').innerHTML = `${pub ? `<span class="chip chip--ok">Publiées le ${date(pub.le)}</span>` : '<span class="tag-warn">Grilles d\'exemple, jamais publiées</span>'}
      ${S.modifie ? '<span class="chip chip--draft">Modifications non enregistrées</span>' : ''}
      <button type="button" class="btn" data-act="enregistrer"${S.modifie ? '' : ' disabled'}>Enregistrer le brouillon</button>
      <button type="button" class="btn btn--primary" data-act="publier"${nbErr || S.modifie ? ' disabled' : ''} title="${nbErr ? 'Corrigez d\'abord les erreurs' : S.modifie ? 'Enregistrez d\'abord' : 'Les tablettes liront ces grilles'}">${ICO('publier')}Publier aux magasins</button>`;
  }
  function rendreGrilles() {
    if (!S.doc) { $('actions').innerHTML = ''; $('page').innerHTML = '<p class="muted">Lecture des grilles…</p>'; return; }
    actionsGrilles();
    const tabs = `<nav class="page-tabs">${onglets().map(([k, l, n]) => `<button type="button" class="page-tabs__tab${String(S.cat) === String(k) ? ' is-on' : ''}" data-cat="${k}">${esc(l)}<span class="page-tabs__count">${n}</span></button>`).join('')}
      <button type="button" class="page-tabs__tab" data-act="categorie">${ICO('ajouter')}Catégorie</button></nav>`;
    const msg = S.msg ? `<p class="muted" role="status" style="margin:0 0 12px">${esc(S.msg)}</p>` : '';
    const errs = Object.keys(S.erreurs).length ? `<section class="card gq-err"><h2 class="block-title">À corriger avant de publier</h2>${Object.entries(S.erreurs).map(([k, l]) => `<p style="margin:8px 0 0;font-size:13px">${esc(k === 'communs' ? 'Tous les produits' : (S.doc.categories.find(c => c.cle === k) || {}).libelle || k)}</p><ul>${l.map(e => `<li>${esc(e)}</li>`).join('')}</ul>`).join('')}</section>` : '';
    $('page').innerHTML = tabs + msg + errs + (S.cat === 'produits' ? surcharges() : categorie());
  }

  function categorie() {
    const g = grille();
    const base = S.cat === 'communs' ? 'communs' : 'categories.' + S.cat;
    const reglages = S.cat === 'communs' ? `<section class="card" style="margin-bottom:16px"><h2 class="block-title">Tous les produits</h2><p class="muted" style="font-size:13px">Ces critères s'ajoutent à la grille de chaque catégorie, et sont seuls posés aux produits sans grille (épicerie…).</p></section>`
      : `<div class="gq-2"><section class="card"><h2 class="block-title">La catégorie</h2>
          <label class="gq-champ">Nom${champ(base + '.libelle', g.libelle)}</label>
          <label class="gq-champ">Mots-clés du catalogue (catégorie, groupe ou nom du produit), séparés par des virgules${champ(base + '.motsCles', (g.motsCles || []).join(', '), ' data-liste="1"')}</label>
          <label class="gq-champ">Quand contrôler${zone(base + '.quand', g.quand || '')}</label></section>
        <section class="card"><h2 class="block-title">Photos par étape</h2>${ETAPES().map(e => `<div class="gq-ligne"><span>${ICO(e.icone)}${esc(e.titre)}</span>
          <span class="seg">${Object.keys(PH).map(k => `<button type="button" data-photo="${e.cle}" data-v="${k}" class="${(g.photos || {})[e.cle] === k ? 'is-on' : ''}">${PH[k]}</button>`).join('')}</span>
          ${champ(base + '.consignes_photo.' + e.cle, (g.consignes_photo || {})[e.cle] || '', ' placeholder="Consigne de la photo"')}</div>`).join('')}</section></div>`;
    const carte = (c, i) => `<button type="button" class="gq-cr${S.sel === i ? ' is-on' : ''}" data-sel="${i}"><div class="gq-cr-h">${esc(c.libelle || 'Nouveau critère')}</div>
      <div class="gq-ops">${reponses(c).map(o => etat(o.niveau || 'mineur', o.libelle)).join('')}</div>
      <div class="gq-meta"><span class="chip chip--draft">${TYPE[c.type] || '?'}</span>${c.facultatif ? '<span class="chip chip--draft">Facultatif</span>' : ''}</div></button>`;
    const cols = `<p class="section-label">Les critères, par étape<span class="section-label__aside">Cliquer un critère pour le modifier · en vert, la réponse attendue</span></p>
      <div class="gq-cols">${ETAPES().map(e => {
        const cs = g.criteres.map((c, i) => [c, i]).filter(([c]) => c.etape === e.cle);
        // Les critères de tous les produits s'ajoutent à la grille : montrés ici, modifiés dans leur onglet.
        const com = S.cat === 'communs' ? [] : S.doc.communs.criteres.filter(c => c.etape === e.cle);
        const carteCom = c => `<button type="button" class="gq-cr" data-cat="communs"><div class="gq-cr-h">${esc(c.libelle)}</div><div class="gq-ops">${reponses(c).map(o => etat(o.niveau || 'mineur', o.libelle)).join('')}</div>
          <div class="gq-meta"><span class="chip chip--draft">${TYPE[c.type] || '?'}</span><span class="chip chip--draft">Tous les produits</span></div></button>`;
        return `<div class="gq-col"><h2>${esc(e.titre)}<span class="section-label__aside">${cs.length + com.length}</span></h2>${cs.map(([c, i]) => carte(c, i)).join('')}${com.map(carteCom).join('')}
          <button type="button" class="btn btn--ghost btn--sm" data-ajout="${e.cle}">${ICO('ajouter')}Ajouter un critère</button></div>`;
      }).join('')}</div>`;
    return reglages + cols + (S.sel !== null && g.criteres[S.sel] ? editeur(g.criteres[S.sel], base + '.criteres.' + S.sel) : '');
  }

  function editeur(c, p) {
    let corps = '';
    if (c.type === 'echelle') {
      corps = `<div class="table-scroll"><table class="ds-table"><thead><tr><th>Réponse</th><th>Attendue</th><th>Niveau</th><th>Cause probable</th><th>Maintenant</th><th>Prochaine fournée</th><th>Le lot</th><th>Récl.</th><th></th></tr></thead><tbody>
        ${(c.options || []).map((o, j) => `<tr><td>${champ(`${p}.options.${j}.libelle`, o.libelle)}</td><td class="etroit"><input type="radio" name="cible" data-cible="${j}"${o.cible ? ' checked' : ''} aria-label="Réponse attendue"></td>
          ${o.cible ? '<td>' + etat('ok') + '</td><td colspan="5" class="muted">La réponse attendue n\'a pas de correction.</td>' : correction(`${p}.options.${j}`, o)}
          <td class="etroit"><button type="button" class="btn btn--danger btn--sm" data-retirer-option="${j}" title="Retirer cette réponse">${ICO('croix')}</button></td></tr>`).join('')}
        </tbody></table></div><button type="button" class="btn btn--ghost btn--sm" data-act="option">${ICO('ajouter')}Ajouter une réponse</button>`;
    } else if (c.type === 'oui_non') {
      const autre = c.attendu === 'oui' ? 'non' : 'oui';
      corps = `<label class="gq-champ" style="max-width:240px">Réponse attendue${choix(p + '.attendu', c.attendu || 'oui', { oui: 'Oui', non: 'Non' })}</label>
        <div class="table-scroll"><table class="ds-table"><thead><tr><th>Réponse</th><th>Niveau</th><th>Cause probable</th><th>Maintenant</th><th>Prochaine fournée</th><th>Le lot</th><th>Récl.</th></tr></thead>
        <tbody><tr><td>${autre === 'oui' ? 'Oui' : 'Non'}</td>${correction(p + '.' + autre, c[autre] || {})}</tr></tbody></table></div>`;
    } else {
      corps = `<div class="gq-ed-g" style="grid-template-columns:1fr 1fr 1fr 1fr"><label class="gq-champ">Unité${champ(p + '.unite', c.unite || '')}</label>
          <label class="gq-champ">Cible${champ(p + '.cible', c.cible ?? '', ' type="number" data-nombre="1"')}</label><label class="gq-champ">Tolérance (±)${champ(p + '.tolerance', c.tolerance ?? '', ' type="number" data-nombre="1"')}</label>
          <label class="gq-champ">ou un minimum${champ(p + '.minimum', c.minimum ?? '', ' type="number" data-nombre="1"')}</label></div>
        <div class="table-scroll"><table class="ds-table"><thead><tr><th>Réponse</th><th>Niveau</th><th>Cause probable</th><th>Maintenant</th><th>Prochaine fournée</th><th>Le lot</th><th>Récl.</th></tr></thead>
        <tbody><tr><td>Hors tolérance</td>${correction(p + '.hors', c.hors || {})}</tr></tbody></table></div>`;
    }
    return `<section class="card table-card"><div class="gq-ed-h"><h2>${esc(c.libelle || 'Nouveau critère')}</h2>
        <button type="button" class="btn btn--sm" data-deplacer="-1" title="Monter">↑</button><button type="button" class="btn btn--sm" data-deplacer="1" title="Descendre">↓</button>
        <button type="button" class="btn btn--danger btn--sm" data-act="supprimer">Retirer le critère</button></div>
      <div class="gq-ed-g"><label class="gq-champ">Libellé${champ(p + '.libelle', c.libelle || '')}</label><label class="gq-champ">Étape${choix(p + '.etape', c.etape, Object.fromEntries(ETAPES().map(e => [e.cle, e.titre])))}</label>
        <label class="gq-champ">Aide (geste à faire)${champ(p + '.aide', c.aide || '')}</label><label class="case"><input type="checkbox" data-p="${p}.facultatif"${c.facultatif ? ' checked' : ''}> Facultatif</label></div>
      <div class="gq-ed-g" style="grid-template-columns:1fr 1fr 2fr"><label class="gq-champ">Type<select data-type="1">${Object.entries(TYPE).map(([k, l]) => `<option value="${k}"${c.type === k ? ' selected' : ''}>${l}</option>`).join('')}</select></label>
        <label class="gq-champ">Clé (unique, a-z, 0-9, _)${champ(p + '.cle', c.cle || '')}</label></div>${corps}</section>`;
  }

  // Les réglages par produit se posent dans la fiche produit du cockpit (Catalogue › fiche › Qualité, 26/11/2026) : effectifs
  // tout de suite, ils ne passent pas par le brouillon. Ici, on les voit tous.
  function surcharges() {
    const prods = Object.entries(S.doc.produits || {});
    const nomCat = k => ((S.doc.categories.find(c => c.cle === k) || {}).libelle) || 'par mots-clés';
    return `<section class="card table-card"><h2 class="block-title">Réglages par produit</h2>
      <p class="muted" style="font-size:13px">Une grille forcée, des valeurs propres (le poids…), une photo de référence qualité : ils se règlent dans la fiche du produit,
        cockpit › Catalogue › la référence › onglet Qualité, et s'appliquent tout de suite.</p>
      ${prods.length ? `<div class="table-scroll"><table class="ds-table"><thead><tr><th>Produit</th><th>Grille</th><th>Valeurs propres</th><th>Photo qualité</th></tr></thead><tbody>
        ${prods.map(([id, x]) => `<tr><td>${esc(x.nom || 'Produit ' + id)}<span class="sub">${esc(id)}</span></td><td>${esc(x.categorie ? nomCat(x.categorie) : 'par mots-clés')}</td>
          <td>${Object.entries(x.surcharges || {}).map(([k, v]) => `<span class="chip chip--draft">${esc(k)} : ${v.minimum != null ? esc(v.minimum) + ' minimum' : esc(v.cible) + ' ± ' + esc(v.tolerance)}</span>`).join(' ') || '—'}</td>
          <td>${x.photo ? `<img src="../${esc(x.photo)}" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid var(--line)">` : '—'}</td></tr>`).join('')}</tbody></table></div>`
        : '<p class="gq-vide">Aucun réglage par produit.</p>'}</section>`;
  }

  /* ── Modifier le brouillon ───────────────────────────────────────────────────────────────────────────── */
  function poser(chemin, v) {
    const k = chemin.split('.');
    let o = S.doc;
    for (let i = 0; i < k.length - 1; i++) { if (o[k[i]] === undefined || o[k[i]] === null) o[k[i]] = {}; o = o[k[i]]; }
    if (v === undefined) delete o[k[k.length - 1]]; else o[k[k.length - 1]] = v;
    S.modifie = true;
  }
  function nouveauCritere(etape, type) {
    const n = grille().criteres.length + 1;
    const c = { cle: 'critere_' + Date.now().toString(36), etape, libelle: 'Nouveau critère ' + n, type };
    const corr = { niveau: 'mineur', cause: '', maintenant: '', prochaine: '', decision: 'vente' };
    if (type === 'echelle') c.options = [{ cle: 'conforme', libelle: 'Conforme', niveau: 'ok', cible: true }, Object.assign({ cle: 'non_conforme', libelle: 'Non conforme' }, corr)];
    else if (type === 'oui_non') { c.attendu = 'oui'; c.non = corr; }
    else { c.unite = 'g'; c.cible = 100; c.tolerance = 5; c.hors = corr; }
    return c;
  }

  document.addEventListener('input', ev => {
    const t = ev.target;
    if (!t.dataset.p || S.vue !== 'grilles') return;
    let v = t.type === 'checkbox' ? t.checked : t.value;
    if (t.dataset.liste) v = String(v).split(',').map(x => x.trim()).filter(Boolean);
    if (t.dataset.nombre) v = t.value === '' ? undefined : Number(t.value);
    if (t.type === 'checkbox' && !v) v = undefined;
    poser(t.dataset.p, v);
    // Les champs texte se modifient sans redessiner (le curseur reste) ; la barre d'actions suit.
    if (t.tagName === 'SELECT' || t.type === 'checkbox') rendre(); else actionsGrilles();
  });

  document.addEventListener('change', ev => {
    const t = ev.target;
    if (t.dataset.f === 'shop') { S.f.shop = t.value; chargerResultats(); return; }
    if (t.dataset.f === 'essais') { S.f.essais = t.checked; chargerResultats(); return; }
    if (t.dataset.type) { const g = grille(); const c = g.criteres[S.sel]; const n = nouveauCritere(c.etape, t.value); g.criteres[S.sel] = Object.assign(n, { cle: c.cle, libelle: c.libelle, aide: c.aide }); S.modifie = true; rendre(); return; }
    if (t.dataset.cible !== undefined) { const c = grille().criteres[S.sel]; c.options.forEach((o, j) => { if (j === Number(t.dataset.cible)) { o.cible = true; o.niveau = 'ok'; ['cause', 'maintenant', 'prochaine', 'decision', 'reclamation'].forEach(k => delete o[k]); } else if (o.cible) { delete o.cible; Object.assign(o, { niveau: 'mineur', cause: '', maintenant: '', prochaine: '', decision: 'vente' }); } }); S.modifie = true; rendre(); }
  });

  document.addEventListener('click', ev => {
    const t = ev.target.closest('button, [data-ouvrir]');
    if (!t || t.disabled) return;
    const d = t.dataset;
    if (d.ouvrir) { S.ouvert = S.ouvert === Number(d.ouvrir) ? null : Number(d.ouvrir); rendre(); return; }
    if (d.jours) { S.f.jours = Number(d.jours); chargerResultats(); return; }
    if (d.cat !== undefined) { S.cat = /^\d+$/.test(d.cat) ? Number(d.cat) : d.cat; S.sel = null; rendre(); return; }
    if (d.sel !== undefined) { S.sel = S.sel === Number(d.sel) ? null : Number(d.sel); rendre(); return; }
    if (d.photo) { const g = grille(); g.photos = g.photos || {}; g.photos[d.photo] = d.v; S.modifie = true; rendre(); return; }
    if (d.ajout) { const g = grille(); g.criteres.push(nouveauCritere(d.ajout, 'echelle')); S.sel = g.criteres.length - 1; S.modifie = true; rendre(); return; }
    if (d.deplacer) {
      const g = grille(); const c = g.criteres[S.sel];
      const memes = g.criteres.map((x, i) => [x, i]).filter(([x]) => x.etape === c.etape).map(([, i]) => i);
      const k = memes.indexOf(S.sel) + Number(d.deplacer);
      if (k >= 0 && k < memes.length) { const j = memes[k]; [g.criteres[S.sel], g.criteres[j]] = [g.criteres[j], g.criteres[S.sel]]; S.sel = j; S.modifie = true; rendre(); }
      return;
    }
    if (d.retirerOption !== undefined) { grille().criteres[S.sel].options.splice(Number(d.retirerOption), 1); S.modifie = true; rendre(); return; }
    if (d.act === 'option') { grille().criteres[S.sel].options.push({ cle: 'reponse_' + Date.now().toString(36), libelle: 'Nouvelle réponse', niveau: 'mineur', cause: '', maintenant: '', prochaine: '', decision: 'vente' }); S.modifie = true; rendre(); return; }
    if (d.act === 'supprimer') { if (confirm('Retirer ce critère de la grille ?')) { grille().criteres.splice(S.sel, 1); S.sel = null; S.modifie = true; rendre(); } return; }
    if (d.act === 'categorie') {
      const nom = prompt('Nom de la nouvelle catégorie (ex. Cakes & biscuits) :'); if (!nom) return;
      const cle = nom.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 30) || 'categorie';
      S.doc.categories.push({ cle, libelle: nom, motsCles: [], quand: '', photos: { cuisson: 'facultative', structure: 'facultative', organoleptique: 'non', presentation: 'facultative' }, consignes_photo: {}, criteres: [] });
      S.cat = S.doc.categories.length - 1; S.sel = null; S.modifie = true; rendre(); return;
    }
    if (d.act === 'enregistrer') {
      envoyer('PUT', '/autocontrole/grilles', { categories: S.doc.categories, communs: S.doc.communs })
        .then(r => { S.modifie = false; S.erreurs = r.erreurs || {}; S.msg = 'Brouillon enregistré' + (Object.keys(S.erreurs).length ? ' : il reste des points à corriger avant de publier.' : ' : il peut être publié.'); rendre(); })
        .catch(e => { S.msg = 'Pas enregistré : ' + e.message; rendre(); });
      return;
    }
    if (d.act === 'publier') {
      if (!confirm('Publier ces grilles aux tablettes de tous les magasins ?')) return;
      envoyer('POST', '/autocontrole/grilles/publication')
        .then(() => { S.msg = 'Grilles publiées : les tablettes les lisent dès maintenant.'; return chargerGrilles(); })
        .catch(e => { S.erreurs = (e.j && e.j.erreurs) || S.erreurs; S.msg = 'Pas publié : ' + e.message; rendre(); });
    }
  });
  window.addEventListener('beforeunload', ev => { if (S.modifie) { ev.preventDefault(); ev.returnValue = ''; } });

  function chargerGrilles() {
    return lire('/autocontrole/grilles').then(g => {
      S.g = g; S.doc = JSON.parse(JSON.stringify(g.brouillon)); S.doc.produits = S.doc.produits || {};
      S.erreurs = g.erreurs || {}; S.modifie = false; rendre();
    }).catch(e => { $('page').innerHTML = `<p class="error">${esc(e.message)}</p>`; });
  }
  function charger() {
    rendre();
    if (S.vue === 'grilles') { if (!S.doc) chargerGrilles(); } else chargerResultats();
  }

  // Les magasins pour le filtre (et le lien de la tablette d'essai), puis l'écran.
  lire('/stores').then(l => { S.shops = (Array.isArray(l) ? l : []).filter(s => s.status !== 'Fermé').map(s => ({ id: String(s.id), nom: s.nom || ('Magasin ' + s.id) })); rail(); if (S.vue === 'resultats') rendreResultats(); }).catch(() => {});
  charger();
})();
