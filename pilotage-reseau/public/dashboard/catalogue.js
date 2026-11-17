/* Dashboard › Mon catalogue (14/11/2026, Sam : « dans le dashboard, un module où les franchisés peuvent créer leur
 * catalogue, sous contrainte » ; maquette validée : liste, puis un assistant en quatre étapes). Depuis le 15/11/2026
 * (« côté franchisé c'est un assistant : choix du catalogue, ensuite les gammes, la présentation, validation et
 * exportation PDF ») : UN assistant en cinq étapes — Catalogue (reçu de la marque, le mien, nouveau), Gammes,
 * Présentation, Validation (points calculés par le Marketing), Export PDF (imprimer, puis diffuser : QR, PDF léger, affiche).
 *
 * Les catalogues vivent dans le portail Marketing ; ce module parle à l'API du cockpit à côté de lui
 * (../api/cockpit/dashboard/catalogues…), qui lit la boutique dans la session, ajoute le jeton de service et relaie
 * au Marketing (src/catalogues_magasin.php). Le navigateur ne voit jamais le jeton. Les contraintes (produits vendus
 * par la boutique, ses prix, 8 à 16 pages par 2 dont les quatre de la marque — couverture, page 2, avant-dernière et
 * dernière —, six mises en page dont les présentations de gamme et les grandes vignettes 2 × 2, gabarits A · B · C, en-tête titre ·
 * bandeau · grande illustration, une page par catégorie ajoutée, place libre — photo de la
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
    mosaique: { label: 'Grandes vignettes 2 × 2', cap: 4 },
    pleine: { label: 'Pleine page', cap: 1 },
    presentation: { label: 'Présentation seule', cap: 0 },
    presentation_liste: { label: 'Présentation + liste', cap: 8 },
  };
  const estPres = l => l === 'presentation' || l === 'presentation_liste';
  /** Les mises en page dites par la place des produits sur la largeur (17/11/2026) : le nom court, ce qu'elle tient. */
  const DISPOS = {
    liste: ['1 par largeur', 'liste avec prix · 12'], grille: ['2 par largeur', 'grille 2 × 3 · 6'], mosaique: ['2 grandes', 'vignettes 2 × 2 · 4'],
    pleine: ['Pleine page', '1 produit et sa promo'], vedette: ['1 vedette + 4', 'la vedette en haut · 5'], presentation: ['Présentation', 'la gamme seule'], presentation_liste: ['Présentation + liste', 'la gamme · 8'],
  };
  /** Les variantes de gabarit (2-3 possibilités pour chaque mise en page), les mêmes que le Marketing. */
  const GABARITS = {
    produits: { A: ['Classique', 'fond papier, titre centré'], B: ['Bandeau', 'bandeau de couleur, titre à gauche'], C: ['Épuré', 'fond blanc, titre à gauche'] },
    presentation: { A: ['Illustration', 'dessin au centre, fond papier'], B: ['Pleine couleur', 'fond de couleur, dessin en blanc'], C: ['Photo pleine', 'grande image en haut'] },
  };
  /** L'en-tête d'une page de produits et l'illustration de sa catégorie (client, 14/11/2026 : « 2 ou 3 possibilités de
   * montrer le titre et l'illustration ») — les mêmes que le Marketing (HEADER_LABELS, CataloguePages.tsx). */
  const ENTETES = {
    titre: ['Titre + pictogramme', 'le titre et un petit pictogramme'],
    bandeau: ['Bandeau illustré', 'bandeau de couleur, illustration en blanc'],
    grande: ['Grande illustration', 'grande illustration en haut, puis le titre'],
  };
  /** Une rangée entière libre : la place libre peut prendre une photo ou l'illustration (la règle du Marketing). */
  const remplissable = (l, n) => l === 'grille' ? n <= 4 : l === 'vedette' ? n <= 3 : l === 'liste' ? n <= 9 : l === 'mosaique' ? n <= 2 : l === 'pleine' ? n === 0 : false;
  /** Rangées de la grille restées libres, que la place libre prend en pleine largeur. */
  const rangsLibres = (l, n) => l === 'grille' ? 3 - Math.ceil(n / 2) : l === 'vedette' ? 2 - Math.ceil(Math.max(0, n - 1) / 2) : l === 'mosaique' ? 2 - Math.ceil(n / 2) : 0;
  const STATUTS = { brouillon: 'Brouillon', pret: 'PDF prêt', imprime: 'Imprimé' };
  /** De 8 à 16 pages, toujours par 2 (« rajouter une page, c'est toujours par 2 »), dont 4 de la marque (1, 2,
   * avant-dernière, dernière) ; le Marketing en dit la liste. */
  const NB_PAGES = [8, 10, 12, 14, 16];
  const PAGES_MARQUE = 4;
  const FORMAT_MM = { A5: { w: 148, h: 210 }, A4: { w: 210, h: 297 } };
  const BLEED = 3;
  const PX_MM = 96 / 25.4;
  /** L'assistant (15/11/2026, client : « côté franchisé c'est un assistant : choix du catalogue, ensuite les gammes, la
   * présentation, validation et exportation PDF ») : cinq étapes ; un catalogue de la marque pris « tel quel » saute
   * les étapes 2 et 3 (verrouillées, « L'adapter à ma boutique » les ouvre). */
  const ETAPES = [
    ['Catalogue', 'reçu de la marque, le mien, ou nouveau'],
    ['Gammes', 'mes gammes, mes produits, à mes prix'],
    ['Présentation', 'catégorie par catégorie, puis l’ordre'],
    ['Validation', 'ce qui s’imprimera'],
    ['Export PDF', 'imprimer, QR, affiche'],
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
      etats[shop] = { shop, el, vue: 'assistant', liste: null, recus: null, recusErr: null, verif: {}, verifErr: {}, lien: undefined, lienErr: null,
        imp: { mode: 'imprimeur', langue: 'fr', qte: 250 }, affiche: { taille: 'A4', titre: '' }, grand: null, creation: false, listeErr: null, prod: null, prodErr: null, cat: null, etape: 1,
        src: null, titre: '', choix: null, pages: null, format: 'A5', langs: ['fr'], lang: 'fr', doc: {}, docErr: {}, msg: null,
        occupe: false, rech: '', modifie: false, nbPages: 8, ouverts: null, proposer: null,
        medias: null, mediasErr: null, mediasQ: '', choixPhoto: null };
      el.addEventListener('click', ev => clic(etats[shop], ev));
      el.addEventListener('input', ev => saisie(etats[shop], ev));
      el.addEventListener('change', ev => changement(etats[shop], ev));
      el.addEventListener('dragstart', ev => glisserDebut(etats[shop], ev));
      el.addEventListener('dragover', ev => glisserSur(etats[shop], ev));
      el.addEventListener('drop', ev => deposer(etats[shop], ev));
      el.addEventListener('dragend', () => glisserFin(etats[shop]));
      el.addEventListener('keydown', ev => { if ((ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') && ev.target.closest && ev.target.closest('.fb')) { ev.preventDefault(); feuilleter(etats[shop], ev.key === 'ArrowRight' ? 1 : -1); } else if (ev.key === 'Escape' && etats[shop].fbGrand) { etats[shop].fbGrand = false; rendre(etats[shop]); } });
    }
    return etats[shop];
  }
  const qs = S => '?shop=' + encodeURIComponent(S.shop);

  function chargerListe(S) {
    S.listeErr = null; S.recusErr = null;
    // Mes catalogues et ceux que la marque a préparés pour ma boutique (le Marketing ne rend que les siens).
    const recus = appel('GET', '/marque' + qs(S)).then(j => { S.recus = j.data || []; chargerApercuRecu(S); }).catch(e => { S.recus = []; S.recusErr = e; });
    return Promise.all([appel('GET', qs(S)).then(j => { S.liste = j.data || []; }).catch(e => { S.listeErr = e; }), recus]).finally(() => rendre(S));
  }
  /** Le chemin d'un catalogue au cockpit : le mien (`/{id}`) ou celui de la marque reçu (`/marque/{id}`). */
  const chemin = (S, c) => ((c || S.cat).owner === 'marque' ? '/marque/' : '/') + (c || S.cat).id;
  const estRecu = S => !!(S.cat && S.cat.owner === 'marque');
  /** L'aperçu (deux pages) du premier catalogue de la marque à regarder, pour sa carte « Nouveau · de la marque ». */
  function chargerApercuRecu(S) {
    const c = (S.recus || []).find(x => x.reception && x.reception.status === 'nouveau');
    if (!c || S.doc['r' + c.id]) { return; }
    S.doc['r' + c.id] = null;
    appel('GET', '/marque/' + c.id + '/document' + qs(S) + '&lang=fr').then(j => { S.doc['r' + c.id] = j.data; }).catch(() => { delete S.doc['r' + c.id]; }).finally(() => rendre(S));
  }
  function chargerVerif(S, force) {
    const c = S.cat; if (!c) { return; }
    const k = c.owner + c.id + '|' + S.lang;
    if (S.verif[k] && !force) { return; }
    delete S.verifErr[k];
    S.verif[k] = null;
    appel('GET', chemin(S) + '/verifications' + qs(S) + '&lang=' + S.lang).then(j => { S.verif[k] = j.data; })
      .catch(e => { delete S.verif[k]; S.verifErr[k] = e; }).finally(() => rendre(S));
  }
  const verifDe = S => S.cat ? S.verif[S.cat.owner + S.cat.id + '|' + S.lang] : null;
  function chargerLien(S) {
    S.lienErr = null;
    // Pas encore de lien : il se crée (le catalogue en ligne fait partie de l'export) ; « Nouveau lien » le remplace.
    appel('GET', chemin(S) + '/lien' + qs(S)).then(j => { const l = (j.data || [])[0] || null; return l || appel('POST', chemin(S) + '/lien' + qs(S), {}).then(k => k.data); })
      .then(l => { S.lien = l; }).catch(e => { S.lien = null; S.lienErr = e; }).finally(() => rendre(S));
  }
  function chargerProduits(S, force) {
    if (S.prod && !force) { return Promise.resolve(); }
    S.prodErr = null;
    return appel('GET', '/produits' + qs(S)).then(j => { S.prod = j.data || {}; }).catch(e => { S.prodErr = e; }).finally(() => rendre(S));
  }
  function chargerDoc(S, force) {
    const c = S.cat; if (!c) { return; }
    const k = c.owner + c.id + '|' + S.lang;
    if (S.doc[k] && !force) { return; }
    delete S.docErr[k];
    S.doc[k] = null;
    appel('GET', chemin(S) + '/document' + qs(S) + '&lang=' + S.lang)
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
      gamme: p.gamme == null ? null : +p.gamme, text: p.text || '', textNl: p.textNl || '', filler: p.filler || null, fillerMediaId: p.fillerMediaId == null ? null : +p.fillerMediaId,
      header: ENTETES[p.header] ? p.header : 'titre', script: p.script === true, ...(p.layout === 'pleine' && p.product != null ? { product: +p.product } : {}) }));
    S.format = c.format || 'A5';
    S.nbPages = NB_PAGES.includes(+c.pageCount) ? +c.pageCount : 8;
    S.langs = (c.langs && c.langs.length ? c.langs : ['fr']).slice();
    if (!S.langs.includes(S.lang)) { S.lang = S.langs[0]; }
    // Promotions de TFBuddy : les choix explicites ; une offre absente est cochée si elle touche un produit coché.
    S.offres = {}; S.offresImg = {}; (c.offers || []).forEach(o => { S.offres[o.type + '-' + o.id] = !!o.on; if (o.mediaId != null) { S.offresImg[o.type + '-' + o.id] = +o.mediaId; } });
    S.choixOffre = null;
    if (!S.cat || S.mepCat !== c.id) { S.mep = null; S.mepDetail = false; S.mepCat = c.id; }
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
    // Une offre qui a une image de la médiathèque (« insert picture from médiathèque ») part aussi, avec son `mediaId`.
    const cles = Array.from(new Set(Object.keys(S.offres || {}).concat(Object.keys(S.offresImg || {})))).filter(k => connues.has(k));
    return cles.map(k => { const o = offres(S).find(x => x.id === k); return { type: o.type, id: +o.erpId, on: offreOn(S, o), mediaId: (S.offresImg || {})[k] != null ? +S.offresImg[k] : null }; });
  }

  /** Les pages intérieures telles que le Marketing les attend (seulement ce qui ne vaut pas son défaut). */
  function pagesCorps(S) {
    return S.pages.map(p => {
      const o = { layout: p.layout, category: estPres(p.layout) ? null : p.category };
      if (p.layout === 'pleine' && p.product != null) { o.product = +p.product; }   // le produit de la pleine page (Marketing v1.8)
      if (p.template && p.template !== 'A') { o.template = p.template; }
      if (p.gamme != null) { o.gamme = p.gamme; }
      if (estPres(p.layout)) {
        if (p.text) { o.text = p.text; }
        if (p.textNl) { o.textNl = p.textNl; }
      } else {
        if (p.filler) {
          o.filler = p.filler;
          if (p.filler === 'photo') { o.fillerMediaId = p.fillerMediaId; }
        }
        if (p.header && p.header !== 'titre') { o.header = p.header; }
        if (p.script) { o.script = true; }
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
    const pris = new Set(), parPage = [];
    // Pleine page (17/11/2026) : le produit choisi pour elle lui est réservé — les pages d'avant ne le prennent pas.
    const reserve = new Map();
    (S.pages || []).forEach((p, i) => { if (p.layout === 'pleine' && p.product != null && prods.some(x => x.id === +p.product) && !reserve.has(+p.product)) { reserve.set(+p.product, i); } });
    const out = (S.pages || []).map((p, i) => {
      const cap = LAYOUTS[p.layout] ? LAYOUTS[p.layout].cap : 6;
      const liste = estPres(p.layout) ? prods : parCat;
      let n = 0; const mes = [];
      const mien = p.layout === 'pleine' ? Array.from(reserve).find(e => e[1] === i) : null;
      if (mien) { pris.add(mien[0]); parPage.push([mien[0]]); return 1; }
      for (const x of liste) {
        if (reserve.has(x.id)) { continue; }
        if (n >= cap) { break; }
        if (pris.has(x.id) || (p.category != null && x.cat !== +p.category) || (p.gamme != null && !x.gammes.includes(+p.gamme))) { continue; }
        pris.add(x.id); n++; mes.push(x.id);
      }
      parPage.push(mes);
      return n;
    });
    out.sansPlace = prods.filter(x => !pris.has(x.id)).length;
    out.ids = parPage;   // les produits de chaque page, dans l'ordre : l'aperçu de la page (apercuPage)
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
    S.vue = 'assistant'; S.etape = 2; S.creation = true; S.cat = null; S.src = null; S.titre = ''; S.titreAuto = ''; S.choix = null; S.pages = null;
    S.format = 'A5'; S.nbPages = 8; S.ouverts = null; S.langs = ['fr']; S.lang = 'fr'; S.msg = null; S.rech = ''; oublierDocs(S);
    chargerProduits(S); rendre(S);
  }
  function ouvrir(S, id, etape, marque) {
    S.vue = 'assistant'; S.msg = null; S.rech = ''; S.ouverts = null; S.creation = false; S.grand = null; oublierDocs(S);
    chargerProduits(S);
    const c = (marque ? S.recus : S.liste || []).find(x => +x.id === +id);
    if (c) { adopter(S, c); S.etape = etape || 2; }
    occupe(S, appel('GET', (marque ? '/marque/' : '/') + id + qs(S)).then(j => { adopter(S, j.data); S.etape = etape || 2; entrer(S); }), 'Lecture du catalogue');
  }
  /** Ce qu'une étape lit en arrivant. */
  function entrer(S) {
    if (S.etape >= 3) { chargerDoc(S); }
    if (S.etape === 3 && S.mep == null) { S.mep = 0; }
    if (S.etape >= 4) { chargerVerif(S); }
    if (S.etape === 5) { S.lien = undefined; chargerLien(S); if (!S.imp.langue || (S.imp.langue !== 'fr+nl' && !S.langs.includes(S.imp.langue))) { S.imp.langue = S.langs.length > 1 ? 'fr+nl' : S.langs[0]; } }
    if (S.etape === 1 && !S.liste) { chargerListe(S); }
  }
  /** « Le prendre tel quel » : il devient le mien à regarder, vérifier, imprimer, diffuser (étapes 2 et 3 verrouillées). */
  function prendre(S, id) {
    occupe(S, appel('PUT', '/marque/' + id + '/reception' + qs(S), { status: 'pris' }).then(j => {
      S.liste = null; S.recus = null; chargerListe(S);
      adopter(S, j.data); S.creation = false; S.etape = 4; oublierDocs(S); entrer(S);
    }), 'Le prendre tel quel');
  }
  /** « L'adapter à ma boutique » : une copie à moi, que la marque ne modifie plus ; je reprends à l'étape 2. */
  function adapter(S, id) {
    if (!window.confirm('Faire une copie à moi de ce catalogue ? La marque ne la modifiera plus : ses mises à jour ne m’arriveront plus.')) { return; }
    occupe(S, appel('POST', '/marque/' + id + '/adaptation' + qs(S), {}).then(j => {
      const retires = (j.meta && j.meta.removedProducts) || [];
      S.liste = null; S.recus = null; chargerListe(S);
      adopter(S, j.data); S.creation = false; S.etape = 2; oublierDocs(S); chargerProduits(S);
      S.msg = { type: 'ok', titre: 'Le catalogue est à moi : je le change comme je veux.' + (retires.length ? ' ' + retires.length + ' produit' + (retires.length > 1 ? 's' : '') + ' que ma boutique ne vend pas ' + (retires.length > 1 ? 'ont été retirés' : 'a été retiré') + '.' : '') };
    }), 'L’adapter à ma boutique');
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
    occupe(S, appel('POST', qs(S), corps).then(j => { adopter(S, j.data); S.creation = false; S.etape = 2; S.liste = null; }), 'Création du catalogue');
  }
  function majTitre(S) {
    const titre = S.titre.trim();
    if (!S.cat || !titre || titre === S.cat.title) { return Promise.resolve(); }
    return appel('PUT', '/' + S.cat.id + qs(S), { title: titre }).then(j => { const ch = S.choix, pg = S.pages; adopter(S, j.data); S.choix = ch; S.pages = pg; });
  }
  /** Étape 2 → 3 : les produits ; la mise en page est refaite d'après eux (répartition automatique du Marketing). */
  /** Les produits à enregistrer : ceux du catalogue (dans son ordre) que ma boutique vend, puis les nouveaux cochés. */
  function lignesProduits(S) {
    const vendus = new Set(produits(S).map(p => +p.id));
    const lignes = []; const vus = new Set();
    (S.cat.products || []).forEach(p => { if (vendus.has(+p.id) && !vus.has(+p.id)) { vus.add(+p.id); lignes.push({ id: +p.id, on: !!S.choix[p.id] }); } });
    produits(S).forEach(p => { if (S.choix[p.id] && !vus.has(+p.id)) { vus.add(+p.id); lignes.push({ id: +p.id, on: true }); } });
    return lignes;
  }
  function enregistrerProduits(S, suite) {
    const lignes = lignesProduits(S);
    const avant = JSON.stringify((S.cat.products || []).map(p => [+p.id, p.on !== false]));
    const apres = JSON.stringify(lignes.map(p => [p.id, p.on]));
    // Sans `pages` : le Marketing garde ma mise en page et donne SA page à une catégorie ajoutée (une page vide, sinon
    // + 2 pages, 16 au plus) ; une catégorie retirée laisse sa page vide.
    let corps = avant === apres ? null : { products: lignes };
    const nbAvant = S.nbPages;
    // Promotions et disponibilité : enregistrées avec les produits (une offre cochée prend une case de sa page).
    const offresAvant = JSON.stringify((S.cat.offers || []).map(o => [o.type, +o.id, !!o.on, o.mediaId == null ? null : +o.mediaId]).sort());
    const offresApres = offresCorps(S);
    if (JSON.stringify(offresApres.map(o => [o.type, o.id, o.on, o.mediaId]).sort()) !== offresAvant) { corps = Object.assign(corps || {}, { offers: offresApres }); }
    if (S.dispo !== (S.cat.showAvailability !== false)) { corps = Object.assign(corps || {}, { showAvailability: S.dispo }); }
    const p = majTitre(S).then(() => corps ? appel('PUT', '/' + S.cat.id + qs(S), corps).then(j => { adopter(S, j.data); oublierDocs(S); }) : null)
      .then(() => { S.etape = suite; S.liste = null; entrer(S); });
    occupe(S, p, 'Enregistrement des produits').then(() => {
      if (!S.msg && S.nbPages > nbAvant) { S.msg = { type: 'inf', titre: 'Chaque catégorie a sa page : ' + (S.nbPages - nbAvant) + ' pages ajoutées (' + S.nbPages + ' pages).' }; rendre(S); }
    });
  }
  function enregistrerPages(S, suite) {
    const corps = { format: S.format, langs: S.langs.slice(), pages: pagesCorps(S) };
    if (S.cat && +S.cat.pageCount !== S.nbPages) { corps.pageCount = S.nbPages; }   // l'assistant ajoute ou retire 2 pages avec les pages
    if (S.choixModifie) { corps.products = lignesProduits(S); }   // les produits montrés ou cachés dans l'assistant (étape 3)
    const p = majTitre(S).then(() => appel('PUT', '/' + S.cat.id + qs(S), corps)).then(j => { S.choixModifie = false; adopter(S, j.data); oublierDocs(S); S.etape = suite; S.liste = null; entrer(S); });
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
    if (l !== 'pleine') { delete p.product; }   // le produit choisi ne vaut que pour une pleine page (le Marketing le refuse ailleurs)
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
    if (n === 1) { S.msg = null; S.etape = 1; S.grand = null; entrer(S); rendre(S); return; }
    if (!S.cat && !(n === 2 && S.creation)) { return; }
    if (S.cat && !estRecu(S) && S.etape === 2 && n > 2) { enregistrerProduits(S, n); return; }
    if (S.cat && !estRecu(S) && S.etape === 3 && S.modifie) { enregistrerPages(S, n); return; }
    S.msg = null; S.etape = n; S.grand = null;
    entrer(S);
    rendre(S);
  }
  /**
   * Un PDF : la page d'impression du Marketing (lien signé de 48 h), dans une ou deux langues. `suite` s'ajoute à
   * l'adresse : `&mode=printer` (PDF imprimeur : fond perdu, traits de coupe), `&mode=livret` (sur mon imprimante,
   * livret A4), `&mode=proof` (PDF léger à partager), `&affiche=A4&l=…` (l'affiche du magasin).
   */
  function telecharger(S, langs, suite, quoi) {
    // Les fenêtres s'ouvrent pendant le clic (sinon le navigateur les bloque), puis reçoivent l'adresse signée.
    const fenetres = langs.map(() => window.open('', '_blank'));
    fenetres.forEach(w => { if (w) { try { w.document.write('<p style="font:15px system-ui;padding:24px;color:#5c5852">Préparation du catalogue…</p>'); } catch (e) { /* rien */ } } });
    const c = S.cat;
    const p = Promise.all(langs.map((lang, i) => appel('POST', chemin(S) + '/impression' + qs(S), { lang }).then(j => {
      const url = j.data && j.data.url;
      if (!url) { throw new ErreurApi(502, { error: { message: 'Le Marketing n’a pas rendu d’adresse d’impression.' } }); }
      const w = fenetres[i];
      if (w) { w.location.href = url + (suite || ''); } else { window.open(url + (suite || ''), '_blank'); }
    }))).then(() => {
      S.msg = { type: 'ok', titre: (quoi || 'Le PDF') + ' s’ouvre dans un nouvel onglet' + (langs.length > 1 ? ' (un par langue)' : '') + ' : choisissez « Enregistrer au format PDF ». Le lien vaut 48 heures.' };
      if (c.owner !== 'marque' && c.status === 'brouillon') {
        return appel('PUT', '/' + c.id + qs(S), { status: 'pret' }).then(k => { adopter(S, k.data); S.liste = null; }).catch(() => {});
      }
    }).catch(e => { fenetres.forEach(w => { if (w) { w.close(); } }); throw e; });
    occupe(S, p, quoi || 'Téléchargement du PDF');
  }
  /** Un point de vérification vu (retenu par le Marketing). */
  function vu(S, code) {
    const v = verifDe(S); if (!v) { return; }
    const it = v.items.find(x => x.code === code); if (!it || it.seen) { return; }
    it.seen = true; v.seen += 1; rendre(S);
    appel('PUT', chemin(S) + '/verifications/' + encodeURIComponent(code) + qs(S), { seen: true }).catch(e => { it.seen = false; v.seen -= 1; S.msg = erreurMsg(S, e, 'Point vu'); rendre(S); });
  }
  /** « La retirer » : la promotion ne s'imprime plus (son choix enregistré, comme à l'étape 2). */
  function retirerPromo(S, code) {
    const v = verifDe(S); const it = v && v.items.find(x => x.code === code);
    if (!it || !it.offer) { return; }
    const k = it.offer.type + '-' + it.offer.id;
    S.offres[k] = false;
    occupe(S, chargerProduits(S).then(() => appel('PUT', '/' + S.cat.id + qs(S), { offers: offresCorps(S) })).then(j => { adopter(S, j.data); oublierDocs(S); S.verif = {}; entrer(S); }), 'Retrait de la promotion');
  }
  /** « Valider mon catalogue » : prêt à imprimer (le mien), puis l'export. */
  function valider(S) {
    const c = S.cat;
    const p = c.owner !== 'marque' && c.status === 'brouillon'
      ? appel('PUT', '/' + c.id + qs(S), { status: 'pret' }).then(j => { adopter(S, j.data); S.liste = null; })
      : Promise.resolve();
    occupe(S, p.then(() => { S.etape = 5; entrer(S); }), 'Validation');
  }
  function marquerImprime(S) {
    const q = Math.max(0, parseInt(S.imp.qte, 10) || 0);
    if (!q) { S.msg = { type: 'err', titre: 'Donnez le nombre d’exemplaires imprimés.' }; rendre(S); return; }
    occupe(S, appel('POST', chemin(S) + '/imprime' + qs(S), { quantity: q }).then(j => {
      adopter(S, j.data); S.liste = null;
      S.msg = { type: 'ok', titre: 'Imprimé · ' + q + ' ex. — ce catalogue garde ses pages de la marque telles qu’imprimées.' };
    }), 'Marquer imprimé');
  }
  function nouveauLien(S, confirmer) {
    if (confirmer && !window.confirm('Faire un nouveau lien ? L’ancien QR (affiches, catalogues déjà distribués) ne mènera plus au catalogue.')) { return; }
    occupe(S, appel('POST', chemin(S) + '/lien' + qs(S), {}).then(j => { S.lien = j.data; }), 'Lien du catalogue en ligne');
  }
  /** Le lien permanent, créé s'il n'existe pas encore, puis l'action. */
  function avecLien(S, faire) {
    if (S.lien) { faire(S.lien); return; }
    occupe(S, appel('POST', chemin(S) + '/lien' + qs(S), {}).then(j => { S.lien = j.data; faire(S.lien); }), 'Lien du catalogue en ligne');
  }
  /** Le QR du catalogue en ligne en image PNG (1000 px). */
  function qrPng(S) {
    if (!S.lien) { return; }
    const svg = qr(S.lien.url, 60).replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ').replace(/width="[^"]*" height="[^"]*"/, 'width="1000" height="1000"');
    const img = new Image();
    img.onload = () => {
      const c = document.createElement('canvas'); c.width = 1000; c.height = 1000;
      c.getContext('2d').drawImage(img, 0, 0, 1000, 1000);
      const a = document.createElement('a'); a.href = c.toDataURL('image/png'); a.download = 'QR catalogue — ' + (S.cat.title || '') + '.png'; a.click();
    };
    img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
  }
  function copierLien(S) {
    if (!S.lien) { return; }
    const ok = () => { S.msg = { type: 'ok', titre: 'Lien copié : ' + S.lien.url }; rendre(S); };
    if (navigator.clipboard) { navigator.clipboard.writeText(S.lien.url).then(ok, () => window.prompt('Le lien du catalogue en ligne', S.lien.url)); } else { window.prompt('Le lien du catalogue en ligne', S.lien.url); }
  }
  function partager(S) {
    avecLien(S, l => {
      if (navigator.share) { navigator.share({ title: S.cat.title, url: l.url }).catch(() => {}); } else { copierLien(S); }
    });
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
    else if (a === 'liste') { aller(S, 1); }
    else if (a === 'relire') { S.liste = null; S.recus = null; S.prod = null; chargerListe(S); rendre(S); }
    else if (a === 'ouvrir') { ouvrir(S, v, +(b.dataset.e || 2)); }
    else if (a === 'apercu') { ouvrir(S, v, 4); }
    else if (a === 'ouvrirM') { ev.stopPropagation(); ouvrir(S, v, +(b.dataset.e || 4), true); }
    else if (a === 'prendre') { ev.stopPropagation(); prendre(S, v); }
    else if (a === 'adapter') { ev.stopPropagation(); adapter(S, v || S.cat.id); }
    else if (a === 'valider') { valider(S); }
    else if (a === 'vu') { vu(S, v); }
    else if (a === 'geste') {
      const code = b.dataset.code;
      if (v === 'ouvrir-page') { S.grand = +b.dataset.p - 1; rendre(S); }
      else if (v === 'moins-de-pages') { changerNbPages(S, Math.max(8, S.nbPages - 2)); }
      else if (v === 'modifier') { aller(S, 3); }
      else if (v === 'retirer-promo') { retirerPromo(S, code); }
      else { vu(S, code); }
    }
    else if (a === 'grand') { S.grand = S.grand === +v ? null : +v; rendre(S); }
    else if (a === 'impmode') { S.imp.mode = v; rendre(S); }
    else if (a === 'implang') { S.imp.langue = v; rendre(S); }
    else if (a === 'impfmt') {
      if (S.cat.owner === 'marque' || v === S.format) { return; }
      occupe(S, appel('PUT', '/' + S.cat.id + qs(S), { format: v }).then(j => { adopter(S, j.data); oublierDocs(S); entrer(S); }), 'Format ' + v);
    }
    else if (a === 'imprime') { marquerImprime(S); }
    else if (a === 'afficheTaille') { S.affiche.taille = v; rendre(S); }
    else if (a === 'lienNouveau') { nouveauLien(S, true); }
    else if (a === 'qrPng') { qrPng(S); }
    else if (a === 'copier') { copierLien(S); }
    else if (a === 'partager') { partager(S); }
    else if (a === 'suppr') { ev.stopPropagation(); supprimer(S, v); }
    else if (a === 'statut') { ev.stopPropagation(); statut(S, v, b.dataset.st); }
    else if (a === 'src') { choisirSource(S, v); }
    else if (a === 'creer') { creer(S); }
    else if (a === 'etape') { aller(S, +v); }
    else if (a === 'prod') { if (S.choix) { S.choix[v] = !S.choix[v]; rendre(S); } }
    else if (a === 'offreImg') { S.choixOffre = S.choixOffre === v ? null : v; if (!S.medias) { chargerMedias(S); } rendre(S); }
    else if (a === 'offreImgRetirer') { delete S.offresImg[v]; rendre(S); }
    else if (a === 'offrePhoto') { S.offresImg[b.dataset.o] = +v; S.choixOffre = null; S.mediaUrls = S.mediaUrls || {}; rendre(S); }
    else if (a === 'offre') { const o = offres(S).find(x => x.id === v); if (o) { S.offres[v] = !offreOn(S, o); rendre(S); } }
    else if (a === 'dispo') { S.dispo = !S.dispo; rendre(S); }
    else if (a === 'tout') { (b.dataset.cat ? produits(S) : visibles(S)).filter(p => !b.dataset.cat || String(p.categoryId) === b.dataset.cat).forEach(p => { S.choix[p.id] = v === '1'; }); rendre(S); }
    else if (a === 'acc') { const o = ouverts(S); if (o.has(v)) { o.delete(v); } else { o.add(v); } rendre(S); }
    else if (a === 'nbpages') { changerNbPages(S, +v); }
    else if (a === 'repartirN') { repartir(S, +v); }
    else if (a === 'layout') { changerType(S, +b.dataset.i, v); }
    else if (a === 'script') { S.pages[+b.dataset.i].script = v === '1'; S.modifie = true; rendre(S); }
    else if (a === 'entete') { S.pages[+b.dataset.i].header = v; S.modifie = true; rendre(S); }
    else if (a === 'gabarit') { S.pages[+b.dataset.i].template = v; S.modifie = true; rendre(S); }
    else if (a === 'zoomPage') { S.zoomPage = +v; rendre(S); }
    else if (a === 'mep') { S.mep = +v; S.zoomPage = null; S.msg = null; rendre(S); const t = S.el.querySelector('.mep'); if (t && t.getBoundingClientRect().top < 0) { t.scrollIntoView({ block: 'start', behavior: 'smooth' }); } }
    else if (a === 'mepLay') { mepPoser(S, +b.dataset.c, { layout: v }); }
    else if (a === 'mepPleine') { mepPoser(S, +b.dataset.c, { pleine: v === '1' }); }
    else if (a === 'mepGab' || a === 'mepScript') { mepPages(S, +b.dataset.c).forEach(i => { if (a === 'mepGab') { S.pages[i].template = v; } else { S.pages[i].script = v === '1'; } }); S.modifie = true; rendre(S); }
    else if (a === 'mepNb') { mepNbPages(S, +v); }
    else if (a === 'mepProdOn') { mepMontrer(S, +b.dataset.c, +v); }
    else if (a === 'fb') { feuilleter(S, +v); }
    else if (a === 'fbAller') { if (!S.fbAnim) { S.fb = +v; rendre(S); } }
    else if (a === 'fbGrand') { S.fbGrand = !S.fbGrand; rendre(S); const l = S.el.querySelector(S.fbGrand ? '.fb-plein .fb-livre' : '.fb-livre'); if (l) { l.focus({ preventScroll: !S.fbGrand }); } }
    else if (a === 'mepBloc') { mepBouger(S, +v, +b.dataset.n, +b.dataset.d); }
    else if (a === 'mepLangs') { S.langs = v.split(','); if (!S.langs.includes(S.lang)) { S.lang = S.langs[0]; } S.modifie = true; rendre(S); }
    else if (a === 'mepDetail') { S.mepDetail = !S.mepDetail; S.zoomPage = null; rendre(S); }
    else if (a === 'zoomFermer') { S.zoomPage = null; rendre(S); }
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
    else if (a === 'lang') { S.lang = v; chargerDoc(S); if (S.etape === 4) { chargerVerif(S); } rendre(S); }
    else if (a === 'pdf') {
      const langs = S.imp.langue === 'fr+nl' ? ['fr', 'nl'] : [S.imp.langue || S.lang];
      if (v === 'leger') { telecharger(S, [S.lang], '&mode=proof', 'Le PDF à partager'); }
      else if (v === 'affiche') {
        if (!S.lien) { return; }
        telecharger(S, [S.lang], '&affiche=' + S.affiche.taille + '&l=' + encodeURIComponent(S.lien.token) + (S.affiche.titre.trim() ? '&titre=' + encodeURIComponent(S.affiche.titre.trim()) : ''), 'L’affiche');
      } else {
        const livret = S.imp.mode === 'livret';
        telecharger(S, langs, livret ? (S.cat.format === 'A5' ? '&mode=livret' : '&mode=proof') : '&mode=printer', livret ? 'Le livret à imprimer' : 'Le PDF imprimeur');
      }
    }
    else if (a === 'reessayer') { chargerDoc(S, true); chargerVerif(S, true); }
  }
  function saisie(S, ev) {
    const t = ev.target;
    if (t.dataset.cm === 'titre') { S.titre = t.value; }
    if (t.dataset.cm === 'rech') { S.rech = t.value; rendreGarde(S, t); }
    if (t.dataset.cm === 'ptexte' || t.dataset.cm === 'ptexteNl') { S.pages[+t.dataset.i][t.dataset.cm === 'ptexte' ? 'text' : 'textNl'] = t.value.slice(0, 400); S.modifie = true; }
    if (t.dataset.cm === 'mediasQ') { S.mediasQ = t.value; }
    if (t.dataset.cm === 'qte') { S.imp.qte = t.value.replace(/\D/g, '').slice(0, 7); }
    if (t.dataset.cm === 'afficheTitre') { S.affiche.titre = t.value.slice(0, 80); }
  }
  function changement(S, ev) {
    const t = ev.target;
    if (t.dataset.cm === 'pcat') { S.pages[+t.dataset.i].category = t.value === '' ? null : +t.value; S.modifie = true; rendre(S); }
    if (t.dataset.cm === 'pgamme') { S.pages[+t.dataset.i].gamme = t.value === '' ? null : +t.value; S.modifie = true; rendre(S); }
    if (t.dataset.cm === 'mepProd') { mepPoser(S, +t.dataset.c, { pleine: true, produit: t.value === '' ? null : +t.value }); }
    if (t.dataset.cm === 'pprod') { const q = S.pages[+t.dataset.i]; if (t.value === '') { delete q.product; } else { q.product = +t.value; } S.modifie = true; rendre(S); }
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

  function rendreEtapes(S) {
    const recu = estRecu(S);
    return '<div class="cm-et4 cm-et5">' + ETAPES.map((e, i) => {
      const n = i + 1, verrou = recu && (n === 2 || n === 3), fait = (S.cat && n < S.etape && !verrou) || (n === 1 && S.creation && S.etape > 1), on = n === S.etape;
      const off = n > 1 && !S.cat && !(n === 2 && S.creation);
      const d = verrou ? 'tel quel · « L’adapter » pour changer' : n === 1 && S.cat ? (recu ? 'de la marque, pris tel quel' : 'le mien · ' + (S.cat.title || '')) : e[1];
      return '<button class="cm-st' + (on ? ' on' : '') + (fait ? ' fait' : '') + (verrou ? ' verrou' : '') + '" data-cm="etape" data-v="' + n + '"' + (off ? ' disabled' : '') + '>'
        + '<span class="no">' + (verrou ? CADENAS : fait ? '✓' : n) + '</span><span style="min-width:0"><div class="l">' + n + ' · ' + esc(e[0]) + '</div><div class="d">' + esc(d) + '</div></span></button>';
    }).join('') + '</div>';
  }

  function rendreAssistant(S) {
    const titre = S.etape === 1 ? 'Mon catalogue' : S.cat ? 'Mon catalogue — « ' + esc(S.cat.title) + ' »' : 'Nouveau catalogue';
    const suivant = { 2: ['3', 'Présentation ›'], 3: ['4', 'Validation ›'], 4: null, 5: null }[S.etape];
    let h = '<div class="cm-hd"><div><div class="k">Espace boutique · ' + esc(S.nom || '') + '</div><h2>' + titre + '</h2></div><span class="sp"></span>';
    if (S.etape === 1) {
      h += '<button class="cm-btn" data-cm="relire">↻ Relire</button><button class="cm-btn" data-cm="nouveau">＋ Nouveau catalogue</button></div>';
    } else {
      h += '<button class="cm-btn" data-cm="liste">‹ Mes catalogues</button>'
        + (S.etape === 4 && S.cat ? '<button class="cm-btn pri" data-cm="valider"' + (S.occupe ? ' disabled' : '') + '>Valider mon catalogue ›</button>' : '')
        + (suivant && S.cat && !estRecu(S) ? '<button class="cm-btn pri" data-cm="etape" data-v="' + suivant[0] + '"' + (S.occupe ? ' disabled' : '') + '>' + suivant[1] + '</button>' : '')
        + (suivant && estRecu(S) ? '<button class="cm-btn pri" data-cm="etape" data-v="4">Validation ›</button>' : '') + '</div>';
    }
    h += rendreEtapes(S) + message(S);
    if (S.prodErr && (S.etape === 2 || S.etape === 3)) { h += bandeauErreur(S.prodErr, 'Les produits de ma boutique n’ont pas pu être lus'); }
    if (S.etape === 1) { h += rendreCatalogues(S); }
    else if ((S.etape === 2 || S.etape === 3) && estRecu(S)) { h += rendreVerrou(S); }
    else if (S.etape === 2) { h += S.cat ? rendreE2(S) : rendreE1(S); }
    else if (S.etape === 3) { h += S.mepDetail ? rendreE3(S) : rendreMep(S); }
    else if (S.etape === 4) { h += rendreValidation(S); }
    else { h += rendreExport(S); }
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
    h += '<div class="cm-msg inf" style="margin-top:12px">Seuls les produits de MON webshop sont proposés, à MES prix. Une catégorie ajoutée a sa page (une page vide, sinon 2 pages de plus, 16 au plus) ; une catégorie retirée laisse sa page vide.</div>';
    h += '<div class="cm-pied"><button class="cm-btn" data-cm="etape" data-v="1">‹ Catalogue</button><span class="sp"></span><button class="cm-btn pri" data-cm="etape" data-v="3"' + (S.occupe ? ' disabled' : '') + '>' + (S.occupe ? 'Enregistrement…' : 'Présentation ›') + '</button></div>';
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
      const img = (S.offresImg || {})[o.id];
      const choixImg = o.type === 'scheduledDiscount' ? '' : '<span class="cm-offre-img">' + (img != null
        ? (S.mediaUrls && S.mediaUrls[img] ? '<img src="' + esc(S.mediaUrls[img]) + '" alt="">' : '<span class="cm-cpt">image n° ' + img + '</span>')
          + '<button class="cm-lien" data-cm="offreImg" data-v="' + esc(o.id) + '">Changer</button><button class="cm-lien" data-cm="offreImgRetirer" data-v="' + esc(o.id) + '" title="Revenir aux photos des produits">Retirer</button>'
        : '<button class="cm-btn" data-cm="offreImg" data-v="' + esc(o.id) + '" title="Une image de la médiathèque à la place des photos des produits, dans la vignette">Image</button>') + '</span>';
      h += '<div class="cm-offre' + (on ? '' : ' off') + '"><span class="bd">' + esc(o.badge) + '</span><span class="nm">' + esc(o.name) + '<small>' + esc(det) + '</small><small>→ ' + esc(ou) + '</small>' + choixImg + '</span>'
        + (o.priceCents != null ? '<b>' + (o.oldPriceCents != null ? '<s>' + esc(prix(o.oldPriceCents)) + '</s> ' : '') + esc(prix(o.priceCents)) + '</b>' : '<b></b>')
        + '<button class="cm-sw' + (on ? ' on' : '') + '" role="switch" aria-checked="' + on + '" aria-label="' + esc(o.name) + '" data-cm="offre" data-v="' + esc(o.id) + '"><span class="tr" aria-hidden="true"></span></button></div>';
      if (S.choixOffre === o.id) { h += rendreMediasOffre(S, o.id); }
    });
    h += '<div class="cm-offre dispo"><span class="nm">Afficher la disponibilité<small>période et délai de commande lus dans TFBuddy, en pied de chaque page</small></span><b></b>'
      + '<button class="cm-sw' + (S.dispo ? ' on' : '') + '" role="switch" aria-checked="' + !!S.dispo + '" aria-label="Afficher la disponibilité" data-cm="dispo"><span class="tr" aria-hidden="true"></span></button></div>';
    return h + '</div>';
  }

  /* L'aperçu d'une page à l'étape 3 (17/11/2026, Sam : « au choix dans la liste, je dois voir la page ou les pages de la
   * catégorie ») : la page composée ici même, comme la composera le Marketing (mêmes rendus : page(), feuille()), avec
   * les produits que la répartition lui donne (remplir), leurs photos et leurs prix du webshop ; la charte et les dessins
   * des familles viennent du document enregistré (chargerDoc), s'il est lu. Elle suit la catégorie, la gamme, la mise en
   * page, l'en-tête, le gabarit et la place libre, sans attendre l'enregistrement. Un clic l'agrandit. */
  function dessinsDoc(S) {
    const doc = S.cat ? S.doc[S.cat.owner + S.cat.id + '|' + S.lang] : null, m = {};
    ((doc && doc.pages) || []).forEach(p => {
      if (p.kind === 'products') { m['c:' + p.title] = { d: p.drawingUrl, f: p.familyDrawingUrl, g: p.gammeImageUrl }; }
      if (p.kind === 'presentation') { m['g:' + p.title] = { d: p.drawingUrl, i: p.imageUrl }; }
    });
    return [doc, m];
  }
  function pageLocale(S, p, ids, m) {
    const fiche = id => { const x = produits(S).find(q => +q.id === +id); return x ? { name: x.name, priceCents: x.priceCents, oldPriceCents: x.oldPriceCents != null ? x.oldPriceCents : null, imageUrl: x.imageUrl || null, weightGrams: x.weightGrams != null ? x.weightGrams : null, allergens: x.allergens || [] } : null; };
    const prods = ids.map(fiche).filter(Boolean);
    if (estPres(p.layout)) {
      const t = nomGamme(S, p.gamme), d = m['g:' + t] || {};
      return { kind: 'presentation', layout: p.layout, template: p.template || 'A', title: t, text: (S.lang === 'nl' ? p.textNl : p.text) || '', drawingUrl: d.d || null, imageUrl: d.i || null, products: p.layout === 'presentation_liste' ? prods.slice(0, 8) : [] };
    }
    if (p.layout === 'pleine' && prods.length) { const o = offreDe(S, ids[0]); if (o) { prods.push(Object.assign({ kind: 'offer' }, o)); } }
    const titre = p.category == null ? (S.lang === 'nl' ? 'Onze selectie' : 'Notre sélection') : nomCat(S, p.category), d = m['c:' + titre] || {};
    const filler = p.filler === 'photo' ? { kind: 'photo', imageUrl: (S.mediaUrls || {})[p.fillerMediaId] || null } : p.filler === 'illustration' ? { kind: 'illustration', drawingUrl: d.f || d.d || null } : null;
    return { kind: 'products', layout: p.layout, template: p.template || 'A', header: p.header || 'titre', script: !!p.script, title: titre,
      gammeTitle: p.gamme != null ? nomGamme(S, p.gamme) : null, products: prods, filler, drawingUrl: d.d || null, familyDrawingUrl: d.f || null, gammeImageUrl: d.g || null };
  }
  function apercuPage(S, i, ids, largeur) {
    const p = S.pages[i];
    try {
      const [doc, m] = dessinsDoc(S);
      const pseudo = { catalogue: { format: S.format }, charter: (doc && doc.charter) || {}, version: { lang: S.lang || 'fr' }, pages: [pageLocale(S, p, ids, m)] };
      return '<button type="button" class="cm-ap" data-cm="zoomPage" data-v="' + i + '" title="Voir la page ' + (i + 3) + ' en grand">' + feuille(pseudo, 0, largeur || 128, true) + '</button>';
    } catch (e) { return mini(p.layout, p.template); }
  }
  /** Les pages de la même catégorie que la page i, dans l'ordre ; une présentation, « Notre sélection » sont seules. */
  function pagesSoeurs(S, i) {
    const p = S.pages[i];
    if (estPres(p.layout) || p.category == null) { return [i]; }
    const k = q => String(q.category);   // la catégorie seule : ses pages, quelle que soit leur gamme
    return S.pages.map((q, j) => j).filter(j => !estPres(S.pages[j].layout) && S.pages[j].category != null && k(S.pages[j]) === k(p));
  }
  /** L'aperçu d'une rangée (17/11/2026, Sam : « si j'ai plus d'une page — 2 au plus —, les mettre l'une à côté de l'autre ») :
   * une catégorie sur deux pages les montre côte à côte, celle de la rangée cerclée ; au-delà, la page seule. */
  function apercusRangee(S, i, ids) {
    const soeurs = pagesSoeurs(S, i);
    if (soeurs.length !== 2) { return apercuPage(S, i, ids[i] || []); }
    return '<div class="cm-ap-duo">' + soeurs.map(j => '<div class="' + (j === i ? 'moi' : '') + '">' + apercuPage(S, j, ids[j] || [], 96) + '<span>p. ' + (j + 3) + '</span></div>').join('') + '</div>';
  }
  /* Glisser-déposer les pages (17/11/2026, Sam : « en drag and drop, remonter ou descendre les pages du catalogue ») :
   * la poignée ⠿ d'une rangée la prend ; posée au-dessus ou au-dessous d'une autre, la page s'y range. Les pages de la
   * marque ne bougent pas. Les flèches ↑ ↓ restent. */
  function glisserDebut(S, ev) {
    // Mise en page finale : un bloc (une catégorie et ses pages) se prend par sa poignée.
    const hb = ev.target.closest && ev.target.closest('[data-blocdrag]');
    if (hb) {
      S.glisseBloc = { de: +hb.dataset.blocdrag, n: +hb.dataset.n };
      const r = hb.closest('.mep-bloc');
      try { ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', 'bloc ' + S.glisseBloc.de); if (r) { ev.dataTransfer.setDragImage(r, 20, 20); } } catch (e) { /* navigateur ancien */ }
      if (r) { setTimeout(() => r.classList.add('glisse'), 0); }
      return;
    }
    const h = ev.target.closest && ev.target.closest('[data-pgdrag]');
    if (!h) { return; }
    S.glisse = +h.dataset.pgdrag;
    try { ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', String(S.glisse)); const r = h.closest('.cm-pg'); if (r) { ev.dataTransfer.setDragImage(r, 30, 30); } } catch (e) { /* navigateur ancien */ }
    const r = h.closest('.cm-pg'); if (r) { setTimeout(() => r.classList.add('glisse'), 0); }
  }
  function cibleDepot(S, ev) {
    const r = ev.target.closest && ev.target.closest('.cm-pg[data-pg]');
    if (!r || S.glisse == null) { return null; }
    const b = r.getBoundingClientRect();
    return { r, i: +r.dataset.pg, apres: ev.clientY > b.top + b.height / 2 };
  }
  /** Le bloc sous le pointeur, et le côté où le bloc pris se poserait (avant · après, sur la largeur). */
  function cibleBloc(S, ev) {
    const r = ev.target.closest && ev.target.closest('.mep-bloc[data-bloc]');
    if (!r || !S.glisseBloc) { return null; }
    const b = r.getBoundingClientRect();
    return { r, de: +r.dataset.bloc, n: +r.dataset.n, apres: ev.clientX > b.left + b.width / 2 };
  }
  function glisserSur(S, ev) {
    if (S.glisseBloc) {
      const cb = cibleBloc(S, ev);
      if (!cb) { return; }
      ev.preventDefault();
      S.el.querySelectorAll('.mep-bloc.depot-avant,.mep-bloc.depot-apres').forEach(x => { if (x !== cb.r) { x.classList.remove('depot-avant', 'depot-apres'); } });
      cb.r.classList.toggle('depot-avant', !cb.apres); cb.r.classList.toggle('depot-apres', cb.apres);
      return;
    }
    const c = cibleDepot(S, ev);
    if (!c) { return; }
    ev.preventDefault();
    S.el.querySelectorAll('.cm-pg.depot-avant,.cm-pg.depot-apres').forEach(x => { if (x !== c.r) { x.classList.remove('depot-avant', 'depot-apres'); } });
    c.r.classList.toggle('depot-avant', !c.apres); c.r.classList.toggle('depot-apres', c.apres);
  }
  function deposer(S, ev) {
    if (S.glisseBloc) {
      const cb = cibleBloc(S, ev), g = S.glisseBloc;
      S.glisseBloc = null;
      if (cb) { ev.preventDefault(); mepDeplacer(S, g.de, g.n, cb.apres ? cb.de + cb.n : cb.de); }
      rendre(S); return;
    }
    const c = cibleDepot(S, ev);
    if (!c) { return; }
    ev.preventDefault();
    const de = S.glisse; let vers = c.i + (c.apres ? 1 : 0);
    S.glisse = null;
    if (de == null || !S.pages[de]) { rendre(S); return; }
    if (vers > de) { vers--; }
    if (vers !== de) { const [p] = S.pages.splice(de, 1); S.pages.splice(vers, 0, p); S.modifie = true; S.zoomPage = null; }
    rendre(S);
  }
  function glisserFin(S) { if (S.glisse != null || S.glisseBloc) { S.glisse = null; S.glisseBloc = null; rendre(S); } }
  /** La page agrandie (un clic sur son aperçu), et ses voisines de la même catégorie. */
  function rendreZoom(S) {
    if (S.zoomPage == null || !S.pages || !S.pages[S.zoomPage]) { return ''; }
    const p = S.pages[S.zoomPage], ids = remplir(S).ids || [];
    const memes = S.pages.map((q, j) => j).filter(j => !estPres(S.pages[j].layout) && !estPres(p.layout) ? S.pages[j].category === p.category && (S.pages[j].gamme || null) === (p.gamme || null) : j === S.zoomPage);
    return '<div class="cm-zoom" data-cm="zoomFermer"><div class="cm-zoom__in">' + memes.map(j => '<div class="cm-zoom__p">' + apercuPage(S, j, ids[j] || [], memes.length > 1 ? 380 : 460) + '<span>Page ' + (j + 3) + '</span></div>').join('')
      + '</div><button type="button" class="cm-btn cm-zoom__x" data-cm="zoomFermer">Fermer</button></div>';
  }
  function mini(layout, tpl) {
    if (estPres(layout)) { return '<span class="mini ' + layout + ' t' + esc(tpl || 'A') + '" aria-hidden="true"><b></b><i></i>' + (layout === 'presentation_liste' ? '<u></u><u></u><u></u>' : '') + '</span>'; }
    if (layout === 'pleine') { return '<span class="mini pleine t' + esc(tpl || 'A') + '" aria-hidden="true"><i></i><i></i></span>'; }
    const n = layout === 'vedette' ? 5 : layout === 'mosaique' ? 4 : 6;
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
  /** Vignette d'un en-tête (la même que le Marketing, HeaderIcon). */
  function vignetteEntete(t) {
    const dessin = (cx, cy, r, c) => '<g fill="none" stroke="' + c + '" stroke-width="1.1"><path d="M' + (cx - r) + ' ' + (cy + r * 0.5) + ' Q' + cx + ' ' + (cy - r * 1.1) + ' ' + (cx + r) + ' ' + (cy + r * 0.5) + ' Z"/></g>';
    let h = '<svg width="28" height="36" viewBox="0 0 40 52" aria-hidden="true"><rect x=".6" y=".6" width="38.8" height="50.8" rx="2" fill="none" stroke="currentColor" stroke-width="1.4"/>';
    if (t === 'bandeau') { h += '<rect x=".6" y=".6" width="38.8" height="13" fill="currentColor" fill-opacity=".85"/>' + dessin(8.5, 7, 4.5, '#fff') + '<rect x="16" y="5.5" width="18" height="3" rx="1" fill="#fff"/>'; }
    else if (t === 'grande') { h += dessin(20, 10, 7, 'currentColor') + '<rect x="11" y="18" width="18" height="3" rx="1" fill="currentColor"/>'; }
    else { h += dessin(10, 6.5, 3, 'currentColor') + '<rect x="15" y="5" width="16" height="3" rx="1" fill="currentColor"/>'; }
    (t === 'grande' ? [25, 37] : [17, 29, 41]).forEach(y => { [4, 21].forEach(x => { h += '<rect x="' + x + '" y="' + y + '" width="15" height="' + (t === 'grande' ? 10 : 9) + '" rx="1.5" fill="none" stroke="currentColor" stroke-width="1"/>'; }); });
    return h + '</svg>';
  }
  /* --- L'assistant de mise en page (17/11/2026, Sam : « la mise en page sous forme d'assistant, catégorie par catégorie
   * sélectionnée ; la dernière page de l'assistant est la mise en page finale, où l'on déplace une catégorie en glisser-
   * déposer » ; « code comme les maquettes », docs/maquettes/assistant-mise-en-page). Une sous-étape par catégorie choisie
   * à l'étape 2, puis la mise en page finale ; « Réglages page par page » ouvre la liste détaillée (rendreE3). ---------- */
  const MEP_LAYOUTS = ['vedette', 'grille', 'liste', 'mosaique'];
  const MEP_COULEURS = ['#8D1D2C', '#B0792A', '#3f7a52', '#2F5D8A', '#7A4E8C', '#A0522D'];
  /** Les catégories de l'assistant : celles des produits choisis, dans l'ordre de l'étape 2 (« Notre sélection » n'en est pas une). */
  const mepCats = S => catsChoisies(S).filter(c => c != null);
  const mepCouleur = (S, c) => { const i = mepCats(S).indexOf(+c); return i >= 0 && i < MEP_COULEURS.length ? MEP_COULEURS[i] : '#9a948c'; };
  const mepPt = col => '<i class="mep-pt" style="background:' + col + '"></i>';
  function mepCompte(S, c) { const ids = new Set(choisis(S)); return produits(S).filter(p => ids.has(+p.id) && p.categoryId != null && +p.categoryId === +c).length; }
  /** Les pages d'une catégorie (hors présentations), dans l'ordre du catalogue. */
  const mepPages = (S, c) => (S.pages || []).map((p, i) => i).filter(i => !estPres(S.pages[i].layout) && S.pages[i].category != null && +S.pages[i].category === +c);
  /** Une page libre : ni présentation, ni produit — « Notre sélection » vide, ou une catégorie dont rien n'est choisi. */
  function mepLibre(S, i, portes) {
    const p = S.pages[i];
    return !estPres(p.layout) && !(portes[i] > 0) && (p.category == null || !mepCats(S).includes(+p.category));
  }
  const PAGE_VIDE = () => ({ layout: 'grille', category: null, template: 'A', gamme: null, text: '', textNl: '', filler: null, fillerMediaId: null, header: 'titre', script: false });
  /** Le réglage d'une catégorie, lu dans ses pages : sa pleine page (et son produit), la mise en page des autres, gabarit, manuscrite. */
  function mepEtat(S, c) {
    const idx = mepPages(S, c);
    const pl = idx.find(i => S.pages[i].layout === 'pleine'), autre = idx.find(i => S.pages[i].layout !== 'pleine');
    return { idx, pleine: pl != null, produit: pl != null && S.pages[pl].product != null ? +S.pages[pl].product : null, layout: autre != null ? S.pages[autre].layout : null,
      template: idx.length ? (S.pages[idx[0]].template || 'A') : null, script: idx.length ? !!S.pages[idx[0]].script : false };
  }
  /**
   * Refait les pages d'une catégorie : sa pleine page (au choix, avec son produit), puis assez de pages de la mise en page
   * choisie pour ses autres produits. Elles prennent la place des anciennes (une nouvelle catégorie : avant les catégories
   * qui la suivent à l'étape 2, sinon avant les pages libres de la fin). Les pages libres font la place ; au-delà, le
   * catalogue prend 2 pages de plus (16 au plus), comme le Marketing quand une catégorie arrive.
   */
  function mepPoser(S, c, reglage) {
    const e = mepEtat(S, c), n = mepCompte(S, c), cats = mepCats(S);
    const r = Object.assign({ layout: e.layout && MEP_LAYOUTS.includes(e.layout) ? e.layout : 'grille', pleine: e.pleine, produit: e.produit }, reglage);
    const modele = e.idx.length ? S.pages[e.idx[0]] : ((S.pages || []).find(p => !estPres(p.layout) && p.category != null) || PAGE_VIDE());
    const tpl = e.template || modele.template || 'A', script = e.idx.length ? e.script : !!modele.script;
    const portes0 = remplir(S), libres0 = new Set((S.pages || []).filter((p, i) => mepLibre(S, i, portes0)));
    // Les anciennes pages se reprennent (place libre, gamme…) : la pleine page pour la pleine page, les autres ensuite.
    const anciennes = e.idx.map(i => S.pages[i]), prises = new Set();
    const reprise = l => { const q = anciennes.find(p => p.layout === l && !prises.has(p)) || anciennes.find(p => !prises.has(p)); if (q) { prises.add(q); } return q ? Object.assign({}, q) : PAGE_VIDE(); };
    const nouvelles = [];
    if (r.pleine) {
      const q = Object.assign(reprise('pleine'), { layout: 'pleine', category: +c, template: tpl, script, filler: null, fillerMediaId: null });
      if (r.produit != null) { q.product = +r.produit; } else { delete q.product; }
      nouvelles.push(q);
    }
    const reste = Math.max(0, n - (r.pleine ? 1 : 0));
    const k = reste ? Math.ceil(reste / LAYOUTS[r.layout].cap) : (r.pleine ? 0 : 1);
    for (let j = 0; j < k; j++) { const q = Object.assign(reprise(r.layout), { layout: r.layout, category: +c, template: tpl, script }); delete q.product; nouvelles.push(q); }
    const pages = (S.pages || []).filter((p, i) => !e.idx.includes(i));
    let ou = e.idx.length ? e.idx[0] : -1;
    if (ou < 0) {
      const apres = cats.slice(cats.indexOf(+c) + 1);
      ou = pages.findIndex(p => !estPres(p.layout) && p.category != null && apres.includes(+p.category));
      if (ou < 0) { ou = pages.length; while (ou > 0 && libres0.has(pages[ou - 1])) { ou--; } }
    }
    pages.splice(ou, 0, ...nouvelles);
    // La place : les pages restées libres (de la fin d'abord), puis 2 pages de plus.
    const garde = S.pages; S.pages = pages;
    const portes = remplir(S);
    const libres = new Set(pages.filter((p, i) => mepLibre(S, i, portes)));
    let nb = S.nbPages;
    for (let t = pages.length - 1; t >= 0 && pages.length > nb - PAGES_MARQUE; t--) { if (libres.has(pages[t])) { pages.splice(t, 1); } }
    const max = NB_PAGES[NB_PAGES.length - 1];
    while (pages.length > nb - PAGES_MARQUE && nb < max) { nb += 2; }
    if (pages.length > nb - PAGES_MARQUE) {
      S.pages = garde;
      S.msg = { type: 'err', titre: 'Plus de place : un catalogue a ' + max + ' pages au plus. Pour « ' + nomCat(S, c) + ' », choisissez une mise en page plus dense, ou moins de produits à l’étape 2.' };
      rendre(S); return;
    }
    while (pages.length < nb - PAGES_MARQUE) { pages.push(PAGE_VIDE()); }
    S.msg = nb > S.nbPages ? { type: 'inf', titre: '« ' + nomCat(S, c) + ' » demande plus de place : le catalogue passe à ' + nb + ' pages (toujours par 2).' } : null;
    S.nbPages = nb; S.modifie = true; S.zoomPage = null;
    rendre(S);
  }
  /** Le nombre de pages à la mise en page finale : les pages libres de la fin partent, ou des pages vides s'ajoutent. */
  function mepNbPages(S, nb) {
    const portes = remplir(S), pages = S.pages.slice();
    for (let t = pages.length - 1; t >= 0 && pages.length > nb - PAGES_MARQUE; t--) { if (mepLibre(S, S.pages.indexOf(pages[t]), portes)) { pages.splice(t, 1); } }
    if (pages.length > nb - PAGES_MARQUE) { S.msg = { type: 'err', titre: 'À ' + nb + ' pages, des produits n’auraient plus de place : retirez des produits à l’étape 2, ou choisissez des mises en page plus denses.' }; rendre(S); return; }
    while (pages.length < nb - PAGES_MARQUE) { pages.push(PAGE_VIDE()); }
    S.pages = pages; S.nbPages = nb; S.modifie = true; S.msg = null; rendre(S);
  }
  /** Les blocs de la mise en page finale : les pages qui se suivent d'une même catégorie (ou libres) font un bloc. */
  function mepBlocs(S, portes) {
    const out = [];
    (S.pages || []).forEach((p, i) => {
      const libre = mepLibre(S, i, portes);
      const cle = estPres(p.layout) ? 'g' + i : libre ? 'libre' : p.category == null ? 'sel' : 'c' + p.category;
      const der = out[out.length - 1];
      if (der && der.cle === cle && !estPres(p.layout)) { der.n++; } else { out.push({ cle, de: i, n: 1, p, libre }); }
    });
    return out;
  }
  /** Déplace un bloc (pages de..de+n) avant la position `vers` (un index de page, compté avant le retrait). */
  function mepDeplacer(S, de, n, vers) {
    if (vers >= de && vers <= de + n) { return false; }
    const pris = S.pages.slice(de, de + n), reste = S.pages.slice(0, de).concat(S.pages.slice(de + n));
    reste.splice(vers > de ? vers - n : vers, 0, ...pris);
    S.pages = reste; S.modifie = true; S.zoomPage = null;
    return true;
  }
  /** Les flèches d'un bloc (sans souris) : avant le bloc précédent, après le suivant. */
  function mepBouger(S, de, n, d) {
    const blocs = mepBlocs(S, remplir(S)), k = blocs.findIndex(x => x.de === de);
    const voisin = blocs[k + d];
    if (k < 0 || !voisin) { return; }
    mepDeplacer(S, de, n, d < 0 ? voisin.de : voisin.de + voisin.n);
    rendre(S);
  }

  function mepSous(S, cats) {
    const fin = cats.length;
    const e = cats.map((c, i) => [i, esc(nomCat(S, c)), mepCompte(S, c) + ' produit' + (mepCompte(S, c) > 1 ? 's' : '')]).concat([[fin, 'Mise en page finale', 'l’ordre, en glisser-déposer']]);
    return '<div class="mep-sous" style="grid-template-columns:repeat(' + Math.min(e.length, 6) + ',minmax(0,1fr))">' + e.map(x => {
      const on = x[0] === S.mep, fait = x[0] < S.mep;
      return '<button class="mep-ss' + (on ? ' on' : fait ? ' fait' : '') + (x[0] === fin ? ' fin' : '') + '" data-cm="mep" data-v="' + x[0] + '"><span class="no">' + (fait ? '✓' : x[0] === fin ? '⠿' : x[0] + 1) + '</span><span style="min-width:0"><span class="l">' + x[1] + '</span><span class="d">' + x[2] + '</span></span></button>';
    }).join('') + '</div>';
  }
  const mepSeg = (action, c, choix, cur) => '<span class="mep-seg">' + choix.map(o => '<button data-cm="' + action + '"' + (c != null ? ' data-c="' + c + '"' : '') + ' data-v="' + o[0] + '" class="' + (String(cur) === String(o[0]) ? 'on' : '') + '">' + o[1] + '</button>').join('') + '</span>';
  /** « Un produit seul sur une page, avec sa promotion » : non · oui, et le produit (le premier de la catégorie, sinon celui choisi). */
  function mepPleine(S, c, e, portes) {
    let h = '<div class="mep-opt' + (e.pleine ? ' on' : '') + '"><div class="t"><span><span class="mep-pt mep-pt--r"></span>Un produit seul sur une page, avec sa promotion</span><span class="sp"></span>' + mepSeg('mepPleine', c, [['0', 'Non'], ['1', 'Oui']], e.pleine ? '1' : '0') + '</div>';
    if (!e.pleine) { return h + '<div class="s">Une page de plus, avant les autres : le produit en grand, son poids, ses allergènes, son prix barré par sa remise et l’offre qui le contient.</div></div>'; }
    const ch = new Set(choisis(S)), liste = produits(S).filter(x => ch.has(+x.id) && x.categoryId != null && +x.categoryId === +c);
    const ip = e.idx.find(i => S.pages[i].layout === 'pleine'), vu = ip != null && portes.ids && portes.ids[ip] && portes.ids[ip][0] != null ? produits(S).find(x => +x.id === +portes.ids[ip][0]) : null;
    const o = vu ? offreDe(S, vu.id) : null;
    return h + '<select class="cm-sel mep-sel" data-cm="mepProd" data-c="' + c + '" aria-label="Le produit de la pleine page"><option value=""' + (e.produit == null ? ' selected' : '') + '>Le premier de la catégorie</option>'
      + liste.map(x => '<option value="' + x.id + '"' + (+e.produit === +x.id ? ' selected' : '') + '>' + esc(x.name) + '</option>').join('') + '</select>'
      + '<div class="s">' + (vu ? '« ' + esc(vu.name) + ' » seul sur sa page · ' + (o ? 'avec sa promotion « ' + esc(o.name) + ' », imprimée dessous.' : 'pas de promotion cochée pour lui : la page montre son prix. Avec une offre (bundle, « 2+1 ») qui le contient, elle s’imprime dessous.') : 'Aucun produit choisi dans cette catégorie.') + '</div></div>';
  }
  function mepCategorie(S, cats, rang, portes) {
    const c = cats[rang], total = cats.length, e = mepEtat(S, c), n = mepCompte(S, c), ids = portes.ids || [];
    const reste = Math.max(0, n - (e.pleine ? 1 : 0));
    let h = '<div class="mep-kick">Étape 3 · Présentation — catégorie ' + (rang + 1) + ' sur ' + total + '</div>'
      + '<div class="mep-tit"><h2>' + esc(nomCat(S, c)) + '</h2><span>' + n + ' produit' + (n > 1 ? 's' : '') + ' choisi' + (n > 1 ? 's' : '') + ' à l’étape 2</span></div>';
    if (e.pleine) { h += mepPleine(S, c, e, portes); }
    if (!e.pleine || reste > 0) {
      h += '<div class="mep-q">' + (e.pleine ? (reste > 1 ? 'Et les ' + reste + ' autres ?' : 'Et l’autre ?') : 'Comment présenter ' + (n > 1 ? 'ces ' + n + ' produits' : 'ce produit') + ' ?') + '</div>'
        + '<div class="mep-lays">' + MEP_LAYOUTS.map(l => {
          const nb = Math.max(1, Math.ceil(reste / LAYOUTS[l].cap));
          return '<button class="mep-lay' + (e.layout === l ? ' on' : '') + (nb > 2 ? ' trop' : '') + '" data-cm="mepLay" data-c="' + c + '" data-v="' + l + '"' + (S.occupe ? ' disabled' : '') + '>' + mini(l, e.template || 'A')
            + '<b>' + esc(DISPOS[l][0]) + '</b><small>' + esc(DISPOS[l][1]) + '</small><em>' + nb + ' page' + (nb > 1 ? 's' : '') + (nb > 2 ? ' · plus de 2' : '') + '</em></button>';
        }).join('') + '</div>';
    }
    h += '<div class="mep-aps">' + (e.idx.length ? e.idx.map(i => '<div class="mep-ap">' + apercuPage(S, i, ids[i] || [], e.idx.length > 2 ? 160 : 210)
      + '<span>page <b>' + (i + 3) + '</b> · ' + (S.pages[i].layout === 'pleine' ? 'pleine page' : (portes[i] || 0) + ' produit' + ((portes[i] || 0) > 1 ? 's' : '')) + '</span></div>').join('')
      : '<div class="mep-rien">Pas encore de page pour cette catégorie : choisissez une mise en page.</div>') + '</div>';
    const gab = '<div class="mep-opt"><div class="t">Gabarit</div><div class="mep-gabs">' + ['A', 'B', 'C'].map(t => '<button class="mep-gab' + ((e.template || 'A') === t ? ' on' : '') + '" data-cm="mepGab" data-c="' + c + '" data-v="' + t + '"' + (e.idx.length ? '' : ' disabled') + ' title="' + esc(GABARITS.produits[t][1]) + '">' + t + ' · ' + esc(GABARITS.produits[t][0]) + '</button>').join('') + '</div>'
      + (e.pleine ? '' : '<div class="t">Catégorie manuscrite<span class="sp"></span>' + mepSeg('mepScript', c, [['0', 'Non'], ['1', 'Oui']], e.script ? '1' : '0') + '</div>') + '</div>';
    h += '<div class="mep-opts">' + (e.pleine ? gab + '<div class="mep-opt"><div class="t">Catégorie manuscrite<span class="sp"></span>' + mepSeg('mepScript', c, [['0', 'Non'], ['1', 'Oui']], e.script ? '1' : '0') + '</div></div>' : mepPleine(S, c, e, portes) + gab) + '</div>';
    const inner = S.nbPages - PAGES_MARQUE, libres = (S.pages || []).filter((p, i) => mepLibre(S, i, portes)).length;
    const prec = rang === 0 ? '<button class="cm-btn" data-cm="etape" data-v="2">‹ Gammes</button>' : '<button class="cm-btn" data-cm="mep" data-v="' + (rang - 1) + '">‹ ' + esc(nomCat(S, cats[rang - 1])) + '</button>';
    const suiv = '<button class="cm-btn pri" data-cm="mep" data-v="' + (rang + 1) + '">' + (rang < total - 1 ? esc(nomCat(S, cats[rang + 1])) : 'Mise en page finale') + ' ›</button>';
    return h + '<div class="mep-pied">' + prec + '<span class="sp"></span><span class="cm-cpt">' + e.idx.length + ' page' + (e.idx.length > 1 ? 's' : '') + ' · ' + (inner - libres) + ' pages de produits sur ' + inner + (libres ? ' · ' + libres + ' libre' + (libres > 1 ? 's' : '') : '') + '</span>' + suiv + '</div>';
  }
  function mepFinale(S, cats, portes) {
    const ids = portes.ids || [], blocs = mepBlocs(S, portes), [doc] = dessinsDoc(S), nb = S.nbPages;
    const marque = (j, no) => '<div class="mep-th">' + (doc && doc.pages && doc.pages[j] ? feuille(doc, j, 92, true) : '<span class="mep-vide mep-vide--m"></span>') + '<span>' + no + '</span></div>';
    const np = doc && doc.pages ? doc.pages.length : 0;
    const nomBloc = b => b.cle === 'libre' ? (b.n > 1 ? 'Pages libres' : 'Page libre') : b.cle === 'sel' ? 'Notre sélection' : estPres(b.p.layout) ? 'Présentation · ' + nomGamme(S, b.p.gamme) : nomCat(S, b.p.category);
    let h = '<div class="mep-kick">Étape 3 · Présentation — la mise en page finale</div>'
      + '<div class="mep-tit"><h2>Mon catalogue</h2><span>' + nb + ' pages ' + esc(S.format) + ' · je glisse une catégorie pour changer l’ordre, ses pages la suivent</span></div>'
      + '<div class="mep-fin">'
      + '<div class="mep-bloc lock"><div class="h">' + CADENAS + ' Marque</div><div class="pp">' + marque(0, 1) + marque(1, 2) + '</div></div>'
      + blocs.map((b, k) => {
        const col = b.cle.charAt(0) === 'c' ? mepCouleur(S, b.p.category) : null;
        const pp = Array.from({ length: b.n }, (x, j) => b.de + j).map(i => '<div class="mep-th">' + (b.libre && !S.pages[i].filler ? '<div class="mep-vide">Photo ou illustration de la gamme · ou −2 pages</div>' : apercuPage(S, i, ids[i] || [], 92)) + '</div>').join('');
        return '<div class="mep-bloc' + (S.glisseBloc && S.glisseBloc.de === b.de ? ' glisse' : '') + (b.libre ? ' libre' : '') + '" data-bloc="' + b.de + '" data-n="' + b.n + '"><div class="h">'
          + '<span class="mep-poi" draggable="true" data-blocdrag="' + b.de + '" data-n="' + b.n + '" title="Glisser pour déplacer" aria-label="Déplacer « ' + esc(nomBloc(b)) + ' »">⠿</span>' + (col ? mepPt(col) : '') + '<span class="nm">' + esc(nomBloc(b)) + '</span>'
          + '<span class="pg">p. ' + (b.de + 3) + (b.n > 1 ? '-' + (b.de + b.n + 2) : '') + '</span>'
          + '<button class="mep-mv" data-cm="mepBloc" data-v="' + b.de + '" data-n="' + b.n + '" data-d="-1"' + (k === 0 ? ' disabled' : '') + ' aria-label="Avant">‹</button><button class="mep-mv" data-cm="mepBloc" data-v="' + b.de + '" data-n="' + b.n + '" data-d="1"' + (k === blocs.length - 1 ? ' disabled' : '') + ' aria-label="Après">›</button>'
          + '</div><div class="pp">' + pp + '</div></div>';
      }).join('')
      + '<div class="mep-bloc lock"><div class="h">' + CADENAS + ' Marque</div><div class="pp">' + marque(np - 2, nb - 1) + marque(np - 1, nb) + '</div></div></div>'
      + '<div class="mep-note">⠿ prendre une catégorie : ses pages suivent ensemble (‹ › sans souris) · un clic sur une page l’ouvre en grand · les pages de la marque ne bougent pas.</div>';
    // Ce qui n'a pas de place, dit (la règle du Marketing).
    const sansPage = catsChoisies(S).filter(c => c != null && !(S.pages || []).some(p => !estPres(p.layout) && p.category != null && +p.category === +c));
    if (sansPage.length) { h += '<div class="cm-msg inf">' + sansPage.map(c => '« ' + esc(nomCat(S, c)) + ' »').join(', ') + (sansPage.length > 1 ? ' n’ont' : ' n’a') + ' pas de page : ' + (sansPage.length > 1 ? 'leurs' : 'ses') + ' produits vont en « Notre sélection » s’il y en a une.</div>'; }
    if (portes.sansPlace) { h += '<div class="cm-msg inf">' + portes.sansPlace + ' produit' + (portes.sansPlace > 1 ? 's' : '') + ' choisi' + (portes.sansPlace > 1 ? 's' : '') + ' sans place dans les pages : choisissez une mise en page plus dense pour sa catégorie, ou ajoutez 2 pages.</div>'; }
    const inner = nb - PAGES_MARQUE, utiles = (S.pages || []).filter((p, i) => !mepLibre(S, i, portes)).length;
    const liste = (S.prod && Array.isArray(S.prod.pageCounts) && S.prod.pageCounts.length ? S.prod.pageCounts : NB_PAGES).map(Number);
    h += '<div class="mep-opts"><div class="mep-opt"><div class="t">Nombre de pages<span class="sp"></span><span class="mep-seg">' + liste.map(x => '<button data-cm="mepNb" data-v="' + x + '" class="' + (x === nb ? 'on' : '') + '"' + (x - PAGES_MARQUE < utiles || S.occupe ? ' disabled' : '') + '>' + x + '</button>').join('') + '</span></div>'
      + '<div class="s">Toujours par 2, dont 4 de la marque. ' + (inner - utiles > 1 ? 'À ' + (nb - 2) + ' pages, 2 pages libres partent.' : utiles === inner ? 'Toutes les pages de produits sont prises.' : '1 page libre : une photo ou l’illustration de la gamme la prend.') + '</div></div>'
      + '<div class="mep-opt"><div class="t">Format<span class="sp"></span>' + mepSeg('format', null, [['A5', 'A5'], ['A4', 'A4']], S.format) + '</div>'
      + '<div class="t">Langues<span class="sp"></span>' + mepSeg('mepLangs', null, [['fr', 'FR'], ['fr,nl', 'FR + NL']], S.langs.includes('nl') ? 'fr,nl' : 'fr') + '</div></div></div>';
    const prec = cats.length ? '<button class="cm-btn" data-cm="mep" data-v="' + (cats.length - 1) + '">‹ ' + esc(nomCat(S, cats[cats.length - 1])) + '</button>' : '<button class="cm-btn" data-cm="etape" data-v="2">‹ Gammes</button>';
    return h + '<div class="mep-pied">' + prec + '<button class="cm-btn" data-cm="mepDetail">Réglages page par page</button><span class="sp"></span><span class="cm-cpt">' + (S.modifie ? 'non enregistré : « Validation » enregistre' : 'chaque catégorie se reprend en haut') + '</span>'
      + '<button class="cm-btn pri" data-cm="etape" data-v="4"' + (S.occupe ? ' disabled' : '') + '>' + (S.occupe ? 'Enregistrement…' : 'Validation ›') + '</button></div>';
  }
  function mepCote(S, cats, portes, finale) {
    const inner = S.nbPages - PAGES_MARQUE;
    const parts = cats.map(c => [c, mepPages(S, c)]);
    const autres = (S.pages || []).map((p, i) => i).filter(i => !mepLibre(S, i, portes) && (estPres(S.pages[i].layout) || S.pages[i].category == null || !cats.includes(+S.pages[i].category))).length;
    const pris = parts.reduce((s, x) => s + x[1].length, 0) + autres;
    const plage = l => l.length ? 'p. ' + (l[0] + 3) + (l.length > 1 ? '-' + (l[l.length - 1] + 3) : '') : 'pas de page';
    return '<aside class="mep-cote"><h3>Mon catalogue · ' + S.nbPages + ' pages ' + esc(S.format) + '</h3><div><b>' + pris + ' page' + (pris > 1 ? 's' : '') + ' de produits sur ' + inner + '</b></div>'
      + '<div class="mep-jauge">' + parts.map(x => '<i style="width:' + (100 * x[1].length / inner) + '%;background:' + mepCouleur(S, x[0]) + '"></i>').join('') + (autres ? '<i style="width:' + (100 * autres / inner) + '%;background:#9a948c"></i>' : '') + '</div>'
      + '<div class="mep-lg">' + parts.map((x, k) => '<button data-cm="mep" data-v="' + k + '">' + mepPt(mepCouleur(S, x[0])) + '<span>' + esc(nomCat(S, x[0])) + '</span><span class="n">' + (finale ? plage(x[1]) : x[1].length ? x[1].length + ' page' + (x[1].length > 1 ? 's' : '') + (k < S.mep ? ' ✓' : '') : 'pas de page') + '</span></button>').join('')
      + (autres ? '<div>' + mepPt('#9a948c') + '<span>Présentations, Notre sélection</span><span class="n">' + autres + ' page' + (autres > 1 ? 's' : '') + '</span></div>' : '')
      + (inner - pris > 0 ? '<div>' + mepPt('#D9D2C7') + '<span>Pages libres</span><span class="n">' + (inner - pris) + '</span></div>' : '') + '</div>'
      + '<div class="mep-note">' + (finale ? 'L’ordre des pages est celui de l’impression : la page 3 suit la page 2 de la marque.' : 'Une étape par catégorie choisie, dans l’ordre de l’étape 2.') + ' Les 4 pages de la marque (couverture, page 2, avant-dernière, dernière) ne bougent pas.</div></aside>';
  }
  /**
   * Les produits de la catégorie, montrés ou non (17/11/2026, Sam : « un tableau avec la liste des produits et un mini
   * toggle on/off, show or not ») : tous ceux que ma boutique vend dans la catégorie ; l'interrupteur coche ou décoche le
   * produit (le choix de l'étape 2), et les pages de la catégorie se refont. Le dernier montré reste : une catégorie se
   * retire à l'étape 2.
   */
  function mepProduits(S, c) {
    const liste = produits(S).filter(p => p.categoryId != null && +p.categoryId === +c);
    const on = liste.filter(p => S.choix && S.choix[p.id]).length;
    return '<section class="mep-prods"><h3>Produits · ' + esc(nomCat(S, c)) + '</h3><div class="mep-cpt">' + on + ' montré' + (on > 1 ? 's' : '') + ' sur ' + liste.length + '</div>'
      + '<table class="mep-tab"><tbody>' + liste.map(p => {
        const o = !!(S.choix && S.choix[p.id]), dernier = o && on === 1;
        return '<tr class="' + (o ? 'on' : 'off') + '"><td class="ph">' + (p.imageUrl ? '<img src="' + esc(p.imageUrl) + '" alt="" loading="lazy">' : '') + '</td><td class="nm">' + esc(p.name) + '</td><td class="px">' + esc(prix(p.priceCents, 'fr')) + '</td>'
          + '<td><button class="mep-tg' + (o ? ' on' : '') + '" role="switch" aria-checked="' + o + '" aria-label="' + esc((o ? 'Cacher ' : 'Montrer ') + p.name) + '" data-cm="mepProdOn" data-c="' + c + '" data-v="' + p.id + '"'
          + (dernier || S.occupe ? ' disabled' : '') + ' title="' + (dernier ? 'Le dernier produit montré : pour retirer la catégorie, décochez-la à l’étape 2' : o ? 'Ne plus le montrer dans le catalogue' : 'Le montrer dans le catalogue') + '"><i></i></button></td></tr>';
      }).join('') + '</tbody></table></section>';
  }
  /** Montrer ou cacher un produit : son choix change, et les pages de sa catégorie se refont (sa pleine page oublie un produit caché). */
  function mepMontrer(S, c, id) {
    if (!S.choix) { return; }
    S.choix[id] = !S.choix[id]; S.choixModifie = true;
    if (mepCompte(S, c) > 0) { mepPoser(S, c, !S.choix[id] && mepEtat(S, c).produit === id ? { produit: null } : {}); return; }
    S.modifie = true; rendre(S);
  }
  function rendreMep(S) {
    const cats = mepCats(S);
    if (S.mep == null || S.mep > cats.length) { S.mep = 0; }
    const portes = remplir(S), finale = S.mep >= cats.length;
    return '<div class="mep">' + mepSous(S, cats) + '<div class="mep-corps"><section class="mep-pan">' + (finale ? mepFinale(S, cats, portes) : mepCategorie(S, cats, S.mep, portes))
      + '</section><div class="mep-col">' + mepCote(S, cats, portes, finale) + (finale ? '' : mepProduits(S, cats[S.mep])) + '</div></div></div>' + rendreZoom(S);
  }
  function rendreE3(S) {
    const prods = produits(S), ids = new Set(choisis(S));
    const parCat = {}; prods.forEach(p => { if (ids.has(+p.id)) { const k = p.categoryId == null ? 'null' : String(p.categoryId); parCat[k] = (parCat[k] || 0) + 1; } });
    const cats = catsChoisies(S);
    const portes = remplir(S);
    const gs = gammesCatalogue(S);
    let h = '<div class="cm-corps"><section class="cm-pan"><div class="h"><button class="cm-btn" data-cm="mepDetail">‹ L’assistant</button><span class="k" style="margin-left:10px">Réglages page par page</span><span class="sp"></span><button class="cm-btn" data-cm="repartir"' + (S.occupe ? ' disabled' : '') + '>↺ Répartir automatiquement</button></div>';
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
          + (p.layout === 'pleine' ? choixProduit(S, p, i, portes.ids ? portes.ids[i] : [])
            : '<div class="cm-cpt" style="margin-top:4px">' + porte + ' produit' + (porte > 1 ? 's' : '') + ' sur ' + cap + ' places' + (p.category == null ? ' · les produits qui restent' : ' · ' + n + ' choisi' + (n > 1 ? 's' : '') + ' dans cette catégorie') + '</div>');
        if (remplissable(p.layout, porte) || p.filler) {
          corps += '<div class="cm-place"><span class="l" title="Quand la page n’est pas pleine, une photo de la médiathèque ou l’illustration de la gamme prend la place libre">Place libre</span><div class="cm-seg">'
            + [['', 'Rien'], ['photo', 'Photo'], ['illustration', 'Illustration']].map(o => '<button data-cm="place" data-i="' + i + '" data-v="' + o[0] + '" class="' + ((p.filler || '') === o[0] ? 'on' : '') + '">' + o[1] + '</button>').join('') + '</div>'
            + (p.filler === 'photo' && p.fillerMediaId ? '<span class="cm-vign">' + (S.mediaUrls && S.mediaUrls[p.fillerMediaId] ? '<img src="' + esc(S.mediaUrls[p.fillerMediaId]) + '" alt="">' : 'photo n° ' + p.fillerMediaId) + '</span>' : '')
            + (!remplissable(p.layout, porte) ? '<span class="cm-cpt">page pleine : rien n’est imprimé</span>' : '') + '</div>';
          if (S.choixPhoto === i) { corps += rendreMedias(S, i); }
        }
      }
      h += '<div class="cm-pg cm-pg--ap' + (pres ? ' pres' : '') + (S.glisse === i ? ' glisse' : '') + '" data-pg="' + i + '"><span class="no"><span class="cm-poignee" draggable="true" data-pgdrag="' + i + '" title="Glisser pour déplacer la page" aria-label="Déplacer la page ' + (i + 3) + '">⠿</span>' + (i + 3) + '</span>' + apercusRangee(S, i, portes.ids || [])
        + '<div class="cm-pg-c">' + corps + '</div>'
        // Une seule rangée de choix (17/11/2026, Sam : « pas deux lignes de choix ; des propositions de comment les produits
        // se présentent sur la page, 1 - 2 - 3 par largeur ») : chaque mise en page en dessin, avec ce qu'elle tient. Le
        // choix de l'en-tête (titre + pictogramme, bandeau illustré, grande illustration) n'est plus proposé : la page garde le sien.
        + '<div class="ctl"><div class="cm-gab cm-lay" role="group" aria-label="Comment les produits se présentent sur la page ' + (i + 3) + '">' + Object.keys(LAYOUTS).map(l => '<button data-cm="layout" data-i="' + i + '" data-v="' + l + '" class="' + (p.layout === l ? 'on' : '') + '"' + (estPres(l) && !gs.length ? ' disabled' : '') + ' title="' + esc(DISPOS[l][0] + ' — ' + DISPOS[l][1]) + '">' + mini(l, p.template) + '<span>' + esc(DISPOS[l][0]) + '</span><small>' + esc(DISPOS[l][1]) + '</small></button>').join('') + '</div>'
        + (pres ? '' : '<div class="cm-script"><span title="Le nom de la catégorie, sous celui de la gamme, en lettres manuscrites (Playwrite, la police manuscrite de la marque)">Catégorie manuscrite</span><div class="cm-seg">'
          + [['0', 'Non'], ['1', 'Oui']].map(o => '<button data-cm="script" data-i="' + i + '" data-v="' + o[0] + '" class="' + ((!!p.script) === (o[0] === '1') ? 'on' : '') + '">' + o[1] + '</button>').join('') + '</div></div>')
        + '<div class="cm-gab" role="group" aria-label="Gabarit de la page ' + (i + 3) + '">' + ['A', 'B', 'C'].map(t => '<button data-cm="gabarit" data-i="' + i + '" data-v="' + t + '" class="' + ((p.template || 'A') === t ? 'on' : '') + '" title="' + t + ' · ' + GABARITS[fam][t][0] + ' — ' + GABARITS[fam][t][1] + '">' + vignette(fam, t) + '<span>' + t + ' · ' + GABARITS[fam][t][0] + '</span></button>').join('') + '</div></div>'
        + '<div class="mv"><button class="cm-btn" data-cm="monter" data-v="' + i + '"' + (i === 0 ? ' disabled' : '') + ' aria-label="Monter">↑</button><button class="cm-btn" data-cm="descendre" data-v="' + i + '"' + (i === S.pages.length - 1 ? ' disabled' : '') + ' aria-label="Descendre">↓</button></div></div>';
    });
    h += marque(nb - 1, '<span class="mini texte" aria-hidden="true"></span>', 'Avant-dernière page', 'Charte de la marque · nos services');
    h += marque(nb, '<span class="mini" aria-hidden="true" style="background:#F6F1E9"></span>', 'Dernière page', 'Commande en ligne, QR de mon webshop, adresse et horaires de ma boutique');
    h += '</div>';
    const sansPage = cats.filter(c => c != null && !(S.pages || []).some(p => !estPres(p.layout) && p.category != null && +p.category === +c));
    if (sansPage.length) { h += '<div class="cm-msg inf" style="margin-top:12px">' + sansPage.map(c => '« ' + esc(nomCat(S, c)) + ' »').join(', ') + (sansPage.length > 1 ? ' n’ont' : ' n’a') + ' pas de page à elle' + (sansPage.length > 1 ? 's' : '') + ' : ' + (S.nbPages >= 16 ? 'plus de place (16 pages) — ' : '') + 'ses produits vont en « Notre sélection » s’il y en a une. Choisissez sa catégorie sur une page' + (S.nbPages < 16 ? ' ou ajoutez 2 pages' : '') + '.</div>'; }
    if (portes.sansPlace) { h += '<div class="cm-msg inf" style="margin-top:12px">' + portes.sansPlace + ' produit' + (portes.sansPlace > 1 ? 's' : '') + ' choisi' + (portes.sansPlace > 1 ? 's' : '') + ' sans place dans les pages. ' + (S.nbPages < 16 ? 'Ajoutez 2 pages, choisissez' : 'Choisissez') + ' une mise en page plus dense, une page « Notre sélection », ou « Répartir automatiquement ».</div>'; }
    h += '<div class="cm-pied"><button class="cm-btn" data-cm="etape" data-v="2">‹ Gammes</button><span class="sp"></span>' + (S.modifie ? '<span class="cm-cpt">modifications non enregistrées</span>' : '')
      + '<button class="cm-btn pri" data-cm="etape" data-v="4"' + (S.occupe ? ' disabled' : '') + '>' + (S.occupe ? 'Enregistrement…' : 'Validation ›') + '</button></div>';
    return h + '</section>' + cote(S, resume(S)) + '</div>' + rendreZoom(S);
  }
  /** Pleine page (17/11/2026, Sam : « un seul produit et sa promotion ») : le produit qu'elle montre, parmi ceux choisis dans
   * sa catégorie ; « le premier de la catégorie » sinon. Sa promotion (la première offre cochée qui le contient) est dite. */
  function choixProduit(S, p, i, ids) {
    const ch = new Set(choisis(S));
    const liste = produits(S).filter(x => ch.has(+x.id) && (p.category == null || +x.categoryId === +p.category));
    const montre = ids && ids.length ? produits(S).find(x => +x.id === +ids[0]) : null;
    const o = montre ? offreDe(S, montre.id) : null;
    return '<div class="cm-lig cm-pleine"><select class="cm-sel" data-cm="pprod" data-i="' + i + '" aria-label="Produit de la page ' + (i + 3) + '"><option value=""' + (p.product == null ? ' selected' : '') + '>Le premier de la catégorie</option>'
      + liste.map(x => '<option value="' + x.id + '"' + (+p.product === +x.id ? ' selected' : '') + '>' + esc(x.name) + '</option>').join('') + '</select></div>'
      + '<div class="cm-cpt" style="margin-top:4px">' + (montre ? '« ' + esc(montre.name) + ' » seul sur la page' + (o ? ' · avec sa promotion « ' + esc(o.name) + ' »' : ' · pas de promotion cochée pour lui') : 'aucun produit choisi dans cette catégorie') + '</div>';
  }
  /** La promotion d'un produit : la première offre cochée qui le contient (la règle du Marketing pour la pleine page). */
  const offreDe = (S, id) => offres(S).find(o => o.type !== 'scheduledDiscount' && (o.productIds || []).some(x => +x === +id) && offreOn(S, o)) || null;
  /* Plus d'import de photo (17/11/2026, Sam : « retirer l'importation de photos, seulement la médiathèque ») : la vignette
   * d'une offre et la place libre d'une page se choisissent dans la médiathèque du réseau et de ma boutique, rien d'autre. */
  /** Le choix de l'image d'une vignette d'offre : les images de la médiathèque du réseau et de ma boutique. */
  function rendreMediasOffre(S, id) {
    let h = '<div class="cm-medias"><div class="cm-lig"><input type="search" class="cm-rech" data-cm="mediasQ" placeholder="Chercher une image" value="' + esc(S.mediasQ) + '">'
      + '<button class="cm-btn" data-cm="photoChercher">Chercher</button>' + '<span class="sp"></span><button class="cm-btn" data-cm="offreImg" data-v="' + esc(id) + '">Fermer</button></div>';
    if (S.mediasErr) { return h + bandeauErreur(S.mediasErr, 'La médiathèque n’a pas pu être lue') + '</div>'; }
    if (!S.medias) { return h + '<div class="cm-vide">Lecture de la médiathèque…</div></div>'; }
    if (!S.medias.length) { return h + '<div class="cm-vide">Aucune image dans la médiathèque du réseau ni de ma boutique.</div></div>'; }
    return h + '<div class="cm-grille">' + S.medias.map(m => '<button data-cm="offrePhoto" data-o="' + esc(id) + '" data-v="' + m.id + '" class="' + (+(S.offresImg || {})[id] === +m.id ? 'on' : '') + '" title="' + esc(m.name) + '"><img src="' + esc(m.thumbnailUrl || m.url) + '" alt="" loading="lazy"><span>' + esc(m.name) + '</span></button>').join('') + '</div></div>';
  }
  /** Le choix de la photo de la place libre : les images de la médiathèque du réseau et de ma boutique. */
  function rendreMedias(S, i) {
    let h = '<div class="cm-medias"><div class="cm-lig"><input type="search" class="cm-rech" data-cm="mediasQ" placeholder="Chercher une photo" value="' + esc(S.mediasQ) + '">'
      + '<button class="cm-btn" data-cm="photoChercher">Chercher</button>' + '<span class="sp"></span><button class="cm-btn" data-cm="photoFermer">Fermer</button></div>';
    if (S.mediasErr) { return h + bandeauErreur(S.mediasErr, 'La médiathèque n’a pas pu être lue') + '</div>'; }
    if (!S.medias) { return h + '<div class="cm-vide">Lecture de la médiathèque…</div></div>'; }
    if (!S.medias.length) { return h + '<div class="cm-vide">Aucune photo dans la médiathèque du réseau ni de ma boutique.</div></div>'; }
    return h + '<div class="cm-grille">' + S.medias.map(m => '<button data-cm="photo" data-i="' + i + '" data-v="' + m.id + '" class="' + (+S.pages[i].fillerMediaId === +m.id ? 'on' : '') + '" title="' + esc(m.name) + '"><img src="' + esc(m.thumbnailUrl || m.url) + '" alt="" loading="lazy"><span>' + esc(m.name) + '</span></button>').join('') + '</div></div>';
  }

  /* --- Étape 1 · Catalogue : reçu de la marque, le mien, ou nouveau (maquette B4) ------------------------------- */
  function rendreCatalogues(S) {
    let h = '';
    if (S.listeErr) { h += bandeauErreur(S.listeErr, 'Mes catalogues n’ont pas pu être lus'); }
    if (S.recusErr) { h += bandeauErreur(S.recusErr, 'Les catalogues de la marque n’ont pas pu être lus'); }
    if (!S.liste && !S.listeErr) { return h + '<div class="db-card"><div class="cm-vide">Lecture de mes catalogues…</div></div>'; }
    (S.recus || []).filter(c => c.reception && c.reception.status === 'nouveau').forEach(c => { h += carteRecu(S, c); });
    const lignes = [];
    (S.recus || []).filter(c => c.reception && c.reception.status !== 'adapte').forEach(c => lignes.push({ c, marque: true }));
    (S.liste || []).forEach(c => lignes.push({ c, marque: false }));
    if (!lignes.length) {
      return h + '<div class="db-card"><div class="cm-vide">Aucun catalogue pour l’instant.<br><br><button class="cm-btn pri" data-cm="nouveau">＋ Créer mon premier catalogue</button></div></div>';
    }
    h += '<div class="db-card" style="overflow:hidden"><table class="cm-tab"><thead><tr><th>Catalogue</th><th style="width:130px">De</th><th style="width:120px">État</th><th class="s-col" style="width:70px">Pages</th><th style="width:280px">Ensuite</th><th style="width:110px"></th></tr></thead><tbody>';
    lignes.forEach(({ c, marque }) => {
      const ng = c.source && c.source.kind === 'gamme' ? gammesDe(S, c) : null;
      const src = (ng ? (/^gamme/i.test(ng[0]) ? '' : ng.length > 1 ? 'Gammes ' : 'Gamme ') + ng.join(' + ') : 'Mes produits') + ' · ' + c.format + ' · ' + (c.langs || ['fr']).join(' + ').toUpperCase();
      const recu = marque && c.reception;
      const imp = c.print && c.print.quantity ? 'Imprimé · ' + c.print.quantity + ' ex.' : null;
      const etat = recu ? (recu.status === 'nouveau' ? ['vif', 'À regarder'] : imp ? ['imprime', imp] : ['pret', 'Pris tel quel']) : [c.status, c.status === 'imprime' && c.printQty ? 'Imprimé · ' + c.printQty + ' ex.' : (STATUTS[c.status] || c.status)];
      const ensuite = recu ? (recu.status === 'nouveau' ? ['4', 'Le feuilleter ›'] : ['5', imp ? 'Diffuser ›' : 'Imprimer ›'])
        : c.status === 'brouillon' ? ['2', 'Continuer ›'] : c.status === 'pret' ? ['4', 'Vérifier et imprimer ›'] : ['5', 'Diffuser ›'];
      h += '<tr class="cl" data-cm="' + (marque ? 'ouvrirM' : 'ouvrir') + '" data-v="' + c.id + '" data-e="' + ensuite[0] + '"><td><div class="t">' + esc(c.title) + '</div><div class="s">' + esc(src) + '</div></td>'
        + '<td>' + (marque ? 'La marque' : 'Ma boutique') + '</td>'
        + '<td><span class="cm-et ' + esc(etat[0]) + '">' + esc(etat[1]) + '</span></td>'
        + '<td class="n s-col">' + (c.pageCount || 0) + '</td>'
        + '<td><button class="cm-lien" data-cm="' + (marque ? 'ouvrirM' : 'ouvrir') + '" data-v="' + c.id + '" data-e="' + ensuite[0] + '">' + esc(ensuite[1]) + '</button></td>'
        + '<td class="act">' + (marque ? '' : '<button class="cm-btn dis" data-cm="suppr" data-v="' + c.id + '" aria-label="Supprimer">Supprimer</button>') + '</td></tr>';
    });
    return h + '</tbody></table></div>';
  }
  /** « Nouveau · de la marque » : la proposition, ses deux pages, « Le prendre tel quel » ou « L'adapter ». */
  function carteRecu(S, c) {
    const doc = S.doc['r' + c.id];
    const vign = doc ? [0, Math.min(2, doc.pages.length - 1)].map(i => feuille(doc, i, 128, true)).join('') : '<div class="cm-vide" style="padding:20px">Lecture…</div>';
    const n = c.pageCount || 0;
    return '<section class="cm-recu"><div class="cm-recu__pages">' + vign + '</div><div class="cm-recu__txt">'
      + '<div class="cm-lig"><span class="cm-tag vif">Nouveau · de la marque</span><span class="cm-cpt">reçu le ' + esc(date(c.reception.receivedAt)) + '</span></div>'
      + '<h3>La marque a préparé votre catalogue « ' + esc(c.title) + ' »</h3>'
      + '<p>Déjà à vos prix, avec votre adresse, vos horaires et votre QR. Vous le prenez tel quel ou vous l’adaptez.</p>'
      + '<div class="cm-recu__pas">'
      + '<button class="cm-pas" data-cm="ouvrirM" data-v="' + c.id + '" data-e="4"><span class="no">1</span><span><b>Le feuilleter</b><i>' + n + ' pages · ' + esc(c.format) + ' · ' + esc((c.langs || ['fr']).join(' + ').toUpperCase()) + '</i></span></button>'
      + '<div class="cm-pas"><span class="no">2</span><span><b>Retirer ce que je ne fais pas</b><i>produits pas à mon webshop : déjà retirés</i></span></div>'
      + '<div class="cm-pas"><span class="no">3</span><span><b>Vérifier, imprimer, diffuser</b><i>les mêmes étapes que mes catalogues</i></span></div>'
      + '</div></div><div class="cm-recu__act"><button class="cm-btn pri" data-cm="prendre" data-v="' + c.id + '"' + (S.occupe ? ' disabled' : '') + '>Le prendre tel quel ›</button>'
      + '<button class="cm-btn" data-cm="adapter" data-v="' + c.id + '"' + (S.occupe ? ' disabled' : '') + '>L’adapter à ma boutique</button>'
      + '<p>« L’adapter » en fait une copie à moi : la marque ne la change plus.</p></div></section>';
  }
  /** Étapes 2 et 3 d'un catalogue de la marque pris tel quel : verrouillées. */
  function rendreVerrou(S) {
    return '<section class="cm-pan cm-verrou"><div class="h"><span class="k">' + (S.etape === 2 ? 'Gammes et produits' : 'Présentation') + '</span></div>'
      + '<p>' + CADENAS.replace('<svg', '<svg style="width:12px;height:12px;vertical-align:-1px"') + ' Ce catalogue est celui de la marque, pris tel quel : ses gammes et sa mise en page sont les siennes, à mes prix, avec mon adresse et mon QR. Les produits que ma boutique ne vend pas n’y sont pas.</p>'
      + '<p>Pour changer les produits ou la présentation, je l’adapte : il devient une copie à moi, que la marque ne modifie plus.</p>'
      + '<div class="cm-pied"><button class="cm-btn" data-cm="adapter" data-v="' + S.cat.id + '"' + (S.occupe ? ' disabled' : '') + '>L’adapter à ma boutique</button><span class="sp"></span><button class="cm-btn pri" data-cm="etape" data-v="4">Validation ›</button></div></section>';
  }

  /* --- Étape 4 · Validation : ce qui s'imprimera (maquette B1) --------------------------------------------------- */
  const GESTES = {
    'ouvrir-page': p => 'Ouvrir la page ' + p + ' ›', 'moins-de-pages': () => null, 'imprimer-ainsi': () => 'Imprimer ainsi', 'c-est-juste': () => '✓ C’est juste',
    modifier: () => 'Modifier', 'dates-vues': () => '✓ Dates vues', 'retirer-promo': () => 'La retirer', 'regle-par-la-marque': () => null,
  };
  function rendreValidation(S) {
    const k = S.cat.owner + S.cat.id + '|' + S.lang, doc = S.doc[k], v = verifDe(S), err = S.verifErr[k] || S.docErr[k];
    let h = '<div class="cm-corps cm-corps--valid"><section class="cm-pan"><div class="h"><span class="k">Je vérifie avant d’imprimer</span><span class="sp"></span>'
      + '<div class="cm-seg">' + [['fr', 'FR'], ['nl', 'NL']].map(l => '<button data-cm="lang" data-v="' + l[0] + '" class="' + (S.lang === l[0] ? 'on' : '') + '">' + l[1] + '</button>').join('') + '</div>'
      + (v ? '<span class="cm-cpt">' + v.seen + ' sur ' + v.total + ' vus</span><span class="cm-jauge"><i style="width:' + Math.round(100 * v.seen / Math.max(1, v.total)) + '%"></i></span>' : '') + '</div>';
    if (err) { h += bandeauErreur(err, 'La vérification n’a pas pu être faite') + '<button class="cm-btn" data-cm="reessayer">Réessayer</button>'; }
    else if (!v) { h += '<div class="cm-vide">Le Marketing compose le catalogue et le vérifie…</div>'; }
    else {
      h += '<ul class="cm-verifs">' + v.items.map(it => {
        const icone = it.level === 'ok' ? '✓' : it.level === 'question' ? '?' : '!';
        const gestes = (it.actions || []).map(a => {
          if (a === 'moins-de-pages') { return S.nbPages > 8 ? '<button class="cm-btn" data-cm="geste" data-v="' + a + '" data-code="' + esc(it.code) + '">Passer à ' + (S.nbPages - 2) + ' pages</button>' : ''; }
          if (a === 'regle-par-la-marque') { return '<span class="cm-tag">Réglé par la marque</span>'; }
          const l = (GESTES[a] || (() => a))(it.page);
          const lien = a === 'imprimer-ainsi' || a === 'modifier' || a === 'retirer-promo';
          return '<button class="' + (lien ? 'cm-lien' : 'cm-btn') + '" data-cm="geste" data-v="' + a + '" data-code="' + esc(it.code) + '" data-p="' + (it.page || '') + '">' + esc(l) + '</button>';
        }).join('');
        return '<li class="cm-verif ' + esc(it.level) + (it.seen ? ' vu' : '') + '"><span class="ic">' + icone + '</span><div class="tx"><b>' + esc(it.title) + '</b><p>' + esc(it.text) + '</p></div>'
          + '<div class="ge">' + (it.level === 'ok' || (it.seen && it.level !== 'alerte') ? '<span class="cm-tag ok">Vu</span>' : gestes || '<button class="cm-btn" data-cm="vu" data-v="' + esc(it.code) + '">Vu</button>') + '</div></li>';
      }).join('') + '</ul>';
    }
    h += '<div class="cm-pied">' + (estRecu(S) ? '<button class="cm-btn" data-cm="liste">‹ Catalogue</button>' : '<button class="cm-btn" data-cm="etape" data-v="3">‹ Présentation</button>') + '<span class="sp"></span>'
      + '<span class="cm-cpt">Imprimer reste possible avec des points ouverts : ils sont rappelés au moment du PDF.</span>'
      + '<button class="cm-btn pri" data-cm="valider"' + (S.occupe ? ' disabled' : '') + '>Valider mon catalogue ›</button></div></section>';
    // Les pages, les points marqués ; un clic ouvre la page en grand.
    h += '<aside class="cm-cote cm-mes-pages"><div class="k">Mes ' + (doc ? doc.pages.length : '') + ' pages · les points à voir</div>';
    if (doc) {
      const marques = {}; ((v && v.pages) || []).forEach(p => { marques[p.no] = p.mark; });
      if (S.grand != null && doc.pages[S.grand]) {
        h += '<div class="cm-grand">' + feuille(doc, S.grand, 300) + '<button class="cm-btn" data-cm="grand" data-v="' + S.grand + '">Fermer la page</button></div>';
      }
      h += '<div class="cm-vign">' + doc.pages.map((p, i) => '<button class="cm-vg' + (S.grand === i ? ' on' : '') + '" data-cm="grand" data-v="' + i + '">' + feuille(doc, i, 76, true)
        + (marques[i + 1] ? '<span class="mk ' + esc(marques[i + 1]) + '">' + (marques[i + 1] === 'question' ? '?' : '!') + '</span>' : '') + '<span class="no">' + (i + 1) + '</span></button>').join('') + '</div>';
      h += '<div class="note">Un clic sur une page l’ouvre en grand. Les pages de la marque sont celles de sa charte publiée' + (doc.charter && doc.charter.version ? ' (version ' + doc.charter.version + ')' : '') + '.</div>';
    } else { h += '<div class="cm-vide">Composition…</div>'; }
    return h + '</aside></div>';
  }

  /* --- Étape 5 · Export PDF : imprimer (B2), puis diffuser (B3) --------------------------------------------------- */
  function rendreExport(S) {
    const c = S.cat, k = c.owner + c.id + '|' + S.lang, doc = S.doc[k], v = verifDe(S);
    const ouverts = v ? v.items.filter(it => !it.seen && it.level !== 'ok') : [];
    const livret = S.imp.mode === 'livret';
    const langs = S.imp.langue === 'fr+nl' ? ['FR', 'NL'] : [String(S.imp.langue || 'fr').toUpperCase()];
    const imp = c.print && c.print.quantity ? c.print : (c.status === 'imprime' && c.printQty ? { quantity: c.printQty } : null);
    let h = rendreFeuilleter(S, doc, k) + '<div class="cm-corps"><div class="cm-col">';
    h += '<section class="cm-pan"><div class="h"><span class="k">Comment je l’imprime ?</span></div><div class="cm-modes">'
      + '<button class="cm-mode' + (!livret ? ' on' : '') + '" data-cm="impmode" data-v="imprimeur"><span class="il il-imp" aria-hidden="true"><i></i><i></i></span><b>Chez un imprimeur</b><ul><li>PDF imprimeur : fond perdu 3 mm, traits de coupe</li><li>pages dans l’ordre, couleurs pour l’impression</li><li>à envoyer à mon imprimeur</li></ul></button>'
      + '<button class="cm-mode' + (livret ? ' on' : '') + '" data-cm="impmode" data-v="livret"><span class="il il-livret" aria-hidden="true"><i></i><i></i></span><b>Sur mon imprimante</b><ul><li>feuilles A4 recto verso, 2 pages par face</li><li>plier au milieu : un livret A5</li><li>pour une petite quantité</li></ul></button>'
      + '</div>' + (livret && c.format !== 'A5' ? '<div class="cm-msg inf" style="margin-top:10px">Le livret plié se fait avec un catalogue A5 : en A4, le PDF garde une page par feuille.</div>' : '') + '</section>';
    h += '<section class="cm-pan"><div class="h"><span class="k">Mon impression</span></div><div class="cm-imp3">'
      + '<div><span class="lb">Format</span><div class="cm-seg">' + ['A5', 'A4'].map(f => '<button data-cm="impfmt" data-v="' + f + '" class="' + (c.format === f ? 'on' : '') + '"' + (estRecu(S) && c.format !== f ? ' disabled title="Le format du catalogue de la marque"' : '') + '>' + f + '</button>').join('') + '</div></div>'
      + '<div><span class="lb">Langue</span><div class="cm-seg">' + [['fr', 'FR'], ['nl', 'NL'], ['fr+nl', 'FR + NL']].map(l => '<button data-cm="implang" data-v="' + l[0] + '" class="' + (S.imp.langue === l[0] ? 'on' : '') + '">' + l[1] + '</button>').join('') + '</div></div>'
      + '<div><span class="lb">Quantité</span><div class="cm-lig"><input type="text" inputmode="numeric" class="cm-qte" data-cm="qte" value="' + esc(S.imp.qte) + '"><span>exemplaires</span></div></div></div>'
      + '<p class="cm-cpt" style="margin:10px 0 0">FR + NL : deux fichiers, un par langue. La quantité est notée sur le catalogue (« Imprimé · ' + esc(S.imp.qte || 'N') + ' ex. ») pour savoir combien il en reste à distribuer.</p></section>';
    h += '</div><aside class="cm-cote cm-fichier"><div class="k">Mon fichier</div><dl>'
      + '<dt>Fichier</dt><dd>' + esc('Catalogue ' + c.title + ' — ' + (S.nom || '') + ' — ' + c.format + ' — ' + langs.join(' + ')) + '.pdf</dd>'
      + '<dt>Pages</dt><dd>' + (c.pageCount || '') + ' pages ' + esc(c.format) + ', dont 4 de la marque</dd>'
      + '<dt>Pour</dt><dd>' + (livret ? 'mon imprimante · livret A4 recto verso, plié' : 'imprimeur · fond perdu 3 mm, traits de coupe') + '</dd>'
      + '<dt>Prix</dt><dd>ceux du webshop de ma boutique, lus maintenant</dd></dl>'
      + (ouverts.length ? '<div class="cm-msg warn">' + ouverts.length + ' point' + (ouverts.length > 1 ? 's' : '') + ' resté' + (ouverts.length > 1 ? 's' : '') + ' ouvert' + (ouverts.length > 1 ? 's' : '') + ' : ' + Array.from(new Set(ouverts.map(it => it.title.toLowerCase()))).slice(0, 3).map(esc).join(' · ') + '. <button class="cm-lien" data-cm="etape" data-v="4">Revenir à la validation</button></div>' : '')
      + '<button class="cm-btn pri cm-large" data-cm="pdf" data-v="imprimeur"' + (S.occupe ? ' disabled' : '') + '>' + (livret ? 'Télécharger le livret à imprimer' : 'Télécharger le PDF imprimeur') + '</button>'
      + '<div class="cm-lig cm-marq"><span>Quand c’est imprimé :</span><button class="cm-btn" data-cm="imprime"' + (S.occupe ? ' disabled' : '') + '>Marquer imprimé · ' + esc(S.imp.qte || '0') + ' ex.</button></div>'
      + (imp ? '<div class="cm-cpt">Déjà noté : imprimé · ' + imp.quantity + ' ex.' + (imp.printedAt ? ' le ' + esc(date(imp.printedAt)) : '') + '</div>' : '')
      + '</aside></div>';
    h += rendreDiffuser(S, doc);
    return h;
  }
  /* --- Feuilleter (17/11/2026, Sam : « un catalogue à feuilleter en simulation dans l'export en PDF, pour être sûr que
   * tout est bien à sa place ») : le document du Marketing, page par page comme imprimé et plié — la couverture seule à
   * droite, puis les doubles pages (2-3, 4-5…), la dernière seule à gauche. La page tourne (3D) ; ‹ › , les flèches du
   * clavier, un clic sur la page de gauche ou de droite ; « Agrandir » l'ouvre en grand. ---------------------------- */
  /** Les doubles pages d'un catalogue de n pages : [gauche, droite], null = rien de ce côté. */
  function planches(n) {
    const out = [[null, 0]];
    for (let i = 1; i < n - 1; i += 2) { out.push([i, i + 1 < n ? i + 1 : null]); }
    if (n > 1 && n % 2 === 0) { out.push([n - 1, null]); }
    return out;
  }
  const libellePlanche = (pl, n) => pl[0] == null ? 'Couverture' : pl[1] == null ? (pl[0] === n - 1 ? 'Dernière page' : 'Page ' + (pl[0] + 1)) : 'Pages ' + (pl[0] + 1) + '-' + (pl[1] + 1);
  /** La largeur d'une page du livre : à l'écran de l'étape, ou en grand (selon la fenêtre). */
  function largeurFb(S, doc) {
    const f = FORMAT_MM[doc.catalogue.format] || FORMAT_MM.A5, r = f.h / f.w;
    if (!S.fbGrand) { return 300; }
    return Math.max(260, Math.min(620, Math.floor((window.innerHeight - 190) / r), Math.floor((window.innerWidth - 140) / 2)));
  }
  function rendreFeuilleter(S, doc, k) {
    if (!doc) { return '<section class="cm-pan fb"><div class="h"><span class="k">Feuilleter mon catalogue</span></div><div class="cm-vide">' + (S.docErr[k] ? esc(S.docErr[k].message) : 'Composition du catalogue…') + '</div></section>'; }
    const n = doc.pages.length, pl = planches(n);
    S.fb = Math.max(0, Math.min(pl.length - 1, S.fb || 0));
    const W = largeurFb(S, doc), f = FORMAT_MM[doc.catalogue.format] || FORMAT_MM.A5, H = Math.round(W * f.h / f.w);
    const [g, d] = pl[S.fb], page = i => i == null ? '' : feuille(doc, i, W, true);
    const langues = S.langs.length > 1 ? '<div class="cm-seg">' + S.langs.map(l => '<button data-cm="lang" data-v="' + l + '" class="' + (S.lang === l ? 'on' : '') + '">' + l.toUpperCase() + '</button>').join('') + '</div>' : '';
    const livre = '<div class="fb-scene"><button class="fb-nav" data-cm="fb" data-v="-1"' + (S.fb === 0 ? ' disabled' : '') + ' aria-label="Page précédente">‹</button>'
      + '<div class="fb-livre' + (g == null ? ' sans-g' : '') + (d == null ? ' sans-d' : '') + '" tabindex="0" aria-label="Le catalogue, ' + esc(libellePlanche(pl[S.fb], n)) + ' — flèches gauche et droite pour tourner les pages" style="--w:' + W + 'px;--h:' + H + 'px">'
      + '<div class="fb-g" data-cm="fb" data-v="-1">' + page(g) + '</div><div class="fb-d" data-cm="fb" data-v="1">' + page(d) + '</div><i class="fb-pli" aria-hidden="true"></i></div>'
      + '<button class="fb-nav" data-cm="fb" data-v="1"' + (S.fb === pl.length - 1 ? ' disabled' : '') + ' aria-label="Page suivante">›</button></div>'
      + '<div class="fb-bas"><span class="fb-ou">' + esc(libellePlanche(pl[S.fb], n)) + ' · ' + n + ' pages</span><div class="fb-pls">' + pl.map((x, i) => '<button data-cm="fbAller" data-v="' + i + '" class="' + (i === S.fb ? 'on' : '') + '" title="' + esc(libellePlanche(x, n)) + '">' + (x[0] == null ? '1' : x[1] == null ? String(x[0] + 1) : (x[0] + 1) + '-' + (x[1] + 1)) + '</button>').join('') + '</div></div>';
    const tete = '<div class="h"><span class="k">Feuilleter mon catalogue</span><span class="cm-cpt" style="margin-left:10px">comme imprimé et plié : la couverture, puis les doubles pages</span><span class="sp"></span>' + langues
      + '<button class="cm-btn" data-cm="fbGrand">' + (S.fbGrand ? 'Fermer' : 'Agrandir') + '</button></div>';
    if (S.fbGrand) { return '<section class="cm-pan fb"><div class="h"><span class="k">Feuilleter mon catalogue</span><span class="sp"></span><button class="cm-btn" data-cm="fbGrand">Agrandir</button></div><div class="cm-cpt">Ouvert en grand.</div></section><div class="fb-plein" role="dialog" aria-label="Feuilleter mon catalogue"><section class="cm-pan fb">' + tete + livre + '</section></div>'; }
    return '<section class="cm-pan fb">' + tete + livre + '</section>';
  }
  /** Tourner une page : la feuille tourne autour du pli (recto : la page qu'on quitte ; verso : celle qui arrive), puis le livre se redessine. */
  function feuilleter(S, sens) {
    const k = S.cat ? S.cat.owner + S.cat.id + '|' + S.lang : null, doc = k ? S.doc[k] : null;
    if (!doc || S.fbAnim) { return; }
    const pl = planches(doc.pages.length), i = S.fb || 0, j = i + sens;
    if (j < 0 || j >= pl.length) { return; }
    const livre = S.el.querySelector('.fb-livre');
    const calme = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!livre || calme) { S.fb = j; rendre(S); return; }
    const W = largeurFb(S, doc), page = x => x == null ? '' : feuille(doc, x, W, true);
    const [g0, d0] = pl[i], [g1, d1] = pl[j];
    // Dessous, la page qui sera découverte ; la feuille qui tourne porte celle qu'on quitte et, au dos, celle qui arrive.
    if (sens > 0) { livre.querySelector('.fb-d').innerHTML = page(d1); livre.classList.toggle('sans-d', d1 == null); }
    else { livre.querySelector('.fb-g').innerHTML = page(g1); livre.classList.toggle('sans-g', g1 == null); }
    const leaf = document.createElement('div');
    leaf.className = 'fb-tourne ' + (sens > 0 ? 'vers-g' : 'vers-d');
    leaf.innerHTML = '<div class="fb-face recto">' + page(sens > 0 ? d0 : g0) + '</div><div class="fb-face verso">' + page(sens > 0 ? g1 : d1) + '</div>';
    livre.appendChild(leaf);
    S.fbAnim = true;
    const fin = () => { if (!S.fbAnim) { return; } S.fbAnim = false; S.fb = j; rendre(S); const l = S.el.querySelector('.fb-livre'); if (l) { l.focus({ preventScroll: true }); } };
    leaf.addEventListener('transitionend', fin, { once: true });
    setTimeout(fin, 1200);   // sans transitionend (onglet caché) : on n'attend pas
    requestAnimationFrame(() => requestAnimationFrame(() => leaf.classList.add('tourne')));
  }
  function rendreDiffuser(S, doc) {
    const l = S.lien;
    const cover = doc && doc.pages[0];
    const titreAffiche = S.affiche.titre || (cover && cover.kind === 'cover' ? (S.lang === 'nl' ? 'Onze catalogus · ' : 'Notre catalogue de ') + cover.title : S.cat.title);
    let h = '<div class="cm-diff">';
    h += '<section class="cm-pan"><h3>QR code du catalogue</h3><p class="cm-cpt">Scanné, il ouvre le catalogue en ligne, toujours la dernière version.</p>'
      + '<div class="cm-qrbox">' + (l ? qr(l.url, 46) : S.lienErr ? bandeauErreur(S.lienErr, 'Le lien n’a pas pu être créé') : '<div class="cm-vide">Création du lien…</div>') + '</div>'
      + (l ? '<div class="cm-cpt">Lien du catalogue en ligne</div><div class="cm-url">' + esc(l.url) + '</div>' : '')
      + '<div class="cm-lig"><button class="cm-btn" data-cm="qrPng"' + (l ? '' : ' disabled') + '>Télécharger le QR (PNG)</button><button class="cm-btn" data-cm="copier"' + (l ? '' : ' disabled') + '>Copier le lien</button>'
      + '<button class="cm-lien" data-cm="lienNouveau"' + (l && !S.occupe ? '' : ' disabled') + ' title="L’ancien QR ne mènera plus au catalogue">Nouveau lien</button></div></section>';
    h += '<section class="cm-pan"><h3>PDF à partager</h3><p class="cm-cpt">Un PDF pour WhatsApp, un e-mail ou Facebook (sans traits de coupe).</p>'
      + '<div class="cm-leger">' + (doc ? [0, 1, 2].filter(i => i < doc.pages.length).map(i => feuille(doc, i, 104, true)).join('') : '<div class="cm-vide">Composition…</div>') + '</div>'
      + '<div class="cm-cpt">' + (S.cat.pageCount || '') + ' pages · ' + S.lang.toUpperCase() + ' · prix de ma boutique</div>'
      + '<div class="cm-lig"><button class="cm-btn" data-cm="pdf" data-v="leger"' + (S.occupe ? ' disabled' : '') + '>Télécharger le PDF léger</button><button class="cm-btn" data-cm="partager"' + (l ? '' : ' disabled') + '>Partager…</button></div></section>';
    h += '<section class="cm-pan cm-affiche"><h3>Affiche en magasin</h3><p class="cm-cpt">À mettre en vitrine ou au comptoir : le client scanne et feuillette le catalogue.</p>'
      + '<div class="cm-aff"><div class="cm-aff__pg">' + affiche(S, doc, l, titreAffiche) + '</div><div class="cm-aff__opt">'
      + '<span class="lb">Format</span><div class="cm-seg">' + ['A4', 'A3'].map(t => '<button data-cm="afficheTaille" data-v="' + t + '" class="' + (S.affiche.taille === t ? 'on' : '') + '">' + t + '</button>').join('') + '</div>'
      + '<label class="lb" for="cm-aff-t">Titre</label><input id="cm-aff-t" type="text" class="cm-qte cm-afft" data-cm="afficheTitre" maxlength="80" placeholder="' + esc(titreAffiche) + '" value="' + esc(S.affiche.titre) + '">'
      + '<span class="lb">Illustration</span><div>celle de la couverture</div></div></div>'
      + '<button class="cm-btn pri" data-cm="pdf" data-v="affiche"' + (l && !S.occupe ? '' : ' disabled') + '>Télécharger l’affiche (PDF)</button></section>';
    h += '</div>';
    const recu = estRecu(S);
    const imp = S.cat.print && S.cat.print.quantity ? S.cat.print.quantity : S.cat.printQty;
    h += '<section class="cm-pan cm-reprendre"><div class="h"><span class="k">Reprendre mon catalogue</span><span class="sp"></span>' + (imp ? '<span class="cm-tag ok">Imprimé · ' + imp + ' ex.</span>' : '') + '</div><div class="cm-rep">'
      + (recu
        ? '<button data-cm="adapter" data-v="' + S.cat.id + '"><b>L’adapter à ma boutique ›</b><i>une copie à moi : produits et mise en page</i></button>'
        : '<button data-cm="etape" data-v="2"><b>Mes produits ›</b><i>étape 2 · ajouter, retirer, mes prix</i></button><button data-cm="etape" data-v="3"><b>Mise en page ›</b><i>étape 3 · pages, gabarits, photos</i></button>')
      + '<button data-cm="nouveau"><b>Nouveau catalogue ›</b><i>une autre gamme, une autre saison</i></button>'
      + '<button data-cm="etape" data-v="4"><b>Les prix ont changé ?</b><i>le prochain PDF reprend les prix du webshop · « Validation » les montre</i></button></div></section>';
    return h;
  }
  /** L'aperçu de l'affiche (le PDF est composé par la page d'impression du Marketing, `&affiche=`). */
  function affiche(S, doc, l, titre) {
    const p = doc && doc.pages[0];
    const img = p && p.kind === 'cover' ? (p.imageUrl ? '<img class="ph" src="' + esc(p.imageUrl) + '" alt="">' : p.drawingUrl ? '<img src="' + esc(p.drawingUrl) + '" alt="">' : '') : '';
    return '<div class="cm-aff__in"><img class="lg" src="' + esc(doc ? logo(doc) : '../assets/img/logo.png') + '" alt=""><div class="art">' + img + '</div><div class="ti">' + esc(titre) + '</div><div class="sc">'
      + (S.lang === 'nl' ? 'Scan om te bladeren' : 'Scannez pour le feuilleter') + '</div>' + (l ? qr(l.url, 15) : '') + '<div class="sh">L’Atelier by · ' + esc(S.nom || '') + '</div></div>';
  }

  /* --- les pages (rendu du Marketing, src/views/catalogue/CataloguePages.tsx) ----------------------------------- */
  function feuille(doc, i, largeurPx, nu) {
    const p = doc.pages[i];
    const f = FORMAT_MM[doc.catalogue.format] || FORMAT_MM.A5;
    const z = largeurPx / (f.w * PX_MM);
    const leg = p.kind === 'cover' ? 'Couverture' : p.kind === 'back' ? 'Dernière page' : p.kind === 'brand' ? 'Page ' + (i + 1) + ' · marque (' + (p.slot === 'page2' ? 'page 2' : 'avant-dernière') + ')'
      : p.kind === 'presentation' ? 'Page ' + (i + 1) + ' · ' + (LAYOUTS[p.layout] ? LAYOUTS[p.layout].label : '') + ' · gabarit ' + (p.template || 'A')
      : 'Page ' + (i + 1) + ' · ' + (LAYOUTS[p.layout] ? LAYOUTS[p.layout].label : '') + ' · ' + (ENTETES[p.header] ? ENTETES[p.header][0] : ENTETES.titre[0]).toLowerCase() + ' · gabarit ' + (p.template || 'A') + ((p.products || []).length ? '' : ' · vide') + (p.filler ? ' · ' + (p.filler.kind === 'photo' ? 'photo' : 'illustration') + ' à la place libre' : '');
    return '<div><div class="cm-feuille" style="width:' + largeurPx + 'px;height:' + Math.round(f.h * PX_MM * z) + 'px">'
      + (p.locked && !nu ? '<span class="cad cm-lock">' + CADENAS + ' Marque</span>' : '')
      + '<div class="pz" style="transform:scale(' + z + ');width:' + f.w + 'mm;height:' + f.h + 'mm"><div style="position:absolute;left:-' + BLEED + 'mm;top:-' + BLEED + 'mm">' + page(doc, i) + '</div></div></div>'
      + (nu ? '' : '<div class="cm-leg">' + esc(leg) + '</div>') + '</div>';
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
    const tpl = p.kind === 'products' || p.kind === 'presentation' ? ' cat-face--t' + esc(/^[ABC]$/.test(p.template || '') ? p.template : 'A') : p.kind === 'cover' ? ' cat-face--cover' + esc(/^[ABC]$/.test(p.template || '') ? p.template : 'A') : '';
    return '<div class="cat-face cat-face--' + esc(p.kind) + tpl + '" style="' + st + '" lang="' + esc(doc.version.lang) + '"><div class="cat-trim">' + corps + '</div></div>';
  }
  const css = (v, d) => /^#[0-9a-f]{3,8}$/i.test(String(v || '')) ? v : d;
  const logo = doc => doc.charter && doc.charter.logoUrl ? doc.charter.logoUrl : '../assets/img/logo.png';
  /** Le logo sur le papier (couverture B) : celui de la charte, sinon le logo officiel en couleur. */
  const logoSombre = doc => doc.charter && doc.charter.logoUrl ? doc.charter.logoUrl : '../assets/img/logo.png';
  /** La police et la couleur choisies pour un texte de la couverture (charte, Marketing v1.9 ; rendu de `coverStyle`,
   * CataloguePages.tsx) : l'attribut style, vide sans choix (celles du gabarit). */
  const POLICES_COUV = { vank: "'Vank','CmVank','GothamPrint',serif", gotham: "'GothamPrint','CmPrint',system-ui,sans-serif", playwrite: "'Playwrite','CmScript',cursive" };
  function styleCouv(font, color) {
    const f = POLICES_COUV[font], c = /^#[0-9A-Fa-f]{6}$/.test(String(color || '')) ? color : null;
    if (!f && !c) { return ''; }
    return ' style="' + (f ? 'font-family:' + f.replace(/"/g, '') + ';' : '') + (font === 'playwrite' ? 'text-transform:none;letter-spacing:0;line-height:1.5;--fs:.55;' : font === 'gotham' ? 'font-weight:500;letter-spacing:.02em;line-height:1.08;--fs:.6;' : '') + (c ? 'color:' + c + ';' : '') + '"';
  }
  /** La couverture dans son gabarit (charte publiée, maquette A1) : A Pleine couleur · B Illustration · C Photo. */
  function couverture(doc, p) {
    const st = styleCouv(p.titleFont, p.titleColor), ss = styleCouv(p.subtitleFont, p.subtitleColor);
    const dessin = p.imageUrl || p.noVisual ? null : (p.drawingUrl || null);
    const boutique = p.eyebrow ? '<div class="cat-cover__' + (p.template === 'B' ? 'shopB' : 'shop') + '">L’Atelier By · ' + esc(p.eyebrow) + '</div>' : '';
    const kicker = p.kicker ? '<div class="cat-cover__kicker">' + esc(p.kicker) + '</div>' : '';
    if (p.template === 'B') {
      return '<div class="cat-cover cat-cover--B"><img class="cat-logo cat-logo--dark cat-cover__logoB" src="' + esc(logoSombre(doc)) + '" alt=""><div class="cat-cover__art">'
        + (p.imageUrl ? '<img class="cat-cover__photoB" src="' + esc(p.imageUrl) + '" alt="">' : dessin ? '<img class="cat-cover__drawB" src="' + esc(dessin) + '" alt="">' : '') + '</div>'
        + kicker + '<div class="cat-cover__title">' + esc(p.title) + '</div><div class="cat-cover__rule"></div>'
        + (p.subtitle ? '<div class="cat-cover__subB"' + ss + '>' + esc(p.subtitle) + '</div>' : '') + boutique + '</div>';
    }
    if (p.template === 'C') {
      return '<div class="cat-cover cat-cover--C"><div class="cat-cover__top">'
        + (p.imageUrl ? '<img class="cat-cover__photoC" src="' + esc(p.imageUrl) + '" alt="">' : dessin ? '<img class="cat-cover__drawC" src="' + esc(dessin) + '" alt="">' : '') + '</div>'
        + '<div class="cat-cover__band"><img class="cat-logo cat-logo--white" src="' + esc(logo(doc)) + '" alt="">' + kicker + '<div class="cat-cover__title"' + st + '>' + esc(p.title) + '</div>'
        + (p.subtitle ? '<div class="cat-cover__sub"' + ss + '>' + esc(p.subtitle) + '</div>' : '') + boutique + '</div></div>';
    }
    return '<div class="cat-cover"><img class="cat-logo cat-logo--white" src="' + esc(logo(doc)) + '" alt="">'
      + '<div class="cat-cover__title"' + st + '>' + esc(p.title) + '</div>'
      + (p.subtitle ? '<div class="cat-cover__sub"' + ss + '>' + esc(p.subtitle) + '</div>' : '')
      + (p.eyebrow ? '<div class="cat-cover__shop">L’Atelier By · ' + esc(p.eyebrow) + '</div>' : '')
      + (p.imageUrl ? '<div class="cat-cover__visual"><img src="' + esc(p.imageUrl) + '" alt=""></div>' : dessin ? '<img class="cat-cover__drawing" src="' + esc(dessin) + '" alt="">' : '') + '</div>';
  }
  /** Page 2 et avant-dernière (charte) : image ou logo, titre, texte ; un paragraphe de plusieurs lignes = une liste. */
  function marquePage(doc, p) {
    const blocs = String(p.text || '').split(/\n\s*\n/).map(b => b.split('\n').map(l => l.trim()).filter(Boolean)).filter(l => l.length);
    // Gabarits (maquette A2) : A texte seul · B image en haut · C image en bas ; sans gabarit : B avec une image.
    const t = p.template || (p.imageUrl ? 'B' : 'A'), haut = t === 'B' && p.imageUrl, bas = t === 'C' && p.imageUrl;
    return '<div class="cat-brand cat-brand--t' + t + (haut ? ' cat-brand--image' : '') + '">'
      + (haut ? '<div class="cat-brand__visual"><img src="' + esc(p.imageUrl) + '" alt=""></div>' : '<img class="cat-logo cat-brand__logo" src="' + esc(logo(doc)) + '" alt="">')
      + '<div class="cat-brand__title">' + esc(p.title) + '</div><div class="cat-brand__rule"></div><div class="cat-brand__text">'
      + blocs.map(l => l.length > 1 ? '<ul>' + l.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>' : '<p>' + esc(l[0]) + '</p>').join('') + '</div>'
      + (p.slot === 'penultimate' && (p.allergens || []).length ? legendeAllergenes(p.allergens, doc.version.lang) : '')
      + (bas ? '<div class="cat-brand__visual cat-brand__visual--bottom"><img src="' + esc(p.imageUrl) + '" alt=""></div>' : '') + '</div>';
  }
  /** La photo du webshop ; sans elle, le dessin de la famille de la page (sinon l'illustration de l'en-tête) — rendu de
   * `Photo`, CataloguePages.tsx ; un cercle vide seulement sans aucun dessin. */
  function photo(pr, repli) {
    const d = pr.imageUrl ? null : dessin(repli);
    return '<span class="cat-photo' + (pr.imageUrl ? ' cat-photo--img' : '') + (d ? ' cat-photo--drawing' : '') + '">' + (pr.imageUrl ? '<img src="' + esc(pr.imageUrl) + '" alt="">' : d ? '<img src="' + esc(d) + '" alt="">' : '<span class="cat-photo__none"></span>') + '</span>';
  }
  /** Le dessin de repli d'une page pour les vignettes sans photo. */
  const repliDe = p => (p && (p.familyDrawingUrl || p.drawingUrl)) || null;
  /** Le prix ; barré par une remise programmée : l'ancien prix à côté, rayé (rendu de `Price`, CataloguePages.tsx). */
  function prixHtml(pr, lang) {
    const vieux = pr.oldPriceCents != null;
    return '<span class="cat-prices">' + (vieux ? '<s class="cat-old">' + esc(prix(pr.oldPriceCents, lang)) + '</s>' : '')
      + '<span class="cat-price' + (pr.priceCents == null ? ' cat-price--none' : '') + (vieux ? ' cat-price--promo' : '') + '">' + esc(prix(pr.priceCents, lang)) + '</span></span>';
  }
  const dispoHtml = t => t ? '<span class="cat-avail">' + esc(t) + '</span>' : '';
  /** La vignette d'une promotion (rendu de `OfferCard`, CataloguePages.tsx). */
  function offreCarte(o, lang, grand, rang, repli) {
    // L'image choisie dans la médiathèque remplace les photos des produits ; sans rien, le dessin de la famille.
    const vus = o.imageUrl ? [] : (o.products || []).slice(0, rang ? 3 : grand ? 4 : (o.products || []).length > 2 ? 4 : 2);
    const photos = '<span class="cat-offer__photos cat-offer__photos--' + Math.max(1, vus.length) + '">'
      + (o.imageUrl ? '<span class="cat-photo cat-photo--media"><img src="' + esc(o.imageUrl) + '" alt=""></span>' : vus.length ? vus.map(x => photo(x, repli)).join('') : photo({}, repli)) + '</span>';
    const noms = (o.products || []).map(p => (p.quantity != null && p.quantity !== 1 ? p.quantity + ' × ' : '') + p.name).join(' + ');
    const plus = o.moreProducts > 0 ? (lang === 'nl' ? ' + ' + o.moreProducts + ' andere' : ' + ' + o.moreProducts + ' autre' + (o.moreProducts > 1 ? 's' : '')) : '';
    return '<div class="cat-offer cat-offer--' + esc(o.type) + (grand ? ' cat-offer--big' : '') + (rang ? ' cat-offer--row' : '') + '"><span class="cat-offer__badge">' + esc(o.badge) + '</span>' + photos
      + '<div class="cat-offer__text"><div class="cat-name">' + esc(o.name) + '</div>' + (noms && !rang ? '<div class="cat-offer__items">' + esc(noms + plus) + '</div>' : '')
      + (o.deal ? '<div class="cat-offer__deal">' + esc(o.deal) + '</div>' : '') + (o.priceCents != null ? prixHtml(o, lang) : '') + dispoHtml(o.period) + '</div></div>';
  }
  /* Le poids et les allergènes de chaque produit (17/11/2026, obligatoires ; rendu de `Facts`, CataloguePages.tsx) et
   * leur légende à l'avant-dernière page (`AllergenLegend`). Pictogrammes : ceux de la tablette (d'après Lucide, ISC). */
  const ALLERGENES = { gluten: '<path d="M2 22 16 8"/><path d="M3.47 12.53 5 11l1.53 1.53a3.5 3.5 0 0 1 0 4.94L5 19l-1.53-1.53a3.5 3.5 0 0 1 0-4.94Z"/><path d="M7.47 8.53 9 7l1.53 1.53a3.5 3.5 0 0 1 0 4.94L9 15l-1.53-1.53a3.5 3.5 0 0 1 0-4.94Z"/><path d="M11.47 4.53 13 3l1.53 1.53a3.5 3.5 0 0 1 0 4.94L13 11l-1.53-1.53a3.5 3.5 0 0 1 0-4.94Z"/><path d="M20 2h2v2a4 4 0 0 1-4 4h-2V6a4 4 0 0 1 4-4Z"/><path d="M11.47 17.47 13 19l-1.53 1.53a3.5 3.5 0 0 1-4.94 0L5 19l1.53-1.53a3.5 3.5 0 0 1 4.94 0Z"/><path d="M15.47 13.47 17 15l-1.53 1.53a3.5 3.5 0 0 1-4.94 0L9 15l1.53-1.53a3.5 3.5 0 0 1 4.94 0Z"/><path d="M19.47 9.47 21 11l-1.53 1.53a3.5 3.5 0 0 1-4.94 0L13 11l1.53-1.53a3.5 3.5 0 0 1 4.94 0Z"/>', crustaces: '<path d="M10 2a3.28 3.28 0 003.227 1.798l6.17-.561A1 1 0 1119.614 8H8.5a6.44 6.44 0 00-5.63 9.75A6.5 6.5 0 008.5 21c1.38 0 2-.5 2.5-1"/><path d="M10 8a8.5 8.5 0 000 8"/><path d="M11 22c-.5-.5-1.12-1-2.5-1a1 1 0 010-5H12a7 7 0 007-7V8"/><path d="M13 12h.01"/><path d="M8 16c-2 0-4.5-4-4-6"/>', oeufs: '<path d="M12 2C8 2 4 8 4 14a8 8 0 0 0 16 0c0-6-4-12-8-12"/>', poisson: '<path d="M6.5 12c.94-3.46 4.94-6 8.5-6 3.56 0 6.06 2.54 7 6-.94 3.47-3.44 6-7 6s-7.56-2.53-8.5-6Z"/><path d="M18 12v.5"/><path d="M16 17.93a9.77 9.77 0 0 1 0-11.86"/><path d="M7 10.67C7 8 5.58 5.97 2.73 5.5c-1 1.5-1 5 .23 6.5-1.24 1.5-1.24 5-.23 6.5C5.58 18.03 7 16 7 13.33"/><path d="M10.46 7.26C10.2 5.88 9.17 4.24 8 3h5.8a2 2 0 0 1 1.98 1.67l.23 1.4"/><path d="m16.01 17.93-.23 1.4A2 2 0 0 1 13.8 21H9.5a5.96 5.96 0 0 0 1.49-3.98"/>', arachides: '<path transform="translate(12 12) rotate(-40) scale(1.08) translate(-12 -12)" d="M12 2.5c2.6 0 4.5 2 4.5 4.6 0 1.6-.8 2.6-1.3 3.6-.4.8 0 1.4.6 2.3.6.9 1.2 2 1.2 3.4 0 3-2.3 5.1-5 5.1s-5-2.1-5-5.1c0-1.4.6-2.5 1.2-3.4.6-.9 1-1.5.6-2.3-.5-1-1.3-2-1.3-3.6 0-2.6 1.9-4.6 4.5-4.6Z"/><path transform="translate(12 12) rotate(-40) scale(1.08) translate(-12 -12)" d="M10.5 6.5h.01M13.5 8h.01M10.5 15.5h.01M13.5 17h.01"/>', soja: '<path d="M10.165 6.598C9.954 7.478 9.64 8.36 9 9c-.64.64-1.521.954-2.402 1.165A6 6 0 0 0 8 22c7.732 0 14-6.268 14-14a6 6 0 0 0-11.835-1.402Z"/><path d="M5.341 10.62a4 4 0 1 0 5.279-5.28"/>', lait: '<path d="M8 2h8"/><path d="M9 2v2.789a4 4 0 0 1-.672 2.219l-.656.984A4 4 0 0 0 7 10.212V20a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-9.789a4 4 0 0 0-.672-2.219l-.656-.984A4 4 0 0 1 15 4.788V2"/><path d="M7 15a6.472 6.472 0 0 1 5 0 6.47 6.47 0 0 0 5 0"/>', fruits_coque: '<path d="M16.847 5.847 20 9a7.23 7.23 0 011.551 7.516C21.241 17.352 21 17.932 21 19v1a1 1 0 01-1 1h-1c-1.069 0-1.648.242-2.485.552A7.2 7.2 0 019.002 20l-3.155-3.153"/><path d="M18.21 5.43c-1.71.69-5.07 1.07-6.71 1.07.46 1.38.91 2.74.61 4.88a.88.88 0 01-.73.74c-1.78.28-3.54-.17-4.88-.62 0 1.64-.38 5-1.07 6.71-.21.52-.82.55-1.17.12A10 10 0 0118.33 4.26c.43.35.4.97-.12 1.17"/><path d="M4.93 4.93 3 3a.7.7 0 010-1"/>', celeri: '<path d="M2 22c1.25-.987 2.27-1.975 3.9-2.2a5.56 5.56 0 0 1 3.8 1.5 4 4 0 0 0 6.187-2.353 3.5 3.5 0 0 0 3.69-5.116A3.5 3.5 0 0 0 20.95 8 3.5 3.5 0 1 0 16 3.05a3.5 3.5 0 0 0-5.831 1.373 3.5 3.5 0 0 0-5.116 3.69 4 4 0 0 0-2.348 6.155C3.499 15.42 4.409 16.712 4.2 18.1 3.926 19.743 3.014 20.732 2 22"/><path d="M2 22 17 7"/>', moutarde: '<rect x="7" y="5" width="10" height="3" rx="1"/><path d="M8 8v11a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2V8"/><path d="M8 13h8"/><path d="M14.5 5 18 1.5"/>', sesame: '<path transform="translate(12 7.5)" d="M0-4.8c1.7 2 2.5 3.6 2.5 4.9a2.5 2.5 0 0 1-5 0c0-1.3.8-2.9 2.5-4.9Z"/><path transform="translate(6.8 16.2) rotate(-40)" d="M0-4.8c1.7 2 2.5 3.6 2.5 4.9a2.5 2.5 0 0 1-5 0c0-1.3.8-2.9 2.5-4.9Z"/><path transform="translate(17.2 16.2) rotate(40)" d="M0-4.8c1.7 2 2.5 3.6 2.5 4.9a2.5 2.5 0 0 1-5 0c0-1.3.8-2.9 2.5-4.9Z"/>', sulfites: '<path d="M8 22h8"/><path d="M7 10h10"/><path d="M12 15v7"/><path d="M12 15a5 5 0 0 0 5-5c0-2-.5-4-2-8H9c-1.5 4-2 6-2 8a5 5 0 0 0 5 5Z"/>', lupin: '<circle cx="12" cy="12" r="3"/><path d="M12 16.5A4.5 4.5 0 1 1 7.5 12 4.5 4.5 0 1 1 12 7.5a4.5 4.5 0 1 1 4.5 4.5 4.5 4.5 0 1 1-4.5 4.5"/><path d="M12 7.5V9"/><path d="M7.5 12H9"/><path d="M16.5 12H15"/><path d="M12 16.5V15"/><path d="m8 8 1.88 1.88"/><path d="M14.12 9.88 16 8"/><path d="m8 16 1.88-1.88"/><path d="M14.12 14.12 16 16"/>', mollusques: '<path d="M14 11a2 2 0 1 1-4 0 4 4 0 0 1 8 0 6 6 0 0 1-12 0 8 8 0 0 1 16 0 10 10 0 1 1-20 0 11.93 11.93 0 0 1 2.42-7.22 2 2 0 1 1 3.16 2.44"/>' };
  const ALLERGENES_NOMS = { gluten: ['Gluten', 'Gluten'], crustaces: ['Crustacés', 'Schaaldieren'], oeufs: ['Œufs', 'Eieren'], poisson: ['Poisson', 'Vis'], arachides: ['Arachides', 'Pinda’s'], soja: ['Soja', 'Soja'], lait: ['Lait', 'Melk'], fruits_coque: ['Fruits à coque', 'Noten'], celeri: ['Céleri', 'Selderij'], moutarde: ['Moutarde', 'Mosterd'], sesame: ['Sésame', 'Sesam'], sulfites: ['Sulfites', 'Sulfieten'], lupin: ['Lupin', 'Lupine'], mollusques: ['Mollusques', 'Weekdieren'] };
  const pictoAllergene = (c, lang) => ALLERGENES[c] ? '<svg class="cat-al" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="' + esc((ALLERGENES_NOMS[c] || [c])[lang === 'nl' ? 1 : 0]) + '"><title>' + esc((ALLERGENES_NOMS[c] || [c])[lang === 'nl' ? 1 : 0]) + '</title>' + ALLERGENES[c] + '</svg>' : '';
  function poidsTexte(g, lang) {
    if (!(g > 0)) { return null; }
    return g >= 1000 ? (g / 1000).toLocaleString(lang === 'nl' ? 'nl-BE' : 'fr-BE', { maximumFractionDigits: 2 }) + '\u00a0kg' : g + '\u00a0g';
  }
  function faits(pr, lang) {
    const w = poidsTexte(pr.weightGrams, lang), al = pr.allergens || [];
    return '<span class="cat-facts">' + (w ? '<span class="cat-weight">' + esc(w) + '</span>' : '') + (al.length ? '<span class="cat-als">' + al.map(c => pictoAllergene(c, lang)).join('') + '</span>' : '') + '</span>';
  }
  function legendeAllergenes(codes, lang) {
    const nl = lang === 'nl';
    return '<div class="cat-allegend"><div class="cat-allegend__title">' + (nl ? 'Allergenen' : 'Allergènes') + '</div><div class="cat-allegend__grid">'
      + codes.map(c => '<span class="cat-allegend__item">' + pictoAllergene(c, lang) + esc((ALLERGENES_NOMS[c] || [c])[nl ? 1 : 0]) + '</span>').join('') + '</div>'
      + '<p class="cat-allegend__note">' + (nl ? 'De pictogrammen naast elk product tonen de allergenen van het recept (verordening EU 1169/2011). Sporen worden niet vermeld: vraag de volledige lijst in de winkel.'
        : 'Les pictogrammes à côté de chaque produit indiquent les allergènes de sa recette (règlement UE 1169/2011). Les traces ne sont pas indiquées : demandez la liste complète en boutique.') + '</p></div>';
  }
  function carte(pr, lang, grand, repli) {
    if (pr.kind === 'offer') { return offreCarte(pr, lang, grand, false, repli); }
    return '<div class="cat-card' + (grand ? ' cat-card--big' : '') + (pr.oldPriceCents != null ? ' cat-card--promo' : '') + '">'
      + (pr.promo ? '<span class="cat-offer__badge cat-card__badge">' + esc(pr.promo.label) + '</span>' : '') + photo(pr, repli)
      + '<div class="cat-card__text"><div class="cat-name">' + esc(pr.name) + '</div>' + faits(pr, lang) + prixHtml(pr, lang) + '</div></div>';
  }
  /** Une image livrée avec le Marketing (dessin de saison, de famille) : son adresse absolue, rendue par le document. */
  const dessin = (url) => /^(https?:)?\/\//.test(String(url || '')) ? url : null;
  function liste(pr, lang, repli) {
    return '<div class="cat-list">' + pr.map(x => x.kind === 'offer' ? offreCarte(x, lang, false, true, repli)
      : '<div class="cat-row">' + photo(x, repli) + '<span class="cat-name">' + esc(x.name) + '</span>' + faits(x, lang) + '<i></i>' + prixHtml(x, lang) + '</div>').join('') + '</div>';
  }
  /** Pleine page (rendu de `FullPage`, CataloguePages.tsx) : le produit en grand — photo, nom, poids et allergènes, prix
   * barré par sa remise, son badge et sa période —, puis « Aussi en promotion » et la vignette de son offre. */
  function pleinePage(pr, lang, repli) {
    const x = pr.find(q => q.kind !== 'offer'), offs = pr.filter(q => q.kind === 'offer');
    if (!x) { return offs.map(o => offreCarte(o, lang, true, false, repli)).join(''); }
    return '<div class="cat-full' + (x.oldPriceCents != null ? ' cat-full--promo' : '') + '"><div class="cat-full__visual">' + (x.promo ? '<span class="cat-offer__badge cat-full__badge">' + esc(x.promo.label) + '</span>' : '') + photo(x, repli) + '</div>'
      + '<div class="cat-full__text"><div class="cat-name cat-full__name">' + esc(x.name) + '</div>' + faits(x, lang) + prixHtml(x, lang) + (x.promo && x.promo.period ? dispoHtml(x.promo.period) : '') + '</div>'
      + offs.map(o => '<div class="cat-full__offer"><div class="cat-full__also">' + (lang === 'nl' ? 'Ook in promotie' : 'Aussi en promotion') + '</div>' + offreCarte(o, lang, false, true, repli) + '</div>').join('') + '</div>';
  }
  /** La disponibilité de la page en pied de page (rendu de `AvailabilityFooter`, composé par le Marketing). */
  const piedDispo = t => t ? '<div class="cat-foot">' + esc(t) + '</div>' : '';
  /** La place libre : la photo de la médiathèque, ou l'illustration (rendu de `Filler`, CataloguePages.tsx). */
  function placeLibre(f, style) {
    const d = dessin(f.drawingUrl), src = d || f.imageUrl;
    return '<div class="cat-filler cat-filler--' + esc(f.kind) + (d ? ' cat-filler--drawing' : '') + '" style="' + style + '">' + (src ? '<img src="' + esc(src) + '" alt="">' : '') + '</div>';
  }
  function interieur(doc, p) {
    const lang = doc.version.lang, pr = p.products || [], f = p.filler || null, r = repliDe(p);
    const rangs = f ? rangsLibres(p.layout, pr.length) : 0;
    const etendu = 'grid-column:1 / -1;grid-row:span ' + rangs;
    let b = '';
    if (!pr.length) {
      b = f ? placeLibre(f, 'flex:1 1 0') : '<div class="cat-empty">' + (lang === 'nl' ? 'Lege pagina' : 'Page vide') + '</div>';
    } else if (p.layout === 'liste') {
      b = liste(pr, lang, r) + (f ? placeLibre(f, 'flex:1 1 0') : '');
    } else if (p.layout === 'pleine') {
      b = pleinePage(pr, lang, r);
    } else if (p.layout === 'mosaique') {
      b = '<div class="cat-grid cat-grid--2x2 cat-grid--mosaique">' + pr.map(x => carte(x, lang, false, r)).join('') + (f && rangs > 0 ? placeLibre(f, etendu) : '') + '</div>';
    } else if (p.layout === 'vedette') {
      b = carte(pr[0], lang, true, r) + '<div class="cat-grid cat-grid--2x2">' + pr.slice(1).map(x => carte(x, lang, false, r)).join('') + (f && rangs > 0 ? placeLibre(f, etendu) : '') + '</div>';
    } else {
      b = '<div class="cat-grid cat-grid--2x3">' + pr.map(x => carte(x, lang, false, r)).join('') + (f && rangs > 0 ? placeLibre(f, etendu) : '') + '</div>';
    }
    // L'en-tête (rendu de `Heading`, CataloguePages.tsx) : la gamme et son illustration (titre + pictogramme · bandeau
    // illustré · grande illustration), puis la catégorie en sous-titre, manuscrite au choix ; sans gamme, la catégorie seule.
    const d = dessin(p.drawingUrl), ph = d ? null : (p.gammeImageUrl || null), src = d || ph, ent = ENTETES[p.header] ? p.header : 'titre';
    const g = p.gammeTitle || null, sc = p.script ? ' cat-h__script' : '', cl = ph ? ' is-photo' : '';
    return '<div class="cat-inner cat-inner--' + esc(p.layout) + ' cat-t-' + esc(p.template || 'A') + ' cat-head-' + ent + '"><div class="cat-h cat-h--' + ent + (src ? '' : ' cat-h--nodraw') + (g ? ' cat-h--gamme' : '') + '"><div class="cat-h__top">'
      + (src ? (ent === 'grande' ? '<span class="cat-h__big' + cl + '"><img src="' + esc(src) + '" alt=""></span>' : '<img class="cat-h__mark' + cl + '" src="' + esc(src) + '" alt="">') : '')
      + '<span class="cat-h__text' + (g ? '' : sc) + '">' + esc(g || p.title) + '</span></div>' + (g ? '<div class="cat-h__sub' + sc + '">' + esc(p.title) + '</div>' : '')
      + '</div><div class="cat-body">' + b + '</div>' + piedDispo(p.availabilityFooter) + '</div>';
  }
  /** La page de présentation d'une gamme (rendu de `Presentation`, CataloguePages.tsx). */
  function presentation(doc, p) {
    const lang = doc.version.lang, d = dessin(p.drawingUrl), avecListe = p.layout === 'presentation_liste', t = p.template || 'A';
    const v = t === 'C' ? (p.imageUrl ? [p.imageUrl, true] : d ? [d, false] : null) : (d ? [d, false] : p.imageUrl ? [p.imageUrl, true] : null);
    return '<div class="cat-pres cat-pres--' + (avecListe ? 'liste' : 'seule') + ' cat-t-' + esc(t) + '">'
      + '<div class="cat-pres__visual ' + (v ? (v[1] ? 'is-photo' : 'is-drawing') : 'is-none') + '">' + (v ? '<img src="' + esc(v[0]) + '" alt="">' : '') + '</div>'
      + '<div class="cat-pres__head"><div class="cat-pres__eyebrow">' + (lang === 'nl' ? 'Het gamma' : 'La gamme') + '</div><div class="cat-pres__title">' + esc(p.title) + '</div><div class="cat-pres__rule"></div>'
      + (p.text ? '<div class="cat-pres__text">' + esc(p.text) + '</div>' : '') + '</div>'
      + (avecListe && (p.products || []).length ? '<div class="cat-pres__list">' + liste(p.products, lang, repliDe(p)) + '</div>' : '') + (avecListe ? piedDispo(p.availabilityFooter) : '') + '</div>';
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
  /** Les coordonnées de la boutique à la dernière page : adresse, horaires, téléphone(s), e-mail (Marketing v1.9 ; rendu de `Back`). */
  const coordonnees = (s, lang) => [s.address, s.hours ? (lang === 'nl' ? 'Open ' + s.hours : 'Ouvert ' + s.hours) : '', [s.phone, s.phone2 || ''].filter(Boolean).join(' · '), s.email || ''].filter(Boolean);
  function dos(doc, p) {
    const lang = doc.version.lang, s = p.shop;
    if (p.template === 'B') {
      // Boutique en avant (maquette A3, gabarit B) : l'adresse et les horaires en grand, le QR plus petit en bas.
      const lignes = s ? coordonnees(s, lang) : [];
      return '<div class="cat-back cat-back--B"><img class="cat-logo" src="' + esc(logo(doc)) + '" alt=""><div class="cat-back__title">' + esc(p.title) + '</div>'
        + (p.text ? '<div class="cat-back__text">' + esc(p.text) + '</div>' : '')
        + '<div class="cat-back__shopB"><div class="cat-back__who">' + esc(s ? 'L’Atelier By — ' + s.name : 'L’Atelier By') + '</div>' + lignes.map(x => '<p>' + esc(x) + '</p>').join('') + '</div>'
        + (p.qrUrl ? '<div class="cat-back__qr cat-back__qr--small">' + qr(p.qrUrl, 20) + '<div><div class="cat-back__host">' + esc(p.host || '') + '</div><div class="cat-back__hint">'
          + esc(s ? (lang === 'nl' ? 'De webshop van ' + s.name : 'Le webshop de ' + s.name) : (lang === 'nl' ? 'Al onze winkels' : 'Toutes nos boutiques')) + '</div></div></div>' : '') + '</div>';
    }
    let h = '<div class="cat-back"><img class="cat-logo" src="' + esc(logo(doc)) + '" alt=""><div class="cat-back__title">' + esc(p.title) + '</div>'
      + (p.text ? '<div class="cat-back__text">' + esc(p.text) + '</div>' : '');
    if (p.qrUrl) {
      h += '<div class="cat-back__qr">' + qr(p.qrUrl, 30) + '<div><div class="cat-back__host">' + esc(p.host || '') + '</div><div class="cat-back__hint">'
        + esc(s ? (lang === 'nl' ? 'De webshop van ' + s.name : 'Le webshop de ' + s.name) : (lang === 'nl' ? 'Al onze winkels' : 'Toutes nos boutiques')) + '</div></div></div>';
    }
    h += '<div class="cat-back__shop"><div class="cat-back__who">' + esc(s ? 'L’Atelier By — ' + s.name : 'L’Atelier By') + '</div>';
    if (s) {
      const lignes = coordonnees(s, lang);
      h += '<p>' + lignes.map(esc).join('<br>') + '</p>';
    } else {
      h += '<p>' + esc((lang === 'nl' ? 'Al onze winkels op ' : 'Toutes nos boutiques sur ') + (p.host || '')) + '</p>';
    }
    return h + '</div></div>';
  }

  function rendre(S) {
    S.el.innerHTML = rendreAssistant(S);
  }

  window.DbCatalogue = {
    /** L'élément du module pour ce magasin (créé à la première demande, son état gardé ensuite). */
    racine(shop, nom) {
      const S = etat(String(shop));
      S.nom = nom || '';
      if (!S.el.childNodes.length) {
        rendre(S);
        if (!S.liste) { chargerListe(S); }
      }
      return S.el;
    },
  };
})();
