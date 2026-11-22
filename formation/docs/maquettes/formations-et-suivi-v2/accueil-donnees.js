/* Les colonnes de l'accueil du module Formation, reprises telles quelles de formation/public/formation/portail.js (ecranAccueil). */
window.ACCUEIL = (function () {
  const AC_ICO = {
    fo: 'M22 10L12 5 2 10l10 5 10-5zM6 12v5c3 2 9 2 12 0v-5',
    man: 'M2 4h7a3 3 0 0 1 3 3v14a2 2 0 0 0-2-2H2zM22 4h-7a3 3 0 0 0-3 3v14a2 2 0 0 1 2-2h8z',
    sop: 'M7 2.5h10a2 2 0 0 1 2 2v15a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-15a2 2 0 0 1 2-2zM9 8h6M9 12h6M9 16h3',
    chk: 'M4 6l2 2 3-3M4 13l2 2 3-3M4 20l2 2 3-3M12 7h8M12 14h8M12 21h8',
  };
  const AC_LIGNES = [['quoi', 'C’est quoi ?'], ['qui', 'Pour qui ?'], ['quand', 'Quand ?'], ['ou', 'Où ?'], ['comment', 'Comment ?'],
    ['pourquoi', 'Pourquoi ?'], ['valide', 'Comment c’est validé ?'], ['code', 'Le code'], ['ex', 'Exemple']];
  const R = { formations: new Array(54) }, L = [].concat(new Array(59).fill({ type: 'Article' }), new Array(31).fill({ type: 'SOP' }), new Array(11).fill({ type: 'Checklist' }));
    const nb = (n, un, plus) => n == null ? '' : n + ' ' + (n > 1 ? plus : un);
    const nT = t => L ? L.filter(x => x.type === t).length : null;
    const COLS = [
      { k: 'fo', nom: 'Formations', mot: 'Apprendre', coul: '#8D1D2C', n: nb(R ? (R.formations || []).length : null, 'formation', 'formations'), lien: '#/rfformations',
        quoi: 'Un parcours pour acquérir les compétences d’un poste.',
        qui: 'Chaque poste, de l’opérateur au franchisé : chaque formation dit pour quel poste elle est obligatoire ou recommandée.',
        quand: 'À une phase : avant l’ouverture, pendant les 6 semaines de lancement, puis en formation continue (recyclages).',
        ou: 'Ce portail, menu Formation : Formations, Postes, Compétences.',
        comment: 'Un formateur ou le business coach la donne ; elle renvoie aux articles du manuel et aux SOP à lire et à faire.',
        pourquoi: 'Pour que chacun sache faire son métier selon les standards du réseau.',
        valide: 'Quand ses compétences sont acquises, elles sont cochées au panel pour la personne.',
        code: 'FO-01, FO-02…', ex: ['FO-01 · Vendeur (initiale + recyclage)', 'href="#/rfformations/pre/FO-01"'] },
      { k: 'man', nom: 'Manuel opératoire', mot: 'Comprendre', coul: '#B0792A', n: nb(nT('Article'), 'article', 'articles'), lien: '#/rfclasseur',
        quoi: 'Les articles qui expliquent le pourquoi : la marque, les règles du réseau, les outils, les chiffres.',
        qui: 'Toute l’équipe ; certains articles sont réservés au franchisé et au responsable (cadenas or, PIN).',
        quand: 'Avant la première journée, puis à relire quand une règle change (nouvelle version).',
        ou: 'La tablette du magasin, onglet Manuel ; le portail, fiche de chaque formation.',
        comment: 'Il se lit partie par partie (« Partie 3 sur 9 »), avec une photo par partie et parfois un petit quiz.',
        pourquoi: 'Pour comprendre avant d’agir : on applique mieux une règle dont on connaît la raison.',
        valide: '« n sur N parties lues » ; le quiz, s’il y en a un.',
        code: '0.0.01, 0.0.02… (0 : le manuel, .0 : sans moment)', ex: ['0.0.14 · Les checklists du magasin : la structure', 'data-tbx="art-chk-01" data-tbx-titre="Les checklists du magasin : la structure"'] },
      { k: 'sop', nom: 'SOP', mot: 'Faire', coul: '#3f7a52', n: nb(nT('SOP'), 'SOP', 'SOP'), lien: '#/operationnel/ouverture',
        quoi: 'Le geste exact, étape par étape : comment on fait, ici, chez L’Atelier By.',
        qui: 'La personne du poste concerné (vente, cuisson, production, nettoyage).',
        quand: 'Au moment du geste : à l’ouverture (0.1), en journée (0.2), l’après-midi (0.3), à la fermeture (0.4).',
        ou: 'La tablette du poste, onglets Ouverture, Journée de travail, Fermeture ; en FR et en NL.',
        comment: 'Une étape à la fois, avec sa photo ; les points critiques en rouge ; un quiz à la fin.',
        pourquoi: 'Pour que le geste soit le même dans chaque magasin, quel que soit celui qui le fait.',
        valide: 'Le responsable l’a « vu faire » et valide avec son PIN ; toutes les SOP d’une compétence vues → compétence cochée au panel.',
        code: '0.1.01 (0 : SOP · 1 : ouverture · 01 : numéro)', ex: ['0.1.01 · Ouverture du magasin et des comptoirs', 'data-tbx="ouv-mag" data-tbx-titre="Ouverture du magasin et des comptoirs"'] },
      { k: 'chk', nom: 'Checklists', mot: 'Vérifier', coul: '#1f5f8b', n: '', lien: '../task/?vue=checklists',
        quoi: 'La liste des tâches à cocher chaque jour, à heure fixe.',
        qui: 'L’équipe du magasin, selon son poste (« Fait par ») ; le contrôleur note les photos.',
        quand: 'Chaque jour, ou chaque semaine, ou chaque mois, à l’heure de la checklist.',
        ou: 'L’application du magasin ; le suivi dans le dashboard magasin, onglet Tâches.',
        comment: 'On coche chaque tâche ; une tâche à photo n’est faite que si la photo est rendue.',
        pourquoi: 'Pour vérifier chaque jour que la SOP a bien été appliquée.',
        valide: 'Les photos sont notées de 1 à 5 ; sous 4, c’est une non-conformité à corriger.',
        code: '1.1.01 (1 : qui, de 1 Vente à 5 Gestion · 1 : quand · 01 : numéro)', ex: ['1.1.01 · Ouverture de la boutique', 'href="#/sop/chk-ac-01"'] },
    ];
  return { COLS, AC_ICO, AC_LIGNES };
})();
