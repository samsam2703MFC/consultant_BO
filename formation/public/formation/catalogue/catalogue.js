/*
 * Le catalogue des manuels (19/11/2026, Sam : « je dois pouvoir feuilleter en PDF les différents manuels ; créer dans le
 * rail la possibilité de feuilleter les pages des différents manuels comme un catalogue », puis « code it all »).
 *
 *   #/                 la grille : quatre manuels, chacun avec Feuilleter, PDF et son QR code ; le dépliant 4 manuels
 *   #/m/<cle>          le feuilletage d'un manuel : couverture, sommaire, puis chaque document, en pages A5
 *   #/m/<cle>/imprimer le même, puis la fenêtre d'impression (« Enregistrer en PDF ») : une page A5 par feuille
 *   #/videos           la MÉDIATHÈQUE (23/11/2026, vidéothèque ; 26/11/2026, Sam : « une vraie médiathèque de vidéos, avec
 *                      une barre de recherche intelligente, par section, catégorie, onglet et titre ») : les vidéos
 *                      d'instruction du dashboard magasin, rangées par section du rail, page, onglet et fonction, la
 *                      recherche et le lecteur ; #/videos/<page> (la clé de ?vue=) : une page, onglet par onglet. Le registre est celui du dashboard
 *                      (panel/public/dashboard/videos/videos.json, fichier public, lu à côté du cockpit) : une vidéo
 *                      ajoutée au dashboard (ou par la routine des vidéos) entre ici d'elle-même.
 *
 * Les quatre manuels : le manuel opératoire (les articles), les standards (SOP, mémos, fiches), les checklists (les
 * fiches de checklist) et le manuel du réseau (les domaines D1–D8 et leurs sous-catégories, avec les standards rangés
 * dans chacune). Le QR code d'un manuel mène à son feuilletage (#/m/<cle>), sur l'adresse où la page est ouverte.
 *
 * Données : l'API du module, à côté (../api/cockpit) — /formation/sop/liste, /formation/sop/fiche/{id}, /formation/ref.
 * Lecture seule : rien n'est écrit. Les brouillons n'y figurent pas (la liste ne les rend pas) ; un document
 * « à valider » porte une pastille.
 */
