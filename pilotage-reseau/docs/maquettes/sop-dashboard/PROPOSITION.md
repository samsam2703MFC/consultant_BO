# SOP « Dashboard magasin » — proposition (07/11/2026)

Demande : un SOP complet pour **comprendre et utiliser le dashboard magasin**
(`…/consulant_bo/dashboard/?shop=4&vue=ops&date=2026-11-07`), rangé dans la bonne formation — en
**pré-ouverture** (la compréhension) et en **formation continue** (l'utilisation) —, avec les captures
nécessaires, une maquette dans le module Formation, puis l'encodage après validation.

**Statut : validé et encodé le 07/11/2026** (v1.0 publiées, NL « à relire »). Ce qui suit est la proposition telle que validée. La maquette est la vraie page du module Formation (portail et
tablette), servie par le serveur, avec le contenu ci-dessous injecté dans le navigateur seulement.

## 1. Où le ranger

| | Choix | Pourquoi |
|---|---|---|
| Formation | **FO-05 — Formation Pilotage Commercial (Franchisé / Responsable)** | Seule formation du référentiel qui porte la compétence **C-PILOTAGE.KPI « Lecture tableau de bord FB »**. Elle est déjà dans les trois phases : Pré-ouverture, Ouverture, Formation continue. Obligatoire pour P7 à P12, recommandée pour P4 à P6. |
| Sous-catégorie du manuel | **D7.1 Gestion & Finance › Pilotage de l'activité** | « Tableau de bord FB, CA, ticket moyen, articles/ticket, KPI hebdo/mensuel » ; ses compétences et sa formation (FO-05) sont exactement celles-ci. |
| Pré-ouverture → **comprendre** | **ART-PIL-01 · Article du manuel « Comprendre le dashboard magasin »** | Un article se lit (« Partie n sur N »), sans moment ni geste : la visite guidée de chaque onglet, d'où viennent les chiffres, ce que disent les couleurs. 28 parties, 28 captures, 5 questions. |
| Formation continue → **utiliser** | **SOP-PIL-01 · SOP « Analyser le dashboard magasin : la routine »** | Un geste qui se refait : chaque matin, en journée, le soir, chaque lundi, chaque début de mois, chaque trimestre, et « Qui prévenir ». 28 étapes, 4 questions. Moment de la tablette : **Ouverture** (la lecture du matin). |

Les deux documents sont rattachés à FO-05 par leur classement : ils apparaissent dans la fiche FO-05, bloc
« Dans le manuel », quelle que soit la phase ouverte (capture `ecrans/01` et `ecrans/02`).

## 2. Ce que montre la maquette (`ecrans/`, ouvrir `index.html`)

| Écran | Ce qu'on y voit |
|---|---|
| `01-portail-fo05-preouverture` | Portail › Formations › Pré-ouverture › FO-05 : « Dans le manuel » liste l'article et la SOP (« à valider »), avec « Vue tablette » et « Modifier » |
| `02-portail-fo05-continue` | La même fiche en Formation continue |
| `03-portail-vue-tablette` | « Vue tablette » de l'article depuis la fiche FO-05 (la tablette elle-même dans son cadre) |
| `04-portail-fiche-sop` | La fiche de la SOP dans le portail (étapes, photos, classement) |
| `10` à `13` | La tablette : l'article, partie 1 ; la partie 9 (P&L court, point critique) ; la partie 21 (six leviers) ; « Toutes les parties » |
| `20` à `23` | La tablette : la SOP, étape 1.4 (vitrine, point critique) ; 4.7 (décider) ; « Qui prévenir » ; « Toutes les étapes » |
| `30`, `31` | L'accueil de la tablette : la SOP dans l'onglet **Ouverture** (Vente), l'article dans l'onglet **Manuel** |

Les captures du dashboard (`../../data/formation/dashboard/photos/`, 29 images 1600 × 1200, le cadre 4:3 de la
tablette) ont été prises sur le magasin 4 (Halle) le 07/11/2026, le bloc décrit entouré en bordeaux.

## 3. À valider

1. **Le rangement** : FO-05, D7.1, un article en pré-ouverture + une SOP en continue (et pas une seule SOP).
2. **Le moment de la SOP** sur la tablette : Ouverture (proposé) ou « au besoin ».
3. **Les postes** : article P4, P7–P12 ; SOP P4, P7, P8, P9, P11, P12.
4. **Le contenu** (section 5) : tournures, durées de la routine (10 min / 3 min / 5 min / 30 min / 1 h), seuils
   d'action proposés (projection < 90 % à 11 h ; atteinte < 80 % ou trois soirs négatifs → consultant).
5. **Le néerlandais** : il sera écrit à l'encodage (le serveur l'exige pour publier une nouvelle version) et
   marqué « à relire », comme les autres SOP.

