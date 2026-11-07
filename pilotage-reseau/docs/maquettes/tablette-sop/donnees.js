/* Maquettes « Lire une SOP sur la tablette » (07/11/2026) — le contenu RÉEL de SOP-CUI-OUV-01 (Ouverture du poste et
   premières cuissons), photos des fours recadrées au format 4:3. Infos clés, contrôle rapide et « qui prévenir » sont
   sortis du fil des étapes : écran d'accueil, contrôle final, bouton permanent. */
window.SOP = {
 "titre": "Ouverture du poste et premières cuissons",
 "code": "SOP-CUI-OUV-01",
 "version": "v1.1",
 "infos": [
  [
   "Quand commencer",
   "À l’arrivée au poste, autour de 4 h."
  ],
  [
   "Document clé",
   "La liste de production active : commandes, restes, production réelle et écarts."
  ]
 ],
 "sections": [
  {
   "titre": "Ouvrir le poste et lancer les premières cuissons",
   "etapes": [
    {
     "n": "1",
     "t": "Commencer le service",
     "l": [
      "Désarmer l’alarme. Allumer les lumières. Mettre son tablier. Se pointer sur la pointeuse."
     ],
     "crit": false,
     "photos": []
    },
    {
     "n": "2",
     "t": "Allumer les équipements",
     "l": [
      "Allumer la climatisation du magasin avec le boîtier sur le mur, à côté de l’évier de la machine à café.",
      "Allumer les frigos comptoirs avec le « gros » bouton.",
      "Allumer les deux fours.",
      "Allumer le bain-marie sur 1,5."
     ],
     "crit": true,
     "photos": [
      "prechauffe.jpg"
     ]
    },
    {
     "n": "3",
     "t": "Vérifier la production du jour",
     "l": [
      "Prendre la liste de production active. Lire les commandes particulières avant de sortir ou cuire les produits."
     ],
     "crit": false,
     "photos": []
    },
    {
     "n": "4",
     "t": "Sortir et préparer le matériel",
     "l": [
      "Sortir du grand congélateur le chariot bâché de pains préparé la veille.",
      "Monter le pistolet à dorer et remplir son réservoir d’œufs liquides."
     ],
     "crit": false,
     "photos": []
    },
    {
     "n": "5",
     "t": "Lancer les programmes dans le bon four",
     "l": [
      "Utiliser les programmes enregistrés sans modifier leurs paramètres."
     ],
     "crit": true,
     "photos": []
    },
    {
     "n": "5.1",
     "t": "Fours et programmes",
     "l": [
      "Pains et pains spéciaux : four gauche, programme « grands pains ».",
      "Baguettes et pistolets : four droit, programme « baguettes »."
     ],
     "crit": true,
     "photos": [
      "grands-pains.jpg",
      "baguettes.jpg"
     ]
    },
    {
     "n": "5.2",
     "t": "Repère",
     "l": [
      "Pour ces familles, la fin du programme signifie que les produits sont cuits."
     ],
     "crit": true,
     "photos": [
      "minuteur.jpg"
     ]
    }
   ]
  },
  {
   "titre": "Terminer les pains et avancer jusqu’à 6 h",
   "etapes": [
    {
     "n": "5.3",
     "t": "Priorité",
     "l": [
      "Une fin de cuisson ou une sonnerie de pousse passe avant une nouvelle tâche."
     ],
     "crit": true,
     "photos": []
    },
    {
     "n": "6",
     "t": "Ranger les produits cuits sur le chariot double",
     "l": [
      "Placer les pains et pains spéciaux d’un côté.",
      "Placer les baguettes et pistolets de l’autre côté.",
      "Le week-end, placer aussi les pains spéciaux du côté baguettes et pistolets."
     ],
     "crit": false,
     "photos": []
    },
    {
     "n": "7",
     "t": "Cuire les pains de la veille",
     "l": [
      "Après la cuisson de tous les grands pains, les charger directement dans le même four. Ne pas relancer de programme. Sélectionner « ajouter du temps » : 4 min et 20 % d’humidité. Cette recuisson ne vaut que pour la catégorie pain."
     ],
     "crit": true,
     "photos": []
    },
    {
     "n": "8",
     "t": "Déplacer le chariot vers le magasin",
     "l": [
      "Attendre que tous les pains, pains spéciaux, baguettes et pistolets soient cuits. Garder les pains de la veille identifiables et préciser oralement leur emplacement à la vente dès son arrivée."
     ],
     "crit": false,
     "photos": []
    },
    {
     "n": "9",
     "t": "Avancer pendant les cycles",
     "l": [
      "Compter les restes encore vendables.",
      "Déduire la production à réaliser à partir de la liste active.",
      "Noter la production réelle et les écarts sur la liste.",
      "Sortir les éclairs et, lorsque la liste le prévoit, les choux.",
      "Sortir les tartes froides prévues sur un chariot à l’arrière.",
      "Contrôler les tartes et quiches de la veille avant présentation.",
      "Préparer les quiches et tartes à cuire prévues sur la liste."
     ],
     "crit": false,
     "photos": []
    },
    {
     "n": "10",
     "t": "Traiter immédiatement les sonneries",
     "l": [
      "4 h 20 et 4 h 30 : pousses de viennoiseries.",
      "5 h : pousse des sandwiches.",
      "Terminer uniquement l’action courte engagée, puis traiter la pousse."
     ],
     "crit": true,
     "photos": []
    },
    {
     "n": "11",
     "t": "Encoder les pertes avant l’ouverture",
     "l": [
      "Regrouper les pertes et les encoder en une fois à la caisse avant 6 h, selon l’Afvalregistratie de la SOP fermeture."
     ],
     "crit": false,
     "photos": []
    }
   ]
  }
 ],
 "controle": [
  {
   "t": "Liste active et commandes vérifiées.",
   "crit": false
  },
  {
   "t": "Climatisation, frigos comptoirs, fours et bain-marie allumés.",
   "crit": true
  },
  {
   "t": "Programmes « grands pains » et « baguettes » lancés dans le bon four.",
   "crit": true
  },
  {
   "t": "Restes comptés ; production réelle et écarts notés.",
   "crit": false
  },
  {
   "t": "Pertes regroupées et encodées avant 6 h.",
   "crit": false
  },
  {
   "t": "Pains de la veille cuits, identifiables et signalés à la vente.",
   "crit": false
  },
  {
   "t": "Sonneries de 4 h 20, 4 h 30 et 5 h prises en charge.",
   "crit": true
  }
 ],
 "contacts": [
  [
   "J’appelle",
   "Programme absent ou différent ; four ou pousse qui ne démarre pas ; rupture empêchant une commande."
  ],
  [
   "J’écris dans Shyfter · production",
   "Climatisation ou frigos comptoirs qui ne s’allument pas."
  ],
  [
   "En cas de doute qualité",
   "Demander l’avis du gérant ou de la personne expérimentée présente. Si personne n’est disponible, mettre le produit de côté, ne pas le présenter et écrire dans Shyfter · production."
  ]
 ],
 "critiques": [
  "Utiliser les programmes enregistrés sans modifier leurs paramètres : pains et pains spéciaux au four gauche (« grands pains »), baguettes et pistolets au four droit (« baguettes »).",
  "Une fin de cuisson ou une sonnerie de pousse (4 h 20, 4 h 30, 5 h) passe avant une nouvelle tâche.",
  "Pains de la veille : dans le même four après les grands pains, sans relancer de programme, « ajouter du temps » 4 min et 20 % d’humidité — catégorie pain uniquement.",
  "Pertes regroupées et encodées en une fois à la caisse avant 6 h.",
  "Produit douteux : demander un avis ; si personne n’est disponible, le mettre de côté, ne pas le présenter et écrire dans Shyfter · production."
 ]
};
