/*
 * Le catalogue des manuels (19/11/2026, Sam : « je dois pouvoir feuilleter en PDF les différents manuels ; créer dans le
 * rail la possibilité de feuilleter les pages des différents manuels comme un catalogue », puis « code it all »).
 *
 *   #/                 la grille : quatre manuels, chacun avec Feuilleter, PDF et son QR code ; le dépliant 4 manuels
 *   #/m/<cle>          le feuilletage d'un manuel : couverture, sommaire, puis chaque document, en pages A5
 *   #/m/<cle>/imprimer le même, puis la fenêtre d'impression (« Enregistrer en PDF ») : une page A5 par feuille
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
      sous: { fr: 'Le classeur : les domaines, puis les postes, leurs formations et leurs compétences', nl: 'De klasseur: de domeinen, dan de functies, hun opleidingen en competenties' },
      unite: { fr: 'domaines', nl: 'domeinen' } },
  ];
  /*
   * Les illustrations des couvertures : dessins au trait, dans l'esprit des icônes du design system (§7.12 : linéaires,
   * bouts et angles arrondis, couleur du texte), à plus grande échelle. Opératoire : le livre ouvert et l'épi ;
   * standards : la toque ; checklists : la planchette cochée ; réseau : les magasins reliés ; dépliant : le dépliant plié.
   */
  var BOUTIQUE = '<path d="M-18 12 L-14 0 H14 L18 12 Z"/><path d="M-18 12 q4.5 6 9 0 q4.5 6 9 0 q4.5 6 9 0 q4.5 6 9 0"/><path d="M-15 15 V36 H15 V15"/><path d="M-5 36 V24 H5 V36"/>';
  var ILLUS = {
    operatoire: '<path d="M60 50 C48 42 30 40 16 42 V100 C30 98 48 100 60 108 C72 100 90 98 104 100 V42 C90 40 72 42 60 50 Z"/><path d="M60 50 V108"/>' +
      '<path d="M26 56 C36 55 44 57 50 60 M26 68 C36 67 44 69 50 72 M26 80 C36 79 44 81 50 84 M94 56 C84 55 76 57 70 60 M94 68 C84 67 76 69 70 72 M94 80 C84 79 76 81 70 84"/>' +
      '<path d="M60 44 V12"/><path d="M60 40 C53 38 50 33 50 27 C56 28 60 33 60 40 Z M60 40 C67 38 70 33 70 27 C64 28 60 33 60 40 Z M60 28 C54 26 52 22 52 17 C57 18 60 22 60 28 Z M60 28 C66 26 68 22 68 17 C63 18 60 22 60 28 Z"/>',
    standards: '<path d="M36 76 C24 74 18 60 28 51 C27 37 43 30 53 37 C57 26 75 25 80 37 C92 32 104 46 95 58 C102 66 96 78 84 76"/>' +
      '<rect x="36" y="76" width="48" height="20" rx="3"/><path d="M48 76 V96 M60 76 V96 M72 76 V96"/><path d="M53 37 C50 44 50 52 52 60 M80 37 C76 45 74 52 73 60"/>',
    checklists: '<rect x="28" y="24" width="64" height="84" rx="6"/><rect x="46" y="16" width="28" height="14" rx="4"/>' +
      '<rect x="38" y="42" width="12" height="12" rx="2"/><path d="M40.5 48 L43.5 51 L48.5 44.5"/><path d="M58 48 H82"/>' +
      '<rect x="38" y="62" width="12" height="12" rx="2"/><path d="M40.5 68 L43.5 71 L48.5 64.5"/><path d="M58 68 H82"/>' +
      '<rect x="38" y="82" width="12" height="12" rx="2"/><path d="M58 88 H76"/>',
    reseau: '<g transform="translate(60 22)">' + BOUTIQUE + '</g><g transform="translate(26 70) scale(.75)">' + BOUTIQUE + '</g><g transform="translate(94 70) scale(.75)">' + BOUTIQUE + '</g>' +
      '<path d="M44 62 L34 70 M76 62 L86 70 M40 98 H80" stroke-dasharray="1 7"/>',
    depliant: '<path d="M20 34 L46 26 L74 34 L100 26 V86 L74 94 L46 86 L20 94 Z"/><path d="M46 26 V86 M74 34 V94"/>' +
      '<rect x="80" y="46" width="13" height="13" rx="1.5"/><path d="M27 48 H40 M27 58 H40 M27 68 H36 M53 46 H67 M53 56 H67 M53 66 H62"/>',
  };
  function illustration(cle, classe) {
    return ILLUS[cle] ? '<svg class="' + (classe || 'ill') + '" viewBox="0 0 120 120" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      ILLUS[cle].replace(/<path /g, '<path vector-effect="non-scaling-stroke" ').replace(/<rect /g, '<rect vector-effect="non-scaling-stroke" ') + '</svg>' : '';
  }
  var CONCURRENCE = 6;     // fiches lues en même temps
  var S = { liste: null, ref: null, fiches: {}, langue: 'fr', manuel: null, pages: null, i: 0, erreur: null,
    parCode: {}, parId: {}, debuts: {}, langPages: null, somIdx: 1,
    v25: null, panel: null, compSop: null, postesLus: false };
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
    return docsDe(m).length;
  }
  function nbSousCats() { return ((S.ref && S.ref.domaines) || []).reduce(function (n, d) { return n + (d.souscats || []).length; }, 0); }
  function aujourdhui() { return new Date().toLocaleDateString(S.langue === 'nl' ? 'nl-BE' : 'fr-BE', { day: 'numeric', month: 'long', year: 'numeric' }); }
  function segLangue() {
    return '<div class="seg" role="group" aria-label="Langue">' + ['fr', 'nl'].map(function (l) {
      return '<button type="button" class="' + (S.langue === l ? 'is-active' : '') + '" data-langue="' + l + '" aria-pressed="' + (S.langue === l) + '">' + l.toUpperCase() + '</button>';
    }).join('') + '</div>';
  }

  /* ── la grille ── */
  function rendreGrille() {
    var cartes = MANUELS.map(function (m) {
      var n = nbDe(m);
      var compte = m.ref ? n + ' ' + L(m.unite) + ' · ' + nbSousCats() + ' ' + T('sous-catégories', 'subcategorieën') : n + ' ' + L(m.unite);
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
      '<p>' + T('Six pages : la franchise, puis une page par manuel avec son QR code. À imprimer et à remettre à l’équipe.', 'Zes pagina’s: de franchise, dan één pagina per handboek met zijn QR-code. Om af te drukken en aan het team te geven.') + '</p>' +
      '<div class="ct-actions"><a class="btn" href="depliant.html">' + T('Ouvrir', 'Openen') + '</a><a class="btn btn--primary" href="depliant.html#imprimer">PDF</a></div></div>';
    root.innerHTML = '<header class="ct-barre"><img src="../img/logo.png" alt="L’Atelier By"><div><div class="ct-titre">' + T('Catalogue des manuels', 'Catalogus van de handboeken') + '</div>' +
      '<div class="ct-sous">' + T('Formation · Documents', 'Opleiding · Documenten') + '</div></div><span class="ct-esp"></span>' + segLangue() +
      '<a class="btn" href="../">' + T('Module Formation', 'Opleidingsmodule') + '</a></header>' +
      '<div class="ct-corps"><div class="ct-kicker">' + T('Formation · Documents', 'Opleiding · Documenten') + '</div>' +
      '<h1 class="ct-h1">' + T('Catalogue des manuels', 'Catalogus van de handboeken') + '</h1>' +
      '<p class="ct-lead">' + T('Feuilletez chaque manuel comme un catalogue, ou enregistrez-le en PDF pour l’imprimer. Chaque manuel a son QR code : le scanner ouvre ce manuel, à la même page.', 'Blader door elk handboek als door een catalogus, of sla het op als pdf om af te drukken. Elk handboek heeft zijn QR-code: wie hem scant, opent dat handboek.') + '</p>' +
      (S.erreur ? '<p class="ct-msg">' + esc(T('Lecture impossible : ', 'Lezen onmogelijk: ') + S.erreur) + '</p>' : '') +
      '<div class="ct-grille">' + cartes + depliant + '</div></div>';
  }

  /* ── la fenêtre du QR code ── */
  function fenetreQr(cle) {
    var m = manuelDe(cle); if (!m) return;
    var url = lienManuel(cle);
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
    var detail = m.ref ? nbSousCats() + ' ' + T('sous-catégories', 'subcategorieën') + (postesV25().length ? ' · ' + postesV25().length + ' ' + T('postes', 'functies') : '') : T('Édition du ', 'Uitgave van ') + aujourdhui();
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
    var imgs = Array.prototype.slice.call(zone.querySelectorAll('img'));
    Promise.race([
      Promise.all(imgs.map(function (im) { return im.complete ? Promise.resolve() : new Promise(function (ok) { im.onload = im.onerror = ok; }); })),
      new Promise(function (ok) { setTimeout(ok, 4000); }),
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
      return Promise.all([document.fonts.load('400 12px Gotham'), document.fonts.load('700 12px Gotham')]).then(function () { return document.fonts.ready; });
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
    rendreGrille();
  }

  /* ── les gestes ── */
  document.addEventListener('click', function (e) {
    var q = e.target.closest('[data-qr]'); if (q) { fenetreQr(q.getAttribute('data-qr')); return; }
    // Un lien direct vers le document où l'on est déjà (même adresse) : pas de « hashchange », on y retourne quand même.
    var ln = e.target.closest('a.lien-doc, a.lien-som');
    if (ln && ln.href.split('#')[0] === base() && ln.hash === location.hash) { e.preventDefault(); aller(); return; }
    var l = e.target.closest('[data-langue]');
    if (l) {
      S.langue = l.getAttribute('data-langue');
      try { localStorage.setItem('ctLangue', S.langue); } catch (x) { /* */ }
      if (S.manuel && S.pages) { var i = S.i; S.pages = paginer(S.manuel); rendreFeuilletage(); allerA(i); } else { rendreGrille(); }
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