Après validation, l'encodage passe par l'API du cockpit (`tools/formation/encoder_sop.py`, voir `ROUTINE.md`) :
photos, création v1.0 « à valider », classement, puis publication.

## 4. Constats faits en préparant le SOP (hors SOP, à trancher)

- **Tiroir « Valeur »** : sur l'onglet **Opérationnel**, toucher « Valeur ▾ » bascule la flèche mais n'ouvre
  rien — `rendValeur()` n'est appelé que pour Semaine, Mois, Trimestre et Année (`dashboard.js`, `rendre()`).
  La capture de la valeur est donc prise sur l'onglet Semaine.
- **Seuil du coût matière — corrigé à votre demande** : il était lu dans la table `kpi` (32 % sur ordinateur) et
  écrit en dur sur le téléphone (35 %). Il vient désormais de **Budget › Paramètres** (`#/budget/parametres`,
  réglage `budgetCharges`) : la catégorie que le palier « Marge brute » retranche, *Matière première & Emballage*,
  somme des taux budget, ou des taux théoriques tant que la colonne budget est à 0. **Avec le réglage du
  07/11/2026, cela donne 40,0 % (38 + 2, théoriques)**, et non plus 32 % : vérifiez que c'est bien la valeur
  voulue (sinon, posez le taux budget dans Budget › Paramètres). Une seule fonction serveur `seuilsCharges()`,
  lue par le jour, la période et le téléphone. Main-d'œuvre (33 %) et frais généraux (13,5 %) restent ceux de la
  table `kpi`. Les captures montrent encore 32 % : elles seront refaites après la mise en ligne (étape 5 de la
  routine).
- **Valeur du magasin** : le `README` décrit encore « 18 derniers mois clos » ; le code calcule sur **730 jours**
  (`VALO_JOURS`). Le SOP suit le code.
- **https** : `https://185.180.206.46/` répond toujours « connexion réinitialisée » ; tout a été lu en http.

## 5. Le contenu complet

## ART-PIL-01 — Comprendre le dashboard magasin (Article du manuel)

- **Objectif** : Savoir lire chaque onglet du dashboard magasin : d’où vient chaque chiffre, à quoi il se compare, ce que disent les couleurs, et où regarder pour répondre à une question sur le magasin.
- **Pour** : Candidat franchisé, franchisé, manager, adjoint manager et chef de section vente (P4, P7 à P12). À lire en pré-ouverture, dans la formation FO-05, avant la première journée.
- **Durée** : 45 min de lecture, puis 30 min sur le dashboard de son magasin
- **Moment (tablette)** : — (onglet Manuel)
- **Classement** : sous-catégorie D7.1 · formation FO-05 · phases preouverture · postes P4, P7, P8, P9, P10, P11, P12 · compétences C-PILOTAGE.KPI, C-PILOTAGE.Vente

### Avant de commencer

**1. Ouvrir le dashboard** · capture `dash-entete.jpg`

- Adresse : le cockpit › Dashboard magasin, ou le lien reçu pour son magasin (…/consulant_bo/dashboard/?shop=…).
- L’onglet qui s’ouvre d’office : Opérationnel, la journée en cours.
- « ↻ Relire » relit les chiffres ; la journée en cours se relit seule toutes les dix minutes.
- Tout est en lecture seule : le dashboard montre, il n’écrit rien. On corrige à la source (caisse, panel, cockpit).

