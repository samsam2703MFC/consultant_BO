/*
 * [358] Tablettes (atelier, bureau) : plein écran forcé. Le navigateur n'accepte le plein écran qu'après un geste :
 * au premier toucher (et après chaque sortie — appareil photo, retour système, rechargement), la page repasse en
 * plein écran, sans la barre d'adresse ni la barre de navigation. Installée comme application (PWA), le manifeste
 * demande déjà « fullscreen » ; ce script couvre aussi la page ouverte dans Chrome.
 */
(function () {
    'use strict';
    const root = document.documentElement;
    const request = root.requestFullscreen || root.webkitRequestFullscreen;
    if (!request || window.self !== window.top) return;
    const active = () => document.fullscreenElement || document.webkitFullscreenElement;
    // Champ de saisie touché : le clavier s'ouvre normalement, le plein écran attend le toucher suivant.
    const typing = (el) => el && el.closest && el.closest('input, textarea, select, [contenteditable="true"]');
    function enter(e) {
        if (active() || typing(e.target)) return;
        try {
            const p = request.call(root, {navigationUI: 'hide'});
            if (p && p.catch) p.catch(() => { /* refusé (réglage du navigateur) : la page reste utilisable */ });
        } catch (err) { /* navigateur sans plein écran */ }
    }
    document.addEventListener('pointerup', enter, true);
    document.addEventListener('keydown', enter, true);
})();
