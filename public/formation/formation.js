/*
 * Module Formation — L'Atelier by.
 *
 * Le référentiel RH du panel (TFBuddy), rangé pour être lu : postes et
 * sous-postes (niveaux), compétences exigées, formations, procédures
 * opératoires, base de connaissances, compétences produit ; les équipes d'un
 * magasin face à leur niveau ; le manuel opératoire en ligne (tablette) ; et
 * la documentation de toutes les routes API qui portent ces données.
 *
 * Design : celui du module Marketing, à l'identique (formation.css, partie 1).
 * Données : uniquement l'API du cockpit (api/cockpit/formation/*), qui lit le
 * panel avec le compte consultant, et y écrit sur demande : compétences d'un
 * niveau, liens d'une compétence, catalogue, compétences et niveau d'une
 * personne. Chaque écriture est relue par le serveur avant d'être annoncée.
 * Navigation : par l'ancre (#/postes/12/34) — le bouton « retour » de la
 * tablette et les favoris marchent.
 */
(function () {
  'use strict';

  // L'API est À CÔTÉ du module (formation/api/cockpit) : la même page se sert sous le cockpit
  // (/consulant_bo/formation/) et à la racine du serveur (/formation/, comme /marketing/).
  var API = 'api/cockpit';
  // Le retour au cockpit : le dossier parent sous le cockpit ; depuis la racine, son adresse.
  var COCKPIT = /^\/formation(\/|$)/.test(location.pathname) ? '/consulant_bo/' : '../';
  var root = document.getElementById('root');

  /* ── Outils ─────────────────────────────────────────────────────────── */

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  var nf = new Intl.NumberFormat('fr-BE');
  function n(v) { return nf.format(v || 0); }
  function pl(k, un, plusieurs) { return n(k) + ' ' + (k > 1 ? (plusieurs || un + 's') : un); }
  function norm(s) { return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }
  function pct(a, b) { return b ? Math.round((a * 100) / b) : 0; }
  function court(s, max) { s = String(s || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim(); return s.length > max ? s.slice(0, max - 1) + '…' : s; }
  function pousser(map, k, v) { var a = map.get(k); if (!a) { a = []; map.set(k, a); } if (a.indexOf(v) < 0) a.push(v); }
  function icone(d) {
    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' + d + '"/></svg>';
  }
  /** Un chemin d'API qui ne se coupe qu'avant une barre oblique (jamais après la première). */
  function chemin(p) { return '<span class="fm-code">' + esc(p).replace(/(?!^)\//g, '<wbr>/') + '</span>'; }
  function date(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    if (isNaN(d)) return esc(iso);
    return d.toLocaleDateString('fr-BE', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' à ' + d.toLocaleTimeString('fr-BE', { hour: '2-digit', minute: '2-digit' });
  }

  /** Appel JSON de l'API du cockpit, avec un délai. */
  function appel(methode, chemin, corps, delai) {
    var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var minuteur = setTimeout(function () { if (ctrl) ctrl.abort(); }, delai || 120000);
    var h = { Accept: 'application/json' };
    if (corps) h['Content-Type'] = 'application/json';
    return fetch(API + chemin, { method: methode, headers: h, credentials: 'same-origin', body: corps ? JSON.stringify(corps) : undefined, signal: ctrl ? ctrl.signal : undefined })
      .then(function (r) {
        return r.text().then(function (t) {
          var d = null;
          try { d = t ? JSON.parse(t) : null; } catch (e) { d = null; }
          if (!r.ok) {
            var err = new Error((d && (d.raison || d.error || d.description)) || 'HTTP ' + r.status);
            err.status = r.status; err.data = d;
            throw err;
          }
          if (d === null) throw new Error('réponse illisible (le serveur n’a pas rendu de JSON)');
          return d;
        });
      }, function (e) {
        var err = new Error(e && e.name === 'AbortError' ? 'le panel ne répond pas (délai dépassé)' : 'cockpit injoignable');
        err.status = 0;
        throw err;
      })
      .finally(function () { clearTimeout(minuteur); });
  }
  function lire(chemin, delai) { return appel('GET', chemin, null, delai); }

  /* ── État ───────────────────────────────────────────────────────────── */

  var S = {
    route: { ecran: 'tableau', a: [] },
    ref: null, refErreur: null, refCharge: false,
    liens: null, liensErreur: null, liensCharge: false,
    niveaux: {}, procedures: {}, formations: {}, competences: {},
    equipe: { magasins: null, erreur: null, charge: false, parShop: {}, ouvert: null },
    cat: null, catErreur: null, catCharge: false,
    v25: null, v25Charge: false, pcListe: null, pcErreur: null, pcCharge: false, pcFiltre: '',
    api: { ouvert: {}, sondes: {}, chemins: {} },
    f: { q: '', categorie: '', vue: 'tous', fq: '', pq: '', rq: '', mq: '', aFam: '', aStatut: '', aQ: '', aDemande: false },
    menu: false,
    tablette: false,
    modale: null,   // fenêtre de saisie ouverte : {type, …}
    occupe: null,   // l'écriture en cours (une à la fois)
    flash: null,    // le message après une écriture : {genre: ok|error, texte}
  };
  var I = null; // index dérivés du référentiel

  try {
    S.carteVue = localStorage.getItem('fm.carte') || 'plan';
    S.tablette = /[?&]tablette=1\b/.test(location.search) || localStorage.getItem('fm.tablette') === '1';
  } catch (e) { /* stockage indisponible : mode normal */ }

  function indexer() {
    var R = S.ref;
    var comp = new Map(), posteById = new Map(), niveauById = new Map(), niveauPoste = new Map(), niveauxParComp = new Map();
    R.competences.forEach(function (c) { comp.set(c.id, c); });
    R.postes.forEach(function (p) {
      if (p.id != null) posteById.set(p.id, p);
      p.niveaux.forEach(function (nv) {
        niveauById.set(nv.id, nv);
        niveauPoste.set(nv.id, p);
        nv.competences.forEach(function (cid) { pousser(niveauxParComp, cid, nv.id); });
      });
    });
    var formation = new Map(), procedure = new Map(), ressource = new Map();
    R.formations.forEach(function (f) { formation.set(f.id, f); });
    R.procedures.forEach(function (p) { procedure.set(p.id, p); });
    R.ressources.forEach(function (r) { ressource.set(r.id, r); });
    var liens = S.liens ? S.liens.liens || {} : null;
    var compParFormation = new Map(), compParProcedure = new Map(), compParRessource = new Map();
    if (liens) {
      Object.keys(liens).forEach(function (k) {
        var cid = +k, l = liens[k];
        l.formations.forEach(function (f) { pousser(compParFormation, f.id, cid); if (!formation.has(f.id)) formation.set(f.id, { id: f.id, nom: f.nom, horsListe: true }); });
        l.procedures.forEach(function (p) { pousser(compParProcedure, p.id, cid); if (!procedure.has(p.id)) procedure.set(p.id, { id: p.id, titre: p.nom, horsListe: true }); });
        l.ressources.forEach(function (r) { pousser(compParRessource, r.id, cid); if (!ressource.has(r.id)) ressource.set(r.id, { id: r.id, titre: r.nom, horsListe: true }); });
      });
    }
    var cats = {};
    R.competences.forEach(function (c) { var k = c.categorie || 'Sans catégorie'; cats[k] = (cats[k] || 0) + 1; });
    I = {
      comp: comp, posteById: posteById, niveauById: niveauById, niveauPoste: niveauPoste, niveauxParComp: niveauxParComp,
      formation: formation, procedure: procedure, ressource: ressource, liens: liens,
      compParFormation: compParFormation, compParProcedure: compParProcedure, compParRessource: compParRessource,
      categories: Object.keys(cats).sort(function (a, b) { return a.localeCompare(b, 'fr'); }),
    };
  }

  /** Les liens d'une compétence : le lot complet s'il est là, sinon la lecture unitaire. */
  function liensDe(cid) {
    var u = S.competences[cid];
    if (I && I.liens) return I.liens[cid] || (u && u.data ? u.data.liens : { procedures: [], formations: [], ressources: [] });
    return u && u.data ? u.data.liens : null;
  }

  function niveauLibelle(nid) {
    var nv = I.niveauById.get(nid), p = I.niveauPoste.get(nid);
    if (!nv) return 'Niveau ' + nid;
    return (p && p.id != null ? p.nom + ' · ' : '') + nv.nom;
  }

  function posteDefaut() {
    var p = S.ref.postes.filter(function (x) { return x.id != null; })[0] || S.ref.postes[0];
    return p || null;
  }

  /* ── Navigation ─────────────────────────────────────────────────────── */

  var ICONES = {
    tableau: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
    postes: 'M4 7h16v13H4zM9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 12h18',
    competences: 'M12 2l3 6.5 7 .9-5 4.8 1.2 7L12 18l-6.2 3.2 1.2-7-5-4.8 7-.9z',
    formations: 'M22 10L12 5 2 10l10 5 10-5zM6 12v5c3 2 9 2 12 0v-5',
    procedures: 'M9 11l3 3 8-8M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
    ressources: 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20M4 19.5A2.5 2.5 0 0 0 6.5 22H20V2H6.5A2.5 2.5 0 0 0 4 4.5z',
    produit: 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8',
    equipes: 'M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.9',
    manuel: 'M2 4h7a3 3 0 0 1 3 3v14a2 2 0 0 0-2-2H2zM22 4h-7a3 3 0 0 0-3 3v14a2 2 0 0 1 2-2h8z',
    api: 'M8 9l-4 3 4 3M16 9l4 3-4 3M14 5l-4 14',
    parcours: 'M2 12h20M6 8v8M12 8v8M18 8v8',
  };
  var NAV = [
    ['Pilotage', [['tableau', 'Carte des métiers']]],
    ['Référentiel', [['postes', 'Postes & sous-postes'], ['competences', 'Compétences'], ['formations', 'Formations'],
      ['procedures', 'Procédures opératoires'], ['ressources', 'Base de connaissances'], ['produit', 'Compétences produit']]],
    ['Équipes', [['parcours', 'Parcours des équipes'], ['equipes', 'Compétences des équipes']]],
    ['Manuel opératoire', [['manuel', 'Manuel en ligne']]],
    ['Documentation', [['api', 'Routes API']]],
  ];
  var TITRES = {};
  NAV.forEach(function (g) { g[1].forEach(function (it) { TITRES[it[0]] = it[1]; }); });

  function lireRoute() {
    var h = (location.hash || '').replace(/^#\/?/, '');
    var parts = h.split('/').filter(Boolean).map(function (x) { try { return decodeURIComponent(x); } catch (e) { return x; } });
    var ecran = TITRES[parts[0]] ? parts[0] : (S.tablette ? 'manuel' : 'tableau');
    S.route = { ecran: ecran, a: parts.slice(1) };
  }
  function arg(i) { var v = S.route.a[i]; return v != null && /^\d+$/.test(v) ? +v : null; }

  function sidebar() {
    var groupes = NAV.map(function (g) {
      return '<div class="sidebar__group"><div class="sidebar__group-label">' + esc(g[0]) + '</div>' +
        g[1].map(function (it) {
          var on = S.route.ecran === it[0];
          return '<a href="#/' + it[0] + '" class="sidebar__item' + (on ? ' is-active' : '') + '"' + (on ? ' aria-current="page"' : '') + '>' + icone(ICONES[it[0]]) + esc(it[1]) + '</a>';
        }).join('') + '</div>';
    }).join('');
    return '<nav class="sidebar" aria-label="Navigation principale">' +
      '<div class="sidebar__head"><img src="img/logo.png" alt="L\'Atelier By" class="sidebar__logo" /><div class="sidebar__kicker">Module Formation</div></div>' +
      '<div class="sidebar__back"><a href="' + COCKPIT + '">' + icone('M19 12H5M12 19l-7-7 7-7') + 'Retour au cockpit</a></div>' +
      '<div class="sidebar__groups">' + groupes + '</div></nav>';
  }

  function coquille(v) {
    var actions = '';
    if (S.ref && S.route.ecran !== 'api') {
      actions += '<button type="button" class="filter" data-act="rafraichir" title="Relire le référentiel dans le panel, sans le cache">Rafraîchir</button>';
    }
    if (S.route.ecran === 'manuel' || S.tablette) {
      actions += '<button type="button" class="filter' + (S.tablette ? ' is-on' : '') + '" data-act="tablette" title="Plein écran tactile, sans barre latérale">' + (S.tablette ? 'Quitter le mode tablette' : 'Mode tablette') + '</button>';
    }
    return '<div class="workspace' + (S.menu ? ' is-menu' : '') + '">' + sidebar() +
      '<div class="fm-veil" data-act="menu"></div>' +
      '<div class="workspace__main"><header class="topbar"><div class="topbar__title">' +
      '<button type="button" class="fm-burger" data-act="menu" aria-label="Ouvrir le menu">' + icone('M3 6h18M3 12h18M3 18h18') + '</button>' +
      '<div><p class="topbar__kicker">' + esc(v.kicker || 'Vue Réseau') + '</p><h1>' + esc(v.titre) + '</h1></div></div>' +
      '<div class="topbar__actions">' + actions + '</div></header>' +
      '<main class="app__body' + (v.large ? ' app__body--wide' : '') + '" id="corps">' + messageFlash() + v.corps + '</main></div>' + fenetre() + '</div>';
  }

  /* ── Pièces communes ────────────────────────────────────────────────── */

  function attente(txt) { return '<p class="muted">' + esc(txt) + '</p>'; }
  function bandeau(txt, genre, tourne) {
    return '<div class="fm-banner' + (genre ? ' fm-banner--' + genre : '') + '">' + (tourne ? '<span class="fm-spin" aria-hidden="true"></span>' : '') + '<span>' + txt + '</span></div>';
  }
  function retour(href, txt) { return '<a class="filter back" href="' + href + '">← ' + esc(txt) + '</a>'; }

  function chipsListe(items, max, cls, href) {
    if (!items.length) return '<span class="muted">—</span>';
    var vus = items.slice(0, max).map(function (x) {
      var lib = esc(x.nom || x.titre || ('#' + x.id));
      return href ? '<li><a class="chip ' + (cls || 'chip--soft') + '" href="' + href(x) + '">' + lib + '</a></li>' : '<li class="chip ' + (cls || 'chip--soft') + '">' + lib + '</li>';
    }).join('');
    var reste = items.length > max ? '<li class="chip chip--mute">+' + (items.length - max) + '</li>' : '';
    return '<ul class="chips">' + vus + reste + '</ul>';
  }

  /** Bandeau « liens » : tant que le lot des liens n'est pas lu, les comptes attendent. */
  function bandeauLiens() {
    if (S.liensCharge && !S.liens) return bandeau('Lecture des liens de chaque compétence (formations, procédures, ressources) — un appel par compétence, la première fois peut prendre une minute.', '', true);
    if (S.liensErreur && !S.liens) return bandeau('Liens des compétences illisibles : ' + esc(S.liensErreur) + '. Les comptes de formations et procédures restent vides.', 'warn');
    return '';
  }
  function compte(arr) {
    if (arr === null) return '<span class="muted">…</span>';
    return arr.length ? '<strong>' + n(arr.length) + '</strong>' : '<span class="muted">0</span>';
  }

  /** Le contenu riche d'une procédure, nettoyé : les balises de texte, rien d'exécutable. */
  var BALISES = ['P', 'BR', 'UL', 'OL', 'LI', 'STRONG', 'B', 'EM', 'I', 'U', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'BLOCKQUOTE', 'TABLE', 'THEAD', 'TBODY',
    'TR', 'TH', 'TD', 'A', 'IMG', 'SPAN', 'DIV', 'HR', 'CODE', 'PRE', 'SMALL', 'SUB', 'SUP'];
  function riche(html) {
    var t = String(html || '');
    if (!t.trim()) return '';
    if (!/<[a-z!\/]/i.test(t)) {
      return t.split(/\n{2,}/).map(function (p) { return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>'; }).join('');
    }
    var doc = new DOMParser().parseFromString('<div>' + t + '</div>', 'text/html');
    var out = document.createElement('div');
    (function copier(de, vers) {
      Array.prototype.forEach.call(de.childNodes, function (nd) {
        if (nd.nodeType === 3) { vers.appendChild(document.createTextNode(nd.nodeValue)); return; }
        if (nd.nodeType !== 1) return;
        var tag = nd.tagName;
        if (/^(SCRIPT|STYLE|IFRAME|OBJECT|EMBED|TEMPLATE|NOSCRIPT|FORM|INPUT|BUTTON|SELECT|TEXTAREA|LINK|META|SVG|MATH)$/.test(tag)) return;
        if (BALISES.indexOf(tag) < 0) { copier(nd, vers); return; }
        var el = document.createElement(/^H[1-3]$/.test(tag) ? 'H4' : /^H[4-6]$/.test(tag) ? 'H5' : tag);
        if (tag === 'A') {
          var h = (nd.getAttribute('href') || '').trim();
          // Un lien sans adresse sûre (javascript:, relatif au panel…) redevient du texte.
          if (!/^(https?:|mailto:)/i.test(h)) { copier(nd, vers); return; }
          el.setAttribute('href', h); el.setAttribute('target', '_blank'); el.setAttribute('rel', 'noopener noreferrer');
        }
        if (tag === 'IMG') {
          var s = nd.getAttribute('src') || '';
          if (!/^(https?:|data:image\/(png|jpe?g|gif|webp);)/i.test(s)) return;
          el.setAttribute('src', s); el.setAttribute('alt', nd.getAttribute('alt') || ''); el.setAttribute('loading', 'lazy');
        }
        if (tag === 'TD' || tag === 'TH') {
          ['colspan', 'rowspan'].forEach(function (a) { var v = nd.getAttribute(a); if (v && /^\d{1,2}$/.test(v)) el.setAttribute(a, v); });
        }
        vers.appendChild(el);
        copier(nd, el);
      });
    })(doc.body.firstChild || doc.body, out);
    return out.innerHTML;
  }

  /** L'écran quand le référentiel n'est pas (encore) là. */
  function sansReferentiel() {
    if (S.refErreur) {
      var e = S.refErreur;
      var auth = e.status === 401;
      return '<section class="card pending"><span class="chip pending__chip">' + (e.status === 503 ? 'Compte du panel à brancher' : auth ? 'Session requise' : 'Panel injoignable') + '</span>' +
        '<h2>' + (e.status === 503 ? 'Le module n’a pas d’accès au panel' : auth ? 'Connectez-vous au cockpit' : 'Le référentiel n’a pas pu être lu') + '</h2>' +
        '<p class="muted">' + esc(e.message) + '</p>' +
        (auth ? '<p class="muted" style="margin-top:8px"><a href="' + COCKPIT + '">Ouvrir le cockpit</a>, puis revenez ici.</p>' : '') +
        (e.status === 503 ? '<p class="muted" style="margin-top:8px">La documentation des routes, elle, reste consultable : <a href="#/api">Routes API</a>.</p>' : '') +
        '<p style="margin-top:12px"><button type="button" class="filter" data-act="rafraichir">Réessayer</button></p></section>';
    }
    return attente('Lecture du référentiel dans le panel — postes, niveaux, compétences, formations, procédures…');
  }

  /* ── Tableau de bord ────────────────────────────────────────────────── */

  /* Les trois familles de postes (demande du 31/10/2026). Le serveur range chaque poste
     (src/parcours.php, pcFamillePoste). Deux portées (31/10/2026) : les postes DANS LA
     FRANCHISE (le magasin : les trois premières familles) et ceux DANS LA MARQUE (le
     franchiseur : direction, consultants, contrôle qualité, client mystère…). */
  var FAMILLES = [
    ['operationnel', 'Opérationnel', 'En magasin : vente, boulangerie-pâtisserie, traiteur — de débutant à responsable d’équipe.'],
    ['cadre', 'Cadre et direction', 'Encadrer le magasin : adjoint, manager.'],
    ['entrepreneur', 'Devenir entrepreneur', 'Candidat, franchisé, multi-boutique.'],
  ];
  var MARQUE = ['marque', 'La marque', 'Le franchiseur : direction, consultants, contrôle qualité, client mystère.'];
  function nomFamille(f) { return f === 'marque' ? 'Dans la marque' : 'Dans la franchise · ' + ((FAMILLES.filter(function (x) { return x[0] === f; })[0] || [0, ''])[1]); }
  function familleDe(p) {
    if (p.id == null) return 'autres';
    return (S.v25 && S.v25.familles && S.v25.familles[p.id]) || 'cadre';
  }
  /** Les postes par famille ; ceux du référentiel que le panel n'a pas encore, à la fin de la leur. */
  function postesParFamille() {
    var o = { autres: [], marque: [] };
    FAMILLES.forEach(function (f) { o[f[0]] = []; });
    S.ref.postes.forEach(function (p) { o[familleDe(p)].push({ poste: p }); });
    ((S.v25 && S.v25.horsPanel) || []).forEach(function (x) { (o[x.famille] || o.entrepreneur).push({ v25: x }); });
    return o;
  }
  /** Ce qu'un poste exige, et ce qui le couvre (null tant que les liens ne sont pas lus). */
  function couverture(p) {
    var set = new Set();
    p.niveaux.forEach(function (nv) { nv.competences.forEach(function (c) { set.add(c); }); });
    var o = { niveaux: p.niveaux.length, comps: set.size, f: null, pr: null };
    if (I.liens) { o.f = 0; o.pr = 0; set.forEach(function (cid) { var l = liensDe(cid); if (l.formations.length) o.f++; if (l.procedures.length) o.pr++; }); }
    return o;
  }
  function barre(v, total) {
    var x = pct(v, total);
    return '<span class="fm-barre' + (x === 0 ? ' is-vide' : x < 50 ? ' is-faible' : '') + '"><span style="width:' + Math.max(x, 2) + '%"></span></span>';
  }
  function kpi(href, nombre, libelle, sous) {
    return '<a class="fm-kpi" href="' + href + '"><span class="fm-kpi__n">' + nombre + '</span><span class="fm-kpi__l">' + esc(libelle) + '</span>' + (sous ? '<span class="fm-kpi__s">' + sous + '</span>' : '') + '</a>';
  }

  /* ── Carte des métiers (tableau de bord) ─────────────────────────────
     Deux vues sur une bascule (maquettes 01a et 01c) : le plan des métiers — trois
     lignes qui entrent TOUTES dans la polyvalence (le gérant maîtrise les trois
     métiers), puis le tronc et la franchise — et les effectifs, avec le vivier.
     Données : GET /formation/carte (src/parcours.php, pcCarte) — des comptes, aucun nom. */
  var COUL = { vente: '#8D1D2C', boul: '#b07a2a', trait: '#3f7a52' };
  var TRONC = '#47423c';
  function pers(nb) { return '<span class="fm-st__pp' + (nb ? '' : ' is-zero') + '">' + (nb ? nb + ' pers.' : 'personne') + '</span>'; }

  function carteMetro(C) {
    var ys = [110, 280, 450], svg = '', st = '';
    var station = function (x, y, coul, href, nom, dt, nb, extra, mx, vide, w) {
      w = w || 104;
      return '<a class="fm-st" href="' + href + '" style="left:' + (x - w / 2) + 'px;width:' + w + 'px;top:' + (y - (mx ? 11 : 9)) + 'px;color:' + coul + '">' +
        '<div class="fm-st__dot' + (mx ? ' is-mx' : '') + '" style="border-color:' + coul + '"></div><div class="fm-st__nm">' + esc(nom) + '</div>' +
        '<div class="fm-st__dt' + (vide ? ' is-vide' : '') + '">' + dt + '</div>' + (extra || '') + pers(nb) + '</a>';
    };
    C.chemin.metiers.forEach(function (m, k) {
      var y = ys[k] || ys[2], coul = COUL[m.cle] || TRONC, nb = m.niveaux.length;
      svg += '<path d="M30,' + y + ' L440,' + y + '" stroke="' + coul + '" stroke-width="7" fill="none" stroke-linecap="round"/>';
      st += '<div class="fm-ligne-nom" style="left:18px;top:' + (y - 34) + 'px;color:' + coul + '">' + esc(m.libelle) + '</div>';
      m.niveaux.forEach(function (n, i) {
        var x = 60 + i * (300 / Math.max(nb - 1, 1));
        st += station(x, y, coul, '#/postes/' + m.id + '/' + n.id, n.nom, pl(n.total, 'compétence'), n.personnes,
          i === m.maitrise ? '<span class="fm-st__mt" style="background:' + coul + '">maîtrise</span><br>' : '', i === m.maitrise);
      });
    });
    var P = C.polyvalence;
    var hub = '<div class="fm-hub" style="left:440px;top:62px;width:185px;height:446px"><div class="fm-hub__k">Pour encadrer</div><div class="fm-hub__t">Maîtriser les trois métiers</div>' +
      P.metiers.map(function (m) { return '<div class="fm-hub__m"><i style="background:' + (COUL[m.cle] || TRONC) + '"></i>' + esc(m.libelle) + '<b>' + n(m.competences) + '</b></div>'; }).join('') +
      '<div class="fm-hub__s"><span>Polyvalence</span><span>' + n(P.competences) + '</span></div><div class="fm-hub__n">compétences au total (sans doublon), jusqu’au niveau « maîtrise » de chaque métier.<br><br>' +
      'Le chef de section reste dans son métier ; le gérant, lui, tient les trois.<br><br><strong>' + n(P.paliers['3']) + '</strong> personne' + (P.paliers['3'] > 1 ? 's' : '') + ' les maîtrise' + (P.paliers['3'] > 1 ? 'nt' : '') + ' aujourd’hui.</div></div>';
    svg += '<path d="M625,280 L1092,280" stroke="' + TRONC + '" stroke-width="7" fill="none" stroke-linecap="round"/>';
    var total = P.competences;
    C.chemin.tronc.forEach(function (x, k) {
      var nv = x.niveaux[x.niveaux.length - 1];
      if (!nv) return;
      total += nv.nouvelles;
      st += station([690, 790][k] || 790, 280, TRONC, '#/postes/' + x.id + '/' + nv.id, x.nom, '+' + nv.nouvelles + ' · <b>' + total + ' au total</b>', nv.personnes, '', false, false, 92);
    });
    var entre = C.chemin.entrepreneur.filter(function (x) { return x.code !== 'P9'; }), loc = C.chemin.entrepreneur.filter(function (x) { return x.code === 'P9'; })[0];
    var xs = [900, 995, 1092];
    entre.forEach(function (x, k) {
      var nv = x.niveaux[x.niveaux.length - 1];
      if (!nv) return;
      st += station(xs[k] || 1092, 280, TRONC, '#/postes/' + x.id + '/' + nv.id, x.nom, nv.nouvelles ? '+' + pl(nv.nouvelles, 'compétence') : 'compétences à définir', nv.personnes, '', false, !nv.nouvelles, 92);
    });
    if (loc && loc.niveaux.length) {
      var nl = loc.niveaux[loc.niveaux.length - 1];
      svg += '<path d="M900,280 C900,180 910,120 960,120 L995,120" stroke="' + TRONC + '" stroke-width="5" stroke-dasharray="2 9" fill="none" stroke-linecap="round"/>';
      st += station(995, 120, TRONC, '#/postes/' + loc.id + '/' + nl.id, loc.nom, nl.nouvelles ? '+' + pl(nl.nouvelles, 'compétence') : 'compétences à définir', nl.personnes, '', false, !nl.nouvelles, 92);
    }
    var zones = '<div class="fm-zone" style="left:0;width:650px"><b>Opérationnel</b></div><div class="fm-zone fm-zone--c" style="left:650px;width:200px"><b>Cadre et direction</b></div>' +
      '<div class="fm-zone fm-zone--e" style="left:850px;width:300px"><b>Devenir entrepreneur</b></div>';
    return '<div class="fm-metro-wrap"><div class="fm-metro">' + zones + '<svg width="1150" height="560" aria-hidden="true">' + svg + '</svg>' + hub + st + '</div></div>' +
      '<div class="fm-legende">' + C.chemin.metiers.map(function (m) { return '<span><i style="background:' + (COUL[m.cle] || TRONC) + '"></i>' + esc(m.libelle) + '</span>'; }).join('') +
      '<span><i style="background:' + TRONC + '"></i>Tronc commun</span><span>◎ niveau de maîtrise du métier</span>' + (loc ? '<span>· · · Locataire-gérant : l’autre voie que la franchise</span>' : '') + '</div>' +
      (C.chemin.reseau.length ? '<p class="muted fm-small" style="margin-top:12px"><strong>Dans la marque — postes du franchiseur</strong>, hors du chemin des magasins (' + C.chemin.reseau.length + ') : ' +
        C.chemin.reseau.map(function (x) { return '<a href="#/postes/' + x.id + '">' + esc(x.nom) + '</a>'; }).join(', ') + '</p>' : '');
  }

  function carteEffectifs(C) {
    var E = C.effectifs, P = C.polyvalence, tot = Math.max(E.actifs, 1);
    var nbDe = function (liste) { return liste.reduce(function (a, x) { return a + x.niveaux.reduce(function (b, nv) { return b + nv.personnes; }, 0); }, 0); };
    var parts = [[nbDe(C.chemin.metiers), 'opérationnel', 'var(--accent)'], [nbDe(C.chemin.tronc), 'cadre et direction', '#a8665f'],
      [nbDe(C.chemin.entrepreneur), 'entrepreneurs', '#c9a227'], [E.sansPoste, 'sans poste dans le panel', '#d9d3ca']];
    var h = '<div class="fm-hero"><div><h2>' + pl(E.actifs, 'personne active', 'personnes actives') + ' dans ' + pl(E.magasins, 'magasin') + '</h2>' +
      '<p class="muted">' + n(E.places) + ' placée' + (E.places > 1 ? 's' : '') + ' sur la carte ; ' + n(E.sansPoste) + ' sans poste dans le panel.</p>' +
      '<div class="fm-pile">' + parts.map(function (x) { var w = x[0] * 100 / tot; return x[0] ? '<div style="width:' + w + '%;background:' + x[2] + (x[2] === '#d9d3ca' ? ';color:var(--muted-2)' : '') + '">' + (w >= 4 ? n(x[0]) : '') + '</div>' : ''; }).join('') + '</div>' +
      '<div class="fm-legende" style="margin-top:8px">' + parts.map(function (x) { return '<span><i style="background:' + x[2] + ';height:10px;width:10px;border-radius:3px"></i>' + n(x[0]) + ' ' + x[1] + '</span>'; }).join('') + '</div></div>' +
      (E.sansPoste ? '<a class="filter is-on" href="#/equipes">' + (E.sansPoste > 1 ? 'Placer les ' + n(E.sansPoste) + ' personnes' : 'Placer la personne') + ' →</a>' : '') + '</div>';
    // Le vivier de gérants : qui maîtrise les trois métiers.
    var totP = Math.max(P.paliers['3'] + P.paliers['2'] + P.paliers['1'] + P.paliers['0'], 1);
    var palier = function (lib, v, col) { return '<div class="fm-palier"><span>' + lib + '</span><div class="fm-palier__bar"><i style="width:' + Math.max(v * 100 / totP, v ? 2 : 0) + '%;background:' + col + '"></i></div><b>' + n(v) + ' pers.</b></div>'; };
    h += '<div class="fm-carte-tete"><div><h2>Le vivier de gérants</h2><p>Pour encadrer un magasin, il faut maîtriser les trois métiers. Qui est prêt, qui y est presque.</p></div></div>' +
      '<div class="fm-vivier"><section class="card"><h2>Polyvalence</h2>' + palier('Les 3 métiers', P.paliers['3'], 'var(--ink)') + palier('2 métiers', P.paliers['2'], '#7a736a') +
      palier('1 métier', P.paliers['1'], '#b8b2a8') + palier('Aucun encore', P.paliers['0'], '#e1dbd2') +
      '<p class="muted fm-small" style="margin-top:10px">' + (P.paliers['3'] ? '<strong>' + pl(P.paliers['3'], 'personne') + '</strong> prête' + (P.paliers['3'] > 1 ? 's' : '') + ' pour Adjoint de Manager.' : 'Personne ne maîtrise encore les trois métiers.') + '</p></section>' +
      '<section class="card"><h2>Métier par métier</h2>' + P.metiers.map(function (m) { return palier(esc(m.libelle), m.personnes, COUL[m.cle] || TRONC); }).join('') +
      '<p class="muted fm-small" style="margin-top:10px">Maîtriser un métier : toutes ses compétences jusqu’au niveau de maîtrise (' + P.metiers.map(function (m) { return esc(m.niveau); }).filter(function (x, i, a) { return a.indexOf(x) === i; }).join(', ') + ').</p></section></div>' +
      (P.avecCompetences < E.actifs / 2 ? bandeau('Dans le panel, <strong>' + n(P.avecCompetences) + ' personne' + (P.avecCompetences > 1 ? 's' : '') + ' sur ' + n(E.actifs) + '</strong> ont des compétences validées : le vivier se remplira avec les parcours et la validation en magasin.', '') : '');
    // Où est l'équipe.
    var cartePoste = function (x, titre) {
      var segs = x.niveaux.map(function (nv, i) {
        return '<div><div class="fm-segs__b' + (nv.personnes ? ' is-p' : '') + (i === x.maitrise ? ' is-mx' : '') + '">' + n(nv.personnes) + '</div><div class="fm-segs__l">' + esc(nv.nom) + (i === x.maitrise ? '<br><b>maîtrise</b>' : '') + '</div></div>';
      }).join('');
      var der = x.niveaux[x.niveaux.length - 1];
      var cond = (x.code === 'P7' || x.code === 'P8') ? '<div class="fm-cond">Condition : maîtriser les 3 métiers<div class="fm-trois">' + C.chemin.metiers.map(function (m) { return '<span style="background:' + (COUL[m.cle] || TRONC) + '">' + esc(m.libelle.split(' –')[0]) + '</span>'; }).join('') + '</div></div>' : '';
      return '<a class="fm-pc3" href="#/postes/' + x.id + '"><div class="fm-pc3__t">' + esc(titre || x.nom) + '<span>' + n(x.niveaux.reduce(function (a, nv) { return a + nv.personnes; }, 0)) + ' pers.</span></div>' +
        '<div class="fm-segs">' + segs + '</div><div class="fm-pc3__d">' + (der && der.total ? pl(der.total, 'compétence') + ' au dernier niveau · ' + pct(der.avecFormation, der.total) + ' % avec formation' : '<span style="color:var(--danger)">compétences à définir</span>') + '</div>' + cond + '</a>';
    };
    h += '<div class="fm-carte-tete"><div><h2>Où est l’équipe</h2><p>Chaque poste, ses niveaux de gauche à droite ; dans chaque case, le nombre de personnes à ce niveau aujourd’hui. Encadré : le niveau de maîtrise du métier.</p></div></div>' +
      '<div class="fm-cols3"><div><p class="fm-col__t">Opérationnel</p>' + C.chemin.metiers.map(function (m) { return cartePoste(m, m.libelle); }).join('') + '</div>' +
      '<div><p class="fm-col__t">Cadre et direction</p>' + C.chemin.tronc.map(function (x) { return cartePoste(x); }).join('') + (C.chemin.reseau.length ? '<p class="muted fm-small">Dans la marque (' + C.chemin.reseau.length + ' postes du franchiseur) : hors du chemin des magasins.</p>' : '') + '</div>' +
      '<div><p class="fm-col__t">Devenir entrepreneur</p>' + C.chemin.entrepreneur.map(function (x) { return cartePoste(x); }).join('') + '</div></div>';
    return h;
  }

  function ecranTableau() {
    var R = S.ref, C = S.carte, vue = S.carteVue === 'effectifs' ? 'effectifs' : 'plan';
    var h = bandeauLiens();
    if (vue === 'plan') {
      var E = C && C.effectifs;
      h += '<div class="fm-kpis">' +
        kpi('#/equipes', E ? n(E.actifs) : '…', 'Personnes actives', E ? n(E.places) + ' placées sur la carte · ' + pl(E.magasins, 'magasin') : 'lecture des équipes…') +
        kpi('#/postes', n(R.postes.filter(function (p) { return p.id != null; }).length), 'Postes', pl(R.postes.reduce(function (a, p) { return a + p.niveaux.length; }, 0), 'niveau')) +
        kpi('#/competences', n(R.competences.length), 'Compétences', C ? n(C.manques.competences - C.manques.sansFormation) + ' ont une formation' : '') +
        kpi('#/formations', S.v25 ? n(S.v25.formations.length) : '…', 'Formations', pl(R.formations.length, 'évaluation') + ' dans le panel') + '</div>';
    }
    h += '<div class="fm-carte-tete"><div><h2>' + (vue === 'plan' ? 'Le chemin des métiers' : 'Les équipes sur la carte') + '</h2><p>' + (vue === 'plan' ?
      'On apprend un métier sur sa ligne. Pour encadrer un magasin, les <strong>trois</strong> lignes sont nécessaires : le gérant maîtrise la vente, la boulangerie-pâtisserie et le traiteur. Ensuite, le tronc : adjoint, manager, puis la franchise.' :
      'Combien de personnes à chaque niveau, et qui peut encadrer demain.') + '</p></div>' +
      '<div class="fm-seg" role="group" aria-label="Vue de la carte"><button type="button" data-act="carte-vue" data-v="plan"' + (vue === 'plan' ? ' class="is-on" aria-pressed="true"' : '') + '>Plan des métiers</button>' +
      '<button type="button" data-act="carte-vue" data-v="effectifs"' + (vue === 'effectifs' ? ' class="is-on" aria-pressed="true"' : '') + '>Effectifs</button></div></div>';
    if (S.carteErreur) h += '<p class="error">La carte n’a pas pu être lue : ' + esc(S.carteErreur) + '</p>';
    else if (!C) h += bandeau('Lecture de la carte : le référentiel, puis les équipes de chaque magasin (la première fois, une minute)…', '', true);
    else h += vue === 'plan' ? carteMetro(C) : carteEffectifs(C);

    // Ce qui manque — commun aux deux vues.
    if (C) {
      var M = C.manques;
      h += '<h2 class="fm-section-titre">Ce qui manque</h2><div class="fm-alertes">' +
        '<a class="fm-alerte' + (C.effectifs.sansPoste ? '' : ' is-ok') + '" href="#/equipes"><b>' + n(C.effectifs.sansPoste) + '</b><span>' + (C.effectifs.sansPoste > 1 ? 'personnes' : 'personne') + ' sans poste dans le panel</span><small>Elles n’apparaissent pas sur la carte : à placer.</small></a>' +
        '<a class="fm-alerte is-w" href="#/competences" data-act="voir-comp" data-v="sansFormation"><b>' + n(M.sansFormation) + '</b><span>compétences sans formation</span><small>À rattacher à une FO.</small></a>' +
        '<a class="fm-alerte" href="#/competences" data-act="voir-comp" data-v="sansProcedure"><b>' + n(M.sansProcedure) + ' / ' + n(M.competences) + '</b><span>compétences sans procédure du manuel</span><small>' + (M.sansProcedure === M.competences ? 'Le manuel n’est relié à aucune compétence.' : 'À relier au manuel opératoire.') + '</small></a>' +
        (M.niveauxVides.length ? '<a class="fm-alerte" href="#/postes"><b>' + n(M.niveauxVides.length) + '</b><span>niveaux sans compétence</span><small>' + esc(M.niveauxVides.join(', ')) + '</small></a>' : '') + '</div>';
    }
    // Les parcours en cours, en bref.
    var actifs = S.pcListe ? S.pcListe.filter(function (p) { return p.statut === 'actif'; }) : null;
    if (actifs && actifs.length) {
      h += '<h2 class="fm-section-titre">Parcours en cours</h2><section class="card"><ul class="fm-todo">' + actifs.slice(0, 6).map(function (p) {
        var x = p.total ? Math.round(p.validees * 100 / p.total) : 0;
        return '<li><a href="parcours/#/p/' + p.id + '"><span class="fm-todo__n is-ok">' + x + '%</span><span><strong>' + esc(p.nom) + '</strong> → ' + esc(p.cibleNom) +
          (p.criteres ? ' <span class="muted fm-small">· admission ' + p.admis + '/' + p.criteres + '</span>' : '') + '</span><span class="fm-todo__go">' + (p.retard ? 'en retard' : 'Voir →') + '</span></a></li>';
      }).join('') + '</ul>' + (actifs.length > 6 ? '<p class="muted fm-small" style="margin-top:8px"><a href="#/parcours">Les ' + actifs.length + ' parcours →</a></p>' : '') + '</section>';
    }
    h += '<details class="fm-tech"><summary>Détail technique : ce que le panel a rendu</summary>' + carteAppels() + '</details>';
    return { titre: 'Carte des métiers', corps: h };
  }

  /** Ce que le panel a rendu, route par route : un vide et un 403 ne se confondent pas. */
  function carteAppels() {
    var a = (S.ref.appels || []).concat(S.liens && S.liens.appels ? S.liens.appels : []);
    var h = '<section class="card table-card"><h2>Ce que le panel a rendu</h2><p class="muted" style="margin-bottom:10px">Source : ' + esc(S.ref.source.base) +
      ' · lu le ' + date((S.ref.cache && S.ref.cache.le) || S.ref.source.lu) + (S.ref.cache && !S.ref.cache.frais ? ' (copie en cache, relue en arrière-plan)' : '') + '.</p>';
    h += '<div class="table-scroll"><table><thead><tr><th>Lecture</th><th>Route du panel</th><th class="num">HTTP</th><th class="num">Lignes</th><th class="num">Durée</th><th>Remarque</th></tr></thead><tbody>';
    a.forEach(function (x) {
      var ok = x.code >= 200 && x.code < 300;
      var codes = x.codes ? Object.keys(x.codes).map(function (k) { return k + ' × ' + x.codes[k]; }).join(', ') : '';
      h += '<tr><td>' + esc(x.cle) + '</td><td>' + chemin(x.chemin) + '</td>' +
        '<td class="num"><span class="chip ' + (ok ? 'chip--ok' : 'chip--ko') + '">' + esc(x.code == null ? '—' : x.code) + '</span></td>' +
        '<td class="num">' + (x.ok != null ? n(x.ok) + ' / ' + n(x.n) + ' appels' : x.n == null ? '—' : n(x.n)) + '</td>' +
        '<td class="num fm-nowrap">' + n(x.ms) + ' ms</td><td class="muted fm-small">' + esc(x.erreur || codes) + '</td></tr>';
    });
    h += '</tbody></table></div></section>';
    return h;
  }

  /* ── Postes & sous-postes ───────────────────────────────────────────── */

  function ecranPostes() {
    var R = S.ref;
    if (!R.postes.length) {
      return { titre: TITRES.postes, corps: bandeauLiens() + '<section class="card pending"><span class="chip pending__chip">Aucun poste</span><h2>Le panel ne rend aucun poste</h2><p class="muted">La route <code>/positions</code> a répondu sans ligne. Voir le tableau de bord, « Ce que le panel a rendu ».</p></section>' };
    }
    var pid = arg(0), nid = arg(1);
    // #/postes/v25/P12 : un poste du référentiel v25 que le panel n'a pas encore (Franchisé multi-boutique…).
    var horsPanel = S.route.a[0] === 'v25' ? String(S.route.a[1] || '') : null;
    var poste = horsPanel !== null ? null : (pid != null && I.posteById.get(pid)) || (S.route.a[0] === undefined ? posteDefaut() : R.postes.filter(function (p) { return p.id == null; })[0]) || posteDefaut();
    var niveau = !poste ? null : nid != null ? I.niveauById.get(nid) : poste.niveaux[0];

    var lignePoste = function (p) {
      var on = p === poste;
      var nComp = new Set(); p.niveaux.forEach(function (nv) { nv.competences.forEach(function (c) { nComp.add(c); }); });
      var x = '<a class="fm-tree__poste' + (on ? ' is-active' : '') + '" href="#/postes/' + (p.id != null ? p.id : 'sans') + '"><span>' + esc(p.nom) + '</span><span class="fm-tree__n">' + n(p.niveaux.length) + ' niv.</span></a>';
      if (on) {
        x += '<ul class="fm-tree__niveaux">' + p.niveaux.map(function (nv, i) {
          return '<li><a class="fm-tree__niveau' + (niveau === nv ? ' is-active' : '') + '" href="#/postes/' + (p.id != null ? p.id : 'sans') + '/' + nv.id + '"><span>' + (i + 1) + ' · ' + esc(nv.nom) + '</span><span class="fm-tree__n">' + n(nv.competences.length) + '</span></a></li>';
        }).join('') + (p.niveaux.length ? '' : '<li class="muted fm-small" style="padding:6px 10px">Aucun niveau</li>') + '</ul>';
      }
      return x;
    };
    var ligneV25 = function (x) {
      return '<a class="fm-tree__poste is-v25' + (horsPanel === x.code ? ' is-active' : '') + '" href="#/postes/v25/' + esc(x.code) + '" title="Pas encore dans le panel"><span>' + esc(x.nom) + '</span><span class="fm-tree__n">à créer</span></a>';
    };
    var arbre = '<section class="card fm-tree">';
    if (!S.v25) arbre += R.postes.map(lignePoste).join('');
    else {
      // Deux portées : dans la franchise (le magasin, par famille), dans la marque (le franchiseur).
      var pf = postesParFamille();
      var groupe = function (f) { return pf[f[0]].length ? '<div class="fm-tree__famille">' + esc(f[1]) + '</div>' + pf[f[0]].map(function (x) { return x.v25 ? ligneV25(x.v25) : lignePoste(x.poste); }).join('') : ''; };
      arbre += '<div class="fm-tree__portee">Dans la franchise<small>le magasin</small></div>' + FAMILLES.map(groupe).join('');
      if (pf.marque.length) arbre += '<div class="fm-tree__portee fm-tree__portee--marque">Dans la marque<small>le franchiseur</small></div>' + pf.marque.map(function (x) { return lignePoste(x.poste); }).join('');
      if (pf.autres.length) arbre += groupe(['autres', 'Autres']);
    }
    arbre += '</section>';

    var droite;
    if (horsPanel !== null) {
      droite = ficheHorsPanel(horsPanel);
    } else if (!niveau) {
      var famP = S.v25 ? familleDe(poste) : null;
      droite = '<section class="card pending"><span class="chip pending__chip">Sans niveau</span>' + (famP && famP !== 'autres' ? ' <span class="fm-tag' + (famP === 'marque' ? ' fm-tag--marque' : ' fm-tag--franchise') + '">' + esc(nomFamille(famP)) + '</span>' : '') + '<h2>' + esc(poste.nom) + '</h2><p class="muted">Ce poste n’a aucun niveau dans le panel (<code>/positions/' + esc(poste.id) + '/levels</code>).</p></section>';
    } else {
      droite = ficheNiveau(poste, niveau);
    }
    return { titre: TITRES.postes, corps: bandeauLiens() + '<div class="fm-split">' + arbre + '<div>' + droite + '</div></div>' };
  }

  /* Le panel ne liste, à chaque niveau, que ses NOUVELLES compétences (mesuré le 31/10/2026) :
     ce qu'il faut maîtriser à un niveau = le cumul des niveaux du poste jusqu'à lui. */
  function cumul(poste, niveauId) {
    var s = new Set(), fini = false;
    poste.niveaux.forEach(function (nv) { if (fini) return; nv.competences.forEach(function (c) { s.add(c); }); if (nv.id === niveauId) fini = true; });
    return s;
  }
  function catDe(c) { var k = (c && c.categorie) || ''; return !k || /^\d+$/.test(k) ? 'À classer' : k; }
  /** Les codes FO qui enseignent une compétence (via ses formations du panel « [FO-xx] … »). */
  function foDe(cid) {
    var l = liensDe(cid), o = [];
    (l ? l.formations : []).forEach(function (f) { var k = codeFo(f.nom); if (k && o.indexOf(k) < 0) o.push(k); });
    return o.sort(function (a, b) { return +a.slice(3) - +b.slice(3); });
  }
  function tagsFo(cid) {
    if (!I.liens) return '<span class="muted fm-small">…</span>';
    var f = foDe(cid), l = liensDe(cid);
    if (f.length) return f.slice(0, 2).map(function (k) { return '<a class="chip chip--soft" href="#/formations/' + k + '">' + k + '</a>'; }).join('');
    if (l && l.formations.length) return '<a class="chip chip--soft" href="#/formations/' + l.formations[0].id + '">' + esc(court(l.formations[0].nom, 26)) + '</a>';
    return '<span class="chip chip--ko">sans formation</span>';
  }

  function ficheNiveau(poste, niveau) {
    var pid = poste.id != null ? poste.id : 'sans';
    var rang = poste.niveaux.indexOf(niveau);
    var tout = cumul(poste, niveau.id);
    var nouv = niveau.competences.slice();
    var avant = Array.from(tout).filter(function (c) { return nouv.indexOf(c) < 0; });
    var vue = S.ficheVue === 'avant' || S.ficheVue === 'tout' ? S.ficheVue : 'nouveau';
    var ids = vue === 'nouveau' ? nouv : vue === 'avant' ? avant : Array.from(tout);
    var fam = S.v25 ? familleDe(poste) : null;
    var h = '<div class="fm-head"><div><h2 class="screen-subtitle">' + esc(poste.nom) + ' › ' + esc(niveau.nom) + '</h2>' +
      '<p class="muted">' + (fam && fam !== 'autres' ? '<span class="fm-tag' + (fam === 'marque' ? ' fm-tag--marque' : ' fm-tag--franchise') + '" style="margin:0 8px 0 0">' + esc(nomFamille(fam)) + '</span>' : '') + 'Niveau ' + (rang + 1) + ' sur ' + poste.niveaux.length + ' · ' + pl(tout.size, 'compétence') + ' à maîtriser, dont ' + n(nouv.length) + ' nouvelle' + (nouv.length > 1 ? 's' : '') + (niveau.description ? ' · ' + esc(niveau.description) : '') + '</p></div>' +
      '<div class="fm-links">' +
      '<a class="filter" href="#/manuel/poste/' + pid + '/' + niveau.id + '">Manuel</a>' +
      '<a class="filter" target="_blank" rel="noopener" href="' + API + '/formation/pdf?type=niveau&id=' + niveau.id + '">Fiche PDF ↗</a>' +
      bouton('niv-ouvrir', 'niv-ouvrir', '+ Exiger une compétence', ' data-nid="' + niveau.id + '"', 'is-on') + '</div></div>';
    // L'escalier du poste : chaque marche, ce qu'elle ajoute et le total.
    h += '<div class="fm-escalier">' + poste.niveaux.map(function (nv, i) {
      return (i ? '<span class="fm-escalier__x">›</span>' : '') + '<a class="' + (nv === niveau ? 'is-on' : i < rang ? 'is-fait' : '') + '" href="#/postes/' + pid + '/' + nv.id + '">' +
        '<span class="fm-escalier__t">' + esc(nv.nom) + '</span><span class="fm-escalier__d">+' + n(nv.competences.length) + ' · ' + n(cumul(poste, nv.id).size) + ' au total</span></a>';
    }).join('') + '</div>';
    if (niveau.competencesLues === false) h += bandeau('Compétences du niveau illisibles : ' + esc(niveau.competencesErreur || 'erreur du panel') + '.', 'error');

    // À gauche : ce qu'il faut savoir faire.
    var g = '<section class="card"><h2>Ce qu’il faut savoir faire</h2><div class="fm-seg fm-seg--petit" role="group">' +
      [['nouveau', 'Nouveau à ce niveau', nouv.length], ['avant', 'Acquis avant', avant.length], ['tout', 'Tout', tout.size]].map(function (v) {
        return '<button type="button" data-act="fiche-vue" data-v="' + v[0] + '"' + (vue === v[0] ? ' class="is-on"' : '') + '>' + v[1] + ' · ' + n(v[2]) + '</button>';
      }).join('') + '</div>';
    var parCat = {};
    ids.forEach(function (cid) { var c = I.comp.get(cid) || { id: cid, nom: 'Compétence #' + cid }; (parCat[catDe(c)] = parCat[catDe(c)] || []).push(c); });
    var cats = Object.keys(parCat).sort(function (a, b) { return parCat[b].length - parCat[a].length || a.localeCompare(b, 'fr'); });
    if (!ids.length) g += '<p class="muted" style="margin-top:6px">' + (vue === 'avant' ? 'Premier niveau du poste : rien avant.' : 'Aucune compétence exigée à ce niveau.') + '</p>';
    cats.forEach(function (k) {
      g += '<div class="fm-cat">' + esc(k) + ' · ' + parCat[k].length + '</div>' + parCat[k].sort(function (a, b) { return a.nom.localeCompare(b.nom, 'fr'); }).map(function (c) {
        var propre = nouv.indexOf(c.id) >= 0;
        return '<div class="fm-comp"><span class="fm-comp__ck"></span><a href="#/competences/' + c.id + '">' + esc(c.nom) + '</a><span class="fm-comp__tags">' + tagsFo(c.id) + '</span>' +
          (propre ? bouton('niv-' + niveau.id + '-' + c.id, 'niv-retirer', '×', ' data-nid="' + niveau.id + '" data-cid="' + c.id + '" title="Ne plus exiger cette compétence à ce niveau"', 'fm-x') : '<span></span>') + '</div>';
      }).join('');
    });
    g += '</section>';

    // À droite : comment l'apprendre, comment y accéder, et après.
    var couvre = {}, sansFo = 0, autres = new Map();
    nouv.forEach(function (cid) {
      var f = I.liens ? foDe(cid) : [];
      f.forEach(function (k) { couvre[k] = (couvre[k] || 0) + 1; });
      if (I.liens && !liensDe(cid).formations.length) sansFo++;
      if (I.liens && !f.length) liensDe(cid).formations.forEach(function (x) { autres.set(x.id, x); });
    });
    var evals = {};
    (formationsFo() || []).forEach(function (x) { evals[x.code] = x.evaluations.length; });
    var codes = Object.keys(couvre).sort(function (a, b) { return +a.slice(3) - +b.slice(3); });
    var d2 = '<section class="card"><h2>Pour les apprendre</h2>';
    if (!I.liens) d2 += attente('Liens des compétences en cours de lecture…');
    else if (!codes.length && !autres.size) d2 += '<p class="muted">Aucune formation ne couvre les compétences nouvelles de ce niveau.</p>';
    else {
      d2 += '<p class="muted fm-small" style="margin:-4px 0 4px">Dans l’ordre du parcours.</p>' + codes.map(function (k) {
        return '<a class="fm-fo" href="#/formations/' + k + '"><span class="fm-fo__code">' + k + '</span><span><span class="fm-fo__t">' + esc(nomFo(k).replace(/^Formation\s+/, '')) + '</span>' +
          '<span class="fm-fo__d">' + (evals[k] ? pl(evals[k], 'évaluation') + ' · ' : '') + 'couvre ' + pl(couvre[k], 'compétence') + ' de ce niveau</span></span></a>';
      }).join('') + Array.from(autres.values()).map(function (x) {
        return '<a class="fm-fo" href="#/formations/' + x.id + '"><span class="fm-fo__code">—</span><span><span class="fm-fo__t">' + esc(x.nom) + '</span><span class="fm-fo__d">formation du panel, sans code FO</span></span></a>';
      }).join('');
    }
    if (sansFo) d2 += '<div class="fm-encart"><strong>' + pl(sansFo, 'compétence') + '</strong> de ce niveau sans formation : à rattacher.</div>';
    d2 += '</section>';
    var dn = S.niveaux[niveau.id];
    var ap = dn && dn.data ? (dn.data.appels || []).filter(function (a) { return a.cle === 'exigences'; })[0] : null;
    var note = (rang > 0 ? '<p style="margin:0 0 10px;font-size:14px">Avoir validé <strong>' + esc(poste.niveaux[rang - 1].nom) + '</strong> (' + pl(avant.length, 'compétence') + ').</p>' : '') +
      (!dn || dn.charge ? attente('Lecture des exigences du panel…') : dn.erreur ? '<p class="error">' + esc(dn.erreur) + '</p>' :
        ap && !(ap.code >= 200 && ap.code < 300) ? '<p class="muted">Exigences du panel indisponibles : ' + chemin(ap.chemin) + ' → HTTP ' + esc(ap.code) + '</p>' : '');
    var code = S.v25 && S.v25.niveauxV25 ? S.v25.niveauxV25[niveau.id] : null;
    d2 += carteCriteres(criteresAdmission(code, dn && dn.data ? dn.data.exigences : []), note).replace('<h2>Critères d’admission</h2>', '<h2>Pour y accéder</h2>');
    var suivant = poste.niveaux[rang + 1];
    d2 += '<section class="card"><h2>Ensuite</h2>' + (suivant ? '<p style="margin:0;font-size:14px"><a href="#/postes/' + pid + '/' + suivant.id + '"><strong>' + esc(suivant.nom) + '</strong></a> : +' +
      pl(suivant.competences.length, 'compétence') + ', ' + n(cumul(poste, suivant.id).size) + ' au total.</p>' : '<p class="muted">Dernier niveau du poste.</p>') + '</section>';
    var taches = dn && dn.data ? dn.data.taches || [] : [];
    if (taches.length) d2 += '<section class="card"><h2>Tâches du niveau</h2><ul>' + taches.map(function (r) { return '<li>' + esc(r.nom) + '</li>'; }).join('') + '</ul></section>';
    return h + '<div class="fm-deux"><div>' + g + '</div><div>' + d2 + '</div></div>';
  }

  /**
   * Les critères d'admission d'un poste, sans doublon (comme src/parcours.php, pcCriteres) :
   * les exigences du niveau dans le panel d'abord, puis le référentiel du cockpit
   * (src/data/admission.json) ; une formation déjà exigée par le panel n'est pas répétée.
   */
  function criteresAdmission(code, exigences) {
    var o = [], parPanel = [];
    (exigences || []).forEach(function (e) {
      if (!e.nom || e.nom === '—') return;
      var fo = codeFo(e.nom);
      if (fo) parPanel.push(fo);
      o.push({ libelle: e.nom, detail: e.description, type: fo ? 'fo' : 'manuel', fo: fo ? [fo] : [], source: 'panel' });
    });
    var def = code && S.v25 && S.v25.admission ? S.v25.admission[code] : null;
    (def ? def.criteres : []).forEach(function (c) {
      var fo = (c.fo || []).filter(function (x) { return parPanel.indexOf(x) < 0; });
      if (c.type === 'fo' && !fo.length) return;
      o.push({ libelle: c.libelle, detail: c.detail, type: c.type, fo: fo, source: 'referentiel' });
    });
    return o;
  }
  function nomFo(code) {
    var f = S.v25 ? S.v25.formations.filter(function (x) { return x.code === code; })[0] : null;
    return f ? f.nom : code;
  }
  function carteCriteres(crit, note) {
    var h = '<section class="card"><h2>Critères d’admission</h2>' + (note || '');
    if (!crit.length) return h + (note ? '' : '<p class="muted">Aucun critère : on y entre par les compétences et les formations du niveau.</p>') + '</section>';
    h += '<ul class="fiche-ul">' + crit.map(function (c) {
      var comment = c.type === 'poste' ? 'vérifié dans le panel : le poste tenu' :
        c.type === 'metiers' ? 'vérifié dans le panel : toutes les compétences de la vente, de la boulangerie-pâtisserie et du traiteur jusqu’à leur niveau de maîtrise — les formations qui manquent s’ajoutent au parcours' :
        c.type === 'fo' ? 'vérifié : ' + c.fo.map(function (k) { return '<a href="#/formations/' + esc(k) + '">' + esc(k) + '</a> ' + esc(nomFo(k)); }).join(', ') + ' — ajoutée au parcours si elle manque' :
        'validé par la manager ou le consultant, dans le suivi du parcours';
      return '<li><strong>' + esc(c.libelle) + '</strong> <span class="chip ' + (c.source === 'panel' ? 'chip--accent' : 'chip--soft') + '">' + (c.source === 'panel' ? 'panel' : 'référentiel') + '</span>' +
        '<br><span class="muted fm-small">' + comment + (c.detail ? ' · ' + esc(court(c.detail, 140)) : '') + '</span></li>';
    }).join('') + '</ul>';
    return h + '<p class="muted fm-small" style="margin-top:10px">Tous remplis — et seulement alors — le parcours valide le niveau. Les exigences du panel passent en premier.</p></section>';
  }

  /** Un poste du référentiel v25 que le panel n'a pas encore : ce qu'il demande, d'où il vient. */
  function ficheHorsPanel(code) {
    if (!S.v25) return S.v25Erreur ? '<p class="error">' + esc(S.v25Erreur) + '</p>' : attente('Lecture du référentiel v25…');
    var hp = (S.v25.horsPanel || []).filter(function (x) { return x.code === code; })[0];
    if (!hp) return '<section class="card pending"><span class="chip pending__chip">Inconnu</span><h2>Poste ' + esc(code) + '</h2><p class="muted">Ce code n’est pas un poste du référentiel v25 absent du panel.</p></section>';
    var parAttendu = function (a) { return S.v25.formations.filter(function (f) { return f.attendu[code] === a; }); };
    var crit = criteresAdmission(code, []);
    var prerequis = [];
    crit.forEach(function (c) { (c.fo || []).forEach(function (k) { if (prerequis.indexOf(k) < 0) prerequis.push(k); }); });
    var oblig = parAttendu('O'), reco = parAttendu('R').filter(function (f) { return prerequis.indexOf(f.code) < 0; });
    prerequis.forEach(function (k) { if (!oblig.some(function (f) { return f.code === k; })) oblig.push({ code: k, nom: nomFo(k) }); });
    var plan = S.v25.plans.filter(function (p) { return p.code === ({ P12: 'PF-MULTI', P9: 'PF-GER' })[code]; })[0];
    var puce = function (f) { return '<li><a class="chip chip--soft" href="#/formations/' + esc(f.code) + '">' + esc(f.code) + ' · ' + esc(court(String(f.nom).replace(/^Formation /, ''), 46)) + '</a></li>'; };
    var h = '<div class="fm-head"><div><h2 class="screen-subtitle">' + esc(hp.nom) + '</h2><p class="muted">Référentiel v25 · ' + esc(code) + '</p></div>' +
      '<div class="fm-links">' + (code === 'P12' || code === 'P9' ? bouton('poste-' + code, 'poste-creer', 'Créer ce poste dans le panel', ' data-code="' + esc(code) + '" data-nom="' + esc(hp.nom) + '"', 'is-on') : '') +
      '<a class="filter" href="parcours/#/nouveau">Ouvrir un parcours ↗</a></div></div>';
    h += bandeau('Ce poste n’existe pas encore dans le panel : il vient du référentiel v25 (l’Excel). Une fois créé dans le panel, ses niveaux, compétences et exigences prendront le relais.', '');
    h += '<div class="grid grid--tight">' +
      '<section class="card"><h2>Critères</h2><p class="metric">' + n(crit.length) + '</p><p class="muted">pour y être admis</p></section>' +
      '<section class="card"><h2>Obligatoires</h2><p class="metric">' + n(oblig.length) + '</p><p class="muted">formations du chemin</p></section>' +
      '<section class="card"><h2>Recommandées</h2><p class="metric">' + n(reco.length) + '</p><p class="muted">pour aller plus loin</p></section>' +
      '<section class="card"><h2>Plan</h2><p class="metric">' + n(plan ? plan.blocs.length : 0) + '</p><p class="muted">' + esc(plan ? plan.nom : 'aucun plan') + '</p></section></div>';
    h += '<div class="fm-cols" style="margin-top:1rem">' + carteCriteres(crit, '') +
      '<section class="card"><h2>Formations obligatoires</h2>' + (oblig.length ? '<ul class="chips">' + oblig.map(puce).join('') + '</ul>' : '<p class="muted">Aucune.</p>') +
      (reco.length ? '<h3 class="fm-small muted" style="margin:14px 0 6px">Pour aller plus loin</h3><ul class="chips">' + reco.map(puce).join('') + '</ul>' : '') + '</section></div>';
    if (plan) {
      h += '<section class="card" style="margin-top:1rem"><h2>' + esc(plan.nom) + '</h2>' + (plan.logique ? '<p class="muted">' + esc(court(plan.logique, 260)) + '</p>' : '') +
        '<ul class="fiche-ul">' + plan.blocs.map(function (b) {
          return '<li><strong>' + esc(b.code + ' · ' + b.nom) + '</strong> <span class="muted fm-small">' + esc(b.formations.join(', ')) + '</span>' +
            (b.checklist.length ? '<br><span class="muted fm-small">' + b.checklist.map(esc).join(' · ') + '</span>' : '') + '</li>';
        }).join('') + '</ul></section>';
    }
    return h;
  }

  /** Le tableau des compétences, rangé par catégorie. */
  function tableCompetences(comps, o) {
    if (!comps.length) return '<p class="muted">Aucune compétence.</p>';
    var tri = comps.slice().sort(function (a, b) {
      return (a.categorie || '~').localeCompare(b.categorie || '~', 'fr') || (a.sousCategorie || '').localeCompare(b.sousCategorie || '', 'fr') || a.nom.localeCompare(b.nom, 'fr');
    });
    var col = 6 + (o.action ? 1 : 0);
    var h = '<div class="table-scroll"><table><thead><tr><th>Compétence</th><th>Sous-catégorie</th>' + (o.niveaux ? '<th>Exigée par</th>' : '<th>Vérification</th>') +
      '<th>Formations</th><th>Procédures</th><th class="num">Ressources</th>' + (o.action ? '<th class="num"></th>' : '') + '</tr></thead><tbody>';
    var cat = null;
    tri.forEach(function (c) {
      var k = c.categorie || 'Sans catégorie';
      if (k !== cat) { cat = k; h += '<tr class="fm-group"><td colspan="' + col + '">' + esc(k) + '</td></tr>'; }
      var l = liensDe(c.id);
      var nivs = I.niveauxParComp.get(c.id) || [];
      h += '<tr class="row-link" data-href="#/competences/' + c.id + '"><td>' + esc(c.nom) + (c.description ? '<p class="muted">' + esc(court(c.description, 150)) + '</p>' : '') + '</td>' +
        '<td>' + esc(c.sousCategorie || '—') + '</td>' +
        (o.niveaux ? '<td>' + (nivs.length ? chipsListe(nivs.map(function (id) { return { id: id, nom: niveauLibelle(id) }; }), 2, 'chip--accent') : '<span class="muted">aucun niveau</span>') + '</td>'
          : '<td>' + (c.verification ? esc(court(c.verification, 90)) : '<span class="muted">—</span>') + '</td>') +
        '<td>' + (l ? chipsListe(l.formations, 2) : '<span class="muted">…</span>') + '</td>' +
        '<td>' + (l ? chipsListe(l.procedures, 2) : '<span class="muted">…</span>') + '</td>' +
        '<td class="num">' + compte(l ? l.ressources : null) + '</td>' +
        (o.action ? '<td class="num fm-nowrap">' + o.action(c) + '</td>' : '') + '</tr>';
    });
    return h + '</tbody></table></div>';
  }

  /* ── Compétences ────────────────────────────────────────────────────── */

  function ecranCompetences() {
    var cid = arg(0);
    if (cid != null) return ficheCompetence(cid);
    var R = S.ref, f = S.f, q = norm(f.q);
    var liste = R.competences.filter(function (c) {
      if (q && norm(c.nom + ' ' + (c.description || '') + ' ' + (c.categorie || '') + ' ' + (c.sousCategorie || '')).indexOf(q) < 0) return false;
      if (f.categorie && (c.categorie || 'Sans catégorie') !== f.categorie) return false;
      var nivs = I.niveauxParComp.get(c.id) || [];
      var l = liensDe(c.id);
      if (f.vue === 'exigees' && !nivs.length) return false;
      if (f.vue === 'hors' && nivs.length) return false;
      if (f.vue === 'sansFormation' && (!l || l.formations.length)) return false;
      if (f.vue === 'sansProcedure' && (!l || l.procedures.length)) return false;
      return true;
    });
    var vues = [['tous', 'Toutes'], ['exigees', 'Exigées par un niveau'], ['hors', 'Hors niveau'], ['sansFormation', 'Sans formation'], ['sansProcedure', 'Sans procédure']];
    var bascule = '<div class="fm-seg" role="group" style="margin-left:auto"><button type="button" data-act="filtre" data-k="compVue" data-v="liste"' + (f.compVue !== 'matrice' ? ' class="is-on"' : '') + '>Liste</button>' +
      '<button type="button" data-act="filtre" data-k="compVue" data-v="matrice"' + (f.compVue === 'matrice' ? ' class="is-on"' : '') + '>Matrice</button></div>';
    if (f.compVue === 'matrice') return { titre: TITRES.competences, corps: bandeauLiens() + '<div class="filters"><div class="filters__row">' + bascule + '</div></div>' + matriceCompetences() };
    var h = bandeauLiens() + '<div class="filters"><div class="filters__row">' +
      '<input class="fm-search" type="search" placeholder="Rechercher une compétence…" data-input="q" value="' + esc(f.q) + '" />' +
      '<select class="fm-select" data-input="categorie"><option value="">Toutes les catégories</option>' +
      I.categories.map(function (c) { return '<option' + (f.categorie === c ? ' selected' : '') + '>' + esc(c) + '</option>'; }).join('') + '</select>' +
      bouton('comp+', 'comp-nouvelle', '+ Nouvelle compétence', '', 'is-on') + bascule + '</div>' +
      '<div class="filters__row">' + vues.map(function (v) {
        var off = (v[0] === 'sansFormation' || v[0] === 'sansProcedure') && !I.liens;
        return '<button type="button" class="filter' + (f.vue === v[0] ? ' is-on' : '') + '" data-act="filtre" data-k="vue" data-v="' + v[0] + '"' + (off ? ' disabled title="Les liens des compétences sont en cours de lecture"' : '') + '>' + esc(v[1]) + '</button>';
      }).join('') + '<span class="muted fm-small">' + pl(liste.length, 'compétence') + ' sur ' + n(R.competences.length) + '</span></div></div>';
    h += '<section class="card table-card" style="margin-top:0">' + tableCompetences(liste, { niveaux: true }) + '</section>';
    return { titre: TITRES.competences, corps: h };
  }

  /** Matrice compétences × niveaux d'un poste : un carré plein où elle entre, clair ensuite. */
  function matriceCompetences() {
    var postes = S.ref.postes.filter(function (p) { return p.id != null && p.niveaux.some(function (nv) { return nv.competences.length; }); });
    if (!postes.length) return '<p class="muted">Aucun poste n’a de compétence exigée.</p>';
    var p = postes.filter(function (x) { return String(x.id) === String(S.f.matPoste); })[0] || postes[0];
    var h = '<div class="fm-choix">' + postes.map(function (x) {
      return '<button type="button" class="filter' + (x === p ? ' is-on' : '') + '" data-act="filtre" data-k="matPoste" data-v="' + x.id + '">' + esc(x.nom) + '</button>';
    }).join('') + '</div>';
    var intro = new Map();
    p.niveaux.forEach(function (nv, k) { nv.competences.forEach(function (c) { if (!intro.has(c)) intro.set(c, k); }); });
    var parCat = {};
    intro.forEach(function (k, cid) { var c = I.comp.get(cid) || { id: cid, nom: 'Compétence #' + cid }; (parCat[catDe(c)] = parCat[catDe(c)] || []).push([k, c]); });
    var cats = Object.keys(parCat).sort(function (a, b) {
      var ma = Math.min.apply(null, parCat[a].map(function (x) { return x[0]; })), mb = Math.min.apply(null, parCat[b].map(function (x) { return x[0]; }));
      return ma - mb || a.localeCompare(b, 'fr');
    });
    h += '<div class="fm-legende" style="margin:0 0 12px"><span><i class="fm-pt2 is-new" style="width:14px;height:14px"></i>exigée à partir de ce niveau</span><span><i class="fm-pt2 is-acq" style="width:14px;height:14px"></i>déjà acquise</span>' +
      '<span><span class="chip chip--soft">FO-01</span> la formation qui l’enseigne</span><span><span class="chip chip--ko">sans formation</span></span></div>';
    h += '<section class="card table-card" style="margin-top:0"><div class="table-scroll"><table class="fm-mat"><thead><tr><th>Compétence</th>' + p.niveaux.map(function (nv) {
      return '<th>' + esc(nv.nom) + '<small>+' + n(nv.competences.length) + ' · ' + n(cumul(p, nv.id).size) + ' au total</small></th>';
    }).join('') + '<th>Formation</th></tr></thead><tbody>';
    cats.forEach(function (k) {
      h += '<tr class="fm-group"><td colspan="' + (p.niveaux.length + 2) + '">' + esc(k) + ' · ' + parCat[k].length + '</td></tr>';
      parCat[k].sort(function (a, b) { return a[0] - b[0] || a[1].nom.localeCompare(b[1].nom, 'fr'); }).forEach(function (x) {
        h += '<tr class="row-link" data-href="#/competences/' + x[1].id + '"><td>' + esc(x[1].nom) + '</td>' + p.niveaux.map(function (nv, j) {
          return '<td class="fm-mat__c">' + (j === x[0] ? '<i class="fm-pt2 is-new"></i>' : j > x[0] ? '<i class="fm-pt2 is-acq"></i>' : '') + '</td>';
        }).join('') + '<td><span class="fm-comp__tags" style="justify-content:flex-start">' + tagsFo(x[1].id) + '</span></td></tr>';
      });
    });
    return h + '</tbody></table></div></section>';
  }

  function ficheCompetence(cid) {
    var c = I.comp.get(cid);
    var u = S.competences[cid];
    if (!c && u && u.data) c = u.data.competence;
    var h = retour('#/competences', 'Compétences');
    if (!c) {
      if (!u || u.charge) return { titre: TITRES.competences, corps: h + attente('Lecture de la compétence…') };
      return { titre: TITRES.competences, corps: h + '<p class="error">' + esc(u.erreur || 'Compétence inconnue') + '</p>' };
    }
    var l = liensDe(cid);
    var nivs = I.niveauxParComp.get(cid) || [];
    h += '<div class="fm-head"><div><h2 class="screen-subtitle">' + esc(c.nom) + '</h2><p class="muted">' + esc([c.categorie, c.sousCategorie].filter(Boolean).join(' › ') || 'Sans catégorie') +
      (c.nomBase && c.nomBase !== c.nom ? ' · nom de base : ' + esc(c.nomBase) : '') + ' · #' + c.id + '</p></div>' +
      '<div class="fm-links"><a class="filter" href="#/manuel/competence/' + cid + '">Ouvrir dans le manuel</a>' +
      (I.comp.has(cid) ? bouton('comp~' + cid, 'comp-modifier', 'Modifier', ' data-cid="' + cid + '"') + bouton('comp-' + cid, 'comp-supprimer', 'Supprimer', ' data-cid="' + cid + '"', 'confirm__danger') : '') +
      '</div></div>';
    h += '<div class="fm-cols" style="margin-top:14px">';
    h += '<section class="card"><h2>Description</h2>' + (c.description ? '<div class="fm-rich">' + riche(c.description) + '</div>' : '<p class="muted">Pas de description dans le panel.</p>') + '</section>';
    h += '<section class="card"><h2>Méthode de vérification</h2>' + (c.verification ? '<div class="fm-rich">' + riche(c.verification) + '</div>' : '<p class="muted">Non renseignée.</p>') +
      (c.lien && /^https?:/i.test(c.lien) ? '<p style="margin-top:10px"><a target="_blank" rel="noopener" href="' + esc(c.lien) + '">Lien du panel ↗</a></p>' : '') + '</section>';
    h += '<section class="card"><h2>Exigée par</h2>' + (nivs.length ? '<ul>' + nivs.map(function (id) {
      var p = I.niveauPoste.get(id);
      return '<li><a href="#/postes/' + (p && p.id != null ? p.id : 'sans') + '/' + id + '">' + esc(niveauLibelle(id)) + '</a></li>';
    }).join('') + '</ul>' : '<p class="muted">Aucun niveau de poste n’exige cette compétence.</p>') + '</section>';
    h += '</div>';
    if (!l) {
      h += '<section class="card table-card">' + attente('Lecture des liens de la compétence…') + '</section>';
    } else {
      var carte = function (type, items, lien, vide) {
        var T = TYPES[type];
        return '<section class="card"><div class="fm-card-head"><h2>' + esc(T[2] === 'Ressource' ? 'Ressources' : T[2] + 's') + ' · ' + n(items.length) + '</h2>' +
          bouton('lien-ouvrir', 'lien-ouvrir', '+ Rattacher', ' data-cid="' + cid + '" data-type="' + type + '"') + '</div>' +
          (items.length ? '<ul class="fm-liste">' + items.map(function (x) {
            return '<li><span>' + lien(x) + '</span>' + bouton('lien-' + cid + type + x.id, 'lien-retirer', '×', ' data-cid="' + cid + '" data-type="' + type + '" data-id="' + x.id + '" title="Détacher" aria-label="Détacher ' + esc(x.nom) + '"', 'fm-x') + '</li>';
          }).join('') + '</ul>' : '<p class="muted">' + vide + '</p>') + '</section>';
      };
      h += '<div class="fm-cols" style="margin-top:1rem">';
      h += carte('formation', l.formations, function (x) { return '<a href="#/formations/' + x.id + '">' + esc(x.nom) + '</a>'; }, 'Aucune formation attachée.');
      h += carte('procedure', l.procedures, function (x) { return '<a href="#/procedures/' + x.id + '">' + esc(x.nom) + '</a>'; }, 'Aucune procédure attachée.');
      h += carte('ressource', l.ressources, function (x) {
        var r = I.ressource.get(x.id);
        return r && r.url && /^https?:/i.test(r.url) ? '<a target="_blank" rel="noopener" href="' + esc(r.url) + '">' + esc(x.nom) + ' ↗</a>' : esc(x.nom);
      }, 'Aucune ressource attachée.');
      h += '</div>';
    }
    return { titre: c.nom, kicker: 'Compétence', corps: h };
  }

  /* ── Formations ─────────────────────────────────────────────────────── */

  function niveauxDeComps(cids) {
    var s = new Set();
    cids.forEach(function (cid) { (I.niveauxParComp.get(cid) || []).forEach(function (nid) { s.add(nid); }); });
    return Array.from(s);
  }

  /* Une formation = une fiche : le FO du référentiel v25 (nom, fréquence, priorités, matrice,
     plans), ses évaluations « [FO-xx] … » et ses compétences du panel (prioritaires). */
  var PRIO = { TRAFIC: 'Trafic', RECURRENCE: 'Récurrence', XP: 'Expérience', 'FOOD-COST': 'Food cost', 'LABOUR-COST': 'Labour cost', 'OVERHEAD-COST': 'Frais fixes' };
  function codeFo(nom) { var m = /^\s*\[(FO-\d+)\]/.exec(nom || ''); return m ? m[1] : null; }
  function sansCode(nom) { return String(nom || '').replace(/^\s*\[FO-\d+\]\s*/, ''); }

  function formationsFo() {
    if (!S.v25) return null;
    var par = {};
    var neuve = function (code, v) { return { code: code, nom: v ? v.nom : 'Formation ' + code, v25: v, evaluations: [], comps: new Set() }; };
    S.v25.formations.forEach(function (f) { par[f.code] = neuve(f.code, f); });
    Array.from(I.formation.values()).forEach(function (t) {
      var c = codeFo(t.nom);
      if (!c) return;
      var x = par[c] || (par[c] = neuve(c, null));
      x.evaluations.push(t);
      if (I.liens) (I.compParFormation.get(t.id) || []).forEach(function (cid) { x.comps.add(cid); });
    });
    return Object.keys(par).map(function (k) { par[k].evaluations.sort(function (a, b) { return a.id - b.id; }); return par[k]; })
      .sort(function (a, b) { return +a.code.slice(3) - +b.code.slice(3); });
  }

  /** « Obligatoire pour » : les niveaux du panel d'abord (via les compétences), puis la matrice v25. */
  function obligatoirePour(x) {
    var api = niveauxDeComps(Array.from(x.comps)).map(niveauLibelle);
    var noms = (S.v25 && S.v25.nomsPanel) || {};
    var v = x.v25 ? Object.keys(x.v25.attendu).filter(function (k) { return x.v25.attendu[k] === 'O'; }).map(function (k) { return noms[k] || k; }) : [];
    v = v.filter(function (nm) { return !api.some(function (a) { return a === nm || a.indexOf(nm + ' · ') === 0; }); });
    return { api: api, v25: v };
  }

  function ecranFormations() {
    var a0 = S.route.a[0];
    if (/^FO-\d+$/.test(a0 || '')) return ficheFo(a0);
    var fid = arg(0);
    if (fid != null) {
      var t = I.formation.get(fid);
      var c = t && codeFo(t.nom);
      return c ? ficheFo(c) : ficheFormation(fid);
    }
    if (!S.v25) return { titre: TITRES.formations, corps: S.v25Erreur ? '<p class="error">' + esc(S.v25Erreur) + '</p>' : attente('Lecture du référentiel des formations…') };
    var q = norm(S.f.fq);
    var toutes = formationsFo();
    var liste = toutes.filter(function (x) { return !q || norm(x.code + ' ' + x.nom).indexOf(q) >= 0; });
    var autres = Array.from(I.formation.values()).filter(function (t) { return !codeFo(t.nom) && (!q || norm(t.nom).indexOf(q) >= 0); });
    var h = bandeauLiens() + '<div class="filters"><div class="filters__row"><input class="fm-search" type="search" placeholder="Rechercher une formation…" data-input="fq" value="' + esc(S.f.fq) + '" />' +
      '<span class="muted fm-small">' + pl(liste.length, 'formation') + '</span>' +
      '<div class="fm-seg" role="group" style="margin-left:auto"><button type="button" data-act="filtre" data-k="foVue" data-v="liste"' + (S.f.foVue !== 'matrice' ? ' class="is-on"' : '') + '>Liste</button>' +
      '<button type="button" data-act="filtre" data-k="foVue" data-v="matrice"' + (S.f.foVue === 'matrice' ? ' class="is-on"' : '') + '>Qui doit suivre quoi</button></div></div></div>';
    if (S.f.foVue === 'matrice') return { titre: TITRES.formations, corps: h + matriceFormations(liste) };
    h += '<section class="card table-card" style="margin-top:0"><div class="table-scroll"><table><thead><tr><th>Formation</th><th>Évaluations</th><th>Ce qu’on apprend</th><th>Obligatoire pour</th></tr></thead><tbody>';
    liste.forEach(function (x) {
      var ob = obligatoirePour(x), tous = ob.api.concat(ob.v25);
      var prio = x.v25 && x.v25.priorites.length ? ' <span class="muted fm-small">· ' + x.v25.priorites.map(function (k) { return PRIO[k] || k; }).join(', ') + '</span>' : '';
      h += '<tr class="row-link" data-href="#/formations/' + x.code + '"><td><span class="fm-code">' + x.code + '</span> ' + esc(x.nom) + prio + '</td>' +
        '<td>' + (x.evaluations.length ? n(x.evaluations.length) : '<span class="muted">pas encore dans le panel</span>') + '</td>' +
        '<td>' + (I.liens ? (x.comps.size ? chipsListe(Array.from(x.comps).map(function (id) { return I.comp.get(id) || { id: id, nom: '#' + id }; }), 2) : '<span class="muted">—</span>') : '<span class="muted">…</span>') + '</td>' +
        '<td>' + (tous.length ? chipsListe(tous.map(function (nm, i) { return { id: i, nom: nm }; }), 2, 'chip--accent') : '<span class="muted">—</span>') + '</td></tr>';
    });
    h += '</tbody></table></div></section>';
    if (autres.length) {
      h += '<div class="section-label">Autres formations du panel · ' + autres.length + '</div><section class="card table-card" style="margin-top:10px"><div class="table-scroll"><table><tbody>' +
        autres.map(function (t) { return '<tr class="row-link" data-href="#/formations/' + t.id + '"><td>' + esc(t.nom) + '</td><td class="muted">' + esc([t.categorie, t.sousCategorie].filter(Boolean).join(' › ') || '—') + '</td></tr>'; }).join('') +
        '</tbody></table></div></section>';
    }
    return { titre: TITRES.formations, corps: h };
  }

  /** « Qui doit suivre quoi » : formations × postes par famille, O plein / R contour (la matrice de l'Excel). */
  function matriceFormations(liste) {
    var cols = [['Opérationnel', ['P3', 'P4', 'P1', 'P5', 'P2', 'P6']], ['Cadre et direction', ['P7', 'P8']], ['Devenir entrepreneur', ['P10', 'P9', 'P11', 'P12']]];
    var courts = { P1: 'Boul.-Pât.', P5: 'Chef Boul.-Pât.', P2: 'Traiteur', P6: 'Chef traiteur', P3: 'Vente', P4: 'Chef vente', P7: 'Adjoint', P8: 'Manager',
      P10: 'Candidat', P9: 'Loc.-gérant', P11: 'Franchisé', P12: 'Multi-boutique' };
    var noms = (S.v25 && S.v25.nomsPanel) || {};
    var h = '<div class="fm-legende" style="margin:0 0 12px"><span><i class="fm-or is-O">O</i> obligatoire</span><span><i class="fm-or is-R">R</i> recommandée</span>' +
      '<span>Colonnes : les postes, par famille · lignes : les formations, par thème</span></div>';
    h += '<section class="card table-card" style="margin-top:0"><div class="table-scroll"><table class="fm-mat"><thead><tr class="fm-mat__fam"><th></th>' +
      cols.map(function (c) { return '<th colspan="' + c[1].length + '">' + esc(c[0]) + '</th>'; }).join('') + '</tr><tr><th>Formation</th>' +
      cols.map(function (c) { return c[1].map(function (k) { return '<th title="' + esc(noms[k] || k) + '">' + esc(courts[k] || k) + '</th>'; }).join(''); }).join('') + '</tr></thead><tbody>';
    var groupes = [], parTheme = {};
    liste.forEach(function (x) {
      var t = (x.v25 && x.v25.sousCategorie) || 'Autres', k = norm(t);
      if (!parTheme[k]) { parTheme[k] = { nom: t, l: [] }; groupes.push(k); }
      parTheme[k].l.push(x);
    });
    groupes.forEach(function (k) {
      h += '<tr class="fm-group"><td colspan="13">' + esc(parTheme[k].nom) + '</td></tr>';
      parTheme[k].l.forEach(function (x) {
        var att = (x.v25 && x.v25.attendu) || {};
        h += '<tr class="row-link" data-href="#/formations/' + x.code + '"><td><span class="fm-code">' + x.code + '</span> ' + esc(String(x.nom).replace(/^Formation\s+/, '')) + '</td>' +
          cols.map(function (c) { return c[1].map(function (p) { return '<td class="fm-mat__c">' + (att[p] === 'O' || att[p] === 'R' ? '<i class="fm-or is-' + att[p] + '">' + att[p] + '</i>' : '') + '</td>'; }).join(''); }).join('') + '</tr>';
      });
    });
    return h + '</tbody></table></div></section>';
  }

  function ficheFo(code) {
    var h = retour('#/formations', 'Formations');
    if (!S.v25) return { titre: TITRES.formations, corps: h + attente('Lecture du référentiel des formations…') };
    var x = formationsFo().filter(function (y) { return y.code === code; })[0];
    if (!x) return { titre: TITRES.formations, corps: h + '<p class="error">Formation ' + esc(code) + ' inconnue.</p>' };
    var v = x.v25, ob = obligatoirePour(x);
    var prio = v && v.priorites.length ? '<ul class="chips">' + v.priorites.map(function (k) { return '<li class="chip chip--lever">' + esc(PRIO[k] || k) + '</li>'; }).join('') + '</ul>' : '';
    h += '<div class="fm-head"><div><h2 class="screen-subtitle">' + esc(x.nom) + '</h2><p class="muted">' + esc([x.code, v && v.frequence, v && v.controleur ? 'contrôlée par ' + v.controleur : null, v && v.procedure ? v.procedure.split(' — ')[0] : null].filter(Boolean).join(' · ')) + '</p></div>' + prio + '</div>';
    h += '<div class="fm-cols" style="margin-top:16px">';
    h += '<section class="card"><h2>Obligatoire pour</h2>' + (ob.api.length || ob.v25.length ? '<ul class="chips">' +
      ob.api.map(function (nm) { return '<li class="chip chip--accent">' + esc(nm) + '</li>'; }).join('') + ob.v25.map(function (nm) { return '<li class="chip chip--soft">' + esc(nm) + '</li>'; }).join('') + '</ul>' : '<p class="muted">Aucun poste.</p>') + '</section>';
    h += '<section class="card"><h2>Comment on la valide · ' + (x.evaluations.length || 5) + ' étapes</h2>' + (x.evaluations.length ? '<ol class="fiche-ol">' +
      x.evaluations.map(function (t) { return '<li><a href="#/formations/' + t.id + '" style="color:inherit">' + esc(sansCode(t.nom)) + '</a></li>'; }).join('') + '</ol>' :
      '<p class="muted" style="line-height:1.5">Pas encore d’évaluations « [' + esc(x.code) + '] » dans le panel : le parcours utilise cinq étapes génériques (théorie, pratique, mise en situation, validation, re-évaluation J+30).</p>') + '</section>';
    h += '</div><div class="fm-cols" style="margin-top:16px">';
    var comps = Array.from(x.comps).map(function (id) { return I.comp.get(id); }).filter(Boolean).sort(function (a, b) { return a.nom.localeCompare(b.nom, 'fr'); });
    var v25c = (S.v25.competences || []).filter(function (c) { return c.formation === x.code; });
    h += '<section class="card"><h2>Ce qu’on apprend · ' + (comps.length || v25c.length) + ' compétences</h2>' + (comps.length ? '<ul class="chips">' +
      comps.map(function (c) { return '<li><a class="chip chip--soft" href="#/competences/' + c.id + '">' + esc(c.nom) + '</a></li>'; }).join('') + '</ul><p class="muted fm-small" style="margin-top:10px">Cochées pour la personne dès que la formation est validée dans son parcours.</p>' :
      v25c.length ? '<ul class="fiche-ul">' + v25c.map(function (c) { return '<li><strong>' + esc(c.nom) + '</strong><span class="muted"> — ' + esc(court(c.description, 90)) + '</span></li>'; }).join('') + '</ul>' : (I.liens ? '<p class="muted">Aucune compétence rattachée.</p>' : attente('Liens en cours de lecture…'))) + '</section>';
    var plans = (S.v25.plans || []).map(function (pl2) {
      var b = pl2.blocs.filter(function (bl) { return bl.formations.indexOf(x.code) >= 0; });
      return b.length ? [pl2.nom, b.map(function (bl) { return bl.code + ' ' + bl.nom; }).join(', ')] : null;
    }).filter(Boolean);
    h += '<section class="card"><h2>Dans les plans</h2>' + (plans.length ? '<ul class="fiche-ul">' + plans.map(function (z) { return '<li>' + esc(z[0]) + ' · <span class="muted">' + esc(z[1]) + '</span></li>'; }).join('') + '</ul>' : '<p class="muted">Dans aucun plan de formation.</p>') + '</section>';
    h += '</div><p class="muted fm-small" style="margin-top:14px">Le panel d’abord (évaluations, compétences, niveaux en bordeaux) ; le référentiel v25 complète ce qu’il n’a pas (nom, fréquence, priorités, postes en beige, plans).</p>';
    return { titre: TITRES.formations, kicker: 'Référentiel', corps: h };
  }

  function dlBrut(brut) {
    var k = Object.keys(brut || {});
    if (!k.length) return '<p class="muted">—</p>';
    return '<dl class="fm-dl">' + k.map(function (x) { return '<dt>' + esc(x) + '</dt><dd>' + esc(brut[x] == null ? '—' : String(brut[x])) + '</dd>'; }).join('') + '</dl>';
  }

  function ficheFormation(fid) {
    var f = I.formation.get(fid);
    var d = S.formations[fid];
    if (d && d.data && d.data.formation) f = Object.assign({}, f || {}, d.data.formation);
    var h = retour('#/formations', 'Formations');
    if (!f) return { titre: TITRES.formations, corps: h + (d && d.charge ? attente('Lecture de la formation…') : '<p class="error">Formation inconnue du référentiel.</p>') };
    var cids = I.liens ? I.compParFormation.get(fid) || [] : null;
    h += '<h2 class="screen-subtitle">' + esc(f.nom) + '</h2><p class="muted">' + esc([f.categorie, f.sousCategorie].filter(Boolean).join(' › ') || 'Sans catégorie') + ' · #' + fid + '</p>';
    h += '<div class="fm-cols" style="margin-top:14px">';
    h += '<section class="card"><h2>Description</h2>' + (f.description ? '<div class="fm-rich">' + riche(f.description) + '</div>' : '<p class="muted">Pas de description.</p>') + '</section>';
    h += '<section class="card"><h2>Formateurs</h2>';
    if (!d || d.charge) h += attente('Lecture…');
    else if (d.erreur) h += '<p class="error">' + esc(d.erreur) + '</p>';
    else if (!d.data.formateurs.length) h += '<p class="muted">Aucun formateur désigné.</p>';
    else h += '<ul>' + d.data.formateurs.map(function (t) { return '<li>' + esc(t.nom) + (t.type ? ' <span class="muted">· ' + esc(t.type) + '</span>' : '') + '</li>'; }).join('') + '</ul>';
    h += '</section>';
    h += '<section class="card"><h2>Niveaux concernés</h2>' + (cids === null ? attente('Liens en cours de lecture…') : (function () {
      var nids = niveauxDeComps(cids);
      return nids.length ? '<ul>' + nids.map(function (id) { var p = I.niveauPoste.get(id); return '<li><a href="#/postes/' + (p && p.id != null ? p.id : 'sans') + '/' + id + '">' + esc(niveauLibelle(id)) + '</a></li>'; }).join('') + '</ul>' : '<p class="muted">Aucun niveau n’exige ses compétences.</p>';
    })()) + '</section></div>';
    h += '<section class="card table-card"><h2>Compétences enseignées</h2>' + (cids === null ? attente('Liens en cours de lecture…') : tableCompetences(cids.map(function (id) { return I.comp.get(id); }).filter(Boolean), { niveaux: true })) + '</section>';
    if (f.brut && Object.keys(f.brut).length) h += '<section class="card table-card"><h2>Champs rendus par le panel</h2>' + dlBrut(f.brut) + '</section>';
    return { titre: f.nom, kicker: 'Formation', corps: h };
  }

  /* ── Procédures opératoires ─────────────────────────────────────────── */

  function ecranProcedures() {
    var id = arg(0);
    if (id != null) {
      var p = I.procedure.get(id);
      var corps = retour('#/procedures', 'Procédures opératoires') + lectureProcedure(id, function (cid) { return '#/competences/' + cid; });
      return { titre: (p && p.titre) || 'Procédure', kicker: 'Procédure opératoire', corps: corps };
    }
    var q = norm(S.f.pq);
    var toutes = Array.from(I.procedure.values());
    var liste = toutes.filter(function (p) { return !q || norm(p.titre + ' ' + (p.description || '') + ' ' + (p.categorie || '')).indexOf(q) >= 0; });
    liste.sort(function (a, b) { return (a.categorie || '~').localeCompare(b.categorie || '~', 'fr') || a.titre.localeCompare(b.titre, 'fr'); });
    var h = bandeauLiens() + '<div class="filters"><div class="filters__row"><input class="fm-search" type="search" placeholder="Rechercher une procédure…" data-input="pq" value="' + esc(S.f.pq) + '" />' +
      '<span class="muted fm-small">' + pl(liste.length, 'procédure') + '</span></div></div><section class="card table-card" style="margin-top:0">';
    if (!liste.length) h += '<p class="muted">' + (toutes.length ? 'Aucune procédure ne correspond.' : 'Le panel ne rend aucune procédure (/operational-procedures).') + '</p>';
    else {
      h += '<div class="table-scroll"><table><thead><tr><th>Procédure</th><th>Sous-catégorie</th><th>Compétences</th><th>Mise à jour</th></tr></thead><tbody>';
      var cat = null;
      liste.forEach(function (p) {
        var k = p.categorie || 'Sans catégorie';
        if (k !== cat) { cat = k; h += '<tr class="fm-group"><td colspan="4">' + esc(k) + '</td></tr>'; }
        var cids = I.liens ? I.compParProcedure.get(p.id) || [] : null;
        h += '<tr class="row-link" data-href="#/procedures/' + p.id + '"><td>' + esc(p.titre) + (p.description ? '<p class="muted">' + esc(court(p.description, 150)) + '</p>' : '') + '</td>' +
          '<td>' + esc(p.sousCategorie || '—') + '</td><td>' + (cids ? chipsListe(cids.map(function (x) { return I.comp.get(x) || { id: x, nom: '#' + x }; }), 2) : '<span class="muted">…</span>') + '</td>' +
          '<td class="fm-nowrap muted fm-small">' + (p.majLe ? esc(String(p.majLe).slice(0, 10)) : '—') + '</td></tr>';
      });
      h += '</tbody></table></div>';
    }
    h += '</section>';
    return { titre: TITRES.procedures, corps: h };
  }

  /** La procédure se lit comme une page du manuel : titre, sections, pièces jointes, PDF. */
  function lectureProcedure(id, lienCompetence) {
    var d = S.procedures[id];
    if (!d || d.charge) return attente('Lecture de la procédure dans le panel…');
    if (d.erreur) return '<p class="error">' + esc(d.erreur) + '</p>';
    var p = d.data.procedure, secs = d.data.sections, pieces = d.data.pieces;
    var h = '<article class="fm-doc"><div class="fm-head"><div><h2 class="screen-subtitle">' + esc(p.titre) + '</h2><p class="muted">' +
      esc([p.categorie, p.sousCategorie].filter(Boolean).join(' › ') || 'Procédure opératoire') + (p.majLe ? ' · mise à jour ' + esc(String(p.majLe).slice(0, 10)) : '') + '</p></div>' +
      '<div class="fm-links"><a class="filter" target="_blank" rel="noopener" href="' + API + '/formation/pdf?type=procedure&id=' + id + '">PDF ↗</a>' +
      '<button type="button" class="filter" data-act="imprimer">Imprimer</button></div></div>';
    if (p.description) h += '<div class="fm-doc__lead fm-rich" style="margin-top:10px">' + riche(p.description) + '</div>';
    if (p.contenu) h += '<section class="card fm-doc__section"><div class="fm-rich">' + riche(p.contenu) + '</div></section>';
    if (!secs.length && !p.contenu) h += '<section class="card fm-doc__section"><p class="muted">Le panel ne rend aucune section pour cette procédure.</p></section>';
    secs.forEach(function (s, i) {
      h += '<section class="card fm-doc__section"><h3><span class="fm-doc__num">' + (i + 1) + '</span>' + esc(s.titre || 'Étape ' + (i + 1)) + '</h3><div class="fm-rich">' + riche(s.contenu || '') + '</div></section>';
    });
    if (pieces.length) {
      h += '<section class="card fm-doc__section"><h2>Pièces jointes</h2><ul>' + pieces.map(function (x) {
        var nom = esc(x.nom || 'Pièce ' + (x.id || ''));
        return '<li>' + (x.url && /^https?:/i.test(x.url) ? '<a target="_blank" rel="noopener" href="' + esc(x.url) + '">' + nom + ' ↗</a>' : nom) + (x.type ? ' <span class="muted">· ' + esc(x.type) + '</span>' : '') + '</li>';
      }).join('') + '</ul></section>';
    }
    var cids = I.liens ? I.compParProcedure.get(id) || [] : null;
    if (cids && cids.length) {
      h += '<section class="card fm-doc__section"><h2>Compétences qui s’appuient sur cette procédure</h2><ul class="chips">' + cids.map(function (cid) {
        var c = I.comp.get(cid);
        return '<li><a class="chip chip--accent" href="' + lienCompetence(cid) + '">' + esc(c ? c.nom : '#' + cid) + '</a></li>';
      }).join('') + '</ul></section>';
    }
    return h + '</article>';
  }

  /* ── Base de connaissances, compétences produit ─────────────────────── */

  function ecranRessources() {
    var q = norm(S.f.rq);
    var toutes = Array.from(I.ressource.values());
    var liste = toutes.filter(function (r) { return !q || norm(r.titre + ' ' + (r.description || '') + ' ' + (r.type || '') + ' ' + (r.categorie || '')).indexOf(q) >= 0; });
    liste.sort(function (a, b) { return a.titre.localeCompare(b.titre, 'fr'); });
    var h = bandeauLiens() + '<div class="filters"><div class="filters__row"><input class="fm-search" type="search" placeholder="Rechercher une ressource…" data-input="rq" value="' + esc(S.f.rq) + '" />' +
      '<span class="muted fm-small">' + pl(liste.length, 'ressource') + '</span></div></div><section class="card table-card" style="margin-top:0">';
    if (!liste.length) h += '<p class="muted">' + (toutes.length ? 'Aucune ressource ne correspond.' : 'Le panel ne rend aucune ressource (/knowledge-base/resources).') + '</p>';
    else {
      h += '<div class="table-scroll"><table><thead><tr><th>Ressource</th><th>Type</th><th>Catégorie</th><th>Compétences</th><th>Lien</th></tr></thead><tbody>';
      liste.forEach(function (r) {
        var cids = I.liens ? I.compParRessource.get(r.id) || [] : null;
        h += '<tr><td>' + esc(r.titre) + (r.description ? '<p class="muted">' + esc(court(r.description, 150)) + '</p>' : '') + '</td><td>' + esc(r.type || '—') + '</td><td>' + esc(r.categorie || '—') + '</td>' +
          '<td>' + (cids ? chipsListe(cids.map(function (x) { return I.comp.get(x) || { id: x, nom: '#' + x }; }), 2, null, function (x) { return '#/competences/' + x.id; }) : '<span class="muted">…</span>') + '</td>' +
          '<td>' + (r.url && /^https?:/i.test(r.url) ? '<a target="_blank" rel="noopener" href="' + esc(r.url) + '">Ouvrir ↗</a>' : '<span class="muted">—</span>') + '</td></tr>';
      });
      h += '</tbody></table></div>';
    }
    return { titre: TITRES.ressources, corps: h + '</section>' };
  }

  function ecranProduit() {
    var L = S.ref.competencesProduit;
    var h = '<p class="muted" style="margin-bottom:12px">Les compétences attachées aux produits et sous-recettes (savoir-faire de fabrication), lues sur <span class="fm-code">/product-competences</span>. Elles se gèrent côté produit dans le panel ; les routes d’alias (traductions du nom et de la description) sont documentées dans <a href="#/api/produit">Routes API</a>.</p>';
    h += '<section class="card table-card" style="margin-top:0">';
    if (!L.length) h += '<p class="muted">Le panel ne rend aucune compétence produit.</p>';
    else {
      var cles = {};
      L.forEach(function (x) { Object.keys(x.brut || {}).forEach(function (k) { if (['id', 'name', 'description', 'base_name', 'base_description'].indexOf(k) < 0) cles[k] = 1; }); });
      var extra = Object.keys(cles).slice(0, 4);
      h += '<div class="table-scroll"><table><thead><tr><th class="num">#</th><th>Compétence produit</th><th>Description</th>' + extra.map(function (k) { return '<th>' + esc(k) + '</th>'; }).join('') + '</tr></thead><tbody>';
      L.slice().sort(function (a, b) { return a.nom.localeCompare(b.nom, 'fr'); }).forEach(function (x) {
        h += '<tr><td class="num muted">' + esc(x.id) + '</td><td>' + esc(x.nom) + '</td><td>' + esc(court(x.description || '—', 200)) + '</td>' +
          extra.map(function (k) { return '<td class="muted">' + esc(x.brut[k] == null ? '—' : court(String(x.brut[k]), 60)) + '</td>'; }).join('') + '</tr>';
      });
      h += '</tbody></table></div>';
    }
    return { titre: TITRES.produit, corps: h + '</section>' };
  }

  /* ── Équipes ────────────────────────────────────────────────────────── */

  function ecranEquipes() {
    var E = S.equipe;
    var sid = arg(0);
    var h = bandeauLiens();
    if (E.erreur && !E.magasins) return { titre: TITRES.equipes, corps: h + '<p class="error">' + esc(E.erreur) + '</p>' };
    if (!E.magasins) return { titre: TITRES.equipes, corps: h + attente('Lecture des magasins…') };
    h += '<div class="filters"><div class="filters__row">' + E.magasins.map(function (m) {
      return '<a class="filter' + (sid === m.id ? ' is-on' : '') + '" href="#/equipes/' + m.id + '">' + esc(m.nom) + '</a>';
    }).join('') + (E.magasins.length ? '' : '<span class="muted">Aucun magasin connu du cockpit.</span>') + '</div></div>';
    if (sid == null) {
      return { titre: TITRES.equipes, corps: h + '<section class="card pending"><h2>Choisissez un magasin</h2><p class="muted">Pour chaque personne : le poste et le niveau qu’elle tient dans le panel, les compétences qu’elle a validées face à celles que son niveau exige, et les formations qui couvrent ce qui manque.</p></section>' };
    }
    var d = E.parShop[sid];
    if (!d || d.charge) return { titre: TITRES.equipes, corps: h + attente('Lecture de l’équipe et des fiches de chacun…') };
    if (d.erreur) return { titre: TITRES.equipes, corps: h + '<p class="error">' + esc(d.erreur) + '</p>' };
    var emps = d.data.employes || [];
    var actifs = emps.filter(function (e) { return e.actif; });
    var lignes = actifs.map(function (e) {
      // Ce que le niveau tenu exige = le cumul des niveaux du poste jusqu'à lui (le panel ne liste que les nouvelles).
      var exig = new Set(), suivant = null;
      e.postes.forEach(function (p) {
        var poste = I.posteById.get(p.id);
        if (!poste || p.niveauId == null) return;
        cumul(poste, p.niveauId).forEach(function (c) { exig.add(c); });
        var i = -1;
        poste.niveaux.forEach(function (nv, k) { if (nv.id === p.niveauId) i = k; });
        if (i >= 0 && poste.niveaux[i + 1]) suivant = { poste: poste, nv: poste.niveaux[i + 1] };
      });
      var acq = new Set(e.competences);
      var manque = Array.from(exig).filter(function (c) { return !acq.has(c); });
      var formations = new Map();
      if (I.liens) manque.forEach(function (cid) { (liensDe(cid).formations || []).forEach(function (f) { formations.set(f.id, f); }); });
      var parcours = (S.pcListe || []).filter(function (x) { return x.employe === e.id && x.statut === 'actif'; })[0] || null;
      return { e: e, exig: exig, valides: Array.from(exig).filter(function (c) { return acq.has(c); }).length, manque: manque, formations: Array.from(formations.values()), suivant: suivant, parcours: parcours };
    });
    var tot = lignes.reduce(function (a, l) { return a + l.exig.size; }, 0), ok = lignes.reduce(function (a, l) { return a + l.valides; }, 0);
    var sansPoste = lignes.filter(function (l) { return !l.e.postes.length; }).length;
    var complets = lignes.filter(function (l) { return l.exig.size && !l.manque.length; }).length;
    var enCours = lignes.filter(function (l) { return l.exig.size && l.manque.length; });
    h += '<div class="fm-kpis" style="margin-bottom:1rem">' +
      '<div class="fm-kpi"><span class="fm-kpi__n">' + n(actifs.length) + '</span><span class="fm-kpi__l">Personnes</span><span class="fm-kpi__s">' + (sansPoste ? n(sansPoste) + ' sans poste dans le panel' : 'toutes placées') + '</span></div>' +
      '<div class="fm-kpi"><span class="fm-kpi__n">' + n(complets) + '</span><span class="fm-kpi__l">Niveau complet</span><span class="fm-kpi__s">prêtes pour l’étape suivante</span></div>' +
      '<div class="fm-kpi"><span class="fm-kpi__n">' + n(enCours.length) + '</span><span class="fm-kpi__l">En progression</span><span class="fm-kpi__s">' + n(enCours.filter(function (l) { return l.parcours; }).length) + ' avec un parcours</span></div>' +
      '<div class="fm-kpi"><span class="fm-kpi__n">' + (tot ? pct(ok, tot) + ' %' : '—') + '</span><span class="fm-kpi__l">Compétences tenues</span><span class="fm-kpi__s">du niveau de chacun</span></div></div>';
    h += '<section class="card table-card"><div class="table-scroll"><table><thead><tr><th>Personne</th><th>Compétences du niveau</th><th>Où elle en est</th><th></th></tr></thead><tbody>';
    lignes.forEach(function (l) {
      var e = l.e, ouvert = E.ouvert === e.id;
      var ini = String(e.nom || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('');
      var po = e.postes.map(function (p) { return (p.nom || 'Poste') + (p.niveau ? ' · ' + p.niveau : ''); }).join(' ; ');
      var etat, action;
      if (!e.postes.length) {
        etat = '<span class="chip chip--ko">à affecter</span>';
        action = bouton('emp' + e.id + 'niv', 'emp-poste', 'Choisir un poste', ' data-sid="' + sid + '" data-eid="' + e.id + '"');
      } else if (!l.exig.size) {
        etat = '<span class="muted">aucune compétence exigée à ce niveau</span>';
        action = '';
      } else if (!l.manque.length) {
        etat = 'Niveau complet' + (l.suivant ? ' → prête pour <strong>' + esc(l.suivant.nv.nom) + '</strong> (+' + n(l.suivant.nv.competences.length) + ')' : '');
        action = l.parcours ? '<a class="chip chip--ok" href="parcours/#/p/' + l.parcours.id + '">parcours en cours</a>' : '<a class="filter" href="parcours/#/nouveau">Ouvrir un parcours</a>';
      } else {
        etat = 'Il manque <strong>' + pl(l.manque.length, 'compétence') + '</strong>';
        action = l.parcours ? '<a class="chip chip--ok" href="parcours/#/p/' + l.parcours.id + '">parcours en cours</a>' :
          '<button type="button" class="filter" data-act="equipe-ouvrir" data-id="' + e.id + '">' + (ouvert ? 'Fermer' : 'Voir ce qui manque') + '</button>';
      }
      h += '<tr class="row-link" data-act="equipe-ouvrir" data-id="' + e.id + '"><td><div class="fm-pers"><span class="fm-ini">' + esc(ini) + '</span><span><span class="fm-pers__n">' + esc(e.nom) + '</span>' +
        '<span class="fm-pers__s">' + (po ? esc(po) : 'sans poste dans le panel') + (!e.lu ? ' · fiche illisible' : '') + '</span></span></div></td>' +
        '<td style="min-width:13rem">' + (l.exig.size ? '<div class="fm-prog"><b>' + n(l.valides) + ' / ' + n(l.exig.size) + '</b> compétences du niveau</div>' + barre(l.valides, l.exig.size) : '<span class="muted">—</span>') + '</td>' +
        '<td>' + etat + '</td><td class="num fm-nowrap">' + action + '</td></tr>';
      if (ouvert) h += '<tr class="fm-detail"><td colspan="4">' + detailEmploye(sid, l) + '</td></tr>';
    });
    h += '</tbody></table></div></section>';
    return { titre: TITRES.equipes, corps: h };
  }

  /** Le détail d'une personne : son niveau, ce qui lui manque, ce qu'elle a validé — et les gestes. */
  function detailEmploye(sid, l) {
    var e = l.e, eid = e.id;
    var att = function (cid) { return ' data-sid="' + sid + '" data-eid="' + eid + '" data-cid="' + cid + '"'; };
    var h = '<div class="fm-head"><div><div class="section-label" style="margin-top:0">Poste et niveau</div><div style="margin-top:8px">' +
      (e.postes.length ? chipsListe(e.postes.map(function (p) { return { id: p.id, nom: (p.nom || 'Poste') + (p.niveau ? ' · ' + p.niveau : '') }; }), 4, 'chip--accent') : '<span class="muted">Aucun poste dans le panel.</span>') + '</div></div>' +
      '<div class="fm-links">' + bouton('emp' + eid + 'niv', 'emp-poste', 'Changer de poste ou de niveau', ' data-sid="' + sid + '" data-eid="' + eid + '"') + '</div></div>';
    // Tout est validé : le niveau suivant du même poste, s'il existe.
    if (l.exig.size && !l.manque.length) {
      e.postes.forEach(function (p) {
        var poste = I.posteById.get(p.id);
        if (!poste) return;
        var i = -1;
        poste.niveaux.forEach(function (nv, k) { if (nv.id === p.niveauId) i = k; });
        var suivant = i >= 0 ? poste.niveaux[i + 1] : null;
        h += '<div class="fm-banner fm-banner--ok" style="margin-top:12px"><span>Toutes les compétences du niveau ' + esc(poste.nom + ' · ' + (p.niveau || '')) + ' sont validées.' +
          (suivant ? '' : ' C’est le dernier niveau du poste.') + '</span>' +
          (suivant ? bouton('emp' + eid + 'niv', 'emp-suivant', 'Passer au niveau « ' + esc(suivant.nom) + ' »', ' data-sid="' + sid + '" data-eid="' + eid + '" data-pid="' + poste.id + '" data-nid="' + suivant.id + '"', 'is-on') : '') + '</div>';
      });
    }
    var manque = l.manque.map(function (c) { return I.comp.get(c); }).filter(Boolean);
    h += '<div class="section-label">À acquérir · ' + n(manque.length) + '</div>';
    h += manque.length ? tableCompetences(manque, { niveaux: false, action: function (c) {
      return bouton('emp' + eid + '+' + c.id, 'emp-valider', 'Valider', att(c.id) + ' title="La personne maîtrise cette compétence : l’enregistrer dans le panel"', 'is-on');
    } }) : '<p class="muted" style="margin-top:8px">' + (l.exig.size ? 'Rien : tout ce que le niveau exige est validé.' : 'Aucun niveau tenu : rien n’est exigé.') + '</p>';
    var valides = e.competences.map(function (cid) { return I.comp.get(cid) || { id: cid, nom: 'Compétence #' + cid }; })
      .sort(function (a, b) { return a.nom.localeCompare(b.nom, 'fr'); });
    h += '<div class="section-label">Validées · ' + n(valides.length) + '</div>';
    h += valides.length ? '<ul class="fm-liste fm-liste--cols">' + valides.map(function (c) {
      return '<li><span>' + esc(c.nom) + (l.exig.has(c.id) ? '' : ' <span class="muted fm-small">(hors niveau)</span>') + '</span>' +
        bouton('emp' + eid + '-' + c.id, 'emp-retirer', '×', att(c.id) + ' title="Retirer la validation" aria-label="Retirer la validation de ' + esc(c.nom) + '"', 'fm-x') + '</li>';
    }).join('') + '</ul>' : '<p class="muted" style="margin-top:8px">Aucune compétence validée dans le panel.</p>';
    return h;
  }

  /* ── Parcours des équipes ───────────────────────────────────────────── */

  function ecranParcoursEquipes() {
    var h = bandeauLiens();
    var mags = S.equipe.magasins || [];
    h += '<div class="filters"><div class="filters__row">' +
      '<button type="button" class="filter' + (!S.pcFiltre ? ' is-on' : '') + '" data-act="filtre" data-k="pcFiltreF" data-v="">Tout le réseau</button>' +
      mags.map(function (m) { return '<button type="button" class="filter' + (String(S.pcFiltre) === String(m.id) ? ' is-on' : '') + '" data-act="pc-filtre" data-v="' + m.id + '">' + esc(m.nom) + '</button>'; }).join('') +
      '<button type="button" class="filter' + (S.pcFiltre === 'candidats' ? ' is-on' : '') + '" data-act="pc-filtre" data-v="candidats">Candidats franchisés</button>' +
      '<a class="filter is-on" href="parcours/#/nouveau" style="margin-left:auto">+ Nouveau parcours</a></div></div>';
    if (S.pcErreur) return { titre: TITRES.parcours, corps: h + '<p class="error">' + esc(S.pcErreur) + '</p>' };
    if (!S.pcListe) return { titre: TITRES.parcours, corps: h + attente('Lecture des parcours…') };
    var l = S.pcListe.filter(function (p) { return !S.pcFiltre || (S.pcFiltre === 'candidats' ? p.profil === 'candidat' : String(p.shop) === String(S.pcFiltre)); });
    var actifs = l.filter(function (p) { return p.statut === 'actif'; });
    var cand = actifs.filter(function (p) { return p.profil === 'candidat'; }).length;
    h += '<div class="fm-kpis" style="margin-bottom:1rem">' +
      '<div class="fm-kpi"><span class="fm-kpi__n">' + n(actifs.length) + '</span><span class="fm-kpi__l">En cours</span><span class="fm-kpi__s">' + pl(actifs.length - cand, 'employé') + ' · ' + pl(cand, 'candidat') + '</span></div>' +
      '<div class="fm-kpi"><span class="fm-kpi__n">' + n(l.filter(function (p) { return p.statut === 'atteint'; }).length) + '</span><span class="fm-kpi__l">Objectifs atteints</span><span class="fm-kpi__s">niveau validé dans le panel</span></div>' +
      '<div class="fm-kpi"><span class="fm-kpi__n">' + n(l.reduce(function (a, p) { return a + p.validees; }, 0)) + '</span><span class="fm-kpi__l">Formations validées</span><span class="fm-kpi__s">sur ' + n(l.reduce(function (a, p) { return a + p.total; }, 0)) + '</span></div>' +
      '<div class="fm-kpi"><span class="fm-kpi__n">' + n(actifs.filter(function (p) { return p.retard; }).length) + '</span><span class="fm-kpi__l">En retard</span><span class="fm-kpi__s">étape dépassée de 2 semaines</span></div></div>';
    h += '<section class="card table-card"><h2>Les parcours</h2>';
    if (!l.length) h += '<p class="muted">Aucun parcours. Le premier se crée en trois étapes : <a href="parcours/#/nouveau">nouveau parcours</a>.</p>';
    else {
      h += '<div class="table-scroll"><table><thead><tr><th>Personne</th><th>Vers</th><th>Frise</th><th class="num">Avancement</th><th>Étape en cours</th><th>Statut</th></tr></thead><tbody>';
      l.forEach(function (p) {
        var pts = '<span class="fm-pts">' + p.frise.map(function (st) { return '<span class="fm-pt ' + st + '"></span>'; }).join('') + '<span class="fm-pt-flag">⚑</span></span>';
        var st = p.statut === 'atteint' ? ['Objectif atteint', 'chip--ok'] : p.statut === 'arrete' ? ['Arrêté', 'chip--mute'] : p.retard ? ['En retard', 'chip--ko'] : ['Dans les temps', 'chip--ok'];
        h += '<tr class="row-link" data-lien="parcours/#/p/' + p.id + '"><td><div class="fm-lead"><span class="fm-initiales">' + esc(String(p.nom).split(/\s+/).map(function (w) { return w.charAt(0); }).join('').slice(0, 2).toUpperCase()) + '</span><span>' + esc(p.nom) +
          '<p class="muted">' + esc(p.depart || (p.profil === 'candidat' ? 'Candidat franchisé' : '—')) + '</p></span></div></td>' +
          '<td>' + esc(p.cibleNom) + '</td><td>' + pts + '</td><td class="num">' + (p.total ? Math.round(p.validees * 100 / p.total) : 0) + ' %</td>' +
          '<td>' + (p.enCours ? esc(p.enCours.nom) + ' <span class="muted fm-small">· ' + p.enCours.faites + '/' + p.enCours.evaluations + '</span>' : '<span class="muted">—</span>') + '</td>' +
          '<td><span class="chip ' + st[1] + '">' + st[0] + '</span></td></tr>';
      });
      h += '</tbody></table></div>';
    }
    h += '</section>';
    return { titre: TITRES.parcours, corps: h };
  }

  /* ── Manuel opératoire (tablette) ───────────────────────────────────── */

  function filAriane(items) {
    return '<nav class="fm-crumbs" aria-label="Fil d’Ariane">' + items.map(function (it, i) {
      var last = i === items.length - 1;
      return (i ? '<span aria-hidden="true">›</span>' : '') + (last || !it[1] ? '<span>' + esc(it[0]) + '</span>' : '<a href="' + it[1] + '">' + esc(it[0]) + '</a>');
    }).join('') + '</nav>';
  }

  function carteCompetence(c, href) {
    var l = liensDe(c.id);
    var chips = l ? '<ul class="chips">' +
      (l.procedures.length ? '<li class="chip chip--accent">' + pl(l.procedures.length, 'procédure') + '</li>' : '') +
      (l.formations.length ? '<li class="chip chip--soft">' + pl(l.formations.length, 'formation') + '</li>' : '') +
      (l.ressources.length ? '<li class="chip chip--soft">' + pl(l.ressources.length, 'ressource') + '</li>' : '') + '</ul>' : '';
    return '<li><a class="choice-card" href="' + href + '"><strong>' + esc(c.nom) + '</strong><span class="muted">' + esc(c.sousCategorie || c.categorie || '') + '</span>' + chips + '</a></li>';
  }

  function ecranManuel() {
    var a = S.route.a;
    var vue = a[0] || '';
    if (vue === 'poste') return manuelPoste();
    if (vue === 'competence') return manuelCompetence();
    if (vue === 'procedure') return manuelProcedure();
    var R = S.ref, q = norm(S.f.mq);
    var h = '<div class="fm-manuel-search"><input type="search" placeholder="Chercher une compétence, une procédure…" data-input="mq" value="' + esc(S.f.mq) + '" /></div>';
    if (q) {
      var comps = R.competences.filter(function (c) { return norm(c.nom + ' ' + (c.description || '') + ' ' + (c.sousCategorie || '')).indexOf(q) >= 0; }).slice(0, 60);
      var procs = Array.from(I.procedure.values()).filter(function (p) { return norm(p.titre + ' ' + (p.description || '')).indexOf(q) >= 0; }).slice(0, 60);
      h += '<div class="section-label" style="margin-top:0">Compétences · ' + n(comps.length) + '</div>';
      h += comps.length ? '<ul class="type-grid">' + comps.map(function (c) { return carteCompetence(c, '#/manuel/competence/' + c.id); }).join('') + '</ul>' : '<p class="muted" style="margin-top:8px">Aucune compétence.</p>';
      h += '<div class="section-label">Procédures · ' + n(procs.length) + '</div>';
      h += procs.length ? '<ul class="type-grid">' + procs.map(function (p) {
        return '<li><a class="choice-card" href="#/manuel/procedure/' + p.id + '"><strong>' + esc(p.titre) + '</strong><span class="muted">' + esc(court(p.description || p.categorie || '', 110)) + '</span></a></li>';
      }).join('') + '</ul>' : '<p class="muted" style="margin-top:8px">Aucune procédure.</p>';
      return { titre: 'Manuel opératoire', kicker: 'Recherche', corps: h };
    }
    var postes = R.postes.filter(function (p) { return p.niveaux.length || p.id != null; });
    if (!postes.length) h += '<section class="card pending"><h2>Aucun poste</h2><p class="muted">Le manuel se construit à partir des postes du panel ; aucun n’a été rendu.</p></section>';
    h += '<div class="campaign-grid">' + postes.map(function (p) {
      var set = new Set(); p.niveaux.forEach(function (nv) { nv.competences.forEach(function (c) { set.add(c); }); });
      return '<a class="card campaign" href="#/manuel/poste/' + (p.id != null ? p.id : 'sans') + '"><div class="fm-card-band"><span class="fm-card-band__letter">' + esc((p.nom || '?').trim().charAt(0).toUpperCase()) + '</span>' +
        '<span class="fm-card-band__count">' + pl(p.niveaux.length, 'niveau') + '</span></div><div class="campaign__body"><div class="campaign__head"><h3>' + esc(p.nom) + '</h3></div>' +
        '<p class="muted campaign__meta">' + pl(set.size, 'compétence') + (p.description ? ' · ' + esc(court(p.description, 90)) : '') + '</p></div></a>';
    }).join('') + '</div>';
    return { titre: 'Manuel opératoire', kicker: 'Manuel en ligne', corps: bandeauLiens() + h };
  }

  function posteParArg(v) {
    if (v === 'sans') return S.ref.postes.filter(function (p) { return p.id == null; })[0] || null;
    return /^\d+$/.test(v || '') ? I.posteById.get(+v) || null : null;
  }

  function manuelPoste() {
    var poste = posteParArg(S.route.a[1]);
    if (!poste) return { titre: 'Manuel opératoire', corps: retour('#/manuel', 'Manuel') + '<p class="error">Poste inconnu.</p>' };
    var nid = arg(2);
    var niveau = (nid != null && poste.niveaux.filter(function (x) { return x.id === nid; })[0]) || poste.niveaux[0];
    var pk = poste.id != null ? poste.id : 'sans';
    var h = filAriane([['Manuel', '#/manuel'], [poste.nom, null]]);
    h += '<div class="fm-head"><div><h2 class="screen-subtitle">' + esc(poste.nom) + '</h2>' + (poste.description ? '<p class="muted">' + esc(poste.description) + '</p>' : '') + '</div>' +
      '<div class="fm-links">' + (niveau ? '<a class="filter" target="_blank" rel="noopener" href="' + API + '/formation/pdf?type=niveau&id=' + niveau.id + '">Fiche du niveau ↗</a>' : '') +
      (poste.id != null ? '<a class="filter" target="_blank" rel="noopener" href="' + API + '/formation/pdf?type=poste&id=' + poste.id + '">Fiche du poste ↗</a>' : '') + '</div></div>';
    if (!poste.niveaux.length) return { titre: poste.nom, kicker: 'Manuel opératoire', corps: h + '<p class="muted" style="margin-top:12px">Ce poste n’a pas de niveau dans le panel.</p>' };
    h += '<ol class="wizard__steps" style="margin-top:14px">' + poste.niveaux.map(function (nv, i) {
      var on = nv === niveau;
      return '<li class="wizard__step' + (on ? ' is-on' : '') + '"><a href="#/manuel/poste/' + pk + '/' + nv.id + '"' + (on ? ' aria-current="step"' : '') + '><span class="wizard__num">' + (i + 1) + '</span>' + esc(nv.nom) + '</a></li>';
    }).join('') + '</ol>';
    if (niveau.description) h += '<p class="muted">' + esc(niveau.description) + '</p>';
    var comps = niveau.competences.map(function (cid) { return I.comp.get(cid); }).filter(Boolean)
      .sort(function (a, b) { return (a.categorie || '~').localeCompare(b.categorie || '~', 'fr') || a.nom.localeCompare(b.nom, 'fr'); });
    if (!comps.length) h += '<p class="muted" style="margin-top:12px">Aucune compétence n’est exigée à ce niveau.</p>';
    var cat = null, ouvert = false;
    comps.forEach(function (c) {
      var k = c.categorie || 'Sans catégorie';
      if (k !== cat) { if (ouvert) h += '</ul>'; cat = k; ouvert = true; h += '<div class="section-label">' + esc(k) + '</div><ul class="type-grid">'; }
      h += carteCompetence(c, '#/manuel/competence/' + c.id + '/' + pk + '/' + niveau.id);
    });
    if (ouvert) h += '</ul>';
    return { titre: poste.nom, kicker: 'Manuel opératoire', corps: bandeauLiens() + h };
  }

  function contexteManuel(i) {
    // …/competence/{cid}/{poste}/{niveau} : d'où l'on vient, pour le fil d'Ariane.
    var poste = posteParArg(S.route.a[i]);
    var nid = /^\d+$/.test(S.route.a[i + 1] || '') ? +S.route.a[i + 1] : null;
    var niveau = poste && nid != null ? poste.niveaux.filter(function (x) { return x.id === nid; })[0] : null;
    return { poste: poste, niveau: niveau, pk: poste ? (poste.id != null ? poste.id : 'sans') : null };
  }

  function manuelCompetence() {
    var cid = arg(1);
    var c = cid != null ? I.comp.get(cid) : null;
    var u = S.competences[cid];
    if (!c && u && u.data) c = u.data.competence;
    var ctx = contexteManuel(2);
    var fil = [['Manuel', '#/manuel']];
    if (ctx.poste) fil.push([ctx.poste.nom, '#/manuel/poste/' + ctx.pk + (ctx.niveau ? '/' + ctx.niveau.id : '')]);
    if (ctx.niveau) fil.push([ctx.niveau.nom, '#/manuel/poste/' + ctx.pk + '/' + ctx.niveau.id]);
    if (!c) return { titre: 'Manuel opératoire', corps: filAriane(fil.concat([['Compétence', null]])) + (u && u.erreur ? '<p class="error">' + esc(u.erreur) + '</p>' : attente('Lecture de la compétence…')) };
    fil.push([c.nom, null]);
    var suite = ctx.poste ? '/' + cid + '/' + ctx.pk + (ctx.niveau ? '/' + ctx.niveau.id : '') : '/' + cid;
    var l = liensDe(cid);
    var h = filAriane(fil) + '<h2 class="screen-subtitle">' + esc(c.nom) + '</h2><p class="muted">' + esc([c.categorie, c.sousCategorie].filter(Boolean).join(' › ')) + '</p>';
    if (c.description) h += '<div class="fm-doc__lead fm-rich" style="margin-top:12px">' + riche(c.description) + '</div>';
    if (c.verification) h += '<section class="card" style="margin-top:14px"><h2>Comment c’est vérifié</h2><div class="fm-rich">' + riche(c.verification) + '</div></section>';
    if (c.lien && /^https?:/i.test(c.lien)) h += '<p style="margin-top:12px"><a class="filter" target="_blank" rel="noopener" href="' + esc(c.lien) + '">Ouvrir le support ↗</a></p>';
    if (!l) return { titre: c.nom, kicker: 'Compétence', corps: h + attente('Lecture des procédures et formations…') };
    h += '<div class="section-label">Procédures à suivre · ' + n(l.procedures.length) + '</div>';
    h += l.procedures.length ? '<ul class="type-grid">' + l.procedures.map(function (x) {
      var p = I.procedure.get(x.id) || {};
      return '<li><a class="choice-card" href="#/manuel/procedure/' + x.id + suite + '"><strong>' + esc(p.titre || x.nom) + '</strong><span class="muted">' + esc(court(p.description || p.sousCategorie || 'Ouvrir la procédure', 120)) + '</span></a></li>';
    }).join('') + '</ul>' : '<p class="muted" style="margin-top:8px">Aucune procédure attachée à cette compétence.</p>';
    h += '<div class="section-label">Formations · ' + n(l.formations.length) + '</div>';
    h += l.formations.length ? '<ul class="type-grid">' + l.formations.map(function (x) {
      var f = I.formation.get(x.id) || {};
      return '<li><div class="choice-card" style="cursor:default"><strong>' + esc(f.nom || x.nom) + '</strong><span class="muted">' + esc(court(f.description || [f.categorie, f.sousCategorie].filter(Boolean).join(' › ') || 'Formation du réseau', 160)) + '</span></div></li>';
    }).join('') + '</ul>' : '<p class="muted" style="margin-top:8px">Aucune formation attachée.</p>';
    if (l.ressources.length) {
      h += '<div class="section-label">Ressources · ' + n(l.ressources.length) + '</div><ul class="type-grid">' + l.ressources.map(function (x) {
        var r = I.ressource.get(x.id) || {};
        var lien = r.url && /^https?:/i.test(r.url);
        return '<li><' + (lien ? 'a target="_blank" rel="noopener" href="' + esc(r.url) + '"' : 'div style="cursor:default"') + ' class="choice-card"><strong>' + esc(r.titre || x.nom) + (lien ? ' ↗' : '') + '</strong><span class="muted">' + esc(court(r.description || r.type || '', 120)) + '</span></' + (lien ? 'a' : 'div') + '></li>';
      }).join('') + '</ul>';
    }
    return { titre: c.nom, kicker: 'Compétence', corps: h };
  }

  function manuelProcedure() {
    var id = arg(1);
    var cid = /^\d+$/.test(S.route.a[2] || '') ? +S.route.a[2] : null;
    var c = cid != null ? I.comp.get(cid) : null;
    var ctx = contexteManuel(3);
    var fil = [['Manuel', '#/manuel']];
    if (ctx.poste) fil.push([ctx.poste.nom, '#/manuel/poste/' + ctx.pk + (ctx.niveau ? '/' + ctx.niveau.id : '')]);
    if (ctx.niveau) fil.push([ctx.niveau.nom, '#/manuel/poste/' + ctx.pk + '/' + ctx.niveau.id]);
    if (c) fil.push([c.nom, '#/manuel/competence/' + cid + (ctx.poste ? '/' + ctx.pk + (ctx.niveau ? '/' + ctx.niveau.id : '') : '')]);
    var p = id != null ? I.procedure.get(id) : null;
    fil.push([(p && p.titre) || 'Procédure', null]);
    return { titre: (p && p.titre) || 'Procédure', kicker: 'Procédure opératoire', corps: filAriane(fil) + lectureProcedure(id, function (x) { return '#/manuel/competence/' + x; }) };
  }

  /* ── Routes API (documentation) ─────────────────────────────────────── */

  var STATUTS = {
    contrat: ['Contrat documenté', 'chip--ok', 'Le swagger décrit la réponse champ par champ.'],
    decrit: ['Décrite, sans schéma', 'chip--soft', 'Le swagger nomme la route et ses codes, pas la forme de la réponse.'],
    runtime: ['Sans contrat', 'chip--mute', 'Route enregistrée dans le panel, sans contrat dans le swagger : la forme se lit à la sonde.'],
    casse: ['Cassée (500)', 'chip--ko', 'Le swagger la déclare en erreur 500 : contrôleur manquant ou TypeError côté panel.'],
  };

  function cleRoute(r) { return r.methode + ' ' + r.chemin; }

  function idPour(prec) {
    if (!S.ref) return null;
    var R = S.ref;
    var prem = function (arr) { return arr && arr.length ? arr[0].id : null; };
    var poste = R.postes.filter(function (p) { return p.id != null; })[0];
    var niveau = R.postes.reduce(function (a, p) { return a || p.niveaux[0]; }, null);
    switch (prec) {
      case 'positions': return poste ? poste.id : null;
      case 'position-levels': case 'levels': return niveau ? niveau.id : null;
      case 'competencies': return prem(R.competences);
      case 'competency-subcategories': return (R.competences.filter(function (c) { return c.sousCategorieId != null; })[0] || {}).sousCategorieId || null;
      case 'trainings': return prem(R.formations);
      case 'operational-procedures': return prem(R.procedures);
      case 'resources': return prem(R.ressources);
      case 'product-competences': return prem(R.competencesProduit);
      case 'workstations': return prem(R.postesTravail);
      case 'sections': return prem(R.structure.sections);
      case 'categories': return prem(R.structure.categories);
      case 'employees': case 'franchisee-employee': {
        var sh = Object.keys(S.equipe.parShop).map(function (k) { return S.equipe.parShop[k]; }).filter(function (x) { return x.data && x.data.employes && x.data.employes.length; })[0];
        return sh ? sh.data.employes[0].id : null;
      }
      default: return null;
    }
  }

  function resoudre(chemin) {
    var segs = chemin.replace(/^\/api\/v1/, '').split('/');
    return segs.map(function (s, i) {
      if (!/^\{.+\}$/.test(s)) return s;
      var v = idPour(segs[i - 1] || '');
      return v != null ? String(v) : '1';
    }).join('/');
  }

  function ecranApi() {
    if (!S.cat) {
      if (S.catErreur) return { titre: TITRES.api, corps: '<p class="error">Catalogue illisible : ' + esc(S.catErreur) + '</p>' };
      return { titre: TITRES.api, corps: attente('Lecture du catalogue des routes…') };
    }
    var C = S.cat, f = S.f;
    var famSel = S.route.a[0] && C.familles.some(function (x) { return x.cle === S.route.a[0]; }) ? S.route.a[0] : '';
    var q = norm(f.aQ);
    var routes = C.routes.filter(function (r) {
      if (famSel && r.famille !== famSel) return false;
      if (f.aStatut && r.statut !== f.aStatut) return false;
      if (f.aDemande && !r.demande) return false;
      if (q && norm(r.methode + ' ' + r.chemin + ' ' + r.note + ' ' + r.resume).indexOf(q) < 0) return false;
      return true;
    });
    var parStatut = {};
    C.routes.forEach(function (r) { parStatut[r.statut] = (parStatut[r.statut] || 0) + 1; });

    var h = '<div class="grid">' +
      '<section class="card"><h2>Routes documentées</h2><p class="metric">' + n(C.routes.length) + '</p><p class="muted">' + esc(C.source) + ' · généré le ' + esc(C.genere) + '</p></section>' +
      '<section class="card"><h2>État dans le swagger</h2><ul class="chips">' + Object.keys(STATUTS).map(function (k) {
        return '<li class="chip ' + STATUTS[k][1] + '" title="' + esc(STATUTS[k][2]) + '">' + n(parStatut[k] || 0) + ' · ' + esc(STATUTS[k][0]) + '</li>';
      }).join('') + '</ul><p class="muted" style="margin-top:10px">Lues par ce module : ' + n(C.routes.filter(function (r) { return r.module; }).length) + ' · citées dans la demande : ' + n(C.routes.filter(function (r) { return r.demande; }).length) + '</p></section>' +
      '<section class="card"><h2>À retenir</h2><p class="muted" style="line-height:1.55">Les postes et sous-postes passent par ' + chemin('/positions') + ' et ' + chemin('/position-levels') + '. Les routes ' + chemin('/career-*') + ' sont déclarées en 500 (contrôleur manquant), comme ' + chemin('/competency-subcategories/{id}/competencies') + '.</p></section></div>';

    h += '<div class="filters" style="margin-top:16px"><div class="filters__row">' +
      '<a class="filter' + (!famSel ? ' is-on' : '') + '" href="#/api">Toutes</a>' +
      C.familles.map(function (fa) {
        var k = C.routes.filter(function (r) { return r.famille === fa.cle; }).length;
        return '<a class="filter' + (famSel === fa.cle ? ' is-on' : '') + '" href="#/api/' + fa.cle + '">' + esc(fa.nom) + ' · ' + k + '</a>';
      }).join('') + '</div><div class="filters__row">' +
      '<button type="button" class="filter' + (!f.aStatut ? ' is-on' : '') + '" data-act="filtre" data-k="aStatut" data-v="">Tous les états</button>' +
      Object.keys(STATUTS).map(function (k) { return '<button type="button" class="filter' + (f.aStatut === k ? ' is-on' : '') + '" data-act="filtre" data-k="aStatut" data-v="' + k + '">' + esc(STATUTS[k][0]) + '</button>'; }).join('') +
      '<button type="button" class="filter' + (f.aDemande ? ' is-on' : '') + '" data-act="bascule" data-k="aDemande">Citées dans la demande</button>' +
      '<input class="fm-search" type="search" placeholder="Filtrer : verbe, chemin, mot…" data-input="aQ" value="' + esc(f.aQ) + '" /></div></div>';

    C.familles.forEach(function (fa) {
      var rs = routes.filter(function (r) { return r.famille === fa.cle; });
      if (!rs.length) return;
      h += '<div class="section-label">' + esc(fa.nom) + ' · ' + rs.length + '</div><p class="muted" style="margin:4px 0 0;font-size:12.5px">' + esc(fa.note) + '</p>';
      h += '<section class="card table-card" style="margin-top:10px;padding:0;overflow:hidden"><div class="table-scroll"><table><thead><tr><th style="width:70px">Verbe</th><th>Route</th><th>État</th><th>Ce qu’elle fait</th><th class="num">Module</th></tr></thead><tbody>';
      rs.forEach(function (r) {
        var k = cleRoute(r), ouvert = !!S.api.ouvert[k];
        h += '<tr class="row-link" data-act="api-ouvrir" data-k="' + esc(k) + '"><td><span class="fm-verbe fm-verbe--' + r.methode + '">' + r.methode + '</span></td>' +
          '<td>' + chemin(r.chemin.replace(/^\/api\/v1/, '')) + (r.demande ? ' <span class="chip chip--accent" style="font-size:10px;padding:2px 7px">demande</span>' : '') + '</td>' +
          '<td><span class="chip ' + STATUTS[r.statut][1] + '">' + esc(STATUTS[r.statut][0]) + '</span></td>' +
          '<td>' + esc(r.note) + '</td><td class="num">' + (r.module ? '<span class="chip chip--ok">lue</span>' : '<span class="muted">—</span>') + '</td></tr>';
        if (ouvert) h += '<tr class="fm-detail"><td colspan="5">' + detailRoute(r) + '</td></tr>';
      });
      h += '</tbody></table></div></section>';
    });
    if (!routes.length) h += '<p class="muted" style="margin-top:14px">Aucune route ne correspond aux filtres.</p>';
    return { titre: TITRES.api, kicker: 'Documentation', corps: h };
  }

  function listeChamps(arr, vide) {
    if (!arr || !arr.length) return '<p class="muted">' + vide + '</p>';
    return '<dl class="fm-dl">' + arr.map(function (c) { return '<dt><span class="fm-code">' + esc(c.champ) + '</span></dt><dd class="muted">' + esc(c.type) + '</dd>'; }).join('') + '</dl>';
  }

  function detailRoute(r) {
    var k = cleRoute(r);
    var h = '<p style="margin:0 0 10px"><span class="fm-verbe fm-verbe--' + r.methode + '">' + r.methode + '</span> ' + chemin(r.chemin) + '</p>';
    h += '<p class="muted" style="margin-bottom:12px">' + esc(STATUTS[r.statut][2]) + (r.tag ? ' · tag swagger : ' + esc(r.tag) : '') + (r.source ? ' · source panel : ' + chemin(r.source) : '') + '</p>';
    h += '<div class="fm-cols">';
    h += '<div><div class="section-label" style="margin-top:0">Paramètres</div>' + (r.params.length ? '<dl class="fm-dl" style="margin-top:8px">' + r.params.map(function (p) {
      return '<dt><span class="fm-code">' + esc(p.nom) + '</span> <span class="muted">(' + esc(p.ou) + (p.requis ? ', requis' : '') + ')</span></dt><dd class="muted">' + esc(p.type || '') + (p.note && !/Path segment required|One or more decimal|Positive-looking/.test(p.note) ? ' — ' + esc(p.note) : '') + '</dd>';
    }).join('') + '</dl>' : '<p class="muted" style="margin-top:8px">Aucun.</p>') + '</div>';
    h += '<div><div class="section-label" style="margin-top:0">Corps envoyé</div><div style="margin-top:8px">' + listeChamps(r.corps, r.methode === 'GET' || r.methode === 'DELETE' ? 'Pas de corps.' : 'Non décrit par le swagger.') + '</div></div>';
    h += '<div><div class="section-label" style="margin-top:0">Réponse</div><div style="margin-top:8px">' + listeChamps(r.reponse, 'Forme non décrite — voir la sonde.') + '</div></div>';
    h += '<div><div class="section-label" style="margin-top:0">Codes</div><dl class="fm-dl" style="margin-top:8px">' + r.codes.map(function (c) { return '<dt>' + esc(c.code) + '</dt><dd class="muted">' + esc(c.note) + '</dd>'; }).join('') + '</dl></div>';
    h += '</div>';
    if (r.methode === 'GET') {
      var ch = S.api.chemins[k] != null ? S.api.chemins[k] : resoudre(r.chemin);
      var s = S.api.sondes[k];
      h += '<div class="section-label">Tester en direct (lecture seule, via le compte du cockpit)</div><div class="fm-probe"><input type="text" data-probe="' + esc(k) + '" value="' + esc(ch) + '" aria-label="Chemin à tester" />' +
        '<button type="button" class="filter is-on" data-act="api-sonder" data-k="' + esc(k) + '"' + (s && s.charge ? ' disabled' : '') + '>' + (s && s.charge ? 'Appel…' : 'Tester') + '</button></div>';
      if (s && !s.charge) {
        if (s.erreur) h += '<p class="error">' + esc(s.erreur) + '</p>';
        else {
          var d = s.data, ok = d.code >= 200 && d.code < 300;
          h += '<ul class="chips" style="margin-top:10px"><li class="chip ' + (ok ? 'chip--ok' : 'chip--ko') + '">HTTP ' + esc(d.code) + '</li><li class="chip chip--soft">' + n(d.ms) + ' ms</li><li class="chip chip--soft">' + esc(d.type) + '</li>' +
            (d.n != null ? '<li class="chip chip--soft">' + pl(d.n, 'ligne') + '</li>' : '') + '</ul>';
          if (d.erreur) h += '<p class="error">' + esc(d.erreur) + '</p>';
          if (d.champs && d.champs.length) h += '<p class="muted" style="margin-top:10px">Champs du premier élément : ' + d.champs.map(function (c) { return '<span class="fm-code">' + esc(c) + '</span>'; }).join(', ') + '</p>';
          if (d.exemple != null) h += '<pre class="fm-pre">' + esc(JSON.stringify(d.exemple, null, 2)) + '</pre>';
        }
      }
    } else {
      h += '<p class="muted" style="margin-top:12px">Écriture : documentée ici, jamais appelée par le module (lecture seule).</p>';
    }
    return h;
  }

  /* ── Écritures ──────────────────────────────────────────────────────── */

  /** Le message laissé par la dernière écriture, en tête de l'écran. */
  function messageFlash() {
    if (!S.flash) return '';
    return '<div class="fm-banner fm-banner--' + (S.flash.genre === 'ok' ? 'ok' : 'error') + '" role="status"><span>' + esc(S.flash.texte) + '</span>' +
      '<button type="button" class="fm-banner__fermer" data-act="flash-fermer" aria-label="Fermer">×</button></div>';
  }
  var minuteurFlash = null;
  function flash(genre, texte) {
    S.flash = { genre: genre, texte: texte };
    clearTimeout(minuteurFlash);
    minuteurFlash = setTimeout(function () { S.flash = null; rendre(true); }, genre === 'ok' ? 6000 : 15000);
  }

  /**
   * Une écriture : une à la fois, le bouton dit qu'elle part, la réponse du
   * serveur (déjà relue au panel) corrige l'état de l'écran par `appliquer`,
   * qui rend le message à afficher. Une erreur reste dans la fenêtre ouverte.
   */
  function ecrire(cle, methode, chemin, corps, appliquer) {
    if (S.occupe) return;
    S.occupe = cle;
    if (S.modale) S.modale.erreur = null;
    rendre(true);
    appel(methode, chemin, corps, 120000).then(function (r) {
      S.occupe = null;
      var msg = appliquer(r);
      flash('ok', r.deja ? 'Déjà ainsi dans le panel — rien à changer.' : msg);
      rendre(true);
    }, function (e) {
      S.occupe = null;
      var msg = 'Le panel n’a pas enregistré : ' + e.message;
      if (S.modale) S.modale.erreur = msg; else flash('error', msg);
      rendre(true);
    });
  }
  function bouton(cle, act, libelle, attrs, cls) {
    var occ = S.occupe === cle;
    return '<button type="button" class="filter' + (cls ? ' ' + cls : '') + '" data-act="' + act + '"' + (attrs || '') + (S.occupe ? ' disabled' : '') + '>' + (occ ? 'Enregistrement…' : libelle) + '</button>';
  }

  // Ce que le serveur rend, appliqué à l'état de l'écran.
  function majNiveau(n) {
    S.ref.postes.forEach(function (p) { p.niveaux.forEach(function (nv) { if (nv.id === n.id) { nv.competences = n.competences; nv.competencesLues = true; } }); });
    indexer();
  }
  function majLiens(cid, liens) {
    if (S.liens) S.liens.liens[cid] = liens;
    S.competences[cid] = { data: { competence: I.comp.get(cid) || (S.competences[cid] && S.competences[cid].data && S.competences[cid].data.competence), liens: liens } };
    indexer();
  }
  function majCompetence(c) {
    var i = -1;
    S.ref.competences.forEach(function (x, k) { if (x.id === c.id) i = k; });
    if (i >= 0) S.ref.competences[i] = c; else S.ref.competences.push(c);
    if (S.liens && !S.liens.liens[c.id]) S.liens.liens[c.id] = { procedures: [], formations: [], ressources: [] };
    indexer();
  }
  function oterCompetence(cid) {
    S.ref.competences = S.ref.competences.filter(function (x) { return x.id !== cid; });
    S.ref.postes.forEach(function (p) { p.niveaux.forEach(function (nv) { nv.competences = nv.competences.filter(function (x) { return x !== cid; }); }); });
    if (S.liens) delete S.liens.liens[cid];
    delete S.competences[cid];
    indexer();
  }
  function majEmploye(sid, f) {
    var d = S.equipe.parShop[sid];
    if (d && d.data) d.data.employes.forEach(function (e) { if (e.id === f.id) { e.postes = f.postes; e.competences = f.competences; e.lu = true; } });
  }
  function nomComp(cid) { var c = I.comp.get(cid); return c ? c.nom : 'compétence #' + cid; }

  // Les gestes.
  function ajouterAuNiveau(nid, cid) {
    ecrire('niv+' + nid + '-' + cid, 'POST', '/formation/niveau/' + nid + '/competences', { competence: cid }, function (r) {
      majNiveau(r.niveau);
      return '« ' + nomComp(cid) + ' » est exigée au niveau ' + niveauLibelle(nid) + '.';
    });
  }
  function retirerDuNiveau(nid, cid) {
    ecrire('niv-' + nid + '-' + cid, 'DELETE', '/formation/niveau/' + nid + '/competences/' + cid, null, function (r) {
      majNiveau(r.niveau); S.modale = null;
      return '« ' + nomComp(cid) + ' » n’est plus exigée au niveau ' + niveauLibelle(nid) + '.';
    });
  }
  var TYPES = { formation: ['formations', 'formation', 'Formation'], procedure: ['procedures', 'procédure', 'Procédure'], ressource: ['ressources', 'ressource', 'Ressource'] };
  function rattacher(cid, type, id) {
    ecrire('lien+' + cid + type + id, 'POST', '/formation/competence/' + cid + '/liens', { type: type, id: id }, function (r) {
      majLiens(cid, r.liens);
      return TYPES[type][2] + ' rattachée à « ' + nomComp(cid) + ' ».';
    });
  }
  function detacher(cid, type, id) {
    ecrire('lien-' + cid + type + id, 'DELETE', '/formation/competence/' + cid + '/liens/' + type + '/' + id, null, function (r) {
      majLiens(cid, r.liens); S.modale = null;
      return TYPES[type][2] + ' détachée de « ' + nomComp(cid) + ' ».';
    });
  }
  function enregistrerCompetence() {
    var m = S.modale, f = m.form;
    var corps = { nom: (f.nom || '').trim(), description: (f.description || '').trim(), verification: (f.verification || '').trim(), sousCategorie: +f.sousCategorie || null };
    if (!corps.nom || !corps.sousCategorie) { m.erreur = 'Le nom et la sous-catégorie sont obligatoires.'; rendre(true); return; }
    if (m.cid) {
      var c = I.comp.get(m.cid) || {};
      var diff = {};
      if (corps.nom !== c.nom) diff.nom = corps.nom;
      if (corps.description !== (c.description || '')) diff.description = corps.description;
      if (corps.verification !== (c.verification || '')) diff.verification = corps.verification;
      if (corps.sousCategorie !== c.sousCategorieId) diff.sousCategorie = corps.sousCategorie;
      if (!Object.keys(diff).length) { S.modale = null; rendre(true); return; }
      ecrire('comp~' + m.cid, 'PATCH', '/formation/competences/' + m.cid, diff, function (r) {
        majCompetence(r.competence); S.modale = null;
        return 'Compétence « ' + r.competence.nom + ' » enregistrée dans le panel.';
      });
    } else {
      ecrire('comp+', 'POST', '/formation/competences', corps, function (r) {
        majCompetence(r.competence); S.modale = null;
        setTimeout(function () { location.hash = '#/competences/' + r.competence.id; }, 0);
        return 'Compétence « ' + r.competence.nom + ' » créée dans le panel (#' + r.competence.id + ').';
      });
    }
  }
  function supprimerCompetence(cid) {
    var nom = nomComp(cid);
    ecrire('comp-' + cid, 'DELETE', '/formation/competences/' + cid, null, function () {
      oterCompetence(cid); S.modale = null;
      setTimeout(function () { location.hash = '#/competences'; }, 0);
      return 'Compétence « ' + nom + ' » supprimée du catalogue.';
    });
  }
  function validerPour(sid, eid, cid, oui) {
    ecrire('emp' + eid + (oui ? '+' : '-') + cid, oui ? 'PUT' : 'DELETE', '/formation/employe/' + eid + '/competences/' + cid + '?shop=' + sid, null, function (r) {
      majEmploye(sid, r.employe); S.modale = null;
      return (oui ? 'Validée : ' : 'Validation retirée : ') + '« ' + nomComp(cid) + ' ».';
    });
  }
  function affecterNiveau(sid, eid, pid, nid) {
    ecrire('emp' + eid + 'niv', 'PUT', '/formation/employe/' + eid + '/poste?shop=' + sid, { poste: pid, niveau: nid }, function (r) {
      majEmploye(sid, r.employe); S.modale = null;
      return 'Niveau affecté : ' + niveauLibelle(nid) + '.';
    });
  }

  /** Une demande de confirmation, au gabarit du voile de confirmation du marketing. */
  function confirmer(titre, texte, libelle, cle, action) {
    S.modale = { type: 'confirmer', titre: titre, texte: texte, libelle: libelle, cle: cle, action: action };
    rendre(true);
  }

  /** La liste à choisir d'une fenêtre (compétences à exiger, formations à rattacher…). */
  function listeChoix(items, cleOcc, act, attrs) {
    if (!items.length) return '<p class="muted" style="margin-top:12px">Rien à proposer.</p>';
    return '<ul class="fm-pick">' + items.slice(0, 200).map(function (x) {
      return '<li><div><strong>' + esc(x.nom) + '</strong>' + (x.note ? '<span class="muted">' + esc(x.note) + '</span>' : '') + '</div>' +
        (x.fait ? '<span class="chip chip--ok">' + esc(x.fait) + '</span>' : bouton(cleOcc(x), act, x.libelle || 'Ajouter', ' data-id="' + x.id + '"' + (attrs || ''))) + '</li>';
    }).join('') + (items.length > 200 ? '<li class="muted">… ' + (items.length - 200) + ' de plus : précisez la recherche.</li>' : '') + '</ul>';
  }

  function fenetre() {
    var m = S.modale;
    if (!m) return '';
    var titre = '', corps = '', actions = '<button type="button" class="filter" data-act="modale-fermer">' + (m.type === 'confirmer' || m.type === 'competence' || m.type === 'poste' ? 'Annuler' : 'Fermer') + '</button>';
    var q = norm(m.q);
    var recherche = function (ph) { return '<input class="fm-search fm-modale__q" type="search" placeholder="' + esc(ph) + '" data-minput="q" value="' + esc(m.q || '') + '" />'; };
    if (m.type === 'confirmer') {
      titre = m.titre;
      corps = '<p class="muted" style="line-height:1.55">' + m.texte + '</p>';
      actions += bouton(m.cle, 'modale-confirmer', m.libelle, '', 'confirm__danger');
    } else if (m.type === 'ajoutNiveau') {
      var nv = I.niveauById.get(m.nid);
      titre = 'Exiger une compétence — ' + niveauLibelle(m.nid);
      var deja = new Set(nv ? nv.competences : []);
      var items = S.ref.competences.filter(function (c) { return !q || norm(c.nom + ' ' + (c.categorie || '') + ' ' + (c.sousCategorie || '')).indexOf(q) >= 0; })
        .sort(function (a, b) { return (a.categorie || '~').localeCompare(b.categorie || '~', 'fr') || a.nom.localeCompare(b.nom, 'fr'); })
        .map(function (c) { return { id: c.id, nom: c.nom, note: [c.categorie, c.sousCategorie].filter(Boolean).join(' › '), fait: deja.has(c.id) ? 'Exigée' : null }; });
      corps = '<p class="muted">Le panel exigera la compétence à ce niveau : elle entre dans la fiche du niveau, le manuel et l’écart des équipes.</p>' + recherche('Rechercher dans le catalogue…') +
        listeChoix(items, function (x) { return 'niv+' + m.nid + '-' + x.id; }, 'niv-ajouter', ' data-nid="' + m.nid + '"');
    } else if (m.type === 'lien') {
      var T = TYPES[m.ltype], l = liensDe(m.cid) || { procedures: [], formations: [], ressources: [] };
      titre = 'Rattacher une ' + T[1] + ' — ' + nomComp(m.cid);
      var lies = new Set(l[T[0]].map(function (x) { return x.id; }));
      var source = m.ltype === 'formation' ? S.ref.formations.map(function (f) { return { id: f.id, nom: f.nom, note: [f.categorie, f.sousCategorie].filter(Boolean).join(' › ') }; })
        : m.ltype === 'procedure' ? S.ref.procedures.map(function (p) { return { id: p.id, nom: p.titre, note: [p.categorie, p.sousCategorie].filter(Boolean).join(' › ') }; })
          : S.ref.ressources.map(function (r) { return { id: r.id, nom: r.titre, note: [r.type, r.categorie].filter(Boolean).join(' · ') }; });
      var it2 = source.filter(function (x) { return !q || norm(x.nom + ' ' + x.note).indexOf(q) >= 0; })
        .sort(function (a, b) { return a.nom.localeCompare(b.nom, 'fr'); })
        .map(function (x) { return Object.assign(x, { fait: lies.has(x.id) ? 'Rattachée' : null, libelle: 'Rattacher' }); });
      corps = recherche('Rechercher…') + listeChoix(it2, function (x) { return 'lien+' + m.cid + m.ltype + x.id; }, 'lien-ajouter', ' data-cid="' + m.cid + '" data-type="' + m.ltype + '"');
    } else if (m.type === 'competence') {
      titre = m.cid ? 'Modifier la compétence' : 'Nouvelle compétence';
      var f = m.form;
      var sc = (S.ref.structure.sousCategories || []).slice();
      var catNom = {};
      (S.ref.structure.categories || []).forEach(function (c) { catNom[c.id] = c.nom; });
      S.ref.competences.forEach(function (c) { if (c.categorieId != null && c.categorie) catNom[c.categorieId] = catNom[c.categorieId] || c.categorie; });
      var groupes = {};
      sc.forEach(function (x) { var k = catNom[x.categorieId] || 'Sans catégorie'; (groupes[k] = groupes[k] || []).push(x); });
      var options = '<option value="">— Choisir —</option>' + Object.keys(groupes).sort(function (a, b) { return a.localeCompare(b, 'fr'); }).map(function (g) {
        return '<optgroup label="' + esc(g) + '">' + groupes[g].sort(function (a, b) { return a.nom.localeCompare(b.nom, 'fr'); }).map(function (x) {
          return '<option value="' + x.id + '"' + (String(f.sousCategorie) === String(x.id) ? ' selected' : '') + '>' + esc(x.nom) + '</option>';
        }).join('') + '</optgroup>';
      }).join('');
      corps = '<label class="field field--block">Sous-catégorie<select class="fm-select" data-mform="sousCategorie">' + options + '</select></label>' +
        '<label class="field field--block">Nom<input type="text" maxlength="255" data-mform="nom" value="' + esc(f.nom || '') + '" /></label>' +
        '<label class="field field--block">Description<textarea rows="4" maxlength="5000" data-mform="description">' + esc(f.description || '') + '</textarea></label>' +
        '<label class="field field--block">Méthode de vérification<textarea rows="2" maxlength="2000" data-mform="verification">' + esc(f.verification || '') + '</textarea></label>';
      actions += bouton(m.cid ? 'comp~' + m.cid : 'comp+', 'competence-enregistrer', m.cid ? 'Enregistrer' : 'Créer', '', 'is-on');
    } else if (m.type === 'poste') {
      titre = 'Poste et niveau — ' + esc(m.nom);
      var postes = S.ref.postes.filter(function (p) { return p.id != null && p.niveaux.length; });
      var pid = +m.poste || (postes[0] && postes[0].id);
      var poste = postes.filter(function (p) { return p.id === pid; })[0];
      corps = '<p class="muted">Le panel enregistre le niveau tenu pour ce poste (un niveau par poste).</p>' +
        '<label class="field field--block">Poste<select class="fm-select" data-minput="poste">' + postes.map(function (p) { return '<option value="' + p.id + '"' + (p.id === pid ? ' selected' : '') + '>' + esc(p.nom) + '</option>'; }).join('') + '</select></label>' +
        '<label class="field field--block">Niveau<select class="fm-select" data-minput="niveau">' + (poste ? poste.niveaux.map(function (nv, i) {
          return '<option value="' + nv.id + '"' + (String(m.niveau) === String(nv.id) ? ' selected' : '') + '>' + (i + 1) + ' · ' + esc(nv.nom) + '</option>';
        }).join('') : '') + '</select></label>';
      actions += bouton('emp' + m.eid + 'niv', 'poste-enregistrer', 'Affecter', '', 'is-on');
    }
    return '<div class="confirm" data-act="modale-fond"><div class="confirm__box card fm-modale" role="dialog" aria-modal="true" aria-label="' + esc(titre) + '">' +
      '<h3>' + esc(titre) + '</h3>' + corps + (m.erreur ? '<p class="error">' + esc(m.erreur) + '</p>' : '') +
      '<div class="confirm__actions">' + actions + '</div></div></div>';
  }

  /* ── Rendu ──────────────────────────────────────────────────────────── */

  var ECRANS = {
    tableau: ecranTableau, postes: ecranPostes, competences: ecranCompetences, formations: ecranFormations,
    procedures: ecranProcedures, ressources: ecranRessources, produit: ecranProduit, equipes: ecranEquipes,
    manuel: ecranManuel, api: ecranApi, parcours: ecranParcoursEquipes,
  };

  function rendre(garder) {
    document.body.classList.toggle('fm-tablette', S.tablette);
    var actif = document.activeElement, focus = null;
    if (actif && actif.getAttribute) {
      ['data-input', 'data-probe', 'data-minput', 'data-mform'].forEach(function (a) {
        if (!focus && actif.hasAttribute(a)) focus = { attr: a, cle: actif.getAttribute(a), debut: actif.selectionStart, fin: actif.selectionEnd };
      });
    }
    var ancien = document.getElementById('corps');
    var defil = garder && ancien ? ancien.scrollTop : 0;

    var e = S.route.ecran, v;
    if (e !== 'api' && !I) v = { titre: TITRES[e] || 'Formation', corps: sansReferentiel() };
    else v = ECRANS[e]();
    root.innerHTML = coquille(v);
    document.title = v.titre + ' — Formation · L\'Atelier by';

    var corps = document.getElementById('corps');
    if (corps && defil) corps.scrollTop = defil;
    if (focus) {
      var el = root.querySelector('[' + focus.attr + '="' + (window.CSS && CSS.escape ? CSS.escape(focus.cle) : focus.cle) + '"]');
      if (el) { el.focus(); try { el.setSelectionRange(focus.debut, focus.fin); } catch (x) { /* select */ } }
    }
    apres();
  }

  /** Les lectures à la demande, d'après l'écran affiché. */
  function apres() {
    var e = S.route.ecran;
    if (e === 'api' && !S.cat && !S.catCharge) chargerCatalogue();
    if (!I) return;
    if (e === 'postes' && S.route.a[0] !== 'v25') {
      var pid = arg(0), nid = arg(1);
      var poste = (pid != null && I.posteById.get(pid)) || (S.route.a[0] === 'sans' ? S.ref.postes.filter(function (p) { return p.id == null; })[0] : posteDefaut());
      var nv = nid != null ? I.niveauById.get(nid) : poste && poste.niveaux[0];
      if (nv) chargerUn(S.niveaux, nv.id, '/formation/niveau?id=' + nv.id);
    }
    if (e === 'competences' && arg(0) != null && !I.liens) chargerUn(S.competences, arg(0), '/formation/competence?id=' + arg(0));
    if (e === 'manuel' && S.route.a[0] === 'competence' && arg(1) != null && !I.liens) chargerUn(S.competences, arg(1), '/formation/competence?id=' + arg(1));
    if (e === 'formations' && arg(0) != null) chargerUn(S.formations, arg(0), '/formation/formation?id=' + arg(0));
    if ((e === 'formations' || e === 'postes' || e === 'tableau') && !S.v25 && !S.v25Charge) {
      S.v25Charge = true;
      lire('/formation/v25', 30000).then(function (d) { S.v25 = d; rendre(true); }, function (er) { S.v25Erreur = er.message; rendre(true); });
    }
    if (e === 'tableau' && !S.carte && !S.carteCharge && !S.carteErreur) {
      S.carteCharge = true;
      lire('/formation/carte?_cache=300' + (S.carteForcer ? '&rafraichir=1' : ''), 280000).then(function (d) { S.carte = d; S.carteCharge = false; S.carteForcer = false; rendre(true); },
        function (er) { S.carteErreur = er.message; S.carteCharge = false; rendre(true); });
    }
    if (e === 'parcours' || e === 'tableau' || e === 'equipes') {
      if (e === 'parcours' && !S.equipe.magasins && !S.equipe.charge && !S.equipe.erreur) chargerMagasins();
      if (!S.pcListe && !S.pcCharge && !S.pcErreur) {
        S.pcCharge = true;
        lire('/parcours/liste', 60000).then(function (d) { S.pcListe = d.parcours || []; S.pcCharge = false; rendre(true); }, function (er) { S.pcErreur = er.message; S.pcCharge = false; rendre(true); });
      }
    }
    if (e === 'procedures' && arg(0) != null) chargerUn(S.procedures, arg(0), '/formation/procedure?id=' + arg(0));
    if (e === 'manuel' && S.route.a[0] === 'procedure' && arg(1) != null) chargerUn(S.procedures, arg(1), '/formation/procedure?id=' + arg(1));
    if (e === 'equipes') {
      if (!S.equipe.magasins && !S.equipe.charge && !S.equipe.erreur) chargerMagasins();
      var sid = arg(0);
      if (sid != null && S.equipe.magasins) chargerUn(S.equipe.parShop, sid, '/formation/equipe?shop=' + sid + '&_cache=300');
    }
  }

  /** Une lecture unitaire, mémorisée dans `boite[id]` — jamais deux fois en même temps. */
  function chargerUn(boite, id, chemin) {
    if (boite[id]) return;
    boite[id] = { charge: true };
    lire(chemin, 90000).then(function (d) { boite[id] = { data: d }; rendre(true); }, function (e) { boite[id] = { erreur: e.message }; rendre(true); });
  }

  function chargerMagasins() {
    S.equipe.charge = true;
    lire('/formation/equipe', 30000).then(function (d) { S.equipe.charge = false; S.equipe.magasins = d.magasins || []; rendre(true); },
      function (e) { S.equipe.charge = false; S.equipe.erreur = e.message; rendre(true); });
  }

  function chargerCatalogue() {
    S.catCharge = true;
    fetch('catalogue.json', { headers: { Accept: 'application/json' } }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) { S.cat = d; S.catCharge = false; rendre(true); }, function (e) { S.catErreur = e.message; S.catCharge = false; rendre(true); });
  }

  function chargerReferentiel(forcer) {
    S.refCharge = true; S.refErreur = null;
    if (forcer) { S.niveaux = {}; S.procedures = {}; S.formations = {}; S.competences = {}; S.equipe.parShop = {}; S.pcListe = null; S.pcErreur = null; }
    rendre(true);
    lire('/formation/referentiel?_cache=600' + (forcer ? '&rafraichir=1' : ''), 170000).then(function (d) {
      S.ref = d; S.refCharge = false;
      // Le référentiel v25 se relit APRÈS le panel : ses correspondances (niveaux, postes absents) en dépendent.
      if (forcer) { S.liens = null; S.v25 = null; S.v25Charge = false; S.carte = null; S.carteErreur = null; S.carteForcer = true; }
      indexer();
      rendre(true);
      chargerLiens(forcer);
    }, function (e) {
      S.refCharge = false; S.refErreur = e;
      rendre(true);
    });
  }

  function chargerLiens(forcer) {
    S.liensCharge = true; S.liensErreur = null;
    // Une seule adresse (sans paramètre) : le serveur corrige ce cache après chaque écriture.
    lire('/formation/liens?_cache=900' + (forcer ? '&rafraichir=1' : ''), 280000).then(function (d) {
      S.liens = d; S.liensCharge = false; indexer(); rendre(true);
      // Les compétences exigées par un niveau mais absentes du catalogue : lues une par une.
      S.ref.competences.forEach(function (c) { if (c.horsCatalogue && !d.liens[c.id]) chargerUn(S.competences, c.id, '/formation/competence?id=' + c.id); });
    }, function (e) {
      S.liensCharge = false; S.liensErreur = e.message; rendre(true);
    });
  }

  /* ── Événements ─────────────────────────────────────────────────────── */

  /** Les gestes d'écriture et les fenêtres. Rend vrai si l'action est traitée. */
  function gestes(act, t, ev) {
    var num = function (k) { return +t.getAttribute(k); };
    switch (act) {
      case 'flash-fermer': S.flash = null; rendre(true); return true;
      case 'modale-fond': if (ev.target === t && !S.occupe) { S.modale = null; rendre(true); } return true;
      case 'modale-fermer': if (!S.occupe) { S.modale = null; rendre(true); } return true;
      case 'modale-confirmer': if (S.modale && S.modale.action) S.modale.action(); return true;
      case 'niv-ouvrir': S.modale = { type: 'ajoutNiveau', nid: num('data-nid'), q: '' }; rendre(true); focusModale(); return true;
      case 'niv-ajouter': ajouterAuNiveau(num('data-nid'), num('data-id')); return true;
      case 'poste-creer': {
        var pcode = t.getAttribute('data-code'), pnom = t.getAttribute('data-nom');
        confirmer('Créer « ' + esc(pnom) + ' » dans le panel ?', 'Le panel aura un nouveau poste <strong>' + esc(pnom) + '</strong>, avec un niveau du même nom. Ses critères d’admission et ses formations restent ceux du référentiel ; les compétences du niveau s’ajoutent ensuite ici, comme pour les autres postes.',
          'Créer dans le panel', 'poste-' + pcode, function () {
            ecrire('poste-' + pcode, 'POST', '/formation/postes', { code: pcode }, function (r) {
              S.modale = null; S.v25Charge = true;   // relu après le référentiel (chargerReferentiel)
              location.hash = '#/postes/' + r.poste.id;
              chargerReferentiel(true);
              return 'Poste créé dans le panel : ' + r.poste.nom + ' (#' + r.poste.id + '), niveau « ' + r.niveau.nom + ' ».';
            });
          });
        return true;
      }
      case 'niv-retirer': {
        var nid = num('data-nid'), cid = num('data-cid');
        confirmer('Ne plus exiger cette compétence ?', '« ' + esc(nomComp(cid)) + ' » ne sera plus exigée au niveau <strong>' + esc(niveauLibelle(nid)) + '</strong>. La compétence reste au catalogue, et les personnes qui l’ont validée la gardent.',
          'Retirer du niveau', 'niv-' + nid + '-' + cid, function () { retirerDuNiveau(nid, cid); });
        return true;
      }
      case 'lien-ouvrir': S.modale = { type: 'lien', cid: num('data-cid'), ltype: t.getAttribute('data-type'), q: '' }; rendre(true); focusModale(); return true;
      case 'lien-ajouter': rattacher(num('data-cid'), t.getAttribute('data-type'), num('data-id')); return true;
      case 'lien-retirer': {
        var c2 = num('data-cid'), ty = t.getAttribute('data-type'), id2 = num('data-id');
        var l2 = liensDe(c2) || { procedures: [], formations: [], ressources: [] };
        var x2 = l2[TYPES[ty][0]].filter(function (x) { return x.id === id2; })[0];
        confirmer('Détacher cette ' + TYPES[ty][1] + ' ?', '« ' + esc(x2 ? x2.nom : '#' + id2) + ' » ne sera plus rattachée à <strong>' + esc(nomComp(c2)) + '</strong>. Elle reste dans le panel.',
          'Détacher', 'lien-' + c2 + ty + id2, function () { detacher(c2, ty, id2); });
        return true;
      }
      case 'comp-nouvelle': {
        var cat = S.f.categorie;
        var sc = (S.ref.structure.sousCategories || []).filter(function (x) { return S.ref.competences.some(function (c) { return c.sousCategorieId === x.id && (c.categorie || 'Sans catégorie') === cat; }); })[0];
        S.modale = { type: 'competence', cid: null, form: { sousCategorie: sc ? sc.id : '', nom: '', description: '', verification: '' } };
        rendre(true); focusModale('[data-mform="nom"]'); return true;
      }
      case 'comp-modifier': {
        var c3 = I.comp.get(num('data-cid'));
        if (!c3) return true;
        S.modale = { type: 'competence', cid: c3.id, form: { sousCategorie: c3.sousCategorieId || '', nom: c3.nom, description: c3.description || '', verification: c3.verification || '' } };
        rendre(true); focusModale('[data-mform="nom"]'); return true;
      }
      case 'competence-enregistrer': enregistrerCompetence(); return true;
      case 'comp-supprimer': {
        var c4 = num('data-cid'), nivs = I.niveauxParComp.get(c4) || [], l4 = liensDe(c4);
        var impact = nivs.length ? ' Elle est exigée par ' + pl(nivs.length, 'niveau') + ' (' + esc(nivs.map(niveauLibelle).join(', ')) + ') : elle en disparaîtra.' : ' Aucun niveau ne l’exige.';
        if (l4 && (l4.formations.length || l4.procedures.length || l4.ressources.length)) impact += ' Ses liens (' + n(l4.formations.length) + ' formation(s), ' + n(l4.procedures.length) + ' procédure(s), ' + n(l4.ressources.length) + ' ressource(s)) seront perdus.';
        confirmer('Supprimer du catalogue ?', '« <strong>' + esc(nomComp(c4)) + '</strong> » sera supprimée du panel.' + impact + ' Ce geste ne se défait pas depuis le module.',
          'Supprimer définitivement', 'comp-' + c4, function () { supprimerCompetence(c4); });
        return true;
      }
      case 'emp-valider': validerPour(num('data-sid'), num('data-eid'), num('data-cid'), true); return true;
      case 'emp-retirer': {
        var sid = num('data-sid'), eid = num('data-eid'), c5 = num('data-cid');
        confirmer('Retirer la validation ?', '« ' + esc(nomComp(c5)) + ' » ne sera plus comptée comme acquise pour cette personne.', 'Retirer', 'emp' + eid + '-' + c5, function () { validerPour(sid, eid, c5, false); });
        return true;
      }
      case 'emp-poste': {
        var s6 = num('data-sid'), e6 = num('data-eid');
        var d6 = S.equipe.parShop[s6], emp = d6 && d6.data ? d6.data.employes.filter(function (x) { return x.id === e6; })[0] : null;
        var tenu = emp && emp.postes[0];
        S.modale = { type: 'poste', sid: s6, eid: e6, nom: emp ? emp.nom : '#' + e6, poste: tenu ? tenu.id : '', niveau: tenu ? tenu.niveauId : '' };
        rendre(true); return true;
      }
      case 'poste-enregistrer': {
        var m = S.modale;
        var selP = root.querySelector('[data-minput="poste"]'), selN = root.querySelector('[data-minput="niveau"]');
        if (!selP || !selN || !selN.value) { m.erreur = 'Choisissez un poste et un niveau.'; rendre(true); return true; }
        affecterNiveau(m.sid, m.eid, +selP.value, +selN.value);
        return true;
      }
      case 'emp-suivant': {
        var s7 = num('data-sid'), e7 = num('data-eid'), p7 = num('data-pid'), n7 = num('data-nid');
        confirmer('Passer au niveau suivant ?', 'Le panel enregistrera le niveau <strong>' + esc(niveauLibelle(n7)) + '</strong> pour cette personne. Les compétences de ce niveau deviendront celles à acquérir.',
          'Passer au niveau', 'emp' + e7 + 'niv', function () { affecterNiveau(s7, e7, p7, n7); });
        return true;
      }
    }
    return false;
  }

  /** Le premier champ de la fenêtre ouverte prend la main (clavier, tablette). */
  function focusModale(sel) {
    setTimeout(function () { var el = root.querySelector(sel || '.fm-modale input, .fm-modale select'); if (el) el.focus(); }, 0);
  }

  root.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-act], [data-href], [data-lien]');
    if (!t || !root.contains(t)) return;
    if (ev.target.closest('a[href]') && ev.target.closest('a[href]') !== t && t.contains(ev.target.closest('a[href]'))) return; // un lien dans la ligne garde son rôle
    var act = t.getAttribute('data-act');
    if (!act && t.hasAttribute('data-lien')) { location.href = t.getAttribute('data-lien'); return; }
    if (!act) { location.hash = t.getAttribute('data-href'); return; }
    if (act === 'pc-filtre') { S.pcFiltre = t.getAttribute('data-v'); rendre(true); return; }
    if (act === 'filtre' && t.getAttribute('data-k') === 'pcFiltreF') { S.pcFiltre = ''; rendre(true); return; }
    if (gestes(act, t, ev)) return;
    if (act === 'menu') { S.menu = !S.menu; rendre(true); return; }
    if (act === 'tablette') {
      S.tablette = !S.tablette;
      try { localStorage.setItem('fm.tablette', S.tablette ? '1' : '0'); } catch (e) { /* rien */ }
      if (S.tablette && S.route.ecran !== 'manuel') location.hash = '#/manuel'; else rendre(true);
      return;
    }
    if (act === 'rafraichir') { chargerReferentiel(true); return; }
    if (act === 'imprimer') { window.print(); return; }
    if (act === 'filtre') { S.f[t.getAttribute('data-k')] = t.getAttribute('data-v'); rendre(true); return; }
    if (act === 'voir-comp') { S.f.vue = t.getAttribute('data-v'); location.hash = '#/competences'; return; }
    if (act === 'fiche-vue') { S.ficheVue = t.getAttribute('data-v'); rendre(true); return; }
    if (act === 'carte-vue') { S.carteVue = t.getAttribute('data-v'); try { localStorage.setItem('fm.carte', S.carteVue); } catch (x) { /* rien */ } rendre(true); return; }
    if (act === 'bascule') { var k = t.getAttribute('data-k'); S.f[k] = !S.f[k]; rendre(true); return; }
    if (act === 'equipe-ouvrir') { var id = +t.getAttribute('data-id'); S.equipe.ouvert = S.equipe.ouvert === id ? null : id; rendre(true); return; }
    if (act === 'api-ouvrir') { var k2 = t.getAttribute('data-k'); S.api.ouvert[k2] = !S.api.ouvert[k2]; rendre(true); return; }
    if (act === 'api-sonder') {
      var k3 = t.getAttribute('data-k');
      var champ = root.querySelector('[data-probe="' + (window.CSS && CSS.escape ? CSS.escape(k3) : k3) + '"]');
      var chemin = (champ ? champ.value : '').trim();
      S.api.chemins[k3] = chemin;
      S.api.sondes[k3] = { charge: true };
      rendre(true);
      lire('/formation/sonde?chemin=' + encodeURIComponent(chemin), 40000).then(function (d) { S.api.sondes[k3] = { data: d }; rendre(true); },
        function (e) { S.api.sondes[k3] = { erreur: e.message }; rendre(true); });
    }
  });

  root.addEventListener('input', function (ev) {
    var t = ev.target;
    var k = t.getAttribute && t.getAttribute('data-input');
    if (k) { S.f[k] = t.value; rendre(true); return; }
    var mk = t.getAttribute && t.getAttribute('data-minput');
    if (mk && S.modale) { S.modale[mk] = t.value; if (mk === 'poste') S.modale.niveau = ''; rendre(true); return; }
    var mf = t.getAttribute && t.getAttribute('data-mform');
    if (mf && S.modale && S.modale.form) { S.modale.form[mf] = t.value; return; }
    var p = t.getAttribute && t.getAttribute('data-probe');
    if (p) S.api.chemins[p] = t.value;
  });

  // Sur le document : après un rendu, le focus peut être retombé sur <body>, hors de #root.
  document.addEventListener('keydown', function (ev) {
    var p = ev.target.getAttribute && ev.target.getAttribute('data-probe');
    if (p && ev.key === 'Enter') {
      var b = root.querySelector('[data-act="api-sonder"][data-k="' + (window.CSS && CSS.escape ? CSS.escape(p) : p) + '"]');
      if (b) b.click();
    }
    if (ev.key === 'Escape' && S.modale && !S.occupe) { S.modale = null; rendre(true); return; }
    if (ev.key === 'Escape' && S.menu) { S.menu = false; rendre(true); }
  });

  window.addEventListener('hashchange', function () {
    lireRoute();
    S.menu = false;
    // Une fenêtre ne survit pas à un changement d'écran (sauf écriture en cours : sa réponse la fermera).
    if (!S.occupe) S.modale = null;
    rendre(false);
  });

  lireRoute();
  chargerReferentiel(false);
})();
