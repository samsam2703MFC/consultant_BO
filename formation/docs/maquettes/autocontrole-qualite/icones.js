/* Les icônes de l'autocontrôle, au format du design system (§7.12) : SVG linéaires, viewBox 24, trait 1.7, couleur du
 * texte. Les tracés du catalogue du §8.4 sont repris tels quels (Contrôle, Retour, Ajouter, Journal, Campagnes,
 * Brochures, Repost, Médiathèque, Grille) ; les autres suivent le même dessin. */
(function () {
  const D = {
    controle: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
    retour: 'M19 12H5M12 19l-7-7 7-7',
    ajouter: 'M12 5v14M5 12h14',
    horloge: 'M12 8v4l3 3M3 12a9 9 0 1 0 9-9 9 9 0 0 0-9 9',
    reclamation: 'M3 11l14-7v16L3 13zM7 14v5',
    plan: 'M3 5l6-2 6 2 6-2v16l-6 2-6-2-6 2zM9 3v16M15 5v16',
    repeter: 'M17 1l4 4-4 4M3 11V9a4 4 0 0 1 4-4h14M7 23l-4-4 4-4M21 13v2a4 4 0 0 1-4 4H3',
    image: 'M3 5h18v14H3zM3 15l5-5 4 4 3-3 6 6M15.5 9a1.5 1.5 0 1 0 0-.01',
    grille: 'M3 3h18v18H3zM3 9h18M3 15h18M9 3v18M15 3v18',
    croix: 'M18 6L6 18M6 6l12 12',
    coche: 'M20 6L9 17l-5-5',
    'double-coche': 'M17 6L6.5 16.5 2 12M22 8l-8 8.5-1.5-1.5',
    'chevron-droite': 'M9 18l6-6-6-6',
    'chevron-bas': 'M6 9l6 6 6-6',
    suivant: 'M5 12h14M12 5l7 7-7 7',
    flamme: 'M12 22c4 0 7-2.7 7-6.8 0-3.4-2.2-5.6-4-7.7-.5 2-1.5 3-2.8 3.4.3-2.9-.9-5.9-3.7-7.9C8.3 6.2 5 9.3 5 14.8 5 19 8 22 12 22z',
    regle: 'M21.3 15.3l-6.6 6.6a1 1 0 0 1-1.4 0L2.1 10.7a1 1 0 0 1 0-1.4l6.6-6.6a1 1 0 0 1 1.4 0l11.2 11.2a1 1 0 0 1 0 1.4zM7.5 10.5l2-2M10.5 13.5l2-2M13.5 16.5l2-2',
    gout: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01',
    vitrine: 'M3 9l1.5-5h15L21 9M4 9v11h16V9M3 9h18M9 20v-6h6v6',
    personne: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
    appareil: 'M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2zM12 17a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
    cible: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 18a6 6 0 1 0 0-12 6 6 0 0 0 0 12zM12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
    outil: 'M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.8-3.8a6 6 0 0 1-7.9 7.9l-6.9 6.9a2.1 2.1 0 0 1-3-3l6.9-6.9a6 6 0 0 1 7.9-7.9z',
    lecture: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM10 8l6 4-6 4z',
    etiquette: 'M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8zM7 7h.01',
    poubelle: 'M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6',
    info: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 16v-4M12 8h.01',
    drapeau: 'M4 22V4M4 4h13l-2 4 2 4H4',
    liste: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
    alerte: 'M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01',
    oeil: 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
    publier: 'M12 16V4M7 9l5-5 5 5M5 20h14',
    deplacer: 'M9 5h.01M15 5h.01M9 12h.01M15 12h.01M9 19h.01M15 19h.01',
    cloche: 'M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0',
    // Pour le rail du portail de formation (catalogue du §8.4).
    tableau: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
    modeles: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5',
    agences: 'M4 7h16v13H4zM9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 12h18',
    etoile: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z',
    analyse: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
    calendrier: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
    ecrire: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
    ecran: 'M3 4h18v12H3zM8 20h8M12 16v4',
  };
  window.AQ_ICO = function (nom, taille) {
    const t = taille || 18;
    return `<svg class="aq-ico" width="${t}" height="${t}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${D[nom] || D.info}"/></svg>`;
  };
})();