**2. Les sept onglets** · capture `dash-frise.jpg`

- Opérationnel : aujourd’hui, heure par heure.
- Semaine et Mois : le résultat de la période, jour par jour.
- Trimestre et Année : le cap, face au plan de développement.
- Promotions : les offres en caisse et ce qu’elles rapportent.
- Primes : où en est chaque vendeuse, prime par prime.
- Sous les onglets, la frise : une case par jour (ou par mois, par trimestre) ; ‹ et › changent de période, un toucher ouvre la case.

**3. Un chiffre ne se lit jamais seul** · capture `dash-cal-semaine.jpg`

- Chaque chiffre est posé à côté d’une référence : l’objectif, le même jour de la semaine dernière, la médiane du réseau ou l’an dernier.
- Bordeaux ou rouge : en retard sur la référence ou au-delà d’un seuil.
- Orange : à surveiller. Vert : en ordre. Doré : record ou objectif dépassé.
- Hachuré : la journée ou la période est en cours, pas encore jugée.

**4. La valeur du magasin** · capture `dash-valeur.jpg`

- En haut à droite, en doré : ce que vaut le fonds de commerce au rythme actuel.
- Le calcul : le chiffre d’affaires des deux dernières années (730 jours, ou les jours vécus si le magasin est plus jeune), ramené à un an, divisé par 6 — deux mois de chiffre d’affaires.
- Le mois en cours est écarté : incomplet, il tirerait la valeur vers le bas.
- Un toucher sur « Valeur ▾ » ouvre le détail : la formule en toutes lettres et la valeur année par année.

### Opérationnel — la journée

**5. Ta place dans le réseau** · capture `dash-reseau.jpg`

- Cinq chiffres face aux autres magasins ouverts : chiffre d’affaires, clients, panier moyen (hors clients pro), marge brute, B2B.
- Le point bordeaux, c’est toi ; les points gris, les autres ; le trait noir, la médiane.
- Sous chaque chiffre : l’écart à la médiane et le chiffre du premier.
- Le rang (2e / 4) se lit en vert quand tu es premier.

**6. L’objectif comptoir du jour** · capture `dash-objectif.jpg`

- L’objectif du jour vient du budget du mois, réparti sur les jours selon la pondération du réseau.
- Barre bordeaux : aujourd’hui à l’heure qu’il est. Barre bleue : le même jour la semaine dernière, à la même heure.
- La partie hachurée mène à la projection de fin de journée au rythme actuel.
- Le B2B (clients pro) a sa propre carte, dessous : il ne se mélange pas au comptoir.
- « Commandes du jour » : les commandes comptoir et webshop à préparer ou à retirer.

**7. Heure par heure** · capture `dash-heures.jpg`

- La courbe du chiffre d’affaires cumulé d’aujourd’hui, face à celle du même jour la semaine dernière.
- Le trait pointillé vertical : maintenant. La ligne de l’objectif comptoir en haut.
- Bascule CA · Clients · Panier : pour voir si le retard vient du nombre de clients ou du panier.
- « Comptoir et pro séparés » ou « Combinés » : deux lectures du même chiffre.

**8. Le duel, chiffre par chiffre** · capture `dash-duel.jpg`

- Trois lignes : chiffre d’affaires, clients, panier moyen.
- Quatre colonnes : aujourd’hui à l’heure qu’il est ; le même jour la semaine dernière à la même heure (et l’écart en %) ; ce même jour sur toute la journée ; la référence (moyenne des six derniers mêmes jours).
- Moins de clients : c’est le trafic (vitrine, trottoir, offre). Panier plus bas : c’est la vente additionnelle.

**9. Le P&L court de la journée** — **point critique** · capture `dash-pl.jpg`

