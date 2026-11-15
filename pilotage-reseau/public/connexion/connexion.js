/*
 * La page de connexion du cockpit (13/11/2026, src/acces.php).
 *   - Siège : le mot de passe du cockpit. La première fois (ou « mot de passe oublié »), il se crée en prouvant qu'on
 *     tient le compte ERP du cockpit (« Mon compte » : téléphone et mot de passe), que le serveur compare au sien.
 *   - Magasin : le magasin et son code, que le siège génère ici même, une fois connecté (« Codes des magasins »).
 *   - Console franchisé : seulement quand elle répond sur le serveur (même compte que la page RH).
 * Ensuite la page demandée (?retour=, même origine seulement) se rouvre ; sans retour : le cockpit pour le siège, le
 * dashboard pour un magasin. ?sortir=1 : déconnexion.
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
  var S = { tel: '', volet: 'siege', creer: false, erreur: null, occupe: false, moi: null, cfg: null, shop: '', codes: null, nouveau: null, bo: 'franchisee' };
  try { var dernier = localStorage.getItem('acces.volet'); if (dernier === 'magasin' || dernier === 'siege') { S.volet = dernier; } S.shop = localStorage.getItem('acces.shop') || ''; } catch (e) { /* stockage fermé */ }

  function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  /** La page à rouvrir : celle de ?retour= si elle est sur ce serveur, sinon l'accueil de la session. */
  function retour(moi) {
    var r = q.get('retour');
    if (r) {
      try { var u = new URL(r, location.href); if (u.origin === location.origin && !/\/connexion\//.test(u.pathname)) { return u.href; } } catch (e) { /* adresse illisible */ }
    }
    if (moi && moi.role === 'franchise' && moi.shops && moi.shops.length) { return '../dashboard/?shop=' + encodeURIComponent(moi.shops[0]); }
    return '../';
  }

  function appel(methode, chemin, corps) {
    var o = { method: methode, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (corps) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(corps); }
    return fetch(API + chemin, o).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { throw new Error(j.error || j.message || ('Erreur ' + r.status)); }
        return j;
      });
    });
  }

  function consoleAppel(bo, action, corps, csrf) {
    var h = { 'Content-Type': 'application/json', Accept: 'application/json' };
    if (csrf) { h['X-CSRF-Token'] = csrf; }
    return fetch(S.cfg.console + '/bo/' + bo + '/' + action, { method: 'POST', credentials: 'same-origin', headers: h, body: JSON.stringify(corps || {}) })
      .then(function (r) {
        return r.json().catch(function () { return null; }).then(function (j) {
          if (!r.ok) { throw new Error(r.status === 401 || r.status === 403 ? 'Adresse e-mail ou mot de passe incorrect.' : ((j && j.error) || ('Erreur ' + r.status))); }
          return j;
        });
      });
  }

  function carte(titre, contenu) {
    return '<div class="hr-login"><div class="hr-login__card"><img src="../hr/img/logo.png" alt="L’Atelier By" />'
      + '<h1>' + titre + '</h1>' + contenu + '</div></div>';
  }
  function champ(libelle, nom, type, attrs) {
    return '<label class="field field--grow">' + libelle + '<input type="' + type + '" name="' + nom + '" ' + (attrs || '') + ' /></label>';
  }
  function erreur() { return S.erreur ? '<p class="error">' + esc(S.erreur) + '</p>' : ''; }
  function bouton(txt) { return '<button type="submit" class="btn btn--primary"' + (S.occupe ? ' disabled' : '') + '>' + (S.occupe ? 'Un instant…' : txt) + '</button>'; }

  function rendreConnecte() {
    var m = S.moi;
    var qui = m.role === 'siege' ? 'Siège' : (m.nom || 'Magasin');
    var h = '<p style="margin-top:1rem">Connecté : <strong>' + esc(qui) + '</strong>' + (m.source === 'console' ? ' · console franchisé' : '') + '</p>'
      + '<a class="btn btn--primary" style="margin-top:1rem;width:100%;justify-content:center" href="' + esc(retour(m)) + '">Continuer</a>'
      + '<button type="button" class="btn btn--ghost" style="margin-top:.6rem;width:100%" data-act="sortir">Se déconnecter</button>';
    if (m.role === 'siege') {
      h += '<h2 style="margin:1.8rem 0 .3rem;font-size:16px;font-weight:400">Codes des magasins</h2>'
        + '<p class="muted" style="font-size:12px">Chaque magasin ouvre son dashboard avec son code, et seulement le sien. Un nouveau code remplace l’ancien et déconnecte les appareils du magasin. Le code s’affiche une seule fois : notez-le.</p>';
      if (!S.codes) { h += '<p class="muted">Chargement…</p>'; }
      else {
        h += '<div style="margin-top:.6rem;display:flex;flex-direction:column;gap:6px">' + S.codes.map(function (c) {
          var neuf = S.nouveau && S.nouveau.shop === c.id ? '<div style="margin-top:6px;padding:10px 12px;border-radius:8px;background:#f6efe9;font:600 20px/1.2 ui-monospace,monospace;letter-spacing:2px;text-align:center" data-code="' + esc(c.id) + '">' + esc(S.nouveau.code) + '</div>' : '';
          return '<div style="border:1px solid var(--line);border-radius:8px;padding:8px 10px"><div style="display:flex;align-items:center;gap:8px">'
            + '<span style="flex:1">' + esc(c.nom) + '<br><span class="muted" style="font-size:11px">' + (c.defini ? 'code depuis le ' + esc((c.le || '').replace(/^(\d{4})-(\d{2})-(\d{2})/, '$3/$2/$1')) : 'pas de code') + '</span></span>'
            + '<button type="button" class="btn btn--sm" data-act="code" data-shop="' + esc(c.id) + '">' + (c.defini ? 'Nouveau code' : 'Créer un code') + '</button>'
            + (c.defini ? '<button type="button" class="btn btn--ghost btn--sm" data-act="retirer" data-shop="' + esc(c.id) + '">Retirer</button>' : '')
            + '</div>' + neuf + '</div>';
        }).join('') + '</div>';
      }
      h += erreur();
    }
    app.innerHTML = carte('Cockpit du réseau', h);
  }

  function rendre() {
    if (S.moi && S.moi.connecte) { rendreConnecte(); return; }
    var c = S.cfg || { magasins: [], motDePasse: true };
    var volets = [['siege', 'Siège'], ['magasin', 'Magasin']].concat(c.console ? [['console', 'Console']] : []);
    var h = '<p class="muted">Connectez-vous pour ouvrir le cockpit ou le dashboard de votre magasin.</p>'
      + '<div class="hr-login__who" role="radiogroup" aria-label="Qui se connecte">' + volets.map(function (v) {
        return '<button type="button" class="choice-pill' + (S.volet === v[0] ? ' is-on' : '') + '" data-act="volet" data-volet="' + v[0] + '" aria-pressed="' + (S.volet === v[0]) + '">' + v[1] + '</button>';
      }).join('') + '</div>';
    if (S.volet === 'siege' && (S.creer || !c.motDePasse)) {
      h += '<form data-form="creer" novalidate>'
        + '<p class="muted" style="font-size:12px;margin:.8rem 0 .2rem">' + (c.motDePasse ? 'Mot de passe oublié : prouvez que vous tenez le compte ERP du cockpit, puis choisissez-en un nouveau. Toutes les sessions ouvertes seront fermées.' : 'Première connexion : prouvez que vous tenez le compte ERP du cockpit (« Mon compte » : son téléphone et son mot de passe), puis choisissez le mot de passe du siège.') + '</p>'
        + champ('Téléphone du compte ERP', 'telephone', 'tel', 'autocomplete="username" required value="' + esc(S.tel) + '"')
        + champ('Mot de passe du compte ERP', 'motDePasseErp', 'password', 'autocomplete="off" required')
        + champ('Nouveau mot de passe du siège', 'motDePasse', 'password', 'autocomplete="new-password" minlength="10" required')
        + champ('Le même, une seconde fois', 'encore', 'password', 'autocomplete="new-password" required')
        + erreur() + bouton(c.motDePasse ? 'Réinitialiser' : 'Créer et se connecter') + '</form>'
        + (c.motDePasse ? '<button type="button" class="btn btn--ghost btn--sm" style="margin-top:.6rem" data-act="creer" data-v="0">Retour</button>' : '');
    } else if (S.volet === 'siege') {
      h += '<form data-form="siege" novalidate>' + champ('Mot de passe du siège', 'motDePasse', 'password', 'autocomplete="current-password" required')
        + erreur() + bouton('Se connecter') + '</form>'
        + '<button type="button" class="btn btn--ghost btn--sm" style="margin-top:.6rem" data-act="creer" data-v="1">Mot de passe oublié ?</button>';
    } else if (S.volet === 'magasin') {
      h += '<form data-form="magasin" novalidate><label class="field field--grow">Magasin<select name="shop" required>'
        + '<option value="">Choisir…</option>' + c.magasins.map(function (m) { return '<option value="' + esc(m.id) + '"' + (String(m.id) === String(S.shop) ? ' selected' : '') + '>' + esc(m.nom) + '</option>'; }).join('')
        + '</select></label>' + champ('Code du magasin', 'code', 'text', 'autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="ABCD-EFGH" required')
        + erreur() + bouton('Ouvrir le dashboard') + '</form>'
        + '<p class="muted hr-login__note">Le code se demande au siège. Il n’ouvre que le dashboard de ce magasin.</p>';
    } else {
      var pil = function (bo, t) { return '<button type="button" class="choice-pill' + (S.bo === bo ? ' is-on' : '') + '" data-act="bo" data-bo="' + bo + '">' + t + '</button>'; };
      h += '<div class="hr-login__who">' + pil('franchisee', 'Franchisé') + pil('franchisor', 'Siège') + '</div>'
        + '<form data-form="console" novalidate>' + champ('Adresse e-mail', 'email', 'email', 'autocomplete="username" required')
        + champ('Mot de passe', 'password', 'password', 'autocomplete="current-password" required') + erreur() + bouton('Se connecter') + '</form>';
    }
    app.innerHTML = carte('Cockpit du réseau', h);
    var f = app.querySelector('form input:not([type=hidden]), form select');
    var vide = app.querySelector(S.volet === 'magasin' && S.shop ? 'input[name="code"]' : 'form input, form select');
    if (vide || f) { (vide || f).focus(); }
  }

  function lireMoi() { return appel('GET', '/acces/moi'); }
  function apres(j) {
    S.moi = j;
    if (!j.connecte) { throw new Error('Connexion acceptée, mais la session n’est pas lisible ici : ouvrez la page en https.'); }
    location.replace(retour(j));
  }
  function lireCodes() { return appel('GET', '/acces/codes').then(function (j) { S.codes = j.magasins || []; rendre(); }).catch(function (e) { S.erreur = e.message; rendre(); }); }

  function sortir() {
    var m = S.moi || {};
    return appel('POST', '/acces/sortir', {}).catch(function () {})
      .then(function () { return m.source === 'console' && m.bo && S.cfg && S.cfg.console ? consoleAppel(m.bo, 'logout', {}, m.csrf).catch(function () {}) : null; })
      .then(function () { S.moi = null; S.codes = null; S.nouveau = null; q.delete('sortir'); history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q.toString() : '')); rendre(); });
  }

  app.addEventListener('click', function (e) {
    var b = e.target.closest('[data-act]');
    if (!b) { return; }
    var a = b.dataset.act;
    if (a === 'volet') { S.volet = b.dataset.volet; S.erreur = null; S.creer = false; try { localStorage.setItem('acces.volet', S.volet); } catch (x) { /* rien */ } rendre(); }
    if (a === 'creer') { S.creer = b.dataset.v === '1'; S.erreur = null; rendre(); }
    if (a === 'bo') { S.bo = b.dataset.bo; S.erreur = null; rendre(); }
    if (a === 'sortir') { sortir(); }
    if (a === 'code' || a === 'retirer') {
      if (a === 'retirer' && !confirm('Retirer le code de ce magasin ? Ses appareils seront déconnectés.')) { return; }
      if (a === 'code' && b.textContent === 'Nouveau code' && !confirm('Un nouveau code remplace l’ancien et déconnecte les appareils du magasin. Continuer ?')) { return; }
      S.erreur = null;
      appel('POST', '/acces/codes', { shop: b.dataset.shop, retirer: a === 'retirer' })
        .then(function (j) { S.nouveau = j.code ? { shop: j.shop, code: j.code } : null; return lireCodes(); })
        .catch(function (x) { S.erreur = x.message; rendre(); });
    }
  });

  app.addEventListener('submit', function (e) {
    var f = e.target;
    e.preventDefault();
    var fd = new FormData(f);
    var v = function (k) { return String(fd.get(k) || ''); };
    var envoi;
    if (f.dataset.form === 'siege') {
      if (!v('motDePasse')) { S.erreur = 'Le mot de passe est demandé.'; rendre(); return; }
      envoi = appel('POST', '/acces/siege', { motDePasse: v('motDePasse') });
    } else if (f.dataset.form === 'creer') {
      S.tel = v('telephone');
      if (!v('telephone') || !v('motDePasseErp')) { S.erreur = 'Le téléphone et le mot de passe du compte ERP sont demandés.'; rendre(); return; }
      if (v('motDePasse').length < 10) { S.erreur = 'Le mot de passe du siège : 10 caractères au moins.'; rendre(); return; }
      if (v('motDePasse') !== v('encore')) { S.erreur = 'Les deux mots de passe ne sont pas les mêmes.'; rendre(); return; }
      envoi = appel('POST', '/acces/siege/creer', { telephone: v('telephone'), motDePasseErp: v('motDePasseErp'), motDePasse: v('motDePasse') });
    } else if (f.dataset.form === 'magasin') {
      S.shop = v('shop');
      if (!S.shop || !v('code')) { S.erreur = 'Le magasin et son code sont demandés.'; rendre(); return; }
      try { localStorage.setItem('acces.shop', S.shop); } catch (x) { /* rien */ }
      envoi = appel('POST', '/acces/magasin', { shop: S.shop, code: v('code') });
    } else if (f.dataset.form === 'console') {
      envoi = consoleAppel(S.bo, 'login', { email: v('email'), password: v('password') });
    } else { return; }
    S.occupe = true; S.erreur = null; rendre();
    envoi.then(lireMoi).then(apres).catch(function (err) { S.erreur = err.message; S.occupe = false; rendre(); });
  });

  Promise.all([appel('GET', '/acces/config'), lireMoi()]).then(function (r) {
    S.cfg = r[0]; S.moi = r[1];
    if (S.moi.mode === 'ouvert') { location.replace(retour(null)); return; }
    if (S.moi.connecte && q.get('sortir') === '1') { sortir(); return; }
    if (S.moi.connecte && q.get('retour')) { location.replace(retour(S.moi)); return; }
    rendre();
    if (S.moi.connecte && S.moi.role === 'siege') { lireCodes(); }
  }).catch(function (err) { S.erreur = err.message; rendre(); });
})();
