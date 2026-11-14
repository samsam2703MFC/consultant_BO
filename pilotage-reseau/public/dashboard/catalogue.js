/* Dashboard › Mon catalogue (14/11/2026, Sam : « dans le dashboard, un module où les franchisés peuvent créer leur
 * catalogue, sous contrainte » ; maquette validée : liste, puis un assistant en quatre étapes).
 *
 * Les catalogues vivent dans le portail Marketing ; ce module parle à l'API du cockpit à côté de lui
 * (../api/cockpit/dashboard/catalogues…), qui lit la boutique dans la session, ajoute le jeton de service et relaie
 * au Marketing (src/catalogues_magasin.php). Le navigateur ne voit jamais le jeton. Les contraintes (produits vendus
 * par la boutique, ses prix, couverture et dernière page de la marque, trois mises en page) sont celles du Marketing :
 * ses refus s'affichent tels quels.
 *
 * Utilisation (dashboard.js) : DbCatalogue.racine(shop, nomMagasin) rend l'élément du module — gardé d'un rendu du
 * dashboard à l'autre, avec son état.
 */
(function () {
  'use strict';
  const API = '../api/cockpit/dashboard/catalogues';
  const LAYOUTS = {
    vedette: { label: '1 vedette + 4', cap: 5 },
    grille: { label: 'Grille 2 × 3', cap: 6 },
    liste: { label: 'Liste avec prix', cap: 12 },
  };
  const STATUTS = { brouillon: 'Brouillon', pret: 'PDF prêt', imprime: 'Imprimé' };
  const FORMAT_MM = { A5: { w: 148, h: 210 }, A4: { w: 210, h: 297 } };
  const BLEED = 3;
  const PX_MM = 96 / 25.4;
  const ETAPES = [
    ['Point de départ', 'une gamme, ou tous mes produits'],
    ['Je clique mes produits', 'ceux de MA boutique, à mes prix'],
    ['Mise en page', 'format, pages intérieures'],
    ['Aperçu et PDF', 'FR ou NL'],
  ];
  const CADENAS = '<svg viewBox="0 0 12 12" aria-hidden="true"><rect x="2" y="5.2" width="8" height="5.8" rx="1.2" fill="currentColor"/><path d="M4 5.4V3.8a2 2 0 0 1 4 0v1.6" fill="none" stroke="currentColor" stroke-width="1.3"/></svg>';

  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const prix = (c, lang) => c == null ? '—' : (c / 100).toLocaleString(lang === 'nl' ? 'nl-BE' : 'fr-BE', { style: 'currency', currency: 'EUR' });
  const date = iso => { if (!iso) { return ''; } const d = new Date(iso); return isNaN(d) ? '' : d.toLocaleDateString('fr-BE', { day: 'numeric', month: 'short', year: 'numeric' }); };

  /** Une erreur lisible : le message du Marketing (ou du cockpit), et le détail champ par champ. */
  class ErreurApi extends Error {
    constructor(statut, corps) {
      const e = (corps && corps.error) || {};
      super(typeof e === 'string' ? ((corps && corps.message) || e) : (e.message || ('Erreur ' + statut)));
      this.statut = statut; this.code = typeof e === 'object' ? (e.code || '') : ''; this.details = (typeof e === 'object' && Array.isArray(e.details)) ? e.details : [];
    }
  }
  function appel(methode, chemin, corps) {
    const o = { method: methode, headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (corps !== undefined) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(corps); }
    return fetch(API + chemin, o).then(r => r.text().then(t => {
      let j = null; try { j = t ? JSON.parse(t) : null; } catch (e) { j = null; }
      if (!r.ok || !j || j.success === false) { throw new ErreurApi(r.status, j || { error: { message: r.status === 0 ? 'Le serveur ne répond pas.' : 'Réponse illisible du serveur (HTTP ' + r.status + ').' } }); }
      return j;
    }), () => { throw new ErreurApi(0, { error: { message: 'Le serveur du cockpit ne répond pas (réseau).' } }); });
  }

  /* --- l'état du module (un par magasin) ------------------------------------------------------------------------ */
  const etats = {};
  function etat(shop) {
    if (!etats[shop]) {
      const el = document.createElement('div');
      el.className = 'cm';
      etats[shop] = { shop, el, vue: 'liste', liste: null, listeErr: null, prod: null, prodErr: null, cat: null, etape: 1,
        src: null, titre: '', choix: null, pages: null, format: 'A5', langs: ['fr'], lang: 'fr', doc: {}, docErr: {}, msg: null,
        occupe: false, rech: '', modifie: false };
      el.addEventListener('click', ev => clic(etats[shop], ev));
      el.addEventListener('input', ev => saisie(etats[shop], ev));
      el.addEventListener('change', ev => changement(etats[shop], ev));
    }
    return etats[shop];
  }
  const qs = S => '?shop=' + encodeURIComponent(S.shop);

  function chargerListe(S) {
    S.listeErr = null;
    return appel('GET', qs(S)).then(j => { S.liste = j.data || []; }).catch(e => { S.listeErr = e; }).finally(() => rendre(S));
  }
  function chargerProduits(S, force) {
    if (S.prod && !force) { return Promise.resolve(); }
    S.prodErr = null;
    return appel('GET', '/produits' + qs(S)).then(j => { S.prod = j.data || {}; }).catch(e => { S.prodErr = e; }).finally(() => rendre(S));
  }
  function chargerDoc(S, force) {
    const c = S.cat; if (!c) { return; }
    const k = c.id + '|' + S.lang;
    if (S.doc[k] && !force) { return; }
    delete S.docErr[k];
    S.doc[k] = null;
    appel('GET', '/' + c.id + '/document' + qs(S) + '&lang=' + S.lang)
      .then(j => { S.doc[k] = j.data; }).catch(e => { delete S.doc[k]; S.docErr[k] = e; }).finally(() => rendre(S));
  }
  function oublierDocs(S) { S.doc = {}; S.docErr = {}; }

  /* --- les données dérivées ------------------------------------------------------------------------------------- */
  const produits = S => (S.prod && S.prod.products) || [];
  const categories = S => (S.prod && S.prod.categories) || [];
  const gammes = S => (S.prod && S.prod.gammes) || [];
  const nomCat = (S, id) => { if (id == null) { return 'Notre sélection'; } const c = categories(S).find(x => +x.id === +id); return c ? c.name : 'Catégorie ' + id; };
  function choisis(S) { return Object.keys(S.choix || {}).filter(k => S.choix[k]).map(Number); }
  function adopter(S, c) {
    S.cat = c;
    S.titre = c.title;
    S.choix = {}; (c.products || []).forEach(p => { S.choix[p.id] = p.on !== false; });
    S.pages = (c.pages || []).map(p => ({ layout: p.layout, category: p.category == null ? null : +p.category }));
    S.format = c.format || 'A5';
    S.langs = (c.langs && c.langs.length ? c.langs : ['fr']).slice();
    if (!S.langs.includes(S.lang)) { S.lang = S.langs[0]; }
    S.modifie = false;
  }

  /* --- les gestes ----------------------------------------------------------------------------------------------- */
  function erreurMsg(S, e, avant) {
    // « Produit 107 : … » se lit avec le nom du produit quand on le connaît.
    const nom = id => { const p = produits(S).find(x => +x.id === +id); return p ? '« ' + p.name + ' »' : 'Produit ' + id; };
    const det = (e.details || []).map(d => {
      const msg = String(d.message || '').replace(/^Produit (\d+)/, (t, id) => nom(id));
      return /^products\[/.test(d.field || '') || !d.field ? msg : d.field + ' : ' + msg;
    });
    return { type: 'err', titre: (avant ? avant + ' — ' : '') + e.message, details: det };
  }
  function occupe(S, p, avant) {
    S.occupe = true; S.msg = null; rendre(S);
    return p.catch(e => { S.msg = erreurMsg(S, e, avant); }).finally(() => {
      S.occupe = false; rendre(S);
      // Un refus doit se voir, même si l'on est en bas de la liste des produits.
      const m = S.msg && S.el.querySelector('.cm-msg');
      if (m && m.scrollIntoView) { m.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    });
  }
  function nouveau(S) {
    S.vue = 'assistant'; S.etape = 1; S.cat = null; S.src = null; S.titre = ''; S.choix = null; S.pages = null;
    S.format = 'A5'; S.langs = ['fr']; S.lang = 'fr'; S.msg = null; S.rech = ''; oublierDocs(S);
    chargerProduits(S); rendre(S);
  }
  function ouvrir(S, id, etape) {
    S.vue = 'assistant'; S.msg = null; S.rech = ''; oublierDocs(S);
    chargerProduits(S);
    const c = (S.liste || []).find(x => +x.id === +id);
    if (c) { adopter(S, c); S.etape = etape || 2; }
    occupe(S, appel('GET', '/' + id + qs(S)).then(j => { adopter(S, j.data); S.etape = etape || 2; if (S.etape === 4) { chargerDoc(S); } }), 'Lecture du catalogue');
  }
  function creer(S) {
    const titre = S.titre.trim();
    if (!titre) { S.msg = { type: 'err', titre: 'Donnez un titre à votre catalogue.' }; rendre(S); return; }
    if (!S.src) { S.msg = { type: 'err', titre: 'Choisissez le point de départ : une gamme, ou tous mes produits.' }; rendre(S); return; }
    const corps = { title: titre, format: 'A5', langs: ['fr'] };
    if (S.src === 'tous') {
      corps.source = { kind: 'choix' };
      corps.products = produits(S).map(p => ({ id: +p.id, on: true }));
    } else {
      corps.source = { kind: 'gamme', ref: +S.src };
    }
    occupe(S, appel('POST', qs(S), corps).then(j => { adopter(S, j.data); S.etape = 2; S.liste = null; }), 'Création du catalogue');
  }
  function majTitre(S) {
    const titre = S.titre.trim();
    if (!S.cat || !titre || titre === S.cat.title) { return Promise.resolve(); }
    return appel('PUT', '/' + S.cat.id + qs(S), { title: titre }).then(j => { const ch = S.choix, pg = S.pages; adopter(S, j.data); S.choix = ch; S.pages = pg; });
  }
  /** Étape 2 → 3 : les produits ; la mise en page est refaite d'après eux (répartition automatique du Marketing). */
  function enregistrerProduits(S, suite) {
    const vendus = new Set(produits(S).map(p => +p.id));
    const lignes = []; const vus = new Set();
    (S.cat.products || []).forEach(p => { if (vendus.has(+p.id) && !vus.has(+p.id)) { vus.add(+p.id); lignes.push({ id: +p.id, on: !!S.choix[p.id] }); } });
    produits(S).forEach(p => { if (S.choix[p.id] && !vus.has(+p.id)) { vus.add(+p.id); lignes.push({ id: +p.id, on: true }); } });
    const avant = JSON.stringify((S.cat.products || []).map(p => [+p.id, p.on !== false]));
    const apres = JSON.stringify(lignes.map(p => [p.id, p.on]));
    const corps = avant === apres ? null : { products: lignes, pages: [] };
    const p = majTitre(S).then(() => corps ? appel('PUT', '/' + S.cat.id + qs(S), corps).then(j => { adopter(S, j.data); oublierDocs(S); }) : null)
      .then(() => { S.etape = suite; S.liste = null; if (suite === 4) { chargerDoc(S); } });
    occupe(S, p, 'Enregistrement des produits');
  }
  function enregistrerPages(S, suite) {
    const corps = { format: S.format, langs: S.langs.slice(), pages: S.pages.map(p => ({ layout: p.layout, category: p.category })) };
    const p = majTitre(S).then(() => appel('PUT', '/' + S.cat.id + qs(S), corps)).then(j => { adopter(S, j.data); oublierDocs(S); S.etape = suite; S.liste = null; if (suite === 4) { chargerDoc(S); } });
    occupe(S, p, 'Enregistrement de la mise en page');
  }
  function repartir(S) {
    occupe(S, appel('PUT', '/' + S.cat.id + qs(S), { pages: [] }).then(j => { adopter(S, j.data); oublierDocs(S); }), 'Répartition automatique');
  }
  function aller(S, n) {
    if (S.occupe || n === S.etape) { return; }
    if (!S.cat && n > 1) { return; }
    if (S.etape === 2 && n > 2) { enregistrerProduits(S, n); return; }
    if (S.etape === 3 && S.modifie) { enregistrerPages(S, n); return; }
    S.msg = null; S.etape = n;
    if (n === 4) { chargerDoc(S); }
    rendre(S);
  }
  function telecharger(S) {
    // La fenêtre s'ouvre pendant le clic (sinon le navigateur la bloque), puis reçoit l'adresse signée du Marketing.
    const w = window.open('', '_blank');
    if (w) { try { w.document.write('<p style="font:15px system-ui;padding:24px;color:#5c5852">Préparation du catalogue…</p>'); } catch (e) { /* rien */ } }
    const c = S.cat;
    const p = appel('POST', '/' + c.id + '/impression' + qs(S), { lang: S.lang }).then(j => {
      const url = j.data && j.data.url;
      if (!url) { throw new ErreurApi(502, { error: { message: 'Le Marketing n’a pas rendu d’adresse d’impression.' } }); }
      if (w) { w.location.href = url; } else { window.open(url, '_blank'); }
      S.msg = { type: 'ok', titre: 'La page d’impression du catalogue s’ouvre dans un nouvel onglet : choisissez « Enregistrer au format PDF ». Le lien vaut 48 heures.' };
      if (c.status === 'brouillon') {
        return appel('PUT', '/' + c.id + qs(S), { status: 'pret' }).then(k => { adopter(S, k.data); S.liste = null; }).catch(() => {});
      }
    }).catch(e => { if (w) { w.close(); } throw e; });
    occupe(S, p, 'Téléchargement du PDF');
  }
  function statut(S, id, st) {
    occupe(S, appel('PUT', '/' + id + qs(S), { status: st }).then(j => { if (S.cat && +S.cat.id === +id) { adopter(S, j.data); } return chargerListe(S); }), 'Changement d’état');
  }
  function supprimer(S, id) {
    const c = (S.liste || []).find(x => +x.id === +id);
    if (!window.confirm('Supprimer le catalogue « ' + (c ? c.title : id) + ' » ? Il disparaît aussi du Marketing.')) { return; }
    occupe(S, appel('DELETE', '/' + id + qs(S)).then(() => chargerListe(S)), 'Suppression');
  }

  function clic(S, ev) {
    const b = ev.target.closest('[data-cm]');
    if (!b || b.disabled || !S.el.contains(b)) { return; }
    const a = b.dataset.cm, v = b.dataset.v;
    if (a === 'nouveau') { nouveau(S); }
    else if (a === 'liste') { S.vue = 'liste'; S.msg = null; if (!S.liste) { chargerListe(S); } rendre(S); }
    else if (a === 'relire') { S.liste = null; S.prod = null; chargerListe(S); rendre(S); }
    else if (a === 'ouvrir') { ouvrir(S, v); }
    else if (a === 'apercu') { ouvrir(S, v, 4); }
    else if (a === 'suppr') { ev.stopPropagation(); supprimer(S, v); }
    else if (a === 'statut') { ev.stopPropagation(); statut(S, v, b.dataset.st); }
    else if (a === 'src') { S.src = v; if (!S.titre.trim() && v !== 'tous') { const g = gammes(S).find(x => String(x.id) === v); if (g) { S.titre = g.name; } } rendre(S); }
    else if (a === 'creer') { creer(S); }
    else if (a === 'etape') { aller(S, +v); }
    else if (a === 'prod') { if (S.choix) { S.choix[v] = !S.choix[v]; rendre(S); } }
    else if (a === 'tout') { visibles(S).filter(p => !b.dataset.cat || String(p.categoryId) === b.dataset.cat).forEach(p => { S.choix[p.id] = v === '1'; }); rendre(S); }
    else if (a === 'layout') { S.pages[+b.dataset.i].layout = v; S.modifie = true; rendre(S); }
    else if (a === 'monter' || a === 'descendre') { const i = +v, j = a === 'monter' ? i - 1 : i + 1; if (j >= 0 && j < S.pages.length) { const t = S.pages[i]; S.pages[i] = S.pages[j]; S.pages[j] = t; S.modifie = true; rendre(S); } }
    else if (a === 'retirer') { S.pages.splice(+v, 1); S.modifie = true; rendre(S); }
    else if (a === 'ajouter') { const cats = catsChoisies(S); S.pages.push({ layout: 'grille', category: cats.length ? cats[0] : null }); S.modifie = true; rendre(S); }
    else if (a === 'repartir') { repartir(S); }
    else if (a === 'format') { S.format = v; S.modifie = true; rendre(S); }
    else if (a === 'lang') { S.lang = v; chargerDoc(S); rendre(S); }
    else if (a === 'pdf') { telecharger(S); }
    else if (a === 'reessayer') { chargerDoc(S, true); }
  }
  function saisie(S, ev) {
    const t = ev.target;
    if (t.dataset.cm === 'titre') { S.titre = t.value; }
    if (t.dataset.cm === 'rech') { S.rech = t.value; rendreGarde(S, t); }
  }
  function changement(S, ev) {
    const t = ev.target;
    if (t.dataset.cm === 'pcat') { S.pages[+t.dataset.i].category = t.value === '' ? null : +t.value; S.modifie = true; rendre(S); }
    if (t.dataset.cm === 'langs') { const l = t.value; S.langs = t.checked ? Array.from(new Set(S.langs.concat(l))) : S.langs.filter(x => x !== l); if (!S.langs.length) { S.langs = ['fr']; } S.modifie = true; rendre(S); }
  }
  /** Un rendu pendant la frappe : le champ garde le curseur. */
  function rendreGarde(S, champ) {
    const pos = champ.selectionStart; rendre(S);
    const n = S.el.querySelector('[data-cm="' + champ.dataset.cm + '"]');
    if (n) { n.focus(); try { n.setSelectionRange(pos, pos); } catch (e) { /* rien */ } }
  }
  function visibles(S) {
    const r = S.rech.trim().toLowerCase();
    return produits(S).filter(p => !r || String(p.name).toLowerCase().includes(r) || String(p.nameNl || '').toLowerCase().includes(r));
  }
  function catsChoisies(S) {
    const ids = new Set(choisis(S));
    const out = [];
    produits(S).forEach(p => { if (ids.has(+p.id)) { const c = p.categoryId == null ? null : +p.categoryId; if (!out.includes(c)) { out.push(c); } } });
    return out;
  }

  /* --- le rendu ------------------------------------------------------------------------------------------------- */
  function message(S) {
    if (!S.msg) { return ''; }
    const d = S.msg.details && S.msg.details.length ? '<ul>' + S.msg.details.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>' : '';
    return '<div class="cm-msg ' + S.msg.type + '" role="' + (S.msg.type === 'err' ? 'alert' : 'status') + '">' + esc(S.msg.titre) + d + '</div>';
  }
  function bandeauErreur(e, quoi) {
    return '<div class="cm-msg err" role="alert"><b>' + esc(quoi) + '</b> — ' + esc(e.message) + '</div>';
  }

  function rendreListe(S) {
    let h = '<div class="cm-hd"><div><div class="k">Espace boutique</div><h2>Mon catalogue</h2></div><span class="sp"></span>'
      + '<button class="cm-btn" data-cm="relire">↻ Relire</button><button class="cm-btn pri" data-cm="nouveau">＋ Nouveau catalogue</button></div>';
    h += message(S);
    if (S.listeErr) { return h + bandeauErreur(S.listeErr, 'Mes catalogues n’ont pas pu être lus'); }
    if (!S.liste) { return h + '<div class="db-card"><div class="cm-vide">Lecture de mes catalogues…</div></div>'; }
    if (!S.liste.length) {
      return h + '<div class="db-card"><div class="cm-vide">Aucun catalogue pour l’instant.<br><br><button class="cm-btn pri" data-cm="nouveau">＋ Créer mon premier catalogue</button></div></div>';
    }
    h += '<div class="db-card" style="overflow:hidden"><table class="cm-tab"><thead><tr><th>Catalogue</th><th style="width:110px">État</th><th class="s-col" style="width:90px">Pages</th><th class="s-col" style="width:90px">Produits</th><th style="width:120px">Mis à jour</th><th></th></tr></thead><tbody>';
    S.liste.forEach(c => {
      const src = c.source && c.source.kind === 'gamme' ? 'Gamme ' + (c.source.label || '') : 'Mes produits';
      h += '<tr class="cl" data-cm="ouvrir" data-v="' + c.id + '"><td><div class="t">' + esc(c.title) + '</div><div class="s">' + esc(src) + ' · ' + esc(c.format) + ' · ' + esc((c.langs || ['fr']).join(' + ').toUpperCase()) + '</div></td>'
        + '<td><span class="cm-et ' + esc(c.status) + '">' + esc(STATUTS[c.status] || c.status) + '</span></td>'
        + '<td class="n s-col">' + (c.pageCount || 0) + '</td><td class="n s-col">' + (c.productCount || 0) + '</td>'
        + '<td class="n">' + esc(date(c.updatedAt)) + '</td>'
        + '<td class="act"><button class="cm-btn" data-cm="apercu" data-v="' + c.id + '">Aperçu</button> '
        + (c.status === 'pret' ? '<button class="cm-btn" data-cm="statut" data-st="imprime" data-v="' + c.id + '">Marquer imprimé</button> ' : '')
        + '<button class="cm-btn dis" data-cm="suppr" data-v="' + c.id + '" aria-label="Supprimer">Supprimer</button></td></tr>';
    });
    return h + '</tbody></table></div>';
  }

  function rendreEtapes(S) {
    return '<div class="cm-et4">' + ETAPES.map((e, i) => {
      const n = i + 1, fait = S.cat && n < S.etape, on = n === S.etape;
      return '<button class="cm-st' + (on ? ' on' : '') + (fait ? ' fait' : '') + '" data-cm="etape" data-v="' + n + '"' + (!S.cat && n > 1 ? ' disabled' : '') + '>'
        + '<span class="no">' + (fait ? '✓' : n) + '</span><span style="min-width:0"><div class="l">' + n + ' · ' + esc(e[0]) + '</div><div class="d">' + esc(e[1]) + '</div></span></button>';
    }).join('') + '</div>';
  }

  function rendreAssistant(S) {
    const titre = S.cat ? 'Mon catalogue — « ' + esc(S.cat.title) + ' »' : 'Nouveau catalogue';
    let h = '<div class="cm-hd"><div><div class="k">Espace boutique · ' + esc(S.nom || '') + '</div><h2>' + titre + '</h2></div><span class="sp"></span>'
      + '<button class="cm-btn" data-cm="liste">‹ Mes catalogues</button>'
      + (S.cat ? '<button class="cm-btn pri" data-cm="etape" data-v="4"' + (S.occupe ? ' disabled' : '') + '>Aperçu et PDF ›</button>' : '') + '</div>';
    h += rendreEtapes(S) + message(S);
    if (S.prodErr) { h += bandeauErreur(S.prodErr, 'Les produits de ma boutique n’ont pas pu être lus'); }
    if (S.etape === 1) { h += rendreE1(S); }
    else if (S.etape === 2) { h += rendreE2(S); }
    else if (S.etape === 3) { h += rendreE3(S); }
    else { h += rendreE4(S); }
    return h;
  }

  function cote(S, contenu) {
    return '<aside class="cm-cote">' + contenu + '<div class="note">' + CADENAS.replace('<svg', '<svg style="width:11px;height:11px;vertical-align:-1px"') + ' Couverture et dernière page : celles de la marque (logo, commande en ligne, adresse, horaires et QR de MA boutique). Je choisis seulement les produits et la mise en page des pages intérieures.</div></aside>';
  }
  function resume(S) {
    if (!S.cat) { return '<div class="k">Mon catalogue</div><div class="cm-cpt">Il se crée à la fin de cette étape.</div>'; }
    const n = choisis(S).length, pages = 2 + (S.pages || []).length;
    return '<div class="k">Mon catalogue · ' + pages + ' pages ' + esc(S.format) + '</div>'
      + '<div style="font-size:13px">' + n + ' produit' + (n > 1 ? 's' : '') + ' choisi' + (n > 1 ? 's' : '') + '</div>'
      + '<div class="cm-cpt" style="margin-top:4px">' + (S.pages || []).map(p => esc(nomCat(S, p.category)) + ' · ' + esc(LAYOUTS[p.layout] ? LAYOUTS[p.layout].label : p.layout)).join('<br>') + '</div>';
  }

  function rendreE1(S) {
    let h = '<div class="cm-corps"><section class="cm-pan"><div class="h"><span class="k">Point de départ</span></div>';
    if (S.cat) {
      const src = S.cat.source && S.cat.source.kind === 'gamme' ? 'la gamme « ' + (S.cat.source.label || '') + ' »' : 'tous mes produits';
      h += '<label class="cm-champ"><span>Titre du catalogue</span><input type="text" maxlength="160" data-cm="titre" value="' + esc(S.titre) + '"></label>'
        + '<div class="cm-msg inf">Ce catalogue est parti de ' + esc(src) + '. Le point de départ ne change plus ; les produits se choisissent à l’étape 2.</div>'
        + '<div class="cm-pied"><span class="sp"></span><button class="cm-btn pri" data-cm="etape" data-v="2"' + (S.occupe ? ' disabled' : '') + '>Mes produits ›</button></div>';
      return h + '</section>' + cote(S, resume(S)) + '</div>';
    }
    h += '<label class="cm-champ"><span>Titre du catalogue</span><input type="text" maxlength="160" data-cm="titre" placeholder="Mes bûches 2026" value="' + esc(S.titre) + '"></label>';
    if (!S.prod && !S.prodErr) { h += '<div class="cm-vide">Lecture des produits et des gammes de ma boutique…</div>'; }
    else if (S.prod) {
      const prods = produits(S);
      h += '<div class="cm-champ"><span>Je pars de…</span></div><div class="cm-src">';
      gammes(S).forEach(g => {
        const n = prods.filter(p => (p.gammes || []).map(Number).includes(+g.id)).length;
        const quand = g.from || g.to ? ' · ' + [g.from ? 'du ' + date(g.from) : '', g.to ? 'au ' + date(g.to) : ''].filter(Boolean).join(' ') : '';
        h += '<button data-cm="src" data-v="' + esc(g.id) + '" class="' + (S.src === String(g.id) ? 'on' : '') + '"' + (n ? '' : ' disabled') + '><span class="n">Gamme ' + esc(g.name) + '</span><span class="d">' + n + ' produit' + (n > 1 ? 's' : '') + ' dans ma boutique' + esc(quand) + '</span></button>';
      });
      h += '<button data-cm="src" data-v="tous" class="' + (S.src === 'tous' ? 'on' : '') + '"' + (prods.length ? '' : ' disabled') + '><span class="n">Tous mes produits</span><span class="d">' + prods.length + ' produit' + (prods.length > 1 ? 's' : '') + ' de mon webshop</span></button></div>';
      if (!prods.length) { h += '<div class="cm-msg inf" style="margin-top:12px">Le Marketing ne trouve aucun produit au webshop de ma boutique : rien à mettre au catalogue pour l’instant.</div>'; }
    }
    h += '<div class="cm-pied"><span class="sp"></span><button class="cm-btn pri" data-cm="creer"' + (S.occupe || !S.prod ? ' disabled' : '') + '>' + (S.occupe ? 'Création…' : 'Créer et choisir mes produits ›') + '</button></div>';
    return h + '</section>' + cote(S, resume(S)) + '</div>';
  }

  function rendreE2(S) {
    let h = '<div class="cm-corps"><section class="cm-pan"><div class="h"><span class="k">Produits disponibles dans ma boutique</span><span class="sp"></span>'
      + '<span class="cm-tag vif">' + choisis(S).length + ' choisis</span><span class="cm-tag">prix de mon webshop</span></div>';
    if (!S.prod) { return h + (S.prodErr ? '' : '<div class="cm-vide">Lecture des produits…</div>') + '</section></div>'; }
    h += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px"><input type="search" class="cm-rech" data-cm="rech" placeholder="Chercher un produit" value="' + esc(S.rech) + '">'
      + '<span style="flex:1"></span><button class="cm-btn" data-cm="tout" data-v="1">Tout cocher</button><button class="cm-btn" data-cm="tout" data-v="0">Tout décocher</button></div>';
    const vis = visibles(S);
    const groupes = new Map();
    vis.forEach(p => { const k = p.categoryId == null ? '' : String(p.categoryId); if (!groupes.has(k)) { groupes.set(k, []); } groupes.get(k).push(p); });
    const ordre = categories(S).map(c => String(c.id)).filter(k => groupes.has(k)).concat(Array.from(groupes.keys()).filter(k => !categories(S).some(c => String(c.id) === k)));
    if (!vis.length) { h += '<div class="cm-vide">' + (S.rech ? 'Aucun produit ne répond à « ' + esc(S.rech) + ' ».' : 'Aucun produit au webshop de ma boutique.') + '</div>'; }
    ordre.forEach(k => {
      const liste = groupes.get(k);
      h += '<div class="cm-cat">' + esc(k === '' ? 'Sans catégorie' : nomCat(S, +k)) + ' · ' + liste.length + '<button class="cm-btn" style="padding:3px 9px;font-size:11px" data-cm="tout" data-v="1" data-cat="' + esc(k === '' ? 'null' : k) + '">Toute la catégorie</button></div><div class="cm-prods">';
      liste.forEach(p => {
        const on = !!S.choix[p.id];
        const ecart = p.shopPriceCents != null && p.priceCents != null && p.shopPriceCents !== p.priceCents;
        h += '<button class="cm-prod' + (on ? ' on' : '') + '" data-cm="prod" data-v="' + p.id + '" aria-pressed="' + on + '">'
          + '<span class="cb" aria-hidden="true"></span><span class="ph">' + (p.imageUrl ? '<img src="' + esc(p.imageUrl) + '" alt="" loading="lazy">' : '<i>sans photo</i>') + '</span>'
          + '<span class="nm">' + esc(p.name) + '</span><span class="px">' + esc(nomCat(S, p.categoryId)) + '<b class="' + (p.priceCents == null ? 'mu' : '') + '"' + (ecart ? ' title="Au comptoir : ' + esc(prix(p.shopPriceCents)) + ' — le catalogue prend le prix du webshop"' : '') + '>' + (p.priceCents == null ? 'sans prix' : esc(prix(p.priceCents))) + '</b></span></button>';
      });
      h += '</div>';
    });
    // Les produits du catalogue que la boutique ne vend plus : dits, grisés.
    const vendus = new Set(produits(S).map(p => +p.id));
    const plus = (S.cat.products || []).filter(p => p.on !== false && !vendus.has(+p.id));
    if (plus.length) { h += '<div class="cm-msg inf" style="margin-top:12px">' + plus.length + ' produit(s) de ce catalogue ne sont plus vendus au webshop de ma boutique : ils sortiront du catalogue à l’enregistrement.</div>'; }
    h += '<div class="cm-msg inf" style="margin-top:12px">Seuls les produits de MON webshop sont proposés, à MES prix. Changer les produits refait la mise en page automatiquement.</div>';
    h += '<div class="cm-pied"><button class="cm-btn" data-cm="etape" data-v="1">‹ Point de départ</button><span class="sp"></span><button class="cm-btn pri" data-cm="etape" data-v="3"' + (S.occupe ? ' disabled' : '') + '>' + (S.occupe ? 'Enregistrement…' : 'Mise en page ›') + '</button></div>';
    return h + '</section>' + cote(S, resume(S)) + '</div>';
  }

  function mini(layout) {
    const n = layout === 'vedette' ? 5 : layout === 'grille' ? 6 : 6;
    return '<span class="mini ' + layout + '" aria-hidden="true">' + '<i></i>'.repeat(n) + '</span>';
  }
  function rendreE3(S) {
    const prods = produits(S), ids = new Set(choisis(S));
    const parCat = {}; prods.forEach(p => { if (ids.has(+p.id)) { const k = p.categoryId == null ? 'null' : String(p.categoryId); parCat[k] = (parCat[k] || 0) + 1; } });
    const capCat = {}; (S.pages || []).forEach(p => { const k = p.category == null ? 'null' : String(p.category); capCat[k] = (capCat[k] || 0) + (LAYOUTS[p.layout] ? LAYOUTS[p.layout].cap : 0); });
    const cats = catsChoisies(S);
    let h = '<div class="cm-corps"><section class="cm-pan"><div class="h"><span class="k">Mise en page</span><span class="sp"></span><button class="cm-btn" data-cm="repartir"' + (S.occupe ? ' disabled' : '') + '>↺ Répartir automatiquement</button></div>';
    h += '<div class="cm-reglages"><div class="cm-champ"><span>Format</span><div class="cm-seg">' + ['A5', 'A4'].map(f => '<button data-cm="format" data-v="' + f + '" class="' + (S.format === f ? 'on' : '') + '">' + f + '</button>').join('') + '</div></div>'
      + '<div class="cm-champ"><span>Langues</span><div class="cm-cases">' + [['fr', 'Français'], ['nl', 'Néerlandais']].map(l => '<label><input type="checkbox" data-cm="langs" value="' + l[0] + '"' + (S.langs.includes(l[0]) ? ' checked' : '') + '> ' + l[1] + '</label>').join('') + '</div></div></div>';
    h += '<div class="cm-pages">';
    h += '<div class="cm-pg lock"><span class="no">1</span><span class="mini cover" aria-hidden="true"></span><div><div class="t">Couverture</div><div class="s">Charte de la marque · le nom de ma boutique</div></div><span class="cm-lock">' + CADENAS + ' Marque</span><span></span></div>';
    (S.pages || []).forEach((p, i) => {
      const k = p.category == null ? 'null' : String(p.category);
      const n = parCat[k] || 0, cap = LAYOUTS[p.layout] ? LAYOUTS[p.layout].cap : 0;
      const options = cats.filter(c => c !== null).concat(p.category != null && !cats.includes(p.category) ? [p.category] : []);
      h += '<div class="cm-pg"><span class="no">' + (i + 2) + '</span>' + mini(p.layout)
        + '<div><select class="cm-sel" data-cm="pcat" data-i="' + i + '" aria-label="Catégorie de la page ' + (i + 2) + '"><option value=""' + (p.category == null ? ' selected' : '') + '>Notre sélection (toutes catégories)</option>'
        + options.map(c => '<option value="' + c + '"' + (+p.category === +c ? ' selected' : '') + '>' + esc(nomCat(S, c)) + '</option>').join('') + '</select>'
        + '<div class="cm-cpt' + (p.category != null && (capCat[k] || 0) < n ? ' ko' : '') + '" style="margin-top:4px">' + (p.category == null ? 'les produits qui restent, ' + cap + ' places' : n + ' produit' + (n > 1 ? 's' : '') + ' choisi' + (n > 1 ? 's' : '') + ' dans cette catégorie · ' + cap + ' places sur cette page') + '</div></div>'
        + '<div class="ctl"><div class="cm-seg">' + Object.keys(LAYOUTS).map(l => '<button data-cm="layout" data-i="' + i + '" data-v="' + l + '" class="' + (p.layout === l ? 'on' : '') + '">' + LAYOUTS[l].label + '</button>').join('') + '</div></div>'
        + '<div class="mv"><button class="cm-btn" data-cm="monter" data-v="' + i + '"' + (i === 0 ? ' disabled' : '') + ' aria-label="Monter">↑</button><button class="cm-btn" data-cm="descendre" data-v="' + i + '"' + (i === S.pages.length - 1 ? ' disabled' : '') + ' aria-label="Descendre">↓</button><button class="cm-btn dis" data-cm="retirer" data-v="' + i + '" aria-label="Retirer la page">✕</button></div></div>';
    });
    h += '<div><button class="cm-btn" data-cm="ajouter"' + ((S.pages || []).length >= 40 ? ' disabled' : '') + '>＋ Ajouter une page</button></div>';
    h += '<div class="cm-pg lock"><span class="no">' + ((S.pages || []).length + 2) + '</span><span class="mini" aria-hidden="true" style="background:#F6F1E9"></span><div><div class="t">Dernière page</div><div class="s">Commande en ligne, QR de mon webshop, adresse et horaires de ma boutique</div></div><span class="cm-lock">' + CADENAS + ' Marque</span><span></span></div>';
    h += '</div>';
    const trop = Object.keys(parCat).filter(k => k !== 'null' && (capCat[k] || 0) < parCat[k]);
    if (trop.length) { h += '<div class="cm-msg inf" style="margin-top:12px">Pas assez de place pour : ' + trop.map(k => esc(nomCat(S, +k)) + ' (' + parCat[k] + ' produits, ' + (capCat[k] || 0) + ' places)').join(', ') + '. Ajoutez une page, choisissez une mise en page plus dense, ou une page « Notre sélection ».</div>'; }
    h += '<div class="cm-pied"><button class="cm-btn" data-cm="etape" data-v="2">‹ Mes produits</button><span class="sp"></span>' + (S.modifie ? '<span class="cm-cpt">modifications non enregistrées</span>' : '')
      + '<button class="cm-btn pri" data-cm="etape" data-v="4"' + (S.occupe ? ' disabled' : '') + '>' + (S.occupe ? 'Enregistrement…' : 'Aperçu et PDF ›') + '</button></div>';
    return h + '</section>' + cote(S, resume(S)) + '</div>';
  }

  function rendreE4(S) {
    const k = S.cat.id + '|' + S.lang, doc = S.doc[k], err = S.docErr[k];
    let h = '<section class="cm-pan"><div class="h"><span class="k">Aperçu · ' + (S.cat.pageCount || '') + ' pages ' + esc(S.cat.format) + '</span>'
      + '<span class="cm-et ' + esc(S.cat.status) + '">' + esc(STATUTS[S.cat.status] || S.cat.status) + '</span><span class="sp"></span>'
      + '<div class="cm-seg">' + [['fr', 'FR'], ['nl', 'NL']].map(l => '<button data-cm="lang" data-v="' + l[0] + '" class="' + (S.lang === l[0] ? 'on' : '') + '">' + l[1] + '</button>').join('') + '</div>'
      + (S.cat.status === 'pret' ? '<button class="cm-btn" data-cm="statut" data-st="imprime" data-v="' + S.cat.id + '">Marquer imprimé</button>' : '')
      + '<button class="cm-btn pri" data-cm="pdf"' + (S.occupe || !doc ? ' disabled' : '') + '>' + (S.occupe ? 'Préparation…' : 'Télécharger le PDF') + '</button></div>';
    if (!S.langs.includes(S.lang)) { h += '<div class="cm-msg inf">Ce catalogue est réglé en ' + S.langs.map(l => l.toUpperCase()).join(' + ') + ' : la version ' + S.lang.toUpperCase() + ' se regarde, ajoutez la langue à l’étape 3 pour la garder.</div>'; }
    if (err) { return h + bandeauErreur(err, 'L’aperçu n’a pas pu être composé') + '<button class="cm-btn" data-cm="reessayer">Réessayer</button></section>'; }
    if (!doc) { return h + '<div class="cm-vide">Composition des pages par le Marketing…</div></section>'; }
    if (doc.warnings && doc.warnings.length) {
      h += '<div class="cm-msg inf"><b>À savoir avant d’imprimer</b><ul>' + doc.warnings.map(w => '<li>' + esc(w.message) + '</li>').join('') + '</ul></div>';
    }
    h += '<div class="cm-apercu" data-cm-apercu="1">' + doc.pages.map((p, i) => feuille(doc, i, 300)).join('') + '</div>';
    h += '<div class="cm-pied"><button class="cm-btn" data-cm="etape" data-v="3">‹ Mise en page</button><span class="sp"></span><span class="cm-cpt">Le PDF s’ouvre sur la page d’impression du Marketing (lien valable 48 h) : « Enregistrer au format PDF ».</span></div>';
    return h + '</section>';
  }

  /* --- les pages (rendu du Marketing, src/views/catalogue/CataloguePages.tsx) ----------------------------------- */
  function feuille(doc, i, largeurPx) {
    const p = doc.pages[i];
    const f = FORMAT_MM[doc.catalogue.format] || FORMAT_MM.A5;
    const z = largeurPx / (f.w * PX_MM);
    const leg = p.kind === 'cover' ? 'Couverture' : p.kind === 'back' ? 'Dernière page' : 'Page ' + (i + 1) + ' · ' + (LAYOUTS[p.layout] ? LAYOUTS[p.layout].label : '') + ((p.products || []).length ? '' : ' · vide');
    return '<div><div class="cm-feuille" style="width:' + largeurPx + 'px;height:' + Math.round(f.h * PX_MM * z) + 'px">'
      + (p.locked ? '<span class="cad cm-lock">' + CADENAS + ' Marque</span>' : '')
      + '<div class="pz" style="transform:scale(' + z + ');width:' + f.w + 'mm;height:' + f.h + 'mm"><div style="position:absolute;left:-' + BLEED + 'mm;top:-' + BLEED + 'mm">' + page(doc, i) + '</div></div></div>'
      + '<div class="cm-leg">' + esc(leg) + '</div></div>';
  }
  function page(doc, i) {
    const p = doc.pages[i];
    const f = FORMAT_MM[doc.catalogue.format] || FORMAT_MM.A5;
    const c = (doc.charter && doc.charter.colors) || {};
    const st = 'width:' + (f.w + BLEED * 2) + 'mm;height:' + (f.h + BLEED * 2) + 'mm;--u:' + (f.w / 148) + ';--b:' + BLEED + 'mm;--p:' + css(c.primary, '#8D1D2C') + ';--s:' + css(c.secondary, '#F6EFE5') + ';--a:' + css(c.accent, '#B0792A') + ';--i:' + css(c.ink, '#241C1A');
    let corps = '';
    if (p.kind === 'cover') { corps = couverture(doc, p); }
    else if (p.kind === 'back') { corps = dos(doc, p); }
    else { corps = interieur(doc, p); }
    return '<div class="cat-face cat-face--' + esc(p.kind) + '" style="' + st + '" lang="' + esc(doc.version.lang) + '"><div class="cat-trim">' + corps + '</div></div>';
  }
  const css = (v, d) => /^#[0-9a-f]{3,8}$/i.test(String(v || '')) ? v : d;
  const logo = doc => doc.charter && doc.charter.logoUrl ? doc.charter.logoUrl : '../assets/img/logo.png';
  function couverture(doc, p) {
    return '<div class="cat-cover"><img class="cat-logo cat-logo--white" src="' + esc(logo(doc)) + '" alt="">'
      + '<div class="cat-cover__title">' + esc(p.title) + '</div>'
      + (p.subtitle ? '<div class="cat-cover__sub">' + esc(p.subtitle) + '</div>' : '')
      + (p.eyebrow ? '<div class="cat-cover__shop">L’Atelier By · ' + esc(p.eyebrow) + '</div>' : '')
      + (p.imageUrl ? '<div class="cat-cover__visual"><img src="' + esc(p.imageUrl) + '" alt=""></div>' : '') + '</div>';
  }
  function photo(pr) {
    return '<span class="cat-photo">' + (pr.imageUrl ? '<img src="' + esc(pr.imageUrl) + '" alt="">' : '<span class="cat-photo__none"></span>') + '</span>';
  }
  function carte(pr, lang, grand) {
    return '<div class="cat-card' + (grand ? ' cat-card--big' : '') + '">' + photo(pr) + '<div class="cat-card__text"><div class="cat-name">' + esc(pr.name) + '</div>'
      + '<span class="cat-price' + (pr.priceCents == null ? ' cat-price--none' : '') + '">' + esc(prix(pr.priceCents, lang)) + '</span></div></div>';
  }
  function interieur(doc, p) {
    const lang = doc.version.lang, pr = p.products || [];
    let b = '';
    if (p.layout === 'liste') {
      b = '<div class="cat-list">' + pr.map(x => '<div class="cat-row">' + photo(x) + '<span class="cat-name">' + esc(x.name) + '</span><i></i><span class="cat-price' + (x.priceCents == null ? ' cat-price--none' : '') + '">' + esc(prix(x.priceCents, lang)) + '</span></div>').join('') + '</div>';
    } else if (p.layout === 'vedette' && pr.length) {
      b = carte(pr[0], lang, true) + '<div class="cat-grid cat-grid--2x2">' + pr.slice(1).map(x => carte(x, lang, false)).join('') + '</div>';
    } else {
      b = '<div class="cat-grid cat-grid--2x3">' + pr.map(x => carte(x, lang, false)).join('') + '</div>';
    }
    return '<div class="cat-inner cat-inner--' + esc(p.layout) + '"><div class="cat-h">' + esc(p.title) + '</div><div class="cat-body">' + b + '</div></div>';
  }
  function qr(url, mm) {
    if (typeof window.qrcode !== 'function') { return ''; }
    try {
      const q = window.qrcode(0, 'M'); q.addData(url); q.make();
      const n = q.getModuleCount(); let d = '';
      for (let r = 0; r < n; r++) { for (let c = 0; c < n; c++) { if (q.isDark(r, c)) { d += 'M' + (c + 2) + ' ' + (r + 2) + 'h1v1h-1z'; } } }
      return '<svg class="cat-qr" viewBox="0 0 ' + (n + 4) + ' ' + (n + 4) + '" width="' + mm + 'mm" height="' + mm + 'mm" shape-rendering="crispEdges" aria-label="' + esc(url) + '"><rect width="' + (n + 4) + '" height="' + (n + 4) + '" fill="#fff"/><path d="' + d + '" fill="#000"/></svg>';
    } catch (e) { return ''; }
  }
  function dos(doc, p) {
    const lang = doc.version.lang, s = p.shop;
    let h = '<div class="cat-back"><img class="cat-logo" src="' + esc(logo(doc)) + '" alt=""><div class="cat-back__title">' + esc(p.title) + '</div>'
      + (p.text ? '<div class="cat-back__text">' + esc(p.text) + '</div>' : '');
    if (p.qrUrl) {
      h += '<div class="cat-back__qr">' + qr(p.qrUrl, 30) + '<div><div class="cat-back__host">' + esc(p.host || '') + '</div><div class="cat-back__hint">'
        + esc(s ? (lang === 'nl' ? 'De webshop van ' + s.name : 'Le webshop de ' + s.name) : (lang === 'nl' ? 'Al onze winkels' : 'Toutes nos boutiques')) + '</div></div></div>';
    }
    h += '<div class="cat-back__shop"><div class="cat-back__who">' + esc(s ? 'L’Atelier By — ' + s.name : 'L’Atelier By') + '</div>';
    if (s) {
      const lignes = [s.address, s.hours ? (lang === 'nl' ? 'Open ' + s.hours : 'Ouvert ' + s.hours) : '', s.phone].filter(Boolean);
      h += '<p>' + lignes.map(esc).join('<br>') + '</p>';
    } else {
      h += '<p>' + esc((lang === 'nl' ? 'Al onze winkels op ' : 'Toutes nos boutiques sur ') + (p.host || '')) + '</p>';
    }
    return h + '</div></div>';
  }

  function rendre(S) {
    S.el.innerHTML = S.vue === 'liste' ? rendreListe(S) : rendreAssistant(S);
  }

  window.DbCatalogue = {
    /** L'élément du module pour ce magasin (créé à la première demande, son état gardé ensuite). */
    racine(shop, nom) {
      const S = etat(String(shop));
      S.nom = nom || '';
      if (!S.el.childNodes.length) {
        rendre(S);
        if (S.vue === 'liste' && !S.liste) { chargerListe(S); }
      }
      return S.el;
    },
  };
})();