- Chiffre d’affaires − coût matière = marge brute.
- Marge brute − invendus et poubelle − main-d’œuvre − frais généraux = résultat.
- Le seuil du coût matière est le taux matière du réseau posé au cockpit, Budget › Paramètres (catégorie Matière première & Emballage) : on le change là, le dashboard suit.
- Main-d’œuvre et frais généraux : les seuils de la Table KPI (33 % et 13,5 % du chiffre d’affaires).
- La main-d’œuvre est celle du planning du jour ; les heures du franchisé sont indiquées à part.
- Le matin, le résultat est toujours négatif : les coûts de toute la journée sont posés, le chiffre d’affaires ne l’est pas encore. Il se lit le soir.

**10. Ventes par catégorie** · capture `dash-categories.jpg`

- Groupe › catégorie › produit : un toucher sur ▸ déplie.
- Pour chaque ligne : chiffre d’affaires, part du total, marge brute, taux de marge, coefficient (prix de vente ÷ coût).
- La couleur du taux : sous 40 %, 40–50, 50–60, 60–70, 70 % et plus en or ; gris = coût matière inconnu (recette à compléter au panel).
- Autres lectures : Treemap (le poids d’un coup d’œil), Promotions, Portions.

**11. La journée** · capture `dash-journee.jpg`

- Une frise de 4 h à 20 h : l’équipe (le planning), les cuissons du four, la vitrine, les commandes.
- « À valider » sur une cuisson : elle n’a pas encore été confirmée.
- À droite, les ventes au comptoir heure par heure face à la moyenne : vert au-dessus, ambre sous la moyenne, bordeaux l’heure en cours.
- « L’équipe du jour › » ouvre le détail du planning.

**12. La vitrine** — **point critique** · capture `dash-vitrine.jpg`

- Ce qui va manquer d’ici la fermeture, au rythme des ventes, et les euros de ventes à sauver.
- Trois familles : Short life (frais du jour), Medium life, Long life.
- « +N à cuire » : ce qu’il faut lancer à la prochaine cuisson ; l’heure de la prochaine cuisson est en haut à droite.
- Rouge : vide alors que des clients le demandent.

**13. Les contrôles en photo** · capture `dash-controles.jpg`

- Les photos des contrôles du jour (ouverture, comptoirs, étiquetage) déposées par l’équipe.
- Trois filtres : Tout, À contrôler (déposée, pas encore notée), Non rendues (pas de photo).
- Une photo s’ouvre en grand d’un toucher.

**14. Le stock vivant** · capture `dash-stock.jpg`

- L’inventaire matière du magasin, relu avec la page.
- Une référence est en alerte quand son stock est négatif (écart de caisse ou de comptage) ou sous le minimum journalier.
- Zéro alerte n’est une bonne nouvelle que si l’inventaire est récent : au-delà de sept jours sans comptage, le bandeau passe en orange.
- « M’avertir » : une notification sur le téléphone dès qu’une référence passe sous son minimum (page en https seulement).

### Semaine et Mois — le résultat

**15. Les tâches de la période** · capture `dash-taches.jpg`

- Faites, non faites, et les jours où moins de 40 % des tâches ont été faites.
- Le fil des jours : une case par jour — vert 80 % et plus, vert clair 40 % et plus, rose sous 40 %, rouge rien de fait.
- « détail ▾ » ouvre les tâches jour par jour ; les tâches bloquantes se lisent sur le jour.

**16. Le résultat de la période** · capture `dash-resultat.jpg`

- CA de la période et objectif (budget validé), attendu à ce jour.
- Atteinte : la part de l’attendu à ce jour, et les clients manquants.
- Projection : où la période finira au rythme actuel. Reste à faire : par jour restant.
- Par jour ouvert, marge brute (et coût matière face au seuil de Budget › Paramètres), résultat net, record de la période.

**17. Commandes et canaux** · capture `dash-canaux.jpg`

- Comptoir (tickets de caisse), clients pro (tickets des sociétés), click & collect (webshop retiré en boutique), livraison (webshop livré).
- Pour chacun : le chiffre, la part du CA, le nombre de tickets ou de commandes, le panier.
- Le tableau des commandes : jour par jour, ce qui est à préparer et ce qui est déjà pris pour les jours à venir.

