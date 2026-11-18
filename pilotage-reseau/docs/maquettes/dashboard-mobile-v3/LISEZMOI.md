# Le dashboard magasin au téléphone — refonte de toutes les vues

**Statut : proposée le 18/11/2026.** Les noms des onglets restent ceux d'aujourd'hui (Sam, 18/11/2026) : Opérationnel,
Exploitation, Contrôle, Tâches, Semaine, Primes. Les maquettes disent encore « Jour » et « Chiffres ».

Le ruban des canaux (maquette C de `../comptoir-webshop/`) est codé. Le reste n'est pas encore codé.

Demande du 18/11/2026 : « retravailler tout le dashboard en vue mobile », puis « toutes les vues mobiles de tout le
dashboard ». Point de départ : la capture de Halle du mercredi 18/11 à 17:43, et le comptoir à distinguer du webshop
(voir `../comptoir-webshop/`).

Écran de référence : **390 × 844** (iPhone 15). Ces maquettes succèdent à `../dashboard-mobile/` et
`../dashboard-mobile-v2/` ; elles reprennent le contenu des six onglets en ligne au 18/11/2026.

## Ce que la capture montre et que la refonte corrige

1. **La barre d'état recouvre l'en-tête.** L'heure 5:43 est écrite sur le logo. `.mb-hd` ne réserve pas
   `env(safe-area-inset-top)`. Dans la refonte, la page commence sous la barre d'état, dessinée en haut de chaque écran.
2. **Une bande blanche vide sous les onglets.** Environ 100 px, entre la barre d'onglets et celle de Safari.
   La cause probable : `#dash.mob{height:100dvh}` et `env(safe-area-inset-bottom)` cumulés sous la barre flottante
   de Safari. À vérifier sur un iPhone avant de coder. Dans la refonte, la barre d'onglets touche le trait du
   téléphone.
3. **L'en-tête est trop serré.** Le logo prend 40 % de la largeur, le nom du magasin est coupé sous un bouton et
   la date est tronquée (« aujourd'hui · mer… »). Dans la refonte, le nom tient seul sur la première ligne. Dessous,
   le pas de temps prend toute la largeur : « ‹ Aujourd'hui · mer. 18 nov. › », ou la semaine, ou le mois pour les
   Primes. Le logo est déjà sur l'icône de l'application.
4. **« Opérationnel » déborde de son onglet.** Les maquettes proposaient des mots courts (Jour, Chiffres). **Refusé le
   18/11/2026 : les noms restent.** Il faudra serrer l'étiquette autrement, par la taille de la police ou la largeur
   des onglets. L'onglet ouvert porte une pastille ; le point rouge et le compteur restent.
5. **Deux cartes de jauge pour un chiffre.** Le comptoir et le B2B font chacun une carte haute d'un demi-écran, et le
   B2B de 34 € en prend autant que le comptoir. Dans la refonte, un seul héros : **2 240 €, 118 % de 1 894 €**. Une
   barre colorée par canal le suit, mercredi dernier dessous, puis **quatre cases** : comptoir, commandes, webshop,
   B2B (la maquette C de `../comptoir-webshop/`, avec une case de plus).

## La grammaire, la même partout

- **L'en-tête** : le magasin, son état (« relu à 17:43 »), la revue du soir et ↻, puis le pas de temps.
- **Un héros par onglet** : le chiffre qui répond à la question de l'onglet, avec une pastille de verdict.
  - Jour : combien, face à l'objectif.
  - Chiffres : quel résultat.
  - Contrôle : combien de photos rendues.
  - Tâches : combien de tâches faites.
  - Semaine : quelle avance ou quel retard.
  - Primes : combien d'acquis.
- **Des cartes blanches** au filet fin. Jamais de bord coloré (demande du 06/11). La couleur ne vit que dans des
  pastilles (vert bon, or à surveiller, rouge à reprendre) et dans les barres.
- **Des feuilles du bas** pour le détail, toutes faites pareil : poignée, titre, ✕, chiffres en grille de quatre,
  lignes qui se déplient. Les écrans pleins sont réservés à ce qui demande les deux mains : la photo, la
  réclamation, la revue du soir.
- Gotham seule, graisse 400, `--line` pour toutes les bordures (`docs/DESIGN_SYSTEM.md`).

## Les écrans

### Les six onglets

Les écrans `*.png` montrent le haut de chaque onglet ; `long/` les montre défilés jusqu'en bas.

| Onglet | Écran | Ce qu'on y voit |
|---|---|---|
| Jour (ex-Opérationnel) | `jour.png`, `long/jour-long.png` | Le chiffre du jour et ses quatre canaux. Puis, carte par carte : clients et panier face à mercredi dernier et à la moyenne des 6 derniers mercredis ; l'heure par heure ; la vitrine (ce qu'il faut recuire) ; la journée (équipe et cuissons) ; le stock. |
| Chiffres (ex-Exploitation) | `chiffres.png`, `long/chiffres-long.png` | Le résultat du jour, le compte en escalier (chaque charge face à son seuil), le rang dans le réseau, les canaux, les catégories, les promotions, la poubelle d'hier, la note du jour. |
| Contrôle | `controle.png`, `long/controle-long.png` | Photos rendues, ce qui cloche, puis les photos en grille de deux avec leur verdict. Dessous, réclamer au fournisseur et une demande au bureau. |
| Tâches | `taches.png`, `long/taches-long.png` | Le compte du jour, ce qui bloque, un filtre, puis une carte par checklist : sa barre et seulement les tâches pas faites. La recherche en bas. |
| Semaine | `semaine.png`, `long/semaine-long.png` | L'avance sur l'objectif à ce jour, sept colonnes face à leur objectif, le damier jour par jour, quatre tuiles, la valeur du magasin. |
| Primes | `primes.png`, `long/primes-long.png` | L'acquis du mois, une ligne par personne avec ses primes en pastilles, les règles du mois. |
| | `planche.jpg` | Les six côte à côte |

