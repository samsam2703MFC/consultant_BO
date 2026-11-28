/* Le manuel du réseau de démonstration (fictif) : les documents que la méga-barre de « Formations et suivi » cherche
 * (mocks/72_formation.js) et que le Professeur cite (mocks/71_assistant.js). Codes au format du manuel (0.x.yy SOP,
 * 0.0.yy article, 1.x.yy checklist, FC-0xx formation continue), contenus courts et génériques, photos dessinées. */

const E = (titre, texte, o) => Object.assign({ titre, texte, critique: false, photo: null, video: null }, o || {});
/** Un document : id, catalogue, type, code, titre, objectif, pour, durée, poste, moment, sections [titre, étapes], points critiques. */
const DOCS = [
  { id: 'art-chk-01', cat: 'man', type: 'Article', code: '0.0.14', titre: 'Les checklists du magasin : la structure', objectif: 'Comprendre comment les checklists sont rangées : qui les fait, quand, et comment elles sont contrôlées.', pour: 'Toute l’équipe', duree: '6 min',
    sections: [['', [E('Qui', 'Chaque checklist dit qui la fait : vente, cuisson, production, nettoyage ou gestion.'), E('Quand', 'Chaque jour, chaque semaine ou chaque mois, à l’heure de la checklist.'), E('Le contrôle', 'Les tâches à photo sont notées ; une photo sous 4 est une non-conformité à corriger.')]]] },
  { id: 'art-vien-01', cat: 'man', type: 'Article', code: '0.0.21', titre: 'La viennoiserie : pourquoi le tourage compte', objectif: 'Comprendre ce que le tourage donne au croissant : le feuilletage, le volume, le croustillant.', pour: 'Production et vente', duree: '5 min',
    sections: [['', [E('Le feuilletage', 'Le beurre et la pâte, pliés couche sur couche : c’est ce qui fait lever le croissant à la cuisson.', { photo: 'u/croissants' }), E('Ce que voit le client', 'Un croissant bien touré est léger, régulier et doré ; c’est lui qui fait revenir le client le matin.')]]] },
  { id: 'art-hyg-01', cat: 'man', type: 'Article', code: '0.0.08', titre: 'L’hygiène au comptoir : les règles du réseau', objectif: 'Les gestes d’hygiène du comptoir, et pourquoi ils comptent.', pour: 'Vente', duree: '4 min',
    sections: [['', [E('Les mains', 'Se laver les mains en prenant son poste et après chaque pause.'), E('La pince', 'Servir à la pince ou au papier, jamais à la main.', { critique: true })]]] },
  { id: 'art-all-01', cat: 'man', type: 'Article', code: '0.0.11', titre: 'Les allergènes : informer le client', objectif: 'Où trouver les allergènes d’un produit et comment répondre au client.', pour: 'Vente', duree: '5 min',
    sections: [['', [E('La fiche du produit', 'Les allergènes sont sur la fiche de chaque produit et sur l’étiquette de la vitrine.'), E('Dans le doute', 'Ne jamais deviner : montrer la fiche, ou demander au responsable.', { critique: true })]]] },
  { id: 'ouv-mag', cat: 'std', type: 'SOP', code: '0.1.01', titre: 'Ouverture du magasin et des comptoirs', objectif: 'Ouvrir le magasin dans l’ordre, pour être prêt à l’heure.', pour: 'Vente', duree: '30 min', poste: 'Vente', moment: 'ouverture',
    sections: [['Avant d’ouvrir', [E('Les lumières et la caisse', 'Allumer les lumières, la caisse et les vitrines réfrigérées.'), E('La vitrine', 'Remplir la vitrine en suivant le planogramme du jour.', { photo: 'u/vitrine' }), E('Le contrôle', 'Faire la checklist d’ouverture et sa photo.', { critique: true })]]] },
  { id: 'sop-cuis-vien', cat: 'std', type: 'SOP', code: '0.1.04', titre: 'Cuisson des viennoiseries du matin', objectif: 'Cuire les croissants et les pains au chocolat pour l’ouverture, dorés et réguliers.', pour: 'Cuisson', duree: '45 min', poste: 'Cuisson', moment: 'ouverture',
    sections: [['', [E('Le four', 'Préchauffer le four sur le programme viennoiserie.'), E('L’enfournement', 'Enfourner les croissants dorés à l’œuf, bien espacés.', { photo: 'u/croissants' }), E('La sortie', 'Sortir quand ils sont dorés partout ; laisser refroidir sur grille avant la vitrine.', { critique: true })]]] },
  { id: 'sop-tour', cat: 'std', type: 'SOP', code: '0.2.05', titre: 'Tourage de la pâte à croissant', objectif: 'Tourer la pâte à croissant : un feuilletage régulier, un croissant léger et croustillant.', pour: 'Production', duree: '40 min', poste: 'Production', moment: 'journee',
    sections: [['Préparer', [E('La pâte et le beurre', 'Sortir la pâte et le beurre de tourage du froid : ils doivent avoir la même souplesse.'), E('Enfermer le beurre', 'Étaler la pâte, poser le beurre au centre et le refermer sans laisser d’air.', { photo: 'u/tourage' })]],
      ['Tourer', [E('Les tours', 'Donner les tours en tournant la pâte d’un quart à chaque fois.', { critique: true }), E('Le repos', 'Laisser reposer au froid entre deux tours : le beurre ne doit jamais fondre.', { critique: true }), E('Détailler', 'Abaisser, détailler les triangles et rouler les croissants sans les écraser.', { photo: 'u/croissants' })]]],
    points_critiques: ['Le beurre ne doit jamais fondre dans la pâte : si la pâte chauffe, la remettre au froid.', 'Un tour oublié ou en trop change tout le feuilletage : noter chaque tour sur la fiche.'] },
  { id: 'sop-vitrine', cat: 'std', type: 'SOP', code: '0.2.02', titre: 'Remplir la vitrine en journée', objectif: 'Garder une vitrine pleine et belle jusqu’au soir.', pour: 'Vente', duree: '10 min', poste: 'Vente', moment: 'journee',
    sections: [['', [E('Le tour de vitrine', 'Toutes les heures, regarder ce qui manque et le remplacer.', { photo: 'u/vitrine' }), E('Les étiquettes', 'Chaque produit a son étiquette, avec son prix et ses allergènes.')]]] },
  { id: 'memo-croissant', cat: 'std', type: 'Mémo', code: '0.2.06', titre: 'Mémo : le croissant parfait en 6 points', objectif: 'Les six points à vérifier sur un croissant avant la vitrine.', pour: 'Production et cuisson', duree: '2 min', poste: 'Production', moment: 'journee',
    sections: [['', [E('La forme', 'Bien roulé, les pointes dessous.', { photo: 'u/croissants' }), E('La couleur', 'Doré partout, sans brûlé.'), E('Le feuilletage', 'Des couches régulières à la coupe.', { critique: true })]]] },
  { id: 'fiche-buche', cat: 'std', type: 'Fiche', code: '0.2.12', titre: 'Fiche produit : bûche de Noël 2026', objectif: 'La bûche de la saison : sa composition, ses allergènes, son prix conseillé.', pour: 'Production et vente', duree: '3 min', poste: 'Production', moment: 'journee',
    sections: [['', [E('La composition', 'Biscuit roulé, crème au beurre, décor de saison.', { photo: 'u/buche' }), E('Les allergènes', 'Gluten, œufs, lait ; fruits à coque selon le décor.', { critique: true })]]] },
  { id: 'sop-ferm', cat: 'std', type: 'SOP', code: '0.4.01', titre: 'Fermeture du magasin et des comptoirs', objectif: 'Fermer le magasin dans l’ordre : rien d’oublié, tout prêt pour le lendemain.', pour: 'Vente', duree: '40 min', poste: 'Vente', moment: 'fermeture',
    sections: [['', [E('La vitrine', 'Sortir les invendus, les compter et les noter.'), E('Le nettoyage', 'Nettoyer la vitrine, le comptoir et les plans de travail.'), E('Les frigos', 'Vérifier que les frigos et les vitrines réfrigérées sont fermés et à bonne température.', { critique: true }), E('La caisse', 'Clôturer la caisse et la compter à deux.', { critique: true })]]] },
  { id: 'sop-caisse', cat: 'std', type: 'SOP', code: '0.4.03', titre: 'Clôture de la caisse', objectif: 'Clôturer la caisse sans écart, et le noter.', pour: 'Gestion', duree: '15 min', poste: 'Gestion', moment: 'fermeture',
    sections: [['', [E('Le ticket de clôture', 'Sortir le ticket de clôture de la journée.'), E('Le comptage', 'Compter la caisse à deux et noter l’écart.', { critique: true })]]] },
  { id: 'chk-ac-01', cat: 'chk', type: 'Checklist', code: '1.1.01', titre: 'Ouverture de la boutique', objectif: 'Les tâches de l’ouverture, à cocher chaque matin.', pour: 'Vente', duree: '', poste: 'Vente', moment: 'ouverture',
    sections: [['', [E('Lumières, caisse et vitrines allumées', ''), E('Vitrine remplie selon le planogramme', '', { photo: 'u/vitrine' }), E('Sol et comptoir propres', '')]]] },
  { id: 'chk-vit-02', cat: 'chk', type: 'Checklist', code: '1.2.03', titre: 'Vitrine viennoiserie de 10 h', objectif: 'La vitrine de viennoiserie pleine pour la deuxième vague du matin.', pour: 'Vente', duree: '', poste: 'Vente', moment: 'journee',
    sections: [['', [E('Croissants et pains au chocolat réassortis', '', { photo: 'u/croissants' }), E('Étiquettes en place', '')]]] },
  { id: 'chk-ferm', cat: 'chk', type: 'Checklist', code: '1.4.01', titre: 'Fermeture de la boutique', objectif: 'Les tâches de la fermeture, à cocher chaque soir.', pour: 'Vente', duree: '', poste: 'Vente', moment: 'fermeture',
    sections: [['', [E('Invendus comptés et notés', ''), E('Frigos fermés et à température', '', { critique: true }), E('Caisse clôturée et comptée à deux', '', { critique: true })]]] },
];

