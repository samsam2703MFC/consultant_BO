/* Dashboard › Mon catalogue (14/11/2026, Sam : « dans le dashboard, un module où les franchisés peuvent créer leur
 * catalogue, sous contrainte » ; maquette validée : liste, puis un assistant en quatre étapes).
 *
 * Les catalogues vivent dans le portail Marketing ; ce module parle à l'API du cockpit à côté de lui
 * (../api/cockpit/dashboard/catalogues…), qui lit la boutique dans la session, ajoute le jeton de service et relaie
 * au Marketing (src/catalogues_magasin.php). Le navigateur ne voit jamais le jeton. Les contraintes (produits vendus
 * par la boutique, ses prix, 8 à 16 pages par 2 dont les quatre de la marque — couverture, page 2, avant-dernière et
 * dernière —, cinq mises en page dont les présentations de gamme, gabarits A · B · C, place libre — photo de la
 * médiathèque du réseau ou de ma boutique, ou illustration) sont celles du Marketing : ses refus s'affichent tels quels.
 * Les pages se dessinent comme celles du Marketing (src/views/catalogue/CataloguePages.tsx), mêmes classes `cat-*`.
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
    presentation: { label: 'Présentation seule', cap: 0 },
    presentation_liste: { label: 'Présentation + liste', cap: 8 },
  };
  const estPres = l => l === 'presentation' || l === 'presentation_liste';
  /** Les variantes de gabarit (2-3 possibilités pour chaque mise en page), les mêmes que le Marketing. */
  const GABARITS = {
    produits: { A: ['Classique', 'fond papier, titre centré'], B: ['Bandeau', 'bandeau de couleur, titre à gauche'], C: ['Épuré', 'fond blanc, titre à gauche'] },
    presentation: { A: ['Illustration', 'dessin au centre, fond papier'], B: ['Pleine couleur', 'fond de couleur, dessin en blanc'], C: ['Photo pleine', 'grande image en haut'] },
  };
  /** Une rangée entière libre : la place libre peut prendre une photo ou l'illustration (la règle du Marketing). */
  const remplissable = (l, n) => l === 'grille' ? n <= 4 : l === 'vedette' ? n <= 3 : l === 'liste' ? n <= 9 : false;
  /** Rangées de la grille restées libres, que la place libre prend en pleine largeur. */
  const rangsLibres = (l, n) => l === 'grille' ? 3 - Math.ceil(n / 2) : l === 'vedette' ? 2 - Math.ceil(Math.max(0, n - 1) / 2) : 0;
  const STATUTS = { brouillon: 'Brouillon', pret: 'PDF prêt', imprime: 'Imprimé' };
  /** De 8 à 16 pages, toujours par 2 (« rajouter une page, c'est toujours par 2 »), dont 4 de la marque (1, 2,
   * avant-dernière, dernière) ; le Marketing en dit la liste. */
  const NB_PAGES = [8, 10, 12, 14, 16];
  const PAGES_MARQUE = 4;
  const FORMAT_MM = { A5: { w: 148, h: 210 }, A4: { w: 210, h: 297 } };
  const BLEED = 3;
  const PX_MM = 96 / 25.4;
  const ETAPES = [
    ['Point de départ', 'mes gammes, ou tous mes produits'],
    ['Je clique mes produits', 'ceux de MA boutique, à mes prix'],
    ['Mise en page', 'pages par 2, gabarits, présentations'],
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
        occupe: false, rech: '', modifie: false, nbPages: 8, ouverts: null, proposer: null,
        medias: null, mediasErr: null, mediasQ: '', choixPhoto: null };
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
    S.pages = (c.pages || []).map(p => ({ layout: p.layout, category: p.category == null ? null : +p.category, template: p.template || 'A',
      gamme: p.gamme == null ? null : +p.gamme, text: p.text || '', textNl: p.textNl || '', filler: p.filler || null, fillerMediaId: p.fillerMediaId == null ? null : +p.fillerMediaId }));
    S.format = c.format || 'A5';
    S.nbPages = NB_PAGES.includes(+c.pageCount) ? +c.pageCount : 8;
    S.langs = (c.langs && c.langs.length ? c.langs : ['fr']).slice();
    if (!S.langs.includes(S.lang)) { S.lang = S.langs[0]; }
    // Promotions de TFBuddy : les choix explicites ; une offre absente est cochée si elle touche un produit coché.
    S.offres = {}; (c.offers || []).forEach(o => { S.offres[o.type + '-' + o.id] = !!o.on; });
    S.dispo = c.showAvailability !== false;
    S.modifie = false;
  }
  /** Les promotions de TFBuddy qui valent pour ma boutique (lues avec ses produits). */
  const offres = S => (S.prod && S.prod.offers) || [];
  /** Une offre est-elle cochée ? Le choix, sinon : touche-t-elle un produit coché ? (la règle du Marketing) */
  function offreOn(S, o) {
    if (Object.prototype.hasOwnProperty.call(S.offres || {}, o.id)) { return !!S.offres[o.id]; }
    const ids = new Set(choisis(S));
    const cats = new Set(produits(S).filter(p => ids.has(+p.id) && p.categoryId != null).map(p => +p.categoryId));
    return (o.productIds || []).some(id => ids.has(+id)) || (o.categoryIds || []).some(id => cats.has(+id));
  }
  /** Les choix à envoyer : seulement les explicites, ceux d'une offre encore proposée. */
  function offresCorps(S) {
    const connues = new Set(offres(S).map(o => o.id));
    return Object.keys(S.offres || {}).filter(k => connues.has(k)).map(k => { const o = offres(S).find(x => x.id === k); return { type: o.type, id: +o.erpId, on: !!S.offres[k] }; });
  }

  /** Les pages intérieures telles que le Marketing les attend (seulement ce qui ne vaut pas son défaut). */
  function pagesCorps(S) {
    return S.pages.map(p => {
      const o = { layout: p.layout, category: estPres(p.layout) ? null : p.category };
      if (p.template && p.template !== 'A') { o.template = p.template; }
      if (p.gamme != null) { o.gamme = p.gamme; }
      if (estPres(p.layout)) {
        if (p.text) { o.text = p.text; }
        if (p.textNl) { o.textNl = p.textNl; }
      } else if (p.filler) {
        o.filler = p.filler;
        if (p.filler === 'photo') { o.fillerMediaId = p.fillerMediaId; }
      }
      return o;
    });
  }
  /** Les images de la médiathèque pour la place libre (réseau et celles de ma boutique). */
  function chargerMedias(S) {
    S.mediasErr = null;
    const q = S.mediasQ.trim();
    return appel('GET', '/medias' + qs(S) + (q ? '&q=' + encodeURIComponent(q) : '')).then(j => {
      S.medias = j.data || [];
      S.mediaUrls = S.mediaUrls || {};
      S.medias.forEach(m => { S.mediaUrls[m.id] = m.thumbnailUrl || m.url; });
    }).catch(e => { S.mediasErr = e; }).finally(() => rendre(S));
  }
  /** Ce que chaque page porte, calculé comme le Marketing (`fill`) : catégorie et gamme de la page, « Notre sélection »
   * pour le reste, une présentation avec liste prend les premiers produits de sa gamme. */
  function remplir(S) {
    const ids = choisis(S);
    const prods = ids.map(id => produits(S).find(p => +p.id === id)).filter(Boolean)
      .map(p => ({ id: +p.id, cat: p.categoryId == null ? 0 : +p.categoryId, gammes: (p.gammes || []).map(Number) }));
    // L'ordre du catalogue : celui de ses produits, à défaut celui du webshop.
    const rang = {}; (S.cat.products || []).forEach((p, i) => { rang[+p.id] = i; });
    prods.sort((a, b) => (rang[a.id] ?? 1e6) - (rang[b.id] ?? 1e6));
    const ordreCat = {}; prods.forEach(p => { if (!(p.cat in ordreCat)) { ordreCat[p.cat] = Object.keys(ordreCat).length; } });
    const parCat = prods.slice().sort((a, b) => ordreCat[a.cat] - ordreCat[b.cat]);
    const pris = new Set();
    const out = (S.pages || []).map(p => {
      const cap = LAYOUTS[p.layout] ? LAYOUTS[p.layout].cap : 6;
      const liste = estPres(p.layout) ? prods : parCat;
      let n = 0;
      for (const x of liste) {
        if (n >= cap) { break; }
        if (pris.has(x.id) || (p.category != null && x.cat !== +p.category) || (p.gamme != null && !x.gammes.includes(+p.gamme))) { continue; }
        pris.add(x.id); n++;
      }
      return n;
    });
    out.sansPlace = prods.filter(x => !pris.has(x.id)).length;
    return out;
  }

  /* --- les gestes ----------------------------------------------------------------------------------------------- */
  function erreurMsg(S, e, avant) {
    // « Produit 107 : … » se lit avec le nom du produit quand on le connaît.
    const nom = id => { const p = produits(S).find(x => +x.id === +id); return p ? '« ' + p.name + ' »' : 'Produit ' + id; };
    const det = (e.details || []).map(d => {
      const msg = String(d.message || '').replace(/^Produit (\d+)/, (t, id) => nom(id));
      return /^(products|pages)\[/.test(d.field || '') || !d.field ? msg : d.field + ' : ' + msg;
    });
    return { type: 'err', titre: (avant ? avant + ' — ' : '') + e.message, details: det };
  }
  function occupe(S, p, avant) {
    S.occupe = true; S.msg = null; S.proposer = null; rendre(S);
    return p.catch(e => { S.msg = erreurMsg(S, e, avant); }).finally(() => {
      S.occupe = false; rendre(S);
      // Un refus doit se voir, même si l'on est en bas de la liste des produits.
      const m = S.msg && S.el.querySelector('.cm-msg');
      if (m && m.scrollIntoView) { m.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    });
  }
  function nouveau(S) {
    S.vue = 'assistant'; S.etape = 1; S.cat = null; S.src = null; S.titre = ''; S.titreAuto = ''; S.choix = null; S.pages = null;
    S.format = 'A5'; S.nbPages = 8; S.ouverts = null; S.langs = ['fr']; S.lang = 'fr'; S.msg = null; S.rech = ''; oublierDocs(S);
    chargerProduits(S); rendre(S);
  }
  function ouvrir(S, id, etape) {
    S.vue = 'assistant'; S.msg = null; S.rech = ''; S.ouverts = null; oublierDocs(S);
    chargerProduits(S);
    const c = (S.liste || []).find(x => +x.id === +id);
    if (c) { adopter(S, c); S.etape = etape || 2; }
    occupe(S, appel('GET', '/' + id + qs(S)).then(j => { adopter(S, j.data); S.etape = etape || 2; if (S.etape === 4) { chargerDoc(S); } }), 'Lecture du catalogue');
  }
  function creer(S) {
    const titre = S.titre.trim();
    if (!titre) { S.msg = { type: 'err', titre: 'Donnez un titre à votre catalogue.' }; rendre(S); return; }
    if (!S.src || (Array.isArray(S.src) && !S.src.length)) { S.msg = { type: 'err', titre: 'Choisissez le point de départ : une ou plusieurs gammes, ou tous mes produits.' }; rendre(S); return; }
    const corps = { title: titre, format: 'A5', pageCount: S.nbPages, langs: ['fr'] };
    if (S.src === 'tous') {
      corps.source = { kind: 'choix' };
      corps.products = produits(S).map(p => ({ id: +p.id, on: true }));
    } else {
      // Une gamme : un entier ; plusieurs (8 au plus) : la liste, produits cumulés par le Marketing.
      corps.source = { kind: 'gamme', ref: S.src.length === 1 ? +S.src[0] : S.src.map(Number) };
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
    let corps = avant === apres ? null : { products: lignes, pages: [] };
    // Promotions et disponibilité : enregistrées avec les produits (une offre cochée prend une case de sa page).
    const offresAvant = JSON.stringify((S.cat.offers || []).map(o => [o.type, +o.id, !!o.on]).sort());
    const offresApres = offresCorps(S);
    if (JSON.stringify(offresApres.map(o => [o.type, o.id, o.on]).sort()) !== offresAvant) { corps = Object.assign(corps || {}, { offers: offresApres }); }
    if (S.dispo !== (S.cat.showAvailability !== false)) { corps = Object.assign(corps || {}, { showAvailability: S.dispo }); }
    const p = majTitre(S).then(() => corps ? appel('PUT', '/' + S.cat.id + qs(S), corps).then(j => { adopter(S, j.data); oublierDocs(S); }) : null)
      .then(() => { S.etape = suite; S.liste = null; if (suite === 4) { chargerDoc(S); } });
    occupe(S, p, 'Enregistrement des produits');
  }
  function enregistrerPages(S, suite) {
    const corps = { format: S.format, langs: S.langs.slice(), pages: pagesCorps(S) };
    const p = majTitre(S).then(() => appel('PUT', '/' + S.cat.id + qs(S), corps)).then(j => { adopter(S, j.data); oublierDocs(S); S.etape = suite; S.liste = null; if (suite === 4) { chargerDoc(S); } });
    occupe(S, p, 'Enregistrement de la mise en page');
  }
  function repartir(S, nb) {
    const corps = nb ? { pageCount: nb, pages: [] } : { pages: [] };
    S.proposer = null;
    occupe(S, appel('PUT', '/' + S.cat.id + qs(S), corps).then(j => { adopter(S, j.data); oublierDocs(S); }), 'Répartition automatique');
  }
  /** Le nombre de pages : enregistré tout de suite (les pages non enregistrées d'abord). Réduire quand une page à
   * retirer porte des produits : le Marketing refuse (CATALOGUE_PAGES_NOT_EMPTY) ; on propose de répartir. */
  function changerNbPages(S, nb) {
    if (!S.cat) { S.nbPages = nb; rendre(S); return; }
    if (nb === S.nbPages) { return; }
    S.proposer = null;
    const avant = S.modifie ? appel('PUT', '/' + S.cat.id + qs(S), { format: S.format, langs: S.langs.slice(), pages: pagesCorps(S) }) : Promise.resolve();
    const p = avant.then(() => appel('PUT', '/' + S.cat.id + qs(S), { pageCount: nb })).then(j => { adopter(S, j.data); oublierDocs(S); S.liste = null; })
      .catch(e => { if (e.code === 'CATALOGUE_PAGES_NOT_EMPTY') { S.proposer = nb; } throw e; });
    occupe(S, p, 'Passage à ' + nb + ' pages');
  }
  /** Le type d'une page : une présentation prend une gamme (la sienne, sinon la première du catalogue). */
  function changerType(S, i, l) {
    const p = S.pages[i];
    if (estPres(l)) {
      const g = p.gamme != null ? p.gamme : gammesCatalogue(S)[0];
      if (g == null) { S.msg = { type: 'inf', titre: 'Aucune gamme au webshop de ma boutique : pas de page de présentation possible.' }; rendre(S); return; }
      p.gamme = g; p.category = null; p.filler = null; p.fillerMediaId = null;
    }
    p.layout = l; S.modifie = true; rendre(S);
  }
  /** Les gammes proposées pour une page : celles du catalogue d'abord, puis les autres de ma boutique. */
  function gammesCatalogue(S) {
    const src = (S.cat && S.cat.source) || {};
    const refs = src.kind === 'gamme' ? (Array.isArray(src.refs) && src.refs.length ? src.refs : (src.ref != null ? [src.ref] : [])).map(Number) : [];
    return refs.concat(gammes(S).map(g => +g.id).filter(id => !refs.includes(id)));
  }
  const nomGamme = (S, id) => { const g = gammes(S).find(x => +x.id === +id); return g ? g.name : 'Gamme ' + id; };
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

  /** Étape 1 : « Tous mes produits » est exclusif ; les gammes se cochent (8 au plus). Le titre proposé suit les
   * gammes cochées (« Noël + Saint-Nicolas ») tant qu'il n'a pas été réécrit. */
  const MAX_GAMMES = 8;
  function choisirSource(S, v) {
    if (v === 'tous') { S.src = S.src === 'tous' ? null : 'tous'; }
    else {
      const l = Array.isArray(S.src) ? S.src.slice() : [];
      const i = l.indexOf(v);
      if (i >= 0) { l.splice(i, 1); } else if (l.length < MAX_GAMMES) { l.push(v); } else { S.msg = { type: 'inf', titre: MAX_GAMMES + ' gammes au plus dans un catalogue.' }; }
      S.src = l.length ? l : null;
    }
    const propose = Array.isArray(S.src) ? S.src.map(id => (gammes(S).find(g => String(g.id) === id) || {}).name).filter(Boolean).join(' + ') : '';
    if (!S.titre.trim() || S.titre === S.titreAuto) { S.titre = propose; S.titreAuto = propose; }
    rendre(S);
  }
  /** Les gammes d'un catalogue : `source.refs` (toutes), à défaut `source.ref`. */
  function gammesDe(S, c) {
    const src = (c && c.source) || {};
    const refs = Array.isArray(src.refs) && src.refs.length ? src.refs : (src.ref != null ? [src.ref] : []);
    const noms = refs.map(r => (gammes(S).find(g => String(g.id) === String(r)) || {}).name).filter(Boolean);
    return noms.length === refs.length && noms.length ? noms : (src.label ? [src.label] : noms);
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
    else if (a === 'src') { choisirSource(S, v); }
    else if (a === 'creer') { creer(S); }
    else if (a === 'etape') { aller(S, +v); }
    else if (a === 'prod') { if (S.choix) { S.choix[v] = !S.choix[v]; rendre(S); } }
    else if (a === 'offre') { const o = offres(S).find(x => x.id === v); if (o) { S.offres[v] = !offreOn(S, o); rendre(S); } }
    else if (a === 'dispo') { S.dispo = !S.dispo; rendre(S); }
    else if (a === 'tout') { (b.dataset.cat ? produits(S) : visibles(S)).filter(p => !b.dataset.cat || String(p.categoryId) === b.dataset.cat).forEach(p => { S.choix[p.id] = v === '1'; }); rendre(S); }
    else if (a === 'acc') { const o = ouverts(S); if (o.has(v)) { o.delete(v); } else { o.add(v); } rendre(S); }
    else if (a === 'nbpages') { changerNbPages(S, +v); }
    else if (a === 'repartirN') { repartir(S, +v); }
    else if (a === 'layout') { changerType(S, +b.dataset.i, v); }
    else if (a === 'gabarit') { S.pages[+b.dataset.i].template = v; S.modifie = true; rendre(S); }
    else if (a === 'place') {
      const p = S.pages[+b.dataset.i];
      if (v === 'photo') { S.choixPhoto = S.choixPhoto === +b.dataset.i ? null : +b.dataset.i; if (!S.medias) { chargerMedias(S); } rendre(S); }
      else { p.filler = v || null; p.fillerMediaId = null; S.choixPhoto = null; S.modifie = true; rendre(S); }
    }
    else if (a === 'photo') { const p = S.pages[+b.dataset.i]; p.filler = 'photo'; p.fillerMediaId = +v; S.choixPhoto = null; S.modifie = true; rendre(S); }
    else if (a === 'photoFermer') { S.choixPhoto = null; rendre(S); }
    else if (a === 'photoChercher') { chargerMedias(S); }
    else if (a === 'monter' || a === 'descendre') { const i = +v, j = a === 'monter' ? i - 1 : i + 1; if (j >= 0 && j < S.pages.length) { const t = S.pages[i]; S.pages[i] = S.pages[j]; S.pages[j] = t; S.modifie = true; rendre(S); } }
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
    if (t.dataset.cm === 'ptexte' || t.dataset.cm === 'ptexteNl') { S.pages[+t.dataset.i][t.dataset.cm === 'ptexte' ? 'text' : 'textNl'] = t.value.slice(0, 400); S.modifie = true; }
    if (t.dataset.cm === 'mediasQ') { S.mediasQ = t.value; }
  }
  function changement(S, ev) {
    const t = ev.target;
    if (t.dataset.cm === 'pcat') { S.pages[+t.dataset.i].category = t.value === '' ? null : +t.value; S.modifie = true; rendre(S); }
    if (t.dataset.cm === 'pgamme') { S.pages[+t.dataset.i].gamme = t.value === '' ? null : +t.value; S.modifie = true; rendre(S); }
    if (t.dataset.cm === 'ptexte' || t.dataset.cm === 'ptexteNl') { rendre(S); }
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
  /** Les catégories dépliées à l'étape 2 : à l'ouverture, celles qui ont un produit coché. */
  function ouverts(S) {
    if (!S.ouverts) {
      S.ouverts = new Set();
      produits(S).forEach(p => { if (S.choix && S.choix[p.id]) { S.ouverts.add(p.categoryId == null ? '' : String(p.categoryId)); } });
    }
    return S.ouverts;
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
    const r = S.proposer ? '<div style="margin-top:8px"><button class="cm-btn pri" data-cm="repartirN" data-v="' + S.proposer + '">Répartir automatiquement sur ' + S.proposer + ' pages</button></div>' : '';
    return '<div class="cm-msg ' + S.msg.type + '" role="' + (S.msg.type === 'err' ? 'alert' : 'status') + '">' + esc(S.msg.titre) + d + r + '</div>';
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
      const ng = c.source && c.source.kind === 'gamme' ? gammesDe(S, c) : null;
      const src = ng ? (ng.length > 1 ? 'Gammes ' : 'Gamme ') + ng.join(' + ') : 'Mes produits';
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
    return '<aside class="cm-cote">' + contenu + '<div class="note">' + CADENAS.replace('<svg', '<svg style="width:11px;height:11px;vertical-align:-1px"') + ' Quatre pages sont celles de la marque : la couverture, la page 2, l’avant-dernière et la dernière (commande en ligne, adresse, horaires et QR de MA boutique). Je choisis les produits et la mise en page des autres.</div></aside>';
  }
  function resume(S) {
    if (!S.cat) { return '<div class="k">Mon catalogue</div><div class="cm-cpt">Il se crée à la fin de cette étape.</div>'; }
    const n = choisis(S).length, pages = S.nbPages;
    return '<div class="k">Mon catalogue · ' + pages + ' pages ' + esc(S.format) + '</div>'
      + '<div style="font-size:13px">' + n + ' produit' + (n > 1 ? 's' : '') + ' choisi' + (n > 1 ? 's' : '') + '</div>'
      + '<div class="cm-cpt" style="margin-top:4px">' + (S.pages || []).map((p, i) => (i + 3) + ' · ' + esc(estPres(p.layout) ? nomGamme(S, p.gamme) : nomCat(S, p.category)) + ' · ' + esc(LAYOUTS[p.layout] ? LAYOUTS[p.layout].label : p.layout)).join('<br>') + '</div>';
  }

  /** « − 2 pages · N pages · ＋ 2 pages » (le Marketing donne la liste permise, `pageCounts`). */
  function segPages(S, libelle) {
    const liste = (S.prod && Array.isArray(S.prod.pageCounts) && S.prod.pageCounts.length ? S.prod.pageCounts : NB_PAGES).map(Number);
    const i = liste.indexOf(S.nbPages), moins = i > 0 ? liste[i - 1] : null, plus = i >= 0 && i < liste.length - 1 ? liste[i + 1] : null;
    const off = S.occupe ? ' disabled' : '';
    return '<div class="cm-champ"><span>' + esc(libelle) + '</span><div class="cm-seg cm-nbp">'
      + '<button data-cm="nbpages" data-v="' + (moins || '') + '"' + (moins ? off : ' disabled') + ' title="' + (moins ? 'Retirer les deux dernières pages intérieures (' + moins + ' pages)' : liste[0] + ' pages au moins') + '">− 2 pages</button>'
      + '<span class="on" title="' + (S.nbPages - PAGES_MARQUE) + ' pages de produits + ' + PAGES_MARQUE + ' pages de la marque">' + S.nbPages + ' pages</span>'
      + '<button data-cm="nbpages" data-v="' + (plus || '') + '"' + (plus ? off : ' disabled') + ' title="' + (plus ? 'Ajouter deux pages intérieures vides en fin (' + plus + ' pages)' : liste[liste.length - 1] + ' pages au plus') + '">＋ 2 pages</button></div></div>';
  }
  function rendreE1(S) {
    let h = '<div class="cm-corps"><section class="cm-pan"><div class="h"><span class="k">Point de départ</span></div>';
    if (S.cat) {
      const ng = S.cat.source && S.cat.source.kind === 'gamme' ? gammesDe(S, S.cat) : null;
      const src = ng ? (ng.length > 1 ? 'des gammes ' : 'de la gamme ') + ng.map(n => '« ' + n + ' »').join(' + ') : 'de tous mes produits';
      h += '<label class="cm-champ"><span>Titre du catalogue</span><input type="text" maxlength="160" data-cm="titre" value="' + esc(S.titre) + '"></label>'
        + '<div class="cm-msg inf">Ce catalogue est parti ' + esc(src) + '. Le point de départ ne change plus ; les produits se choisissent à l’étape 2.</div>'
        + '<div class="cm-pied"><span class="sp"></span><button class="cm-btn pri" data-cm="etape" data-v="2"' + (S.occupe ? ' disabled' : '') + '>Mes produits ›</button></div>';
      return h + '</section>' + cote(S, resume(S)) + '</div>';
    }
    h += '<label class="cm-champ"><span>Titre du catalogue</span><input type="text" maxlength="160" data-cm="titre" placeholder="Mes bûches 2026" value="' + esc(S.titre) + '"></label>';
    h += segPages(S, 'Nombre de pages · dont 4 de la marque (modifiable ensuite)');
    if (!S.prod && !S.prodErr) { h += '<div class="cm-vide">Lecture des produits et des gammes de ma boutique…</div>'; }
    else if (S.prod) {
      const prods = produits(S);
      const coches = Array.isArray(S.src) ? S.src : [];
      const cumul = coches.length ? prods.filter(p => (p.gammes || []).some(x => coches.includes(String(x)))).length : 0;
      h += '<div class="cm-champ"><span>Je pars de… une ou plusieurs gammes (' + MAX_GAMMES + ' au plus), ou tous mes produits</span></div>';
      if (coches.length) { h += '<div class="cm-cpt" style="margin:-6px 0 10px">' + coches.length + ' gamme' + (coches.length > 1 ? 's' : '') + ' cochée' + (coches.length > 1 ? 's' : '') + ' · <b style="font-weight:400;color:var(--color-primary)">' + cumul + ' produit' + (cumul > 1 ? 's' : '') + ' cumulé' + (cumul > 1 ? 's' : '') + '</b> (un produit de deux gammes compte une fois)</div>'; }
      h += '<div class="cm-src">';
      gammes(S).forEach(g => {
        const n = prods.filter(p => (p.gammes || []).map(Number).includes(+g.id)).length;
        const quand = g.from || g.to ? ' · ' + [g.from ? 'du ' + date(g.from) : '', g.to ? 'au ' + date(g.to) : ''].filter(Boolean).join(' ') : '';
        const on = coches.includes(String(g.id));
        h += '<button data-cm="src" data-v="' + esc(g.id) + '" role="checkbox" aria-checked="' + on + '" class="gam' + (on ? ' on' : '') + '"' + (n && (on || coches.length < MAX_GAMMES) ? '' : ' disabled') + '><span class="cb" aria-hidden="true"></span><span class="n">Gamme ' + esc(g.name) + '</span><span class="d">' + n + ' produit' + (n > 1 ? 's' : '') + ' dans ma boutique' + esc(quand) + '</span></button>';
      });
      h += '<button data-cm="src" data-v="tous" role="radio" aria-checked="' + (S.src === 'tous') + '" class="' + (S.src === 'tous' ? 'on' : '') + '"' + (prods.length ? '' : ' disabled') + '><span class="n">Tous mes produits</span><span class="d">' + prods.length + ' produit' + (prods.length > 1 ? 's' : '') + ' de mon webshop</span></button></div>';
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
    // Les catégories en accordéons : un clic sur l'en-tête déplie / replie ; la recherche déplie celles qui ont un résultat.
    const ouv = ouverts(S), cherche = S.rech.trim() !== '';
    h += '<div class="cm-accs">';
    ordre.forEach(k => {
      const liste = groupes.get(k);
      const tous = produits(S).filter(p => (p.categoryId == null ? '' : String(p.categoryId)) === k);
      const nOn = tous.filter(p => S.choix[p.id]).length, tout = tous.length > 0 && nOn === tous.length;
      const ouvert = cherche || ouv.has(k);
      h += '<div class="cm-acc' + (ouvert ? ' ouvert' : '') + '"><div class="cm-acc-hd">'
        + '<button class="cm-acc-t" data-cm="acc" data-v="' + esc(k) + '" aria-expanded="' + ouvert + '"><span class="chev" aria-hidden="true">›</span><span class="nm">' + esc(k === '' ? 'Sans catégorie' : nomCat(S, +k)) + '</span>'
        + '<span class="n">' + nOn + ' / ' + tous.length + ' produit' + (tous.length > 1 ? 's' : '') + '</span></button>'
        + '<button class="cm-sw' + (tout ? ' on' : '') + '" role="switch" aria-checked="' + tout + '" data-cm="tout" data-v="' + (tout ? '0' : '1') + '" data-cat="' + esc(k === '' ? 'null' : k) + '" title="' + (tout ? 'Retirer toute la catégorie' : 'Toute la catégorie') + '"><span class="l">toute la catégorie</span><span class="tr" aria-hidden="true"></span></button></div>';
      if (ouvert) {
        h += '<div class="cm-acc-l">';
        liste.forEach(p => {
          const on = !!S.choix[p.id];
          const ecart = p.shopPriceCents != null && p.priceCents != null && p.shopPriceCents !== p.priceCents;
          h += '<div class="cm-pl' + (on ? '' : ' off') + '"><span class="ph">' + (p.imageUrl ? '<img src="' + esc(p.imageUrl) + '" alt="" loading="lazy">' : '') + '</span>'
            + '<span class="nm">' + esc(p.name) + '</span><b class="' + (p.priceCents == null ? 'mu' : '') + '"' + (ecart ? ' title="Au comptoir : ' + esc(prix(p.shopPriceCents)) + ' — le catalogue prend le prix du webshop"' : '') + '>' + (p.priceCents == null ? 'sans prix' : esc(prix(p.priceCents))) + '</b>'
            + '<button class="cm-sw' + (on ? ' on' : '') + '" role="switch" aria-checked="' + on + '" aria-label="' + esc(p.name) + '" data-cm="prod" data-v="' + p.id + '"><span class="tr" aria-hidden="true"></span></button></div>';
        });
        h += '</div>';
      }
      h += '</div>';
    });
    h += '</div>';
    // Les produits du catalogue que la boutique ne vend plus : dits, grisés.
    const vendus = new Set(produits(S).map(p => +p.id));
    const plus = (S.cat.products || []).filter(p => p.on !== false && !vendus.has(+p.id));
    h += sectionOffres(S);
    if (plus.length) { h += '<div class="cm-msg inf" style="margin-top:12px">' + plus.length + ' produit(s) de ce catalogue ne sont plus vendus au webshop de ma boutique : ils sortiront du catalogue à l’enregistrement.</div>'; }
    h += '<div class="cm-msg inf" style="margin-top:12px">Seuls les produits de MON webshop sont proposés, à MES prix. Changer les produits refait la mise en page automatiquement.</div>';
    h += '<div class="cm-pied"><button class="cm-btn" data-cm="etape" data-v="1">‹ Point de départ</button><span class="sp"></span><button class="cm-btn pri" data-cm="etape" data-v="3"' + (S.occupe ? ' disabled' : '') + '>' + (S.occupe ? 'Enregistrement…' : 'Mise en page ›') + '</button></div>';
    return h + '</section>' + cote(S, resume(S)) + '</div>';
  }

  /** « Promotions et bundles » : les offres de TFBuddy de ma boutique, un interrupteur chacune ; et la disponibilité. */
  function sectionOffres(S) {
    const liste = offres(S), err = S.prod && S.prod.offersError;
    let h = '<div class="cm-offres"><div class="cm-offres-h"><span class="k">Promotions et bundles</span><span class="sp"></span><span class="cm-tag">lus dans TFBuddy</span></div>';
    if (err) { h += '<div class="cm-msg err">Promotions de TFBuddy illisibles : ' + esc(err) + '. Rien n’est inventé : le catalogue n’en montre aucune.</div>'; }
    else if (!liste.length) { h += '<div class="cm-vide">Aucune promotion active dans TFBuddy pour ma boutique.</div>'; }
    liste.forEach(o => {
      const on = offreOn(S, o);
      const ou = o.type === 'scheduledDiscount' ? 'prix barré' : nomCat(S, o.categoryId);
      const det = [(o.products || []).map(p => p.name).join(' + ') + (o.moreProducts ? ' + ' + o.moreProducts : ''), o.period].filter(Boolean).join(' · ');
      h += '<div class="cm-offre' + (on ? '' : ' off') + '"><span class="bd">' + esc(o.badge) + '</span><span class="nm">' + esc(o.name) + '<small>' + esc(det) + '</small><small>→ ' + esc(ou) + '</small></span>'
        + (o.priceCents != null ? '<b>' + (o.oldPriceCents != null ? '<s>' + esc(prix(o.oldPriceCents)) + '</s> ' : '') + esc(prix(o.priceCents)) + '</b>' : '<b></b>')
        + '<button class="cm-sw' + (on ? ' on' : '') + '" role="switch" aria-checked="' + on + '" aria-label="' + esc(o.name) + '" data-cm="offre" data-v="' + esc(o.id) + '"><span class="tr" aria-hidden="true"></span></button></div>';
    });
    h += '<div class="cm-offre dispo"><span class="nm">Afficher la disponibilité<small>période et délai de commande lus dans TFBuddy, sous le prix</small></span><b></b>'
      + '<button class="cm-sw' + (S.dispo ? ' on' : '') + '" role="switch" aria-checked="' + !!S.dispo + '" aria-label="Afficher la disponibilité" data-cm="dispo"><span class="tr" aria-hidden="true"></span></button></div>';
    return h + '</div>';
  }

  function mini(layout, tpl) {
    if (estPres(layout)) { return '<span class="mini ' + layout + ' t' + esc(tpl || 'A') + '" aria-hidden="true"><b></b><i></i>' + (layout === 'presentation_liste' ? '<u></u><u></u><u></u>' : '') + '</span>'; }
    const n = layout === 'vedette' ? 5 : 6;
    return '<span class="mini ' + layout + ' t' + esc(tpl || 'A') + '" aria-hidden="true">' + '<i></i>'.repeat(n) + '</span>';
  }
  /** Vignette d'une variante de gabarit (la même que le Marketing). */
  function vignette(famille, t) {
    if (famille === 'presentation') {
      const plein = t === 'B' ? ' fill="currentColor" fill-opacity=".85"' : ' fill="none"', clair = t === 'B' ? '#fff' : 'currentColor';
      return '<svg width="28" height="36" viewBox="0 0 40 52" aria-hidden="true"><rect x=".6" y=".6" width="38.8" height="50.8" rx="2"' + plein + ' stroke="currentColor" stroke-width="1.4"/>'
        + (t === 'C' ? '<rect x=".6" y=".6" width="38.8" height="28" fill="currentColor" fill-opacity=".25"/>' : '<circle cx="20" cy="17" r="9" fill="none" stroke="' + clair + '" stroke-width="1.4"/>')
        + '<rect x="' + (t === 'C' ? 5 : 9) + '" y="34" width="22" height="4" rx="1" fill="' + clair + '"/><rect x="' + (t === 'C' ? 5 : 12) + '" y="41" width="16" height="2" rx="1" fill="' + clair + '" opacity=".6"/></svg>';
    }
    let h = '<svg width="28" height="36" viewBox="0 0 40 52" aria-hidden="true"><rect x=".6" y=".6" width="38.8" height="50.8" rx="2" fill="none" stroke="currentColor" stroke-width="1.4"/>'
      + (t === 'B' ? '<rect x=".6" y=".6" width="38.8" height="10" fill="currentColor" fill-opacity=".85"/>' : '')
      + '<rect x="' + (t === 'A' ? 11 : 4) + '" y="4" width="18" height="3" rx="1" fill="' + (t === 'B' ? '#fff' : 'currentColor') + '"/>' + (t === 'C' ? '<rect x="4" y="9.5" width="32" height=".8" fill="currentColor"/>' : '');
    [14, 26, 38].forEach(y => { [4, 21].forEach(x => { h += '<rect x="' + x + '" y="' + y + '" width="15" height="10" rx="1.5"' + (t === 'C' ? ' fill="currentColor" fill-opacity=".18"' : ' fill="none" stroke="currentColor" stroke-width="1"') + '/>'; }); });
    return h + '</svg>';
  }
  function rendreE3(S) {
    const prods = produits(S), ids = new Set(choisis(S));
    const parCat = {}; prods.forEach(p => { if (ids.has(+p.id)) { const k = p.categoryId == null ? 'null' : String(p.categoryId); parCat[k] = (parCat[k] || 0) + 1; } });
    const cats = catsChoisies(S);
    const portes = remplir(S);
    const gs = gammesCatalogue(S);
    let h = '<div class="cm-corps"><section class="cm-pan"><div class="h"><span class="k">Mise en page</span><span class="sp"></span><button class="cm-btn" data-cm="repartir"' + (S.occupe ? ' disabled' : '') + '>↺ Répartir automatiquement</button></div>';
    h += '<div class="cm-reglages">' + segPages(S, 'Nombre de pages · toujours par 2 · dont 4 de la marque') + '<div class="cm-champ"><span>Format</span><div class="cm-seg">' + ['A5', 'A4'].map(f => '<button data-cm="format" data-v="' + f + '" class="' + (S.format === f ? 'on' : '') + '">' + f + '</button>').join('') + '</div></div>'
      + '<div class="cm-champ"><span>Langues</span><div class="cm-cases">' + [['fr', 'Français'], ['nl', 'Néerlandais']].map(l => '<label><input type="checkbox" data-cm="langs" value="' + l[0] + '"' + (S.langs.includes(l[0]) ? ' checked' : '') + '> ' + l[1] + '</label>').join('') + '</div></div></div>';
    h += '<div class="cm-pages">';
    const marque = (no, mini, t, s2) => '<div class="cm-pg lock"><span class="no">' + no + '</span>' + mini + '<div><div class="t">' + t + '</div><div class="s">' + s2 + '</div></div><span class="cm-lock">' + CADENAS + ' Marque</span><span></span></div>';
    const nb = S.nbPages;
    h += marque(1, '<span class="mini cover" aria-hidden="true"></span>', 'Couverture', 'Charte de la marque · le nom de ma boutique');
    h += marque(2, '<span class="mini texte" aria-hidden="true"></span>', 'Page 2', 'Charte de la marque · le mot de la boulangerie');
    (S.pages || []).forEach((p, i) => {
      const pres = estPres(p.layout), cap = LAYOUTS[p.layout] ? LAYOUTS[p.layout].cap : 0, porte = portes[i] || 0;
      const k = p.category == null ? 'null' : String(p.category), n = parCat[k] || 0;
      const fam = pres ? 'presentation' : 'produits';
      let corps = '';
      if (pres) {
        corps += '<select class="cm-sel" data-cm="pgamme" data-i="' + i + '" aria-label="Gamme de la page ' + (i + 3) + '">' + gs.map(g => '<option value="' + g + '"' + (+p.gamme === g ? ' selected' : '') + '>' + esc(nomGamme(S, g)) + '</option>').join('') + '</select>'
          + '<div class="cm-cpt" style="margin-top:4px">' + (p.layout === 'presentation_liste' ? porte + ' produit' + (porte > 1 ? 's' : '') + ' de la gamme listé' + (porte > 1 ? 's' : '') + ' avec leurs prix (8 au plus)' : 'l’illustration, le nom et le texte de la gamme') + '</div>'
          + '<textarea class="cm-txt" data-cm="ptexte" data-i="' + i + '" maxlength="400" rows="2" placeholder="Texte de la présentation (facultatif, 400 caractères)">' + esc(p.text) + '</textarea>'
          + (S.langs.includes('nl') ? '<textarea class="cm-txt" data-cm="ptexteNl" data-i="' + i + '" maxlength="400" rows="2" placeholder="Texte néerlandais">' + esc(p.textNl) + '</textarea>' : '');
      } else {
        const options = cats.filter(c => c !== null).concat(p.category != null && !cats.includes(p.category) ? [p.category] : []);
        corps += '<div class="cm-lig"><select class="cm-sel" data-cm="pcat" data-i="' + i + '" aria-label="Catégorie de la page ' + (i + 3) + '"><option value=""' + (p.category == null ? ' selected' : '') + '>Notre sélection (toutes catégories)</option>'
          + options.map(c => '<option value="' + c + '"' + (+p.category === +c ? ' selected' : '') + '>' + esc(nomCat(S, c)) + '</option>').join('') + '</select>'
          + (gs.length ? '<select class="cm-sel" data-cm="pgamme" data-i="' + i + '" aria-label="Gamme de la page ' + (i + 3) + '"><option value=""' + (p.gamme == null ? ' selected' : '') + '>Toutes les gammes</option>' + gs.map(g => '<option value="' + g + '"' + (+p.gamme === g ? ' selected' : '') + '>' + esc(nomGamme(S, g)) + '</option>').join('') + '</select>' : '') + '</div>'
          + '<div class="cm-cpt" style="margin-top:4px">' + porte + ' produit' + (porte > 1 ? 's' : '') + ' sur ' + cap + ' places' + (p.category == null ? ' · les produits qui restent' : ' · ' + n + ' choisi' + (n > 1 ? 's' : '') + ' dans cette catégorie') + '</div>';
        if (remplissable(p.layout, porte) || p.filler) {
          corps += '<div class="cm-place"><span class="l" title="Quand la page n’est pas pleine, une photo de la médiathèque ou l’illustration de la gamme prend la place libre">Place libre</span><div class="cm-seg">'
            + [['', 'Rien'], ['photo', 'Photo'], ['illustration', 'Illustration']].map(o => '<button data-cm="place" data-i="' + i + '" data-v="' + o[0] + '" class="' + ((p.filler || '') === o[0] ? 'on' : '') + '">' + o[1] + '</button>').join('') + '</div>'
            + (p.filler === 'photo' && p.fillerMediaId ? '<span class="cm-vign">' + (S.mediaUrls && S.mediaUrls[p.fillerMediaId] ? '<img src="' + esc(S.mediaUrls[p.fillerMediaId]) + '" alt="">' : 'photo n° ' + p.fillerMediaId) + '</span>' : '')
            + (!remplissable(p.layout, porte) ? '<span class="cm-cpt">page pleine : rien n’est imprimé</span>' : '') + '</div>';
          if (S.choixPhoto === i) { corps += rendreMedias(S, i); }
        }
      }
      h += '<div class="cm-pg' + (pres ? ' pres' : '') + '"><span class="no">' + (i + 3) + '</span>' + mini(p.layout, p.template)
        + '<div class="cm-pg-c">' + corps + '</div>'
        + '<div class="ctl"><div class="cm-seg cm-types">' + Object.keys(LAYOUTS).map(l => '<button data-cm="layout" data-i="' + i + '" data-v="' + l + '" class="' + (p.layout === l ? 'on' : '') + '"' + (estPres(l) && !gs.length ? ' disabled' : '') + '>' + LAYOUTS[l].label + '</button>').join('') + '</div>'
        + '<div class="cm-gab" role="group" aria-label="Gabarit de la page ' + (i + 3) + '">' + ['A', 'B', 'C'].map(t => '<button data-cm="gabarit" data-i="' + i + '" data-v="' + t + '" class="' + ((p.template || 'A') === t ? 'on' : '') + '" title="' + t + ' · ' + GABARITS[fam][t][0] + ' — ' + GABARITS[fam][t][1] + '">' + vignette(fam, t) + '<span>' + t + ' · ' + GABARITS[fam][t][0] + '</span></button>').join('') + '</div></div>'
        + '<div class="mv"><button class="cm-btn" data-cm="monter" data-v="' + i + '"' + (i === 0 ? ' disabled' : '') + ' aria-label="Monter">↑</button><button class="cm-btn" data-cm="descendre" data-v="' + i + '"' + (i === S.pages.length - 1 ? ' disabled' : '') + ' aria-label="Descendre">↓</button></div></div>';
    });
    h += marque(nb - 1, '<span class="mini texte" aria-hidden="true"></span>', 'Avant-dernière page', 'Charte de la marque · nos services');
    h += marque(nb, '<span class="mini" aria-hidden="true" style="background:#F6F1E9"></span>', 'Dernière page', 'Commande en ligne, QR de mon webshop, adresse et horaires de ma boutique');
    h += '</div>';
    if (portes.sansPlace) { h += '<div class="cm-msg inf" style="margin-top:12px">' + portes.sansPlace + ' produit' + (portes.sansPlace > 1 ? 's' : '') + ' choisi' + (portes.sansPlace > 1 ? 's' : '') + ' sans place dans les pages. ' + (S.nbPages < 16 ? 'Ajoutez 2 pages, choisissez' : 'Choisissez') + ' une mise en page plus dense, une page « Notre sélection », ou « Répartir automatiquement ».</div>'; }
    h += '<div class="cm-pied"><button class="cm-btn" data-cm="etape" data-v="2">‹ Mes produits</button><span class="sp"></span>' + (S.modifie ? '<span class="cm-cpt">modifications non enregistrées</span>' : '')
      + '<button class="cm-btn pri" data-cm="etape" data-v="4"' + (S.occupe ? ' disabled' : '') + '>' + (S.occupe ? 'Enregistrement…' : 'Aperçu et PDF ›') + '</button></div>';
    return h + '</section>' + cote(S, resume(S)) + '</div>';
  }
  /** Le choix de la photo de la place libre : les images de la médiathèque du réseau et de ma boutique. */
  function rendreMedias(S, i) {
    let h = '<div class="cm-medias"><div class="cm-lig"><input type="search" class="cm-rech" data-cm="mediasQ" placeholder="Chercher une photo" value="' + esc(S.mediasQ) + '">'
      + '<button class="cm-btn" data-cm="photoChercher">Chercher</button><span class="sp"></span><button class="cm-btn" data-cm="photoFermer">Fermer</button></div>';
    if (S.mediasErr) { return h + bandeauErreur(S.mediasErr, 'La médiathèque n’a pas pu être lue') + '</div>'; }
    if (!S.medias) { return h + '<div class="cm-vide">Lecture de la médiathèque…</div></div>'; }
    if (!S.medias.length) { return h + '<div class="cm-vide">Aucune photo dans la médiathèque du réseau ni de ma boutique.</div></div>'; }
    return h + '<div class="cm-grille">' + S.medias.map(m => '<button data-cm="photo" data-i="' + i + '" data-v="' + m.id + '" class="' + (+S.pages[i].fillerMediaId === +m.id ? 'on' : '') + '" title="' + esc(m.name) + '"><img src="' + esc(m.thumbnailUrl || m.url) + '" alt="" loading="lazy"><span>' + esc(m.name) + '</span></button>').join('') + '</div></div>';
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
    const leg = p.kind === 'cover' ? 'Couverture' : p.kind === 'back' ? 'Dernière page' : p.kind === 'brand' ? 'Page ' + (i + 1) + ' · marque (' + (p.slot === 'page2' ? 'page 2' : 'avant-dernière') + ')'
      : p.kind === 'presentation' ? 'Page ' + (i + 1) + ' · ' + (LAYOUTS[p.layout] ? LAYOUTS[p.layout].label : '') + ' · gabarit ' + (p.template || 'A')
      : 'Page ' + (i + 1) + ' · ' + (LAYOUTS[p.layout] ? LAYOUTS[p.layout].label : '') + ' · gabarit ' + (p.template || 'A') + ((p.products || []).length ? '' : ' · vide') + (p.filler ? ' · ' + (p.filler.kind === 'photo' ? 'photo' : 'illustration') + ' à la place libre' : '');
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
    else if (p.kind === 'brand') { corps = marquePage(doc, p); }
    else if (p.kind === 'presentation') { corps = presentation(doc, p); }
    else { corps = interieur(doc, p); }
    const tpl = p.kind === 'products' || p.kind === 'presentation' ? ' cat-face--t' + esc(/^[ABC]$/.test(p.template || '') ? p.template : 'A') : '';
    return '<div class="cat-face cat-face--' + esc(p.kind) + tpl + '" style="' + st + '" lang="' + esc(doc.version.lang) + '"><div class="cat-trim">' + corps + '</div></div>';
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
  /** Page 2 et avant-dernière (charte) : image ou logo, titre, texte ; un paragraphe de plusieurs lignes = une liste. */
  function marquePage(doc, p) {
    const blocs = String(p.text || '').split(/\n\s*\n/).map(b => b.split('\n').map(l => l.trim()).filter(Boolean)).filter(l => l.length);
    return '<div class="cat-brand' + (p.imageUrl ? ' cat-brand--image' : '') + '">'
      + (p.imageUrl ? '<div class="cat-brand__visual"><img src="' + esc(p.imageUrl) + '" alt=""></div>' : '<img class="cat-logo cat-brand__logo" src="' + esc(logo(doc)) + '" alt="">')
      + '<div class="cat-brand__title">' + esc(p.title) + '</div><div class="cat-brand__rule"></div><div class="cat-brand__text">'
      + blocs.map(l => l.length > 1 ? '<ul>' + l.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>' : '<p>' + esc(l[0]) + '</p>').join('') + '</div></div>';
  }
  function photo(pr) {
    return '<span class="cat-photo' + (pr.imageUrl ? ' cat-photo--img' : '') + '">' + (pr.imageUrl ? '<img src="' + esc(pr.imageUrl) + '" alt="">' : '<span class="cat-photo__none"></span>') + '</span>';
  }
  /** Le prix ; barré par une remise programmée : l'ancien prix à côté, rayé (rendu de `Price`, CataloguePages.tsx). */
  function prixHtml(pr, lang) {
    const vieux = pr.oldPriceCents != null;
    return '<span class="cat-prices">' + (vieux ? '<s class="cat-old">' + esc(prix(pr.oldPriceCents, lang)) + '</s>' : '')
      + '<span class="cat-price' + (pr.priceCents == null ? ' cat-price--none' : '') + (vieux ? ' cat-price--promo' : '') + '">' + esc(prix(pr.priceCents, lang)) + '</span></span>';
  }
  const dispoHtml = t => t ? '<span class="cat-avail">' + esc(t) + '</span>' : '';
  /** La vignette d'une promotion (rendu de `OfferCard`, CataloguePages.tsx). */
  function offreCarte(o, lang, grand, rang) {
    const vus = (o.products || []).slice(0, rang ? 3 : grand ? 4 : (o.products || []).length > 2 ? 4 : 2);
    const photos = '<span class="cat-offer__photos cat-offer__photos--' + Math.max(1, vus.length) + '">' + (vus.length ? vus.map(photo).join('') : '<span class="cat-photo"><span class="cat-photo__none"></span></span>') + '</span>';
    const noms = (o.products || []).map(p => (p.quantity != null && p.quantity !== 1 ? p.quantity + ' × ' : '') + p.name).join(' + ');
    const plus = o.moreProducts > 0 ? (lang === 'nl' ? ' + ' + o.moreProducts + ' andere' : ' + ' + o.moreProducts + ' autre' + (o.moreProducts > 1 ? 's' : '')) : '';
    return '<div class="cat-offer cat-offer--' + esc(o.type) + (grand ? ' cat-offer--big' : '') + (rang ? ' cat-offer--row' : '') + '"><span class="cat-offer__badge">' + esc(o.badge) + '</span>' + photos
      + '<div class="cat-offer__text"><div class="cat-name">' + esc(o.name) + '</div>' + (noms && !rang ? '<div class="cat-offer__items">' + esc(noms + plus) + '</div>' : '')
      + (o.deal ? '<div class="cat-offer__deal">' + esc(o.deal) + '</div>' : '') + (o.priceCents != null ? prixHtml(o, lang) : '') + dispoHtml(o.period) + '</div></div>';
  }
  function carte(pr, lang, grand) {
    if (pr.kind === 'offer') { return offreCarte(pr, lang, grand, false); }
    return '<div class="cat-card' + (grand ? ' cat-card--big' : '') + (pr.oldPriceCents != null ? ' cat-card--promo' : '') + '">'
      + (pr.promo ? '<span class="cat-offer__badge cat-card__badge">' + esc(pr.promo.label) + '</span>' : '') + photo(pr)
      + '<div class="cat-card__text"><div class="cat-name">' + esc(pr.name) + '</div>' + prixHtml(pr, lang) + dispoHtml(pr.availability) + '</div></div>';
  }
  /** Une image livrée avec le Marketing (dessin de saison, de famille) : son adresse absolue, rendue par le document. */
  const dessin = (url) => /^(https?:)?\/\//.test(String(url || '')) ? url : null;
  function liste(pr, lang) {
    return '<div class="cat-list">' + pr.map(x => x.kind === 'offer' ? offreCarte(x, lang, false, true)
      : '<div class="cat-row' + (x.availability ? ' cat-row--avail' : '') + '">' + photo(x) + '<span class="cat-name">' + esc(x.name) + dispoHtml(x.availability) + '</span><i></i>' + prixHtml(x, lang) + '</div>').join('') + '</div>';
  }
  /** La place libre : la photo de la médiathèque, ou l'illustration (rendu de `Filler`, CataloguePages.tsx). */
  function placeLibre(f, style) {
    const d = dessin(f.drawingUrl), src = d || f.imageUrl;
    return '<div class="cat-filler cat-filler--' + esc(f.kind) + (d ? ' cat-filler--drawing' : '') + '" style="' + style + '">' + (src ? '<img src="' + esc(src) + '" alt="">' : '') + '</div>';
  }
  function interieur(doc, p) {
    const lang = doc.version.lang, pr = p.products || [], f = p.filler || null;
    const rangs = f ? rangsLibres(p.layout, pr.length) : 0;
    const etendu = 'grid-column:1 / -1;grid-row:span ' + rangs;
    let b = '';
    if (!pr.length) {
      b = f ? placeLibre(f, 'flex:1 1 0') : '<div class="cat-empty">' + (lang === 'nl' ? 'Lege pagina' : 'Page vide') + '</div>';
    } else if (p.layout === 'liste') {
      b = liste(pr, lang) + (f ? placeLibre(f, 'flex:1 1 0') : '');
    } else if (p.layout === 'vedette') {
      b = carte(pr[0], lang, true) + '<div class="cat-grid cat-grid--2x2">' + pr.slice(1).map(x => carte(x, lang, false)).join('') + (f && rangs > 0 ? placeLibre(f, etendu) : '') + '</div>';
    } else {
      b = '<div class="cat-grid cat-grid--2x3">' + pr.map(x => carte(x, lang, false)).join('') + (f && rangs > 0 ? placeLibre(f, etendu) : '') + '</div>';
    }
    const marque = dessin(p.drawingUrl);
    return '<div class="cat-inner cat-inner--' + esc(p.layout) + ' cat-t-' + esc(p.template || 'A') + '"><div class="cat-h">' + (marque ? '<img class="cat-h__mark" src="' + esc(marque) + '" alt="">' : '')
      + '<span class="cat-h__text">' + esc(p.title) + '</span></div><div class="cat-body">' + b + '</div></div>';
  }
  /** La page de présentation d'une gamme (rendu de `Presentation`, CataloguePages.tsx). */
  function presentation(doc, p) {
    const lang = doc.version.lang, d = dessin(p.drawingUrl), avecListe = p.layout === 'presentation_liste', t = p.template || 'A';
    const v = t === 'C' ? (p.imageUrl ? [p.imageUrl, true] : d ? [d, false] : null) : (d ? [d, false] : p.imageUrl ? [p.imageUrl, true] : null);
    return '<div class="cat-pres cat-pres--' + (avecListe ? 'liste' : 'seule') + ' cat-t-' + esc(t) + '">'
      + '<div class="cat-pres__visual ' + (v ? (v[1] ? 'is-photo' : 'is-drawing') : 'is-none') + '">' + (v ? '<img src="' + esc(v[0]) + '" alt="">' : '') + '</div>'
      + '<div class="cat-pres__head"><div class="cat-pres__eyebrow">' + (lang === 'nl' ? 'Het gamma' : 'La gamme') + '</div><div class="cat-pres__title">' + esc(p.title) + '</div><div class="cat-pres__rule"></div>'
      + (p.text ? '<div class="cat-pres__text">' + esc(p.text) + '</div>' : '') + '</div>'
      + (avecListe && (p.products || []).length ? '<div class="cat-pres__list">' + liste(p.products, lang) + '</div>' : '') + '</div>';
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
