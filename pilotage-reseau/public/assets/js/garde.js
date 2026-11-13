/*
 * La garde des pages du cockpit (13/11/2026, src/acces.php) : l'API demande une session de la console franchisé.
 * Chargé en tête de chaque page, avant ses propres scripts :
 *   <script src="../assets/js/garde.js" data-api="../api/cockpit"></script>
 * - en http, la page repasse en https (les cookies de la console sont « Secure ») ;
 * - personne de connecté : la page part sur la page de connexion, qui la rouvre ensuite ;
 * - un franchisé : le magasin de l'adresse (?shop=) doit être l'un des siens, sinon on ouvre le premier ;
 * - une page du siège (data-siege="1" : le cockpit, les visites, le marketing…) renvoie un franchisé à son dashboard ;
 * - window.ACCES dit qui est connecté (role « siege » ou « franchise », nom, magasins).
 * La page reste cachée le temps de la vérification. Sans réseau, elle s'affiche (ses lectures échoueront seules).
 */
(function () {
  var moi = document.currentScript;
  var local = /^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname);
  if (location.protocol === 'http:' && !local) {
    location.replace('https://' + location.host + location.pathname + location.search + location.hash);
    return;
  }
  var api = new URL((moi && moi.getAttribute('data-api')) || 'api/cockpit', location.href).href.replace(/\/$/, '');
  var connexion = new URL('../../connexion/', moi ? moi.src : location.href).href;
  var racine = document.documentElement;
  racine.style.visibility = 'hidden';
  function montrer() { racine.style.visibility = ''; }
  function message(texte) {
    montrer();
    var b = document.createElement('div');
    b.setAttribute('role', 'alert');
    b.style.cssText = 'position:fixed;left:16px;right:16px;top:16px;z-index:99999;padding:14px 18px;border-radius:10px;background:#8D1D2C;color:#fff;font:15px/1.4 system-ui,sans-serif';
    b.textContent = texte;
    (document.body || racine).appendChild(b);
  }
  window.ACCES = null;
  fetch(api + '/acces/moi', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    .then(function (r) { return r.json().then(function (j) { return { code: r.status, j: j }; }); })
    .then(function (x) {
      var j = x.j || {};
      if (x.code === 503) { message(j.message || 'La connexion par la console ne répond pas. Réessayez dans un instant.'); return; }
      if (!j.connecte) { location.replace(connexion + '?retour=' + encodeURIComponent(location.href)); return; }
      window.ACCES = j;
      if (j.role === 'franchise') {
        var shops = (j.shops || []).map(String);
        if (!shops.length) { message('Votre compte n’a encore aucun magasin. Demandez au siège de l’ajouter.'); return; }
        if (moi && moi.getAttribute('data-siege') === '1') {
          location.replace(new URL('../../dashboard/?shop=' + encodeURIComponent(shops[0]), moi.src).href);
          return;
        }
        var q = new URLSearchParams(location.search);
        var s = q.get('shop');
        var dashboard = /\/dashboard\/(index\.html)?$/.test(location.pathname);
        if ((s !== null || dashboard) && shops.indexOf(s) < 0) {
          q.set('shop', shops[0]);
          location.replace(location.pathname + '?' + q.toString() + location.hash);
          return;
        }
      }
      montrer();
    })
    .catch(function () { montrer(); });
})();