/* Les autres documents du manuel : un titre, un objectif, une étape (ce que la recherche lit). */
const AUTRES = [
  ['man', 'Article', '0.0.01', 'La marque L’Atelier By', 'Ce qui fait la marque : les produits, l’accueil, le magasin.'],
  ['man', 'Article', '0.0.03', 'Le rôle de chacun au magasin', 'Qui fait quoi, du franchisé à l’étudiant du week-end.'],
  ['man', 'Article', '0.0.05', 'Lire les chiffres de son magasin', 'Le chiffre d’affaires, le panier, le food cost, le personnel.'],
  ['man', 'Article', '0.0.17', 'Les invendus : les compter, les réduire', 'Pourquoi on compte les invendus, et comment les faire baisser.'],
  ['man', 'Article', '0.0.24', 'Le service au comptoir', 'Accueillir, conseiller, proposer : le service du réseau.'],
  ['std', 'SOP', '0.1.02', 'Mise en route du fournil', 'Allumer et contrôler les équipements du fournil.', 'Production', 'ouverture'],
  ['std', 'SOP', '0.1.06', 'Cuisson des pains du matin', 'Cuire les baguettes et les pains spéciaux pour l’ouverture.', 'Cuisson', 'ouverture'],
  ['std', 'SOP', '0.2.08', 'Préparer les sandwichs du midi', 'Monter les sandwichs du jour, dans l’ordre et au froid.', 'Production', 'journee'],
  ['std', 'SOP', '0.2.10', 'Montage des tartes du jour', 'Foncer, garnir et cuire les tartes de la vitrine.', 'Production', 'journee'],
  ['std', 'SOP', '0.3.01', 'Le réassort de l’après-midi', 'Remplir la vitrine pour la fin de journée.', 'Vente', 'apres-midi'],
  ['std', 'SOP', '0.4.05', 'Nettoyage du fournil', 'Nettoyer le fournil et ranger les plaques.', 'Nettoyage', 'fermeture'],
  ['std', 'Mémo', '0.2.14', 'Mémo : la baguette tradition', 'Les points à vérifier sur une baguette avant la vitrine.', 'Cuisson', 'journee'],
  ['std', 'Fiche', '0.2.15', 'Fiche produit : pain au chocolat', 'Composition, allergènes et prix conseillé.', 'Production', 'journee'],
  ['chk', 'Checklist', '1.1.02', 'Ouverture du fournil', 'Les tâches de l’ouverture du fournil, chaque matin.', 'Production', 'ouverture'],
  ['chk', 'Checklist', '1.2.01', 'Températures des frigos', 'Relever la température de chaque frigo, deux fois par jour.', 'Vente', 'journee'],
  ['chk', 'Checklist', '1.3.02', 'Vitrine de l’après-midi', 'La vitrine pleine et étiquetée pour la fin de journée.', 'Vente', 'apres-midi'],
  ['chk', 'Checklist', '5.4.01', 'Clôture de la journée', 'La caisse, les invendus et les commandes du lendemain.', 'Gestion', 'fermeture'],
];
AUTRES.forEach(([cat, type, code, titre, objectif, poste, moment], i) => DOCS.push({ id: 'doc-' + code.replace(/\./g, '-'), cat, type, code, titre, objectif, pour: poste || 'Toute l’équipe', duree: '', poste: poste || '', moment: moment || '',
  sections: [['', [E(titre, objectif)]]] }));