**18. Invendus et poubelle** — **point critique** · capture `dash-invendus.jpg`

- Les pièces jetées déclarées en caisse : leur coût de production se retranche du résultat.
- Valeur de vente perdue : ce qu’elles auraient rapporté au prix de vente.
- Motifs (fin de journée, casse…) et « Les saisies une par une » : l’heure, l’opérateur, la quantité.
- Rien de déclaré ne veut pas dire rien de jeté : une poubelle non saisie fausse le résultat.

**19. Le CA de chaque jour** · capture `dash-cal-mois.jpg`

- Le calendrier de la période : en grand, le chiffre d’affaires du jour.
- Couleur et jauge face à l’objectif du jour : 110 % et plus, 100–110, 90–100, 75–90, sous 75 %.
- Le trait de la jauge : 100 % de l’objectif. Hachuré : le jour en cours.
- En Mois, à côté : le profil des jours (le jour de la semaine le plus faible).

**20. Douze semaines et les heures** · capture `dash-heures-semaine.jpg`

- Les douze dernières semaines : clients, panier moyen ou articles par client, face à l’an dernier.
- Les heures : l’heure avec le plus de clients, le plus de chiffre, la plus rentable, la moins rentable.
- La journée type : pour chaque heure, ventes, coût matière, rémunération et marge nette (vert = l’heure gagne, rouge = elle perd).
- C’est la base pour ajuster le planning et les cuissons.

**21. Les six leviers (onglet Mois)** · capture `dash-leviers.jpg`

- Trafic (clients par jour), récurrence (clients qui reviennent), expérience client (note Google), food cost, labour cost, overhead cost.
- Chaque levier face à l’an dernier et au réseau ; pastille selon les seuils de la Table KPI : sous l’alerte, entre alerte et critique, au-delà.
- « L’évolution de l’année » montre chaque levier mois par mois.

**22. La rentabilité jour par jour (onglet Mois)** · capture `dash-rentab.jpg`

- Le P&L du mois, puis le résultat net de chaque jour en une ligne de cases.
- Une case : un jour ; sa couleur dit s’il a gagné ou perdu de l’argent.
- Le profil des jours dit quel jour de la semaine porte le mois et lequel le freine.

### Trimestre et Année — le cap

**23. Le trimestre** · capture `dash-annotations.jpg`

- Les quatre trimestres de l’année face à l’objectif du plan de développement.
- Le trimestre choisi : objectif, réalisé, attendu à ce jour, écart, clients manquants, marge nette.
- Les annotations du trimestre : celles du franchisé, du consultant et de la marque, et les actions posées.
- Le rituel du trimestre : cinq étapes, à valider avec le consultant.

**24. L’année** · capture `dash-annee.jpg`

- L’objectif de l’année (engagement du franchisé), le réalisé, l’attendu, l’écart et la projection de fin d’année.
- La rampe à cinq ans : l’année 1, 2, 3 puis la maturité.
- Trimestre par trimestre, le trafic face à l’an dernier, puis mois par mois.

**25. La heatmap des mois** · capture `dash-heatmap.jpg`

- Une case par mois : l’atteinte du budget, ou le CA du mois.
- Rouge sous 80 %, orange 80–95 %, vert 95–105 %, doré au-delà de 105 %.
- Le tableau dessous donne, mois par mois : CA, budget, clients, panier, food cost, labour, overhead, marge nette.

### Promotions, Primes et téléphone

**26. Promotions** · capture `dash-promos.jpg`

- Lecture seule : les promotions se créent et se modifient au cockpit (Promotions › Édition).
- « Où les promotions sont utilisées » : combien de fois, au comptoir ou au webshop, sur 7 ou 30 jours.
- Le coefficient du lot : ce que rapporte la vente avec la promotion ; en rouge quand il est bas.
- Dessous : les promotions actives, à venir, en brouillon, terminées.

**27. Primes** · capture `dash-primes.jpg`

