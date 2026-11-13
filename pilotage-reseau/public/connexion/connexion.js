/*
 * La page de connexion du cockpit (13/11/2026, src/acces.php). Le formulaire part à la console franchisé
 * (POST {console}/bo/{franchisee|franchisor}/login), qui pose son cookie de session sur l'hôte ; l'API du cockpit le
 * relaie à la console pour savoir qui est connecté (GET ../api/cockpit/acces/moi). Ensuite la page demandée
 * (?retour=, même origine seulement) se rouvre ; sans retour : le cockpit pour le siège, le dashboard pour un franchisé.
 * ?sortir=1 : déconnexion.
 */
(function () {
  'use strict';
  var local = /^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname);
  if (location.protocol === 'http:' && !local) {
    location.replace('https://' + location.host + location.pathname + location.search);
    return;
  }
  var API = '../api/cockpit';
  var q = new URLSearchParams(location.search);
  var app = document.getElementById('app');
  var S = { bo: 'franchisee', erreur: null, occupe: false, moi: null, console: null, email: '' };

  function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  /** La page à rouvrir : celle de ?retour= si elle est sur ce serveur, sinon l'accueil du compte. */
  function retour(moi) {
    var r = q.get('retour');
    if (r) {
      try { var u = new URL(r, location.href); if (u.origin === location.origin && !/\/connexion\//.test(u.pathname)) { return u.href; } } catch (e) { /* adresse illisible */ }
    }
    if (moi && moi.role === 'franchise' && moi.shops && moi.shops.length) { return '../dashboard/?shop=' + encodeURIComponent(moi.shops[0]); }
    return '../';
  }

  function lireMoi() {
    return fetch(API + '/acces/moi', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw new Error(j.message || ('Erreur ' + r.status)); } return j; }); });
  }

  function consoleAppel(bo, action, corps, csrf) {
    var h = { 'Content-Type': 'application/json', Accept: 'application/json' };
    if (csrf) { h['X-CSRF-Token'] = csrf; }
    return fetch(S.console + '/bo/' + bo + '/' + action, { method: 'POST', credentials: 'same-origin', headers: h, body: JSON.stringify(corps || {}) })
      .then(function (r) {
        return r.json().catch(function () { return null; }).then(function (j) {
          if (!r.ok) {
            var m = (j && j.error) || ('Erreur ' + r.status);
            if (r.status === 401 || r.status === 403) { m = 'Adresse e-mail ou mot de passe incorrect.'; }
            throw new Error(m);
          }
          return j;
        });
      });
  }

  function carte(contenu) {
    return '<div class="hr-login"><div class="hr-login__card"><img src="../hr/img/logo.png" alt="L’Atelier By" />'
      + '<h1>Cockpit du réseau</h1><p class="muted">Connectez-vous avec votre compte de la console franchisé.</p>' + contenu + '</div></div>';
  }

  function rendre() {
    if (S.moi && S.moi.connecte) {
      var role = S.moi.role === 'siege' ? 'Siège' : 'Franchisé';
      app.innerHTML = carte('<p style="margin-top:1rem">Connecté : <strong>' + esc(S.moi.nom || '') + '</strong> · ' + role + '</p>'
        + '<a class="btn btn--primary" style="margin-top:1rem;width:100%;justify-content:center" href="' + esc(retour(S.moi)) + '">Continuer</a>'
        + '<button type="button" class="btn btn--ghost" style="margin-top:.6rem;width:100%" data-act="sortir">Se déconnecter</button>');
      return;
    }
    if (!S.console) {
      app.innerHTML = carte('<p class="error" style="margin-top:1rem">' + esc(S.erreur || 'La connexion par la console n’est pas configurée sur ce serveur.') + '</p>');
      return;
    }
    var pilule = function (bo, txt) {
      return '<button type="button" class="choice-pill' + (S.bo === bo ? ' is-on' : '') + '" data-act="bo" data-bo="' + bo + '" aria-pressed="' + (S.bo === bo) + '">' + txt + '</button>';
    };
    app.innerHTML = carte('<div class="hr-login__who" role="radiogroup" aria-label="Type de compte">' + pilule('franchisee', 'Franchisé') + pilule('franchisor', 'Siège') + '</div>'
      + '<form data-form="login" novalidate>'
      + '<label class="field field--grow">Adresse e-mail<input type="email" name="email" autocomplete="username" required value="' + esc(S.email) + '" /></label>'
      + '<label class="field field--grow">Mot de passe<input type="password" name="password" autocomplete="current-password" required /></label>'
      + (S.erreur ? '<p class="error">' + esc(S.erreur) + '</p>' : '')
      + '<button type="submit" class="btn btn--primary"' + (S.occupe ? ' disabled' : '') + '>' + (S.occupe ? 'Connexion…' : 'Se connecter') + '</button>'
      + '</form><p class="muted hr-login__note">Le même compte que la page RH : même e-mail, même mot de passe. Un franchisé ouvre le dashboard de ses magasins ; le siège ouvre tout le cockpit.</p>');
    // Le curseur va là où il reste à écrire : l'e-mail, ou le mot de passe quand l'e-mail est déjà là.
    var champ = app.querySelector(S.email ? 'input[name="password"]' : 'input[name="email"]');
    if (champ) { champ.focus(); }
  }

  function sortir() {
    var m = S.moi || {};
    // L'identité gardée par l'API d'abord (elle se retrouve par le cookie), puis la session de la console.
    return fetch(API + '/acces/oublier', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' } }).catch(function () {})
      .then(function () { return m.bo ? consoleAppel(m.bo, 'logout', {}, m.csrf).catch(function () {}) : null; })
      .then(function () { S.moi = null; q.delete('sortir'); history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q.toString() : '')); rendre(); });
  }

  app.addEventListener('click', function (e) {
    var b = e.target.closest('[data-act]');
    if (!b) { return; }
    if (b.dataset.act === 'bo') { var e = app.querySelector('input[name="email"]'); if (e) { S.email = e.value; } S.bo = b.dataset.bo; S.erreur = null; rendre(); }
    if (b.dataset.act === 'sortir') { sortir(); }
  });

  app.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.dataset.form !== 'login') { return; }
    e.preventDefault();
    var fd = new FormData(f);
    S.email = String(fd.get('email') || '');
    if (!fd.get('email') || !fd.get('password')) { S.erreur = 'L’adresse e-mail et le mot de passe sont demandés.'; rendre(); return; }
    S.occupe = true; S.erreur = null; rendre();
    consoleAppel(S.bo, 'login', { email: fd.get('email'), password: fd.get('password') })
      .then(lireMoi)
      .then(function (j) {
        S.moi = j;
        if (!j.connecte) { throw new Error('Connexion acceptée par la console, mais la session n’est pas lisible ici : ouvrez la page en https.'); }
        location.replace(retour(j));
      })
      .catch(function (err) { S.erreur = err.message; S.occupe = false; rendre(); });
  });

  lireMoi().then(function (j) {
    S.moi = j; S.console = j.console || null;
    if (j.mode === 'ouvert') { location.replace(retour(null)); return; }
    if (j.connecte && q.get('sortir') === '1') { sortir(); return; }
    if (j.connecte && q.get('retour')) { location.replace(retour(j)); return; }
    rendre();
  }).catch(function (err) { S.erreur = err.message; rendre(); });
})();