/** Les formations FO-xx, les domaines du manuel du réseau. */
const FORMATIONS = [
  { code: 'FO-01', titre: 'Vendeur (initiale + recyclage)', famille: 'Vente', postes: ['Vendeur'], phases: ['avant l’ouverture', 'formation continue'], competences: 9 },
  { code: 'FO-02', titre: 'Boulanger-tourier', famille: 'Production', postes: ['Boulanger'], phases: ['avant l’ouverture'], competences: 12 },
  { code: 'FO-03', titre: 'Pâtissier', famille: 'Production', postes: ['Pâtissier'], phases: ['avant l’ouverture'], competences: 11 },
  { code: 'FO-04', titre: 'Responsable de magasin', famille: 'Gestion', postes: ['Responsable'], phases: ['lancement'], competences: 8 },
  { code: 'FO-05', titre: 'Franchisé : piloter son magasin', famille: 'Gestion', postes: ['Franchisé'], phases: ['avant l’ouverture', 'lancement'], competences: 10 },
  { code: 'FO-06', titre: 'Préparateur snacking', famille: 'Production', postes: ['Préparateur'], phases: ['avant l’ouverture'], competences: 7 },
  { code: 'FO-07', titre: 'Hygiène et sécurité alimentaire', famille: 'Hygiène', postes: ['Tous'], phases: ['avant l’ouverture', 'formation continue'], competences: 6 },
];
const DOMAINES = [
  { code: 'R1', titre: 'Les produits', souscats: ['Viennoiserie', 'Pains', 'Pâtisserie', 'Snacking'] },
  { code: 'R2', titre: 'Hygiène et sécurité', souscats: ['Hygiène', 'Allergènes', 'Sécurité'] },
  { code: 'R3', titre: 'Vente et service', souscats: ['Accueil', 'Vitrine', 'Caisse'] },
  { code: 'R4', titre: 'Gestion du magasin', souscats: ['Planning', 'Commandes', 'Chiffres'] },
];