(function () {
  'use strict';

  var API = '../api/cockpit';
  var MANUELS = [
    // Les couvertures (19/11/2026, Sam : « utiliser les couleurs du design system pour les couvertures + illustration ») :
    // la palette de la marque réservée aux couvertures (docs/DESIGN_SYSTEM.md §13.3) : Ruby, Noir, Pop-Up, Abricot ; texte
    // crème #F5EFE4 sur les couleurs sombres, Noir sur l'Abricot.
    { cle: 'operatoire', couleur: '#8D1D2C', encre: '#F5EFE4', types: ['Article'],
      titre: { fr: 'Manuel opératoire', nl: 'Operationeel handboek' },
      sous: { fr: 'Le pourquoi : les articles, partie par partie', nl: 'Het waarom: de artikels, deel per deel' },
      unite: { fr: 'articles', nl: 'artikels' } },
    { cle: 'standards', couleur: '#222222', encre: '#F5EFE4', types: ['SOP', 'Mémo', 'Fiche'],
      titre: { fr: 'Standards (SOP)', nl: 'Standaarden (SOP)' },
      sous: { fr: 'Le comment : les gestes, étape par étape', nl: 'Het hoe: de handelingen, stap per stap' },
      unite: { fr: 'standards', nl: 'standaarden' } },
    { cle: 'checklists', couleur: '#78554B', encre: '#F5EFE4', types: ['Checklist'],
      titre: { fr: 'Checklists', nl: 'Checklists' },
      sous: { fr: 'Le quand : ce qu’on contrôle, et à quel moment', nl: 'Het wanneer: wat men controleert, en wanneer' },
      unite: { fr: 'checklists', nl: 'checklists' } },
    { cle: 'reseau', couleur: '#F2C9A0', encre: '#222222', ref: true,
      titre: { fr: 'Manuel du réseau', nl: 'Netwerkhandboek' },
      sous: { fr: 'Le classeur : les domaines et les postes', nl: 'De klasseur: de domeinen en de functies' },
      unite: { fr: 'domaines', nl: 'domeinen' } },
    // Le manuel de pré-ouverture (19/11/2026, Sam : « rajouter un manuel de pré-ouverture dans le catalogue des manuels ») :
    // le Guide pré-ouverture du Manuel du réseau (GET /formation/ref › preouverture, édité dans le module, lu par le CRM),
    // mis en pages : le compte à rebours, chaque section avec ses étapes (échéance, responsable, preuve à garder), les
    // contacts, puis les articles à lire avant l'ouverture. Le Beige de la marque, texte Noir.
    { cle: 'preouverture', couleur: '#EAE4DC', encre: '#222222', guide: true,
      titre: { fr: 'Pré-ouverture', nl: 'Vóór de opening' },
      sous: { fr: 'Le compte à rebours : de J-90 à l’ouverture', nl: 'De aftelling: van D-90 tot de opening' },
      unite: { fr: 'étapes', nl: 'stappen' } },
    // Les fiches techniques (19/11/2026, Sam : « créer un nouveau manuel : fiches des recettes et fiches techniques, une
    // feuille par produit, triée par catégorie, standard, saison ») : la fiche technique de chaque produit du panel TF
    // Buddy, celle de l'application cuisine (GET /formation/ref/fiches, src/fiches_techniques.php). Crème, texte Ruby.
    { cle: 'fiches', couleur: '#F5EFE4', encre: '#8D1D2C', fiches: true,
      titre: { fr: 'Fiches techniques', nl: 'Technische fiches' },
      sous: { fr: 'Les recettes : une fiche par produit', nl: 'De recepten: één fiche per product' },
      unite: { fr: 'produits', nl: 'producten' } },
  ];
  /*
   * Les illustrations des couvertures (19/11/2026, Sam : « illustrations du design system ») : celles de la marque, au trait
   * continu, des gammes et des familles de produits, déjà servies par le cockpit (public/tablette/img/p,
   * public/assets/img/newsletter). Posées en masque, elles prennent la couleur du texte de la couverture : crème sur le
   * Ruby, le Noir et le Pop-Up, Noir sur l'Abricot ; Ruby, leur couleur d'origine, sur la carte Beige du dépliant.
   */
  var ILLUS = {
    operatoire: '../../tablette/img/p/croissant.png',
    standards: '../../tablette/img/p/bread-1.png',
    checklists: '../../tablette/img/p/sweet-tart-small.png',
    reseau: '../../assets/img/newsletter/cake.png',
    preouverture: '../../tablette/img/p/rolls.png',
    fiches: '../../tablette/img/p/sandwiches.png',
    depliant: '../../tablette/img/p/cookies.png',
  };
  function illustration(cle, classe) {
    var u = ILLUS[cle];
    return u ? '<span class="illu ' + (classe || 'ill') + '" aria-hidden="true" style="-webkit-mask-image:url(' + u + ');mask-image:url(' + u + ')"></span>' : '';
  }
  var CONCURRENCE = 6;     // fiches lues en même temps
  var S = { liste: null, ref: null, fiches: {}, langue: 'fr', manuel: null, pages: null, i: 0, erreur: null,
    parCode: {}, parId: {}, debuts: {}, langPages: null, somIdx: 1,
    v25: null, panel: null, compSop: null, postesLus: false, ft: null };
  /*
   * Les couleurs des codes de checklist : celles du cockpit (dashboard/dashboard.js TJ_ZONES, task/task.js CK_ZONES), pour
   * qu'un code se lise pareil partout. 1er chiffre : qui ; 2e : quand ; puis le numéro (src/checklists_codes.php). Le 0
   * est celui des SOP et des articles. Le « quand » reste gris : le bleu est déjà celui du nettoyage.
   */
  var ZONES = {
    1: [{ fr: 'Vente', nl: 'Verkoop' }, '#8D1D2C'], 2: [{ fr: 'Boulangerie', nl: 'Bakkerij' }, '#B0792A'],
    3: [{ fr: 'Traiteur', nl: 'Traiteur' }, '#3f7a52'], 4: [{ fr: 'Nettoyage', nl: 'Schoonmaak' }, '#1f5f8b'],
    5: [{ fr: 'Gestion', nl: 'Beheer' }, '#6B4FBB'] };
  var MOMENTS = { 1: { fr: 'Ouverture', nl: 'Opening' }, 2: { fr: 'Journée', nl: 'Dag' }, 3: { fr: 'Après-midi', nl: 'Namiddag' },
    4: { fr: 'Fermeture', nl: 'Sluiting' }, 5: { fr: 'Chaque semaine', nl: 'Elke week' }, 6: { fr: 'Chaque mois', nl: 'Elke maand' } };
  var GRIS = '#5c5852';
  try { if (localStorage.getItem('ctLangue') === 'nl') S.langue = 'nl'; } catch (e) { /* */ }
  var root = document.getElementById('ct');
  var mesure = null;

  /* ── petits outils ── */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function L(x) { if (x == null) return ''; if (typeof x === 'string') return x; return x[S.langue] || x.fr || x.nl || ''; }
  function T(fr, nl) { return S.langue === 'nl' ? nl : fr; }
  function api(chemin) {
    return fetch(API + chemin, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
  }
  function base() { return location.href.split('#')[0]; }
  function lienManuel(cle) { return base() + '#/m/' + cle; }
  function qrSvg(url, cellule) {
    if (typeof window.qrcode !== 'function') return '';
    var q = window.qrcode(0, 'M'); q.addData(url); q.make();
    return q.createSvgTag({ cellSize: cellule || 4, margin: 0, scalable: true, alt: 'QR code : ' + url });
  }
  function photoUrl(p) { p = String(p); return p.indexOf('u/') === 0 ? API + '/formation/sop/photo/' + encodeURIComponent(p.slice(2)) : '../sop/photos/' + encodeURIComponent(p); }
  /** Le manuel où se lit un document, d'après son type. */
  function manuelDuType(t) { return t === 'Article' ? 'operatoire' : t === 'Checklist' ? 'checklists' : 'standards'; }
  /** Le lien direct d'un document : sa page dans le feuilletage de son manuel (adresse complète : il reste cliquable dans le PDF). */
  function lienDoc(d) { return base() + '#/m/' + manuelDuType(d.type) + '/d/' + encodeURIComponent(d.id); }
  /**
   * Un texte, échappé, où chaque code de document (0.1.01, 0.0.56…) ou de checklist (1.1.01, 5.5.01…) qui existe dans le
   * module devient un lien direct (19/11/2026, Sam : « quand on parle d'une procédure, SOP, tâche ou checklist, mettre un
   * lien direct dans le PDF »). Un code sans document (une checklist du panel sans fiche) reste du texte.
   */
  function lier(texte) {
    return esc(texte).replace(/\b([0-5]\.[0-6]\.\d{2})\b/g, function (m0, code) {
      var d = S.parCode[code];
      return d ? '<a class="lien-doc" href="' + esc(lienDoc(d)) + '">' + code + '</a>' : m0;
    });
  }
  /** Un code de checklist (z.m.nn) en pastille à la couleur de sa zone ; tout autre code, tel quel. */
  function codeCk(code) {
    var m = /^([1-5])\.([1-6])\.(\d{2})$/.exec(String(code || ''));
    return m ? '<span class="ck-c" style="background:' + ZONES[m[1]][1] + '">' + esc(code) + '</span>' : esc(code);
  }
  function norm(t) { return String(t || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]/g, ''); }
  function indexer() {
    S.parCode = {}; S.parId = {};
    ((S.liste && S.liste.sops) || []).forEach(function (d) { if (d.code) S.parCode[d.code] = d; S.parId[d.id] = d; });
  }
  function manuelDe(cle) { return MANUELS.filter(function (m) { return m.cle === cle; })[0] || null; }
  function docsDe(m) {
    if (!S.liste || !m.types) return [];
    return (S.liste.sops || []).filter(function (s) { return m.types.indexOf(s.type) >= 0; })
      .sort(function (a, b) { return String(a.code).localeCompare(String(b.code), 'fr', { numeric: true }); });
  }
  function nbDe(m) {
    if (m.ref) return ((S.ref && S.ref.domaines) || []).length;
    if (m.guide) return guideEtapes().length;
    if (m.fiches) return ftProduits().length;
    return docsDe(m).length;
  }
  function nbSousCats() { return ((S.ref && S.ref.domaines) || []).reduce(function (n, d) { return n + (d.souscats || []).length; }, 0); }
  function aujourdhui() { return new Date().toLocaleDateString(S.langue === 'nl' ? 'nl-BE' : 'fr-BE', { day: 'numeric', month: 'long', year: 'numeric' }); }
  function segLangue() {
    return '<div class="seg" role="group" aria-label="Langue">' + ['fr', 'nl'].map(function (l) {
      return '<button type="button" class="' + (S.langue === l ? 'is-active' : '') + '" data-langue="' + l + '" aria-pressed="' + (S.langue === l) + '">' + l.toUpperCase() + '</button>';
    }).join('') + '</div>';
  }

  /* ── la médiathèque : le registre ── */
  var VGENRES = [
    { g: 'formation', t: { fr: 'Modules de formation', nl: 'Opleidingsmodules' }, k: { fr: 'Formation', nl: 'Opleiding' } },
    { g: 'nouveau', t: { fr: 'Nouvelles fonctions', nl: 'Nieuwe functies' }, k: { fr: 'Nouvelle fonction', nl: 'Nieuwe functie' } },
    { g: 'tuto', t: { fr: 'Modes d’emploi', nl: 'Handleidingen' }, k: { fr: 'Mode d’emploi', nl: 'Handleiding' } },
  ];
  /** Le dossier des vidéos : celui du dashboard du cockpit (l'adresse que rend /acces/moi, garde.js), sinon à côté. */
  function dossierVideos() {
    try { if (window.ACCES && window.ACCES.cockpit) return new URL('dashboard/videos/', new URL(window.ACCES.cockpit, location.href)).href; } catch (e) { /* adresse illisible */ }
    return new URL('../../dashboard/videos/', location.href).href;
  }
  function chargerVideos() {
    if (S.videos || S.videosLecture) return S.videosLecture;
    var essais = [dossierVideos(), new URL('../../dashboard/videos/', location.href).href].filter(function (x, i, t) { return t.indexOf(x) === i; });
    function essayer(i) {
      if (i >= essais.length) return Promise.reject(new Error('registre des vidéos introuvable'));
      return fetch(essais[i] + 'videos.json', { cache: 'no-cache', headers: { Accept: 'application/json' } })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (j) { S.videosDossier = essais[i]; return j; }, function () { return essayer(i + 1); });
    }
    S.videosLecture = essayer(0).then(function (j) { S.videos = (j && j.videos || []).filter(function (v) { return v && v.id && v.fichier; }); },
      function (e) { S.videos = []; S.videosErreur = e.message; }).then(chargerFilmees).then(function () { S.videosLecture = null; });
    return S.videosLecture;
  }
  /** Les vidéos filmées (Production, Vente et comptoir…) : l'API du module (formation/src/mediatheque.php), publiées seulement. */
  function chargerFilmees() {
    return fetch(API + '/formation/mediatheque/liste', { credentials: 'same-origin', cache: 'no-cache', headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (j) { S.filmees = (j && j.videos) || []; }, function () { S.filmees = []; });
  }
  function carteVideotheque() {
    var n = S.videos ? S.videos.length + (S.filmees || []).length : null;
    var nf = S.videos ? S.videos.filter(function (v) { return v.genre === 'formation'; }).length : 0;
    return '<div class="ct-carte">' +
      '<a class="ct-couv ct-couv-vid" href="#/videos" style="text-decoration:none">' +
        '<div class="n">' + T('Médiathèque', 'Mediatheek') + '</div>' +
        '<svg class="ill" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.5" y="4.5" width="19" height="15" rx="2.5"/><path d="m10 9 5 3-5 3z"/></svg>' +
        '<div class="bas"><div class="t">' + T('Médiathèque', 'Mediatheek') + '</div><div class="s">' + T('Chaque page, onglet et fonction du dashboard, en vidéo', 'Elke pagina, elk tabblad en elke functie van het dashboard, op video') + '</div></div></a>' +
      '<h3>' + T('Médiathèque', 'Mediatheek') + '</h3>' +
      '<p><span class="ct-chip">' + (n == null ? T('Lecture…', 'Lezen…') : n + ' ' + T('vidéos', 'video’s') + (nf ? ' · ' + nf + ' ' + T('formation' + (nf > 1 ? 's' : ''), 'opleiding' + (nf > 1 ? 'en' : '')) : '')) + '</span></p>' +
      '<div class="ct-actions"><a class="btn btn--primary" href="#/videos">' + T('Regarder', 'Bekijken') + '</a><button type="button" class="btn" data-qr="videos">QR</button></div></div>';
  }
  /* ── la médiathèque (26/11/2026, Sam : « une vraie médiathèque de vidéos, avec une barre de recherche intelligente,
     par section, catégorie, onglet et titre ») ──
     Trois niveaux de vidéos (24/11/2026) : la page du rail (`niveau` absent ou « page »), ses onglets (« onglet ») et
     leurs fonctions (« fonction ») ; `parent` range chacune, `debut` et `fin` bornent le passage du MP4 de l'onglet.
     Rangement : la SECTION du rail (La journée, Les chiffres…), la CATÉGORIE (la page : Offres, Mon équipe…), l'ONGLET,
     le TITRE. #/videos : l'accueil ; #/videos/<page> (la clé de ?vue=, « offres ») : la page onglet par onglet. La
     recherche ignore accents et majuscules, connaît des synonymes (« mail » trouve la Boîte mail, « horaire » le
     planning), accepte un début de mot et une faute de frappe, et classe titre d'abord, puis onglet et page, puis
     résumé. « / » met le curseur dans la recherche, Entrée ouvre le premier résultat, Échap l'efface. */
  var VNIV = { page: { fr: 'Page', nl: 'Pagina' }, onglet: { fr: 'Onglet', nl: 'Tabblad' }, fonction: { fr: 'Fonction', nl: 'Functie' } };
  // Les sections et les pages du rail du dashboard, dans son ordre (panel/public/dashboard/dashboard.js, RAIL).
  var VSECTIONS = [
    { s: { fr: 'En haut du rail', nl: 'Bovenaan' }, p: [['boite', 'Boîte mail'], ['assistant', 'Assistant IA']] },
    { s: { fr: 'La journée', nl: 'De dag' }, p: [['ops', 'Opérationnel'], ['commandes', 'Commandes'], ['taches', 'Tâches'], ['morts', 'Temps morts'], ['equipe', 'Mon équipe'], ['primes', 'Primes']] },
    { s: { fr: 'Les chiffres', nl: 'De cijfers' }, p: [['semaine', 'Semaine'], ['mois', 'Mois'], ['annee', 'Année'], ['tresorerie', 'Trésorerie']] },
    { s: { fr: 'Vendre', nl: 'Verkopen' }, p: [['analyse', 'Analyse'], ['produits', 'Produits'], ['promos', 'Promotions'], ['clients', 'Clients'], ['offres', 'Offres'], ['concurrence', 'Concurrence']] },
    { s: { fr: 'Marketing', nl: 'Marketing' }, p: [['marketing', 'Mon marketing'], ['social', 'Réseaux sociaux'], ['fidelite', 'Fidélité'], ['catalogue', 'Mon catalogue']] },
    { s: { fr: 'Se former', nl: 'Leren' }, p: [['formation', 'Formations et suivi']] },
    { s: { fr: 'En bas du rail', nl: 'Onderaan' }, p: [['coffre', 'Mon coffre-fort'], ['mails', 'Paramètres']] },
  ];
  /* Les COLLECTIONS (26/11/2026, Sam : « vidéo d'utilisation du dashboard, vidéo de production… ») : le dashboard (le
     registre ci-dessus, rangé par section, page, onglet) et les vidéos filmées, rangées par catégorie (texte libre),
     ajoutées dans le portail de formation (Médiathèque › Gérer les vidéos). #/videos/c/<collection>[/<catégorie>] ;
     #/videos/v/<id> ouvre une vidéo (le lien du lecteur du dashboard). */
  var VCOLS = [['dashboard', { fr: 'Utilisation du dashboard', nl: 'Gebruik van het dashboard' }], ['production', { fr: 'Production', nl: 'Productie' }],
    ['vente', { fr: 'Vente et comptoir', nl: 'Verkoop en toonbank' }], ['hygiene', { fr: 'Hygiène et sécurité', nl: 'Hygiëne en veiligheid' }],
    ['ouverture', { fr: 'Ouverture et fermeture', nl: 'Opening en sluiting' }]];
  function vNb(n) { return n + ' ' + T(n > 1 ? 'vidéos' : 'vidéo', n > 1 ? 'video’s' : 'video'); }
  function vColNom(k) { var c = VCOLS.filter(function (x) { return x[0] === k; })[0]; return c ? L(c[1]) : k; }
  function vSlug(s) { return vNorm(s).replace(/ /g, '-') || 'autres'; }
  var RACINE_SITE = new URL('../../', location.href).href;   // le site : le catalogue vit sous formation/catalogue/
  VNIV.video = { fr: 'Vidéo', nl: 'Video' };
  var VALIAS = { jour: 'ops', trimestre: 'annee', frais: 'tresorerie', reclamation: 'ops' };
  var VTYPES = [['tout', { fr: 'Tout', nl: 'Alles' }], ['page', { fr: 'Pages', nl: 'Pagina’s' }], ['onglet', { fr: 'Onglets', nl: 'Tabbladen' }],
    ['fonction', { fr: 'Fonctions', nl: 'Functies' }], ['formation', { fr: 'Formations', nl: 'Opleidingen' }], ['nouveau', { fr: 'Nouveautés', nl: 'Nieuw' }]];
  // Des mots qui se valent pour chercher (un mot tapé trouve aussi les autres de son groupe).
  var VSYN = [['mail', 'email', 'courriel', 'boite', 'message'], ['horaire', 'planning', 'shift', 'service'], ['creer', 'ajouter', 'nouveau', 'nouvelle', 'saisir'],
    ['modifier', 'changer', 'editer', 'adapter', 'corriger', 'regler'], ['supprimer', 'effacer', 'retirer', 'enlever'], ['envoyer', 'partager', 'transferer', 'repondre'],
    ['prix', 'tarif', 'marge', 'coefficient'], ['client', 'clientele', 'particulier', 'entreprise', 'b2b'], ['promo', 'promotion', 'bundle', 'remise'],
    ['commande', 'tournee', 'livraison', 'retrait'], ['tresorerie', 'cash', 'banque', 'solde', 'argent'], ['frais', 'charge', 'depense'],
    ['vente', 'chiffre', 'caisse', 'ca'], ['employe', 'personne', 'equipe', 'vendeuse', 'vendeur'], ['prime', 'bonus'], ['catalogue', 'brochure'],
    ['coffre', 'document', 'contrat', 'attestation'], ['formation', 'former', 'apprendre', 'manuel', 'cours'], ['parametre', 'reglage', 'option'],
    ['tva', 'taxe'], ['imprimer', 'impression', 'pdf'], ['concurrent', 'concurrence', 'releve'], ['newsletter', 'campagne', 'sms'],
    ['invendu', 'perte', 'casse'], ['stock', 'vitrine'], ['conge', 'absence', 'absent']];
  var VVIDES = ' le la les l un une des de du d a au aux et en pour sur mon ma mes ton ta tes son sa ses comment je j on qu que quoi ou dans avec faire fait video videos ';
  function vNorm(s) { return String(s == null ? '' : s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, ' ').trim(); }
  function vMots(s) { return vNorm(s).split(' ').filter(Boolean); }
  function estPageV(v) { return !v.niveau || v.niveau === 'page'; }
  function enfantsV(v) { return (S.videos || []).filter(function (x) { return x.parent === v.id && !estPageV(x); }); }
  function descendantsV(v) { var E = enfantsV(v); return E.concat.apply(E, E.map(enfantsV)); }
  function srcV(dos, v) { return dos + v.fichier + (v.debut != null && v.fin != null ? '#t=' + (+v.debut).toFixed(2) + ',' + (+v.fin).toFixed(2) : ''); }
  /** La page (catégorie) d'une clé de ?vue= : {cle, nom, si (rang de la section), pi (rang dans la section)}. */
  function vCat(vue) {
    var k = VALIAS[vue] || vue;
    for (var i = 0; i < VSECTIONS.length; i++) { for (var j = 0; j < VSECTIONS[i].p.length; j++) { if (VSECTIONS[i].p[j][0] === k) return { cle: k, nom: VSECTIONS[i].p[j][1], si: i, pi: j }; } }
    return { cle: k, nom: k, si: VSECTIONS.length, pi: 0 };
  }
  /** L'index : chaque vidéo avec sa section, sa page, son onglet, et les mots où chercher (refait à chaque lecture du registre). */
  function vIndex() {
    if (S.vidx && S.vidxDe === S.videos && S.vidxF === S.filmees && S.vidxL === S.langue) return S.vidx;
    var par = {}; (S.videos || []).forEach(function (v) { par[v.id] = v; });
    S.vidx = (S.videos || []).map(function (v, ordre) {
      var p = v, o = null, g = 0;
      while (p && !estPageV(p) && p.parent && par[p.parent] && g++ < 4) { if (p.niveau === 'onglet') { o = p; } p = par[p.parent]; }
      if (v.niveau === 'onglet') { o = v; }
      var c = vCat(((p && p.vues) || v.vues || ['?'])[0]);
      var sec = VSECTIONS[c.si] ? L(VSECTIONS[c.si].s) : '';
      return { v: v, ordre: ordre, col: 'dashboard', niv: v.niveau || 'page', page: p, onglet: o, cat: c, sec: sec,
        mTitre: vMots(v.titre), mLieu: vMots(c.nom + ' ' + (o && o !== v ? o.titre : '') + ' ' + (p && p !== v ? p.titre : '')), mSec: vMots(sec + ' dashboard'), mRes: vMots(v.resume) };
    });
    // Les vidéos filmées : la collection tient lieu de section, la catégorie de page.
    var n0 = S.vidx.length, nl = S.langue === 'nl';
    (S.filmees || []).forEach(function (f, i) {
      var v = { id: 'm' + f.id, titre: (nl && f.titreNl) || f.titre, resume: (nl && f.resumeNl) || f.resume || '', duree: f.duree, filme: f,
        afficheUrl: f.affiche ? RACINE_SITE + f.affiche : (f.lien && f.lien.type === 'youtube' ? 'https://i.ytimg.com/vi/' + f.lien.id + '/hqdefault.jpg' : '') };
      var cn = vColNom(f.collection), nom = f.categorie || T('Autres vidéos', 'Overige video’s');
      S.vidx.push({ v: v, ordre: n0 + i, col: f.collection, niv: 'video', page: null, onglet: null, sec: cn,
        cat: { cle: 'f:' + f.collection + ':' + vSlug(f.categorie || ''), slug: vSlug(f.categorie || ''), nom: nom, si: -1, pi: 0 },
        mTitre: vMots(f.titre + ' ' + (f.titreNl || '')), mLieu: vMots(nom), mSec: vMots(cn), mRes: vMots(v.resume) });
    });
    S.vidxDe = S.videos; S.vidxF = S.filmees; S.vidxL = S.langue;
    return S.vidx;
  }
  function vDist1(a, b) {   // vrai si a et b diffèrent d'au plus une lettre (ajout, retrait, remplacement, inversion)
    if (Math.abs(a.length - b.length) > 1) return false;
    var i = 0, j = 0, d = 0;
    while (i < a.length && j < b.length) {
      if (a[i] === b[j]) { i++; j++; continue; }
      if (++d > 1) return false;
      if (a[i + 1] === b[j] && a[i] === b[j + 1]) { i += 2; j += 2; continue; }
      if (a.length > b.length) i++; else if (b.length > a.length) j++; else { i++; j++; }
    }
    return d + (a.length - i) + (b.length - j) <= 1;
  }
  /** Les formes d'un mot cherché : lui-même et ses synonymes. */
  function vFormes(t) {
    var F = [t];
    VSYN.forEach(function (g) { if (g.some(function (x) { return x === t || (t.length >= 4 && x.indexOf(t) === 0); })) { F = F.concat(g); } });
    return F.filter(function (x, i, a) { return a.indexOf(x) === i; });
  }
  /** Un mot de la vidéo répond-il à une forme cherchée ? 2 = mot entier, 1 = début de mot ou faute de frappe. */
  function vTouche(mot, f) {
    if (mot === f) return 2;
    if (f.length >= 2 && mot.indexOf(f) === 0) return 1;
    if (f.length >= 5 && (vDist1(mot, f) || vDist1(mot.slice(0, f.length), f))) return 1;
    return 0;
  }
  // Le mot tapé compte plein, un synonyme aux trois quarts (« créer une offre » met « Créer » avant « Nouvelle »).
  function vScoreMots(mots, formes) { var b = 0; mots.forEach(function (m) { formes.forEach(function (f, i) { b = Math.max(b, vTouche(m, f) * (i ? 0.75 : 1)); }); }); return b; }
  /** La recherche : les mots tapés (hors mots vides), chacun cherché dans le titre (×6), le lieu (page, onglet ×4), la
   *  section (×2) et le résumé (×2). Tous les mots doivent répondre ; sinon, les vidéos les plus proches. */
  function vChercher(q, type) {
    var T0 = vMots(q).filter(function (t) { return VVIDES.indexOf(' ' + t + ' ') < 0; });
    if (!T0.length) return { liste: [], proches: false, mots: [] };
    var F = T0.map(vFormes);
    var R = vIndex().filter(function (e) { return vType(e, type); }).map(function (e) {
      var tous = true, sc = 0;
      F.forEach(function (fs) {
        var s = Math.max(vScoreMots(e.mTitre, fs) * 6, vScoreMots(e.mLieu, fs) * 4, vScoreMots(e.mSec, fs) * 2, vScoreMots(e.mRes, fs) * 2);
        if (!s) tous = false; sc += s;
      });
      return { e: e, sc: sc, tous: tous };
    }).filter(function (r) { return r.sc > 0; });
    var pleins = R.filter(function (r) { return r.tous; });
    var L2 = (pleins.length ? pleins : R).sort(function (a, b) { return b.sc - a.sc || a.e.ordre - b.e.ordre; });
    return { liste: L2.map(function (r) { return r.e; }), proches: !pleins.length && R.length > 0, mots: [].concat.apply([], F) };
  }
  function vType(e, type) {
    if (!type || type === 'tout') return true;
    if (type === 'formation' || type === 'nouveau') return (e.v.genre || 'tuto') === type;
    return e.niv === type;
  }
  /** Le titre, les mots trouvés en évidence. */
  function vSurligne(titre, formes) {
    if (!formes || !formes.length) return esc(titre);
    return String(titre).split(/(\s+)/).map(function (bout) {
      var n = vNorm(bout).split(' ')[0] || '';
      return n && formes.some(function (f) { return vTouche(n, f); }) ? '<mark>' + esc(bout) + '</mark>' : esc(bout);
    }).join('');
  }
  function vChemin(e, sans) {
    var P = [e.sec, e.cat.nom];
    if (e.onglet && e.onglet !== e.v) P.push(e.onglet.titre);
    return P.filter(function (x) { return x && x !== sans; }).map(esc).join(' › ');
  }
  function vKicker(e) {
    var g = e.v.genre || 'tuto';
    if (g === 'formation') return T('Formation', 'Opleiding');
    if (g === 'nouveau' && e.niv === 'page') return T('Nouveauté', 'Nieuw');
    if (e.niv === 'video') return e.sec;
    return L(VNIV[e.niv] || VNIV.page);
  }
  /** Une carte : l'affiche, la durée, le type, le titre (mots trouvés en évidence), où elle se range, le résumé. */
  function carteV(dos, e, formes, cheminSans) {
    var v = e.v;
    return '<button type="button" class="ct-vt ct-mt-' + e.niv + '" data-video="' + esc(v.id) + '">' +
      '<span class="ct-vt-aff">' + (v.afficheUrl || v.affiche ? '<img src="' + esc(v.afficheUrl || dos + v.affiche) + '" alt="" loading="lazy">' : '') + '<span class="ct-vt-lire" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="m9 7 9 5-9 5z"/></svg></span>' + (v.duree ? '<span class="ct-vt-d">' + esc(v.duree) + '</span>' : '') + '</span>' +
      '<span class="ct-vt-k">' + esc(vKicker(e)) + '</span><span class="ct-vt-t">' + vSurligne(v.titre, formes) + '</span>' +
      '<span class="ct-mt-ch">' + vChemin(e, cheminSans) + '</span>' + (v.resume ? '<span class="ct-vt-r">' + esc(v.resume) + '</span>' : '') + '</button>';
  }
  /** La carte d'une page (catégorie) : sa première affiche, combien de vidéos, d'onglets, de fonctions. */
  function carteCat(dos, cle, E) {
    var c = vCat(cle), P = E.filter(function (e) { return e.niv === 'page'; }), aff = (P[0] || E[0]).v.affiche;
    var no = E.filter(function (e) { return e.niv === 'onglet'; }).length, nf = E.filter(function (e) { return e.niv === 'fonction'; }).length;
    return '<a class="ct-mt-cat" href="#/videos/' + encodeURIComponent(c.cle) + '"><span class="ct-vt-aff">' + (aff ? '<img src="' + esc(dos + aff) + '" alt="" loading="lazy">' : '') + '</span>' +
      '<span class="ct-vt-t">' + esc(c.nom) + '</span><span class="ct-mt-n">' + vNb(E.length) +
      (no ? ' · ' + no + ' ' + T('onglet' + (no > 1 ? 's' : ''), 'tabblad' + (no > 1 ? 'en' : '')) : '') + (nf ? ' · ' + nf + ' ' + T('fonction' + (nf > 1 ? 's' : ''), 'functie' + (nf > 1 ? 's' : '')) : '') + '</span></a>';
  }
  /** Les vidéos rangées par section puis par page (une liste filtrée par type, sans recherche). */
  function vRangees(dos, E) {
    var h = '';
    VSECTIONS.forEach(function (S1, si) {
      var Es = E.filter(function (e) { return e.cat.si === si; }); if (!Es.length) return;
      h += '<h2 class="ct-mt-sec">' + esc(L(S1.s)) + '</h2>';
      S1.p.forEach(function (pp) {
        var Ep = Es.filter(function (e) { return e.cat.cle === pp[0]; }); if (!Ep.length) return;
        h += '<h3 class="ct-vt-h"><a href="#/videos/' + pp[0] + '">' + esc(pp[1]) + '</a> <span class="ct-chip">' + Ep.length + '</span></h3><div class="ct-vt-grille">' +
          Ep.map(function (e) { return carteV(dos, e, null, e.sec); }).join('') + '</div>';
      });
    });
    return h;
  }
  /** Une page (catégorie), onglet par onglet : ses vidéos de page, puis chaque onglet et ses fonctions (E : les vidéos
   *  du type choisi ; un onglet garde son titre quand seules ses fonctions sont montrées). */
  function vCategorie(dos, cle, E) {
    var c = vCat(cle), Ec = E.filter(function (e) { return e.cat.cle === c.cle; });
    var P = Ec.filter(function (e) { return e.niv === 'page'; });
    var O = vIndex().filter(function (e) { return e.cat.cle === c.cle && e.niv === 'onglet'; });
    var corps = O.map(function (o) {
      var X = Ec.filter(function (e) { return e === o || (e.niv === 'fonction' && e.onglet === o.v); });
      if (!X.length) return '';
      return '<h3 class="ct-vt-h">' + esc(o.v.titre) + ' <span class="ct-chip">' + X.length + '</span></h3><div class="ct-vt-grille">' +
        X.map(function (e) { return carteV(dos, e, null, c.nom); }).join('') + '</div>';
    }).join('');
    var seules = Ec.filter(function (e) { return e.niv === 'fonction' && !e.onglet; });
    return (P.length ? '<h2 class="ct-mt-sec">' + T('La page', 'De pagina') + '</h2><div class="ct-vt-grille">' + P.map(function (e) { return carteV(dos, e, null, e.sec); }).join('') + '</div>' : '') +
      (corps ? '<h2 class="ct-mt-sec">' + T('Onglet par onglet', 'Per tabblad') + '</h2>' + corps : '') +
      (seules.length ? '<div class="ct-vt-grille">' + seules.map(function (e) { return carteV(dos, e, null, c.nom); }).join('') + '</div>' : '');
  }
  /** Le sommaire : les collections ; sous celle qui est ouverte, ses sections et pages (dashboard) ou ses catégories. */
  function vSommaire(col, cle, catS, E) {
    return '<nav class="ct-mt-nav" aria-label="' + T('Collections', 'Collecties') + '"><a href="#/videos" class="' + (!col ? 'on' : '') + '">' + T('Toute la médiathèque', 'De hele mediatheek') + ' <i>' + E.length + '</i></a>' +
      VCOLS.map(function (c) {
        var Ec = E.filter(function (e) { return e.col === c[0]; });
        var h = '<a href="#/videos/c/' + c[0] + '" class="ct-mt-coln ' + (col === c[0] && !cle && !catS ? 'on' : '') + '">' + esc(L(c[1])) + ' <i>' + Ec.length + '</i></a>';
        if (col !== c[0]) return h;
        if (c[0] === 'dashboard') {
          return h + VSECTIONS.map(function (S1) {
            var P = S1.p.filter(function (pp) { return Ec.some(function (e) { return e.cat.cle === pp[0]; }); }); if (!P.length) return '';
            return '<div class="ct-mt-ns">' + esc(L(S1.s)) + '</div>' + P.map(function (pp) {
              return '<a href="#/videos/' + pp[0] + '" class="ct-mt-sous ' + (cle === pp[0] ? 'on' : '') + '">' + esc(pp[1]) + ' <i>' + Ec.filter(function (e) { return e.cat.cle === pp[0]; }).length + '</i></a>';
            }).join('');
          }).join('');
        }
        return h + vCatsF(Ec).map(function (k) {
          return '<a href="#/videos/c/' + c[0] + '/' + k.slug + '" class="ct-mt-sous ' + (catS === k.slug ? 'on' : '') + '">' + esc(k.nom) + ' <i>' + k.E.length + '</i></a>';
        }).join('');
      }).join('') + '</nav>';
  }
  /** Les catégories d'une collection filmée, dans l'ordre alphabétique (« Autres vidéos » en dernier). */
  function vCatsF(Ec) {
    var vus = {}, K = [];
    Ec.forEach(function (e) { if (!vus[e.cat.slug]) { vus[e.cat.slug] = { slug: e.cat.slug, nom: e.cat.nom, vide: !e.v.filme.categorie, E: [] }; K.push(vus[e.cat.slug]); } vus[e.cat.slug].E.push(e); });
    return K.sort(function (a, b) { return (a.vide - b.vide) || a.nom.localeCompare(b.nom, 'fr'); });
  }
  /** La carte d'une catégorie filmée. */
  function carteCatF(col, k) {
    var aff = (k.E.filter(function (e) { return e.v.afficheUrl; })[0] || { v: {} }).v.afficheUrl;
    return '<a class="ct-mt-cat" href="#/videos/c/' + col + '/' + k.slug + '"><span class="ct-vt-aff">' + (aff ? '<img src="' + esc(aff) + '" alt="" loading="lazy">' : '') + '</span>' +
      '<span class="ct-vt-t">' + esc(k.nom) + '</span><span class="ct-mt-n">' + k.E.length + ' ' + T('vidéo' + (k.E.length > 1 ? 's' : ''), 'video' + (k.E.length > 1 ? '’s' : '')) + '</span></a>';
  }
  /** Une collection filmée : chaque catégorie et ses vidéos (ou une seule catégorie). */
  function vColFilmee(dos, col, catS, E) {
    var K = vCatsF(E.filter(function (e) { return e.col === col; })).filter(function (k) { return !catS || k.slug === catS; });
    if (!K.length) return '<p class="ct-msg">' + T('Pas encore de vidéo dans cette collection. Elles s’ajoutent dans le portail de formation : Médiathèque › Gérer les vidéos.', 'Nog geen video in deze collectie.') + '</p>';
    return K.map(function (k) {
      return (catS ? '' : '<h3 class="ct-vt-h"><a href="#/videos/c/' + col + '/' + k.slug + '">' + esc(k.nom) + '</a> <span class="ct-chip">' + k.E.length + '</span></h3>') +
        '<div class="ct-vt-grille">' + k.E.map(function (e) { return carteV(dos, e, null, e.sec); }).join('') + '</div>';
    }).join('');
  }
  /** La collection du dashboard : les modules de formation, les nouveautés, puis chaque section et ses pages. */
  function vColDashboard(dos, E) {
    var Fo = E.filter(function (e) { return (e.v.genre || 'tuto') === 'formation'; }), Nv = E.filter(function (e) { return (e.v.genre || 'tuto') === 'nouveau'; });
    return (Fo.length ? '<h2 class="ct-mt-sec">' + T('Modules de formation', 'Opleidingsmodules') + '</h2><div class="ct-vt-grille">' + Fo.map(function (e) { return carteV(dos, e); }).join('') + '</div>' : '') +
      (Nv.length ? '<h2 class="ct-mt-sec">' + T('Nouvelles fonctions', 'Nieuwe functies') + '</h2><div class="ct-vt-grille">' + Nv.map(function (e) { return carteV(dos, e); }).join('') + '</div>' : '') +
      VSECTIONS.map(function (S1) {
        var P = S1.p.filter(function (pp) { return E.some(function (e) { return e.cat.cle === pp[0]; }); }); if (!P.length) return '';
        return '<h2 class="ct-mt-sec">' + esc(L(S1.s)) + '</h2><div class="ct-mt-cats">' + P.map(function (pp) { return carteCat(dos, pp[0], E.filter(function (e) { return e.cat.cle === pp[0]; })); }).join('') + '</div>';
      }).join('');
  }
  /** L'accueil : chaque collection, ses pages (dashboard) ou ses catégories. */
  function vAccueil(dos, E) {
    return VCOLS.map(function (c) {
      var Ec = E.filter(function (e) { return e.col === c[0]; });
      var h = '<h2 class="ct-mt-sec ct-mt-colh"><a href="#/videos/c/' + c[0] + '">' + esc(L(c[1])) + '</a> <span class="ct-chip">' + Ec.length + '</span></h2>';
      if (!Ec.length) return h + '<p class="ct-mt-note">' + T('Pas encore de vidéo.', 'Nog geen video.') + '</p>';
      if (c[0] === 'dashboard') {
        var P = []; VSECTIONS.forEach(function (S1) { S1.p.forEach(function (pp) { if (Ec.some(function (e) { return e.cat.cle === pp[0]; })) P.push(pp[0]); }); });
        // Les dix premières pages, puis le lien vers toute la collection : les autres collections restent en vue.
        return h + '<div class="ct-mt-cats">' + P.slice(0, 10).map(function (k) { return carteCat(dos, k, Ec.filter(function (e) { return e.cat.cle === k; })); }).join('') + '</div>' +
          (P.length > 10 ? '<p><a class="ct-vt-plus" href="#/videos/c/dashboard">' + T('Les ' + P.length + ' pages du dashboard →', 'De ' + P.length + ' pagina’s van het dashboard →') + '</a></p>' : '');
      }
      var K = vCatsF(Ec);
      return h + (K.length > 1 || !K[0].vide ? '<div class="ct-mt-cats">' + K.map(function (k) { return carteCatF(c[0], k); }).join('') + '</div>'
        : '<div class="ct-vt-grille">' + Ec.map(function (e) { return carteV(dos, e, null, e.sec); }).join('') + '</div>');
    }).join('');
  }
  function rendreVideotheque() {
    var V = S.videos || [], dos = S.videosDossier || dossierVideos(), r = route();
    var E = S.videos != null ? vIndex() : [], q = S.vq || '', type = S.vtype || 'tout', corps, compte = '';
    // L'adresse : #/videos/c/<collection>[/<catégorie>], #/videos/v/<id> (une vidéo), #/videos/<page du dashboard>.
    var col = '', cle = '', catS = '', vid = '';
    if (r.cle === 'c') { col = VCOLS.some(function (c) { return c[0] === r.action; }) ? r.action : ''; catS = col && col !== 'dashboard' ? (r.id || '') : ''; }
    else if (r.cle === 'v') { vid = decodeURIComponent(r.action || ''); }
    else if (r.cle) {
      var brut = decodeURIComponent(r.cle);
      // L'ancienne adresse #/videos/<id d'une vidéo de page> mène à sa page.
      var pv = V.filter(function (v) { return v.id === brut && estPageV(v); })[0];
      cle = pv ? vCat((pv.vues || [''])[0]).cle : vCat(brut).cle;
      if (vCat(cle).si >= VSECTIONS.length) cle = '';   // une clé inconnue : l'accueil
    }
    if (vid) {
      var ev = E.filter(function (x) { return x.v.id === vid; })[0];
      if (ev && ev.col === 'dashboard') { cle = ev.cat.cle; } else if (ev) { col = ev.col; catS = ev.cat.slug; }
      if (ev && S.vLu !== vid) { S.vLu = vid; setTimeout(function () { if (!document.querySelector('.ct-voile')) lireVideo(vid); }, 0); }
    } else { S.vLu = null; }
    if (cle) col = 'dashboard';
    var dash = col === 'dashboard';
    // La portée de la recherche et des puces : la page, la catégorie, la collection, ou (à l'accueil) la puce choisie.
    var colf = col || S.vcolf || '';
    var dans = function (e) { return (!colf || e.col === colf) && (!cle || e.cat.cle === cle) && (!catS || e.cat.slug === catS); };
    var ici = cle ? vCat(cle).nom : catS ? (E.filter(function (e) { return e.col === col && e.cat.slug === catS; })[0] || { cat: { nom: catS } }).cat.nom : col ? vColNom(col) : '';
    if (S.videos == null) { corps = '<p class="ct-msg">' + T('Lecture des vidéos…', 'Video’s lezen…') + '</p>'; }
    else if (!E.length) { corps = '<p class="ct-msg">' + esc(S.videosErreur ? T('Lecture impossible : ', 'Lezen onmogelijk: ') + S.videosErreur : T('Aucune vidéo pour le moment.', 'Nog geen video’s.')) + '</p>'; }
    else if (q.trim()) {
      var Rr = vChercher(q, dash ? type : 'tout'), R = Rr.liste.filter(dans), n = S.vplus ? R.length : Math.min(R.length, 48);
      compte = R.length + ' ' + T('résultat' + (R.length > 1 ? 's' : ''), 'resulta' + (R.length > 1 ? 'ten' : 'at')) + (ici ? ' · ' + esc(ici) : '');
      corps = !R.length ? '<p class="ct-msg">' + T('Aucune vidéo pour « ', 'Geen video voor « ') + esc(q) + ' ». ' + T('Essayez un autre mot, ou parcourez les collections.', 'Probeer een ander woord, of blader door de collecties.') + '</p>'
        : (Rr.proches ? '<p class="ct-mt-note">' + T('Aucune vidéo ne contient tous ces mots : voici les plus proches.', 'Geen video bevat al deze woorden: dit zijn de dichtstbijzijnde.') + '</p>' : '') +
          '<div class="ct-vt-grille">' + R.slice(0, n).map(function (e) { return carteV(dos, e, Rr.mots); }).join('') + '</div>' +
          (R.length > n ? '<p><button type="button" class="btn" data-vplus="1">' + T('Voir les ' + (R.length - n) + ' autres', 'De ' + (R.length - n) + ' andere tonen') + '</button></p>' : '');
    } else if (cle) {
      var Ef = E.filter(function (e) { return e.col === 'dashboard' && vType(e, type); });
      compte = vNb(Ef.filter(function (e) { return e.cat.cle === cle; }).length);
      corps = vCategorie(dos, cle, Ef) || '<p class="ct-msg">' + T('Aucune vidéo de ce type sur cette page.', 'Geen video van dit type op deze pagina.') + '</p>';
    } else if (dash) {
      var Ed = E.filter(function (e) { return e.col === 'dashboard' && vType(e, type); });
      compte = vNb(Ed.length);
      corps = type !== 'tout' ? vRangees(dos, Ed) : vColDashboard(dos, Ed);
    } else if (col) {
      compte = vNb(E.filter(dans).length);
      corps = vColFilmee(dos, col, catS, E);
    } else {
      compte = vNb(E.length);
      corps = vAccueil(dos, E);
    }
    // Les puces : les types de vidéo du dashboard dans sa collection ; à l'accueil, les collections (pour la recherche).
    var puces = dash ? VTYPES.map(function (t) { return '<button type="button" data-vtype="' + t[0] + '" class="' + (type === t[0] ? 'on' : '') + '">' + esc(L(t[1])) + '</button>'; }).join('')
      : !col && q.trim() ? [['', { fr: 'Toutes les collections', nl: 'Alle collecties' }]].concat(VCOLS).map(function (c) { return '<button type="button" data-vcolf="' + c[0] + '" class="' + ((S.vcolf || '') === c[0] ? 'on' : '') + '">' + esc(L(c[1])) + '</button>'; }).join('') : '';
    var titre = ici ? esc(ici) : T('Médiathèque', 'Mediatheek');
    var kicker = cle ? vColNom('dashboard') + (VSECTIONS[vCat(cle).si] ? ' · ' + L(VSECTIONS[vCat(cle).si].s) : '') : catS ? vColNom(col) : col ? T('Collection', 'Collectie') : T('Formation · Vidéos', 'Opleiding · Video’s');
    var retour = cle ? '<a class="ct-vt-plus" href="#/videos/c/dashboard">← ' + esc(vColNom('dashboard')) + '</a>' : catS ? '<a class="ct-vt-plus" href="#/videos/c/' + col + '">← ' + esc(vColNom(col)) + '</a>'
      : col ? '<a class="ct-vt-plus" href="#/videos">← ' + T('Toute la médiathèque', 'De hele mediatheek') + '</a>' : '';
    root.innerHTML = '<header class="ct-barre"><img src="../img/logo.png" alt="L’Atelier By"><div><div class="ct-titre">' + T('Médiathèque', 'Mediatheek') + '</div>' +
      '<div class="ct-sous">' + T('Catalogue · Formation', 'Catalogus · Opleiding') + '</div></div><span class="ct-esp"></span>' + segLangue() +
      '<a class="btn" href="#/">' + T('Catalogue des manuels', 'Catalogus van de handboeken') + '</a></header>' +
      '<div class="ct-corps ct-mt">' + (E.length ? vSommaire(col, cle, catS, E) : '') + '<div class="ct-mt-main">' +
      '<div class="ct-kicker">' + esc(kicker) + '</div><h1 class="ct-h1">' + titre + '</h1>' +
      (col ? '' : '<p class="ct-lead">' + T('Toutes les vidéos du réseau : l’utilisation du dashboard (chaque page, chaque onglet, chaque fonction), la production, la vente et le comptoir, l’hygiène et la sécurité, l’ouverture et la fermeture. Cherchez un geste (« créer une offre », « croissant », « TVA »), ou parcourez les collections.', 'Alle video’s van het netwerk: het gebruik van het dashboard, de productie, verkoop en toonbank, hygiëne en veiligheid, opening en sluiting. Zoek een handeling, of blader door de collecties.') + '</p>') +
      '<div class="ct-mt-cherche"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>' +
        '<input id="ct-vq" type="search" autocomplete="off" value="' + esc(q) + '" placeholder="' + (ici ? T('Chercher dans ', 'Zoeken in ') + esc(ici) + '…' : T('Chercher une vidéo : « créer une offre », « horaires », « croissant »…', 'Een video zoeken…')) + '" aria-label="' + T('Chercher une vidéo', 'Een video zoeken') + '"><kbd>/</kbd></div>' +
      (puces || compte ? '<div class="ct-mt-types" role="group">' + puces + (compte ? '<span class="ct-mt-compte">' + compte + '</span>' : '') + '</div>' : '') +
      (retour && !q.trim() ? '<p>' + retour + '</p>' : '') +
      corps + '</div></div>';
  }
  /** Le lecteur : la vidéo (ou son passage), où elle se range, et la suite dans le même onglet. */
  function lireVideo(id) {
    var E = vIndex(), e = E.filter(function (x) { return x.v.id === id; })[0]; if (!e) return;
    var v = e.v, dos = S.videosDossier || dossierVideos(), f = v.filme;
    var meme = f ? E.filter(function (x) { return x.v !== v && x.col === e.col && x.cat.slug === e.cat.slug; }) :
      e.onglet ? E.filter(function (x) { return x.v !== v && (x.v === e.onglet.id || x.onglet === e.onglet || x.v === e.onglet); }) :
      E.filter(function (x) { return x.v !== v && x.cat.cle === e.cat.cle && x.niv === 'onglet'; });
    var o = document.createElement('div');
    o.className = 'ct-voile';
    o.innerHTML = '<div class="ct-fenetre ct-vt-fen" role="dialog" aria-label="' + esc(v.titre) + '"><div class="ct-vt-fh"><div><div class="ct-kicker">' + esc(vKicker(e)) + (v.duree ? ' · ' + esc(v.duree) : '') + ' · ' + vChemin(e, f ? e.sec : '') + '</div><h3>' + esc(v.titre) + '</h3></div>' +
      '<button type="button" class="btn" data-fermer="1">' + T('Fermer', 'Sluiten') + '</button></div>' + vMedia(dos, v) +
      (v.resume ? '<p>' + esc(v.resume) + '</p>' : '') +
      (meme.length ? '<div class="ct-mt-suite"><div class="ct-kicker">' + (f ? T('Dans la même catégorie', 'In dezelfde categorie') : e.onglet ? T('Dans le même onglet', 'In hetzelfde tabblad') : T('Ses onglets', 'De tabbladen')) + '</div>' +
        meme.map(function (x) { return '<button type="button" data-video="' + esc(x.v.id) + '"><span>' + esc(L(VNIV[x.niv])) + '</span>' + esc(x.v.titre) + (x.v.duree ? '<small>' + esc(x.v.duree) + '</small>' : '') + '</button>'; }).join('') + '</div>' : '') + '</div>';
    o.addEventListener('click', function (ev) {
      if (ev.target === o || ev.target.closest('[data-fermer]')) { var vd0 = o.querySelector('video'); if (vd0) try { vd0.pause(); } catch (x) { /* */ } o.remove(); return; }
      var s = ev.target.closest('[data-video]'); if (s) { ev.stopPropagation(); var vd1 = o.querySelector('video'); if (vd1) try { vd1.pause(); } catch (x) { /* */ } o.remove(); lireVideo(s.getAttribute('data-video')); }
    });
    // Un passage (onglet ou fonction) s'arrête à sa fin, une fois.
    var vd = o.querySelector('video');
    if (vd && v.fin != null) {
      var fini = false;
      vd.addEventListener('timeupdate', function () { if (!fini && vd.currentTime >= +v.fin - 0.05) { fini = true; vd.pause(); } });
      vd.addEventListener('loadedmetadata', function () { if (v.debut != null && vd.currentTime < +v.debut) vd.currentTime = +v.debut; });
    }
    document.body.appendChild(o);
  }
  /** Ce que joue le lecteur : le MP4 du dashboard, le fichier envoyé, ou le lecteur de YouTube, Vimeo, Google Drive —
   *  construit depuis l'identifiant que l'API a reconnu, jamais depuis l'adresse brute. */
  function vMedia(dos, v) {
    var f = v.filme, l = f && f.lien, poster = v.afficheUrl || (v.affiche ? dos + v.affiche : '');
    var tag = function (src) { return '<video src="' + esc(src) + '"' + (poster ? ' poster="' + esc(poster) + '"' : '') + ' controls autoplay playsinline preload="metadata"></video>'; };
    if (!f) return tag(srcV(dos, v));
    if (f.source === 'fichier') return tag(RACINE_SITE + f.fichier);
    if (!l) return '<p class="ct-msg">' + T('Lien illisible.', 'Link onleesbaar.') + '</p>';
    if (l.type === 'fichier') return tag(f.url);
    var src = l.type === 'youtube' ? 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(l.id) + '?autoplay=1&rel=0'
      : l.type === 'vimeo' ? 'https://player.vimeo.com/video/' + l.id.split('/').map(encodeURIComponent).join('?h=') + (l.id.indexOf('/') >= 0 ? '&' : '?') + 'autoplay=1'
      : 'https://drive.google.com/file/d/' + encodeURIComponent(l.id) + '/preview';
    return '<iframe class="ct-vt-cadre" src="' + esc(src) + '" title="' + esc(v.titre) + '" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>';
  }
  // La recherche se redessine en gardant le curseur ; « / » y va, Entrée ouvre le premier résultat, Échap l'efface.
  var vMinut = null;
  document.addEventListener('input', function (e) {
    if (e.target.id !== 'ct-vq') return;
    S.vq = e.target.value; S.vplus = false;
    clearTimeout(vMinut); vMinut = setTimeout(function () {
      var pos = e.target.selectionStart; rendreVideotheque(); var i = document.getElementById('ct-vq'); if (i) { i.focus(); try { i.setSelectionRange(pos, pos); } catch (x) { /* */ } }
    }, 140);
  });
  document.addEventListener('keydown', function (e) {
    if (route().ecran !== 'videos' || document.querySelector('.ct-voile')) return;
    var dans = e.target && e.target.id === 'ct-vq';
    if (e.key === '/' && !dans && !/INPUT|TEXTAREA|SELECT/.test((e.target && e.target.tagName) || '')) { e.preventDefault(); var i = document.getElementById('ct-vq'); if (i) i.focus(); }
    else if (dans && e.key === 'Enter') { var b = root.querySelector('.ct-mt-main [data-video]'); if (b) lireVideo(b.getAttribute('data-video')); }
    else if (dans && e.key === 'Escape') { S.vq = ''; rendreVideotheque(); var i2 = document.getElementById('ct-vq'); if (i2) i2.focus(); }
  });
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-vtype]'); if (t) { S.vtype = t.getAttribute('data-vtype'); S.vplus = false; rendreVideotheque(); return; }
    var c = e.target.closest('[data-vcolf]'); if (c) { S.vcolf = c.getAttribute('data-vcolf'); S.vplus = false; rendreVideotheque(); return; }
    if (e.target.closest('[data-vplus]')) { S.vplus = true; rendreVideotheque(); }
  });

  /* ── la grille ── */
  function rendreGrille() {
    var cartes = MANUELS.map(function (m) {
      var n = nbDe(m);
      var compte = m.ref ? n + ' ' + L(m.unite) + ' · ' + nbSousCats() + ' ' + T('sous-catégories', 'subcategorieën')
        : m.guide ? n + ' ' + L(m.unite) + ' · ' + guideSections().length + ' ' + T('sections', 'delen')
        : m.fiches ? (S.ft ? n + ' ' + L(m.unite) + ' · ' + ftCategories().length + ' ' + T('catégories', 'categorieën') : T('Une fiche par produit', 'Eén fiche per product'))
        : n + ' ' + L(m.unite);
      return '<div class="ct-carte">' +
        '<a class="ct-couv" href="#/m/' + m.cle + '" style="background:' + m.couleur + ';color:' + m.encre + ';text-decoration:none">' +
          '<div class="n">' + T('Manuel', 'Handboek') + '</div>' + illustration(m.cle) +
          '<div class="bas"><div class="t">' + esc(L(m.titre)) + '</div><div class="s">' + esc(L(m.sous)) + '</div></div></a>' +
        '<h3>' + esc(L(m.titre)) + '</h3>' +
        '<p><span class="ct-chip">' + esc(compte) + '</span></p>' +
        '<div class="ct-actions">' +
          '<a class="btn btn--primary" href="#/m/' + m.cle + '">' + T('Feuilleter', 'Bladeren') + '</a>' +
          '<a class="btn" href="#/m/' + m.cle + '/imprimer" title="' + esc(T('Imprimer ou enregistrer en PDF', 'Afdrukken of opslaan als pdf')) + '">PDF</a>' +
          '<button type="button" class="btn" data-qr="' + m.cle + '">QR</button>' +
        '</div></div>';
    }).join('');
    var depliant = '<div class="ct-carte ct-carte-dep">' + illustration('depliant', 'ill-dep') +
      '<h3>' + T('Dépliant : comment lire les manuels', 'Folder: hoe de handboeken lezen') + '</h3>' +
      '<p>' + T('Huit pages : la franchise, puis une page par manuel avec son QR code. À imprimer et à remettre à l’équipe.', 'Acht pagina’s: de franchise, dan één pagina per handboek met zijn QR-code. Om af te drukken en aan het team te geven.') + '</p>' +
      '<div class="ct-actions"><a class="btn" href="depliant.html">' + T('Ouvrir', 'Openen') + '</a><a class="btn btn--primary" href="depliant.html#imprimer">PDF</a></div></div>';
    root.innerHTML = '<header class="ct-barre"><img src="../img/logo.png" alt="L’Atelier By"><div><div class="ct-titre">' + T('Catalogue des manuels', 'Catalogus van de handboeken') + '</div>' +
      '<div class="ct-sous">' + T('Formation · Documents', 'Opleiding · Documenten') + '</div></div><span class="ct-esp"></span>' + segLangue() +
      '<a class="btn" href="../">' + T('Module Formation', 'Opleidingsmodule') + '</a></header>' +
      '<div class="ct-corps"><div class="ct-kicker">' + T('Formation · Documents', 'Opleiding · Documenten') + '</div>' +
      '<h1 class="ct-h1">' + T('Catalogue des manuels', 'Catalogus van de handboeken') + '</h1>' +
      '<p class="ct-lead">' + T('Feuilletez chaque manuel comme un catalogue, ou enregistrez-le en PDF pour l’imprimer. Chaque manuel a son QR code : le scanner ouvre ce manuel, à la même page.', 'Blader door elk handboek als door een catalogus, of sla het op als pdf om af te drukken. Elk handboek heeft zijn QR-code: wie hem scant, opent dat handboek.') + '</p>' +
      (S.erreur ? '<p class="ct-msg">' + esc(T('Lecture impossible : ', 'Lezen onmogelijk: ') + S.erreur) + '</p>' : '') +
      '<div class="ct-grille">' + cartes + carteVideotheque() + depliant + '</div></div>';
  }

  /* ── la fenêtre du QR code ── */
  function fenetreQr(cle) {
    var m = cle === 'videos' ? { titre: { fr: 'Médiathèque', nl: 'Mediatheek' } } : manuelDe(cle); if (!m) return;
    var url = cle === 'videos' ? base() + '#/videos' : lienManuel(cle);
    var v = document.createElement('div');
    v.className = 'ct-voile';
    v.innerHTML = '<div class="ct-fenetre" role="dialog" aria-label="QR code"><h3 style="margin:0 0 10px">' + esc(L(m.titre)) + '</h3>' +
      '<div class="qr">' + qrSvg(url, 6) + '</div><div class="url">' + esc(url) + '</div>' +
      '<div class="ct-actions"><button type="button" class="btn" data-copier="' + esc(url) + '">' + T('Copier le lien', 'Link kopiëren') + '</button>' +
      '<button type="button" class="btn btn--primary" data-fermer="1">' + T('Fermer', 'Sluiten') + '</button></div></div>';
    v.addEventListener('click', function (e) {
      if (e.target === v || e.target.closest('[data-fermer]')) { v.remove(); return; }
      var c = e.target.closest('[data-copier]');
      if (c && navigator.clipboard) { navigator.clipboard.writeText(c.getAttribute('data-copier')).then(function () { c.textContent = T('Lien copié', 'Link gekopieerd'); }); }
    });
    document.body.appendChild(v);
  }

  /* ── le chargement d'un manuel ── */
  function rendreChargement(m, fait, total) {
    root.innerHTML = '<header class="ct-barre"><a class="ct-retour" href="#/">← ' + T('Catalogue', 'Catalogus') + '</a><span class="ct-titre">' + esc(L(m.titre)) + '</span></header>' +
      '<div class="ct-corps"><p class="ct-msg" id="ct-charge">' + T('Lecture du manuel', 'Het handboek wordt gelezen') + (total ? ' : ' + fait + ' / ' + total : '…') + '</p></div>';
  }
  function majChargement(m, fait, total) {
    var e = document.getElementById('ct-charge');
    if (e) e.textContent = T('Lecture du manuel : ', 'Het handboek wordt gelezen: ') + fait + ' / ' + total;
  }
  /**
   * Les postes du manuel du réseau (19/11/2026, Sam : « dans le manuel du réseau, rajouter les postes, les formations et les
   * compétences par poste ») : le référentiel v25 (/formation/v25 : postes P1–P12, formations et ce qu'elles attendent de
   * chaque poste, compétences clés), les niveaux et compétences de Franchise Buddy (/formation/referentiel) et les SOP de
   * chaque compétence (/formation/sop/competences). Une lecture qui échoue laisse la partie dire « illisible ».
   */
  function chargerPostes() {
    if (S.postesLus) return Promise.resolve();
    return Promise.all([
      api('/formation/v25').then(function (j) { S.v25 = j; }, function () { S.v25 = null; }),
      api('/formation/referentiel').then(function (j) { S.panel = j; }, function () { S.panel = null; }),
      api('/formation/sop/competences').then(function (j) { S.compSop = j; }, function () { S.compSop = null; }),
    ]).then(function () { S.postesLus = !!S.v25; });
  }
  function charger(m) {
    if (m.ref) return chargerPostes();
    if (m.guide) return Promise.resolve();   // le guide arrive avec /formation/ref, lu au départ
    if (m.fiches) return chargerFiches(m);
    var docs = docsDe(m);
    var manquants = docs.filter(function (d) { return !S.fiches[d.id]; });
    var total = docs.length, fait = total - manquants.length, k = 0, actifs = 0;
    return new Promise(function (ok) {
      function suivant() {
        if (k >= manquants.length && actifs === 0) { ok(); return; }
        while (actifs < CONCURRENCE && k < manquants.length) {
          (function (d) {
            actifs++;
            api('/formation/sop/fiche/' + encodeURIComponent(d.id))
              .then(function (f) { S.fiches[d.id] = f; }, function () { S.fiches[d.id] = { erreur: true }; })
              .then(function () { actifs--; fait++; majChargement(m, fait, total); suivant(); });
          })(manquants[k++]);
        }
      }
      suivant();
    });
  }

  /* ── le manuel des fiches techniques ── */
  /** Les fiches, lues par lots au panel (la réponse dit combien il en manque) : on rappelle jusqu'à les avoir toutes. */
  function chargerFiches(m) {
    if (S.ft && !S.ft.manquent) return Promise.resolve();
    var essais = 0;
    function lire() {
      return api('/formation/ref/fiches').then(function (j) {
        S.ft = j; essais++;
        majChargement(m, (j.total || 0) - (j.manquent || 0), j.total || 0);
        if (j.manquent > 0 && essais < 15) return lire();
      });
    }
    return lire();
  }
  var GAMMES = { standard: { fr: 'Standard', nl: 'Standaard' }, saison: { fr: 'Saison', nl: 'Seizoen' }, aucune: { fr: 'Hors gamme', nl: 'Buiten gamma' } };
  var MANQUES = { fiche: { fr: 'fiche', nl: 'fiche' }, recette: { fr: 'recette', nl: 'recept' }, etapes: { fr: 'étapes de préparation', nl: 'bereidingsstappen' },
    photo: { fr: 'photo', nl: 'foto' }, allergenes: { fr: 'allergènes', nl: 'allergenen' } };
  function ftProduits() { return (S.ft && S.ft.produits) || []; }
  /** Les catégories, dans l'ordre du serveur (groupe, catégorie ; standard, saison, hors gamme ; nom). */
  function ftCategories() {
    var out = [], idx = {};
    ftProduits().forEach(function (p) {
      var k = p.groupe + '|' + p.categorie;
      if (!idx[k]) { idx[k] = { groupe: p.groupe, categorie: p.categorie, produits: [] }; out.push(idx[k]); }
      idx[k].produits.push(p);
    });
    return out;
  }
  function ftManques(p) {
    var f = p.fiche;
    if (!f) return ['fiche'];
    var l = [];
    if (!(f.ingredients || []).length) l.push('recette');
    if (!(f.etapes || []).length) l.push('etapes');
    if (!f.photo) l.push('photo');
    if (!(f.allergenes || []).length) l.push('allergenes');
    return l;
  }
  function nombre(x) { return x == null ? '' : String(Math.round(x * 100) / 100).replace('.', S.langue === 'nl' ? ',' : ','); }
  function dureeVie(min) {
    if (!min) return '';
    var h = min / 60;
    return h >= 48 ? Math.round(h / 24) + ' ' + T('jours', 'dagen') : Math.round(h) + ' h';
  }
  /** Les photos des pages ne se chargent qu'affichées ou imprimées : l'adresse du panel est redemandée à chaque fois. */
  function photosDe(el) {
    el.querySelectorAll('img[data-src]').forEach(function (im) {
      im.onerror = function () { var b = im.parentNode; if (b) b.classList.add('vide'); };
      im.src = im.getAttribute('data-src'); im.removeAttribute('data-src');
    });
  }
  function blocsFichesIntro() {
    var ps = ftProduits(), n = { standard: 0, saison: 0, aucune: 0 }, k = { fiche: 0, recette: 0, etapes: 0, photo: 0, allergenes: 0 };
    ps.forEach(function (p) { n[p.gamme] = (n[p.gamme] || 0) + 1; ftManques(p).forEach(function (x) { k[x]++; }); });
    var B = [{ neuve: true, html: '<h2>' + T('Lire une fiche technique', 'Een technische fiche lezen') + '</h2>' +
      '<p>' + T('Une fiche par produit, catégorie par catégorie : d’abord la gamme standard, puis les produits de saison, puis ceux hors gamme. Chaque fiche donne la conservation, la durée de vie, les allergènes, la recette (ingrédients et quantités), les étapes de préparation, l’emballage et les compétences demandées.',
        'Eén fiche per product, categorie per categorie: eerst het standaardgamma, dan de seizoensproducten, dan die buiten gamma. Elke fiche geeft de bewaring, de houdbaarheid, de allergenen, het recept (ingrediënten en hoeveelheden), de bereidingsstappen, de verpakking en de gevraagde competenties.') + '</p>' }];
    B.push({ html: '<div class="ft-stats">' + ['standard', 'saison', 'aucune'].map(function (g) {
      return '<div><b>' + (n[g] || 0) + '</b><small>' + esc(L(GAMMES[g])) + '</small></div>'; }).join('') + '</div>' });
    B.push({ html: '<h3>' + T('Ce qu’il reste à compléter dans TF Buddy', 'Wat nog aan te vullen is in TF Buddy') + '</h3>' +
      '<p>' + T('Les données viennent des fiches techniques du panel TF Buddy, comme dans l’application cuisine. Une case vide se complète dans TF Buddy, pas ici : la fiche suit au prochain passage.',
        'De gegevens komen uit de technische fiches van het TF Buddy-panel, zoals in de keukenapp. Een leeg vak vult men aan in TF Buddy, niet hier: de fiche volgt bij de volgende lezing.') + '</p>' });
    ['recette', 'etapes', 'photo', 'allergenes'].forEach(function (x) {
      B.push({ html: '<div class="ft-i"><span>' + T('Sans ', 'Zonder ') + esc(L(MANQUES[x])) + '</span><b>' + k[x] + ' / ' + ps.length + '</b></div>' });
    });
    if (k.fiche) B.push({ html: '<div class="ft-i"><span>' + T('Fiche illisible au panel', 'Fiche onleesbaar in het panel') + '</span><b>' + k.fiche + '</b></div>' });
    return B;
  }
  function blocsFicheProduit(p) {
    var f = p.fiche || {}, B = [];
    var vide = '<span class="ft-vide">' + T('à compléter dans TF Buddy', 'aan te vullen in TF Buddy') + '</span>';
    var chips = '<span class="ft-g ft-g-' + esc(p.gamme) + '">' + esc(L(GAMMES[p.gamme] || GAMMES.aucune)) + '</span>' +
      (p.saisons || []).map(function (s) { return '<span class="ft-s">' + esc(s) + '</span>'; }).join('') +
      (f.vegetarien ? '<span class="ft-s">' + T('Végétarien', 'Vegetarisch') + '</span>' : '');
    var faits = [];
    if (f.temperature != null) faits.push([T('Conservation', 'Bewaring'), nombre(f.temperature) + ' °C']);
    var vie = dureeVie(f.vieMinutes) || (p.dlvHeures ? p.dlvHeures + ' h' : '');
    if (vie) faits.push([T('Durée de vie', 'Houdbaarheid'), vie]);
    if (f.rechauffe) faits.push([T('Remise en température', 'Opwarmen'), [f.rechauffe.minutes ? nombre(f.rechauffe.minutes) + ' min' : '', f.rechauffe.degres ? nombre(f.rechauffe.degres) + ' °C' : ''].filter(Boolean).join(' · ')]);
    if (f.delaiHeures) faits.push([T('Commander', 'Bestellen'), nombre(f.delaiHeures) + ' h ' + T('avant', 'vooraf')]);
    if (f.avantVente) faits.push([T('Préparation', 'Bereiding'), T('avant la vente', 'vóór de verkoop')]);
    if (p.prix) faits.push([T('Prix de vente', 'Verkoopprijs'), nombre(p.prix) + ' €']);
    if (f.rendement && f.rendement !== 1) faits.push([T('Rendement', 'Opbrengst'), nombre(f.rendement)]);
    B.push({ neuve: true, html: '<div class="doc-code">' + esc(p.ref || String(p.id)) + ' · ' + esc(p.categorie) + '</div><h2>' + esc(p.nom) + '</h2>' +
      '<div class="ft-chips">' + chips + '</div>' +
      (f.photo ? '<div class="ft-photo"><img data-src="' + esc(API + '/formation/ref/fiches/photo/' + p.id) + '" alt=""></div>' : '') +
      (faits.length ? '<div class="ft-faits">' + faits.map(function (x) { return '<div><small>' + esc(x[0]) + '</small><b>' + esc(x[1]) + '</b></div>'; }).join('') + '</div>' : '') +
      '<div class="ft-al"><b>' + T('Allergènes', 'Allergenen') + ' :</b> ' + ((f.allergenes || []).length ? esc(f.allergenes.join(', ')) : vide) + '</div>' });
    if (!p.fiche) { B.push({ html: '<p class="ft-vide">' + T('Fiche illisible au panel pour l’instant.', 'Fiche nu niet leesbaar in het panel.') + '</p>' }); return B; }
    var ing = (f.ingredients || []).filter(function (i) { return !i.emballage; });
    var ligne = function (i) { return '<div class="ft-i"><span>' + esc(i.nom) + (i.sousRecette ? ' <small>(' + T('préparation', 'bereiding') + ')</small>' : '') + '</span><b>' + (i.qte != null ? nombre(i.qte) + ' ' + esc(i.unite) : '') + '</b></div>'; };
    var tRec = '<h3>' + T('La recette', 'Het recept') + (f.rendement ? ' <small>· ' + T('pour ', 'voor ') + nombre(f.rendement) + '</small>' : '') + '</h3>';
    if (ing.length) { B.push({ html: tRec + ligne(ing[0]) }); ing.slice(1).forEach(function (i) { B.push({ html: ligne(i) }); }); }
    else B.push({ html: tRec + '<p>' + vide + '</p>' });
    var et = f.etapes || [];
    var tEt = '<h3>' + T('La préparation', 'De bereiding') + '</h3>';
    var etape = function (t, k) { return '<div class="ft-e"><span class="ft-n">' + (k + 1) + '</span><p>' + lier(t) + '</p></div>'; };
    if (et.length) { B.push({ html: tEt + etape(et[0], 0) }); et.slice(1).forEach(function (t, k) { B.push({ html: etape(t, k + 1) }); }); }
    else B.push({ html: tEt + '<p>' + vide + '</p>' });
    var emb = (f.emballage ? [f.emballage.nom].concat(f.emballage.materiaux || []) : []).concat((f.ingredients || []).filter(function (i) { return i.emballage; }).map(function (i) { return i.nom; }));
    B.push({ html: '<h3>' + T('L’emballage', 'De verpakking') + '</h3><p>' + (emb.length ? esc(emb.join(' · ')) : vide) + '</p>' });
    if ((f.competences || []).length) B.push({ html: '<p class="ft-comp"><b>' + T('Compétences', 'Competenties') + ' :</b> ' + esc(f.competences.join(', ')) + '</p>' });
    // Le contrôle qualité (26/11/2026, Sam : « ce qui est regardé, ce qui est bon, ce qui est pas bon ») : les grilles
    // d'autocontrôle du cockpit (aqStandardProduit, consultant/src/autocontrole.php), un critère par ligne, par étape.
    var q = p.qualite;
    if (q && (q.etapes || []).length) {
      var ETQ = { cuisson: ['Cuisson', 'Bakken'], structure: ['Structure', 'Structuur'], organoleptique: ['Organoleptique', 'Organoleptisch'], presentation: ['Présentation', 'Presentatie'] };
      var puce = function (cls, t) { return '<span class="ft-q-' + cls + '">' + esc(t) + '</span>'; };
      var tQ = '<h3>' + T('Le contrôle qualité', 'De kwaliteitscontrole') + ' <small>· ' + esc(q.grille) + '</small></h3>' +
        '<p class="ft-q-leg">' + puce('ok', T('bon', 'goed')) + puce('mi', T('à surveiller', 'op te volgen')) + puce('ko', T('pas bon', 'niet goed')) +
        (q.aValider ? ' <small>' + T('grille d’exemple, à valider par la production', 'voorbeeldraster, door de productie te valideren') + '</small>' : '') + '</p>';
      var premier = true;
      q.etapes.forEach(function (e) {
        var et = ETQ[e.etape] ? T(ETQ[e.etape][0], ETQ[e.etape][1]) : e.titre;
        e.criteres.forEach(function (c, k) {
          var html = (k === 0 ? '<div class="ft-q-et">' + esc(et) + (e.photo === 'obligatoire' ? ' <small>· ' + T('photo obligatoire', 'foto verplicht') + '</small>' : '') + '</div>' : '') +
            '<div class="ft-q"><span>' + esc(c.libelle) + '</span><span>' + c.bon.map(function (t) { return puce('ok', t); }).join('') +
            c.surveiller.map(function (t) { return puce('mi', t); }).join('') + c.pasBon.map(function (t) { return puce('ko', t); }).join('') + '</span></div>';
          B.push({ html: (premier ? tQ : '') + html });
          premier = false;
        });
      });
    }
    var mq = ftManques(p);
    if (mq.length) B.push({ html: '<p class="ft-manque">' + T('À compléter dans TF Buddy : ', 'Aan te vullen in TF Buddy: ') + esc(mq.map(function (x) { return L(MANQUES[x]); }).join(', ')) + '.</p>' });
    return B;
  }

  /* ── la mise en pages (A5) ── */
  function creerMesure() {
    var d = document.createElement('div');
    d.setAttribute('aria-hidden', 'true');
    d.style.cssText = 'position:absolute;left:-20000px;top:0;visibility:hidden;';
    document.body.appendChild(d);
    return d;
  }
  function pageVide(m) {
    var p = document.createElement('section');
    p.className = 'pg';
    p.innerHTML = '<div class="pg-tete"><img src="../img/logo.png" alt=""><span>' + esc(L(m.titre)) + '</span></div>' +
      '<div class="pg-corps"></div><div class="pg-pied"><span>L’Atelier By · ' + esc(L(m.titre)) + '</span><span class="pg-n"></span></div>';
    return p;
  }
  function couverture(m) {
    var p = document.createElement('section');
    p.className = 'pg couv';
    var n = nbDe(m);
    var detail = m.fiches ? ftCategories().length + ' ' + T('catégories', 'categorieën') : m.guide ? guideSections().length + ' ' + T('sections', 'delen')
      : m.ref ? nbSousCats() + ' ' + T('sous-catégories', 'subcategorieën') + (postesV25().length ? ' · ' + postesV25().length + ' ' + T('postes', 'functies') : '') : T('Édition du ', 'Uitgave van ') + aujourdhui();
    p.innerHTML = '<div class="logo"><img src="../img/logo.png" alt="L’Atelier By"></div>' +
      '<div class="haut"><div class="gros">' + n + '</div><p>' + esc(L(m.unite)) + ' · ' + esc(detail) + '</p></div>' +
      '<div class="bande" style="background:' + m.couleur + ';color:' + m.encre + '">' + illustration(m.cle, 'ill-couv') + '<div class="k">' + T('Manuel du réseau L’Atelier By', 'Netwerkhandboek L’Atelier By') + '</div>' +
      '<div class="t">' + esc(L(m.titre)) + '</div><div class="s">' + esc(L(m.sous)) + '</div>' +
      '<div class="ed">' + T('Édition du ', 'Uitgave van ') + esc(aujourdhui()) + '</div>' +
      '<div class="qr">' + qrSvg(lienManuel(m.cle), 4) + '</div></div>';
    return p;
  }
  /** Les blocs d'un document : [{html, neuve}] ; un titre part avec sa première ligne. */
  function blocsDocument(d, f) {
    var c = (f && f.contenu) || {};
    var B = [];
    var av = d.statut === 'a_valider' ? '<span class="chip-av">' + T('à valider', 'te valideren') + '</span>' : '';
    B.push({ neuve: true, html: '<div class="doc-code">' + (d.type === 'Checklist' ? codeCk(d.code) : esc(d.code)) + ' · ' + esc(d.type) + av + '</div><h2>' + esc(L(c.titre) || L(d.titre)) + '</h2>' +
      (L(c.objectif) ? '<div class="doc-obj">' + lier(L(c.objectif)) + '</div>' : '') +
      (L(c.pour) ? '<div class="doc-pour">' + lier(L(c.pour)) + '</div>' : '') });
    if (!f || f.erreur) { B.push({ html: '<p>' + T('Fiche illisible pour l’instant.', 'Fiche nu niet leesbaar.') + '</p>' }); return B; }
    (c.sections || []).forEach(function (s) {
      var tete = L(s.titre) ? '<h3>' + esc(L(s.titre)) + '</h3>' : '';
      (s.etapes || []).forEach(function (e) {
        var lignes = String(L(e.texte) || '').split('\n').filter(function (x) { return x.trim() !== ''; });
        var cls = 'ligne' + (e.critique ? ' crit' : '');
        var titre = (L(e.titre) ? '<h4>' + esc(L(e.titre)) + '</h4>' : '') + (e.critique ? '<div class="crit-et">' + T('Point critique', 'Kritiek punt') + '</div>' : '');
        var premiere = lignes.length ? '<p class="' + cls + '">' + lier(lignes[0]) + '</p>' : '';
        B.push({ html: tete + titre + premiere });
        tete = '';
        lignes.slice(1).forEach(function (x) { B.push({ html: '<p class="' + cls + '">' + lier(x) + '</p>' }); });
        if (e.photo) B.push({ html: '<div class="photo"><img src="' + esc(photoUrl(e.photo)) + '" alt="" loading="eager"></div>' });
      });
    });
    var pcs = (c.points_critiques || []).map(L).filter(Boolean);
    if (pcs.length) {
      B.push({ html: '<h3>' + T('Points critiques', 'Kritieke punten') + '</h3><div class="pc">' + lier(pcs[0]) + '</div>' });
      pcs.slice(1).forEach(function (x) { B.push({ html: '<div class="pc">' + lier(x) + '</div>' }); });
    }
    var nq = (c.quiz || []).length;
    if (nq) B.push({ html: '<p style="color:var(--muted,#7a736a);font-size:8pt;margin-top:2mm">' + T('Quiz : ', 'Quiz: ') + nq + ' ' + T('questions, sur la tablette du magasin.', 'vragen, op de tablet van de winkel.') + '</p>' });
    return B;
  }
  function blocsDomaine(dom) {
    var B = [{ neuve: true, html: '<div class="doc-code">' + esc(dom.code) + '</div><h2>' + esc(L(dom)) + '</h2>' }];
    var st = (S.ref && S.ref.standards) || [];
    (dom.souscats || []).forEach(function (sc) {
      var liste = st.filter(function (x) { return x.souscat === sc.cle; });
      B.push({ html: '<div class="sc"><b>' + esc(sc.cle) + '</b> · ' + esc(L(sc)) +
        (sc.description ? '<small>' + lier(sc.description) + '</small>' : '') +
        (liste.length ? '<ul>' + liste.map(function (x) { var d = S.parId[x.id]; var t = esc(x.code) + ' · ' + esc(L(x)); return '<li>' + (d ? '<a class="lien-doc" href="' + esc(lienDoc(d)) + '">' + t + '</a>' : t) + '</li>'; }).join('') + '</ul>'
          : '<small>' + T('Aucun standard rangé ici pour l’instant.', 'Hier is nog geen standaard ingedeeld.') + '</small>') + '</div>' });
    });
    return B;
  }
  /**
   * Le mode d'emploi du code des checklists (19/11/2026, Sam : « mettre l'explication, avec les couleurs, de comment est
   * structurée la numérotation des checklists ») : la page qui suit la couverture du manuel des checklists. Même lecture que
   * « Comment c'est organisé » du dashboard (onglet Tâches), mêmes couleurs.
   */
  function blocsCodeChecklist(m) {
    var docs = docsDe(m);
    var ex = docs[0] || { code: '1.1.01', titre: { fr: 'Ouverture de la boutique', nl: 'Opening van de winkel' } };
    var p = String(ex.code).split('.'), z = ZONES[p[0]] || [{ fr: '', nl: '' }, GRIS];
    function lecture(code) {
      var q = String(code).split('.'), zz = ZONES[q[0]];
      return (zz ? L(zz[0]) + ' (' + q[0] + ')' : '') + ' · ' + L(MOMENTS[q[1]] || '') + ' (' + q[1] + ') · ' + T('n° ', 'nr. ') + q[2];
    }
    var B = [];
    B.push({ neuve: true, html: '<div class="doc-code">' + T('Mode d’emploi', 'Handleiding') + '</div><h2>' + T('Lire le code d’une checklist', 'De code van een checklist lezen') + '</h2>' +
      '<div class="doc-obj">' + T('Une checklist est une liste de tâches à faire à un moment de la journée. Chaque checklist a un code en trois parties : qui la fait, quand, et son numéro.',
        'Een checklist is een lijst taken op een moment van de dag. Elke checklist heeft een code in drie delen: wie, wanneer, en het nummer.') + '</div>' });
    B.push({ html: '<div class="ck-ex"><div class="ck-gros"><i style="background:' + z[1] + '1f;color:' + z[1] + '">' + esc(p[0]) + '</i>.<i class="mo">' + esc(p[1]) + '</i>.<i class="nu">' + esc(p[2]) + '</i></div>' +
      '<div class="ck-ex-t"><b>' + esc(ex.code) + ' · ' + esc(L(ex.titre)) + '</b><small>' + esc(lecture(ex.code)) + '</small></div></div>' });
    var qui = Object.keys(ZONES).map(function (k) { return '<div><span class="ck-c" style="background:' + ZONES[k][1] + '">' + k + '</span> ' + esc(L(ZONES[k][0])) + '</div>'; }).join('') +
      '<div><span class="ck-c" style="background:' + GRIS + '">0</span> ' + T('SOP et articles', 'SOP en artikels') + '</div>';
    var quand = Object.keys(MOMENTS).map(function (k) { return '<div><span class="ck-c mo">.' + k + '</span> ' + esc(L(MOMENTS[k])) + '</div>'; }).join('');
    var num = '<div><span class="ck-c nu">.01</span> ' + T('la première', 'de eerste') + '</div><div><span class="ck-c nu">.02</span> ' + T('la deuxième…', 'de tweede…') + '</div>' +
      '<p>' + T('L’ordre des checklists d’une même zone, au même moment. Les mêmes codes dans tout le réseau : chaque magasin fait les mêmes checklists, aux mêmes heures.',
        'De volgorde van de checklists van eenzelfde zone, op hetzelfde moment. Dezelfde codes in het hele netwerk: elke winkel doet dezelfde checklists, op dezelfde uren.') + '</p>';
    B.push({ html: '<div class="ck-leg"><div><h4>' + T('1er chiffre · qui', '1e cijfer · wie') + '</h4>' + qui + '</div>' +
      '<div><h4>' + T('2e chiffre · quand', '2e cijfer · wanneer') + '</h4>' + quand + '</div>' +
      '<div><h4>' + T('Le numéro', 'Het nummer') + '</h4>' + num + '</div></div>' });
    // D'autres exemples : une checklist par zone présente dans le manuel.
    var vus = {}, autres = docs.filter(function (d) { var k = String(d.code).charAt(0); if (vus[k] || d === ex) return false; vus[k] = 1; return k !== p[0]; }).slice(0, 4);
    if (autres.length) {
      B.push({ html: '<h3>' + T('D’autres exemples', 'Andere voorbeelden') + '</h3>' });
      autres.forEach(function (d) { B.push({ html: '<div class="som"><span class="c">' + codeCk(d.code) + '</span><span class="t"><a class="lien-som" href="' + esc(lienDoc(d)) + '">' + esc(L(d.titre)) + '</a> <small class="ck-lu">· ' + esc(lecture(d.code)) + '</small></span></div>' }); });
    }
    B.push({ html: '<h3>' + T('Quand une tâche compte-t-elle comme faite ?', 'Wanneer telt een taak als gedaan?') + '</h3><p class="ligne ck-regle"><b>' + T('Obligatoire', 'Verplicht') + '</b> · ' +
      T('elle doit être faite chaque jour où elle revient ; les pourcentages du dashboard ne comptent qu’elles.', 'ze moet elke dag gedaan worden waarop ze terugkomt; de percentages van het dashboard tellen enkel deze.') + '</p>' });
    B.push({ html: '<p class="ligne ck-regle"><b>' + T('Photo', 'Foto') + '</b> · ' + T('une tâche qui demande une photo n’est faite que si la photo est rendue : cochée sans photo, elle ne compte pas.', 'een taak met foto is pas gedaan als de foto er is: aangevinkt zonder foto telt ze niet.') + '</p>' });
    B.push({ html: '<p class="ligne ck-regle"><b>' + T('Contrôle qualité', 'Kwaliteitscontrole') + '</b> · ' + T('chaque photo rendue est notée de 1 à 5 par le contrôleur ; sous 4, c’est une non-conformité, à corriger.', 'elke foto krijgt van de controleur een score van 1 tot 5; onder 4 is het een non-conformiteit, te corrigeren.') + '</p>' });
    return B;
  }

  /* ── les postes du manuel du réseau ── */
  function postesV25() { return (S.v25 && S.v25.postes) || []; }
  /** Les postes d'une compétence du référentiel : « P3 Vente », « P4-P5 », « P3 niv3 / P4-P5 », « Toutes »… */
  function codesPostes(t) {
    t = String(t || ''); var tous = postesV25().map(function (p) { return p.code; });
    if (/toutes|tous/i.test(t)) return tous;
    var out = [];
    t.replace(/P(\d+)\s*[-–]\s*P(\d+)/g, function (m0, a, b) { for (var i = +a; i <= +b; i++) out.push('P' + i); return m0; });
    t.replace(/P(\d+)/g, function (m0, a) { out.push('P' + a); return m0; });
    return out.filter(function (c, i) { return out.indexOf(c) === i && tous.indexOf(c) >= 0; });
  }
  /** L'article du manuel opératoire d'une formation : art-fo-NN, sinon le même titre (FO-31 → 0.0.20). */
  function articleDeFo(fo) {
    var d = S.parId['art-fo-' + String(fo.code || '').slice(3)];
    if (d) return d;
    var n = norm(fo.nom);
    return ((S.liste && S.liste.sops) || []).filter(function (x) { return x.type === 'Article' && norm(x.titre && x.titre.fr || x.titre) === n; })[0] || null;
  }
  /** Les niveaux Franchise Buddy rattachés à un poste du référentiel (niveauxV25 : niveau du panel → P1…P12). */
  function niveauxPanel(code) {
    var nv = (S.v25 && S.v25.niveauxV25) || {}, out = [];
    ((S.panel && S.panel.postes) || []).forEach(function (p) { (p.niveaux || []).forEach(function (n) { if (nv[String(n.id)] === code) out.push({ poste: p, niveau: n }); }); });
    return out.sort(function (a, b) { return (a.poste.id - b.poste.id) || ((a.niveau.ordre || 0) - (b.niveau.ordre || 0)); });
  }
  function blocsPostesIntro() {
    var n = postesV25().length;
    return [{ neuve: true, html: '<div class="doc-code">' + T('Les postes', 'De functies') + '</div><h2>' + T('Les postes : formations et compétences', 'De functies: opleidingen en competenties') + '</h2>' +
      '<div class="doc-obj">' + T(n + ' postes, de l’opérateur au franchisé multi-boutique. Pour chacun : ce qu’il fait, comment y accéder, les formations qu’il suit et les compétences qu’il doit tenir.',
        n + ' functies, van operator tot multi-winkel franchisenemer. Voor elk: wat hij doet, hoe je er komt, de opleidingen en de competenties.') + '</div>' },
      { html: '<p class="ligne"><b>' + T('Formations', 'Opleidingen') + '</b> · ' + T('celles du référentiel du réseau (FO-01 à FO-' + (((S.v25 && S.v25.formations) || []).length) + ') : obligatoires ou recommandées pour le poste. Quand un article du manuel opératoire la porte, son code renvoie à l’article.',
        'die van het netwerkreferentieel: verplicht of aanbevolen voor de functie. Als een artikel van het operationeel handboek ze draagt, verwijst de code naar het artikel.') + '</p>' },
      { html: '<p class="ligne"><b>' + T('Compétences clés', 'Kerncompetenties') + '</b> · ' + T('les compétences du référentiel qui visent ce poste, avec leur formation.', 'de competenties van het referentieel voor deze functie, met hun opleiding.') + '</p>' },
      { html: '<p class="ligne"><b>' + T('Niveau par niveau', 'Niveau per niveau') + '</b> · ' + T('les niveaux du poste dans Franchise Buddy et les compétences à valider à chacun ; une compétence soulignée a sa SOP (le lien l’ouvre), « formateur » se valide par le formateur.',
        'de niveaus van de functie in Franchise Buddy en de te valideren competenties; een onderstreepte competentie heeft haar SOP, « opleider » wordt door de opleider gevalideerd.') + '</p>' },
      { html: '<div class="pc">' + T('Source : le référentiel v25 du réseau et Franchise Buddy, lus au moment où le manuel s’ouvre.', 'Bron: het referentieel v25 van het netwerk en Franchise Buddy, gelezen bij het openen.') + '</div>' }];
  }
  function blocsPoste(p) {
    var V = S.v25 || {}, B = [];
    var nivs = niveauxPanel(p.code), pp = nivs.length ? nivs[0].poste : null;
    var nomPanel = (V.nomsPanel || {})[p.code];
    B.push({ neuve: true, html: '<div class="doc-code">' + esc(p.code) + ' · ' + T('Poste', 'Functie') + '</div><h2>' + esc(p.nom) + '</h2>' +
      (pp && pp.description ? '<div class="doc-obj">' + lier(String(pp.description).trim()) + '</div>' : '') +
      (nomPanel && norm(nomPanel) !== norm(p.nom) ? '<div class="doc-pour">' + T('Dans Franchise Buddy : ', 'In Franchise Buddy: ') + esc(nomPanel) + '</div>' : '') });
    // Pour y accéder (les critères d'admission du cockpit)
    var adm = ((V.admission || {})[p.code] || {}).criteres || [];
    adm.forEach(function (c, i) { B.push({ html: (i ? '' : '<h3>' + T('Pour y accéder', 'Toegang') + '</h3>') + '<p class="ligne">' + esc(c.libelle) + '</p>' }); });
    // Les formations attendues
    function ligneFo(f) {
      var a = articleDeFo(f);
      return '<div class="fo"><span class="fo-c">' + esc(f.code) + '</span><span class="fo-t">' + esc(f.nom) + (f.frequence ? ' <small>· ' + esc(f.frequence) + '</small>' : '') + '</span>' +
        (a ? '<a class="lien-doc fo-a" href="' + esc(lienDoc(a)) + '">' + esc(a.code) + '</a>' : '') + '</div>';
    }
    var fos = (V.formations || []).filter(function (f) { return f.attendu && f.attendu[p.code]; });
    var titreFo = '<h3>' + T('Formations', 'Opleidingen') + '</h3>';
    [['O', T('Obligatoires', 'Verplicht')], ['R', T('Recommandées', 'Aanbevolen')]].forEach(function (g) {
      var l = fos.filter(function (f) { return f.attendu[p.code] === g[0]; });
      l.forEach(function (f, i) { B.push({ html: (i ? '' : titreFo + '<h4>' + g[1] + ' · ' + l.length + '</h4>') + ligneFo(f) }); if (!i) titreFo = ''; });
    });
    if (!fos.length) B.push({ html: titreFo + '<p class="vide">' + T('Aucune formation attendue dans le référentiel.', 'Geen opleiding verwacht in het referentieel.') + '</p>' });
    // Les compétences clés du référentiel
    var cles = (V.competences || []).filter(function (c) { return codesPostes(c.postes).indexOf(p.code) >= 0; });
    cles.forEach(function (c, i) {
      B.push({ html: (i ? '' : '<h3>' + T('Compétences clés', 'Kerncompetenties') + ' · ' + cles.length + '</h3>') + '<div class="cp"><b>' + esc(c.nom) + '</b>' +
        (c.formation ? ' <small>· ' + esc(c.formation) + '</small>' : '') + (c.niveau ? ' <small>· ' + esc(c.niveau) + '</small>' : '') +
        (c.description ? '<small class="d">' + esc(c.description) + '</small>' : '') + '</div>' });
    });
    // Niveau par niveau (Franchise Buddy)
    var parId = {}; ((S.panel && S.panel.competences) || []).forEach(function (c) { parId[c.id] = c; });
    var sops = {}; ((S.compSop && S.compSop.competences) || []).forEach(function (c) { sops[String(c.id)] = c.sops || []; });
    var titreNiv = '<h3>' + T('Niveau par niveau', 'Niveau per niveau') + ' <small>· Franchise Buddy</small></h3>';
    var avecComp = nivs.filter(function (x) { return (x.niveau.competences || []).length; });
    avecComp.forEach(function (x) {
      var ids = x.niveau.competences || [];
      var desc = String(x.niveau.description || '').trim();
      B.push({ html: titreNiv + '<h4>' + esc(x.niveau.nom) + ' <small>· ' + ids.length + ' ' + T('compétences', 'competenties') + '</small></h4>' + (desc ? '<p class="niv-d">' + esc(desc) + '</p>' : '') });
      titreNiv = '';
      var puces = ids.map(function (id) {
        var c = parId[id]; if (!c) return '';
        var s0 = (sops[String(id)] || []).map(function (sid) { return S.parId[sid]; }).filter(Boolean)[0];
        var nom = s0 ? '<a class="lien-doc" href="' + esc(lienDoc(s0)) + '">' + esc(c.nom) + '</a>' : esc(c.nom);
        return '<span class="cpb">' + nom + (c.verification === 'instructor' ? ' <small>· ' + T('formateur', 'opleider') + '</small>' : '') + '</span>';
      }).filter(Boolean);
      for (var i = 0; i < puces.length; i += 9) B.push({ html: '<div class="cps">' + puces.slice(i, i + 9).join('') + '</div>' });
    });
    if (!avecComp.length) B.push({ html: titreNiv + '<p class="vide">' + T('Aucune compétence encore rattachée à ce poste dans Franchise Buddy.', 'Nog geen competentie gekoppeld aan deze functie in Franchise Buddy.') + '</p>' });
    return B;
  }

  /* ── le manuel de pré-ouverture : le Guide pré-ouverture (sections, étapes, contacts) ── */
  var QUI = { franchise: { fr: 'Franchisé', nl: 'Franchisenemer' }, atelier: { fr: 'L’Atelier By', nl: 'L’Atelier By' },
    ensemble: { fr: 'Ensemble', nl: 'Samen' }, formateurs: { fr: 'Formateurs', nl: 'Opleiders' } };
  function guideSections() { return (S.ref && S.ref.preouverture && S.ref.preouverture.sections) || []; }
  function guideEtapes() {
    var out = [];
    guideSections().forEach(function (s) { if (s.type !== 'contacts') (s.points || []).forEach(function (p) { out.push({ s: s, p: p }); }); });
    return out;
  }
  function libelle(o) { return S.langue === 'nl' && o.nl ? o.nl : (o.fr || ''); }
  function champLangue(o, k) { return S.langue === 'nl' && o[k + '_nl'] ? o[k + '_nl'] : (o[k + '_fr'] || ''); }
  function echeance(j) { return j === null || j === undefined || j === '' ? '' : (+j === 0 ? T('Jour J', 'Dag D') : T('J-', 'D-') + (+j)); }
  function qui(p) { return '<span class="po-qui po-' + esc(p.qui || '') + '">' + esc(QUI[p.qui] ? L(QUI[p.qui]) : (p.qui || '')) + '</span>'; }
  function ligneEtape(p, avecPreuve) {
    var preuve = avecPreuve ? champLangue(p, 'preuve') : '';
    return '<div class="po"><span class="po-j">' + esc(echeance(p.j)) + '</span><span class="po-t">' + lier(libelle(p)) +
      (preuve ? '<small>' + T('Preuve à garder : ', 'Bewijs om te bewaren: ') + esc(preuve) + '</small>' : '') + '</span>' + qui(p) + '</div>';
  }
  function blocsRebours() {
    var e = guideEtapes().filter(function (x) { return x.p.j !== null && x.p.j !== undefined && x.p.j !== ''; })
      .sort(function (a, b) { return (+b.p.j) - (+a.p.j); });
    var B = [{ neuve: true, html: '<div class="doc-code">' + T('Le compte à rebours', 'De aftelling') + '</div><h2>' + T('De J-90 à l’ouverture', 'Van D-90 tot de opening') + '</h2>' +
      '<div class="doc-obj">' + T('Toutes les étapes de la pré-ouverture, de la plus lointaine au jour J. Chacune a son responsable ; les preuves à garder sont dans les sections qui suivent, et votre consultant les vérifie à la journée test.',
        'Alle stappen vóór de opening, van de verste tot dag D. Elke stap heeft een verantwoordelijke; de bewijzen om te bewaren staan in de volgende delen, en uw consulent controleert ze op de testdag.') + '</div>' +
      '<div class="po-leg">' + Object.keys(QUI).map(function (k) { return qui({ qui: k }); }).join(' ') + '</div>' }];
    [[9999, 61, T('Plus de deux mois avant', 'Meer dan twee maanden vooraf')], [60, 31, T('Le deuxième mois avant', 'De tweede maand vooraf')],
     [30, 11, T('Le dernier mois', 'De laatste maand')], [10, 0, T('Les dix derniers jours', 'De laatste tien dagen')]].forEach(function (t) {
      var l = e.filter(function (x) { return +x.p.j <= t[0] && +x.p.j >= t[1]; });
      l.forEach(function (x, i) { B.push({ html: (i ? '' : '<h3>' + esc(t[2]) + ' <small>· ' + l.length + '</small></h3>') + ligneEtape(x.p, false) }); });
    });
    return B;
  }
  function blocsSectionGuide(s, n) {
    var intro = champLangue(s, 'intro');
    var B = [{ neuve: true, html: '<div class="doc-code">' + T('Section ', 'Deel ') + n + '</div><h2>' + esc(libelle(s)) + '</h2>' + (intro ? '<div class="doc-obj">' + lier(intro) + '</div>' : '') }];
    (s.points || []).forEach(function (p) {
      B.push({ html: s.type === 'contacts' ? '<div class="po"><span class="po-t">' + esc(libelle(p)) + '</span>' + qui(p) + '</div>' : ligneEtape(p, true) });
    });
    return B;
  }
  function blocsLectures() {
    var docs = ((S.liste && S.liste.sops) || []).filter(function (d) {
      return d.type === 'Article' && d.statut !== 'brouillon' && ((d.classement && d.classement.phases) || []).indexOf('preouverture') >= 0;
    }).sort(function (a, b) { return String(a.code).localeCompare(String(b.code), 'fr', { numeric: true }); });
    var B = [{ neuve: true, html: '<div class="doc-code">' + T('À lire', 'Te lezen') + '</div><h2>' + T('Les articles à lire avant l’ouverture', 'De artikels om te lezen vóór de opening') + '</h2>' +
      '<div class="doc-obj">' + T('Les articles du manuel opératoire rangés dans la phase de pré-ouverture (' + docs.length + '). Le code ouvre l’article. Les SOP et les checklists s’apprennent pendant la formation.',
        'De artikels van het operationeel handboek in de fase vóór de opening (' + docs.length + '). De code opent het artikel. De SOP en de checklists leert men tijdens de opleiding.') + '</div>' }];
    docs.forEach(function (d) { B.push({ html: '<div class="fo"><span class="fo-c"><a class="lien-doc" href="' + esc(lienDoc(d)) + '">' + esc(d.code) + '</a></span><span class="fo-t">' + esc(L(d.titre)) + '</span></div>' }); });
    return B;
  }

  /*
   * Les compétences à acquérir, par profil (19/11/2026, Sam : « dans pré-ouverture, rajouter la liste des compétences à
   * acquérir par poste, avec le lien QR code vers son application qui est dans le CRM ») : le Guide des formations et
   * compétences (GET /formation/ref › blocs), ses points des phases « pré-ouverture » et « mise en route », profil par
   * profil (gérant, vendeur, étudiant, multi-boutique : les plans du référentiel), bloc par bloc, avec le critère
   * « Réussi si… » et la mention « bloquante » (nécessaire à l'attestation). Le QR code ouvre le CRM, où chacun suit son
   * plan et ses validations (crmUrl du module : réglage, sinon l'adresse du Recrutement dans erp_cles).
   */
  var PROFILS = [['gerant', 'PF-GER', { fr: 'Gérant (franchisé)', nl: 'Zaakvoerder (franchisenemer)' }],
    ['vendeur', 'PF-VEND', { fr: 'Vendeur, vendeuse', nl: 'Verkoper, verkoopster' }],
    ['etudiant', 'PF-ETU', { fr: 'Étudiant, flexi-job', nl: 'Student, flexi-job' }],
    ['multi', 'PF-MULTI', { fr: 'Gérant multi-boutique', nl: 'Zaakvoerder van meerdere winkels' }]];
  function nomProfil(pr) {
    var plan = ((S.ref && S.ref.plans) || []).filter(function (x) { return x.code === pr[1]; })[0];
    return S.langue === 'fr' && plan && plan.fr ? plan.fr.replace(/^Plan\s+/, '') : L(pr[2]);
  }
  function pointsProfil(cle) {
    var out = [];
    ((S.ref && S.ref.blocs) || []).forEach(function (b) {
      (b.items || []).forEach(function (it) {
        if ((it.profils || []).indexOf(cle) >= 0 && (it.phase === 'pre' || it.phase === 'ouv')) out.push({ b: b, it: it });
      });
    });
    return out;
  }
  function blocsCompetencesIntro() {
    var url = (S.ref && S.ref.crmUrl) || '';
    var B = [{ neuve: true, html: '<div class="doc-code">' + T('Les compétences', 'De competenties') + '</div><h2>' + T('Les compétences à acquérir avant l’ouverture', 'De competenties te verwerven vóór de opening') + '</h2>' +
      '<div class="doc-obj">' + T('Pour chaque profil, les compétences et les formations du Guide des formations, bloc par bloc : celles de la pré-ouverture et de la mise en route. Chacune a son critère « Réussi si… ». Une compétence « bloquante » est nécessaire à l’attestation d’ouverture.',
        'Voor elk profiel de competenties en opleidingen van de opleidingsgids, blok per blok: die van vóór de opening en van de opstart. Elk heeft zijn criterium « Geslaagd als… ». Een « blokkerende » competentie is nodig voor het openingsattest.') + '</div>' }];
    B.push({ html: '<div class="po-qr">' + (url ? '<div class="po-qr-img">' + qrSvg(url, 4) + '</div>' : '') + '<div><b>' + T('Suivre sa formation dans le CRM', 'Zijn opleiding volgen in het CRM') + '</b>' +
      '<p>' + (url ? T('Scannez le code : votre plan de formation, vos validations et votre attestation sont dans votre espace du CRM.', 'Scan de code: uw opleidingsplan, uw validaties en uw attest staan in uw ruimte in het CRM.') + '</p><small>' + esc(url) + '</small>'
        : T('L’adresse du CRM n’est pas encore réglée : votre consultant vous la donne.', 'Het adres van het CRM is nog niet ingesteld: uw consulent geeft het u.') + '</p>') + '</div></div>' });
    PROFILS.forEach(function (pr) {
      var l = pointsProfil(pr[0]);
      B.push({ html: '<div class="po"><span class="po-t">' + esc(nomProfil(pr)) + '</span><span class="po-n">' + l.length + ' ' + T('à acquérir', 'te verwerven') + ' · ' + l.filter(function (x) { return x.it.bloq; }).length + ' ' + T('bloquantes', 'blokkerend') + '</span></div>' });
    });
    return B;
  }
  function blocsCompetencesProfil(pr) {
    var l = pointsProfil(pr[0]);
    var B = [{ neuve: true, html: '<div class="doc-code">' + T('Compétences · profil', 'Competenties · profiel') + '</div><h2>' + esc(nomProfil(pr)) + '</h2>' +
      '<div class="doc-obj">' + l.length + ' ' + T('compétences et formations à acquérir, dont ', 'competenties en opleidingen te verwerven, waarvan ') + l.filter(function (x) { return x.it.bloq; }).length + ' ' + T('bloquantes pour l’attestation.', 'blokkerend voor het attest.') + '</div>' }];
    var bloc = null;
    l.forEach(function (x) {
      var titre = '';
      if (bloc !== x.b) {
        bloc = x.b;
        titre = '<h4>' + T('Bloc ', 'Blok ') + esc(x.b.n) + ' · ' + esc(libelle(x.b)) + (x.b.role ? ' <small>· ' + esc(S.langue === 'nl' && x.b.role_nl ? x.b.role_nl : x.b.role) + '</small>' : '') + '</h4>';
      }
      var crit = champLangue(x.it, 'critere');
      B.push({ html: titre + '<div class="cp"><b>' + esc(libelle(x.it)) + '</b> <small>· ' + esc(x.it.id) + '</small>' +
        (x.it.bloq ? ' <span class="po-bloq">' + T('bloquante', 'blokkerend') + '</span>' : '') +
        (x.it.phase === 'ouv' ? ' <span class="po-ouv">' + T('à la mise en route', 'bij de opstart') + '</span>' : '') +
        (crit ? '<small class="d">' + T('Réussi si : ', 'Geslaagd als: ') + esc(crit) + '</small>' : '') + '</div>' });
    });
    return B;
  }

  /** Range des blocs dans des pages ; rend {pages, debuts} (debuts[k] : l'indice de page où commence l'entrée k). */
  function ranger(m, entrees) {
    var pages = [], debuts = [], cur = null;
    function nouvelle() { cur = pageVide(m); mesure.appendChild(cur); pages.push(cur); }
    function corps() { return cur.querySelector('.pg-corps'); }
    function deborde() { var c = corps(); return c.scrollHeight > c.clientHeight + 1; }
    entrees.forEach(function (blocs) {
      blocs.forEach(function (b, i) {
        if (!cur || b.neuve) nouvelle();
        var el = document.createElement('div');
        el.innerHTML = b.html;
        corps().appendChild(el);
        if (deborde() && corps().children.length > 1) { corps().removeChild(el); nouvelle(); corps().appendChild(el); }
        if (i === 0) debuts.push(pages.indexOf(cur));
      });
    });
    return { pages: pages, debuts: debuts };
  }
  function paginer(m) {
    if (!mesure) mesure = creerMesure();
    mesure.innerHTML = '';
    // titres : le sommaire ({sec} : un intertitre ; sinon une entrée k) ; ids[k] : l'identifiant de l'entrée k (aller à…).
    var titres = [], entrees = [], ids = [];
    function ajouter(id, c, t, blocs) {
      titres.push({ c: c, t: t, href: base() + '#/m/' + m.cle + '/d/' + encodeURIComponent(id), k: entrees.length });
      ids.push(id); entrees.push(blocs);
    }
    if (m.ref) {
      var doms = (S.ref && S.ref.domaines) || [];
      titres.push({ sec: T('Les domaines', 'De domeinen') });
      doms.forEach(function (d) { ajouter(d.code, d.code, L(d), blocsDomaine(d)); });
      titres.push({ sec: T('Les postes : formations et compétences', 'De functies: opleidingen en competenties') });
      if (postesV25().length) {
        ajouter('postes', '', T('Lire cette partie', 'Dit deel lezen'), blocsPostesIntro());
        postesV25().forEach(function (p) { ajouter(p.code, p.code, p.nom, blocsPoste(p)); });
      } else {
        ajouter('postes', '', T('Les postes (illisibles pour l’instant)', 'De functies (nu niet leesbaar)'),
          [{ neuve: true, html: '<h2>' + T('Les postes', 'De functies') + '</h2><p>' + T('Le référentiel des postes est illisible pour l’instant : rouvrez le manuel plus tard.', 'Het referentieel van de functies is nu niet leesbaar: open het handboek later opnieuw.') + '</p>' }]);
      }
    } else if (m.guide) {
      if (guideSections().length) {
        ajouter('rebours', T('J-', 'D-'), T('Le compte à rebours, de J-90 à l’ouverture', 'De aftelling, van D-90 tot de opening'), blocsRebours());
        titres.push({ sec: T('Les étapes, section par section', 'De stappen, deel per deel') });
        guideSections().forEach(function (sc, k) { ajouter(sc.cle, String(k + 1), libelle(sc), blocsSectionGuide(sc, k + 1)); });
        if (((S.ref && S.ref.blocs) || []).length) {
          titres.push({ sec: T('Les compétences à acquérir, par profil', 'De te verwerven competenties, per profiel') });
          ajouter('competences', '', T('Comment les lire, et les suivre dans le CRM', 'Hoe ze te lezen, en te volgen in het CRM'), blocsCompetencesIntro());
          PROFILS.forEach(function (pr) { if (pointsProfil(pr[0]).length) ajouter('comp-' + pr[0], '', nomProfil(pr), blocsCompetencesProfil(pr)); });
        }
        titres.push({ sec: T('À lire', 'Te lezen') });
        ajouter('lectures', '', T('Les articles à lire avant l’ouverture', 'De artikels om te lezen vóór de opening'), blocsLectures());
      } else {
        ajouter('rebours', '', T('Le guide (illisible pour l’instant)', 'De gids (nu niet leesbaar)'),
          [{ neuve: true, html: '<h2>' + T('Pré-ouverture', 'Vóór de opening') + '</h2><p>' + T('Le Guide pré-ouverture est illisible pour l’instant : rouvrez le manuel plus tard.', 'De gids vóór de opening is nu niet leesbaar: open het handboek later opnieuw.') + '</p>' }]);
      }
    } else if (m.fiches) {
      if (ftProduits().length) {
        ajouter('lire', '', T('Lire une fiche, et ce qu’il reste à compléter', 'Een fiche lezen, en wat nog aan te vullen is'), blocsFichesIntro());
        var grp = null;
        ftCategories().forEach(function (c) {
          if (c.groupe !== grp) { grp = c.groupe; titres.push({ sec: grp || T('Sans groupe', 'Zonder groep') }); }
          var B = [];
          c.produits.forEach(function (p) { B = B.concat(blocsFicheProduit(p)); });
          ajouter('cat-' + norm(c.categorie), String(c.produits.length), c.categorie, B);
        });
      } else {
        ajouter('lire', '', T('Les fiches (illisibles pour l’instant)', 'De fiches (nu niet leesbaar)'),
          [{ neuve: true, html: '<h2>' + T('Fiches techniques', 'Technische fiches') + '</h2><p>' + T('Les fiches techniques du panel sont illisibles pour l’instant : rouvrez le manuel plus tard.', 'De technische fiches van het panel zijn nu niet leesbaar: open het handboek later opnieuw.') + '</p>' }]);
      }
    } else {
      docsDe(m).forEach(function (d) {
        ajouter(d.id, d.code, L(d.titre), blocsDocument(d, S.fiches[d.id]));
        titres[titres.length - 1].href = lienDoc(d);
      });
    }
    var contenu = ranger(m, entrees);
    // Le mode d'emploi du code, entre la couverture et le sommaire (manuel des checklists).
    var intro = m.cle === 'checklists' ? ranger(m, [blocsCodeChecklist(m)]) : { pages: [] };
    // Le sommaire : ses pages d'abord (numéros posés ensuite, quand on sait combien il en prend).
    var som = ranger(m, [[{ neuve: true, html: '<h2>' + T('Sommaire', 'Inhoud') + '</h2>' }]].concat(titres.map(function (x) {
      if (x.sec) return [{ html: '<div class="som-sec">' + esc(x.sec) + '</div>' }];
      var t = x.href ? '<a class="lien-som" href="' + esc(x.href) + '">' + esc(x.t) + '</a>' : esc(x.t);
      return [{ html: '<div class="som"><span class="c">' + codeCk(x.c) + '</span><span class="t">' + t + '</span><span class="n" data-k="' + x.k + '"></span></div>' }];
    })));
    var cv = couverture(m);
    mesure.appendChild(cv);
    var toutes = [cv].concat(intro.pages, som.pages, contenu.pages);
    var decal = 1 + intro.pages.length + som.pages.length;
    S.somIdx = 1 + intro.pages.length;
    som.pages.forEach(function (p) {
      p.querySelectorAll('.som .n').forEach(function (n) { n.textContent = String(decal + contenu.debuts[+n.getAttribute('data-k')] + 1); });
    });
    toutes.forEach(function (p, j) { var n = p.querySelector('.pg-n'); if (n) n.textContent = T('Page ', 'Pagina ') + (j + 1) + ' / ' + toutes.length; });
    S.debuts = {};
    ids.forEach(function (id, k) { S.debuts[id] = decal + contenu.debuts[k]; });
    S.langPages = S.langue;
    return toutes;
  }

  /* ── le feuilletage ── */
  function double() { return window.innerWidth >= 1100; }
  function vues() {
    // En double page : la couverture seule, puis les pages par deux (1-2, 3-4…) ; sinon une page à la fois.
    var N = S.pages.length, v = [];
    if (!double()) { for (var i = 0; i < N; i++) v.push([i]); return v; }
    v.push([0]);
    for (var j = 1; j < N; j += 2) v.push(j + 1 < N ? [j, j + 1] : [j]);
    return v;
  }
  function vueDe(i) { var V = vues(); for (var k = 0; k < V.length; k++) if (V[k].indexOf(i) >= 0) return k; return 0; }
  function rendreFeuilletage() {
    var m = S.manuel;
    root.innerHTML = '<header class="ct-barre"><a class="ct-retour" href="#/">← ' + T('Catalogue', 'Catalogus') + '</a>' +
      '<span class="ct-titre">' + esc(L(m.titre)) + '</span><span class="ct-esp"></span>' +
      '<span class="ct-pages" id="ct-pages"></span>' +
      '<button type="button" class="btn" data-aller="1">' + T('Sommaire', 'Inhoud') + '</button>' +
      '<button type="button" class="btn" data-qr="' + m.cle + '">QR</button>' + segLangue() +
      '<button type="button" class="btn btn--primary" data-imprimer="1">' + T('Imprimer · PDF', 'Afdrukken · pdf') + '</button></header>' +
      '<div class="ct-corps"><div class="ct-scene" id="ct-scene"><div class="ct-livre" id="ct-livre"></div></div>' +
      '<div class="ct-nav"><button type="button" class="btn" data-pas="-1" aria-label="' + T('Page précédente', 'Vorige pagina') + '">‹</button>' +
      '<input type="range" id="ct-curseur" min="1" max="' + S.pages.length + '" value="1" aria-label="Page">' +
      '<button type="button" class="btn" data-pas="1" aria-label="' + T('Page suivante', 'Volgende pagina') + '">›</button></div>' +
      '<div class="ct-aide">' + T('Tourner : les flèches, le clavier ou un glissement · Échap pour revenir au catalogue', 'Bladeren: de pijlen, het toetsenbord of vegen · Esc om terug te keren') + '</div></div>';
    afficher(0);
  }
  function afficher(sens) {
    var livre = document.getElementById('ct-livre'); if (!livre) return;
    var V = vues(), k = vueDe(S.i), idx = V[k];
    livre.innerHTML = '';
    idx.forEach(function (i) { livre.appendChild(S.pages[i].cloneNode(true)); });
    photosDe(livre);
    livre.classList.toggle('un', idx.length === 1);
    // À l'échelle de la fenêtre.
    var scene = document.getElementById('ct-scene');
    var pw = S.pages[0].offsetWidth || 559, ph = S.pages[0].offsetHeight || 794;
    var largeur = pw * idx.length;
    var dispoL = Math.max(280, scene.clientWidth - 24), dispoH = Math.max(320, window.innerHeight - 190);
    var z = Math.min(dispoL / largeur, dispoH / ph, 1.6);
    livre.style.transform = 'scale(' + z + ')';
    livre.style.setProperty('--ct-z', 'scale(' + z + ')');
    scene.style.height = Math.ceil(ph * z + 8) + 'px';
    livre.classList.remove('tourne-d', 'tourne-g');
    if (sens) { void livre.offsetWidth; livre.classList.add(sens > 0 ? 'tourne-d' : 'tourne-g'); }
    var N = S.pages.length;
    var lab = document.getElementById('ct-pages');
    if (lab) lab.textContent = T('Page ', 'Pagina ') + (idx.length > 1 ? (idx[0] + 1) + '–' + (idx[1] + 1) : (idx[0] + 1)) + ' / ' + N;
    var cur = document.getElementById('ct-curseur'); if (cur) cur.value = idx[0] + 1;
  }
  function pas(d) {
    var V = vues(), k = vueDe(S.i) + d;
    if (k < 0 || k >= V.length) return;
    S.i = V[k][0]; afficher(d);
  }
  function allerA(i) { S.i = Math.max(0, Math.min(S.pages.length - 1, i)); afficher(0); }

  /* ── l'impression (PDF) ── */
  /**
   * Les liens directs dans le PDF. Chrome change un lien « même page + #… » en lien interne au PDF, et l'abandonne si
   * l'ancre n'existe pas : nos liens #/m/… disparaîtraient. Donc, dans la copie imprimée seulement :
   *  - un document du même manuel → ancre de sa première page : le clic saute à la bonne page DU PDF ;
   *  - un document d'un autre manuel → l'adresse du catalogue avec « ?m=<manuel> » (une autre adresse aux yeux de
   *    Chrome, donc un vrai lien web) : le clic ouvre ce manuel en ligne, à la page du document.
   */
  function ancre(id) { return 'doc-' + String(id).replace(/[^\w-]/g, '_'); }
  function lierPourPdf(zone) {
    var pgs = zone.children, racine = location.origin + location.pathname;
    Object.keys(S.debuts).forEach(function (id) { var pg = pgs[S.debuts[id]]; if (pg && !pg.id) pg.id = ancre(id); });
    Array.prototype.forEach.call(zone.querySelectorAll('a.lien-doc, a.lien-som'), function (a) {
      var r = /#\/m\/([^/]+)\/d\/([^/]+)$/.exec(a.getAttribute('href') || '');
      if (!r) return;
      var id = decodeURIComponent(r[2]);
      if (S.manuel && r[1] === S.manuel.cle && S.debuts[id] != null && document.getElementById(ancre(id))) a.setAttribute('href', '#' + ancre(id));
      else a.setAttribute('href', racine + '?m=' + r[1] + '#/m/' + r[1] + '/d/' + r[2]);
    });
  }
  function imprimerManuel() {
    var zone = document.getElementById('ct-imprime');
    zone.innerHTML = '';
    S.pages.forEach(function (p) { zone.appendChild(p.cloneNode(true)); });
    lierPourPdf(zone);
    photosDe(zone);
    var imgs = Array.prototype.slice.call(zone.querySelectorAll('img'));
    Promise.race([
      Promise.all(imgs.map(function (im) { return im.complete ? Promise.resolve() : new Promise(function (ok) { im.onload = im.onerror = ok; }); })),
      new Promise(function (ok) { setTimeout(ok, S.manuel && S.manuel.fiches ? 30000 : 4000); }),
    ]).then(function () {
      if (/\/imprimer$/.test(location.hash) && S.manuel) history.replaceState(null, '', '#/m/' + S.manuel.cle);
      window.print();
    });
  }

  /* ── l'ouverture d'un manuel ── */
  function ouvrirManuel(m, imprimer, cible) {
    S.manuel = m; S.i = 0; S.pages = null;
    rendreChargement(m, 0, docsDe(m).length);
    // La police AVANT la mesure : Gotham (400 le texte, 700 les accents) ne se charge qu'à son premier emploi ; mesurer avec la
    // police de secours, plus étroite, laisserait déborder les pages une fois Gotham arrivée.
    charger(m).then(function () {
      if (!document.fonts || !document.fonts.load) return null;
      return Promise.all([document.fonts.load('400 12px Gotham'), document.fonts.load('700 12px Gotham'),
        document.fonts.load('300 12px GothamPage'), document.fonts.load('500 12px GothamPage')]).then(function () { return document.fonts.ready; });
    }).then(function () {
      if (S.manuel !== m) return;
      S.pages = paginer(m);
      rendreFeuilletage();
      if (cible && S.debuts[cible] != null) allerA(S.debuts[cible]);
      if (imprimer) imprimerManuel();
    }).catch(function (e) {
      root.innerHTML = '<div class="ct-corps"><p class="ct-msg">' + esc(T('Lecture impossible : ', 'Lezen onmogelijk: ') + e.message) + '</p><p><a href="#/">← ' + T('Catalogue', 'Catalogus') + '</a></p></div>';
    });
  }

  /* ── l'adresse ── */
  function route() { var h = location.hash.replace(/^#\/?/, '').split('/'); return { ecran: h[0] || '', cle: h[1] || '', action: h[2] || '', id: h[3] ? decodeURIComponent(h[3]) : '' }; }
  function aller() {
    var r = route();
    if (r.ecran === 'm' && manuelDe(r.cle)) {
      var m = manuelDe(r.cle), cible = r.action === 'd' ? r.id : '';
      // Le manuel est déjà mis en pages (un lien vers un de ses documents) : on y va sans tout relire.
      if (S.manuel === m && S.pages && S.langPages === S.langue && document.getElementById('ct-livre')) {
        if (cible && S.debuts[cible] != null) allerA(S.debuts[cible]);
        else if (r.action === 'imprimer') imprimerManuel();
        return;
      }
      ouvrirManuel(m, r.action === 'imprimer', cible);
      return;
    }
    S.manuel = null; S.pages = null;
    if (r.ecran === 'videos') {
      rendreVideotheque();
      if (!S.videos) chargerVideos().then(function () { if (route().ecran === 'videos') rendreVideotheque(); });
      return;
    }
    rendreGrille();
    if (!S.videos) chargerVideos().then(function () { if (!route().ecran) rendreGrille(); });
  }

  /* ── les gestes ── */
  document.addEventListener('click', function (e) {
    var q = e.target.closest('[data-qr]'); if (q) { fenetreQr(q.getAttribute('data-qr')); return; }
    var vd = e.target.closest('[data-video]'); if (vd) { lireVideo(vd.getAttribute('data-video')); return; }
    // Un lien direct vers le document où l'on est déjà (même adresse) : pas de « hashchange », on y retourne quand même.
    var ln = e.target.closest('a.lien-doc, a.lien-som');
    if (ln && ln.href.split('#')[0] === base() && ln.hash === location.hash) { e.preventDefault(); aller(); return; }
    var l = e.target.closest('[data-langue]');
    if (l) {
      S.langue = l.getAttribute('data-langue');
      try { localStorage.setItem('ctLangue', S.langue); } catch (x) { /* */ }
      if (S.manuel && S.pages) { var i = S.i; S.pages = paginer(S.manuel); rendreFeuilletage(); allerA(i); } else if (route().ecran === 'videos') { rendreVideotheque(); } else { rendreGrille(); }
      return;
    }
    var p = e.target.closest('[data-pas]'); if (p) { pas(+p.getAttribute('data-pas')); return; }
    if (e.target.closest('[data-aller]')) { allerA(S.somIdx || 1); return; }
    if (e.target.closest('[data-imprimer]') && S.pages) { imprimerManuel(); return; }
  });
  document.addEventListener('input', function (e) { if (e.target.id === 'ct-curseur' && S.pages) allerA(+e.target.value - 1); });
  document.addEventListener('keydown', function (e) {
    if (!S.pages || document.querySelector('.ct-voile')) { if (e.key === 'Escape') { var v = document.querySelector('.ct-voile'); if (v) v.remove(); } return; }
    if (e.key === 'ArrowRight' || e.key === 'PageDown') { e.preventDefault(); pas(1); }
    else if (e.key === 'ArrowLeft' || e.key === 'PageUp') { e.preventDefault(); pas(-1); }
    else if (e.key === 'Home') allerA(0);
    else if (e.key === 'End') allerA(S.pages.length - 1);
    else if (e.key === 'Escape') location.hash = '#/';
  });
  var depart = null;
  document.addEventListener('pointerdown', function (e) { if (S.pages && e.target.closest('#ct-scene')) depart = e.clientX; });
  document.addEventListener('pointerup', function (e) {
    if (depart == null) return;
    var dx = e.clientX - depart; depart = null;
    if (Math.abs(dx) > 50) pas(dx < 0 ? 1 : -1);
  });
  var minuterie = null;
  window.addEventListener('resize', function () { clearTimeout(minuterie); minuterie = setTimeout(function () { if (S.pages) afficher(0); }, 150); });
  window.addEventListener('hashchange', aller);

  /* ── au départ ── */
  Promise.all([
    api('/formation/sop/liste').then(function (j) { S.liste = j; indexer(); }),
    api('/formation/ref').then(function (j) { S.ref = j; }),
  ]).catch(function (e) { S.erreur = e.message; }).then(aller);
})();
