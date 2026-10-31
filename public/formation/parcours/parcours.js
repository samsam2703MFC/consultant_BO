/*
 * Parcours compétences — L'Atelier by.
 *
 * Pour chaque personne (employé ou candidat franchisé) : un assistant en trois
 * étapes trace le chemin vers le poste visé (où aller, ce que je sais déjà, mon
 * parcours), puis une frise horizontale le suit, formation par formation.
 * Mobile d'abord : la page rejoindra l'application employé ; pour l'instant on
 * l'ouvre depuis le rail du cockpit (Formation › Parcours compétences).
 *
 * Les données des API du panel ont priorité (postes, niveaux, compétences,
 * évaluations) ; le référentiel v25 complète (formations FO-xx, matrice, plans).
 * Tout passe par ../api/cockpit/parcours/* — voir src/parcours.php.
 */
(function () {
  'use strict';

  var API = '../api/cockpit';
  var root = document.getElementById('root');
  var SEMAINES = { tranquille: 4.3, normal: 3, rapide: 2 };
  var RYTHMES = [['tranquille', 'Tranquille', 'une formation par mois'], ['normal', 'Normal', 'une formation toutes les 3 semaines'], ['rapide', 'Rapide', 'une formation toutes les 2 semaines']];
  var MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function pl(n, un, plusieurs) { return n + ' ' + (n > 1 ? (plusieurs || un + 's') : un); }
  function dateCourte(iso) { if (!iso) return ''; var d = new Date(iso + 'T12:00:00'); return d.getDate() + ' ' + MOIS[d.getMonth()]; }
  function moisAn(d) { return MOIS[d.getMonth()].replace('.', '') + ' ' + d.getFullYear(); }

  function appel(methode, chemin, corps) {
    var h = { Accept: 'application/json' };
    if (corps) h['Content-Type'] = 'application/json';
    return fetch(API + chemin, { method: methode, headers: h, credentials: 'same-origin', body: corps ? JSON.stringify(corps) : undefined })
      .then(function (r) {
        return r.text().then(function (t) {
          var d = null;
          try { d = t ? JSON.parse(t) : null; } catch (e) { d = null; }
          if (!r.ok) throw new Error((d && (d.raison || d.error)) || 'HTTP ' + r.status);
          if (d === null) throw new Error('réponse illisible');
          return d;
        });
      }, function () { throw new Error('cockpit injoignable'); });
  }

  /* ── État ───────────────────────────────────────────────────────────── */

  var S = {
    route: [], liste: null, listeErreur: null, magasins: null, filtre: '',
    equipe: {}, nv: null, p: {}, sel: null, msg: null, occupe: false, sheet: null,
  };

  function lireRoute() {
    S.route = (location.hash || '').replace(/^#\/?/, '').split('/').filter(Boolean);
  }

  function message(genre, texte) {
    S.msg = { genre: genre, texte: texte };
    clearTimeout(message.t);
    message.t = setTimeout(function () { S.msg = null; rendre(); }, genre === 'ok' ? 5000 : 12000);
  }

  /* ── Pièces ─────────────────────────────────────────────────────────── */

  function tete(gauche, droite) {
    return '<div class="pc-head">' + (gauche || '<img src="../img/logo.png" alt="L\'Atelier By">') + (droite || '') + '</div>';
  }
  function barres(n) {
    var h = '<div class="ph-steps">';
    for (var i = 1; i <= 3; i++) h += '<span class="' + (i <= n ? 'on' : '') + '"></span>';
    return h + '</div>';
  }
  function titre(kicker, t, sous) {
    return '<p class="topbar__kicker">' + esc(kicker) + '</p><h1 class="ph-title">' + t + '</h1>' + (sous ? '<p class="muted ph-sous">' + sous + '</p>' : '');
  }
  function cta(libelle, act, off) {
    return '<div class="ph-cta"><button type="button" data-act="' + act + '"' + (off || S.occupe ? ' disabled' : '') + '>' + (S.occupe ? 'Un instant…' : libelle) + '</button></div>';
  }
  function msg() {
    return S.msg ? '<p class="pc-msg pc-msg--' + (S.msg.genre === 'ok' ? 'ok' : 'ko') + '">' + esc(S.msg.texte) + '</p>' : '';
  }
  function points(frise) {
    return '<span class="pts">' + frise.map(function (s) { return '<span class="pt ' + s + '"></span>'; }).join('') + '<span class="pt-flag">⚑</span></span>';
  }
  /** Qui valide : le consultant pour un candidat ou une cible de franchise (P9–P12), la manager sinon. */
  function quiValide(code, candidat) { return candidat || /^P(9|1[0-2])$/.test(code || '') ? 'ton consultant' : 'ta manager'; }
  function initiales(nom) {
    return String(nom || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(function (x) { return x.charAt(0).toUpperCase(); }).join('');
  }

  /* ── Accueil : les parcours ─────────────────────────────────────────── */

  function ecranAccueil() {
    var h = tete(null, '<span>Parcours compétences</span>') + titre('Formation', 'Les parcours', 'Le chemin de chacun vers le poste qu’il vise.') + msg();
    if (S.magasins && S.magasins.length) {
      h += '<select class="fm-select pc-select" data-input="filtre"><option value="">Tous les magasins</option>' + S.magasins.map(function (m) {
        return '<option value="' + m.id + '"' + (String(S.filtre) === String(m.id) ? ' selected' : '') + '>' + esc(m.nom) + '</option>';
      }).join('') + '<option value="candidats"' + (S.filtre === 'candidats' ? ' selected' : '') + '>Candidats franchisés</option></select>';
    }
    if (S.listeErreur) return h + '<p class="error">' + esc(S.listeErreur) + '</p>' + cta('+ Nouveau parcours', 'nouveau');
    if (!S.liste) return h + '<p class="muted" style="margin-top:16px">Lecture…</p>';
    var l = S.liste.filter(function (p) {
      if (!S.filtre) return true;
      if (S.filtre === 'candidats') return p.profil === 'candidat';
      return String(p.shop) === String(S.filtre);
    });
    h += '<div class="section-label">' + pl(l.length, 'parcours', 'parcours') + '</div><div style="margin-top:8px">';
    if (!l.length) h += '<p class="muted">Aucun parcours pour l’instant. Le premier se crée en trois étapes.</p>';
    l.forEach(function (p) {
      var pct = p.total ? Math.round(p.validees * 100 / p.total) : 0;
      h += '<a class="pc-row" href="#/p/' + p.id + '"><span class="lead__initials">' + esc(initiales(p.nom)) + '</span><span class="pc-row__txt"><strong>' + esc(p.nom) + '</strong>' +
        '<span class="muted">→ ' + esc(p.cibleNom) + (p.statut === 'atteint' ? ' · atteint' : p.retard ? ' · en retard' : '') + '</span>' + points(p.frise) + '</span>' +
        '<span class="pc-row__pct">' + pct + '<small>%</small></span></a>';
    });
    return h + '</div>' + cta('+ Nouveau parcours', 'nouveau');
  }

  /* ── Nouveau : pour qui ? ───────────────────────────────────────────── */

  function ecranPourQui() {
    var nv = S.nv || (S.nv = { shop: '' });
    var h = tete('<a href="#/">‹ Parcours</a>', '<span>Nouveau</span>') + titre('Nouveau parcours', 'Pour qui&nbsp;?', 'Un employé du réseau, ou un candidat franchisé.') + msg();
    h += '<div class="section-label">Un employé</div>';
    h += '<select class="fm-select pc-select" data-input="nvShop"><option value="">Choisir le magasin…</option>' + (S.magasins || []).map(function (m) {
      return '<option value="' + m.id + '"' + (String(nv.shop) === String(m.id) ? ' selected' : '') + '>' + esc(m.nom) + '</option>';
    }).join('') + '</select>';
    if (nv.shop) {
      var eq = S.equipe[nv.shop];
      if (!eq || eq.charge) h += '<p class="muted" style="margin-top:10px">Lecture de l’équipe…</p>';
      else if (eq.erreur) h += '<p class="error">' + esc(eq.erreur) + '</p>';
      else {
        h += '<div style="margin-top:10px">' + eq.data.filter(function (e) { return e.actif; }).map(function (e) {
          var po = e.postes[0];
          return '<button type="button" class="pc-row" data-act="choisir-employe" data-id="' + e.id + '" data-nom="' + esc(e.nom) + '"><span class="lead__initials">' + esc(initiales(e.nom)) + '</span>' +
            '<span><strong>' + esc(e.nom) + '</strong><span class="muted">' + esc(po ? (po.nom || '') + (po.niveau ? ' · ' + po.niveau : '') : 'sans poste dans le panel') + '</span></span></button>';
        }).join('') + '</div>';
      }
    }
    h += '<div class="section-label">Un candidat franchisé</div>' +
      '<input class="pc-input" type="text" maxlength="120" placeholder="Prénom et nom du candidat" data-input="nvNom" value="' + esc(nv.nomCandidat || '') + '" />' +
      '<div class="pc-actions"><button type="button" class="filter" data-act="choisir-candidat"' + ((nv.nomCandidat || '').trim() ? '' : ' disabled') + '>Continuer avec ce candidat</button></div>';
    return h;
  }

  /* ── L'assistant, 3 étapes ──────────────────────────────────────────── */

  function destCourante() {
    var nv = S.nv;
    if (!nv || !nv.dest) return null;
    return nv.dest.destinations.filter(function (d) { return d.cle === nv.cible; })[0] || null;
  }
  function aSuivre(d) {
    var nv = S.nv;
    return d.formations.filter(function (f) { return !f.acquise && !nv.suivies[f.fo]; });
  }
  function moisPour(n, rythme) { return Math.max(1, Math.round(n * SEMAINES[rythme] / 4.33)); }

  function etape1() {
    var nv = S.nv, d = nv.dest;
    var h = tete('<a href="#/nouveau">‹ Pour qui</a>', '<span>Étape 1 / 3</span>') + barres(1);
    if (!d) return h + titre('Mon parcours', 'Où veux-tu aller&nbsp;?', '') + '<p class="muted">Lecture des postes et de ce qu’ils demandent…</p>';
    h += titre('Mon parcours', 'Où veux-tu aller&nbsp;?', 'Choisis le poste que tu vises. On te prépare le chemin.') + msg();
    if (d.personne.niveau) h += '<div class="ph-now"><span class="chip chip--soft">Aujourd’hui</span> ' + esc(d.personne.niveau) + '</div>';
    if (d.parcoursActif) h += '<p class="pc-msg pc-msg--ko">Un parcours est déjà en cours pour cette personne : en créer un autre l’arrêtera. <a href="#/p/' + d.parcoursActif + '">Voir le parcours en cours</a></p>';
    // Les trois familles de postes, comme dans le module (Opérationnel · Cadre et direction · Devenir entrepreneur).
    var groupes = [['metier', 'Opérationnel'], ['encadrer', 'Cadre et direction'], ['entrepreneur', 'Devenir entrepreneur']];
    groupes.forEach(function (g) {
      var l = d.destinations.filter(function (x) { return x.groupe === g[0]; });
      if (!l.length) return;
      h += '<div class="section-label">' + g[1] + '</div><ul class="type-grid ph-grid">' + l.map(function (x) {
        var detail = (x.sous ? esc(x.sous) + ' · ' : '') + (x.competences.length ? pl(x.competences.length, 'compétence') + ' · ' : '') +
          pl(x.aFaire, 'formation') + ' · environ ' + moisPour(x.aFaire, 'normal') + ' mois' +
          (x.admission.length ? ' · admission ' + x.admis + '/' + x.admission.length : '');
        return '<li><button type="button" class="choice-card' + (nv.cible === x.cle ? ' is-on' : '') + '" data-act="cible" data-cle="' + esc(x.cle) + '"><strong>' + esc(x.titre) + '</strong><span class="muted">' + detail + '</span></button></li>';
      }).join('') + '</ul>';
    });
    if (!d.destinations.length) h += '<p class="muted">Aucune destination : le poste tenu n’a pas d’étape au-dessus dans le panel.</p>';
    return h + cta('Continuer', 'vers-2', !nv.cible);
  }

  function etape2() {
    var nv = S.nv, d = destCourante();
    if (!d) { location.hash = '#/nouveau/1'; return ''; }
    var h = tete('<a href="#/nouveau/1">‹ Étape 1</a>', '<span>Étape 2 / 3</span>') + barres(2) +
      titre('Mon parcours', 'Ce que tu sais déjà', esc(d.titre) + (d.sous ? ' · ' + esc(d.sous) : '')) + msg();
    var acquises = d.formations.filter(function (f) { return f.acquise; }).length;
    if (acquises) h += '<div class="ph-ok">✓ <strong>' + pl(acquises, 'formation') + ' déjà ' + (acquises > 1 ? 'validées' : 'validée') + '</strong> <span class="muted">— elles comptent déjà</span></div>';
    if (d.competences.length) {
      h += '<div class="section-label">Ce que le niveau demande</div><p class="muted ph-note">Tu sais déjà le faire&nbsp;? Coche : ta manager le confirmera.</p><ul class="ph-list">' +
        d.competences.map(function (c) {
          if (c.acquise) return '<li class="pc-row is-lock"><span class="ph-check is-on">✓</span><span><strong>' + esc(c.nom) + '</strong><span class="muted">déjà validée dans le panel</span></span></li>';
          var on = !!nv.acquis[c.id];
          return '<li class="pc-row" data-act="acquis" data-id="' + c.id + '"><span class="ph-check' + (on ? ' is-on' : '') + '">' + (on ? '✓' : '') + '</span><span><strong>' + esc(c.nom) + '</strong><span class="muted">' + (on ? 'je sais le faire — à confirmer' : 'à apprendre') + '</span></span></li>';
        }).join('') + '</ul>';
    }
    var reste = d.formations.filter(function (f) { return !f.acquise; });
    if (reste.length) {
      h += '<div class="section-label">Les formations du chemin</div><p class="muted ph-note">Tu en as déjà suivi une&nbsp;? Coche-la : elle passe dans le déjà fait.</p><ul class="ph-list">' +
        reste.map(function (f) {
          var on = !!nv.suivies[f.fo];
          return '<li class="pc-row" data-act="suivie" data-fo="' + esc(f.fo) + '"><span class="ph-check' + (on ? ' is-on' : '') + '">' + (on ? '✓' : '') + '</span><span><strong>' + esc(f.nom) + '</strong><span class="muted">' + esc(f.fo) + ' · ' + pl(f.evaluations, 'évaluation') + (on ? ' · déjà suivie' : '') + '</span></span></li>';
        }).join('') + '</ul>';
    }
    return h + cta('Continuer', 'vers-3');
  }

  function etape3() {
    var nv = S.nv, d = destCourante();
    if (!d) { location.hash = '#/nouveau/1'; return ''; }
    var n = aSuivre(d).length, fin = new Date();
    fin.setDate(fin.getDate() + Math.round(n * SEMAINES[nv.rythme] * 7));
    // La mini-frise tient dans la carte : huit points au plus, le reste en nombre.
    var pts = '';
    for (var i = 0; i < Math.min(n, 8); i++) pts += '<span class="mini__pt' + (i === 0 ? ' is-on' : '') + '"></span>';
    if (n > 8) pts += '<span class="mini__plus">+' + (n - 8) + '</span>';
    var h = tete('<a href="#/nouveau/2">‹ Étape 2</a>', '<span>Étape 3 / 3</span>') + barres(3) +
      titre('Mon parcours', n ? 'Ton parcours est prêt' : 'Tout est déjà là&nbsp;!', 'Vers <strong>' + esc(d.titre) + '</strong>' + (d.sous ? ' · ' + esc(d.sous) : '') + '.') + msg();
    h += '<div class="card ph-resume"><div class="ph-big">' + n + '<span> formation' + (n > 1 ? 's' : '') + ' à suivre</span></div>' +
      '<p class="muted">' + (n ? 'objectif <strong>' + moisAn(fin) + '</strong>' : 'il ne reste qu’à valider le niveau') + (d.facultatives ? ' · ' + d.facultatives + ' pour aller plus loin' : '') + '</p>' +
      (n ? '<div class="mini">' + pts + '<span class="mini__flag">⚑</span></div>' : '') + '</div>';
    h += '<div class="section-label">Ton rythme</div><div class="filters__row ph-rythme">' + RYTHMES.map(function (r) {
      return '<button type="button" class="filter' + (nv.rythme === r[0] ? ' is-on' : '') + '" data-act="rythme" data-r="' + r[0] + '">' + r[1] + '</button>';
    }).join('') + '</div><p class="muted ph-note">' + esc(RYTHMES.filter(function (r) { return r[0] === nv.rythme; })[0][2]) + '.</p>';
    if (d.admission.length) {
      h += '<div class="section-label">Pour être admis</div><ul class="ph-list">' + d.admission.map(function (c) { return ligneCritere(c, quiValide(d.v25, !nv.employe), false); }).join('') + '</ul>';
    }
    var coach = quiValide(d.v25, !nv.employe) === 'ton consultant';
    h += '<div class="section-label">Qui t’accompagne</div><div class="ph-coach"><span class="lead__initials">' + (coach ? 'CO' : 'MG') + '</span><span><strong>' +
      (coach ? 'Ton consultant' : 'Ta manager') + '</strong><span class="muted">valide chaque étape avec toi</span></span></div>';
    return h + cta('C’est parti', 'creer');
  }

  /* ── Les critères d'admission ───────────────────────────────────────── */

  /**
   * Une ligne de critère. Vérifié (panel, parcours) : coché, sans action.
   * Formations qui manquent : elles sont dans le chemin. Le reste : la manager
   * ou le consultant le valide dans le suivi (`action` : le bouton).
   */
  function ligneCritere(c, qui, action) {
    var etat;
    if (c.ok) {
      etat = c.preuve === 'panel' ? 'vérifié dans le panel' : c.preuve === 'parcours' ? 'validé dans ce parcours' : 'validé' + (c.le ? ' le ' + dateCourte(c.le) : '') + ' par ' + (qui === 'ta manager' ? 'la manager' : 'le consultant');
    } else if (c.type === 'fo' && c.manque.length) {
      etat = 'dans ton parcours : ' + c.manque.map(function (m) { return court(m.nom); }).join(', ');
    } else {
      etat = 'à valider par ' + qui;
    }
    var bouton = '';
    if (action && c.type !== 'fo' && (!c.ok || c.preuve === 'consultant')) {
      bouton = '<button type="button" class="filter' + (c.ok ? '' : ' is-on') + '" data-act="adm" data-cle="' + esc(c.cle) + '" data-fait="' + (c.ok ? '0' : '1') + '"' + (S.occupe ? ' disabled' : '') + '>' + (c.ok ? 'Retirer' : 'Valider') + '</button>';
    }
    return '<li class="pc-row is-lock"><span class="ph-check' + (c.ok ? ' is-on' : '') + '">' + (c.ok ? '✓' : '') + '</span><span class="pc-row__txt"><strong>' + esc(c.libelle) + '</strong>' +
      '<span class="muted">' + esc(etat) + (c.source === 'panel' ? ' · exigence du panel' : '') + '</span></span>' + bouton + '</li>';
  }

  /* ── La frise de suivi ──────────────────────────────────────────────── */

  function ecranParcours(id) {
    var x = S.p[id];
    var h = tete('<a href="#/">‹ Parcours</a>', '<span>Suivi</span>');
    if (!x || x.charge) return h + '<p class="muted">Lecture du parcours…</p>';
    if (x.erreur) return h + '<p class="error">' + esc(x.erreur) + '</p>';
    var p = x.data.parcours, et = x.data.etapes;
    var pct = p.total ? Math.round(p.validees * 100 / p.total) : 0;
    var encours = et.filter(function (e) { return e.statut === 'encours'; })[0];
    var sel = et.filter(function (e) { return e.fo === S.sel; })[0] || encours || et[et.length - 1];
    h += titre(p.profil === 'candidat' ? 'Candidat franchisé' : 'Mon parcours', esc(p.nom), p.depart ? esc(p.depart) : '') + msg();
    h += '<div class="ph-goal"><div><span class="muted">Objectif</span><strong>' + esc(p.cibleNom) + '</strong></div><span class="ph-pct">' + pct + '<small>%</small></span></div>' +
      '<div class="progress"><div class="progress__fill" style="width:' + pct + '%"></div></div>' +
      '<p class="muted ph-note">' + p.validees + ' formation' + (p.validees > 1 ? 's' : '') + ' validée' + (p.validees > 1 ? 's' : '') + ' sur ' + p.total +
      (p.statut === 'atteint' ? ' · objectif atteint' : p.statut === 'arrete' ? ' · parcours arrêté' : p.retard ? ' · en retard' : ' · dans les temps') + '</p>';
    // La frise : le passé à gauche, l'étape en cours au milieu, l'objectif au bout.
    var noeuds = et.map(function (e) {
      var k = e.statut === 'validee' ? 'fait' + (e.origine !== 'parcours' ? ' ' + e.origine : '') : e.statut === 'encours' ? 'encours' : 'avenir';
      var quand = e.statut === 'validee' ? (e.valideeLe ? dateCourte(e.valideeLe.slice(0, 10)) : 'fait') : e.statut === 'encours' ? 'maintenant' : dateCourte(e.prevue);
      return '<div class="jalon ' + k + (sel && sel.fo === e.fo ? ' is-sel' : '') + '" data-act="sel" data-fo="' + esc(e.fo) + '"><span class="jalon__dot">' + (e.statut === 'validee' ? '✓' : '') + '</span>' +
        '<span class="jalon__date">' + esc(quand) + '</span><span class="jalon__nom">' + esc(court(e.nom)) + '</span></div>';
    }).join('');
    var fin = et.length ? et[et.length - 1].prevue : null;
    noeuds += '<div class="jalon cible"><span class="jalon__dot">⚑</span><span class="jalon__date">' + esc(fin ? dateCourte(fin) : '') + '</span><span class="jalon__nom">' + esc(p.cibleNom.split(' · ')[0]) + '</span></div>';
    h += '<div class="frise" id="frise"><div class="frise__track">' + noeuds + '</div></div>';
    // L'étape choisie (par défaut celle en cours) et ses évaluations.
    if (sel) {
      var lock = sel.origine !== 'parcours' || p.statut !== 'actif';
      h += '<div class="card ph-now-card"><p class="topbar__kicker">' + (sel.statut === 'validee' ? 'Validée' : sel.statut === 'encours' ? 'En cours' : 'À venir · ' + esc(dateCourte(sel.prevue))) + '</p>' +
        '<strong class="ph-now-title">' + esc(sel.nom) + '</strong><p class="muted">' + esc(sel.fo) + ' · ' + sel.faites + ' sur ' + sel.evaluations.length + ' évaluations' +
        (sel.origine === 'panel' ? ' · acquise dans le panel' : sel.origine === 'declaree' ? ' · déjà suivie (déclarée)' : '') + '</p>';
      if (sel.origine === 'parcours') {
        h += '<ul class="ph-evals">' + sel.evaluations.map(function (v, i) {
          return '<li class="' + (v.fait ? 'is-done' : '') + (lock ? ' is-off' : '') + '"' + (lock ? '' : ' data-act="eval" data-fo="' + esc(sel.fo) + '" data-i="' + i + '"') + '><span class="ph-check' + (v.fait ? ' is-on' : '') + '">' + (v.fait ? '✓' : '') + '</span>' + esc(v.nom) + '</li>';
        }).join('') + '</ul>' + (lock ? '' : '<p class="muted ph-note">La dernière évaluation cochée valide la formation et ses compétences dans le panel.</p>');
      }
      h += '</div>';
    }
    // Les compétences cochées à l'étape 2, à confirmer par la manager.
    if (p.acquisDeclares && p.acquisDeclares.length && p.statut === 'actif') {
      h += '<div class="section-label">À confirmer</div><ul class="ph-list">' + p.acquisDeclares.map(function (a) {
        return '<li class="pc-row is-lock"><span class="ph-check">?</span><span class="pc-row__txt"><strong>' + esc(a.nom) + '</strong><span class="muted">déclarée à la création</span></span>' +
          '<button type="button" class="filter" data-act="confirmer" data-id="' + a.id + '"' + (S.occupe ? ' disabled' : '') + '>Confirmer</button></li>';
      }).join('') + '</ul>';
    }
    // Les critères d'admission : la manager (ou le consultant) valide ce qui ne se vérifie pas.
    var adm = x.data.admission || [];
    if (adm.length) {
      h += '<div class="section-label">Critères d’admission · ' + p.admis + '/' + p.criteres + '</div><ul class="ph-list">' +
        adm.map(function (c) { return ligneCritere(c, quiValide(p.cibleV25, p.profil === 'candidat'), p.statut === 'actif'); }).join('') + '</ul>';
    }
    // Ensuite : les deux prochaines.
    var suite = et.filter(function (e) { return e.statut === 'avenir'; }).slice(0, 3);
    if (suite.length) h += '<div class="section-label">Ensuite</div><ul class="pc-next">' + suite.map(function (e) { return '<li><span>' + esc(e.nom) + '</span><span class="muted">' + esc(dateCourte(e.prevue)) + '</span></li>'; }).join('') + '</ul>';
    var admisOk = p.admis === p.criteres;
    if (p.statut === 'actif' && p.validees === p.total && p.total) {
      h += '<div class="pc-done"><strong>Toutes les formations sont validées.</strong>' + (admisOk ? 'Il reste à valider le niveau : le panel l’enregistre pour la personne.' :
        'Il reste ' + pl(p.criteres - p.admis, 'critère') + ' d’admission à remplir avant de valider le niveau.') + '</div>';
    }
    if (p.statut === 'actif') {
      h += '<div class="section-label">Rythme</div><div class="filters__row ph-rythme">' + RYTHMES.map(function (r) {
        return '<button type="button" class="filter' + (p.rythme === r[0] ? ' is-on' : '') + '" data-act="p-rythme" data-r="' + r[0] + '"' + (S.occupe ? ' disabled' : '') + '>' + r[1] + '</button>';
      }).join('') + '</div><div class="pc-actions" style="margin-top:22px"><button type="button" class="filter" data-act="arreter">Arrêter ce parcours</button></div>';
    }
    if (p.statut === 'actif' && p.validees === p.total && p.total && admisOk) h += cta('Objectif atteint — valider le niveau', 'atteint');
    return h;
  }
  function court(nom) { return String(nom || '').replace(/^Formation\s+/i, '').replace(/\s*\(.*?\)\s*$/, ''); }

  /* ── Fenêtre de confirmation (feuille du bas) ───────────────────────── */

  function feuille() {
    var f = S.sheet;
    if (!f) return '';
    return '<div class="pc-sheet" data-act="sheet-fond"><div class="pc-sheet__box"><h3>' + esc(f.titre) + '</h3><p class="muted" style="line-height:1.5">' + f.texte + '</p>' +
      '<div class="pc-actions"><button type="button" class="filter" data-act="sheet-non">Annuler</button><button type="button" class="filter ' + (f.danger ? 'confirm__danger' : 'is-on') + '" data-act="sheet-oui"' + (S.occupe ? ' disabled' : '') + '>' + esc(f.libelle) + '</button></div></div></div>';
  }

  /* ── Rendu, lectures ────────────────────────────────────────────────── */

  function rendre() {
    var r = S.route, h;
    if (r[0] === 'nouveau' && r[1] === '1') h = etape1();
    else if (r[0] === 'nouveau' && r[1] === '2') h = etape2();
    else if (r[0] === 'nouveau' && r[1] === '3') h = etape3();
    else if (r[0] === 'nouveau') h = ecranPourQui();
    else if (r[0] === 'p' && /^\d+$/.test(r[1] || '')) h = ecranParcours(+r[1]);
    else h = ecranAccueil();
    var actif = document.activeElement, cle = actif && actif.getAttribute ? actif.getAttribute('data-input') : null, pos = cle ? actif.selectionStart : null;
    root.innerHTML = h + feuille();
    if (cle) { var el = root.querySelector('[data-input="' + cle + '"]'); if (el) { el.focus(); try { el.setSelectionRange(pos, pos); } catch (e) { /* select */ } } }
    var fr = document.getElementById('frise');
    if (fr && !fr.dataset.place) {
      var on = fr.querySelector('.jalon.is-sel') || fr.querySelector('.jalon.encours');
      if (on) fr.scrollLeft = Math.max(0, on.offsetLeft - 120);
      fr.dataset.place = '1';
    }
    apres();
  }

  function apres() {
    var r = S.route;
    if (!S.magasins && !S.magasinsCharge) {
      S.magasinsCharge = true;
      appel('GET', '/formation/equipe').then(function (d) { S.magasins = d.magasins || []; rendre(); }, function () { S.magasins = []; rendre(); });
    }
    if (!r[0] && !S.liste && !S.listeCharge) chargerListe();
    if (r[0] === 'nouveau' && S.nv && S.nv.shop && !S.equipe[S.nv.shop]) {
      var sid = S.nv.shop;
      S.equipe[sid] = { charge: true };
      appel('GET', '/formation/equipe?shop=' + sid + '&_cache=300').then(function (d) { S.equipe[sid] = { data: d.employes || [] }; rendre(); },
        function (e) { S.equipe[sid] = { erreur: e.message }; rendre(); });
    }
    if (r[0] === 'nouveau' && r[1] && (!S.nv || !S.nv.pret)) { location.hash = '#/nouveau'; }
    if (r[0] === 'p' && /^\d+$/.test(r[1] || '') && !S.p[+r[1]]) chargerParcours(+r[1]);
  }

  function chargerListe() {
    S.listeCharge = true;
    appel('GET', '/parcours/liste').then(function (d) { S.liste = d.parcours || []; S.listeCharge = false; rendre(); },
      function (e) { S.listeErreur = e.message; S.listeCharge = false; rendre(); });
  }
  function chargerParcours(id) {
    S.p[id] = { charge: true };
    appel('GET', '/parcours?id=' + id).then(function (d) { S.p[id] = { data: d }; rendre(); }, function (e) { S.p[id] = { erreur: e.message }; rendre(); });
  }

  /** Commencer l'assistant pour une personne : on lit ses destinations. */
  function demarrer(nv) {
    nv.cible = null; nv.acquis = {}; nv.suivies = {}; nv.rythme = 'normal'; nv.dest = null; nv.pret = true;
    S.nv = nv;
    location.hash = '#/nouveau/1';
    appel('GET', '/parcours/destinations' + (nv.employe ? '?employe=' + nv.employe + '&shop=' + nv.shop : '')).then(function (d) {
      S.nv.dest = d;
      if (!d.destinations.length) message('ko', 'Aucune destination trouvée pour cette personne.');
      rendre();
    }, function (e) { message('ko', e.message); location.hash = '#/nouveau'; rendre(); });
  }

  /** Une écriture sur un parcours ; la réponse est le parcours relu. */
  function ecrireParcours(id, methode, chemin, corps, texteOk) {
    if (S.occupe) return;
    S.occupe = true; rendre();
    appel(methode, chemin, corps).then(function (d) {
      S.occupe = false; S.sheet = null;
      if (d.parcours) S.p[id] = { data: d };
      S.liste = null; S.listeCharge = false;
      var t = typeof texteOk === 'function' ? texteOk(d) : texteOk;
      if (t) message('ok', t);
      rendre();
    }, function (e) { S.occupe = false; S.sheet = null; message('ko', e.message); rendre(); });
  }

  /* ── Événements ─────────────────────────────────────────────────────── */

  root.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-act]');
    if (!t || t.disabled) return;
    var act = t.getAttribute('data-act'), nv = S.nv, id = +(S.route[1] || 0);
    if (act === 'nouveau') { S.nv = { shop: S.filtre && S.filtre !== 'candidats' ? S.filtre : '' }; location.hash = '#/nouveau'; return; }
    if (act === 'choisir-employe') { demarrer({ employe: +t.getAttribute('data-id'), shop: +nv.shop, nom: t.getAttribute('data-nom') }); return; }
    if (act === 'choisir-candidat') { demarrer({ employe: null, shop: null, nom: (nv.nomCandidat || '').trim(), nomCandidat: nv.nomCandidat }); return; }
    if (act === 'cible') { nv.cible = t.getAttribute('data-cle'); nv.acquis = {}; nv.suivies = {}; rendre(); return; }
    if (act === 'vers-2') { location.hash = '#/nouveau/2'; return; }
    if (act === 'vers-3') { location.hash = '#/nouveau/3'; return; }
    if (act === 'acquis') { var c = t.getAttribute('data-id'); if (nv.acquis[c]) delete nv.acquis[c]; else nv.acquis[c] = true; rendre(); return; }
    if (act === 'suivie') { var f = t.getAttribute('data-fo'); if (nv.suivies[f]) delete nv.suivies[f]; else nv.suivies[f] = true; rendre(); return; }
    if (act === 'rythme') { nv.rythme = t.getAttribute('data-r'); rendre(); return; }
    if (act === 'creer') {
      if (S.occupe) return;
      S.occupe = true; rendre();
      appel('POST', '/parcours', { employe: nv.employe, shop: nv.shop, nom: nv.nom, cible: nv.cible, rythme: nv.rythme,
        acquis: Object.keys(nv.acquis).map(Number), suivies: Object.keys(nv.suivies) }).then(function (d) {
        S.occupe = false; S.p[d.parcours.id] = { data: d }; S.liste = null; S.listeCharge = false; S.nv = null; S.sel = null;
        message('ok', 'Parcours créé : ' + d.parcours.cibleNom + '. Bonne route !');
        location.hash = '#/p/' + d.parcours.id;
      }, function (e) { S.occupe = false; message('ko', e.message); rendre(); });
      return;
    }
    if (act === 'sel') { S.sel = t.getAttribute('data-fo'); rendre(); return; }
    if (act === 'eval') {
      var fo = t.getAttribute('data-fo'), i = +t.getAttribute('data-i');
      var e2 = S.p[id].data.etapes.filter(function (x) { return x.fo === fo; })[0];
      var fait = !e2.evaluations[i].fait;
      var dernier = fait && e2.evaluations.filter(function (v) { return !v.fait; }).length === 1;
      ecrireParcours(id, 'PATCH', '/parcours/' + id + '/etape/' + fo, { evaluation: i, fait: fait }, function (d) {
        if (!dernier) return null;
        S.sel = null;
        var pn = d.panel;
        return 'Formation validée' + (pn && pn.ok && pn.cochees ? ' — ' + pl(pn.cochees, 'compétence') + ' cochée' + (pn.cochees > 1 ? 's' : '') + ' dans le panel' : '') +
          (pn && !pn.ok ? ' — le panel n’a pas tout enregistré (' + (pn.raison || pn.cochees + '/' + pn.attendues) + ')' : '') + '.';
      });
      return;
    }
    if (act === 'confirmer') { ecrireParcours(id, 'POST', '/parcours/' + id + '/acquis/' + t.getAttribute('data-id'), null, 'Compétence confirmée et enregistrée dans le panel.'); return; }
    if (act === 'adm') {
      var fait2 = t.getAttribute('data-fait') === '1';
      ecrireParcours(id, 'PATCH', '/parcours/' + id + '/admission/' + encodeURIComponent(t.getAttribute('data-cle')), { fait: fait2 }, fait2 ? 'Critère d’admission validé.' : 'Critère d’admission retiré.');
      return;
    }
    if (act === 'p-rythme') { ecrireParcours(id, 'PATCH', '/parcours/' + id, { rythme: t.getAttribute('data-r') }, 'Rythme changé : les dates sont recalculées.'); return; }
    if (act === 'arreter') {
      S.sheet = { titre: 'Arrêter ce parcours ?', texte: 'Les formations déjà validées restent acquises. Le parcours sort de la liste.', libelle: 'Arrêter', danger: true,
        faire: function () { ecrireParcours(id, 'PATCH', '/parcours/' + id, { statut: 'arrete' }, 'Parcours arrêté.'); } };
      rendre(); return;
    }
    if (act === 'atteint') {
      var p = S.p[id].data.parcours;
      S.sheet = { titre: 'Valider le niveau ?', texte: (p.employe ? 'Le panel enregistrera <strong>' + esc(p.cibleNom) + '</strong> pour ' + esc(p.nom) + '.' : 'Le parcours de ' + esc(p.nom) + ' sera marqué atteint.'),
        libelle: 'Valider le niveau', faire: function () { ecrireParcours(id, 'PATCH', '/parcours/' + id, { statut: 'atteint' }, 'Objectif atteint. Bravo !'); } };
      rendre(); return;
    }
    if (act === 'sheet-oui' && S.sheet) { S.sheet.faire(); return; }
    if (act === 'sheet-non' || (act === 'sheet-fond' && ev.target === t)) { if (!S.occupe) { S.sheet = null; rendre(); } return; }
  });

  root.addEventListener('input', function (ev) {
    var k = ev.target.getAttribute && ev.target.getAttribute('data-input');
    if (!k) return;
    if (k === 'filtre') { S.filtre = ev.target.value; rendre(); return; }
    if (k === 'nvShop') { S.nv = S.nv || {}; S.nv.shop = ev.target.value; rendre(); return; }
    if (k === 'nvNom') { S.nv = S.nv || {}; S.nv.nomCandidat = ev.target.value; rendre(); return; }
  });

  window.addEventListener('hashchange', function () {
    lireRoute();
    if (S.route[0] === 'p') { var id = +(S.route[1] || 0); if (S.p[id] && !S.p[id].charge) chargerParcours(id); S.sel = null; }
    if (!S.route[0]) { S.liste = null; S.listeCharge = false; }
    rendre();
    window.scrollTo(0, 0);
  });

  lireRoute();
  rendre();
})();