### Les vues qui s'ouvrent depuis les onglets

Elles sont dans `vues/`.

| Vue | Écran | S'ouvre depuis |
|---|---|---|
| Commandes du jour | `vues/cmd.png` | La ligne sous le chiffre du jour. Le CA en commandes rapproché de la caisse, comptoir puis webshop, et demain. |
| Ventes B2B | `vues/b2b.png` | La case B2B. |
| Un produit de la vitrine | `vues/produit.png` | « Croissant · recuire 12 ». |
| L'équipe du jour | `vues/equipe.png` | La carte « La journée ». |
| Stock | `vues/stock.png` | La carte du bas de Jour. |
| La poubelle d'hier | `vues/poubelle.png` | Chiffres. |
| Une photo de contrôle | `vues/photo.png` | Une vignette de Contrôle. Plein écran : la référence, le commentaire, le verdict. |
| Réclamation fournisseur | `vues/reclamation.png` | Contrôle. |
| Une demande au bureau | `vues/bureau.png` | Contrôle. |
| La revue du soir | `vues/revue.png` | La planchette de l'en-tête. Une question à la fois. |
| | `planche-vues.jpg` | Les dix |

## Réel, illustratif

**Réel**, à Halle le mercredi 18/11 à 17:43 :
- les jauges de la capture : comptoir 2 124 €, B2B 116 €, objectifs 1 860 € et 34 € ; mercredi 11/11 à 17:42
  3 473 €, la journée 3 493 € ;
- les commandes lues au panel : le 18/11, une commande comptoir de 9,80 € et un click & collect de 5,80 €, tous
  deux encaissés en caisse ; le 11/11, 271,80 € et 76,18 € ; le 19/11, 38,50 € et 70,08 €. Le panel ne joint pas
  leurs articles, et la feuille le dit.

Le 11 novembre est férié en Belgique.

**Illustratif**, tout le reste : le B2B du 11/11 (58 €), les clients et paniers, l'heure par heure, la vitrine, le
planning, le compte du jour, les catégories, les promotions, les contrôles, les tâches, la semaine, les primes, le
stock, la poubelle. Les prénoms, la société et le fournisseur sont inventés.

## Ce qu'il faudra pour coder

- **Le cadre** (`dashboard.css`, `rendMobile`, `mbOnglets`) :
  - réserver `env(safe-area-inset-top)` dans l'en-tête ;
  - mettre la barre d'onglets en `position:fixed`, avec `env(safe-area-inset-bottom)` et sans `100dvh` sur `#dash.mob` ;
  - écrire le nouvel en-tête : le nom seul sur la première ligne, le pas de temps sur toute la largeur ;
  - garder les noms des onglets (décision du 18/11/2026).
- **Jour** (`rendOpsMobile`, `opDuelSplit`, `opDuelPro`) : le héros et ses quatre cases remplacent les deux cartes
  de jauge. Le partage des canaux **à l'heure de J−7** demande de couper les commandes à l'heure du retrait (voir
  `../comptoir-webshop/LISEZMOI.md`). La moyenne des 6 derniers mercredis existe déjà (`refTickets`, `reference`).
- **Chiffres, Semaine** (`maPageExp`, `maPageSem`) : les mêmes données, rangées en héros, escalier et tuiles.
- **Contrôle, Tâches, Primes** (`maPageCtrl`, `rendTachesMobile`, `rendPrimesMobile`) : les mêmes données. La grille
  de photos remplace le carrousel au téléphone.
- **Les feuilles** (`b2bRendre`, `cmdmRendre`, la fiche produit, l'équipe, le stock, la poubelle) : la même feuille
  du bas pour toutes.

## Régénérer

```
node pilotage-reseau/docs/maquettes/dashboard-mobile-v3/capturer.js
```

Aucun serveur à lancer. Les adresses ont la forme `index.html?v=…` :
- un onglet : `jour`, `chiffres`, `controle`, `taches`, `semaine`, `primes` ; défilé jusqu'en bas : `jour-long`,
  `chiffres-long`, etc. ;
- une vue : `cmd`, `b2b`, `produit`, `equipe`, `stock`, `poubelle`, `bureau`, `photo`, `reclamation`, `revue` ;
- les planches : `planche`, `planche-vues`.

Les onglets sont dans `index.html`, les vues et l'affichage dans `vues.js`, les styles dans `mobile3.css`.