- Où en sont les vendeuses : les primes du magasin (toute l’équipe gagne ensemble) et les primes individuelles.
- Pour chacune : la cible, où en est chaque personne, « en tête » ou « gagnée ».
- Éligibles et « sous le minimum d’heures » : qui peut toucher la prime ce mois.
- Lecture seule : les primes se posent et se règlent au cockpit.

**28. Au téléphone** · capture `dash-mobile.jpg`

- Sous 560 px de large, la même page se lit au pouce : Opérationnel, Exploitation, Contrôle, Semaine, Primes, en bas de l’écran.
- Le chiffre de la période en grand, l’alerte des tâches bloquantes juste dessous, puis des cartes.
- Le mois, le trimestre et l’année se lisent sur ordinateur ou tablette.

### Points critiques

- Un chiffre se lit toujours face à sa référence (objectif, même jour la semaine dernière, médiane du réseau, an dernier), jamais seul.
- Le résultat du P&L court se lit le soir : le matin, les coûts de toute la journée sont déjà posés.
- Le dashboard est en lecture seule : une erreur se corrige à la source (caisse, panel, cockpit), puis on relit.
- Zéro alerte de stock ne rassure que si l’inventaire a moins de sept jours.

### Quiz (bonne réponse en gras)

1. À 9 h, le P&L court affiche un résultat négatif. Que faut-il en conclure ?
   - La journée sera perdante, il faut réduire l’équipe
   - **Rien encore : les coûts de toute la journée sont posés, le chiffre d’affaires ne l’est pas ; le résultat se lit le soir**
   - Le dashboard est en panne
2. Comment se calcule la valeur du magasin ?
   - **Le CA des deux dernières années ramené à un an, divisé par 6 (deux mois de chiffre d’affaires)**
   - Le CA du mois en cours × 12
   - Le résultat net de l’année × 3
3. Dans « Le duel », le panier moyen est en hausse mais le CA en baisse face à la semaine dernière. Où chercher ?
   - La vente additionnelle
   - Le coût matière
   - **Le nombre de clients : c’est le trafic qui manque**
4. La heatmap de l’année montre une case orange. Que veut-elle dire ?
   - Le mois a dépassé son budget
   - **Le mois a atteint entre 80 et 95 % de son budget**
   - Le mois n’a pas de budget
5. Le bandeau du stock dit « inventaire non recompté depuis 12 jours » en orange. Que faire ?
   - Rien, il n’y a pas d’alerte
   - Modifier le stock dans le dashboard
   - **Planifier un comptage : sans inventaire récent, l’absence d’alerte ne prouve rien**

---

## SOP-PIL-01 — Analyser le dashboard magasin : la routine (SOP)

- **Objectif** : Savoir chaque jour, chaque semaine et chaque mois où en est le magasin, et en tirer une action notée, chiffres à l’appui.
- **Pour** : Le responsable du magasin : franchisé, manager, adjoint manager ou chef de section vente (P4, P7, P8, P9, P11, P12). Formation continue FO-05.
- **Durée** : 10 min chaque matin · 2 × 3 min en journée · 5 min le soir · 30 min le lundi · 1 h le premier jour ouvré du mois
- **Moment (tablette)** : ouverture
- **Classement** : sous-catégorie D7.1 · formation FO-05 · phases operationnel, formation · postes P4, P7, P8, P9, P11, P12 · compétences C-PILOTAGE.KPI, C-PILOTAGE.Vente, C-PILOTAGE.Franchise

### Chaque matin, à l’ouverture — 10 min

**1.1. Ouvrir le dashboard** · capture `dash-entete.jpg`

- Ouvrir le dashboard du magasin sur l’onglet Opérationnel.
- Toucher « ↻ Relire » pour partir de chiffres frais.

**1.2. Relire la veille** · capture `dash-frise.jpg`

- Dans la frise, toucher la case d’hier.
- Noter son % de l’objectif, son résultat (P&L court) et sa poubelle.
- Une case orange ou rouge : chercher pourquoi dans « Le duel » (clients ou panier).

**1.3. Contrôles en photo** · capture `dash-controles.jpg`

