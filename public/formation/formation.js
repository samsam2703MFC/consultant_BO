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
 * Données : uniquement l'API du cockpit (../api/cockpit/formation/*), qui lit
 * le panel avec le compte consultant — rien n'est écrit dans le panel.
 * Navigation : par l'ancre (#/postes/12/34) — le bouton « retour » de la
 * tablette et les favoris marchent.
 */
(function () {
  'use strict';

  var API = '../api/cockpit';
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

  /** Lecture JSON de l'API du cockpit, avec un délai. */
  function lire(chemin, delai) {
    var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var minuteur = setTimeout(function () { if (ctrl) ctrl.abort(); }, delai || 120000);
    return fetch(API + chemin, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined })
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

  /* ── État ───────────────────────────────────────────────────────────── */

  var S = {
    route: { ecran: 'tableau', a: [] },
    ref: null, refErreur: null, refCharge: false,
    liens: null, liensErreur: null, liensCharge: false,
    niveaux: {}, procedures: {}, formations: {}, competences: {},
    equipe: { magasins: null, erreur: null, charge: false, parShop: {}, ouvert: null },
    cat: null, catErreur: null, catCharge: false,
    api: { ouvert: {}, sondes: {}, chemins: {} },
    f: { q: '', categorie: '', vue: 'tous', fq: '', pq: '', rq: '', mq: '', aFam: '', aStatut: '', aQ: '', aDemande: false },
    menu: false,
    tablette: false,
  };
  var I = null; // index dérivés du référentiel

  try {
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
    if (I && I.liens) return I.liens[cid] || { procedures: [], formations: [], ressources: [] };
    var u = S.competences[cid];
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
  };
  var NAV = [
    ['Pilotage', [['tableau', 'Tableau de bord']]],
    ['Référentiel', [['postes', 'Postes & sous-postes'], ['competences', 'Compétences'], ['formations', 'Formations'],
      ['procedures', 'Procédures opératoires'], ['ressources', 'Base de connaissances'], ['produit', 'Compétences produit']]],
    ['Équipes', [['equipes', 'Compétences des équipes']]],
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
      '<div class="sidebar__head"><img src="../assets/img/logo.png" alt="L\'Atelier By" class="sidebar__logo" /><div class="sidebar__kicker">Module Formation</div></div>' +
      '<div class="sidebar__back"><a href="../">' + icone('M19 12H5M12 19l-7-7 7-7') + 'Retour au cockpit</a></div>' +
      '<div class="sidebar__brand">Réseau L’Atelier by</div>' +
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
      '<main class="app__body' + (v.large ? ' app__body--wide' : '') + '" id="corps">' + v.corps + '</main></div></div>';
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
        (auth ? '<p class="muted" style="margin-top:8px"><a href="../">Ouvrir le cockpit</a>, puis revenez ici.</p>' : '') +
        (e.status === 503 ? '<p class="muted" style="margin-top:8px">La documentation des routes, elle, reste consultable : <a href="#/api">Routes API</a>.</p>' : '') +
        '<p style="margin-top:12px"><button type="button" class="filter" data-act="rafraichir">Réessayer</button></p></section>';
    }
    return attente('Lecture du référentiel dans le panel — postes, niveaux, compétences, formations, procédures…');
  }

  /* ── Tableau de bord ────────────────────────────────────────────────── */

  function ecranTableau() {
    var R = S.ref;
    var niveaux = 0, exigees = new Set();
    R.postes.forEach(function (p) { niveaux += p.niveaux.length; p.niveaux.forEach(function (nv) { nv.competences.forEach(function (c) { exigees.add(c); }); }); });
    var sansFormation = null, sansProcedure = null, sansRien = null;
    if (I.liens) {
      sansFormation = 0; sansProcedure = 0; sansRien = 0;
      exigees.forEach(function (cid) {
        var l = liensDe(cid);
        if (!l.formations.length) sansFormation++;
        if (!l.procedures.length) sansProcedure++;
        if (!l.formations.length && !l.procedures.length && !l.ressources.length) sansRien++;
      });
    }
    var parCat = {};
    R.competences.forEach(function (c) { var k = c.categorie || 'Sans catégorie'; parCat[k] = (parCat[k] || 0) + 1; });
    var cats = Object.keys(parCat).sort(function (a, b) { return parCat[b] - parCat[a]; });

    var h = bandeauLiens();
    h += '<div class="grid">';
    h += '<section class="card"><h2>Postes</h2><p class="metric">' + n(R.postes.filter(function (p) { return p.id != null; }).length) + '</p><p class="muted">' + pl(niveaux, 'niveau') + ' (sous-postes)</p></section>';
    h += '<section class="card"><h2>Compétences</h2><p class="metric">' + n(R.competences.length) + '</p><p class="muted">' + n(exigees.size) + ' exigées par au moins un niveau</p>' +
      '<ul class="chips" style="margin-top:10px">' + cats.slice(0, 6).map(function (c) { return '<li class="chip chip--lever">' + esc(c) + ' · ' + n(parCat[c]) + '</li>'; }).join('') + '</ul></section>';
    h += '<section class="card"><h2>Formations</h2><p class="metric">' + n(R.formations.length) + '</p><p class="muted">' +
      (sansFormation === null ? 'Liens en cours de lecture…' : n(sansFormation) + ' compétence' + (sansFormation > 1 ? 's' : '') + ' exigée' + (sansFormation > 1 ? 's' : '') + ' sans formation') + '</p></section>';
    h += '<section class="card"><h2>Procédures opératoires</h2><p class="metric">' + n(R.procedures.length) + '</p><p class="muted">' +
      (sansProcedure === null ? 'Liens en cours de lecture…' : n(sansProcedure) + ' compétence' + (sansProcedure > 1 ? 's' : '') + ' exigée' + (sansProcedure > 1 ? 's' : '') + ' sans procédure') + '</p></section>';
    h += '<section class="card"><h2>Base de connaissances</h2><p class="metric">' + n(R.ressources.length) + '</p><p class="muted">ressources · ' + pl(R.competencesProduit.length, 'compétence produit', 'compétences produit') + '</p></section>';
    h += '</div>';

    // Couverture par poste.
    h += '<section class="card table-card"><h2>Couverture par poste</h2>';
    if (!R.postes.length) {
      h += '<p class="muted">Le panel ne rend aucun poste (/positions).</p>';
    } else {
      h += '<div class="table-scroll"><table><thead><tr><th>Poste</th><th class="num">Niveaux</th><th class="num">Compétences exigées</th><th class="num">Avec formation</th><th class="num">Avec procédure</th><th class="num">Sans aucun support</th></tr></thead><tbody>';
      R.postes.forEach(function (p) {
        var set = new Set();
        p.niveaux.forEach(function (nv) { nv.competences.forEach(function (c) { set.add(c); }); });
        var f = null, pr = null, rien = null;
        if (I.liens) {
          f = 0; pr = 0; rien = 0;
          set.forEach(function (cid) { var l = liensDe(cid); if (l.formations.length) f++; if (l.procedures.length) pr++; if (!l.formations.length && !l.procedures.length && !l.ressources.length) rien++; });
        }
        var cell = function (v) { return v === null ? '<span class="muted">…</span>' : n(v) + ' <span class="muted fm-small">(' + pct(v, set.size) + ' %)</span>'; };
        h += '<tr class="row-link" data-href="#/postes/' + (p.id != null ? p.id : 'sans') + '"><td>' + esc(p.nom) + (p.description ? '<p class="muted">' + esc(court(p.description, 120)) + '</p>' : '') + '</td>' +
          '<td class="num">' + n(p.niveaux.length) + '</td><td class="num">' + n(set.size) + '</td><td class="num">' + cell(f) + '</td><td class="num">' + cell(pr) + '</td>' +
          '<td class="num">' + (rien === null ? '<span class="muted">…</span>' : rien ? '<span class="chip chip--ko">' + n(rien) + '</span>' : '<span class="chip chip--ok">0</span>') + '</td></tr>';
      });
      h += '</tbody></table></div>';
    }
    h += '</section>';

    h += carteAppels();
    return { titre: 'Tableau de bord', corps: h };
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
    var poste = (pid != null && I.posteById.get(pid)) || (S.route.a[0] === undefined ? posteDefaut() : R.postes.filter(function (p) { return p.id == null; })[0]) || posteDefaut();
    var niveau = nid != null ? I.niveauById.get(nid) : poste.niveaux[0];

    var arbre = '<section class="card fm-tree"><div class="sidebar__group-label" style="padding-top:4px">Postes</div>' + R.postes.map(function (p) {
      var on = p === poste;
      var nComp = new Set(); p.niveaux.forEach(function (nv) { nv.competences.forEach(function (c) { nComp.add(c); }); });
      var x = '<a class="fm-tree__poste' + (on ? ' is-active' : '') + '" href="#/postes/' + (p.id != null ? p.id : 'sans') + '"><span>' + esc(p.nom) + '</span><span class="fm-tree__n">' + n(p.niveaux.length) + ' niv.</span></a>';
      if (on) {
        x += '<ul class="fm-tree__niveaux">' + p.niveaux.map(function (nv, i) {
          return '<li><a class="fm-tree__niveau' + (niveau === nv ? ' is-active' : '') + '" href="#/postes/' + (p.id != null ? p.id : 'sans') + '/' + nv.id + '"><span>' + (i + 1) + ' · ' + esc(nv.nom) + '</span><span class="fm-tree__n">' + n(nv.competences.length) + '</span></a></li>';
        }).join('') + (p.niveaux.length ? '' : '<li class="muted fm-small" style="padding:6px 10px">Aucun niveau</li>') + '</ul>';
      }
      return x;
    }).join('') + '</section>';

    var droite;
    if (!niveau) {
      droite = '<section class="card pending"><span class="chip pending__chip">Sans niveau</span><h2>' + esc(poste.nom) + '</h2><p class="muted">Ce poste n’a aucun niveau dans le panel (<code>/positions/' + esc(poste.id) + '/levels</code>).</p></section>';
    } else {
      droite = ficheNiveau(poste, niveau);
    }
    return { titre: TITRES.postes, corps: bandeauLiens() + '<div class="fm-split">' + arbre + '<div>' + droite + '</div></div>' };
  }

  function ficheNiveau(poste, niveau) {
    var comps = niveau.competences.map(function (cid) { return I.comp.get(cid); }).filter(Boolean);
    var formations = new Set(), procedures = new Set(), ressources = new Set();
    if (I.liens) comps.forEach(function (c) { var l = liensDe(c.id); l.formations.forEach(function (f) { formations.add(f.id); }); l.procedures.forEach(function (p) { procedures.add(p.id); }); l.ressources.forEach(function (r) { ressources.add(r.id); }); });
    var rang = poste.niveaux.indexOf(niveau) + 1;
    var h = '<div class="fm-head"><div><h2 class="screen-subtitle">' + esc(poste.nom) + ' › ' + esc(niveau.nom) + '</h2>' +
      '<p class="muted">Niveau ' + rang + ' sur ' + poste.niveaux.length + (niveau.description ? ' · ' + esc(niveau.description) : '') + '</p></div>' +
      '<div class="fm-links">' +
      '<a class="filter" href="#/manuel/poste/' + (poste.id != null ? poste.id : 'sans') + '/' + niveau.id + '">Ouvrir dans le manuel</a>' +
      '<a class="filter" target="_blank" rel="noopener" href="' + API + '/formation/pdf?type=niveau&id=' + niveau.id + '">Fiche PDF du niveau ↗</a>' +
      (poste.id != null ? '<a class="filter" target="_blank" rel="noopener" href="' + API + '/formation/pdf?type=poste&id=' + poste.id + '">Fiche PDF du poste ↗</a>' : '') +
      '</div></div>';
    h += '<div class="grid grid--tight">' +
      '<section class="card"><h2>Compétences</h2><p class="metric">' + n(comps.length) + '</p><p class="muted">exigées à ce niveau</p></section>' +
      '<section class="card"><h2>Formations</h2><p class="metric">' + (I.liens ? n(formations.size) : '…') + '</p><p class="muted">qui les enseignent</p></section>' +
      '<section class="card"><h2>Procédures</h2><p class="metric">' + (I.liens ? n(procedures.size) : '…') + '</p><p class="muted">du manuel opératoire</p></section>' +
      '<section class="card"><h2>Ressources</h2><p class="metric">' + (I.liens ? n(ressources.size) : '…') + '</p><p class="muted">base de connaissances</p></section></div>';

    if (niveau.competencesLues === false) {
      h += bandeau('Compétences du niveau illisibles : ' + esc(niveau.competencesErreur || 'erreur du panel') + '.', 'error');
    }
    h += '<section class="card table-card"><h2>Compétences exigées</h2>' + tableCompetences(comps, { niveaux: false }) + '</section>';

    // Exigences et tâches : lues à la demande.
    var d = S.niveaux[niveau.id];
    h += '<div class="fm-cols" style="margin-top:1rem">';
    var bloc = function (titre, cle, vide) {
      var x = '<section class="card"><h2>' + titre + '</h2>';
      if (!d || d.charge) x += attente('Lecture…');
      else if (d.erreur) x += '<p class="error">' + esc(d.erreur) + '</p>';
      else {
        var appel = (d.data.appels || []).filter(function (a) { return a.cle === cle; })[0];
        var lignes = d.data[cle] || [];
        if (appel && !(appel.code >= 200 && appel.code < 300)) x += '<p class="muted">Route indisponible : ' + chemin(appel.chemin) + ' → HTTP ' + esc(appel.code) + '</p>';
        else if (!lignes.length) x += '<p class="muted">' + vide + '</p>';
        else x += '<ul>' + lignes.map(function (r) { return '<li>' + esc(r.nom) + (r.description && r.description !== r.nom ? ' <span class="muted">— ' + esc(court(r.description, 140)) + '</span>' : '') + '</li>'; }).join('') + '</ul>';
      }
      return x + '</section>';
    };
    h += bloc('Exigences du niveau', 'exigences', 'Aucune exigence posée en plus des compétences.');
    h += bloc('Tâches du niveau', 'taches', 'Aucune tâche attachée à ce niveau.');
    h += '</div>';
    return h;
  }

  /** Le tableau des compétences, rangé par catégorie. */
  function tableCompetences(comps, o) {
    if (!comps.length) return '<p class="muted">Aucune compétence.</p>';
    var tri = comps.slice().sort(function (a, b) {
      return (a.categorie || '~').localeCompare(b.categorie || '~', 'fr') || (a.sousCategorie || '').localeCompare(b.sousCategorie || '', 'fr') || a.nom.localeCompare(b.nom, 'fr');
    });
    var col = o.niveaux ? 7 : 6;
    var h = '<div class="table-scroll"><table><thead><tr><th>Compétence</th><th>Sous-catégorie</th>' + (o.niveaux ? '<th>Exigée par</th>' : '<th>Vérification</th>') +
      '<th>Formations</th><th>Procédures</th><th class="num">Ressources</th>' + (o.niveaux ? '<th class="num">Niveaux</th>' : '') + '</tr></thead><tbody>';
    var cat = null;
    tri.forEach(function (c) {
      var k = c.categorie || 'Sans catégorie';
      if (k !== cat) { cat = k; h += '<tr class="fm-group"><td colspan="' + col + '">' + esc(k) + '</td></tr>'; }
      var l = liensDe(c.id);
      var nivs = I.niveauxParComp.get(c.id) || [];
      h += '<tr class="row-link" data-href="#/competences/' + c.id + '"><td>' + esc(c.nom) + (c.description ? '<p class="muted">' + esc(court(c.description, 150)) + '</p>' : '') + '</td>' +
        '<td>' + esc(c.sousCategorie || '—') + '</td>' +
        (o.niveaux ? '<td>' + chipsListe(nivs.map(function (id) { return { id: id, nom: niveauLibelle(id) }; }), 2, 'chip--accent') + '</td>'
          : '<td>' + (c.verification ? esc(court(c.verification, 90)) : '<span class="muted">—</span>') + '</td>') +
        '<td>' + (l ? chipsListe(l.formations, 2) : '<span class="muted">…</span>') + '</td>' +
        '<td>' + (l ? chipsListe(l.procedures, 2) : '<span class="muted">…</span>') + '</td>' +
        '<td class="num">' + compte(l ? l.ressources : null) + '</td>' +
        (o.niveaux ? '<td class="num">' + (nivs.length ? n(nivs.length) : '<span class="muted">0</span>') + '</td>' : '') + '</tr>';
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
    var h = bandeauLiens() + '<div class="filters"><div class="filters__row">' +
      '<input class="fm-search" type="search" placeholder="Rechercher une compétence…" data-input="q" value="' + esc(f.q) + '" />' +
      '<select class="fm-select" data-input="categorie"><option value="">Toutes les catégories</option>' +
      I.categories.map(function (c) { return '<option' + (f.categorie === c ? ' selected' : '') + '>' + esc(c) + '</option>'; }).join('') + '</select></div>' +
      '<div class="filters__row">' + vues.map(function (v) {
        var off = (v[0] === 'sansFormation' || v[0] === 'sansProcedure') && !I.liens;
        return '<button type="button" class="filter' + (f.vue === v[0] ? ' is-on' : '') + '" data-act="filtre" data-k="vue" data-v="' + v[0] + '"' + (off ? ' disabled title="Les liens des compétences sont en cours de lecture"' : '') + '>' + esc(v[1]) + '</button>';
      }).join('') + '<span class="muted fm-small">' + pl(liste.length, 'compétence') + ' sur ' + n(R.competences.length) + '</span></div></div>';
    h += '<section class="card table-card" style="margin-top:0">' + tableCompetences(liste, { niveaux: true }) + '</section>';
    return { titre: TITRES.competences, corps: h };
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
      '<div class="fm-links"><a class="filter" href="#/manuel/competence/' + cid + '">Ouvrir dans le manuel</a></div></div>';
    h += '<div class="fm-cols" style="margin-top:14px">';
    h += '<section class="card"><h2>Description</h2>' + (c.description ? '<div class="fm-rich">' + riche(c.description) + '</div>' : '<p class="muted">Pas de description dans le panel.</p>') + '</section>';
    h += '<section class="card"><h2>Méthode de vérification</h2>' + (c.verification ? '<div class="fm-rich">' + riche(c.verification) + '</div>' : '<p class="muted">Non renseignée.</p>') + '</section>';
    h += '<section class="card"><h2>Exigée par</h2>' + (nivs.length ? '<ul>' + nivs.map(function (id) {
      var p = I.niveauPoste.get(id);
      return '<li><a href="#/postes/' + (p && p.id != null ? p.id : 'sans') + '/' + id + '">' + esc(niveauLibelle(id)) + '</a></li>';
    }).join('') + '</ul>' : '<p class="muted">Aucun niveau de poste n’exige cette compétence.</p>') + '</section>';
    h += '</div>';
    if (!l) {
      h += '<section class="card table-card">' + attente('Lecture des liens de la compétence…') + '</section>';
    } else {
      h += '<div class="fm-cols" style="margin-top:1rem">';
      h += '<section class="card"><h2>Formations · ' + n(l.formations.length) + '</h2>' + (l.formations.length ? '<ul>' + l.formations.map(function (x) { return '<li><a href="#/formations/' + x.id + '">' + esc(x.nom) + '</a></li>'; }).join('') + '</ul>' : '<p class="muted">Aucune formation attachée.</p>') + '</section>';
      h += '<section class="card"><h2>Procédures · ' + n(l.procedures.length) + '</h2>' + (l.procedures.length ? '<ul>' + l.procedures.map(function (x) { return '<li><a href="#/procedures/' + x.id + '">' + esc(x.nom) + '</a></li>'; }).join('') + '</ul>' : '<p class="muted">Aucune procédure attachée.</p>') + '</section>';
      h += '<section class="card"><h2>Ressources · ' + n(l.ressources.length) + '</h2>' + (l.ressources.length ? '<ul>' + l.ressources.map(function (x) {
        var r = I.ressource.get(x.id);
        return '<li>' + (r && r.url ? '<a target="_blank" rel="noopener" href="' + esc(r.url) + '">' + esc(x.nom) + ' ↗</a>' : esc(x.nom)) + '</li>';
      }).join('') + '</ul>' : '<p class="muted">Aucune ressource attachée.</p>') + '</section>';
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

  function ecranFormations() {
    var fid = arg(0);
    if (fid != null) return ficheFormation(fid);
    var q = norm(S.f.fq);
    var toutes = Array.from(I.formation.values());
    var liste = toutes.filter(function (f) { return !q || norm(f.nom + ' ' + (f.description || '') + ' ' + (f.categorie || '')).indexOf(q) >= 0; });
    liste.sort(function (a, b) { return a.nom.localeCompare(b.nom, 'fr'); });
    var h = bandeauLiens() + '<div class="filters"><div class="filters__row"><input class="fm-search" type="search" placeholder="Rechercher une formation…" data-input="fq" value="' + esc(S.f.fq) + '" />' +
      '<span class="muted fm-small">' + pl(liste.length, 'formation') + '</span></div></div>';
    h += '<section class="card table-card" style="margin-top:0">';
    if (!liste.length) h += '<p class="muted">' + (toutes.length ? 'Aucune formation ne correspond.' : 'Le panel ne rend aucune formation (/operational-framework/trainings).') + '</p>';
    else {
      h += '<div class="table-scroll"><table><thead><tr><th>Formation</th><th>Catégorie</th><th>Compétences enseignées</th><th class="num">Niveaux concernés</th></tr></thead><tbody>';
      liste.forEach(function (f) {
        var cids = I.liens ? I.compParFormation.get(f.id) || [] : null;
        var comps = cids ? cids.map(function (id) { return I.comp.get(id) || { id: id, nom: 'Compétence ' + id }; }) : null;
        h += '<tr class="row-link" data-href="#/formations/' + f.id + '"><td>' + esc(f.nom) + (f.description ? '<p class="muted">' + esc(court(f.description, 150)) + '</p>' : '') +
          (f.horsListe ? '<p class="muted">absente de /operational-framework/trainings — connue par ses compétences</p>' : '') + '</td>' +
          '<td>' + esc([f.categorie, f.sousCategorie].filter(Boolean).join(' › ') || '—') + '</td>' +
          '<td>' + (comps ? chipsListe(comps, 3) : '<span class="muted">…</span>') + '</td>' +
          '<td class="num">' + (cids ? n(niveauxDeComps(cids).length) : '<span class="muted">…</span>') + '</td></tr>';
      });
      h += '</tbody></table></div>';
    }
    h += '</section>';
    return { titre: TITRES.formations, corps: h };
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
      var exig = new Set();
      e.postes.forEach(function (p) { var nv = p.niveauId != null ? I.niveauById.get(p.niveauId) : null; if (nv) nv.competences.forEach(function (c) { exig.add(c); }); });
      var acq = new Set(e.competences);
      var manque = Array.from(exig).filter(function (c) { return !acq.has(c); });
      var formations = new Map();
      if (I.liens) manque.forEach(function (cid) { (liensDe(cid).formations || []).forEach(function (f) { formations.set(f.id, f); }); });
      return { e: e, exig: exig, valides: Array.from(exig).filter(function (c) { return acq.has(c); }).length, manque: manque, formations: Array.from(formations.values()) };
    });
    var tot = lignes.reduce(function (a, l) { return a + l.exig.size; }, 0), ok = lignes.reduce(function (a, l) { return a + l.valides; }, 0);
    h += '<div class="grid">' +
      '<section class="card"><h2>Équipe active</h2><p class="metric">' + n(actifs.length) + '</p><p class="muted">' + n(emps.length - actifs.length) + ' inactif' + (emps.length - actifs.length > 1 ? 's' : '') + ' non montré' + (emps.length - actifs.length > 1 ? 's' : '') + '</p></section>' +
      '<section class="card"><h2>Compétences validées</h2><p class="metric">' + pct(ok, tot) + ' <span class="unit">%</span></p><p class="muted">' + n(ok) + ' sur ' + n(tot) + ' exigées par les niveaux tenus</p></section>' +
      '<section class="card"><h2>Sans poste</h2><p class="metric">' + n(lignes.filter(function (l) { return !l.e.postes.length; }).length) + '</p><p class="muted">personnes sans poste ni niveau dans le panel</p></section></div>';
    h += '<section class="card table-card"><h2>Compétences de l’équipe</h2><div class="table-scroll"><table><thead><tr><th>Personne</th><th>Poste · niveau</th><th>Compétences</th><th>À acquérir</th><th>Formations à suivre</th></tr></thead><tbody>';
    lignes.forEach(function (l) {
      var e = l.e, ouvert = E.ouvert === e.id;
      var prog = l.exig.size ? pct(l.valides, l.exig.size) : null;
      h += '<tr class="row-link" data-act="equipe-ouvrir" data-id="' + e.id + '"><td>' + esc(e.nom) + (e.role ? '<p class="muted">' + esc(e.role) + '</p>' : '') + (!e.lu ? '<p class="muted">fiche illisible</p>' : '') + '</td>' +
        '<td>' + (e.postes.length ? chipsListe(e.postes.map(function (p) { return { id: p.id, nom: (p.nom || 'Poste') + (p.niveau ? ' · ' + p.niveau : '') }; }), 2, 'chip--accent') : '<span class="muted">—</span>') + '</td>' +
        '<td style="min-width:150px">' + (prog === null ? '<span class="muted">' + n(e.competences.length) + ' validée' + (e.competences.length > 1 ? 's' : '') + '</span>' :
          '<div class="progress progress--inline"><div class="progress__fill" style="width:' + prog + '%"></div></div><p class="muted">' + n(l.valides) + ' / ' + n(l.exig.size) + ' · ' + prog + ' %</p>') + '</td>' +
        '<td>' + chipsListe(l.manque.map(function (c) { return I.comp.get(c) || { id: c, nom: '#' + c }; }), 2, 'chip--warn') + '</td>' +
        '<td>' + (I.liens ? (l.formations.length ? chipsListe(l.formations, 2) : '<span class="muted">—</span>') : '<span class="muted">…</span>') + '</td></tr>';
      if (ouvert) {
        h += '<tr class="fm-detail"><td colspan="5">' + (l.manque.length ? '<div class="section-label" style="margin-top:0">Ce qui manque à ' + esc(e.nom) + '</div>' + tableCompetences(l.manque.map(function (c) { return I.comp.get(c); }).filter(Boolean), { niveaux: false }) :
          '<p class="muted">' + (l.exig.size ? 'Toutes les compétences du niveau sont validées.' : 'Aucun niveau tenu : rien n’est exigé.') + '</p>') + '</td></tr>';
      }
    });
    h += '</tbody></table></div></section>';
    return { titre: TITRES.equipes, corps: h };
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

  /* ── Rendu ──────────────────────────────────────────────────────────── */

  var ECRANS = {
    tableau: ecranTableau, postes: ecranPostes, competences: ecranCompetences, formations: ecranFormations,
    procedures: ecranProcedures, ressources: ecranRessources, produit: ecranProduit, equipes: ecranEquipes,
    manuel: ecranManuel, api: ecranApi,
  };

  function rendre(garder) {
    document.body.classList.toggle('fm-tablette', S.tablette);
    var actif = document.activeElement, focus = null;
    if (actif && actif.getAttribute) {
      var cle = actif.getAttribute('data-input') || actif.getAttribute('data-probe');
      if (cle) focus = { attr: actif.hasAttribute('data-input') ? 'data-input' : 'data-probe', cle: cle, debut: actif.selectionStart, fin: actif.selectionEnd };
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
    if (e === 'postes') {
      var pid = arg(0), nid = arg(1);
      var poste = (pid != null && I.posteById.get(pid)) || (S.route.a[0] === 'sans' ? S.ref.postes.filter(function (p) { return p.id == null; })[0] : posteDefaut());
      var nv = nid != null ? I.niveauById.get(nid) : poste && poste.niveaux[0];
      if (nv) chargerUn(S.niveaux, nv.id, '/formation/niveau?id=' + nv.id);
    }
    if (e === 'competences' && arg(0) != null && !I.liens) chargerUn(S.competences, arg(0), '/formation/competence?id=' + arg(0));
    if (e === 'manuel' && S.route.a[0] === 'competence' && arg(1) != null && !I.liens) chargerUn(S.competences, arg(1), '/formation/competence?id=' + arg(1));
    if (e === 'formations' && arg(0) != null) chargerUn(S.formations, arg(0), '/formation/formation?id=' + arg(0));
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
    if (forcer) { S.niveaux = {}; S.procedures = {}; S.formations = {}; S.competences = {}; S.equipe.parShop = {}; }
    rendre(true);
    lire('/formation/referentiel?_cache=600' + (forcer ? '&rafraichir=1' : ''), 170000).then(function (d) {
      S.ref = d; S.refCharge = false;
      if (forcer) { S.liens = null; }
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
    var hors = S.ref.competences.filter(function (c) { return c.horsCatalogue; }).map(function (c) { return c.id; });
    lire('/formation/liens?_cache=900' + (hors.length ? '&en_plus=' + hors.join(',') : '') + (forcer ? '&rafraichir=1' : ''), 280000).then(function (d) {
      S.liens = d; S.liensCharge = false; indexer(); rendre(true);
    }, function (e) {
      S.liensCharge = false; S.liensErreur = e.message; rendre(true);
    });
  }

  /* ── Événements ─────────────────────────────────────────────────────── */

  root.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-act], [data-href]');
    if (!t || !root.contains(t)) return;
    if (ev.target.closest('a[href]') && ev.target.closest('a[href]') !== t && t.contains(ev.target.closest('a[href]'))) return; // un lien dans la ligne garde son rôle
    var act = t.getAttribute('data-act');
    if (!act) { location.hash = t.getAttribute('data-href'); return; }
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
    var p = t.getAttribute && t.getAttribute('data-probe');
    if (p) S.api.chemins[p] = t.value;
  });

  root.addEventListener('keydown', function (ev) {
    var p = ev.target.getAttribute && ev.target.getAttribute('data-probe');
    if (p && ev.key === 'Enter') {
      var b = root.querySelector('[data-act="api-sonder"][data-k="' + (window.CSS && CSS.escape ? CSS.escape(p) : p) + '"]');
      if (b) b.click();
    }
    if (ev.key === 'Escape' && S.menu) { S.menu = false; rendre(true); }
  });

  window.addEventListener('hashchange', function () {
    lireRoute();
    S.menu = false;
    rendre(false);
  });

  lireRoute();
  chargerReferentiel(false);
})();
