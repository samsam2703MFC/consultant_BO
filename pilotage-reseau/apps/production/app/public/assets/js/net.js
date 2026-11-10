/*
 * Appels réseau de toutes les pages de l'ERP (chargé en tête, avant les autres scripts).
 * - Application dans un sous-répertoire (ex. /production) : les appels écrits en dur '/ajax/…' ou
 *   '/api/…' sont dirigés sous ce répertoire (fetch et XMLHttpRequest).
 * - Connexion coupée (« Failed to fetch ») : une lecture (GET / HEAD) est retentée une fois après
 *   0,7 s ; ensuite un message lisible remplace le texte brut du navigateur. Une écriture n'est
 *   jamais rejouée (risque de doublon).
 * - En quittant la page, les appels interrompus par le navigateur n'affichent pas d'erreur.
 */
(function () {
    'use strict';
    var base = new URL(window.API_BASE_URL || '/', window.location.origin).pathname.replace(/\/+$/, '');
    var fix = function (u) { return (base && typeof u === 'string' && /^\/(ajax|api)\//.test(u)) ? base + u : u; };
    var leaving = false;
    window.addEventListener('pagehide', function () { leaving = true; });
    window.addEventListener('beforeunload', function () { leaving = true; });
    window.addEventListener('pageshow', function () { leaving = false; });
    window.ERP_NETWORK_ERROR = 'Brak połączenia z serwerem — sprawdź sieć i spróbuj ponownie.';

    var nativeFetch = window.fetch;
    window.fetch = function (input, init) {
        var url = fix(input);
        var method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
        var attempt = function (retried) {
            return nativeFetch.call(window, url, init).catch(function (err) {
                if (err && err.name === 'AbortError') throw err;
                if (leaving) return new Promise(function () {});
                if (!retried && (method === 'GET' || method === 'HEAD')) {
                    return new Promise(function (resolve) { setTimeout(resolve, 700); }).then(function () { return attempt(true); });
                }
                throw new TypeError(window.ERP_NETWORK_ERROR);
            });
        };
        return attempt(false);
    };

    var nativeOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url) {
        var args = Array.prototype.slice.call(arguments);
        args[1] = fix(url);
        return nativeOpen.apply(this, args);
    };
})();