- Revenir sur aujourd’hui : tous les contrôles d’ouverture sont-ils rendus ?
- « Non rendues » : faire déposer la photo maintenant.
- « À contrôler » : les regarder et corriger sur place ce qui ne va pas.

**1.4. Vitrine et prochaine cuisson** — **point critique** · capture `dash-vitrine.jpg`

- Lire combien de produits vont manquer et les euros à sauver.
- Reporter les « +N à cuire » sur la prochaine cuisson.
- Un produit vide alors que des clients le demandent passe en premier.

**1.5. Stock** · capture `dash-stock.jpg`

- Une alerte : vérifier la référence (comptage, réception, écart de caisse).
- Bandeau orange « non recompté depuis N jours » : planifier un comptage dans la semaine.

**1.6. L’équipe du jour** · capture `dash-journee.jpg`

- Dans « La journée », vérifier le planning face aux heures fortes de la veille.
- Une heure creuse très chargée en personnel : l’occuper (mise en place, nettoyage, démarchage) ou ajuster le planning.

### En journée — 11 h et 15 h, 3 min

**2.1. Objectif comptoir** · capture `dash-objectif.jpg`

- Lire la projection de fin de journée face à l’objectif comptoir.
- Projection sous 90 % de l’objectif à 11 h : décider une action pour l’après-midi et la dire à l’équipe.

**2.2. Clients ou panier ?** · capture `dash-duel.jpg`

- Dans « Le duel », comparer aux mêmes heures de la semaine dernière.
- Moins de clients : vitrine pleine, porte et trottoir, offre du moment.
- Panier plus bas : rappeler la vente additionnelle (boisson, viennoiserie, formule).

**2.3. Heure par heure** · capture `dash-heures.jpg`

- Regarder si la courbe d’aujourd’hui rattrape celle de la semaine dernière.
- Vérifier la vitrine avant chaque cuisson (Midi, Après-midi).

### Le soir, à la fermeture — 5 min

**3.1. Le résultat de la journée** · capture `dash-pl.jpg`

- Lire le P&L court : chiffre d’affaires, marge brute, résultat.
- Coût matière au-dessus de son seuil (celui de Budget › Paramètres) ou main-d’œuvre au-dessus du sien : noter la cause probable.

**3.2. La poubelle est déclarée** — **point critique** · capture `dash-pl.jpg`

- « Invendus et poubelle » doit montrer les pièces jetées du jour.
- « Rien déclaré au panel » alors qu’on a jeté : faire saisir les invendus en caisse avant de partir.

**3.3. Les tâches du jour** · capture `dash-taches.jpg`

- Vérifier qu’aucune tâche bloquante ne reste ouverte ; sinon, la confier pour demain matin.

### Chaque lundi — la semaine écoulée, 30 min

**4.1. Ouvrir la semaine passée** · capture `dash-cal-semaine.jpg`

- Onglet Semaine, puis ‹ pour revenir à la semaine écoulée.

**4.2. Le résultat** · capture `dash-resultat.jpg`

- Noter : CA, atteinte de l’objectif, résultat net, record.
- Atteinte sous 90 % : identifier les jours rouges dans « Le CA de chaque jour ».

**4.3. Face au réseau** · capture `dash-reseau.jpg`

- Lire son rang et l’écart à la médiane sur le CA, les clients, le panier et l’atteinte.
- Le chiffre où l’on est le plus loin de la médiane devient la priorité de la semaine.

**4.4. Tâches et contrôles** · capture `dash-taches.jpg`

- Lire le % de tâches faites et les jours sous 40 %.
- Un jour sous 40 % : voir avec l’équipe de ce jour-là ce qui a bloqué.

**4.5. Invendus** · capture `dash-invendus.jpg`

- Lire les pièces jetées, le coût de production et la valeur de vente perdue.
- Les trois références les plus jetées : baisser leur production ou changer leur heure de cuisson.

**4.6. Les heures et le planning** · capture `dash-heures-semaine.jpg`

- Lire l’heure la plus rentable et la moins rentable, et la journée type.
- Une heure en rouge (marge nette négative) plusieurs jours : ajuster le planning de la semaine suivante.

