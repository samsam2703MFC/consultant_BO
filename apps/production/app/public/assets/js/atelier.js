/* ==========================================================================
   ERP — confort de lecture et de saisie, chargé sur toutes les pages.
   Ne change aucune donnée envoyée au serveur : il marque les champs
   obligatoires, signale les erreurs pendant la saisie et affiche les dates
   au format polonais (la valeur brute reste dans l'info-bulle).
   ========================================================================== */
(function () {
    'use strict';

    var FIELDS = 'input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]), select, textarea';

    /* --- Champs obligatoires : astérisque sur le libellé ------------------ */
    function labelFor(field) {
        if (field.id) {
            var byFor = document.querySelector('label[for="' + CSS.escape(field.id) + '"]');
            if (byFor) return byFor;
        }
        var group = field.closest('.form-group, .mb-3, .mb-2, .col, [class*="col-"]');
        for (var i = 0; group && i < 3; i++) {
            var lab = group.querySelector('label:not(.form-check-label)');
            if (lab) return lab;
            group = group.parentElement && group.parentElement.closest('.form-group, .row');
        }
        return null;
    }

    function markRequired(root) {
        root.querySelectorAll('[required]').forEach(function (field) {
            if (!field.matches(FIELDS)) return;
            var lab = labelFor(field);
            // Etykieta z gwiazdką wpisaną w tekst (kreatory) : bez drugiej gwiazdki.
            if (lab && !/\*\s*$/.test(lab.textContent)) lab.classList.add('at-required');
        });
    }

    /* --- Vérification pendant la saisie ------------------------------------ */
    function check(field) {
        if (!field.willValidate || field.disabled || field.readOnly) return;
        var ok = field.checkValidity();
        field.classList.toggle('is-invalid', !ok);
        field.classList.toggle('is-valid', ok && field.value !== '');
    }

    document.addEventListener('blur', function (e) {
        var f = e.target;
        if (f.matches && f.matches(FIELDS) && f.form && (f.required || f.pattern || f.type === 'email' || f.min || f.max)) check(f);
    }, true);
    document.addEventListener('input', function (e) {
        if (e.target.classList && e.target.classList.contains('is-invalid')) check(e.target);
    }, true);
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.noValidate) return;
        form.querySelectorAll(FIELDS).forEach(function (f) { if (f.required) check(f); });
    }, true);

    /* --- Dates au format polonais ----------------------------------------- */
    var ISO = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::\d{2})?)?$/;
    var TIME = /^(\d{2}):(\d{2}):\d{2}$/;   // heure seule sous une date : secondes retirées
    var SKIP = 'script, style, textarea, input, select, option, [contenteditable], .no-format, [data-no-format]';

    function formatDates(root) {
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode: function (node) {
                var t = node.nodeValue.trim();
                if (!(t.length >= 10 && t.length <= 19 && ISO.test(t)) && !(t.length === 8 && TIME.test(t) && node.parentElement && node.parentElement.closest('td'))) return NodeFilter.FILTER_SKIP;
                var p = node.parentElement;
                if (!p || p.closest(SKIP)) return NodeFilter.FILTER_SKIP;
                return NodeFilter.FILTER_ACCEPT;
            }
        });
        var nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        nodes.forEach(function (node) {
            var raw = node.nodeValue.trim();
            var m = raw.match(ISO), tm = raw.match(TIME);
            var txt = m ? m[3] + '.' + m[2] + '.' + m[1] + (m[4] ? ', ' + m[4] + ':' + m[5] : '') : tm[1] + ':' + tm[2];
            node.nodeValue = node.nodeValue.replace(raw, txt);
            var p = node.parentElement;
            if (p && !p.title) p.title = raw;
        });
    }

    /* --- Démarrage, et contenu chargé plus tard (AJAX) ------------------- */
    function run(root) {
        markRequired(root);
        formatDates(root);
    }

    function start() {
        var main = document.getElementById('main') || document.body;
        run(main);
        var pending = false;
        new MutationObserver(function () {
            if (pending) return;
            pending = true;
            setTimeout(function () { pending = false; run(main); }, 150);
        }).observe(main, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