/* Les photos des étapes (dessins) : /formation/sop/photo/{nom}. */
const T = require('./taches');
function buche(v) {
  const r = (v || 0) % 3, deco = ['#3f6b3a', '#8D1D2C', '#d9973b'][r];
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 480" width="640" height="480"><defs><linearGradient id="f" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f4ede2"/><stop offset="1" stop-color="#e4d8c6"/></linearGradient>
    <linearGradient id="b" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#7a4a26"/><stop offset="1" stop-color="#4d2a12"/></linearGradient></defs>
    <rect width="640" height="480" fill="url(#f)"/><ellipse cx="320" cy="372" rx="250" ry="26" fill="#000" opacity=".10"/><rect x="90" y="330" width="460" height="22" rx="6" fill="#d8c7a8"/>
    <path d="M120 330 C120 230 160 200 210 200 L470 200 C520 200 540 240 540 330 Z" fill="url(#b)"/>
    ${[0, 1, 2, 3, 4, 5].map(i => `<path d="M${150 + i * 62} 214 q12 50 0 108" stroke="#8a5a32" stroke-width="5" fill="none" opacity=".7"/>`).join('')}
    <ellipse cx="540" cy="266" rx="26" ry="64" fill="#9b6a3f"/><ellipse cx="540" cy="266" rx="16" ry="44" fill="none" stroke="#6d4424" stroke-width="4"/>
    <circle cx="250" cy="196" r="14" fill="${deco}"/><circle cx="276" cy="190" r="10" fill="#fff" opacity=".9"/><path d="M330 186 l26 -30 l26 30z" fill="${deco}"/><circle cx="430" cy="194" r="12" fill="#e8e1d3"/></svg>`;
}
function tourage() {
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 480" width="640" height="480"><rect width="640" height="480" fill="#d9d2c7"/><rect x="40" y="40" width="560" height="400" rx="10" fill="#efe9df"/>
    <rect x="150" y="150" width="340" height="180" rx="14" fill="#f2dfb5"/><rect x="200" y="185" width="240" height="110" rx="8" fill="#f8e7a0" opacity=".95"/>
    ${[0, 1, 2, 3, 4, 5, 6].map(i => `<path d="M150 ${166 + i * 24} h340" stroke="#e5c98a" stroke-width="3" opacity=".7"/>`).join('')}
    <rect x="470" y="120" width="30" height="240" rx="15" fill="#b98a5a" transform="rotate(18 485 240)"/></svg>`;
}
function photo(nom) {
  const n = String(nom).replace(/\.svg$/, '');
  if (n === 'croissants') { return T.illustration('croissants'); }
  if (n === 'vitrine') { return T.illustration('viennoiseries'); }
  if (n === 'tourage') { return tourage(); }
  if (/^buche/.test(n)) { return buche(+(n.split('-')[1] || 0)); }
  return null;
}

/** Le contenu d'un document, dans la forme que lit le lecteur (contenu : titre, objectif, pour, durée, sections, points critiques). */
function contenu(d) {
  let n = 0;   // les étapes se numérotent d'un bout à l'autre du document
  return { titre: d.titre, objectif: d.objectif, pour: d.pour, duree: d.duree,
    sections: d.sections.map(([titre, L]) => ({ titre, etapes: L.map(e => Object.assign({ numero: ++n }, e)) })), points_critiques: d.points_critiques || [] };
}
/** Des sections {titre, etapes} numérotées d'un bout à l'autre. */
function numeroter(sections) { let n = 0; return sections.map(s => ({ titre: s.titre, etapes: s.etapes.map(e => Object.assign({ numero: ++n }, e)) })); }
const doc = id => DOCS.find(d => d.id === id);

module.exports = { DOCS, FORMATIONS, DOMAINES, contenu, numeroter, doc, photo, buche };