**4.7. Décider** — **point critique** · capture `dash-douze.jpg`

- Choisir une à trois actions pour la semaine, chacune avec un chiffre à faire bouger.
- Les écrire dans le plan d’action du magasin et les annoncer au briefing d’équipe.
- Le lundi suivant, relire si le chiffre a bougé.

### Le premier jour ouvré du mois — le mois clos, 1 h

**5.1. Les six leviers** · capture `dash-leviers.jpg`

- Onglet Mois, puis ‹ pour revenir au mois clos.
- Traiter d’abord les pastilles au-delà du seuil critique (food cost, labour cost, overhead cost, trafic, récurrence, note Google).

**5.2. Le résultat du mois** · capture `dash-rentab.jpg`

- Noter : CA face au budget, atteinte, marge brute, résultat net.
- Lire la rentabilité jour par jour et le profil des jours : quel jour freine le mois ?

**5.3. Le CA de chaque jour** · capture `dash-cal-mois.jpg`

- Repérer les jours sous 75 % de l’objectif et chercher la cause (météo, rupture, équipe, travaux).

**5.4. Promotions et primes** · capture `dash-promos.jpg`

- Promotions : garder celles dont le coefficient tient, proposer au cockpit d’arrêter les autres.
- Primes : faire le point avec chaque vendeuse sur ce qui lui reste à faire.

**5.5. Préparer la visite du consultant** · capture `dash-valeur.jpg`

- Rassembler les chiffres du mois, les actions de chaque lundi et leur effet.
- Les apporter à la visite ou au point mensuel avec le consultant.

### Chaque trimestre — le cap

**6.1. Le trimestre** · capture `dash-annotations.jpg`

- Onglet Trimestre : lire l’écart au plan de développement et les clients manquants.
- Écrire l’annotation du franchisé et suivre le rituel du trimestre avec le consultant.

**6.2. L’année** · capture `dash-heatmap.jpg`

- Onglet Année : lire la projection de fin d’année face à l’objectif et la heatmap des mois.
- Un écart qui se creuse deux trimestres de suite : revoir le plan avec le consultant.

### Qui prévenir

**7.1. Un chiffre manifestement faux**

- 0 € alors que la caisse a tourné, coût matière inconnu, magasin absent du réseau : prévenir le consultant, avec une capture.
- Ne pas corriger « de tête » : la correction se fait à la source (caisse, panel, cockpit).

**7.2. Une dérive qui dure**

- Résultat négatif trois soirs de suite, ou atteinte de la semaine sous 80 % : prévenir le consultant (business coach).
- Stock négatif sur une référence : prévenir le responsable de la production et recompter.

### Points critiques

- Chaque lecture finit par une action notée ; une lecture sans action ne sert à rien.
- Le résultat du jour se lit le soir, jamais le matin.
- Les invendus doivent être déclarés en caisse chaque soir, sinon le résultat est faux.
- Le dashboard est en lecture seule : on corrige à la source, puis on relit.

### Quiz (bonne réponse en gras)

1. À 11 h, la projection de fin de journée est à 82 % de l’objectif comptoir. Que faire ?
   - Attendre le soir pour voir
   - Baisser l’objectif
   - **Décider une action pour l’après-midi (vitrine, offre, vente additionnelle) et la dire à l’équipe**
2. Le soir, « Invendus et poubelle » affiche « rien déclaré au panel » alors que l’équipe a jeté des pièces. Que faire ?
   - Rien, ce n’est pas grave
   - **Faire saisir les invendus en caisse avant de partir**
   - Retirer le montant du résultat de tête
3. Le lundi, quelle est la priorité de la semaine ?
   - **Le chiffre où le magasin est le plus loin de la médiane du réseau, avec une à trois actions notées**
   - Tous les chiffres à la fois
   - Le chiffre le plus haut
4. Le résultat est négatif trois soirs de suite. Qui prévenir ?
   - Personne
   - **Le consultant (business coach)**
   - Le fournisseur

---

